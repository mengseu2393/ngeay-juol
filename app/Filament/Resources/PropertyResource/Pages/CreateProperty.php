<?php

namespace App\Filament\Resources\PropertyResource\Pages;

use App\Filament\Resources\PropertyResource;
use App\Services\DefaultPropertyUtilitiesService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateProperty extends CreateRecord
{
    protected static string $resource = PropertyResource::class;

    /**
     * A property with no utilities bills rent only, silently. Stand the starter
     * catalog up here and say so, so the rate is the one thing left to fill in.
     */
    protected function afterCreate(): void
    {
        $created = app(DefaultPropertyUtilitiesService::class)->seed($this->getRecord());

        if ($created->isEmpty()) {
            return;
        }

        Notification::make()
            ->title(__('Electricity and water added'))
            ->body(__('Set their rates before the first billing run.'))
            ->success()
            ->send();
    }

    /** Land on the edit page so the Units tab (and "Generate rooms") is right there. */
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
