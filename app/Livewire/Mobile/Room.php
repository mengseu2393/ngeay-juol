<?php

namespace App\Livewire\Mobile;

use App\Models\Unit;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m placeholder — replaced when this screen is ported.
 */
#[Layout('mobile.layout')]
class Room extends Component
{
    public Unit $unit;

    public function mount(Unit $unit): void
    {
        $this->unit = $unit;
    }

    public function render()
    {
        return view('livewire.mobile.coming-soon')->title(__('Room'));
    }
}
