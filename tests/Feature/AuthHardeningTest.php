<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

/**
 * Regression cover for the credential-surface hardening:
 *
 *  - POST /login is served by this app's own LoginController, not Fortify's, so
 *    Fortify never attached the `login` limiter it defines. Password guessing was
 *    unlimited; the throttle is wired manually in routes/web.php and must stay.
 *  - Public self-registration was on by default. RentWise provisions landlords
 *    through platform staff and tenants through landlords, so /register is an
 *    account-creation and user-enumeration surface with no legitimate user.
 *  - `Password::default()` silently degrades to `min:8` when no defaults are
 *    registered — FortifyServiceProvider registers them.
 */
class AuthHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        User::factory()->create([
            'email' => 'landlord@example.com',
            'password' => bcrypt('correct-horse'),
            'status' => UserStatus::Active->value,
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->from(route('login'))->post(route('login'), [
                'login' => 'landlord@example.com',
                'password' => 'wrong-guess-'.$attempt,
            ])->assertStatus(302);
        }

        $this->post(route('login'), [
            'login' => 'landlord@example.com',
            'password' => 'wrong-guess-6',
        ])->assertStatus(429);

        $this->assertGuest();
    }

    /**
     * The limiter is keyed by username + IP, so one account being hammered must
     * not lock every other landlord out of the login form.
     */
    public function test_the_login_limiter_is_scoped_to_the_submitted_username(): void
    {
        User::factory()->create([
            'email' => 'victim@example.com',
            'password' => bcrypt('correct-horse'),
            'status' => UserStatus::Active->value,
        ]);

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->post(route('login'), [
                'login' => 'attacked@example.com',
                'password' => 'guess-'.$attempt,
            ]);
        }

        $this->post(route('login'), [
            'login' => 'victim@example.com',
            'password' => 'correct-horse',
        ])->assertStatus(302);

        $this->assertAuthenticated();
    }

    public function test_public_registration_routes_are_disabled(): void
    {
        $this->assertFalse(
            app('router')->getRoutes()->hasNamedRoute('register'),
            'Public self-registration must not be routable.',
        );

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Walk In',
            'email' => 'walkin@example.com',
            'password' => 'Str0ngPassphrase!',
            'password_confirmation' => 'Str0ngPassphrase!',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'walkin@example.com']);
    }

    public function test_password_reset_requests_are_rate_limited(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/forgot-password', ['email' => 'landlord@example.com']);
        }

        $this->post('/forgot-password', ['email' => 'landlord@example.com'])
            ->assertStatus(429);
    }

    public function test_password_defaults_require_length_mixed_case_and_numbers(): void
    {
        $rules = ['password' => Password::default()];

        foreach (['short1A', 'alllowercase123', 'ALLUPPERCASE123', 'NoDigitsAtAllHere'] as $weak) {
            $this->assertTrue(
                Validator::make(['password' => $weak], $rules)->fails(),
                "[$weak] should be rejected by the password policy.",
            );
        }

        $this->assertFalse(
            Validator::make(['password' => 'Riverside2026Rent'], $rules)->fails(),
            'A long mixed-case password with digits should be accepted.',
        );
    }

    /**
     * ->uncompromised() hits the HaveIBeenPwned API. It is deliberately
     * production-only so local dev and CI never depend on outbound network
     * access — this asserts the non-production branch stays offline-safe by
     * accepting a password that is certainly in every breach corpus but
     * otherwise satisfies the policy.
     */
    public function test_the_breach_check_is_not_applied_outside_production(): void
    {
        $this->assertFalse(app()->isProduction());

        $this->assertFalse(
            Validator::make(['password' => 'Password1234'], ['password' => Password::default()])->fails(),
            'Outside production the policy must not perform a network breach lookup.',
        );
    }
}
