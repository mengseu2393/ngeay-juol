<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utility_id')->constrained('utilities')->cascadeOnDelete();
            $table->foreignId('landlord_id')->constrained('users')->restrictOnDelete(); // DENORMALIZED for scoping
            // Exactly one scope column is non-null (enforced by CHECK below + app layer)
            $table->foreignId('property_id')->nullable()->constrained('properties')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->cascadeOnDelete();
            $table->foreignId('rental_id')->nullable()->constrained('rentals')->cascadeOnDelete();
            $table->boolean('waived')->default(true);
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('landlord_id');
            $table->index(['utility_id', 'property_id', 'unit_id', 'rental_id'], 'idx_waiver_scope');
        });

        // Enforce "exactly one scope" — a NULL-unique index is defeated by MySQL/MariaDB
        // NULL semantics, so use a real CHECK constraint (§6.2). Graceful fallback if
        // the engine rejects it.
        try {
            $table = DB::getTablePrefix().'utility_waivers';
            DB::statement(
                "ALTER TABLE `{$table}` ADD CONSTRAINT chk_waiver_one_scope "
                .'CHECK ((property_id IS NOT NULL) + (unit_id IS NOT NULL) + (rental_id IS NOT NULL) = 1)'
            );
        } catch (\Throwable) {
            // Engine rejected CHECK — rely on application-layer validation.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_waivers');
    }
};
