<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\ReadingType;
use App\Filament\Resources\PropertyUtilityResource\Pages\ListPropertyUtilities;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use App\Support\ActiveProperty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use Database\Seeders\RolesAndPermissionsSeeder;

class PropertyUtilityResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->seed(RolesAndPermissionsSeeder::class);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('landlord'));
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();
        parent::tearDown();
    }

    public function test_initialize_readings_action_exists_and_can_be_mounted_for_metered_utility(): void
    {
        $landlord = User::create([
            'name' => 'Landlord User',
            'email' => 'landlord@example.com',
            'password' => bcrypt('password'),
        ]);
        $landlord->assignRole('landlord');

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Property Alpha',
        ]);
        ActiveProperty::set($property->id);

        $utility = PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered,
            'rate' => 0.15,
            'unit_of_measure' => 'kWh',
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'rent_amount' => 500,
        ]);

        $this->actingAs($landlord);

        Livewire::test(ListPropertyUtilities::class)
            ->assertTableActionExists('initializeReadings')
            ->mountTableAction('initializeReadings', $utility)
            ->assertTableActionDataSet([
                'reading_date' => now()->toDateString(),
            ]);
    }

    public function test_initialize_readings_action_submits_baseline_readings(): void
    {
        $landlord = User::create([
            'name' => 'Landlord User',
            'email' => 'landlord@example.com',
            'password' => bcrypt('password'),
        ]);
        $landlord->assignRole('landlord');

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Property Alpha',
        ]);
        ActiveProperty::set($property->id);

        $utility = PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered,
            'rate' => 0.15,
            'unit_of_measure' => 'kWh',
        ]);

        $unit1 = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'rent_amount' => 500,
        ]);

        $unit2 = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '102',
            'room_type' => 'Standard',
            'rent_amount' => 500,
        ]);

        $this->actingAs($landlord);

        $test = Livewire::test(ListPropertyUtilities::class)
            ->callTableAction('initializeReadings', $utility, data: [
                'reading_date' => '2026-07-02',
                'units' => [
                    $unit1->id => '120.5',
                    $unit2->id => '', // skip this
                ]
            ]);

        $test->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('utility_usages', [
            'unit_id' => $unit1->id,
            'property_utility_id' => $utility->id,
            'reading_date' => '2026-07-02',
            'old_reading' => '120.500',
            'new_reading' => '120.500',
            'amount_used' => '0.000',
            'reading_type' => ReadingType::Actual->value,
        ]);

        $this->assertDatabaseMissing('utility_usages', [
            'unit_id' => $unit2->id,
            'property_utility_id' => $utility->id,
        ]);
    }

    public function test_initialize_readings_action_does_not_overwrite_existing_readings(): void
    {
        $landlord = User::create([
            'name' => 'Landlord User',
            'email' => 'landlord@example.com',
            'password' => bcrypt('password'),
        ]);
        $landlord->assignRole('landlord');

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Property Alpha',
        ]);
        ActiveProperty::set($property->id);

        $utility = PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered,
            'rate' => 0.15,
            'unit_of_measure' => 'kWh',
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'rent_amount' => 500,
        ]);

        // Create an existing reading
        $existing = UtilityUsage::create([
            'unit_id' => $unit->id,
            'property_utility_id' => $utility->id,
            'reading_date' => '2026-07-01',
            'old_reading' => 50,
            'new_reading' => 100,
            'amount_used' => 50,
            'reading_type' => ReadingType::Actual->value,
            'recorded_by_id' => $landlord->id,
        ]);

        $this->actingAs($landlord);

        // When displaying form, the unit's input should be disabled
        Livewire::test(ListPropertyUtilities::class)
            ->mountTableAction('initializeReadings', $utility)
            ->assertTableActionExists('initializeReadings');

        // Let's call the action with a value for the existing unit
        // and ensure it does not overwrite or create a duplicate baseline.
        Livewire::test(ListPropertyUtilities::class)
            ->callTableAction('initializeReadings', $utility, data: [
                'reading_date' => '2026-07-02',
                'units' => [
                    $unit->id => '200.0', // This is technically ignored in submit processing
                ]
            ])
            ->assertHasNoTableActionErrors();

        // The database record should remain unchanged (amount_used should still be 50, not 0)
        $this->assertDatabaseHas('utility_usages', [
            'id' => $existing->id,
            'amount_used' => '50.000',
        ]);
        
        // No new utility usage record should be created
        $this->assertEquals(1, UtilityUsage::count());
    }

    public function test_reset_readings_action_creates_a_zero_baseline_after_meter_replacement(): void
    {
        $landlord = User::create([
            'name' => 'Landlord User',
            'email' => 'landlord@example.com',
            'password' => bcrypt('password'),
        ]);
        $landlord->assignRole('landlord');

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Property Alpha',
        ]);
        ActiveProperty::set($property->id);

        $utility = PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered,
            'rate' => 0.15,
            'unit_of_measure' => 'kWh',
        ]);

        $unit1 = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'rent_amount' => 500,
        ]);

        $unit2 = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '102',
            'room_type' => 'Standard',
            'rent_amount' => 500,
        ]);

        foreach ([$unit1, $unit2] as $unit) {
            UtilityUsage::create([
                'unit_id' => $unit->id,
                'property_utility_id' => $utility->id,
                'reading_date' => '2026-07-01',
                'old_reading' => 8000,
                'new_reading' => 8432,
                'amount_used' => 432,
                'reading_type' => ReadingType::Actual->value,
                'recorded_by_id' => $landlord->id,
            ]);
        }

        $this->actingAs($landlord);

        Livewire::test(ListPropertyUtilities::class)
            ->assertTableActionExists('resetReadings')
            ->callTableAction('resetReadings', $utility, data: [
                'reading_date' => '2026-07-10',
                'units' => [
                    $unit1->id => '0',
                    $unit2->id => '', // untouched meter
                ],
            ])
            ->assertHasNoTableActionErrors();

        // Room 102 keeps its single, pre-existing row.
        $this->assertEquals(1, UtilityUsage::where('unit_id', $unit2->id)->count());

        // What billing will pick up as the next cycle's old_reading.
        $latest = UtilityUsage::where('unit_id', $unit1->id)
            ->where('property_utility_id', $utility->id)
            ->orderByDesc('reading_date')
            ->orderByDesc('id')
            ->first();
        $this->assertSame('2026-07-10', $latest->reading_date->toDateString());
        $this->assertEquals(0, (float) $latest->new_reading);
        $this->assertEquals(0, (float) $latest->old_reading);
        $this->assertEquals(0, (float) $latest->amount_used);
        $this->assertEquals(2, UtilityUsage::where('unit_id', $unit1->id)->count());
    }

    public function test_reset_readings_action_ignores_rooms_without_a_meter_history(): void
    {
        $landlord = User::create([
            'name' => 'Landlord User',
            'email' => 'landlord@example.com',
            'password' => bcrypt('password'),
        ]);
        $landlord->assignRole('landlord');

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Property Alpha',
        ]);
        ActiveProperty::set($property->id);

        $utility = PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered,
            'rate' => 0.15,
            'unit_of_measure' => 'kWh',
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'rent_amount' => 500,
        ]);

        $this->actingAs($landlord);

        Livewire::test(ListPropertyUtilities::class)
            ->callTableAction('resetReadings', $utility, data: [
                'reading_date' => '2026-07-10',
                'units' => [$unit->id => '0'],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(0, UtilityUsage::count());
    }
}
