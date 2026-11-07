<?php

namespace App\Support;

use App\Models\User;

/**
 * The /m2 app's feature flag (config/m2.php): on for every landlord-side user
 * once `enabled`, and for the listed testers before that.
 */
class M2Shell
{
    public static function isAvailableFor(?User $user): bool
    {
        if (! SimpleLandlordMode::canUse($user)) {
            return false;
        }

        return config('m2.enabled')
            || in_array(strtolower((string) $user->email), config('m2.testers', []), true);
    }

    /**
     * Whether /m2 is this user's landing page (config m2.default) — only for a
     * user the flag lets in, so nobody is sent to a 404.
     */
    public static function isDefaultFor(?User $user): bool
    {
        return config('m2.default') && self::isAvailableFor($user);
    }

    /**
     * A stored photo's URL without the host. Media Library builds absolute URLs from APP_URL, which breaks
     * the picture (a broken-image icon) whenever the phone reaches the app by another address than APP_URL
     * — LAN IP vs 127.0.0.1 vs a tunnel. A path-only URL always resolves against the page's own host.
     */
    public static function mediaUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return $path ? $path.(($q = parse_url($url, PHP_URL_QUERY)) ? '?'.$q : '') : $url;
    }
}
