<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move utilities from a GLOBAL catalog to PROPERTY-scoped records. Each property
 * owns its own utilities (provider, rate, billing rule); nothing is shared or
 * inherited across a landlord's properties. Existing data is migrated, not wiped.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Per-property utility definitions ---------------------------------
        Schema::create('property_utilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('landlord_id')->constrained('users')->restrictOnDelete(); // denormalized for scope
            $table->string('name');
            $table->string('unit_of_measure')->default('unit');
            $table->unsignedTinyInteger('billing_type')->default(1); // BillingType: Metered=1
            $table->decimal('rate', 12, 4)->default(0);
            $table->string('provider')->nullable();
            $table->string('account_ref')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('property_id');
            $table->index('landlord_id');
        });

        // 2. Per-property settings (1:1) --------------------------------------
        Schema::create('property_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->unique()->constrained('properties')->cascadeOnDelete();
            $table->string('currency', 8)->default('USD');
            $table->string('invoice_prefix')->nullable();
            $table->unsignedTinyInteger('due_day_of_month')->default(7);
            $table->decimal('late_fee', 12, 2)->default(0);
            $table->unsignedSmallInteger('default_lease_months')->nullable();
            $table->string('deposit_policy')->nullable();
            $table->string('water_billing_default')->nullable();
            $table->text('parking_info')->nullable();
            $table->text('insurance_info')->nullable();
            $table->string('caretaker_name')->nullable();
            $table->string('caretaker_phone')->nullable();
            $table->timestamps();
        });

        // 3. Repoint usages + waivers to property utilities -------------------
        Schema::table('utility_usages', function (Blueprint $table) {
            $table->foreignId('property_utility_id')->nullable()->after('utility_id')
                ->constrained('property_utilities')->nullOnDelete();
        });
        Schema::table('utility_waivers', function (Blueprint $table) {
            $table->foreignId('property_utility_id')->nullable()->after('utility_id')
                ->constrained('property_utilities')->nullOnDelete();
        });

        // 4. Backfill from the old global tables ------------------------------
        $this->backfill();

        // 5. Drop the old utility_id columns ----------------------------------
        Schema::table('utility_usages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('utility_id');
        });
        // Drop the FK BEFORE its backing index (MySQL needs an index for the FK).
        Schema::table('utility_waivers', function (Blueprint $table) {
            $table->dropForeign(['utility_id']);
        });
        Schema::table('utility_waivers', function (Blueprint $table) {
            $table->dropIndex('idx_waiver_scope'); // composite index leads with utility_id
            $table->dropColumn('utility_id');
            $table->index(['property_utility_id', 'unit_id', 'rental_id'], 'idx_waiver_scope2');
        });

        // 6. Drop the global utility tables -----------------------------------
        Schema::dropIfExists('utility_prices');
        Schema::dropIfExists('utilities');
    }

    private function backfill(): void
    {
        if (! Schema::hasTable('utilities')) {
            return;
        }

        $utilities = DB::table('utilities')->get()->keyBy('id');
        $prices = DB::table('utility_prices')->get();
        $now = Carbon::now();
        $cache = []; // "propertyId:utilityId" => property_utility_id

        $resolve = function (?int $propertyId, ?int $utilityId) use (&$cache, $utilities, $prices, $now) {
            if (! $propertyId || ! $utilityId || ! isset($utilities[$utilityId])) {
                return null;
            }
            $key = "{$propertyId}:{$utilityId}";
            if (isset($cache[$key])) {
                return $cache[$key];
            }

            $util = $utilities[$utilityId];
            $rate = optional(
                $prices->where('utility_id', $utilityId)->where('property_id', $propertyId)->sortByDesc('effective_from')->first()
                ?? $prices->where('utility_id', $utilityId)->whereNull('property_id')->sortByDesc('effective_from')->first()
            )->price ?? 0;
            $landlordId = DB::table('properties')->where('id', $propertyId)->value('landlord_id');

            return $cache[$key] = DB::table('property_utilities')->insertGetId([
                'property_id' => $propertyId,
                'landlord_id' => $landlordId,
                'name' => $util->name,
                'unit_of_measure' => $util->unit_of_measure,
                'billing_type' => 1,
                'rate' => $rate,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        };

        foreach (DB::table('utility_usages')->get() as $usage) {
            $propertyId = DB::table('units')->where('id', $usage->unit_id)->value('property_id');
            if ($puId = $resolve((int) $propertyId, (int) $usage->utility_id)) {
                DB::table('utility_usages')->where('id', $usage->id)->update(['property_utility_id' => $puId]);
            }
        }

        foreach (DB::table('utility_waivers')->get() as $waiver) {
            $propertyId = $waiver->property_id
                ?? DB::table('units')->where('id', $waiver->unit_id)->value('property_id')
                ?? DB::table('rentals')->where('id', $waiver->rental_id)->value('unit_id'); // resolved below
            if ($waiver->property_id === null && $waiver->unit_id === null && $waiver->rental_id) {
                $unitId = DB::table('rentals')->where('id', $waiver->rental_id)->value('unit_id');
                $propertyId = DB::table('units')->where('id', $unitId)->value('property_id');
            }
            if ($puId = $resolve($propertyId ? (int) $propertyId : null, (int) $waiver->utility_id)) {
                DB::table('utility_waivers')->where('id', $waiver->id)->update(['property_utility_id' => $puId]);
            }
        }
    }

    public function down(): void
    {
        // Recreate minimal global tables so the schema can roll back.
        Schema::create('utilities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('unit_of_measure');
            $table->timestamps();
        });
        Schema::create('utility_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utility_id')->constrained('utilities')->cascadeOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->cascadeOnDelete();
            $table->decimal('price', 12, 4);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestamps();
        });

        Schema::table('utility_usages', function (Blueprint $table) {
            $table->foreignId('utility_id')->nullable()->after('id')->constrained('utilities')->nullOnDelete();
            $table->dropConstrainedForeignId('property_utility_id');
        });
        Schema::table('utility_waivers', function (Blueprint $table) {
            $table->dropIndex('idx_waiver_scope2');
            $table->foreignId('utility_id')->nullable()->after('id')->constrained('utilities')->nullOnDelete();
            $table->dropConstrainedForeignId('property_utility_id');
        });

        Schema::dropIfExists('property_settings');
        Schema::dropIfExists('property_utilities');
    }
};
