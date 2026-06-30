<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('landlord_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->unsignedTinyInteger('method')->default(1); // PaymentMethod enum
            $table->unsignedTinyInteger('status')->default(1); // SubscriptionPaymentStatus: Pending=1
            $table->timestamp('paid_at')->nullable();
            $table->date('covers_from');
            $table->date('covers_to');
            $table->string('gateway')->nullable();
            $table->string('gateway_transaction_id')->nullable();
            $table->string('gateway_ref')->nullable();
            $table->string('receipt_number')->nullable()->unique();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('subscription_id');
            $table->index('landlord_id');
            $table->index('status');

            // Idempotency: no duplicate transaction per gateway
            $table->unique(['gateway', 'gateway_transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
