<?php

namespace App\Services;

use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Validation\Rule;

/**
 * Saving a room's own details (number, type, floor, rent, status, …) — the
 * write behind the panel's Edit room page (UnitResource\Pages\EditUnit), the /m
 * Edit room screen (Livewire\Mobile\EditRoom) and /m2's Add room
 * (Livewire\M2\AddRoom).
 *
 * Callers authorize ('update' or 'create' on the Unit) and validate first.
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

    /** The phone forms' rules for a room's details, by Livewire property name. */
    public static function formRules(): array
    {
        return [
            'roomNumber' => ['required', 'string', 'max:255'],
            'floorNumber' => ['nullable', 'string', 'max:255'],
            'roomType' => ['required', 'string', 'max:255'],
            'rentAmount' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'rentCurrency' => ['required', Rule::in(['USD', 'KHR'])],
            'maxOccupants' => ['nullable', 'integer', 'min:1', 'max:20'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** What formRules() calls each field in its messages. */
    public static function formLabels(): array
    {
        return [
            'roomNumber' => __('Room number'),
            'floorNumber' => __('Floor'),
            'roomType' => __('Room type'),
            'rentAmount' => __('Rent amount'),
            'rentCurrency' => __('Currency'),
            'maxOccupants' => __('Max occupants'),
            'description' => __('Notes'),
        ];
    }

    /** Whether another room of this property already has this number, ignoring case and spaces around it. */
    public static function numberTaken(int $propertyId, string $number, ?int $exceptUnitId = null): bool
    {
        return Unit::query()
            ->where('property_id', $propertyId)
            ->whereRaw('LOWER(room_number) = ?', [mb_strtolower(trim($number))])
            ->when($exceptUnitId, fn ($q) => $q->whereKeyNot($exceptUnitId))
            ->exists();
    }

    /**
     * Add one room to a property. The plan's room limit is the Unit model's own
     * creating hook: over it, this throws a ValidationException keyed `subscription`.
     */
    public function create(Property $property, array $data): Unit
    {
        return Unit::create(array_intersect_key($data, array_flip(static::FIELDS)) + [
            'property_id' => $property->id,
            'landlord_id' => $property->landlord_id,
            'status' => UnitStatus::Available,
        ]);
    }

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
