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

            <div>
                <label class="rw-sm-label" for="at-start">{{ __('Start date') }} <span class="text-red-500">*</span></label>
                <input type="date" id="at-start" wire:model="startDate" class="rw-sm-input">
                @error('startDate') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="rw-sm-label" for="at-rent">{{ __('Monthly rent') }} <span class="text-red-500">*</span></label>
                <input type="number" id="at-rent" wire:model="monthlyRent" class="rw-sm-input" step="0.01" min="0" placeholder="0.00">
                @error('monthlyRent') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            @error('unitId')
                <div class="rw-sm-error-banner">{{ $message }}</div>
            @enderror

            <button wire:click="submit" class="rw-sm-btn-primary w-full" id="add-tenant-submit">
                {{ __('Confirm') }}
            </button>

            <p class="text-xs text-center text-gray-400 dark:text-gray-500">
                {{ __('Advanced fields (ID card, guarantor, etc.) are available in') }}
                <a href="{{ \App\Filament\Resources\RentalResource::getUrl('create', panel: 'landlord') }}" class="underline text-primary-600 dark:text-primary-400">{{ __('Full Mode') }}</a>.
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
