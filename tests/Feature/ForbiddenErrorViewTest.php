<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Custom resources/views/errors/403.blade.php replaces Laravel's bare default
 * page with a "Go back" action and full Khmer localization, matching
 * docs/LOCALIZATION.md's rule that all UI chrome goes through __().
 */
class ForbiddenErrorViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_forbidden_page_shows_a_back_button(): void
    {
        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $response = $this->actingAs($tenant)
            ->withSession(['locale' => 'en'])
            ->post(route('landlord.simple-mode.toggle'));

        $response->assertForbidden();
        $response->assertSee('Go back');
        $response->assertSee('history.back()', false);
    }

    public function test_forbidden_page_is_translated_to_khmer(): void
    {
        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $response = $this->actingAs($tenant)
            ->withSession(['locale' => 'km'])
            ->post(route('landlord.simple-mode.toggle'));

        $response->assertForbidden();
        $response->assertSee('ត្រឡប់ក្រោយ');
        $response->assertSee('អ្នកមិនមានសិទ្ធិមើលទំព័រនេះទេ');
    }
}
