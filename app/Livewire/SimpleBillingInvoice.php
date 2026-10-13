<?php

namespace App\Livewire;

use App\Livewire\Concerns\BuildsMonthlyBilling;
use Filament\Notifications\Notification;
use Livewire\Component;

/**
 * Simple Mode's "Create invoices" screen (inside the Filament landlord panel).
 * The wizard itself is Concerns\BuildsMonthlyBilling, shared with the mobile
 * shell's App\Livewire\Mobile\Billing.
 */
class SimpleBillingInvoice extends Component
{
    use BuildsMonthlyBilling;

    protected $listeners = ['propertyChanged' => 'refreshProperty'];

    /** Inside the panel, so a Filament notification. */
    protected function notify(string $title, ?string $body = null, string $level = 'warning'): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->status($level)
            ->send();
    }

    public function render()
    {
        return view('livewire.simple-billing-invoice');
    }
}
