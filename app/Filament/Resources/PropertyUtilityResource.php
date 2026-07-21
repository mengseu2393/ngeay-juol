<?php

namespace App\Filament\Resources;

use App\Enums\BillingType;
use App\Enums\ReadingType;
use App\Filament\Concerns\ScopesToActiveProperty;
use App\Filament\Resources\PropertyUtilityResource\Pages;
use App\Filament\Resources\PropertyUtilityResource\RelationManagers;
use App\Models\PropertyUtility;
use App\Models\ChargeRule;
use App\Models\Rental;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Models\UtilityWaiver;
use App\Services\ChargeRuleResolver;
use App\Services\MeterReadingResolver;
use App\Support\ActiveProperty;
use App\Support\Money;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The per-property utility *catalog* (Electricity, Water, … with their rates and
 * billing rules) — what ManageUtilities used to manage inside the property
 * workspace. Distinct from UtilityUsageResource, which holds metered readings.
 */
class PropertyUtilityResource extends Resource
{
    use ScopesToActiveProperty;

    protected static ?string $model = PropertyUtility::class;

    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';

    protected static ?int $navigationSort = 5;

    protected static function propertyContextFallbackGroup(): ?string
    {
        return 'Utilities';
    }

    public static function getNavigationLabel(): string
    {
        return __('Utilities');
    }

