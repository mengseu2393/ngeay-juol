<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * The web app manifest, served by the app (it used to be public/manifest.json)
 * so it can follow the mobile shell's flag: once /m is on for everyone the
 * installed app starts there, with /m shortcuts.
 *
 * "id" is pinned to "/login" — the start_url every existing install was made
 * with, which browsers used as the app's identity. Changing start_url without
 * it would make Chrome treat /m as a different app (a second icon).
 *
 * With config m2.default on (the /m2 deploy) the app opens /m2, with /m2's
 * shortcuts and green; the id stays "/login" for the same reason.
 */
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $mobile = (bool) config('mobile.enabled');
        $m2 = (bool) config('m2.default');

        $shortcuts = match (true) {
            $m2 => [
                ['name' => 'ទំព័រដើម', 'short_name' => 'Home', 'url' => '/m2'],
                ['name' => 'ផ្ទាំងគ្រប់គ្រង', 'short_name' => 'Dashboard', 'url' => '/m2/dashboard'],
                ['name' => 'ការកំណត់', 'short_name' => 'Settings', 'url' => '/m2/settings'],
            ],
            $mobile => [
                ['name' => 'ចេញវិក្កយបត្រ', 'short_name' => 'Billing', 'url' => '/m/billing'],
                ['name' => 'វិក្កយបត្រ', 'short_name' => 'Invoices', 'url' => '/m'],
                ['name' => 'បន្ទប់', 'short_name' => 'Rooms', 'url' => '/m/rooms'],
                ['name' => 'អានកុងទ័រ', 'short_name' => 'Readings', 'url' => '/m/readings'],
            ],
            default => [
                ['name' => 'ចេញវិក្កយបត្រ', 'short_name' => 'Billing', 'url' => '/app/monthly-billing'],
                ['name' => 'វិក្កយបត្រ', 'short_name' => 'Invoices', 'url' => '/app/invoices'],
                ['name' => 'បន្ទប់', 'short_name' => 'Rooms', 'url' => '/app/units'],
            ],
        };

        $icon = [['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png']];

        return response()->json([
            'id' => '/login',
            'name' => 'ងាយជួល — ngeay juol',
            'short_name' => 'ងាយជួល',
            'description' => 'Landlord property management — simple daily tool for rental tracking in Cambodia.',
            'start_url' => $m2 ? '/m2' : ($mobile ? '/m' : '/login'),
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'background_color' => $m2 ? '#e9f5ee' : '#f0fdf4',
            // /m2's own green (its layout's theme-color meta).
            'theme_color' => $m2 ? '#0b7a55' : '#059669',
            'lang' => 'km',
            'dir' => 'ltr',
            'categories' => ['productivity', 'business', 'finance'],
            'icons' => [
                ['src' => '/favicon.ico', 'sizes' => '48x48', 'type' => 'image/x-icon'],
                ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => array_map(fn (array $s) => $s + ['icons' => $icon], $shortcuts),
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            // Short: a flag flip has to reach installed apps; browsers re-check it rarely anyway.
            'Cache-Control' => 'public, max-age=300',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
