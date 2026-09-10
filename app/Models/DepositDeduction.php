<?php

namespace App\Models;

use App\Enums\DepositDeductionCategory;
use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One itemised deduction against a {@see DepositSettlement}: a category, a
 * plain-language reason, and an amount with its USD/KHR twins.
 */
class DepositDeduction extends Model
{
    use BelongsToLandlord;

    protected $fillable = [
        'deposit_settlement_id',
        'landlord_id',
        'category',
        'reason',
        'amount',
        'currency',
        'amount_usd',
        'amount_khr',
        'exchange_rate',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'category' => DepositDeductionCategory::class,
            'amount' => 'decimal:2',
            'amount_usd' => 'decimal:2',
            'amount_khr' => 'decimal:0',
            'exchange_rate' => 'decimal:4',
        ];
    }

    /** Keep the parent settlement's withheld/refund totals in sync with its items. */
    protected static function booted(): void
    {
        static::saved(fn (DepositDeduction $deduction) => $deduction->settlement?->recalculateTotals());
        static::deleted(fn (DepositDeduction $deduction) => $deduction->settlement?->recalculateTotals());
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    public function resolveLandlordId(): ?int
    {
        return DepositSettlement::withoutGlobalScopes()
            ->whereKey($this->deposit_settlement_id)
            ->value('landlord_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(DepositSettlement::class, 'deposit_settlement_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
