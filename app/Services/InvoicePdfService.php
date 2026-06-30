<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\InvoicePaper;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as BasePDF;

/**
 * Renders an invoice to a dompdf document at a chosen paper size.
 *
 * The same Blade view drives both the standard ISO-page layout and the narrow
 * thermal-receipt layout; {@see InvoicePaper} decides which one and supplies the
 * paper geometry.
 */
class InvoicePdfService
{
    /** Build a print-ready PDF for the invoice at the requested paper size. */
    public function make(Invoice $invoice, string $size): BasePDF
    {
        $invoice->loadMissing(['lines', 'payments.recordedBy', 'tenant', 'rental.unit.property', 'property']);

        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'size' => $size,
            'thermal' => InvoicePaper::isThermal($size),
        ]);

        $pdf->setPaper(InvoicePaper::dompdfPaper($size, $invoice), 'portrait');

        return $pdf;
    }

    /** Safe download filename for an invoice document (sanitised invoice number). */
    public static function filename(Invoice $invoice, string $ext): string
    {
        $base = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $invoice->invoice_number);

        return $base . '.' . $ext;
    }
}
