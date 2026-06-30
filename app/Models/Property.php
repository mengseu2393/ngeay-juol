<?php

namespace App\Models;

use App\Enums\PropertyType;
use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Property extends Model implements HasMedia
{
    use BelongsToLandlord;
    use InteractsWithMedia;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'landlord_id',
        'name',
        'property_type',
        'description',
        'address_line',
        'street',
        'village',
        'commune',
        'district',
        'city',
        'postal_code',
        'amenities',
    ];

    protected function casts(): array
    {
        return [
            'property_type' => PropertyType::class,
            'amenities' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'property_type', 'landlord_id'])->logOnlyDirty();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos');
    }

    // total_floors / total_rooms are computed (never stored — fixes the old drift bug).
    protected function totalRooms(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->units_count ?? $this->units()->count(),
        );
    }

    protected function totalFloors(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->units()->distinct()->count('floor_number'),
        );
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function propertyUtilities(): HasMany
    {
        return $this->hasMany(PropertyUtility::class);
    }

    public function utilityWaivers(): HasMany
    {
        return $this->hasMany(UtilityWaiver::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(PropertySetting::class);
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }
}
