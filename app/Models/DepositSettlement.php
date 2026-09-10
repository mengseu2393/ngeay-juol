<?php

namespace App\Models;

use App\Enums\DepositSettlementStatus;
use App\Models\Concerns\BelongsToLandlord;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The end-of-tenancy account for `rentals.security_deposit`: what was held,
 * what was withheld and why, and what is owed back.
 *
 * The three money figures each carry USD/KHR twins converted at this record's
 * own `exchange_rate` — a settlement re-read years later must show the numbers
 * the tenant was actually given, not today's rate.
 */
class DepositSettlement extends Model
{
    use BelongsToLandlord;

    protected $fillable = [
        'rental_id',
        'landlord_id',
        'final_invoice_id',
        'currency',
        'exchange_rate',
        'deposit_amount',
        'deposit_amount_usd',
        'deposit_amount_khr',
        'deductions_total',
        'deductions_total_usd',
        'deductions_total_khr',
        'refund_amount',
        'refund_amount_usd',
        'refund_amount_khr',
        'status',
        'settled_at',
        'settled_by_id',
        'refund_reference',
        'refund_paid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:4',
            'deposit_amount' => 'decimal:2',
            'deposit_amount_usd' => 'decimal:2',
            'deposit_amount_khr' => 'decimal:0',
            'deductions_total' => 'decimal:2',
            'deductions_total_usd' => 'decimal:2',
            'deductions_total_khr' => 'decimal:0',
            'refund_amount' => 'decimal:2',
            'refund_amount_usd' => 'decimal:2',
            'refund_amount_khr' => 'decimal:0',
            'status' => DepositSettlementStatus::class,
            'settled_at' => 'datetime',
            'refund_paid_at' => 'date',
        ];
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    public function resolveLandlordId(): ?int
    {
        return Rental::withoutGlobalScopes()->whereKey($this->rental_id)->value('landlord_id');
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function finalInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'final_invoice_id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by_id');
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(DepositDeduction::class);
    }

    /**
     * Recompute the withheld total and the resulting refund from the itemised
     * deductions. Called from {@see DepositDeduction::booted()} — never by the
     * writer that created the settlement (CLAUDE.md: a child that derives a
     * parent's monetary total recomputes the parent itself).
     *
     * Saved quietly: this is a derived figure, not a landlord edit worth an
     * activity-log entry or another round of model events.
     */
    public function recalculateTotals(): void
    {
        $currency = Money::normalize($this->currency);

        $deductions = $this->deductions()->get();
        $deductionsUsd = round((float) $deductions->sum('amount_usd'), 2);
        $deductionsKhr = round((float) $deductions->sum('amount_khr'));

        $depositUsd = (float) $this->deposit_amount_usd;
        $depositKhr = (float) $this->deposit_amount_khr;

        $refundUsd = round(max(0.0, $depositUsd - $deductionsUsd), 2);
        $refundKhr = round(max(0.0, $depositKhr - $deductionsKhr));

        $this->forceFill([
            'deductions_total' => $currency === 'KHR' ? $deductionsKhr : $deductionsUsd,
            'deductions_total_usd' => $deductionsUsd,
            'deductions_total_khr' => $deductionsKhr,
            'refund_amount' => $currency === 'KHR' ? $refundKhr : $refundUsd,
            'refund_amount_usd' => $refundUsd,
            'refund_amount_khr' => $refundKhr,
        ])->saveQuietly();
    }
}
