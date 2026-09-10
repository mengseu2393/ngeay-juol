<?php

namespace Tests\Feature;

use App\Enums\MoveInReadinessStatus;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Enums\UserStatus;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Services\CompleteMoveInAction;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WHY: CompleteMoveInAction used to persist with saveQuietly(), which skips
 * Rental::booted()'s `saved` hook. That hook is the ONLY place that (a) creates the
 * first invoice when a property has create_invoice_on_move_in set, and (b) flips the
 * tenancy's User to Active. Both were silently dropped — and an Inactive user fails
 * User::canAccessPanel() and LoginController's status filter, so the tenant that had
 * just been moved in could not sign in.
 *
 * The bug was invisible for as long as CompleteMoveInAction had no call site anywhere
 * in the app. It became reachable the moment a "Complete move-in" action was wired to
 * the tenancy screen, so these two assertions pin the two hook side effects rather
 * than the persistence mechanism — a future refactor may change how it saves, but
 * must not lose the invoice or leave the tenant locked out.
 */
class CompleteMoveInWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow('2026-09-10 09:00:00');
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_completing_a_move_in_issues_the_first_invoice_and_activates_the_tenant(): void
    {
        ['landlord' => $landlord, 'rental' => $rental, 'tenant' => $tenant, 'unit' => $unit] = $this->fixture();

        $this->actingAs($landlord);

        $this->assertSame(0, Invoice::withoutGlobalScopes()->where('rental_id', $rental->id)->count());
        $this->assertSame(UserStatus::Inactive, $tenant->refresh()->status);

        app(CompleteMoveInAction::class)($rental, $landlord->id);

        $rental->refresh();
        $this->assertSame(RentalStatus::Active, $rental->status);
        $this->assertSame(MoveInReadinessStatus::Active, $rental->move_in_status);
        $this->assertNotNull($rental->moved_in_at);

        $this->assertSame(
            1,
            Invoice::withoutGlobalScopes()->where('rental_id', $rental->id)->count(),
            'create_invoice_on_move_in is set, so the saved hook must issue the first invoice.',
        );

        $this->assertSame(
            UserStatus::Active,
            $tenant->refresh()->status,
            'An Inactive tenant cannot pass canAccessPanel()/LoginController, so moving in must activate them.',
        );

        $this->assertSame(UnitStatus::Occupied, $unit->refresh()->status);
    }

    /**
     * @return array{landlord: User, property: Property, unit: Unit, rental: Rental, tenant: User}
     */
    private function fixture(): array
    {
        $landlord = User::create([
            'name' => 'Landlord User',
            'email' => 'landlord-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $landlord->assignRole('landlord');

        $tenant = User::create([
            'name' => 'Sok Dara',
            'username' => 'tenant-'.uniqid(),
            'password' => bcrypt('password'),
        ]);
        // `status` is not in User::$fillable (the DB default is Active), so it has to
        // be forced — the same reason CreateLandlord forceFills it.
        $tenant->forceFill(['status' => UserStatus::Inactive])->saveQuietly();
        $tenant->assignRole('tenant');

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Riverside Residences',
        ]);

        PropertySetting::create([
            'property_id' => $property->id,
            'currency' => 'USD',
            'invoice_prefix' => 'INV',
            'create_invoice_on_move_in' => true,
            'due_day_of_month' => 7,
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'status' => UnitStatus::Available,
            'rent_amount' => 300,
            'rent_currency' => 'USD',
        ]);

        // Not yet Active: this is a tenancy awaiting move-in, which is exactly the
        // shape CompleteMoveInAction exists to advance.
        $rental = Rental::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'unit_id' => $unit->id,
            'occupant_name' => 'Sok Dara',
            'monthly_rent' => 300,
            'monthly_rent_currency' => 'USD',
            'security_deposit' => 300,
            'security_deposit_currency' => 'USD',
            'status' => RentalStatus::Expired,
            'start_date' => '2026-09-10',
        ]);

        return compact('landlord', 'property', 'unit', 'rental', 'tenant');
    }
}
