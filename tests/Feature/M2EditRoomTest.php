<?php

namespace Tests\Feature;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Livewire\M2\EditRoom;
use App\Livewire\M2\Room;
use App\Models\Property;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;

/**
 * /m2 Edit room. The rules are /m's EditRoom; pinned here is what /m2 adds: the
 * way in from the Room screen, the delete landing on /m2's own Home (never /m,
 * which can be switched off separately), and the note that a new room price
 * doesn't change the rent of the tenant already living there.
 */
class M2EditRoomTest extends M2TestCase
{
    /** @return array{0: User, 1: Property, 2: Unit} */
    private function vacantRoom(): array
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'B-7', 'Dara');
        $rental->update(['status' => RentalStatus::Vacated]);
        $rental->unit->update(['status' => UnitStatus::Available]);

        return [$landlord, $property, $rental->unit->fresh()];
    }

    public function test_the_room_screen_opens_edit_room(): void
    {
        [$landlord, $property, $unit] = $this->vacantRoom();
        $this->signIn($landlord, $property);

        Livewire::test(Room::class, ['unit' => $unit])
            ->assertSeeHtml('id="m2-room-edit"')
            ->assertSee(route('m2.room.edit', $unit));

        $this->get(route('m2.room.edit', $unit))
            ->assertOk()
            ->assertSee('id="m2-edit-room-form"', false);
    }

    public function test_saving_changes_the_room(): void
    {
        [$landlord, $property, $unit] = $this->vacantRoom();
        $this->signIn($landlord, $property);

        Livewire::test(EditRoom::class, ['unit' => $unit])
            ->assertSet('roomNumber', 'B-7')
            ->set('roomNumber', 'C-9')
            ->set('floorNumber', '3')
            ->set('roomType', 'Deluxe')
            ->set('rentAmount', '450000')
            ->set('rentCurrency', 'KHR')
            ->set('status', UnitStatus::Maintenance->value)
            ->set('maxOccupants', '4')
            ->set('description', 'Corner room')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('rw-m-toast', message: __('Room saved'));

        $unit->refresh();
        $this->assertSame('C-9', $unit->room_number);
        $this->assertSame('3', (string) $unit->floor_number);
        $this->assertSame('Deluxe', $unit->room_type);
        $this->assertSame(450000.0, (float) $unit->rent_amount);
        $this->assertSame('KHR', $unit->rent_currency);
        $this->assertSame(UnitStatus::Maintenance, $unit->status);
        $this->assertSame(4, (int) $unit->max_occupants);
        $this->assertSame('Corner room', $unit->description);
    }

    public function test_a_blank_room_number_is_an_error(): void
    {
        [$landlord, $property, $unit] = $this->vacantRoom();
        $this->signIn($landlord, $property);

        Livewire::test(EditRoom::class, ['unit' => $unit])
            ->set('roomNumber', '')
            ->call('save')
            ->assertHasErrors('roomNumber');

        $this->assertSame('B-7', $unit->fresh()->room_number);
    }

    public function test_deleting_an_empty_room_goes_to_m2_home(): void
    {
        [$landlord, $property, $unit] = $this->vacantRoom();
        $this->signIn($landlord, $property);

        Livewire::test(EditRoom::class, ['unit' => $unit])
            ->assertDontSeeHtml('id="m2-room-delete-card"')
            ->call('openDelete')
            ->assertSeeHtml('id="m2-room-delete-card"')
            ->call('cancelDelete')
            ->assertDontSeeHtml('id="m2-room-delete-card"')
            ->call('openDelete')
            ->call('delete')
            ->assertRedirect(route('m2.home'));

        $this->assertNull(Unit::find($unit->id));
    }

    public function test_a_room_with_a_tenant_cannot_be_deleted_and_says_why(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $this->signIn($landlord, $property);

        Livewire::test(EditRoom::class, ['unit' => $unit])
            ->assertDontSeeHtml('id="m2-room-delete"')
            ->assertSeeHtml('id="m2-room-status-hint"')
            ->assertSeeHtml('id="m2-room-rent-hint"')
            ->call('delete')
            ->assertSet('confirmingDelete', false)
            ->assertDispatched('rw-m-toast', message: __('End the tenancy before deleting this room.'))
            ->assertNoRedirect();

        $this->assertNotNull(Unit::find($unit->id));
    }

    /** Invoices bill the tenancy's own rent, which is why the screen warns about it. */
    public function test_a_new_room_price_leaves_the_current_tenants_rent_alone(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->signIn($landlord, $property);

        Livewire::test(EditRoom::class, ['unit' => $rental->unit])
            ->set('rentAmount', '999')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(999.0, (float) $rental->unit->fresh()->rent_amount);
        $this->assertSame(300.0, (float) $rental->fresh()->monthly_rent);
    }

    public function test_a_read_only_subscription_cannot_change_or_delete_the_room(): void
    {
        [$landlord, $property, $unit] = $this->vacantRoom();
        Subscription::withoutGlobalScopes()->where('landlord_id', $landlord->id)
            ->update(['ends_at' => now()->subDays(3), 'grace_ends_at' => null]);
        $this->signIn($landlord, $property);

        Livewire::test(Room::class, ['unit' => $unit])->assertDontSeeHtml('id="m2-room-edit"');

        Livewire::test(EditRoom::class, ['unit' => $unit])
            ->set('roomNumber', 'Nope')
            ->call('save')
            ->assertDispatched('rw-m-toast', message: __('Write actions are disabled until payment is completed.'))
            ->call('delete')
            ->assertNoRedirect();

        $this->assertSame('B-7', $unit->fresh()->room_number);
        $this->assertNotNull(Unit::find($unit->id));
    }

    public function test_another_landlords_room_is_not_found(): void
    {
        [, , $unit] = $this->vacantRoom();
        $this->signIn($this->landlord());

        $this->get('/m2/rooms/'.$unit->id.'/edit')->assertNotFound();
    }
}
