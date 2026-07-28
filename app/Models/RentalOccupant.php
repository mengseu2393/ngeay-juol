<?php

namespace App\Models;

use App\Enums\OccupantRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class RentalOccupant extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'rental_id',
        'user_id',
        'role',
        'occupant_name',
        'occupant_phone',
        'occupant_id_card',
        'occupant_address',
        'occupant_gender',
        'occupant_dob',
        'occupant_nationality',
        'occupant_workplace',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relationship',
        'guarantor_name',
        'guarantor_phone',
        'guarantor_id_number',
        'guarantor_address',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'occupant_dob' => 'date',
            'role'         => OccupantRole::class,
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    /** Optional linked user account (for tenants who have app access). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // -------------------------------------------------------------------------
    // Media
    // -------------------------------------------------------------------------

    public function registerMediaCollections(): void
    {
        // ID-card / document photos for this occupant.
        $this->addMediaCollection('id_cards');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isPrimary(): bool
    {
        return $this->role === OccupantRole::Primary;
    }
}
