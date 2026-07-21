<?php

namespace App\Models;

use App\Enums\MeterScope;
use App\Enums\MeterStatus;
use App\Models\Concerns\BelongsToLandlord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One physical measuring device for one utility.
 *
 * The index it shows is continuous only within a single device, so consumption
 * is ALWAYS `reading − previous reading of the same meter` (falling back to
 * {@see $installed_reading} for the very first reading). When a meter is swapped
 * the old row is retired with a final_reading and a new row opens with its own
 * installed_reading — no subtraction ever crosses the two.
 */
class UtilityMeter extends Model
{
    use BelongsToLandlord;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'property_utility_id',
        'landlord_id',
        'scope',
        'unit_id',
        'floor_number',
        'parent_meter_id',
        'serial',
        'digits',
        'multiplier',
        'installed_on',
        'installed_reading',
        'removed_on',
        'final_reading',
        'status',
        'replaced_meter_id',
        'notes',
        'created_by_id',
    ];

    /**
     * Mirrors the column defaults so a meter created without them behaves the
     * same in memory as after a refresh — `multiplier` especially, since a null
     * one silently multiplies every consumption by zero.
     */
    protected $attributes = [
        'scope' => 'unit',
        'status' => 'active',
        'multiplier' => 1,
        'installed_reading' => 0,
    ];

    protected function casts(): array
    {
        return [
            'scope' => MeterScope::class,
            'status' => MeterStatus::class,
            'digits' => 'integer',
            'multiplier' => 'decimal:4',
            'installed_on' => 'date',
            'removed_on' => 'date',
            'installed_reading' => 'decimal:3',
            'final_reading' => 'decimal:3',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['serial', 'installed_on', 'installed_reading', 'removed_on', 'final_reading', 'status', 'multiplier'])
            ->logOnlyDirty();
    }

    public function resolveLandlordId(): ?int
    {
        return PropertyUtility::withoutGlobalScopes()
            ->whereKey($this->property_utility_id)
            ->value('landlord_id');
    }

    public function propertyUtility(): BelongsTo
    {
        return $this->belongsTo(PropertyUtility::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** The meter this one replaced, i.e. the previous device on the same wall. */
    public function replacedMeter(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_meter_id');
    }

    /** Main meter this one sits under (scope=floor/property sub-metering). */
    public function parentMeter(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_meter_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(UtilityUsage::class, 'meter_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MeterStatus::Active->value);
    }

    public function scopeForRoom(Builder $query, int $unitId, int $propertyUtilityId): Builder
    {
        return $query->where('unit_id', $unitId)
            ->where('property_utility_id', $propertyUtilityId);
    }

    public function isActive(): bool
    {
        return $this->status === MeterStatus::Active;
    }

    /** Most recent reading taken on THIS device, or null if it has none yet. */
    public function latestUsage(): ?UtilityUsage
    {
        return $this->usages()
            ->orderByDesc('reading_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Index the next cycle must subtract from: this meter's last reading, or the
     * opening index when nothing has been read off it yet.
     */
    public function currentIndex(): float
    {
        return (float) ($this->latestUsage()?->new_reading ?? $this->installed_reading);
    }

    /**
     * Consumption between two indexes of THIS meter, applying the multiplier and
     * the digit rollover (a 5-digit meter goes 99,998 → 00,001 = 3 units used,
     * not a negative). Without `digits` a decrease can only be an error or a
     * swap that was not recorded, so it clamps to 0 as billing always has.
     */
    public function consumption(float $previousIndex, float $currentIndex): float
    {
        $delta = $currentIndex - $previousIndex;

        if ($delta < 0 && $this->digits) {
            $delta += 10 ** $this->digits;
        }

        return round(max(0, $delta) * (float) $this->multiplier, 3);
    }

    /** "SN-4471 · Room 101" for pickers and invoice lines. */
    public function label(): string
    {
        $serial = $this->serial ?: __('Meter #:id', ['id' => $this->getKey()]);
        $room = $this->unit?->room_number;

        return $room ? "{$serial} · ".__('Room :room', ['room' => $room]) : $serial;
    }
}
