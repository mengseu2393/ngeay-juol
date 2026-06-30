<?php

namespace App\Filament\Pages;

use App\Models\PropertySetting;
use App\Support\ActiveProperty;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
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
            ['currency' => 'USD', 'due_day_of_month' => 7],
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
                        Forms\Components\TextInput::make('currency')->default('USD')->maxLength(8),
                        Forms\Components\TextInput::make('invoice_prefix')->placeholder('e.g. RIV'),
                        Forms\Components\TextInput::make('due_day_of_month')
                            ->numeric()->minValue(1)->maxValue(28)->default(7),
                        Forms\Components\TextInput::make('late_fee')->numeric()->prefix('$')->default(0),
                        Forms\Components\TextInput::make('water_billing_default')->placeholder('e.g. metered / flat'),
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
