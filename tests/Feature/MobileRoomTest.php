<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\ReadingType;
use App\Enums\UnitStatus;
use App\Livewire\Mobile\Room;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * /m/rooms/{unit}. It runs the same room logic as Simple Mode's room screen and
 * the panel's room page (Concerns\ManagesUnit); these pin that the phone screen
 * applies it — readings, resets and corrections through their services, a toast
 * and a closed sheet when one is done — and that it only ever links to /m.
 */
class MobileRoomTest extends MobileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-20 09:00:00');
    }

    private function vacantRoom(User $landlord, Property $property, string $room = '101'): Unit
    {
        return Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => $room,
            'room_type' => 'Standard',
            'rent_amount' => 500,
            'status' => UnitStatus::Available,
        ]);
    }

    private function meteredUtility(User $landlord, Property $property, string $name = 'Electricity'): PropertyUtility
    {
        return PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => $name,
            'billing_type' => BillingType::Metered,
            'rate' => 0.15,
            'unit_of_measure' => 'kWh',
        ]);
    }

    private function reading(Unit $unit, PropertyUtility $utility, string $date, float $used, float $new): UtilityUsage
    {
        return UtilityUsage::create([
            'unit_id' => $unit->id, 'property_utility_id' => $utility->id,
            'reading_type' => ReadingType::Actual, 'reading_date' => $date,
            'old_reading' => $new - $used, 'new_reading' => $new, 'amount_used' => $used,
            'recorded_by_id' => $unit->landlord_id,
        ]);
    }

    public function test_an_occupied_room_shows_its_agreement_and_links_to_mobile_screens(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Sok Dara');

        $html = $this->signIn($landlord, $property)
            ->get(route('mobile.room', $rental->unit_id))
            ->assertOk()
            ->assertSee('Sok Dara')
            ->assertSee('href="'.route('mobile.rooms').'"', false)
            ->assertSee('href="'.route('mobile.tenant', $rental).'"', false)
            ->assertSee('href="'.route('mobile.room.end-tenancy', $rental->unit_id).'"', false)
            ->assertSee('href="'.route('mobile.room.edit', $rental->unit_id).'"', false)
            ->assertDontSee('href="'.route('mobile.room.add-tenant', $rental->unit_id).'"', false)
            ->getContent();

        $this->assertStringNotContainsString('/app/', $html);
        $this->assertStringNotContainsString('rw-sm-', $html);
    }

    public function test_a_vacant_room_offers_add_tenant(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);

        $this->signIn($landlord, $property)
            ->get(route('mobile.room', $room))
            ->assertOk()
            ->assertSee(__('No active agreement'))
            ->assertSee('href="'.route('mobile.room.add-tenant', $room).'"', false)
            ->assertDontSee('href="'.route('mobile.room.end-tenancy', $room).'"', false);
    }

    /** A deep link to a room of another property switches to that property, like the panel's room page. */
    public function test_opening_a_room_makes_its_property_active(): void
    {
        $landlord = $this->landlord();
        $home = $this->property($landlord, 'Home');
        $other = $this->property($landlord, 'Other');
        $room = $this->vacantRoom($landlord, $other);

        $this->signIn($landlord, $home)->get(route('mobile.room', $room))->assertOk();

        $this->assertSame($other->id, ActiveProperty::id());
    }

    public function test_another_landlords_room_is_not_found(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $stranger = $this->landlord();
        $foreign = $this->vacantRoom($stranger, $this->property($stranger));

        $this->signIn($landlord, $property)
            ->get(route('mobile.room', $foreign))
            ->assertNotFound();
    }

    /** The room is fixed when the screen opens; a tampered request can't point it elsewhere. */
    public function test_the_room_cannot_be_swapped_for_another_from_the_browser(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);
        $stranger = $this->landlord();
        $foreign = $this->vacantRoom($stranger, $this->property($stranger));

        $this->signIn($landlord, $property);
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(Room::class, ['unit' => $room])->set('unitId', $foreign->id);
    }

    public function test_recording_a_reading_measures_from_the_previous_one_and_toasts(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);
        $utility = $this->meteredUtility($landlord, $property);
        $this->reading($room, $utility, '2026-02-20', 100, 480);

        $this->signIn($landlord, $property);

        Livewire::test(Room::class, ['unit' => $room])
            ->assertSeeHtml('id="rw-m-room-record"')
            ->assertDontSeeHtml('id="rw-m-reading-form"')
            ->call('openPanel', 'reading')
            ->assertSeeHtml('id="rw-m-reading-form"')
            ->set('newReading', '530')
            ->call('saveReading')
            ->assertHasNoErrors()
            ->assertSet('panel', null)
            ->assertSet('notice', null)
            ->assertDispatched('rw-m-sheet-close', name: 'reading')
            ->assertDispatched('rw-m-toast', message: __('Meter reading saved.'));

        $this->assertEquals(50, (float) UtilityUsage::where('new_reading', 530)->value('amount_used'));
    }

    public function test_a_lower_reading_is_rejected_and_the_sheet_stays_open(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);
        $utility = $this->meteredUtility($landlord, $property);
        $this->reading($room, $utility, '2026-02-20', 100, 480);

        $this->signIn($landlord, $property);

        Livewire::test(Room::class, ['unit' => $room])
            ->call('openPanel', 'reading')
            ->set('newReading', '400')
            ->call('saveReading')
            ->assertHasErrors('newReading')
            ->assertSet('panel', 'reading')
            ->assertNotDispatched('rw-m-toast');
    }

    /** Saving without opening the form first (a forged call) is refused. */
    public function test_a_reading_needs_the_form_opened_first(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);
        $this->meteredUtility($landlord, $property);

        $this->signIn($landlord, $property);

        Livewire::test(Room::class, ['unit' => $room])
            ->set('newReading', '10')
            ->call('saveReading')
            ->assertForbidden();

        $this->assertSame(0, UtilityUsage::count());
    }

    public function test_another_propertys_utility_cannot_be_selected(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);
        $this->meteredUtility($landlord, $property);
        $stranger = $this->landlord();
        $foreignUtility = $this->meteredUtility($stranger, $this->property($stranger));

        $this->signIn($landlord, $property);

        Livewire::test(Room::class, ['unit' => $room])
            ->call('selectUtility', $foreignUtility->id)
            ->assertNotFound();
    }

    public function test_switching_utility_shows_its_own_history(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);
        $electricity = $this->meteredUtility($landlord, $property, 'Electricity');
        $water = $this->meteredUtility($landlord, $property, 'Water');
        $power = $this->reading($room, $electricity, '2026-02-20', 100, 480);
        $tap = $this->reading($room, $water, '2026-02-21', 5, 77);

        $this->signIn($landlord, $property);

        Livewire::test(Room::class, ['unit' => $room])
            ->assertSet('utilityId', $electricity->id)
            ->assertSeeHtml('id="rw-m-reading-'.$power->id.'"')
            ->assertDontSeeHtml('id="rw-m-reading-'.$tap->id.'"')
            ->call('selectUtility', $water->id)
            ->assertSeeHtml('id="rw-m-reading-'.$tap->id.'"')
            ->assertDontSeeHtml('id="rw-m-reading-'.$power->id.'"');
    }

    public function test_reset_needs_confirmation_keeps_history_and_toasts(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);
        $utility = $this->meteredUtility($landlord, $property);
        $this->reading($room, $utility, '2026-02-20', 100, 480);

        $this->signIn($landlord, $property);

        $screen = Livewire::test(Room::class, ['unit' => $room])
            ->call('openPanel', 'reset')
            ->assertSeeHtml('id="rw-m-reset-form"')
            ->set('startingReading', '0')
            ->call('resetReading')
            ->assertHasErrors('confirmReset')
            ->assertNotDispatched('rw-m-toast');
        $this->assertSame(1, UtilityUsage::count());

        $screen->set('confirmReset', true)
            ->call('resetReading')
            ->assertHasNoErrors()
            ->assertDispatched('rw-m-sheet-close', name: 'reset')
            ->assertDispatched('rw-m-toast', message: __('Reading reset. The next reading will be measured from the new starting point.'));

        $this->assertSame(2, UtilityUsage::count());
        $this->assertTrue(UtilityUsage::where('is_reset', true)->exists());
    }

    public function test_correcting_a_past_reading_needs_confirmation_and_rechains_the_next(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);
        $utility = $this->meteredUtility($landlord, $property);
        $feb = $this->reading($room, $utility, '2026-02-20', 20, 120);
        $mar = $this->reading($room, $utility, '2026-03-10', 20, 140);

        $this->signIn($landlord, $property);

        $screen = Livewire::test(Room::class, ['unit' => $room])
            ->assertSeeHtml('id="rw-m-reading-edit-'.$feb->id.'"')
            ->call('edit', $feb->id)
            ->assertSeeHtml('id="rw-m-correct-form"')
            ->set('editReading', '130')
            ->call('reviewEdit')
            ->assertSet('confirming', true)
            ->assertSeeHtml('id="rw-m-correct-confirm-form"')
            ->call('saveEdit')
            ->assertHasErrors('confirmedEdit');
        $this->assertEquals(120, (float) $feb->fresh()->new_reading);

        $screen->set('confirmedEdit', true)
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('editingId', null)
            ->assertDispatched('rw-m-sheet-close', name: 'correct')
            ->assertDispatched('rw-m-toast', message: __('Reading corrected.'));

        $this->assertEquals(130, (float) $feb->fresh()->new_reading);
        $this->assertEquals(10, (float) $mar->fresh()->amount_used);
    }

    /** A reading of another room (or another landlord's) can't be opened for correction. */
    public function test_a_reading_of_another_room_cannot_be_corrected(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property, '101');
        $neighbour = $this->vacantRoom($landlord, $property, '102');
        $utility = $this->meteredUtility($landlord, $property);
        $theirs = $this->reading($neighbour, $utility, '2026-02-20', 20, 120);

        $this->signIn($landlord, $property);

        try {
            Livewire::test(Room::class, ['unit' => $room])->call('edit', $theirs->id);
            $this->fail('Another room\'s reading was opened.');
        } catch (ModelNotFoundException) {
            // A 404 over HTTP.
        }

        $this->assertEquals(120, (float) $theirs->fresh()->new_reading);
    }

    public function test_a_room_without_meters_says_so(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $room = $this->vacantRoom($landlord, $property);

        $this->signIn($landlord, $property);

        Livewire::test(Room::class, ['unit' => $room])
            ->assertSee(__('No metered utilities are set up for this property yet.'))
            ->assertDontSeeHtml('id="rw-m-room-record"');
    }
}
