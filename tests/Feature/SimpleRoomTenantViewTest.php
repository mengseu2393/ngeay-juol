<?php

namespace Tests\Feature;

use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\RentalStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UnitStatus;
use App\Enums\UserStatus;
use App\Livewire\SimpleRoomList;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use App\Models\User;
use App\Support\ActiveProperty;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Simple Mode Rooms screen's "View tenant" popup shows occupant detail and
 * lets the landlord (re)generate the tenant's portal login. Passwords are
 * hashed at rest (see RoomAccountService) so there is no "view the existing
 * password" — only "reset it and show the new one once", exactly like
 * RentalResource\Actions\TenantLogin on the desktop panel.
 */
class SimpleRoomTenantViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        ActiveProperty::clear();
    }

    public function test_view_tenant_shows_occupant_detail(): void
    {
        [$landlord, $property, $unit, $rental] = $this->rentalSetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleRoomList::class)
            ->call('viewTenant', $rental->id)
            ->assertSet('viewingRentalId', $rental->id)
            ->assertSee('Sok Dara')
            ->assertSee('012 345 678')
            ->assertSee('123456789');
    }

    public function test_view_tenant_is_scoped_to_active_property(): void
    {
        [$landlord, $property, $unit, $rental] = $this->rentalSetup();

        $otherProperty = Property::create(['landlord_id' => $landlord->id, 'name' => 'Other property']);
        $otherUnit = Unit::create([
            'property_id' => $otherProperty->id,
            'landlord_id' => $landlord->id,
            'room_number' => '999',
            'room_type' => 'Standard',
            'rent_amount' => 300,
        ]);
        $otherTenant = $this->makeTenant();
        $otherRental = Rental::create([
            'landlord_id' => $landlord->id,
            'tenant_id' => $otherTenant->id,
            'unit_id' => $otherUnit->id,
            'occupant_name' => 'Foreign Tenant',
            'monthly_rent' => 300,
            'security_deposit' => 0,
            'status' => RentalStatus::Active,
            'start_date' => now()->toDateString(),
        ]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleRoomList::class)
            ->call('viewTenant', $otherRental->id)
            ->assertSet('viewingRentalId', null);
    }

    public function test_reset_tenant_login_creates_a_dedicated_account_when_still_on_the_shared_room_account(): void
    {
        // A tenancy still pointing at its unit's shared room account (the
        // RentalResource\Actions\TenantLogin::hasOwnLogin() === false case)
        // must get a fresh, dedicated account rather than resetting the
        // account shared by every room's occupant.
        [$landlord, $property, $unit, $rental] = $this->rentalSetup(withOwnLogin: false);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        $sharedAccountId = $unit->account_user_id;

        $component = Livewire::actingAs($landlord)
            ->test(SimpleRoomList::class)
            ->call('viewTenant', $rental->id)
            ->call('resetTenantLogin', $rental->id);

        $component->assertSet('newUsername', fn ($username) => filled($username));
        $component->assertSet('newPassword', fn ($password) => filled($password) && strlen($password) >= 8);

        $rental->refresh();
        $this->assertNotNull($rental->tenant_id);
        $this->assertNotSame($sharedAccountId, $rental->tenant_id);
        $this->assertNotNull($rental->tenant->username);
    }

    public function test_reset_tenant_login_on_existing_account_changes_its_password(): void
    {
        [$landlord, $property, $unit, $rental] = $this->rentalSetup(withOwnLogin: true);

        $oldHash = $rental->tenant->password;

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleRoomList::class)
            ->call('viewTenant', $rental->id)
            ->call('resetTenantLogin', $rental->id)
            ->assertSet('newPassword', fn ($password) => filled($password));

        $rental->refresh();
        $this->assertNotSame($oldHash, $rental->tenant->password);
    }

    public function test_reset_tenant_login_is_not_reachable_for_a_landlord_who_does_not_own_the_rental(): void
    {
        [$landlord, $property, $unit, $rental] = $this->rentalSetup();
        $oldHash = $rental->tenant->password;

        // Rental::class uses BelongsToLandlord, so another landlord's query
        // never sees the row at all — a 404, not a 403.
        $otherLandlord = $this->makeLandlord();

        Livewire::actingAs($otherLandlord)
            ->test(SimpleRoomList::class)
            ->call('resetTenantLogin', $rental->id)
            ->assertStatus(404);

        $this->assertSame($oldHash, $rental->fresh()->tenant->password);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  bool  $withOwnLogin  true: tenant already has a dedicated login
     *                              account. false: the rental still points at
     *                              its unit's shared room account (no
     *                              dedicated login yet) — RoomAccountService's
     *                              "mint a fresh one" branch.
     * @return array{User, Property, Unit, Rental}
     */
    private function rentalSetup(bool $withOwnLogin = true): array
    {
        $landlord = $this->makeLandlord();

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Test Property',
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'rent_amount' => 500,
            'status' => UnitStatus::Occupied,
        ]);

        if ($withOwnLogin) {
            $tenantId = $this->makeTenant('sok-dara-101')->id;
        } else {
            $sharedAccount = $this->makeTenant('room-101-shared');
            $unit->account_user_id = $sharedAccount->id;
            $unit->saveQuietly();
            $tenantId = $sharedAccount->id;
        }

        $rental = Rental::create([
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenantId,
            'unit_id' => $unit->id,
            'occupant_name' => 'Sok Dara',
            'occupant_phone' => '012 345 678',
            'occupant_id_card' => '123456789',
            'monthly_rent' => 500,
            'security_deposit' => 0,
            'status' => RentalStatus::Active,
            'start_date' => now()->startOfMonth()->toDateString(),
        ]);

        return [$landlord, $property, $unit->fresh(), $rental];
    }

    private function makeLandlord(): User
    {
        $landlord = User::factory()->create([
            'name' => 'Test Landlord '.uniqid(),
            'email' => 'landlord-tenant-view-'.uniqid().'@example.com',
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

    private function makeTenant(?string $username = null): User
    {
        $tenant = User::factory()->create([
            'name' => 'Test Tenant '.uniqid(),
            'email' => 'tenant-'.uniqid().'@example.com',
            'username' => $username,
        ]);
        $tenant->forceFill(['status' => UserStatus::Active])->save();
        $tenant->assignRole('tenant');

        return $tenant;
    }
}
