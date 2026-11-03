<div id="m2-profile" class="m2a" x-data="{
    uploading: false,
    failed: false,
    async pick(event) {
        const input = event.target;
        const file = input.files && input.files[0];
        if (! file) { return; }
        this.uploading = true;
        this.failed = false;
        const upload = await window.m2ResizeImage(file, 800);
        this.$wire.upload('avatarPhoto', upload, () => { this.uploading = false; }, () => { this.uploading = false; this.failed = true; });
        input.value = '';
    },
}">
    <x-m2.header :title="__('Profile')" :subtitle="$name" :back="route('m2.settings')" :profile="false" :avatar="$avatarUrl" :initial="$name" />

    <main class="m2-wrap m2a-main">
        {{-- Photo: picking one saves it straight away (SimpleProfile::updatedAvatarPhoto) --}}
        <section style="text-align:center;margin-top:8px" aria-label="{{ __('Photo') }}">
            <span class="m2-avatar is-lg" style="margin:0 auto;width:96px;height:96px;font-size:34px" id="m2-profile-avatar">
                @if ($avatarPhoto)<img src="{{ $avatarPhoto->temporaryUrl() }}" alt="{{ __('Profile photo') }}">
                @elseif ($avatarUrl)<img src="{{ $avatarUrl }}" alt="{{ __('Profile photo') }}">
                @else{{ mb_strtoupper(mb_substr($name ?: '?', 0, 1)) }}@endif
            </span>
            <label for="m2-profile-photo" class="m2-btn is-ghost m2a-photo-btn" x-bind:class="{ 'is-busy': uploading }">
                <span x-show="! uploading">{{ __('Choose photo') }}</span>
                <span x-show="uploading" x-cloak>{{ __('Uploading…') }}</span>
                {{-- No `capture`: it would open the camera directly and hide the photo library. --}}
                <input type="file" id="m2-profile-photo" accept="image/*" x-on:change="pick($event)" style="position:absolute;width:1px;height:1px;opacity:0">
            </label>
            <p class="m2-err" x-show="failed" x-cloak id="m2-profile-photo-failed">{{ __('The photo could not be uploaded. Try another one.') }}</p>
            @error('avatarPhoto') <p class="m2-err">{{ $message }}</p> @enderror
        </section>

        @if ($infoSaved)<div class="m2-notice is-ok" role="status" id="m2-profile-info-saved">{{ __('Profile updated.') }}</div>@endif

        <h2 class="m2a-h">{{ __('Information') }}</h2>
        <form wire:submit="saveInfo" class="m2a-card" id="m2-profile-info">
            <div>
                <label for="m2-profile-name" class="m2-label">{{ __('Full name') }}</label>
                <input type="text" id="m2-profile-name" wire:model="name" class="m2-input" autocomplete="name">
                @error('name') <p class="m2-err">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="m2-profile-phone" class="m2-label">{{ __('Phone') }}</label>
                <input type="tel" id="m2-profile-phone" wire:model="phone_number" class="m2-input" autocomplete="tel" inputmode="tel">
                @error('phone_number') <p class="m2-err">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="m2-profile-email" class="m2-label">{{ __('Email') }}</label>
                <input type="email" id="m2-profile-email" wire:model="email" class="m2-input" autocomplete="email" inputmode="email" autocapitalize="off">
                @error('email') <p class="m2-err">{{ $message }}</p> @enderror
            </div>
            <div class="m2-grid2">
                <div>
                    <label for="m2-profile-gender" class="m2-label">{{ __('Gender') }}</label>
                    <select id="m2-profile-gender" wire:model="gender" class="m2-input">
                        <option value="">—</option>
                        <option value="male">{{ __('Male') }}</option>
                        <option value="female">{{ __('Female') }}</option>
                        <option value="other">{{ __('Other') }}</option>
                    </select>
                    @error('gender') <p class="m2-err">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="m2-profile-dob" class="m2-label">{{ __('Date of birth') }}</label>
                    <input type="date" id="m2-profile-dob" wire:model="dob" class="m2-input" autocomplete="bday">
                    @error('dob') <p class="m2-err">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label for="m2-profile-nationality" class="m2-label">{{ __('Nationality') }}</label>
                <input type="text" id="m2-profile-nationality" wire:model="nationality" class="m2-input" placeholder="{{ __('e.g. Khmer, Vietnamese') }}">
                @error('nationality') <p class="m2-err">{{ $message }}</p> @enderror
            </div>
            <div class="m2-grid2">
                <div>
                    <label for="m2-profile-province" class="m2-label">{{ __('Province / City') }}</label>
                    <input type="text" id="m2-profile-province" wire:model="province" class="m2-input">
                </div>
                <div>
                    <label for="m2-profile-district" class="m2-label">{{ __('District') }}</label>
                    <input type="text" id="m2-profile-district" wire:model="district" class="m2-input">
                </div>
                <div>
                    <label for="m2-profile-commune" class="m2-label">{{ __('Commune') }}</label>
                    <input type="text" id="m2-profile-commune" wire:model="commune" class="m2-input">
                </div>
                <div>
                    <label for="m2-profile-village" class="m2-label">{{ __('Village') }}</label>
                    <input type="text" id="m2-profile-village" wire:model="village" class="m2-input">
                </div>
            </div>
            <button type="submit" class="m2-btn" wire:loading.attr="disabled" wire:target="saveInfo" id="m2-profile-save-info">
                <span wire:loading.remove wire:target="saveInfo">{{ __('Save') }}</span>
                <span wire:loading wire:target="saveInfo">{{ __('Saving…') }}</span>
            </button>
        </form>

        @if ($passwordSaved)<div class="m2-notice is-ok" role="status" id="m2-profile-password-saved">{{ __('Password updated.') }}</div>@endif

        <h2 class="m2a-h">{{ __('Password') }}</h2>
        <form wire:submit="savePassword" class="m2a-card" id="m2-profile-password">
            <div>
                <label for="m2-profile-current-password" class="m2-label">{{ __('Current password') }}</label>
                <input type="password" id="m2-profile-current-password" wire:model="current_password" class="m2-input" autocomplete="current-password">
                @error('current_password') <p class="m2-err">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="m2-profile-new-password" class="m2-label">{{ __('New password') }}</label>
                <input type="password" id="m2-profile-new-password" wire:model="password" class="m2-input" autocomplete="new-password">
                @error('password') <p class="m2-err">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="m2-profile-password-confirmation" class="m2-label">{{ __('Confirm new password') }}</label>
                <input type="password" id="m2-profile-password-confirmation" wire:model="password_confirmation" class="m2-input" autocomplete="new-password">
            </div>
            <button type="submit" class="m2-btn" wire:loading.attr="disabled" wire:target="savePassword" id="m2-profile-save-password">
                <span wire:loading.remove wire:target="savePassword">{{ __('Update password') }}</span>
                <span wire:loading wire:target="savePassword">{{ __('Saving…') }}</span>
            </button>
        </form>
    </main>
</div>
