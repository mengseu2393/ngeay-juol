<div class="space-y-4">

    {{-- ── Step: pick vacant room ── --}}
    @if($step === 'pick')
        @if($vacantRooms->isEmpty())
            <div class="rw-sm-empty-state rounded-2xl border border-dashed border-gray-200 dark:border-gray-700 p-8 text-center">
                <div class="mb-2 text-3xl">🚪</div>
                <p class="font-semibold text-gray-700 dark:text-gray-300">{{ __('No vacant rooms') }}</p>
                <p class="mt-1 text-sm text-gray-500">{{ __('All rooms in this property are occupied or unavailable.') }}</p>
            </div>
        @else
            <p class="rw-sm-step-hint">{{ __('Pick a vacant room') }}</p>
            <div class="space-y-2">
                @foreach($vacantRooms as $room)
                    <button
                        wire:click="pickRoom({{ $room->id }})"
                        class="rw-sm-room-row w-full text-left"
                        id="pick-room-{{ $room->id }}"
                    >
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="rw-sm-room-number">{{ $room->room_number }}</p>
                                @if($room->rent_amount)
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ \App\Support\Money::format($room->rent_amount, $room->property?->currency) }}
                                        / {{ __('month') }}
                                    </p>
                                @endif
                            </div>
                            <span class="rw-sm-badge rw-sm-badge-success shrink-0">{{ __('Available') }}</span>
                        </div>
                    </button>
                @endforeach
            </div>
        @endif

    {{-- ── Step: enter details ── --}}
    @elseif($step === 'details')
        <div class="rw-sm-form space-y-4">
        <div class="rw-sm-wizard-header">
            <button wire:click="backToPick" class="rw-sm-back-btn-sm" id="add-tenant-back">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                {{ __('Change room') }}
            </button>
        </div>

        @if($selectedUnit)
            <div class="rw-sm-selected-room">
                <span class="rw-sm-room-number">{{ $selectedUnit->room_number }}</span>
            </div>
        @endif

        <div class="space-y-4">
            <div>
                <label class="rw-sm-label" for="at-name">{{ __('Full name') }} <span class="text-red-500">*</span></label>
                <input type="text" id="at-name" wire:model="occupantName" class="rw-sm-input" placeholder="{{ __('Tenant full name') }}" autocomplete="name">
                @error('occupantName') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="rw-sm-label" for="at-phone">{{ __('Phone') }}</label>
                <input type="tel" id="at-phone" wire:model="occupantPhone" class="rw-sm-input" placeholder="012 345 678" autocomplete="tel">
                @error('occupantPhone') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="rw-sm-label" for="at-id-card">{{ __('ID card') }}</label>
                    <input type="text" id="at-id-card" wire:model="occupantIdCard" class="rw-sm-input" placeholder="{{ __('ID card number') }}">
                    @error('occupantIdCard') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="rw-sm-label" for="at-gender">{{ __('Gender') }}</label>
                    <select id="at-gender" wire:model="occupantGender" class="rw-sm-input">
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
                    <label class="rw-sm-label" for="at-dob">{{ __('Date of birth') }}</label>
                    <input type="date" id="at-dob" wire:model="occupantDob" class="rw-sm-input">
                    @error('occupantDob') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="rw-sm-label" for="at-nationality">{{ __('Nationality') }}</label>
                    <input type="text" id="at-nationality" wire:model="occupantNationality" class="rw-sm-input" placeholder="{{ __('e.g. Khmer, Vietnamese') }}">
                    @error('occupantNationality') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="rw-sm-label" for="at-workplace">{{ __('Workplace') }}</label>
                <input type="text" id="at-workplace" wire:model="occupantWorkplace" class="rw-sm-input" placeholder="{{ __('e.g. company name') }}">
                @error('occupantWorkplace') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="rw-sm-label" for="at-address">{{ __('Address') }}</label>
                <textarea id="at-address" wire:model="occupantAddress" class="rw-sm-input" rows="2"></textarea>
                @error('occupantAddress') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="rw-sm-label" for="at-id-photos">{{ __('ID card photos') }}</label>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">{{ __('Front/back of national ID, passport, etc.') }}</p>

                <div class="flex flex-wrap justify-center gap-2">
                    @foreach($idCardPhotos as $index => $photo)
                        <div class="relative h-20 w-20 shrink-0 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                            <img src="{{ $photo->temporaryUrl() }}" class="h-full w-full object-cover" alt="{{ __('ID card photo') }}">
                            <button
                                type="button"
                                wire:click="removeIdCardPhoto({{ $index }})"
                                class="absolute top-0.5 right-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-black/60 text-white"
                                id="at-id-photo-remove-{{ $index }}"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3" aria-hidden="true">
                                    <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                                </svg>
                            </button>
                        </div>
                    @endforeach

                    @if(count($idCardPhotos) < 2)
                        <label for="at-id-photos" class="flex h-20 w-20 shrink-0 cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed border-gray-300 dark:border-gray-600 text-gray-400 dark:text-gray-500">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z"/></svg>
                            <span class="text-[0.65rem]">{{ __('Add photo') }}</span>
                        </label>
                        <input
                            type="file"
                            id="at-id-photos"
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
                        <label class="rw-sm-label" for="at-ec-name">{{ __('Name') }}</label>
                        <input type="text" id="at-ec-name" wire:model="emergencyContactName" class="rw-sm-input">
                        @error('emergencyContactName') <p class="rw-sm-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="rw-sm-label" for="at-ec-phone">{{ __('Phone') }}</label>
                            <input type="tel" id="at-ec-phone" wire:model="emergencyContactPhone" class="rw-sm-input">
                            @error('emergencyContactPhone') <p class="rw-sm-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="rw-sm-label" for="at-ec-relationship">{{ __('Relationship') }}</label>
                            <input type="text" id="at-ec-relationship" wire:model="emergencyContactRelationship" class="rw-sm-input">
                            @error('emergencyContactRelationship') <p class="rw-sm-error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <label class="rw-sm-label" for="at-start">{{ __('Start date') }} <span class="text-red-500">*</span></label>
                <input type="date" id="at-start" wire:model="startDate" class="rw-sm-input">
                @error('startDate') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="rw-sm-label" for="at-rent">{{ __('Monthly rent') }} <span class="text-red-500">*</span></label>
                    <input type="number" id="at-rent" wire:model="monthlyRent" class="rw-sm-input" step="0.01" min="0" placeholder="0.00">
                    @error('monthlyRent') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="rw-sm-label" for="at-deposit">{{ __('Security deposit') }}</label>
                    <input type="number" id="at-deposit" wire:model="securityDeposit" class="rw-sm-input" step="0.01" min="0" placeholder="0.00">
                    @error('securityDeposit') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>
            </div>

            @error('unitId')
                <div class="rw-sm-error-banner">{{ $message }}</div>
            @enderror

            <button wire:click="submit" class="rw-sm-btn-primary w-full" id="add-tenant-submit">
                {{ __('Confirm') }}
            </button>

            <p class="text-xs text-center text-gray-400 dark:text-gray-500">
                {{ __('Guarantor details are available in') }}
                <a href="{{ \App\Filament\Resources\RentalResource::getUrl('create', ['from' => 'simple'], panel: 'landlord') }}" class="underline text-primary-600 dark:text-primary-400">{{ __('Full Mode') }}</a>.
            </p>
        </div>
        </div>

    {{-- ── Step: done ── --}}
    @elseif($step === 'done')
        <div class="rw-sm-result-card">
            <div class="rw-sm-result-icon">✅</div>
            <h3 class="rw-sm-result-title">{{ __('Tenancy created') }}</h3>

            <div class="mt-4 space-y-2 text-left">
                <div class="rw-sm-result-row">
                    <span>{{ __('Room') }}</span>
                    <strong>{{ $result['room_number'] }}</strong>
                </div>
                <div class="rw-sm-result-row">
                    <span>{{ __('Tenant') }}</span>
                    <strong>{{ $result['occupant_name'] }}</strong>
                </div>
                @if($result['username'])
                    <div class="rw-sm-result-row">
                        <span>{{ __('Login username') }}</span>
                        <strong class="font-mono">{{ $result['username'] }}</strong>
                    </div>
                @endif
                @if($result['password'])
                    <div class="rw-sm-result-row">
                        <span>{{ __('Login password') }}</span>
                        <strong class="font-mono">{{ $result['password'] }}</strong>
                    </div>
                    <p class="text-xs text-amber-600 dark:text-amber-400">{{ __('Save this password now — it is shown only once.') }}</p>
                @endif
            </div>

            <button wire:click="reset_form" class="rw-sm-btn-primary mt-5 w-full" id="add-tenant-again">
                {{ __('Add another tenant') }}
            </button>
        </div>
    @endif
</div>
