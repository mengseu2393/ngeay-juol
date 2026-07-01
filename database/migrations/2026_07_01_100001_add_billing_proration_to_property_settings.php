<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add configurable rent proration / upfront-payment rules to property_settings.
 *
 * New columns:
 *  - first_month_billing_mode   string  full_month | prorated | half_month
 *  - proration_cutoff_day       int     Day-of-month threshold for half_month mode (1–28)
 *  - require_first_month_upfront bool   Must first-month invoice be paid before move-in?
 *  - upfront_deposit_months     int     How many months of rent to collect as deposit (0/1/2)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            // Proration rule — default "full_month" to preserve existing behaviour.
            $table->string('first_month_billing_mode', 32)
                ->default('full_month')
                ->after('deposit_policy');

            // Only relevant when mode = 'half_month'. Defaults to mid-month (15).
            $table->unsignedTinyInteger('proration_cutoff_day')
                ->default(15)
                ->after('first_month_billing_mode');

            // Gate: tenant must pay first invoice before being allowed to move in.
            $table->boolean('require_first_month_upfront')
                ->default(false)
                ->after('proration_cutoff_day');

            // 0 = no deposit; 1 = 1× monthly rent; 2 = 2× monthly rent.
            $table->unsignedTinyInteger('upfront_deposit_months')
                ->default(0)
                ->after('require_first_month_upfront');
        });
    }

    public function down(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn([
                'first_month_billing_mode',
                'proration_cutoff_day',
                'require_first_month_upfront',
                'upfront_deposit_months',
            ]);
        });
    }
};
