<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\UnitStatus;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\InvoicePdfService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The batch "print all" route: ?ids= is an allow-list that LandlordScope
 * re-filters, so a landlord can only ever batch their OWN invoices, and
 * tenants are locked out entirely. The PDF service is mocked — rendering
 * itself is covered by the single-invoice paths.
 */
class InvoiceBatchPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_landlord_gets_batch_pdf_for_own_invoices_only(): void
    {
        [$landlord, $own] = $this->makeLandlordWithInvoice('A');
        [, $foreign] = $this->makeLandlordWithInvoice('B');

        $this->mock(InvoicePdfService::class, function ($mock) use ($own) {
            $mock->shouldReceive('makeBatch')
                ->once()
                ->withArgs(fn ($invoices) => $invoices->pluck('id')->all() === [$own->id])
                ->andReturn('%PDF-fake');
        });

        $this->actingAs($landlord)
            ->get(route('invoices.batch-pdf', ['ids' => $own->id . ',' . $foreign->id, 'mode' => 'stream']))
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_batch_pdf_404s_when_no_own_invoices_match(): void
    {
        [$landlord] = $this->makeLandlordWithInvoice('A');
        [, $foreign] = $this->makeLandlordWithInvoice('B');

        $this->actingAs($landlord)
            ->get(route('invoices.batch-pdf', ['ids' => (string) $foreign->id]))
            ->assertNotFound();
    }

    public function test_tenant_cannot_use_batch_pdf(): void
    {
        [, $invoice] = $this->makeLandlordWithInvoice('A');

        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $this->actingAs($tenant)
            ->get(route('invoices.batch-pdf', ['ids' => (string) $invoice->id]))
            ->assertForbidden();
    }

    public function test_batch_pdf_rejects_garbage_ids(): void
    {
        [$landlord] = $this->makeLandlordWithInvoice('A');

        $this->actingAs($landlord)
            ->get(route('invoices.batch-pdf', ['ids' => 'abc,,;drop']))
            ->assertNotFound();
    }

    /** @return array{0: User, 1: Invoice} */
    private function makeLandlordWithInvoice(string $tag): array
    {
        $landlord = User::factory()->create(['email' => "batch-landlord-{$tag}-" . uniqid() . '@example.com']);
        $landlord->assignRole('landlord');

        $property = Property::create(['landlord_id' => $landlord->id, 'name' => "Property {$tag}"]);

        $unit = Unit::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'room_number' => "{$tag}-101",
            'room_type' => 'Standard',
            'rent_amount' => 500,
            'status' => UnitStatus::Available,
        ]);

        $tenant = User::factory()->create(['email' => "batch-tenant-{$tag}-" . uniqid() . '@example.com']);
        $tenant->assignRole('tenant');

        $rental = \App\Models\Rental::create([
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'unit_id' => $unit->id,
            'monthly_rent' => 500,
            'security_deposit' => 0,
            'status' => \App\Enums\RentalStatus::Active,
            'start_date' => now()->startOfMonth()->toDateString(),
        ]);

        $invoice = Invoice::create([
            'rental_id' => $rental->id,
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'tenant_id' => $tenant->id,
            'invoice_number' => "INV-{$tag}-001",
            'amount_due' => 500.0,
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'issue_date' => now(),
            'due_date' => now()->addDays(7),
            'payment_status' => InvoiceStatus::Pending,
        ]);

        return [$landlord, $invoice];
    }
}
