<?php

namespace App\Filament\Widgets;

use App\Enums\SubscriptionPaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Pages\Renewals;
use App\Filament\Resources\LandlordResource;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AdminPlatformStatsWidget extends StatsOverviewWidget
{
    // Ahead of the dashboard's other widgets and of Filament's own AccountWidget
    // (-3) and FilamentInfoWidget (-2), which sink to the bottom as a result.
    protected static ?int $sort = -40;

    public static function canView(): bool
    {
        return auth()->user()?->isPlatformStaff() ?? false;
    }

    protected function getStats(): array
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $pendingPayments = SubscriptionPayment::withoutGlobalScopes()
            ->where('status', SubscriptionPaymentStatus::Pending->value)
            ->count();

        $monthlyRevenue = (float) SubscriptionPayment::withoutGlobalScopes()
            ->where('status', SubscriptionPaymentStatus::Succeeded->value)
            ->whereBetween('paid_at', [$monthStart, $monthEnd])
            ->sum('amount');

        return [
            Stat::make(__('Landlords'), User::role('landlord')->count())
                ->descriptionIcon('heroicon-o-users')
                ->url(LandlordResource::canAccess() ? LandlordResource::getUrl() : null),

            Stat::make(
                __('Active subscriptions'),
                Subscription::withoutGlobalScopes()
                    ->withoutTrashed()
                    ->where('status', SubscriptionStatus::Active->value)
                    ->count()
            )
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success'),

            // A number nobody could act on until the approvals queue existed; the
            // link is the whole point of the stat.
            Stat::make(__('Pending subscription payments'), $pendingPayments)
                ->description($pendingPayments > 0 ? __('open the approval queue') : __('nothing to check'))
                ->descriptionIcon('heroicon-o-clock')
                ->color($pendingPayments > 0 ? 'warning' : 'success')
                ->url(Renewals::canAccess()
                    ? Renewals::getUrl(['tab' => Renewals::TAB_APPROVALS])
                    : null),

            Stat::make(__('Monthly subscription revenue'), '$'.number_format($monthlyRevenue, 2))
                ->description(__('current month'))
                ->descriptionIcon('heroicon-o-banknotes')
                ->color('success'),
        ];
    }
}
