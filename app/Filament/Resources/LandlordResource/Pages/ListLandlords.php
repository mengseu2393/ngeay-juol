<?php

namespace App\Filament\Resources\LandlordResource\Pages;

use App\Filament\Resources\LandlordResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLandlords extends ListRecords
{
    protected static string $resource = LandlordResource::class;

    protected function getHeaderActions(): array
    {
        // Filament authorizes this against LandlordResource::canCreate(), which is
        // super-admin-only — support sees the directory read-only.
        return [
            Actions\CreateAction::make()
                ->label(__('New Landlord')),
        ];
    }
}
