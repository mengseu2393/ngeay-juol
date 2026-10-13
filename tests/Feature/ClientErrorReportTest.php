<?php

namespace Tests\Feature;

use App\Enums\ErrorLogType;
use App\Enums\PlanBillingModel;
use App\Enums\PlanInterval;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\ErrorLog;
use App\Models\Property;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\ActiveProperty;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Simple Mode "sometimes breaks" on phones in ways the server never sees: a JS
 * exception, a Livewire request that died on a flaky connection or came back
 * 5xx (Livewire then shows the raw error page in an overlay). The browser
 * reports those to /client-errors so they land in /admin → Error Log with the
 * device context (installed app vs tab, viewport, zoom) needed to reproduce them.
 */
class ClientErrorReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        ActiveProperty::clear();
    }

    protected function tearDown(): void
    {
        ActiveProperty::clear();

        parent::tearDown();
    }

    public function test_a_browser_error_is_recorded_with_its_page_and_device_context(): void
    {
        $landlord = $this->makeLandlord();

        $this->actingAs($landlord)
            ->postJson(route('client-errors.store'), [
                'kind' => 'livewire',
                'message' => 'Livewire request failed: HTTP 502',
                'page' => 'https://example.test/app/simple?screen=rooms',
                'source' => '/livewire/update',
                'status' => 502,
                'stack' => '<html>Bad Gateway</html>',
                'client' => [
                    'display_mode' => 'standalone',
                    'viewport' => '390x664',
                    'zoom' => '1.15',
                    'components' => 'simple-room-list',
                ],
            ])
            ->assertNoContent();

        $log = ErrorLog::sole();
        $this->assertSame(ErrorLogType::Client, $log->type);
        $this->assertSame('Livewire request failed: HTTP 502', $log->message);
        $this->assertSame('client:livewire', $log->exception_class);
        // The page the user was on, not the report endpoint.
        $this->assertSame('https://example.test/app/simple?screen=rooms', $log->url);
        $this->assertNull($log->method);
        $this->assertSame($landlord->id, $log->user_id);
        $this->assertSame(502, $log->context['status']);
        $this->assertSame('standalone', $log->context['client']['display_mode']);
        $this->assertSame('1.15', $log->context['client']['zoom']);
    }

    public function test_secret_looking_context_keys_are_not_stored(): void
    {
        $this->actingAs($this->makeLandlord())
            ->postJson(route('client-errors.store'), [
                'kind' => 'error',
                'message' => 'TypeError: x is undefined',
                'client' => ['csrf_token' => 'abc123', 'viewport' => '390x664'],
            ])
            ->assertNoContent();

        $client = ErrorLog::sole()->context['client'];
        $this->assertSame('[hidden]', $client['csrf_token']);
        $this->assertSame('390x664', $client['viewport']);
    }

    /** An anonymous endpoint that writes rows would be a free spam sink. */
    public function test_a_guest_cannot_write_to_the_error_log(): void
    {
        $this->postJson(route('client-errors.store'), [
            'kind' => 'error',
            'message' => 'spam',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('error_logs', 0);
    }

    public function test_an_unknown_report_kind_is_rejected(): void
    {
        $this->actingAs($this->makeLandlord())
            ->postJson(route('client-errors.store'), [
                'kind' => 'anything',
                'message' => 'x',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('error_logs', ['type' => ErrorLogType::Client->value]);
    }

    public function test_the_landlord_panel_installs_the_reporter_for_signed_in_users(): void
    {
        $landlord = $this->makeLandlord();
        $property = Property::create(['landlord_id' => $landlord->id, 'name' => 'Reporter Property']);

        $this->actingAs($landlord);
        ActiveProperty::set($property->id);

        $this->get('/app/simple')
            ->assertSuccessful()
            ->assertSee('window.rwClientErrors', false)
            ->assertSee('client-errors', false);
    }

    private function makeLandlord(): User
    {
        $landlord = User::factory()->create([
            'name' => 'Reporter Landlord',
            'email' => 'reporter-landlord-'.uniqid().'@example.com',
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

        return $landlord;
    }
}
