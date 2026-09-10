<?php

namespace App\Filament\Resources\LandlordResource\Pages;

use App\Filament\Resources\LandlordResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLandlord extends EditRecord
{
    protected static string $resource = LandlordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Verified behaviour: User soft-deletes, and every landlord-owned table
            // declares `landlord_id ... restrictOnDelete()`, so nothing cascades.
            Actions\DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading(__('Delete landlord'))
                ->modalDescription(__('The account is soft-deleted: the landlord can no longer sign in and drops out of this list unless the Trashed filter is on. Their properties, units, tenancies and invoices are kept untouched, and the account can be restored.'))
                ->disabled(fn (User $record): bool => LandlordResource::ownsProperties($record))
                ->tooltip(fn (User $record): ?string => LandlordResource::ownsProperties($record)
                    ? __('Delete or reassign this landlord\'s properties first.')
                    : null)
                ->before(function (Actions\DeleteAction $action, User $record): void {
                    LandlordResource::guardDeletion($action, $record);
                }),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['name'] = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
        unset($data['first_name'], $data['last_name']);

        return $data;
    }

    protected function afterSave(): void
    {
        if (array_key_exists('status', $this->data)) {
            $this->record->forceFill(['status' => $this->data['status']])->save();
        }
    }
}
