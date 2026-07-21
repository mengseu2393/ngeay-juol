<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Physical meters
    |--------------------------------------------------------------------------
    |
    | Master switch for the meter layer (utility_meters + utility_usages.meter_id).
    |
    | ON  — the previous reading for a cycle comes from the room's ACTIVE meter:
    |       its last reading, or the meter's installed_reading when it has none
    |       yet. Replacing a meter retires the old one (final reading) and opens
    |       a new one (installed reading, which is rarely 0), so consumption is
    |       never computed across two different devices.
    |
    | OFF — every lookup falls back to the original behaviour: "latest
    |       utility_usages row for (unit, property_utility), else 0". Meter rows
    |       are left untouched and simply ignored, so the feature can be cut off
    |       at any time without a rollback or data loss.
    |
    | Rooms that have no meter row fall back to the legacy path even when this is
    | ON, which is what makes the rollout incremental — run
    | `php artisan utilities:backfill-meters` to create meters from the readings
    | that already exist.
    |
    */

    'meters' => (bool) env('UTILITY_METERS', true),

];
