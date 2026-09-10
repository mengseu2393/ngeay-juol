<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exit path for `rentals.security_deposit`.
 *
 * A deposit is collected on every tenancy (and billed as a first-invoice line),
 * but before this table there was nowhere to record what happened to it — the
 * money went in and the system could neither give it back nor explain why it
 * had not. docs/MOVE_IN_BILLING_RULES.md ("Security-deposit lifecycle") asks for
 * a held amount, confirmed deductions, a refund figure and a refund reference;
 * this is that record, one per tenancy.
 *
 * Amounts follow the invoice-line money convention: a native `*_amount` in
 * `currency`, USD/KHR twins, and the `exchange_rate` that produced them —
 * snapshotted here so a later re-read never converts at a different rate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('landlord_id')->constrained('users')->restrictOnDelete(); // DENORMALIZED for scoping
            // The closing invoice this settlement was calculated alongside, when one was made.
            $table->foreignId('final_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 12, 4)->nullable(); // USD→KHR, snapshotted

            // Held: the deposit the tenancy actually agreed to (rentals.security_deposit).
            $table->decimal('deposit_amount', 12, 2)->default(0);
            $table->decimal('deposit_amount_usd', 12, 2)->default(0);
            $table->decimal('deposit_amount_khr', 12, 0)->default(0);

            // Withheld: recomputed from deposit_deductions, never written by the parent's writer.
            $table->decimal('deductions_total', 12, 2)->default(0);
            $table->decimal('deductions_total_usd', 12, 2)->default(0);
            $table->decimal('deductions_total_khr', 12, 0)->default(0);

            // Owed back: deposit − deductions.
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->decimal('refund_amount_usd', 12, 2)->default(0);
            $table->decimal('refund_amount_khr', 12, 0)->default(0);

            $table->unsignedTinyInteger('status')->default(2); // DepositSettlementStatus: Draft=1, Settled=2, Refunded=3
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('settled_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('refund_reference')->nullable();
            $table->date('refund_paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('landlord_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_settlements');
    }
};
