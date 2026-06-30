<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('landlord_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('monthly_rent', 12, 2);
            $table->decimal('security_deposit', 12, 2)->default(0);
            $table->string('lease_agreement')->nullable(); // single contract-file column
            $table->text('terms_conditions')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->unsignedTinyInteger('status')->default(1); // RentalStatus: Active=1
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('landlord_id');
            $table->index('tenant_id');
            $table->index('unit_id');
            $table->index('status');
        });

        // DB-level "one active tenancy per unit" guard via a STORED generated column +
        // unique index. NULL for non-active rows (MariaDB allows many NULLs in a unique
        // index), = unit_id only while Active & not soft-deleted. Graceful fallback to
        // TenancyService::hasOverlap() if the engine rejects the DDL (§6.2).
        try {
            $table = DB::getTablePrefix().'rentals';
            DB::statement(
                "ALTER TABLE `{$table}` ADD COLUMN active_unit_id BIGINT UNSIGNED "
                .'GENERATED ALWAYS AS (CASE WHEN status = 1 AND deleted_at IS NULL THEN unit_id ELSE NULL END) STORED'
            );
            DB::statement("CREATE UNIQUE INDEX uniq_active_tenancy_per_unit ON `{$table}` (active_unit_id)");
        } catch (\Throwable) {
            // Engine rejected generated-column DDL — rely on application-layer overlap guard.
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rentals');
    }
};
