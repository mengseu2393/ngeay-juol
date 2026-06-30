<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionPaymentStatus;
use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    use BelongsToLandlord;

    protected $fillable = [
        'subscription_id',
        'landlord_id',
        'plan_id',
        'amount',
        'currency',
        'method',
        'status',
        'paid_at',
        'covers_from',
        'covers_to',
        'gateway',
        'gateway_transaction_id',
        'gateway_ref',
        'receipt_number',
        'note',
        'recorded_by_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'status' => SubscriptionPaymentStatus::class,
            'paid_at' => 'datetime',
            'covers_from' => 'date',
            'covers_to' => 'date',
        ];
    }

    /** Fallback landlord_id derivation for staff/nested creates. */
    public function resolveLandlordId(): ?int
    {
        return $this->landlord_id;
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
