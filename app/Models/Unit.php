<?php

namespace App\Models;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Models\Concerns\BelongsToLandlord;
use App\Services\SubscriptionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use App\Models\Subscription;

class Unit extends Model
{
    use BelongsToLandlord;
    use LogsActivity;
    use SoftDeletes;

    // Note: 'created_at'/'updated_at' deliberately NOT fillable (old anti-pattern fixed).
    protected $fillable = [
        'property_id',
        'landlord_id',
        'account_user_id',
        'room_number',
        'floor_number',
        'room_type',
        'rent_amount',
        'due_date',
        'status',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'rent_amount' => 'decimal:2',
            'due_date' => 'date',
            'status' => UnitStatus::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['room_number', 'rent_amount', 'status'])
            ->logOnlyDirty();
    }

    /** Enforce subscription unit cap on create (super-admin creates bypass via policy). */
    protected static function booted(): void
    {
        static::creating(function (Unit $unit) {
            if ($unit->landlord_id) {
                SubscriptionService::assertWithinUnitCap(
                    User::find($unit->landlord_id),
                );
            }
        });

        static::created(function (Unit $unit) {
            if ($unit->landlord_id) {
                $sub = Subscription::withoutGlobalScopes()->where('landlord_id', $unit->landlord_id)->first();
                if ($sub) {
                    SubscriptionService::recomputeUnitCount($sub);
                }
            }
        });

        static::deleted(function (Unit $unit) {
            if ($unit->landlord_id) {
                $sub = Subscription::withoutGlobalScopes()->where('landlord_id', $unit->landlord_id)->first();
                if ($sub) {
                    SubscriptionService::recomputeUnitCount($sub);
                }
            }
        });
    }

    /** Fallback landlord_id derivation for nested/staff/seeder creates. */
    public function resolveLandlordId(): ?int
    {
        return Property::withoutGlobalScopes()->whereKey($this->property_id)->value('landlord_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** The room's permanent login account (reused across occupants). */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_user_id');
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class);
    }

    public function activeRental(): HasOne
    {
        return $this->hasOne(Rental::class)->where('status', RentalStatus::Active->value);
    }

    public function utilityUsages(): HasMany
    {
        return $this->hasMany(UtilityUsage::class);
    }

    /** Every invoice ever billed to this room, across all of its tenancies. */
    public function invoices(): HasManyThrough
    {
        return $this->hasManyThrough(Invoice::class, Rental::class, 'unit_id', 'rental_id');
    }
}
