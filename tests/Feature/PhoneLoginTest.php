<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhoneLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** Stored numbers predate any formatting rule, so any typed format must match. */
    public function test_landlord_can_sign_in_with_their_phone_number_in_any_format(): void
    {
        $landlord = $this->landlord(['phone_number' => '012 345 678']);

        foreach (['012345678', '012-345-678', '+855 12 345 678', '85512345678'] as $typed) {
            $this->post(route('login'), ['login' => $typed, 'password' => 'password'])
                ->assertRedirect(route('filament.landlord.pages.dashboard'));

            $this->assertAuthenticatedAs($landlord);
            $this->post(route('logout'));
        }
    }

    public function test_wrong_password_with_a_phone_number_fails(): void
    {
        $this->landlord(['phone_number' => '012345678']);

        $this->post(route('login'), ['login' => '012345678', 'password' => 'nope'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    /** phone_number isn't unique — the account whose password matches wins. */
    public function test_shared_phone_number_signs_in_the_account_whose_password_matches(): void
    {
        $this->landlord(['phone_number' => '012345678', 'password' => bcrypt('other-password')]);
        $landlord = $this->landlord(['phone_number' => '012345678']);

        $this->post(route('login'), ['login' => '012345678', 'password' => 'password']);

        $this->assertAuthenticatedAs($landlord);
    }

    public function test_username_and_email_sign_in_still_work(): void
    {
        $user = $this->landlord(['username' => 'riverside-101', 'email' => 'owner@example.com']);

        $this->post(route('login'), ['login' => 'riverside-101', 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'));

        $this->post(route('login'), ['login' => 'owner@example.com', 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_accounts_cannot_sign_in_by_phone(): void
    {
        $this->landlord(['phone_number' => '012345678', 'status' => UserStatus::Inactive->value]);

        $this->post(route('login'), ['login' => '012345678', 'password' => 'password'])
            ->assertSessionHasErrors('login');
    }

    private function landlord(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'password' => bcrypt('password'),
            'status' => UserStatus::Active->value,
        ], $attributes));
        $user->assignRole('landlord');

        return $user;
    }
}
