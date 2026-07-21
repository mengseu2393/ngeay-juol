<?php

namespace App\Models;

use App\Enums\BillingType;
use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A utility owned by ONE property (its own provider, rate and billing rule).
 * Never shared or inherited across a landlord's other properties.
 */
class PropertyUtility extends Model
{
    use BelongsToLandlord;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'property_id',
        'landlord_id',
        'charge_definition_id',
        'name',
        'unit_of_measure',
        'billing_type',
        'rate',
        'currency',
        'provider',
        'account_ref',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'billing_type' => BillingType::class,
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'rate', 'provider', 'billing_type', 'is_active'])
            ->logOnlyDirty();
    }

    protected static function booted(): void
    {
        static::creating(function (PropertyUtility $utility) {
            if (empty($utility->currency)) {
                $propSetting = PropertySetting::where('property_id', $utility->property_id)->first();
                $utility->currency = $propSetting ? ($propSetting->currency ?: 'USD') : 'USD';
            }
        });
    }

    public function resolveLandlordId(): ?int
    {
        return Property::withoutGlobalScopes()->whereKey($this->property_id)->value('landlord_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(UtilityUsage::class);
    }

    /** Physical devices — present only for metered utilities. */
    public function meters(): HasMany
    {
        return $this->hasMany(UtilityMeter::class);
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(UtilityWaiver::class);
    }

    public function chargeRules(): HasMany
    {
        return $this->hasMany(ChargeRule::class, 'property_utility_id');
    }

    public function chargeDefinition(): BelongsTo
    {
        return $this->belongsTo(ChargeDefinition::class);
    }

    public function requiresReading(): bool
    {
        return $this->billing_type !== BillingType::Flat;
    }

    public function isFixedMonthlyCharge(): bool
    {
        return ! $this->requiresReading();
    }
}
