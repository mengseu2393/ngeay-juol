<?php

namespace App\Filament\Widgets;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Scopes\LandlordScope;
use App\Models\Unit;
use App\Models\User;
use App\Support\Receivables;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What the platform is actually carrying, summed across every landlord.
 *
 * {@see AdminPlatformStatsWidget} answers "is the business getting paid?"; this
 * answers "is the product being used?". The two diverge in the direction that
 * matters most: a landlord who pays on time but has never created a room is
 * churn that has not happened yet, and neither revenue nor headcount shows it.
 *
 * Queries drop {@see LandlordScope} by name rather than calling
 * withoutGlobalScopes(), which would also strip SoftDeletes and count deleted
 * properties and rooms as live inventory.
 */
class AdminPlatformUsageWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -38;

    public static function canView(): bool
    {
        return auth()->user()?->isPlatformStaff() ?? false;
    }

    public function getHeading(): ?string
    {
        return __('Across all landlords');
    }

    protected function getStats(): array
    {
        return [
            $this->propertiesStat(),
            $this->occupancyStat(),
            $this->tenanciesStat(),
            $this->receivablesStat(),
        ];
    }

    /** Inventory, plus the landlords who signed up and then never built any. */
    private function propertiesStat(): Stat
    {
        $properties = Property::withoutGlobalScope(LandlordScope::class)->count();

        $landlords = User::role('landlord')->count();
        $withStock = Property::withoutGlobalScope(LandlordScope::class)
            ->distinct()
            ->count('landlord_id');
        $dormant = max(0, $landlords - $withStock);

        return Stat::make(__('Properties'), $properties)
            ->description($dormant > 0
                ? __(':count landlords have not added one yet', ['count' => $dormant])
                : __('every landlord has at least one'))
            ->descriptionIcon('heroicon-o-building-office-2')
            ->color($dormant > 0 ? 'warning' : 'success');
    }

    private function occupancyStat(): Stat
    {
        $counts = Unit::withoutGlobalScope(LandlordScope::class)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $total = (int) $counts->sum();
        $occupied = (int) ($counts[UnitStatus::Occupied->value] ?? 0);
        $rate = $total > 0 ? (int) round($occupied / $total * 100) : 0;

        return Stat::make(__('Occupancy'), $total > 0 ? "{$rate}%" : '—')
            ->description(__(':occupied of :total rooms let', ['occupied' => $occupied, 'total' => $total]))
            ->descriptionIcon('heroicon-o-home-modern')
            ->color(match (true) {
                $total === 0 => 'gray',
                $rate >= 90 => 'success',
                $rate >= 70 => 'warning',
                default => 'danger',
            });
    }

    private function tenanciesStat(): Stat
    {
        $active = Rental::withoutGlobalScope(LandlordScope::class)
            ->where('status', RentalStatus::Active->value)
            ->count();

        $endingSoon = Rental::withoutGlobalScope(LandlordScope::class)
            ->where('status', RentalStatus::Active->value)
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->startOfDay(), now()->addDays(30)->endOfDay()])
            ->count();

        return Stat::make(__('Active tenancies'), $active)
            ->description($endingSoon > 0
                ? __(':count ending within 30 days', ['count' => $endingSoon])
                : __('none ending within 30 days'))
            ->descriptionIcon('heroicon-o-key')
            ->color($endingSoon > 0 ? 'warning' : 'gray');
    }

    /**
     * Tenant rent still outstanding platform-wide — the volume the product moves,
     * not platform income. {@see Receivables} reads through LandlordScope, which
     * is a no-op for the platform staff this widget is gated to, so the figure is
     * already every landlord's books added together.
     */
    private function receivablesStat(): Stat
    {
        $outstanding = Receivables::outstanding();

        return Stat::make(__('Rent outstanding'), Receivables::format($outstanding))
            ->description(trans_choice(
                '{0} no open invoices|{1} :count open invoice|[2,*] :count open invoices',
                $outstanding['count'],
                ['count' => $outstanding['count']],
            ).' · '.__('tenant money, not platform income'))
            ->descriptionIcon('heroicon-o-document-currency-dollar')
            ->color('gray');
    }
}
