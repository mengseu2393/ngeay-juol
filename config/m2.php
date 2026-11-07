<?php

/*
| The /m2 app — a second, separate phone app built from the owner's paper
| sketches (home → room → tenant, meters, services, bill, slip, dashboard).
| Independent of the /m shell: own routes, layout, Livewire namespace, views
| and stylesheet. Off until it is ready: while off, only the testers listed
| here (comma-separated emails) can open it; everyone else gets a 404.
*/

return [

    'enabled' => (bool) env('M2_ENABLED', false),

    'testers' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('M2_TESTERS', '')),
    ))),

    /*
    | Make /m2 the landing page for everyone the flag above lets in: sign-in
    | sends them to /m2, "/" goes to /m2 (guests to the sign-in page), and the
    | installed app starts at /m2. For the Vercel test deploy (docs/VERCEL.md).
    */
    'default' => (bool) env('M2_DEFAULT', false),

];
