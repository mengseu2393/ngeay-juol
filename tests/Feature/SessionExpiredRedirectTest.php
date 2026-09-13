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
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Livewire's default on a failed request (e.g. session/CSRF expired mid-page,
 * so /livewire/update comes back 419) is to surface the failure directly —
 * a confirm() prompt or raw error HTML, neither of which means anything to a
 * landlord/tenant. components.rw-session-expired-redirect intercepts that and
 * sends them to the login page instead, silently.
 */
class SessionExpiredRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_landlord_panel_renders_the_session_expired_redirect_script(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('landlord'));

        $landlord = User::create([
            'name' => 'Landlord',
            'email' => 'landlord-session-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
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

        $response = $this->actingAs($landlord)->get('/app');

        $response->assertSuccessful();
        $response->assertSee("Livewire.hook('request'", false);
        $response->assertSee('status === 419', false);
    }

    public function test_admin_panel_renders_the_session_expired_redirect_script(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-session-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);
        $admin->forceFill(['status' => UserStatus::Active])->save();
        $admin->assignRole('super_admin');

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertSuccessful();
        $response->assertSee("Livewire.hook('request'", false);
    }
}
