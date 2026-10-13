<?php

namespace Tests\Feature;

use App\Livewire\Mobile\Settings;
use Livewire\Livewire;

/**
 * /m's Settings tab. Ported from Simple Mode's settings partial, whose links
 * went into the Filament panel (/app/...). On a phone the whole app is /m, so
 * every row here must stay inside /m — and there is no "switch to full mode".
 */
class MobileSettingsTest extends MobileTestCase
{
    public function test_it_renders_the_grouped_list_for_the_landlord(): void
    {
        $landlord = $this->landlord('settings-owner@example.com');
        $property = $this->property($landlord);

        $this->signIn($landlord, $property)
            ->get(route('mobile.settings'))
            ->assertOk()
            ->assertSee(__('Settings'))
            ->assertSee('Mobile Landlord')
            ->assertSee('settings-owner@example.com')
            ->assertSee(__('Utility Rates'))
            ->assertSee(__('Meter readings'))
            ->assertSee(__('Log out'));
    }

    public function test_every_row_links_to_a_mobile_screen(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $html = Livewire::test(Settings::class)->html();

        foreach ([
            'rw-m-settings-account' => 'mobile.profile',
            'rw-m-settings-property' => 'mobile.property-settings',
            'rw-m-settings-add-property' => 'mobile.add-property',
            'rw-m-settings-rooms' => 'mobile.rooms',
            'rw-m-settings-tenants' => 'mobile.tenants',
            'rw-m-settings-utility-rates' => 'mobile.utility-rates',
            'rw-m-settings-readings' => 'mobile.readings',
            'rw-m-settings-profile' => 'mobile.profile',
        ] as $id => $route) {
            $this->assertMatchesRegularExpression(
                '#href="'.preg_quote(route($route), '#').'"\s+wire:navigate\s+id="'.$id.'"#',
                $html,
                "{$id} should wire:navigate to {$route}",
            );
        }
    }

    /** Nothing on the phone settings screen may lead back into the Filament panel. */
    public function test_nothing_links_into_the_filament_panel_or_full_mode(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $html = Livewire::test(Settings::class)->html();

        $this->assertDoesNotMatchRegularExpression('#href="[^"]*/app(/|")#', $html);
        $this->assertStringNotContainsString('rw-sm-', $html);
        $this->assertStringNotContainsString(__('Try the new app (beta)'), $html);
        $this->assertStringNotContainsStringIgnoringCase('full mode', $html);
    }

    /** Sign out and the language switch change the session: real requests, not wire:navigate. */
    public function test_sign_out_posts_to_logout_with_csrf_and_language_links_are_full_loads(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $html = Livewire::test(Settings::class)->html();

        $this->assertMatchesRegularExpression('#<form method="POST" action="'.preg_quote(route('logout'), '#').'"[^>]*>\s*<input type="hidden" name="_token"#', $html);

        foreach (['en', 'km'] as $locale) {
            $this->assertMatchesRegularExpression(
                '#<a\s+href="'.preg_quote(route('locale.switch', $locale), '#').'"\s+id="rw-m-settings-locale-'.$locale.'"#',
                $html,
            );
        }

        $this->assertDoesNotMatchRegularExpression('#locale/(en|km)"\s+wire:navigate#', $html);
    }

    public function test_the_current_language_is_marked(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $this->withSession(['locale' => 'km'])
            ->get(route('mobile.settings'))
            ->assertOk()
            ->assertSeeInOrder(['id="rw-m-settings-locale-km"', 'aria-current="true"'], false);
    }

    public function test_switching_language_returns_to_settings_in_that_language(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $this->from(route('mobile.settings'))
            ->get(route('locale.switch', 'km'))
            ->assertRedirect(route('mobile.settings'))
            ->assertSessionHas('locale', 'km');
    }

    public function test_signing_out_ends_the_session(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->get(route('mobile.settings'))->assertRedirect();
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('mobile.settings'))->assertRedirect();
        $this->assertGuest();
    }
}
