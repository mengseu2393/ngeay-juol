<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Simple Mode — Profile screen for mobile/PWA: edit name/email/phone, swap the
 * avatar photo (User's `avatar` singleFile media collection), and change the
 * password. Three independent forms/saves on one screen rather than a wizard,
 * since none of the three depend on each other.
 */
class SimpleProfile extends Component
{
    use WithFileUploads;

    public string $name = '';

    public string $email = '';

    public string $phone_number = '';

    public string $gender = '';

    public string $dob = '';

    public string $nationality = '';

    public string $province = '';

    public string $district = '';

    public string $commune = '';

    public string $village = '';

    public ?TemporaryUploadedFile $avatarPhoto = null;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $infoSaved = false;

    public bool $passwordSaved = false;

    public function mount(): void
    {
        $user = Auth::user();
        $this->name = (string) $user->name;
        $this->email = (string) $user->email;
        $this->phone_number = (string) $user->phone_number;
        $this->gender = (string) $user->gender;
        $this->dob = $user->dob?->toDateString() ?? '';
        $this->nationality = (string) $user->nationality;
        $this->province = (string) $user->province;
        $this->district = (string) $user->district;
        $this->commune = (string) $user->commune;
        $this->village = (string) $user->village;
    }

    public function saveInfo(): void
    {
        $this->infoSaved = false;
        $user = Auth::user();

        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,'.$user->id,
            'phone_number' => 'nullable|string|max:50',
            'gender' => 'nullable|in:male,female,other',
            'dob' => 'nullable|date',
            'nationality' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:255',
            'commune' => 'nullable|string|max:255',
            'village' => 'nullable|string|max:255',
        ]);

        $user->update([
            'name' => trim($this->name),
            'email' => trim($this->email),
            'phone_number' => trim($this->phone_number) ?: null,
            'gender' => $this->gender ?: null,
            'dob' => $this->dob ?: null,
            'nationality' => trim($this->nationality) ?: null,
            'province' => trim($this->province) ?: null,
            'district' => trim($this->district) ?: null,
            'commune' => trim($this->commune) ?: null,
            'village' => trim($this->village) ?: null,
        ]);

        $this->infoSaved = true;
    }

    public function saveAvatar(): void
    {
        $user = Auth::user();

        $this->validate([
            'avatarPhoto' => 'required|image|max:5120',
        ]);

        $user->addMedia($this->avatarPhoto->getRealPath())
            ->usingFileName($this->avatarPhoto->getClientOriginalName())
            ->toMediaCollection('avatar');

        $this->reset('avatarPhoto');

        // Topbar user-menu avatar is rendered outside this component (Filament
        // chrome), so a full reload is the simplest way to pick up the new image.
        $this->dispatch('avatar-updated');
    }

    public function savePassword(): void
    {
        $this->passwordSaved = false;
        $user = Auth::user();

        $this->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ]);

        $user->forceFill([
            'password' => Hash::make($this->password),
        ])->save();

        $this->reset(['current_password', 'password', 'password_confirmation']);
        $this->passwordSaved = true;
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.simple-profile', [
            'avatarUrl' => $user->getFirstMediaUrl('avatar') ?: null,
        ]);
    }
}
