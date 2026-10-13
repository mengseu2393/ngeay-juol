<?php

namespace Tests\Feature;

use App\Support\DeviceSession;

/**
 * Moving phones from Simple Mode (inside the Filament panel) to /m. All of it
 * follows the feature flag: a phone or the installed app of a user the flag lets
 * in signs in to /m and is sent from any landlord-panel page to the matching /m
 * screen; everyone else is untouched. Only the subscription billing page and
 * the onboarding wizard stay in the panel (/m has neither yet).
 */
class MobileCutOverTest extends MobileTestCase
{
    public function test_a_tester_signing_in_on_a_phone_lands_in_m(): void
    {
        config(['mobile.enabled' => false, 'mobile.testers' => ['tester@example.com']]);
        $tester = $this->landlord('tester@example.com');
        $this->property($tester);

        $this->withHeader('User-Agent', self::PHONE)
            ->post(route('login'), ['login' => 'tester@example.com', 'password' => 'password'])
            ->assertRedirect(route('mobile.invoices'));
    }

    public function test_everyone_else_keeps_simple_mode_while_the_flag_is_off(): void
    {
        config(['mobile.enabled' => false, 'mobile.testers' => []]);
        $landlord = $this->landlord();
        $this->property($landlord);

        $response = $this->withHeader('User-Agent', self::PHONE)
            ->post(route('login'), ['login' => $landlord->email, 'password' => 'password']);

        $this->assertStringNotContainsString('/m', (string) parse_url($response->headers->get('Location'), PHP_URL_PATH));
    }

    public function test_desktop_never_goes_to_m(): void
    {
        $landlord = $this->landlord();
        $this->property($landlord);

        $response = $this->withHeader('User-Agent', self::DESKTOP)
            ->post(route('login'), ['login' => $landlord->email, 'password' => 'password']);

        $this->assertStringStartsWith('/app', (string) parse_url($response->headers->get('Location'), PHP_URL_PATH));

        $this->withHeader('User-Agent', self::DESKTOP)->get('/app/units')->assertOk();
    }

    /** Old Simple Mode deep links and the full-mode pages phones used to visit. */
    public function test_a_phone_is_sent_from_panel_pages_to_the_matching_m_screen(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $unit = $rental->unit_id;
        $this->signIn($landlord, $property);

        $expected = [
            '/app' => route('mobile.invoices'),
            '/app/simple' => route('mobile.invoices'),
            '/app/simple?screen=rooms' => route('mobile.rooms'),
            '/app/simple?screen=tenants' => route('mobile.tenants'),
            '/app/simple?screen=settings' => route('mobile.settings'),
            '/app/simple?screen=billing-invoice' => route('mobile.billing'),
            '/app/simple?screen=utility' => route('mobile.readings'),
            '/app/simple?screen=utility-rates' => route('mobile.utility-rates'),
            '/app/simple?screen=profile' => route('mobile.profile'),
            "/app/simple?screen=unit&unit_id={$unit}" => route('mobile.room', $unit),
            "/app/simple?screen=add-tenant&unit_id={$unit}" => route('mobile.room.add-tenant', $unit),
            "/app/simple?screen=end-tenancy&unit_id={$unit}" => route('mobile.room.end-tenancy', $unit),
            "/app/unit-overview/{$unit}" => route('mobile.room', $unit),
            "/app/units/{$unit}/edit" => route('mobile.room.edit', $unit),
            "/app/rentals/{$rental->id}" => route('mobile.tenant', $rental->id),
            '/app/property-settings' => route('mobile.property-settings'),
            '/app/properties' => route('mobile.property-settings'),
            '/app/add-property' => route('mobile.add-property'),
            '/app/property-utilities' => route('mobile.utility-rates'),
            '/app/monthly-billing' => route('mobile.billing'),
            '/app/invoices' => route('mobile.invoices'),
        ];

        foreach ($expected as $from => $to) {
            $this->withHeader('User-Agent', self::PHONE)->get($from)->assertRedirect($to);
        }
    }

    public function test_the_subscription_page_stays_in_the_panel(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $location = $this->withHeader('User-Agent', self::PHONE)->get('/app/billing')->headers->get('Location');

        $this->assertStringNotContainsString('/m', (string) $location);
    }

    /** iPadOS reports a desktop user agent: the installed app is recognised by its cookie. */
    public function test_the_installed_app_on_a_tablet_is_sent_to_m_too(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $this->withHeader('User-Agent', self::DESKTOP)
            ->withUnencryptedCookie(DeviceSession::DISPLAY_MODE_COOKIE, 'standalone')
            ->get('/app/simple?screen=rooms')
            ->assertRedirect(route('mobile.rooms'));
    }

    public function test_the_panels_installed_app_lock_points_at_m_when_available(): void
    {
        config(['mobile.enabled' => false, 'mobile.testers' => ['tester@example.com']]);
        $tester = $this->landlord('tester@example.com');
        $other = $this->landlord();

        $this->signIn($tester, $this->property($tester))
            ->withHeader('User-Agent', self::DESKTOP)
            ->get('/app/simple')
            ->assertSee('location.replace("'.route('mobile.invoices').'")', false);

        $this->signIn($other, $this->property($other))
            ->withHeader('User-Agent', self::DESKTOP)
            ->get('/app/simple')
            ->assertDontSee('location.replace("'.route('mobile.invoices').'")', false);
    }

    // ── Manifest & service worker ────────────────────────────────────────────

    public function test_the_manifest_starts_in_m_once_enabled_and_keeps_its_identity(): void
    {
        config(['mobile.enabled' => false]);
        $this->get('/manifest.json')
            ->assertOk()
            ->assertJsonPath('id', '/login')
            ->assertJsonPath('start_url', '/login');

        config(['mobile.enabled' => true]);
        $manifest = $this->get('/manifest.json')
            ->assertOk()
            ->assertJsonPath('id', '/login')
            ->assertJsonPath('start_url', '/m')
            ->json();

        foreach ($manifest['shortcuts'] as $shortcut) {
            $this->assertStringStartsWith('/m', $shortcut['url']);
        }

        // The old manifest offered a square icon as a "wide" screenshot (invalid) — gone.
        $this->assertArrayNotHasKey('screenshots', $manifest);
    }

    /** The service worker used to cache the manifest cache-first — it could never change again. */
    public function test_the_service_worker_leaves_the_manifest_to_the_network(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        $this->assertStringNotContainsString("'/manifest.json'", $sw);
        $this->assertStringContainsString('evictOtherVersions', $sw);
    }

    // ── Android app (Trusted Web Activity) ───────────────────────────────────

    public function test_asset_links_are_empty_until_the_android_app_is_configured(): void
    {
        config(['mobile.twa' => ['package' => null, 'fingerprints' => []]]);

        $this->get('/.well-known/assetlinks.json')->assertOk()->assertExactJson([]);
    }

    public function test_asset_links_publish_the_configured_android_app(): void
    {
        config(['mobile.twa' => ['package' => 'app.ngeayjuol.twa', 'fingerprints' => ['AA:BB', 'CC:DD']]]);

        $this->get('/.well-known/assetlinks.json')
            ->assertOk()
            ->assertExactJson([[
                'relation' => ['delegate_permission/common.handle_all_urls'],
                'target' => [
                    'namespace' => 'android_app',
                    'package_name' => 'app.ngeayjuol.twa',
                    'sha256_cert_fingerprints' => ['AA:BB', 'CC:DD'],
                ],
            ]]);
    }
}
