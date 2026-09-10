<?php

namespace Tests\Feature;

use App\Enums\MaintenancePriority;
use App\Enums\MaintenanceStatus;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Filament\Resources\MaintenanceRequestResource\Pages\CreateMaintenanceRequest;
use App\Models\MaintenanceRequest;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Support\ActiveProperty;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cross-tenant security boundary for the maintenance-request writer.
 *
 * Sibling of {@see CrossTenantBillingGuardTest}, same shape of hole but a
 * MEASURED, smaller blast radius — recorded here because the difference is the
 * reason the two files assert different things.
 *
 * `CreateMaintenanceRequest::mutateFormDataBeforeCreate()` reads the submitted
 * `unit_id` and `property_id` through `withoutGlobalScopes()` lookups (it has
 * to: those parent rows are what supply `landlord_id`). The room Select is
 * built from `->options()`, so Filament emits no `exists` rule for it and the
 * id is forgeable.
 *
 * What a negative control (guard removed, attack re-run) actually produced:
 *  - forged `unit_id`  → a request was created pointing at the VICTIM's room,
 *    while `landlord_id`/`property_id` stayed the attacker's. So it does not
 *    land in the victim's queue the way a forged invoice lands in their books;
 *    it creates a dangling cross-tenant reference that would render the other
 *    landlord's room in the attacker's UI. Still a leak, still blocked.
 *  - forged `property_id` → NOT exploitable through the form at all; the value
 *    never survives into `$data`, and the row is written entirely under the
 *    attacker. That case is pinned below as a non-vulnerability, so a future
 *    change that starts trusting the field gets caught.
 *
 * These are security regressions, not feature tests: a failure here means
 * tenant isolation on this path has been reopened.
 */
class CrossTenantMaintenanceGuardTest extends TestCase
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

    public function test_landlord_cannot_file_a_request_against_another_landlords_room(): void
    {
        [$attacker, $attackerUnit] = $this->createLandlordWithRoom('Attacker');
        [, $victimUnit] = $this->createLandlordWithRoom('Victim');

        $this->actingAs($attacker);
        ActiveProperty::clear();

        Livewire::test(CreateMaintenanceRequest::class)
            ->fillForm($this->formData($attacker, $attackerUnit))
            // The forged id: a room belonging to the other landlord.
            ->set('data.unit_id', $victimUnit->id)
            ->set('data.property_id', null)
            ->call('create')
            ->assertForbidden();

        $this->assertDatabaseCount('maintenance_requests', 0);
    }

    /**
     * A forged `property_id` is not exploitable through this form — the value
     * does not survive into the writer, so the row is written wholly under the
     * attacker. Pinned as a non-vulnerability: the assertion is about where the
     * row lands, not about which mechanism rejected it, so it keeps holding if
     * the guard, the Select, or the dehydration behaviour changes.
     */
    public function test_a_forged_property_id_cannot_put_a_request_in_another_landlords_books(): void
    {
        [$attacker, $attackerUnit] = $this->createLandlordWithRoom('Attacker');
        [, $victimUnit] = $this->createLandlordWithRoom('Victim');

        $this->actingAs($attacker);
        ActiveProperty::clear();

        $victimLandlordId = (int) Property::withoutGlobalScopes()
            ->whereKey($victimUnit->property_id)
            ->value('landlord_id');

        Livewire::test(CreateMaintenanceRequest::class)
            ->fillForm($this->formData($attacker, $attackerUnit))
            ->set('data.property_id', $victimUnit->property_id)
            ->call('create');

        $this->assertDatabaseMissing('maintenance_requests', ['landlord_id' => $victimLandlordId]);
        $this->assertDatabaseMissing('maintenance_requests', ['property_id' => $victimUnit->property_id]);
        $this->assertDatabaseMissing('maintenance_requests', ['unit_id' => $victimUnit->id]);
    }

    /**
     * The happy path must still work — a guard that denies everything would
     * pass the two tests above while breaking the feature.
     */
    public function test_landlord_can_still_file_a_request_for_their_own_room(): void
    {
        [$landlord, $unit] = $this->createLandlordWithRoom('Owner');

        $this->actingAs($landlord);
        ActiveProperty::set($unit->property_id);

        Livewire::test(CreateMaintenanceRequest::class)
            ->fillForm($this->formData($landlord, $unit))
            ->call('create')
            ->assertHasNoFormErrors();

        $request = MaintenanceRequest::withoutGlobalScopes()->firstOrFail();

        $this->assertSame($landlord->id, (int) $request->landlord_id);
        $this->assertSame($unit->id, (int) $request->unit_id);
        $this->assertSame($unit->property_id, (int) $request->property_id);
    }

    /** @return array<string, mixed> */
    private function formData(User $landlord, Unit $unit): array
    {
        $rental = Rental::withoutGlobalScopes()->where('unit_id', $unit->id)->firstOrFail();

        return [
            'tenant_id' => $rental->tenant_id,
            'property_id' => $unit->property_id,
            'unit_id' => $unit->id,
            'title' => 'Leaking tap',
            'description' => 'The kitchen tap drips all night.',
            'priority' => MaintenancePriority::Medium->value,
            'status' => MaintenanceStatus::Open->value,
        ];
    }

    /** @return array{0: User, 1: Unit} */
    private function createLandlordWithRoom(string $label): array
    {
        $landlord = User::create([
            'name' => $label.' Landlord',
            'email' => strtolower($label).'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $landlord->assignRole('landlord');

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => $label.' Property',
        ]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'status' => UnitStatus::Occupied,
        ]);

        $tenant = User::create([
            'name' => $label.' Tenant',
            'email' => 'tenant-'.strtolower($label).'-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $tenant->assignRole('tenant');

        Rental::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'unit_id' => $unit->id,
            'occupant_name' => $label.' Occupant',
            'monthly_rent' => 300,
            'status' => RentalStatus::Active,
            'start_date' => '2026-09-01',
        ]);

        return [$landlord, $unit];
    }
}
