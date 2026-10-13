<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utility_usages', function (Blueprint $table) {
            // A "reset" reading marks a new starting point (meter swapped / restarted at 0).
            // Consumption is never measured across it, and it is not billed.
            $table->boolean('is_reset')->default(false)->after('is_waived');
        });
    }

    public function down(): void
    {
        Schema::table('utility_usages', function (Blueprint $table) {
            $table->dropColumn('is_reset');
        });
    }
};
