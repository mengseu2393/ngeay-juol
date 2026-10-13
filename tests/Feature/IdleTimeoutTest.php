<?php

namespace Tests\Feature;

use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Http\Middleware\IdleTimeout;
use App\Models\Property;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\DeviceSession;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdleTimeoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_staff_user_is_logged_out_after_idle_timeout(): void
    {
        $staff = User::factory()->create(['status' => UserStatus::Active->value]);
        $staff->assignRole('super_admin');

        $this->actingAs($staff);
        session(['_last_activity_at' => now()->subMinutes(IdleTimeout::MINUTES + 1)->timestamp]);

        $response = $this->get('/admin/qr-code-generator');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_staff_user_within_idle_window_stays_logged_in(): void
    {
        $staff = User::factory()->create(['status' => UserStatus::Active->value]);
        $staff->assignRole('super_admin');

        $this->actingAs($staff);
        session(['_last_activity_at' => now()->subMinutes(IdleTimeout::MINUTES - 1)->timestamp]);

        $response = $this->get('/admin/qr-code-generator');

        $response->assertOk();
        $this->assertAuthenticated();
    }

    /** Desktop keeps the idle logout (subscribed, so nothing else would sign them out). */
    public function test_landlord_user_is_logged_out_after_idle_timeout(): void
    {
        $landlord = $this->subscribedLandlord();

        $this->actingAs($landlord);
        session(['_last_activity_at' => now()->subMinutes(IdleTimeout::MINUTES + 1)->timestamp]);

        $response = $this->get('/app/simple');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * A landlord's phone is a daily tool: it must stay signed in like a native app
     * instead of dropping to the login page every time it's reopened after 15 minutes.
     */
    public function test_landlord_on_a_phone_is_not_logged_out_after_idle_time(): void
    {
        $landlord = $this->subscribedLandlord();

        $this->actingAs($landlord);
        session(['_last_activity_at' => now()->subMinutes(IdleTimeout::MINUTES + 60)->timestamp]);

        $response = $this->withHeader('User-Agent', self::PHONE)->get('/app/simple');

        $response->assertSuccessful();
        $this->assertAuthenticated();
    }

    /** The installed app on a tablet (iPadOS reports a desktop user agent). */
    public function test_landlord_in_the_installed_app_is_not_logged_out_after_idle_time(): void
    {
        $landlord = $this->subscribedLandlord();

        $this->actingAs($landlord);
        session(['_last_activity_at' => now()->subMinutes(IdleTimeout::MINUTES + 60)->timestamp]);

        $response = $this->withUnencryptedCookie(DeviceSession::DISPLAY_MODE_COOKIE, 'standalone')
            ->get('/app/simple');

        $response->assertSuccessful();
        $this->assertAuthenticated();
    }

    /** Platform staff keep the idle logout everywhere, phone included. */
    public function test_staff_on_a_phone_is_still_logged_out_after_idle_time(): void
    {
        $staff = User::factory()->create(['status' => UserStatus::Active->value]);
        $staff->assignRole('super_admin');

        $this->actingAs($staff);
        session(['_last_activity_at' => now()->subMinutes(IdleTimeout::MINUTES + 1)->timestamp]);

        $this->withHeader('User-Agent', self::PHONE)
            ->get('/admin/qr-code-generator')
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** Subscribed, with a property — so only IdleTimeout could send them to /login. */
    private function subscribedLandlord(): User
    {
        $landlord = User::factory()->create(['status' => UserStatus::Active->value]);
        $landlord->assignRole('landlord');
        Property::create(['landlord_id' => $landlord->id, 'name' => 'Idle Property']);

        $plan = SubscriptionPlan::create([
            'slug' => 'starter',
            'name' => 'Starter',
            'billing_model' => PlanBillingModel::Tiered,
            'interval' => PlanInterval::Monthly,
            'price' => 30,
            'currency' => 'USD',
            'trial_days' => 0,
            'grace_days' => 7,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Subscription::withoutGlobalScopes()->create([
            'landlord_id' => $landlord->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'billing_model' => PlanBillingModel::Tiered,
            'interval' => PlanInterval::Monthly,
            'price' => 30,
            'currency' => 'USD',
            'starts_at' => now()->startOfMonth(),
            'ends_at' => now()->addMonth()->endOfMonth(),
            'auto_renew' => true,
        ]);

        return $landlord;
    }

    private const PHONE = 'Mozilla/5.0 (Linux; Android 13; SM-A145F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';
}
