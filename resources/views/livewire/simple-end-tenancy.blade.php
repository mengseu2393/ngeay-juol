<div class="space-y-4">

    {{-- ── Step: pick occupied room ── --}}
    @if($step === 'pick')
        @if($occupiedRooms->isEmpty())
            <div class="rw-sm-empty-state rounded-2xl border border-dashed border-gray-200 dark:border-gray-700 p-8 text-center">
                <div class="mb-2 text-3xl">🏠</div>
                <p class="font-semibold text-gray-700 dark:text-gray-300">{{ __('No occupied rooms') }}</p>
                <p class="mt-1 text-sm text-gray-500">{{ __('There are no active tenancies in this property.') }}</p>
            </div>
        @else
            <p class="rw-sm-step-hint">{{ __('Pick an occupied room') }}</p>
            <div class="space-y-2">
                @foreach($occupiedRooms as $room)
                    @php
                        $tenantName = $room->activeRental?->occupant_name ?: ($room->activeRental?->tenant?->name ?? '—');
                    @endphp
                    <button
                        wire:click="pickRoom({{ $room->id }})"
                        class="rw-sm-room-row w-full text-left"
                        id="end-pick-room-{{ $room->id }}"
                    >
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="rw-sm-room-number">{{ $room->room_number }}</p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $tenantName }}</p>
                            </div>
                            <span class="rw-sm-badge rw-sm-badge-info shrink-0">{{ __('Occupied') }}</span>
                        </div>
                    </button>
                @endforeach
            </div>
        @endif

    {{-- ── Step: confirm ── --}}
    @elseif($step === 'confirm')
        <div class="rw-sm-form space-y-4">
        <div class="rw-sm-wizard-header">
            <button wire:click="backToPick" class="rw-sm-back-btn-sm" id="end-tenancy-back">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                {{ __('Change room') }}
            </button>
        </div>

        @if($selectedUnit)
            @php
                $tenantName = $selectedUnit->activeRental?->occupant_name ?: ($selectedUnit->activeRental?->tenant?->name ?? '—');
            @endphp
            <div class="rw-sm-selected-room">
                <span class="rw-sm-room-number">{{ $selectedUnit->room_number }}</span>
                <span class="text-gray-600 dark:text-gray-400 text-sm">{{ $tenantName }}</span>
            </div>
        @endif

        <div class="space-y-4">
            <div>
                <label class="rw-sm-label" for="et-date">{{ __('End date') }} <span class="text-red-500">*</span></label>
                <input type="date" id="et-date" wire:model="endDate" class="rw-sm-input">
                @error('endDate') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="rw-sm-label" for="et-status">{{ __('Outcome') }} <span class="text-red-500">*</span></label>
                <select id="et-status" wire:model="status" class="rw-sm-input">
                    @foreach($outcomes as $val => $label)
                        <option value="{{ $val }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('status') <p class="rw-sm-error">{{ $message }}</p> @enderror
            </div>

            <div class="rw-sm-toggle-row">
                <label class="rw-sm-label" for="et-free">{{ __('Mark room as available') }}</label>
                <input type="checkbox" id="et-free" wire:model="freeRoom" class="rw-sm-checkbox">
            </div>

            @error('unitId')
                <div class="rw-sm-error-banner">{{ $message }}</div>
            @enderror

            <button wire:click="submit" class="rw-sm-btn-warning w-full" id="end-tenancy-submit">
                {{ __('End tenancy') }}
            </button>
        </div>
        </div>

    {{-- ── Step: done ── --}}
    @elseif($step === 'done')
        <div class="rw-sm-result-card">
            <div class="rw-sm-result-icon">✅</div>
            <h3 class="rw-sm-result-title">{{ __('Tenancy ended') }}</h3>

            <div class="mt-4 space-y-2 text-left">
                <div class="rw-sm-result-row">
                    <span>{{ __('Room') }}</span>
                    <strong>{{ $result['room_number'] }}</strong>
                </div>
                <div class="rw-sm-result-row">
                    <span>{{ __('Tenant') }}</span>
                    <strong>{{ $result['tenant_name'] }}</strong>
                </div>
                <div class="rw-sm-result-row">
                    <span>{{ __('Outcome') }}</span>
                    <strong>{{ $result['rental_status'] }}</strong>
                </div>
                <div class="rw-sm-result-row">
                    <span>{{ __('Room status') }}</span>
                    <strong>{{ $result['unit_status'] }}</strong>
                </div>
            </div>

            <button wire:click="reset_form" class="rw-sm-btn-primary mt-5 w-full" id="end-tenancy-again">
                {{ __('End another tenancy') }}
            </button>

            <div class="mt-3 text-center">
                <a href="{{ \App\Filament\Resources\RentalResource::getUrl('index', panel: 'landlord') }}" class="text-sm text-gray-400 underline">
                    {{ __('Full Mode') }} — {{ __('check rental history') }}
                </a>
            </div>
        </div>
    @endif
</div>
