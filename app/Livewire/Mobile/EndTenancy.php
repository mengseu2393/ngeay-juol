<?php

namespace App\Livewire\Mobile;

use App\Enums\RentalStatus;
use App\Livewire\Concerns\EndsTenancy;
use App\Models\Unit;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /m/rooms/{unit}/end — end this room's tenancy: the end date, how it ended
 * and whether the room is free again. Same form and rules as Simple Mode's
 * end-tenancy flow ({@see EndsTenancy}); the room comes from the URL instead
 * of a picker.
 */
#[Layout('mobile.layout')]
class EndTenancy extends Component
{
    use EndsTenancy {
        submit as private endTenancyNow;
    }

    /** 'confirm' | 'done' */
    #[Locked]
    public string $step = 'confirm';

    /** Fixed by the URL; the binding already 404s for another landlord's room. */
    #[Locked]
    public ?int $unitId = null;

    public function mount(Unit $unit): void
    {
        $this->unitId = $unit->getKey();
        $this->endDate = now()->toDateString();
        $this->status = (string) RentalStatus::Vacated->value;
        $this->freeRoom = true;
    }

    public function submit(): void
    {
        abort_unless($this->step === 'confirm', 404);

        // Ended in the meantime: the service says so ("no active tenancy").
        $rental = $this->loadUnit($this->unitId)?->activeRental;
        abort_if($rental && ! Auth::user()?->can('update', $rental), 403);

        // An unknown outcome is a validation error here, not a RentalStatus::from() crash.
        $this->validate(['status' => 'required|in:'.implode(',', array_keys($this->endOutcomes()))]);

        $this->endTenancyNow();
    }

    /** The room from the URL — never the active property's pick. */
    protected function loadUnit(?int $id): ?Unit
    {
        return $id ? Unit::query()->with('activeRental.tenant')->find($id) : null;
    }

    public function render()
    {
        $unit = Unit::query()->with(['property', 'activeRental.tenant'])->findOrFail($this->unitId);
        $rental = $unit->activeRental;

        return view('livewire.mobile.end-tenancy', [
            'unit' => $unit,
            'rental' => $rental,
            'available' => $rental && Auth::user()?->can('update', $rental),
            'outcomes' => $this->endOutcomes(),
        ])->title(__('End tenancy'));
    }
}
