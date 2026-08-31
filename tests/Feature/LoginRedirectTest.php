<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_landlord_login_with_admin_intended_url_redirects_to_landlord_dashboard(): void
    {
        $landlord = User::factory()->create([
            'email' => 'landlord@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active->value,
        ]);
        $landlord->assignRole('landlord');

        // Put the /admin url in session
        session(['url.intended' => '/admin/dashboard']);

        $response = $this->post(route('login'), [
            'login' => $landlord->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('filament.landlord.pages.dashboard'));
        $this->assertNull(session('url.intended'));
    }

    public function test_landlord_login_with_landlord_intended_url_is_honored(): void
    {
        $landlord = User::factory()->create([
            'email' => 'landlord@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active->value,
        ]);
        $landlord->assignRole('landlord');

        // Put the landlord sub-page URL in session
        session(['url.intended' => '/app/invoices']);

        $response = $this->post(route('login'), [
            'login' => $landlord->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/app/invoices');
    }

    public function test_tenant_login_with_admin_or_landlord_intended_url_redirects_to_tenant_portal(): void
    {
        $tenant = User::factory()->create([
            'email' => 'tenant@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active->value,
        ]);
        $tenant->assignRole('tenant');

        // Case 1: /admin intended url
        session(['url.intended' => '/admin/users']);
        $response = $this->post(route('login'), [
            'login' => $tenant->email,
            'password' => 'password',
        ]);
        $response->assertRedirect(route('portal.dashboard'));
        $this->assertNull(session('url.intended'));

        // Log out
        $this->post(route('logout'));

        // Case 2: /landlord intended url
        session(['url.intended' => '/app/invoices']);
        $response = $this->post(route('login'), [
            'login' => $tenant->email,
            'password' => 'password',
        ]);
        $response->assertRedirect(route('portal.dashboard'));
        $this->assertNull(session('url.intended'));
    }

    public function test_tenant_login_with_allowed_intended_url_is_honored(): void
    {
        $tenant = User::factory()->create([
            'email' => 'tenant@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active->value,
        ]);
        $tenant->assignRole('tenant');

        session(['url.intended' => '/portal/invoices']);

        $response = $this->post(route('login'), [
            'login' => $tenant->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/portal/invoices');
    }

    public function test_platform_staff_login_with_any_intended_url_is_honored(): void
    {
        $staff = User::factory()->create([
            'email' => 'staff@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active->value,
        ]);
        $staff->assignRole('super_admin');

        session(['url.intended' => '/admin/users']);

        $response = $this->post(route('login'), [
            'login' => $staff->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/admin/users');
    }
}
