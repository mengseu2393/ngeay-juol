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

];
