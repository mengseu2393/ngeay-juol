<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\MeterStatus;
use App\Enums\ReadingType;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\UtilityUsage;
use App\Services\MeterReadingResolver;
use App\Services\OpeningReadingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Opening readings: the index each meter showed the day billing started.
 *
 * The point of every assertion here is the same one — after an opening reading
 * is recorded, the first invoice must charge consumption *since* that number,
 * not the meter's whole lifetime total. So the tests check the resolver's answer
 * as well as the row that was written.
 */
class OpeningReadingTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private Property $property;

    private PropertyUtility $utility;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->landlord = User::create([
            'name' => 'Landlord User',
            'email' => 'landlord@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->property = Property::create([
            'landlord_id' => $this->landlord->id,
            'name' => 'Property Alpha',
        ]);

        $this->utility = PropertyUtility::create([
            'property_id' => $this->property->id,
            'landlord_id' => $this->landlord->id,
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered,
            'rate' => 0.15,
            'unit_of_measure' => 'kWh',
        ]);

        $this->unit = Unit::create([
            'property_id' => $this->property->id,
            'landlord_id' => $this->landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'rent_amount' => 500,
        ]);

        // Readings are stamped with who took them; this always runs behind auth.
        $this->actingAs($this->landlord);
    }

    private function service(): OpeningReadingService
    {
        return app(OpeningReadingService::class);
    }

    private function reading(array $attributes = []): UtilityUsage
    {
        return UtilityUsage::create(array_merge([
            'unit_id' => $this->unit->id,
            'property_utility_id' => $this->utility->id,
            'landlord_id' => $this->landlord->id,
            'recorded_by_id' => $this->landlord->id,
            'reading_type' => ReadingType::Actual->value,
        ], $attributes));
    }

    private function meter(array $attributes = []): UtilityMeter
    {
        return UtilityMeter::create(array_merge([
            'property_utility_id' => $this->utility->id,
            'landlord_id' => $this->landlord->id,
            'unit_id' => $this->unit->id,
            'installed_on' => '2026-01-01',
            'installed_reading' => 0,
            'status' => MeterStatus::Active->value,
        ], $attributes));
    }

    // ---------------------------------------------------------------------
    // Metered rooms — the number belongs on the device
    // ---------------------------------------------------------------------

    public function test_a_room_with_an_unread_meter_is_open_and_stores_the_opening_index_on_the_meter(): void
    {
        $meter = $this->meter();

        $row = $this->service()->rows($this->utility)->get($this->unit->id);
        $this->assertSame(OpeningReadingService::OPEN_METER, $row['state']);

        $result = $this->service()->apply($this->utility, '2026-08-01', [$this->unit->id => 4120]);

        $this->assertSame(['meters' => 1, 'baselines' => 0, 'skipped' => 0], $result);
        $this->assertSame(4120.0, (float) $meter->fresh()->installed_reading);

        // No phantom reading: the opening index is a property of the device.
        $this->assertSame(0, UtilityUsage::where('unit_id', $this->unit->id)->count());
    }

    public function test_the_first_invoice_after_an_opening_reading_bills_only_what_was_used_since(): void
    {
        $this->meter();
        $this->service()->apply($this->utility, '2026-08-01', [$this->unit->id => 4120]);

        $context = app(MeterReadingResolver::class)->previous($this->unit->id, $this->utility->id);

        $this->assertSame(4120.0, $context['previous']);
        $this->assertSame('meter_install', $context['source']);

        // A month later the meter reads 4,190 — that is 70 kWh, not 4,190.
        $baseline = app(MeterReadingResolver::class)
            ->baselineFor($this->unit->id, $this->utility->id, '2026-09-01', 4190);

        $this->assertSame(4120.0, $baseline['old']);
        $this->assertSame(70.0, $baseline['amount']);
    }

    public function test_an_opening_reading_older_than_the_install_date_pulls_the_install_date_back(): void
    {
        $meter = $this->meter(['installed_on' => '2026-03-01']);

        $this->service()->apply($this->utility, '2026-01-15', [$this->unit->id => 900]);

        $this->assertSame('2026-01-15', $meter->fresh()->installed_on->toDateString());
    }

    public function test_a_later_opening_reading_leaves_an_earlier_install_date_alone(): void
    {
        $meter = $this->meter(['installed_on' => '2026-01-01']);

        $this->service()->apply($this->utility, '2026-08-01', [$this->unit->id => 900]);

        $this->assertSame('2026-01-01', $meter->fresh()->installed_on->toDateString());
    }

    public function test_a_meter_that_has_been_read_is_locked_and_never_rewritten(): void
    {
        $meter = $this->meter(['installed_reading' => 4120]);
        $this->reading([
            'meter_id' => $meter->id,
            'reading_date' => '2026-09-01',
            'old_reading' => 4120,
            'new_reading' => 4190,
            'amount_used' => 70,
        ]);

        $row = $this->service()->rows($this->utility)->get($this->unit->id);
        $this->assertSame(OpeningReadingService::LOCKED_METER, $row['state']);
        $this->assertSame(4190.0, $row['baseline']);

        $result = $this->service()->apply($this->utility, '2026-10-01', [$this->unit->id => 1]);

        // Rewriting it would retroactively change what September charged.
        $this->assertSame(['meters' => 0, 'baselines' => 0, 'skipped' => 1], $result);
        $this->assertSame(4120.0, (float) $meter->fresh()->installed_reading);
    }

    // ---------------------------------------------------------------------
    // Un-metered rooms — the number has nowhere to live but a baseline row
    // ---------------------------------------------------------------------

    public function test_a_room_without_a_meter_gets_a_zero_consumption_baseline_row(): void
    {
        $row = $this->service()->rows($this->utility)->get($this->unit->id);
        $this->assertSame(OpeningReadingService::OPEN_LEGACY, $row['state']);

        $result = $this->service()->apply($this->utility, '2026-08-01', [$this->unit->id => 1091]);

        $this->assertSame(['meters' => 0, 'baselines' => 1, 'skipped' => 0], $result);

        $usage = UtilityUsage::where('unit_id', $this->unit->id)->sole();
        $this->assertSame(1091.0, (float) $usage->old_reading);
        $this->assertSame(1091.0, (float) $usage->new_reading);
        $this->assertSame(0.0, (float) $usage->amount_used);

        $this->assertSame(
            1091.0,
            app(MeterReadingResolver::class)->previousReading($this->unit->id, $this->utility->id),
        );
    }

    public function test_a_room_that_already_has_readings_is_locked(): void
    {
        $this->reading([
            'reading_date' => '2026-07-01',
            'old_reading' => 1000,
            'new_reading' => 1091,
            'amount_used' => 91,
        ]);

        $row = $this->service()->rows($this->utility)->get($this->unit->id);
        $this->assertSame(OpeningReadingService::LOCKED_USAGE, $row['state']);
        $this->assertSame(1091.0, $row['baseline']);

        $result = $this->service()->apply($this->utility, '2026-08-01', [$this->unit->id => 5]);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, UtilityUsage::where('unit_id', $this->unit->id)->count());
    }

    // ---------------------------------------------------------------------
    // Form / submission edges
    // ---------------------------------------------------------------------

    public function test_blank_rooms_are_left_untouched_so_the_form_can_be_filled_a_few_at_a_time(): void
    {
        $second = Unit::create([
            'property_id' => $this->property->id,
            'landlord_id' => $this->landlord->id,
            'room_number' => '102',
            'room_type' => 'Standard',
            'rent_amount' => 500,
        ]);

        $result = $this->service()->apply($this->utility, '2026-08-01', [
            $this->unit->id => 1091,
            $second->id => '',
        ]);

        $this->assertSame(['meters' => 0, 'baselines' => 1, 'skipped' => 0], $result);
        $this->assertSame(0, UtilityUsage::where('unit_id', $second->id)->count());

        // Still open, so it can be done tomorrow.
        $this->assertSame(
            OpeningReadingService::OPEN_LEGACY,
            $this->service()->rows($this->utility)->get($second->id)['state'],
        );
    }

    public function test_a_room_from_another_property_is_ignored(): void
    {
        $other = Property::create(['landlord_id' => $this->landlord->id, 'name' => 'Property Beta']);
        $foreign = Unit::create([
            'property_id' => $other->id,
            'landlord_id' => $this->landlord->id,
            'room_number' => '999',
            'room_type' => 'Standard',
            'rent_amount' => 500,
        ]);

        $result = $this->service()->apply($this->utility, '2026-08-01', [$foreign->id => 500]);

        $this->assertSame(['meters' => 0, 'baselines' => 0, 'skipped' => 0], $result);
        $this->assertSame(0, UtilityUsage::where('unit_id', $foreign->id)->count());
    }

    public function test_rooms_are_listed_in_room_number_order(): void
    {
        foreach (['103', '102'] as $number) {
            Unit::create([
                'property_id' => $this->property->id,
                'landlord_id' => $this->landlord->id,
                'room_number' => $number,
                'room_type' => 'Standard',
                'rent_amount' => 500,
            ]);
        }

        $numbers = $this->service()->rows($this->utility)
            ->map(fn (array $row): string => $row['unit']->room_number)
            ->values()
            ->all();

        $this->assertSame(['101', '102', '103'], $numbers);
    }

    /** With the meter layer off, even a room that has a meter takes the legacy path. */
    public function test_meters_disabled_falls_back_to_baseline_rows(): void
    {
        config()->set('utilities.meters', false);
        $meter = $this->meter();

        $row = $this->service()->rows($this->utility)->get($this->unit->id);
        $this->assertSame(OpeningReadingService::OPEN_LEGACY, $row['state']);

        $result = $this->service()->apply($this->utility, '2026-08-01', [$this->unit->id => 1091]);

        $this->assertSame(1, $result['baselines']);
        $this->assertSame(0.0, (float) $meter->fresh()->installed_reading);
    }
}
