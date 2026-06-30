<?php

namespace App\Models;

use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SubscriptionPlan extends Model
{
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'billing_model',
        'interval',
        'price',
        'unit_price',
        'max_units',
        'max_properties',
        'trial_days',
        'grace_days',
        'features',
        'currency',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'billing_model' => PlanBillingModel::class,
            'interval' => PlanInterval::class,
            'price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'max_units' => 'integer',
            'max_properties' => 'integer',
            'trial_days' => 'integer',
            'grace_days' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'price', 'max_units', 'is_active'])
            ->logOnlyDirty();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}
