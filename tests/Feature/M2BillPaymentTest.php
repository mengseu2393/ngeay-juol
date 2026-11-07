<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Livewire\M2\Bill;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PropertySetting;
use App\Models\Subscription;
use App\Support\Money;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * /m2 Bill — "Record payment". Money goes through Invoice::recordPayment() so the
 * ledger stays the truth for amount_paid and the status. More than the balance is
 * refused on purpose (the panel allows it): /m2 has no way to undo a payment, so a
 * typo like 3000 for 300 would be stuck, and the same check stops a double payment
 * when two phones have the form open.
 */
class M2BillPaymentTest extends M2TestCase
{
    protected function tearDown(): void
    {
        $this->forgetRememberedCurrencies();

        parent::tearDown();
    }

    /** Money remembers a property's currency for the request; a test process outlives one, and ids get reused. */
    private function forgetRememberedCurrencies(): void
    {
        (new \ReflectionProperty(Money::class, 'currencyByProperty'))->setValue(null, []);
    }

    public function test_paying_the_whole_balance_marks_the_invoice_paid(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $invoice = $this->invoice($rental, 'INV-1', 300);
        $this->signIn($landlord, $property);

        Livewire::test(Bill::class, ['unit' => $rental->unit])
            ->assertSeeHtml('id="m2-bill-pay"')
            ->assertDontSeeHtml('id="m2-bill-pay-form"')
            ->call('startPay')
            ->assertSeeHtml('id="m2-bill-pay-form"')
            ->assertSet('payAmount', '300.00')
            ->set('payMethod', PaymentMethod::BankTransfer->value)
            ->set('payNote', ' ABA transfer ')
            ->call('submitPay')
            ->assertHasNoErrors()
            ->assertDispatched('rw-m-toast', message: __('Invoice fully paid.'))
            ->assertSet('payingInvoiceId', null)
            ->assertDontSeeHtml('id="m2-bill-pay"')
            ->assertDontSeeHtml('id="m2-bill-pay-form"');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->payment_status);
        $this->assertSame(0.0, (float) $invoice->balance);

