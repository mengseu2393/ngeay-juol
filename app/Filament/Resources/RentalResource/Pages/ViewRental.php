<?php

namespace App\Filament\Resources\RentalResource\Pages;

use App\Filament\Resources\RentalResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewRental extends ViewRecord
{
    protected static string $resource = RentalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
