<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateBatchInvoicePdfJob;
use App\Models\Export;
use App\Models\Invoice;
use App\Services\InvoiceExcelExport;
use App\Services\InvoicePdfService;
use App\Support\InvoicePaper;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams/downloads an invoice as a PDF (A4, A5, or 80/65 mm thermal receipt) or
 * an XLSX workbook. Routes sit behind 'auth'. Landlords/managers are constrained
 * to their own invoices by Invoice's LandlordScope on the binding; {@see guard()}
 * additionally stops a logged-in tenant from fetching someone else's documents.
 */
class InvoiceDocumentController extends Controller
{
    /**
     * Batches of this many invoices or fewer render inline, in the request, the
     * way they always have; anything larger is queued.
     *
     * Every batch render holds ONE Node + headless-Chrome process (~150-250MB)
     * for its whole duration, inside a PHP-FPM worker. Ten invoices is about the
     * largest batch that still finishes in a few seconds, and it covers the
     * everyday flow this route was built for — "print the two or three invoices
     * I just created" from the invoice list or Simple Mode. Beyond that we are
     * into month-end runs for a whole building (the header action allows up to
     * 200), which is precisely the case that pins a worker for minutes and, with
     * a few landlords doing it at once, exhausts RAM. Raise this only with a
     * matching look at worker memory.
     */
    public const INLINE_BATCH_LIMIT = 10;

    public function view(Invoice $invoice)
    {
        $this->guard($invoice);
        $invoice->loadMissing(['lines.utilityUsage.propertyUtility', 'rental.unit.property', 'tenant', 'property']);

        return view('invoices.simple-view', compact('invoice'));
    }

    /**
     * Render the invoice as a PDF. ?size= picks the paper (defaults to a4);
     * ?mode=stream opens inline (print preview) instead of downloading.
     */
    public function pdf(Request $request, Invoice $invoice)
    {
        $this->guard($invoice);

        $size = in_array($request->query('size'), InvoicePaper::SIZES, true)
            ? $request->query('size')
            : 'a4';

        $mode = $request->query('mode') === 'stream' ? 'stream' : 'download';

        $pdfContent = app(InvoicePdfService::class)->make($invoice, $size);
        $name = InvoicePdfService::filename($invoice, 'pdf');

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($mode === 'stream' ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Render many invoices into ONE PDF (one per page) — "print all" for a
     * filtered list. ?ids= is a comma-separated allow-list built by the caller;
     * Invoice's LandlordScope re-filters it, so foreign IDs are silently
     * dropped rather than leaked. Landlord/staff only — tenants have no
     * batch-print use case.
     *
     * Small batches stream back inline (unchanged). Above
     * {@see INLINE_BATCH_LIMIT} the render moves to a queued job and the caller
     * gets a "preparing" response instead of a PDF; the finished file arrives as
     * a database notification with a download button.
     */
    public function batchPdf(Request $request)
    {
        $user = auth()->user();
        abort_unless($user?->isPlatformStaff() || $user?->effectiveLandlordId(), 403);

        $ids = collect(explode(',', (string) $request->query('ids')))
            ->filter(fn ($id) => ctype_digit($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->take(200);

        abort_if($ids->isEmpty(), 404);

        $invoices = Invoice::query()
            ->with(['lines', 'payments.recordedBy', 'tenant', 'rental.unit.property', 'property'])
            ->whereIn('id', $ids)
            ->orderBy('invoice_number')
            ->get();

        abort_if($invoices->isEmpty(), 404);

        $mode = $request->query('mode') === 'stream' ? 'inline' : 'attachment';
        $name = 'invoices-'.now()->format('Ymd-Hi').'-'.$invoices->count().'.pdf';

        if ($invoices->count() > self::INLINE_BATCH_LIMIT) {
            return $this->queueBatchPdf($request, $invoices->pluck('id')->all(), $name);
        }

        $pdfContent = app(InvoicePdfService::class)->makeBatch($invoices);

        return response($pdfContent, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $mode.'; filename="'.$name.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Hand a too-large batch to the queue and answer with "we're on it".
     *
     * Delivery deliberately reuses the Export plumbing the utility export
     * already uses — an Export row plus the `exports.download` route, which
     * enforces owner + `completed` — rather than a second download mechanism.
     * If no queue worker is running the row simply stays `pending`, the user
     * keeps seeing "preparing" and `exports.download` 404s "not ready": slow,
     * but never a silent black hole.
     */
    protected function queueBatchPdf(Request $request, array $invoiceIds, string $name)
    {
        $export = Export::create([
            'user_id' => auth()->id(),
            'file_name' => $name,
            'status' => 'pending',
        ]);

        GenerateBatchInvoicePdfJob::dispatch($export, $invoiceIds);

        $body = __(':count invoices are being combined into one PDF. You will get a notification with a download link when it is ready.', [
            'count' => count($invoiceIds),
        ]);

        // The render outlives this request, so the notification the job sends is
        // the channel that actually reaches the landlord; this flash is only the
        // immediate acknowledgement on the page they land back on.
        Notification::make()
            ->title(__('Preparing your invoices'))
            ->body($body)
            ->info()
            ->send();

        if ($request->expectsJson()) {
            return response()->json([
                'queued' => true,
                'export_id' => $export->getKey(),
                'message' => $body,
            ], 202);
        }

        return redirect()->back(fallback: route('filament.landlord.resources.invoices.index'));
    }

    /**
     * Stream the invoice as an XLSX download.
     */
    public function excel(Invoice $invoice): StreamedResponse
    {
        $this->guard($invoice);

        return app(InvoiceExcelExport::class)->download($invoice);
    }

    /**
     * Platform staff and landlord/manager actors are already limited to the
     * invoices they may see (staff: all; landlord/manager: their own, via
     * LandlordScope). Any other actor — a tenant on the shared 'web' guard —
     * may only reach the documents for their OWN invoice.
     */
    protected function guard(Invoice $invoice): void
    {
        $user = auth()->user();

        if ($user?->isPlatformStaff() || $user?->effectiveLandlordId()) {
            return;
        }

        abort_unless($user && in_array((int) $invoice->rental_id, $user->tenantPortalRentalIds(), true), 403);
    }
}
