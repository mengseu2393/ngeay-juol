<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'two_factor_secret')) {
            Schema::table('users', function (Blueprint $table) {
                $table->text('two_factor_secret')
                    ->after('password')
                    ->nullable();

                $table->text('two_factor_recovery_codes')
                    ->after('two_factor_secret')
                    ->nullable();

                $table->timestamp('two_factor_confirmed_at')
                    ->after('two_factor_recovery_codes')
                    ->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('users', 'two_factor_secret')) {
                $columns[] = 'two_factor_secret';
            }
            if (Schema::hasColumn('users', 'two_factor_recovery_codes')) {
                $columns[] = 'two_factor_recovery_codes';
            }
            if (Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                $columns[] = 'two_factor_confirmed_at';
            }
            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
