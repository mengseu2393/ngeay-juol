<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->boolean('create_invoice_on_move_in')
                ->default(false)
                ->after('require_first_month_upfront')
                ->comment('Automatically generate the first rent invoice when a new tenant is created.');
        });
    }

    public function down(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn('create_invoice_on_move_in');
        });
    }
};
