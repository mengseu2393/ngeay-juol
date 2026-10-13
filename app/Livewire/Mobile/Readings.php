<?php

namespace App\Livewire\Mobile;

use App\Livewire\SimpleUtilityUsage;
use App\Models\UtilityUsage;
use App\Services\MeterUtilities;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;

/**
 * /m/readings — this month's meter readings on a phone. The same component as
 * Simple Mode's (SimpleUtilityUsage: room scoping, utility whitelisting, real
 * consumption via MeterReadingResolver, same-day idempotency, the ?unit_id=
 * deep link and its tenancy-started / tenancy-ended listeners), plus what the
 * phone list needs: how many of each room's meters still have no reading this
 * month, so the rooms left to do are the ones shown first.
 */
#[Layout('mobile.layout')]
class Readings extends SimpleUtilityUsage
{
    public function submitUtilityReading(): void
    {
        parent::submitUtilityReading();

        if ($this->readingSuccess) {
            $this->dispatch('rw-m-sheet-close', name: 'reading');
            $this->dispatch('rw-m-toast', message: __('Meter reading saved.'));
        }
    }

    /**
     * [unit_id => meters without a reading dated this month]. A property's
     * metered utilities are the same for every room (MeterUtilities::forUnit
     * only looks at property_id), so that is one query, plus one for the month's
     * readings — not one per room.
     *
     * @return array<int, int>
     */
    private function pendingByRoom(Collection $rooms, Collection $metered): array
    {
        if ($rooms->isEmpty() || $metered->isEmpty()) {
            return [];
        }

        $done = UtilityUsage::query()
            ->whereIn('unit_id', $rooms->modelKeys())
            ->whereIn('property_utility_id', $metered->modelKeys())
            ->whereBetween('reading_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->get(['unit_id', 'property_utility_id'])
            ->groupBy('unit_id')
            ->map(fn (Collection $rows) => $rows->pluck('property_utility_id')->unique()->count());

        return $rooms
            ->mapWithKeys(fn ($room) => [$room->getKey() => $metered->count() - (int) $done->get($room->getKey(), 0)])
            ->all();
    }

    public function render()
    {
        $data = $this->utilityUsageData();
        $metered = $data['rooms']->isNotEmpty() ? MeterUtilities::forUnit($data['rooms']->first()) : collect();
        $pending = $this->pendingByRoom($data['rooms'], $metered);

        return view('livewire.mobile.readings', $data + [
            'propertyMeters' => $metered,
            'pending' => $pending,
            'dueCount' => count(array_filter($pending)),
            'recordingUnit' => $this->recordingUnitId ? $data['rooms']->firstWhere('id', $this->recordingUnitId) : null,
        ])->title(__('Meter readings'));
    }
}
