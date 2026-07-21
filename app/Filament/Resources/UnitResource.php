<?php

namespace App\Filament\Resources;

use App\Enums\BillingType;
use App\Enums\ReadingType;
use App\Enums\UnitStatus;
use App\Filament\Concerns\ScopesToActiveProperty;
use App\Filament\Resources\UnitResource\Pages;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\UtilityUsage;
use App\Services\MeterReadingResolver;
use App\Services\RoomAccountService;
use App\Support\ActiveProperty;
use App\Support\Money;
use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UnitResource extends Resource implements HasShieldPermissions
{
    use ScopesToActiveProperty;

    protected static ?string $model = Unit::class;

    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = 2;

    /**
     * Shield permissions for the Unit resource. The standard CRUD set PLUS a custom
     * `generate_rooms` prefix → a `generate_rooms_unit` permission that admins can
     * toggle per role at /admin/shield/roles to control the "Generate rooms" action.
     * ("New unit" is already gated by `create_unit` through UnitPolicy::create.)
     */
    public static function getPermissionPrefixes(): array
    {
        return [
            'view',
            'view_any',
            'create',
            'update',
            'restore',
            'restore_any',
            'replicate',
            'reorder',
            'delete',
            'delete_any',
            'force_delete',
            'force_delete_any',
            'generate_rooms',
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('Rooms');
    }

    public static function getModelLabel(): string
    {
        return __('Unit');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('property_id')
                ->relationship('property', 'name')
                // In a property context the property is implied — default + hide it.
                ->default(fn () => ActiveProperty::id())
                ->hidden(fn () => ActiveProperty::id() !== null)
                ->dehydrated()
                ->searchable()->preload()
                ->required(fn () => ActiveProperty::id() === null),
            Forms\Components\TextInput::make('room_number')->required(),
            Forms\Components\TextInput::make('floor_number'),
            Forms\Components\TextInput::make('room_type')->required(),
            Forms\Components\Grid::make(2)
                ->schema([
                    Forms\Components\TextInput::make('rent_amount')
                        ->label(__('Rent amount'))
                        ->numeric()
                        ->required(),
                    Forms\Components\Select::make('rent_currency')
                        ->label(__('Currency'))
                        ->options([
                            'USD' => 'USD ($)',
                            'KHR' => 'KHR (៛)',
                        ])
                        ->default(fn () => ActiveProperty::id() ? Money::forPropertyId(ActiveProperty::id()) : 'USD')
                        ->required(),
                ]),
            Forms\Components\DatePicker::make('due_date'),
            Forms\Components\Select::make('status')->options(UnitStatus::class)->default(UnitStatus::Available)->required(),
            Forms\Components\Textarea::make('description')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Property is implied in a property context — only show it cross-property.
                Tables\Columns\TextColumn::make('property.name')->label(__('Property'))
                    ->visible(fn () => ActiveProperty::id() === null)
                    ->searchable()->sortable(),
                Tables\Columns\TextColumn::make('floor_number')->label(__('Floor'))->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('room_number')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('room_type')->toggleable(),
                Tables\Columns\TextColumn::make('rent_amount')
                    ->formatStateUsing(fn ($state, Unit $record) => Money::formatForRecord($state, $record))
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()
                    // Click an occupied room's status to end its current tenancy.
                    ->action(static::endTenancyAction())
                    ->tooltip(fn (Unit $record) => $record->activeRental ? __('Click to end tenancy') : null),
                // Who currently rents the room (the active tenancy's occupant).
                Tables\Columns\TextColumn::make('activeRental.occupant_name')->label(__('Tenant'))
                    ->state(fn (Unit $record) => $record->activeRental?->occupant_name
                        ?: $record->activeRental?->tenant?->name)
                    ->description(fn (Unit $record) => $record->activeRental?->tenant?->username)
                    ->placeholder('— vacant —')
                    ->searchable(),
                Tables\Columns\TextColumn::make('account.username')->label(__('Login'))
                    ->placeholder('— no account —')->copyable()->toggleable(),
            ])
            ->defaultSort('room_number')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(UnitStatus::class),
                Tables\Filters\SelectFilter::make('property')->relationship('property', 'name')
                    ->visible(fn () => ActiveProperty::id() === null),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->headerActions([
                static::generateRoomsAction(),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make(),
                    static::meterReadingsAction(),
                    static::accountAction(),
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

    /**
     * End the room's current tenancy: a small modal that vacates (or expires) the
     * active rental, stamps its end date, and optionally frees the room. Only shown
     * when the room actually has an active tenancy.
     */
    protected static function endTenancyAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('endTenancy')
            ->label(__('End tenancy'))
            ->icon('heroicon-o-arrow-right-on-rectangle')
            ->color('warning')
            ->visible(fn (Unit $record) => $record->activeRental !== null)
            ->modalWidth('md')
            ->modalHeading(fn (Unit $record) => __('End tenancy for room :room', ['room' => $record->room_number]))
            ->modalDescription(fn (Unit $record) => __('Tenant').': '.($record->activeRental?->occupant_name
                ?: ($record->activeRental?->tenant?->name ?? '—')))
            ->modalSubmitActionLabel(__('End tenancy'))
            ->form([
                Forms\Components\DatePicker::make('end_date')
                    ->label(__('End date'))
                    ->default(now())
                    ->required(),
                Forms\Components\Select::make('status')
                    ->label(__('Outcome'))
                    ->options([
                        \App\Enums\RentalStatus::Vacated->value => \App\Enums\RentalStatus::Vacated->getLabel(),
                        \App\Enums\RentalStatus::Expired->value => \App\Enums\RentalStatus::Expired->getLabel(),
                    ])
                    ->default(\App\Enums\RentalStatus::Vacated->value)
                    ->required(),
                Forms\Components\Toggle::make('free_room')
                    ->label(__('Mark room as available'))
                    ->default(true),
            ])
            ->action(function (Unit $record, array $data) {
                $rental = $record->activeRental;
                if (! $rental) {
                    Notification::make()->title(__('This room has no active tenancy.'))->warning()->send();

                    return;
                }

                $rental->update([
                    'status' => (int) $data['status'],
                    'end_date' => $data['end_date'],
                ]);

                if ($data['free_room'] ?? true) {
                    $record->update(['status' => UnitStatus::Available]);
                }

                Notification::make()
                    ->title(__('Tenancy ended'))
                    ->body(__('Room :room is now :status.', [
                        'room' => $record->room_number,
                        'status' => \App\Enums\RentalStatus::from((int) $data['status'])->getLabel(),
                    ]))
                    ->success()->send();
            });
    }

    /** Per-room login account: create or reset (was ManageRooms::accountAction). */
    protected static function accountAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('account')
            ->label(fn (Unit $record) => $record->account_user_id ? __('Reset login') : __('Create login'))
            ->icon('heroicon-o-key')
            ->color('gray')
            ->modalHeading(fn (Unit $record) => $record->account_user_id ? __('Reset room login password') : __('Create room login account'))
            ->modalDescription(fn (Unit $record) => $record->account
                ? __('Username').': '.$record->account->username.' — '.__('set a new password for the next tenant.')
                : __('A username is generated from the property + room number. The tenant can use it to view invoices.'))
            ->modalSubmitActionLabel(fn (Unit $record) => $record->account_user_id ? __('Reset password') : __('Create account'))
            ->form([
                Forms\Components\TextInput::make('password')->label(__('Password'))->password()->revealable()
                    ->helperText(__('Leave blank to auto-generate a password.')),
            ])
            ->action(function (Unit $record, array $data) {
                $service = app(RoomAccountService::class);
                $result = $record->account_user_id
                    ? $service->resetPassword($record, $data['password'] ?: null)
                    : $service->createForUnit($record, $data['password'] ?: null);

                Notification::make()
                    ->title(__('Room login ready'))
                    ->body(__('Username').': **'.$result['username'].'**'.($result['password'] ? ' · '.__('Password').': **'.$result['password'].'**' : ''))
                    ->success()->persistent()->send();
            });
    }

    /**
     * Per-room meter readings popup. Lists the property's meter-based utilities
     * (Metered / Shared — Flat has no meter) and records one {@see UtilityUsage}
     * reading per utility for this room.
     *
     * Consumption is derived automatically: a utility's previous reading for this
     * room becomes the new row's old_reading and amount_used = max(0, new − old).
     * The FIRST ever reading for a room+utility has no prior, so it is stored as a
     * baseline (old_reading = new_reading, amount_used = 0) — i.e. the starting
     * meter value the user asked to seed when a room has no utility data yet.
     */
    protected static function meterReadingsAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('meterReadings')
            ->label(__('Meter readings'))
            ->icon('heroicon-o-bolt')
            ->color('warning')
            ->visible(fn () => auth()->user()?->can('create', UtilityUsage::class))
            ->modalWidth('lg')
            ->modalHeading(fn (Unit $record) => __('Meter readings — Room :room', ['room' => $record->room_number]))
            ->modalSubmitActionLabel(__('Save readings'))
            ->form(function (Unit $record): array {
                $utilities = static::meterUtilitiesFor($record);

                if ($utilities->isEmpty()) {
                    return [
                        Forms\Components\Placeholder::make('no_utilities')
                            ->label('')
                            ->content(__('No metered utilities are set up for this property yet. Add them under Utilities first, then record readings here.')),
                    ];
                }

                $schema = [
                    Forms\Components\Grid::make(2)->schema([
                        Forms\Components\DatePicker::make('reading_date')
                            ->label(__('Reading date'))->default(now())->required()
                            // Readings are of the meter "as of" a past/current moment;
                            // future dates would corrupt the next billing's baseline.
                            ->maxDate(now()),
                        Forms\Components\Select::make('reading_type')
                            ->label(__('Reading type'))
                            ->options(ReadingType::class)
                            ->default(ReadingType::Actual->value)->required(),
                    ]),
                ];

                foreach ($utilities as $utility) {
                    $uom = $utility->unit_of_measure;
                    $help = static::readingHint($record->getKey(), $utility->getKey(), $uom);

                    $schema[] = Forms\Components\TextInput::make("meters.{$utility->getKey()}")
                        ->label($utility->name.($uom ? " ({$uom})" : ''))
                        ->numeric()->minValue(0)->step('0.001')
                        // Cap to the new_reading decimal(12,3) column so a fat-finger
                        // entry can't overflow the DB.
                        ->maxValue(999999999)
                        ->suffix($uom)
                        ->helperText($help);
                }

                return $schema;
            })
            ->action(function (Unit $record, array $data): void {
                $date = \Illuminate\Support\Carbon::parse($data['reading_date'] ?? now())->toDateString();
                $type = (int) ($data['reading_type'] ?? ReadingType::Actual->value);
                $meters = $data['meters'] ?? [];

                // Whitelist the submitted utility ids against the ones we actually
                // rendered for THIS room's property — a tampered form payload can't
                // inject another property's (or an inactive/flat) utility id.
                $allowed = static::meterUtilitiesFor($record)->keyBy('id');

                // Link readings to the room's current tenancy (when occupied) so
                // rental-scoped utility waivers resolve — mirrors MonthlyBilling.
                $rentalId = $record->activeRental?->getKey();

                // One transaction so a multi-utility save is all-or-nothing.
                $saved = \Illuminate\Support\Facades\DB::transaction(function () use ($meters, $allowed, $record, $date, $type, $rentalId): int {
                    $count = 0;
                    foreach ($meters as $utilityId => $value) {
                        $utilityId = (int) $utilityId;
                        if ($value === null || $value === '' || ! $allowed->has($utilityId)) {
                            continue;
                        }
                        $new = (float) $value;

                        // Baseline = the latest reading STRICTLY BEFORE this date, so
                        // re-recording the same day corrects (not chains off) itself.
                        // With a meter this measures from its installed_reading, so the
                        // first reading already bills; without one it stays a zero-usage
                        // baseline as before. See MeterReadingResolver::baselineFor().
                        $baseline = app(MeterReadingResolver::class)
                            ->baselineFor($record->getKey(), $utilityId, $date, $new);
                        $old = $baseline['old'];
                        $amount = $baseline['amount'];

                        // Idempotent per (room, utility, date): a second submit for the
                        // same day updates the row instead of stacking duplicates.
                        UtilityUsage::updateOrCreate(
                            [
                                'unit_id' => $record->getKey(),
                                'property_utility_id' => $utilityId,
                                'reading_date' => $date,
                            ],
                            [
                                'rental_id' => $rentalId,
                                'reading_type' => $type,
                                'old_reading' => $old,
                                'new_reading' => $new,
                                'amount_used' => $amount,
                                'recorded_by_id' => auth()->id(),
                                // landlord_id auto-fills via BelongsToLandlord / resolveLandlordId().
                            ],
                        );
                        $count++;
                    }

                    return $count;
                });

                Notification::make()
                    ->title($saved
                        ? __(':count meter reading(s) saved for room :room', ['count' => $saved, 'room' => $record->room_number])
                        : __('No readings entered.'))
                    ->{$saved ? 'success' : 'warning'}()
                    ->send();
            });
    }

    /** The property's meter-based, active utilities (Metered / Shared) for a room. */
    public static function meterUtilitiesFor(Unit $record): \Illuminate\Support\Collection
    {
        return PropertyUtility::query()
            ->where('property_id', $record->property_id)
            ->whereIn('billing_type', [BillingType::Metered->value, BillingType::Shared->value])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Helper text under a reading input: what the consumption will be measured
     * from. Three cases — a previous reading, a meter that has just been
     * installed (its opening index), or nothing at all (legacy baseline rule).
     */
    public static function readingHint(int $unitId, int $utilityId, ?string $uom = null): string
    {
        $context = app(MeterReadingResolver::class)->previous($unitId, $utilityId);
        $suffix = $uom ? ' '.$uom : '';
        $value = static::trimReading($context['previous']).$suffix;

        return match ($context['source']) {
            'meter_reading' => __('Previous: :value', ['value' => $value])
                .($context['usage']?->reading_date ? ' · '.$context['usage']->reading_date->format('d M Y') : ''),
            'meter_install' => __('Meter installed at :value — first reading bills from there.', ['value' => $value]),
            'usage' => __('Previous: :value', ['value' => $value]),
            default => __('First reading — sets the starting baseline (no consumption billed).'),
        };
    }

    /** Most recent reading for a room + utility, or null if none yet. */
    public static function latestUsage(int $unitId, int $utilityId): ?UtilityUsage
    {
        return UtilityUsage::query()
            ->where('unit_id', $unitId)
            ->where('property_utility_id', $utilityId)
            ->orderByDesc('reading_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The latest reading STRICTLY BEFORE the given date — the baseline a new
     * reading's consumption is measured from. Same ordering as the billing flows
     * (MonthlyBilling / BuildsInvoiceForm) so baselines stay consistent.
     */
    public static function priorReading(int $unitId, int $utilityId, string $date): ?UtilityUsage
    {
        return UtilityUsage::query()
            ->where('unit_id', $unitId)
            ->where('property_utility_id', $utilityId)
            ->whereDate('reading_date', '<', $date)
            ->orderByDesc('reading_date')
            ->orderByDesc('id')
            ->first();
    }

    /** Trim a decimal(…,3) reading to a clean string ("1,251", "11", "1.5"). */
    public static function trimReading($value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3), '0'), '.');
    }

    /**
     * Bulk room generator for the active property (was ManageRooms::generateRoomsAction).
     * Only available inside a property context.
     *
     * Pricing is per-floor with a full, editable price grid: you set a default price
     * per floor, press "Build / refresh", and get one pre-filled price box per room —
     * then override any individual room. rent_amount is driven entirely by the grid
     * (price-only; rooms can still be assigned a pricing group later via edit).
     */
    protected static function generateRoomsAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('generateRooms')
            ->label(__('Generate rooms'))
            ->icon('heroicon-o-squares-plus')
            ->color('primary')
            // Shown only in a property context AND when the role holds `generate_rooms_unit`
            // (toggle it per role at /admin/shield/roles). super_admin passes via Gate::before.
            ->visible(fn () => ActiveProperty::id() !== null && auth()->user()?->can('generate_rooms_unit'))
            ->modalWidth('3xl')
            ->modalSubmitActionLabel(__('Generate'))
            ->form([
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\TextInput::make('prefix')->label(__('Room-number prefix'))
                        ->placeholder('optional, e.g. "A-"')->maxLength(10)->live(onBlur: true),
                    Forms\Components\Select::make('numbering')->label(__('Numbering'))
                        ->options([
                            'floor_seq' => __('Floor + sequence (101, 102 · 201 …)'),
                            'sequential' => __('Sequential (1, 2, 3 …)'),
                        ])->default('floor_seq')->required()->live(),
                    Forms\Components\TextInput::make('room_type')->label(__('Room type'))
                        ->default('Room')->required(),
                ]),

                // Step 1 — define floors, room counts and the default price for each floor.
                Forms\Components\Repeater::make('floors')
                    ->label(__('Floors & default price'))
                    ->schema([
                        Forms\Components\TextInput::make('floor')->label(__('Floor #'))
                            ->numeric()->required()->live(onBlur: true),
                        Forms\Components\TextInput::make('rooms')->label(__('Rooms'))
                            ->numeric()->minValue(0)->default(4)->required()->live(onBlur: true),
                        Forms\Components\TextInput::make('default_price')->label(__('Default price per room'))
                            ->numeric()->prefix(fn () => Money::activeSymbol())->live(onBlur: true),
                    ])
                    ->columns(3)
                    ->default([
                        ['floor' => 0, 'rooms' => 4, 'default_price' => null],
                        ['floor' => 1, 'rooms' => 4, 'default_price' => null],
                        ['floor' => 2, 'rooms' => 4, 'default_price' => null],
                        ['floor' => 3, 'rooms' => 4, 'default_price' => null],
                    ])
                    ->addActionLabel(__('Add a floor')),

                // Step 2 — build the editable grid from the floors above. Re-running merges:
                // manually-overridden rooms are kept, a changed floor default propagates to the rest.
                Forms\Components\Actions::make([
                    Forms\Components\Actions\Action::make('build')
                        ->label(__('Build / refresh room grid'))
                        ->icon('heroicon-o-arrow-path')
                        ->color('gray')
                        ->action(function (Forms\Get $get, Forms\Set $set) {
                            $set('grid', static::buildRoomGrid([
                                'prefix' => $get('prefix'),
                                'numbering' => $get('numbering'),
                                'floors' => $get('floors') ?? [],
                            ], $get('grid') ?? []));
                        }),
                ]),

                Forms\Components\Placeholder::make('summary')->label('')
                    ->content(fn (Forms\Get $get) => static::gridSummary($get('grid') ?? [])),

                // Step 3 — the full grid + live preview: one price box per room, 4 across.
                // "✦" marks any room whose price differs from its floor default.
                Forms\Components\Repeater::make('grid')
                    ->label(__('Rooms & prices — edit any room'))
                    ->schema([
                        Forms\Components\Hidden::make('number'),
                        Forms\Components\Hidden::make('floor'),
                        Forms\Components\Hidden::make('floor_default'),
                        Forms\Components\TextInput::make('price')
                            ->label(fn (Forms\Get $get) => $get('number')
                                .(filled($get('floor_default')) && (float) $get('price') !== (float) $get('floor_default') ? ' ✦' : ''))
                            ->numeric()->prefix(fn () => Money::activeSymbol())->live(onBlur: true),
                    ])
                    ->grid(4)
                    ->addable(false)->deletable(false)->reorderable(false)
                    ->visible(fn (Forms\Get $get) => filled($get('grid'))),

                Forms\Components\Toggle::make('create_accounts')->label(__('Create a login account for each room'))->default(true)->live(),
                Forms\Components\TextInput::make('account_password')->label(__('Default password for the room logins'))->password()->revealable()
                    ->helperText(__('Used for every room. Leave blank to auto-generate one per room (reset/view individually later).'))
                    ->visible(fn (Forms\Get $get) => (bool) $get('create_accounts')),
            ])
            ->action(function (array $data) {
                $property = ActiveProperty::model();
                if (! $property) {
                    return;
                }
                $accountService = app(RoomAccountService::class);

                // Fall back to a freshly built grid if the user never pressed "Build".
                $grid = ! empty($data['grid'])
                    ? $data['grid']
                    : static::buildRoomGrid($data, []);

                $makeAccounts = (bool) ($data['create_accounts'] ?? false);
                $existing = $property->units()->pluck('room_number')->all();
                $created = 0;
                $skipped = 0;
                $accounts = 0;

                foreach ($grid as $row) {
                    $number = (string) ($row['number'] ?? '');
                    if ($number === '' || in_array($number, $existing, true)) {
                        $skipped++;

                        continue;
                    }
                    $unit = Unit::create([
                        'property_id' => $property->id,
                        'landlord_id' => $property->landlord_id,
                        'room_number' => $number,
                        'floor_number' => (string) ($row['floor'] ?? ''),
                        'room_type' => $data['room_type'] ?? 'Room',
                        'rent_amount' => (float) ($row['price'] ?? 0),
                        'rent_currency' => Money::forPropertyId($property->id),
                        'status' => UnitStatus::Available,
                    ]);
                    $existing[] = $number;
                    $created++;
                    if ($makeAccounts) {
                        $unit->setRelation('property', $property);
                        $accountService->createForUnit($unit, $data['account_password'] ?: null);
                        $accounts++;
                    }
                }

                Notification::make()
                    ->title("Generated {$created} room(s)".($accounts ? " with {$accounts} login account(s)" : '').($skipped ? ", skipped {$skipped} existing" : ''))
                    ->success()->send();
            });
    }

    /**
     * Build the flat, editable room grid from the floor rows. Each entry carries its
     * computed room number, floor, the floor default (for the "differs" marker) and a
     * price. Merge semantics against $current (the existing grid): a room the user
     * manually overrode (price != its old floor default) keeps its price; every other
     * room takes the floor's current default — so changing a default and refreshing
     * propagates to untouched rooms without discarding deliberate overrides.
     *
     * @return list<array{number:string,floor:string,floor_default:float|null,price:float}>
     */
    protected static function buildRoomGrid(array $data, array $current = []): array
    {
        $prefix = $data['prefix'] ?? '';
        $numbering = $data['numbering'] ?? 'floor_seq';

        $prev = [];
        foreach ($current as $row) {
            if (! empty($row['number'])) {
                $prev[(string) $row['number']] = [
                    'price' => ($row['price'] === '' || ($row['price'] ?? null) === null) ? null : (float) $row['price'],
                    'default' => ($row['floor_default'] === '' || ($row['floor_default'] ?? null) === null) ? null : (float) $row['floor_default'],
                ];
            }
        }

        $grid = [];
        $seq = 0;
        foreach ($data['floors'] ?? [] as $floorRow) {
            $floor = (int) ($floorRow['floor'] ?? 0);
            $count = (int) ($floorRow['rooms'] ?? 0);
            $default = ($floorRow['default_price'] === '' || ($floorRow['default_price'] ?? null) === null)
                ? null
                : (float) $floorRow['default_price'];

            for ($i = 1; $i <= $count; $i++) {
                $seq++;
                $number = $numbering === 'floor_seq'
                    ? sprintf('%s%d%02d', $prefix, $floor, $i)
                    : sprintf('%s%d', $prefix, $seq);

                $price = $default ?? 0.0;
                if (isset($prev[$number]) && $prev[$number]['price'] !== null) {
                    $oldDefault = $prev[$number]['default'] ?? 0.0;
                    if ((float) $prev[$number]['price'] !== (float) $oldDefault) {
                        $price = (float) $prev[$number]['price']; // deliberate override → keep
                    }
                }

                $grid[] = [
                    'number' => $number,
                    'floor' => (string) $floor,
                    'floor_default' => $default,
                    'price' => $price,
                ];
            }
        }

        return $grid;
    }

    /** One-line live summary above the grid: total rooms + how many differ from their floor default. */
    protected static function gridSummary(array $grid): string
    {
        if (empty($grid)) {
            return __('Set the floors and prices above, then press "Build / refresh room grid".');
        }

        $custom = 0;
        foreach ($grid as $row) {
            $fd = $row['floor_default'] ?? null;
            if ($fd !== null && (float) ($row['price'] ?? 0) !== (float) $fd) {
                $custom++;
            }
        }

        return count($grid).' '.__('rooms').($custom ? ' · '.$custom.' '.__('priced individually') : '');
    }

    public static function getRelations(): array
    {
        return [
            UnitResource\RelationManagers\RentalsRelationManager::class,
            UnitResource\RelationManagers\InvoicesRelationManager::class,
            UnitResource\RelationManagers\UtilityUsageRelationManager::class,
            \App\Filament\Resources\PropertyUtilityResource\RelationManagers\ChargeRulesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUnits::route('/'),
            'create' => Pages\CreateUnit::route('/create'),
            'edit' => Pages\EditUnit::route('/{record}/edit'),
        ];
    }
}
