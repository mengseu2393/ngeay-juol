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

        // Auto-create the primary occupant record from the rental's occupant fields.
        if (! empty($data['occupant_name'])) {
            $rental->occupants()->create([
                'role'                           => 'primary',
                'user_id'                        => $rental->tenant_id,
                'occupant_name'                  => $data['occupant_name'],
                'occupant_phone'                 => $data['occupant_phone'] ?? null,
                'occupant_id_card'               => $data['occupant_id_card'] ?? null,
                'occupant_address'               => $data['occupant_address'] ?? null,
                'occupant_gender'                => $data['occupant_gender'] ?? null,
                'occupant_dob'                   => $data['occupant_dob'] ?? null,
                'occupant_nationality'           => $data['occupant_nationality'] ?? null,
                'occupant_workplace'             => $data['occupant_workplace'] ?? null,
                'emergency_contact_name'         => $data['emergency_contact_name'] ?? null,
                'emergency_contact_phone'        => $data['emergency_contact_phone'] ?? null,
                'emergency_contact_relationship' => $data['emergency_contact_relationship'] ?? null,
                'guarantor_name'                 => $data['guarantor_name'] ?? null,
                'guarantor_phone'                => $data['guarantor_phone'] ?? null,
                'guarantor_id_number'            => $data['guarantor_id_number'] ?? null,
                'guarantor_address'              => $data['guarantor_address'] ?? null,
            ]);
        }

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
