<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Filament\Resources\PropertyResource\Pages\CreateProperty;
use App\Filament\Resources\PropertyResource\Pages\ViewProperty;
use App\Filament\Widgets\PropertySetupChecklistWidget;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use App\Services\DefaultPropertyUtilitiesService;
use App\Support\ActiveProperty;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Covers the two halves of "a new property can't bill and nothing says so":
 * the starter utility catalog seeded at create time, and the checklist widget
 * that names whatever is still missing.
 *
 * The rate assertions are the load-bearing ones. Seeding Electricity/Water with
 * rate 0 is deliberate — a guessed rate would bill wrong money silently — so the
 * checklist must treat a 0-rate utility as unfinished work, not as done.
 */
class PropertySetupChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('landlord'));
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();

        parent::tearDown();
    }

    public function test_creating_a_property_seeds_electricity_and_water(): void
    {
        $landlord = $this->createLandlord();
        $this->actingAs($landlord);

        Livewire::test(CreateProperty::class)
            ->fillForm([
                'landlord_id' => $landlord->id,
                'name' => 'Borey One',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $property = Property::where('name', 'Borey One')->firstOrFail();

        $this->assertDatabaseHas('property_utilities', [
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Electricity',
            'unit_of_measure' => 'kWh',
            'billing_type' => BillingType::Metered->value,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('property_utilities', [
            'property_id' => $property->id,
            'name' => 'Water',
            'unit_of_measure' => 'm³',
        ]);

        // Rate stays 0: only the landlord knows what EDC/PPWSA charges.
        $this->assertEqualsWithDelta(
            0.0,
            (float) PropertyUtility::where('property_id', $property->id)->value('rate'),
            0.0001,
        );
    }

    /** Seeding is one-shot: a property that already has a catalog is left alone. */
    public function test_seeding_is_skipped_when_the_property_already_has_utilities(): void
    {
        $landlord = $this->createLandlord();
        $property = $this->createProperty($landlord);

        PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered,
            'rate' => 0.25,
            'unit_of_measure' => 'kWh',
            'is_active' => true,
        ]);

        $created = app(DefaultPropertyUtilitiesService::class)->seed($property);

        $this->assertTrue($created->isEmpty());
        $this->assertSame(1, PropertyUtility::where('property_id', $property->id)->count());
    }

    /** A deleted "Water" must stay deleted rather than reappear on the next seed. */
    public function test_seeding_does_not_resurrect_a_soft_deleted_utility(): void
    {
        $landlord = $this->createLandlord();
        $property = $this->createProperty($landlord);

        $utility = PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Water',
            'billing_type' => BillingType::Metered,
            'rate' => 0.5,
            'unit_of_measure' => 'm³',
            'is_active' => true,
        ]);
        $utility->delete();

        app(DefaultPropertyUtilitiesService::class)->seed($property);

        $this->assertSame(0, PropertyUtility::where('property_id', $property->id)->count());
    }

    public function test_checklist_lists_every_unfinished_step_for_a_bare_property(): void
    {
        $landlord = $this->createLandlord();
        $property = $this->createProperty($landlord);
        $this->actingAs($landlord);

        $widget = Livewire::test(PropertySetupChecklistWidget::class, ['propertyId' => $property->id])
            ->assertOk()
            // Renders the view, not just the data: the checklist is hand-styled
            // markup, so a Blade error here would otherwise only show in prod.
            ->assertSee(__('Add rooms'))
            ->assertSee(__('Set up utilities'));

        $steps = collect($widget->instance()->getSteps())->keyBy('key');

        $this->assertFalse($steps['rooms']['done']);
        $this->assertFalse($steps['utilities']['done']);
        $this->assertFalse($steps['tenants']['done']);

        // Rates and readings are neither done nor actionable yet: a green tick
        // would claim "every utility has a rate" about an empty catalog.
        $this->assertFalse($steps['rates']['done']);
        $this->assertTrue($steps['rates']['blocked']);
        $this->assertFalse($steps['readings']['done']);
        $this->assertTrue($steps['readings']['blocked']);
    }

    public function test_a_zero_rate_utility_keeps_the_rates_step_open(): void
    {
        $landlord = $this->createLandlord();
        $property = $this->createProperty($landlord);

        PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered,
            'rate' => 0,
            'unit_of_measure' => 'kWh',
            'is_active' => true,
        ]);

        $this->actingAs($landlord);

        $steps = collect(
            Livewire::test(PropertySetupChecklistWidget::class, ['propertyId' => $property->id])
                ->instance()
                ->getSteps()
        )->keyBy('key');

        $this->assertTrue($steps['utilities']['done']);
        $this->assertFalse($steps['rates']['done']);
        $this->assertFalse($steps['rates']['blocked']);
    }

    public function test_rooms_without_an_opening_index_keep_the_readings_step_open(): void
    {
        $landlord = $this->createLandlord();
        $property = $this->createProperty($landlord);

        $utility = PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered,
            'rate' => 0.25,
            'unit_of_measure' => 'kWh',
            'is_active' => true,
        ]);

        $unit = $this->createUnit($property, '101');
        $this->createUnit($property, '102');

        UtilityUsage::create([
            'property_utility_id' => $utility->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'recorded_by_id' => $landlord->id,
            'reading_date' => '2026-09-01',
            'old_reading' => 120,
            'new_reading' => 120,
            'amount_used' => 0,
        ]);

        $this->actingAs($landlord);

        $steps = collect(
            Livewire::test(PropertySetupChecklistWidget::class, ['propertyId' => $property->id])
                ->instance()
                ->getSteps()
        )->keyBy('key');

        $this->assertTrue($steps['rooms']['done']);
        $this->assertTrue($steps['rates']['done']);
        // Room 101 has a baseline, 102 does not.
        $this->assertFalse($steps['readings']['done']);
        $this->assertFalse($steps['readings']['blocked']);
    }

    public function test_widget_hides_itself_once_the_property_is_fully_set_up(): void
    {
        $landlord = $this->createLandlord();
        $property = $this->createFullySetUpProperty($landlord);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        $this->assertFalse(PropertySetupChecklistWidget::canView());
    }

    public function test_widget_is_visible_on_the_overview_of_an_unfinished_property(): void
    {
        $landlord = $this->createLandlord();
        $property = $this->createProperty($landlord);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::test(ViewProperty::class, ['record' => $property->id])
            ->assertOk();

        $this->assertTrue(PropertySetupChecklistWidget::canView());
    }

    /**
     * The Overview can show a property that is not the one in the sidebar
     * switcher, so the page must hand the widget its own record rather than let
     * it read the session context.
     */
    public function test_overview_passes_its_own_record_to_the_widget(): void
    {
        $landlord = $this->createLandlord();
        $viewed = $this->createProperty($landlord, 'Viewed');
        $active = $this->createProperty($landlord, 'Active');

        $this->actingAs($landlord);
        ActiveProperty::set($active->id);

        $data = Livewire::test(ViewProperty::class, ['record' => $viewed->id])
            ->instance()
            ->getWidgetData();

        $this->assertSame($viewed->id, $data['propertyId']);
    }

    /** Clicking a step selects the property first — its target is context-scoped. */
    public function test_going_to_a_step_switches_the_active_property(): void
    {
        $landlord = $this->createLandlord();
        $other = $this->createProperty($landlord, 'Other');
        $property = $this->createProperty($landlord, 'Target');

        $this->actingAs($landlord);
        ActiveProperty::set($other->id);

        Livewire::test(PropertySetupChecklistWidget::class, ['propertyId' => $property->id])
            ->call('go', 'rooms')
            ->assertRedirect();

        $this->assertSame($property->id, ActiveProperty::id());
    }

    private function createLandlord(): User
    {
        $user = User::create([
            'name' => 'Landlord User',
            'email' => 'landlord-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole('landlord');

        return $user;
    }

    private function createProperty(User $landlord, string $name = 'Prop'): Property
    {
        return Property::create([
            'landlord_id' => $landlord->id,
            'name' => $name,
        ]);
    }

    private function createUnit(Property $property, string $roomNumber): Unit
    {
        return Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $property->landlord_id,
            'room_number' => $roomNumber,
            'room_type' => 'Standard',
            'status' => UnitStatus::Available,
        ]);
    }

    private function createFullySetUpProperty(User $landlord): Property
    {
        $property = $this->createProperty($landlord, 'Complete');

        $utility = PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered,
            'rate' => 0.25,
            'unit_of_measure' => 'kWh',
            'is_active' => true,
        ]);

        $unit = $this->createUnit($property, '101');

        UtilityUsage::create([
            'property_utility_id' => $utility->id,
            'unit_id' => $unit->id,
            'landlord_id' => $landlord->id,
            'recorded_by_id' => $landlord->id,
            'reading_date' => '2026-09-01',
            'old_reading' => 100,
            'new_reading' => 100,
            'amount_used' => 0,
        ]);

        $tenant = User::create([
            'name' => 'Tenant',
            'email' => 'tenant-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $tenant->assignRole('tenant');

        Rental::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'unit_id' => $unit->id,
            'occupant_name' => 'Occupant',
            'monthly_rent' => 500,
            'status' => RentalStatus::Active,
            'start_date' => '2026-09-01',
        ]);

        return $property;
    }
}
