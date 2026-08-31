<?php

namespace App\Support;

use App\Models\User;
use App\Providers\Filament\LandlordPanelProvider;
use Illuminate\Http\Request;

class SimpleLandlordMode
{
    public static function canUse(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(['landlord', 'landlord_manager', 'super_admin']);
    }

    public static function enabledFor(?User $user): bool
    {
        return self::canUse($user) && (bool) $user?->prefers_simple_landlord_mode;
    }

    public static function shouldRedirectToSimple(Request $request): bool
    {
        if (! $request->isMethodSafe()) {
            return false;
        }

        if (! self::enabledFor($request->user())) {
            return false;
        }

        $panel = LandlordPanelProvider::PATH;

        return ($request->is($panel) || $request->is($panel.'/*'))
            && ! $request->is($panel.'/simple', $panel.'/simple/*');
    }
}
