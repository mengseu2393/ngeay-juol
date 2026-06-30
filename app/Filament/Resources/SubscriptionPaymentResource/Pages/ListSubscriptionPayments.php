<?php

namespace App\Filament\Resources\SubscriptionPaymentResource\Pages;

use App\Filament\Resources\SubscriptionPaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptionPayments extends ListRecords
{
    protected static string $resource = SubscriptionPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Record payment'),
        ];
    }
}
