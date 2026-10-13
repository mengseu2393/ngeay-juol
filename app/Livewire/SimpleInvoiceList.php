<?php

namespace App\Livewire;

use App\Livewire\Concerns\ListsInvoices;
use Livewire\Component;

/**
 * Simple invoice card list for mobile/PWA use.
 * Shows invoices scoped to the active property with simple filters (Unpaid / Paid / This month).
 * Inline payment recording uses Invoice::recordPayment() — never writes to amount_paid directly.
 * The list logic itself is shared with the mobile shell (Concerns\ListsInvoices).
 */
class SimpleInvoiceList extends Component
{
    use ListsInvoices;

    protected $queryString = ['filter', 'search'];

    /** Sibling Simple Mode screens stay mounted on one page; re-render when they change shared data. */
    protected $listeners = ['invoices-created' => '$refresh'];

    public function render()
    {
        return view('livewire.simple-invoice-list', $this->invoiceListData());
    }
}
