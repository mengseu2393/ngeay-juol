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

    /**
     * A single-line postal address assembled from the Cambodian address fields,
     * de-duplicated so overlapping entries don't repeat. Data entry frequently
     * copies the whole address into `address_line` AND fills `street`/`commune`
     * with the same text; naively imploding every field then prints the address
     * two or three times over.
     *
     * We work at comma-segment granularity: a field is dropped only when every
     * one of its segments is already represented by a field we've kept. That
     * collapses a field repeating the whole address (address_line == street) or a
     * single value already inside another field ("Phnom Penh" as both the commune
     * and the tail of address_line), while keeping a genuinely distinct field that
     * merely shares a substring with another ("San" vs "Sangkat San Khang").
     */
    protected function formattedAddress(): Attribute
    {
        return Attribute::make(get: function (): string {
            $parts = array_filter(
                [$this->address_line, $this->street, $this->village, $this->commune, $this->district, $this->city, $this->postal_code],
                fn ($part) => filled(trim((string) $part)),
            );

            $kept = [];
            $seen = []; // normalised comma-segments already represented by a kept field

            foreach ($parts as $part) {
                $part = trim((string) $part);

                $segments = array_values(array_filter(
                    array_map(fn ($s) => mb_strtolower(trim($s)), explode(',', $part)),
                    fn ($s) => $s !== '',
                ));

                // Redundant only when it adds no new segment.
                if ($segments !== [] && array_diff($segments, $seen) === []) {
                    continue;
                }

                $kept[] = $part;
                $seen = array_merge($seen, $segments);
            }

            return implode(', ', $kept);
        });
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
