<?php

namespace Tests\Feature;

use App\Enums\FirstMonthBillingMode;
use App\Filament\Pages\PropertySettings;
use App\Models\MoveInRule;
use App\Models\PropertySetting;
use App\Services\ExchangeRateService;
use App\Services\PropertySettingsService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Mockery;

/**
 * PropertySettingsService is the write behind both the panel's Property
 * Settings page and the /m one. Moving it out of the Filament page must not
 * change what the page saves — the last test drives the page itself.
 */
class PropertySettingsServiceTest extends MobileTestCase
{
    public function test_settings_are_created_once_with_the_defaults(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->actingAs($landlord);

        $setting = app(PropertySettingsService::class)->forProperty($property->id);
        app(PropertySettingsService::class)->forProperty($property->id);

        $this->assertSame(1, PropertySetting::where('property_id', $property->id)->count());
        $this->assertSame(7, $setting->due_day_of_month);
        $this->assertSame(FirstMonthBillingMode::FullMonth, $setting->fresh()->first_month_billing_mode);
        $this->assertFalse($setting->fresh()->monthly_billing_enabled);
    }

    /** A new preset rebuilds the structured move-in rules; saving it again changes nothing. */
    public function test_a_new_move_in_preset_rebuilds_the_rules(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->actingAs($landlord);
        $service = app(PropertySettingsService::class);
        $setting = $service->forProperty($property->id);

        $service->save($setting, ['move_in_preset' => 'first_deposit', 'invoice_prefix' => 'RIV']);

        $this->assertSame('RIV', $setting->fresh()->invoice_prefix);
        $active = MoveInRule::where('property_id', $property->id)->where('is_active', true)->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['First-period rent', 'Security deposit'], $active);

        $service->save($setting->fresh(), ['move_in_preset' => 'custom']);
        $this->assertSame(2, MoveInRule::where('property_id', $property->id)->where('is_active', true)->count());
    }

    public function test_fetching_the_exchange_rate_saves_it(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->actingAs($landlord);

        $this->app->instance(ExchangeRateService::class, Mockery::mock(ExchangeRateService::class, function ($mock) {
            $mock->shouldReceive('fetchUsdToKhr')->once()->andReturn(['rate' => 4012.5, 'date' => '2026-10-13', 'source' => 'NBC']);
        }));

        $setting = app(PropertySettingsService::class)->forProperty($property->id);
        $payload = app(PropertySettingsService::class)->fetchExchangeRate($setting);

        $this->assertSame(4012.5, $payload['rate']);
        $this->assertEquals(4012.5, $setting->fresh()->usd_khr_exchange_rate);
        $this->assertSame('NBC', $setting->fresh()->exchange_rate_source);
        $this->assertSame('2026-10-13', $setting->fresh()->exchange_rate_date->toDateString());
    }

    public function test_the_panel_page_still_saves_through_it(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('landlord'));

        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->signIn($landlord, $property);

        Livewire::test(PropertySettings::class)
            ->set('data.invoice_prefix', 'DESK')
            ->set('data.due_day_of_month', 10)
            ->set('data.move_in_preset', 'first_last')
            ->call('save')
            ->assertHasNoErrors();

        $setting = PropertySetting::where('property_id', $property->id)->firstOrFail();
        $this->assertSame('DESK', $setting->invoice_prefix);
        $this->assertSame(10, $setting->due_day_of_month);
        $this->assertTrue(MoveInRule::where('property_id', $property->id)->where('name', 'Last-month rent credit')->where('is_active', true)->exists());
    }
}
