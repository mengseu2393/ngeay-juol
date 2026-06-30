<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Canonical payments ledger (renamed from old payment_histories). All payment
        // writes MUST go through Invoice::recordPayment() — no direct amount_paid writes.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('recorded_by_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->dateTime('paid_at');
            $table->unsignedTinyInteger('method')->default(1); // PaymentMethod: Cash=1
            $table->string('transaction_ref')->nullable();
            $table->string('receipt_number')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'paid_at']);
            $table->index('recorded_by_id'); // FIX: the missing FK index from the old schema
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
