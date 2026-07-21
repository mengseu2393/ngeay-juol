<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The physical device. utility_usages used to imply one per (unit, utility) with
 * no record of when it was installed, what index it started at, or that it was
 * ever swapped — see the "(no meters)" note in create_utility_usages_table.
 *
 * Purely additive: nothing reads this table until config('utilities.meters') is
 * on AND a meter row exists for the room, so dropping the table (or flipping the
 * flag) restores the previous behaviour exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_meters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_utility_id')->constrained('property_utilities')->cascadeOnDelete();
            $table->foreignId('landlord_id')->constrained('users')->restrictOnDelete(); // DENORMALIZED for scoping
            $table->string('scope', 20)->default('unit'); // MeterScope
            $table->foreignId('unit_id')->nullable()->constrained('units')->cascadeOnDelete(); // scope=unit
            $table->string('floor_number')->nullable(); // scope=floor, mirrors units.floor_number
            $table->foreignId('parent_meter_id')->nullable()->constrained('utility_meters')->nullOnDelete(); // sub-meter → main

            $table->string('serial')->nullable();
            $table->unsignedTinyInteger('digits')->nullable(); // set → delta wraps at 10^digits
            $table->decimal('multiplier', 10, 4)->default(1); // CT ratio; billed = delta * multiplier

            $table->date('installed_on');
            $table->decimal('installed_reading', 12, 3)->default(0); // the opening index — usually NOT 0
            $table->date('removed_on')->nullable();
            $table->decimal('final_reading', 12, 3)->nullable();

            $table->string('status', 20)->default('active'); // MeterStatus
            $table->foreignId('replaced_meter_id')->nullable()->constrained('utility_meters')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // The hot path: "the active meter for this room + utility".
            $table->index(['property_utility_id', 'unit_id', 'status'], 'utility_meters_active_idx');
            $table->index('landlord_id');
            $table->index('serial');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_meters');
    }
};
