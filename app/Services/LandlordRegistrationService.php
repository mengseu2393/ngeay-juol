<?php

namespace App\Services;

use App\Enums\OnboardingStep;
use App\Enums\UserStatus;
use App\Models\LandlordProfile;
use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\PhoneNumber;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Public landlord self-registration (/register) — step 1 of onboarding.
 *
 * Provisions exactly what platform staff do in CreateLandlord (landlord role,
 * Active status, a Subscription) plus a LandlordProfile marked to start the
 * setup wizard at Properties. All-or-nothing: a landlord without a Subscription
 * is Revoked by SubscriptionService::effectiveAccess(), i.e. an account that
 * exists but can't sign in.
 */
class LandlordRegistrationService
{
    /** Kill switch in Admin Settings → Landlord Registration. */
    public static function isOpen(): bool
    {
        return (bool) Setting::get('registration_enabled', true, 'registration');
    }

    /** The plan chosen in Admin Settings, falling back to the seeded Free plan. */
    public static function signupPlan(): ?SubscriptionPlan
    {
        $planId = Setting::get('signup_plan_id', null, 'registration');

        $plan = filled($planId)
            ? SubscriptionPlan::query()->where('is_active', true)->find($planId)
            : null;

        return $plan ?? SubscriptionPlan::query()
            ->where('slug', SubscriptionPlanSeeder::FREE_SLUG)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @param  array{name: string, email?: ?string, phone_number?: ?string, password: string, company_name?: ?string}  $data
     */
    public function register(array $data): User
    {
        $plan = static::signupPlan();

        if (! $plan) {
            throw new \RuntimeException(__('Registration is not available right now. Please contact support.'));
        }

        return DB::transaction(function () use ($data, $plan) {
            $user = User::create([
                'name' => $data['name'],
                'email' => filled($data['email'] ?? null) ? mb_strtolower($data['email']) : null,
                'phone_number' => filled($data['phone_number'] ?? null) ? PhoneNumber::normalize($data['phone_number']) : null,
                'password' => $data['password'],
            ]);

            // status isn't fillable — same as CreateLandlord::afterCreate().
            $user->forceFill(['status' => UserStatus::Active])->save();

            $user->assignRole('landlord');

            LandlordProfile::create([
                'user_id' => $user->id,
                'company_name' => $data['company_name'] ?? null,
                'onboarding_step' => OnboardingStep::Properties,
            ]);

            SubscriptionService::assign($user, $plan, ['auto_renew' => false]);

            return $user;
        });
    }
}
