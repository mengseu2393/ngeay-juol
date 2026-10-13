<?php

namespace App\Livewire\Mobile;

use App\Livewire\UnitReadingHistory;

/**
 * One room's meter-reading history inside the /m Utility Rates screen's
 * history sheet. Same component as the panel's and Simple Mode's (scoping,
 * two-step confirm-before-save correction, UtilityReadingCorrectionService
 * maths) — only the view is the phone one, and a correction ends in a toast.
 */
class UtilityRatesReadingHistory extends UnitReadingHistory
{
    public function save(): void
    {
        parent::save();

        if ($this->saved) {
            $this->dispatch('rw-m-toast', message: __('Reading corrected.'));
        }
    }

    public function render()
    {
        return view('livewire.mobile.utility-rates-reading-history', parent::render()->getData());
    }
}
