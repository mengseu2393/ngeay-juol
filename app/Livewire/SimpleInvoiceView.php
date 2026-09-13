<?php

namespace App\Livewire;

use App\Models\Invoice;
use App\Support\ActiveProperty;
use Livewire\Component;

/**
 * Simple Mode's invoice-slip popup, isolated from SimpleInvoiceList so opening
 * it doesn't drag the whole invoice list (and its heavier eager loads) through
 * a re-render — SimpleInvoiceList only tracks *which* invoice id is open and
 * mounts this component for it; this component owns its own query/lifecycle.
 */
class SimpleInvoiceView extends Component
{
    public int $invoiceId;

    public function mount(int $invoiceId): void
    {
        $this->invoiceId = $invoiceId;
    }

    public function closeView(): void
    {
        $this->dispatch('invoice-view-closed');
    }

    public function render()
    {
        $invoice = Invoice::query()
            ->with(['lines.utilityUsage.propertyUtility', 'rental.unit.property', 'tenant', 'property.settings'])
            ->when(ActiveProperty::id(), fn ($q) => $q->where('property_id', ActiveProperty::id()))
            ->whereKey($this->invoiceId)
            ->first();

        abort_unless($invoice, 404);

        return view('livewire.simple-invoice-view', ['invoice' => $invoice]);
    }
}
