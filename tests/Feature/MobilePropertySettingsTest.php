<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\FirstMonthBillingMode;
use App\Enums\PropertyType;
use App\Livewire\Mobile\PropertySettings;
use App\Models\MoveInRule;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use App\Services\ExchangeRateService;
use App\Support\ActiveProperty;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * /m/settings/property — the active property's details, billing, move-in rules,
 * contacts and opening readings on a phone. Each section saves on its own
 * through the services the panel's Property Settings page uses.
 */
class MobilePropertySettingsTest extends MobileTestCase
{
    public function test_it_shows_the_active_propertys_sections_without_any_filament(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord, 'Sunrise Flats');

        $this->signIn($landlord, $property)
            ->get(route('mobile.property-settings'))
            ->assertOk()
            ->assertSee('Sunrise Flats')
            ->assertSee(__('Billing'))
            ->assertSee(__('Move-in rules'))
            ->assertSee(__('Contacts & property info'))
            ->assertSee(route('mobile.rooms'), false)
            ->assertSee(route('mobile.settings'), false)
            ->assertDontSee('fi-', false)
            ->assertDontSee('/app/', false);
    }

    public function test_without_an_active_property_it_asks_for_one(): void
    {
        $landlord = $this->landlord();
        $this->property($landlord, 'One');
        $this->property($landlord, 'Two');
        $this->actingAs($landlord);
        ActiveProperty::clear(); // "All properties"

        Livewire::test(PropertySettings::class)
            ->assertSee(__('Choose a property first'))
            ->assertSet('propertyId', null);
    }

    public function test_property_details_are_saved_with_the_address(): void
    {
        [$landlord, $property] = $this->subject();

        Livewire::test(PropertySettings::class)
            ->set('details.name', '  Riverside  ')
            ->set('details.property_type', PropertyType::RoomRental->value)
            ->set('details.address_line', '#12, St. 271')
            ->set('details.city', 'Phnom Penh')
            ->call('saveDetails')
            ->assertHasNoErrors()
            ->assertDispatched('rw-m-sheet-close', name: 'prop-details')
            ->assertDispatched('rw-m-toast');

        $property->refresh();
        $this->assertSame('Riverside', $property->name);
        $this->assertSame(PropertyType::RoomRental, $property->property_type);
        $this->assertSame('#12, St. 271', $property->address_line);
        $this->assertSame('Phnom Penh', $property->city);
    }

    public function test_changing_the_province_clears_the_lower_address_levels(): void
    {
        [$landlord, $property] = $this->subject();
        $property->update(['city' => 'Old', 'district' => 'D', 'commune' => 'C', 'village' => 'V']);

        Livewire::test(PropertySettings::class)
            ->assertSet('details.district', 'D')
            ->set('details.city', 'Kandal')
            ->assertSet('details.district', '')
            ->assertSet('details.commune', '')
            ->assertSet('details.village', '');
    }

    public function test_a_name_is_required(): void
    {
        [$landlord, $property] = $this->subject();

        Livewire::test(PropertySettings::class)
            ->set('details.name', '')
            ->call('saveDetails')
            ->assertHasErrors('details.name')
            ->assertNotDispatched('rw-m-sheet-close');

        $this->assertSame('Mobile Property', $property->fresh()->name);
    }

    public function test_billing_settings_are_saved(): void
    {
        [$landlord, $property] = $this->subject();

        Livewire::test(PropertySettings::class)
            ->set('billing.currency', 'KHR')
            ->set('billing.invoice_prefix', 'RIV')
            ->set('billing.due_day_of_month', '5')
            ->set('billing.invoice_due_days', '10')
            ->set('billing.late_fee', '')
            ->set('billing.usd_khr_exchange_rate', '4100')
            ->set('billing.exchange_rate_date', '2026-10-01')
            ->call('saveBilling')
            ->assertHasNoErrors()
            ->assertDispatched('rw-m-sheet-close', name: 'prop-billing');

        $setting = PropertySetting::where('property_id', $property->id)->firstOrFail();
        $this->assertSame('KHR', $setting->currency);
        $this->assertSame('RIV', $setting->invoice_prefix);
        $this->assertSame(5, $setting->due_day_of_month);
        $this->assertSame(10, $setting->invoice_due_days);
        $this->assertEquals(0, $setting->late_fee);
        $this->assertEquals(4100, $setting->usd_khr_exchange_rate);
        $this->assertSame('2026-10-01', $setting->exchange_rate_date->toDateString());
    }

    public function test_billing_validation(): void
    {
        $this->subject();

        Livewire::test(PropertySettings::class)
            ->set('billing.currency', 'EUR')
            ->set('billing.due_day_of_month', '31')
            ->set('billing.invoice_due_days', '0')
            ->set('billing.usd_khr_exchange_rate', '0')
            ->call('saveBilling')
            ->assertHasErrors(['billing.currency', 'billing.due_day_of_month', 'billing.invoice_due_days', 'billing.usd_khr_exchange_rate']);
    }

    public function test_monthly_billing_switches_on_the_card(): void
    {
        [$landlord, $property] = $this->subject();

        Livewire::test(PropertySettings::class)
            ->call('setMonthlyBilling', true)
            ->assertReturned(true)
            ->assertDispatched('rw-m-toast', message: __('Monthly billing turned on'));

        $this->assertTrue(PropertySetting::where('property_id', $property->id)->value('monthly_billing_enabled'));
    }

    public function test_todays_exchange_rate_is_fetched_and_saved(): void
    {
        [$landlord, $property] = $this->subject();
        $this->app->instance(ExchangeRateService::class, Mockery::mock(ExchangeRateService::class, function ($mock) {
            $mock->shouldReceive('fetchUsdToKhr')->once()->andReturn(['rate' => 4050.25, 'date' => '2026-10-13', 'source' => 'NBC']);
        }));

        Livewire::test(PropertySettings::class)
            ->set('billing.invoice_prefix', 'UNSAVED')
            ->call('fetchExchangeRate')
            ->assertSet('billing.usd_khr_exchange_rate', '4050.25')
            ->assertSet('billing.exchange_rate_date', '2026-10-13')
            ->assertSet('billing.invoice_prefix', 'UNSAVED');

        $this->assertEquals(4050.25, PropertySetting::where('property_id', $property->id)->value('usd_khr_exchange_rate'));
    }

    public function test_a_failed_exchange_rate_fetch_is_a_toast_not_an_error_page(): void
    {
        $this->subject();
        $this->app->instance(ExchangeRateService::class, Mockery::mock(ExchangeRateService::class, function ($mock) {
            $mock->shouldReceive('fetchUsdToKhr')->andThrow(new \RuntimeException('NBC is down'));
        }));

        Livewire::test(PropertySettings::class)
            ->call('fetchExchangeRate')
            ->assertDispatched('rw-m-toast', message: __('Could not fetch exchange rate. Please try again, or enter the rate yourself.'));
    }

    public function test_move_in_rules_are_saved_and_a_preset_builds_its_rules(): void
    {
        [$landlord, $property] = $this->subject();

        Livewire::test(PropertySettings::class)
            ->set('moveIn.first_month_billing_mode', FirstMonthBillingMode::HalfMonth->value)
            ->set('moveIn.proration_cutoff_day', '')
            ->call('saveMoveIn')
            ->assertHasErrors('moveIn.proration_cutoff_day')
            ->set('moveIn.proration_cutoff_day', '20')
            ->set('moveIn.require_first_month_upfront', true)
            ->set('moveIn.create_invoice_on_move_in', true)
            ->set('moveIn.upfront_deposit_months', '2')
            ->set('moveIn.move_in_preset', 'first_deposit')
            ->call('saveMoveIn')
            ->assertHasNoErrors();

        $setting = PropertySetting::where('property_id', $property->id)->firstOrFail();
        $this->assertSame(FirstMonthBillingMode::HalfMonth, $setting->first_month_billing_mode);
        $this->assertSame(20, $setting->proration_cutoff_day);
        $this->assertTrue($setting->require_first_month_upfront);
        $this->assertTrue($setting->create_invoice_on_move_in);
        $this->assertSame(2, $setting->upfront_deposit_months);
        $this->assertSame('first_deposit', $setting->move_in_preset);
        $this->assertTrue(MoveInRule::where('property_id', $property->id)->where('name', 'Security deposit')->where('is_active', true)->exists());
    }

    public function test_contacts_are_saved(): void
    {
        [$landlord, $property] = $this->subject();

        Livewire::test(PropertySettings::class)
            ->set('contacts.caretaker_name', 'Sokha')
            ->set('contacts.caretaker_phone', '012 345 678')
            ->set('contacts.parking_info', 'Motorbikes inside the gate')
            ->call('saveContacts')
            ->assertHasNoErrors();

        $setting = PropertySetting::where('property_id', $property->id)->firstOrFail();
        $this->assertSame('Sokha', $setting->caretaker_name);
        $this->assertSame('012 345 678', $setting->caretaker_phone);
        $this->assertSame('Motorbikes inside the gate', $setting->parking_info);
        $this->assertNull($setting->insurance_info);
    }

    public function test_closing_a_sheet_without_saving_drops_the_edits(): void
    {
        $this->subject();

        Livewire::test(PropertySettings::class)
            ->set('details.name', 'Typed but not saved')
            ->call('discard', 'prop-details')
            ->assertSet('details.name', 'Mobile Property');
    }

    public function test_opening_readings_are_recorded_for_rooms_that_still_need_one(): void
    {
        [$landlord, $property] = $this->subject();
        $electricity = PropertyUtility::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id, 'name' => 'Electricity',
            'billing_type' => BillingType::Metered, 'rate' => 0.25, 'unit_of_measure' => 'kWh', 'is_active' => true,
        ]);
        $room = Unit::create([
            'property_id' => $property->id, 'landlord_id' => $landlord->id,
            'room_number' => '101', 'room_type' => 'Room', 'rent_amount' => 100,
        ]);

        Livewire::test(PropertySettings::class)
            ->assertSet('openingUtilityId', $electricity->id)
            ->assertSee(__('Opening readings'))
            ->call('saveOpeningReadings')
            ->assertHasErrors('openingReadings')
            ->set("openingReadings.{$room->id}", '4120')
            ->call('saveOpeningReadings')
            ->assertHasNoErrors()
            ->assertDispatched('rw-m-sheet-close', name: 'prop-opening');

        $usage = UtilityUsage::where('unit_id', $room->id)->firstOrFail();
        $this->assertEquals(4120, $usage->new_reading);
        $this->assertEquals(0, $usage->amount_used);
    }

    public function test_a_read_only_subscription_cannot_save(): void
    {
        [$landlord, $property] = $this->subject();
        Subscription::withoutGlobalScopes()->where('landlord_id', $landlord->id)
            ->update(['ends_at' => now()->subDays(3), 'grace_ends_at' => null]);

        Livewire::test(PropertySettings::class)
            ->set('details.name', 'Nope')
            ->call('saveDetails')
            ->assertDispatched('rw-m-toast', message: __('Write actions are disabled until payment is completed.'))
            ->call('setMonthlyBilling', true)
            ->assertReturned(false);

        $this->assertSame('Mobile Property', $property->fresh()->name);
    }

    public function test_without_the_update_permission_saving_is_forbidden(): void
    {
        [$landlord, $property] = $this->subject();
        Role::findByName('landlord')->revokePermissionTo('update_property');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($landlord->fresh());

        Livewire::test(PropertySettings::class)
            ->set('contacts.caretaker_name', 'Nope')
            ->call('saveContacts')
            ->assertForbidden();
    }

    /** The active property is resolved through LandlordScope: another landlord's id in the session never loads. */
    public function test_another_landlords_property_is_never_loaded(): void
    {
        $landlord = $this->landlord();
        $own = $this->property($landlord, 'Mine');
        $stranger = $this->landlord();
        $foreign = $this->property($stranger, 'Not mine');

        $this->actingAs($landlord);
        session()->put(ActiveProperty::SESSION_KEY, $foreign->id);

        Livewire::test(PropertySettings::class)
            ->assertSet('propertyId', $own->id)
            ->assertDontSee('Not mine');

        $this->assertSame('Not mine', Property::withoutGlobalScopes()->find($foreign->id)->name);
    }

    /** @return array{0: User, 1: Property} */
    private function subject(): array
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->signIn($landlord, $property);

        return [$landlord, $property];
    }
}
