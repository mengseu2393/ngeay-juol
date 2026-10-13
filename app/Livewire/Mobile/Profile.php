<?php

namespace App\Livewire\Mobile;

use App\Livewire\SimpleProfile;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;

/**
 * /m/settings/profile — the signed-in user's profile on a phone. The same
 * component as Simple Mode's (SimpleProfile: info, avatar that saves the
 * moment a photo is picked, password change, all for Auth::user() only), with
 * a phone view and a toast for each save. It still dispatches avatar-updated;
 * /m has no avatar in its top bar, so nothing reloads on it.
 */
#[Layout('mobile.layout')]
class Profile extends SimpleProfile
{
    public function saveInfo(): void
    {
        parent::saveInfo();

        if ($this->infoSaved) {
            $this->dispatch('rw-m-toast', message: __('Profile updated.'));
        }
    }

    public function saveAvatar(): void
    {
        parent::saveAvatar();

        // Simple Mode reloads the page here; /m re-renders in place, so drop the
        // user's already-loaded media or the old photo would keep showing.
        Auth::user()->unsetRelation('media');

        $this->dispatch('rw-m-toast', message: __('Profile photo updated.'));
    }

    public function savePassword(): void
    {
        parent::savePassword();

        if ($this->passwordSaved) {
            $this->dispatch('rw-m-toast', message: __('Password updated.'));
        }
    }

    public function render()
    {
        return view('livewire.mobile.profile', [
            'avatarUrl' => Auth::user()->getFirstMediaUrl('avatar') ?: null,
        ])->title(__('Profile'));
    }
}
