<?php

namespace App\Livewire\M2;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\InvoicePdfService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2/invoices/{invoice}/slip — sketch 2's "Slip": property name and room,
 * the tenant, the charges, the total. A page to show or print (the print
 * sheet hides the bars) with the real invoice PDF one tap away, and "Share to
 * Telegram", which opens the Telegram app with the message written and saves the
 * PDF for attaching in the chat: a tg:// link can't carry a file. A cancelled bill
 * can't be shared: the tenant would read it as still owed. The {invoice} binding
 * runs through the landlord scope.
 */
#[Layout('m2.layout')]
class Slip extends Component
{
    public Invoice $invoice;

    public function mount(Invoice $invoice): void
    {
        $this->invoice = $invoice;
    }

    /** The message that goes with the PDF. A settled bill says so, rather than "pay $0.00 by…". */
    private function shareText(Invoice $invoice, string $room): string
    {
        if ($invoice->payment_status === InvoiceStatus::Paid || (float) $invoice->balance <= 0.004) {
            return __('Invoice :number — :room — paid', ['number' => $invoice->invoice_number, 'room' => $room]);
        }

        return __('Invoice :number — :room — :amount due :date', [
            'number' => $invoice->invoice_number,
            'room' => $room,
            'amount' => Money::formatForRecord($invoice->balance, $invoice),
            'date' => Invoice::displayDate($invoice->due_date, 'd M Y'),
        ]);
    }

    public function render()
    {
        $invoice = $this->invoice->load(['lines', 'rental.unit', 'property.settings']);
        $unit = $invoice->rental?->unit;

        return view('livewire.m2.slip', [
            'invoice' => $invoice,
            'unit' => $unit,
            'canShare' => $invoice->payment_status !== InvoiceStatus::Cancelled,
            'shareFilename' => InvoicePdfService::filename($invoice, 'pdf'),
            'telegramUrl' => 'tg://msg?text='.rawurlencode($this->shareText($invoice, $unit?->room_number ?? '—')),
            'lines' => $invoice->lines->filter(fn ($line) => $line->shouldAppearOnTenantInvoice())->values(),
        ])->title($invoice->invoice_number);
    }
}
