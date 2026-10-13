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
 */
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $mobile = (bool) config('mobile.enabled');

        $shortcuts = $mobile
            ? [
                ['name' => 'ចេញវិក្កយបត្រ', 'short_name' => 'Billing', 'url' => '/m/billing'],
                ['name' => 'វិក្កយបត្រ', 'short_name' => 'Invoices', 'url' => '/m'],
                ['name' => 'បន្ទប់', 'short_name' => 'Rooms', 'url' => '/m/rooms'],
                ['name' => 'អានកុងទ័រ', 'short_name' => 'Readings', 'url' => '/m/readings'],
            ]
            : [
                ['name' => 'ចេញវិក្កយបត្រ', 'short_name' => 'Billing', 'url' => '/app/monthly-billing'],
                ['name' => 'វិក្កយបត្រ', 'short_name' => 'Invoices', 'url' => '/app/invoices'],
                ['name' => 'បន្ទប់', 'short_name' => 'Rooms', 'url' => '/app/units'],
            ];

        $icon = [['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png']];

        return response()->json([
            'id' => '/login',
            'name' => 'ងាយជួល — ngeay juol',
            'short_name' => 'ងាយជួល',
            'description' => 'Landlord property management — simple daily tool for rental tracking in Cambodia.',
            'start_url' => $mobile ? '/m' : '/login',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'background_color' => '#f0fdf4',
            'theme_color' => '#059669',
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
