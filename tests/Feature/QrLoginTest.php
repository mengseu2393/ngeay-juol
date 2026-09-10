<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Filament\Pages\QrCodeGenerator;
use App\Models\QrLoginToken;
use App\Models\User;
use App\Services\QrLoginTokenService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin "QR Code Login" page used to encode the landlord's *plaintext
 * password* into the QR payload
 * (/login?qr_login=<email>&qr_password=<password>), which leaked a reusable
 * credential into browser history, web-server access logs, Referer headers and
 * the printed QR image itself.
 *
 * It now encodes a single-use, 15-minute, signed token that is stored hashed.
 * Every assertion below guards one leg of that replacement — in particular the
 * deliberate UX change: a scanned code is dead, and a photographed one expires.
 */
class QrLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_qr_token_signs_the_landlord_in(): void
    {
        $landlord = $this->makeLandlord();

        $url = $this->issueFor($landlord)['url'];

        $this->get($url)->assertRedirect(route('filament.landlord.pages.dashboard'));

        $this->assertAuthenticatedAs($landlord);
    }

    /**
     * The whole point of the redesign: a QR code someone photographed over the
     * landlord's shoulder is worthless once the landlord has scanned it.
     */
    public function test_the_same_qr_token_fails_on_a_second_use(): void
    {
        $landlord = $this->makeLandlord();
        $url = $this->issueFor($landlord)['url'];

        $this->get($url);
        $this->assertAuthenticatedAs($landlord);

        $this->post(route('logout'));
        $this->assertGuest();

        $this->get($url)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_expired_qr_link_is_rejected(): void
    {
        $landlord = $this->makeLandlord();
        $url = $this->issueFor($landlord)['url'];

        // One minute past the constant, whatever the constant happens to be.
        $this->travel(QrLoginToken::LIFETIME_MINUTES + 1)->minutes();

        // The signature expires with the token, so this fails closed at the
        // middleware before any database work happens.
        $this->get($url)->assertForbidden();
        $this->assertGuest();
    }

    /**
     * Belt-and-braces: even with a still-valid signature, the service refuses a
     * token whose own expires_at has passed (e.g. clock skew, or a link signed
     * for longer by a future caller).
     */
    public function test_the_service_refuses_a_token_past_its_expiry(): void
    {
        $landlord = $this->makeLandlord();

        Carbon::setTestNow('2026-09-09 10:00:00');
        $issued = app(QrLoginTokenService::class)->issue($landlord);
        Carbon::setTestNow('2026-09-09 10:16:00');

        $raw = $this->rawTokenFrom($issued['url']);
        $this->assertNull(app(QrLoginTokenService::class)->redeem($raw));

        Carbon::setTestNow();
    }

    public function test_an_inactive_users_qr_token_is_rejected(): void
    {
        $landlord = $this->makeLandlord();
        $url = $this->issueFor($landlord)['url'];

        $landlord->forceFill(['status' => UserStatus::Inactive->value])->save();

        $this->get($url)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        $this->assertNull(app(QrLoginTokenService::class)->redeem('this-token-never-existed'));
    }

    public function test_a_tampered_token_never_reaches_the_database(): void
    {
        $landlord = $this->makeLandlord();
        $issued = $this->issueFor($landlord);

        $raw = $this->rawTokenFrom($issued['url']);
        $tampered = str_replace($raw, str_repeat('a', strlen($raw)), $issued['url']);

        $this->get($tampered)->assertForbidden();
        $this->assertGuest();
    }

    /**
     * The vulnerability this feature replaced: nothing credential-shaped may
     * appear in the QR payload, and the raw token must never be persisted.
     */
    public function test_the_qr_url_carries_no_credential_and_the_token_is_stored_hashed(): void
    {
        $landlord = $this->makeLandlord();
        $issued = $this->issueFor($landlord);

        $this->assertStringNotContainsString('qr_password', $issued['url']);
        $this->assertStringNotContainsString('qr_login', $issued['url']);
        $this->assertStringNotContainsString($landlord->email, $issued['url']);
        $this->assertStringNotContainsString('secret-landlord-password', $issued['url']);

        $raw = $this->rawTokenFrom($issued['url']);
        $this->assertDatabaseMissing('qr_login_tokens', ['token_hash' => $raw]);
        $this->assertDatabaseHas('qr_login_tokens', [
            'user_id' => $landlord->id,
            'token_hash' => hash('sha256', $raw),
            'used_at' => null,
        ]);
    }

    public function test_redemption_records_who_generated_the_link_and_when_it_was_burned(): void
    {
        $landlord = $this->makeLandlord();
        $admin = $this->makeSuperAdmin();

        $issued = app(QrLoginTokenService::class)->issue($landlord, $admin);

        $this->assertSame($admin->id, $issued['token']->created_by_id);

        $this->get($issued['url']);

        $this->assertNotNull($issued['token']->fresh()->used_at);
    }

    /**
     * The generator page must no longer ask for — or need — the landlord's
     * password. Regression guard against the old form field creeping back.
     */
    public function test_the_generator_page_mints_a_link_without_asking_for_a_password(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $admin = $this->makeSuperAdmin();
        $landlord = $this->makeLandlord();

        $this->actingAs($admin);

        Livewire::test(QrCodeGenerator::class)
            ->assertFormFieldExists('landlord_id')
            ->assertFormFieldDoesNotExist('password')
            ->fillForm(['landlord_id' => $landlord->id])
            ->call('generate')
            ->assertHasNoFormErrors()
            ->assertSet('landlordName', $landlord->name);

        $this->assertDatabaseCount('qr_login_tokens', 1);
        $this->assertDatabaseHas('qr_login_tokens', [
            'user_id' => $landlord->id,
            'created_by_id' => $admin->id,
        ]);
    }

    private function makeLandlord(): User
    {
        $landlord = User::factory()->create([
            'email' => 'landlord@example.com',
            'password' => bcrypt('secret-landlord-password'),
            'status' => UserStatus::Active->value,
        ]);
        $landlord->assignRole('landlord');

        return $landlord;
    }

    private function makeSuperAdmin(): User
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'status' => UserStatus::Active->value,
        ]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    /** @return array{token: QrLoginToken, url: string} */
    private function issueFor(User $user): array
    {
        return app(QrLoginTokenService::class)->issue($user);
    }

    private function rawTokenFrom(string $url): string
    {
        $segments = explode('/', parse_url($url, PHP_URL_PATH) ?: '');

        return end($segments);
    }
}
