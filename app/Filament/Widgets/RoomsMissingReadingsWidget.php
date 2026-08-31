<?php

namespace App\Filament\Widgets;

use App\Enums\BillingType;
use App\Enums\RentalStatus;
use App\Filament\Pages\MonthlyBilling;
use App\Filament\Widgets\Concerns\HasActivePropertyScope;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Providers\Filament\LandlordPanelProvider;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Occupied rooms whose meter readings for this month are incomplete — the exact
 * set that will block the billing run.
 *
 * "Incomplete" means fewer distinct (room × metered utility) readings this month
 * than the property has active metered utilities, so a room with electricity in
 * but water missing shows up here rather than passing as done. Each property has
 * its own utility count, so the comparison is per-property; it is expressed as a
 * correlated subquery because a HAVING clause cannot live inside an OR group.
 */
class RoomsMissingReadingsWidget extends BaseWidget
{
    use HasActivePropertyScope;

    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    /** @var array<int, int>|null Active metered utilities per property, memoised per request. */
    private ?array $expectedByProperty = null;

    public function getHeading(): string
    {
        return __('Rooms still waiting on a meter reading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->query())
            ->columns([
                Tables\Columns\TextColumn::make('room_number')
                    ->label(__('Room'))
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('property.name')
                    ->label(__('Property'))
                    ->placeholder('—')
                    ->visible(fn (): bool => $this->activePropertyId() === null),

                Tables\Columns\TextColumn::make('activeRental.occupant_name')
                    ->label(__('Tenant'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('readings_this_month')
                    ->label(__('Readings'))
                    ->getStateUsing(fn (Unit $record): string => ((int) $record->readings_this_month)
                        .' / '.($this->expectedByProperty()[$record->property_id] ?? 0))
                    ->badge()
                    ->color(fn (Unit $record): string => (int) $record->readings_this_month === 0 ? 'danger' : 'warning'),
            ])
            ->paginated(false)
            ->defaultSort('room_number')
            ->emptyStateHeading(__('Every occupied room is read'))
            ->emptyStateDescription(__('Nothing is blocking this month’s billing run.'))
            ->emptyStateIcon('heroicon-o-check-badge')
            ->actions([
                Tables\Actions\Action::make('record')
                    ->label(__('Record'))
                    ->icon('heroicon-m-bolt')
                    ->color('primary')
                    ->url(fn (): string => MonthlyBilling::getUrl(panel: LandlordPanelProvider::ID)),
            ]);
    }

    private function query(): Builder
    {
        $expected = $this->expectedByProperty();

        $query = $this->scopeToActiveProperty(Unit::query())
            ->with(['property', 'activeRental'])
            ->whereHas('rentals', fn (Builder $q) => $q->where('status', RentalStatus::Active->value))
            // COUNT(DISTINCT …) via a select() override — withCount's own COUNT(*)
            // would double-count a room that was read twice for the same utility.
            ->withCount(['utilityUsages as readings_this_month' => fn ($q) => $q
                ->whereBetween('reading_date', [$this->cycleStart(), $this->cycleEnd()])
                ->select(DB::raw('COUNT(DISTINCT property_utility_id)')),
            ])
            ->limit(10);

        if ($expected === []) {
            return $query->whereRaw('1 = 0'); // nothing is metered — nothing can be missing
        }

        $readingCount = '(SELECT COUNT(DISTINCT uu.property_utility_id) FROM utility_usages uu'
            .' WHERE uu.unit_id = units.id AND uu.reading_date BETWEEN ? AND ?)';

        return $query->where(function (Builder $outer) use ($expected, $readingCount) {
            foreach ($expected as $propertyId => $utilityCount) {
                $outer->orWhere(fn (Builder $q) => $q
                    ->where('units.property_id', $propertyId)
                    ->whereRaw($readingCount.' < ?', [$this->cycleStart(), $this->cycleEnd(), $utilityCount]));
            }
        });
    }

    /**
     * Active metered utilities per property, for properties in the current scope.
     *
     * @return array<int, int>
     */
    private function expectedByProperty(): array
    {
        if ($this->expectedByProperty !== null) {
            return $this->expectedByProperty;
        }

        $query = PropertyUtility::query()
            ->where('is_active', true)
            ->where('billing_type', BillingType::Metered->value);

        $this->scopeToActiveProperty($query);

        return $this->expectedByProperty = $query
            ->selectRaw('property_id, COUNT(*) as aggregate')
            ->groupBy('property_id')
            ->pluck('aggregate', 'property_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function cycleStart(): string
    {
        return now()->startOfMonth()->toDateString();
    }

    private function cycleEnd(): string
    {
        return now()->endOfMonth()->toDateString();
    }
}
