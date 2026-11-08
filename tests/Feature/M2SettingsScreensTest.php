<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\FirstMonthBillingMode;
use App\Enums\PropertyType;
use App\Livewire\M2\AddProperty;
use App\Livewire\M2\PropertySettings;
use App\Livewire\M2\UtilityRate;
use App\Livewire\M2\UtilityRates;
use App\Livewire\M2\UtilityReadingHistory;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Support\ActiveProperty;
use Livewire\Livewire;

/**
 * /m2's own settings screens: Property settings (a hub of five sections, each
 * its own screen), Add property, and Utility rates (list, add/edit, reading
 * history). Pinned: they never leave /m2 (no /m or panel links), and the saving
 * is the shared /m / Simple Mode logic — so these tests check that a save lands
 * and where the screen goes afterwards, not every validation rule again (the
 * Mobile* tests cover those).
 */
class M2SettingsScreensTest extends M2TestCase
{
    // --- Property settings ---

    public function test_the_hub_lists_the_sections_and_the_other_properties_inside_m2(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord, 'Sunrise Apartments');
        $this->property($landlord, 'Riverside House');
        $this->utility($property, 'Electricity');
        $this->signIn($landlord, $property);

        $this->get(route('m2.property'))
            ->assertOk()
            ->assertSee('Sunrise Apartments')
            ->assertSee('Riverside House')
            ->assertSee(route('m2.property.section', 'details'), false)
            ->assertSee(route('m2.property.section', 'billing'), false)
            ->assertSee(route('m2.property.section', 'move-in'), false)
            ->assertSee(route('m2.property.section', 'contacts'), false)
            ->assertSee(route('m2.property.section', 'opening'), false)
            ->assertSee(route('m2.add-property'), false)
            ->assertDontSee('/m/settings', false)
            ->assertDontSee('/app/', false);
    }

    public function test_another_property_opens_from_the_hub(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord, 'Sunrise Apartments');
        $other = $this->property($landlord, 'Riverside House');
        $this->signIn($landlord, $property);

        Livewire::test(PropertySettings::class)
            ->call('switchTo', $other->id)
            ->assertRedirect(route('m2.property'));

        $this->assertSame($other->id, ActiveProperty::id());
    }

    public function test_another_landlords_property_cannot_be_switched_to(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $stranger = $this->landlord();
        $theirs = $this->property($stranger, 'Not yours');
        $this->signIn($landlord, $property);

        Livewire::test(PropertySettings::class)->call('switchTo', $theirs->id)->assertNotFound();
        $this->assertSame($property->id, ActiveProperty::id());
    }

    public function test_each_section_is_its_own_screen_and_an_unknown_one_is_not_found(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $this->get(route('m2.property.section', 'details'))->assertOk()->assertSee('id="m2-prop-details-form"', false)->assertDontSee('id="m2-prop-billing-form"', false);
        $this->get(route('m2.property.section', 'billing'))->assertOk()->assertSee('id="m2-prop-billing-form"', false)->assertSee('id="m2-prop-monthly"', false);
        $this->get(route('m2.property.section', 'move-in'))->assertOk()->assertSee('id="m2-prop-move-in-form"', false);
        $this->get(route('m2.property.section', 'contacts'))->assertOk()->assertSee('id="m2-prop-contacts-form"', false);
        $this->get('/m2/settings/property/nope')->assertNotFound();
    }

    /** Opening readings only exist for metered utilities — without one, the section is not offered. */
    public function test_opening_readings_without_a_metered_utility_goes_back_to_the_hub(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(PropertySettings::class, ['section' => 'opening'])->assertRedirect(route('m2.property'));
        $this->get(route('m2.property'))->assertDontSee(route('m2.property.section', 'opening'), false);
    }

    public function test_saving_details_goes_back_to_the_hub_with_a_toast(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord, 'Old name');
        $this->signIn($landlord, $property);

        Livewire::test(PropertySettings::class, ['section' => 'details'])
            ->set('details.name', 'Sunrise Apartments')
            ->set('details.property_type', PropertyType::RoomRental->value)
            ->set('details.address_line', 'Street 271, #24')
            ->call('saveDetails')
            ->assertHasNoErrors()
            ->assertRedirect(route('m2.property'));

        $property->refresh();
        $this->assertSame('Sunrise Apartments', $property->name);
        $this->assertSame(PropertyType::RoomRental, $property->property_type);
        $this->assertSame(__('Property details saved'), session('m2-toast'));
    }

    public function test_billing_move_in_and_contacts_save_from_their_screens(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->signIn($landlord, $property);

        Livewire::test(PropertySettings::class, ['section' => 'billing'])
            ->set('billing.currency', 'KHR')
            ->set('billing.invoice_prefix', 'SUN')
            ->set('billing.due_day_of_month', '7')
            ->set('billing.invoice_due_days', '7')
            ->call('saveBilling')
            ->assertHasNoErrors()
            ->assertRedirect(route('m2.property'));

        Livewire::test(PropertySettings::class, ['section' => 'billing'])
            ->call('setMonthlyBilling', true)
            ->assertDispatched('rw-m-toast');

        Livewire::test(PropertySettings::class, ['section' => 'move-in'])
            ->set('moveIn.first_month_billing_mode', FirstMonthBillingMode::HalfMonth->value)
            ->set('moveIn.proration_cutoff_day', '20')
            ->set('moveIn.create_invoice_on_move_in', true)
            ->call('saveMoveIn')
            ->assertHasNoErrors()
            ->assertRedirect(route('m2.property'));

        Livewire::test(PropertySettings::class, ['section' => 'contacts'])
            ->set('contacts.caretaker_name', 'Vuthy Ouk')
            ->set('contacts.caretaker_phone', '012 345 678')
            ->call('saveContacts')
            ->assertHasNoErrors()
            ->assertRedirect(route('m2.property'));

        $setting = PropertySetting::where('property_id', $property->id)->firstOrFail();
        $this->assertSame('KHR', $setting->currency);
        $this->assertSame('SUN', $setting->invoice_prefix);
        $this->assertSame(7, (int) $setting->due_day_of_month);
        $this->assertTrue((bool) $setting->monthly_billing_enabled);
        $this->assertSame(FirstMonthBillingMode::HalfMonth, $setting->first_month_billing_mode);
        $this->assertSame(20, (int) $setting->proration_cutoff_day);
        $this->assertTrue((bool) $setting->create_invoice_on_move_in);
        $this->assertSame('Vuthy Ouk', $setting->caretaker_name);

        // The hub reads the saved values back.
        $this->get(route('m2.property'))->assertSee('id="m2-prop-billing-on"', false)->assertSee('Vuthy Ouk');
    }

    public function test_a_validation_error_keeps_the_section_open(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(PropertySettings::class, ['section' => 'details'])
            ->set('details.name', '')
            ->call('saveDetails')
            ->assertHasErrors('details.name')
            ->assertNoRedirect();
    }

    public function test_opening_readings_are_saved_from_their_screen(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $electricity = $this->utility($property, 'Electricity');
        $room = Unit::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id,
            'room_number' => 'A-101', 'room_type' => 'Room', 'rent_amount' => 100,
        ]);
        $this->signIn($landlord, $property);

        Livewire::test(PropertySettings::class, ['section' => 'opening'])
            ->assertSet('openingUtilityId', $electricity->id)
            ->assertSee('A-101')
            ->set("openingReadings.{$room->id}", '1120')
            ->call('saveOpeningReadings')
            ->assertHasNoErrors()
            ->assertDispatched('rw-m-toast');

        $this->assertEquals(1120, UtilityUsage::where('unit_id', $room->id)->value('new_reading'));
    }

    // --- Add property ---

    public function test_add_property_creates_it_and_opens_its_rooms(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord, 'Existing'));

        $this->get(route('m2.add-property'))->assertOk()->assertSee('id="m2-new-with-rooms"', false)->assertDontSee('/app/', false);

        Livewire::test(AddProperty::class)
            ->set('name', 'Sunrise Apartments')
            ->set('rates.Electricity', '0.25')
            ->set('withRooms', true)
            ->set('floors', '2')
            ->set('perFloor', '5')
            ->set('roomPrice', '300')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('m2.home'));

        $new = Property::where('name', 'Sunrise Apartments')->firstOrFail();
        $this->assertSame($new->id, ActiveProperty::id());
        $this->assertSame(10, Unit::where('property_id', $new->id)->count());
        $this->assertSame(__('Property added'), session('m2-toast'));
    }

    /**
     * The owner: "Generate rooms should work the same as register". Like the setup wizard, Add property
     * now generates the rooms into a list first, where any room's number or price can be changed and
     * rooms added or removed — and exactly that list is saved.
     */
    public function test_add_property_generates_rooms_that_can_be_edited_before_saving(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord, 'Existing'));

        Livewire::test(AddProperty::class)
            ->set('name', 'Garden Rooms')
            ->set('floors', '2')
            ->set('perFloor', '2')
            ->set('roomPrice', '60')
            ->call('generateRooms')
            ->assertSet('grid', fn ($grid) => array_column($grid, 'number') === ['101', '102', '201', '202'])
            ->assertSeeHtml('id="m2-new-room-list"')
            ->set('grid.1.price', '75')
            ->set('grid.3.number', 'B-1')
            ->call('removeRoom', 2)
            ->call('addRoom')
            ->set('grid.3.number', 'Shop')
            ->set('grid.3.price', '200')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('m2.home'));

        $new = Property::where('name', 'Garden Rooms')->firstOrFail();
        $this->assertEquals(
            ['101' => 60.0, '102' => 75.0, 'B-1' => 60.0, 'Shop' => 200.0],
            Unit::where('property_id', $new->id)->pluck('rent_amount', 'room_number')->map(fn ($p) => (float) $p)->sortKeys()->all(),
        );
    }

    public function test_add_property_rejects_a_room_number_used_twice(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord, 'Existing'));

        Livewire::test(AddProperty::class)
            ->set('name', 'Twin Rooms')
            ->set('floors', '1')
            ->set('perFloor', '2')
            ->set('roomPrice', '60')
            ->call('generateRooms')
            ->set('grid.1.number', '101')
            ->call('save')
            ->assertHasErrors(['grid.0.number']);

        $this->assertDatabaseMissing('properties', ['name' => 'Twin Rooms']);
    }

    public function test_add_property_without_rooms_opens_its_settings(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord, 'Existing'));

        Livewire::test(AddProperty::class)
            ->set('name', 'Riverside House')
            ->set('withRooms', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('m2.property'));
    }

    // --- Utility rates ---

    public function test_the_utility_list_filters_and_each_row_opens_its_edit_screen(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $electricity = $this->utility($property, 'Electricity');
        $internet = $this->utility($property, 'Internet', BillingType::Flat, 10);
        $internet->update(['is_active' => false]);
        $this->signIn($landlord, $property);

        $this->get(route('m2.utilities'))
            ->assertOk()
            ->assertSee(route('m2.utilities.edit', $electricity), false)
            ->assertSee(route('m2.utilities.new'), false)
            ->assertSee(route('m2.utilities.history'), false)
            ->assertDontSee('/m/settings', false);

        Livewire::test(UtilityRates::class)
            ->set('filter', 'inactive')
            ->assertSee(route('m2.utilities.edit', $internet), false)
            ->assertDontSee(route('m2.utilities.edit', $electricity), false);
    }

    public function test_a_utility_is_added_and_edited_and_the_list_shows_a_toast(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->signIn($landlord, $property);

        Livewire::test(UtilityRate::class)
            ->set('form.name', 'Parking')
            ->assertSet('form.unit_of_measure', 'month')
            ->assertSet('form.billing_type', BillingType::Flat->value)
            ->set('form.rate', '5')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('m2.utilities'));

        $parking = PropertyUtility::where('property_id', $property->id)->where('name', 'Parking')->firstOrFail();
        $this->assertEquals(5, $parking->rate);
        $this->assertSame(__('Utility added.'), session('m2-toast'));

        Livewire::test(UtilityRate::class, ['utility' => $parking->id])
            ->assertSet('form.rate', '5')
            ->set('form.rate', '6.5')
            ->set('form.provider', 'City')
            ->call('submit')
            ->assertRedirect(route('m2.utilities'));

        $this->assertEquals(6.5, $parking->fresh()->rate);
        $this->assertSame('City', $parking->fresh()->provider);
    }

    public function test_a_bad_rate_stays_on_the_screen_with_the_error(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->signIn($landlord, $property);

        Livewire::test(UtilityRate::class)
            ->set('form.rate', 'abc')
            ->call('submit')
            ->assertHasErrors('rate')
            ->assertNoRedirect();
    }

    /** Delete is two taps on the same screen: nothing is removed until the confirm. */
    public function test_a_utility_is_turned_off_and_deleted_only_after_the_confirm(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $water = $this->utility($property, 'Water');
        $this->signIn($landlord, $property);

        Livewire::test(UtilityRate::class, ['utility' => $water->id])
            ->call('toggle')
            ->assertRedirect(route('m2.utilities'));
        $this->assertFalse($water->fresh()->is_active);

        Livewire::test(UtilityRate::class, ['utility' => $water->id])
            ->call('askDelete')
            ->assertSet('confirmingDelete', true)
            ->assertSeeHtml('id="m2-utility-delete-confirm"')
            ->call('cancelDelete')
            ->assertSet('confirmingDelete', false);
        $this->assertNotNull($water->fresh());

        Livewire::test(UtilityRate::class, ['utility' => $water->id])
            ->call('askDelete')
            ->call('destroy')
            ->assertRedirect(route('m2.utilities'));
        $this->assertNull(PropertyUtility::find($water->id));
    }

    public function test_another_propertys_utility_is_not_found(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $elsewhere = $this->utility($this->property($landlord, 'Elsewhere'), 'Electricity');
        $this->signIn($landlord, $property);

        $this->get(route('m2.utilities.edit', $elsewhere))->assertNotFound();
    }

    // --- Reading history ---

    public function test_reading_history_lists_a_rooms_readings_and_switches_room(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $electricity = $this->utility($property, 'Electricity');
        $a = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $b = $this->rental($landlord, $property, 'B-1', 'Sok')->unit;
        $this->reading($landlord, $a, $electricity, '2026-09-10', 1210, 38);
        $this->reading($landlord, $b, $electricity, '2026-10-10', 777, 40);
        $this->signIn($landlord, $property);

        $this->get(route('m2.utilities.history'))->assertOk()->assertSee('1,172 → 1,210', false);

        Livewire::test(UtilityReadingHistory::class)
            ->assertSet('unitId', $a->id)
            ->assertSee('38 kWh')
            ->call('selectUnit', $b->id)
            ->assertSet('unitId', $b->id)
            ->assertSet('utilityId', $electricity->id)
            ->assertSee('40 kWh')
            ->assertDontSee('38 kWh');
    }

    public function test_reading_history_corrects_a_reading_after_the_confirm(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $electricity = $this->utility($property, 'Electricity');
        $room = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $reading = $this->reading($landlord, $room, $electricity, '2026-10-10', 1250, 40);
        $this->signIn($landlord, $property);

        Livewire::test(UtilityReadingHistory::class)
            ->call('edit', $reading->id)
            ->set('newReading', '1260')
            ->call('review')
            ->assertSet('confirming', true)
            ->call('save')
            ->assertHasErrors('confirmed')
            ->set('confirmed', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('rw-m-toast');

        $this->assertEquals(1260, $reading->fresh()->new_reading);
    }

    public function test_a_room_of_another_property_cannot_be_picked(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->utility($property, 'Electricity');
        $this->rental($landlord, $property, 'A-1', 'Dara');
        $other = $this->property($landlord, 'Elsewhere');
        $theirs = $this->rental($landlord, $other, 'Z-9', 'Nita')->unit;
        $this->signIn($landlord, $property);

        Livewire::test(UtilityReadingHistory::class)->call('selectUnit', $theirs->id)->assertNotFound();
    }

    // --- Toast ---

    public function test_a_flashed_message_shows_as_a_toast_on_the_next_screen(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $this->withSession(['m2-toast' => 'Utility added.'])
            ->get(route('m2.utilities'))
            ->assertSee('id="m2-flash"', false)
            ->assertSee('Utility added.')
            ->assertSee('js/m2-feedback.js', false);
    }
}
