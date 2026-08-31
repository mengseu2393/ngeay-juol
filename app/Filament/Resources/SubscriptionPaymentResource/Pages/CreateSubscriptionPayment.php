<?php

namespace App\Filament\Resources\SubscriptionPaymentResource\Pages;

use App\Filament\Resources\SubscriptionPaymentResource;
use App\Services\SubscriptionService;
use Illuminate\Database\Eloquent\Model;
use Filament\Resources\Pages\CreateRecord;

class CreateSubscriptionPayment extends CreateRecord
{
    protected static string $resource = SubscriptionPaymentResource::class;

    protected function getRedirectUrl(): string
    {
        return ListSubscriptionPayments::mergedPageUrl();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $sub = \App\Models\Subscription::withoutGlobalScopes()->find($data['subscription_id']);
        if ($sub) {
            $data['plan_id'] = $sub->plan_id;
            $data['landlord_id'] = $sub->landlord_id;
            $data['gateway'] = $data['gateway'] ?? 'manual';
        }
        $data['recorded_by_id'] = auth()->id();
        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $statusValue = $data['status'] instanceof \App\Enums\SubscriptionPaymentStatus 
            ? $data['status']->value 
            : (int) $data['status'];

        if ($statusValue === \App\Enums\SubscriptionPaymentStatus::Succeeded->value) {
            $sub = \App\Models\Subscription::withoutGlobalScopes()->findOrFail($data['subscription_id']);
            return SubscriptionService::renew($sub, $data);
        }

        return static::getModel()::create($data);
    }
}
