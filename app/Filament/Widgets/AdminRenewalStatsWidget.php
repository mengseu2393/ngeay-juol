<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Renewals;
use App\Support\Money;
use App\Support\RenewalQueue;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Header of the {@see Renewals} page: the size of each pile
 * before the admin opens either table.
 *
 * The split that matters is between "in grace" and "access lost" — both are past
 * their end date, but the first is a landlord still working who needs a reminder,
 * and the second is a landlord locked out who needs a phone call.
 */
class AdminRenewalStatsWidget extends StatsOverviewWidget
{
    // Four cheap counts. Rendered with the page rather than lazily, so the
    // header arrives whole instead of the tables appearing above an empty box.
    protected static bool $isLazy = false;

    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    protected function getStats(): array
    {
        $counts = RenewalQueue::counts();
        $lostAccess = $counts[RenewalQueue::BUCKET_EXPIRED] + $counts[RenewalQueue::BUCKET_SUSPENDED];

        return [
            Stat::make(__('Expiring soon'), $counts[RenewalQueue::BUCKET_EXPIRING])
                ->description($counts[RenewalQueue::BUCKET_PENDING] > 0
                    ? __('within :days days', ['days' => RenewalQueue::HORIZON_DAYS])
                        .' · '.__(':count never started', ['count' => $counts[RenewalQueue::BUCKET_PENDING]])
                    : __('within :days days', ['days' => RenewalQueue::HORIZON_DAYS]))
                ->descriptionIcon('heroicon-o-clock')
                ->color($counts[RenewalQueue::BUCKET_EXPIRING] > 0 ? 'warning' : 'success'),

            Stat::make(__('In grace'), $counts[RenewalQueue::BUCKET_GRACE])
                ->description(__('past due, still working'))
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color($counts[RenewalQueue::BUCKET_GRACE] > 0 ? 'warning' : 'success'),

            Stat::make(__('Access lost'), $lostAccess)
                ->description(__(':expired expired · :suspended suspended', [
                    'expired' => $counts[RenewalQueue::BUCKET_EXPIRED],
                    'suspended' => $counts[RenewalQueue::BUCKET_SUSPENDED],
                ]))
                ->descriptionIcon('heroicon-o-lock-closed')
                ->color($lostAccess > 0 ? 'danger' : 'success'),

            $this->approvalsStat(),
        ];
    }

    private function approvalsStat(): Stat
    {
        $pending = RenewalQueue::pendingPayments()->get(['amount', 'currency']);

        $usd = (float) $pending->where('currency', '!=', 'KHR')->sum('amount');
        $khr = (float) $pending->where('currency', 'KHR')->sum('amount');

        return Stat::make(__('Awaiting approval'), $pending->count())
            ->description($pending->isEmpty()
                ? __('nothing to check')
                : __('worth :amount', ['amount' => Money::format($usd, 'USD').($khr > 0 ? ' / '.Money::format($khr, 'KHR') : '')]))
            ->descriptionIcon('heroicon-o-banknotes')
            ->color($pending->isEmpty() ? 'success' : 'warning');
    }
}
