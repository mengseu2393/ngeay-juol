<?php

namespace App\Filament\Resources\SubscriptionPaymentResource\Pages;

use App\Filament\Resources\SubscriptionPaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSubscriptionPayment extends EditRecord
{
    protected static string $resource = SubscriptionPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
