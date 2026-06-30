<?php

namespace App\Filament\Resources\SubscriptionPlanResource\Pages;

use App\Filament\Resources\SubscriptionPlanResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSubscriptionPlan extends EditRecord
{
    protected static string $resource = SubscriptionPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->subscriptions()->count() === 0),
        ];
    }
}
