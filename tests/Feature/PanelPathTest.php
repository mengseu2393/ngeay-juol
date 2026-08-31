<?php

namespace Tests\Feature;

use App\Providers\Filament\LandlordPanelProvider;
use Tests\TestCase;

/**
 * The landlord panel lives at /app (a neutral prefix — the same panel serves
 * landlord_manager staff and platform staff drilling in). Its panel *id* stays
 * 'landlord', so every filament.landlord.* route name is unchanged.
 */
class PanelPathTest extends TestCase
{
    public function test_panel_is_served_from_the_app_prefix(): void
    {
        $this->assertSame('app', LandlordPanelProvider::PATH);
        $this->assertStringStartsWith(
            '/app',
            parse_url(route('filament.landlord.pages.dashboard'), PHP_URL_PATH),
        );
    }

    public function test_legacy_landlord_root_redirects_permanently(): void
    {
        $this->get('/landlord')
            ->assertStatus(301)
            ->assertRedirect('/app');
    }

    public function test_legacy_landlord_subpath_is_preserved(): void
    {
        $this->get('/landlord/invoices')
            ->assertStatus(301)
            ->assertRedirect('/app/invoices');
    }

    public function test_legacy_redirect_keeps_the_query_string(): void
    {
        $this->get('/landlord/simple?screen=invoices')
            ->assertStatus(301)
            ->assertRedirect('/app/simple?screen=invoices');
    }

    /** The catch-all is registered last, so it must never shadow a real /app route. */
    public function test_legacy_redirect_does_not_shadow_document_routes(): void
    {
        $this->get('/landlord/invoices/9/pdf')
            ->assertStatus(301)
            ->assertRedirect('/app/invoices/9/pdf');
    }
}
