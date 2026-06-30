<?php

namespace App\Filament\Resources\LandlordResource\Pages;

use App\Enums\UserStatus;
use App\Filament\Resources\LandlordResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLandlord extends CreateRecord
{
    protected static string $resource = LandlordResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_id'] = auth()->id();
        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->forceFill([
            'status' => $this->data['status'] ?? UserStatus::Active,
            'created_by_id' => auth()->id(),
        ])->save();

        $this->record->assignRole('landlord');
    }
}
