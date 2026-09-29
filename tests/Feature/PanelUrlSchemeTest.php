<?php

namespace Tests\Feature;

use Filament\Facades\Filament;
use Tests\TestCase;

/**
 * Regression (production, behind a TLS proxy): the panels captured asset()/url() while the app
 * was still booting — before the request was known to be HTTPS — so the favicon was requested
 * as http:// (blocked as mixed content) and the SPA URL exceptions (/logout, /login, PDF
 * downloads, ...) were http:// patterns that never matched the real https:// links. They are
 * lazy now, so they must follow the scheme of the request being served.
 */
class PanelUrlSchemeTest extends TestCase
{
    public function test_panel_favicon_and_spa_exceptions_follow_the_request_scheme(): void
    {
        $this->call('GET', 'https://localhost/login');

        foreach (['landlord', 'admin'] as $id) {
            $panel = Filament::getPanel($id);

            $this->assertStringStartsWith('https://', $panel->getFavicon(), "$id favicon");

            $absolute = array_filter($panel->getSpaUrlExceptions(), fn (string $url) => str_starts_with($url, 'http'));
            $this->assertNotEmpty($absolute);
            foreach ($absolute as $url) {
                $this->assertStringStartsWith('https://', $url, "$id SPA exception $url");
            }
        }
    }
}
