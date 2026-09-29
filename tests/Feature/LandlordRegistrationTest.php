<?php

namespace Tests\Feature;

use App\Enums\OnboardingStep;
use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\UserStatus;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LandlordRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secret-Pass-2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
    }

    public function test_register_page_is_shown_to_guests(): void
    {
        $this->get(route('register'))
            ->assertOk()
            ->assertSee(route('register'), false);
    }

    public function test_registering_with_a_phone_creates_a_ready_landlord_and_opens_the_setup_wizard(): void
    {
        $response = $this->post(route('register'), $this->payload([
            'phone_number' => '+855 12 345 678',
            'company_name' => 'Sok Rentals',
        ]));

        $response->assertRedirect(route('filament.landlord.pages.setup'));

        $user = User::where('phone_number', '012345678')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasRole('landlord'));
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertNull($user->email);

        $this->assertSame('Sok Rentals', $user->landlordProfile->company_name);
        $this->assertSame(OnboardingStep::Properties, $user->landlordProfile->onboarding_step);
        $this->assertNull($user->landlordProfile->onboarding_completed_at);

        $sub = Subscription::withoutGlobalScopes()->where('landlord_id', $user->id)->firstOrFail();
        $this->assertSame(SubscriptionPlanSeeder::FREE_SLUG, $sub->plan->slug);
        $this->assertTrue($sub->isLifetime());
        $this->assertFalse((bool) $sub->auto_renew);
    }

    /**
     * Regression: a browser session can still hold the login id of a user who has
     * since been deleted. Auth::check() is false then, but Auth::id() falls back to
     * that raw session value — and SubscriptionService stamped it into
     * subscription_histories.created_by_id, failing the FK and the whole sign-up.
     */
    public function test_a_stale_session_from_a_deleted_user_does_not_break_registration(): void
    {
        $deleted = User::factory()->create();
        $deleted->forceDelete();

        $this->withSession([Auth::guard()->getName() => $deleted->id])
            ->post(route('register'), $this->payload(['phone_number' => '012345678']))
            ->assertRedirect(route('filament.landlord.pages.setup'));

        $user = User::where('phone_number', '012345678')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('subscription_histories', ['landlord_id' => $user->id, 'created_by_id' => null]);
    }

    public function test_a_signed_in_user_cannot_register_another_account(): void
    {
        $landlord = User::factory()->create(['status' => UserStatus::Active->value]);
        $landlord->assignRole('landlord');

        $this->actingAs($landlord)
            ->post(route('register'), $this->payload(['phone_number' => '012345678']))
            ->assertRedirect(route('filament.landlord.pages.dashboard'));

        $this->assertDatabaseMissing('users', ['phone_number' => '012345678']);
    }

    public function test_registering_with_only_an_email_works(): void
    {
        $this->post(route('register'), $this->payload(['email' => 'Owner@Example.com']))
            ->assertRedirect(route('filament.landlord.pages.setup'));

        $this->assertDatabaseHas('users', ['email' => 'owner@example.com', 'phone_number' => null]);
    }

    public function test_phone_or_email_is_required(): void
    {
        $this->post(route('register'), $this->payload())
            ->assertSessionHasErrors(['phone_number', 'email']);

        $this->assertGuest();
    }

    /** Older rows store phones in any format, so uniqueness is checked in normalized form. */
    public function test_a_phone_already_registered_in_another_format_is_rejected(): void
    {
        User::factory()->create(['phone_number' => '012 345 678']);

        $this->post(route('register'), $this->payload(['phone_number' => '85512345678']))
            ->assertSessionHasErrors('phone_number');

        $this->assertSame(1, User::count());
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'owner@example.com']);

        $this->post(route('register'), $this->payload(['email' => 'owner@example.com']))
            ->assertSessionHasErrors('email');
    }

    /** Product decision: no strength rule — a simple password (even the phone number) is fine. */
    public function test_any_password_is_accepted_even_the_phone_number(): void
    {
        $this->post(route('register'), $this->payload([
            'phone_number' => '012345678',
            'password' => '012345678',
            'password_confirmation' => '012345678',
        ]))->assertRedirect(route('filament.landlord.pages.setup'));

        $this->post(route('logout'));

        $this->post(route('login'), ['login' => '012345678', 'password' => '012345678']);
        $this->assertAuthenticated();
    }

    public function test_password_confirmation_must_match(): void
    {
        $this->post(route('register'), $this->payload([
            'phone_number' => '012345678',
            'password' => '1234',
            'password_confirmation' => '4321',
        ]))->assertSessionHasErrors('password');

        $this->assertSame(0, User::count());
    }

    public function test_bots_filling_the_honeypot_are_rejected(): void
    {
        $this->post(route('register'), $this->payload(['phone_number' => '012345678', 'website' => 'http://spam.test']))
            ->assertSessionHasErrors('website');

        $this->assertSame(0, User::count());
    }

    public function test_new_landlords_get_the_plan_chosen_in_admin_settings(): void
    {
        $trial = SubscriptionPlan::create([
            'name' => 'Three months free',
            'slug' => 'three-months-free',
            'billing_model' => PlanBillingModel::Flat,
            'interval' => PlanInterval::Quarterly,
            'price' => 0,
            'currency' => 'USD',
        ]);
        Setting::set('signup_plan_id', $trial->id, 'string', 'registration');

        $this->post(route('register'), $this->payload(['phone_number' => '012345678']));

        $sub = Subscription::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($trial->id, $sub->plan_id);
        $this->assertFalse($sub->isLifetime());
        $this->assertSame(now()->startOfDay()->addMonths(3)->toDateString(), $sub->ends_at->toDateString());
    }

    /** No plan → no half-made account that exists but is Revoked at sign-in. */
    public function test_registration_creates_nothing_when_no_plan_is_available(): void
    {
        SubscriptionPlan::query()->update(['is_active' => false]);

        $this->post(route('register'), $this->payload(['phone_number' => '012345678']))
            ->assertSessionHasErrors('register');

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_registration_can_be_switched_off_by_the_admin(): void
    {
        Setting::set('registration_enabled', false, 'bool', 'registration');

        $this->get(route('register'))->assertNotFound();
        $this->post(route('register'), $this->payload(['phone_number' => '012345678']))->assertNotFound();
        $this->get('/')->assertDontSee(route('register'), false);
    }

    public function test_registration_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post(route('register'), $this->payload())->assertStatus(302);
        }

        $this->post(route('register'), $this->payload())->assertStatus(429);
    }

    public function test_home_and_login_pages_link_to_registration(): void
    {
        $this->get('/')->assertSee(route('register'), false);
        $this->get(route('login'))->assertSee(route('register'), false);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sok Dara',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ], $overrides);
    }
}
