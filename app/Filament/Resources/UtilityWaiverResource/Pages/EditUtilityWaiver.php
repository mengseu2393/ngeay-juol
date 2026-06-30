<?php

namespace App\Filament\Resources\UtilityWaiverResource\Pages;

use App\Filament\Resources\UtilityWaiverResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUtilityWaiver extends EditRecord
{
    protected static string $resource = UtilityWaiverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
