<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The exit half of the move-in columns added by 2026_07_12_030001. Move-in
 * already records when the tenancy started and who let the tenant in; until now
 * the only trace of a move-out was `status` flipping to Vacated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->date('move_out_date')->nullable()->after('move_in_promised_payment_date');
            $table->timestamp('moved_out_at')->nullable()->after('move_out_date');
            $table->foreignId('moved_out_by_id')->nullable()->after('moved_out_at')->constrained('users')->nullOnDelete();
            $table->text('move_out_reason')->nullable()->after('moved_out_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('moved_out_by_id');
            $table->dropColumn(['move_out_date', 'moved_out_at', 'move_out_reason']);
        });
    }
};
