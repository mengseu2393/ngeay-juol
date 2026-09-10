<?php

namespace Tests\Feature;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Filament\Resources\RentalResource\Actions\TenantLogin;
use App\Filament\Resources\RentalResource\Pages\CreateRental;
use App\Filament\Resources\RentalResource\Pages\ListRentals;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Services\RoomAccountService;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Portal access for a tenancy created the normal way.
 *
 * `rentals.tenant_id` IS the tenant's portal login, but the tenancy form only
 * captures free-text occupant details — so the only path to a working tenant
 * login used to be UnitResource's `generate_rooms` bulk action (a shared
 * per-ROOM account) or the tenant list buried under a unit. A landlord adding a
 * tenancy from the Tenants screen got a tenant who could not sign in, which made
 * the whole tenant portal unreachable for most tenants.
 *
 * Worse, `rentals.tenant_id` is NOT NULL and CreateRental passed no tenant at
 * all: creating a tenancy from that screen threw an integrity-constraint error
 * outright. test_creating_a_tenancy_from_the_tenants_screen_issues_a_login pins
 * that regression.
 */
class RentalTenantAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('landlord'));
        Carbon::setTestNow('2026-09-10 09:00:00');
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_create_login_gives_the_tenancy_its_own_tenant_user(): void
    {
        ['landlord' => $landlord, 'rental' => $rental, 'property' => $property, 'roomAccount' => $roomAccount] = $this->fixture();
        ActiveProperty::set($property->id);
        $this->actingAs($landlord);

        $this->assertSame($roomAccount->id, $rental->tenant_id, 'Starts out on the shared room account.');
        $this->assertFalse(TenantLogin::hasOwnLogin($rental));

        Livewire::test(ListRentals::class)
            ->callTableAction('tenant_login', $rental, ['password' => null])
            ->assertHasNoTableActionErrors();

        $rental->refresh();
        $this->assertNotSame($roomAccount->id, $rental->tenant_id);
        $this->assertNotNull($rental->tenant);
        $this->assertTrue($rental->tenant->hasRole('tenant'));
        $this->assertTrue(TenantLogin::hasOwnLogin($rental));
    }

    /**
     * The action is one button whose meaning flips: minting a second account for
     * a tenancy that already has one would orphan the first (and the invoices
     * already issued against it), so once a dedicated login exists the only
     * offered operation must be a password reset on that same user.
     */
    public function test_the_action_switches_to_reset_password_once_a_login_exists(): void
    {
        ['landlord' => $landlord, 'rental' => $rental] = $this->fixture();
        $this->actingAs($landlord);

        $service = app(RoomAccountService::class);

        $first = $service->createForRental($rental);
        $this->assertTrue($first['created']);
        $userId = $rental->refresh()->tenant_id;
        $this->assertTrue(TenantLogin::hasOwnLogin($rental));

        $second = $service->createForRental($rental->refresh(), 'brand-new-pass');
        $this->assertFalse($second['created'], 'A second run must reset, not create.');
        $this->assertSame($userId, $rental->refresh()->tenant_id);
        $this->assertSame($first['username'], $second['username']);
        $this->assertSame(1, User::where('username', $first['username'])->count());
    }

    public function test_the_tenant_can_authenticate_with_the_generated_credentials(): void
    {
        ['landlord' => $landlord, 'rental' => $rental] = $this->fixture();
        $this->actingAs($landlord);

        $result = app(RoomAccountService::class)->createForRental($rental);

        Auth::logout();
        $this->assertTrue(Auth::attempt([
            'username' => $result['username'],
            'password' => $result['password'],
        ]));
        $this->assertSame($rental->refresh()->tenant_id, Auth::id());

        Auth::logout();
        $this->assertFalse(Auth::attempt([
            'username' => $result['username'],
            'password' => 'not-the-password',
        ]));
    }

    public function test_resetting_the_password_invalidates_the_old_one(): void
    {
        ['landlord' => $landlord, 'rental' => $rental] = $this->fixture();
        $this->actingAs($landlord);

        $service = app(RoomAccountService::class);
        $first = $service->createForRental($rental);
        $second = $service->createForRental($rental->refresh());

        Auth::logout();
        $this->assertFalse(Auth::attempt(['username' => $first['username'], 'password' => $first['password']]));
        $this->assertTrue(Auth::attempt(['username' => $second['username'], 'password' => $second['password']]));
    }

    /**
     * Regression: `rentals.tenant_id` is NOT NULL and CreateRental used to call
     * Rental::create() with no tenant, so this page threw a NOT NULL constraint
     * violation and no tenancy could be created from the Tenants screen at all.
     */
    public function test_creating_a_tenancy_from_the_tenants_screen_issues_a_login(): void
    {
        ['landlord' => $landlord, 'property' => $property] = $this->fixture();

        $vacant = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '202',
            'room_type' => 'Standard',
            'status' => UnitStatus::Available,
        ]);

        ActiveProperty::set($property->id);
        $this->actingAs($landlord);

        Livewire::test(CreateRental::class)
            ->fillForm([
                'unit_id' => $vacant->id,
                'status' => RentalStatus::Active->value,
                'monthly_rent' => 250,
                'monthly_rent_currency' => 'USD',
                'security_deposit' => 250,
                'security_deposit_currency' => 'USD',
                'start_date' => '2026-09-10',
                'occupant_name' => 'Chan Sophea',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rental = Rental::where('unit_id', $vacant->id)->firstOrFail();
        $this->assertNotNull($rental->tenant_id);
        $this->assertTrue($rental->tenant->hasRole('tenant'));
        $this->assertTrue(TenantLogin::hasOwnLogin($rental));
        $this->assertSame('Chan Sophea', $rental->occupants()->where('role', 'primary')->value('occupant_name'));
    }

    /**
     * @return array{landlord: User, property: Property, unit: Unit, rental: Rental, roomAccount: User}
     */
    private function fixture(): array
    {
        $landlord = User::create([
            'name' => 'Landlord User',
            'email' => 'landlord-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $landlord->assignRole('landlord');

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Riverside Residences',
        ]);

        PropertySetting::create([
            'property_id' => $property->id,
            'currency' => 'USD',
            'invoice_prefix' => 'INV',
            'create_invoice_on_move_in' => false,
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'status' => UnitStatus::Available,
        ]);

        // The shared per-room account created at property setup — what a tenancy
        // added the old way ends up pointing at.
        $this->actingAs($landlord);
        $roomAccount = app(RoomAccountService::class)->createForUnit($unit)['user'];
        Auth::logout();

        $rental = Rental::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $roomAccount->id,
            'unit_id' => $unit->id,
            'occupant_name' => 'Sok Dara',
            'monthly_rent' => 300,
            'monthly_rent_currency' => 'USD',
            'security_deposit' => 300,
            'security_deposit_currency' => 'USD',
            'status' => RentalStatus::Active,
            'start_date' => '2026-06-01',
        ]);

        return compact('landlord', 'property', 'unit', 'rental', 'roomAccount');
    }
}
