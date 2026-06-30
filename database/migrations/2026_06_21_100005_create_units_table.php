<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('landlord_id')->constrained('users')->restrictOnDelete(); // DENORMALIZED for scoping
            $table->string('room_number');
            $table->string('floor_number')->nullable();
            $table->string('room_type'); // single column (no duplicate 'type')
            $table->decimal('rent_amount', 12, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->unsignedTinyInteger('status')->default(1); // UnitStatus: Available=1
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('property_id');
            $table->index('landlord_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
