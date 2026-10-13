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

];
