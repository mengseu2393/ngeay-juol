<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Services\PaymentReceiptPdfService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The payment-receipt route.
 *
 * Two things are load-bearing here. First, authorization: a Payment carries NO
 * landlord_id and no LandlordScope, so the route binding cannot isolate anything
 * — the controller has to reach through payment.invoice.landlord_id, and a
 * regression there would expose every landlord's receipts by incrementing an id.
 *
 * Second, the render is exercised for real (not mocked) on both paper shapes,
 * because the Blade view is the part that breaks: it must stay table-only with
 * the Khmer @font-face pair, since dompdf is a LIVE fallback renderer. Note that
 * a green test still cannot tell Chrome from dompdf — only the absence of
 * 'render failed' in the log can (see CLAUDE.md).
 */
class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_owning_landlord_gets_the_thermal_receipt(): void
    {
        [$landlord, , $payment] = $this->createPayment('A');

        $response = $this->actingAs($landlord)
            ->get(route('payments.receipt', ['payment' => $payment, 'size' => '58mm']))
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
    }

    public function test_receipt_also_renders_on_the_standard_page_size(): void
    {
        [$landlord, , $payment] = $this->createPayment('B');

        $response = $this->actingAs($landlord)
            ->get(route('payments.receipt', ['payment' => $payment, 'size' => 'a5', 'mode' => 'stream']))
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('inline;', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_another_landlord_cannot_fetch_the_receipt(): void
    {
        [, , $payment] = $this->createPayment('C');
        [$otherLandlord] = $this->createPayment('D');

        // The service must never even be reached for a foreign payment.
        $this->mock(PaymentReceiptPdfService::class, fn ($mock) => $mock->shouldNotReceive('make'));

        $this->actingAs($otherLandlord)
            ->get(route('payments.receipt', ['payment' => $payment]))
            ->assertForbidden();
    }

    public function test_unrelated_tenant_cannot_fetch_the_receipt(): void
    {
        [, , $payment] = $this->createPayment('E');

        $stranger = User::create([
            'name' => 'Stranger',
            'email' => 'stranger@example.com',
            'password' => bcrypt('password'),
        ]);
        $stranger->assignRole('tenant');

        $this->mock(PaymentReceiptPdfService::class, fn ($mock) => $mock->shouldNotReceive('make'));

        $this->actingAs($stranger)
            ->get(route('payments.receipt', ['payment' => $payment]))
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        [, , $payment] = $this->createPayment('F');

        $this->get(route('payments.receipt', ['payment' => $payment]))
            ->assertRedirect(route('login'));
    }

    /**
     * receipt_number is free text the landlord may leave blank, and a receipt may
     * be reprinted at any time — so the fallback number is derived from the
     * payment's own key (deterministic, unique) rather than counted, which would
     * renumber earlier receipts as soon as a payment is deleted.
     */
    public function test_blank_receipt_number_falls_back_to_a_deterministic_number(): void
    {
        [$landlord, $invoice, $payment] = $this->createPayment('G');

        $expected = 'RCP-'.$landlord->id.'-'.$payment->paid_at->format('Ym').'-'.str_pad((string) $payment->id, 4, '0', STR_PAD_LEFT);

        $this->assertSame($expected, PaymentReceiptPdfService::receiptNumber($payment));
        $this->assertSame($expected.'.pdf', PaymentReceiptPdfService::filename($payment, 'pdf'));

        $payment->update(['receipt_number' => 'HAND-WRITTEN-7']);

        $this->assertSame('HAND-WRITTEN-7', PaymentReceiptPdfService::receiptNumber($payment->fresh()->setRelation('invoice', $invoice)));
    }

    /** @return array{0: User, 1: Invoice, 2: Payment} */
    protected function createPayment(string $tag): array
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

        $payment = $invoice->recordPayment([
            'amount' => 200,
            'currency' => 'USD',
            'method' => PaymentMethod::Cash,
            'paid_at' => now(),
            'recorded_by_id' => $landlord->id,
        ]);

        return [$landlord, $invoice, $payment];
    }
}
