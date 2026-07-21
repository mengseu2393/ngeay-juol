<div class="rw-simple-billing">
    @php
        $access = $this->getAccess();
        $selectedProperty = $this->selectedProperty();
        $rooms = $this->rooms;
    @endphp

    <div
        x-data="{
            focusReading(event) {
                const el = this.$refs[event.detail.ref];
                if (el) {
                    el.focus();
                    if (typeof el.select === 'function') {
                        el.select();
                    }
                }
            }
        }"
        x-on:focus-reading.window="focusReading($event)"
        class="rw-simple-billing-inner space-y-4 px-1 py-2"
    >
        {{-- Subscription Warnings --}}
        @if($access === \App\Enums\SubscriptionAccess::PastDue)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/20 dark:text-amber-100 flex items-start gap-2">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" />
                <div>
                    <p class="font-semibold text-xs">{{ __('Subscription past due') }}</p>
                    <p class="mt-0.5 text-[10px] text-amber-700 dark:text-amber-300">{{ __('Please complete payment to restore full access.') }}</p>
                </div>
            </div>
        @elseif($access === \App\Enums\SubscriptionAccess::ReadOnly)
            <div class="rounded-lg border border-red-200 bg-red-50/50 p-3 text-sm text-red-900 dark:border-red-900/40 dark:bg-red-950/20 dark:text-red-100 flex items-start gap-2">
                <x-heroicon-o-lock-closed class="h-5 w-5 shrink-0 text-red-600 dark:text-red-400 mt-0.5" />
                <div>
                    <p class="font-semibold text-xs">{{ __('Write actions are disabled') }}</p>
                    <p class="mt-0.5 text-[10px] text-red-700 dark:text-red-300">{{ __('Your subscription is read-only until payment is completed.') }}</p>
                </div>
            </div>
        @endif

        {{-- Blocked state when billing is not available for the selected property --}}
        @if($this->step === 'blocked' || ! $selectedProperty)
            <div class="text-center py-6 space-y-2">
                <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                    <x-heroicon-o-home-modern class="h-5 w-5 text-gray-500 dark:text-gray-400" />
                </div>
                <div class="space-y-1">
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">
                        {{ __('Billing is not available for this property.') }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ __('Enable monthly billing in property settings to continue.') }}
                    </p>
                </div>
            </div>

        {{-- Step: Start screen --}}
        @elseif($this->step === 'start')
            <div class="space-y-4">
                <div>
                    <h2 class="text-base font-bold text-gray-950 dark:text-white">
                        {{ __('Confirm billing details') }}
                    </h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        {{ __('Set the billing date and verify active utilities before starting readings.') }}
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl border border-gray-100 bg-gray-50/50 p-3 dark:border-gray-800 dark:bg-gray-800/30">
                        <label for="sbi-date-input" class="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider block mb-1">
                            {{ __('Billing date') }}
                        </label>
                        <input
                            id="sbi-date-input"
                            type="date"
                            wire:model.live="issueDate"
                            class="w-full rounded-lg border-gray-300 bg-white text-sm font-semibold text-gray-950 shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white py-1.5 px-2"
                        >
                    </div>

                    <div class="rounded-xl border border-gray-100 bg-gray-50/50 p-3 dark:border-gray-800 dark:bg-gray-800/30">
                        <span class="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider block mb-1">
                            {{ __('Rooms due') }}
                        </span>
                        <p class="text-2xl font-extrabold text-gray-950 dark:text-white">
                            {{ $this->dueRoomCount() }}
                        </p>
                    </div>
                </div>

                <div class="rounded-xl border border-gray-100 bg-gray-50/50 p-3 dark:border-gray-800 dark:bg-gray-800/30">
                    <span class="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider block mb-2">
                        {{ __('Active utilities') }}
                    </span>
                    <div class="flex flex-wrap gap-1.5">
                        @forelse($this->activeUtilities() as $utility)
                            <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/10 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20">
                                {{ __($utility->name) }}
                            </span>
                        @empty
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('No active utilities') }}
                            </span>
                        @endforelse
                    </div>
                </div>

                @if(! $this->billingEnabled())
                    <div class="rounded-xl border border-amber-200 bg-amber-50/50 p-3 text-xs text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/20 dark:text-amber-100 flex items-start gap-2">
                        <x-heroicon-o-exclamation-triangle class="h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                        <div>
                            <p class="font-semibold">{{ __('Monthly billing is disabled for this property.') }}</p>
                            <p class="mt-0.5 text-[10px] text-gray-655 dark:text-gray-400">{{ __('Please enable it in property settings before continuing.') }}</p>
                        </div>
                    </div>
                @else
                    <div class="grid grid-cols-2 gap-1 rounded-xl border border-gray-200 bg-gray-100/80 p-1 dark:border-gray-700 dark:bg-gray-900/80">
                        <button
                            type="button"
                            wire:click="$set('manualMode', false)"
                            class="rounded-lg px-3 py-2 text-[10px] font-bold text-center transition {{ !$this->manualMode ? 'bg-white text-primary-700 shadow-sm dark:bg-gray-800 dark:text-primary-300' : 'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' }}"
                        >
                            {{ __('Scheduled billing') }}
                        </button>
                        <button
                            type="button"
                            wire:click="$set('manualMode', true)"
                            class="rounded-lg px-3 py-2 text-[10px] font-bold text-center transition {{ $this->manualMode ? 'bg-primary-600 text-white shadow-sm' : 'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' }}"
                        >
                            {{ __('Manual billing') }}
                        </button>
                    </div>

                    @if($this->manualMode)
                        <div class="rounded-2xl border border-gray-200 bg-white p-2.5 shadow-sm dark:border-gray-700 dark:bg-gray-900 space-y-2.5">
                            <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-1 pb-2 dark:border-gray-800">
                                <div>
                                    <p class="text-[11px] font-bold leading-tight text-gray-900 dark:text-white">{{ __('Select rooms to bill') }}</p>
                                    <p class="mt-0.5 text-[9px] leading-tight text-gray-500 dark:text-gray-400">
                                        {{ count($this->selectedRentalIds) }} / {{ $this->activeRentals()->count() }} {{ __('rooms selected') }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="toggleSelectAllRentals"
                                    class="shrink-0 rounded-lg border border-primary-200 bg-primary-50 px-2 py-1 text-[9px] font-bold leading-tight text-primary-700 transition hover:bg-primary-100 dark:border-primary-900/60 dark:bg-primary-500/10 dark:text-primary-300"
                                >
                                    {{ count($this->selectedRentalIds) === $this->activeRentals()->count() ? __('Clear all') : __('Select all') }}
                                </button>
                            </div>
                            <div class="grid max-h-48 grid-cols-1 gap-1.5 overflow-y-auto pr-1">
                                @forelse($this->activeRentals() as $rental)
                                    <label class="group flex min-h-12 cursor-pointer items-center gap-2 rounded-xl border px-2 py-1.5 transition {{ in_array($rental->id, $this->selectedRentalIds) ? 'border-primary-500 bg-primary-50/70 ring-1 ring-primary-500/20 dark:border-primary-500 dark:bg-primary-500/10' : 'border-gray-200 bg-gray-50/50 hover:border-primary-300 hover:bg-primary-50/40 dark:border-gray-700 dark:bg-gray-800/40' }}">
                                        <input
                                            type="checkbox"
                                            value="{{ $rental->id }}"
                                            wire:model.live="selectedRentalIds"
                                            class="h-3.5 w-3.5 shrink-0 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800"
                                        >
                                        <div class="min-w-0">
                                            <p class="truncate text-[11px] font-bold leading-tight text-gray-900 dark:text-white">
                                                {{ $rental->unit?->room_number }}
                                            </p>
                                            <p class="truncate text-[9px] leading-tight text-gray-500 dark:text-gray-400">
                                                {{ $rental->occupant_name }}
                                            </p>
                                        </div>
                                    </label>
                                @empty
                                    <div class="col-span-full py-3 text-center text-[10px] text-gray-500 dark:text-gray-400">
                                        {{ __('No active tenancies in this property.') }}
                                    </div>
                                @endforelse
                            </div>
                        </div>
                    @endif

                    <div class="pt-0.5">
                        @if($this->manualMode)
                            <button
                                type="button"
                                wire:click="startBilling"
                                @disabled(count($this->selectedRentalIds) === 0)
                                class="inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-primary-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-primary-500 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {{ __('Start billing') }}
                            </button>
                        @else
                            @if($this->dueRoomCount() > 0)
                                <button
                                    type="button"
                                    wire:click="startBilling"
                                    class="inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-primary-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-primary-500"
                                >
                                    {{ __('Start billing') }}
                                </button>
                            @else
                                <div class="w-full rounded-xl border border-gray-250 bg-gray-50 p-3 text-center dark:border-gray-800 dark:bg-gray-800/40">
                                    <p class="text-xs font-semibold text-gray-700 dark:text-gray-300">
                                        {{ __('No rooms are due for billing on this date.') }}
                                    </p>
                                    <p class="mt-0.5 text-[10px] text-gray-500 dark:text-gray-400">
                                        {{ __('Try changing the billing date above, select another property, or select Manual billing.') }}
                                    </p>
                                </div>
                            @endif
                        @endif
                    </div>
                @endif
            </div>

        {{-- Step: Reading Workspace --}}
        @elseif($this->step === 'reading')
            <div class="space-y-4">
                <div class="flex items-end justify-between gap-3 px-1">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-primary-600 dark:text-primary-400">{{ __('Billing readings') }}</p>
                        <h2 class="mt-1 text-base font-bold text-gray-950 dark:text-white">{{ __('Room') }} {{ $this->currentRoomNumber() }}</h2>
                    </div>
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-[10px] font-bold text-gray-600 dark:bg-gray-800 dark:text-gray-300">{{ $this->currentRoomProgress() }}</span>
                </div>
                @php
                    $cardIndex = $this->currentRoomIndex;
                    $cardRoom = $this->currentRoom();
                    $cardSummary = $this->roomSummary($cardIndex);
                @endphp
                @if($cardRoom)
                    <div wire:key="reading-card-{{ $cardIndex }}" x-init="$nextTick(() => $el.querySelector('[data-sbi-new-reading]')?.focus())" class="overflow-hidden rounded-2xl border border-primary-100 bg-white p-4 shadow-md ring-1 ring-primary-500/10 dark:border-primary-900/50 dark:bg-gray-900 space-y-4">
                        <div class="flex items-start justify-between gap-3 border-b border-gray-100 pb-3 dark:border-gray-800">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-bold text-gray-950 dark:text-white">{{ __('Room') }} {{ $cardRoom['room_number'] }}</span>
                                    @if($cardRoom['is_first_invoice'] ?? false)
                                        <span class="rounded-full bg-primary-50 px-2 py-0.5 text-[9px] font-semibold text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">{{ __('Prorated') }}</span>
                                    @endif
                                </div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $cardRoom['occupant_name'] }}</span>
                            </div>
                            <button type="button" wire:click="toggleRoomSkip({{ $cardIndex }})" class="rounded-lg border px-2.5 py-1.5 text-[10px] font-bold transition {{ $cardRoom['skipped'] ? 'border-primary-200 bg-primary-50/20 text-primary-600 dark:border-primary-900/30 dark:text-primary-400' : 'border-red-200 text-red-600 hover:bg-red-50/50 dark:border-red-900/30' }}">{{ $cardRoom['skipped'] ? __('Unskip') : __('Skip') }}</button>
                        </div>

                        @if(!$cardRoom['skipped'])
                            <div class="grid min-w-0 grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                                <div class="rounded-xl bg-gray-50 p-3 dark:bg-gray-800/50">
                                    <span class="mb-1 block text-[9px] font-bold uppercase tracking-wider text-gray-400">{{ __('Rent') }}</span>
                                    <span class="text-base font-extrabold text-gray-950 dark:text-white">{{ \App\Support\Money::format($cardRoom['rent'], $cardRoom['rent_currency'] ?? 'USD') }}</span>
                                </div>
                                <div class="min-w-0 rounded-xl bg-gray-50 p-3 dark:bg-gray-800/50">
                                    <span class="mb-1 block text-[9px] font-bold uppercase tracking-wider text-gray-400">{{ __('Billing Period') }}</span>
                                    <div class="flex min-w-0 flex-col gap-1.5 sm:flex-row sm:items-center">
                                        <input type="date" wire:model.live="rooms.{{ $cardIndex }}.period_start" class="min-w-0 w-full rounded-lg border-gray-300 bg-white px-2 py-1.5 text-[10px] shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                        <span class="hidden text-center text-gray-400 sm:inline">&rarr;</span>
                                        <input type="date" wire:model.live="rooms.{{ $cardIndex }}.period_end" class="min-w-0 w-full rounded-lg border-gray-300 bg-white px-2 py-1.5 text-[10px] shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
                                    </div>
                                </div>
                            </div>

                            @if($this->roomHasInvalidPeriodOrDuplicate($cardIndex))
                                <div class="rounded-lg border border-red-200 bg-red-50/50 p-2 text-[10px] font-semibold text-red-650 dark:border-red-900/30 dark:bg-red-950/10 dark:text-red-400">{{ __('Please check this billing period or duplicate invoice.') }}</div>
                            @endif

                            <div class="space-y-2.5 pt-1">
                                <span class="block border-b border-gray-100 pb-1 text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:border-gray-800">{{ __('Utilities') }}</span>
                                <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                                    @foreach($cardRoom['utilities'] as $utilityIndex => $utility)
                                        @php($preview = $this->utilityPreview($cardIndex, $utilityIndex))
                                        <div class="rounded-xl border border-gray-100 bg-gray-50/70 p-3 space-y-1.5 dark:border-gray-800 dark:bg-gray-800/30">
                                            <span class="font-bold text-gray-700 dark:text-gray-300">{{ $utility['utility_name'] }}</span>
                                            @if($utility['requires_reading'] ?? true)
                                                <div class="text-[8px] leading-tight text-gray-400 dark:text-gray-500">{{ __('Previous reading') }}: <span class="font-semibold text-gray-500 dark:text-gray-400">{{ $preview['old_reading'] !== null ? $this->formatQuantity($preview['old_reading']) : '—' }}</span></div>
                                                <input type="number" step="any" inputmode="decimal" wire:model.live.debounce.300ms="rooms.{{ $cardIndex }}.utilities.{{ $utilityIndex }}.new_reading" data-sbi-new-reading class="w-full rounded-lg border-gray-300 bg-white px-3 py-2 text-sm font-semibold shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white" placeholder="{{ __('New reading') }}">
                                                @if($preview['amount_used'] !== null)<div class="text-[10px] font-semibold text-emerald-600 dark:text-emerald-500">{{ __('Usage') }}: {{ $this->formatQuantity($preview['amount_used']) }} {{ $utility['unit_of_measure'] }} · {{ \App\Support\Money::format($preview['charge'], $utility['currency'] ?? 'USD') }}</div>@endif
                                            @else
                                                <span class="text-[10px] text-gray-500 dark:text-gray-400">{{ __('Fixed') }}: {{ \App\Support\Money::format($preview['charge'], $utility['currency'] ?? 'USD') }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            <div class="flex items-center justify-between rounded-xl bg-primary-50 px-3 py-3 dark:bg-primary-500/10"><span class="text-xs font-bold text-primary-800 dark:text-primary-300">{{ __('Estimated total') }}</span><span class="text-lg font-extrabold text-primary-700 dark:text-primary-300">{{ $cardSummary['estimated_total_display'] }}</span></div>
                        @else
                            <div class="py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('This room will be skipped from the current billing run.') }}</div>
                        @endif
                    </div>
                @endif
                <div wire:ignore class="hidden">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-800 text-left text-xs">
                        <thead class="bg-gray-50 dark:bg-gray-800/50">
                            <tr>
                                <th class="px-2 py-2 font-semibold text-gray-950 dark:text-white">{{ __('Room') }}</th>
                                <th class="px-2 py-2 font-semibold text-gray-950 dark:text-white">{{ __('Tenant') }}</th>
                                <th class="px-2 py-2 font-semibold text-gray-950 dark:text-white text-right">{{ __('Rent') }}</th>
                                <th class="px-2 py-2 font-semibold text-gray-950 dark:text-white min-w-[180px]">{{ __('Period') }}</th>
                                @foreach($this->activeUtilities() as $utility)
                                    <th class="px-2 py-2 font-semibold text-gray-950 dark:text-white min-w-[120px]">{{ __($utility->name) }}</th>
                                @endforeach
                                <th class="px-2 py-2 font-semibold text-gray-950 dark:text-white text-right">{{ __('Est.') }}</th>
                                <th class="px-2 py-2 font-semibold text-gray-950 dark:text-white text-center">{{ __('Skip') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-800">
                            @foreach([$this->currentRoomIndex => $this->currentRoom()] as $index => $room)
                                @php($summary = $this->roomSummary($index))
                                <tr class="{{ $room['skipped'] ? 'opacity-50 bg-gray-50/50 dark:bg-gray-800/10' : '' }} hover:bg-gray-50/30 dark:hover:bg-gray-800/10 transition-colors">
                                    <td class="px-2 py-2 font-bold text-gray-950 dark:text-white text-[11px]">
                                        {{ $room['room_number'] }}
                                    </td>
                                    <td class="px-2 py-2">
                                        <span class="font-medium text-gray-900 dark:text-white truncate max-w-[100px] block text-[10px]">
                                            {{ $room['occupant_name'] }}
                                        </span>
                                    </td>
                                    <td class="px-2 py-2 text-right font-medium text-gray-900 dark:text-white text-[10px]">
                                        {{ \App\Support\Money::format($room['rent'], $room['rent_currency'] ?? 'USD') }}
                                        @if($room['is_first_invoice'] ?? false)
                                            <span class="block text-[8px] font-semibold text-primary-600 dark:text-primary-400">
                                                {{ __('Prorated') }}
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-2">
                                        <div class="flex min-w-0 flex-col gap-1.5 sm:flex-row sm:items-center">
                                            <input
                                                type="date"
                                                wire:model.live="rooms.{{ $index }}.period_start"
                                                class="min-w-0 w-full rounded border-gray-300 bg-white px-1 py-0.5 text-[9px] shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white sm:w-24"
                                                {{ $room['skipped'] ? 'disabled' : '' }}
                                            >
                                            <span class="hidden text-gray-400 dark:text-gray-600 text-[9px] sm:inline">&rarr;</span>
                                            <input
                                                type="date"
                                                wire:model.live="rooms.{{ $index }}.period_end"
                                                class="min-w-0 w-full rounded border-gray-300 bg-white px-1 py-0.5 text-[9px] shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white sm:w-24"
                                                {{ $room['skipped'] ? 'disabled' : '' }}
                                            >
                                        </div>
                                        @if($this->roomHasInvalidPeriodOrDuplicate($index))
                                            <div class="mt-0.5 text-[8px] text-red-650 dark:text-red-400 font-semibold">
                                                @if(\Carbon\Carbon::parse($room['period_start'])->isAfter(\Carbon\Carbon::parse($room['period_end'])))
                                                    <span>⚠ {{ __('Period start is after end') }}</span>
                                                @elseif($this->hasDuplicateInvoice($index))
                                                    <span>⚠ {{ __('Duplicate invoice exists') }}</span>
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    @foreach($room['utilities'] as $utilityIndex => $utility)
                                        @php($preview = $this->utilityPreview($index, $utilityIndex))
                                        <td class="px-2 py-2">
                                            @if($utility['requires_reading'] ?? true)
                                                <div class="text-[8px] text-gray-400 dark:text-gray-500">
                                                    {{ __('Prev') }}: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $preview['old_reading'] !== null ? $this->formatQuantity($preview['old_reading']) : '—' }}</span>
                                                </div>
                                                <input
                                                    type="number"
                                                    step="any"
                                                    inputmode="decimal"
                                                    wire:model.live.debounce.300ms="rooms.{{ $index }}.utilities.{{ $utilityIndex }}.new_reading"
                                                    data-sbi-new-reading
                                                    class="w-full rounded border-gray-300 bg-white px-1 py-0.5 text-[10px] font-semibold shadow-sm focus:border-emerald-500 focus:ring-emerald-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                                                    placeholder="{{ __('New') }}"
                                                    {{ $room['skipped'] ? 'disabled' : '' }}
                                                >
                                                @if($preview['is_lower_reading'])
                                                    <div class="mt-0.5">
                                                        <input
                                                            type="text"
                                                            wire:model.live.debounce.300ms="rooms.{{ $index }}.utilities.{{ $utilityIndex }}.override_reason"
                                                            placeholder="{{ __('Reason') }}"
                                                            class="w-full rounded border-amber-300 bg-amber-50/50 px-1 py-0.5 text-[8px] focus:border-amber-500 dark:border-amber-900/30 dark:bg-amber-950/20 text-gray-955 dark:text-white"
                                                            {{ $room['skipped'] ? 'disabled' : '' }}
                                                        >
                                                    </div>
                                                @endif
                                                @if($preview['amount_used'] !== null && !$room['skipped'])
                                                    <div class="text-[8px] text-emerald-600 dark:text-emerald-500 mt-0.5 font-semibold">
                                                        {{ $this->formatQuantity($preview['amount_used']) }} {{ $utility['unit_of_measure'] }} &middot; {{ \App\Support\Money::format($preview['charge'], $utility['currency'] ?? 'USD') }}
                                                    </div>
                                                @endif
                                            @else
                                                <span class="text-[10px] text-gray-550 dark:text-gray-400 font-medium">{{ __('Fixed') }}: {{ \App\Support\Money::format($preview['charge'], $utility['currency'] ?? 'USD') }}</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="px-2 py-2 text-right font-bold text-gray-955 dark:text-white text-[10px]">
                                        {{ $summary['estimated_total_display'] }}
                                    </td>
                                    <td class="px-2 py-2 text-center">
                                        <button
                                            type="button"
                                            wire:click="toggleRoomSkip({{ $index }})"
                                            class="text-[10px] font-semibold transition {{ $room['skipped'] ? 'text-primary-600 dark:text-primary-400 hover:underline' : 'text-red-600 hover:text-red-700 hover:underline' }}"
                                        >
                                            {{ $room['skipped'] ? __('Unskip') : __('Skip') }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="sticky bottom-2 z-40 flex items-center justify-between gap-2 rounded-2xl border border-gray-200 bg-white/95 p-2 shadow-lg backdrop-blur dark:border-gray-800 dark:bg-gray-950/95">
                    <button
                        type="button"
                        wire:click="previousRoom"
                        class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-[10px] font-semibold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                    >
                        {{ __('Previous') }}
                    </button>
                    <button
                        type="button"
                        wire:click="nextRoom"
                        class="inline-flex items-center gap-1 rounded-lg bg-primary-600 px-3 py-1.5 text-[10px] font-bold text-white shadow-sm hover:bg-primary-500 transition"
                    >
                        {{ $this->currentRoomIndex >= count($this->rooms) - 1 ? __('Go to Review') : __('Next room') }}
                    </button>
                </div>
            </div>

        {{-- Step: Review Screen --}}
        @elseif($this->step === 'review')
            <div
                class="space-y-3"
                @if($this->reviewFocusIndex !== null)
                    x-init="$nextTick(() => document.getElementById('sbi-review-room-{{ $this->reviewFocusIndex }}')?.scrollIntoView({ behavior: 'smooth', block: 'center' }))"
                @endif
            >
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 space-y-3">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b border-gray-100 pb-2 dark:border-gray-800">
                        <div>
                            <h2 class="text-sm font-bold text-gray-950 dark:text-white">
                                {{ __('Review billing summary') }}
                            </h2>
                            <p class="text-[10px] text-gray-550 dark:text-gray-400">
                                {{ __('Review charges before creating invoices.') }}
                            </p>
                        </div>
                        @if($access !== \App\Enums\SubscriptionAccess::ReadOnly)
                            {{-- Blocking rooms keep the server path (warning notification); otherwise open instantly client-side. --}}
                            <button
                                type="button"
                                @if($this->firstBlockingRoomIndex() !== null)
                                    wire:click="openCreateConfirmation"
                                @else
                                    x-data
                                    x-on:click="$dispatch('open-billing-confirm')"
                                @endif
                                class="inline-flex items-center gap-1 rounded-lg bg-primary-600 px-3 py-1.5 text-[10px] font-bold text-white shadow-sm hover:bg-primary-500 transition w-full sm:w-auto justify-center"
                            >
                                {{ __('Create invoices') }}
                            </button>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <div class="rounded-lg bg-gray-50/50 p-2 dark:bg-gray-800/30">
                            <span class="text-[9px] font-medium text-gray-500 dark:text-gray-400 block">{{ __('Completed') }}</span>
                            <span class="text-lg font-bold text-gray-950 dark:text-white block">{{ $this->completeRoomCount() }}</span>
                        </div>
                        <div class="rounded-lg bg-gray-50/50 p-2 dark:bg-gray-800/30">
                            <span class="text-[9px] font-medium text-gray-500 dark:text-gray-400 block">{{ __('Skipped') }}</span>
                            <span class="text-lg font-bold text-gray-950 dark:text-white block">{{ $this->skippedRoomCount() }}</span>
                        </div>
                        <div class="rounded-lg bg-gray-50/50 p-2 dark:bg-gray-800/30">
                            <span class="text-[9px] font-medium text-gray-550 dark:text-gray-400 block">{{ __('Warnings') }}</span>
                            <span class="text-lg font-bold text-amber-600 dark:text-amber-400 block">{{ $this->roomsWithWarningsCount() }}</span>
                        </div>
                        <div class="rounded-lg bg-gray-50/50 p-2 dark:bg-gray-800/30">
                            <span class="text-[9px] font-medium text-gray-550 dark:text-gray-400 block">{{ __('Invoices') }}</span>
                            <span class="text-lg font-bold text-emerald-600 dark:text-emerald-400 block">{{ $this->estimatedInvoiceCount() }}</span>
                        </div>
                    </div>
                </div>

                <div class="space-y-3">
                    @foreach($rooms as $index => $room)
                        @php($summary = $this->roomSummary($index))
                        <div
                            id="sbi-review-room-{{ $index }}"
                            class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900 space-y-3 transition hover:border-gray-300 dark:hover:border-gray-700"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="space-y-0.5">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <h3 class="text-sm font-bold text-gray-950 dark:text-white">
                                            {{ __('Room') }} {{ $room['room_number'] }}
                                        </h3>
                                        @if($summary['is_skipped'])
                                            <span class="inline-flex items-center rounded-md bg-gray-100 px-1.5 py-0.5 text-[9px] font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                                {{ __('Skipped') }}
                                            </span>
                                        @elseif($summary['warning_count'] > 0)
                                            <span class="inline-flex items-center rounded-md bg-amber-50 px-1.5 py-0.5 text-[9px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/20">
                                                {{ __('Warning') }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-md bg-emerald-50 px-1.5 py-0.5 text-[9px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/10 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20">
                                                {{ __('Ready') }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="text-[10px] font-medium text-gray-600 dark:text-gray-300">
                                        {{ $room['occupant_name'] }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="editRoom({{ $index }})"
                                    class="rounded-lg border border-gray-200 bg-white px-2 py-1 text-[9px] font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                                >
                                    {{ __('Edit') }}
                                </button>
                            </div>

                            <div class="grid grid-cols-3 gap-2">
                                <div class="rounded-lg bg-gray-50/50 p-2 dark:bg-gray-800/30">
                                    <span class="text-[8px] font-medium text-gray-500 dark:text-gray-400 block">{{ __('Utilities') }}</span>
                                    <span class="text-[10px] font-semibold text-gray-950 dark:text-white block truncate" title="{{ $summary['utility_summary'] }}">
                                        {{ $summary['utility_summary'] }}
                                    </span>
                                </div>
                                <div class="rounded-lg bg-gray-50/50 p-2 dark:bg-gray-800/30">
                                    <span class="text-[9px] font-medium text-gray-500 dark:text-gray-400 block">{{ __('Rent') }}</span>
                                    <span class="text-xs font-bold text-gray-955 dark:text-white mt-0.5 block">
                                        {{ \App\Support\Money::format($summary['rent'], $summary['rent_currency'] ?? 'USD') }}
                                    </span>
                                </div>
                                <div class="rounded-lg bg-gray-50/50 p-2 dark:bg-gray-800/30">
                                    <span class="text-[9px] font-medium text-gray-500 dark:text-gray-400 block">{{ __('Estimated total') }}</span>
                                    <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400 mt-0.5 block">
                                        {{ $summary['estimated_total_display'] }}
                                    </span>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                <div class="rounded-lg bg-gray-50/50 p-2 dark:bg-gray-800/30">
                                    <span class="text-[8px] font-medium text-gray-500 dark:text-gray-400 block">{{ __('Charges to bill') }}</span>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @forelse($summary['charges_to_bill'] as $charge)
                                            <span class="inline-flex items-center rounded-md bg-emerald-50 px-1.5 py-0.5 text-[9px] font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">{{ $charge }}</span>
                                        @empty
                                            <span class="text-[9px] text-gray-500 dark:text-gray-400">{{ __('None') }}</span>
                                        @endforelse
                                    </div>
                                </div>
                                <div class="rounded-lg bg-gray-50/50 p-2 dark:bg-gray-800/30">
                                    <span class="text-[8px] font-medium text-gray-500 dark:text-gray-400 block">{{ __('Free or waived') }}</span>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @forelse($summary['free_or_waived'] as $charge)
                                            <span class="inline-flex items-center rounded-md bg-amber-50 px-1.5 py-0.5 text-[9px] font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">{{ $charge }}</span>
                                        @empty
                                            <span class="text-[9px] text-gray-500 dark:text-gray-400">{{ __('None') }}</span>
                                        @endforelse
                                    </div>
                                </div>
                                <div class="rounded-lg bg-gray-50/50 p-2 dark:bg-gray-800/30">
                                    <span class="text-[8px] font-medium text-gray-500 dark:text-gray-400 block">{{ __('Not billed') }}</span>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @forelse($summary['not_billed'] as $charge)
                                            <span class="inline-flex items-center rounded-md bg-gray-100 px-1.5 py-0.5 text-[9px] font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ $charge }}</span>
                                        @empty
                                            <span class="text-[9px] text-gray-500 dark:text-gray-400">{{ __('None') }}</span>
                                        @endforelse
                                    </div>
                                </div>
                            </div>

                            @if($summary['warnings'])
                                <div class="rounded-lg border border-amber-200 bg-amber-50/50 p-2 text-[10px] text-amber-900 dark:border-amber-900/30 dark:bg-amber-950/20 dark:text-amber-100 space-y-0.5">
                                    <p class="font-bold">{{ __('Warnings') }}:</p>
                                    <ul class="list-disc pl-3 space-y-0.5">
                                        @foreach($summary['warnings'] as $warning)
                                            <li>{{ $warning }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            <button
                                type="button"
                                wire:click="toggleRoomSkip({{ $index }})"
                                class="text-[10px] font-semibold transition {{ $summary['is_skipped'] ? 'text-primary-600 dark:text-primary-400' : 'text-red-600 hover:text-red-700' }}"
                            >
                                {{ $summary['is_skipped'] ? __('Unskip room') : __('Skip room') }}
                            </button>
                        </div>
                    @endforeach
                </div>

                <div class="sticky bottom-0 z-40 rounded-xl border border-gray-250 bg-white p-3 shadow-lg dark:border-gray-850 dark:bg-gray-950 backdrop-blur-md bg-opacity-95">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-[10px] text-gray-500 dark:text-gray-400">
                            {{ __('Review complete? Ready to batch-generate invoices.') }}
                        </p>
                        @if($access !== \App\Enums\SubscriptionAccess::ReadOnly)
                            {{-- Blocking rooms keep the server path (warning notification); otherwise open instantly client-side. --}}
                            <button
                                type="button"
                                @if($this->firstBlockingRoomIndex() !== null)
                                    wire:click="openCreateConfirmation"
                                @else
                                    x-data
                                    x-on:click="$dispatch('open-billing-confirm')"
                                @endif
                                class="inline-flex items-center gap-1 rounded-lg bg-primary-600 px-3 py-1.5 text-[10px] font-bold text-white shadow-sm hover:bg-primary-500 transition w-full sm:w-auto justify-center"
                            >
                                {{ __('Create invoices') }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>

        {{-- Step: Result Screen --}}
        @else
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900 space-y-4">
                <div class="text-center pb-3 border-b border-gray-100 dark:border-gray-800">
                    <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 dark:bg-emerald-500/10">
                        <x-heroicon-o-check class="h-5 w-5 text-emerald-600 dark:text-emerald-500" />
                    </div>
                    <h2 class="text-base font-bold text-gray-955 dark:text-white mt-3">
                        {{ __('Billing complete') }}
                    </h2>
                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5">
                        {{ __('All processed rooms have been billed for this period.') }}
                    </p>
                </div>

                <div class="space-y-2">
                    <h3 class="text-[9px] font-semibold text-gray-400 dark:text-gray-550 uppercase tracking-wider">
                        {{ __('Billing run summary') }}
                    </h3>
                    <div class="rounded-lg border border-gray-100 bg-gray-50/50 p-3 dark:border-gray-800 dark:bg-gray-800/30 space-y-2">
                        <div class="flex justify-between">
                            <span class="text-[10px] text-gray-500 dark:text-gray-400">{{ __('Invoices created') }}:</span>
                            <span class="font-bold text-emerald-600 dark:text-emerald-400 text-[10px]">{{ $this->resultSummary['created'] }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-[10px] text-gray-500 dark:text-gray-400">{{ __('Rooms skipped') }}:</span>
                            <span class="font-bold text-gray-700 dark:text-gray-300 text-[10px]">{{ $this->resultSummary['skipped'] }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-[10px] text-gray-500 dark:text-gray-400">{{ __('Failed runs') }}:</span>
                            <span class="font-bold {{ $this->resultSummary['failed'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300' }} text-[10px]">
                                {{ $this->resultSummary['failed'] }}
                            </span>
                        </div>
                    </div>
                </div>

                @if($this->resultSummary['failures'])
                    <div class="space-y-1.5">
                        <h4 class="text-[9px] font-semibold text-red-650 dark:text-red-400 uppercase tracking-wider">
                            {{ __('Details of failures') }}
                        </h4>
                        <div class="max-h-32 overflow-y-auto rounded-lg border border-red-100 bg-red-50/30 p-2 dark:border-red-900/30 dark:bg-red-950/20 text-[10px] text-red-900 dark:text-red-205 space-y-1">
                            @foreach($this->resultSummary['failures'] as $failure)
                                <div>
                                    <span class="font-bold">{{ __('Room') }} {{ $failure['room_number'] }}</span>: {{ $failure['message'] }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex flex-col gap-2 pt-1">
                    <button
                        type="button"
                        wire:click="startAnotherProperty"
                        class="w-full rounded-lg bg-primary-600 px-3 py-2 text-xs font-bold text-white shadow-sm hover:bg-primary-500 transition"
                    >
                        {{ __('Bill another property') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('step', 'start')"
                        class="w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
                    >
                        {{ __('Back to start') }}
                    </button>
                </div>
            </div>
        @endif

        {{-- Confirmation Modal — always in the DOM so opening is instant (Alpine);
             entangled with showCreateConfirmation so server flows (tests, blocked-room
             warnings, post-create reset) stay authoritative. --}}
        @if($this->rooms !== [])
            <div
                x-data="{ show: $wire.entangle('showCreateConfirmation') }"
                x-show="show"
                x-cloak
                x-on:open-billing-confirm.window="show = true"
                x-on:keydown.escape.window="show = false"
                style="display: none;"
                class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/60 p-4 backdrop-blur-sm transition-opacity">
                <div class="w-full max-w-sm rounded-xl bg-white p-5 shadow-xl dark:bg-gray-900 border border-gray-200 dark:border-gray-800 space-y-4">
                    <div>
                        <h3 class="text-sm font-bold text-gray-950 dark:text-white">
                            {{ __('Confirm invoice creation') }}
                        </h3>
                        <p class="mt-1 text-[10px] text-gray-500 dark:text-gray-400">
                            {{ __('You are about to generate monthly invoices for this property.') }}
                        </p>
                    </div>

                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/50 space-y-1.5 text-[10px]">
                        <div class="flex justify-between">
                            <span class="text-gray-500 dark:text-gray-400">{{ __('Invoices to create') }}:</span>
                            <span class="font-bold text-gray-900 dark:text-white">{{ $this->estimatedInvoiceCount() }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500 dark:text-gray-400">{{ __('Rooms to skip') }}:</span>
                            <span class="font-bold text-gray-900 dark:text-white">{{ $this->skippedRoomCount() }}</span>
                        </div>
                        @if($this->roomsWithWarningsCount() > 0)
                            <div class="flex justify-between text-amber-600 dark:text-amber-400">
                                <span>{{ __('Rooms with warnings') }}:</span>
                                <span class="font-bold">{{ $this->roomsWithWarningsCount() }}</span>
                            </div>
                        @endif
                        @php($rateInfo = $this->getExchangeRateInfo())
                        <div class="border-t border-gray-200 dark:border-gray-700 my-1 pt-1 text-[9px] space-y-0.5">
                            <div class="font-semibold text-gray-700 dark:text-gray-300">
                                {{ __('Exchange rate for this invoice run') }}:
                            </div>
                            <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                <span>1 USD = {{ number_format($rateInfo['rate'], 0) }} KHR</span>
                                <span>{{ __('Source') }}: {{ $rateInfo['source'] }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            x-on:click="show = false"
                            class="w-full rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-[10px] font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 sm:w-auto"
                        >
                            {{ __('Cancel') }}
                        </button>
                        <button
                            type="button"
                            wire:click="createInvoices"
                            class="w-full rounded-lg bg-primary-600 px-3 py-1.5 text-[10px] font-bold text-white shadow-sm hover:bg-primary-500 transition sm:w-auto"
                        >
                            {{ __('Create invoices') }}
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
