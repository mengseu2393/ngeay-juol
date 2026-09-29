<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\OnboardingStep;
use App\Enums\PropertyType;
use App\Enums\UserStatus;
use App\Filament\Pages\LandlordSetup;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use App\Models\User;
use App\Services\LandlordRegistrationService;
use App\Services\SubscriptionService;
use App\Support\ActiveProperty;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The /app/setup wizard saves each step to the real tables on "Next" so a
 * landlord who closes the app mid-way resumes where they left off — these pin
 * that, plus that Back → edit → Next updates rows instead of duplicating them.
 */
class LandlordSetupWizardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('landlord'));
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();

        parent::tearDown();
    }

    public function test_self_registered_landlord_is_sent_to_the_wizard_until_it_is_done(): void
    {
        $landlord = $this->registeredLandlord();

        $this->actingAs($landlord)
            ->get(route('filament.landlord.pages.dashboard'))
            ->assertRedirect(route('filament.landlord.pages.setup'));

        $this->get(route('filament.landlord.pages.setup'))->assertOk();
    }

    public function test_staff_provisioned_landlords_never_see_the_wizard(): void
    {
        $landlord = User::factory()->create(['status' => UserStatus::Active->value]);
        $landlord->assignRole('landlord');
        SubscriptionService::assign($landlord, SubscriptionPlan::firstOrFail());

        $this->actingAs($landlord)
            ->get(route('filament.landlord.pages.dashboard'))
            ->assertOk();

        $this->get(route('filament.landlord.pages.setup'))->assertForbidden();
    }

    public function test_finish_later_stops_the_redirect_for_this_session_only(): void
    {
        $landlord = $this->registeredLandlord();
        $this->actingAs($landlord);

        Livewire::test(LandlordSetup::class)
            ->call('skip')
            ->assertRedirect(route('filament.landlord.pages.dashboard'));

        $this->get(route('filament.landlord.pages.dashboard'))->assertOk();
        $this->assertNull($landlord->fresh()->landlordProfile->onboarding_completed_at);
    }

    public function test_properties_step_saves_properties_currency_and_default_utilities(): void
    {
        $landlord = $this->registeredLandlord();
        $this->actingAs($landlord);

        Livewire::test(LandlordSetup::class)
            ->set('properties.0.name', 'Sunrise Apartment')
            ->set('properties.0.currency', 'KHR')
            ->call('addProperty')
            ->set('properties.1.name', 'Riverside House')
            ->set('properties.1.property_type', PropertyType::House->value)
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', OnboardingStep::Utilities->value);

        $properties = Property::orderBy('id')->get();
        $this->assertSame(['Sunrise Apartment', 'Riverside House'], $properties->pluck('name')->all());
        $this->assertTrue($properties->every(fn ($p) => $p->landlord_id === $landlord->id));
        $this->assertSame('KHR', PropertySetting::where('property_id', $properties[0]->id)->value('currency'));
        $this->assertSame(['Electricity', 'Water'], PropertyUtility::where('property_id', $properties[0]->id)->orderBy('id')->pluck('name')->all());
        $this->assertSame(OnboardingStep::Utilities, $landlord->fresh()->landlordProfile->onboarding_step);
    }

    public function test_property_name_is_required(): void
    {
        $this->actingAs($this->registeredLandlord());

        Livewire::test(LandlordSetup::class)
            ->call('next')
            ->assertHasErrors('properties.0.name')
            ->assertSet('step', OnboardingStep::Properties->value);

        $this->assertSame(0, Property::count());
    }

    public function test_going_back_and_saving_again_updates_instead_of_duplicating(): void
    {
        $this->actingAs($this->registeredLandlord());

        $wizard = Livewire::test(LandlordSetup::class)
            ->set('properties.0.name', 'Sunrise')
            ->call('next')
            ->call('back')
            ->assertSet('step', OnboardingStep::Properties->value)
            ->set('properties.0.name', 'Sunrise Apartment')
            ->call('next');

        $wizard->assertHasNoErrors();
        $this->assertSame(['Sunrise Apartment'], Property::pluck('name')->all());
        $this->assertSame(2, PropertyUtility::count());
    }

    public function test_removing_a_property_card_deletes_the_saved_property(): void
    {
        $this->actingAs($this->registeredLandlord());

        Livewire::test(LandlordSetup::class)
            ->set('properties.0.name', 'Keep')
            ->call('addProperty')
            ->set('properties.1.name', 'Drop')
            ->call('next')
            ->call('back')
            ->call('removeProperty', 1)
            ->call('next')
            ->assertHasNoErrors();

        $this->assertSame(['Keep'], Property::pluck('name')->all());
    }

    public function test_utilities_step_saves_prices_and_custom_flat_fees(): void
    {
        $this->actingAs($this->registeredLandlord());
        $wizard = $this->wizardAtUtilities('Sunrise');
        $propertyId = Property::value('id');

        $wizard
            ->set("utilities.$propertyId.0.rate", '0.25')
            ->set("utilities.$propertyId.1.rate", '0.5')
            ->call('addUtility', $propertyId)
            ->set("utilities.$propertyId.2.name", 'Trash')
            ->set("utilities.$propertyId.2.rate", '2')
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', OnboardingStep::Rooms->value);

        $rates = PropertyUtility::where('property_id', $propertyId)->orderBy('id')->get();
        $this->assertSame(['Electricity', 'Water', 'Trash'], $rates->pluck('name')->all());
        $this->assertSame(['0.2500', '0.5000', '2.0000'], $rates->map(fn ($u) => number_format((float) $u->rate, 4, '.', ''))->all());
        $this->assertSame(BillingType::Flat, $rates[2]->billing_type);
    }

    public function test_utility_price_is_required_and_removed_utilities_are_deleted(): void
    {
        $this->actingAs($this->registeredLandlord());
        $wizard = $this->wizardAtUtilities('Sunrise');
        $propertyId = Property::value('id');

        $wizard->call('next')->assertHasErrors("utilities.$propertyId.0.rate");

        $wizard
            ->set("utilities.$propertyId.0.rate", '0.25')
            ->call('removeUtility', $propertyId, 1)
            ->call('next')
            ->assertHasNoErrors();

        $this->assertSame(['Electricity'], PropertyUtility::where('property_id', $propertyId)->pluck('name')->all());
    }

    public function test_rooms_step_generates_a_priced_grid_and_saves_units(): void
    {
        $this->actingAs($this->registeredLandlord());
        $wizard = $this->wizardAtRooms('Sunrise', currency: 'KHR');
        $propertyId = Property::value('id');

        $wizard
            ->set("rooms.$propertyId.floors", 2)
            ->set("rooms.$propertyId.per_floor", 3)
            ->set("rooms.$propertyId.price", 300000)
            ->call('generateRooms', $propertyId)
            ->assertCount("rooms.$propertyId.grid", 6)
            ->set("rooms.$propertyId.grid.5.price", 350000)
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', OnboardingStep::Review->value);

        $units = Unit::where('property_id', $propertyId)->orderBy('id')->get();
        $this->assertSame(['101', '102', '103', '201', '202', '203'], $units->pluck('room_number')->all());
        $this->assertSame(300000.0, (float) $units[0]->rent_amount);
        $this->assertSame(350000.0, (float) $units[5]->rent_amount);
        $this->assertTrue($units->every(fn ($u) => $u->rent_currency === 'KHR'));
    }

    /** Regenerating keeps hand-edited prices and the saved rows' ids — no duplicate rooms. */
    public function test_regenerating_rooms_after_going_back_does_not_duplicate_units(): void
    {
        $this->actingAs($this->registeredLandlord());
        $wizard = $this->wizardAtRooms('Sunrise');
        $propertyId = Property::value('id');

        $wizard
            ->set("rooms.$propertyId.floors", 1)
            ->set("rooms.$propertyId.per_floor", 2)
            ->set("rooms.$propertyId.price", 80)
            ->call('generateRooms', $propertyId)
            ->set("rooms.$propertyId.grid.1.price", 95)
            ->call('next')
            ->call('back')
            ->set("rooms.$propertyId.per_floor", 3)
            ->call('generateRooms', $propertyId)
            ->call('next')
            ->assertHasNoErrors();

        $units = Unit::where('property_id', $propertyId)->orderBy('room_number')->get();
        $this->assertSame(['101', '102', '103'], $units->pluck('room_number')->all());
        $this->assertSame([80.0, 95.0, 80.0], $units->map(fn ($u) => (float) $u->rent_amount)->all());
    }

    public function test_each_property_needs_at_least_one_room(): void
    {
        $this->actingAs($this->registeredLandlord());
        $wizard = $this->wizardAtRooms('Sunrise');
        $propertyId = Property::value('id');

        $wizard->call('next')
            ->assertHasErrors("rooms.$propertyId.grid")
            ->assertSet('step', OnboardingStep::Rooms->value);
    }

    public function test_plan_room_limit_is_enforced_in_the_wizard(): void
    {
        $landlord = $this->registeredLandlord();
        Subscription::withoutGlobalScopes()->where('landlord_id', $landlord->id)->update(['max_units' => 2]);
        $this->actingAs($landlord);
        $wizard = $this->wizardAtRooms('Sunrise');
        $propertyId = Property::value('id');

        $wizard
            ->set("rooms.$propertyId.per_floor", 3)
            ->set("rooms.$propertyId.price", 50)
            ->call('generateRooms', $propertyId)
            ->call('next')
            ->assertHasErrors('subscription')
            ->assertSet('step', OnboardingStep::Rooms->value);

        $this->assertSame(0, Unit::count(), 'The whole step rolls back');
    }

    public function test_reopening_the_wizard_resumes_at_the_saved_step_with_saved_data(): void
    {
        $this->actingAs($this->registeredLandlord());
        $this->wizardAtUtilities('Sunrise');

        Livewire::test(LandlordSetup::class)
            ->assertSet('step', OnboardingStep::Utilities->value)
            ->assertSet('properties.0.name', 'Sunrise');
    }

    public function test_finishing_completes_onboarding_and_selects_the_first_property(): void
    {
        $landlord = $this->registeredLandlord();
        $this->actingAs($landlord);
        $wizard = $this->wizardAtRooms('Sunrise');
        $propertyId = Property::value('id');

        $wizard
            ->set("rooms.$propertyId.price", 100)
            ->call('generateRooms', $propertyId)
            ->call('next')
            ->assertSee('Sunrise')
            ->call('finish')
            ->assertRedirect(route('filament.landlord.pages.dashboard'));

        $this->assertNotNull($landlord->fresh()->landlordProfile->onboarding_completed_at);
        $this->assertSame($propertyId, ActiveProperty::id());

        $this->get(route('filament.landlord.pages.dashboard'))->assertOk();
        $this->get(route('filament.landlord.pages.setup'))->assertRedirect(route('filament.landlord.pages.dashboard'));
    }

    public function test_a_tampered_id_cannot_edit_another_landlords_property(): void
    {
        $other = User::factory()->create(['status' => UserStatus::Active->value]);
        $other->assignRole('landlord');
        $foreign = Property::withoutGlobalScopes()->forceCreate(['landlord_id' => $other->id, 'name' => 'Not yours']);

        $this->actingAs($this->registeredLandlord());

        Livewire::test(LandlordSetup::class)
            ->set('properties.0.id', $foreign->id)
            ->set('properties.0.name', 'Hijacked')
            ->call('next')
            ->assertHasNoErrors();

        $this->assertSame('Not yours', Property::withoutGlobalScopes()->find($foreign->id)->name);
    }

    public function test_setup_page_renders_every_step(): void
    {
        $this->actingAs($this->registeredLandlord());

        $wizard = Livewire::test(LandlordSetup::class)->assertSee(__('Your properties'));
        $wizard->set('properties.0.name', 'Sunrise')->call('next')->assertSee(__('Utility prices'));
        $propertyId = Property::value('id');
        $wizard->set("utilities.$propertyId.0.rate", 1)->set("utilities.$propertyId.1.rate", 1)
            ->call('next')->assertSee(__('Rooms & prices'));
    }

    // ---------------------------------------------------------------------

    private function registeredLandlord(): User
    {
        static $n = 0;
        $n++;

        return app(LandlordRegistrationService::class)->register([
            'name' => 'Landlord '.$n,
            'phone_number' => '01234567'.$n,
            'password' => 'Secret-Pass-2026',
        ]);
    }

    private function wizardAtUtilities(string $name, string $currency = 'USD')
    {
        return Livewire::test(LandlordSetup::class)
            ->set('properties.0.name', $name)
            ->set('properties.0.currency', $currency)
            ->call('next')
            ->assertHasNoErrors();
    }

    private function wizardAtRooms(string $name, string $currency = 'USD')
    {
        $wizard = $this->wizardAtUtilities($name, $currency);
        $propertyId = Property::value('id');

        return $wizard
            ->set("utilities.$propertyId.0.rate", '0.25')
            ->set("utilities.$propertyId.1.rate", '0.5')
            ->call('next')
            ->assertHasNoErrors();
    }
}
