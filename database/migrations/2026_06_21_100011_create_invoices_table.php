<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_id')->constrained('rentals')->restrictOnDelete();
            $table->foreignId('landlord_id')->constrained('users')->restrictOnDelete(); // DENORMALIZED for scoping
            $table->foreignId('tenant_id')->constrained('users')->restrictOnDelete();   // DENORMALIZED for scoping
            $table->string('invoice_number')->unique(); // GENERATED on create — NOT NULL
            $table->decimal('amount_due', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('issue_date');
            $table->date('due_date');
            $table->unsignedTinyInteger('payment_status')->default(1); // InvoiceStatus: Draft=1 (single source of truth)
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('landlord_id');
            $table->index('tenant_id');
            $table->index('rental_id');
            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
