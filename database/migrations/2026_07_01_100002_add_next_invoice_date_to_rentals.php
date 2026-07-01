<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add next_invoice_date to rentals.
 *
 * This per-tenancy date drives the "Issue date" field in MonthlyBilling and
 * any "Create invoice" action, so the landlord doesn't have to re-enter it
 * every billing cycle. After an invoice is generated, the billing flow should
 * roll this forward to the following month automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->date('next_invoice_date')
                ->nullable()
                ->after('end_date')
                ->comment('Pre-set invoice/billing date; auto-populates issue_date on the next invoice run.');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn('next_invoice_date');
        });
    }
};
