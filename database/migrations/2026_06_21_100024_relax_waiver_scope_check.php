<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Utilities are now property-bound (property_utility_id), so a waiver's scope is:
 * property-wide (no unit/rental) OR narrowed to a unit OR a rental. The old
 * "exactly one of property/unit/rental" CHECK no longer fits — drop it.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement('ALTER TABLE utility_waivers DROP CONSTRAINT chk_waiver_one_scope');
        } catch (\Throwable) {
            // already absent / engine without CHECK support
        }
    }

    public function down(): void
    {
        try {
            DB::statement(
                'ALTER TABLE utility_waivers ADD CONSTRAINT chk_waiver_one_scope '
                .'CHECK ((property_id IS NOT NULL) + (unit_id IS NOT NULL) + (rental_id IS NOT NULL) = 1)'
            );
        } catch (\Throwable) {
        }
    }
};
