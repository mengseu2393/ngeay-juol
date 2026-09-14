<div class="rw-sm-form space-y-4">

    @if($saved)
        <div class="rw-sm-result-card">
            <div class="rw-sm-result-icon">✅</div>
            <h3 class="rw-sm-result-title">{{ __('Saved') }}</h3>
        </div>
    @else
        <div class="space-y-4">
            <div>
                <label class="rw-sm-label" for="et-name">{{ __('Full name') }} <span class="text-red-500">*</span></label>
                <input type="text" id="et-name" wire:model="occupantName" class="rw-sm-input" placeholder="{{ __('Tenant full name') }}" autocomplete="name">
                @error('occupantName') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="rw-sm-label" for="et-phone">{{ __('Phone') }}</label>
                <input type="tel" id="et-phone" wire:model="occupantPhone" class="rw-sm-input" placeholder="012 345 678" autocomplete="tel">
                @error('occupantPhone') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="rw-sm-label" for="et-id-card">{{ __('ID card') }}</label>
                    <input type="text" id="et-id-card" wire:model="occupantIdCard" class="rw-sm-input" placeholder="{{ __('ID card number') }}">
                    @error('occupantIdCard') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="rw-sm-label" for="et-gender">{{ __('Gender') }}</label>
                    <select id="et-gender" wire:model="occupantGender" class="rw-sm-input">
                        <option value="">{{ __('Select gender') }}</option>
                        <option value="male">{{ __('Male') }}</option>
                        <option value="female">{{ __('Female') }}</option>
                        <option value="other">{{ __('Other') }}</option>
                    </select>
                    @error('occupantGender') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="rw-sm-label" for="et-dob">{{ __('Date of birth') }}</label>
                    <input type="date" id="et-dob" wire:model="occupantDob" class="rw-sm-input">
                    @error('occupantDob') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="rw-sm-label" for="et-nationality">{{ __('Nationality') }}</label>
                    <input type="text" id="et-nationality" wire:model="occupantNationality" class="rw-sm-input" placeholder="{{ __('e.g. Khmer, Vietnamese') }}">
                    @error('occupantNationality') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="rw-sm-label" for="et-workplace">{{ __('Workplace') }}</label>
                <input type="text" id="et-workplace" wire:model="occupantWorkplace" class="rw-sm-input" placeholder="{{ __('e.g. company name') }}">
                @error('occupantWorkplace') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="rw-sm-label" for="et-address">{{ __('Address') }}</label>
                <textarea id="et-address" wire:model="occupantAddress" class="rw-sm-input" rows="2"></textarea>
                @error('occupantAddress') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="rw-sm-label" for="et-id-photos">{{ __('ID card photos') }}</label>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">{{ __('Front/back of national ID, passport, etc.') }}</p>

                <div class="flex flex-wrap justify-center gap-2">
                    @foreach($idCardPhotos as $index => $photo)
                        <div class="relative h-20 w-20 shrink-0 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                            <img src="{{ $photo->temporaryUrl() }}" class="h-full w-full object-cover" alt="{{ __('ID card photo') }}">
                            <button
                                type="button"
                                wire:click="removeIdCardPhoto({{ $index }})"
                                class="absolute top-0.5 right-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-black/60 text-white"
                                id="et-id-photo-remove-{{ $index }}"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3" aria-hidden="true">
                                    <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                                </svg>
                            </button>
                        </div>
                    @endforeach

                    @if(count($idCardPhotos) < 2)
                        <label for="et-id-photos" class="flex h-20 w-20 shrink-0 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600 text-gray-400 dark:text-gray-500">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z"/></svg>
                            <span class="text-[0.65rem]">{{ __('Add photo') }}</span>
                        </label>
                        <input
                            type="file"
                            id="et-id-photos"
                            wire:model="idCardPhotos"
                            accept="image/*"
                            capture="environment"
                            multiple
                            class="hidden"
                        >
                    @endif
                </div>

                <div wire:loading wire:target="idCardPhotos" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Uploading…') }}
                </div>

                @error('idCardPhotos') <p class="rw-sm-error">{{ $message }}</p> @enderror
                @error('idCardPhotos.*') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                <p class="rw-sm-label mb-2">{{ __('Emergency contact') }}</p>
                <div class="space-y-3">
                    <div>
                        <label class="rw-sm-label" for="et-ec-name">{{ __('Name') }}</label>
                        <input type="text" id="et-ec-name" wire:model="emergencyContactName" class="rw-sm-input">
                        @error('emergencyContactName') <p class="rw-sm-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="rw-sm-label" for="et-ec-phone">{{ __('Phone') }}</label>
                            <input type="tel" id="et-ec-phone" wire:model="emergencyContactPhone" class="rw-sm-input">
                            @error('emergencyContactPhone') <p class="rw-sm-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="rw-sm-label" for="et-ec-relationship">{{ __('Relationship') }}</label>
                            <input type="text" id="et-ec-relationship" wire:model="emergencyContactRelationship" class="rw-sm-input">
                            @error('emergencyContactRelationship') <p class="rw-sm-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="rw-sm-label" for="et-rent">{{ __('Monthly rent') }} <span class="text-red-500">*</span></label>
                    <input type="number" id="et-rent" wire:model="monthlyRent" class="rw-sm-input" step="0.01" min="0" placeholder="0.00">
                    @error('monthlyRent') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="rw-sm-label" for="et-deposit">{{ __('Security deposit') }}</label>
                    <input type="number" id="et-deposit" wire:model="securityDeposit" class="rw-sm-input" step="0.01" min="0" placeholder="0.00">
                    @error('securityDeposit') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex gap-3">
                {{-- Parents own the popup — they close it on this event --}}
                <button type="button" @click="$dispatch('tenant-edit-cancel')" class="rw-sm-btn-secondary flex-1" id="edit-tenant-cancel">
                    {{ __('Cancel') }}
                </button>
                <button wire:click="submit" class="rw-sm-btn-primary flex-1" id="edit-tenant-submit">
                    {{ __('Save') }}
                </button>
            </div>
        </div>
    @endif
</div>
