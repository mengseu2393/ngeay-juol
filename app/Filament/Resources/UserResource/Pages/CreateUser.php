<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** status & manages_landlord_id are not mass-assignable — set them via forceFill. */
    protected function handleRecordCreation(array $data): Model
    {
        $guarded = [
            'status' => $data['status'] ?? null,
            'manages_landlord_id' => $data['manages_landlord_id'] ?? null,
            'created_by_id' => Auth::id(),
        ];
        unset($data['status'], $data['manages_landlord_id']);

        $user = static::getModel()::create($data);
        $user->forceFill(array_filter($guarded, fn ($v) => $v !== null))->save();

        return $user;
    }
}
