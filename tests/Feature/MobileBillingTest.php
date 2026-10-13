<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\FirstMonthBillingMode;
use App\Enums\ReadingType;
use App\Livewire\Mobile\Billing;
use App\Livewire\SimpleBillingInvoice;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UtilityUsage;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Livewire\Livewire;

/**
 * /m's "Create invoices" screen (Mobile\Billing). The wizard itself is
 * Concerns\BuildsMonthlyBilling, shared with Simple Mode's SimpleBillingInvoice;
 * the only thing that differs is how a message reaches the user (notify()):
 * /m has no Filament notification container, so a Filament notification there
 * would be silent — it must be a toast. Simple Mode is inside the panel, so it
 * must keep sending Filament notifications.
 */
class MobileBillingTest extends MobileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-05 09:00:00');
    }

    // --- Simple Mode keeps its Filament notifications ----------------------

    /** Simple Mode's warnings are still Filament notifications after the extraction. */
    public function test_simple_mode_still_warns_with_a_filament_notification(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);

        $this->signIn($landlord, $property);

        Livewire::test(SimpleBillingInvoice::class)
            ->call('startBilling')
            ->assertNotified(
                Notification::make()->warning()->title(__('No rooms are due for billing on this date.'))
            )
            ->assertNotDispatched('rw-m-toast');
    }

    /** …and its "Billing complete" summary too, with the same body as before. */
    public function test_simple_mode_still_reports_billing_complete_with_a_filament_notification(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(SimpleBillingInvoice::class)
            ->call('startBilling')
            ->set('rooms.0.utilities.0.new_reading', '150')
            ->call('createInvoices')
            ->assertNotified(
                Notification::make()
                    ->success()
                    ->title(__('Billing complete'))
                    ->body(implode(' ', [
                        __('Created :count invoice(s).', ['count' => 1]),
                        __('Billed :count charge(s).', ['count' => 2]),
                        __('Free/Waived :count charge(s).', ['count' => 0]),
                        __('Not applicable :count charge(s).', ['count' => 0]),
                        __('Skipped this cycle :count charge(s).', ['count' => 0]),
                    ]))
            )
            ->assertDispatched('invoices-created');

        $this->assertSame(1, Invoice::count());
    }

    // --- Mobile\Billing ------------------------------------------------------

    public function test_the_screen_renders_the_start_step_without_filament(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $this->rental($landlord, $property, 'A-1', 'Dara');

        $html = $this->signIn($landlord, $property)
            ->get(route('mobile.billing'))
            ->assertOk()
            ->assertSee(__('Create invoices'))
            ->assertSee(__('Billing date'))
            ->assertSee(__('Electricity'))
            ->assertSee(__('Start billing (:count rooms)', ['count' => 1]))
            ->assertSee('href="'.route('mobile.invoices').'"', false)
            ->getContent();

        $this->assertSame('start', $this->stepAttribute($html));
        $this->assertStringNotContainsString('rw-sm-', $html);
        $this->assertStringNotContainsString('fi-', $html);
        $this->assertStringNotContainsString('/app/', $html);
    }

    /**
     * The whole run on a phone: start → room by room → review → create →
     * result, with the toast (not a Filament notification) and the
     * `invoices-created` event, and the way back to the Invoices screen.
     */
    public function test_a_full_run_creates_an_invoice_for_every_due_room(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $first = $this->rental($landlord, $property, 'A-1', 'Dara');
        $second = $this->rental($landlord, $property, 'A-2', 'Sokha');

        $this->signIn($landlord, $property);

        $test = Livewire::test(Billing::class)
            ->assertSet('step', 'start')
            ->call('startBilling')
            ->assertSet('step', 'reading')
            ->assertSet('currentRoomIndex', 0)
            ->assertSee('A-1')
            ->assertSee(__('Next room'))
            ->set('rooms.0.utilities.0.new_reading', '150')
            ->assertSee(__('Usage').': 150 kWh')
            ->call('nextRoom')
            ->assertSet('currentRoomIndex', 1)
            ->set('rooms.1.utilities.0.new_reading', '40')
            ->call('nextRoom')
            ->assertSet('step', 'review')
            ->assertSee(__('Ready'))
            ->assertSee("rw-m-sheet-open', { name: 'billing-confirm' }", false)
            ->call('createInvoices')
            ->assertSet('step', 'result')
            ->assertSet('resultSummary.created', 2)
            ->assertDispatched('invoices-created')
            ->assertDispatched('rw-m-toast', message: __('Billing complete'), level: 'success')
            ->assertNotNotified()
            ->assertSee(__('View invoices'))
            ->assertSee('href="'.route('mobile.invoices').'"', false);

        $this->assertSame(2, Invoice::count());
        $this->assertDatabaseHas('invoices', ['rental_id' => $first->id, 'landlord_id' => $landlord->id]);
        $this->assertDatabaseHas('invoices', ['rental_id' => $second->id, 'landlord_id' => $landlord->id]);
        $this->assertDatabaseHas('utility_usages', ['rental_id' => $first->id, 'new_reading' => 150, 'amount_used' => 150]);

        // "Back to start" begins a fresh run for the same property, dated at the
        // rooms' next cycle — as a plain date, which is all <input type="date"> accepts.
        $test->call('billAgain')
            ->assertSet('step', 'start')
            ->assertSet('rooms', [])
            ->assertSet('propertyId', $property->id)
            ->assertSet('issueDate', '2026-07-01');
    }

    /** Nothing created (every room skipped) → no `invoices-created`. */
    public function test_a_run_that_creates_nothing_does_not_announce_new_invoices(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(Billing::class)
            ->call('startBilling')
            ->call('skipCurrentRoom')
            ->assertSet('step', 'review')
            ->call('createInvoices')
            ->assertSet('step', 'result')
            ->assertSet('resultSummary.skipped', 1)
            ->assertNotDispatched('invoices-created');

        $this->assertSame(0, Invoice::count());
    }

    /**
     * A room with a missing reading blocks the run: review sends the user
     * back to that room, create refuses — each with a toast, inline state on
     * the screen, and no invoice.
     */
    public function test_a_room_without_its_reading_blocks_the_run(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->rental($landlord, $property, 'A-2', 'Sokha');

        $this->signIn($landlord, $property);

        $test = Livewire::test(Billing::class)
            ->call('startBilling')
            ->set('rooms.1.utilities.0.new_reading', '40')
            ->call('jumpToRoom', 1)
            ->assertSet('currentRoomIndex', 1)
            ->call('nextRoom')
            ->assertSet('step', 'reading')
            ->assertSet('currentRoomIndex', 0)
            ->assertDispatched('rw-m-toast', message: __('Please finish or override the highlighted room before review.'), level: 'warning')
            ->assertNotNotified();

        $this->assertSame('todo', $test->instance()->roomStatus(0));
        $this->assertSame('done', $test->instance()->roomStatus(1));

        $test->call('createInvoices')
            ->assertDispatched('rw-m-toast', message: __('Complete or skip the blocked rooms before creating invoices.'), level: 'warning')
            ->assertSet('step', 'reading');

        // On review (reached without the check) the blocked room is flagged and
        // Create goes through the server, which warns instead of opening the sheet.
        $test->call('returnToReview')
            ->assertSee(__('Needs attention'))
            ->assertSee(__('Missing reading'))
            ->assertSee('wire:click="openCreateConfirmation"', false)
            ->call('openCreateConfirmation')
            ->assertSet('showCreateConfirmation', false)
            ->assertDispatched('rw-m-toast', message: __('Complete or skip the blocked rooms before creating invoices.'), level: 'warning');

        $this->assertSame(0, Invoice::count());
    }

    /** A reading below the previous one needs a reason before it can be billed. */
    public function test_a_lower_reading_blocks_until_a_reason_is_given(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');

        UtilityUsage::create([
            'property_utility_id' => PropertyUtility::where('property_id', $property->id)->value('id'),
            'unit_id' => $rental->unit_id,
            'rental_id' => $rental->id,
            'landlord_id' => $landlord->id,
            'recorded_by_id' => $landlord->id,
            'reading_type' => ReadingType::Actual,
            'reading_date' => '2026-06-30',
            'old_reading' => 100,
            'new_reading' => 200,
            'amount_used' => 100,
        ]);

        $this->signIn($landlord, $property);

        $test = Livewire::test(Billing::class)
            ->call('startBilling')
            ->assertSee(__('Previous reading'))
            ->set('rooms.0.utilities.0.new_reading', '150')
            ->assertSee(__('Lower than previous reading'))
            ->assertSee(__('Lower than previous reading — add a reason to bill it'));

        $this->assertSame('200', $test->get('rooms.0.utilities.0.old_reading'));
        $this->assertSame('attention', $test->instance()->roomStatus(0));

        $test->call('nextRoom')
            ->assertSet('step', 'reading')
            ->assertDispatched('rw-m-toast')
            ->set('rooms.0.utilities.0.override_reason', 'Meter replaced')
            ->call('nextRoom')
            ->assertSet('step', 'review')
            ->call('createInvoices')
            ->assertSet('resultSummary.created', 1);

        $this->assertSame(1, Invoice::count());
    }

    /**
     * The reading fields are text inputs, so a mistyped value reaches the
     * server. It must count as missing — Simple Mode's number inputs never
     * sent one, and createInvoices() would otherwise bill the room without
     * that utility.
     */
    public function test_a_reading_that_is_not_a_number_blocks_the_room(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(Billing::class)
            ->call('startBilling')
            ->set('rooms.0.utilities.0.new_reading', '12,5')
            ->assertSee(__('Enter numbers only, e.g. 1250.5'))
            ->call('nextRoom')
            ->assertSet('step', 'reading')
            ->call('createInvoices')
            ->assertNotDispatched('invoices-created');

        $this->assertSame(0, Invoice::count());
    }

    /** Billing off for the property: say so, and point to /m's property settings — not the panel. */
    public function test_a_property_with_billing_disabled_is_blocked(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord, enabled: false);
        $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(Billing::class)
            ->assertSet('step', 'blocked')
            ->assertSee(__('Billing is not available for this property.'))
            ->assertSee('href="'.route('mobile.property-settings').'"', false)
            ->assertDontSee('/app/', false)
            ->call('startBilling')
            ->assertDispatched('rw-m-toast')
            ->assertSet('rooms', []);
    }

    public function test_without_an_active_property_it_asks_for_one(): void
    {
        $landlord = $this->landlord(); // no property at all yet

        $this->actingAs($landlord);

        Livewire::test(Billing::class)
            ->assertSet('step', 'blocked')
            ->assertSee(__('Choose a property first'))
            ->call('startBilling')
            ->assertDispatched('rw-m-toast', message: __('Select a property from the sidebar to start billing.'), level: 'warning');
    }

    /** Read-only subscription: the run can be reviewed but not written. */
    public function test_a_read_only_subscription_cannot_create_invoices(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $this->rental($landlord, $property, 'A-1', 'Dara');

        Subscription::withoutGlobalScopes()->where('landlord_id', $landlord->id)->update([
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subDays(10),
            'grace_ends_at' => null,
        ]);

        $this->signIn($landlord, $property);

        Livewire::test(Billing::class)
            ->assertSee(__('Write actions are disabled'))
            ->call('startBilling')
            ->set('rooms.0.utilities.0.new_reading', '150')
            ->call('nextRoom')
            ->assertSet('step', 'review')
            ->assertSee('aria-describedby="rw-m-billing-read-only"', false)
            ->assertDontSee("rw-m-sheet-open', { name: 'billing-confirm' }", false)
            ->call('createInvoices')
            ->assertDispatched('rw-m-toast', message: __('Write actions are disabled until payment is completed.'), level: 'warning')
            ->assertNotDispatched('invoices-created')
            ->assertNotNotified();

        $this->assertSame(0, Invoice::count());
    }

    /** Choosing rooms by hand bills exactly those — even one that isn't due. */
    public function test_manual_mode_bills_only_the_chosen_rooms(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $chosen = $this->rental($landlord, $property, 'A-1', 'Dara');
        $other = $this->rental($landlord, $property, 'A-2', 'Sokha');
        $notDue = $this->rental($landlord, $property, 'A-3', 'Vanna');
        $notDue->update(['next_invoice_date' => '2026-08-01']);

        $this->signIn($landlord, $property);

        // The suggested billing date is the earliest next_invoice_date (A-3's), so all three are due on it.
        $test = Livewire::test(Billing::class)
            ->assertSet('issueDate', '2026-08-01')
            ->set('issueDate', '2026-07-05')
            ->assertSee(__('Start billing (:count rooms)', ['count' => 2]))
            ->set('manualMode', true)
            ->assertSee('A-3')
            ->call('startBilling')
            ->assertDispatched('rw-m-toast', message: __('Please select at least one room for manual billing.'), level: 'warning')
            ->assertSet('step', 'start')
            ->call('toggleSelectAllRentals')
            ->assertCount('selectedRentalIds', 3)
            ->call('toggleSelectAllRentals')
            ->assertCount('selectedRentalIds', 0)
            ->set('selectedRentalIds', [(string) $chosen->id, (string) $notDue->id])
            ->assertSee(__('Start billing (:count rooms)', ['count' => 2]))
            ->call('startBilling')
            ->assertSet('step', 'reading')
            ->assertCount('rooms', 2);

        $this->assertSame(['A-1', 'A-3'], array_column($test->get('rooms'), 'room_number'));

        $test->set('rooms.0.utilities.0.new_reading', '10')
            ->set('rooms.1.utilities.0.new_reading', '20')
            ->call('goToReview')
            ->call('createInvoices')
            ->assertSet('resultSummary.created', 2);

        $this->assertDatabaseHas('invoices', ['rental_id' => $chosen->id]);
        $this->assertDatabaseHas('invoices', ['rental_id' => $notDue->id]);
        $this->assertDatabaseMissing('invoices', ['rental_id' => $other->id]);
    }

    /** Hand-picked ids from another landlord are simply not found (LandlordScope). */
    public function test_manual_mode_ignores_another_landlords_rental(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $stranger = $this->landlord();
        $foreign = $this->rental($stranger, $this->billingProperty($stranger, 'Elsewhere'), 'X-1', 'Stranger');

        $this->signIn($landlord, $property);

        Livewire::test(Billing::class)
            ->set('manualMode', true)
            ->set('selectedRentalIds', [(string) $foreign->id])
            ->call('startBilling')
            ->assertSet('rooms', [])
            ->assertDispatched('rw-m-toast', message: __('None of the selected rooms have active rentals.'), level: 'warning');

        $this->assertSame(0, Invoice::withoutGlobalScopes()->count());
    }

    /** First invoice of a tenancy that started mid-month: prorated, and re-priced when the period changes. */
    public function test_the_first_period_is_prorated_and_follows_the_dates(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $rental->update(['start_date' => '2026-06-16']);

        $this->signIn($landlord, $property);

        $test = Livewire::test(Billing::class)
            ->call('startBilling')
            ->assertSee(__('Prorated'))
            ->assertSet('rooms.0.period_start', '2026-06-16')
            ->assertSet('rooms.0.period_end', '2026-07-05');

        $prorated = (float) $test->get('rooms.0.rent');
        $this->assertGreaterThan(0, $prorated);

        $test->set('rooms.0.period_end', '2026-06-30');
        $this->assertLessThan($prorated, (float) $test->get('rooms.0.rent'));

        $test->set('rooms.0.period_end', '2026-06-01')
            ->assertSee(__('Period start is after end'));
        $this->assertEquals(0.0, (float) $test->get('rooms.0.rent'));
    }

    /** Review → Edit opens that room; Back to review returns; Back from review resumes the readings. */
    public function test_review_navigation(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->rental($landlord, $property, 'A-2', 'Sokha');

        $this->signIn($landlord, $property);

        Livewire::test(Billing::class)
            ->call('startBilling')
            ->set('rooms.0.utilities.0.new_reading', '10')
            ->set('rooms.1.utilities.0.new_reading', '20')
            ->call('goToReview')
            ->assertSet('step', 'review')
            ->call('editRoom', 1)
            ->assertSet('step', 'reading')
            ->assertSet('currentRoomIndex', 1)
            ->assertSee(__('Back to review'))
            ->call('previousRoom')
            ->assertSet('step', 'review')
            ->call('backToReadings')
            ->assertSet('step', 'reading')
            ->assertSet('reviewFocusIndex', null)
            ->call('jumpToRoom', 0)
            ->assertSet('currentRoomIndex', 0)
            ->call('jumpToRoom', 9)
            ->assertSet('currentRoomIndex', 0)
            ->call('previousRoom')
            ->assertSet('step', 'start');
    }

    /** Enter on a reading: "next" while another reading follows in the room, "done" on the last. */
    public function test_reading_fields_are_phone_keyboards_with_next_and_done(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $this->addWater($landlord, $property);
        $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        $html = Livewire::test(Billing::class)->call('startBilling')->html();

        $this->assertSame(2, preg_match_all('/<input[^>]*data-reading[^>]*>/s', $html, $inputs));
        $this->assertStringContainsString('inputmode="decimal"', $inputs[0][0]);
        $this->assertStringContainsString('enterkeyhint="next"', $inputs[0][0]);
        $this->assertStringContainsString('id="rw-m-reading-0-0"', $inputs[0][0]);
        $this->assertStringContainsString('enterkeyhint="done"', $inputs[0][1]);
        $this->assertStringContainsString('rw-m-billing-bar', $html);
    }

    /** advanceFromReading() still points the client at the next field (focus-reading → #rw-m-reading-*). */
    public function test_advancing_from_a_reading_focuses_the_next_one(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $this->addWater($landlord, $property);
        $this->rental($landlord, $property, 'A-1', 'Dara');

        $this->signIn($landlord, $property);

        Livewire::test(Billing::class)
            ->call('startBilling')
            ->call('advanceFromReading', 0, 0)
            ->assertDispatched('focus-reading', ref: 'reading-0-1');
    }

    /** The confirmation sheet carries the run's numbers and the exchange rate. */
    public function test_the_confirmation_sheet_summarises_the_run(): void
    {
        $landlord = $this->landlord();
        $property = $this->billingProperty($landlord);
        $this->rental($landlord, $property, 'A-1', 'Dara');
        $this->rental($landlord, $property, 'A-2', 'Sokha');

        $this->signIn($landlord, $property);

        $html = Livewire::test(Billing::class)
            ->call('startBilling')
            ->set('rooms.0.utilities.0.new_reading', '10')
            ->call('toggleRoomSkip', 1)
            ->call('goToReview')
            ->assertSee(__('Confirm invoice creation'))
            ->assertSee('1 USD = 4,000 KHR')
            ->html();

        $this->assertSame(1, preg_match('/id="rw-m-billing-confirm-count">\s*(\d+)\s*</', $html, $m));
        $this->assertSame('1', $m[1]);
    }

    // --- Fixtures ------------------------------------------------------------

    /** A property with monthly billing on and one metered utility (Electricity, $0.25/kWh). */
    protected function billingProperty(User $landlord, string $name = 'Billing Property', bool $enabled = true): Property
    {
        $property = $this->property($landlord, $name);

        PropertySetting::create([
            'property_id' => $property->id,
            'currency' => 'USD',
            'invoice_prefix' => 'INV',
            'monthly_billing_enabled' => $enabled,
            'invoice_due_days' => 7,
            'due_day_of_month' => 7,
            'first_month_billing_mode' => FirstMonthBillingMode::Prorated,
        ]);

        PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Electricity',
            'billing_type' => BillingType::Metered,
            'rate' => 0.25,
            'unit_of_measure' => 'kWh',
            'is_active' => true,
        ]);

        return $property;
    }

    private function addWater(User $landlord, Property $property): void
    {
        PropertyUtility::create([
            'property_id' => $property->id,
            'landlord_id' => $landlord->id,
            'name' => 'Water',
            'billing_type' => BillingType::Metered,
            'rate' => 0.5,
            'unit_of_measure' => 'm3',
            'is_active' => true,
        ]);
    }

    private function stepAttribute(string $html): ?string
    {
        return preg_match('/id="rw-m-billing"[^>]*data-step="([a-z]+)"/s', $html, $m) ? $m[1] : null;
    }
}
