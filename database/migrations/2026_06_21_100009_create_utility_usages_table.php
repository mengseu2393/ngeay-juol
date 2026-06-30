<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utility_id')->constrained('utilities')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete(); // primary reading path (no meters)
            $table->foreignId('rental_id')->nullable()->constrained('rentals')->nullOnDelete();
            $table->foreignId('landlord_id')->constrained('users')->restrictOnDelete(); // DENORMALIZED for scoping
            $table->foreignId('recorded_by_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('reading_type')->default(1); // ReadingType: Actual=1
            $table->date('reading_date')->nullable();
            $table->decimal('old_reading', 12, 3)->nullable();
            $table->decimal('new_reading', 12, 3)->nullable();
            $table->decimal('amount_used', 12, 3)->default(0);
            $table->boolean('is_waived')->default(false);
            $table->timestamps();

            $table->index('unit_id');
            $table->index('rental_id');
            $table->index('landlord_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_usages');
    }
};
