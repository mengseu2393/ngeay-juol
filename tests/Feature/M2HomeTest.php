<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Livewire\M2\DashboardProperty;
use App\Livewire\M2\Home;
use App\Support\ActiveProperty;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * /m2 Home (sketch 1). Pinned: rooms come "priority by due" — the room that has
 * waited longest on top, rooms that owe nothing after, by room number — and a
 * room's E / W chips show its newest electricity / water use.
 */
class M2HomeTest extends M2TestCase
{
    public function test_rooms_are_ordered_by_what_they_owe_longest_waiting_first(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $paidUp = $this->rental($landlord, $property, 'A-1', 'Dara');
        $veryLate = $this->rental($landlord, $property, 'A-2', 'Sokha');
        $slightlyLate = $this->rental($landlord, $property, 'A-3', 'Vanna');
        $this->invoice($paidUp, 'INV-1', 300, InvoiceStatus::Paid);
        $this->invoice($slightlyLate, 'INV-3', 300, InvoiceStatus::Pending, Carbon::parse('2026-10-05'));
        $this->invoice($veryLate, 'INV-2', 300, InvoiceStatus::Overdue, Carbon::parse('2026-09-01'));

        $this->signIn($landlord, $property);

        Livewire::test(Home::class)
            ->assertViewHas('rooms', fn ($rooms) => $rooms->pluck('room_number')->all() === ['A-2', 'A-3', 'A-1'])
            ->assertSee(__(':days days late', ['days' => 35]))
            ->assertSee(__(':days days late', ['days' => 1]));
    }

    /**
     * Owing nothing isn't the same as having paid: a tenancy that has never been billed (or only
     * has a cancelled bill) showed "Paid up" (បានបង់ហើយ) on Home and on the dashboard's property
     * list. The owner flagged it; such a room now reads "No bill yet" until its first invoice.
     */
    public function test_a_tenant_without_any_invoice_is_no_bill_yet_not_paid_up(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $unbilled = $this->rental($landlord, $property, 'A-1', 'Dara');
        $cancelledOnly = $this->rental($landlord, $property, 'A-2', 'Sokha');
        $this->invoice($cancelledOnly, 'INV-C', 300, InvoiceStatus::Cancelled);
        $paid = $this->rental($landlord, $property, 'A-3', 'Vanna');
        $this->invoice($paid, 'INV-P', 300, InvoiceStatus::Paid);

        $this->signIn($landlord, $property);

        foreach ([Livewire::test(Home::class), Livewire::test(DashboardProperty::class, ['property' => $property])] as $screen) {
            $html = $screen->html();
            $this->assertSame(1, substr_count($html, '>'.__('Paid up').'<'));
            $this->assertSame(2, substr_count($html, '>'.__('No bill yet').'<'));
        }

        Livewire::test(Home::class)->assertViewHas('summary', fn ($s) => $s['paid'] === 1);
    }

    /**
     * The room cards stay light: meter readings (E / W) live on the Room screen, not on Home. What is
     * owed shows as a thin strip along the card's bottom edge, and only for a room that owes.
     */
    public function test_a_room_card_has_no_meter_chips_and_shows_due_only_when_owing(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $this->reading($landlord, $unit, $this->utility($property, 'Electricity'), '2026-10-10', 142, 42);

        $this->signIn($landlord, $property);

        Livewire::test(Home::class)
            ->assertDontSee('E 42')
            ->assertDontSeeHtml('m2a-sum')
            ->assertDontSeeHtml('m2-view-bar');
    }

    /**
     * Style B hero: the bar shows what is still to collect and three counters (late / due soon / paid up),
     * all derived from the same per-room dues the list is ordered by.
     */
    public function test_the_hero_summarises_what_is_owed_and_counts_rooms_by_state(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $paidUp = $this->rental($landlord, $property, 'A-1', 'Dara');
        $late = $this->rental($landlord, $property, 'A-2', 'Sokha');
        $soon = $this->rental($landlord, $property, 'A-3', 'Vanna');
        $this->invoice($paidUp, 'INV-1', 300, InvoiceStatus::Paid);
        $this->invoice($late, 'INV-2', 300, InvoiceStatus::Overdue, Carbon::parse('2026-09-01'));
        $this->invoice($soon, 'INV-3', 300, InvoiceStatus::Pending, Carbon::parse('2099-01-01'));

        $this->signIn($landlord, $property);

        Livewire::test(Home::class)
            ->assertSeeHtml('id="m2a-sum"')
            ->assertViewHas('summary', fn ($s) => $s['late'] === 1 && $s['soon'] === 1 && $s['paid'] === 1 && $s['total'] === 600.0)
            ->assertSee('$600.00')
            ->assertDontSee('៛');
    }

