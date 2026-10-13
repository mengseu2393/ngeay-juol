<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\ReadingType;
use App\Enums\RentalStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UnitStatus;
use App\Enums\UserStatus;
use App\Filament\Pages\UnitOverview;
use App\Filament\Resources\PropertyResource;
use App\Filament\Resources\PropertyResource\Pages\EditProperty;
use App\Livewire\PropertyUnitsManager;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The property settings "Units" tab: a card list that opens each room's own page.
 */
class PropertyUnitsManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-20 09:00:00');
        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('landlord'));
        ActiveProperty::clear();
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_lists_every_room_with_status_and_filters(): void
    {
        [$landlord, $property] = $this->fixture();

        Livewire::actingAs($landlord)->test(PropertyUnitsManager::class, ['propertyId' => $property->id])
            ->assertSee('101')->assertSee('102')->assertSee('Sok Dara')
            ->set('filter', 'vacant')->assertSee('101')->assertDontSee('Sok Dara')
            ->set('filter', 'occupied')->assertSee('Sok Dara');
    }

    public function test_each_card_links_to_the_units_own_page(): void
    {
        [$landlord, $property, $vacant] = $this->fixture();

        Livewire::actingAs($landlord)->test(PropertyUnitsManager::class, ['propertyId' => $property->id])
            ->assertSeeHtml('href="'.UnitOverview::getUrl(['unit' => $vacant->id]).'"');
    }

    public function test_another_landlords_property_is_not_accessible(): void
    {
        [, $property] = $this->fixture();
        $other = $this->makeLandlord();

        $this->expectException(ModelNotFoundException::class); // renders as a 404

        Livewire::actingAs($other)->test(PropertyUnitsManager::class, ['propertyId' => $property->id]);
    }

    public function test_the_property_edit_page_has_the_units_tab_and_the_back_link_target_exists(): void
    {
        [$landlord, $property] = $this->fixture();

        Livewire::actingAs($landlord)->test(EditProperty::class, ['record' => $property->id])
            ->assertSee(__('Units'));

        $this->actingAs($landlord)
            ->get(PropertyResource::getUrl('edit', ['record' => $property->id, 'tab' => 'property_tabs-units-tab']))
            ->assertOk()
            ->assertSee('property-units-manager', false);
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

    private function fixture(): array
    {
        $landlord = $this->makeLandlord();
        $property = Property::create(['landlord_id' => $landlord->id, 'name' => 'Test Property']);
        $vacant = Unit::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id, 'room_number' => '101',
            'room_type' => 'Standard', 'rent_amount' => 500, 'status' => UnitStatus::Available,
        ]);
        $occupied = Unit::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id, 'room_number' => '102',
            'room_type' => 'Standard', 'rent_amount' => 500, 'status' => UnitStatus::Occupied,
        ]);
        $tenant = User::factory()->create(['email' => 't-'.uniqid().'@example.com']);
        $tenant->assignRole('tenant');
        Rental::create([
            'landlord_id' => $landlord->id, 'unit_id' => $occupied->id, 'tenant_id' => $tenant->id,
            'occupant_name' => 'Sok Dara', 'monthly_rent' => 500, 'status' => RentalStatus::Active,
            'start_date' => '2026-01-01',
        ]);
        $utility = PropertyUtility::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id, 'name' => 'Electricity',
            'billing_type' => BillingType::Metered, 'rate' => 0.15, 'unit_of_measure' => 'kWh',
        ]);

        return [$landlord, $property, $vacant, $occupied, $utility];
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
