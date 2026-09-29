<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Livewire\PropertySwitcher;
use App\Models\Property;
use App\Models\User;
use App\Support\ActiveProperty;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A multi-property landlord used to land on "All properties", which in Simple
 * Mode is a dead end ("Choose a property first"). A fresh session now
 * auto-selects the first property (by name, the top of the switcher), but an
 * explicit "All properties" pick must still stick — otherwise the switcher's
 * All option would silently bounce back to a property.
 */
class ActivePropertyDefaultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
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

    public function test_an_explicit_all_properties_choice_is_respected(): void
    {
        $landlord = $this->makeLandlord();
        Property::create(['landlord_id' => $landlord->id, 'name' => 'Alpha House']);
        Property::create(['landlord_id' => $landlord->id, 'name' => 'Zeta Flats']);

        $this->actingAs($landlord);

        Livewire::test(PropertySwitcher::class)
            ->assertSet('propertyId', fn ($id) => $id !== '')
            ->set('propertyId', '');

        $this->assertNull(ActiveProperty::id());
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
