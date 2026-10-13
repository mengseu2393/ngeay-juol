<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\ReadingType;
use App\Livewire\Mobile\UtilityRates;
use App\Livewire\Mobile\UtilityRatesReadingHistory;
use App\Models\Property;
use App\Models\PropertyUtility;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityUsage;
use Livewire\Livewire;

/**
 * /m's Utility Rates screen. It is Simple Mode's component (SimpleUtilityRates)
 * with a phone view, so these pin what the phone adds — toasts, the sheet
 * events, the mobile reading-history component — and that the inherited
 * scoping still holds on every write.
 */
class MobileUtilityRatesTest extends MobileTestCase
{
    public function test_it_lists_the_active_propertys_utilities_with_a_back_link_to_settings(): void
    {
        $landlord = $this->landlord();
        $home = $this->property($landlord, 'Home');
        $this->utility($home, ['name' => 'Electricity']);
        $this->utility($this->property($landlord, 'Other'), ['name' => 'Water']);

        $this->signIn($landlord, $home)
            ->get(route('mobile.utility-rates'))
            ->assertOk()
            ->assertSee('EDC-Electricity')
            ->assertDontSee('EDC-Water')
            ->assertSee('href="'.route('mobile.settings').'"', false)
            ->assertDontSee('rw-sm-', false)
            ->assertDontSee('fi-', false);
    }

    public function test_filter_and_search_narrow_the_list(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->utility($property, ['name' => 'Electricity', 'is_active' => true]);
        $this->utility($property, ['name' => 'Water', 'is_active' => false]);

        $this->signIn($landlord, $property);

        Livewire::test(UtilityRates::class)
            ->assertSee('EDC-Electricity')->assertSee('EDC-Water')
            ->set('filter', 'active')
            ->assertSee('EDC-Electricity')->assertDontSee('EDC-Water')
            ->set('filter', 'inactive')
            ->assertDontSee('EDC-Electricity')->assertSee('EDC-Water')
            ->set('filter', 'all')
            ->set('search', 'EDC-Wat')
            ->assertSee('EDC-Water')->assertDontSee('EDC-Electricity');
    }

    public function test_without_an_active_property_it_asks_to_choose_one(): void
    {
        $landlord = $this->landlord();
        $this->property($landlord, 'One');
        $this->property($landlord, 'Two');

        $this->actingAs($landlord)
            ->withSession(['active_property_id' => 0])
            ->get(route('mobile.utility-rates'))
            ->assertOk()
            ->assertSee(__('Choose a property first'))
            ->assertDontSeeHtml('id="rw-m-utility-add"');
    }

    public function test_saving_creates_a_utility_closes_the_sheet_and_toasts(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $this->signIn($landlord, $property);

        Livewire::test(UtilityRates::class)
            ->call('save', null, [
                'name' => 'Water',
                'billing_type' => BillingType::Metered->value,
                'rate' => '0.30',
                'unit_of_measure' => 'm³',
                'provider' => 'PPWSA',
                'account_ref' => '',
                'is_active' => true,
            ])
            ->assertHasNoErrors()
            ->assertDispatched('utility-rate-saved')
            ->assertDispatched('rw-m-toast', message: __('Utility added.'));

        $this->assertDatabaseHas('property_utilities', [
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Water',
            'provider' => 'PPWSA',
        ]);
    }

    public function test_saving_an_invalid_rate_keeps_the_errors_and_does_not_toast(): void
    {
        $landlord = $this->landlord();
        $this->signIn($landlord, $this->property($landlord));

        Livewire::test(UtilityRates::class)
            ->call('save', null, [
                'name' => 'Water',
                'billing_type' => BillingType::Metered->value,
                'rate' => '-1',
                'unit_of_measure' => 'm³',
            ])
            ->assertHasErrors(['rate'])
            ->assertNotDispatched('utility-rate-saved')
            ->assertNotDispatched('rw-m-toast')
            ->assertSeeHtml('data-rw-m-error')
            ->call('clearFormErrors')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('property_utilities', 0);
    }

    public function test_edit_toggle_and_delete_each_toast(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $utility = $this->utility($property, ['name' => 'Electricity', 'rate' => 0.25]);
        $this->signIn($landlord, $property);

        $component = Livewire::test(UtilityRates::class);

        $component->call('save', $utility->id, [
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered->value,
            'rate' => '0.28',
            'unit_of_measure' => 'kWh',
            'provider' => 'EDC',
            'is_active' => true,
        ])
            ->assertHasNoErrors()
            ->assertDispatched('rw-m-toast', message: __('Utility rate saved.'));
        $this->assertEquals(0.28, (float) $utility->fresh()->rate);

        $component->call('toggleActive', $utility->id)
            ->assertDispatched('utility-rate-toggled')
            ->assertDispatched('rw-m-toast', message: __('Utility deactivated.'));
        $this->assertFalse($utility->fresh()->is_active);

        $component->call('delete', $utility->id)
            ->assertDispatched('utility-rate-deleted')
            ->assertDispatched('rw-m-toast', message: __('Utility deleted.'));
        $this->assertSoftDeleted('property_utilities', ['id' => $utility->id]);
    }

