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
        DB::statement('UPDATE rentals SET property_id = (SELECT property_id FROM units WHERE units.id = rentals.unit_id) WHERE property_id IS NULL');

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('property_id')->nullable()->after('rental_id')->constrained('properties')->cascadeOnDelete();
            $table->index('property_id');
        });
        DB::statement('UPDATE invoices SET property_id = (SELECT property_id FROM rentals WHERE rentals.id = invoices.rental_id) WHERE property_id IS NULL');
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
