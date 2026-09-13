<div class="space-y-4" x-data x-on:avatar-updated.window="window.location.reload()">

    {{-- ── Avatar ── --}}
    <div class="rw-sm-panel rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
        <p class="rw-sm-property-label text-xs font-semibold uppercase tracking-widest text-primary-600 dark:text-primary-400 mb-3">{{ __('Photo') }}</p>

        <div class="flex flex-col items-center gap-3">
            <div class="rw-sm-avatar overflow-hidden rounded-full border border-gray-200 dark:border-gray-700 bg-gray-100 dark:bg-gray-800">
                @if($avatarPhoto)
                    <img src="{{ $avatarPhoto->temporaryUrl() }}" class="h-full w-full object-cover" alt="{{ __('Profile photo') }}">
                @elseif($avatarUrl)
                    <img src="{{ $avatarUrl }}" class="h-full w-full object-cover" alt="{{ __('Profile photo') }}">
                @else
                    <div class="flex h-full w-full items-center justify-center text-gray-400 dark:text-gray-500">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-10 w-10"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                @endif
            </div>

            <label for="profile-avatar" class="rw-sm-btn-secondary cursor-pointer text-sm">
                {{ __('Choose photo') }}
                <input type="file" id="profile-avatar" wire:model="avatarPhoto" accept="image/*" capture="user" class="hidden">
            </label>

            <div wire:loading wire:target="avatarPhoto" class="text-xs text-gray-500 dark:text-gray-400">
                {{ __('Uploading…') }}
            </div>

            @error('avatarPhoto') <p class="rw-sm-error">{{ $message }}</p> @enderror

            @if($avatarPhoto)
                <button type="button" wire:click="saveAvatar" wire:loading.attr="disabled" wire:target="saveAvatar" class="rw-sm-btn-primary w-full" id="profile-save-photo">
                    <span wire:loading.remove wire:target="saveAvatar">{{ __('Save photo') }}</span>
                    <span wire:loading wire:target="saveAvatar">{{ __('Saving…') }}</span>
                </button>
            @endif
        </div>
    </div>

    {{-- ── Profile information ── --}}
    <div class="rw-sm-panel rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
        <p class="rw-sm-property-label text-xs font-semibold uppercase tracking-widest text-primary-600 dark:text-primary-400 mb-3">{{ __('Information') }}</p>

        @if($infoSaved)
            <div class="rw-sm-success-banner mb-3" role="status">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>{{ __('Profile updated.') }}</span>
            </div>
        @endif

        <div class="space-y-3">
            <div>
                <label class="rw-sm-label" for="profile-name">{{ __('Full name') }}</label>
                <input type="text" id="profile-name" wire:model="name" class="rw-sm-input">
                @error('name') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="rw-sm-label" for="profile-email">{{ __('Email') }}</label>
                <input type="email" id="profile-email" wire:model="email" class="rw-sm-input">
                @error('email') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="rw-sm-label" for="profile-phone">{{ __('Phone') }}</label>
                <input type="tel" id="profile-phone" wire:model="phone_number" class="rw-sm-input">
                @error('phone_number') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="rw-sm-label" for="profile-gender">{{ __('Gender') }}</label>
                    <select id="profile-gender" wire:model="gender" class="rw-sm-input">
                        <option value="">—</option>
                        <option value="male">{{ __('Male') }}</option>
                        <option value="female">{{ __('Female') }}</option>
                        <option value="other">{{ __('Other') }}</option>
                    </select>
                    @error('gender') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="rw-sm-label" for="profile-dob">{{ __('Date of birth') }}</label>
                    <input type="date" id="profile-dob" wire:model="dob" class="rw-sm-input">
                    @error('dob') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="rw-sm-label" for="profile-nationality">{{ __('Nationality') }}</label>
                <input type="text" id="profile-nationality" wire:model="nationality" class="rw-sm-input" placeholder="{{ __('e.g. Khmer, Vietnamese') }}">
                @error('nationality') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                <p class="rw-sm-label mb-2">{{ __('Location') }}</p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="rw-sm-label" for="profile-province">{{ __('Province / City') }}</label>
                        <input type="text" id="profile-province" wire:model="province" class="rw-sm-input">
                        @error('province') <p class="rw-sm-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="rw-sm-label" for="profile-district">{{ __('District') }}</label>
                        <input type="text" id="profile-district" wire:model="district" class="rw-sm-input">
                        @error('district') <p class="rw-sm-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="rw-sm-label" for="profile-commune">{{ __('Commune') }}</label>
                        <input type="text" id="profile-commune" wire:model="commune" class="rw-sm-input">
                        @error('commune') <p class="rw-sm-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="rw-sm-label" for="profile-village">{{ __('Village') }}</label>
                        <input type="text" id="profile-village" wire:model="village" class="rw-sm-input">
                        @error('village') <p class="rw-sm-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <button type="button" wire:click="saveInfo" wire:loading.attr="disabled" wire:target="saveInfo" class="rw-sm-btn-primary w-full" id="profile-save-info">
                <span wire:loading.remove wire:target="saveInfo">{{ __('Save') }}</span>
                <span wire:loading wire:target="saveInfo">{{ __('Saving…') }}</span>
            </button>
        </div>
    </div>

    {{-- ── Password ── --}}
    <div class="rw-sm-panel rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
        <p class="rw-sm-property-label text-xs font-semibold uppercase tracking-widest text-primary-600 dark:text-primary-400 mb-3">{{ __('Password') }}</p>

        @if($passwordSaved)
            <div class="rw-sm-success-banner mb-3" role="status">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>{{ __('Password updated.') }}</span>
            </div>
        @endif

        <div class="space-y-3">
            <div>
                <label class="rw-sm-label" for="profile-current-password">{{ __('Current password') }}</label>
                <input type="password" id="profile-current-password" wire:model="current_password" class="rw-sm-input" autocomplete="current-password">
                @error('current_password') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="rw-sm-label" for="profile-password">{{ __('New password') }}</label>
                <input type="password" id="profile-password" wire:model="password" class="rw-sm-input" autocomplete="new-password">
                @error('password') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="rw-sm-label" for="profile-password-confirmation">{{ __('Confirm new password') }}</label>
                <input type="password" id="profile-password-confirmation" wire:model="password_confirmation" class="rw-sm-input" autocomplete="new-password">
            </div>

            <button type="button" wire:click="savePassword" wire:loading.attr="disabled" wire:target="savePassword" class="rw-sm-btn-primary w-full" id="profile-save-password">
                <span wire:loading.remove wire:target="savePassword">{{ __('Update password') }}</span>
                <span wire:loading wire:target="savePassword">{{ __('Saving…') }}</span>
            </button>
        </div>
    </div>

</div>
