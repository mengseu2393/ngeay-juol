<?php

namespace Tests\Feature;

use App\Enums\OnboardingStep;
use App\Enums\PropertyType;
use App\Filament\Pages\LandlordSetup;
use App\Livewire\M2\Setup;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\User;
use App\Services\LandlordRegistrationService;
use App\Support\ActiveProperty;
use Database\Seeders\SubscriptionPlanSeeder;
use Livewire\Livewire;

/**
 * /m2/setup — the setup wizard after /register, rebuilt for phones. The owner asked for the same
 * flow as the panel's wizard with a new, responsive screen: the steps, rules and writes are the
 * shared RunsLandlordSetup (LandlordSetupWizardTest pins those); these pin who is sent here and
 * that the phone screens drive the same steps to the end.
 */
class M2SetupTest extends M2TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlanSeeder::class);
    }

    public function test_registering_on_a_phone_opens_the_m2_setup_and_on_a_desktop_the_panel_one(): void
    {
        $this->withHeader('User-Agent', self::PHONE)
            ->post(route('register'), ['name' => 'Phone Owner', 'phone_number' => '012345671', 'password' => 'x', 'password_confirmation' => 'x'])
            ->assertRedirect(route('m2.setup'));

        auth()->logout();
        $this->flushSession();

        $this->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64) Chrome/130.0 Safari/537.36')
            ->post(route('register'), ['name' => 'Desk Owner', 'phone_number' => '012345672', 'password' => 'x', 'password_confirmation' => 'x'])
            ->assertRedirect(route('filament.landlord.pages.setup'));
    }

    public function test_an_unfinished_landlord_in_m2_is_sent_back_to_the_m2_setup(): void
    {
        $this->actingAs($this->registeredLandlord());

        $this->get(route('m2.home'))->assertRedirect(route('m2.setup'));
        $this->get(route('m2.setup'))->assertOk()->assertSee(__('Your properties'))->assertDontSee('fi-');

        // A phone opening the panel goes to the phone wizard too; a desktop keeps the panel's.
        $this->withHeader('User-Agent', self::PHONE)
            ->get(route('filament.landlord.pages.dashboard'))
            ->assertRedirect(route('m2.setup'));
    }

    public function test_the_phone_wizard_walks_every_step_to_the_end(): void
    {
        $landlord = $this->registeredLandlord();
        $this->actingAs($landlord);

        $wizard = Livewire::test(Setup::class)
            ->assertSet('properties.0.property_type', PropertyType::RoomRental->value)
            ->set('properties.0.name', 'Sunrise Rooms')
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', OnboardingStep::Utilities->value);

        $property = Property::query()->sole();

        $wizard->set('utilities.'.$property->id.'.0.rate', '1000')
            ->set('utilities.'.$property->id.'.1.rate', '2500')
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', OnboardingStep::Rooms->value)
            ->set("rooms.{$property->id}.floors", '2')
            ->set("rooms.{$property->id}.per_floor", '2')
            ->set("rooms.{$property->id}.price", '80')
            ->call('generateRooms', $property->id)
            ->assertSet("rooms.{$property->id}.grid.0.number", '101')
            ->set("rooms.{$property->id}.grid.3.price", '95')
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', OnboardingStep::Review->value)
            ->assertSee('Sunrise Rooms')
            ->call('finish')
            ->assertRedirect(route('m2.home'));

        $this->assertEquals(['101' => 80, '102' => 80, '201' => 80, '202' => 95], Unit::query()->orderBy('room_number')->pluck('rent_amount', 'room_number')->map(fn ($p) => (float) $p)->all());
        $this->assertEquals(1000, (float) PropertyUtility::where('name', 'Electricity')->value('rate'));
        $this->assertNotNull($landlord->fresh()->landlordProfile->onboarding_completed_at);
        $this->assertSame($property->id, ActiveProperty::id());
    }

    public function test_finish_later_leaves_for_m2_home_for_this_session(): void
    {
        $this->actingAs($this->registeredLandlord());

        Livewire::test(Setup::class)->call('skip')->assertRedirect(route('m2.home'));

        $this->assertTrue(session(LandlordSetup::SKIP_SESSION_KEY));
        $this->get(route('m2.home'))->assertOk();
    }

    public function test_a_landlord_who_never_registered_cannot_open_it(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $this->get(route('m2.setup'))->assertForbidden();
    }

    private function registeredLandlord(): User
    {
        static $n = 0;
        $n++;

        return app(LandlordRegistrationService::class)->register([
            'name' => 'Phone Landlord '.$n,
            'phone_number' => '09876543'.$n,
            'password' => 'Secret-Pass-2026',
        ]);
    }
}
