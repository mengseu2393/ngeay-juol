<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('property_type')->default(1); // PropertyType enum
            $table->text('description')->nullable();

            // Cambodian address fields
            $table->string('address_line')->nullable();
            $table->string('street')->nullable();
            $table->string('village')->nullable();   // Phum
            $table->string('commune')->nullable();   // Sangkat
            $table->string('district')->nullable();  // Khan
            $table->string('city')->nullable();      // Province / City
            $table->string('postal_code')->nullable();

            $table->json('amenities')->nullable();
            // total_floors / total_rooms are computed accessors (NOT stored) — see Property model
            $table->timestamps();
            $table->softDeletes();

            $table->index('landlord_id');
            $table->index('property_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
