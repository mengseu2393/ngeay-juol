<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\FirstMonthBillingMode;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Livewire\SimpleAddTenant;
use App\Livewire\SimpleBillingInvoice;
use App\Livewire\SimpleEndTenancy;
use App\Livewire\SimpleInvoiceList;
use App\Livewire\SimpleRoomList;
use App\Livewire\SimpleTenantList;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Simple Mode keeps every screen mounted on one page (Invoices, Rooms, Tenants,
 * Add tenant, End tenancy, ... toggled with x-show). A screen that changes
 * shared data must announce it, and the screens that show that data must
 * re-render — otherwise adding a tenant leaves the Rooms tab showing the room
 * as vacant, and new invoices are missing from the Invoices tab, but only if
 * that tab had already loaded (which is why it looked random on phones).
 *
 * Each test mounts the list first (the "already loaded tab"), changes the data
 * through the screen that owns the action, then delivers that screen's event.
 */
class SimpleModeScreenSyncTest extends TestCase
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

    public function test_adding_a_tenant_refreshes_rooms_tenants_and_end_tenancy(): void
    {
        [$landlord, , , $vacant] = $this->fixture();

        $rooms = Livewire::test(SimpleRoomList::class)->assertSeeHtml('id="room-card-'.$vacant->id.'"');
        $this->assertStringContainsString('data-status="available"', $this->card($rooms->html(), 'room-card-'.$vacant->id));
        $tenants = Livewire::test(SimpleTenantList::class)->assertDontSee('Sok Dara');
        $endTenancy = Livewire::test(SimpleEndTenancy::class)->assertDontSee('201');

        Livewire::test(SimpleAddTenant::class)
            ->call('pickRoom', $vacant->id)
            ->set('occupantName', 'Sok Dara')
            ->set('startDate', '2026-07-05')
            ->set('monthlyRent', '300')
            ->call('submit')
            ->assertSet('step', 'done')
            ->assertDispatched('tenancy-started');

        $rooms->dispatch('tenancy-started');
        $this->assertStringContainsString('data-status="occupied"', $this->card($rooms->html(), 'room-card-'.$vacant->id));
        $tenants->dispatch('tenancy-started')->assertSee('Sok Dara');
        $endTenancy->dispatch('tenancy-started')->assertSee('201');
    }

    public function test_ending_a_tenancy_refreshes_rooms_and_add_tenant(): void
    {
        [, , $rental] = $this->fixture();
        $unit = $rental->unit;

        $rooms = Livewire::test(SimpleRoomList::class);
        $this->assertStringContainsString('data-status="occupied"', $this->card($rooms->html(), 'room-card-'.$unit->id));
        $addTenant = Livewire::test(SimpleAddTenant::class)->assertDontSee($unit->room_number);

        Livewire::test(SimpleEndTenancy::class)
            ->call('pickRoom', $unit->id)
            ->set('endDate', '2026-07-05')
            ->set('status', (string) RentalStatus::Vacated->value)
            ->set('freeRoom', true)
            ->call('submit')
            ->assertDispatched('tenancy-ended');

        $rooms->dispatch('tenancy-ended');
        $this->assertStringContainsString('data-status="available"', $this->card($rooms->html(), 'room-card-'.$unit->id));
        $addTenant->dispatch('tenancy-ended')->assertSee($unit->room_number);
    }

    public function test_creating_invoices_refreshes_the_invoice_list_and_tenant_balances(): void
    {
        $this->fixture();

        $invoices = Livewire::test(SimpleInvoiceList::class)->assertDontSee('Occupant A');
        $dueTenants = Livewire::test(SimpleTenantList::class)->set('filter', 'due')->assertDontSee('Occupant A');

        Livewire::test(SimpleBillingInvoice::class)
            ->call('startBilling')
            ->set('rooms.0.utilities.0.new_reading', '150')
            ->call('createInvoices')
            ->assertDispatched('invoices-created');

        $this->assertSame(1, Invoice::count());
        $invoices->dispatch('invoices-created')->assertSee('Occupant A');
        $dueTenants->dispatch('invoices-created')->assertSee('Occupant A');
    }

    public function test_recording_a_full_payment_refreshes_tenant_balances(): void
    {
        $this->fixture();

        Livewire::test(SimpleBillingInvoice::class)
            ->call('startBilling')
            ->set('rooms.0.utilities.0.new_reading', '150')
            ->call('createInvoices');
        $invoice = Invoice::sole();

        $dueTenants = Livewire::test(SimpleTenantList::class)->set('filter', 'due')->assertSee('Occupant A');

        Livewire::test(SimpleInvoiceList::class)
            ->call('submitPayFor', $invoice->id, (string) $invoice->fresh()->balance, 1)
            ->assertDispatched('pay-saved');

        $dueTenants->dispatch('pay-saved')->assertDontSee('Occupant A');
    }

    /** The opening tag of one card — where its data-status lives. */
    private function card(string $html, string $id): string
    {
        $this->assertSame(1, preg_match('/<div[^>]*id="'.preg_quote($id, '/').'"[^>]*>/s', $html, $match), "card {$id} not rendered");

        return $match[0];
    }

    /** @return array{User, Property, Rental, Unit} landlord, property, an occupied room's rental, a vacant room */
    private function fixture(): array
    {
        $landlord = User::create([
            'name' => 'Sync Landlord',
            'email' => 'sync-landlord-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $landlord->assignRole('landlord');

        $property = Property::create(['landlord_id' => $landlord->id, 'name' => 'Sync Property']);

        PropertySetting::create([
            'property_id' => $property->id,
            'currency' => 'USD',
            'invoice_prefix' => 'SYN',
            'monthly_billing_enabled' => true,
            'invoice_due_days' => 7,
            'due_day_of_month' => 7,
            'first_month_billing_mode' => FirstMonthBillingMode::Prorated,
        ]);

        PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered,
            'rate' => 0.25,
            'unit_of_measure' => 'kWh',
            'is_active' => true,
        ]);

        $tenant = User::create([
            'name' => 'Tenant A',
            'email' => 'sync-tenant-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $tenant->assignRole('tenant');

        $occupied = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'status' => UnitStatus::Occupied,
        ]);

        $rental = Rental::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'unit_id' => $occupied->id,
            'occupant_name' => 'Occupant A',
            'monthly_rent' => 500,
            'status' => RentalStatus::Active,
            'start_date' => '2026-07-01',
            'next_invoice_date' => null,
        ]);

        $vacant = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '201',
            'room_type' => 'Standard',
            'rent_amount' => 300,
            'status' => UnitStatus::Available,
        ]);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        return [$landlord, $property, $rental, $vacant];
    }
}
