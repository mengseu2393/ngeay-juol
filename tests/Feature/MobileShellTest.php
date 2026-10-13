<?php

namespace Tests\Feature;

use App\Http\Middleware\IdleTimeout;
use App\Livewire\Mobile\PropertySwitcher;
use App\Support\ActiveProperty;
use Livewire\Livewire;

/**
 * The /m mobile shell: Simple Mode rebuilt as a standalone phone app, with no
 * Filament layout, CSS, components or render hooks. Behind a feature flag
 * (config/mobile.php) until it is ready — off means a 404, except for the
 * listed testers.
 */
class MobileShellTest extends MobileTestCase
{
    // ── Feature flag ─────────────────────────────────────────────────────────

    public function test_m_does_not_exist_while_the_flag_is_off(): void
    {
        config(['mobile.enabled' => false]);
        $landlord = $this->landlord();

        $this->signIn($landlord, $this->property($landlord))->get('/m')->assertNotFound();
    }

    public function test_a_listed_tester_can_open_m_while_the_flag_is_off(): void
    {
        config(['mobile.enabled' => false, 'mobile.testers' => ['tester@example.com']]);
        $tester = $this->landlord('Tester@Example.com');

        $this->signIn($tester, $this->property($tester))->get('/m')->assertOk();
    }

    public function test_m_is_for_simple_mode_users_only(): void
    {
        $tenant = $this->landlord();
        $tenant->syncRoles(['tenant']);

        $this->signIn($tenant)->get('/m')->assertNotFound();
    }

    public function test_a_guest_is_sent_to_sign_in_and_brought_back(): void
    {
        $this->get('/m')->assertRedirect(route('login'));

        $this->assertSame(url('/m'), session('url.intended'));
    }

    // ── The shell ────────────────────────────────────────────────────────────

    public function test_the_shell_is_a_standalone_app_page_without_filament(): void
    {
        $landlord = $this->landlord();

        $html = $this->signIn($landlord, $this->property($landlord, 'Riverside'))
            ->get('/m')
            ->assertOk()
            ->getContent();

        // Native-app chrome.
        $this->assertStringContainsString('viewport-fit=cover', $html);
        $this->assertStringContainsString('<link rel="manifest" href="/manifest.json">', $html);
        $this->assertStringContainsString('content="black-translucent"', $html);
        $this->assertStringContainsString('id="rw-m-tabbar"', $html);
        $this->assertStringContainsString('Riverside', $html); // property switcher in the top bar
        $this->assertMatchesRegularExpression('/<a[^>]*aria-current="page"[^>]*>|<a\s[^>]*data-rw-m-tab[^>]*aria-current="page"/s', $html);

        // Shared app components (not Filament): fonts, error reporter, failed-request toast.
        $this->assertStringContainsString("font-family: 'Kantumruy Pro'", $html);
        $this->assertStringContainsString('window.rwClientErrors', $html);
        $this->assertStringContainsString("Livewire.hook('request'", $html);

        // No Filament anywhere.
        $this->assertStringNotContainsString('/css/filament/', $html);
        $this->assertStringNotContainsString('/js/filament/', $html);
        $this->assertStringNotContainsString('rentwise-admin.css', $html);
        $this->assertStringNotContainsString('fi-layout', $html);
        $this->assertStringNotContainsString('fi-body', $html);
    }

    /**
     * Outside a panel Filament's "current panel" is the default one — admin — so
     * an IdleTimeout keyed on the current panel would sign phones out of /m after
     * 15 minutes. It goes by path now.
     */
    public function test_a_phone_is_not_signed_out_of_m_after_idle_time(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));
        session([IdleTimeout::SESSION_KEY => now()->subHours(2)->timestamp]);

        $this->withHeader('User-Agent', self::PHONE)->get('/m')->assertOk();
        $this->assertAuthenticated();
    }

    public function test_desktop_keeps_the_idle_logout_in_m(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));
        session([IdleTimeout::SESSION_KEY => now()->subHours(2)->timestamp]);

        $this->withHeader('User-Agent', self::DESKTOP)->get('/m')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** Sheets close like native ones: drag down from the handle (or the top of the content). */
    public function test_bottom_sheets_can_be_dragged_down_to_dismiss(): void
    {
        $landlord = $this->landlord();

        $this->signIn($landlord, $this->property($landlord))
            ->get('/m')
            ->assertOk()
            ->assertSee('x-on:touchstart="dragStart($event)"', false)
            ->assertSee('x-on:touchmove="dragMove($event)"', false)
            ->assertSee('x-on:touchend="dragEnd()"', false);
    }

    /** The installed app has no browser reload: every screen gets pull-to-refresh. */
    public function test_every_screen_has_a_pull_to_refresh_indicator(): void
    {
        $landlord = $this->landlord();

        $this->signIn($landlord, $this->property($landlord))
            ->get('/m')
            ->assertOk()
            ->assertSee('id="rw-m-ptr"', false);
    }

    /** No Filament panel to go back to: a switch reloads the current /m screen. */
    public function test_switching_property_reloads_the_screen_instead_of_opening_the_panel(): void
    {
        $landlord = $this->landlord();
        $first = $this->property($landlord, 'First');
        $second = $this->property($landlord, 'Second');
        $this->signIn($landlord, $first);

        Livewire::test(PropertySwitcher::class)
            ->set('propertyId', (string) $second->id)
            ->assertDispatched('rw-m-property-switched')
            ->assertNoRedirect();

        $this->assertSame($second->id, ActiveProperty::id());
    }

    /** The installed app has no address bar: Simple Mode's Settings links testers to /m. */
    public function test_simple_mode_settings_link_to_m_only_for_users_the_flag_lets_in(): void
    {
        config(['mobile.enabled' => false, 'mobile.testers' => ['tester@example.com']]);
        $tester = $this->landlord('tester@example.com');
        $other = $this->landlord();

        $this->signIn($tester, $this->property($tester))
            ->get('/app/simple?screen=settings')
            ->assertSee('id="simple-settings-mobile-shell"', false);

        $this->signIn($other, $this->property($other))
            ->get('/app/simple?screen=settings')
            ->assertDontSee('id="simple-settings-mobile-shell"', false);
    }
}
