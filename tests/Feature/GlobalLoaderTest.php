<?php

namespace Tests\Feature;

use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The loading spinner is meant to be on every page, and the app serves pages from four
 * different shells — the two Filament panels, the tenant portal layout, and the standalone
 * auth/marketing views. Nothing but a test walks all four, so this is what stops one of them
 * from quietly drifting back to a blank white load.
 */
class GlobalLoaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function assertPageHasLoader(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertSuccessful();
        $response->assertSee('css/rentwise-loader.css', false);
        $response->assertSee('js/rentwise-loader.js', false);
    }

    public function test_marketing_page_has_the_loader(): void
    {
        $this->assertPageHasLoader($this->get('/'));
    }

    public function test_auth_screens_have_the_loader(): void
    {
        $this->assertPageHasLoader($this->get('/login'));
        $this->assertPageHasLoader($this->get('/forgot-password'));
    }

    public function test_landlord_panel_has_the_loader(): void
    {
        $this->assertPageHasLoader($this->actingAs($this->makeLandlord())->get('/app/simple'));
    }

    public function test_admin_panel_has_the_loader(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['status' => UserStatus::Active])->save();
        $admin->assignRole('super_admin');

        $this->assertPageHasLoader(
            $this->actingAs($admin)->get(route('filament.admin.pages.dashboard'))
        );
    }

    public function test_tenant_portal_has_the_loader(): void
    {
        $tenant = User::factory()->create();
        $tenant->assignRole('tenant');

        $this->assertPageHasLoader($this->actingAs($tenant)->get(route('portal.dashboard')));
    }

    /**
     * The panels' inline spinners come from a published vendor view, which a `filament:upgrade`
     * or a re-publish would happily overwrite with Filament's own SVG.
     */
    public function test_filament_loading_indicator_draws_the_rentwise_dots(): void
    {
        $html = Blade::render('<x-filament::loading-indicator />');

        $this->assertStringContainsString('rw-spin', $html);
        $this->assertStringContainsString('fill="currentColor"', $html);
        $this->assertSame(4, substr_count($html, '<circle'));
    }

    /**
     * Livewire's own progress bar is a second loading indicator on every SPA navigation, and
     * it has to stay hidden in CSS: `livewire.navigate.show_progress_bar => false` throws a
     * ReferenceError out of Livewire 3.8.1's bundle init and takes Alpine down with it. If
     * anyone ever "tidies up" this rule, that config looks like the obvious replacement.
     */
    public function test_loader_stylesheet_hides_livewires_progress_bar(): void
    {
        $this->assertStringContainsString(
            '#nprogress',
            file_get_contents(public_path('css/rentwise-loader.css')),
        );

        $this->assertFalse(
            file_exists(config_path('livewire.php')) && config('livewire.navigate.show_progress_bar') === false,
            'Disabling Livewire\'s progress bar in config breaks Alpine boot in Livewire 3.8.1.',
        );
    }

    private function makeLandlord(): User
    {
        $landlord = User::factory()->create();
        $landlord->forceFill(['status' => UserStatus::Active])->save();
        $landlord->assignRole('landlord');

        $plan = SubscriptionPlan::firstOrCreate(['slug' => 'starter'], [
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
}
