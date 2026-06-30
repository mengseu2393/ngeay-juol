<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Normalized identity schema (§6.2): PK = id, password column = `password`,
     * status as tinyInteger (UserStatus enum), self-referencing created_by_id,
     * Fortify 2FA columns, OAuth provider columns (kept out of $fillable), soft deletes.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone_number')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->unsignedTinyInteger('status')->default(1); // UserStatus: Active=1

            // who created this account (admin/landlord-delegated creation)
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            // for landlord_manager role: the landlord this user acts on behalf of (drives LandlordScope)
            $table->foreignId('manages_landlord_id')->nullable()->constrained('users')->nullOnDelete();

            // Fortify two-factor authentication
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            // OAuth / social identity (set only via controlled callbacks, never $fillable)
            $table->string('provider')->nullable();
            $table->string('provider_id')->nullable();
            $table->string('google_id')->nullable();
            $table->string('facebook_id')->nullable();
            $table->string('telegram_id')->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['provider', 'provider_id']);
            $table->index('status');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
