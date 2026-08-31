<?php

namespace App\Filament\Widgets;

use App\Enums\BillingType;
use App\Enums\RentalStatus;
use App\Filament\Pages\MonthlyBilling;
use App\Filament\Widgets\Concerns\HasActivePropertyScope;
use App\Providers\Filament\LandlordPanelProvider;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PropertyUtility;
use App\Models\Rental;
use App\Models\UtilityUsage;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * "What still has to happen this month" — the one part of the dashboard that
 * looks forward instead of back.
 *
 * The rest of the page charts what already happened; this row tracks the monthly
 * billing run itself (rooms waiting to be invoiced, meter readings still to
 * collect, invoices issued, cash actually in) and links straight into
 * {@see MonthlyBilling}, which is where the work gets done.
 */
class BillingCycleWidget extends StatsOverviewWidget
{
    use HasActivePropertyScope;

    protected static ?int $sort = -3;

    public function getHeading(): ?string
    {
        return __('This month').' — '.now()->translatedFormat('F Y');
    }

    protected function getStats(): array
    {
        return [
            $this->dueToBillStat(),
            $this->readingsStat(),
            $this->invoicedStat(),
            $this->collectedStat(),
        ];
    }

    /** Active tenancies whose next invoice date has arrived (same rule as the nav badge). */
    private function dueToBillStat(): Stat
    {
        $due = $this->billableRentals()
            ->where(function (Builder $query) {
                $query->whereNull('next_invoice_date')
                    ->orWhereDate('next_invoice_date', '<=', now()->toDateString());
            })
            ->count();

        return Stat::make(__('Rooms due to bill'), $due)
            ->description($due > 0 ? __('open monthly billing') : __('all caught up'))
            ->descriptionIcon($due > 0 ? 'heroicon-o-arrow-right-circle' : 'heroicon-o-check-circle')
            ->url($due > 0 ? MonthlyBilling::getUrl(panel: LandlordPanelProvider::ID) : null)
            ->color($due > 0 ? 'warning' : 'success');
    }

    /**
     * Meter readings recorded this month against the number the billing run needs.
     * A room that is half-read still blocks its invoice, so this counts every
     * (room × metered utility) pair rather than just rooms touched.
     */
    private function readingsStat(): Stat
    {
        $expected = $this->expectedReadingCount();

        $recorded = $this->scopeThroughRelation(
            UtilityUsage::query()->whereBetween('reading_date', [now()->startOfMonth(), now()->endOfMonth()]),
            'unit',
        )
            ->select('unit_id', 'property_utility_id')
            ->distinct()
            ->get()
            ->count();

        $recorded = min($recorded, $expected); // guard against readings for since-ended tenancies

        return Stat::make(__('Meter readings'), $expected > 0 ? "{$recorded} / {$expected}" : '—')
            ->description($expected > 0 && $recorded < $expected
                ? __(':count still to collect', ['count' => $expected - $recorded])
                : __('nothing outstanding'))
            ->descriptionIcon('heroicon-o-bolt')
            ->color(match (true) {
                $expected === 0 || $recorded >= $expected => 'success',
                $recorded === 0 => 'danger',
                default => 'warning',
            });
    }

    private function invoicedStat(): Stat
    {
        $issued = $this->scopeToActiveProperty(Invoice::query())
            ->whereBetween('issue_date', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        $tenancies = $this->billableRentals()->count();

        return Stat::make(__('Invoices issued'), $tenancies > 0 ? "{$issued} / {$tenancies}" : (string) $issued)
            ->description(__('for :count billable tenancies', ['count' => $tenancies]))
            ->descriptionIcon('heroicon-o-document-text')
            ->color($tenancies > 0 && $issued >= $tenancies ? 'success' : 'gray');
    }

    /**
     * Cash actually received this month, against what was billed this month.
     * amount_usd/amount_khr hold the same payment converted both ways, so the
     * two figures are one amount shown twice — never a sum of the two.
     */
    private function collectedStat(): Stat
    {
        $payments = $this->scopeThroughRelation(
            Payment::query()->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()]),
            'invoice',
        );

        $collectedUsd = (float) (clone $payments)->sum('amount_usd');
        $collectedKhr = (float) (clone $payments)->sum('amount_khr');

        $billedUsd = (float) $this->scopeToActiveProperty(Invoice::query())
            ->whereBetween('issue_date', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('total_usd');

        $rate = $billedUsd > 0 ? (int) round($collectedUsd / $billedUsd * 100) : null;

        return Stat::make(
            __('Collected'),
            Money::format($collectedUsd, 'USD').' / '.Money::format($collectedKhr, 'KHR'),
        )
            ->description($rate !== null
                ? __(':rate% of :billed billed this month', [
                    'rate' => $rate,
                    'billed' => Money::format($billedUsd, 'USD'),
                ])
                : __('nothing billed this month'))
            ->descriptionIcon('heroicon-o-arrow-trending-up')
            ->color(match (true) {
                $rate === null => 'gray',
                $rate >= 90 => 'success',
                $rate >= 60 => 'warning',
                default => 'danger',
            });
    }

    /** Active tenancies in properties that actually run the monthly billing flow. */
    private function billableRentals(): Builder
    {
        return $this->scopeToActiveProperty(Rental::query())
            ->where('status', RentalStatus::Active->value)
            ->whereHas('unit.property.settings', fn (Builder $q) => $q->where('monthly_billing_enabled', true));
    }

    /** (active tenancies × active metered utilities), summed per property. */
    private function expectedReadingCount(): int
    {
        $rentalsByProperty = $this->billableRentals()
            ->selectRaw('property_id, COUNT(*) as aggregate')
            ->groupBy('property_id')
            ->pluck('aggregate', 'property_id');

        if ($rentalsByProperty->isEmpty()) {
            return 0;
        }

        $utilitiesByProperty = PropertyUtility::query()
            ->whereIn('property_id', $rentalsByProperty->keys())
            ->where('is_active', true)
            ->where('billing_type', BillingType::Metered->value)
            ->selectRaw('property_id, COUNT(*) as aggregate')
            ->groupBy('property_id')
            ->pluck('aggregate', 'property_id');

        $expected = 0;
        foreach ($rentalsByProperty as $propertyId => $rentals) {
            $expected += (int) $rentals * (int) ($utilitiesByProperty[$propertyId] ?? 0);
        }

        return $expected;
    }
}
