<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToLandlord;
use App\Notifications\InvoiceGeneratedNotification;
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
        'usd_khr_rate',
        'exchange_rate_source',
        'exchange_rate_date',
        'exchange_rate_fetched_at',
        'exchange_rate_note',
        'total_usd',
        'total_khr',
        'native_usd_total',
        'native_khr_total',
        'paid_usd',
        'paid_khr',
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
            'usd_khr_rate' => 'decimal:4',
            'exchange_rate_date' => 'date',
            'exchange_rate_fetched_at' => 'datetime',
            'total_usd' => 'decimal:2',
            'total_khr' => 'decimal:0',
            'native_usd_total' => 'decimal:2',
            'native_khr_total' => 'decimal:0',
            'paid_usd' => 'decimal:2',
            'paid_khr' => 'decimal:0',
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
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
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
        return DB::transaction(function () use ($attributes) {
            $currency = \App\Support\Money::normalize($attributes['currency'] ?? ($this->property?->settings?->currency ?? 'USD'));
            $amount = (float) $attributes['amount'];
            
            // conversion rate is the invoice's saved rate
            $rate = (float) ($this->usd_khr_rate ?: ($this->property?->settings?->usd_khr_exchange_rate ?: 4000));
            
            $amountUsd = 0.0;
            $amountKhr = 0.0;
            if ($currency === 'USD') {
                $amountUsd = $amount;
                $amountKhr = round($amountUsd * $rate, 0);
            } else { // KHR
                $amountKhr = round($amount, 0);
                $amountUsd = $rate > 0 ? round($amountKhr / $rate, 2) : 0.0;
            }

            return $this->payments()->create([
                'recorded_by_id' => $attributes['recorded_by_id'] ?? Auth::id(),
                'amount' => $amount,
                'currency' => $currency,
                'amount_usd' => $amountUsd,
                'amount_khr' => $amountKhr,
                'exchange_rate' => $rate,
                'exchange_rate_source' => $this->exchange_rate_source ?: 'invoice_snapshot',
                'paid_at' => $attributes['paid_at'] ?? now(),
                'method' => $attributes['method'] ?? PaymentMethod::Cash,
                'transaction_ref' => $attributes['transaction_ref'] ?? null,
                'receipt_number' => $attributes['receipt_number'] ?? null,
                'note' => $attributes['note'] ?? null,
            ]);
        });
    }

    /** Recompute amount_paid (from the ledger) and payment_status. */
    public function recalculateFromLedger(): void
    {
        $this->paid_usd = (string) $this->payments()->sum('amount_usd');
        $this->paid_khr = (string) $this->payments()->sum('amount_khr');

        $reportingCurrency = \App\Support\Money::normalize($this->property?->settings?->currency ?? 'USD');
        if ($reportingCurrency === 'KHR') {
            $this->amount_paid = $this->paid_khr;
        } else {
            $this->amount_paid = $this->paid_usd;
        }

        $this->payment_status = $this->resolvePaymentStatus();
        $this->saveQuietly();
    }

    /** Recompute amount_due from the line items, then re-resolve status. */
    public function recalculateAmountDue(): void
    {
        $this->total_usd = (string) $this->lines()->sum('amount_usd');
        $this->total_khr = (string) $this->lines()->sum('amount_khr');
        $this->native_usd_total = (string) $this->lines()->where('currency', 'USD')->sum('amount');
        $this->native_khr_total = (string) $this->lines()->where('currency', 'KHR')->sum('amount');

        $reportingCurrency = \App\Support\Money::normalize($this->property?->settings?->currency ?? 'USD');
        if ($reportingCurrency === 'KHR') {
            $this->amount_due = $this->total_khr;
        } else {
            $this->amount_due = $this->total_usd;
        }

        $this->payment_status = $this->resolvePaymentStatus();
        $this->saveQuietly();
    }

    public function resolvePaymentStatus(): InvoiceStatus
    {
        if ($this->payment_status === InvoiceStatus::Cancelled) {
            return InvoiceStatus::Cancelled; // terminal & sticky
        }

        $totalUsd = (float) ($this->total_usd ?? 0);
        $paidUsd = (float) ($this->paid_usd ?? 0);
        $totalKhr = (float) ($this->total_khr ?? 0);
        $paidKhr = (float) ($this->paid_khr ?? 0);

        // fallback to amount_due / amount_paid if totals are not set yet (old invoices)
        if ($totalUsd <= 0.0 && $totalKhr <= 0.0) {
            $totalUsd = (float) $this->amount_due;
            $paidUsd = (float) $this->amount_paid;
        }

        if ($paidUsd <= 0.0 && $paidKhr <= 0.0) {
            if ($this->payment_status === InvoiceStatus::Draft) {
                return InvoiceStatus::Draft;
            }

            return ($this->due_date && $this->due_date->isPast())
                ? InvoiceStatus::Overdue
                : InvoiceStatus::Pending;
        }

        if (($totalUsd > 0 && $paidUsd + 0.005 >= $totalUsd) || ($totalKhr > 0 && $paidKhr + 0.5 >= $totalKhr)) {
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

    public function getBalanceUsdAttribute(): float
    {
        $totalUsd = $this->total_usd;
        $paidUsd = $this->paid_usd;
        if ($totalUsd === null) {
            $rate = (float) ($this->usd_khr_rate ?: ($this->property?->settings?->usd_khr_exchange_rate ?: 4000));
            $reportingCurrency = \App\Support\Money::normalize($this->property?->settings?->currency ?? 'USD');
            if ($reportingCurrency === 'USD') {
                $totalUsd = (float) $this->amount_due;
            } else {
                $totalUsd = $rate > 0 ? round((float) $this->amount_due / $rate, 2) : 0.0;
            }
        }
        if ($paidUsd === null) {
            $rate = (float) ($this->usd_khr_rate ?: ($this->property?->settings?->usd_khr_exchange_rate ?: 4000));
            $reportingCurrency = \App\Support\Money::normalize($this->property?->settings?->currency ?? 'USD');
            if ($reportingCurrency === 'USD') {
                $paidUsd = (float) $this->amount_paid;
            } else {
                $paidUsd = $rate > 0 ? round((float) $this->amount_paid / $rate, 2) : 0.0;
            }
        }
        return round(max(0.0, (float) $totalUsd - (float) $paidUsd), 2);
    }

    public function getBalanceKhrAttribute(): float
    {
        $totalKhr = $this->total_khr;
        $paidKhr = $this->paid_khr;
        if ($totalKhr === null) {
            $rate = (float) ($this->usd_khr_rate ?: ($this->property?->settings?->usd_khr_exchange_rate ?: 4000));
            $reportingCurrency = \App\Support\Money::normalize($this->property?->settings?->currency ?? 'USD');
            if ($reportingCurrency === 'KHR') {
                $totalKhr = (float) $this->amount_due;
            } else {
                $totalKhr = round((float) $this->amount_due * $rate, 0);
            }
        }
        if ($paidKhr === null) {
            $rate = (float) ($this->usd_khr_rate ?: ($this->property?->settings?->usd_khr_exchange_rate ?: 4000));
            $reportingCurrency = \App\Support\Money::normalize($this->property?->settings?->currency ?? 'USD');
            if ($reportingCurrency === 'KHR') {
                $paidKhr = (float) $this->amount_paid;
            } else {
                $paidKhr = round((float) $this->amount_paid * $rate, 0);
            }
        }
        return round(max(0.0, (float) $totalKhr - (float) $paidKhr), 0);
    }

    // ---------------------------------------------------------------------
    // Date presentation (defensive)
    // ---------------------------------------------------------------------

    /**
     * Whether a date is real enough to print. Guards against corrupt values such
     * as period_start = "0011-11-11" (a mistyped year that Carbon parses happily
     * but which renders as "11 Nov 0011"). Anything outside a sane year window is
     * treated as missing rather than shown.
     */
    public static function isPlausibleDate(mixed $date): bool
    {
        return $date instanceof \Carbon\CarbonInterface
            && $date->year >= 2000
            && $date->year <= 2100;
    }

    /**
     * Format a date for display, or a placeholder when it's missing/implausible.
     * The placeholder defaults to an em dash for the on-screen UI; the dompdf PDF
     * passes a plain "-" because the bundled Khmer font has no em/en dash glyph.
     */
    public static function displayDate(mixed $date, string $format = 'd M Y', string $placeholder = '—'): string
    {
        return self::isPlausibleDate($date) ? $date->translatedFormat($format) : $placeholder;
    }

    /**
     * The billing period as a single human string. Drops an endpoint that is
     * missing or implausible instead of printing a broken range like
     * "11 Nov 0011 – 01 Jul 2026"; collapses to one date when only one endpoint
     * is valid, and to the placeholder when neither is. The separator/placeholder
     * default to en/em dashes for the UI; the PDF passes ASCII (the Khmer font
     * lacks those glyphs — they'd otherwise render as tofu boxes).
     */
    public function billingPeriodLabel(string $format = 'd M Y', string $separator = ' – ', string $placeholder = '—'): string
    {
        $start = self::isPlausibleDate($this->period_start) ? $this->period_start->translatedFormat($format) : null;
        $end = self::isPlausibleDate($this->period_end) ? $this->period_end->translatedFormat($format) : null;

        return match (true) {
            $start && $end => $start.$separator.$end,
            (bool) $end => $end,
            (bool) $start => $start,
            default => $placeholder,
        };
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

        static::created(function (Invoice $invoice) {
            $invoice->tenant?->notify(new InvoiceGeneratedNotification($invoice));
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
