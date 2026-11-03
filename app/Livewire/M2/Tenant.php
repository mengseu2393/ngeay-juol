<?php

namespace App\Livewire\M2;

use App\Enums\OccupantRole;
use App\Enums\RentalStatus;
use App\Livewire\Concerns\EndsTenancy;
use App\Models\Rental;
use App\Models\Unit;
use App\Support\M2Shell;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /m2/tenants/{rental} — sketch ⑤: the tenant's picture and name, then name,
 * phone, gender, date of birth and the ID card (number + photos). The sketch's
 * "place of birth" has no column, so the tenant's address and nationality stand
 * in for it. The {rental} binding runs through the landlord scope.
 *
 * "End tenancy" lives here for the room's current tenant: a small confirm card
 * (end date, how it ended, free the room) that runs the same rules as the /m
 * shell and Simple Mode ({@see EndsTenancy}).
 */
#[Layout('m2.layout')]
class Tenant extends Component
{
    use EndsTenancy {
        submit as private endTenancyNow;
    }

    public Rental $rental;

    /** The room of this tenancy; fixed by the URL's rental. */
    #[Locked]
    public ?int $unitId = null;

    /** 'view' | 'done' */
    #[Locked]
    public string $step = 'view';

    public bool $confirming = false;

    public function mount(Rental $rental): void
    {
        $this->rental = $rental;
        $this->unitId = $rental->unit_id;
        $this->endDate = now()->toDateString();
        $this->status = (string) RentalStatus::Vacated->value;
        $this->freeRoom = true;
    }

    public function openEnd(): void
    {
        abort_unless($this->canEnd(), 403);

        $this->confirming = true;
    }

    public function cancelEnd(): void
    {
        $this->confirming = false;
        $this->resetErrorBag();
    }

    public function submit(): void
    {
        abort_unless($this->canEnd(), 403);

        // An unknown outcome is a validation error here, not a RentalStatus::from() crash.
        $this->validate(['status' => 'required|in:'.implode(',', array_keys($this->endOutcomes()))]);

        $this->endTenancyNow();

        if ($this->step === 'done') {
            $this->confirming = false;
        }
    }

    /** Only the room's current, active tenancy can be ended, by someone allowed to edit it. */
    private function canEnd(): bool
    {
        $rental = $this->rental->fresh();

        return $rental
            && $rental->status === RentalStatus::Active
            && $this->step === 'view'
            && (bool) Auth::user()?->can('update', $rental);
    }

    /** The room from the URL's rental — never the active property's pick. */
    protected function loadUnit(?int $id): ?Unit
    {
        return $id ? Unit::query()->with('activeRental.tenant')->find($id) : null;
    }

    public function render()
    {
        $rental = $this->rental->fresh(['unit', 'tenant', 'occupants']) ?? $this->rental->load('unit', 'tenant', 'occupants');

        return view('livewire.m2.tenant', [
            'rental' => $rental,
            'genders' => ['male' => __('Male'), 'female' => __('Female'), 'other' => __('Other')],
            'photos' => $rental->getMedia('id_cards'),
            'coOccupants' => $rental->occupants->reject(fn ($o) => $o->role === OccupantRole::Primary),
            'avatar' => M2Shell::mediaUrl($rental->tenant?->getFirstMediaUrl('avatar')),
            'canEnd' => $this->canEnd(),
            'outcomes' => $this->endOutcomes(),
        ])->title($rental->occupant_name ?: __('Tenant'));
    }
}
