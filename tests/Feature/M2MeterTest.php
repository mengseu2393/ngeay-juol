<?php

namespace Tests\Feature;

use App\Livewire\M2\Meter;
use App\Models\UtilityUsage;
use Livewire\Livewire;

/**
 * /m2's Electricity / Water screen (sketch 2). The consumption maths belong to
 * MeterReadingResolver and the reset to UtilityReadingResetService (both have
 * their own tests); pinned here is that this screen goes through them, keeps one
 * row per room + utility + day, and refuses a meter that isn't this room's.
 */
class M2MeterTest extends M2TestCase
{
    public function test_saving_a_reading_uses_the_previous_one_as_its_begin(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $electricity = $this->utility($property, 'Electricity');
        $this->reading($landlord, $unit, $electricity, '2026-09-30', 100, 10);

        $this->signIn($landlord, $property);

        Livewire::test(Meter::class, ['unit' => $unit, 'utility' => $electricity])
            ->set('reading', '130')
            ->assertSee(__('Used'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('reading', '')
            ->assertSee(__('Meter reading saved.'));

        $this->assertDatabaseHas('utility_usages', [
            'unit_id' => $unit->id,
            'property_utility_id' => $electricity->id,
            'reading_date' => '2026-10-13 00:00:00',
            'old_reading' => '100.000',
            'new_reading' => '130.000',
            'amount_used' => '30.000',
        ]);
    }

    public function test_saving_twice_in_a_day_updates_the_row_instead_of_stacking_another(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $water = $this->utility($property, 'Water');
        $this->reading($landlord, $unit, $water, '2026-09-30', 50, 5);

        $this->signIn($landlord, $property);

        $component = Livewire::test(Meter::class, ['unit' => $unit, 'utility' => $water]);
        $component->set('reading', '60')->call('save');
        $component->set('reading', '62')->call('save');

        $this->assertSame(2, UtilityUsage::where('unit_id', $unit->id)->count());
        $this->assertSame('12.000', UtilityUsage::where('unit_id', $unit->id)->orderByDesc('id')->first()->amount_used);
    }

    public function test_a_blank_or_non_numeric_reading_is_refused(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $electricity = $this->utility($property, 'Electricity');

        $this->signIn($landlord, $property);

        foreach (['', 'abc', '-5'] as $bad) {
            Livewire::test(Meter::class, ['unit' => $unit, 'utility' => $electricity])
                ->set('reading', $bad)
                ->call('save')
                ->assertHasErrors('reading');
        }

        $this->assertSame(0, UtilityUsage::count());
    }

    public function test_reset_records_a_new_starting_point_that_is_not_billed(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $electricity = $this->utility($property, 'Electricity');
        $this->reading($landlord, $unit, $electricity, '2026-09-30', 9990, 10);

        $this->signIn($landlord, $property);

        Livewire::test(Meter::class, ['unit' => $unit, 'utility' => $electricity])
            ->call('openReset')
            ->assertSet('resetting', true)
            ->set('resetTo', '5')
            ->call('confirmReset')
            ->assertHasNoErrors()
            ->assertSet('resetting', false)
            ->assertSee(__('Meter reset. The next reading starts from here.'));

        $this->assertDatabaseHas('utility_usages', [
            'unit_id' => $unit->id,
            'property_utility_id' => $electricity->id,
            'is_reset' => true,
            'new_reading' => '5.000',
            'amount_used' => '0.000',
        ]);
    }

    public function test_a_reset_cannot_be_dated_before_the_latest_reading_and_leaves_no_row(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $electricity = $this->utility($property, 'Electricity');

        $this->signIn($landlord, $property);

        Livewire::test(Meter::class, ['unit' => $unit, 'utility' => $electricity])
            ->call('openReset')
            ->set('resetTo', '-1')
            ->call('confirmReset')
            ->assertHasErrors('resetTo');

        $this->assertSame(0, UtilityUsage::count());
    }

    public function test_another_properties_meter_cannot_be_opened_for_this_room(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $other = $this->property($landlord, 'Other');
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $foreignMeter = $this->utility($other, 'Electricity');

        $this->signIn($landlord, $property);

        $this->get(route('m2.meter', [$unit, $foreignMeter]))->assertNotFound();
    }

    public function test_the_history_lists_the_latest_readings_of_this_room_only(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $neighbour = $this->rental($landlord, $property, 'A-2', 'Sokha')->unit;
        $electricity = $this->utility($property, 'Electricity');
        $this->reading($landlord, $unit, $electricity, '2026-09-30', 100, 11);
        $this->reading($landlord, $neighbour, $electricity, '2026-09-30', 700, 77);

        $this->signIn($landlord, $property);

        Livewire::test(Meter::class, ['unit' => $unit, 'utility' => $electricity])
            ->assertSeeHtml('id="m2-meter-history"')
            ->assertSee('11 kWh')
            ->assertDontSee('77 kWh');
    }
}
