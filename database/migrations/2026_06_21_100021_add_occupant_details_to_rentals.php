<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->string('occupant_id_card')->nullable()->after('occupant_phone');
            $table->string('occupant_address')->nullable()->after('occupant_id_card');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn(['occupant_id_card', 'occupant_address']);
        });
    }
};
