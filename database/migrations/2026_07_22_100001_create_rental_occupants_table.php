<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_occupants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_id')->constrained('rentals')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Role within the tenancy
            $table->string('role')->default('primary'); // primary, co_tenant, dependent

            // Personal details
            $table->string('occupant_name');
            $table->string('occupant_phone')->nullable();
            $table->string('occupant_id_card')->nullable();
            $table->string('occupant_address')->nullable();
            $table->string('occupant_gender')->nullable();
            $table->date('occupant_dob')->nullable();
            $table->string('occupant_nationality')->nullable();
            $table->string('occupant_workplace')->nullable();

            // Emergency contact
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone')->nullable();
            $table->string('emergency_contact_relationship')->nullable();

            // Guarantor
            $table->string('guarantor_name')->nullable();
            $table->string('guarantor_phone')->nullable();
            $table->string('guarantor_id_number')->nullable();
            $table->string('guarantor_address')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('rental_id');
            $table->index('user_id');
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_occupants');
    }
};
