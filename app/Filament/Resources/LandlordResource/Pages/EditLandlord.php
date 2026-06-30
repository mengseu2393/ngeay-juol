<?php

namespace App\Filament\Resources\LandlordResource\Pages;

use App\Filament\Resources\LandlordResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLandlord extends EditRecord
{
    protected static string $resource = LandlordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        if (array_key_exists('status', $this->data)) {
            $this->record->forceFill(['status' => $this->data['status']])->save();
        }
    }
}
