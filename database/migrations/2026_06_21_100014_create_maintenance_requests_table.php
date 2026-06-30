<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('landlord_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('rental_id')->nullable()->constrained('rentals')->nullOnDelete();
            $table->string('title');
            $table->text('description');
            $table->unsignedTinyInteger('priority')->default(2); // MaintenancePriority: Medium=2
            $table->unsignedTinyInteger('status')->default(1);   // MaintenanceStatus: Open=1
            $table->timestamps();
            $table->softDeletes();

            $table->index('landlord_id');
            $table->index('tenant_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_requests');
    }
};
