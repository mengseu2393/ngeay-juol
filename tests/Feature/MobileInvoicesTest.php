<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Livewire\Mobile\Invoices;
use Livewire\Livewire;

/**
 * /m's Invoices screen. It shares its list logic with Simple Mode's
 * (Concerns\ListsInvoices); these pin that the phone screen applies it — scoped
 * to the active property, filtered, searchable, loaded in batches as you scroll.
 */
class MobileInvoicesTest extends MobileTestCase
{
    public function test_it_lists_the_active_propertys_unpaid_invoices_by_default(): void
    {
        $landlord = $this->landlord();
        $home = $this->property($landlord, 'Home');
        $other = $this->property($landlord, 'Other');

        $this->invoice($this->rental($landlord, $home, 'A-1', 'Dara'), 'INV-UNPAID');
        $this->invoice($this->rental($landlord, $home, 'A-2', 'Sokha'), 'INV-PAID', status: InvoiceStatus::Paid);
        $this->invoice($this->rental($landlord, $other, 'B-1', 'Vanna'), 'INV-ELSEWHERE');

        $this->signIn($landlord, $home)
            ->get('/m')
            ->assertOk()
            ->assertSee('INV-UNPAID')
            ->assertSee('Dara')
            ->assertDontSee('INV-PAID')
            ->assertDontSee('INV-ELSEWHERE');
    }

    public function test_another_landlords_invoices_never_show(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $stranger = $this->landlord();
        $this->invoice($this->rental($stranger, $this->property($stranger), 'X-1', 'Stranger'), 'INV-STRANGER');

        $this->signIn($landlord, $property);

        Livewire::test(Invoices::class)
            ->set('filter', 'all')
            ->assertDontSee('INV-STRANGER');
    }

    public function test_filters_and_search_narrow_the_list(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->invoice($this->rental($landlord, $property, 'A-1', 'Dara'), 'INV-1');
        $this->invoice($this->rental($landlord, $property, 'A-2', 'Sokha'), 'INV-2', status: InvoiceStatus::Paid);

        $this->signIn($landlord, $property);

        Livewire::test(Invoices::class)
            ->set('filter', 'paid')
            ->assertSee('INV-2')->assertDontSee('INV-1')
            ->set('filter', 'all')
            ->assertSee('INV-1')->assertSee('INV-2')
            ->set('search', 'Dara')
            ->assertSee('INV-1')->assertDontSee('INV-2')
            ->set('search', 'A-2')
            ->assertSee('INV-2')->assertDontSee('INV-1');
    }

    /** Scrolling loads the next batch; a new filter starts from the top again. */
    public function test_more_invoices_load_in_batches(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');

        foreach (range(1, 25) as $n) {
            $this->invoice($rental, sprintf('INV-%03d', $n), issued: now()->subDays($n));
        }

        $this->signIn($landlord, $property);

        Livewire::test(Invoices::class)
            ->assertSee('INV-020')->assertDontSee('INV-021')
            ->assertSeeHtml('id="rw-m-invoice-load-more"')
            ->call('loadMore')
            ->assertSee('INV-025')
            ->assertDontSeeHtml('id="rw-m-invoice-load-more"')
            ->set('filter', 'all')
            ->assertSet('perPage', 20);
    }

    public function test_no_active_property_asks_to_choose_one(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord);

        Livewire::test(Invoices::class)->assertSee(__('Choose a property first'));
    }
}
