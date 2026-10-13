<?php

namespace Tests\Feature;

use App\Enums\PropertyType;
use App\Enums\UserStatus;
use App\Filament\Pages\AddProperty;
use App\Livewire\PropertySwitcher;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\LandlordRegistrationService;
use App\Support\ActiveProperty;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * "Add property" (/app/add-property) reuses the signup wizard's screens for an
 * established landlord. The risk is the shared code: the wizard deletes
 * properties dropped from its list and tracks onboarding progress — neither may
 * happen here, or adding a building could wipe an existing one.
 */
class AddPropertyTest extends TestCase
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

    public function test_walks_the_wizard_steps_and_creates_one_new_property_with_rooms(): void
    {
        [$landlord, $existing] = $this->landlordWithProperty();

        Livewire::actingAs($landlord)
            ->test(AddProperty::class)
            ->assertSet('properties.0.id', null)
            ->set('properties.0.name', 'New Tower')
            ->set('properties.0.city', 'Phnom Penh')
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 3)
            ->tap(function ($c) {
                $id = Property::where('name', 'New Tower')->value('id');
                foreach (array_keys($c->get("utilities.$id")) as $j) {
                    $c->set("utilities.$id.$j.rate", 1);
                }
            })
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 4)
            ->tap(function ($c) {
                $id = Property::where('name', 'New Tower')->value('id');
                $c->set("rooms.$id.floors", 2)->set("rooms.$id.per_floor", 3)->set("rooms.$id.price", 120)
                    ->call('generateRooms', $id);
            })
            ->call('next')
            ->assertSet('step', 5)
            ->call('finish')
            ->assertRedirect();

        $new = Property::where('name', 'New Tower')->firstOrFail();
        $this->assertSame(6, Unit::where('property_id', $new->id)->count());
        $this->assertSame($new->id, ActiveProperty::id());

        // The existing property and its rooms are untouched.
        $this->assertNotNull(Property::find($existing->id));
        $this->assertSame(2, Unit::where('property_id', $existing->id)->count());
    }

    public function test_it_never_edits_or_deletes_the_landlords_other_properties(): void
    {
        [$landlord, $existing] = $this->landlordWithProperty();

        // A client forcing the existing property's id into the form is ignored.
        Livewire::actingAs($landlord)
            ->test(AddProperty::class)
            ->set('properties.0.id', $existing->id)
            ->set('properties.0.name', 'Hijack')
            ->call('next');

        $this->assertSame('Existing House', $existing->fresh()->name);
        $this->assertDatabaseHas('properties', ['name' => 'Hijack']);
        $this->assertSame(2, Property::count());
    }

    public function test_onboarding_progress_is_not_touched(): void
    {
        [$landlord] = $this->landlordWithProperty();
        $before = $landlord->landlordProfile->fresh()->onboarding_step;

        Livewire::actingAs($landlord)
            ->test(AddProperty::class)
            ->set('properties.0.name', 'Another')
            ->call('next');

        $this->assertEquals($before, $landlord->landlordProfile->fresh()->onboarding_step);
    }

    public function test_room_rental_is_a_selectable_property_type(): void
    {
        [$landlord] = $this->landlordWithProperty();

        $options = PropertyType::options();
        $this->assertSame(__('Room rental'), $options[7]);
        $this->assertSame(__('Other'), end($options));

        Livewire::actingAs($landlord)
            ->test(AddProperty::class)
            ->set('properties.0.name', 'Student rooms')
            ->set('properties.0.property_type', 7)
            ->call('next')
            ->assertHasNoErrors();

        $this->assertSame(PropertyType::RoomRental, Property::where('name', 'Student rooms')->firstOrFail()->property_type);
    }

    public function test_default_utilities_show_their_translated_name_but_keep_the_english_key(): void
    {
        [$landlord] = $this->landlordWithProperty();

        $page = Livewire::actingAs($landlord)
            ->test(AddProperty::class)
            ->set('properties.0.name', 'Khmer names')
            ->call('next')
            ->assertSee(__('Electricity'))
            ->assertSeeHtml('<option value="Electricity"');

        $id = Property::where('name', 'Khmer names')->value('id');
        $this->assertSame('Electricity', $page->get("utilities.$id.0.name"));
    }

    public function test_name_is_required(): void
    {
        [$landlord] = $this->landlordWithProperty();

        Livewire::actingAs($landlord)
            ->test(AddProperty::class)
            ->set('properties.0.name', '')
            ->call('next')
            ->assertHasErrors('properties.0.name');
    }

    public function test_users_without_the_create_permission_are_forbidden(): void
    {
        [$landlord] = $this->landlordWithProperty();

        Role::findByName('landlord')->revokePermissionTo('create_property');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($landlord->fresh())
            ->get(AddProperty::getUrl())
            ->assertForbidden();
    }

    public function test_platform_staff_without_a_landlord_cannot_use_it(): void
    {
        $admin = User::factory()->create(['status' => UserStatus::Active->value]);
        $admin->assignRole('super_admin');

        $this->actingAs($admin)->get(AddProperty::getUrl())->assertForbidden();

        Livewire::actingAs($admin)
            ->test(PropertySwitcher::class)
            ->assertDontSeeHtml('id="rw-add-property-option"');
    }

    public function test_the_switcher_option_opens_this_screen(): void
    {
        [$landlord] = $this->landlordWithProperty();

        Livewire::actingAs($landlord)
            ->test(PropertySwitcher::class)
            ->set('propertyId', PropertySwitcher::ADD)
            ->assertRedirect(AddProperty::getUrl());
    }

    public function test_the_page_renders_for_a_landlord(): void
    {
        [$landlord] = $this->landlordWithProperty();

        $this->actingAs($landlord)->get(AddProperty::getUrl())->assertOk()->assertSee(__('Add property'));
    }

    /** @return array{0: User, 1: Property} */
    private function landlordWithProperty(): array
    {
        static $n = 0;
        $n++;

        $landlord = app(LandlordRegistrationService::class)->register([
            'name' => 'Landlord '.$n,
            'phone_number' => '09876543'.$n,
            'password' => 'Secret-Pass-2026',
        ]);
        $landlord->landlordProfile->update(['onboarding_completed_at' => now()]);

        $this->actingAs($landlord);
        $existing = Property::create(['name' => 'Existing House']);
        foreach (['101', '102'] as $number) {
            Unit::create([
                'property_id' => $existing->id, 'landlord_id' => $landlord->id,
                'room_number' => $number, 'room_type' => 'Room', 'rent_amount' => 100,
            ]);
        }

        return [$landlord->fresh(), $existing];
    }
}
