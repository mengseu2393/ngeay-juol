<?php

namespace App\Livewire\Mobile;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * /m/settings — the Settings tab: a native grouped list linking to the other
 * /m settings screens, the language switch and sign out. Ported from Simple
 * Mode's settings partial (filament.pages.partials.simple-settings), minus its
 * links into the Filament panel: phones stay in /m, and there is no "full mode"
 * here — desktop users have the panel.
 *
 * Nothing stateful: the language links and the sign-out form are full page
 * loads (they change the session), every other row is a wire:navigate link.
 */
#[Layout('mobile.layout')]
class Settings extends Component
{
    public function render()
    {
        $user = Auth::user();

        return view('livewire.mobile.settings', [
            'user' => $user,
            'avatarUrl' => $user?->getFirstMediaUrl('avatar') ?: null,
            'locale' => app()->getLocale(),
            'locales' => ['en' => 'English', 'km' => 'ខ្មែរ'],
        ])->title(__('Settings'));
    }
}
