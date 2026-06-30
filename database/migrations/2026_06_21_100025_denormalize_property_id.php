<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalize property_id onto rentals + invoices so the Property workspace can
 * expose them as clean, createable relation-manager tabs (and for cheap scoping).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->after('unit_id')->constrained('properties')->cascadeOnDelete();
            $table->index('property_id');
        });
        DB::statement('UPDATE rentals r JOIN units u ON u.id = r.unit_id SET r.property_id = u.property_id WHERE r.property_id IS NULL');

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->after('rental_id')->constrained('properties')->cascadeOnDelete();
            $table->index('property_id');
        });
        DB::statement('UPDATE invoices i JOIN rentals r ON r.id = i.rental_id SET i.property_id = r.property_id WHERE i.property_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_id');
        });
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_id');
        });
    }
};
