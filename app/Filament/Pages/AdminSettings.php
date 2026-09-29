<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Models\SubscriptionPlan;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class AdminSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 99;

    protected static string $view = 'filament.pages.admin-settings';

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    public function getTitle(): string
    {
        return __('Admin Settings');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'location_selects_enabled' => Setting::get('location_selects_enabled', true, 'admin'),
            'registration_enabled' => Setting::get('registration_enabled', true, 'registration'),
            'signup_plan_id' => Setting::get('signup_plan_id', null, 'registration'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('Location Input'))
                    ->description(__('Controls province, district, commune, and village fields when creating or editing landlords and tenants.'))
                    ->icon('heroicon-o-map-pin')
                    ->schema([
                        Forms\Components\Toggle::make('location_selects_enabled')
                            ->label(__('Use location dropdowns'))
                            ->helperText(__('Turn this off to allow admins and landlords to type the four location fields manually.'))
                            ->default(true)
                            ->inline(false),
                    ]),
                Forms\Components\Section::make(__('Landlord Registration'))
                    ->description(__('Landlords can create their own account from the Register button on the home and login pages.'))
                    ->icon('heroicon-o-user-plus')
                    ->schema([
                        Forms\Components\Toggle::make('registration_enabled')
                            ->label(__('Allow landlords to register'))
                            ->default(true)
                            ->inline(false),
                        Forms\Components\Select::make('signup_plan_id')
                            ->label(__('Plan for new sign-ups'))
                            ->options(fn () => SubscriptionPlan::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'))
                            ->placeholder(__('Free (default)'))
                            ->helperText(__('How long it lasts comes from the plan\'s interval — choose Forever, Monthly, Quarterly or Yearly on the plan itself.')),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        Setting::set(
            'location_selects_enabled',
            (bool) ($state['location_selects_enabled'] ?? false),
            'bool',
            'admin',
        );

        Setting::set('registration_enabled', (bool) ($state['registration_enabled'] ?? false), 'bool', 'registration');
        Setting::set('signup_plan_id', $state['signup_plan_id'] ?? '', 'string', 'registration');

        Notification::make()
            ->success()
            ->title(__('Admin settings saved'))
            ->send();
    }
}
