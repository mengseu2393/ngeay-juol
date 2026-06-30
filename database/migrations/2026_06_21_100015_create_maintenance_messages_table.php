<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('maintenance_requests')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete(); // role derived from Spatie role, no free-string discriminator
            $table->text('body');
            $table->timestamps();

            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_messages');
    }
};