        $payment = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame(300.0, (float) $payment->amount);
        $this->assertSame(PaymentMethod::BankTransfer, $payment->method);
        $this->assertSame('ABA transfer', $payment->note);
        $this->assertSame($landlord->id, $payment->recorded_by_id);
    }

    public function test_a_part_payment_leaves_the_rest_to_pay(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $invoice = $this->invoice($rental, 'INV-1', 300);
        $this->signIn($landlord, $property);

        Livewire::test(Bill::class, ['unit' => $rental->unit])
            ->call('startPay')
            ->set('payAmount', '100')
            ->call('submitPay')
            ->assertHasNoErrors()
            ->assertDispatched('rw-m-toast', message: __('Payment saved. Balance: :balance', ['balance' => '$200.00']))
            ->assertSeeHtml('id="m2-bill-pay"')
            ->call('startPay')
            ->assertSet('payAmount', '200.00');

        $this->assertSame(InvoiceStatus::Partial, $invoice->fresh()->payment_status);
    }

    public function test_more_than_the_balance_is_refused(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $invoice = $this->invoice($rental, 'INV-1', 300);
        $this->signIn($landlord, $property);

        Livewire::test(Bill::class, ['unit' => $rental->unit])
            ->call('startPay')
            ->set('payAmount', '3000')
            ->call('submitPay')
            ->assertHasErrors('payAmount')
            ->assertSeeHtml('id="m2-pay-amount-error"');

        $this->assertSame(0, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame(InvoiceStatus::Pending, $invoice->fresh()->payment_status);
    }

    public function test_an_invalid_amount_shows_an_error_and_saves_nothing(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $invoice = $this->invoice($rental, 'INV-1', 300);
        $this->signIn($landlord, $property);

        $bill = Livewire::test(Bill::class, ['unit' => $rental->unit])->call('startPay');

        foreach (['', 'abc', '0', '-5'] as $amount) {
            $bill->set('payAmount', $amount)->call('submitPay')->assertHasErrors('payAmount');
        }

        $this->assertSame(0, Payment::where('invoice_id', $invoice->id)->count());
    }

    /** Two phones have the form open: the second save finds nothing left, so the rent isn't taken twice. */
    public function test_a_payment_taken_elsewhere_stops_the_second_save(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $invoice = $this->invoice($rental, 'INV-1', 300);
        $this->signIn($landlord, $property);

        $bill = Livewire::test(Bill::class, ['unit' => $rental->unit])->call('startPay');

        Invoice::find($invoice->id)->recordPayment(['amount' => 300, 'currency' => 'USD']);

        $bill->call('submitPay')->assertHasErrors('payAmount');

        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
    }

    public function test_paid_and_cancelled_invoices_take_no_payment(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);

        foreach ([InvoiceStatus::Paid, InvoiceStatus::Cancelled] as $i => $status) {
            $rental = $this->rental($landlord, $property, 'A-'.$i, 'Dara');
            $this->invoice($rental, 'INV-'.$i, 300, $status);
            $this->signIn($landlord, $property);

            Livewire::test(Bill::class, ['unit' => $rental->unit])
                ->assertDontSeeHtml('id="m2-bill-pay"')
                ->call('startPay')
                ->assertForbidden();
        }
    }

    public function test_the_invoice_picked_from_the_pills_is_the_one_paid(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $older = $this->invoice($rental, 'INV-OLD', 100, issued: now()->subMonth());
        $newer = $this->invoice($rental, 'INV-NEW', 300);
        $this->signIn($landlord, $property);

        Livewire::test(Bill::class, ['unit' => $rental->unit])
            ->call('startPay')
            ->assertSet('payingInvoiceId', $newer->id)
            ->call('pick', $older->id)
            ->assertSet('payingInvoiceId', null)
            ->call('startPay')
            ->assertSet('payingInvoiceId', $older->id)
            ->assertSet('payAmount', '100.00')
            ->call('submitPay')
            ->assertHasNoErrors();

        $this->assertSame(InvoiceStatus::Paid, $older->fresh()->payment_status);
        $this->assertSame(InvoiceStatus::Pending, $newer->fresh()->payment_status);
    }

    public function test_another_rooms_invoice_cannot_be_paid_from_this_room(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $mine = $this->invoice($rental, 'INV-A', 300);
        $other = $this->invoice($this->rental($landlord, $property, 'A-2', 'Sok'), 'INV-B', 500);
        $this->signIn($landlord, $property);

        // A tampered pick falls back to this room's own invoice…
        Livewire::test(Bill::class, ['unit' => $rental->unit])
            ->call('pick', $other->id)
            ->call('startPay')
            ->assertSet('payingInvoiceId', $mine->id)
            ->call('submitPay')
            ->assertHasNoErrors();

        $this->assertSame(InvoiceStatus::Paid, $mine->fresh()->payment_status);
        $this->assertSame(0, Payment::where('invoice_id', $other->id)->count());

        // …and the form's target can't be set from the browser.
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(Bill::class, ['unit' => $rental->unit])->set('payingInvoiceId', $other->id);
    }

    public function test_a_read_only_subscription_cannot_record_a_payment(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $invoice = $this->invoice($rental, 'INV-1', 300);
        $this->signIn($landlord, $property);

        $open = Livewire::test(Bill::class, ['unit' => $rental->unit])->call('startPay');

        Subscription::withoutGlobalScopes()->where('landlord_id', $landlord->id)
            ->update(['ends_at' => now()->subDays(3), 'grace_ends_at' => null]);

        $open->call('submitPay')->assertForbidden();

        Livewire::test(Bill::class, ['unit' => $rental->unit])
            ->assertDontSeeHtml('id="m2-bill-pay"')
            ->call('startPay')
            ->assertForbidden();

        $this->assertSame(0, Payment::where('invoice_id', $invoice->id)->count());
    }

    public function test_a_riel_invoice_is_paid_in_riel_with_no_decimals(): void
    {
        $landlord = $this->landlord();
        $property = $this->property($landlord);
        PropertySetting::updateOrCreate(['property_id' => $property->id], ['currency' => 'KHR', 'usd_khr_exchange_rate' => 4000]);
        $this->forgetRememberedCurrencies();
        $rental = $this->rental($landlord, $property, 'A-1', 'Dara');
        $invoice = $this->invoice($rental, 'INV-1', 400000);
        $this->signIn($landlord, $property);

        Livewire::test(Bill::class, ['unit' => $rental->unit])
            ->call('startPay')
            ->assertSet('payAmount', '400000')
            ->call('submitPay')
            ->assertHasNoErrors();

        $payment = Payment::where('invoice_id', $invoice->id)->sole();
        $this->assertSame('KHR', $payment->currency);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->payment_status);
    }
}
