<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\RentalStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UnitStatus;
use App\Enums\UserStatus;
use App\Livewire\SimpleTenantList;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use App\Models\User;
use App\Support\ActiveProperty;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SimpleTenantList is Simple Mode's mobile tenant directory — every active
 * tenancy in the active property, with the same tenant-detail popup
 * SimpleRoomList uses (duplicated there, not shared — see that component's
 * class docblock).
 */
class SimpleTenantListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        ActiveProperty::clear();
    }

    public function test_row_shows_the_tenants_total_due(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();

        Invoice::create([
            'rental_id' => $rental->id,
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $rental->tenant_id,
            'invoice_number' => 'INV-DUE-001',
            'amount_due' => 500.0,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'issue_date' => now(),
            'due_date' => now()->addDays(7),
            'payment_status' => InvoiceStatus::Pending,
        ]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleTenantList::class)
            ->assertSee(__('Total due'))
            ->assertSee('$500.00');
    }

    public function test_row_shows_zero_due_when_fully_paid(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();

        Invoice::create([
            'rental_id' => $rental->id,
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $rental->tenant_id,
            'invoice_number' => 'INV-PAID-001',
            'amount_due' => 500.0,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'issue_date' => now(),
            'due_date' => now()->addDays(7),
            'payment_status' => InvoiceStatus::Paid,
        ]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(SimpleTenantList::class)
            ->assertSee('$0.00');
    }

    public function test_only_active_tenancies_in_the_active_property_are_shown(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();

        // A vacant room in the same property must not appear.
        Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '102',
            'room_type' => 'Standard',
            'rent_amount' => 400,
            'status' => UnitStatus::Available,
        ]);

        // An active tenancy in a DIFFERENT property must not appear.
        $otherProperty = Property::create(['landlord_id' => $landlord->id, 'name' => 'Other property']);
        $otherUnit = Unit::create([
            'property_id' => $otherProperty->id,
            'landlord_id' => $landlord->id,
            'room_number' => '999',
            'room_type' => 'Standard',
            'rent_amount' => 300,
            'status' => UnitStatus::Occupied,
        ]);
        $otherTenant = $this->makeTenant();
        Rental::create([
            'landlord_id' => $landlord->id,
            'tenant_id' => $otherTenant->id,
            'unit_id' => $otherUnit->id,
            'occupant_name' => 'Foreign Tenant',
            'monthly_rent' => 300,
            'security_deposit' => 0,
            'status' => RentalStatus::Active,
            'start_date' => now()->startOfMonth()->toDateString(),
        ]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::test(SimpleTenantList::class)
            ->assertSee($rental->occupant_name)
            ->assertSee($unit->room_number)
            ->assertDontSee('Foreign Tenant')
            ->assertDontSee('102');
    }

    public function test_search_filters_by_tenant_name(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();

        $otherUnit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '102',
            'room_type' => 'Standard',
            'rent_amount' => 400,
            'status' => UnitStatus::Occupied,
        ]);
        $otherTenant = $this->makeTenant();
        Rental::create([
            'landlord_id' => $landlord->id,
            'tenant_id' => $otherTenant->id,
            'unit_id' => $otherUnit->id,
            'occupant_name' => 'Sok Dara',
            'monthly_rent' => 400,
            'security_deposit' => 0,
            'status' => RentalStatus::Active,
            'start_date' => now()->startOfMonth()->toDateString(),
        ]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::test(SimpleTenantList::class)
            ->set('search', 'Sok Dara')
            ->assertSee('Sok Dara')
            ->assertDontSee($rental->occupant_name);
    }

    public function test_search_filters_by_room_number(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();

        $otherUnit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '102',
            'room_type' => 'Standard',
            'rent_amount' => 400,
            'status' => UnitStatus::Occupied,
        ]);
        $otherTenant = $this->makeTenant();
        Rental::create([
            'landlord_id' => $landlord->id,
            'tenant_id' => $otherTenant->id,
            'unit_id' => $otherUnit->id,
            'occupant_name' => 'Sok Dara',
            'monthly_rent' => 400,
            'security_deposit' => 0,
            'status' => RentalStatus::Active,
            'start_date' => now()->startOfMonth()->toDateString(),
        ]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::test(SimpleTenantList::class)
            ->set('search', '102')
            ->assertSee('Sok Dara')
            ->assertDontSee($rental->occupant_name);
    }

    public function test_tapping_a_row_opens_the_tenant_detail_modal(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();

        $rental->forceFill([
            'occupant_phone' => '012345678',
            'occupant_id_card' => 'ID-999',
        ])->save();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::test(SimpleTenantList::class)
            ->call('viewTenant', $rental->id)
            ->assertSet('viewingRentalId', $rental->id)
            ->assertSee('012345678')
            ->assertSee('ID-999');
    }

    public function test_tenant_detail_modal_shows_uploaded_id_card_photos(): void
    {
        Storage::fake('public');
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();

        $photo = UploadedFile::fake()->image('id-front.jpg');
        $rental->addMedia($photo->getRealPath())
            ->usingFileName($photo->getClientOriginalName())
            ->toMediaCollection('id_cards');

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::test(SimpleTenantList::class)
            ->call('viewTenant', $rental->id)
            ->assertSee(__('ID card photos'))
            ->assertSee($rental->getFirstMediaUrl('id_cards'), false);
    }

    public function test_empty_state_renders_when_there_are_no_active_tenants(): void
    {
        [$landlord, $property] = $this->landlordSetup(createRental: false);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::test(SimpleTenantList::class)
            ->assertSee(__('No tenants found.'));
    }

    public function test_open_add_tenant_shows_the_add_tenant_popup(): void
    {
        [$landlord, $property] = $this->landlordSetup(createRental: false);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::test(SimpleTenantList::class)
            ->call('openAddTenant')
            ->assertSet('showAddTenant', true)
            ->call('closeAddTenant')
            ->assertSet('showAddTenant', false);
    }

    public function test_edit_tenant_opens_popup_for_a_scoped_rental_only(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::test(SimpleTenantList::class)
            ->call('editTenant', $rental->id)
            ->assertSet('editingRentalId', $rental->id)
            ->call('closeEditTenant')
            ->assertSet('editingRentalId', null)
            ->call('editTenant', 999999)
            ->assertSet('editingRentalId', null);
    }

    public function test_end_tenancy_opens_popup_for_a_scoped_rental_only(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::test(SimpleTenantList::class)
            ->call('endTenancy', $rental->id)
            ->assertSet('endingRentalId', $rental->id)
            ->call('closeEndTenancy')
            ->assertSet('endingRentalId', null)
            ->call('endTenancy', 999999)
            ->assertSet('endingRentalId', null);
    }

    private function landlordSetup(bool $createRental = true): array
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
            'status' => UnitStatus::Available,
        ]);

        if (! $createRental) {
            return [$landlord, $property, $unit, null];
        }

        $tenant = $this->makeTenant();
        $unit->update(['status' => UnitStatus::Occupied]);
        $rental = Rental::create([
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'unit_id' => $unit->id,
            'occupant_name' => 'Existing Tenant',
            'monthly_rent' => 500,
            'security_deposit' => 0,
            'status' => RentalStatus::Active,
            'start_date' => now()->startOfMonth()->toDateString(),
        ]);

        return [$landlord, $property, $unit, $rental];
    }

    private function makeLandlord(): User
    {
        $landlord = User::factory()->create([
            'name' => 'Test Landlord '.uniqid(),
            'email' => 'landlord-tenant-list-'.uniqid().'@example.com',
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

    private function makeTenant(): User
    {
        $tenant = User::factory()->create([
            'name' => 'Test Tenant '.uniqid(),
            'email' => 'tenant-'.uniqid().'@example.com',
        ]);
        $tenant->forceFill(['status' => UserStatus::Active])->save();
        $tenant->assignRole('tenant');

        return $tenant;
    }
}
