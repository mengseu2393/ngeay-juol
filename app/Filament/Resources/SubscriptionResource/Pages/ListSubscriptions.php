<?php

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Filament\Resources\SubscriptionPaymentResource;
use App\Filament\Resources\SubscriptionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

/**
 * Merged subscriptions page: subscriptions and their payments live side by side as
 * two tabs of this single page (`/admin/subscriptions`, `?tab=payments`) instead of
 * two separate navigation entries. The payments tab is rendered by the
 * SubscriptionPaymentsTableWidget.
 *
 * `$tab` only decides what the *initial* render shows; the view swaps panels in the
 * browser, so switching tabs never waits on the server.
 */
class ListSubscriptions extends ListRecords
{
    public const TAB_SUBSCRIPTIONS = 'subscriptions';

    public const TAB_PAYMENTS = 'payments';

    protected static string $resource = SubscriptionResource::class;

    protected static string $view = 'filament.resources.subscriptions.list-subscriptions';

    #[Url]
    public string $tab = self::TAB_SUBSCRIPTIONS;

    public function mount(): void
    {
        parent::mount();

        if (! in_array($this->tab, [self::TAB_SUBSCRIPTIONS, self::TAB_PAYMENTS], true)) {
            $this->tab = self::TAB_SUBSCRIPTIONS;
        }
    }

    public function isPaymentsTab(): bool
    {
        return $this->tab === self::TAB_PAYMENTS;
    }

    public function getSubscriptionsCount(): int
    {
        return SubscriptionResource::getEloquentQuery()->count();
    }

    public function getPaymentsCount(): int
    {
        return SubscriptionPaymentResource::getEloquentQuery()->count();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label(__('Assign subscription'))
                ->extraAttributes(fn (): array => $this->tabAttributes(self::TAB_SUBSCRIPTIONS)),
            Actions\Action::make('recordPayment')
                ->label(__('Record payment'))
                ->icon('heroicon-m-plus')
                ->url(fn (): string => SubscriptionPaymentResource::getUrl('create'))
                ->extraAttributes(fn (): array => $this->tabAttributes(self::TAB_PAYMENTS)),
        ];
    }

    /**
     * Header buttons follow the tab in the browser, like the panels do. The inline
     * style covers the first paint, before Alpine takes the element over.
     *
     * Filament escapes quotes in the attribute bag, so the expression has to stay
     * quote-free — hence the boolean `payments` flag rather than a string compare.
     *
     * @return array<string, string>
     */
    protected function tabAttributes(string $tab): array
    {
        return array_filter([
            'x-show' => $tab === self::TAB_PAYMENTS ? 'payments' : '! payments',
            'style' => $this->tab === $tab ? null : 'display: none;',
        ]);
    }
}
