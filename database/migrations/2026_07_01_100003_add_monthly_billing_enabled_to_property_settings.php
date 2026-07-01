<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add monthly_billing_enabled flag to property_settings.
 *
 * When false (default) the Monthly Billing page shows an "Enable this feature
 * in Property Settings" prompt instead of loading due rooms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->boolean('monthly_billing_enabled')
                ->default(false)
                ->after('upfront_deposit_months')
                ->comment('Enable the zero-click Monthly Billing auto-load feature for this property.');
        });
    }

    public function down(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn('monthly_billing_enabled');
        });
    }
};
