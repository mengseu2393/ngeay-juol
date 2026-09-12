<?php

namespace App\Filament\Resources\UtilityUsageResource\Pages;

use App\Filament\Resources\UtilityUsageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUtilityUsage extends CreateRecord
{
    protected static string $resource = UtilityUsageResource::class;

    /** Deep-linked from simple mode's "Set utility reading" button (?unit_id=). */
    public function mount(): void
    {
        parent::mount();

        if ($unitId = request()->query('unit_id')) {
            $this->form->fill(['unit_id' => (int) $unitId, 'old_reading' => 0]);
        }
    }
}
