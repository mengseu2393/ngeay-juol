<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Filament\Pages\AddProperty;
use App\Livewire\PropertySwitcher;
use App\Models\Property;
use App\Models\User;
use App\Support\ActiveProperty;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A multi-property landlord used to land on "All properties", which in Simple
 * Mode is a dead end ("Choose a property first"). A fresh session now
 * auto-selects the first property (by name, the top of the switcher). The
 * switcher no longer offers "All properties" at all.
 */
class ActivePropertyDefaultTest extends TestCase
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

    public function test_a_fresh_session_auto_selects_the_first_property_by_name(): void
    {
        $landlord = $this->makeLandlord();
        Property::create(['landlord_id' => $landlord->id, 'name' => 'Zeta Flats']);
        $alpha = Property::create(['landlord_id' => $landlord->id, 'name' => 'Alpha House']);

        $this->actingAs($landlord);
        $this->resetResolved();

        $this->assertSame($alpha->id, ActiveProperty::id());
    }

    public function test_switcher_has_no_all_properties_option_and_leaves_a_stale_all_view(): void
    {
        $landlord = $this->makeLandlord();
        $alpha = Property::create(['landlord_id' => $landlord->id, 'name' => 'Alpha House']);
        Property::create(['landlord_id' => $landlord->id, 'name' => 'Zeta Flats']);

        $this->actingAs($landlord);
        ActiveProperty::clear(); // a session still on the old landlord-wide view

        Livewire::test(PropertySwitcher::class)
            ->assertSet('propertyId', (string) $alpha->id)
            ->assertDontSee(__('All properties'));

        $this->assertSame($alpha->id, ActiveProperty::id());
    }

    public function test_switcher_rejects_a_property_the_user_cannot_see(): void
    {
        $landlord = $this->makeLandlord();
        Property::create(['landlord_id' => $landlord->id, 'name' => 'Alpha House']);
        $foreign = Property::create(['landlord_id' => $this->makeLandlord()->id, 'name' => 'Foreign']);

        $this->actingAs($landlord);

        Livewire::test(PropertySwitcher::class)
            ->set('propertyId', (string) $foreign->id)
            ->assertNotFound();
    }

    public function test_choosing_add_property_in_the_dropdown_opens_the_add_property_screen(): void
    {
        $landlord = $this->makeLandlord();
        $alpha = Property::create(['landlord_id' => $landlord->id, 'name' => 'Alpha House']);
        $this->actingAs($landlord);

        Livewire::test(PropertySwitcher::class)
            ->set('propertyId', PropertySwitcher::ADD)
            ->assertRedirect(AddProperty::getUrl())
            ->assertSet('propertyId', (string) $alpha->id);
    }

    public function test_add_property_option_follows_the_create_permission(): void
    {
        $landlord = $this->makeLandlord();
        Property::create(['landlord_id' => $landlord->id, 'name' => 'Alpha House']);

        $this->actingAs($landlord);
        Livewire::test(PropertySwitcher::class)->assertSeeHtml('id="rw-add-property-option"');

        // The permission comes from the landlord role, so revoke it there.
        Role::findByName('landlord')->revokePermissionTo('create_property');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($landlord->fresh());
        Livewire::test(PropertySwitcher::class)->assertDontSeeHtml('id="rw-add-property-option"');
    }

    public function test_a_single_property_landlord_is_always_in_it(): void
    {
        $landlord = $this->makeLandlord();
        $only = Property::create(['landlord_id' => $landlord->id, 'name' => 'Only House']);

        $this->actingAs($landlord);
        ActiveProperty::clear();

        $this->assertSame($only->id, ActiveProperty::id());
    }

    public function test_platform_staff_are_not_auto_defaulted(): void
    {
        $landlord = $this->makeLandlord();
        Property::create(['landlord_id' => $landlord->id, 'name' => 'Alpha House']);

        $admin = User::factory()->create();
        $admin->forceFill(['status' => UserStatus::Active])->save();
        $admin->assignRole('super_admin');

        $this->actingAs($admin);
        $this->resetResolved();

        $this->assertNull(ActiveProperty::id());
    }

    /** Wipe the session key and the per-request memo, as a brand-new login would. */
    private function resetResolved(): void
    {
        Session::forget(ActiveProperty::SESSION_KEY);
        ActiveProperty::set(null);
        Session::forget(ActiveProperty::SESSION_KEY);
    }

    private function makeLandlord(): User
    {
        $landlord = User::factory()->create();
        $landlord->forceFill(['status' => UserStatus::Active])->save();
        $landlord->assignRole('landlord');

        return $landlord;
    }
}
