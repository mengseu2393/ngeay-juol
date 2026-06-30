<?php

namespace App\Models;

use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Subscription extends Model
{
    use BelongsToLandlord;
    use LogsActivity;
    use SoftDeletes;

    // landlord_id is auto-filled by BelongsToLandlord for landlord actors;
    // super_admin assigns explicitly, so it must be fillable.
    protected $fillable = [
        'landlord_id',
        'plan_id',
        'status',
        'billing_model',
        'interval',
        'price',
        'unit_price',
        'max_units',
        'max_properties',
        'features',
        'currency',
        'starts_at',
        'ends_at',
        'grace_ends_at',
        'trial_ends_at',
        'auto_renew',
        'cancelled_at',
        'cancellation_reason',
        'suspended_at',
        'suspension_reason',
        'current_unit_count',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'billing_model' => PlanBillingModel::class,
            'interval' => PlanInterval::class,
            'price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'max_units' => 'integer',
            'max_properties' => 'integer',
            'features' => 'array',
            'starts_at' => 'date',
            'ends_at' => 'date',
            'grace_ends_at' => 'date',
            'trial_ends_at' => 'date',
            'auto_renew' => 'boolean',
            'cancelled_at' => 'datetime',
            'suspended_at' => 'datetime',
            'current_unit_count' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['plan_id', 'status', 'ends_at', 'price', 'max_units', 'auto_renew'])
            ->logOnlyDirty();
    }

    /** Fallback landlord_id derivation for staff/nested creates. */
    public function resolveLandlordId(): ?int
    {
        return $this->landlord_id;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(SubscriptionHistory::class, 'subscription_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class, 'subscription_id');
    }
}
