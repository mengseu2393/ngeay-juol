<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Resources\InvoiceResource\RelationManagers\PaymentsRelationManager;
use App\Models\Invoice;
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
 * The "Record payment" row action — the one-click version of the most frequent
 * daily job.
 *
 * What these lock down is not the modal but the ledger contract: the action
 * writes ONLY through Invoice::recordPayment(), and amount_paid / payment_status
 * are recomputed by the Payment model's own saved hook. A regression that sets
 * either of them from the action would still make the invoice "look right" for
 * the first payment and then drift on the second, so the partial-payment case is
 * asserted explicitly rather than only the paid-in-full one.
 */
class RecordPaymentActionTest extends TestCase
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

    public function test_action_records_a_full_payment_through_the_ledger(): void
    {
        [$landlord, $invoice] = $this->createInvoice('A');

        $this->actingAs($landlord);

        Livewire::test(ListInvoices::class)
            ->callTableAction('recordPayment', $invoice, [
                'amount' => '500',
                'currency' => 'USD',
                'method' => PaymentMethod::Cash->value,
                'paid_at' => now()->toDateTimeString(),
                'receipt_number' => 'RCP-9',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $invoice->id,
            'amount' => 500.0,
            'currency' => 'USD',
            'receipt_number' => 'RCP-9',
            'recorded_by_id' => $landlord->id,
        ]);

        $invoice->refresh();

        $this->assertSame('500.00', (string) $invoice->amount_paid);
        $this->assertSame(0.0, (float) $invoice->balance);
        $this->assertSame(InvoiceStatus::Paid, $invoice->payment_status);
    }

    public function test_partial_payment_leaves_the_remaining_balance(): void
    {
        [$landlord, $invoice] = $this->createInvoice('B');

        $this->actingAs($landlord);

        Livewire::test(ListInvoices::class)
            ->callTableAction('recordPayment', $invoice, [
                'amount' => '200',
                'currency' => 'USD',
                'method' => PaymentMethod::Cash->value,
                'paid_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoTableActionErrors();

        $invoice->refresh();

        $this->assertSame('200.00', (string) $invoice->amount_paid);
        $this->assertSame(300.0, (float) $invoice->balance);
        $this->assertSame(InvoiceStatus::Partial, $invoice->payment_status);
        $this->assertSame(1, $invoice->payments()->count());
    }

    public function test_action_is_hidden_once_the_invoice_is_settled(): void
    {
        [$landlord, $invoice] = $this->createInvoice('C');

        $invoice->recordPayment([
            'amount' => 500,
            'currency' => 'USD',
            'method' => PaymentMethod::Cash,
            'recorded_by_id' => $landlord->id,
        ]);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->payment_status);

        $this->actingAs($landlord);

        Livewire::test(ListInvoices::class)
            ->assertTableActionHidden('recordPayment', $invoice);
    }

    public function test_action_is_hidden_on_a_cancelled_invoice(): void
    {
        [$landlord, $invoice] = $this->createInvoice('D');

        $invoice->payment_status = InvoiceStatus::Cancelled;
        $invoice->save();

        $this->actingAs($landlord);

        Livewire::test(ListInvoices::class)
            ->assertTableActionHidden('recordPayment', $invoice);
    }

    /**
     * The same action is registered twice — on the invoice row (record = the
     * invoice) and on the payments relation-manager header (record = null, the
     * invoice is the OWNER record). The resolver that bridges those two contexts
     * is the whole reason the action takes a closure, so the second surface is
     * exercised rather than assumed.
     */
    public function test_relation_manager_header_action_records_through_the_same_path(): void
    {
        [$landlord, $invoice] = $this->createInvoice('G');

        $this->actingAs($landlord);

        Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $invoice,
            'pageClass' => EditInvoice::class,
        ])
            ->callTableAction('recordPaymentFromRelation', null, [
                'amount' => '150',
                'currency' => 'USD',
                'method' => PaymentMethod::Cash->value,
                'paid_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoTableActionErrors();

        $invoice->refresh();

        $this->assertSame('150.00', (string) $invoice->amount_paid);
        $this->assertSame(InvoiceStatus::Partial, $invoice->payment_status);
    }

    /**
     * Payments carry no landlord_id of their own, so the only thing keeping a
     * landlord out of a neighbour's ledger is LandlordScope on the invoice the
     * action resolves. If that ever stopped applying, the action would happily
     * write a payment against a foreign invoice.
     */
    public function test_landlord_cannot_record_a_payment_against_another_landlords_invoice(): void
    {
        [, $foreignInvoice] = $this->createInvoice('E');
        [$landlord, $ownInvoice] = $this->createInvoice('F');

        // The second fixture's property is the active one; act as its landlord.
        $this->actingAs($landlord);

        Livewire::test(ListInvoices::class)
            ->assertCanSeeTableRecords([$ownInvoice])
            ->assertCanNotSeeTableRecords([$foreignInvoice])
            ->callTableAction('recordPayment', $foreignInvoice, [
                'amount' => '500',
                'currency' => 'USD',
                'method' => PaymentMethod::Cash->value,
                'paid_at' => now()->toDateTimeString(),
            ]);

        $this->assertDatabaseCount('payments', 0);

        $foreignInvoice = Invoice::withoutGlobalScopes()->find($foreignInvoice->id);
        $this->assertSame('0.00', (string) $foreignInvoice->amount_paid);
        $this->assertSame(InvoiceStatus::Pending, $foreignInvoice->payment_status);
    }

    /** @return array{0: User, 1: Invoice} */
    protected function createInvoice(string $tag): array
    {
        $landlord = User::create([
            'name' => "Landlord {$tag}",
            'email' => "landlord-{$tag}@example.com",
            'password' => bcrypt('password'),
        ]);
        $landlord->assignRole('landlord');

        $property = Property::create([
            'landlord_id' => $landlord->id,
            'name' => "Property {$tag}",
        ]);
        ActiveProperty::set($property->id);

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => "{$tag}-101",
            'room_type' => 'Standard',
            'rent_amount' => 500,
        ]);

        $tenant = User::create([
            'name' => "Tenant {$tag}",
            'email' => "tenant-{$tag}@example.com",
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
            'invoice_number' => "INV-{$tag}-001",
            'amount_due' => 500,
            'total_usd' => 500,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'issue_date' => now(),
            'due_date' => now()->endOfMonth(),
            'payment_status' => InvoiceStatus::Pending,
        ]);

        return [$landlord, $invoice];
    }
}
