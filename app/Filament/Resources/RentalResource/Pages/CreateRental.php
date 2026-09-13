<?php

namespace App\Filament\Resources\RentalResource\Pages;

use App\Filament\Resources\RentalResource;
use App\Models\Rental;
use App\Models\Unit;
use App\Services\RoomAccountService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRental extends CreateRecord
{
    protected static string $resource = RentalResource::class;

    /** Whether this page was reached from a Simple Mode link (?from=simple). */
    public bool $fromSimpleMode = false;

    public function mount(): void
    {
        parent::mount();

        $this->fromSimpleMode = request()->query('from') === 'simple';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('backToSimpleMode')
                ->label(__('Back to Simple Mode'))
                ->icon('heroicon-o-device-phone-mobile')
                ->color('gray')
                ->url(route('filament.landlord.pages.simple'))
                ->visible(fn () => $this->fromSimpleMode),
        ];
    }

    /**
     * `rentals.tenant_id` is NOT NULL, so a tenancy cannot be inserted without a
     * user to hang the portal login on — `Rental::create($data)` from this form
     * (which captures only free-text occupant fields) failed outright.
     *
     * RoomAccountService::createForRental() mints the tenancy's own account,
     * sets tenant_id and persists the row in one step — the same thing
     * UnitResource's tenant list has always done. The generated credentials are
     * surfaced once, here, and can be reissued later with the tenancy's
     * "Reset password" action.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $unit = Unit::find($data['unit_id'] ?? null);
        $data['property_id'] = $unit?->property_id;
        $data['landlord_id'] = $unit?->landlord_id;

        $rental = new Rental($data);
        if ($unit) {
            $rental->setRelation('unit', $unit);
        }
        $account = app(RoomAccountService::class)->createForRental($rental);

        // Auto-create the primary occupant record from the rental's occupant fields.
        if (! empty($data['occupant_name'])) {
            $rental->occupants()->create([
                'role' => 'primary',
                'user_id' => $rental->tenant_id,
                'occupant_name' => $data['occupant_name'],
                'occupant_phone' => $data['occupant_phone'] ?? null,
                'occupant_id_card' => $data['occupant_id_card'] ?? null,
                'occupant_address' => $data['occupant_address'] ?? null,
                'occupant_gender' => $data['occupant_gender'] ?? null,
                'occupant_dob' => $data['occupant_dob'] ?? null,
                'occupant_nationality' => $data['occupant_nationality'] ?? null,
                'occupant_workplace' => $data['occupant_workplace'] ?? null,
                'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
                'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
                'emergency_contact_relationship' => $data['emergency_contact_relationship'] ?? null,
                'guarantor_name' => $data['guarantor_name'] ?? null,
                'guarantor_phone' => $data['guarantor_phone'] ?? null,
                'guarantor_id_number' => $data['guarantor_id_number'] ?? null,
                'guarantor_address' => $data['guarantor_address'] ?? null,
            ]);
        }

        Notification::make()
            ->title(__('Tenant created'))
            ->body(
                __('Occupant').': **'.$rental->occupant_name.'**'
                .'  \n'.__('Username').': **'.$account['username'].'** · '.__('Password').': **'.$account['password'].'**'
                .'  \n'.__('This password will not be shown again.')
            )
            ->success()->persistent()->send();

        return $rental;
    }

    protected function getRedirectUrl(): string
    {
        return $this->fromSimpleMode
            ? static::getResource()::getUrl('index', ['from' => 'simple'])
            : static::getResource()::getUrl('index');
    }
}
