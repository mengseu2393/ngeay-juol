{{-- The "Edit tenant" sheet's form (App\Livewire\Mobile\TenantEdit), inside livewire.mobile.tenant. --}}
@php
    $label = 'mb-1 block text-sm font-semibold';
    $error = 'mt-1 text-sm text-red-600 dark:text-red-400';
@endphp

<form
    x-data="rwMobileTenantEdit(@js(__('Something went wrong. Please try again.')))"
    x-on:rw-m-sheet-closed.window="$event.detail.name === 'tenant-edit' && $wire.discard()"
    wire:submit="submit"
    class="space-y-4 px-5 pt-2"
    novalidate
>
    <h2 class="text-lg font-bold">{{ __('Edit tenant') }}</h2>

    <div>
        <label class="{{ $label }}" for="rw-m-et-name">{{ __('Full name') }} <span class="text-red-500">*</span></label>
        <input type="text" id="rw-m-et-name" wire:model="occupantName" class="rw-m-input" placeholder="{{ __('Tenant full name') }}" autocomplete="name" enterkeyhint="next" required>
        @error('occupantName') <p class="{{ $error }}">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="{{ $label }}" for="rw-m-et-phone">{{ __('Phone') }}</label>
        <input type="tel" id="rw-m-et-phone" wire:model="occupantPhone" class="rw-m-input" placeholder="012 345 678" inputmode="tel" autocomplete="tel" enterkeyhint="next">
        @error('occupantPhone') <p class="{{ $error }}">{{ $message }}</p> @enderror
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label class="{{ $label }}" for="rw-m-et-id-card">{{ __('ID card') }}</label>
            <input type="text" id="rw-m-et-id-card" wire:model="occupantIdCard" class="rw-m-input" placeholder="{{ __('ID card number') }}" autocomplete="off" enterkeyhint="next">
            @error('occupantIdCard') <p class="{{ $error }}">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="{{ $label }}" for="rw-m-et-gender">{{ __('Gender') }}</label>
            <select id="rw-m-et-gender" wire:model="occupantGender" class="rw-m-input">
                <option value="">{{ __('Select gender') }}</option>
                <option value="male">{{ __('Male') }}</option>
                <option value="female">{{ __('Female') }}</option>
                <option value="other">{{ __('Other') }}</option>
            </select>
            @error('occupantGender') <p class="{{ $error }}">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3">
        <div>
            <label class="{{ $label }}" for="rw-m-et-dob">{{ __('Date of birth') }}</label>
            <input type="date" id="rw-m-et-dob" wire:model="occupantDob" class="rw-m-input" max="{{ now()->toDateString() }}" autocomplete="bday">
            @error('occupantDob') <p class="{{ $error }}">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="{{ $label }}" for="rw-m-et-nationality">{{ __('Nationality') }}</label>
            <input type="text" id="rw-m-et-nationality" wire:model="occupantNationality" class="rw-m-input" placeholder="{{ __('e.g. Khmer, Vietnamese') }}" autocomplete="off" enterkeyhint="next">
            @error('occupantNationality') <p class="{{ $error }}">{{ $message }}</p> @enderror
        </div>
    </div>

    <div>
        <label class="{{ $label }}" for="rw-m-et-workplace">{{ __('Workplace') }}</label>
        <input type="text" id="rw-m-et-workplace" wire:model="occupantWorkplace" class="rw-m-input" placeholder="{{ __('e.g. company name') }}" autocomplete="organization" enterkeyhint="next">
        @error('occupantWorkplace') <p class="{{ $error }}">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="{{ $label }}" for="rw-m-et-address">{{ __('Address') }}</label>
        <textarea id="rw-m-et-address" wire:model="occupantAddress" class="rw-m-input" rows="2" autocomplete="street-address"></textarea>
        @error('occupantAddress') <p class="{{ $error }}">{{ $message }}</p> @enderror
    </div>

    {{-- ── ID card photos: the ones on file (removable) + new ones, shrunk in the browser before upload ── --}}
    <div>
        <p class="{{ $label }}">{{ __('ID card photos') }}</p>
        <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">{{ __('Front/back of national ID, passport, etc.') }}</p>

        <div class="flex flex-wrap gap-2">
            @foreach ($savedPhotos as $media)
                <div class="relative size-20 shrink-0 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700" wire:key="rw-m-et-saved-{{ $media->id }}">
                    <img src="{{ $media->getUrl() }}" class="size-full object-cover" alt="{{ __('ID card photo') }}" loading="lazy">
                    <button
                        type="button"
                        wire:click="removeSavedPhoto({{ $media->id }})"
                        wire:confirm="{{ __('Remove this photo?') }}"
                        class="absolute top-0 right-0 flex size-8 items-center justify-center text-white"
                        aria-label="{{ __('Remove photo') }}"
                        id="rw-m-et-saved-remove-{{ $media->id }}"
                    >
                        <span class="flex size-6 items-center justify-center rounded-full bg-black/60">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-3.5" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" /></svg>
                        </span>
                    </button>
                </div>
            @endforeach

            @foreach ($idCardPhotos as $index => $photo)
                <div class="relative size-20 shrink-0 overflow-hidden rounded-xl border-2 border-brand-500" wire:key="rw-m-et-new-{{ $index }}">
                    @if ($photo->isPreviewable())
                        <img src="{{ $photo->temporaryUrl() }}" class="size-full object-cover" alt="{{ __('ID card photo') }}">
                    @else
                        <span class="flex size-full items-center justify-center p-1 text-center text-[0.65rem] break-all text-slate-500">{{ $photo->getClientOriginalName() }}</span>
                    @endif
                    <button
                        type="button"
                        wire:click="removeIdCardPhoto({{ $index }})"
                        class="absolute top-0 right-0 flex size-8 items-center justify-center text-white"
                        aria-label="{{ __('Remove photo') }}"
                        id="rw-m-et-new-remove-{{ $index }}"
                    >
                        <span class="flex size-6 items-center justify-center rounded-full bg-black/60">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-3.5" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" /></svg>
                        </span>
                    </button>
                </div>
            @endforeach

            @if (count($idCardPhotos) < 2)
                <label
                    for="rw-m-et-photos"
                    class="flex size-20 shrink-0 cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-slate-300 text-slate-400 transition active:scale-95 active:bg-slate-50 dark:border-slate-600 dark:text-slate-500 dark:active:bg-slate-800"
                    x-bind:class="uploading && 'pointer-events-none opacity-60'"
                >
                    <svg x-show="! uploading" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" /></svg>
                    <svg x-show="uploading" x-cloak class="size-6 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"></path></svg>
                    <span class="text-[0.65rem] font-semibold" x-text="uploading ? @js(__('Uploading…')) : @js(__('Add photo'))">{{ __('Add photo') }}</span>
                </label>
                {{-- Not wire:model: Alpine shrinks the photos first, then uploads (appending). --}}
                <input type="file" id="rw-m-et-photos" accept="image/*" multiple class="hidden" x-on:change="pickPhotos($event)">
            @endif
        </div>

        @error('idCardPhotos') <p class="{{ $error }}">{{ $message }}</p> @enderror
        @error('idCardPhotos.*') <p class="{{ $error }}">{{ $message }}</p> @enderror
    </div>

    <fieldset class="space-y-3 border-t border-slate-200 pt-4 dark:border-slate-800">
        <legend class="sr-only">{{ __('Emergency contact') }}</legend>
        <p class="text-sm font-bold">{{ __('Emergency contact') }}</p>
        <div>
            <label class="{{ $label }}" for="rw-m-et-ec-name">{{ __('Name') }}</label>
            <input type="text" id="rw-m-et-ec-name" wire:model="emergencyContactName" class="rw-m-input" autocomplete="off" enterkeyhint="next">
            @error('emergencyContactName') <p class="{{ $error }}">{{ $message }}</p> @enderror
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="{{ $label }}" for="rw-m-et-ec-phone">{{ __('Phone') }}</label>
                <input type="tel" id="rw-m-et-ec-phone" wire:model="emergencyContactPhone" class="rw-m-input" inputmode="tel" autocomplete="off" enterkeyhint="next">
                @error('emergencyContactPhone') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $label }}" for="rw-m-et-ec-relationship">{{ __('Relationship') }}</label>
                <input type="text" id="rw-m-et-ec-relationship" wire:model="emergencyContactRelationship" class="rw-m-input" placeholder="{{ __('e.g. mother, brother') }}" autocomplete="off" enterkeyhint="next">
                @error('emergencyContactRelationship') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
        </div>
    </fieldset>

    <div class="grid grid-cols-2 gap-3 border-t border-slate-200 pt-4 dark:border-slate-800">
        <div>
            <label class="{{ $label }}" for="rw-m-et-rent">{{ __('Monthly rent') }} <span class="text-red-500">*</span></label>
            <input type="text" inputmode="decimal" id="rw-m-et-rent" wire:model="monthlyRent" class="rw-m-input" placeholder="0.00" autocomplete="off" enterkeyhint="next">
            @error('monthlyRent') <p class="{{ $error }}">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="{{ $label }}" for="rw-m-et-deposit">{{ __('Security deposit') }}</label>
            <input type="text" inputmode="decimal" id="rw-m-et-deposit" wire:model="securityDeposit" class="rw-m-input" placeholder="0.00" autocomplete="off" enterkeyhint="done">
            @error('securityDeposit') <p class="{{ $error }}">{{ $message }}</p> @enderror
        </div>
    </div>

    <div class="grid grid-cols-2 gap-2 pt-1">
        <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'tenant-edit' })" class="rw-m-btn rw-m-btn-secondary" id="rw-m-et-cancel">{{ __('Cancel') }}</button>
        <button type="submit" class="rw-m-btn rw-m-btn-primary" wire:loading.attr="disabled" wire:target="submit,idCardPhotos" x-bind:disabled="uploading" id="rw-m-et-save">
            <span wire:loading.remove wire:target="submit">{{ __('Save') }}</span>
            <span wire:loading wire:target="submit">{{ __('Saving…') }}</span>
        </button>
    </div>
</form>