    public function test_the_header_shows_the_owner_until_a_property_is_chosen_and_the_properties_side_lists_them_all(): void
    {
        $landlord = $this->landlord();
        $home = $this->property($landlord, 'Son Sok Place');
        $this->property($landlord, 'KV Residence');
        $this->rental($landlord, $home, 'A-1', 'Dara');

        $this->signIn($landlord, $home);

        Livewire::test(Home::class)
            ->assertSeeHtml('<b>'.__('Hi, :name', ['name' => 'Mobile Landlord']).'</b>')
            ->assertSee(__('All properties'))
            ->call('setView', 'properties')
            ->assertSee('Son Sok Place')
            ->assertSee('KV Residence')
            ->assertDontSee(__(':occupied of :total rooms occupied', ['occupied' => 1, 'total' => 1]))
            ->assertDontSee(__('Total due'));

        Livewire::test(Home::class, ['scope' => 'property'])
            ->assertSeeHtml('<b>'.__('Hi, :name', ['name' => 'Mobile Landlord']).'</b>')
            ->assertSeeHtml('<small class="m2a-sub">Son Sok Place</small>');
    }

    public function test_choosing_a_property_opens_its_rooms(): void
    {
        $landlord = $this->landlord();
        $first = $this->property($landlord, 'First');
        $second = $this->property($landlord, 'Second');
        $this->rental($landlord, $second, 'B-7', 'Bopha');

        $this->signIn($landlord, $first);

        Livewire::test(Home::class, ['view' => 'properties'])
            ->call('selectProperty', $second->id)
            ->assertSet('view', 'rooms')
            ->assertSee('B-7');

        $this->assertSame($second->id, ActiveProperty::id());
    }

    public function test_another_landlords_property_cannot_be_opened(): void
    {
        $mine = $this->landlord();
        $property = $this->property($mine);
        $foreign = $this->property($this->landlord(), 'Not mine');

        $this->signIn($mine, $property);

        Livewire::test(Home::class)->call('selectProperty', $foreign->id)->assertNotFound();

        $this->assertSame($property->id, ActiveProperty::id());
    }

    public function test_rooms_span_every_property_by_default_and_name_the_property(): void
    {
        $landlord = $this->landlord();
        $first = $this->property($landlord, 'Son Sok Place');
        $second = $this->property($landlord, 'KV Residence');
        $this->rental($landlord, $first, 'A-1', 'Dara');
        $late = $this->rental($landlord, $second, 'B-7', 'Bopha');
        $this->invoice($late, 'INV-B', 300, InvoiceStatus::Overdue, Carbon::parse('2026-09-01'));
        $stranger = $this->landlord();
        $this->rental($stranger, $this->property($stranger, 'Theirs'), 'Z-9', 'Zed');

        $this->signIn($landlord, $first);

        Livewire::test(Home::class)
            ->assertSet('scope', 'all')
            ->assertViewHas('rooms', fn ($rooms) => $rooms->pluck('room_number')->all() === ['B-7', 'A-1']) // owing room first, across properties
            ->assertSee('KV Residence')
            ->assertSee('Son Sok Place')
            ->assertDontSee('Z-9')
            ->assertDontSee('Theirs');
    }

    public function test_choosing_a_property_filters_the_rooms_and_show_all_clears_it(): void
    {
        $landlord = $this->landlord();
        $first = $this->property($landlord, 'Son Sok Place');
        $second = $this->property($landlord, 'KV Residence');
        $this->rental($landlord, $first, 'A-1', 'Dara');
        $this->rental($landlord, $second, 'B-7', 'Bopha');

        $this->signIn($landlord, $first);

        Livewire::test(Home::class, ['view' => 'properties'])
            ->call('selectProperty', $second->id)
            ->assertSet('scope', 'property')
            ->assertViewHas('rooms', fn ($rooms) => $rooms->pluck('room_number')->all() === ['B-7'])
            ->assertSeeHtml('id="m2-show-all"')
            ->call('showAllRooms')
            ->assertSet('scope', 'all')
            ->assertViewHas('rooms', fn ($rooms) => $rooms->count() === 2);
    }

    public function test_an_unknown_view_falls_back_to_rooms(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(Home::class, ['view' => 'nonsense', 'scope' => 'x'])->assertSet('view', 'rooms')->assertSet('scope', 'all');
    }
}
