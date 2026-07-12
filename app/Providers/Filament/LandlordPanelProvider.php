<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
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
use App\Filament\Widgets\OverdueInvoicesWidget;
use App\Filament\Widgets\PortfolioStatsWidget;
use App\Filament\Widgets\RevenueChartWidget;
use App\Filament\Widgets\RoomStatusWidget;
use App\Filament\Widgets\SubscriptionStatusWidget;
use App\Filament\Widgets\UtilityUsageWidget;
use App\Http\Middleware\EnsureActiveSubscription;
use App\Http\Middleware\RedirectToSimpleLandlordMode;
use App\Http\Middleware\SetLocale;
use App\Support\ActiveProperty;
use App\Support\SimpleLandlordMode;
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
            ->brandName(__('ngeay juol'))
            ->brandLogo(fn () => view('filament.components.brand-logo'))
            ->favicon(asset('favicon.ico'))
            ->brandLogoHeight('2.25rem')
            ->font('Plus Jakarta Sans')
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('17rem')
            ->spa()
            ->unsavedChangesAlerts()
            ->spaUrlExceptions([
                url('/locale/*'),
                url('/locale/en'),
                url('/locale/km'),
                url('/logout'),
                url('/landlord/logout'),
                url('/login'),
                url('/landlord/login'),
                url('/admin/*'),
                url('/landlord/invoices/*/pdf*'),
                url('/landlord/invoices/*/excel*'),
                'mailto:*',
            ])
            ->globalSearch(false)
            ->databaseNotifications()
            ->colors([
                'primary' => Color::Emerald,
                'gray' => Color::Slate,
            ])
            // Invoice printing/PDF is served by the dompdf document route (a real
            // PDF, so no browser-injected print headers) — there's no window.print()
            // popup to wire up here; just load the shared admin theme.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<link rel="stylesheet" href="'.asset('css/rentwise-admin.css').'?v='.@filemtime(public_path('css/rentwise-admin.css')).'">'.
                    '<link rel="manifest" href="'.asset('manifest.json').'">'.
                    '<meta name="theme-color" content="#059669">'.
                    '<meta name="mobile-web-app-capable" content="yes">'.
                    '<meta name="apple-mobile-web-app-capable" content="yes">'.
                    '<meta name="apple-mobile-web-app-title" content="ngeay juol">',
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => '<script>if("serviceWorker" in navigator){navigator.serviceWorker.register("/sw.js").catch(()=>{})}</script>',
            )
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => Blade::render(
                    '<div class="flex items-center gap-2">@include(\'filament.components.language-switcher\')</div>',
                ),
            )
            // Property context switcher at the top of the sidebar — landlord panel only.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_START,
                fn (): string => Blade::render('@livewire(\'property-switcher\')'),
            )
            ->navigationGroups([
                'PropertyContext' => NavigationGroup::make()
                    ->label(fn () => ActiveProperty::name() ?? __('This property')),
                'Portfolio' => NavigationGroup::make()->label(fn () => __('Portfolio')),
                'Properties' => NavigationGroup::make()->label(fn () => __('Properties')),
                'Tenancy' => NavigationGroup::make()->label(fn () => __('Tenancy')),
                'Billing' => NavigationGroup::make()->label(fn () => __('Billing')),
                'Utilities' => NavigationGroup::make()->label(fn () => __('Utilities')),
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label(fn () => SimpleLandlordMode::enabledFor(auth()->user())
                        ? __('Switch to full mode')
                        : __('Switch to simple mode'))
                    ->icon(fn () => SimpleLandlordMode::enabledFor(auth()->user())
                        ? 'heroicon-o-squares-2x2'
                        : 'heroicon-o-device-phone-mobile')
                    ->postAction(fn () => route('landlord.simple-mode.toggle'))
                    ->visible(fn () => SimpleLandlordMode::canUse(auth()->user())),
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
            ])
            ->pages([
                Dashboard::class,
                Billing::class,
                MonthlyBilling::class,
                PropertySettings::class,
                ConsumptionHistory::class,
                \App\Filament\Pages\SimpleDashboard::class,
            ])
            ->widgets([
                AccountWidget::class,
                PortfolioStatsWidget::class,
                SubscriptionStatusWidget::class,
                RoomStatusWidget::class,
                UtilityUsageWidget::class,
                RevenueChartWidget::class,
                OverdueInvoicesWidget::class,
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
                RedirectToSimpleLandlordMode::class,
            ]);
    }
}
