<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\HasActivePropertyScope;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Money;
use Filament\Widgets\ChartWidget;

class RevenueChartWidget extends ChartWidget
{
    use HasActivePropertyScope;

    protected static ?int $sort = 0;

    public ?string $filter = null;

    public function getHeading(): string
    {
        return __('Revenue & Concessions').' ('.Money::activeSymbol().')';
    }

    protected function getType(): string
    {
        return 'line';
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

        $invoices = $this->scopeToActiveProperty(Invoice::query())
            ->whereYear('issue_date', $year)
            ->with('lines')
            ->get();

        $monthlyRevenue = array_fill(1, 12, 0.0);
        $monthlyFreeValue = array_fill(1, 12, 0.0);
        $monthlyWaivedValue = array_fill(1, 12, 0.0);
        $monthlyCustomValue = array_fill(1, 12, 0.0);

        foreach ($invoices as $invoice) {
            $month = $invoice->issue_date ? $invoice->issue_date->month : null;
            if (! $month) {
                continue;
            }

            foreach ($invoice->lines->filter(fn (InvoiceLine $line) => $line->shouldAppearOnTenantInvoice()) as $line) {
                $lineValue = self::lineAmountIn($line, $reportingCurrency, $invoice);
                $concessionValue = self::concessionValueIn($line, $reportingCurrency, $invoice);

                switch ($line->resolvedChargeState()) {
                    case 'free':
                        $monthlyFreeValue[$month] += $concessionValue;
                        break;
                    case 'waived':
                        $monthlyWaivedValue[$month] += $concessionValue;
                        break;
                    case 'custom':
                        $monthlyCustomValue[$month] += $lineValue;
                        $monthlyRevenue[$month] += $lineValue;
                        break;
                    default:
                        $monthlyRevenue[$month] += $lineValue;
                        break;
                }
            }
        }

        $currencySymbol = Money::symbol($reportingCurrency);
        $monthLabels = [
            __('Jan'), __('Feb'), __('Mar'), __('Apr'), __('May'), __('Jun'),
            __('Jul'), __('Aug'), __('Sep'), __('Oct'), __('Nov'), __('Dec'),
        ];

        return [
            'datasets' => [
                [
                    'label' => __('Revenue').' ('.$currencySymbol.')',
                    'data' => array_values($monthlyRevenue),
                    'borderColor' => '#10b981', // green/emerald for collected cash
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                ],
                [
                    'label' => __('Free value').' ('.$currencySymbol.')',
                    'data' => array_values($monthlyFreeValue),
                    'borderColor' => '#0ea5e9', // sky for free concessions
                    'backgroundColor' => 'rgba(14, 165, 233, 0.1)',
                    'fill' => true,
                ],
                [
                    'label' => __('Waived value').' ('.$currencySymbol.')',
                    'data' => array_values($monthlyWaivedValue),
                    'borderColor' => '#f59e0b', // amber for waived concessions
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'fill' => true,
                ],
                [
                    'label' => __('Custom adjustments').' ('.$currencySymbol.')',
                    'data' => array_values($monthlyCustomValue),
                    'borderColor' => '#8b5cf6', // violet for custom overrides
                    'backgroundColor' => 'rgba(139, 92, 246, 0.1)',
                    'fill' => true,
                ],
            ],
            'labels' => $monthLabels,
        ];
    }

    /**
     * A line's value in one currency.
     *
     * Lines are stored in their own native currency ($ rent alongside ៛ water is
     * routine here), so the native `amount` column must never be summed across a
     * mixed invoice — that adds 50 to 80,000 and prints the result with a single
     * symbol. amount_usd/amount_khr hold the same figure converted at the
     * invoice's snapshot rate; legacy rows that predate those columns fall back
     * to converting `amount` here.
     */
    private static function lineAmountIn(InvoiceLine $line, string $currency, Invoice $invoice): float
    {
        $converted = $currency === 'KHR' ? $line->amount_khr : $line->amount_usd;

        if ($converted !== null) {
            return (float) $converted;
        }

        return Money::convert(
            (float) $line->amount,
            Money::normalize($line->currency),
            $currency,
            self::rateFor($line, $invoice),
        );
    }

    /** What a free/waived line would have been worth had it been charged. */
    private static function concessionValueIn(InvoiceLine $line, string $currency, Invoice $invoice): float
    {
        $unitPrice = $currency === 'KHR' ? $line->unit_price_khr : $line->unit_price_usd;

        if ($unitPrice !== null) {
            return (float) $unitPrice * (float) $line->quantity;
        }

        return Money::convert(
            (float) $line->unit_price * (float) $line->quantity,
            Money::normalize($line->unit_price_currency ?: $line->currency),
            $currency,
            self::rateFor($line, $invoice),
        );
    }

    private static function rateFor(InvoiceLine $line, Invoice $invoice): float
    {
        return (float) ($line->exchange_rate
            ?: $invoice->usd_khr_rate
            ?: $invoice->property?->settings?->usd_khr_exchange_rate
            ?: 4000);
    }
}
