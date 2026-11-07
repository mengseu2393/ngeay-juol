<?php

namespace Tests\Feature;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Livewire\M2\AddRoom;
use App\Livewire\M2\EditRoom;
use App\Livewire\M2\Home;
use App\Livewire\M2\PropertySettings;
use App\Models\PropertySetting;
use App\Models\Subscription;
use App\Models\Unit;
use App\Support\Money;
use Livewire\Livewire;

/**
 * /m2 Add room: adding one room to a property that already exists (before this,
 * rooms could only be made together with a new property). Pinned: the room lands
 * on the chosen property and never another landlord's, a number can't repeat
 * within a property, and the plan's room limit shows as a message instead of a crash.
 */
class M2AddRoomTest extends M2TestCase
{
    protected function tearDown(): void
    {
        $this->forgetRememberedCurrencies();

        parent::tearDown();
    }

    /** Money remembers a property's currency for the request; a test process outlives one, and ids get reused. */
    private function forgetRememberedCurrencies(): void
    {
        (new \ReflectionProperty(Money::class, 'currencyByProperty'))->setValue(null, []);
    }

    public function test_home_and_property_settings_open_add_room(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->signIn($landlord, $property);

        Livewire::test(Home::class)
            ->assertSeeHtml('id="m2-add-room"')
            ->call('selectProperty', $property->id)
            ->assertSee(route('m2.add-room', ['property' => $property->id]));

        Livewire::test(PropertySettings::class)
            ->assertSeeHtml('id="m2-prop-add-room"')
            ->assertSee('from=settings', false);

        $this->get(route('m2.add-room'))->assertOk()->assertSee('id="m2-add-room-form"', false);
    }

    /** rooms/new must not be swallowed by the rooms/{unit} route. */
    public function test_the_address_is_not_taken_for_a_room_number(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $this->get('/m2/rooms/new')->assertOk()->assertSee('id="m2-add-room-screen"', false);
    }

    public function test_a_room_is_added_and_opens(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->signIn($landlord, $property);

        $add = Livewire::test(AddRoom::class)
            ->assertSet('propertyId', $property->id)
            ->set('roomNumber', ' C-9 ')
            ->set('floorNumber', '3')
            ->set('roomType', 'Deluxe')
            ->set('rentAmount', '250')
            ->set('maxOccupants', '2')
            ->set('description', ' Corner room ')
            ->call('save')
            ->assertHasNoErrors();

        $unit = Unit::query()->where('property_id', $property->id)->sole();
        $add->assertRedirect(route('m2.room', $unit));
        $this->assertSame(__('Room added'), session('m2-toast'));

        $this->assertSame('C-9', $unit->room_number);
        $this->assertSame('3', (string) $unit->floor_number);
        $this->assertSame('Deluxe', $unit->room_type);
        $this->assertSame(250.0, (float) $unit->rent_amount);
        $this->assertSame(2, (int) $unit->max_occupants);
        $this->assertSame('Corner room', $unit->description);
        $this->assertSame(UnitStatus::Available, $unit->status);
        $this->assertSame($landlord->id, $unit->landlord_id);
    }

    public function test_the_new_room_can_take_a_tenant_and_shows_on_home(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->signIn($landlord, $property);

        Livewire::test(AddRoom::class)->set('roomNumber', 'N-1')->set('rentAmount', '100')->call('save')->assertHasNoErrors();
        $unit = Unit::query()->where('room_number', 'N-1')->sole();

        Livewire::test(Home::class)->assertSeeHtml('id="m2-room-'.$unit->id.'"');
        $this->get(route('m2.room.add-tenant', $unit))->assertOk()->assertSee('id="m2-add-tenant-form"', false);
    }

    public function test_the_property_can_be_chosen_and_brings_its_currency(): void
    {
        $landlord = $this->landlord();
        $usd = $this->property($landlord, 'Alpha');
        $khr = $this->property($landlord, 'Beta');
        PropertySetting::updateOrCreate(['property_id' => $khr->id], ['currency' => 'KHR']);
        $this->forgetRememberedCurrencies();
        $this->signIn($landlord, $usd);

        Livewire::test(AddRoom::class)
            ->assertSeeHtml('id="m2-room-property"')
            ->assertSet('propertyId', $usd->id)
            ->assertSet('rentCurrency', 'USD')
            ->set('propertyId', $khr->id)
            ->assertSet('rentCurrency', 'KHR')
            ->set('roomNumber', 'K-1')
            ->set('rentAmount', '400000')
            ->call('save')
            ->assertHasNoErrors();

        $unit = Unit::query()->where('room_number', 'K-1')->sole();
        $this->assertSame($khr->id, $unit->property_id);
        $this->assertSame('KHR', $unit->rent_currency);
    }

    public function test_the_address_can_name_the_property(): void
    {
        $landlord = $this->landlord();
        $this->property($landlord, 'Alpha');
        $second = $this->property($landlord, 'Beta');
        $this->signIn($landlord);

        Livewire::withQueryParams(['property' => $second->id])
            ->test(AddRoom::class)
            ->assertSet('propertyId', $second->id);
    }

