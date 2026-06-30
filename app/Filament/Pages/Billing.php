<?php

namespace App\Filament\Pages;

use App\Enums\SubscriptionAccess;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Auth;

class Billing extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.billing';

    protected static ?string $slug = 'billing';

    public ?Subscription $subscription = null;

    public function mount(): void
    {
        $this->subscription = Subscription::withoutGlobalScopes()
            ->where('landlord_id', Auth::user()->effectiveLandlordId())
            ->with(['plan', 'payments' => fn ($q) => $q->latest()])
            ->first();
    }

    public static function getNavigationLabel(): string
    {
        return __('Subscription / Plan');
    }

    public static function getModelLabel(): string
    {
        return __('Subscription');
    }

    public function getTitle(): string
    {
        return __('Subscription & Plan');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->hasAnyRole(['landlord', 'landlord_manager']) ?? false;
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    public function getBreadcrumbs(): array
    {
        return [
            __('Billing') => 'javascript:void(0)',
            __('Subscription') => 'javascript:void(0)',
        ];
    }

    protected function getHeaderActions(): array
    {
        if (! $this->subscription || $this->subscription->status === \App\Enums\SubscriptionStatus::Suspended) {
            return [];
        }

        return [
            Actions\Action::make('renew')
                ->label('Renew subscription')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->form([
                    Forms\Components\TextInput::make('amount')
                        ->required()->numeric()->prefix('$')
                        ->default(fn () => $this->subscription?->price ?? 0),
                    Forms\Components\Select::make('method')
                        ->options(\App\Enums\PaymentMethod::class)
                        ->default(\App\Enums\PaymentMethod::BankTransfer->value),
                    Forms\Components\Textarea::make('note')->rows(2),
                ])
                ->action(function (array $data): void {
                    SubscriptionService::renew($this->subscription, $data);
                    Notification::make()->success()->title('Subscription renewed!')->send();
                    $this->mount(); // refresh
                })
                ->visible(fn () => $this->subscription?->auto_renew || $this->subscription?->ends_at?->isPast()),

            Actions\Action::make('upgrade')
                ->label('Upgrade plan')
                ->icon('heroicon-o-arrow-trending-up')
                ->color('success')
                ->form([
                    Forms\Components\Select::make('plan_id')
                        ->label('Choose a plan')
                        ->options(fn () => SubscriptionPlan::where('is_active', true)
                            ->where('max_units', '>', $this->subscription?->max_units ?? 0)
                            ->orWhereNull('max_units')
                            ->pluck('name', 'id'))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $plan = SubscriptionPlan::findOrFail($data['plan_id']);
                    SubscriptionService::changePlan($this->subscription, $plan, immediate: true);
                    Notification::make()->success()->title('Plan upgraded!')->send();
                    $this->mount();
                }),

            Actions\Action::make('downgrade')
                ->label('Downgrade plan')
                ->icon('heroicon-o-arrow-trending-down')
                ->color('warning')
                ->form([
                    Forms\Components\Select::make('plan_id')
                        ->label('Choose a plan')
                        ->options(fn () => SubscriptionPlan::where('is_active', true)->pluck('name', 'id'))
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $plan = SubscriptionPlan::findOrFail($data['plan_id']);
                    SubscriptionService::changePlan($this->subscription, $plan, immediate: false);
                    Notification::make()->success()->title('Plan change scheduled for next period!')->send();
                    $this->mount();
                }),

            Actions\Action::make('cancel')
                ->label('Cancel subscription')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Why are you cancelling?')
                        ->rows(2),
                ])
                ->action(function (array $data): void {
                    SubscriptionService::cancel($this->subscription, $data['reason'] ?? null, immediate: false);
                    Notification::make()->warning()->title('Subscription will end at the current period end.')->send();
                    $this->mount();
                }),
        ];
    }

    // ---------------------------------------------------------------------
    // View data
    // ---------------------------------------------------------------------

    public function getAccess(): SubscriptionAccess
    {
        return SubscriptionService::effectiveAccess(Auth::user());
    }

    public function getDaysToExpiry(): ?int
    {
        if (! $this->subscription?->ends_at) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->subscription->ends_at, false);
    }

    public function getUsagePercent(): ?int
    {
        if (! $this->subscription || ! $this->subscription->max_units || ! $this->subscription->current_unit_count) {
            return null;
        }

        return (int) round(($this->subscription->current_unit_count / $this->subscription->max_units) * 100);
    }
}
