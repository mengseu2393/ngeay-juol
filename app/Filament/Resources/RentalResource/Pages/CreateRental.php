<?php

namespace App\Filament\Resources\RentalResource\Pages;

use App\Filament\Resources\RentalResource;
use App\Models\Rental;
use App\Models\Unit;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRental extends CreateRecord
{
    protected static string $resource = RentalResource::class;

    /**
     * Create rental without creating a login account.
     * Login accounts are room-based (created when unit is created), not tenant-based.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $unit = Unit::find($data['unit_id'] ?? null);
        $data['property_id'] = $unit?->property_id;
        $data['landlord_id'] = $unit?->landlord_id;

        $rental = Rental::create($data);

        Notification::make()
            ->title(__('Tenant created'))
            ->body(__('Occupant').': **'.$rental->occupant_name.'**')
            ->success()->send();

        return $rental;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
