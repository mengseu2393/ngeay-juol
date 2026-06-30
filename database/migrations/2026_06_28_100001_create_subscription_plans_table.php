<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('billing_model')->default(3); // PlanBillingModel: Tiered=3
            $table->unsignedTinyInteger('interval')->default(1);      // PlanInterval: Monthly=1
            $table->decimal('price', 12, 2);
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->unsignedInteger('max_units')->nullable();
            $table->unsignedInteger('max_properties')->nullable();
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedSmallInteger('grace_days')->default(0);
            $table->json('features')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
