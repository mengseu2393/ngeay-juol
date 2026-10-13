<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\ReadingType;
use App\Enums\SubscriptionStatus;
use App\Enums\UnitStatus;
use App\Enums\UserStatus;
use App\Livewire\SimpleAddTenant;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use App\Support\ActiveProperty;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Move-in readings are the starting index for a new tenancy. amount_used must
 * stay 0: the usage since the last reading belongs to the vacant period / the
 * previous tenant, so the new tenant must not be billed for it.
 */
class AddTenantMoveInReadingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        ActiveProperty::clear();
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();
        parent::tearDown();
    }

    public function test_move_in_readings_are_saved_as_the_tenants_starting_point(): void
    {
        [$landlord, $unit, $electricity] = $this->fixture();
        $this->priorReading($unit, $electricity, '2026-01-01', 100);

        Livewire::actingAs($landlord)->test(SimpleAddTenant::class)
            ->call('pickRoom', $unit->id)
            ->assertSee(__('Move-in meter readings'))
            ->set('occupantName', 'Sok Dara')
            ->set('monthlyRent', '500')
            ->set("moveInReadings.{$electricity->id}", '130')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('step', 'done');

        $usage = UtilityUsage::where('unit_id', $unit->id)->where('new_reading', 130)->firstOrFail();
        $this->assertEquals(100, (float) $usage->old_reading);
        $this->assertEquals(0, (float) $usage->amount_used); // not billed to the new tenant
        $this->assertNotNull($usage->rental_id);
    }

    public function test_a_reading_below_the_previous_one_blocks_the_move_in(): void
    {
        [$landlord, $unit, $electricity] = $this->fixture();
        $this->priorReading($unit, $electricity, '2026-01-01', 100);

        Livewire::actingAs($landlord)->test(SimpleAddTenant::class)
            ->call('pickRoom', $unit->id)
            ->set('occupantName', 'Sok Dara')
            ->set('monthlyRent', '500')
            ->set("moveInReadings.{$electricity->id}", '90')
            ->call('submit')
            ->assertHasErrors("moveInReadings.{$electricity->id}")
            ->assertSet('step', 'details');

        $this->assertDatabaseMissing('rentals', ['occupant_name' => 'Sok Dara']);
    }

    public function test_readings_are_optional(): void
    {
        [$landlord, $unit] = $this->fixture();

        Livewire::actingAs($landlord)->test(SimpleAddTenant::class)
            ->call('pickRoom', $unit->id)
            ->set('occupantName', 'Sok Dara')
            ->set('monthlyRent', '500')
            ->call('submit')
            ->assertSet('step', 'done');

        $this->assertSame(0, UtilityUsage::count());
    }

    public function test_a_utility_that_is_not_this_rooms_is_ignored(): void
    {
        [$landlord, $unit] = $this->fixture();
        $other = Property::create(['landlord_id' => $landlord->id, 'name' => 'Other']);
        $foreign = PropertyUtility::create([
            'property_id' => $other->id, 'landlord_id' => $landlord->id,
            'name' => 'Water', 'billing_type' => BillingType::Metered, 'rate' => 1, 'unit_of_measure' => 'm3',
        ]);

        Livewire::actingAs($landlord)->test(SimpleAddTenant::class)
            ->call('pickRoom', $unit->id)
            ->set('occupantName', 'Sok Dara')
            ->set('monthlyRent', '500')
            ->set("moveInReadings.{$foreign->id}", '50')
            ->call('submit')
            ->assertSet('step', 'done');

        $this->assertSame(0, UtilityUsage::count());
    }

    private function priorReading(Unit $unit, PropertyUtility $utility, string $date, float $value): void
    {
        UtilityUsage::create([
            'unit_id' => $unit->id, 'property_utility_id' => $utility->id,
            'reading_type' => ReadingType::Actual, 'reading_date' => $date,
            'old_reading' => $value, 'new_reading' => $value, 'amount_used' => 0,
            'recorded_by_id' => $unit->landlord_id,
        ]);
    }

    private function fixture(): array
    {
        $landlord = $this->makeLandlord();
        $property = Property::create(['landlord_id' => $landlord->id, 'name' => 'Test Property']);
        $unit = Unit::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id,
            'room_number' => '101', 'room_type' => 'Standard', 'rent_amount' => 500,
            'status' => UnitStatus::Available,
        ]);
        $electricity = PropertyUtility::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id,
            'name' => 'Electricity', 'billing_type' => BillingType::Metered,
            'rate' => 0.15, 'unit_of_measure' => 'kWh',
        ]);
        ActiveProperty::set($property->id);

        return [$landlord, $unit, $electricity];
    }

    private function makeLandlord(): User
    {
        $landlord = User::factory()->create([
            'name' => 'Test Landlord '.uniqid(),
            'email' => 'landlord-utility-usage-'.uniqid().'@example.com',
        ]);
        $landlord->forceFill(['status' => UserStatus::Active])->save();
        $landlord->assignRole('landlord');

        $plan = SubscriptionPlan::firstOrCreate([
            'slug' => 'starter',
        ], [
            'name' => 'Starter',
            'billing_model' => PlanBillingModel::Tiered,
            'interval' => PlanInterval::Monthly,
            'price' => 30,
            'currency' => 'USD',
            'trial_days' => 0,
            'grace_days' => 7,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Subscription::withoutGlobalScopes()->create([
            'landlord_id' => $landlord->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'billing_model' => PlanBillingModel::Tiered,
            'interval' => PlanInterval::Monthly,
            'price' => 30,
            'currency' => 'USD',
            'starts_at' => now()->startOfMonth(),
            'ends_at' => now()->addMonth()->endOfMonth(),
            'auto_renew' => true,
        ]);

        return $landlord;
    }
}
