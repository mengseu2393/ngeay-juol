<?php

namespace App\Providers\Filament;

use App\Filament\Pages\AdminSettings;
use App\Filament\Pages\QrCodeGenerator;
use App\Filament\Pages\Renewals;
use App\Filament\Resources\ActivityLogResource;
use App\Filament\Resources\LandlordResource;
use App\Filament\Resources\SubscriptionPaymentResource;
use App\Filament\Resources\SubscriptionPlanResource;
use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\UserResource;
use App\Filament\Widgets\AdminGrowthChartWidget;
use App\Filament\Widgets\AdminLandlordActivityWidget;
use App\Filament\Widgets\AdminPlanMixWidget;
use App\Filament\Widgets\AdminPlatformStatsWidget;
use App\Filament\Widgets\AdminPlatformUsageWidget;
use App\Http\Middleware\SetLocale;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
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
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Platform staff panel at /admin — super_admin & support only (see User::canAccessPanel).
 * Landlords no longer use this panel; they have their own back-office at /landlord
 * (LandlordPanelProvider). This panel holds platform-management resources only:
 * landlords, users, and Shield role management.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
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
                url('/admin/logout'),
                url('/login'),
                url('/admin/login'),
                url('/'.LandlordPanelProvider::PATH.'/*'),
                url('/'.LandlordPanelProvider::PATH.'/invoices/*/pdf*'),
                url('/'.LandlordPanelProvider::PATH.'/invoices/*/excel*'),
                'mailto:*',
            ])
            ->databaseNotifications()
            ->colors([
                'primary' => Color::Emerald,
                'gray' => Color::Slate,
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<link rel="preconnect" href="https://fonts.googleapis.com">'.
                    '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'.
                    '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:wght@400;500;600;700&display=swap">'.
                    '<link rel="stylesheet" href="'.asset('css/rentwise-admin.css').'?v='.@filemtime(public_path('css/rentwise-admin.css')).'">'.
                    view('components.rw-loader-head')->render(),
            )
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => view('filament.components.language-switcher'),
            )
            ->navigationGroups([
                'Billing' => NavigationGroup::make()->label(fn () => __('Billing')),
                'Administration' => NavigationGroup::make()->label(fn () => __('Administration')),
            ])
            ->userMenuItems([
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
            // Explicit registration (not discovery) so landlord resources never leak
            // into the staff panel. Only platform-management resources live here.
            ->resources([
                SubscriptionPlanResource::class,
                SubscriptionResource::class,
                SubscriptionPaymentResource::class,
                LandlordResource::class,
                UserResource::class,
                ActivityLogResource::class,
            ])
            ->pages([
                Dashboard::class,
                Renewals::class,
                AdminSettings::class,
                QrCodeGenerator::class,
            ])
            // Dashboard, top to bottom: what the business earns, what the product
            // carries, where both are heading, then the customer list the first
            // three are aggregates of. AccountWidget and FilamentInfoWidget keep
            // Filament's own -3/-2 sorts and so fall in below all of it.
            ->widgets([
                AdminPlatformStatsWidget::class,     // -40
                AdminPlatformUsageWidget::class,     // -38
                AdminGrowthChartWidget::class,       // -36
                AdminPlanMixWidget::class,           // -35
                AdminLandlordActivityWidget::class,  // -34
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
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
            ]);
    }
}
