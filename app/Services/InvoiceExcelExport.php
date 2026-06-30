<?php

namespace App\Services;

use App\Models\Invoice;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceExcelExport
{
    /**
     * Stream the invoice as a downloadable XLSX workbook.
     */
    public function download(Invoice $invoice): StreamedResponse
    {
        $invoice->loadMissing(['lines', 'payments.recordedBy', 'tenant', 'rental.unit.property', 'property']);

        return response()->streamDownload(
            fn () => $this->write($invoice),
            InvoicePdfService::filename($invoice, 'xlsx'),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * Write the workbook contents straight to the output buffer.
     */
    private function write(Invoice $invoice): void
    {
        $bold = (new Style())->setFontBold();

        $writer = new Writer();
        $writer->openToFile('php://output');

        // --- Title block -------------------------------------------------
        $writer->addRow($this->labelled(__('Invoice'), (string) $invoice->invoice_number, $bold));
        $writer->addRow($this->labelled(__('Property'), (string) optional($invoice->property)->name));
        $writer->addRow($this->labelled(__('Tenant'), (string) (optional($invoice->tenant)->name ?? optional($invoice->rental)->occupant_name)));
        $writer->addRow($this->labelled(__('Room'), (string) optional(optional($invoice->rental)->unit)->room_number));
        $writer->addRow($this->labelled(__('Period'), $this->period($invoice)));
        $writer->addRow($this->labelled(__('Issued'), $this->date($invoice->issue_date)));
        $writer->addRow($this->labelled(__('Due'), $this->date($invoice->due_date)));
        $writer->addRow($this->labelled(__('Status'), (string) optional($invoice->payment_status)->getLabel()));

        $writer->addRow(Row::fromValues([]));

        // --- Line items --------------------------------------------------
        $writer->addRow(Row::fromValues([
            __('Description'),
            __('Type'),
            __('Qty'),
            __('Unit price'),
            __('Amount'),
        ], $bold));

        foreach ($invoice->lines as $line) {
            $description = (string) $line->description;
            if ($line->is_waived) {
                $description .= ' '.__('(Waived)');
            }

            $writer->addRow(Row::fromValues([
                $description,
                (string) optional($line->line_type)->getLabel(),
                (float) $line->quantity,
                $this->money($line->unit_price),
                $this->money($line->is_waived ? 0 : $line->amount),
            ]));
        }

        // --- Totals ------------------------------------------------------
        $writer->addRow(Row::fromValues([]));
        $writer->addRow($this->total(__('Subtotal'), $invoice->amount_due, $bold));
        $writer->addRow($this->total(__('Total due'), $invoice->amount_due, $bold));
        $writer->addRow($this->total(__('Paid'), $invoice->amount_paid, $bold));
        $writer->addRow($this->total(__('Balance'), $invoice->balance, $bold));

        // --- Payments ----------------------------------------------------
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues([
            __('Date'),
            __('Amount'),
            __('Method'),
            __('Receipt'),
        ], $bold));

        foreach ($invoice->payments as $payment) {
            $writer->addRow(Row::fromValues([
                $this->date($payment->paid_at),
                $this->money($payment->amount),
                (string) optional($payment->method)->getLabel(),
                (string) $payment->receipt_number,
            ]));
        }

        $writer->close();
    }

    /**
     * A two-column "Label / value" row, optionally styling the label cell.
     */
    private function labelled(string $label, string $value, ?Style $style = null): Row
    {
        return Row::fromValues([$label, $value], $style);
    }

    /**
     * A totals row: blank, blank, blank, label, money.
     */
    private function total(string $label, mixed $amount, Style $style): Row
    {
        return Row::fromValues(['', '', '', $label, $this->money($amount)], $style);
    }

    /**
     * Format a money value as a "$1,234.56" string.
     */
    private function money(mixed $value): string
    {
        return '$'.number_format((float) $value, 2);
    }

    /**
     * Format a nullable date as "d M Y" (empty string when null).
     */
    private function date(mixed $date): string
    {
        return $date ? $date->format('d M Y') : '';
    }

    /**
     * Render the billing period as "start – end" using available dates.
     */
    private function period(Invoice $invoice): string
    {
        $start = $this->date($invoice->period_start);
        $end = $this->date($invoice->period_end);

        if ('' === $start && '' === $end) {
            return '';
        }

        return trim($start.' – '.$end, ' –');
    }
}
