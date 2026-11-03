<?php

namespace App\Livewire\M2;

use App\Livewire\SimpleProfile;
use App\Support\M2Shell;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;

/**
 * /m2/settings/profile — the signed-in user's own profile. The logic is
 * SimpleProfile's (info, photo that saves the moment one is picked, password
 * change — all for Auth::user() only); only the view is /m2's.
 */
#[Layout('m2.layout')]
class Profile extends SimpleProfile
{
    public function saveAvatar(): void
    {
        parent::saveAvatar();

        // Re-render in place: drop the already-loaded media or the old photo would keep showing.
        Auth::user()->unsetRelation('media');
    }

    public function render()
    {
        return view('livewire.m2.profile', [
            'avatarUrl' => M2Shell::mediaUrl(Auth::user()->getFirstMediaUrl('avatar')),
        ])->title(__('Profile'));
    }
}
