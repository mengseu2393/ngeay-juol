<?php

/*
| The mobile shell at /m — Simple Mode rebuilt as a standalone phone app,
| outside Filament. Off until it is ready: while off, only the testers listed
| here (comma-separated emails) can open it; everyone else gets a 404.
*/

return [

    'enabled' => (bool) env('MOBILE_SHELL_ENABLED', false),

    'testers' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('MOBILE_SHELL_TESTERS', '')),
    ))),

    /*
    | Android app (Trusted Web Activity, docs/TWA.md). Published in
    | /.well-known/assetlinks.json so Android shows the site full-screen inside
    | the app. Fingerprints: SHA-256 of the signing certificate(s), colon-separated
    | hex, comma-separated when there are several (upload key + Play App Signing).
    */
    'twa' => [
        'package' => env('TWA_PACKAGE_NAME'),
        'fingerprints' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TWA_SHA256_FINGERPRINTS', '')),
        ))),
    ],

];
