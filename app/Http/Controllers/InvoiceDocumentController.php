<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\InvoiceExcelExport;
use App\Services\InvoicePdfService;
use App\Support\InvoicePaper;
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

        $pdf = app(InvoicePdfService::class)->make($invoice, $size);
        $name = InvoicePdfService::filename($invoice, 'pdf');

        return $mode === 'stream' ? $pdf->stream($name) : $pdf->download($name);
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

        abort_unless((int) $invoice->tenant_id === (int) $user?->id, 403);
    }
}
