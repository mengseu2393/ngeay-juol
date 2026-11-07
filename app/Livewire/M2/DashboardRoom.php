<?php

namespace App\Livewire\M2;

use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Services\M2DashboardService;
use App\Support\Money;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2/dashboard/rooms/{unit} — one room's meter readings and bills, grouped by month,
 * newest first. A pill picks "all", one meter, or only the bills; "Show earlier months"
 * reaches six months further back. The {unit} binding rides the landlord scope.
 */
#[Layout('m2.layout')]
class DashboardRoom extends Component
{
    public Unit $unit;

    protected $queryString = ['kind' => ['except' => 'all']];

    /** 'all', 'bill' or a property_utility id. */
    public string $kind = 'all';

    public int $months = M2DashboardService::MONTHS;

    public function showEarlier(): void
    {
        $this->months = min($this->months + M2DashboardService::MONTHS, 60);
    }

    public function render()
    {
        $meters = PropertyUtility::query()
            ->where('property_id', $this->unit->property_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (PropertyUtility $utility) => $utility->requiresReading())
            ->values();

        // A stale or tampered kind falls back instead of reading some other property's meter.
        $kind = $this->kind === 'all' || $this->kind === 'bill' || $meters->contains('id', (int) $this->kind) ? $this->kind : 'all';

        return view('livewire.m2.dashboard-room', [
            'room' => $this->unit->load('activeRental'),
            'property' => Property::query()->find($this->unit->property_id),
            'currency' => Money::forPropertyId($this->unit->property_id),
            'meters' => $meters,
            'historyKind' => $kind,
            'history' => M2DashboardService::roomHistory($this->unit, $kind, $this->months),
        ])->title(__('Room :room', ['room' => $this->unit->room_number]));
    }
}
