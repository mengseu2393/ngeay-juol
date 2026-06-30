<?php

namespace App\Filament\Resources\UtilityWaiverResource\Pages;

use App\Filament\Resources\UtilityWaiverResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUtilityWaivers extends ListRecords
{
    protected static string $resource = UtilityWaiverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('Add waiver')),
        ];
    }
}
