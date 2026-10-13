<?php

namespace App\Services;

use App\Models\Unit;

/**
 * Saving a room's own details (number, type, floor, rent, status, …) — the
 * write behind the panel's Edit room page (UnitResource\Pages\EditUnit) and
 * the /m Edit room screen (Livewire\Mobile\EditRoom).
 *
 * Callers authorize ('update' on the Unit) and validate first.
 */
class RoomDetailsService
{
    /** The columns a room's edit form may change. */
    public const FIELDS = [
        'property_id',
        'room_number',
        'floor_number',
        'room_type',
        'rent_amount',
        'rent_currency',
        'due_date',
        'status',
        'max_occupants',
        'description',
    ];

    public function update(Unit $unit, array $data): Unit
    {
        $unit->update(array_intersect_key($data, array_flip(static::FIELDS)));

        return $unit;
    }

    /**
     * Delete a room. Refused while someone lives in it: ending the tenancy
     * first keeps its invoices and readings attached to a tenancy that ended,
     * rather than to a room that vanished under it.
     */
    public function delete(Unit $unit): void
    {
        if ($unit->activeRental()->exists()) {
            throw new \DomainException(__('End the tenancy before deleting this room.'));
        }

        $unit->delete();
    }
}
