<?php

namespace App\Models;

use App\Enums\SubscriptionAction;
use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionHistory extends Model
{
    use BelongsToLandlord;

    protected $fillable = [
        'subscription_id',
        'landlord_id',
        'plan_id',
        'action',
        'period_start',
        'period_end',
        'price',
        'unit_count',
        'amount_charged',
        'meta',
        'note',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'action' => SubscriptionAction::class,
            'price' => 'decimal:2',
            'amount_charged' => 'decimal:2',
            'unit_count' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'meta' => 'array',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
