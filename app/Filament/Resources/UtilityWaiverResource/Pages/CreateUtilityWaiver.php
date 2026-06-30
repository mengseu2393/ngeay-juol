<?php

namespace App\Filament\Resources\UtilityWaiverResource\Pages;

use App\Filament\Resources\UtilityWaiverResource;
use App\Support\ActiveProperty;
use Filament\Resources\Pages\CreateRecord;

class CreateUtilityWaiver extends CreateRecord
{
    protected static string $resource = UtilityWaiverResource::class;

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
