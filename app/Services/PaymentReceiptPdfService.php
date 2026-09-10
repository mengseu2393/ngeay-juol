<?php

namespace App\Services;

use App\Models\Payment;
use App\Support\InvoicePaper;
use Barryvdh\DomPDF\Facade\Pdf;
use Spatie\Browsershot\Browsershot;

/**
 * Renders a single payment as a hand-over receipt PDF.
 *
 * Deliberately built on the invoice pipeline rather than beside it:
 * {@see InvoicePaper} still owns every paper size, and
 * {@see InvoicePdfService::renderThroughBrowsershot()} still owns the
 * Browsershot-then-dompdf half. Only the geometry, the fallback renderer and
 * the log line are ours.
 *
 * The fallback is SILENT BY DESIGN — a warning log line, then a dompdf PDF that
 * cannot shape Khmer script at all. After touching this, generate a receipt and
 * grep storage/logs/laravel.log for 'render failed'; a passing test does not
 * catch the fallback.
 */
class PaymentReceiptPdfService
{
    /**
     * Thermal page height in millimetres.
     *
     * Unlike an invoice (whose height grows with its line items, hence the 220mm
     * the invoice service asks for) a receipt has a FIXED set of rows — header,
     * ~8 meta rows, the amount, three invoice totals, an optional note. 140mm
     * holds all of that with room to spare and does not waste a hand-span of
     * paper on every cash payment.
     */
    private const THERMAL_HEIGHT_MM = 140;

    /** Build a print-ready receipt PDF for the payment at the requested paper size. */
    public function make(Payment $payment, string $size): string
    {
        $payment->loadMissing([
            'recordedBy',
            'invoice.tenant',
            'invoice.property.settings',
            'invoice.rental.unit.property',
        ]);

        $html = view('payments.receipt', $this->viewData($payment, $size))->render();

        return InvoicePdfService::renderThroughBrowsershot(
            $html,
            function (Browsershot $browsershot) use ($size): void {
                $browsershot->margins(0, 0, 0, 0);

                // Geometry comes from InvoicePaper, the single source of truth for
                // paper sizes — don't reintroduce hard-coded mm widths here. Thermal
                // MUST go through paperSize(); Browsershot has no paperWidth() and
                // silently swallows the call (see InvoicePdfService::render()).
                if (InvoicePaper::isThermal($size)) {
                    $browsershot->paperSize(InvoicePaper::widthMm($size), self::THERMAL_HEIGHT_MM, 'mm');
                } elseif ($size === 'a5') {
                    $browsershot->format('A5');
                } else {
                    $browsershot->format('A4');
                }

                $browsershot->timeout(60);
            },
            fn () => $this->makeWithDompdf($payment, $size),
            'Browsershot payment receipt PDF render failed; falling back to dompdf.',
            ['size' => $size, 'payment_id' => $payment->getKey()],
        );
    }

    /**
     * The receipt's number.
     *
     * `payments.receipt_number` is free text the landlord may or may not fill in,
     * so a blank one falls back to a generated number that mirrors the invoice
     * convention in {@see InvoiceBuilderService::generateNumber()}
     * (`INV-{landlordId}-{YYYYMM}-{seq}`) with an RCP prefix.
     *
     * The sequence part is the payment's primary key rather than a counted
     * position: a receipt may be reprinted at any time and MUST print the same
     * number every time, which a "count the rows before me" sequence cannot
     * promise once a payment is deleted. The key is unique per row, so the
     * result is deterministic and collision-free without a write on a GET.
     */
    public static function receiptNumber(Payment $payment): string
    {
        if (filled($payment->receipt_number)) {
            return (string) $payment->receipt_number;
        }

        $landlordId = (int) ($payment->invoice?->landlord_id ?? 0);
        $period = ($payment->paid_at ?? $payment->created_at ?? now())->format('Ym');

        return sprintf(
            'RCP-%d-%s-%s',
            $landlordId,
            $period,
            str_pad((string) $payment->getKey(), 4, '0', STR_PAD_LEFT),
        );
    }

    /** Safe download filename for a receipt document (sanitised receipt number). */
    public static function filename(Payment $payment, string $ext): string
    {
        $base = preg_replace('/[^A-Za-z0-9_\-]/', '_', static::receiptNumber($payment));

        return $base.'.'.$ext;
    }

    /** Build the same receipt with dompdf when Chromium is unavailable. */
    protected function makeWithDompdf(Payment $payment, string $size): string
    {
        $pdf = Pdf::loadView('payments.receipt', $this->viewData($payment, $size));

        // No invoice is passed: InvoicePaper sizes a thermal page from an
        // invoice's line + payment count, and a receipt has neither — its own
        // minimum height (400pt) is the right box here.
        $pdf->setPaper(InvoicePaper::dompdfPaper($size), 'portrait');

        return $pdf->output();
    }

    /** @return array<string, mixed> */
    protected function viewData(Payment $payment, string $size): array
    {
        return [
            'payment' => $payment,
            'invoice' => $payment->invoice,
            'size' => $size,
            'thermal' => InvoicePaper::isThermal($size),
            'receiptNumber' => static::receiptNumber($payment),
        ];
    }
}
