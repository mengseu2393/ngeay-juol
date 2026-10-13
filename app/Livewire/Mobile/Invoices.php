<?php

namespace App\Livewire\Mobile;

use App\Livewire\Concerns\ListsInvoices;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m — the mobile shell's Invoices screen (its home). Same list, filters,
 * search and payment recording as Simple Mode's (Concerns\ListsInvoices), in a
 * phone layout: tap a card for the invoice in a bottom sheet, load more by
 * scrolling instead of page numbers.
 */
#[Layout('mobile.layout')]
class Invoices extends Component
{
    use ListsInvoices;

    private const PAGE_SIZE = 20;

    public int $perPage = self::PAGE_SIZE;

    protected $queryString = [
        'filter' => ['except' => 'unpaid'],
        'search' => ['except' => ''],
    ];

    protected $listeners = ['invoices-created' => '$refresh'];

    public function loadMore(): void
    {
        $this->perPage += self::PAGE_SIZE;
    }

    /** A new filter or search starts from the top again. */
    public function updated(string $property): void
    {
        if (in_array($property, ['filter', 'search'], true)) {
            $this->perPage = self::PAGE_SIZE;
        }
    }

    public function render()
    {
        return view('livewire.mobile.invoices', $this->invoiceListData($this->perPage))
            ->title(__('Invoices'));
    }
}
