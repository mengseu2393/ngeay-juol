<?php

namespace App\Livewire\Mobile;

use App\Livewire\SimpleUtilityRates;
use Livewire\Attributes\Layout;

/**
 * /m/settings/utilities — the property's utility rates on a phone. The same
 * component as Simple Mode's (filter, search, one-shot save / toggle / delete,
 * reading history — all scoped to the active property, all its events), in a
 * phone layout: tap a card to edit it in a bottom sheet, and a toast instead of
 * the inline success banner.
 */
#[Layout('mobile.layout')]
class UtilityRates extends SimpleUtilityRates
{
    public function save(?int $id, array $data): void
    {
        parent::save($id, $data);
        $this->toast();
    }

    public function toggleActive(int $id): void
    {
        parent::toggleActive($id);
        $this->toast();
        $this->dispatch('utility-rate-toggled');
    }

    public function delete(int $id): void
    {
        parent::delete($id);
        $this->toast();
    }

    /** Reopening the form starts clean (called only when old errors are on screen). */
    public function clearFormErrors(): void
    {
        $this->resetErrorBag();
    }

    private function toast(): void
    {
        if ($this->successMessage) {
            $this->dispatch('rw-m-toast', message: $this->successMessage);
        }
    }

    public function render()
    {
        return view('livewire.mobile.utility-rates', $this->utilityRatesData())
            ->title(__('Utility Rates'));
    }
}
