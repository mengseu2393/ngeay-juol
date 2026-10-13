<?php

namespace App\Livewire\Mobile;

use App\Livewire\Concerns\ManagesUnit;
use App\Models\Unit;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m/rooms/{unit} — one room start to finish: its agreement, its meters and
 * their history. Same behaviour as Simple Mode's room screen and the panel's
 * room page ({@see ManagesUnit}); here the reading, reset and correction forms
 * open in bottom sheets and a finished action is a toast instead of a banner.
 * Moving a tenant in or out is its own screen (Add tenant / End tenancy).
 */
#[Layout('mobile.layout')]
class Room extends Component
{
    use ManagesUnit {
        saveReading as private saveReadingNow;
        resetReading as private resetReadingNow;
        saveEdit as private saveEditNow;
    }

    /** The binding already 404s for another landlord's room (LandlordScope). */
    public function mount(Unit $unit): void
    {
        $this->mountUnit($unit->getKey());
    }

    public function saveReading(): void
    {
        $this->saveReadingNow();
        $this->announce('reading');
    }

    public function resetReading(): void
    {
        $this->resetReadingNow();
        $this->announce('reset');
    }

    public function saveEdit(): void
    {
        $this->saveEditNow();
        $this->announce('correct');
    }

    /** A finished action closes its sheet and shows its message once. */
    private function announce(string $sheet): void
    {
        if ($this->notice === null) {
            return;
        }

        $this->dispatch('rw-m-sheet-close', name: $sheet);
        $this->dispatch('rw-m-toast', message: $this->notice);
        $this->notice = null;
    }

    public function render()
    {
        $data = $this->unitViewData();

        return view('livewire.mobile.room', $data)
            ->title(__('Room :room', ['room' => $data['unit']->room_number]));
    }
}
