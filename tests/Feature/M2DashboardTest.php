<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\UnitStatus;
use App\Livewire\M2\Dashboard;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * /m2 Dashboard (sketch 3): Revenue by date, Due, Occupancy, History by room.
 * Pinned: each tab's numbers, that a date range is always real and ordered,
 * that nothing from another landlord or property leaks in, and that a tampered
 * room / kind falls back instead of querying someone else's id.
 */
class M2DashboardTest extends M2TestCase
{
    public function test_income_lists_the_ranges_payments_newest_first_with_who_paid(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $a1 = $this->rental($landlord, $property, 'A-1', 'Dara');
        $a2 = $this->rental($landlord, $property, 'A-2', 'Sokha');
        $this->pay($this->invoice($a1, 'INV-1'), 100, '2026-10-02');
        $this->pay($this->invoice($a1, 'INV-2', issued: Carbon::parse('2026-09-01')), 50, '2026-10-09');
        $this->pay($this->invoice($a2, 'INV-3'), 200, '2026-10-05');
        $this->pay($this->invoice($a2, 'INV-4', issued: Carbon::parse('2026-08-01')), 999, '2026-09-20'); // last month

        $this->signIn($landlord, $property);

        Livewire::test(Dashboard::class)
            ->assertViewHas('data', fn ($data) => $data['total'] === 350.0
                && $data['count'] === 3
                && $data['payments']->pluck('amount')->all() === [50.0, 200.0, 100.0]
                && $data['payments']->pluck('room')->all() === ['A-1', 'A-2', 'A-1']
                && $data['payments']->pluck('tenant')->all() === ['Dara', 'Sokha', 'Dara'])
            ->assertSee('$350.00')
            ->assertSee('Sokha · A-2')
            ->call('setRange', 'last')
            ->assertSee('$999.00')
            ->assertDontSee('$350.00');
    }

    /**
     * The "+12%" chip compares with the stretch of the same length just before the range,
     * and is left out when that stretch took no money — there is no percentage of nothing.
     */
    public function test_income_shows_the_change_against_the_previous_period_only_when_there_is_one(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->pay($this->invoice($rental, 'INV-1'), 100, '2026-09-10');
        $this->pay($this->invoice($rental, 'INV-2', issued: Carbon::parse('2026-09-01')), 150, '2026-10-10');

        $this->signIn($landlord, $property);

        Livewire::test(Dashboard::class)
            ->assertViewHas('data', fn ($data) => $data['trend'] === 50)
            ->assertSeeHtml('id="m2-revenue-trend"')
            ->assertSee('+50%')
            ->call('setRange', 'last')
            ->assertViewHas('data', fn ($data) => $data['trend'] === null)
            ->assertDontSeeHtml('id="m2-revenue-trend"');
    }

    public function test_revenue_never_includes_another_landlords_or_propertys_payments(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $mine = $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->pay($this->invoice($mine, 'INV-1'), 100, '2026-10-02');

        $otherProperty = $this->property($landlord, 'Other');
        $this->pay($this->invoice($this->rental($landlord, $otherProperty, 'B-1', 'Bopha'), 'INV-B'), 70, '2026-10-02');

        $stranger = $this->landlord();
        $this->pay($this->invoice($this->rental($stranger, $this->property($stranger, 'Theirs'), 'Z-1', 'Zed'), 'INV-Z'), 5000, '2026-10-02');

        $this->signIn($landlord, $property);

        Livewire::test(Dashboard::class)
            ->assertViewHas('data', fn ($data) => $data['total'] === 100.0 && $data['count'] === 1);
    }

    public function test_due_lists_the_longest_waiting_room_first_with_its_total(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $late = $this->rental($landlord, $property, 'A-1', 'Dara');
        $soon = $this->rental($landlord, $property, 'A-2', 'Sokha');
        $this->invoice($soon, 'INV-S', 100, InvoiceStatus::Pending, Carbon::parse('2026-10-10'));
        $this->invoice($late, 'INV-L1', 300, InvoiceStatus::Overdue, Carbon::parse('2026-09-01'));
        $this->invoice($late, 'INV-L2', 50, InvoiceStatus::Pending, Carbon::parse('2026-09-20'));
        $this->invoice($soon, 'INV-P', 999, InvoiceStatus::Paid);

        $this->signIn($landlord, $property);

        Livewire::test(Dashboard::class, ['tab' => 'due'])
            ->assertViewHas('data', fn ($data) => $data['total'] === 450.0
                && $data['overdueRooms'] === 1
                && $data['rows']->pluck('room')->all() === ['A-1', 'A-2']
                && $data['rows']->first()['daysLate'] === 35
                && $data['rows']->first()['invoices'] === 2)
            ->assertSee('$450.00');
    }

