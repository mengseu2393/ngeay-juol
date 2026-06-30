<?php

namespace App\Filament\Resources\PropertyUtilityResource\Pages;

use App\Filament\Resources\PropertyUtilityResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPropertyUtilities extends ListRecords
{
    protected static string $resource = PropertyUtilityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label(__('Add utility')),
        ];
    }
}
