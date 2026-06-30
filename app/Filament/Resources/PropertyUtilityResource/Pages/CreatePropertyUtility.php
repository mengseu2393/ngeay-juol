<?php

namespace App\Filament\Resources\PropertyUtilityResource\Pages;

use App\Filament\Resources\PropertyUtilityResource;
use App\Support\ActiveProperty;
use Filament\Resources\Pages\CreateRecord;

class CreatePropertyUtility extends CreateRecord
{
    protected static string $resource = PropertyUtilityResource::class;

    /**
     * The form's property_id is a hidden, defaulted field whenever a property is
     * active — but hidden defaults don't reliably dehydrate, so inject the active
     * property here. When no property is active the field is shown + required, so
     * the submitted value is kept as-is.
     */
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
