<?php

namespace Tests\Feature;

use App\Enums\ErrorLogType;
use App\Filament\Resources\ErrorLogResource;
use App\Filament\Resources\ErrorLogResource\Pages\ListErrorLogs;
use App\Filament\Resources\LandlordResource\Pages\CreateLandlord;
use App\Models\ErrorLog;
use App\Models\User;
use App\Services\ErrorLogService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * /admin → Error Log exists because a landlord's registration on production kept
 * bouncing back to /register with no visible message (a validation error on a
 * field the page doesn't render) and nobody could see why without SSH. Every
 * failure a user can hit must land here — and passwords must not.
 */
class ErrorLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_a_server_error_is_recorded_with_its_trace_and_request(): void
    {
        Route::get('/_test/boom', fn () => throw new \RuntimeException('Kaboom'))->middleware('web');

        $this->get('/_test/boom')->assertStatus(500);

        $log = ErrorLog::sole();
        $this->assertSame(ErrorLogType::Exception, $log->type);
        $this->assertSame('Kaboom', $log->message);
        $this->assertSame(\RuntimeException::class, $log->exception_class);
        $this->assertNotEmpty($log->trace);
        $this->assertSame('GET', $log->method);
        $this->assertStringEndsWith('/_test/boom', $log->url);
    }

    public function test_a_rejected_registration_is_recorded_without_the_password(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);

        $this->post(route('register'), [
            'name' => 'Sok Dara',
            'phone_number' => '012345678',
            'password' => 'my-secret',
            'password_confirmation' => 'my-secret',
            'website' => 'autofilled',
        ])->assertSessionHasErrors('website');

        $log = ErrorLog::sole();
        $this->assertSame(ErrorLogType::Validation, $log->type);
        $this->assertArrayHasKey('website', $log->context['errors']);
        $this->assertSame('012345678', $log->context['input']['phone_number']);
        $this->assertSame('[hidden]', $log->context['input']['password']);
        $this->assertStringNotContainsString('my-secret', json_encode($log->context));
    }

    public function test_an_expired_csrf_token_is_recorded_as_blocked(): void
    {
        // The CSRF middleware is a no-op in unit tests unless re-enabled this way.
        Route::post('/_test/form', fn () => 'ok')->middleware('web');
        $this->app->instance('env', 'production');

        $this->post('/_test/form')->assertStatus(419);

        $this->assertSame(ErrorLogType::Blocked, ErrorLog::sole()->type);
    }

    /** Filament forms validate inside Livewire and never reach the exception handler. */
    public function test_a_rejected_panel_form_is_recorded(): void
    {
        $this->actingAs($this->createStaff('super_admin'));

        Livewire::test(CreateLandlord::class)
            ->call('create')
            ->assertHasFormErrors();

        $log = ErrorLog::sole();
        $this->assertSame(ErrorLogType::Validation, $log->type);
        $this->assertSame(CreateLandlord::class, $log->route_name);
        $this->assertNotEmpty($log->context['errors']);
    }

    public function test_an_error_shown_only_as_a_notification_is_recorded(): void
    {
        ErrorLogService::recordHandled(new \DomainException('Unit is already occupied.'), 'Move-in could not be completed');

        $log = ErrorLog::sole();
        $this->assertSame(ErrorLogType::ActionFailed, $log->type);
        $this->assertSame('Move-in could not be completed: Unit is already occupied.', $log->message);
    }

    public function test_only_super_admins_can_see_the_log(): void
    {
        $this->actingAs($this->createStaff('support'));
        $this->assertFalse(ErrorLogResource::canViewAny());

        $admin = $this->createStaff('super_admin');
        $this->actingAs($admin);
        $this->assertTrue(ErrorLogResource::canViewAny());

        $log = ErrorLog::create(['type' => ErrorLogType::Exception, 'message' => 'Kaboom']);

        Livewire::test(ListErrorLogs::class)
            ->assertCanSeeTableRecords([$log])
            ->callTableAction('resolve', $log)
            ->assertHasNoTableActionErrors();

        $this->assertNotNull($log->fresh()->resolved_at);
    }

    public function test_entries_older_than_30_days_are_pruned(): void
    {
        $old = ErrorLog::create(['type' => ErrorLogType::Exception, 'message' => 'old']);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();
        $recent = ErrorLog::create(['type' => ErrorLogType::Exception, 'message' => 'recent']);

        $this->artisan('model:prune', ['--model' => [ErrorLog::class]])->assertSuccessful();

        $this->assertDatabaseMissing('error_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('error_logs', ['id' => $recent->id]);
    }

    protected function createStaff(string $role): User
    {
        $user = User::create([
            'name' => 'Staff User',
            'email' => $role.'@example.com',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole($role);

        return $user;
    }
}
