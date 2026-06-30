<?php

namespace App\Filament\Resources\UnitResource\Pages;

use App\Filament\Resources\UnitResource;
use App\Support\ActiveProperty;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateUnit extends CreateRecord
{
    protected static string $resource = UnitResource::class;

    /** Hidden defaulted property_id doesn't dehydrate reliably — inject it here. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['property_id'])) {
            $data['property_id'] = ActiveProperty::id();
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
