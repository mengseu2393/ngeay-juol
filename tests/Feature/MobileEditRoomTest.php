<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\UnitStatus;
use App\Livewire\Mobile\EditRoom;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * /m/rooms/{unit}/edit — the phone version of the panel's Edit room page. It
 * saves through the same services (RoomDetailsService, RoomMeterReadingsService);
 * these pin the screen: what it shows, what it saves, and who may.
 */
class MobileEditRoomTest extends MobileTestCase
{
    public function test_it_shows_the_room_filled_in_without_any_filament(): void
    {
        [$landlord, $property, $unit] = $this->vacantRoom();

        $this->signIn($landlord, $property)
            ->get(route('mobile.room.edit', $unit))
            ->assertOk()
            ->assertSee(__('Edit room'))
            ->assertSee(__('Room :room', ['room' => 'B-7']))
            ->assertSee(route('mobile.room', $unit), false)
            ->assertDontSee('fi-', false)
            ->assertDontSee('/app/', false);
    }

    public function test_it_saves_the_rooms_details(): void
    {
        [$landlord, $property, $unit] = $this->vacantRoom();
        $this->signIn($landlord, $property);

        Livewire::test(EditRoom::class, ['unit' => $unit])
            ->assertSet('rentAmount', '250')
            ->set('roomNumber', ' B-8 ')
            ->set('floorNumber', '2')
            ->set('roomType', 'Deluxe')
            ->set('rentAmount', '275.50')
            ->set('rentCurrency', 'KHR')
            ->set('status', UnitStatus::Maintenance->value)
            ->set('maxOccupants', '3')
            ->set('description', 'Fan, no AC')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('rw-m-toast', message: __('Room saved'));

        $unit->refresh();
        $this->assertSame('B-8', $unit->room_number);
        $this->assertSame('2', $unit->floor_number);
        $this->assertSame('Deluxe', $unit->room_type);
        $this->assertEquals(275.5, $unit->rent_amount);
        $this->assertSame('KHR', $unit->rent_currency);
        $this->assertSame(UnitStatus::Maintenance, $unit->status);
        $this->assertSame(3, $unit->max_occupants);
        $this->assertSame('Fan, no AC', $unit->description);
    }

    public function test_invalid_input_shows_errors_and_saves_nothing(): void
    {
        [$landlord, $property, $unit] = $this->vacantRoom();
        $this->signIn($landlord, $property);

        Livewire::test(EditRoom::class, ['unit' => $unit])
            ->set('roomNumber', '')
            ->set('rentAmount', 'lots')
            ->set('maxOccupants', '50')
            ->set('rentCurrency', 'EUR')
            ->call('save')
            ->assertHasErrors(['roomNumber', 'rentAmount', 'maxOccupants', 'rentCurrency']);

        $this->assertSame('B-7', $unit->fresh()->room_number);
    }

    public function test_another_landlords_room_is_not_found(): void
    {
        [$landlord, $property] = $this->vacantRoom();
        [, , $foreign] = $this->vacantRoom();

        $this->signIn($landlord, $property)
            ->get(route('mobile.room.edit', $foreign))
            ->assertNotFound();
    }

    public function test_without_the_update_permission_saving_is_forbidden(): void
    {
        [$landlord, $property, $unit] = $this->vacantRoom();
        Role::findByName('landlord')->revokePermissionTo('update_unit');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->signIn($landlord->fresh(), $property);

        Livewire::test(EditRoom::class, ['unit' => $unit])
            ->set('roomNumber', 'Nope')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('B-7', $unit->fresh()->room_number);
    }

    public function test_a_read_only_subscription_cannot_change_the_room(): void
    {
        [$landlord, $property, $unit] = $this->vacantRoom();
        Subscription::withoutGlobalScopes()->where('landlord_id', $landlord->id)
            ->update(['ends_at' => now()->subDays(3), 'grace_ends_at' => null]);
        $this->signIn($landlord, $property);

        Livewire::test(EditRoom::class, ['unit' => $unit])
            ->set('roomNumber', 'Nope')
            ->call('save')
            ->assertDispatched('rw-m-toast', message: __('Write actions are disabled until payment is completed.'));

        $this->assertSame('B-7', $unit->fresh()->room_number);
    }

    public function test_meter_readings_are_saved_from_the_sheet(): void
    {
        [$landlord, $property, $unit] = $this->vacantRoom();
        $electricity = PropertyUtility::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id, 'name' => 'Electricity',
            'billing_type' => BillingType::Metered, 'rate' => 0.25, 'unit_of_measure' => 'kWh', 'is_active' => true,
        ]);
        $this->signIn($landlord, $property);

        Livewire::test(EditRoom::class, ['unit' => $unit])
            ->assertSee(__('Record meter readings'))
            ->call('saveReadings')
            ->assertHasErrors('meters')
            ->set('readingDate', now()->addDay()->toDateString())
            ->set("meters.{$electricity->id}", '1520')
            ->call('saveReadings')
            ->assertHasErrors('readingDate')
            ->set('readingDate', now()->toDateString())
            ->call('saveReadings')
            ->assertHasNoErrors()
            ->assertDispatched('rw-m-sheet-close', name: 'room-readings')
            ->assertSet('meters', []);

        $this->assertEquals(1520, UtilityUsage::where('unit_id', $unit->id)->value('new_reading'));
    }

    public function test_a_vacant_room_can_be_deleted_but_an_occupied_one_cannot(): void
    {
        [$landlord, $property, $unit] = $this->vacantRoom();
        $occupied = $this->rental($landlord, $property, 'C-1', 'Dara')->unit;
        $this->signIn($landlord, $property);

        Livewire::test(EditRoom::class, ['unit' => $occupied])
            ->assertDontSeeHtml('id="rw-m-room-delete-open"')
            ->call('delete')
            ->assertDispatched('rw-m-toast', message: __('End the tenancy before deleting this room.'))
            ->assertNoRedirect();
        $this->assertNotNull(Unit::find($occupied->id));

        Livewire::test(EditRoom::class, ['unit' => $unit])
            ->assertSeeHtml('id="rw-m-room-delete-open"')
            ->call('delete')
            ->assertRedirect(route('mobile.rooms'));
        $this->assertSoftDeleted('units', ['id' => $unit->id]);
    }

    /** @return array{0: User, 1: Property, 2: Unit} */
    private function vacantRoom(): array
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => 'B-7',
            'room_type' => 'Standard',
            'rent_amount' => 250,
            'status' => UnitStatus::Available,
        ]);

        return [$landlord, $property, $unit];
    }
}
