<?php

namespace App\Livewire\M2;

use App\Models\PropertyUtility;
use App\Services\PropertySetupService;
use App\Support\ActiveProperty;
use App\Support\M2Shell;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m2/settings — what the round picture in every header opens: the signed-in
 * account, links to property and utility settings, profile, the language switch
 * and sign out; the same options as the old /m Settings tab.
 *
 * Property settings, Add property and Utility rates open their /m2 screens
 * (M2\PropertySettings, M2\AddProperty, M2\UtilityRates). Nothing stateful
 * here: language links and the sign-out form are full page loads (they change
 * the session).
 */
#[Layout('m2.layout')]
class Settings extends Component
{
    public function render()
    {
        $user = Auth::user();
        $propertyId = ActiveProperty::id();

        return view('livewire.m2.settings', [
            'user' => $user,
            'avatarUrl' => M2Shell::mediaUrl($user?->getFirstMediaUrl('avatar')),
            'locale' => app()->getLocale(),
            'locales' => ['en' => 'English', 'km' => 'ខ្មែរ'],
            'canAddProperty' => PropertySetupService::canAdd($user),
            'propertyName' => ActiveProperty::model()?->name,
            'activeUtilities' => $propertyId
                ? PropertyUtility::query()->where('property_id', $propertyId)->where('is_active', true)->count()
                : null,
        ])->title(__('Settings'));
    }
}
