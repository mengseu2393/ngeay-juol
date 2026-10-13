<?php

namespace App\Livewire\Mobile;

use App\Models\Rental;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m placeholder — replaced when this screen is ported.
 */
#[Layout('mobile.layout')]
class Tenant extends Component
{
    public Rental $rental;

    public function mount(Rental $rental): void
    {
        $this->rental = $rental;
    }

    public function render()
    {
        return view('livewire.mobile.coming-soon')->title(__('Tenant'));
    }
}
