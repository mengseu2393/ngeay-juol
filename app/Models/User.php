<?php

namespace App\Models;

use App\Enums\UserStatus;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasMedia, HasName
{
    use HasFactory;
    use HasRoles;
    use InteractsWithMedia;
    use LogsActivity;
    use Notifiable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;

    /**
     * Mass-assignable attributes. Intentionally EXCLUDES status, created_by_id,
     * manages_landlord_id and all social-id columns (F14 mass-assignment guard) —
     * those are set only by controlled code paths.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'phone_number',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    // ---------------------------------------------------------------------
    // Audit
    // ---------------------------------------------------------------------

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone_number', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    // ---------------------------------------------------------------------
    // Media (ID cards / avatar) — re-encoded, EXIF-stripped at upload time
    // ---------------------------------------------------------------------

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('id_cards')->onlyKeepLatest(2);
        $this->addMediaCollection('avatar')->singleFile();
    }

    // ---------------------------------------------------------------------
    // Filament panel access
    // ---------------------------------------------------------------------

    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->status !== UserStatus::Active) {
            return false;
        }

        // Two back-office panels: platform staff on /admin, landlords on /landlord.
        // super_admin may enter both; tenants use the Livewire/Flux portal (no panel).
        return match ($panel->getId()) {
            'admin' => $this->hasAnyRole(['super_admin', 'support']),
            'landlord' => $this->hasAnyRole(['super_admin', 'landlord', 'landlord_manager']),
            default => false,
        };
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    // ---------------------------------------------------------------------
    // Role helpers (centralized — used by LandlordScope, policies, Gate)
    // ---------------------------------------------------------------------

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /** super_admin or support: cross-landlord visibility. */
    public function isPlatformStaff(): bool
    {
        return $this->hasAnyRole(['super_admin', 'support']);
    }

    /**
     * The landlord whose data this user owns/operates on, or null for
     * platform staff / tenants. Drives {@see LandlordScope}.
     */
    public function effectiveLandlordId(): ?int
    {
        if ($this->hasRole('landlord')) {
            return (int) $this->getKey();
        }

        if ($this->hasRole('landlord_manager')) {
            return $this->manages_landlord_id !== null ? (int) $this->manages_landlord_id : null;
        }

        return null;
    }

    /** May this user create tenant accounts? (admin always; landlord/manager if delegated). */
    public function canCreateTenants(): bool
    {
        if ($this->isPlatformStaff()) {
            return true;
        }

        if ($this->hasAnyRole(['landlord', 'landlord_manager'])) {
            return (bool) ($this->landlordProfileForActor()?->can_create_tenants);
        }

        return false;
    }

    /** Resolve the landlord_profile that governs this actor's delegation flag. */
    public function landlordProfileForActor(): ?LandlordProfile
    {
        if ($this->hasRole('landlord')) {
            return $this->landlordProfile;
        }

        if ($this->hasRole('landlord_manager') && $this->manages_landlord_id) {
            return LandlordProfile::where('user_id', $this->manages_landlord_id)->first();
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------------

    public function landlordProfile(): HasOne
    {
        return $this->hasOne(LandlordProfile::class);
    }

    public function tenantProfile(): HasOne
    {
        return $this->hasOne(TenantProfile::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by_id');
    }

    public function managesLandlord(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manages_landlord_id');
    }

    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'landlord_id');
    }

    public function rentalsAsLandlord(): HasMany
    {
        return $this->hasMany(Rental::class, 'landlord_id');
    }

    public function rentalsAsTenant(): HasMany
    {
        return $this->hasMany(Rental::class, 'tenant_id');
    }

    public function invoicesAsLandlord(): HasMany
    {
        return $this->hasMany(Invoice::class, 'landlord_id');
    }

    public function invoicesAsTenant(): HasMany
    {
        return $this->hasMany(Invoice::class, 'tenant_id');
    }

    public function maintenanceAsTenant(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class, 'tenant_id');
    }

    public function maintenanceAsLandlord(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class, 'landlord_id');
    }
}
