<?php

namespace Tests\Feature;

use App\Enums\FirstMonthBillingMode;
use App\Enums\InvoiceStatus;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Filament\Resources\TenantResource;
use App\Filament\Resources\TenantResource\Pages\ListTenants;
use App\Filament\Resources\TenantResource\Pages\ViewTenant;
use App\Filament\Resources\TenantResource\RelationManagers\TenanciesRelationManager;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The landlord-panel tenant directory ({@see TenantResource}) — a cross-property
 * list of tenant *accounts*, as opposed to RentalResource's list of tenancies.
 *
 * The resource's model is User, which unlike every landlord-owned model carries
 * no `landlord_id` and therefore no LandlordScope: a user row is shared platform
 * infrastructure, and the same person may hold tenancies with two different
 * landlords. Visibility is consequently a hand-written rule, duplicated from
 * UserResource — accounts the actor created (or that their landlord created),
 * plus accounts renting one of that landlord's units. If that rule is ever
 * weakened here, the directory becomes a cross-landlord tenant dump: names,
 * phone numbers, ID cards and outstanding balances of another operator's
 * tenants. The isolation tests below are security regressions, not features.
 */
class TenantDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('landlord'));
        Carbon::setTestNow('2026-07-05 09:00:00');
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // --- Isolation -----------------------------------------------------------

    /**
     * The security-critical assertion: landlord A's directory lists A's tenants
     * and nobody else's. Landlord B's tenant is a perfectly ordinary `tenant`-role
     * user row sitting in the same `users` table with no landlord_id to scope on,
     * so only the created_by_id / rentalsAsTenant rule keeps it out.
     */
    public function test_a_landlord_sees_only_their_own_tenants(): void
    {
        [$landlordA, $propertyA] = $this->makeLandlord('A');
        [$tenantA] = $this->rentRoom($landlordA, $propertyA, '101');

        [$landlordB, $propertyB] = $this->makeLandlord('B');
        [$tenantB] = $this->rentRoom($landlordB, $propertyB, '201');

        $this->actingAs($landlordA);

        Livewire::test(ListTenants::class)
            // Clear the property filter first: with it applied, landlord B's tenant
            // would drop out for the wrong reason and the scope could rot unnoticed.
            ->filterTable('property', null)
            ->assertCanSeeTableRecords([$tenantA])
            ->assertCanNotSeeTableRecords([$tenantB, $landlordA, $landlordB]);
    }

    /**
     * Hiding the row is not enough — the view page resolves its record through the
     * same query, so a guessed id must 404 rather than render another landlord's
     * tenant with their contact details and balance.
     */
    public function test_a_landlord_cannot_open_another_landlords_tenant(): void
    {
        [$landlordA] = $this->makeLandlord('A');
        [$landlordB, $propertyB] = $this->makeLandlord('B');
        [$tenantB] = $this->rentRoom($landlordB, $propertyB, '201');

        $this->actingAs($landlordA);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(ViewTenant::class, ['record' => $tenantB->getKey()]);
    }

    /**
     * The directory is tenants only. Staff accounts (the landlord themself, a
     * landlord_manager) share the users table and would otherwise turn up in a
     * list a landlord reads as "my tenants".
     */
    public function test_only_tenant_role_accounts_are_listed(): void
    {
        [$landlord, $property] = $this->makeLandlord('A');
        [$tenant] = $this->rentRoom($landlord, $property, '101');

        $manager = User::create([
            'name' => 'Manager',
            'email' => 'manager-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $manager->assignRole('landlord_manager');
        $manager->forceFill(['created_by_id' => $landlord->id, 'manages_landlord_id' => $landlord->id])->save();

        $this->actingAs($landlord);

        Livewire::test(ListTenants::class)
            ->filterTable('property', null)
            ->assertCanSeeTableRecords([$tenant])
            ->assertCanNotSeeTableRecords([$manager]);
    }

    // --- Search & filters ----------------------------------------------------

    public function test_search_finds_a_tenant_by_phone_number(): void
    {
        [$landlord, $property] = $this->makeLandlord('A');
        [$sok] = $this->rentRoom($landlord, $property, '101', ['name' => 'Sok', 'phone_number' => '012345678']);
        [$dara] = $this->rentRoom($landlord, $property, '102', ['name' => 'Dara', 'phone_number' => '098765432']);

        $this->actingAs($landlord);

        Livewire::test(ListTenants::class)
            ->searchTable('012345678')
            ->assertCanSeeTableRecords([$sok])
            ->assertCanNotSeeTableRecords([$dara]);

        Livewire::test(ListTenants::class)
            ->searchTable('Dara')
            ->assertCanSeeTableRecords([$dara])
            ->assertCanNotSeeTableRecords([$sok]);
    }

    /**
     * "Has portal login" means the account carries a credential it can sign in
     * with — a username or an email. Landlords keep tenant records for occupants
     * who never got a login, and those must be the ones the filter separates out.
     */
    public function test_portal_login_filter_splits_accounts_with_and_without_credentials(): void
    {
        [$landlord, $property] = $this->makeLandlord('A');
        [$withLogin] = $this->rentRoom($landlord, $property, '101', [
            'name' => 'Has login',
            'username' => 'room101',
            'email' => null,
        ]);
        [$withoutLogin] = $this->rentRoom($landlord, $property, '102', [
            'name' => 'No login',
            'username' => null,
            'email' => null,
        ]);

        $this->actingAs($landlord);

        $this->assertTrue(TenantResource::hasPortalLogin($withLogin));
        $this->assertFalse(TenantResource::hasPortalLogin($withoutLogin));

        Livewire::test(ListTenants::class)
            ->filterTable('has_portal_login', true)
            ->assertCanSeeTableRecords([$withLogin])
            ->assertCanNotSeeTableRecords([$withoutLogin]);

        Livewire::test(ListTenants::class)
            ->filterTable('has_portal_login', false)
            ->assertCanSeeTableRecords([$withoutLogin])
            ->assertCanNotSeeTableRecords([$withLogin]);
    }

    /**
     * The property filter defaults to the sidebar's active property but is a
     * filter, not a query scope: clearing it brings back a tenant who has moved
     * out of that property, which is the whole point of a directory.
     */
    public function test_property_filter_narrows_the_directory_and_can_be_cleared(): void
    {
        [$landlord, $propertyA] = $this->makeLandlord('A');
        $propertyB = Property::create(['landlord_id' => $landlord->id, 'name' => 'Property A2']);
        PropertySetting::create(['property_id' => $propertyB->id, 'currency' => 'USD', 'invoice_prefix' => 'INVA2']);

        [$tenantA] = $this->rentRoom($landlord, $propertyA, '101');
        [$tenantB] = $this->rentRoom($landlord, $propertyB, '201');

        $this->actingAs($landlord);
        ActiveProperty::set($propertyA->id);

        Livewire::test(ListTenants::class)
            ->assertCanSeeTableRecords([$tenantA])
            ->assertCanNotSeeTableRecords([$tenantB])
            ->filterTable('property', null)
            ->assertCanSeeTableRecords([$tenantA, $tenantB]);
    }

    // --- View page -----------------------------------------------------------

    /**
     * A tenant who moved from 101 to 205 is one person with two tenancies; the
     * view page has to show both, newest first, or "has he rented here before?"
     * is unanswerable from the directory.
     */
    public function test_view_page_shows_the_tenancy_history_across_rooms(): void
    {
        [$landlord, $property] = $this->makeLandlord('A');
        [$tenant, $current] = $this->rentRoom($landlord, $property, '205');

        $oldUnit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'status' => UnitStatus::Available,
        ]);

        $previous = Rental::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'unit_id' => $oldUnit->id,
            'occupant_name' => $tenant->name,
            'monthly_rent' => 400,
            'status' => RentalStatus::Vacated,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $this->actingAs($landlord);

        Livewire::test(ViewTenant::class, ['record' => $tenant->getKey()])
            ->assertSuccessful()
            ->assertSee('205');

        Livewire::test(TenanciesRelationManager::class, [
            'ownerRecord' => $tenant->fresh(),
            'pageClass' => ViewTenant::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$current, $previous]);

        // The active tenancy is the "current" one even though it is not the oldest.
        $this->assertTrue($current->is(TenantResource::currentTenancy($tenant->fresh())));
    }

    /**
     * The directory's headline number: everything still open across every room
     * this tenant has rented, settled invoices excluded.
     */
    public function test_outstanding_balance_totals_only_open_invoices(): void
    {
        [$landlord, $property] = $this->makeLandlord('A');
        [$tenant, $rental] = $this->rentRoom($landlord, $property, '101');

        $this->makeInvoice($rental, 500, InvoiceStatus::Pending);
        $this->makeInvoice($rental, 300, InvoiceStatus::Paid);

        $this->actingAs($landlord);

        $balance = TenantResource::openBalance($tenant->fresh());

        $this->assertSame(1, $balance['count']);
        $this->assertSame(500.0, $balance['usd']);
        $this->assertTrue(TenantResource::owesMoney($tenant->fresh()));
    }

    // --- Fixtures ------------------------------------------------------------

    /** @return array{0: User, 1: Property} */
    private function makeLandlord(string $suffix): array
    {
        $landlord = User::create([
            'name' => 'Landlord '.$suffix,
            'email' => 'landlord-'.$suffix.'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $landlord->assignRole('landlord');

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Property '.$suffix,
        ]);

        PropertySetting::create([
            'property_id' => $property->id,
            'currency' => 'USD',
            'invoice_prefix' => 'INV'.$suffix,
            'monthly_billing_enabled' => true,
            'invoice_due_days' => 7,
            'due_day_of_month' => 7,
            'first_month_billing_mode' => FirstMonthBillingMode::Prorated,
        ]);

        return [$landlord, $property];
    }

    /**
     * A tenant account renting one room. `$tenant` overrides the account columns
     * (pass `'username' => null, 'email' => null` for a login-less occupant).
     *
     * @param  array<string, mixed>  $tenant
     * @return array{0: User, 1: Rental}
     */
    private function rentRoom(User $landlord, Property $property, string $room, array $tenant = []): array
    {
        $tenantUser = User::create(array_merge([
            'name' => 'Tenant '.$room,
            'email' => 'tenant-'.$room.'-'.uniqid().'@example.com',
            'phone_number' => '01200'.$room,
            'password' => bcrypt('password'),
        ], $tenant));
        $tenantUser->assignRole('tenant');

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => $room,
            'room_type' => 'Standard',
            'status' => UnitStatus::Available,
        ]);

        $rental = Rental::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenantUser->id,
            'unit_id' => $unit->id,
            'occupant_name' => $tenantUser->name,
            'monthly_rent' => 500,
            'status' => RentalStatus::Active,
            'start_date' => '2026-01-01',
        ]);

        return [$tenantUser, $rental];
    }

    private function makeInvoice(Rental $rental, float $amount, InvoiceStatus $status): Invoice
    {
        return Invoice::create([
            'rental_id' => $rental->id,
            'property_id' => $rental->property_id,
            'landlord_id' => $rental->landlord_id,
            'tenant_id' => $rental->tenant_id,
            'invoice_number' => 'INV-'.uniqid(),
            'amount_due' => $amount,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'issue_date' => '2026-06-30',
            'due_date' => '2026-07-07',
            'payment_status' => $status,
        ]);
    }
}
