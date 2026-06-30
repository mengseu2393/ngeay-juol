<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /** status & manages_landlord_id are not mass-assignable — set them via forceFill. */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $guarded = [];
        if (array_key_exists('status', $data)) {
            $guarded['status'] = $data['status'];
        }
        if (array_key_exists('manages_landlord_id', $data)) {
            $guarded['manages_landlord_id'] = $data['manages_landlord_id'];
        }
        unset($data['status'], $data['manages_landlord_id']);

        $record->update($data);

        if ($guarded !== []) {
            $record->forceFill($guarded)->save();
        }

        return $record;
    }
}
