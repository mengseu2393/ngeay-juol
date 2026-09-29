<?php

namespace Tests\Feature;

use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\SubscriptionAccess;
use App\Enums\UserStatus;
use App\Models\Property;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * "Forever" plans (the free sign-up plan) are a far-future ends_at rather than a
 * NULL one — subscriptions.ends_at is NOT NULL and effectiveAccess() revokes a
 * subscription without one. These pin that the expiry/renewal jobs leave it alone.
 */
class LifetimeSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        Carbon::setTestNow('2026-09-28 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_seeded_free_plan_is_forever_unlimited_and_rerunnable(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        $plans = SubscriptionPlan::where('slug', SubscriptionPlanSeeder::FREE_SLUG)->get();

        $this->assertCount(1, $plans);
        $this->assertSame(PlanInterval::Lifetime, $plans->first()->interval);
        $this->assertSame('0.00', $plans->first()->price);
        $this->assertNull($plans->first()->max_units);
        $this->assertNull($plans->first()->max_properties);
    }

    public function test_lifetime_subscription_has_full_access_and_is_never_expired_or_billed(): void
    {
        $landlord = $this->createLandlord();
        $plan = SubscriptionPlan::where('slug', SubscriptionPlanSeeder::FREE_SLUG)->first();

        $sub = SubscriptionService::assign($landlord, $plan, ['auto_renew' => false]);

        $this->assertTrue($sub->isLifetime());
        $this->assertTrue($sub->ends_at->greaterThan(now()->addYears(99)));
        $this->assertSame(SubscriptionAccess::Full, SubscriptionService::effectiveAccess($landlord));

        Carbon::setTestNow('2036-01-01 09:00:00');

        $this->assertSame(0, SubscriptionService::markExpired());
        $this->assertSame(0, SubscriptionService::ensurePendingRenewalPayments());
        $this->assertSame(SubscriptionAccess::Full, SubscriptionService::effectiveAccess($landlord));
    }

    public function test_property_cap_blocks_creating_more_properties_than_the_plan_allows(): void
    {
        $landlord = $this->createLandlord();
        $plan = SubscriptionPlan::create([
            'name' => 'One property',
            'slug' => 'one-property',
            'billing_model' => PlanBillingModel::Flat,
            'interval' => PlanInterval::Lifetime,
            'price' => 0,
            'max_properties' => 1,
            'currency' => 'USD',
        ]);
        SubscriptionService::assign($landlord, $plan);

        $this->actingAs($landlord);
        Property::create(['name' => 'First']);

        $this->expectException(ValidationException::class);
        Property::create(['name' => 'Second']);
    }

    private function createLandlord(): User
    {
        $landlord = User::factory()->create(['status' => UserStatus::Active->value]);
        $landlord->assignRole('landlord');

        return $landlord;
    }
}
