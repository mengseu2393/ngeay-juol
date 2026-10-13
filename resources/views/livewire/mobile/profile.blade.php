@php
    $label = 'mb-1 block text-sm font-semibold';
    $error = 'mt-1 text-sm text-red-600 dark:text-red-400';
    $groupLabel = 'px-4 pb-1.5 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400';
@endphp

<div class="space-y-6 pb-2" x-data="rwMobileProfile()">
    <div>
        <a href="{{ route('mobile.settings') }}" wire:navigate class="-ml-2 inline-flex min-h-11 items-center gap-1 pt-3 pr-3 pl-1 font-semibold text-brand-600 active:opacity-60 dark:text-emerald-400" id="rw-m-profile-back">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-6" aria-hidden="true"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>
            {{ __('Settings') }}
        </a>
        <h1 class="rw-m-title pt-1 pb-0">{{ __('Profile') }}</h1>
    </div>

    {{-- ── Photo: picking one saves it straight away (SimpleProfile::updatedAvatarPhoto) ── --}}
    <section class="flex flex-col items-center gap-3" aria-label="{{ __('Photo') }}">
        <div class="relative size-28 overflow-hidden rounded-full border border-slate-200 bg-slate-100 dark:border-slate-700 dark:bg-slate-800" id="rw-m-profile-avatar">
            @if ($avatarPhoto)
                <img src="{{ $avatarPhoto->temporaryUrl() }}" class="size-full object-cover" alt="{{ __('Profile photo') }}">
            @elseif ($avatarUrl)
                <img src="{{ $avatarUrl }}" class="size-full object-cover" alt="{{ __('Profile photo') }}">
            @else
                <div class="flex size-full items-center justify-center text-slate-400 dark:text-slate-500">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-14" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                </div>
            @endif

            <div x-show="uploading" x-cloak class="absolute inset-0 flex items-center justify-center bg-slate-950/50 text-white" aria-busy="true">
                <svg class="size-7 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            </div>
        </div>

        <label for="rw-m-profile-photo" class="rw-m-btn rw-m-btn-secondary min-h-11 cursor-pointer text-sm" x-bind:class="uploading && 'pointer-events-none opacity-60'">
            <span x-show="! uploading">{{ __('Choose photo') }}</span>
            <span x-show="uploading" x-cloak>{{ __('Uploading…') }}</span>
            {{-- No `capture` attribute: it would open the camera directly and hide the photo library. --}}
            <input type="file" id="rw-m-profile-photo" accept="image/*" class="sr-only" x-on:change="pickAvatar($event)">
        </label>

        @error('avatarPhoto') <p class="{{ $error }} text-center">{{ $message }}</p> @enderror
    </section>

    {{-- ── Information ── --}}
    <section aria-labelledby="rw-m-profile-info-label">
        <h2 id="rw-m-profile-info-label" class="{{ $groupLabel }}">{{ __('Information') }}</h2>
        <form wire:submit="saveInfo" class="rw-m-card space-y-4" id="rw-m-profile-info">
            <div>
                <label for="rw-m-profile-name" class="{{ $label }}">{{ __('Full name') }}</label>
                <input type="text" id="rw-m-profile-name" wire:model="name" class="rw-m-input" autocomplete="name" enterkeyhint="next">
                @error('name') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="rw-m-profile-phone" class="{{ $label }}">{{ __('Phone') }}</label>
                <input type="tel" id="rw-m-profile-phone" wire:model="phone_number" class="rw-m-input" autocomplete="tel" inputmode="tel" enterkeyhint="next">
                @error('phone_number') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="rw-m-profile-email" class="{{ $label }}">{{ __('Email') }}</label>
                <input type="email" id="rw-m-profile-email" wire:model="email" class="rw-m-input" autocomplete="email" inputmode="email" autocapitalize="off" enterkeyhint="next">
                @error('email') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="rw-m-profile-gender" class="{{ $label }}">{{ __('Gender') }}</label>
                    <select id="rw-m-profile-gender" wire:model="gender" class="rw-m-input">
                        <option value="">—</option>
                        <option value="male">{{ __('Male') }}</option>
                        <option value="female">{{ __('Female') }}</option>
                        <option value="other">{{ __('Other') }}</option>
                    </select>
                    @error('gender') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="rw-m-profile-dob" class="{{ $label }}">{{ __('Date of birth') }}</label>
                    <input type="date" id="rw-m-profile-dob" wire:model="dob" class="rw-m-input" autocomplete="bday">
                    @error('dob') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="rw-m-profile-nationality" class="{{ $label }}">{{ __('Nationality') }}</label>
                <input type="text" id="rw-m-profile-nationality" wire:model="nationality" class="rw-m-input" placeholder="{{ __('e.g. Khmer, Vietnamese') }}" enterkeyhint="next">
                @error('nationality') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <fieldset class="space-y-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                <legend class="sr-only">{{ __('Location') }}</legend>
                <p class="text-sm font-semibold text-slate-500 dark:text-slate-400" aria-hidden="true">{{ __('Location') }}</p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="rw-m-profile-province" class="{{ $label }}">{{ __('Province / City') }}</label>
                        <input type="text" id="rw-m-profile-province" wire:model="province" class="rw-m-input" enterkeyhint="next">
                        @error('province') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="rw-m-profile-district" class="{{ $label }}">{{ __('District') }}</label>
                        <input type="text" id="rw-m-profile-district" wire:model="district" class="rw-m-input" enterkeyhint="next">
                        @error('district') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="rw-m-profile-commune" class="{{ $label }}">{{ __('Commune') }}</label>
                        <input type="text" id="rw-m-profile-commune" wire:model="commune" class="rw-m-input" enterkeyhint="next">
                        @error('commune') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="rw-m-profile-village" class="{{ $label }}">{{ __('Village') }}</label>
                        <input type="text" id="rw-m-profile-village" wire:model="village" class="rw-m-input" enterkeyhint="done">
                        @error('village') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                </div>
            </fieldset>

            <button type="submit" wire:loading.attr="disabled" wire:target="saveInfo" class="rw-m-btn rw-m-btn-primary w-full" id="rw-m-profile-save-info">
                <span wire:loading.remove wire:target="saveInfo">{{ __('Save') }}</span>
                <span wire:loading wire:target="saveInfo">{{ __('Saving…') }}</span>
            </button>
        </form>
    </section>

    {{-- ── Password ── --}}
    <section aria-labelledby="rw-m-profile-password-label">
        <h2 id="rw-m-profile-password-label" class="{{ $groupLabel }}">{{ __('Password') }}</h2>
        <form wire:submit="savePassword" class="rw-m-card space-y-4" id="rw-m-profile-password">
            <div>
                <label for="rw-m-profile-current-password" class="{{ $label }}">{{ __('Current password') }}</label>
                <input type="password" id="rw-m-profile-current-password" wire:model="current_password" class="rw-m-input" autocomplete="current-password" enterkeyhint="next">
                @error('current_password') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="rw-m-profile-new-password" class="{{ $label }}">{{ __('New password') }}</label>
                <input type="password" id="rw-m-profile-new-password" wire:model="password" class="rw-m-input" autocomplete="new-password" enterkeyhint="next">
                @error('password') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="rw-m-profile-password-confirmation" class="{{ $label }}">{{ __('Confirm new password') }}</label>
                <input type="password" id="rw-m-profile-password-confirmation" wire:model="password_confirmation" class="rw-m-input" autocomplete="new-password" enterkeyhint="done">
            </div>

            <button type="submit" wire:loading.attr="disabled" wire:target="savePassword" class="rw-m-btn rw-m-btn-primary w-full" id="rw-m-profile-save-password">
                <span wire:loading.remove wire:target="savePassword">{{ __('Update password') }}</span>
                <span wire:loading wire:target="savePassword">{{ __('Saving…') }}</span>
            </button>
        </form>
    </section>
</div>
