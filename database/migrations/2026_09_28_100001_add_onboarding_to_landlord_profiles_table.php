<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setup-wizard progress for self-registered landlords (/app/setup).
 *
 * onboarding_step stays NULL for every landlord provisioned by platform staff,
 * so the wizard redirect (RedirectToLandlordSetup) never touches them — no
 * backfill needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landlord_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('onboarding_step')->nullable()->after('can_create_tenants'); // OnboardingStep: Properties=2; NULL = not self-registered
            $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_step');
        });
    }

    public function down(): void
    {
        Schema::table('landlord_profiles', function (Blueprint $table) {
            $table->dropColumn(['onboarding_step', 'onboarding_completed_at']);
        });
    }
};
