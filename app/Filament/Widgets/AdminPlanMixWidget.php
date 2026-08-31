<?php

namespace App\Filament\Widgets;

use App\Enums\SubscriptionStatus;
use App\Models\Scopes\LandlordScope;
use App\Models\Subscription;
use App\Support\Money;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which plans the paying landlords actually sit on.
 *
 * Counted over the statuses that still hold a seat — Pending, Trial and Active
 * ({@see SubscriptionStatus::isAccessible()}) — so a plan does not keep credit
 * for landlords who cancelled off it months ago.
 *
 * The subheading carries normalised MRR: quarterly and yearly prices divided
 * down to a monthly figure so a $199/year plan and a $9/month plan can be added
 * together at all. It is the one platform number that has no home elsewhere in
 * the panel — {@see AdminPlatformStatsWidget} reports cash banked this month,
 * which is a different question and frequently $0 mid-cycle.
 */
class AdminPlanMixWidget extends ChartWidget
{
    protected static ?int $sort = -35;

    protected static ?string $maxHeight = '260px';

    protected int|string|array $columnSpan = 1;

    /**
     * The panel's own emerald ramp rather than a rainbow — one plan mix is one
     * measure, so it reads as shades of the brand colour. Ordered light-dark-light
     * so neighbouring slices still separate; wraps if a seventh plan appears.
     */
    private const SLICE_COLORS = ['#10b981', '#065f46', '#6ee7b7', '#047857', '#a7f3d0', '#34d399'];

    /** @var Collection<int, Subscription>|null */
    private ?Collection $held = null;

    public static function canView(): bool
    {
        return auth()->user()?->isPlatformStaff() ?? false;
    }

    public function getHeading(): string
    {
        return __('Plan mix');
    }

    public function getDescription(): ?string
    {
        return __('Recurring revenue: :amount/mo', ['amount' => Money::format($this->mrr(), 'USD')]);
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $byPlan = $this->heldSubscriptions()
            ->groupBy(fn (Subscription $sub) => $sub->plan?->name ?? __('Unknown plan'))
            ->sortByDesc->count();

        return [
            'datasets' => [
                [
                    'label' => __('Landlords'),
                    'data' => $byPlan->map->count()->values()->all(),
                    'backgroundColor' => $byPlan->keys()
                        ->map(fn ($name, int $i) => self::SLICE_COLORS[$i % count(self::SLICE_COLORS)])
                        ->all(),
                    'borderWidth' => 0,
                ],
            ],
            'labels' => $byPlan->keys()->all(),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['position' => 'bottom', 'labels' => ['boxWidth' => 12, 'usePointStyle' => true]],
            ],
            'cutout' => '58%',
            // Filament's chart.js fills in options.scales.x/y for every widget
            // regardless of type (it only uses ??=, so it cannot tell a doughnut
            // from a line). Left alone, Chart.js honours them and draws a stray
            // 0–1 axis down the side of the ring.
            'scales' => [
                'x' => ['display' => false],
                'y' => ['display' => false],
            ],
        ];
    }

    /**
     * Every subscription still occupying a plan seat, with its plan loaded.
     * Memoised: the chart body and the MRR subheading both walk this set, and
     * Filament renders them in the same request.
     *
     * @return Collection<int, Subscription>
     */
    private function heldSubscriptions(): Collection
    {
        return $this->held ??= Subscription::withoutGlobalScope(LandlordScope::class)
            ->whereIn('status', array_map(
                fn (SubscriptionStatus $s) => $s->value,
                array_filter(SubscriptionStatus::cases(), fn (SubscriptionStatus $s) => $s->isAccessible()),
            ))
            ->with('plan')
            ->get();
    }

    /**
     * Monthly recurring revenue: each subscription's snapshotted price divided by
     * the months its interval covers. Trials priced at 0 contribute nothing, which
     * is the honest reading — they are not revenue until they convert.
     */
    private function mrr(): float
    {
        return round(
            $this->heldSubscriptions()->sum(
                fn (Subscription $sub) => (float) $sub->price / max(1, $sub->interval->months()),
            ),
            2,
        );
    }
}
