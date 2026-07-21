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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The meter layer: where the previous index comes from, what a replacement does
 * to it, and the guarantee that rooms without a meter (or the whole feature
 * switched off) bill exactly as they did before.
 */
class UtilityMeterTest extends TestCase
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
    }

    private function resolver(): MeterReadingResolver
    {
        return app(MeterReadingResolver::class);
    }

    private function meter(array $attributes = []): UtilityMeter
    {
        return UtilityMeter::create(array_merge([
            'property_utility_id' => $this->utility->id,
            'landlord_id' => $this->landlord->id,
            'unit_id' => $this->unit->id,
            'installed_on' => '2026-01-01',
            'installed_reading' => 8432,
            'status' => MeterStatus::Active->value,
        ], $attributes));
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

    public function test_room_without_a_meter_uses_the_legacy_latest_reading(): void
    {
        $this->reading([
            'reading_date' => '2026-06-30',
            'old_reading' => 100,
            'new_reading' => 180,
            'amount_used' => 80,
        ]);

        $context = $this->resolver()->previous($this->unit->id, $this->utility->id);

        $this->assertSame(180.0, $context['previous']);
        $this->assertNull($context['meter']);
        $this->assertSame('usage', $context['source']);
    }

    public function test_room_with_no_history_at_all_previously_reads_zero(): void
    {
        $context = $this->resolver()->previous($this->unit->id, $this->utility->id);

        $this->assertSame(0.0, $context['previous']);
        $this->assertSame('none', $context['source']);
    }

    public function test_a_new_meter_bills_from_its_installed_reading_not_from_zero(): void
    {
        $this->meter(['installed_reading' => 8432]);

        $context = $this->resolver()->previous($this->unit->id, $this->utility->id);

        $this->assertSame(8432.0, $context['previous']);
        $this->assertSame('meter_install', $context['source']);
    }

    public function test_meter_with_readings_uses_its_own_latest_reading(): void
    {
        $meter = $this->meter();
        $this->reading([
            'meter_id' => $meter->id,
            'reading_date' => '2026-06-30',
            'old_reading' => 8432,
            'new_reading' => 8600,
            'amount_used' => 168,
        ]);

        $context = $this->resolver()->previous($this->unit->id, $this->utility->id);

        $this->assertSame(8600.0, $context['previous']);
        $this->assertSame('meter_reading', $context['source']);
        $this->assertTrue($context['meter']->is($meter));
    }

    public function test_replacing_a_meter_retires_the_old_one_and_bills_from_the_new_index(): void
    {
        $old = $this->meter();
        $this->reading([
            'meter_id' => $old->id,
            'reading_date' => '2026-06-30',
            'old_reading' => 8432,
            'new_reading' => 8600,
            'amount_used' => 168,
        ]);

        $new = $this->resolver()->replace($old, '2026-07-10', installedReading: 0, finalReading: 8650);

        $old->refresh();
        $this->assertSame(MeterStatus::Removed, $old->status);
        $this->assertEquals(8650, (float) $old->final_reading);
        $this->assertSame('2026-07-10', $old->removed_on->toDateString());

        $this->assertSame(MeterStatus::Active, $new->status);
        $this->assertTrue($new->replacedMeter->is($old));

        // The whole point: the next cycle measures from the NEW device's 0,
        // not from the old device's 8,600.
        $context = $this->resolver()->previous($this->unit->id, $this->utility->id);
        $this->assertSame(0.0, $context['previous']);
        $this->assertTrue($context['meter']->is($new));

        // The old meter's readings stay attached to it, untouched.
        $this->assertSame(1, $old->usages()->count());
    }

    public function test_a_replacement_meter_can_start_at_a_non_zero_index(): void
    {
        $old = $this->meter();
        $new = $this->resolver()->replace($old, '2026-07-10', installedReading: 1250.5);

        $this->assertEquals(1250.5, (float) $new->installed_reading);
        $this->assertSame(1250.5, $this->resolver()->previousReading($this->unit->id, $this->utility->id));

        // No final reading given → the old meter closes at the index it was on.
        $this->assertEquals(8432, (float) $old->refresh()->final_reading);
    }

    public function test_readings_are_stamped_with_the_active_meter_automatically(): void
    {
        $meter = $this->meter();

        $usage = $this->reading([
            'reading_date' => '2026-07-31',
            'old_reading' => 8432,
            'new_reading' => 8500,
            'amount_used' => 68,
        ]);

        $this->assertSame($meter->id, $usage->meter_id);
    }

    public function test_rollover_on_a_digit_limited_meter_is_usage_not_a_negative(): void
    {
        $meter = $this->meter(['digits' => 5, 'installed_reading' => 99000]);

        // 99,998 → 00,001 on a 5-digit meter is 3 units, not −99,997.
        $this->assertSame(3.0, $meter->consumption(99998, 1));
        $this->assertSame(0.0, $meter->consumption(99998, 99998));
    }

    public function test_multiplier_scales_consumption(): void
    {
        $meter = $this->meter(['multiplier' => 20]);

        $this->assertSame(200.0, $meter->consumption(100, 110));
    }

    public function test_consumption_without_a_meter_still_clamps_at_zero(): void
    {
        $this->assertSame(0.0, $this->resolver()->consumption(500, 100));
        $this->assertSame(40.0, $this->resolver()->consumption(100, 140));
    }

    public function test_feature_flag_off_falls_back_to_the_legacy_path_entirely(): void
    {
        config()->set('utilities.meters', false);

        $this->meter(['installed_reading' => 8432]);
        $this->reading([
            'reading_date' => '2026-06-30',
            'old_reading' => 100,
            'new_reading' => 180,
            'amount_used' => 80,
        ]);

        $context = $this->resolver()->previous($this->unit->id, $this->utility->id);

        $this->assertSame(180.0, $context['previous']);
        $this->assertNull($context['meter']);
        $this->assertNull($this->resolver()->activeMeter($this->unit->id, $this->utility->id));
    }

    public function test_baseline_for_a_dated_reading_measures_from_the_meter_install(): void
    {
        $this->meter(['installed_on' => '2026-01-01', 'installed_reading' => 8432]);

        $baseline = $this->resolver()->baselineFor($this->unit->id, $this->utility->id, '2026-07-31', 8500);

        $this->assertSame(8432.0, $baseline['old']);
        $this->assertSame(68.0, $baseline['amount']);
    }

    public function test_baseline_without_a_meter_keeps_the_zero_usage_first_reading_rule(): void
    {
        $baseline = $this->resolver()->baselineFor($this->unit->id, $this->utility->id, '2026-07-31', 8500);

        $this->assertSame(8500.0, $baseline['old']);
        $this->assertSame(0.0, $baseline['amount']);
        $this->assertNull($baseline['meter']);
    }

    public function test_backfill_creates_one_meter_per_room_and_splits_at_a_reset(): void
    {
        // Two cycles on the first device, then a reset to 0 and one cycle on the second.
        $this->reading(['reading_date' => '2026-05-31', 'old_reading' => 8000, 'new_reading' => 8200, 'amount_used' => 200]);
        $this->reading(['reading_date' => '2026-06-30', 'old_reading' => 8200, 'new_reading' => 8400, 'amount_used' => 200]);
        $this->reading(['reading_date' => '2026-07-10', 'old_reading' => 0, 'new_reading' => 0, 'amount_used' => 0]);
        $this->reading(['reading_date' => '2026-07-31', 'old_reading' => 0, 'new_reading' => 90, 'amount_used' => 90]);

        $this->artisan('utilities:backfill-meters')->assertSuccessful();

        $meters = UtilityMeter::withoutGlobalScopes()
            ->where('unit_id', $this->unit->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $meters);

        [$first, $second] = [$meters[0], $meters[1]];

        $this->assertSame(MeterStatus::Removed, $first->status);
        $this->assertEquals(8000, (float) $first->installed_reading); // the opening index, not 0
        $this->assertEquals(8400, (float) $first->final_reading);
        $this->assertSame('2026-06-30', $first->removed_on->toDateString());
        $this->assertSame(2, $first->usages()->count());

        $this->assertSame(MeterStatus::Active, $second->status);
        $this->assertEquals(0, (float) $second->installed_reading);
        $this->assertTrue($second->replacedMeter->is($first));
        $this->assertSame(2, $second->usages()->count());

        // Billing now continues from the live device.
        $this->assertSame(90.0, $this->resolver()->previousReading($this->unit->id, $this->utility->id));
    }

    public function test_backfill_is_idempotent(): void
    {
        $this->reading(['reading_date' => '2026-05-31', 'old_reading' => 8000, 'new_reading' => 8200, 'amount_used' => 200]);

        $this->artisan('utilities:backfill-meters')->assertSuccessful();
        $this->artisan('utilities:backfill-meters')->assertSuccessful();

        $this->assertSame(1, UtilityMeter::withoutGlobalScopes()->where('unit_id', $this->unit->id)->count());
    }
}
