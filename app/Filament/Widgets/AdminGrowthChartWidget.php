<?php

namespace App\Filament\Widgets;

use App\Enums\SubscriptionPaymentStatus;
use App\Models\Scopes\LandlordScope;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;

/**
 * The two numbers that decide whether the platform is a business: how many
 * landlords arrived, and how much they paid.
 *
 * They share one timeline but not one unit, so revenue rides a second y-axis on
 * the right. Plotting dollars against headcount on a single scale would flatten
 * whichever series happens to be smaller into the baseline — with $9–$199 plans
 * and a handful of signups a month, that is guaranteed to happen to one of them.
 *
 * Buckets are grouped in PHP, not by DATE_FORMAT(): the test suite runs on
 * SQLite, which has no such function, and twelve months of rows is a trivial
 * amount to fold in memory.
 */
class AdminGrowthChartWidget extends ChartWidget
{
    protected static ?int $sort = -36;

    protected static ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 2;

    /** How many months of history the chart covers, including the current one. */
    private const MONTHS = 12;

    public static function canView(): bool
    {
        return auth()->user()?->isPlatformStaff() ?? false;
    }

    public function getHeading(): string
    {
        return __('Growth');
    }

    public function getDescription(): ?string
    {
        return __('New landlords and subscription revenue collected, last 12 months.');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $months = $this->months();
        $keys = $months->map(fn (CarbonInterface $m) => $m->format('Y-m'));

        $signups = User::role('landlord')
            ->where('created_at', '>=', $months->first())
            ->pluck('created_at')
            ->countBy(fn (?CarbonInterface $at) => $at?->format('Y-m'));

        // Only settled money counts: a Pending row is an invitation to pay, and
        // charting it as revenue would book income the platform never received.
        $revenue = SubscriptionPayment::withoutGlobalScope(LandlordScope::class)
            ->where('status', SubscriptionPaymentStatus::Succeeded->value)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $months->first())
            ->get(['amount', 'paid_at'])
            ->groupBy(fn (SubscriptionPayment $p) => $p->paid_at->format('Y-m'))
            ->map(fn (Collection $rows) => round((float) $rows->sum('amount'), 2));

        return [
            'datasets' => [
                [
                    'label' => __('New landlords'),
                    'data' => $keys->map(fn (string $k) => (int) $signups->get($k, 0))->all(),
                    'backgroundColor' => 'rgba(5, 150, 105, 0.55)', // emerald-600 — the brand green
                    'borderColor' => '#059669',
                    'borderWidth' => 1,
                    'yAxisID' => 'y',
                    'order' => 2,
                ],
                [
                    'type' => 'line',
                    'label' => __('Subscription revenue').' ($)',
                    'data' => $keys->map(fn (string $k) => (float) $revenue->get($k, 0))->all(),
                    // Same brand green, four steps lighter: the two series stay in the
                    // palette and still separate, by lightness and by bar-vs-line.
                    'borderColor' => '#6ee7b7', // emerald-300
                    'backgroundColor' => 'rgba(110, 231, 183, 0.12)',
                    'tension' => 0,
                    'fill' => true,
                    'yAxisID' => 'y1',
                    'order' => 1,
                ],
            ],
            'labels' => $months->map(fn (CarbonInterface $m) => $m->isoFormat('MMM YY'))->all(),
        ];
    }

    protected function getOptions(): RawJs
    {
        // Landlords are whole people: forcing an integer step stops Chart.js
        // labelling the left axis 0, 0.5, 1 when a month adds a single signup.
        $landlords = self::jsString(__('Landlords'));

        return RawJs::make(<<<JS
            {
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: {
                        position: 'left',
                        beginAtZero: true,
                        ticks: { precision: 0 },
                        title: { display: true, text: {$landlords} },
                    },
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        grid: { drawOnChartArea: false },
                        ticks: { callback: (value) => '\$' + value },
                    },
                },
            }
            JS);
    }

    /**
     * A translated label as a single-quoted JS string literal.
     *
     * Filament renders chart options into an x-data="..." HTML attribute, and
     * emits a RawJs value unescaped. One double quote in here — which is exactly
     * what json_encode() produces — closes the attribute early and spills the
     * rest of the options onto the page as visible text.
     */
    private static function jsString(string $value): string
    {
        return "'".strtr($value, ['\\' => '\\\\', "'" => "\\'"])."'";
    }

    /** The last {@see self::MONTHS} month-starts, oldest first. */
    private function months(): Collection
    {
        return collect(range(self::MONTHS - 1, 0))
            ->map(fn (int $back) => now()->startOfMonth()->subMonths($back));
    }
}
