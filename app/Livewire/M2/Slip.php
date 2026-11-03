<?php

namespace App\Livewire\M2;

use App\Models\Invoice;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2/invoices/{invoice}/slip — sketch 2's "Slip": property name and room,
 * the tenant, the charges, the total. A page to show or print (the print
 * sheet hides the bars) with the real invoice PDF one tap away. The {invoice}
 * binding runs through the landlord scope.
 */
#[Layout('m2.layout')]
class Slip extends Component
{
    public Invoice $invoice;

    public function mount(Invoice $invoice): void
    {
        $this->invoice = $invoice;
    }

    public function render()
    {
        $invoice = $this->invoice->load(['lines', 'rental.unit', 'property.settings']);

        return view('livewire.m2.slip', [
            'invoice' => $invoice,
            'unit' => $invoice->rental?->unit,
            'lines' => $invoice->lines->filter(fn ($line) => $line->shouldAppearOnTenantInvoice())->values(),
        ])->title($invoice->invoice_number);
    }
}