    /** Another landlord's utility: every write and the history 404, and nothing changes. */
    public function test_another_landlords_utility_cannot_be_touched(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $stranger = $this->landlord();
        $foreign = $this->utility($this->property($stranger), ['name' => 'Gas', 'rate' => 0.5]);

        $this->signIn($landlord, $property);

        Livewire::test(UtilityRates::class)
            ->call('save', $foreign->id, [
                'name' => 'Gas',
                'billing_type' => BillingType::Metered->value,
                'rate' => '9',
                'unit_of_measure' => 'kWh',
            ])
            ->assertStatus(404);

        Livewire::test(UtilityRates::class)->call('toggleActive', $foreign->id)->assertStatus(404);
        Livewire::test(UtilityRates::class)->call('delete', $foreign->id)->assertStatus(404);
        Livewire::test(UtilityRates::class)->call('openHistory', $foreign->id)->assertStatus(404);

        $foreign = PropertyUtility::withoutGlobalScopes()->find($foreign->id);
        $this->assertNotNull($foreign);
        $this->assertEquals(0.5, (float) $foreign->rate);
        $this->assertTrue($foreign->is_active);
        $this->assertNull($foreign->deleted_at);
    }

    /** Pointing the history at a foreign room 404s too. */
    public function test_the_reading_history_is_scoped_to_the_active_propertys_rooms(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $utility = $this->utility($property, ['name' => 'Electricity']);
        $stranger = $this->landlord();
        $foreignRoom = $this->rental($stranger, $this->property($stranger), 'X-1', 'Stranger')->unit_id;

        $this->signIn($landlord, $property);

        Livewire::test(UtilityRates::class)
            ->call('openHistory', $utility->id)
            ->call('selectHistoryRoom', $foreignRoom)
            ->assertStatus(404);

        Livewire::test(UtilityRatesReadingHistory::class, ['unitId' => $foreignRoom, 'utilityId' => $utility->id])
            ->assertStatus(404);
    }

    public function test_reading_history_shows_the_rooms_readings_in_the_phone_view(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $utility = $this->utility($property, ['name' => 'Electricity']);
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $this->reading($landlord, $unit, $utility, 1180, 1251);

        $this->signIn($landlord, $property);

        Livewire::test(UtilityRates::class)
            ->call('openHistory', $utility->id)
            ->assertSet('historyUtilityId', $utility->id)
            ->assertSet('historyUnitId', $unit->id)
            ->assertSeeHtml('id="rw-m-history-room"')
            ->call('closeHistory')
            ->assertSet('historyUtilityId', null);

        Livewire::test(UtilityRatesReadingHistory::class, ['unitId' => $unit->id, 'utilityId' => $utility->id])
            ->assertSee('1,251')
            ->assertSee('71')
            ->assertSeeHtml('id="rw-m-reading-history-'.$unit->id.'"')
            ->assertDontSeeHtml('rw-rh-');
    }

    public function test_correcting_a_reading_from_the_history_toasts(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $utility = $this->utility($property, ['name' => 'Electricity']);
        $unit = $this->rental($landlord, $property, 'A-1', 'Dara')->unit;
        $usage = $this->reading($landlord, $unit, $utility, 100, 150);

        $this->signIn($landlord, $property);

        Livewire::test(UtilityRatesReadingHistory::class, ['unitId' => $unit->id, 'utilityId' => $utility->id])
            ->call('edit', $usage->id)
            ->set('newReading', '160')
            ->call('review')
            ->assertSet('confirming', true)
            ->set('confirmed', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true)
            ->assertDispatched('rw-m-toast', message: __('Reading corrected.'));

        $this->assertEquals(160, (float) $usage->fresh()->new_reading);
    }

    private function utility(Property $property, array $attributes = []): PropertyUtility
    {
        return PropertyUtility::create($attributes + [
            'property_id' => $property->id,
            'landlord_id' => $property->landlord_id,
            'billing_type' => BillingType::Metered,
            'rate' => 0.25,
            'unit_of_measure' => 'kWh',
            'provider' => 'EDC-'.($attributes['name'] ?? 'x'),
            'is_active' => true,
        ]);
    }

    private function reading(User $landlord, Unit $unit, PropertyUtility $utility, float $old, float $new): UtilityUsage
    {
        return UtilityUsage::create([
            'unit_id' => $unit->id,
            'property_utility_id' => $utility->id,
            'landlord_id' => $landlord->id,
            'recorded_by_id' => $landlord->id,
            'reading_type' => ReadingType::Actual,
            'reading_date' => now()->subDays(3)->toDateString(),
            'old_reading' => $old,
            'new_reading' => $new,
            'amount_used' => $new - $old,
        ]);
    }
}