    public function test_one_property_needs_no_picker(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(AddRoom::class)->assertDontSeeHtml('id="m2-room-property"');
    }

    public function test_a_number_can_not_repeat_within_a_property(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->signIn($landlord, $property);

        Livewire::test(AddRoom::class)
            ->set('roomNumber', ' a-1 ')
            ->set('rentAmount', '100')
            ->call('save')
            ->assertHasErrors('roomNumber')
            ->assertNoRedirect();

        $this->assertSame(1, Unit::query()->where('property_id', $property->id)->count());
    }

    public function test_the_same_number_is_fine_in_another_property(): void
    {
        $landlord = $this->landlord();
        $this->rental($landlord, $this->property($landlord, 'Alpha'), 'A-1', 'Dara');
        $other = $this->property($landlord, 'Beta');
        $this->signIn($landlord, $other);

        Livewire::test(AddRoom::class)
            ->set('propertyId', $other->id)
            ->set('roomNumber', 'A-1')
            ->set('rentAmount', '100')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Unit::query()->where('property_id', $other->id)->count());
    }

    /** A deleted room's number is free again: nothing left in the list to confuse it with. */
    public function test_a_deleted_rooms_number_can_be_used_again(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $rental->update(['status' => RentalStatus::Vacated]);
        $rental->unit->delete();
        $this->signIn($landlord, $property);

        Livewire::test(AddRoom::class)->set('roomNumber', 'A-1')->set('rentAmount', '100')->call('save')->assertHasNoErrors();
    }

    public function test_edit_room_refuses_another_rooms_number_but_keeps_its_own(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->rental($landlord, $property, 'A-1', 'Dara');
        $unit = $this->rental($landlord, $property, 'A-2', 'Sok')->unit;
        $this->signIn($landlord, $property);

        Livewire::test(EditRoom::class, ['unit' => $unit])
            ->set('roomNumber', 'A-1')
            ->call('save')
            ->assertHasErrors('roomNumber');
        $this->assertSame('A-2', $unit->fresh()->room_number);

        Livewire::test(EditRoom::class, ['unit' => $unit])
            ->set('rentAmount', '999')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(999.0, (float) $unit->fresh()->rent_amount);
    }

    public function test_missing_fields_are_errors(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(AddRoom::class)
            ->set('roomNumber', '')
            ->set('roomType', '')
            ->set('rentAmount', 'abc')
            ->call('save')
            ->assertHasErrors(['roomNumber', 'roomType', 'rentAmount']);

        $this->assertSame(0, Unit::query()->count());
    }

    public function test_the_plans_room_limit_shows_a_message_and_adds_nothing(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->rental($landlord, $property, 'A-1', 'Dara');
        Subscription::withoutGlobalScopes()->where('landlord_id', $landlord->id)->update(['max_units' => 1]);
        $this->signIn($landlord, $property);

        Livewire::test(AddRoom::class)
            ->set('roomNumber', 'A-2')
            ->set('rentAmount', '100')
            ->call('save')
            ->assertHasErrors('subscription')
            ->assertSeeHtml('id="m2-add-room-limit"')
            ->assertNoRedirect();

        $this->assertSame(1, Unit::query()->where('property_id', $property->id)->count());
    }

    public function test_a_read_only_subscription_cannot_add_a_room(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->signIn($landlord, $property);

        $open = Livewire::test(AddRoom::class)->set('roomNumber', 'A-1')->set('rentAmount', '100');

        Subscription::withoutGlobalScopes()->where('landlord_id', $landlord->id)
            ->update(['ends_at' => now()->subDays(3), 'grace_ends_at' => null]);

        $open->call('save')->assertForbidden();
        Livewire::test(Home::class)->assertDontSeeHtml('id="m2-add-room"');
        Livewire::test(PropertySettings::class)->assertDontSeeHtml('id="m2-prop-add-room"');
        $this->get('/m2/rooms/new')->assertForbidden();

        $this->assertSame(0, Unit::query()->count());
    }

    public function test_a_room_cannot_be_added_to_another_landlords_property(): void
    {
        $other = $this->landlord();
        $theirs = $this->property($other, 'Theirs');
        $landlord = $this->landlord();
        $mine = $this->property($landlord, 'Mine');
        $this->signIn($landlord, $mine);

        // Through the address: falls back to their own property.
        Livewire::withQueryParams(['property' => $theirs->id])
            ->test(AddRoom::class)
            ->assertSet('propertyId', $mine->id);

        // Through a tampered form: not found, nothing created.
        Livewire::test(AddRoom::class)
            ->set('propertyId', $theirs->id)
            ->set('roomNumber', 'X-1')
            ->set('rentAmount', '100')
            ->call('save')
            ->assertNotFound();

        $this->assertSame(0, Unit::withoutGlobalScopes()->where('property_id', $theirs->id)->count());
    }

    public function test_with_no_property_there_is_nothing_to_add_to(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord);

        Livewire::test(Home::class)->assertDontSeeHtml('id="m2-add-room"');
        $this->get('/m2/rooms/new')->assertNotFound();
    }
}
