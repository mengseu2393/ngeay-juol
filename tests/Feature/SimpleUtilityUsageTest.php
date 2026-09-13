<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\ReadingType;
use App\Enums\RentalStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Livewire\SimpleUtilityUsage;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Rental;
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
 * SimpleUtilityUsage is the mobile "Simple Mode" screen for ONGOING monthly meter
 * readings. It deliberately does NOT reuse SimpleRoomList::submitUtilityReading()'s
 * simplified baseline-only "initial setup" math (old_reading=null, amount=0) —
 * it mirrors UnitResource::meterReadingsAction()'s real consumption computation
 * via MeterReadingResolver::baselineFor() instead.
 */
class SimpleUtilityUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        ActiveProperty::clear();
    }

    public function test_component_scopes_rooms_to_active_property(): void
    {
        [$landlord, $property, $unit] = $this->propertySetup();

        $otherProperty = Property::create(['landlord_id' => $landlord->id, 'name' => 'Other property']);
        $otherUnit = Unit::create([
            'property_id' => $otherProperty->id,
            'landlord_id' => $landlord->id,
            'room_number' => '999',
            'room_type' => 'Standard',
            'rent_amount' => 300,
        ]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleUtilityUsage::class)
            ->assertSee($unit->room_number)
            ->assertDontSee($otherUnit->room_number);
    }

    public function test_room_row_shows_the_active_tenants_name(): void
    {
        [$landlord, $property, $unit] = $this->propertySetup();

        $tenant = User::factory()->create(['email' => 'tenant-'.uniqid().'@example.com']);
        $tenant->assignRole('tenant');

        Rental::create([
            'landlord_id' => $landlord->id,
            'unit_id' => $unit->id,
            'tenant_id' => $tenant->id,
            'occupant_name' => 'Sok Dara',
            'monthly_rent' => 500,
            'status' => RentalStatus::Active,
            'start_date' => now()->toDateString(),
        ]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleUtilityUsage::class)
            ->assertSee('Sok Dara');
    }

    public function test_room_row_shows_no_tenant_placeholder_when_vacant(): void
    {
        [$landlord, $property, $unit] = $this->propertySetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleUtilityUsage::class)
            ->assertSee(__('No tenant'));
    }

    public function test_submitting_reading_computes_real_consumption_from_prior_reading(): void
    {
        [$landlord, $property, $unit, $utility] = $this->propertySetup();

        // A real prior reading — the new row's consumption must be new - old
        // (50), not the zero-usage baseline SimpleRoomList would produce.
        UtilityUsage::create([
            'unit_id' => $unit->id,
            'property_utility_id' => $utility->id,
            'landlord_id' => $landlord->id,
            'recorded_by_id' => $landlord->id,
            'reading_type' => ReadingType::Actual,
            'reading_date' => now()->subMonth()->toDateString(),
            'old_reading' => 100,
            'new_reading' => 180,
            'amount_used' => 80,
        ]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleUtilityUsage::class)
            ->call('openUtilityReading', $unit->id)
            ->set("readingValues.{$utility->id}", '230')
            ->call('submitUtilityReading')
            ->assertHasNoErrors();

        $usage = UtilityUsage::where('unit_id', $unit->id)
            ->where('property_utility_id', $utility->id)
            ->whereDate('reading_date', now()->toDateString())
            ->firstOrFail();

        $this->assertSame(now()->toDateString(), $usage->reading_date->toDateString());
        $this->assertEquals(180, (float) $usage->old_reading);
        $this->assertEquals(230, (float) $usage->new_reading);
        $this->assertEquals(50, (float) $usage->amount_used);
    }

    public function test_tamper_protection_rejects_utility_not_belonging_to_active_property(): void
    {
        [$landlord, $property, $unit] = $this->propertySetup();

        $otherProperty = Property::create(['landlord_id' => $landlord->id, 'name' => 'Other property']);
        $foreignUtility = PropertyUtility::create([
            'property_id' => $otherProperty->id,
            'landlord_id' => $landlord->id,
            'name' => 'Water',
            'billing_type' => BillingType::Metered,
            'rate' => 0.5,
            'unit_of_measure' => 'm3',
        ]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleUtilityUsage::class)
            ->call('openUtilityReading', $unit->id)
            ->set("readingValues.{$foreignUtility->id}", '50')
            ->call('submitUtilityReading')
            ->assertHasErrors(['readingValues']);

        $this->assertDatabaseMissing('utility_usages', [
            'unit_id' => $unit->id,
            'property_utility_id' => $foreignUtility->id,
        ]);
    }

    public function test_negative_reading_value_is_rejected(): void
    {
        [$landlord, $property, $unit, $utility] = $this->propertySetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleUtilityUsage::class)
            ->call('openUtilityReading', $unit->id)
            ->set("readingValues.{$utility->id}", '-5')
            ->call('submitUtilityReading')
            ->assertHasErrors(["readingValues.{$utility->id}" => 'min']);

        $this->assertDatabaseMissing('utility_usages', [
            'unit_id' => $unit->id,
            'property_utility_id' => $utility->id,
        ]);
    }

    public function test_non_numeric_reading_value_is_rejected(): void
    {
        [$landlord, $property, $unit, $utility] = $this->propertySetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleUtilityUsage::class)
            ->call('openUtilityReading', $unit->id)
            ->set("readingValues.{$utility->id}", 'not-a-number')
            ->call('submitUtilityReading')
            ->assertHasErrors(["readingValues.{$utility->id}" => 'numeric']);

        $this->assertDatabaseMissing('utility_usages', [
            'unit_id' => $unit->id,
            'property_utility_id' => $utility->id,
        ]);
    }

    public function test_resubmitting_same_day_updates_instead_of_duplicating(): void
    {
        [$landlord, $property, $unit, $utility] = $this->propertySetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleUtilityUsage::class)
            ->call('openUtilityReading', $unit->id)
            ->set("readingValues.{$utility->id}", '100')
            ->call('submitUtilityReading')
            ->assertHasNoErrors();

        Livewire::actingAs($landlord)
            ->test(SimpleUtilityUsage::class)
            ->call('openUtilityReading', $unit->id)
            ->set("readingValues.{$utility->id}", '150')
            ->call('submitUtilityReading')
            ->assertHasNoErrors();

        $this->assertSame(1, UtilityUsage::where('unit_id', $unit->id)
            ->where('property_utility_id', $utility->id)
            ->whereDate('reading_date', now()->toDateString())
            ->count());

        $this->assertDatabaseHas('utility_usages', [
            'unit_id' => $unit->id,
            'property_utility_id' => $utility->id,
            'new_reading' => 150,
        ]);
    }

    public function test_blank_reading_is_skipped_without_error(): void
    {
        [$landlord, $property, $unit, $utility] = $this->propertySetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleUtilityUsage::class)
            ->call('openUtilityReading', $unit->id)
            ->set("readingValues.{$utility->id}", '')
            ->call('submitUtilityReading')
            ->assertHasErrors(['readingValues']);

        $this->assertDatabaseMissing('utility_usages', [
            'unit_id' => $unit->id,
            'property_utility_id' => $utility->id,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{User, Property, Unit, PropertyUtility}
     */
    private function propertySetup(): array
    {
        $landlord = $this->makeLandlord();

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Test Property',
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'rent_amount' => 500,
        ]);

        $utility = PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered,
            'rate' => 0.15,
            'unit_of_measure' => 'kWh',
        ]);

        return [$landlord, $property, $unit, $utility];
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
