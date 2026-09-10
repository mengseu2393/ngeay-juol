<?php

namespace App\Models;

use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Models\Concerns\BelongsToLandlord;
use App\Notifications\MaintenanceRequestCreatedNotification;
use App\Notifications\MaintenanceStatusChangedNotification;
use App\Support\Notifications\NotificationRecipients;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class MaintenanceRequest extends Model implements HasMedia
{
    use BelongsToLandlord;
    use InteractsWithMedia;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'landlord_id',
        'property_id',
        'unit_id',
        'rental_id',
        'title',
        'description',
        'priority',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'priority' => MaintenancePriority::class,
            'status' => MaintenanceStatus::class,
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photos');
    }

    protected static function booted(): void
    {
        static::created(function (MaintenanceRequest $request) {
            NotificationRecipients::landlordOperators((int) $request->landlord_id)
                ->each(fn (User $user) => $user->notify(new MaintenanceRequestCreatedNotification($request)));
        });

        static::updated(function (MaintenanceRequest $request) {
            if (! $request->wasChanged('status')) {
                return;
            }

            // getRawOriginal, not getOriginal: getOriginal() runs the enum cast and
            // returns a MaintenanceStatus instance, which (int) then fatals on.
            $oldStatus = MaintenanceStatus::tryFrom((int) $request->getRawOriginal('status'));
            $newStatus = $request->status instanceof MaintenanceStatus
                ? $request->status
                : MaintenanceStatus::from((int) $request->status);

            $request->tenant?->notify(new MaintenanceStatusChangedNotification($request, $oldStatus, $newStatus));
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
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

    public function messages(): HasMany
    {
        return $this->hasMany(MaintenanceMessage::class, 'request_id');
    }
}
