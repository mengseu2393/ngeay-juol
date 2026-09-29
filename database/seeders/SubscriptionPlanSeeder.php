<?php

namespace Database\Seeders;

use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The default "Free" package every self-registered landlord is put on
 * (see LandlordRegistrationService::signupPlan()).
 *
 * Forever + unlimited by default. Platform staff tune it afterwards in the
 * Subscription Plans resource (switch the interval to Monthly/Quarterly/Yearly
 * for a time-limited free period, or add limits). firstOrCreate() never
 * overwrites those edits on a re-run.
 */
class SubscriptionPlanSeeder extends Seeder
{
    public const FREE_SLUG = 'free';

    public function run(): void
    {
        DB::transaction(function () {
            SubscriptionPlan::firstOrCreate(
                ['slug' => self::FREE_SLUG],
                [
                    'name' => 'Free',
                    'description' => 'Default plan for landlords who sign up themselves.',
                    'billing_model' => PlanBillingModel::Flat,
                    'interval' => PlanInterval::Lifetime,
                    'price' => 0,
                    'max_units' => null,
                    'max_properties' => null,
                    'trial_days' => 0,
                    'grace_days' => 0,
                    'currency' => 'USD',
                    'is_active' => true,
                    'sort_order' => 0,
                ],
            );
        });
    }
}
