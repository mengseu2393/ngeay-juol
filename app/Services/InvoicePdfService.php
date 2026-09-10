<?php

namespace App\Services;

use App\Models\Invoice;
use App\Support\BrowsershotFactory;
use App\Support\InvoicePaper;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Spatie\Browsershot\Browsershot;
use Throwable;

/**
 * Renders an invoice to a PDF document using headless Chrome (Browsershot) at a chosen paper size.
 *
 * The same Blade view drives both the standard ISO-page layout and the narrow
 * thermal-receipt layout; {@see InvoicePaper} decides which one and supplies the
 * paper geometry.
 */
class InvoicePdfService
{
    /** Build a print-ready PDF for the invoice at the requested paper size. */
    public function make(Invoice $invoice, string $size): string
    {
        $invoice->loadMissing(['lines', 'payments.recordedBy', 'tenant', 'rental.unit.property', 'property']);

        $html = view('invoices.pdf', [
            'invoice' => $invoice,
            'size' => $size,
            'thermal' => InvoicePaper::isThermal($size),
        ])->render();

        return $this->render($html, $size, fn () => $this->makeWithDompdf($invoice, $size));
    }

    /**
     * Build one PDF containing many invoices (one per page) — the "print all"
     * flow for a filtered list. Each invoice is rendered through the SAME
     * single-invoice template, then the page bodies are stitched together with
     * page breaks, so batch output can never drift from the single-invoice PDF.
     * Standard (A4/A5) layout only — thermal receipts don't batch.
     */
    public function makeBatch($invoices, string $size = 'a4'): string
    {
        $style = null;
        $pages = [];

        foreach ($invoices as $invoice) {
            $invoice->loadMissing(['lines', 'payments.recordedBy', 'tenant', 'rental.unit.property', 'property']);

            $html = view('invoices.pdf', [
                'invoice' => $invoice,
                'size' => $size,
                'thermal' => false,
            ])->render();

            if ($style === null && preg_match('~<style>(.*?)</style>~s', $html, $m)) {
                $style = $m[1];
            }

            // Anchor past </head>: the template's CSS comments mention "<body>",
            // so a bare <body> match would land inside the <style> block.
            if (preg_match('~</head>\s*<body[^>]*>(.*?)</body>~s', $html, $m)) {
                $pages[] = '<div class="rw-batch-page">'.$m[1].'</div>';
            }
        }

        // The single-invoice template puts the page padding on <body> (see its
        // comment about dompdf); in a batch that must live on each page div so
        // every invoice starts padded.
        $html = '<!DOCTYPE html><html lang="'.str_replace('_', '-', app()->getLocale()).'"><head><meta charset="utf-8">'
            .'<title>'.__('Invoices').'</title>'
            .'<style>'.$style.'
                body { margin: 0 !important; }
                .rw-batch-page { padding: 44px 52px; page-break-after: always; }
                .rw-batch-page:last-child { page-break-after: auto; }
            </style></head><body>'.implode('', $pages).'</body></html>';

        return $this->render($html, $size, function () use ($html, $size) {
            $pdf = Pdf::loadHTML($html);
            $pdf->setPaper($size === 'a5' ? 'a5' : 'a4', 'portrait');

            return $pdf->output();
        }, timeout: 120);
    }

    /**
     * Render HTML to PDF bytes through Browsershot, falling back to dompdf on
     * any Throwable. THE ONE PLACE that half of the pipeline lives.
     *
     * `BrowsershotFactory` already owns the ~25 lines of Chrome/Node wiring; the
     * try / log-warning / dompdf half was still copy-pasted next to every call
     * of it ({@see render()} here and `ExportUtilityUsagesJob::generatePdf()`),
     * so a third Browsershot caller (the queued batch print) triggered the
     * extraction CLAUDE.md asks for. Callers keep only what genuinely differs:
     * paper geometry ($configure), the dompdf renderer, and the log line.
     *
     * The fallback is SILENT BY DESIGN — a warning log line, then a dompdf PDF
     * that cannot shape Khmer script at all. After touching this, generate a PDF
     * and grep storage/logs/laravel.log for 'render failed'; a passing test does
     * not catch the fallback.
     *
     * @param  callable(Browsershot): void  $configure  applies margins/paper/orientation/timeout
     * @param  callable(): string  $fallback  dompdf renderer, returns PDF bytes
     * @param  array<string, mixed>  $logContext  extra context merged into the warning
     */
    public static function renderThroughBrowsershot(
        string $html,
        callable $configure,
        callable $fallback,
        string $failureMessage,
        array $logContext = [],
    ): string {
        $browsershot = BrowsershotFactory::html($html);

        $configure($browsershot);

        try {
            return $browsershot->pdf();
        } catch (Throwable $exception) {
            Log::warning($failureMessage, $logContext + [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $fallback();
        }
    }

    /** Render final HTML to PDF via Browsershot, invoking $fallback on failure. */
    protected function render(string $html, string $size, callable $fallback, int $timeout = 60): string
    {
        return static::renderThroughBrowsershot(
            $html,
            function (Browsershot $browsershot) use ($size, $timeout): void {
                $browsershot->margins(0, 0, 0, 0);

                // Geometry comes from InvoicePaper, the single source of truth for paper
                // sizes — don't reintroduce hard-coded mm values here.
                //
                // Thermal MUST go through paperSize(). Browsershot has no paperWidth() or
                // paperHeight() method: its __call forwards unknown methods to the image
                // manipulations object, so the old paperWidth(58,'mm')->paperHeight(220,'mm')
                // pair was silently swallowed and every receipt rendered as US Letter.
                if (InvoicePaper::isThermal($size)) {
                    $browsershot->paperSize(InvoicePaper::widthMm($size), 220, 'mm');
                } elseif ($size === 'a5') {
                    $browsershot->format('A5');
                } else {
                    $browsershot->format('A4');
                }

                $browsershot->timeout($timeout);
            },
            $fallback,
            'Browsershot invoice PDF render failed; falling back to dompdf.',
            ['size' => $size],
        );
    }

    /** Build the same invoice PDF with dompdf when Chromium is unavailable. */
    protected function makeWithDompdf(Invoice $invoice, string $size): string
    {
        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'size' => $size,
            'thermal' => InvoicePaper::isThermal($size),
        ]);

        $pdf->setPaper(InvoicePaper::dompdfPaper($size, $invoice), 'portrait');

        return $pdf->output();
    }

    /** Safe download filename for an invoice document (sanitised invoice number). */
    public static function filename(Invoice $invoice, string $ext): string
    {
        $base = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $invoice->invoice_number);

        return $base.'.'.$ext;
    }
}
