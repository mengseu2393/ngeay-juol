<?php

namespace App\Filament\Widgets;

use App\Enums\SubscriptionAccess;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class SubscriptionStatusWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -5;

    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $user = Auth::user();
        return $user && $user->hasAnyRole(['landlord', 'landlord_manager']);
    }

    protected function getStats(): array
    {
        $user = Auth::user();
        $access = SubscriptionService::effectiveAccess($user);
        $sub = Subscription::withoutGlobalScopes()
            ->where('landlord_id', $user->effectiveLandlordId())
            ->with('plan')
            ->first();

        if (! $sub) {
            return [
                Stat::make(__('Subscription'), __('No subscription'))
                    ->description(__('Contact administrator'))
                    ->icon('heroicon-o-x-circle', IconPosition::Before)
                    ->color('danger'),
            ];
        }

        $daysToExpiry = $sub->ends_at ? (int) now()->startOfDay()->diffInDays($sub->ends_at, false) : null;

        $color = match (true) {
            $access === SubscriptionAccess::Revoked => 'danger',
            $access === SubscriptionAccess::ReadOnly => 'danger',
            $access === SubscriptionAccess::PastDue => 'warning',
            $daysToExpiry !== null && $daysToExpiry <= 0 => 'danger',
            $daysToExpiry !== null && $daysToExpiry <= 7 => 'warning',
            default => 'success',
        };

        $label = $daysToExpiry !== null && $daysToExpiry > 0
            ? "{$daysToExpiry} " . __('days remaining')
            : $access->getLabel();

        return [
            Stat::make(
                __('Plan'),
                $sub->plan->name
            )
                ->description($label)
                ->icon(match ($color) {
                    'danger' => 'heroicon-o-x-circle',
                    'warning' => 'heroicon-o-exclamation-triangle',
                    default => 'heroicon-o-check-circle',
                }, IconPosition::Before)
                ->color($color),

            Stat::make(
                __('Units'),
                ($sub->current_unit_count ?? 0) . ($sub->max_units ? " / {$sub->max_units}" : '')
            )
                ->description(__('of max allowed'))
                ->icon('heroicon-o-building-office-2', IconPosition::Before)
                ->color('gray'),

            Stat::make(
                __('Status'),
                $sub->status->getLabel()
            )
                ->description($sub->ends_at?->format('Y-m-d') ?? '')
                ->icon('heroicon-o-credit-card', IconPosition::Before)
                ->color($sub->status->getColor()),
        ];
    }
}
