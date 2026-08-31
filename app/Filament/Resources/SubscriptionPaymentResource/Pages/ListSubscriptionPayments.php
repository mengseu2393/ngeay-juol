<?php

namespace App\Filament\Resources\SubscriptionPaymentResource\Pages;

use App\Filament\Resources\SubscriptionPaymentResource;
use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use Filament\Resources\Pages\ListRecords;

/**
 * Kept only so `/admin/subscription-payments` (bookmarks, breadcrumbs and Filament's
 * default redirects) still resolves — the list itself now lives on the merged
 * subscriptions page as its "Payments" tab.
 */
class ListSubscriptionPayments extends ListRecords
{
    protected static string $resource = SubscriptionPaymentResource::class;

    public function mount(): void
    {
        $this->redirect(static::mergedPageUrl(), navigate: false);
    }

    public static function mergedPageUrl(): string
    {
        return SubscriptionResource::getUrl('index', ['tab' => ListSubscriptions::TAB_PAYMENTS]);
    }
}
