<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_login_tokens', function (Blueprint $table) {
            $table->id();

            // The account the QR code signs in.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Only the SHA-256 of the random token is persisted — the raw value
            // exists exactly once, inside the generated QR image. A database leak
            // therefore yields no usable login link.
            $table->string('token_hash', 64)->unique();

            $table->timestamp('expires_at');

            // Set atomically on redemption; a non-null value burns the token.
            $table->timestamp('used_at')->nullable();

            // Audit trail: which platform staff member minted this link.
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_login_tokens');
    }
};
