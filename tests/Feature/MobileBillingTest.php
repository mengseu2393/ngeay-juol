<?php

namespace Tests\Feature;

use App\Enums\BillingType;
use App\Enums\FirstMonthBillingMode;
use App\Livewire\SimpleBillingInvoice;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\PropertyUtility;
use App\Models\User;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Livewire\Livewire;

/**
 * The monthly billing wizard (Concerns\BuildsMonthlyBilling) is shared by
 * Simple Mode's SimpleBillingInvoice and /m's Mobile\Billing; the only thing
 * that differs is how a message reaches the user (notify()). Simple Mode is
 * inside the Filament panel, so it must keep sending Filament notifications.
 */
class MobileBillingTest extends MobileTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-05 09:00:00');
    }

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
}
