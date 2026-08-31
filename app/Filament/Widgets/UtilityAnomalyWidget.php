<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\UtilityUsageResource;
use App\Filament\Widgets\Concerns\HasActivePropertyScope;
use App\Filament\Widgets\Concerns\OrdersByPrecomputedRank;
use App\Models\UtilityUsage;
use App\Providers\Filament\LandlordPanelProvider;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Rooms whose latest meter reading is far above their own recent normal.
 *
 * A jump like this is usually a leak, a meter misread, or a digit typed twice —
 * all cheaper to settle before the invoice goes out than to argue about after.
 * Each room is compared against its own history rather than a portfolio average,
 * because a family of six and a single tenant have nothing in common.
 */
class UtilityAnomalyWidget extends BaseWidget
{
    use HasActivePropertyScope;
    use OrdersByPrecomputedRank;

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    /** How far above the recent average counts as worth a look. */
    private const SPIKE_THRESHOLD = 1.4;

    /**
     * Prior readings averaged to form the baseline. Two is deliberate: meters are
     * read monthly, so demanding a longer history would leave the widget silent
     * for a landlord's first several months — exactly when a misread is likeliest.
     */
    private const MIN_HISTORY = 2;

    /** @var array<int, float>|null usage id => multiple of its own average */
    private ?array $spikes = null;

    public function getHeading(): string
    {
        return __('Unusual utility readings');
    }

    public function table(Table $table): Table
    {
        $spikes = $this->spikes();

        $query = UtilityUsage::query()
            ->with(['unit', 'propertyUtility'])
            ->whereIn('id', array_keys($spikes) ?: [0]);

        // spikes() is already sorted worst-first — keep that, not reading order.
        $this->orderByRank($query, array_keys($spikes));

        return $table
            ->query($query)
            ->columns([
                Tables\Columns\TextColumn::make('unit.room_number')
                    ->label(__('Room'))
                    ->weight('bold')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('propertyUtility.name')
                    ->label(__('Utility'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('reading_date')
                    ->label(__('Read on'))
                    ->date('d M Y'),

                Tables\Columns\TextColumn::make('amount_used')
                    ->label(__('Used'))
                    ->formatStateUsing(fn ($state, UtilityUsage $record): string => rtrim(rtrim(number_format((float) $state, 2), '0'), '.')
                        .' '.($record->propertyUtility?->unit_of_measure ?? ''))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('spike')
                    ->label(__('vs usual'))
                    ->getStateUsing(fn (UtilityUsage $record): string => '×'.number_format($spikes[$record->getKey()] ?? 1, 1))
                    ->badge()
                    ->color(fn (UtilityUsage $record): string => ($spikes[$record->getKey()] ?? 1) >= 2.0 ? 'danger' : 'warning')
                    ->alignEnd(),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('No unusual readings'))
            ->emptyStateDescription(__('Every room is consuming close to its own normal.'))
            ->emptyStateIcon('heroicon-o-check-badge')
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label(__('Open'))
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (UtilityUsage $record): string => UtilityUsageResource::getUrl('edit', ['record' => $record], panel: LandlordPanelProvider::ID)),
            ]);
    }

    /**
     * The comparison needs each room's history in order, which SQL cannot express
     * portably (no window functions on the oldest supported engines). The scan is
     * bounded: latest readings only, and only for rooms in the current scope.
     *
     * @return array<int, float> usage id => multiple of that room's own average
     */
    private function spikes(): array
    {
        if ($this->spikes !== null) {
            return $this->spikes;
        }

        $history = $this->scopeThroughRelation(
            UtilityUsage::query()->where('reading_date', '>=', now()->subMonths(6)->startOfMonth()),
            'unit',
        )
            ->where('is_waived', false)
            ->where('amount_used', '>', 0)
            ->orderBy('reading_date')
            ->get(['id', 'unit_id', 'property_utility_id', 'reading_date', 'amount_used'])
            ->groupBy(fn (UtilityUsage $usage) => $usage->unit_id.':'.$usage->property_utility_id);

        $spikes = [];

        foreach ($history as $readings) {
            if ($readings->count() <= self::MIN_HISTORY) {
                continue; // not enough of a baseline to call anything unusual
            }

            $latest = $readings->last();
            $baseline = $readings->slice(-1 - self::MIN_HISTORY, self::MIN_HISTORY);
            $average = (float) $baseline->avg('amount_used');

            if ($average <= 0.0) {
                continue;
            }

            $multiple = (float) $latest->amount_used / $average;

            if ($multiple >= self::SPIKE_THRESHOLD) {
                $spikes[$latest->getKey()] = round($multiple, 2);
            }
        }

        arsort($spikes);

        return $this->spikes = array_slice($spikes, 0, 8, true);
    }
}
