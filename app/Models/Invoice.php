<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Invoice extends Model
{
    use BelongsToLandlord;
    use LogsActivity;
    use SoftDeletes;

    // amount_paid is intentionally NOT fillable — it is derived from the payments
    // ledger only (no direct writes, fixing the old InvoiceDisplay::markPaid drift).
    protected $fillable = [
        'rental_id',
        'property_id',
        'landlord_id',
        'tenant_id',
        'invoice_number',
        'amount_due',
        'period_start',
        'period_end',
        'issue_date',
        'due_date',
        'payment_status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount_due' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
            'issue_date' => 'date',
            'due_date' => 'date',
            'payment_status' => InvoiceStatus::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['invoice_number', 'amount_due', 'amount_paid', 'payment_status'])
            ->logOnlyDirty();
    }

    // ---------------------------------------------------------------------
    // Ledger (single source of truth)
    // ---------------------------------------------------------------------

    /**
     * Record a payment against this invoice. The Payment model's saved-event
     * recomputes amount_paid + payment_status from the ledger, so every payment
     * write path (this method, Filament, API) stays consistent.
     */
    public function recordPayment(array $attributes): Payment
    {
        return DB::transaction(fn () => $this->payments()->create([
            'recorded_by_id' => $attributes['recorded_by_id'] ?? Auth::id(),
            'amount' => $attributes['amount'],
            'paid_at' => $attributes['paid_at'] ?? now(),
            'method' => $attributes['method'] ?? PaymentMethod::Cash,
            'transaction_ref' => $attributes['transaction_ref'] ?? null,
            'receipt_number' => $attributes['receipt_number'] ?? null,
            'note' => $attributes['note'] ?? null,
        ]));
    }

    /** Recompute amount_paid (from the ledger) and payment_status. */
    public function recalculateFromLedger(): void
    {
        $this->amount_paid = (string) $this->payments()->sum('amount');
        $this->payment_status = $this->resolvePaymentStatus();
        $this->saveQuietly();
    }

    /** Recompute amount_due from the line items, then re-resolve status. */
    public function recalculateAmountDue(): void
    {
        $this->amount_due = (string) $this->lines()->sum('amount');
        $this->payment_status = $this->resolvePaymentStatus();
        $this->saveQuietly();
    }

    public function resolvePaymentStatus(): InvoiceStatus
    {
        if ($this->payment_status === InvoiceStatus::Cancelled) {
            return InvoiceStatus::Cancelled; // terminal & sticky
        }

        $due = (float) $this->amount_due;
        $paid = (float) $this->amount_paid;

        if ($paid <= 0.0) {
            if ($this->payment_status === InvoiceStatus::Draft) {
                return InvoiceStatus::Draft;
            }

            return ($this->due_date && $this->due_date->isPast())
                ? InvoiceStatus::Overdue
                : InvoiceStatus::Pending;
        }

        if ($paid + 0.001 >= $due) {
            return InvoiceStatus::Paid;
        }

        return InvoiceStatus::Partial;
    }

    protected function balance(): Attribute
    {
        return Attribute::make(
            get: fn () => round((float) $this->amount_due - (float) $this->amount_paid, 2),
        );
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    public function resolveLandlordId(): ?int
    {
        return Rental::withoutGlobalScopes()->whereKey($this->rental_id)->value('landlord_id');
    }

    protected static function booted(): void
    {
        // Keep denormalized property_id in sync with the rental's property.
        static::saving(function (Invoice $invoice) {
            if (empty($invoice->property_id) && $invoice->rental_id) {
                $invoice->property_id = Rental::withoutGlobalScopes()->whereKey($invoice->rental_id)->value('property_id');
            }
        });
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
