<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'invoice_id',
        'recorded_by_id',
        'amount',
        'paid_at',
        'method',
        'transaction_ref',
        'receipt_number',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'method' => PaymentMethod::class,
        ];
    }

    /**
     * Keep the invoice ledger consistent no matter how a payment is written
     * (recordPayment, Filament, API, console). This is the single choke point
     * that guarantees amount_paid + payment_status never drift.
     */
    protected static function booted(): void
    {
        static::saved(fn (Payment $payment) => $payment->invoice?->recalculateFromLedger());
        static::deleted(fn (Payment $payment) => $payment->invoice?->recalculateFromLedger());
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
