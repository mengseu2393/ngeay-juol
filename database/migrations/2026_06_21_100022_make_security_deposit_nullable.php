<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            // A rental may have no deposit; allow null (blank in the form).
            $table->decimal('security_deposit', 12, 2)->nullable()->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->decimal('security_deposit', 12, 2)->nullable(false)->default(0)->change();
        });
    }
};
