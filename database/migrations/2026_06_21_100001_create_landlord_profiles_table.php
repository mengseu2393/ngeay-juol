<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landlord_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->json('payout_details')->nullable();
            $table->boolean('can_create_tenants')->default(false); // delegated tenant creation (least privilege)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landlord_profiles');
    }
};
