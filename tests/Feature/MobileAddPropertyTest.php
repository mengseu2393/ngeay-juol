<?php

namespace Tests\Feature;

use App\Enums\PropertyType;
use App\Enums\UserStatus;
use App\Livewire\Mobile\AddProperty;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Support\ActiveProperty;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * /m/settings/property/new — the panel's Add property wizard as one phone
 * screen. Same rules (create permission, plan property/room limits, default
 * utilities) through PropertySetupService; nothing is written until Save, and
 * the landlord's other properties are never touched.
 */
class MobileAddPropertyTest extends MobileTestCase
{
    public function test_it_renders_without_any_filament(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);

        $this->signIn($landlord, $property)
            ->get(route('mobile.add-property'))
            ->assertOk()
            ->assertSee(__('Add property'))
            ->assertSee(__('Electricity'))
            ->assertSee(route('mobile.settings'), false)
            ->assertDontSee('fi-', false)
            ->assertDontSee('/app/', false);
    }

    public function test_it_creates_the_property_with_utilities_and_rooms_and_makes_it_active(): void
    {
        $landlord = $this->landlord();
        $existing = $this->property($landlord, 'Existing House');
        $this->signIn($landlord, $existing);

        Livewire::test(AddProperty::class)
            ->set('name', 'New Tower')
            ->set('propertyType', PropertyType::Apartment->value)
            ->set('currency', 'KHR')
            ->set('city', 'Phnom Penh')
            ->set('rates.Electricity', '1000')
            ->set('rates.Water', '2500')
            ->set('withRooms', true)
            ->set('floors', '2')
            ->set('perFloor', '3')
            ->set('roomPrice', '400000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('mobile.rooms'));

        $new = Property::where('name', 'New Tower')->firstOrFail();
        $this->assertSame($landlord->id, $new->landlord_id);
        $this->assertSame('Phnom Penh', $new->city);
        $this->assertSame($new->id, ActiveProperty::id());
        $this->assertSame('KHR', PropertySetting::where('property_id', $new->id)->value('currency'));
        $this->assertEquals(
            ['Electricity' => 1000, 'Water' => 2500],
            PropertyUtility::where('property_id', $new->id)->pluck('rate', 'name')->map(fn ($r) => (float) $r)->all(),
        );
        $this->assertSame(6, Unit::where('property_id', $new->id)->count());

        // The other property is untouched.
        $this->assertSame('Existing House', $existing->fresh()->name);
    }

    public function test_without_rooms_it_goes_to_the_property_settings(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(AddProperty::class)
            ->set('name', 'Bare plot')
            ->set('withRooms', false)
            ->set('floors', '') // ignored without rooms
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('mobile.property-settings'));

        $this->assertSame(0, Unit::where('property_id', Property::where('name', 'Bare plot')->value('id'))->count());
    }

    public function test_validation_errors_write_nothing(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(AddProperty::class)
            ->set('name', '')
            ->set('propertyType', 99)
            ->set('rates.Electricity', 'cheap')
            ->set('floors', '0')
            ->set('roomPrice', '')
            ->call('save')
            ->assertHasErrors(['name', 'propertyType', 'rates.Electricity', 'floors', 'roomPrice'])
            ->assertNoRedirect();

        $this->assertSame(1, Property::count());
    }

    public function test_the_plan_property_limit_is_shown_and_enforced(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));
        Subscription::withoutGlobalScopes()->where('landlord_id', $landlord->id)->update(['max_properties' => 1]);

        $message = __('You have reached the maximum limit of :max properties for your current subscription plan. Please upgrade your plan to add more properties.', ['max' => 1]);

        Livewire::test(AddProperty::class)
            ->assertSee($message)
            ->set('name', 'One too many')
            ->set('withRooms', false)
            ->call('save')
            ->assertHasErrors('subscription')
            ->assertNoRedirect();

        $this->assertDatabaseMissing('properties', ['name' => 'One too many']);
    }

    /** The rooms hit the plan's room limit: the property itself must not be left behind half-made. */
    public function test_the_plan_room_limit_rolls_everything_back(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));
        Subscription::withoutGlobalScopes()->where('landlord_id', $landlord->id)->update(['max_units' => 4]);

        Livewire::test(AddProperty::class)
            ->set('name', 'Too many rooms')
            ->set('floors', '1')
            ->set('perFloor', '5')
            ->set('roomPrice', '100')
            ->call('save')
            ->assertHasErrors('subscription');

        $this->assertDatabaseMissing('properties', ['name' => 'Too many rooms']);
        $this->assertSame(0, Unit::count());
    }

    public function test_users_without_the_create_permission_are_forbidden(): void
    {
        $landlord = $this->landlord();
        Role::findByName('landlord')->revokePermissionTo('create_property');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->signIn($landlord->fresh(), $this->property($landlord))
            ->get(route('mobile.add-property'))
            ->assertForbidden();
    }

    public function test_platform_staff_without_a_landlord_cannot_use_it(): void
    {
        $admin = User::factory()->create(['status' => UserStatus::Active->value]);
        $admin->assignRole('super_admin');
        $this->actingAs($admin);

        Livewire::test(AddProperty::class)->assertForbidden();
    }

    public function test_a_read_only_subscription_cannot_add(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));
        $component = Livewire::test(AddProperty::class);

        Subscription::withoutGlobalScopes()->where('landlord_id', $landlord->id)
            ->update(['ends_at' => now()->subDays(3), 'grace_ends_at' => null]);

        // Read-only also drops the create permission (LandlordOwnedPolicy::allowsWrites).
        $component->set('name', 'Nope')->call('save')->assertForbidden();

        $this->assertDatabaseMissing('properties', ['name' => 'Nope']);
    }

    public function test_changing_the_province_clears_the_lower_address_levels(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(AddProperty::class)
            ->set('district', 'X')
            ->set('commune', 'Y')
            ->set('city', 'Kandal')
            ->assertSet('district', '')
            ->assertSet('commune', '');
    }
}