    public static function getModelLabel(): string
    {
        return __('Utility');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('property_id')
                ->relationship('property', 'name')
                ->default(fn () => ActiveProperty::id())
                ->hidden(fn () => ActiveProperty::id() !== null)
                ->dehydrated()
                ->searchable()->preload()
                ->required(fn () => ActiveProperty::id() === null),
            Forms\Components\Select::make('name')
                ->label(__('Utility name'))
                ->options(fn (?PropertyUtility $record) => static::utilityOptionsForRecord($record))
                ->default('Electricity')
                ->searchable()
                ->native(false)
                ->selectablePlaceholder(false)
                ->required()
                ->live()
                ->afterStateUpdated(fn ($state, Forms\Set $set) => static::applyUtilityDefaults($state, $set)),
            Forms\Components\TextInput::make('unit_of_measure')
                ->label(__('Unit of measure'))
                ->required()
                ->default('kWh'),
            Forms\Components\Select::make('billing_type')->options(BillingType::class)->default(BillingType::Metered)->required(),
            Forms\Components\Grid::make(2)
                ->schema([
                    Forms\Components\TextInput::make('rate')
                        ->numeric()
                        ->step(0.0001)
                        ->required()
                        ->helperText(__('Per unit (metered) or fixed amount (flat).')),
                    Forms\Components\Select::make('currency')
                        ->label(__('Currency'))
                        ->options([
                            'USD' => 'USD ($)',
                            'KHR' => 'KHR (៛)',
                        ])
                        ->default(fn () => ActiveProperty::id() ? Money::forPropertyId(ActiveProperty::id()) : 'USD')
                        ->required(),
                ]),
            Forms\Components\TextInput::make('provider')->placeholder('e.g. EDC, PPWSA'),
            Forms\Components\TextInput::make('account_ref')->label(__('Account #')),
            Forms\Components\Toggle::make('is_active')->default(true),
            Forms\Components\Textarea::make('notes')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->formatStateUsing(fn ($state) => static::utilityLabel((string) $state))
                    ->searchable(),
                Tables\Columns\TextColumn::make('billing_type')->badge(),
                Tables\Columns\TextColumn::make('rate')
                    ->formatStateUsing(fn ($state, PropertyUtility $record) => Money::formatForRecord($state, $record)),
                Tables\Columns\TextColumn::make('unit_of_measure')->label(__('Unit')),
                Tables\Columns\TextColumn::make('provider')->placeholder('—')->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    static::initializeReadingsAction(),
                    static::resetReadingsAction(),
                    static::manageApplicabilityAction(),
                    static::addWaiverAction(),
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

    /** @return array<string, string> */
    protected static function utilityOptions(): array
    {
        return [
            'Electricity' => __('Electricity'),
            'Water' => __('Water'),
            'Gas' => __('Gas'),
            'Internet' => __('Internet'),
            'Trash' => __('Trash'),
            'Cleaning' => __('Cleaning'),
            'Parking' => __('Parking'),
            'Security' => __('Security'),
            'Other' => __('Other'),
        ];
    }

    /** @return array<string, string> */
    protected static function utilityOptionsForRecord(?PropertyUtility $record): array
    {
        $options = static::utilityOptions();
        $name = (string) ($record?->name ?? '');

        if ($name !== '' && ! array_key_exists($name, $options)) {
            $options[$name] = $name;
        }

        return $options;
    }

    protected static function utilityLabel(string $name): string
    {
        return static::utilityOptions()[$name] ?? $name;
    }

    protected static function applyUtilityDefaults(mixed $state, Forms\Set $set): void
    {
        $defaults = match ((string) $state) {
            'Electricity' => ['unit' => 'kWh', 'billing' => BillingType::Metered],
            'Water' => ['unit' => 'm³', 'billing' => BillingType::Metered],
            'Gas' => ['unit' => 'm³', 'billing' => BillingType::Metered],
            'Internet' => ['unit' => 'month', 'billing' => BillingType::Flat],
            'Trash' => ['unit' => 'month', 'billing' => BillingType::Flat],
            'Cleaning' => ['unit' => 'month', 'billing' => BillingType::Flat],
            'Parking' => ['unit' => 'month', 'billing' => BillingType::Flat],
            'Security' => ['unit' => 'month', 'billing' => BillingType::Flat],
            default => ['unit' => 'unit', 'billing' => BillingType::Flat],
        };

        $set('unit_of_measure', $defaults['unit']);
        $set('billing_type', $defaults['billing']->value);
    }

    protected static function addWaiverAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('addWaiver')
            ->label(__('Add waiver'))
            ->icon('heroicon-o-receipt-percent')
            ->color('warning')
            ->modalWidth('lg')
            ->modalHeading(fn ($record) => __('Add waiver — :utility', ['utility' => $record->name]))
            ->modalSubmitActionLabel(__('Save waiver'))
            ->form(function ($record): array {
                $propertyId = $record->property_id;

                return [
                    Forms\Components\Toggle::make('waived')->default(true)
                        ->label(__('Waived'))
                        ->helperText(__('On = this utility is not charged for the scope below.')),

                    Forms\Components\Toggle::make('apply_all')->default(false)
                        ->label(__('Apply to all rooms'))
                        ->helperText(__('Creates one property-wide waiver (ignores room selection below).'))
                        ->live(),

                    Forms\Components\CheckboxList::make('unit_ids')
                        ->label(__('Select rooms'))
                        ->options(fn () => Unit::where('property_id', $propertyId)
                            ->orderBy('room_number')
                            ->pluck('room_number', 'id'))
                        ->columns(2)
                        ->searchable()
                        ->bulkToggleable()
                        ->hidden(fn (Forms\Get $get) => $get('apply_all')),
                ];
            })
            ->action(function ($record, array $data): void {
                $applyAll = $data['apply_all'] ?? false;
                $waived = $data['waived'] ?? true;
                $base = [
                    'property_utility_id' => $record->getKey(),
                    'property_id' => $record->property_id,
                    'created_by_id' => auth()->id(),
                    'waived' => $waived,
                ];

                $count = 0;

                if ($applyAll) {
                    // One property-wide waiver (no unit/rental scope)
                    UtilityWaiver::create($base);
                    $count = 1;
                } else {
                    $unitIds = $data['unit_ids'] ?? [];
                    if (empty($unitIds)) {
                        // No rooms selected → property-wide
                        UtilityWaiver::create($base);
                        $count = 1;
                    } else {
                        foreach ($unitIds as $unitId) {
                            UtilityWaiver::create(array_merge($base, [
                                'unit_id' => $unitId,
                            ]));
                            $count++;
                        }
                    }
                }

                Notification::make()
                    ->title(trans_choice(':count waiver added|:count waivers added', $count, ['count' => $count]))
                    ->success()
                    ->send();
            });
    }

