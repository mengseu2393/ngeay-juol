<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Billing;
use App\Filament\Pages\MonthlyBilling;

use App\Filament\Pages\PropertySettings;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\MaintenanceRequestResource;
use App\Filament\Resources\PropertyResource;
use App\Filament\Resources\PropertyUtilityResource;
use App\Filament\Resources\RentalResource;
use App\Filament\Resources\UnitResource;
use App\Filament\Resources\UtilityUsageResource;
use App\Filament\Widgets\BillingCycleWidget;
use App\Filament\Widgets\LeaseExpiryWidget;
use App\Filament\Widgets\OverdueInvoicesWidget;
use App\Filament\Widgets\PortfolioStatsWidget;
use App\Filament\Widgets\ReceivablesAgingWidget;
use App\Filament\Widgets\RecentPaymentsWidget;
use App\Filament\Widgets\RevenueChartWidget;
use App\Filament\Widgets\RoomsMissingReadingsWidget;
use App\Filament\Widgets\RoomStatusWidget;
use App\Filament\Widgets\SubscriptionStatusWidget;
use App\Filament\Widgets\TopDebtorsWidget;
use App\Filament\Widgets\UtilityAnomalyWidget;
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
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Landlord back-office at /app. This is the property-management workspace:
 * the sidebar follows a selected property (the property switcher), and only the
 * landlord-owned resources live here. Platform staff use the separate /admin panel.
 *
 * The URL prefix is /app (neutral — the panel is also used by landlord_manager
 * staff and by platform staff drilling in), while the panel *id* stays 'landlord'
 * so every filament.landlord.* route name is unchanged. Anything that needs the
 * prefix as a string reads self::PATH rather than hardcoding it again.
 */
class LandlordPanelProvider extends PanelProvider
{
    /** URL prefix for this panel. Legacy /landlord/* is 301'd here in routes/web.php. */
    public const PATH = 'app';

    /** Panel id — unchanged by the move to /app, so filament.landlord.* route names still resolve. */
    public const ID = 'landlord';

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id(self::ID)
            ->path(self::PATH)
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
                url('/'.self::PATH.'/logout'),
                url('/login'),
                url('/admin/*'),
                url('/'.self::PATH.'/invoices/*/pdf*'),
                url('/'.self::PATH.'/invoices/*/excel*'),
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
                fn (): string => '<link rel="preconnect" href="https://fonts.googleapis.com">'.
                    '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'.
                    '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;600;700&display=swap">'.
                    '<link rel="stylesheet" href="'.asset('css/rentwise-admin.css').'?v='.@filemtime(public_path('css/rentwise-admin.css')).'">'.
                    '<link rel="manifest" href="/manifest.json">'.
                    '<meta name="theme-color" content="#059669">'.
                    '<meta name="mobile-web-app-capable" content="yes">'.
                    '<meta name="apple-mobile-web-app-capable" content="yes">'.
                    '<meta name="apple-mobile-web-app-status-bar-style" content="default">'.
                    '<meta name="apple-mobile-web-app-title" content="ងាយជួល">'.
                    '<link rel="apple-touch-icon" href="/icons/icon-192.png">'.
                    view('components.rw-loader-head')->render(),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Blade::render(
                    '<script>if("serviceWorker" in navigator){window.addEventListener("load",()=>{navigator.serviceWorker.register("/sw.js").catch(()=>{})})}</script>' .
                    '@include(\'filament.components.pwa-install-banner\')' .
                    '@include(\'components.rw-print-script\')' .
                    '@include(\'components.rw-close-dropdown-on-action\')'
                ),
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
                'Operations' => NavigationGroup::make()->label(fn () => __('Operations')),
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
                MaintenanceRequestResource::class,
                PropertyUtilityResource::class,
                UtilityUsageResource::class,
            ])
            ->pages([
                Dashboard::class,
                Billing::class,
                MonthlyBilling::class,
                PropertySettings::class,
                \App\Filament\Pages\SimpleDashboard::class,
            ])
            // Dashboard, top to bottom. AccountWidget is deliberately absent: its
            // "Hi, <name>" card occupied the first slot without telling a landlord
            // anything. Ordering runs standing position → this month's work → the
            // lists to act on → the retrospective charts.
            ->widgets([
                SubscriptionStatusWidget::class,   // -5
                PortfolioStatsWidget::class,       // -4  occupancy, tenancies, deposits, outstanding
                BillingCycleWidget::class,         // -3  readings / invoices / cash for this month
                TopDebtorsWidget::class,           // -2  who to phone
                RoomsMissingReadingsWidget::class, // -1  what blocks the billing run
                RevenueChartWidget::class,         //  0
                ReceivablesAgingWidget::class,     //  1
                RoomStatusWidget::class,           //  2
                UtilityUsageWidget::class,         //  3
                UtilityAnomalyWidget::class,       //  4  leaks and misread meters
                LeaseExpiryWidget::class,          //  5
                RecentPaymentsWidget::class,       //  6
                OverdueInvoicesWidget::class,      //  7  invoice-level detail behind TopDebtors
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
