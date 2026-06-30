<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('landlord_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();
            $table->unsignedTinyInteger('action'); // SubscriptionAction enum
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('unit_count')->nullable();
            $table->decimal('amount_charged', 12, 2)->nullable();
            $table->json('meta')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('subscription_id');
            $table->index('landlord_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_histories');
    }
};
