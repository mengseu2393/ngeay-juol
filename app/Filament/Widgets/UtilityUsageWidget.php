<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasActivePropertyScope;
use App\Models\UtilityUsage;
use App\Support\Money;
use Filament\Widgets\ChartWidget;

class UtilityUsageWidget extends ChartWidget
{
    use HasActivePropertyScope;

    protected static ?int $sort = 3;

    public ?string $filter = null;

    public function getHeading(): string
    {
        return __('Utility cost').' ('.Money::activeSymbol().')';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getFilters(): ?array
    {
        $currentYear = now()->year;
        $years = [];
        for ($year = $currentYear; $year >= $currentYear - 4; $year--) {
            $years[(string) $year] = (string) $year;
        }

        return $years;
    }

    protected function getData(): array
    {
        $year = (int) ($this->filter ?? now()->year);
        $reportingCurrency = Money::activeCurrency();

        $usages = $this->scopeThroughRelation(
            UtilityUsage::query()->whereYear('reading_date', $year),
            'unit',
        )
            ->with(['propertyUtility', 'unit.property.settings'])
            ->get();

        $dataByUtility = [];
        foreach ($usages as $usage) {
            $utility = $usage->propertyUtility;
            $month = $usage->reading_date?->month;

            if (! $month || ! $utility) {
                continue;
            }

            $utilityName = $utility->name ?: __('Unknown');
            $dataByUtility[$utilityName] ??= array_fill(1, 12, 0.0);

            // Each utility prices in its own currency (៛/kWh electricity next to
            // $/m³ water is normal), so costs are converted before they meet on
            // one axis — otherwise 800 and 0.30 get added together.
            $nativeCost = $usage->is_waived
                ? 0.0
                : (float) $usage->amount_used * (float) ($utility->rate ?? 0.0);

            $dataByUtility[$utilityName][$month] += round(Money::convert(
                $nativeCost,
                Money::normalize($utility->currency),
                $reportingCurrency,
                (float) ($usage->unit?->property?->settings?->usd_khr_exchange_rate ?: 4000),
            ), 2);
        }

        $monthLabels = [
            __('Jan'), __('Feb'), __('Mar'), __('Apr'), __('May'), __('Jun'),
            __('Jul'), __('Aug'), __('Sep'), __('Oct'), __('Nov'), __('Dec'),
        ];

        $colors = [
            'electricity' => '#eab308', // Amber/Yellow
            'water' => '#3b82f6',       // Blue
            'gas' => '#f97316',         // Orange
        ];

        $symbol = Money::symbol($reportingCurrency);
        $datasets = [];
        $index = 0;
        foreach ($dataByUtility as $utilityName => $monthsData) {
            $color = $colors[strtolower($utilityName)] ?? null;
            if (! $color) {
                $palette = ['#10b981', '#a855f7', '#ec4899', '#6366f1', '#14b8a6'];
                $color = $palette[$index % count($palette)];
                $index++;
            }

            $datasets[] = [
                'label' => __($utilityName).' ('.$symbol.')',
                'data' => array_values($monthsData),
                'backgroundColor' => $color,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $monthLabels,
        ];
    }
}
