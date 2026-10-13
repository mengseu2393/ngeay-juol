<?php

namespace App\Livewire\Mobile;

use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m placeholder — replaced when this screen is ported.
 */
#[Layout('mobile.layout')]
class UtilityRates extends Component
{
    public function render()
    {
        return view('livewire.mobile.coming-soon')->title(__('Utility Rates'));
    }
}
