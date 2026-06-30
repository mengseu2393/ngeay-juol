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
        'name',
        'unit_of_measure',
        'billing_type',
        'rate',
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

    public function waivers(): HasMany
    {
        return $this->hasMany(UtilityWaiver::class);
    }
}
