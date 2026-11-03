<?php

namespace App\Livewire\M2;

use App\Providers\Filament\LandlordPanelProvider;
use App\Services\PropertySetupService;
use App\Support\M2Shell;
use App\Support\MobileShell;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2/settings — what the round picture in every header opens: the signed-in
 * account, links to property and utility settings, profile, the language switch
 * and sign out; the same options as the old /m Settings tab.
 *
 * Property settings, Add property and Utility rates are not rebuilt in /m2: they
 * open the existing screens — /m's when the /m flag lets this user in, otherwise
 * the landlord panel's own pages. Nothing stateful here: language links and the
 * sign-out form are full page loads (they change the session).
 */
#[Layout('m2.layout')]
class Settings extends Component
{
    public function render()
    {
        $user = Auth::user();
        $inMobileShell = MobileShell::isAvailableFor($user);
        $panel = LandlordPanelProvider::PATH;

        return view('livewire.m2.settings', [
            'user' => $user,
            'avatarUrl' => M2Shell::mediaUrl($user?->getFirstMediaUrl('avatar')),
            'locale' => app()->getLocale(),
            'locales' => ['en' => 'English', 'km' => 'ខ្មែរ'],
            'canAddProperty' => PropertySetupService::canAdd($user),
            'links' => [
                'property' => $inMobileShell ? route('mobile.property-settings') : url($panel.'/property-settings'),
                'add' => $inMobileShell ? route('mobile.add-property') : url($panel.'/add-property'),
                'rates' => $inMobileShell ? route('mobile.utility-rates') : url($panel.'/property-utilities'),
            ],
        ])->title(__('Settings'));
    }
}
