<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Http\Middleware\IdleTimeout;
use App\Models\User;
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

    public function test_landlord_user_is_logged_out_after_idle_timeout(): void
    {
        $landlord = User::factory()->create(['status' => UserStatus::Active->value]);
        $landlord->assignRole('landlord');

        $this->actingAs($landlord);
        session(['_last_activity_at' => now()->subMinutes(IdleTimeout::MINUTES + 1)->timestamp]);

        $response = $this->get('/app/simple');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
