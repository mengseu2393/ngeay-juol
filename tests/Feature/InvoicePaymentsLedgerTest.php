<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Support\ActiveProperty;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The standalone /app/payments page was folded into the invoices list: each row
 * expands to its own payments ledger. These cover the merge — the ledger renders
 * inline, and the payments route is gone.
 */
class InvoicePaymentsLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('landlord'));
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();
        parent::tearDown();
    }

    public function test_invoice_row_renders_its_payments_inline(): void
    {
        [$landlord, $invoice] = $this->createInvoice();

        $invoice->recordPayment([
            'amount' => 300,
            'paid_at' => now(),
            'method' => PaymentMethod::Cash,
            'receipt_number' => 'RCP-11',
            'recorded_by_id' => $landlord->id,
        ]);

        $this->actingAs($landlord);

        Livewire::test(ListInvoices::class)
            ->assertCanSeeTableRecords([$invoice])
            ->assertSee('RCP-11')
            ->assertSee(__('Recorded by'));
    }

    public function test_invoice_without_payments_shows_the_empty_ledger(): void
    {
        [$landlord, $invoice] = $this->createInvoice();

        $this->actingAs($landlord);

        Livewire::test(ListInvoices::class)
            ->assertCanSeeTableRecords([$invoice])
            ->assertSee(__('No payments recorded yet.'));
    }

    /**
     * In the card layout the merged ledger uses, a record URL / record action wraps
     * the whole row in one link, which swallows the per-column actions — the status
     * badge navigated to Edit instead of opening the Payments modal. Both must stay
     * off (ListInvoices::makeTable).
     */
    public function test_row_is_not_wrapped_in_a_link_so_column_actions_still_fire(): void
    {
        [$landlord, $invoice] = $this->createInvoice();

        $this->actingAs($landlord);

        $table = Livewire::test(ListInvoices::class)
            ->assertTableActionExists('managePaymentsFromStatus')
            ->instance()
            ->getTable();

        $this->assertNull($table->getRecordUrl($invoice));
        $this->assertNull($table->getRecordAction($invoice));
    }

    public function test_payments_page_no_longer_exists(): void
    {
        [$landlord] = $this->createInvoice();

        $this->actingAs($landlord)
            ->get('/app/payments')
            ->assertNotFound();
    }

    /** @return array{0: User, 1: Invoice} */
    protected function createInvoice(): array
    {
        $landlord = User::create([
            'name' => 'Landlord User',
            'email' => 'landlord@example.com',
            'password' => bcrypt('password'),
        ]);
        $landlord->assignRole('landlord');

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => 'Property Alpha',
        ]);
        ActiveProperty::set($property->id);

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => '101',
            'room_type' => 'Standard',
            'rent_amount' => 500,
        ]);

        $tenant = User::create([
            'name' => 'Sok Dara',
            'email' => 'tenant@example.com',
            'password' => bcrypt('password'),
        ]);
        $tenant->assignRole('tenant');

        $rental = Rental::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'unit_id' => $unit->id,
            'tenant_id' => $tenant->id,
            'start_date' => now()->startOfMonth(),
            'monthly_rent' => 500,
        ]);

        $invoice = Invoice::create([
            'rental_id' => $rental->id,
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'invoice_number' => 'INV-001',
            'amount_due' => 500,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'issue_date' => now(),
            'due_date' => now()->endOfMonth(),
            'payment_status' => InvoiceStatus::Pending,
        ]);

        return [$landlord, $invoice];
    }
}
