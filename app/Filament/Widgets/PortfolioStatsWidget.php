<?php

namespace App\Filament\Widgets;

use App\Enums\InvoiceStatus;
use App\Enums\RentalStatus;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Rental;
use App\Models\UtilityWaiver;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Portfolio aggregate. Auto-scoped by the models' LandlordScope: a landlord sees
 * their own totals; super_admin / support see the whole platform (the admin's
 * "global view" alongside per-property detail).
 */
class PortfolioStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -3;

    protected function getStats(): array
    {
        $outstanding = (float) Invoice::query()
            ->whereIn('payment_status', [
                InvoiceStatus::Pending->value,
                InvoiceStatus::Partial->value,
                InvoiceStatus::Overdue->value,
            ])
            ->selectRaw('COALESCE(SUM(amount_due - amount_paid), 0) as bal')
            ->value('bal');

        return [
            Stat::make(__('Properties'), Property::count())
                ->descriptionIcon('heroicon-o-building-office-2'),
            Stat::make(__('Active tenancies'), Rental::where('status', RentalStatus::Active->value)->count())
                ->descriptionIcon('heroicon-o-key'),
            Stat::make(__('Outstanding'), '$'.number_format($outstanding, 2))
                ->description(__('unpaid invoice balances'))
                ->color($outstanding > 0 ? 'warning' : 'success'),
            Stat::make(__('Active waivers'), UtilityWaiver::where('waived', true)->count())
                ->descriptionIcon('heroicon-o-receipt-percent'),
        ];
    }
}
