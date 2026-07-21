<?php

namespace App\Services;

use App\Models\Invoice;
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
                $pages[] = '<div class="rw-batch-page">' . $m[1] . '</div>';
            }
        }

        // The single-invoice template puts the page padding on <body> (see its
        // comment about dompdf); in a batch that must live on each page div so
        // every invoice starts padded.
        $html = '<!DOCTYPE html><html lang="' . str_replace('_', '-', app()->getLocale()) . '"><head><meta charset="utf-8">'
            . '<title>' . __('Invoices') . '</title>'
            . '<style>' . $style . '
                body { margin: 0 !important; }
                .rw-batch-page { padding: 44px 52px; page-break-after: always; }
                .rw-batch-page:last-child { page-break-after: auto; }
            </style></head><body>' . implode('', $pages) . '</body></html>';

        return $this->render($html, $size, function () use ($html, $size) {
            $pdf = Pdf::loadHTML($html);
            $pdf->setPaper($size === 'a5' ? 'a5' : 'a4', 'portrait');

            return $pdf->output();
        }, timeout: 120);
    }

    /** Render final HTML to PDF via Browsershot, invoking $fallback on failure. */
    protected function render(string $html, string $size, callable $fallback, int $timeout = 60): string
    {
        $browsershot = Browsershot::html($html)
            ->showBackground()
            ->margins(0, 0, 0, 0)
            ->setNodeModulePath(config('services.browsershot.node_module_path', base_path('node_modules')))
            ->noSandbox();
            
        if ($chromePath = config('services.browsershot.chrome_path')) {
            $browsershot->setChromePath($chromePath);
        }

        $browsershot->addChromiumArguments(config('services.browsershot.chromium_arguments', []));
        $browsershot->addChromiumArguments(['allow-file-access-from-files']);

        if ($nodeBinary = $this->nodeBinary()) {
            $browsershot->setNodeBinary($nodeBinary);
        }

        if ($npmBinary = config('services.browsershot.npm_binary')) {
            $browsershot->setNpmBinary($npmBinary);
        }

        if ($includePath = config('services.browsershot.include_path')) {
            $browsershot->setIncludePath($includePath);
        }

        if ($size === 'a4') {
            $browsershot->format('A4');
        } elseif ($size === 'a5') {
            $browsershot->format('A5');
        } else {
            // Thermal receipt: pass exact mm dimensions to Puppeteer
            $browsershot->paperWidth(80, 'mm')
                ->paperHeight(220, 'mm');
        }

        $browsershot->timeout($timeout);

        try {
            return $browsershot->pdf();
        } catch (Throwable $exception) {
            Log::warning('Browsershot invoice PDF render failed; falling back to dompdf.', [
                'size' => $size,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $fallback();
        }
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

    protected function nodeBinary(): ?string
    {
        $configured = config('services.browsershot.node_binary');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $playwrightNodes = glob((string) getenv('HOME') . '/.cache/ms-playwright-go/*/node') ?: [];

        usort($playwrightNodes, 'strnatcmp');

        foreach (array_reverse($playwrightNodes) as $node) {
            if (is_executable($node)) {
                return $node;
            }
        }

        return null;
    }

    /** Safe download filename for an invoice document (sanitised invoice number). */
    public static function filename(Invoice $invoice, string $ext): string
    {
        $base = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) $invoice->invoice_number);

        return $base . '.' . $ext;
    }
}
