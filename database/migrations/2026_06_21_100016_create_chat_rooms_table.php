<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('type')->default(1); // ChatRoomType: Direct=1
            $table->nullableMorphs('related');               // polymorphic context (morph map aliases)
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_rooms');
    }
};
