<?php

namespace App\Support;

use App\Models\User;
use App\Providers\Filament\LandlordPanelProvider;
use Illuminate\Http\Request;

/**
 * The /m mobile shell's feature flag (config/mobile.php): on for everyone who
 * can use Simple Mode once `enabled`, and for the listed testers before that.
 */
class MobileShell
{
    public static function isAvailableFor(?User $user): bool
    {
        if (! SimpleLandlordMode::canUse($user)) {
            return false;
        }

        return config('mobile.enabled')
            || in_array(strtolower((string) $user->email), config('mobile.testers', []), true);
    }

    /**
     * Whether this request comes from a device that gets the mobile shell instead
     * of the panel: a phone or the installed app, for a user the flag lets in.
     */
    public static function takesOver(Request $request): bool
    {
        return DeviceSession::wantsLongSession($request) && self::isAvailableFor($request->user());
    }

    /**
     * The /m screen that replaces a landlord-panel URL a phone was sent to — old
     * Simple Mode screens (?screen=…&unit_id=…) and the full-mode pages phones
     * were allowed into. Null for the two pages /m doesn't have: the
     * subscription billing page and the onboarding wizard (other middleware
     * redirects there, so bouncing them would loop).
     */
    public static function urlFor(Request $request): ?string
    {
        $panel = LandlordPanelProvider::PATH;
        $path = trim($request->path(), '/');

        if ($path !== $panel && ! str_starts_with($path, $panel.'/')) {
            return null;
        }

        $rest = trim(substr($path, strlen($panel)), '/');

        if (preg_match('#^(setup|billing)(/|$)#', $rest)) {
            return null;
        }

        if ($rest === 'simple') {
            return self::simpleScreenUrl((string) $request->query('screen'), (int) $request->query('unit_id'));
        }

        $patterns = [
            '#^unit-overview/(\d+)$#' => 'mobile.room',
            '#^units/(\d+)/edit$#' => 'mobile.room.edit',
            '#^units/(\d+)$#' => 'mobile.room',
            '#^rentals/(\d+)(/edit)?$#' => 'mobile.tenant',
        ];

        foreach ($patterns as $pattern => $route) {
            if (preg_match($pattern, $rest, $match)) {
                return route($route, $match[1]);
            }
        }

        return match (true) {
            $rest === 'add-property' => route('mobile.add-property'),
            $rest === 'property-settings', str_starts_with($rest, 'properties') => route('mobile.property-settings'),
            str_starts_with($rest, 'property-utilities') => route('mobile.utility-rates'),
            str_starts_with($rest, 'utility-usages') => route('mobile.readings'),
            $rest === 'monthly-billing' => route('mobile.billing'),
            str_starts_with($rest, 'units') => route('mobile.rooms'),
            str_starts_with($rest, 'rentals'), str_starts_with($rest, 'tenants') => route('mobile.tenants'),
            default => route('mobile.invoices'),
        };
    }

    private static function simpleScreenUrl(string $screen, int $unitId): string
    {
        if ($unitId > 0) {
            $route = match ($screen) {
                'add-tenant' => 'mobile.room.add-tenant',
                'end-tenancy' => 'mobile.room.end-tenancy',
                'unit' => 'mobile.room',
                default => null,
            };

            if ($route) {
                return route($route, $unitId);
            }
        }

        return route(match ($screen) {
            'billing-invoice' => 'mobile.billing',
            'rooms', 'units', 'unit', 'add-tenant', 'end-tenancy' => 'mobile.rooms',
            'tenants' => 'mobile.tenants',
            'settings' => 'mobile.settings',
            'utility' => 'mobile.readings',
            'utility-rates' => 'mobile.utility-rates',
            'profile' => 'mobile.profile',
            default => 'mobile.invoices',
        });
    }
}
