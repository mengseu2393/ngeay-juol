<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\MeterStatus;
use App\Filament\Resources\PropertyUtilityResource\Pages\EditPropertyUtility;
use App\Filament\Resources\PropertyUtilityResource\RelationManagers\MetersRelationManager;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityMeter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The Meters tab on a utility's edit page — install, replace, and the kill switch. */
class MetersRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $landlord;

    private PropertyUtility $utility;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('landlord'));

        $this->landlord = User::create([
            'name' => 'Landlord User',
            'email' => 'landlord@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->landlord->assignRole('landlord');

        $property = Property::create(['landlord_id' => $this->landlord->id, 'name' => 'Property Alpha']);

        $this->utility = PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $this->landlord->id,
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered,
            'rate' => 0.15,
            'unit_of_measure' => 'kWh',
        ]);

        $this->unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $this->landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'rent_amount' => 500,
        ]);

        $this->actingAs($this->landlord);
    }

    private function metersTab()
    {
        return Livewire::test(MetersRelationManager::class, [
            'ownerRecord' => $this->utility,
            'pageClass' => EditPropertyUtility::class,
        ]);
    }

    public function test_the_meters_tab_renders_and_can_install_a_meter_at_a_non_zero_index(): void
    {
        $this->metersTab()
            ->assertSuccessful()
            ->callTableAction('create', data: [
                'unit_id' => $this->unit->id,
                'serial' => 'SN-4471',
                'installed_on' => '2026-07-01',
                'installed_reading' => '8432.5',
                'multiplier' => 1,
            ])
            ->assertHasNoTableActionErrors();

        $meter = UtilityMeter::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('SN-4471', $meter->serial);
        $this->assertEquals(8432.5, (float) $meter->installed_reading);
        $this->assertSame(MeterStatus::Active, $meter->status);
    }

    public function test_a_second_active_meter_for_the_same_room_is_rejected(): void
    {
        UtilityMeter::create([
            'property_utility_id' => $this->utility->id,
            'landlord_id' => $this->landlord->id,
            'unit_id' => $this->unit->id,
            'installed_on' => '2026-01-01',
            'installed_reading' => 100,
        ]);

        $this->metersTab()
            ->callTableAction('create', data: [
                'unit_id' => $this->unit->id,
                'installed_on' => '2026-07-01',
                'installed_reading' => '0',
                'multiplier' => 1,
            ])
            ->assertHasTableActionErrors(['unit_id']);

        $this->assertSame(1, UtilityMeter::withoutGlobalScopes()->count());
    }

    public function test_replace_action_retires_the_old_meter_and_opens_the_new_one(): void
    {
        $meter = UtilityMeter::create([
            'property_utility_id' => $this->utility->id,
            'landlord_id' => $this->landlord->id,
            'unit_id' => $this->unit->id,
            'installed_on' => '2026-01-01',
            'installed_reading' => 8000,
        ]);

        $this->metersTab()
            ->callTableAction('replace', $meter, data: [
                'date' => '2026-07-10',
                'final_reading' => '8600',
                'installed_reading' => '0',
                'serial' => 'SN-9002',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(MeterStatus::Removed, $meter->refresh()->status);
        $this->assertEquals(8600, (float) $meter->final_reading);

        $new = UtilityMeter::withoutGlobalScopes()->where('serial', 'SN-9002')->firstOrFail();
        $this->assertSame(MeterStatus::Active, $new->status);
        $this->assertEquals(0, (float) $new->installed_reading);
    }

    public function test_the_tab_is_hidden_when_the_feature_is_switched_off(): void
    {
        $this->assertTrue(MetersRelationManager::canViewForRecord($this->utility, EditPropertyUtility::class));

        config()->set('utilities.meters', false);
        $this->assertFalse(MetersRelationManager::canViewForRecord($this->utility, EditPropertyUtility::class));

        // ...and for a flat-rate utility, which has no meter at all.
        config()->set('utilities.meters', true);
        $this->utility->update(['billing_type' => BillingType::Flat]);
        $this->assertFalse(MetersRelationManager::canViewForRecord($this->utility->refresh(), EditPropertyUtility::class));
    }
}
