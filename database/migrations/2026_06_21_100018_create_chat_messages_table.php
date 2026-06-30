<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_room_id')->constrained('chat_rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('type')->default(1); // ChatMessageType: Text=1
            $table->text('body')->nullable();
            $table->string('file_url')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['chat_room_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
