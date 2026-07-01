<?php

namespace App\Filament\Pages;

use App\Enums\FirstMonthBillingMode;
use App\Models\PropertySetting;
use App\Support\ActiveProperty;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

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
        return ActiveProperty::id() !== null;
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
            ],
        );

        $this->form->fill($this->setting->attributesToArray());
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('Billing'))
                    ->description(__('Per-property defaults — never shared with your other properties.'))
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        // ── Feature flag ──────────────────────────────────────────
                        Forms\Components\Toggle::make('monthly_billing_enabled')
                            ->label(__('Enable Monthly Billing'))
                            ->helperText(__('When ON, the Monthly Billing page auto-loads all due rooms so you can enter meter readings and generate invoices in one click. Turn this off to hide the feature for this property.'))
                            ->default(false)
                            ->inline(false)
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('currency')->default('USD')->maxLength(8),
                        Forms\Components\TextInput::make('invoice_prefix')->placeholder('e.g. RIV'),
                        Forms\Components\TextInput::make('due_day_of_month')
                            ->numeric()->minValue(1)->maxValue(28)->default(7),
                        Forms\Components\TextInput::make('late_fee')->numeric()->prefix('$')->default(0),
                        Forms\Components\TextInput::make('water_billing_default')->placeholder('e.g. metered / flat'),
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
                        Forms\Components\TextInput::make('deposit_policy')->placeholder('e.g. 1 month'),
                    ])->columns(2),

                Forms\Components\Section::make(__('Contacts & property info'))
                    ->icon('heroicon-o-user-circle')
                    ->schema([
                        Forms\Components\TextInput::make('caretaker_name'),
                        Forms\Components\TextInput::make('caretaker_phone')->tel(),
                        Forms\Components\Textarea::make('parking_info')->columnSpanFull(),
                        Forms\Components\Textarea::make('insurance_info')->columnSpanFull(),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless($this->setting !== null, 403);

        $this->setting->update($this->form->getState());

        Notification::make()
            ->success()
            ->title(__('Property settings saved'))
            ->send();
    }
}
