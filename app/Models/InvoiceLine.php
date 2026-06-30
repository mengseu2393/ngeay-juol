<?php

namespace App\Models;

use App\Enums\InvoiceLineType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id',
        'line_type',
        'utility_usage_id',
        'description',
        'quantity',
        'unit_price',
        'amount',
        'is_waived',
    ];

    protected function casts(): array
    {
        return [
            'line_type' => InvoiceLineType::class,
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:4',
            'amount' => 'decimal:2',
            'is_waived' => 'boolean',
        ];
    }

    /** Keep the parent invoice's amount_due in sync with its line items. */
    protected static function booted(): void
    {
        static::saved(fn (InvoiceLine $line) => $line->invoice?->recalculateAmountDue());
        static::deleted(fn (InvoiceLine $line) => $line->invoice?->recalculateAmountDue());
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function utilityUsage(): BelongsTo
    {
        return $this->belongsTo(UtilityUsage::class);
    }
}