    protected static function manageApplicabilityAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('manageApplicability')
            ->label(__('Manage applicability'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('primary')
            ->modalWidth('xl')
            ->modalHeading(fn ($record) => __('Manage applicability — :utility', ['utility' => $record->name]))
            ->modalSubmitActionLabel(__('Apply rules'))
            ->form(function ($record): array {
                $propertyId = $record->property_id;

                return [
                    Forms\Components\Select::make('target_scope')
                        ->label(__('Apply to'))
                        ->options([
                            'property' => __('All rooms in the property'),
                            'unit' => __('Selected rooms'),
                            'rental' => __('Selected rentals'),
                        ])
                        ->default('property')
                        ->required()
                        ->live(),

                    Forms\Components\Toggle::make('apply_all')
                        ->label(__('Apply to all rooms'))
                        ->default(true)
                        ->visible(fn (Forms\Get $get) => $get('target_scope') !== 'property')
                        ->helperText(__('Turn this on to apply the rule to every room in the active property.'))
                        ->live(),

                    Forms\Components\Toggle::make('occupied_only')
                        ->label(__('Active / occupied rooms only'))
                        ->default(false)
                        ->visible(fn (Forms\Get $get) => in_array($get('target_scope'), ['unit', 'rental'], true))
                        ->helperText(__('Only include rooms that currently have an active tenancy.')),

                    Forms\Components\CheckboxList::make('unit_ids')
                        ->label(__('Select rooms'))
                        ->options(fn () => Unit::where('property_id', $propertyId)->orderBy('room_number')->pluck('room_number', 'id'))
                        ->columns(2)
                        ->searchable()
                        ->bulkToggleable()
                        ->visible(fn (Forms\Get $get) => $get('target_scope') === 'unit' && ! $get('apply_all'))
                        ->helperText(__('Choose the rooms to update.')),

                    Forms\Components\CheckboxList::make('rental_ids')
                        ->label(__('Select rentals'))
                        ->options(fn () => Rental::whereHas('unit', fn ($q) => $q->where('property_id', $propertyId))
                            ->with('unit')->orderBy('id')->get()
                            ->mapWithKeys(fn ($r) => [$r->id => '#'.$r->id.' · '.($r->unit?->room_number ?? '').' · '.($r->occupant_name ?? '')]))
                        ->columns(2)
                        ->searchable()
                        ->bulkToggleable()
                        ->visible(fn (Forms\Get $get) => $get('target_scope') === 'rental' && ! $get('apply_all'))
                        ->helperText(__('Choose the rentals to update.')),

                    Forms\Components\Select::make('state')
                        ->label(__('Applicability / State'))
                        ->options([
                            'normal' => ChargeRuleResolver::stateLabel('normal'),
                            'free' => ChargeRuleResolver::stateLabel('free'),
                            'waived' => ChargeRuleResolver::stateLabel('waived'),
                            'not_applicable' => ChargeRuleResolver::stateLabel('not_applicable'),
                            'skipped_this_cycle' => ChargeRuleResolver::stateLabel('skipped_this_cycle'),
                            'custom' => ChargeRuleResolver::stateLabel('custom'),
                        ])
                        ->default('normal')
                        ->required()
                        ->live()
                        ->helperText(fn (Forms\Get $get) => ChargeRuleResolver::stateHelpText((string) $get('state'))),

                    Forms\Components\TextInput::make('amount_override')
                        ->label(__('Custom amount'))
                        ->numeric()
                        ->visible(fn (Forms\Get $get) => $get('state') === 'custom')
                        ->required(fn (Forms\Get $get) => $get('state') === 'custom'),

                    Forms\Components\Select::make('currency_override')
                        ->label(__('Custom currency'))
                        ->options([
                            'USD' => 'USD ($)',
                            'KHR' => 'KHR (៛)',
                        ])
                        ->visible(fn (Forms\Get $get) => $get('state') === 'custom')
                        ->required(fn (Forms\Get $get) => $get('state') === 'custom'),

                    Forms\Components\DatePicker::make('effective_from')
                        ->label(__('Effective from')),

                    Forms\Components\DatePicker::make('effective_until')
                        ->label(__('Effective until')),

                    Forms\Components\TextInput::make('reason')
                        ->label(__('Reason'))
                        ->placeholder(__('e.g. No motorbike, maintenance issue'))
                        ->columnSpanFull(),
                ];
            })
            ->action(function ($record, array $data): void {
                $targets = [];
                $propertyId = $record->property_id;
                $scope = $data['target_scope'] ?? 'property';
                $applyAll = (bool) ($data['apply_all'] ?? false);
                $occupiedOnly = (bool) ($data['occupied_only'] ?? false);

                if ($scope === 'property') {
                    $targets[] = ['scope_type' => 'property', 'scope_id' => $propertyId];
                } elseif ($scope === 'unit') {
                    $query = Unit::where('property_id', $propertyId);
                    if ($occupiedOnly) {
                        $query->whereHas('activeRental');
                    }
                    $ids = $applyAll ? $query->pluck('id')->all() : ($data['unit_ids'] ?? []);
                    foreach ($ids as $unitId) {
                        $targets[] = ['scope_type' => 'unit', 'scope_id' => (int) $unitId];
                    }
                } elseif ($scope === 'rental') {
                    $query = Rental::whereHas('unit', fn ($q) => $q->where('property_id', $propertyId));
                    if ($occupiedOnly) {
                        $query->where('status', \App\Enums\RentalStatus::Active->value);
                    }
                    $ids = $applyAll ? $query->pluck('id')->all() : ($data['rental_ids'] ?? []);
                    foreach ($ids as $rentalId) {
                        $targets[] = ['scope_type' => 'rental', 'scope_id' => (int) $rentalId];
                    }
                }

                if ($targets === []) {
                    Notification::make()
                        ->warning()
                        ->title(__('Select at least one room or rental.'))
                        ->send();

                    return;
                }

                $created = 0;
                foreach ($targets as $target) {
                    ChargeRule::create([
                        'charge_definition_id' => $record->charge_definition_id,
                        'property_utility_id' => $record->getKey(),
                        'landlord_id' => $record->landlord_id,
                        'property_id' => $propertyId,
                        'scope_type' => $target['scope_type'],
                        'scope_id' => $target['scope_id'],
                        'state' => $data['state'] ?? 'normal',
                        'amount_override' => $data['amount_override'] ?? null,
                        'currency_override' => $data['currency_override'] ?? null,
                        'effective_from' => $data['effective_from'] ?? null,
                        'effective_until' => $data['effective_until'] ?? null,
                        'reason' => $data['reason'] ?? null,
                        'created_by_id' => auth()->id(),
                    ]);
                    $created++;
                }

                Notification::make()
                    ->success()
                    ->title(__(':count rule(s) applied', ['count' => $created]))
                    ->send();
            });
    }

    protected static function initializeReadingsAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('initializeReadings')
            ->label(__('Initialize readings'))
            ->icon('heroicon-o-bolt')
            ->color('warning')
            ->visible(fn ($record) => $record->billing_type === BillingType::Metered)
            ->modalWidth('lg')
            ->modalHeading(fn ($record) => __('Initialize readings — :utility', ['utility' => $record->name]))
            ->modalSubmitActionLabel(__('Initialize'))
            ->form(function ($record): array {
                $units = Unit::where('property_id', $record->property_id)
                    ->orderBy('room_number')
                    ->get();

                if ($units->isEmpty()) {
                    return [
                        Forms\Components\Placeholder::make('no_units')
                            ->label('')
                            ->content(__('No rooms are set up for this property yet.')),
                    ];
                }

                $existingUnitIds = UtilityUsage::where('property_utility_id', $record->id)
                    ->pluck('unit_id')
                    ->toArray();

                $schema = [
                    Forms\Components\DatePicker::make('reading_date')
                        ->label(__('Reading date'))
                        ->default(now())
                        ->required()
                        ->maxDate(now()),
                ];

                foreach ($units as $unit) {
                    $hasReading = in_array($unit->id, $existingUnitIds);
                    $uom = $record->unit_of_measure;

                    $input = Forms\Components\TextInput::make("units.{$unit->id}")
                        ->label(__('Room :room', ['room' => $unit->room_number]))
                        ->numeric()
                        ->minValue(0)
                        ->step('0.001')
                        ->maxValue(999999999)
                        ->suffix($uom);

                    if ($hasReading) {
                        $latest = UtilityUsage::where('unit_id', $unit->id)
                            ->where('property_utility_id', $record->id)
                            ->orderByDesc('reading_date')
                            ->orderByDesc('id')
                            ->first();
                        $latestVal = $latest ? rtrim(rtrim(number_format((float) $latest->new_reading, 3), '0'), '.') : '';

                        $input->disabled()
                            ->dehydrated(false)
                            ->helperText(__('Reading already exists (:value)', ['value' => $latestVal]));
                    } else {
                        $input->helperText(__('Sets the starting baseline (no consumption billed).'));
                    }

                    $schema[] = $input;
                }

                return $schema;
            })
            ->action(function ($record, array $data): void {
                $units = Unit::where('property_id', $record->property_id)
                    ->with('activeRental')
                    ->get()
                    ->keyBy('id');

                $saved = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $record, $units): int {
                    $count = 0;
                    $unitsData = $data['units'] ?? [];

                    $existingUnitIds = UtilityUsage::where('property_utility_id', $record->id)
                        ->pluck('unit_id')
                        ->toArray();

                    foreach ($unitsData as $unitId => $value) {
                        if ($value === null || $value === '') {
                            continue;
                        }
                        $unitId = (int) $unitId;
                        if (in_array($unitId, $existingUnitIds)) {
                            continue;
                        }
                        $unit = $units->get($unitId);
                        if (! $unit) {
                            continue;
                        }

                        UtilityUsage::create([
                            'unit_id' => $unitId,
                            'property_utility_id' => $record->getKey(),
                            'rental_id' => $unit->activeRental?->getKey(),
                            'reading_type' => ReadingType::Actual->value,
                            'reading_date' => $data['reading_date'],
                            'old_reading' => $value,
                            'new_reading' => $value,
                            'amount_used' => 0,
                            'recorded_by_id' => auth()->id(),
                        ]);
                        $count++;
                    }

                    return $count;
                });

                Notification::make()
                    ->title($saved
                        ? __(':count opening reading(s) initialized', ['count' => $saved])
                        : __('No readings entered.'))
                    ->{$saved ? 'success' : 'warning'}()
                    ->send();
            });
    }

    /**
     * Meter replacement: the physical meter changed, so its counter restarts
     * (usually at 0) and the old counter must never be subtracted again.
     *
     * Billing always reads the *latest* usage row's new_reading as the next
     * cycle's old_reading (see MonthlyBilling::buildRoomRow), so a reset is
     * simply a fresh baseline row — old_reading = new_reading = the new meter's
     * starting value, amount_used = 0 — dated today. Anything still un-invoiced
     * on the old meter is gone after this, hence the warning in the modal.
     */
    protected static function resetReadingsAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('resetReadings')
            ->label(__('Reset meter (replacement)'))
            ->icon('heroicon-o-arrow-path')
            ->color('danger')
            ->visible(fn ($record) => $record->billing_type === BillingType::Metered)
            ->modalWidth('lg')
            ->modalHeading(fn ($record) => __('Reset meter — :utility', ['utility' => $record->name]))
            ->modalDescription(__('Use this when a meter was physically replaced. Issue the current invoice FIRST — any consumption on the old meter that has not been billed yet is lost once you reset.'))
            ->modalSubmitActionLabel(__('Reset meters'))
            ->form(function ($record): array {
                $units = Unit::where('property_id', $record->property_id)
                    ->orderBy('room_number')
                    ->get();

                $latestByUnit = static::latestUsagesByUnit($record, $units->pluck('id')->all());

                if ($latestByUnit->isEmpty()) {
                    return [
                        Forms\Components\Placeholder::make('no_readings')
                            ->label('')
                            ->content(__('No readings recorded yet — use “Initialize readings” to set the opening baseline instead.')),
                    ];
                }

                $schema = [
                    Forms\Components\DatePicker::make('reading_date')
                        ->label(__('Replacement date'))
                        ->default(now())
                        ->required()
                        ->maxDate(now()),
                ];

                foreach ($units as $unit) {
                    $latest = $latestByUnit->get($unit->id);
                    if (! $latest) {
                        continue;
                    }

                    $schema[] = Forms\Components\TextInput::make("units.{$unit->id}")
                        ->label(__('Room :room', ['room' => $unit->room_number]))
                        ->numeric()
                        ->minValue(0)
                        ->step('0.001')
                        ->maxValue(999999999)
                        ->suffix($record->unit_of_measure)
                        ->placeholder('0')
                        ->helperText(__('Last reading :value — leave blank to keep this meter unchanged.', [
                            'value' => static::formatReading($latest->new_reading).' '.$record->unit_of_measure,
                        ]));
                }

                return $schema;
            })
            ->action(function ($record, array $data): void {
                $units = Unit::where('property_id', $record->property_id)
                    ->with('activeRental')
                    ->get()
                    ->keyBy('id');

                $saved = \Illuminate\Support\Facades\DB::transaction(function () use ($data, $record, $units): int {
                    $count = 0;
                    $latestByUnit = static::latestUsagesByUnit($record, $units->keys()->all());

                    foreach (($data['units'] ?? []) as $unitId => $value) {
                        if ($value === null || $value === '') {
                            continue;
                        }
                        $unitId = (int) $unitId;
                        $unit = $units->get($unitId);
                        // Only rooms that already have a meter history can be reset;
                        // the rest belong to "Initialize readings".
                        if (! $unit || ! $latestByUnit->has($unitId)) {
                            continue;
                        }

                        // Rooms that have a real meter get a real replacement —
                        // the old device is retired with its final reading and
                        // the new one opens at $value. Rooms without one keep the
                        // original behaviour: a fresh zero-usage baseline row.
                        $resolver = app(MeterReadingResolver::class);
                        if ($meter = $resolver->activeMeter($unitId, $record->getKey())) {
                            $resolver->replace($meter, $data['reading_date'], (float) $value);
                            $count++;

                            continue;
                        }

                        UtilityUsage::create([
                            'unit_id' => $unitId,
                            'property_utility_id' => $record->getKey(),
                            'rental_id' => $unit->activeRental?->getKey(),
                            'reading_type' => ReadingType::Actual->value,
                            'reading_date' => $data['reading_date'],
                            'old_reading' => $value,
                            'new_reading' => $value,
                            'amount_used' => 0,
                            'recorded_by_id' => auth()->id(),
                        ]);
                        $count++;
                    }

                    return $count;
                });

                Notification::make()
                    ->title($saved
                        ? __(':count meter(s) reset — next invoice starts from the new baseline', ['count' => $saved])
                        : __('No meters reset.'))
                    ->{$saved ? 'success' : 'warning'}()
                    ->send();
            });
    }

    /**
     * Latest usage row per unit for one utility, keyed by unit_id. Mirrors the
     * ordering billing uses to pick the previous reading (date, then id).
     *
     * @param  array<int, int>  $unitIds
     * @return \Illuminate\Support\Collection<int, UtilityUsage>
     */
    protected static function latestUsagesByUnit(PropertyUtility $utility, array $unitIds): \Illuminate\Support\Collection
    {
        if ($unitIds === []) {
            return collect();
        }

        return UtilityUsage::where('property_utility_id', $utility->getKey())
            ->whereIn('unit_id', $unitIds)
            ->orderBy('reading_date')
            ->orderBy('id')
            ->get()
            ->keyBy('unit_id'); // keyBy keeps the last row per unit — the latest
    }

    protected static function formatReading(mixed $value): string
    {
        $formatted = rtrim(rtrim(number_format((float) $value, 3), '0'), '.');

        return $formatted === '' ? '0' : $formatted; // a 0 reading trims down to an empty string
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MetersRelationManager::class,
            RelationManagers\WaiversRelationManager::class,
            RelationManagers\ChargeRulesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPropertyUtilities::route('/'),
            'create' => Pages\CreatePropertyUtility::route('/create'),
            'edit' => Pages\EditPropertyUtility::route('/{record}/edit'),
        ];
    }
}
