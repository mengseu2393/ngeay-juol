<?php

namespace App\Filament\Pages;

use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\Widgets\AdminRenewalStatsWidget;
use App\Support\RenewalQueue;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * The platform's daily billing round: who is about to lapse, and whose payment
 * is waiting to be believed.
 *
 * Both facts were already in the database and neither was reachable — a landlord
 * three weeks past their end date looked identical to a healthy one in the
 * subscriptions list, and a pending payment could only be settled by editing the
 * row by hand and then editing the subscription to match. The two tabs are the
 * two halves of the same conversation, so they share a page rather than becoming
 * two more sidebar entries.
 *
 * Structure follows {@see ListSubscriptions}:
 * one page, a `?tab=` URL parameter, and a TableWidget per tab.
 */
class Renewals extends Page
{
    public const TAB_RENEWALS = 'renewals';

    public const TAB_APPROVALS = 'approvals';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'Billing';

    /** Ahead of Plans and Subscriptions: this is the tab you open first. */
    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.renewals';

    protected static ?string $slug = 'renewals';

    #[Url]
    public string $tab = self::TAB_RENEWALS;

    public static function getNavigationLabel(): string
    {
        return __('Renewals');
    }

    public function getTitle(): string
    {
        return __('Renewals & approvals');
    }

    public function getSubheading(): ?string
    {
        return __('Subscriptions needing a decision, and payments waiting to be approved.');
    }

    /** Billing mutations, so super admin only — the same bar as the resources it acts on. */
    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Work waiting in the sidebar, so nobody has to open the page to find none. */
    public static function getNavigationBadge(): ?string
    {
        $total = RenewalQueue::total();

        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if (! in_array($this->tab, [self::TAB_RENEWALS, self::TAB_APPROVALS], true)) {
            $this->tab = self::TAB_RENEWALS;
        }
    }

    public function isApprovalsTab(): bool
    {
        return $this->tab === self::TAB_APPROVALS;
    }

    public function getRenewalsCount(): int
    {
        return RenewalQueue::needsAttention()->count();
    }

    public function getApprovalsCount(): int
    {
        return RenewalQueue::pendingPayments()->count();
    }

    protected function getHeaderWidgets(): array
    {
        return [
            AdminRenewalStatsWidget::class,
        ];
    }
}
