<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasActivePropertyScope;
use App\Support\Money;
use App\Support\Receivables;
use Filament\Widgets\ChartWidget;

/**
 * How old the unpaid money is.
 *
 * A single "Outstanding" figure hides the difference between a landlord whose
 * tenants pay a week late and one carrying six months of bad debt. Plotted in the
 * property's own reporting currency — an axis cannot carry "$120 / ៛480,000".
 */
class ReceivablesAgingWidget extends ChartWidget
{
    use HasActivePropertyScope;

    protected static ?int $sort = 1;

    protected static ?string $maxHeight = '220px';

    public function getHeading(): string
    {
        return __('Unpaid by age').' ('.Money::activeSymbol().')';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $currency = Money::activeCurrency();
        $aging = Receivables::aging($this->activePropertyId());

        $labels = [];
        $values = [];

        foreach ($aging as $bucket) {
            $labels[] = $bucket['label'];
            $values[] = Receivables::inCurrency($bucket, $currency);
        }

        return [
            'datasets' => [
                [
                    'label' => __('Outstanding'),
                    'data' => $values,
                    'backgroundColor' => [
                        '#94a3b8', // not due — slate, no action needed
                        '#f59e0b', // 1–30 — amber
                        '#f97316', // 31–60 — orange
                        '#ef4444', // 60+ — red
                    ],
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
