<?php

namespace App\Models;

use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UtilityWaiver extends Model
{
    use BelongsToLandlord;

    protected $fillable = [
        'property_utility_id',
        'landlord_id',
        'property_id',
        'unit_id',
        'rental_id',
        'waived',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'waived' => 'boolean',
        ];
    }

    /**
     * Is the given utility waived for a rental? Resolution priority:
     * rental-scope > unit-scope > property-scope (most specific wins).
     */
    /**
     * Is this property-utility waived for a unit/rental? A waiver applies when it is
     * property-wide (no unit/rental narrowing) OR it narrows to this unit/rental.
     */
    public static function isWaivedFor(int $propertyUtilityId, ?int $rentalId, ?int $unitId): bool
    {
        return static::query()
            ->where('property_utility_id', $propertyUtilityId)
            ->where('waived', true)
            ->where(function ($q) use ($rentalId, $unitId) {
                $q->where(fn ($w) => $w->whereNull('unit_id')->whereNull('rental_id')); // property-wide
                if ($unitId) {
                    $q->orWhere('unit_id', $unitId);
                }
                if ($rentalId) {
                    $q->orWhere('rental_id', $rentalId);
                }
            })
            ->exists();
    }

    public function resolveLandlordId(): ?int
    {
        if ($this->unit_id) {
            return Unit::withoutGlobalScopes()->whereKey($this->unit_id)->value('landlord_id');
        }
        if ($this->property_id) {
            return Property::withoutGlobalScopes()->whereKey($this->property_id)->value('landlord_id');
        }
        if ($this->rental_id) {
            return Rental::withoutGlobalScopes()->whereKey($this->rental_id)->value('landlord_id');
        }

        return null;
    }

    public function propertyUtility(): BelongsTo
    {
        return $this->belongsTo(PropertyUtility::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
