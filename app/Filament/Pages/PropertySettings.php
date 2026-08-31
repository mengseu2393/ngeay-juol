<?php

namespace App\Filament\Pages;

use App\Enums\BillingType;
use App\Enums\FirstMonthBillingMode;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Support\ActiveProperty;
use App\Support\Money;
use App\Services\ExchangeRateService;
use App\Services\OpeningReadingService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Per-property billing/lease configuration as a first-class sidebar page, scoped
 * to the {@see ActiveProperty}. Replaces the collapsed "Billing & lease settings"
 * accordion that used to live inside PropertyResource's Edit form.
 */
class PropertySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $view = 'filament.pages.property-settings';

    protected static ?int $navigationSort = 90;

    public ?array $data = [];

    public ?PropertySetting $setting = null;

    /** Sits in the shared active-property group (label = the property's name). */
    public static function getNavigationGroup(): ?string
    {
        return ActiveProperty::id() !== null
            ? ActiveProperty::NAV_GROUP
            : __('Properties');
    }

    public static function getNavigationLabel(): string
    {
        return __('Property Settings');
    }

    public function getTitle(): string
    {
        $name = ActiveProperty::name();

        return $name
            ? __('Property Settings') . ' — ' . $name
            : __('Property Settings');
    }

    /** Only visible / reachable once a property is selected. */
    public static function shouldRegisterNavigation(): bool
    {
        return ! \App\Support\SimpleLandlordMode::enabledFor(auth()->user())
            && ActiveProperty::id() !== null;
    }

    public static function canAccess(): bool
    {
        return ActiveProperty::id() !== null;
    }

    public function mount(): void
    {
        abort_unless(ActiveProperty::id() !== null, 403);

        $this->setting = PropertySetting::firstOrCreate(
            ['property_id' => ActiveProperty::id()],
            [
                'currency'                    => 'USD',
                'due_day_of_month'            => 7,
                'first_month_billing_mode'    => FirstMonthBillingMode::FullMonth->value,
                'proration_cutoff_day'        => 15,
                'require_first_month_upfront' => false,
                'upfront_deposit_months'      => 0,
                'monthly_billing_enabled'     => false,
                'invoice_due_days'            => 7,
            ],
        );

        $this->form->fill($this->setting->attributesToArray());
    }

    /**
     * Opening readings live here rather than on the utility itself because this
     * is the "before you bill anything" page: monthly billing is switched on a
     * few fields below, and the index each meter starts from is the other half
     * of that same setup step. PropertyUtilityResource keeps its own per-utility
     * version for later corrections.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('openingReadings')
                ->label(__('Opening readings'))
                ->icon('heroicon-o-bolt')
                ->color('warning')
                ->visible(fn (): bool => $this->meteredUtilities()->isNotEmpty())
                ->modalWidth('3xl')
                ->modalHeading(__('Opening utility readings'))
                ->modalDescription(__('What each meter showed on the day you started. Without it the first invoice charges the meter\'s whole lifetime reading.'))
                ->modalSubmitActionLabel(__('Save opening readings'))
                ->form([
                    Forms\Components\Select::make('property_utility_id')
                        ->label(__('Utility'))
                        ->options(fn (): array => $this->meteredUtilities()->pluck('name', 'id')->all())
                        ->default(fn () => $this->meteredUtilities()->first()?->getKey())
                        ->selectablePlaceholder(false)
                        ->required()
                        // Rebuilds the room list below: which rooms still need an
                        // opening index is a per-utility question.
                        ->live(),

                    Forms\Components\DatePicker::make('reading_date')
                        ->label(__('Reading date'))
                        ->default(now())
                        ->maxDate(now())
                        ->required()
                        ->helperText(__('The day these numbers were read off the meters.')),

                    Forms\Components\Group::make()
                        ->schema(fn (Get $get): array => $this->openingReadingFields($get('property_utility_id')))
                        ->columnSpanFull(),
                ])
                ->action(fn (array $data) => $this->saveOpeningReadings($data)),
        ];
    }

    /** Metered utilities of the active property — the only kind with an index. */
    protected function meteredUtilities(): Collection
    {
        return PropertyUtility::query()
            ->where('property_id', ActiveProperty::id())
            ->where('billing_type', BillingType::Metered->value)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * One number per room, with the rooms whose opening index is already settled
     * shown but locked — seeing them is the point, since "why is this room
     * greyed out" is answered by the value next to it.
     *
     * @return array<int, Forms\Components\Component>
     */
    protected function openingReadingFields(mixed $utilityId): array
    {
        $utility = $utilityId
            ? $this->meteredUtilities()->firstWhere('id', (int) $utilityId)
            : null;

        if (! $utility) {
            return [];
        }

        $rows = app(OpeningReadingService::class)->rows($utility);

        if ($rows->isEmpty()) {
            return [
                Forms\Components\Placeholder::make('no_rooms')
                    ->label('')
                    ->content(__('No rooms are set up for this property yet.')),
            ];
        }

        $fields = $rows->map(function (array $row) use ($utility): Forms\Components\TextInput {
            $input = Forms\Components\TextInput::make('readings.'.$row['unit']->getKey())
                ->label(__('Room :room', ['room' => $row['unit']->room_number]))
                ->numeric()
                ->minValue(0)
                ->maxValue(999999999)
                ->step('0.001')
                ->suffix($utility->unit_of_measure);

            return match ($row['state']) {
                OpeningReadingService::OPEN_METER => $input->helperText(__('Meter :meter — sets its opening index.', [
                    'meter' => $row['meter']->serial ?: __('Meter #:id', ['id' => $row['meter']->getKey()]),
                ])),
                OpeningReadingService::OPEN_LEGACY => $input->helperText(__('Sets the starting baseline (no consumption billed).')),
                default => $input
                    ->disabled()
                    ->dehydrated(false)
                    ->placeholder(static::formatReading($row['baseline']))
                    ->helperText(__('Already set (:value) — reading from here on.', [
                        'value' => static::formatReading($row['baseline']),
                    ])),
            };
        })->values()->all();

        return [
            Forms\Components\Fieldset::make(__('Rooms'))
                ->schema($fields)
                ->columns(2),
        ];
    }

    /** Trailing zeros off a decimal:3 reading — "1091" beats "1091.000". */
    protected static function formatReading(?float $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    protected function saveOpeningReadings(array $data): void
    {
        if (! SubscriptionService::canMutate(auth()->user())) {
            Notification::make()
                ->danger()
                ->title(__('Write actions are disabled until payment is completed.'))
                ->send();

            return;
        }

        $utility = $this->meteredUtilities()->firstWhere('id', (int) $data['property_utility_id']);

        if (! $utility) {
            Notification::make()->danger()->title(__('That utility is no longer available.'))->send();

            return;
        }

        $result = app(OpeningReadingService::class)->apply(
            $utility,
            Carbon::parse($data['reading_date'])->toDateString(),
            $data['readings'] ?? [],
        );

        $recorded = $result['meters'] + $result['baselines'];

        Notification::make()
            ->{$recorded > 0 ? 'success' : 'warning'}()
            ->title($recorded > 0
                ? __(':count opening reading(s) initialized', ['count' => $recorded])
                : __('No readings entered.'))
            ->body($recorded > 0 ? __('Billing measures :utility from these numbers.', ['utility' => $utility->name]) : null)
            ->send();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('Billing'))
                    ->description(__('Per-property defaults — never shared with your other properties.'))
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        Forms\Components\Toggle::make('monthly_billing_enabled')
                            ->label(__('Enable Monthly Billing'))
                            ->helperText(__('When ON, the Monthly Billing page auto-loads all due rooms so you can enter meter readings and generate invoices in one click. Turn this off to hide the feature for this property.'))
                            ->default(false)
                            ->inline(false)
                            ->columnSpanFull(),

                        Forms\Components\Select::make('currency')
                            ->label(__('Currency'))
                            ->options(static::currencyOptions())
                            ->default('USD')
                            ->required()
                            ->live()
                            ->selectablePlaceholder(false)
                            ->helperText(__('Used as the default when creating rooms, utilities, and fees. Existing prices keep their own currency.')),

                        Forms\Components\TextInput::make('invoice_prefix')
                            ->label(__('Invoice prefix'))
                            ->placeholder(__('e.g. RIV')),

                        Forms\Components\TextInput::make('usd_khr_exchange_rate')
                            ->label(__('USD to KHR exchange rate'))
                            ->numeric()
                            ->minValue(1)
                            ->step('0.0001')
                            ->prefix(__('1 USD ='))
                            ->suffix('KHR')
                            ->helperText(__('Fetch from NBC or enter the rate you want to use for this property.')),

                        Forms\Components\DatePicker::make('exchange_rate_date')
                            ->label(__('Exchange rate date')),

                        Forms\Components\Hidden::make('exchange_rate_source'),
                        Forms\Components\Hidden::make('exchange_rate_fetched_at'),

                        Forms\Components\Placeholder::make('exchange_rate_status')
                            ->label(__('Saved exchange rate'))
                            ->content(function (Get $get): string {
                                $rate = $get('usd_khr_exchange_rate');
                                $date = $get('exchange_rate_date');

                                if (blank($rate) || blank($date)) {
                                    return __('No exchange rate saved yet.');
                                }

                                return __('1 USD = :rate KHR (:date)', [
                                    'rate' => number_format((float) $rate, 2),
                                    'date' => Carbon::parse($date)->toDateString(),
                                ]);
                            }),

                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('fetchUsdKhrExchangeRate')
                                ->label(__('Fetch today\'s exchange rate'))
                                ->icon('heroicon-o-arrow-path')
                                ->color('gray')
                                ->action(fn () => $this->fetchUsdKhrExchangeRate()),
                        ])
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('due_day_of_month')
                            ->label(__('Due day of month'))
                            ->numeric()->minValue(1)->maxValue(28)->default(7),
                        Forms\Components\TextInput::make('invoice_due_days')
                            ->label(__('Invoice Due Duration (Days)'))
                            ->helperText(__('Number of days after the issue date before the invoice becomes overdue.'))
                            ->numeric()->minValue(1)->default(7),
                        Forms\Components\TextInput::make('late_fee')
                            ->label(__('Late fee'))
                            ->numeric()
                            ->prefix(fn (Get $get): string => static::currencySymbol($get('currency')))
                            ->default(0),
                        Forms\Components\TextInput::make('water_billing_default')
                            ->label(__('Default water billing'))
                            ->placeholder(__('e.g. metered / flat')),
                    ])->columns(2),

                // ── Move-in billing rules ──────────────────────────────────────
                Forms\Components\Section::make(__('Move-in Billing Rules'))
                    ->description(__('Configure how the first month\'s rent is calculated and what must be paid before a tenant moves in.'))
                    ->icon('heroicon-o-calendar-days')
                    ->schema([
                        Forms\Components\Select::make('first_month_billing_mode')
                            ->label(__('First-month billing mode'))
                            ->options(FirstMonthBillingMode::options())
                            ->default(FirstMonthBillingMode::FullMonth->value)
                            ->selectablePlaceholder(false)
                            ->live()
                            ->helperText(function (Get $get): string {
                                $mode = FirstMonthBillingMode::tryFrom($get('first_month_billing_mode'));

                                return $mode?->getDescription() ?? '';
                            })
                            ->columnSpanFull()
                            ->required(),

                        Forms\Components\TextInput::make('proration_cutoff_day')
                            ->label(__('Half-month cutoff day'))
                            ->helperText(__('If the tenant moves in AFTER this day of the month, charge half rent. Otherwise charge full rent.'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(28)
                            ->default(15)
                            ->suffix(__('of the month'))
                            ->visible(fn (Get $get) => $get('first_month_billing_mode') === FirstMonthBillingMode::HalfMonth->value)
                            ->required(fn (Get $get) => $get('first_month_billing_mode') === FirstMonthBillingMode::HalfMonth->value),

                        Forms\Components\Toggle::make('require_first_month_upfront')
                            ->label(__('Require first month paid before move-in'))
                            ->helperText(__('When enabled, the first invoice must be settled before the tenancy is considered active.'))
                            ->default(false)
                            ->inline(false),

                        Forms\Components\Select::make('move_in_preset')
                            ->label(__('Move-in requirements preset'))
                            ->options([
                                'flexible' => __('Flexible'),
                                'first_last' => __('First and last month'),
                                'first_deposit' => __('First month and deposit'),
                                'first_last_deposit' => __('First, last, and deposit'),
                                'custom' => __('Custom'),
                            ])
                            ->default('flexible')
                            ->helperText(__('Presets populate structured rules. Existing tenancy snapshots are never changed.')),

                        Forms\Components\Toggle::make('create_invoice_on_move_in')
                            ->label(__('Auto-create invoice on move-in'))
                            ->helperText(__('Automatically generate the first rent invoice when a new tenant is created.'))
                            ->default(false)
                            ->inline(false),

                        Forms\Components\Select::make('upfront_deposit_months')
                            ->label(__('Security deposit'))
                            ->helperText(__('Number of months\' rent collected as an upfront security deposit (added as a line item on the first invoice).'))
                            ->options([
                                0 => __('No deposit'),
                                1 => __('1 month (1× rent)'),
                                2 => __('2 months (2× rent)'),
                                3 => __('3 months (3× rent)'),
                            ])
                            ->default(0)
                            ->selectablePlaceholder(false)
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make(__('Lease'))
                    ->icon('heroicon-o-document-text')
                    ->schema([
                        Forms\Components\TextInput::make('default_lease_months')
                            ->numeric()->label(__('Default lease (months)')),
                        Forms\Components\TextInput::make('deposit_policy')
                            ->label(__('Deposit policy'))
                            ->placeholder(__('e.g. 1 month')),
                    ])->columns(2),

                Forms\Components\Section::make(__('Contacts & property info'))
                    ->icon('heroicon-o-user-circle')
                    ->schema([
                        Forms\Components\TextInput::make('caretaker_name')
                            ->label(__('Caretaker name')),
                        Forms\Components\TextInput::make('caretaker_phone')
                            ->label(__('Caretaker phone'))
                            ->tel(),
                        Forms\Components\Textarea::make('parking_info')
                            ->label(__('Parking info'))
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('insurance_info')
                            ->label(__('Insurance info'))
                            ->columnSpanFull(),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    protected static function currencyOptions(): array
    {
        return [
            'USD' => __('USD - US Dollar ($)'),
            'KHR' => __('KHR - Khmer Riel (៛)'),
        ];
    }

    protected static function currencySymbol(?string $currency): string
    {
        return Money::symbol($currency);
    }

    public function fetchUsdKhrExchangeRate(): void
    {
        abort_unless($this->setting !== null, 403);

        if (! SubscriptionService::canMutate(auth()->user())) {
            Notification::make()
                ->danger()
                ->title(__('Write actions are disabled until payment is completed.'))
                ->send();

            return;
        }

        $state = $this->data ?? [];

        try {
            $exchangeRate = app(ExchangeRateService::class)->fetchUsdToKhr();
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title(__('Could not fetch exchange rate'))
                ->body(__('Please try again. If it keeps failing, enter the rate manually.'))
                ->send();

            return;
        }

        $payload = [
            'usd_khr_exchange_rate' => number_format((float) $exchangeRate['rate'], 4, '.', ''),
            'exchange_rate_date' => $exchangeRate['date'],
            'exchange_rate_source' => $exchangeRate['source'],
            'exchange_rate_fetched_at' => now(),
        ];

        $this->setting->update($payload);
        $this->setting->refresh();

        $this->form->fill(array_replace($state, [
            'usd_khr_exchange_rate' => $payload['usd_khr_exchange_rate'],
            'exchange_rate_date' => $payload['exchange_rate_date'],
            'exchange_rate_source' => $payload['exchange_rate_source'],
            'exchange_rate_fetched_at' => $payload['exchange_rate_fetched_at']->toDateTimeString(),
        ]));

        Notification::make()
            ->success()
            ->title(__('Exchange rate saved'))
            ->body(__('Saved :rate KHR per 1 USD from :source.', [
                'rate' => number_format((float) $exchangeRate['rate'], 2),
                'source' => $exchangeRate['source'],
            ]))
            ->send();
    }

    public function save(): void
    {
        abort_unless($this->setting !== null, 403);

        if (! SubscriptionService::canMutate(auth()->user())) {
            Notification::make()
                ->danger()
                ->title(__('Write actions are disabled until payment is completed.'))
                ->send();

            return;
        }

        $state = $this->form->getState();
        $previousPreset = $this->setting->move_in_preset;
        $this->setting->update($state);
        if (($state['move_in_preset'] ?? null) && $state['move_in_preset'] !== 'custom' && $state['move_in_preset'] !== $previousPreset) {
            app(\App\Services\MoveInRuleService::class)->configurePreset(
                $this->setting->property_id,
                $state['move_in_preset'],
                $state['currency'] ?? $this->setting->currency,
            );
        }

        Notification::make()
            ->success()
            ->title(__('Property settings saved'))
            ->send();
    }
}
