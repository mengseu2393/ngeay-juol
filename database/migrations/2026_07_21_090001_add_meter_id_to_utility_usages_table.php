<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which device produced a reading. Nullable on purpose: every existing row keeps
 * a NULL meter and still bills through the legacy "latest row" path, so this can
 * be adopted room by room (`utilities:backfill-meters`) instead of big-bang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utility_usages', function (Blueprint $table) {
            $table->foreignId('meter_id')
                ->nullable()
                ->after('property_utility_id')
                ->constrained('utility_meters')
                ->nullOnDelete();

            $table->index(['meter_id', 'reading_date'], 'utility_usages_meter_date_idx');
        });
    }

    public function down(): void
    {
        Schema::table('utility_usages', function (Blueprint $table) {
            $table->dropIndex('utility_usages_meter_date_idx');
            $table->dropConstrainedForeignId('meter_id');
        });
    }
};
