<?php

namespace App\Models;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Models\Concerns\BelongsToLandlord;
use App\Services\TenancyService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Rental extends Model implements HasMedia
{
    use BelongsToLandlord;
    use InteractsWithMedia;
    use LogsActivity;
    use SoftDeletes;

    // active_unit_id is a STORED generated column — never mass-assigned.
    protected $fillable = [
        'landlord_id',
        'property_id',
        'tenant_id',
        'occupant_name',
        'occupant_phone',
        'occupant_id_card',
        'occupant_address',
        'unit_id',
        'monthly_rent',
        'security_deposit',
        'lease_agreement',
        'terms_conditions',
        'signed_at',
        'status',
        'start_date',
        'end_date',
        'next_invoice_date',
    ];

    protected function casts(): array
    {
        return [
            'monthly_rent'       => 'decimal:2',
            'security_deposit'   => 'decimal:2',
            'signed_at'          => 'datetime',
            'status'             => RentalStatus::class,
            'start_date'         => 'date',
            'end_date'           => 'date',
            'next_invoice_date'  => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tenant_id', 'unit_id', 'monthly_rent', 'status', 'start_date', 'end_date'])
            ->logOnlyDirty();
    }

    public function isActive(): bool
    {
        return $this->status === RentalStatus::Active;
    }

    protected static function booted(): void
    {
        // Keep the denormalized property_id in sync with the unit's property.
        static::saving(function (Rental $rental) {
            if ($rental->unit_id && (empty($rental->property_id) || $rental->isDirty('unit_id'))) {
                $rental->property_id = Unit::withoutGlobalScopes()->whereKey($rental->unit_id)->value('property_id');
            }
        });

        // Keep the room's status in lock-step with its tenancy.
        static::saved(function (Rental $rental) {
            // An active tenancy occupies its room. (occupyUnit only flips an
            // otherwise-free room — it never overrides Maintenance/Unavailable.)
            if ($rental->status === RentalStatus::Active) {
                $rental->occupyUnit();

                // Auto-create first invoice if enabled in Property Settings
                $setting = \App\Models\PropertySetting::where('property_id', $rental->property_id)->first();
                if ($setting && $setting->create_invoice_on_move_in) {
                    $exists = \App\Models\Invoice::where('rental_id', $rental->id)->exists();
                    if (! $exists) {
                        $periodStart = \Illuminate\Support\Carbon::parse($rental->start_date);
                        if ($setting->first_month_billing_mode === \App\Enums\FirstMonthBillingMode::FullMonth) {
                            $periodEnd = $periodStart->copy()->addMonth()->subDay();
                        } else {
                            $periodEnd = $periodStart->copy()->endOfMonth();
                        }

                        $dueDay = $setting->due_day_of_month ?: 7;
                        $dueDate = $periodStart->copy()->day($dueDay);
                        if ($dueDate->isBefore($periodStart)) {
                            $dueDate->addMonth();
                        }

                        app(\App\Services\InvoiceBuilderService::class)->create([
                            'rental' => $rental,
                            'period_start' => $periodStart,
                            'period_end' => $periodEnd,
                            'issue_date' => now(),
                            'due_date' => $dueDate,
                            'include_rent' => true,
                            'is_first_invoice' => true,
                            'usages' => [],
                        ]);

                        // Roll next_invoice_date forward so the next run starts after this period
                        $rental->withoutEvents(fn () => $rental->update([
                            'next_invoice_date' => $periodEnd->copy()->addDay(),
                        ]));
                    }
                }
            }

            // If the tenancy was moved to a different room, free the room it left
            // (when nothing else keeps it occupied). We intentionally do NOT free
            // on a mere status change — ending a tenancy has its own "Mark room as
            // available" toggle that must stay in control of that decision.
            if ($rental->wasChanged('unit_id')) {
                $previousUnitId = $rental->getOriginal('unit_id');
                if ($previousUnitId) {
                    static::freeUnitIfVacant((int) $previousUnitId);
                }
            }

            // Update associated tenant's user status based on rental status.
            if ($rental->wasChanged('status') && $rental->tenant_id) {
                $userStatus = $rental->status === RentalStatus::Active
                    ? \App\Enums\UserStatus::Active
                    : \App\Enums\UserStatus::Inactive;

                // Ensure we don't deactivate the shared room account if it's used as the tenant.
                // The shared room account is kept active for the next occupant.
                $sharedRoomAccount = $rental->unit?->account_user_id;
                if ($rental->tenant_id !== $sharedRoomAccount) {
                    $rental->tenant()->update(['status' => $userStatus]);
                }
            }
        });

        // Deleting (incl. soft-delete) a tenancy frees its room if nothing else holds it.
        // Exclude this rental from the "still active?" check: hasActiveTenancy() runs
        // withoutGlobalScopes(), which also drops the soft-delete scope, so a just
        // soft-deleted row would otherwise still count as active.
        static::deleted(function (Rental $rental) {
            if ($rental->unit_id) {
                static::freeUnitIfVacant((int) $rental->unit_id, $rental->getKey());
            }
        });
    }

    /** Mark this tenancy's room Occupied — but only when it is currently free. */
    public function occupyUnit(): void
    {
        if (! $this->unit_id) {
            return;
        }

        $unit = Unit::withoutGlobalScopes()->find($this->unit_id);
        if ($unit && $unit->status === UnitStatus::Available) {
            $unit->status = UnitStatus::Occupied;
            $unit->save();
        }
    }

    /**
     * Free a room IFF it is Occupied and no active tenancy remains on it. Leaves
     * Maintenance / Unavailable rooms untouched.
     */
    protected static function freeUnitIfVacant(int $unitId, ?int $excludeRentalId = null): void
    {
        $unit = Unit::withoutGlobalScopes()->find($unitId);
        if (! $unit || $unit->status !== UnitStatus::Occupied) {
            return;
        }

        if (! TenancyService::hasActiveTenancy($unitId, $excludeRentalId)) {
            $unit->status = UnitStatus::Available;
            $unit->save();
        }
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function registerMediaCollections(): void
    {
        // ID-card / document photos for this tenancy's occupant.
        $this->addMediaCollection('id_cards');
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    public function resolveLandlordId(): ?int
    {
        return Unit::withoutGlobalScopes()->whereKey($this->unit_id)->value('landlord_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function utilityUsages(): HasMany
    {
        return $this->hasMany(UtilityUsage::class);
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }
}
