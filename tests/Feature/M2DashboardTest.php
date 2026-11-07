<?php

namespace Tests\Feature;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Livewire\M2\Dashboard;
use App\Livewire\M2\DashboardIncome;
use App\Livewire\M2\DashboardOccupancy;
use App\Livewire\M2\DashboardProperty;
use App\Livewire\M2\DashboardRoom;
use Carbon\Carbon;
use Livewire\Livewire;

/**
 * The /m2 dashboard, in Home's list style: Dashboard (properties + this month's
 * numbers) → DashboardProperty (its rooms) → DashboardRoom (readings and bills by
 * month), plus DashboardIncome and DashboardOccupancy (six months each).
 *
 * Pinned: each screen's numbers, that months group newest-first with zero months
 * kept, that nothing from another landlord or property leaks in, and that a tampered
 * property / kind falls back instead of reading someone else's id.
 */
class M2DashboardTest extends M2TestCase
{
    public function test_dashboard_lists_properties_with_this_months_income_and_the_summary(): void
    {
        $landlord = $this->landlord();
        $sunrise = $this->property($landlord, 'Sunrise');
        $river = $this->property($landlord, 'River');
        $a1 = $this->rental($landlord, $sunrise, 'A-1', 'Dara');
        $this->rental($landlord, $river, 'B-1', 'Bopha')->unit->update(['status' => UnitStatus::Available]);
        $this->pay($this->invoice($a1, 'INV-1'), 100, '2026-10-02');
        $this->pay($this->invoice($a1, 'INV-2', issued: Carbon::parse('2026-09-01')), 50, '2026-10-09');
        $this->pay($this->invoice($a1, 'INV-3', issued: Carbon::parse('2026-08-01')), 999, '2026-09-20'); // last month

        $this->signIn($landlord, $sunrise);

        Livewire::test(Dashboard::class)
            ->assertViewHas('income', 150.0)
            ->assertViewHas('bills', 1)
            ->assertViewHas('occupancy', 50)
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('property.name')->all() === ['River', 'Sunrise']
                && $rows->firstWhere('property.name', 'Sunrise')['income'] === 150.0
                && $rows->firstWhere('property.name', 'River')['income'] === 0.0)
            ->assertSee('m2-dash-property-'.$sunrise->id, false);
    }

    public function test_dashboard_shows_the_change_against_last_month_only_when_there_is_one(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->pay($this->invoice($rental, 'INV-1'), 150, '2026-10-02');

        $this->signIn($landlord, $property);

        Livewire::test(Dashboard::class)->assertViewHas('trend', null);

        $this->pay($this->invoice($rental, 'INV-2', issued: Carbon::parse('2026-09-01')), 100, '2026-09-20');

        Livewire::test(Dashboard::class)->assertViewHas('trend', 50);
    }

    public function test_dashboard_never_counts_another_landlords_payments_and_their_property_is_a_404(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->pay($this->invoice($this->rental($landlord, $property, 'A-1', 'Dara'), 'INV-1'), 100, '2026-10-02');

        $stranger = $this->landlord();
        $theirs = $this->property($stranger, 'Theirs');
        $zed = $this->rental($stranger, $theirs, 'Z-1', 'Zed');
        $this->pay($this->invoice($zed, 'INV-Z'), 5000, '2026-10-02');

        $this->signIn($landlord, $property);

        Livewire::test(Dashboard::class)
            ->assertViewHas('income', 100.0)
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 1);

        $this->get(route('m2.dashboard.property', $theirs))->assertNotFound();
        $this->get(route('m2.dashboard.room', $zed->unit_id))->assertNotFound();
    }

    public function test_a_property_lists_rooms_that_owe_first_with_the_total_owed(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $paidUp = $this->rental($landlord, $property, 'A-1', 'Dara');
        $owes = $this->rental($landlord, $property, 'A-2', 'Sokha');
        $vacant = $this->rental($landlord, $property, 'A-3', 'Vanna')->unit;
        $vacant->update(['status' => UnitStatus::Available]);
        $this->pay($this->invoice($paidUp, 'INV-1', 100), 100, '2026-10-02');
        $this->invoice($owes, 'INV-2', 300, issued: Carbon::parse('2026-09-01'));

        $this->signIn($landlord, $property);

        Livewire::test(DashboardProperty::class, ['property' => $property])
            ->assertViewHas('rooms', fn ($rooms) => $rooms->pluck('room_number')->all() === ['A-2', 'A-1', 'A-3'])
            ->assertViewHas('owed', 300.0)
            ->assertViewHas('late', 1)
            ->assertSee('m2-dash-room-'.$owes->unit_id, false);
    }

    public function test_a_rooms_history_groups_readings_and_bills_by_month_newest_first(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $electricity = $this->utility($property, 'Electricity');
        $this->reading($landlord, $rental->unit, $electricity, '2026-09-04', 110, 10);
        $this->reading($landlord, $rental->unit, $electricity, '2026-10-04', 130, 20);
        $this->reading($landlord, $rental->unit, $electricity, '2026-10-12', 150, 20);
        $this->pay($this->invoice($rental, 'INV-OCT', 300), 100, '2026-10-05');
        $this->invoice($rental, 'INV-SEP', 280, issued: Carbon::parse('2026-09-05'));
        $this->reading($landlord, $rental->unit, $electricity, '2025-01-04', 10, 10); // outside the six months

        $this->signIn($landlord, $property);

        Livewire::test(DashboardRoom::class, ['unit' => $rental->unit])
            ->assertViewHas('history', fn ($history) => $history->keys()->all() === ['2026-10', '2026-09']
                && $history['2026-10']['items']->count() === 3
                && $history['2026-10']['billed'] === 300.0
                && $history['2026-10']['paid'] === 100.0
                && $history['2026-09']['billed'] === 280.0)
            ->assertSee('INV-OCT')
            ->set('kind', 'bill')
            ->assertViewHas('history', fn ($history) => $history['2026-10']['items']->count() === 1)
            ->set('kind', (string) $electricity->id)
            ->assertViewHas('history', fn ($history) => $history['2026-10']['items']->count() === 2 && $history['2026-10']['billed'] === 0.0)
            ->assertDontSee('INV-OCT')
            ->set('kind', '999999')
            ->assertViewHas('historyKind', 'all')
            ->call('showEarlier')
            ->assertSet('months', 12)
            ->assertViewHas('history', fn ($history) => $history->has('2025-01') === false);
    }

    public function test_income_lists_six_months_newest_first_keeping_months_with_no_payment(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->pay($this->invoice($rental, 'INV-1'), 40, '2026-10-02');
        $this->pay($this->invoice($rental, 'INV-2'), 60, '2026-10-09');
        $this->pay($this->invoice($rental, 'INV-3', issued: Carbon::parse('2026-08-01')), 100, '2026-08-20');

        $this->signIn($landlord, $property);

        Livewire::test(DashboardIncome::class)
            ->assertViewHas('months', fn ($months) => $months->keys()->all() === ['2026-10', '2026-09', '2026-08', '2026-07', '2026-06', '2026-05']
                && $months['2026-10']['total'] === 100.0
                && $months['2026-10']['count'] === 2
                && $months['2026-09']['total'] === 0.0
                && $months['2026-08']['total'] === 100.0)
            ->assertViewHas('sum', 200.0)
            ->assertViewHas('trend', null);
    }

    public function test_income_can_be_narrowed_to_one_property_and_a_foreign_id_means_all(): void
    {
        $landlord = $this->landlord();
        $mine = $this->property($landlord, 'Mine');
        $other = $this->property($landlord, 'Other');
        $this->pay($this->invoice($this->rental($landlord, $mine, 'A-1', 'Dara'), 'INV-A'), 100, '2026-10-02');
        $this->pay($this->invoice($this->rental($landlord, $other, 'B-1', 'Bopha'), 'INV-B'), 70, '2026-10-02');

        $stranger = $this->landlord();
        $theirs = $this->property($stranger, 'Theirs');
        $this->pay($this->invoice($this->rental($stranger, $theirs, 'Z-1', 'Zed'), 'INV-Z'), 5000, '2026-10-02');

        $this->signIn($landlord, $mine);

        Livewire::test(DashboardIncome::class, ['property' => (string) $mine->id])->assertViewHas('sum', 100.0);
        Livewire::test(DashboardIncome::class)->assertViewHas('sum', 170.0);
        Livewire::test(DashboardIncome::class, ['property' => (string) $theirs->id])->assertViewHas('sum', 170.0);
    }

    /**
     * A tenancy counts in a month from its start until it moves out — so a room that left in
     * August still counts as let for August, and not from September on.
     */
    public function test_occupancy_shows_today_and_the_share_of_rooms_let_in_each_of_the_last_six_months(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $stays = $this->rental($landlord, $property, 'A-1', 'Dara');
        $leaves = $this->rental($landlord, $property, 'A-2', 'Sokha');
        $stays->update(['start_date' => '2026-01-01']);
        $leaves->update(['start_date' => '2026-01-01', 'status' => RentalStatus::Vacated, 'move_out_date' => '2026-08-15']);
        $leaves->unit->update(['status' => UnitStatus::Available]);
        $repair = $this->rental($landlord, $property, 'A-3', 'Vanna')->unit;
        $repair->update(['status' => UnitStatus::Maintenance]);

        $this->signIn($landlord, $property);

        // Newest first: Oct (A-1 + A-3, which moved in on the 1st), Sep (only A-1), then Aug back to May (A-1 + A-2).
        Livewire::test(DashboardOccupancy::class)
            ->assertViewHas('total', 3)
            ->assertViewHas('occupied', 1)
            ->assertViewHas('percent', 33)
            ->assertViewHas('months', fn ($months) => $months->pluck('occupied')->all() === [2, 1, 2, 2, 2, 2]
                && $months->pluck('percent')->all() === [67, 33, 67, 67, 67, 67])
            ->assertViewHas('attention', fn ($rooms) => $rooms->pluck('room_number')->all() === ['A-2', 'A-3']);
    }

    public function test_the_occupancy_screen_is_empty_but_does_not_break_with_no_rooms(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(DashboardOccupancy::class)
            ->assertViewHas('total', 0)
            ->assertViewHas('percent', 0)
            ->assertSee(__('No rooms found.'));
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
