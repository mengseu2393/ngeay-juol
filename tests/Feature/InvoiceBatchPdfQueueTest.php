<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Http\Controllers\InvoiceDocumentController;
use App\Jobs\GenerateBatchInvoicePdfJob;
use App\Models\Export;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\User;
use App\Services\InvoicePdfService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Print all" is a headless-Chrome render held inside the web request: one
 * Node + Chrome process (~150-250MB) for the whole batch. Small batches — the
 * everyday "print the invoices I just made" — must keep streaming inline
 * exactly as before, but a month-end batch for a whole building has to move to
 * the queue or it pins a PHP-FPM worker for minutes and, with a few landlords
 * printing at once, takes the box down.
 *
 * These tests pin the threshold behaviour on both sides, and the delivery path
 * for the queued half (Export row + the existing owner-checked
 * `exports.download` route) — deliberately NOT a second download mechanism.
 * The PDF service is mocked throughout; rendering is covered elsewhere.
 */
class InvoiceBatchPdfQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_small_batch_still_renders_inline(): void
    {
        Queue::fake();

        [$landlord, $invoices] = $this->makeLandlordWithInvoices('A', InvoiceDocumentController::INLINE_BATCH_LIMIT);

        $this->mock(InvoicePdfService::class, function ($mock) {
            $mock->shouldReceive('makeBatch')->once()->andReturn('%PDF-fake');
        });

        $this->actingAs($landlord)
            ->get(route('invoices.batch-pdf', ['ids' => $invoices->pluck('id')->implode(','), 'mode' => 'stream']))
            ->assertSuccessful()
            ->assertHeader('Content-Type', 'application/pdf');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('exports', 0);
    }

    public function test_large_batch_is_queued_instead_of_rendered_inline(): void
    {
        Queue::fake();

        [$landlord, $invoices] = $this->makeLandlordWithInvoices('B', InvoiceDocumentController::INLINE_BATCH_LIMIT + 1);

        // Rendering inline is the exact failure mode being prevented.
        $this->mock(InvoicePdfService::class, function ($mock) {
            $mock->shouldNotReceive('makeBatch');
        });

        $this->actingAs($landlord)
            ->get(route('invoices.batch-pdf', ['ids' => $invoices->pluck('id')->implode(',')]))
            ->assertRedirect();

        $export = Export::where('user_id', $landlord->id)->sole();
        $this->assertSame('pending', $export->status);
        $this->assertNull($export->file_path);

        Queue::assertPushed(
            GenerateBatchInvoicePdfJob::class,
            fn (GenerateBatchInvoicePdfJob $job) => $job->export->is($export)
                && $job->invoiceIds === $invoices->pluck('id')->all(),
        );
    }

    /** An XHR caller gets a machine-readable 202 rather than a redirect to HTML. */
    public function test_large_batch_returns_202_for_json_callers(): void
    {
        Queue::fake();

        [$landlord, $invoices] = $this->makeLandlordWithInvoices('C', InvoiceDocumentController::INLINE_BATCH_LIMIT + 1);

        $this->actingAs($landlord)
            ->getJson(route('invoices.batch-pdf', ['ids' => $invoices->pluck('id')->implode(',')]))
            ->assertStatus(202)
            ->assertJson(['queued' => true]);

        Queue::assertPushed(GenerateBatchInvoicePdfJob::class);
    }

    public function test_queued_job_produces_an_export_the_owner_can_download(): void
    {
        [$landlord, $invoices] = $this->makeLandlordWithInvoices('D', 2);

        $this->mock(InvoicePdfService::class, function ($mock) {
            $mock->shouldReceive('makeBatch')->once()->andReturn('%PDF-fake');
        });

        $export = Export::create([
            'user_id' => $landlord->id,
            'file_name' => 'invoices-batch.pdf',
            'status' => 'pending',
        ]);

        $notificationsBefore = DB::table('notifications')->count();

        (new GenerateBatchInvoicePdfJob($export, $invoices->pluck('id')->all()))->handle();

        $export->refresh();
        $this->assertSame('completed', $export->status);
        $this->assertNotNull($export->file_path);
        $this->assertFileExists(storage_path('app/'.$export->file_path));

        // The user is told the file exists — a queued render has no request left
        // to flash into, so the database notification is the only channel.
        $this->assertSame($notificationsBefore + 1, DB::table('notifications')->count());
        // Compare against __(), not the English literal: the app locale is `km`,
        // so this assertion would break the moment the key is translated.
        $this->assertStringContainsString(
            json_encode(__('Invoices ready to download'), JSON_UNESCAPED_SLASHES),
            (string) DB::table('notifications')->where('notifiable_id', $landlord->id)->latest('created_at')->value('data'),
        );

        $this->actingAs($landlord)
            ->get(route('exports.download', ['file_id' => $export->getKey()]))
            ->assertSuccessful()
            ->assertHeader('Content-Disposition', 'attachment; filename=invoices-batch.pdf');

        $stranger = User::factory()->create(['email' => 'stranger-'.uniqid().'@example.com']);
        $stranger->assignRole('landlord');

        $this->actingAs($stranger)
            ->get(route('exports.download', ['file_id' => $export->getKey()]))
            ->assertForbidden();

        @unlink(storage_path('app/'.$export->file_path));
    }

    public function test_failed_batch_job_marks_the_export_failed_and_notifies(): void
    {
        [$landlord, $invoices] = $this->makeLandlordWithInvoices('E', 2);

        $this->mock(InvoicePdfService::class, function ($mock) {
            $mock->shouldReceive('makeBatch')->once()->andThrow(new \RuntimeException('chrome exploded'));
        });

        $export = Export::create([
            'user_id' => $landlord->id,
            'file_name' => 'invoices-batch.pdf',
            'status' => 'pending',
        ]);

        $notificationsBefore = DB::table('notifications')->count();

        try {
            (new GenerateBatchInvoicePdfJob($export, $invoices->pluck('id')->all()))->handle();
            $this->fail('The job should rethrow so the queue records the failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('chrome exploded', $e->getMessage());
        }

        $this->assertSame('failed', $export->refresh()->status);
        $this->assertSame($notificationsBefore + 1, DB::table('notifications')->count());
    }

    /** @return array{0: User, 1: Collection<int, Invoice>} */
    private function makeLandlordWithInvoices(string $tag, int $count): array
    {
        $landlord = User::factory()->create(['email' => "queue-landlord-{$tag}-".uniqid().'@example.com']);
        $landlord->assignRole('landlord');

        $property = Property::create(['landlord_id' => $landlord->id, 'name' => "Property {$tag}"]);

        $tenant = User::factory()->create(['email' => "queue-tenant-{$tag}-".uniqid().'@example.com']);
        $tenant->assignRole('tenant');

        $invoices = collect(range(1, $count))->map(function (int $i) use ($tag, $landlord, $property, $tenant) {
            $unit = Unit::create([
                'property_id' => $property->id,
                'landlord_id' => $landlord->id,
                'room_number' => "{$tag}-".str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'room_type' => 'Standard',
                'rent_amount' => 500,
                'status' => UnitStatus::Available,
            ]);

            $rental = Rental::create([
                'landlord_id' => $landlord->id,
                'tenant_id' => $tenant->id,
                'unit_id' => $unit->id,
                'monthly_rent' => 500,
                'security_deposit' => 0,
                'status' => RentalStatus::Active,
                'start_date' => now()->startOfMonth()->toDateString(),
            ]);

            return Invoice::create([
                'rental_id' => $rental->id,
                'property_id' => $property->id,
                'landlord_id' => $landlord->id,
                'tenant_id' => $tenant->id,
                'invoice_number' => "INV-{$tag}-".str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'amount_due' => 500.0,
                'period_start' => now()->startOfMonth(),
                'period_end' => now()->endOfMonth(),
                'issue_date' => now(),
                'due_date' => now()->addDays(7),
                'payment_status' => InvoiceStatus::Pending,
            ]);
        });

        return [$landlord, $invoices];
    }
}
