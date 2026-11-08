<?php

namespace Tests\Feature;

/**
 * /m2 as an installable app (PWA). The layout links the manifest and registers the
 * service worker itself: a screen's own scripts don't run when wire:navigate swaps it in,
 * and beforeinstallprompt fires once at load, so Settings' "Install app" row can only
 * use the event the layout kept.
 */
class M2PwaTest extends M2TestCase
{
    public function test_every_m2_screen_links_the_manifest_and_registers_the_service_worker(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        foreach (['m2.home', 'm2.settings'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('<link rel="manifest" href="/manifest.json">', false)
                ->assertSee("navigator.serviceWorker.register('/sw.js')", false)
                ->assertSee("addEventListener('beforeinstallprompt'", false)
                ->assertSee("type: 'm2-warm'", false);
        }
    }

    /**
     * On the /m2 deploy the installed app opens /m2 with /m2's shortcuts, but keeps the
     * manifest id "/login": changing it would make phones treat it as a second app.
     */
    public function test_the_manifest_opens_m2_on_the_m2_deploy_and_keeps_its_identity(): void
    {
        config(['m2.default' => true]);

        $manifest = $this->get('/manifest.json')
            ->assertOk()
            ->assertJsonPath('id', '/login')
            ->assertJsonPath('start_url', '/m2')
            ->assertJsonPath('scope', '/')
            ->assertJsonPath('theme_color', '#0b7a55')
            ->json();

        $this->assertSame(['/m2', '/m2/dashboard', '/m2/settings'], array_column($manifest['shortcuts'], 'url'));
    }

    public function test_settings_offers_install_app(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        $this->get(route('m2.settings'))
            ->assertOk()
            ->assertSee('id="m2-settings-install"', false)
            ->assertSee('id="m2-settings-install-ios"', false)
            ->assertSee('window.m2InstallPrompt', false);
    }

    /**
     * Pages are never cached (a bill or slip must not come back stale offline); only versioned
     * files are. Livewire's script is versioned by ?id=, so it is cached ahead of the
     * network-only '/livewire' rule. Navigation preload must answer network-only pages too,
     * or the browser requests them twice.
     */
    public function test_the_service_worker_caches_files_not_pages(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString('navigationPreload', $sw);
        $this->assertStringContainsString('function isLivewireScript', $sw);
        $this->assertLessThan(
            strpos($sw, 'if (isNetworkOnly(url))'),
            strpos($sw, 'if (isLivewireScript(url))'),
        );
        $this->assertStringContainsString("data.type !== 'm2-warm'", $sw);

        $networkFirst = substr($sw, strpos($sw, 'async function networkFirstWithOfflineFallback'));
        $networkFirst = substr($networkFirst, 0, strpos($networkFirst, "\n}\n"));
        $this->assertStringNotContainsString('cache.put', $networkFirst);
    }
}
