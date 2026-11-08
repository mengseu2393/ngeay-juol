<?php

namespace App\Livewire\M2;

use App\Enums\BillingType;
use App\Livewire\Concerns\RunsLandlordSetup;
use App\Support\LocationHelper;
use App\Support\UtilityCatalog;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2/setup — the setup wizard a self-registered landlord goes through after
 * /register, on a phone: the same steps, rules and writes as the panel's
 * /app/setup (RunsLandlordSetup), in /m2's look. Properties → utilities → rooms
 * & prices → review; every Next saves, so leaving mid-way resumes at the same
 * step (RedirectToLandlordSetup sends /m2 and phone users here).
 */
#[Layout('m2.layout')]
class Setup extends Component
{
    use RunsLandlordSetup;

    protected function setupDoneUrl(): string
    {
        return route('m2.home');
    }

    public function render()
    {
        return view('livewire.m2.setup', [
            'billingTypes' => BillingType::options(),
            'utilityNames' => UtilityCatalog::options(),
            'provinces' => LocationHelper::getProvinces(),
        ])->title(__('Set up your rentals'));
    }
}
