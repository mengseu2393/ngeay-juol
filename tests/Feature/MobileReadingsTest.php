<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\ReadingType;
use App\Enums\UnitStatus;
use App\Livewire\Mobile\Readings;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * /m's Meter readings screen. It is Simple Mode's SimpleUtilityUsage with a
 * phone view, so the reading maths and tamper protection are that component's
 * (and its tests'); these pin what the phone adds — which rooms still need a
 * reading THIS month, the sheet/toast events — and that the inherited scoping
 * still holds for another landlord's rooms.
 */
class MobileReadingsTest extends MobileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-13 09:00:00');
    }

    public function test_it_shows_which_rooms_still_need_this_months_readings(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $electricity = $this->meter($property, 'Electricity');
        $water = $this->meter($property, 'Water');
        $this->meter($property, 'Trash', BillingType::Flat); // not read from a meter

        $due = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $done = $this->rental($landlord, $property, 'A-2', 'Sokha')->unit;
        $partly = $this->rental($landlord, $property, 'A-3', 'Vanna')->unit;

        $this->reading($landlord, $due, $electricity, '2026-09-30'); // last month: still due
        $this->reading($landlord, $done, $electricity, '2026-10-02');
        $this->reading($landlord, $done, $water, '2026-10-02');
        $this->reading($landlord, $partly, $water, '2026-10-05');

        $this->signIn($landlord, $property);

        Livewire::test(Readings::class)
            ->assertViewHas('pending', [$due->id => 2, $done->id => 0, $partly->id => 1])
            ->assertViewHas('dueCount', 2)
            ->assertSeeHtml('id="rw-m-reading-room-'.$done->id.'"')
            ->assertSee(__(':count utility reading(s) due', ['count' => 2]))
            ->assertSee(__(':count utility reading(s) due', ['count' => 1]))
            ->assertSee(__('Due').' (2)')
            ->assertSee(__('Done').' (1)');
    }

    public function test_it_renders_for_the_active_property_only_with_a_back_link(): void
    {
        $landlord = $this->landlord();
        $home = $this->property($landlord, 'Home');
        $this->meter($home, 'Electricity');
        $this->rental($landlord, $home, 'H-1', 'Dara');
        $this->rental($landlord, $this->property($landlord, 'Other'), 'O-1', 'Elsewhere');

        $this->signIn($landlord, $home)
            ->get(route('mobile.readings'))
            ->assertOk()
            ->assertSee('H-1')
            ->assertDontSee('O-1')
            ->assertSee('href="'.route('mobile.settings').'"', false)
            ->assertDontSee('rw-sm-', false);
    }

    public function test_a_property_without_meters_points_to_utility_rates(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(Readings::class)
            ->assertSeeHtml('id="rw-m-readings-no-meters"')
            ->assertSeeHtml('href="'.route('mobile.utility-rates').'"');
    }

    public function test_the_reading_sheet_has_big_decimal_inputs_with_next_and_done_keys(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $electricity = $this->meter($property, 'Electricity');
        $water = $this->meter($property, 'Water');
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $this->reading($landlord, $unit, $electricity, '2026-09-30', 1251);

        $this->signIn($landlord, $property);

        $html = Livewire::test(Readings::class)
            ->call('openUtilityReading', $unit->id)
            ->assertSet('recordingUnitId', $unit->id)
            ->assertSee(__('Previous: :value', ['value' => '1,251 kWh']))
            ->html();

        // Electricity sorts first (forUnit orders by name): "next", then Water: "done".
        $this->assertMatchesRegularExpression('#inputmode="decimal"\s+id="rw-m-reading-'.$electricity->id.'".*?enterkeyhint="next"#s', $html);
        $this->assertMatchesRegularExpression('#inputmode="decimal"\s+id="rw-m-reading-'.$water->id.'".*?enterkeyhint="done"#s', $html);
    }

    public function test_saving_records_real_consumption_closes_the_sheet_and_toasts(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $electricity = $this->meter($property, 'Electricity');
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $this->reading($landlord, $unit, $electricity, '2026-09-30', 180);

        $this->signIn($landlord, $property);

        Livewire::test(Readings::class)
            ->assertViewHas('pending', [$unit->id => 1])
            ->call('openUtilityReading', $unit->id)
            ->set("readingValues.{$electricity->id}", '230')
            ->call('submitUtilityReading')
            ->assertHasNoErrors()
            ->assertSet('recordingUnitId', null)
            ->assertDispatched('rw-m-sheet-close', name: 'reading')
            ->assertDispatched('rw-m-toast', message: __('Meter reading saved.'))
            ->assertViewHas('pending', [$unit->id => 0]);

        $usage = UtilityUsage::where('unit_id', $unit->id)->whereDate('reading_date', '2026-10-13')->firstOrFail();
        $this->assertEquals(180, (float) $usage->old_reading);
        $this->assertEquals(50, (float) $usage->amount_used);
    }

    public function test_invalid_or_empty_readings_keep_the_sheet_open_without_a_toast(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $electricity = $this->meter($property, 'Electricity');
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;

        $this->signIn($landlord, $property);

        Livewire::test(Readings::class)
            ->call('openUtilityReading', $unit->id)
            ->set("readingValues.{$electricity->id}", '-5')
            ->call('submitUtilityReading')
            ->assertHasErrors(["readingValues.{$electricity->id}"])
            ->set("readingValues.{$electricity->id}", '')
            ->call('submitUtilityReading')
            ->assertHasErrors(['readingValues'])
            ->assertSet('recordingUnitId', $unit->id)
            ->assertNotDispatched('rw-m-toast')
            ->assertNotDispatched('rw-m-sheet-close');

        $this->assertDatabaseCount('utility_usages', 0);
    }

    /** Another landlord's room: never listed, never opened, never written to. */
    public function test_another_landlords_room_cannot_be_read_or_written(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->meter($property, 'Electricity');
        $this->rental($landlord, $property, 'A-1', 'Dara');

        $stranger = $this->landlord();
        $strangerProperty = $this->property($stranger, 'Stranger Property');
        $foreignMeter = $this->meter($strangerProperty, 'Electricity');
        $foreignUnit = $this->rental($stranger, $strangerProperty, 'X-1', 'Stranger')->unit;

        $this->signIn($landlord, $property);

        Livewire::test(Readings::class)
            ->assertDontSee('X-1')
            ->call('openUtilityReading', $foreignUnit->id)
            ->assertSet('recordingUnitId', null);

        Livewire::test(Readings::class)
            ->set('recordingUnitId', $foreignUnit->id)
            ->set("readingValues.{$foreignMeter->id}", '50')
            ->call('submitUtilityReading')
            ->assertStatus(404);

        Livewire::withQueryParams(['unit_id' => $foreignUnit->id])
            ->test(Readings::class)
            ->assertSet('recordingUnitId', null);

        $this->assertSame(0, UtilityUsage::withoutGlobalScopes()->where('unit_id', $foreignUnit->id)->count());
    }

    /** A meter of another property can't be smuggled into this room's readings. */
    public function test_a_foreign_meter_id_is_rejected(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->meter($property, 'Electricity');
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $stranger = $this->landlord();
        $foreignMeter = $this->meter($this->property($stranger), 'Water');

        $this->signIn($landlord, $property);

        Livewire::test(Readings::class)
            ->call('openUtilityReading', $unit->id)
            ->set("readingValues.{$foreignMeter->id}", '50')
            ->call('submitUtilityReading')
            ->assertHasErrors(['readingValues']);

        $this->assertDatabaseCount('utility_usages', 0);
    }

    public function test_the_unit_id_deep_link_opens_that_rooms_sheet(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $electricity = $this->meter($property, 'Electricity');
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;

        $this->signIn($landlord, $property)
            ->get(route('mobile.readings', ['unit_id' => $unit->id]))
            ->assertOk()
            ->assertSee("rw-m-sheet-open', { name: 'reading' }", false)
            ->assertSee('id="rw-m-reading-'.$electricity->id.'"', false);
    }

    /** Kept from Simple Mode: a tenancy started or ended elsewhere re-renders the list. */
    public function test_tenancy_events_refresh_the_list(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->meter($property, 'Electricity');
        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => 'B-1',
            'room_type' => 'Standard',
            'rent_amount' => 300,
            'status' => UnitStatus::Available,
        ]);

        $this->signIn($landlord, $property);

        $component = Livewire::test(Readings::class)->assertSee(__('No tenant'));

        $rental = $this->rental($landlord, $property, 'B-2', 'Newcomer');
        $component->dispatch('tenancy-started')->assertSee('Newcomer');

        $rental->delete();
        $component->dispatch('tenancy-ended')->assertDontSee('Newcomer')->assertSee($unit->room_number);
    }

    private function meter(Property $property, string $name, BillingType $type = BillingType::Metered): PropertyUtility
    {
        return PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $property->landlord_id,
            'name' => $name,
            'billing_type' => $type,
            'rate' => 0.25,
            'unit_of_measure' => $name === 'Water' ? 'm³' : 'kWh',
            'is_active' => true,
        ]);
    }

    private function reading(User $landlord, Unit $unit, PropertyUtility $utility, string $date, float $value = 100): UtilityUsage
    {
        return UtilityUsage::create([
            'unit_id' => $unit->id,
            'property_utility_id' => $utility->id,
            'landlord_id' => $landlord->id,
            'recorded_by_id' => $landlord->id,
            'reading_type' => ReadingType::Actual,
            'reading_date' => $date,
            'old_reading' => $value - 10,
            'new_reading' => $value,
            'amount_used' => 10,
        ]);
    }
}
