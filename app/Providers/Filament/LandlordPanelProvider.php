<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Billing;
use App\Filament\Pages\ConsumptionHistory;
use App\Filament\Pages\MonthlyBilling;
use App\Filament\Pages\PropertySettings;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\PaymentResource;
use App\Filament\Resources\PropertyResource;
use App\Filament\Resources\PropertyUtilityResource;
use App\Filament\Resources\RentalResource;
use App\Filament\Resources\UnitResource;
use App\Filament\Resources\UtilityUsageResource;
use App\Filament\Resources\UtilityWaiverResource;
use App\Filament\Widgets\PortfolioStatsWidget;
use App\Filament\Widgets\SubscriptionStatusWidget;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\SetLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Landlord back-office at /landlord. This is the property-management workspace:
 * the sidebar follows a selected property (the property switcher), and only the
 * landlord-owned resources live here. Platform staff use the separate /admin panel.
 */
class LandlordPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('landlord')
            ->path('landlord')
            ->login(\App\Filament\Pages\Auth\Login::class)
            ->brandName('RentWise')
            ->font('Plus Jakarta Sans')
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('17rem')
            ->globalSearch(false)
            ->colors([
                'primary' => Color::Emerald,
                'gray' => Color::Slate,
            ])
            // Invoice printing/PDF is served by the dompdf document route (a real
            // PDF, so no browser-injected print headers) — there's no window.print()
            // popup to wire up here; just load the shared admin theme.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<link rel="stylesheet" href="'.asset('css/rentwise-admin.css').'?v='.@filemtime(public_path('css/rentwise-admin.css')).'">',
            )
            // Property context switcher at the top of the sidebar — landlord panel only.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_START,
                fn (): string => Blade::render('@livewire(\'property-switcher\')'),
            )
            ->navigationGroups([
                'PropertyContext' => NavigationGroup::make()
                    ->label(fn () => \App\Support\ActiveProperty::name() ?? __('This property')),
                'Portfolio' => NavigationGroup::make()->label(fn () => __('Portfolio')),
                'Properties' => NavigationGroup::make()->label(fn () => __('Properties')),
                'Tenancy' => NavigationGroup::make()->label(fn () => __('Tenancy')),
                'Billing' => NavigationGroup::make()->label(fn () => __('Billing')),
                'Utilities' => NavigationGroup::make()->label(fn () => __('Utilities')),
            ])
            ->userMenuItems([
                // Platform staff drill into a landlord's properties from /admin;
                // give them a way back to the staff panel.
                MenuItem::make()
                    ->label(fn () => __('Back to admin'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->url(fn () => route('filament.admin.pages.dashboard'))
                    ->visible(fn () => auth()->user()?->isPlatformStaff() ?? false),
                MenuItem::make()
                    ->label('English')
                    ->icon('heroicon-o-language')
                    ->url(fn () => route('locale.switch', 'en'))
                    ->visible(fn () => app()->getLocale() !== 'en'),
                MenuItem::make()
                    ->label('ខ្មែរ')
                    ->icon('heroicon-o-language')
                    ->url(fn () => route('locale.switch', 'km'))
                    ->visible(fn () => app()->getLocale() !== 'km'),
            ])
            // Explicit registration (not discovery) so each resource lives in exactly
            // one panel: these are the landlord-owned resources.
            ->resources([
                PropertyResource::class,
                UnitResource::class,
                RentalResource::class,
                InvoiceResource::class,
                PaymentResource::class,
                PropertyUtilityResource::class,
                UtilityUsageResource::class,
                UtilityWaiverResource::class,
            ])
            ->pages([
                Dashboard::class,
                Billing::class,
                MonthlyBilling::class,
                PropertySettings::class,
                ConsumptionHistory::class,
            ])
            ->widgets([
                AccountWidget::class,
                PortfolioStatsWidget::class,
                SubscriptionStatusWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                EnsureActiveSubscription::class,
            ]);
    }
}
