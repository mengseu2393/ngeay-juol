<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Room accounts can log in by username (tenants may have no email).
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('email');
            $table->string('email')->nullable()->change(); // optional for room accounts
        });

        // Each unit has one permanent login account (reused across occupants).
        Schema::table('units', function (Blueprint $table) {
            $table->foreignId('account_user_id')->nullable()->after('room_type')
                ->constrained('users')->nullOnDelete();
        });

        // Capture each occupant per tenancy period (history of C1 2024, C2 2025…)
        // even though the room login account is reused.
        Schema::table('rentals', function (Blueprint $table) {
            $table->string('occupant_name')->nullable()->after('tenant_id');
            $table->string('occupant_phone')->nullable()->after('occupant_name');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn(['occupant_name', 'occupant_phone']);
        });
        Schema::table('units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_user_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
            $table->string('email')->nullable(false)->change();
        });
    }
};
