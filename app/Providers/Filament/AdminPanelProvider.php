<?php

namespace App\Providers\Filament;

use App\Filament\Resources\LandlordResource;
use App\Filament\Resources\SubscriptionPaymentResource;
use App\Filament\Resources\SubscriptionPlanResource;
use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\UserResource;
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
            ->login(\App\Filament\Pages\Auth\Login::class)
            ->brandName('RentWise Staff')
            ->font('Plus Jakarta Sans')
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('17rem')
            ->colors([
                'primary' => Color::Emerald,
                'gray' => Color::Slate,
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<link rel="stylesheet" href="'.asset('css/rentwise-admin.css').'?v='.@filemtime(public_path('css/rentwise-admin.css')).'">',
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
            ])
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
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
