<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utility_id')->constrained('utilities')->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->cascadeOnDelete(); // null = global price
            $table->decimal('price', 12, 4);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestamps();

            $table->index(['utility_id', 'property_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_prices');
    }
};
