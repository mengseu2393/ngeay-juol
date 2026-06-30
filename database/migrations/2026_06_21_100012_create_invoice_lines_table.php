<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedTinyInteger('line_type'); // InvoiceLineType: Rent=1, Utility=2, AdHoc=3
            $table->foreignId('utility_usage_id')->nullable()->constrained('utility_usages')->nullOnDelete();
            $table->string('description');
            $table->decimal('quantity', 12, 3)->nullable();
            $table->decimal('unit_price', 12, 4)->nullable();
            $table->decimal('amount', 12, 2);
            $table->boolean('is_waived')->default(false);
            $table->timestamps();

            $table->unique('utility_usage_id'); // each reading billed exactly once
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
