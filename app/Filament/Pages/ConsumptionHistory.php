<?php

namespace App\Filament\Pages;

use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Support\ActiveProperty;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\Page;

/**
 * A full-year (12-month) consumption view for one unit + utility. Pick a unit,
 * a utility and a year and see month-by-month Previous → Current → Used with an
 * inline trend bar, % change vs the prior month, and an automatic spike flag.
 * Reuses the existing meter-reading chain on {@see UtilityUsage} — no new data.
 */
class ConsumptionHistory extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 8;

    protected static string $view = 'filament.pages.consumption-history';

    public ?array $data = [];

    /** Sits in the active-property group when a property is selected, else under Utilities. */
    public static function getNavigationGroup(): ?string
    {
        return ActiveProperty::id() !== null ? ActiveProperty::NAV_GROUP : 'Utilities';
    }

    public static function getNavigationLabel(): string
    {
        return __('Consumption history');
    }

    public function getTitle(): string
    {
        return __('Consumption history');
    }

    /** Same permission as the readings list — whoever can see usages can review history. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('view_any_utility::usage');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess()
            && (ActiveProperty::id() !== null || (bool) auth()->user()?->isPlatformStaff());
    }

    public function mount(): void
    {
        // Prefill from a deep link (?unit=&utility=&year=, e.g. the table's "History"
        // row action) or fall back to the current year.
        $this->form->fill([
            'unit_id' => request()->integer('unit') ?: null,
            'property_utility_id' => request()->integer('utility') ?: null,
            'year' => request()->integer('year') ?: (int) now()->year,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\Select::make('unit_id')
                        ->label(__('Unit'))
                        ->options(fn () => Unit::query()
                            ->when(ActiveProperty::id(), fn ($q, $pid) => $q->where('property_id', $pid))
                            ->orderBy('room_number')
                            ->pluck('room_number', 'id'))
                        ->searchable()->required()->live()
                        // Changing the unit can invalidate the chosen utility.
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('property_utility_id', null)),
                    Forms\Components\Select::make('property_utility_id')
                        ->label(__('Utility'))
                        ->options(function (Get $get) {
                            $unitId = $get('unit_id');
                            if (! $unitId) {
                                return [];
                            }
                            $propertyId = Unit::whereKey($unitId)->value('property_id');

                            return PropertyUtility::where('property_id', $propertyId)->pluck('name', 'id');
                        })
                        ->searchable()->required()->live(),
                    Forms\Components\Select::make('year')
                        ->label(__('Year'))
                        ->options($this->yearOptions())
                        ->default((int) now()->year)
                        ->required()->live(),
                ]),
            ])
            ->statePath('data');
    }

    /** @return array<int, int> recent years, newest first */
    protected function yearOptions(): array
    {
        $current = (int) now()->year;
        $years = [];
        for ($y = $current; $y >= $current - 6; $y--) {
            $years[$y] = $y;
        }

        return $years;
    }

    /** Trim a stored decimal(…,3) to a clean, thousands-separated string ("1,251", "11", "1.5"). */
    public function fmt(float|int|string|null $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }

        return rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    }

    /**
     * Build the 12-month history for the selected unit + utility + year.
     *
     * @return array{hasSelection: bool, utility: ?PropertyUtility, rows: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function getHistory(): array
    {
        $unitId = $this->data['unit_id'] ?? null;
        $utilityId = $this->data['property_utility_id'] ?? null;
        $year = (int) ($this->data['year'] ?? now()->year);
        $utility = $utilityId ? PropertyUtility::find($utilityId) : null;

        if (! $unitId || ! $utilityId) {
            return ['hasSelection' => false, 'utility' => $utility, 'rows' => [], 'summary' => []];
        }

        $readings = UtilityUsage::query()
            ->where('unit_id', $unitId)
            ->where('property_utility_id', $utilityId)
            ->whereYear('reading_date', $year)
            ->orderBy('reading_date')->orderBy('id')
            ->get();

        // Bucket readings into calendar months (a month may hold more than one reading).
        $byMonth = [];
        foreach ($readings as $r) {
            $m = (int) ($r->reading_date?->month ?? 0);
            if ($m >= 1) {
                $byMonth[$m][] = $r;
            }
        }

        $rows = [];
        $usedValues = [];   // months that actually have readings (for avg/peak/spike)
        $prevUsed = null;
        for ($m = 1; $m <= 12; $m++) {
            $group = $byMonth[$m] ?? [];
            if ($group === []) {
                $rows[] = ['month' => $m, 'has' => false, 'old' => null, 'new' => null,
                    'used' => null, 'type' => null, 'waived' => false, 'delta' => null, 'spike' => false];

                continue;
            }

            $first = $group[0];
            $last = $group[count($group) - 1];
            $used = 0.0;
            $waived = false;
            foreach ($group as $g) {
                $used += (float) $g->amount_used;
                $waived = $waived || (bool) $g->is_waived;
            }

            $delta = ($prevUsed !== null && $prevUsed > 0) ? (($used - $prevUsed) / $prevUsed) * 100 : null;

            $rows[] = [
                'month' => $m, 'has' => true,
                'old' => (float) $first->old_reading,
                'new' => (float) $last->new_reading,
                'used' => $used,
                'type' => $last->reading_type,
                'waived' => $waived,
                'delta' => $delta,
                'spike' => false, // resolved below, once we know the year's average
            ];
            $prevUsed = $used;
            $usedValues[] = $used;
        }

        // Spike = a month notably above the average of the OTHER months with readings
        // (needs ≥3 data points to be meaningful). Waived/zero months never count.
        $total = array_sum($usedValues);
        if (count($usedValues) >= 3) {
            foreach ($rows as &$row) {
                if (! $row['has'] || $row['used'] <= 0) {
                    continue;
                }
                $avgOthers = (count($usedValues) - 1) > 0
                    ? ($total - $row['used']) / (count($usedValues) - 1)
                    : 0;
                if ($avgOthers > 0 && $row['used'] > 1.5 * $avgOthers) {
                    $row['spike'] = true;
                }
            }
            unset($row);
        }

        $peakMonth = null;
        $peakVal = 0.0;
        foreach ($rows as $row) {
            if ($row['has'] && $row['used'] > $peakVal) {
                $peakVal = $row['used'];
                $peakMonth = $row['month'];
            }
        }

        return [
            'hasSelection' => true,
            'utility' => $utility,
            'rows' => $rows,
            'summary' => [
                'total' => $total,
                'avg' => $usedValues ? $total / count($usedValues) : 0.0,
                'monthsWith' => count($usedValues),
                'peakMonth' => $peakMonth,
                'peakVal' => $peakVal,
                'maxUsed' => $usedValues ? max($usedValues) : 0.0,
                'waivedCount' => count(array_filter($rows, fn ($r) => $r['has'] && $r['waived'])),
            ],
        ];
    }
}
