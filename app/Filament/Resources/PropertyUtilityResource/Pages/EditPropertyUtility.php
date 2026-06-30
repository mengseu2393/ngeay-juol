<?php

namespace App\Filament\Resources\PropertyUtilityResource\Pages;

use App\Filament\Resources\PropertyUtilityResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPropertyUtility extends EditRecord
{
    protected static string $resource = PropertyUtilityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
