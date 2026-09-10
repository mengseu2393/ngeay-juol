<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One itemised "we kept $X because Y" row against a deposit settlement.
 *
 * Itemised rather than a single `deductions_total` column on the settlement so
 * the tenant can be shown (and can later dispute) each line, and so the total is
 * always derivable from evidence rather than asserted. The settlement's totals
 * are recomputed from these rows in DepositDeduction::booted().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deposit_settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landlord_id')->constrained('users')->restrictOnDelete(); // DENORMALIZED for scoping

            $table->unsignedTinyInteger('category')->default(6); // DepositDeductionCategory: UnpaidRent=1, UnpaidUtilities=2, Damage=3, Cleaning=4, LostKeys=5, Other=6
            $table->string('reason');

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->decimal('amount_usd', 12, 2)->default(0);
            $table->decimal('amount_khr', 12, 0)->default(0);
            $table->decimal('exchange_rate', 12, 4)->nullable(); // the settlement's saved rate

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('landlord_id');
            $table->index(['deposit_settlement_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_deductions');
    }
};
