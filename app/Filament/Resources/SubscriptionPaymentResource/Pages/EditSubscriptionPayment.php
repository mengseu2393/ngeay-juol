<?php

namespace App\Filament\Resources\SubscriptionPaymentResource\Pages;

use App\Filament\Resources\SubscriptionPaymentResource;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSubscriptionPayment extends EditRecord
{
    protected static string $resource = SubscriptionPaymentResource::class;

    protected function getRedirectUrl(): string
    {
        return ListSubscriptionPayments::mergedPageUrl();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $originalStatus = $record->status;
        $newStatus = $data['status'] instanceof \App\Enums\SubscriptionPaymentStatus
            ? $data['status']
            : \App\Enums\SubscriptionPaymentStatus::tryFrom((int) $data['status']);

        if ($originalStatus !== \App\Enums\SubscriptionPaymentStatus::Succeeded && $newStatus === \App\Enums\SubscriptionPaymentStatus::Succeeded) {
            $data['gateway'] = $data['gateway'] ?? $record->gateway ?? 'manual';
            $data['subscription_id'] = $record->subscription_id;

            return SubscriptionService::renew(
                Subscription::withoutGlobalScopes()->findOrFail($record->subscription_id),
                $data,
            );
        }

        $record->update($data);

        return $record;
    }
}
