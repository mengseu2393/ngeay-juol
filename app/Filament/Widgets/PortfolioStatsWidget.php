<?php

namespace App\Filament\Widgets;

use App\Enums\RentalStatus;
use App\Enums\UnitStatus;
use App\Filament\Widgets\Concerns\HasActivePropertyScope;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Unit;
use App\Support\Money;
use App\Support\Receivables;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The portfolio's standing position: how full it is, who lives there, what is
 * held on deposit and what is still owed.
 *
 * Follows the sidebar property switcher like every other dashboard widget — a
 * portfolio-wide number sitting next to property-scoped charts reads as a
 * contradiction rather than as extra context.
 */
class PortfolioStatsWidget extends StatsOverviewWidget
{
    use HasActivePropertyScope;

    protected static ?int $sort = -4;

    public function getHeading(): ?string
    {
        return $this->scopeLabel();
    }

    protected function getStats(): array
    {
        $propertyId = $this->activePropertyId();

        return [
            $this->occupancyStat($propertyId),
            $this->tenanciesStat(),
            $this->depositsStat(),
            $this->outstandingStat($propertyId),
        ];
    }

    private function occupancyStat(?int $propertyId): Stat
    {
        $counts = $this->scopeToActiveProperty(Unit::query())
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $total = (int) $counts->sum();
        $occupied = (int) ($counts[UnitStatus::Occupied->value] ?? 0);
        $vacant = (int) ($counts[UnitStatus::Available->value] ?? 0);
        $rate = $total > 0 ? round($occupied / $total * 100) : 0;

        // Rent the vacant rooms would bring in if they were let at their asking price.
        $lostRent = $this->scopeToActiveProperty(Unit::query())
            ->where('status', UnitStatus::Available->value)
            ->get(['rent_amount', 'rent_currency', 'property_id']);

        $lostUsd = 0.0;
        $lostKhr = 0.0;
        foreach ($lostRent as $unit) {
            if (Money::forRecord($unit) === 'KHR') {
                $lostKhr += (float) $unit->rent_amount;
            } else {
                $lostUsd += (float) $unit->rent_amount;
            }
        }

        $description = $vacant > 0
            ? trans_choice('{1} :count room empty|[2,*] :count rooms empty', $vacant, ['count' => $vacant])
                .' · '.__('worth :amount/mo', ['amount' => Money::format($lostUsd, 'USD').' / '.Money::format($lostKhr, 'KHR')])
            : __('every room is let');

        // Without a property selected the count is portfolio-wide — say so.
        if ($propertyId === null) {
            $description .= ' · '.__(':count properties', ['count' => Property::count()]);
        }

        return Stat::make(__('Occupancy'), $total > 0 ? "{$rate}%" : '—')
            ->description($description)
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
        $active = $this->scopeToActiveProperty(Rental::query())
            ->where('status', RentalStatus::Active->value)
            ->count();

        $endingSoon = $this->scopeToActiveProperty(Rental::query())
            ->where('status', RentalStatus::Active->value)
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->startOfDay(), now()->addDays(60)->endOfDay()])
            ->count();

        return Stat::make(__('Active tenancies'), $active)
            ->description($endingSoon > 0
                ? __(':count ending within 60 days', ['count' => $endingSoon])
                : __('none ending within 60 days'))
            ->descriptionIcon('heroicon-o-key')
            ->color($endingSoon > 0 ? 'warning' : 'gray');
    }

    private function depositsStat(): Stat
    {
        // Money held on behalf of tenants — a liability, not revenue.
        $rentals = $this->scopeToActiveProperty(Rental::query())
            ->where('status', RentalStatus::Active->value)
            ->whereNotNull('security_deposit')
            ->get(['security_deposit', 'security_deposit_currency', 'property_id']);

        $usd = 0.0;
        $khr = 0.0;
        foreach ($rentals as $rental) {
            $currency = Money::normalize($rental->security_deposit_currency ?: Money::forRecord($rental));
            if ($currency === 'KHR') {
                $khr += (float) $rental->security_deposit;
            } else {
                $usd += (float) $rental->security_deposit;
            }
        }

        return Stat::make(__('Deposits held'), Money::format($usd, 'USD').' / '.Money::format($khr, 'KHR'))
            ->description(__('refundable to :count tenancies', ['count' => $rentals->count()]))
            ->descriptionIcon('heroicon-o-lock-closed')
            ->color('gray');
    }

    private function outstandingStat(?int $propertyId): Stat
    {
        $aging = Receivables::aging($propertyId);
        $overdue = ['usd' => 0.0, 'khr' => 0.0, 'count' => 0];

        foreach (['1_30', '31_60', '60_plus'] as $bucket) {
            $overdue['usd'] += $aging[$bucket]['usd'];
            $overdue['khr'] += $aging[$bucket]['khr'];
            $overdue['count'] += $aging[$bucket]['count'];
        }

        $total = [
            'usd' => $overdue['usd'] + $aging['not_due']['usd'],
            'khr' => $overdue['khr'] + $aging['not_due']['khr'],
        ];

        $worst = $aging['60_plus'];
        $description = $worst['count'] > 0
            ? __(':amount is 60+ days late', ['amount' => Receivables::format($worst)])
            : ($overdue['count'] > 0
                ? __(':count overdue invoices', ['count' => $overdue['count']])
                : __('nothing overdue'));

        return Stat::make(__('Outstanding'), Receivables::format($total))
            ->description($description)
            ->descriptionIcon('heroicon-o-banknotes')
            ->color(match (true) {
                $worst['count'] > 0 => 'danger',
                $overdue['count'] > 0 => 'warning',
                default => 'success',
            });
    }
}
