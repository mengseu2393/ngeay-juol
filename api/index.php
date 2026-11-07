<?php

/*
| Vercel entry point (vercel-php runtime) — a TEST deploy for the /m2 app only.
| See docs/VERCEL.md for what does and doesn't work there.
|
| Vercel's filesystem is read-only except /tmp, and /tmp is wiped between cold
| starts. So Laravel's storage dir and its bootstrap/cache files are moved to
| /tmp, and the storage skeleton is recreated on every request (cheap no-op
| once it exists). Values set in the Vercel dashboard win over these defaults.
*/

$defaults = [
    'LARAVEL_STORAGE_PATH' => '/tmp/storage',
    'APP_CONFIG_CACHE' => '/tmp/bootstrap/config.php',
    'APP_EVENTS_CACHE' => '/tmp/bootstrap/events.php',
    'APP_PACKAGES_CACHE' => '/tmp/bootstrap/packages.php',
    'APP_ROUTES_CACHE' => '/tmp/bootstrap/routes.php',
    'APP_SERVICES_CACHE' => '/tmp/bootstrap/services.php',
    'LOG_CHANNEL' => 'stderr',
    // Vercel's edge is the only way in, so its X-Forwarded-* headers are trusted
    // (otherwise url()/asset() emit http:// and Livewire breaks on mixed content).
    'TRUSTED_PROXIES' => '*',
];

foreach ($defaults as $key => $value) {
    $current = getenv($key);
    $value = ($current === false || $current === '') ? $value : $current;
    $_ENV[$key] = $_SERVER[$key] = $value;
}

foreach ([
    'app/public',
    'app/private',
    'framework/cache/data',
    'framework/sessions',
    'framework/views',
    'logs',
] as $dir) {
    $path = $_ENV['LARAVEL_STORAGE_PATH'].'/'.$dir;
    if (! is_dir($path)) {
        @mkdir($path, 0755, true);
    }
}

foreach (['APP_CONFIG_CACHE', 'APP_EVENTS_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_SERVICES_CACHE'] as $key) {
    if (! is_dir($dir = dirname($_ENV[$key]))) {
        @mkdir($dir, 0755, true);
    }
}

require __DIR__.'/../public/index.php';
