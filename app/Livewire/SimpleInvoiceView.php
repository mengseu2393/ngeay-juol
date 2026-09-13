<?php

namespace App\Livewire;

use App\Models\Invoice;
use App\Support\ActiveProperty;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Simple Mode's invoice-slip popup, isolated from SimpleInvoiceList so opening
 * it doesn't drag the whole invoice list (and its heavier eager loads) through
 * a re-render.
 *
 * The popup shell (backdrop + card) is owned client-side by Alpine in the
 * Blade view and opens the instant "View details" is tapped — before any
 * network activity. This component is mounted once, always, alongside the
 * list; it only fetches and morphs in the invoice *body* via open(), so the
 * one server round-trip fills an already-visible, already-animated popup
 * instead of gating it. On a phone that round-trip (plus the DOM morph of a
 * 15-card list, which the old parent-tracked design forced) was the entire
 * perceived lag.
 */
class SimpleInvoiceView extends Component
{
    #[Locked]
    public ?int $invoiceId = null;

    public function mount(?int $invoiceId = null): void
    {
        $this->invoiceId = $invoiceId;
    }

    /** Called from the client the moment the popup is opened for an invoice. */
    public function open(int $invoiceId): void
    {
        $this->invoiceId = $invoiceId;
    }

    public function render()
    {
        $invoice = null;

        if ($this->invoiceId !== null) {
            $invoice = Invoice::query()
                ->with(['lines.utilityUsage.propertyUtility', 'rental.unit.property', 'tenant', 'property.settings'])
                ->when(ActiveProperty::id(), fn ($q) => $q->where('property_id', ActiveProperty::id()))
                ->whereKey($this->invoiceId)
                ->first();

            abort_unless($invoice, 404);
        }

        return view('livewire.simple-invoice-view', ['invoice' => $invoice]);
    }
}