    public function test_due_says_so_when_nobody_owes(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->invoice($this->rental($landlord, $property, 'A-1', 'Dara'), 'INV-1', 300, InvoiceStatus::Paid);

        $this->signIn($landlord, $property);

        Livewire::test(Dashboard::class, ['tab' => 'due'])->assertSeeHtml('id="m2-due-empty"');
    }

    public function test_occupancy_counts_rooms_by_status(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->rental($landlord, $property, 'A-2', 'Sokha');
        $vacant = $this->rental($landlord, $property, 'A-3', 'Vanna')->unit;
        $vacant->update(['status' => UnitStatus::Available]);
        $repair = $this->rental($landlord, $property, 'A-4', 'Veasna')->unit;
        $repair->update(['status' => UnitStatus::Maintenance]);

        $this->signIn($landlord, $property);

        Livewire::test(Dashboard::class, ['tab' => 'occupancy'])
            ->assertViewHas('data', fn ($data) => $data['total'] === 4
                && $data['occupied'] === 2
                && $data['available'] === 1
                && $data['other'] === 1
                && $data['percent'] === 50);
    }

    public function test_history_lists_readings_for_the_range_and_one_room(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $a1 = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $a2 = $this->rental($landlord, $property, 'A-2', 'Sokha')->unit;
        $electricity = $this->utility($property, 'Electricity');
        $this->reading($landlord, $a1, $electricity, '2026-10-03', 110, 10);
        $this->reading($landlord, $a1, $electricity, '2026-10-10', 135, 25);
        $this->reading($landlord, $a2, $electricity, '2026-10-04', 220, 20);
        $this->reading($landlord, $a2, $electricity, '2026-08-04', 150, 99); // out of range

        $this->signIn($landlord, $property);

        Livewire::test(Dashboard::class, ['tab' => 'history'])
            ->assertViewHas('historyKind', (string) $electricity->id)
            ->assertViewHas('data', fn ($data) => $data['count'] === 3 && $data['total'] === 55.0)
            ->set('room', (string) $a1->id)
            ->assertViewHas('data', fn ($data) => $data['count'] === 2 && $data['total'] === 35.0);
    }

    public function test_history_can_show_bills_instead_of_a_meter(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->invoice($rental, 'INV-OCT', 300);
        $this->invoice($rental, 'INV-SEP', 280, issued: Carbon::parse('2026-09-05'));

        $this->signIn($landlord, $property);

        Livewire::test(Dashboard::class, ['tab' => 'history', 'kind' => 'bill'])
            ->assertViewHas('data', fn ($data) => $data['kind'] === 'bill' && $data['count'] === 1 && $data['total'] === 300.0)
            ->assertSee('INV-OCT')
            ->assertDontSee('INV-SEP')
            ->call('setRange', 'quarter')
            ->assertViewHas('data', fn ($data) => $data['count'] === 2 && $data['total'] === 580.0);
    }

    public function test_a_tampered_room_or_kind_falls_back_instead_of_reading_someone_elses_data(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $mine = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $electricity = $this->utility($property, 'Electricity');
        $this->reading($landlord, $mine, $electricity, '2026-10-03', 110, 10);

        $stranger = $this->landlord();
        $theirProperty = $this->property($stranger, 'Theirs');
        $theirUnit = $this->rental($stranger, $theirProperty, 'Z-1', 'Zed')->unit;
        $theirMeter = $this->utility($theirProperty, 'Electricity');
        $this->reading($stranger, $theirUnit, $theirMeter, '2026-10-03', 900, 800);

        $this->signIn($landlord, $property);

        Livewire::test(Dashboard::class, ['tab' => 'history', 'room' => (string) $theirUnit->id, 'kind' => (string) $theirMeter->id])
            ->assertViewHas('roomId', null)
            ->assertViewHas('historyKind', (string) $electricity->id)
            ->assertViewHas('data', fn ($data) => $data['total'] === 10.0);
    }

    public function test_the_date_range_is_always_real_ordered_and_bounded(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(Dashboard::class, ['from' => 'garbage', 'to' => '2026-02-31'])
            ->assertSet('from', '2026-10-01')
            ->assertSet('to', '2026-10-31')
            ->set('from', '2026-11-20')
            ->assertSet('from', '2026-11-20')
            ->assertSet('to', '2026-11-20') // 'to' follows when 'from' is moved past it
            ->set('to', '2026-11-01')
            ->assertSet('from', '2026-11-01') // 'from' follows when 'to' is moved before it
            ->set('from', '2020-01-01')
            ->assertSet('to', '2021-01-01'); // a range over a year long is pulled in
    }

    public function test_an_unknown_tab_falls_back_to_revenue(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(Dashboard::class, ['tab' => 'nonsense'])->assertSet('tab', 'revenue');
    }

    private function pay($invoice, float $amount, string $date): void
    {
        $invoice->recordPayment([
            'amount' => $amount,
            'currency' => 'USD',
            'paid_at' => Carbon::parse($date.' 10:00'),
            'recorded_by_id' => $invoice->landlord_id,
        ]);
    }
}
