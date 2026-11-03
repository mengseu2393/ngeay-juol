<?php

namespace App\Livewire\M2;

use App\Models\Invoice;
use App\Models\Unit;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2/rooms/{unit}/bill — sketch 2's "Acc Bill": the room, each charge of its
 * bill (water, electricity, rent…) with its amount, and the total. Shows the
 * newest invoice of the room across all its tenancies; the pills above pick an
 * earlier one. Read-only — payments are recorded elsewhere, through
 * Invoice::recordPayment().
 */
#[Layout('m2.layout')]
class Bill extends Component
{
    public Unit $unit;

    /** An earlier invoice picked from the pills (?invoice=); anything not this room's is ignored. */
    protected $queryString = ['invoiceId' => ['as' => 'invoice', 'except' => null]];

    public ?int $invoiceId = null;

    public function mount(Unit $unit): void
    {
        $this->unit = $unit;
    }

    public function pick(int $invoiceId): void
    {
        $this->invoiceId = $invoiceId;
    }

    public function render()
    {
        $recent = $this->unit->invoices()
            ->orderByDesc('invoices.issue_date')->orderByDesc('invoices.id')
            ->limit(6)
            ->get();

        $invoice = $recent->firstWhere('id', $this->invoiceId) ?? $recent->first();

        $invoice?->load(['lines', 'rental', 'property.settings']);

        return view('livewire.m2.bill', [
            'unit' => $this->unit,
            'recent' => $recent,
            'invoice' => $invoice,
            'lines' => $invoice ? $invoice->lines->filter(fn ($line) => $line->shouldAppearOnTenantInvoice())->values() : collect(),
        ])->title(__('Bill'));
    }
}
