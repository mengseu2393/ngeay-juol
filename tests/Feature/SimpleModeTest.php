<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\ActiveProperty;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SimpleModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        ActiveProperty::clear();
    }

    // ── Access control ─────────────────────────────────────────────────────────

    public function test_landlord_can_access_simple_mode_page(): void
    {
        $landlord = $this->makeLandlord();

        $this->actingAs($landlord)
            ->get('/app/simple')
            ->assertSuccessful();
    }

    public function test_non_landlord_tenant_cannot_access_simple_mode_page(): void
    {
        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        // Tenant has no subscription so subscription guard fires first (redirect).
        // Either way they must NOT get a 200 on the landlord panel.
        $response = $this->actingAs($tenant)->get('/app/simple');
        $this->assertTrue(
            in_array($response->getStatusCode(), [302, 403], true),
            "Expected redirect or forbidden, got {$response->getStatusCode()}"
        );
    }

    public function test_unauthenticated_user_is_redirected_from_simple_mode(): void
    {
        $this->get('/app/simple')->assertRedirect();
    }

    public function test_landlord_dashboard_redirects_to_simple_mode_when_preference_is_enabled(): void
    {
        $landlord = $this->makeLandlord();
        $landlord->forceFill(['prefers_simple_landlord_mode' => true])->save();

        $this->actingAs($landlord)
            ->get('/app')
            ->assertRedirect(route('filament.landlord.pages.simple'));

        $this->actingAs($landlord)
            ->get('/app/simple')
            ->assertSuccessful();
    }

    public function test_regular_landlord_pages_redirect_to_simple_mode_when_preference_is_enabled(): void
    {
        $landlord = $this->makeLandlord();
        $landlord->forceFill(['prefers_simple_landlord_mode' => true])->save();

        $this->actingAs($landlord)
            ->get('/app/properties')
            ->assertRedirect(route('filament.landlord.pages.simple'));
    }

    public function test_simple_mode_menu_action_enables_preference(): void
    {
        $landlord = $this->makeLandlord();

        $this->actingAs($landlord)
            ->post(route('landlord.simple-mode.toggle'))
            ->assertRedirect(route('filament.landlord.pages.simple'));

        $this->assertTrue($landlord->fresh()->prefersSimpleLandlordMode());
    }

    public function test_simple_mode_menu_action_disables_preference(): void
    {
        $landlord = $this->makeLandlord();
        $landlord->forceFill(['prefers_simple_landlord_mode' => true])->save();

        $this->actingAs($landlord)
            ->post(route('landlord.simple-mode.toggle'))
            ->assertRedirect(route('filament.landlord.pages.dashboard'));

        $this->assertFalse($landlord->fresh()->prefersSimpleLandlordMode());
    }

    public function test_tenant_cannot_toggle_simple_mode_preference(): void
    {
        $tenant = $this->makeTenant();

        $this->actingAs($tenant)
            ->post(route('landlord.simple-mode.toggle'))
            ->assertForbidden();
    }

    public function test_guest_cannot_toggle_simple_mode_preference(): void
    {
        $this->post(route('landlord.simple-mode.toggle'))
            ->assertRedirect();
    }

    // ── Simple invoice list ────────────────────────────────────────────────────

    public function test_simple_invoice_list_scopes_to_active_property(): void
    {
        [$landlord, $property, $unit, $rental, $invoice] = $this->landlordSetup();

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
            'monthly_rent' => 300,
            'security_deposit' => 0,
            'status' => RentalStatus::Active,
            'start_date' => now()->toDateString(),
        ]);
        $otherInvoice = Invoice::create([
            'rental_id' => $otherRental->id,
            'property_id' => $otherProperty->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $otherTenant->id,
            'invoice_number' => 'INV-OTHER',
            'amount_due' => 300.0,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'issue_date' => now(),
            'due_date' => now()->addDays(7),
            'payment_status' => InvoiceStatus::Pending,
        ]);

        // Set session active property to first property
        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(\App\Livewire\SimpleInvoiceList::class, ['filter' => 'all'])
            ->assertSee($invoice->invoice_number)
            ->assertDontSee($otherInvoice->invoice_number);
    }

    // ── Simple record payment ──────────────────────────────────────────────────

    public function test_simple_record_payment_updates_invoice_ledger(): void
    {
        [$landlord, $property, $unit, $rental, $invoice] = $this->landlordSetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        $component = Livewire::actingAs($landlord)
            ->test(\App\Livewire\SimpleInvoiceList::class)
            ->call('startPay', $invoice->id)
            ->set('payAmount', '250.00')
            ->set('payMethod', PaymentMethod::Cash->value)
            ->call('submitPay');

        $invoice->refresh();

        $this->assertEquals(250.0, (float) $invoice->amount_paid);
        $this->assertEquals(InvoiceStatus::Partial, $invoice->payment_status);
        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'amount' => 250.00,
            'method' => PaymentMethod::Cash->value,
        ]);
    }

    public function test_simple_record_payment_does_not_write_amount_paid_directly(): void
    {
        [$landlord, $property, $unit, $rental, $invoice] = $this->landlordSetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        // amount_paid before should be 0
        $this->assertEquals(0, (float) $invoice->fresh()->amount_paid);

        // The Livewire component must use recordPayment() which goes via the ledger.
        // We verify by checking a Payment row exists rather than checking the direct column.
        Livewire::actingAs($landlord)
            ->test(\App\Livewire\SimpleInvoiceList::class)
            ->call('startPay', $invoice->id)
            ->set('payAmount', '500.00')
            ->set('payMethod', PaymentMethod::Cash->value)
            ->call('submitPay');

        $this->assertDatabaseHas('payments', ['invoice_id' => $invoice->id]);
        $this->assertEquals(InvoiceStatus::Paid, $invoice->fresh()->payment_status);
    }

    // ── Simple add tenant ──────────────────────────────────────────────────────

    public function test_simple_add_tenant_creates_rental_for_vacant_room(): void
    {
        [$landlord, $property, $unit] = $this->landlordSetup(createRental: false);

        $this->assertEquals(UnitStatus::Available, $unit->status);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(\App\Livewire\SimpleAddTenant::class)
            ->call('pickRoom', $unit->id)
            ->set('occupantName', 'Sok Dara')
            ->set('occupantPhone', '012345678')
            ->set('startDate', now()->toDateString())
            ->set('monthlyRent', '500.00')
            ->call('submit')
            ->assertSet('step', 'done');

        $this->assertDatabaseHas('rentals', [
            'unit_id' => $unit->id,
            'occupant_name' => 'Sok Dara',
            'status' => RentalStatus::Active->value,
        ]);
    }

    public function test_simple_add_tenant_rejects_occupied_room(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(\App\Livewire\SimpleAddTenant::class)
            ->call('pickRoom', $unit->id)
            ->set('occupantName', 'Another Tenant')
            ->set('startDate', now()->toDateString())
            ->set('monthlyRent', '500.00')
            ->call('submit')
            ->assertHasErrors(['unitId']);
    }

    // ── Simple end tenancy ─────────────────────────────────────────────────────

    public function test_simple_end_tenancy_updates_rental_status_and_room_status(): void
    {
        [$landlord, $property, $unit, $rental] = $this->landlordSetup();

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        Livewire::actingAs($landlord)
            ->test(\App\Livewire\SimpleEndTenancy::class)
            ->call('pickRoom', $unit->id)
            ->set('endDate', now()->toDateString())
            ->set('status', (string) RentalStatus::Vacated->value)
            ->set('freeRoom', true)
            ->call('submit')
            ->assertSet('step', 'done');

        $rental->refresh();
        $unit->refresh();

        $this->assertEquals(RentalStatus::Vacated, $rental->status);
        $this->assertEquals(UnitStatus::Available, $unit->status);
    }

    // ── PWA ──────────────────────────────────────────────────────────────────

    public function test_pwa_manifest_is_accessible(): void
    {
        $this->assertFileExists(public_path('manifest.json'));
    }

    public function test_pwa_service_worker_is_accessible(): void
    {
        $this->assertFileExists(public_path('sw.js'));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * @return array{User, Property, Unit, ?Rental, ?Invoice}
     */
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
            return [$landlord, $property, $unit, null, null];
        }

        $tenant = $this->makeTenant();
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

        $invoice = Invoice::create([
            'rental_id' => $rental->id,
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'invoice_number' => 'INV-001',
            'amount_due' => 500.0,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'issue_date' => now(),
            'due_date' => now()->addDays(7),
            'payment_status' => InvoiceStatus::Pending,
        ]);

        return [$landlord, $property, $unit, $rental, $invoice];
    }

    private function makeLandlord(): User
    {
        $landlord = User::factory()->create([
            'name' => 'Test Landlord ' . uniqid(),
            'email' => 'landlord-simple-mode-' . uniqid() . '@example.com',
        ]);
        $landlord->forceFill(['status' => \App\Enums\UserStatus::Active])->save();
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
            'name' => 'Test Tenant ' . uniqid(),
            'email' => 'tenant-' . uniqid() . '@example.com',
        ]);
        $tenant->forceFill(['status' => \App\Enums\UserStatus::Active])->save();
        $tenant->assignRole('tenant');

        return $tenant;
    }
}
