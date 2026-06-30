<?php

namespace App\Filament\Resources;

use App\Enums\ReadingType;
use App\Filament\Concerns\ScopesToActiveProperty;
use App\Filament\Pages\ConsumptionHistory;
use App\Filament\Resources\UtilityUsageResource\Pages;
use App\Models\UtilityUsage;
use App\Support\ActiveProperty;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UtilityUsageResource extends Resource
{
    use ScopesToActiveProperty;

    protected static ?string $model = UtilityUsage::class;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';

    protected static ?int $navigationSort = 7;

    protected static function propertyContextFallbackGroup(): ?string
    {
        return 'Utilities';
    }

    /** Usage rows carry no property_id — reach the property through the unit. */
    protected static function applyActivePropertyScope(Builder $query, int $propertyId): void
    {
        $query->whereHas('unit', fn (Builder $q) => $q->where('property_id', $propertyId));
    }

    public static function getModelLabel(): string
    {
        return __('Utility usage');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('unit_id')
                ->relationship(
                    'unit',
                    'room_number',
                    fn ($query) => ActiveProperty::id()
                        ? $query->where('property_id', ActiveProperty::id())
                        : $query,
                )
                ->searchable()->preload()->required()
                ->live(),
            Forms\Components\Select::make('property_utility_id')
                ->label('Utility')
                // scoped to the selected unit's property
                ->options(function (Forms\Get $get) {
                    $unitId = $get('unit_id');
                    if (! $unitId) {
                        return [];
                    }
                    $propertyId = \App\Models\Unit::whereKey($unitId)->value('property_id');

                    return \App\Models\PropertyUtility::where('property_id', $propertyId)->pluck('name', 'id');
                })
                ->searchable()->required(),
            Forms\Components\Select::make('rental_id')
                ->relationship('rental', 'id')
                ->searchable()->label('Rental'),
            Forms\Components\Hidden::make('recorded_by_id')->default(fn () => auth()->id()),
            Forms\Components\Select::make('reading_type')
                ->options(ReadingType::class)
                ->default(ReadingType::Actual),
            Forms\Components\DatePicker::make('reading_date'),
            Forms\Components\TextInput::make('old_reading')->numeric(),
            Forms\Components\TextInput::make('new_reading')->numeric(),
            Forms\Components\TextInput::make('amount_used')->numeric()->required(),
            Forms\Components\Toggle::make('is_waived'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'propertyUtility:id,name,unit_of_measure,rate',
                'unit:id,room_number',
            ]))
            ->defaultSort('reading_date', 'desc')
            ->defaultGroup('reading_date')
            ->columns([
                Tables\Columns\TextColumn::make('propertyUtility.name')
                    ->label('Utility')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('unit.room_number')
                    ->label('Unit')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\ColumnGroup::make('Meter reading', [
                    Tables\Columns\TextColumn::make('old_reading')->label('Previous')
                        ->alignEnd()->color('gray')
                        ->formatStateUsing(fn ($state, UtilityUsage $record) => static::reading($state, $record)),
                    Tables\Columns\TextColumn::make('new_reading')->label('Current')
                        ->alignEnd()->color('gray')
                        ->formatStateUsing(fn ($state, UtilityUsage $record) => static::reading($state, $record)),
                    Tables\Columns\TextColumn::make('amount_used')->label('Used')
                        ->alignEnd()->weight('bold')->sortable()
                        ->color(fn (UtilityUsage $record) => static::isSpike($record) ? 'danger' : null)
                        ->icon(fn (UtilityUsage $record) => static::isSpike($record) ? 'heroicon-m-exclamation-triangle' : null)
                        ->tooltip(fn (UtilityUsage $record) => static::isSpike($record) ? __('Unusual spike vs this unit & utility\'s average') : null)
                        ->formatStateUsing(fn ($state, UtilityUsage $record) => static::reading($state, $record))
                        ->summarize(Sum::make()->label(__('Total'))
                            ->formatStateUsing(fn ($state) => static::fmt($state))),
                ]),
                Tables\Columns\ColumnGroup::make('Billing', [
                    Tables\Columns\TextColumn::make('amount_billed')
                        ->label('Amount')
                        ->alignEnd()
                        ->money('USD')
                        ->state(fn (UtilityUsage $record) => $record->propertyUtility?->rate
                            ? round((float) $record->amount_used * (float) $record->propertyUtility->rate, 2)
                            : null)
                        ->placeholder('—'),
                    Tables\Columns\IconColumn::make('is_waived')
                        ->label('Waived')
                        ->boolean(),
                ]),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('property_utility_id')->label('Utility')
                    ->relationship('propertyUtility', 'name')->searchable()->preload(),
                Tables\Filters\SelectFilter::make('unit_id')->label('Unit')
                    ->relationship('unit', 'room_number')->searchable()->preload(),
                Tables\Filters\SelectFilter::make('reading_type')->options(ReadingType::class),
                Tables\Filters\TernaryFilter::make('is_waived'),
                Tables\Filters\Filter::make('reading_date')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label(__('From')),
                        Forms\Components\DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('reading_date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('reading_date', '<=', $d))),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    // Jump straight to the 12-month view for this row's unit + utility.
                    Tables\Actions\Action::make('history')
                        ->label(__('History'))
                        ->icon('heroicon-o-chart-bar')
                        ->color('gray')
                        ->url(fn (UtilityUsage $record) => ConsumptionHistory::getUrl([
                            'unit' => $record->unit_id,
                            'utility' => $record->property_utility_id,
                            'year' => $record->reading_date?->year ?? now()->year,
                        ], panel: 'landlord')),
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                    Tables\Actions\DeleteAction::make(),
                ])->icon('heroicon-m-ellipsis-vertical')->label(null)->color('gray'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Format a stored decimal(…,3) reading with its utility's unit of measure ("1,251 kWh"). */
    protected static function reading($state, UtilityUsage $record): string
    {
        if ($state === null || $state === '') {
            return '—';
        }
        $uom = $record->propertyUtility?->unit_of_measure;

        return trim(static::fmt($state).' '.($uom ?? ''));
    }

    /** Trim a decimal(…,3) to a clean, thousands-separated string ("1,251", "11", "1.5"). */
    protected static function fmt($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    }

    /**
     * A reading is a "spike" when its usage is > 1.5× the average for the SAME unit +
     * utility (needs ≥3 readings to judge). Averages are loaded once per request into a
     * static map — a single grouped query — so flagging never causes N+1.
     *
     * @var array<string, array{a: float, n: int}>|null
     */
    protected static ?array $avgCache = null;

    protected static function isSpike(UtilityUsage $record): bool
    {
        if ((float) $record->amount_used <= 0) {
            return false;
        }

        if (static::$avgCache === null) {
            static::$avgCache = [];
            UtilityUsage::query()
                ->where('amount_used', '>', 0)
                ->selectRaw('unit_id, property_utility_id, AVG(amount_used) as a, COUNT(*) as n')
                ->groupBy('unit_id', 'property_utility_id')
                ->get()
                ->each(function ($row) {
                    static::$avgCache[$row->unit_id.':'.$row->property_utility_id] = [
                        'a' => (float) $row->a,
                        'n' => (int) $row->n,
                    ];
                });
        }

        $e = static::$avgCache[$record->unit_id.':'.$record->property_utility_id] ?? null;

        // Compare against the average of the OTHER readings (exclude this one) so a
        // single high reading doesn't inflate its own baseline.
        if (! $e || $e['n'] < 3) {
            return false;
        }
        $others = ($e['a'] * $e['n'] - (float) $record->amount_used) / ($e['n'] - 1);

        return $others > 0 && (float) $record->amount_used > 1.5 * $others;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUtilityUsages::route('/'),
            'create' => Pages\CreateUtilityUsage::route('/create'),
            'edit' => Pages\EditUtilityUsage::route('/{record}/edit'),
        ];
    }
}
