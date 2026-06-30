<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->unsignedTinyInteger('status')->default(1); // SubscriptionStatus: Pending=1

            // Snapshot of plan terms at assignment
            $table->unsignedTinyInteger('billing_model')->default(3);
            $table->unsignedTinyInteger('interval')->default(1);
            $table->decimal('price', 12, 2);
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->unsignedInteger('max_units')->nullable();
            $table->unsignedInteger('max_properties')->nullable();
            $table->json('features')->nullable();
            $table->string('currency', 3)->default('USD');

            // Dates
            $table->date('starts_at');
            $table->date('ends_at');
            $table->date('grace_ends_at')->nullable();
            $table->date('trial_ends_at')->nullable();

            // Flags
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->unsignedInteger('current_unit_count')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('plan_id');
            $table->index('status');
            $table->index('ends_at');

            // Uniqueness on (landlord_id) is enforced at the application layer
            // by SubscriptionService::assign() (MariaDB/MySQL 5.x don't support
            // partial unique indexes with WHERE deleted_at IS NULL).
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
