<?php

namespace App\Support;

use App\Models\User;

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
}
