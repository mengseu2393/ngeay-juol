<?php

namespace App\Livewire;

use App\Livewire\Concerns\ManagesUnit;
use Livewire\Component;

/**
 * Simple Mode's room screen (/app/simple?screen=unit&unit_id=N): the same room
 * page as the full-mode App\Filament\Pages\UnitOverview, inside the Simple Mode
 * shell. All behaviour is in {@see ManagesUnit}; the markup is the shared
 * `partials.unit-overview-body`.
 */
class SimpleUnitDetail extends Component
{
    use ManagesUnit;

    /** Deep-linked from a room card, like the add-tenant / end-tenancy screens. */
    public function mount(?int $unitId = null): void
    {
        $id = $unitId ?? (int) request()->query('unit_id');

        if ($id) {
            $this->mountUnit($id);
        }
    }

    public function render()
    {
        if (! isset($this->unitId)) {
            return view('livewire.simple-unit-detail', ['empty' => true]);
        }

        return view('livewire.simple-unit-detail', $this->unitViewData() + [
            'empty' => false,
            'simple' => true,
            'backUrl' => null,
        ]);
    }
}
