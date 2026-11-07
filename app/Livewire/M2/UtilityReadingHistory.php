<?php

namespace App\Livewire\M2;

use App\Livewire\Mobile\UtilityRatesReadingHistory;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Support\ActiveProperty;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;

/**
 * /m2/settings/utilities/history — one room's meter readings for one utility,
 * picked with two selects, and the two-step confirm-before-save correction of
 * a reading. Everything but the room picker is UnitReadingHistory's (scoping,
 * UtilityReadingCorrectionService maths, policies) via /m's subclass.
 * ?room= and ?utility= open a given room / utility.
 */
#[Layout('m2.layout')]
class UtilityReadingHistory extends UtilityRatesReadingHistory
{
    public function mount(?int $unitId = null, ?int $utilityId = null): void
    {
        abort_unless(auth()->user()?->can('viewAny', UtilityUsage::class), 403);

        $rooms = $this->rooms();
        $unitId ??= (int) request()->query('room') ?: null;
        $utilityId ??= (int) request()->query('utility') ?: null;
        $unitId = $rooms->contains('id', $unitId) ? $unitId : $rooms->first()?->getKey();

        if (! $unitId) {
            $this->unitId = 0; // no rooms yet: the screen says so

            return;
        }

        parent::mount($unitId, $utilityId);
    }

    public function selectUnit(int $unitId): void
    {
        abort_unless($this->rooms()->contains('id', $unitId), 404);

        $this->unitId = $unitId;
        $this->cancelEdit();
        $this->saved = false;
        $this->limit = self::PER_PAGE;

        // Keep the same utility when this room has it too.
        if (! $this->utilities()->has($this->utilityId)) {
            $this->utilityId = $this->utilities()->first()?->getKey();
        }
    }

    /** @return Collection<int, Unit> */
    private function rooms(): Collection
    {
        return ActiveProperty::id()
            ? Unit::query()->where('property_id', ActiveProperty::id())->orderBy('room_number')->get(['id', 'room_number'])
            : collect();
    }

    public function render()
    {
        return view('livewire.m2.utility-reading-history', parent::render()->getData() + [
            'rooms' => $this->rooms(),
        ])->title(__('Reading history'));
    }
}
