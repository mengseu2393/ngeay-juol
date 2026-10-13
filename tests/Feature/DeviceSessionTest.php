<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\DeviceSession;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Session policy by device (decision for the mobile app): a landlord's phone or
 * the installed app stays signed in like a native app — "remember me" is ticked
 * by default and lasts ~30 days — while desktop keeps the framework default and
 * the 15-minute idle logout (IdleTimeoutTest).
 */
class DeviceSessionTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    private const DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_phone_login_is_remembered_for_about_thirty_days(): void
    {
        $response = $this->withHeader('User-Agent', self::PHONE)->login();

        $expiresInDays = ($this->rememberCookie($response)->getExpiresTime() - now()->timestamp) / 86400;
        $this->assertEqualsWithDelta(30, $expiresInDays, 0.1);
    }

    public function test_the_installed_app_login_is_remembered_for_about_thirty_days(): void
    {
        $response = $this->withHeader('User-Agent', self::DESKTOP)
            ->withUnencryptedCookie(DeviceSession::DISPLAY_MODE_COOKIE, 'standalone')
            ->login();

        $expiresInDays = ($this->rememberCookie($response)->getExpiresTime() - now()->timestamp) / 86400;
        $this->assertEqualsWithDelta(30, $expiresInDays, 0.1);
    }

    public function test_a_desktop_login_keeps_the_default_remember_duration(): void
    {
        $response = $this->withHeader('User-Agent', self::DESKTOP)->login();

        $expiresInDays = ($this->rememberCookie($response)->getExpiresTime() - now()->timestamp) / 86400;
        $this->assertGreaterThan(300, $expiresInDays);
    }

    public function test_the_login_page_ticks_remember_me_on_a_phone_only(): void
    {
        $this->assertStringContainsString('checked', $this->rememberInput(self::PHONE));
        $this->assertStringNotContainsString('checked', $this->rememberInput(self::DESKTOP));
    }

    private function rememberInput(string $userAgent): string
    {
        $html = $this->withHeader('User-Agent', $userAgent)->get(route('login'))->getContent();
        $this->assertSame(1, preg_match('/<input[^>]*id="login-remember"[^>]*>/', $html, $match));

        return $match[0];
    }

    /** JavaScript writes the display-mode cookie, so it must reach the server unencrypted. */
    public function test_the_display_mode_cookie_is_read_without_encryption(): void
    {
        $this->withUnencryptedCookie(DeviceSession::DISPLAY_MODE_COOKIE, 'standalone')->get(route('login'));

        $this->assertTrue(DeviceSession::isInstalledApp(request()));
    }

    private function login()
    {
        $user = User::factory()->create([
            'email' => 'device-session-'.uniqid().'@example.com',
            'status' => UserStatus::Active->value,
        ]);
        $user->assignRole('landlord');

        return $this->post(route('login'), [
            'login' => $user->email,
            'password' => 'password',
            'remember' => 'on',
        ]);
    }

    private function rememberCookie($response)
    {
        $cookie = $response->getCookie(Auth::guard()->getRecallerName(), decrypt: false);
        $this->assertNotNull($cookie, 'remember-me cookie was not set');

        return $cookie;
    }
}
