@php
    use App\Enums\SubscriptionAccess;
    use App\Services\ChargeRuleResolver;
    use App\Support\ActiveProperty;
    use App\Support\Money;
    use Carbon\Carbon;

    $access = $this->getAccess();
    $readOnly = $access === SubscriptionAccess::ReadOnly;
    $selectedProperty = $this->selectedProperty();
    $rooms = $this->rooms;
    $roomCount = count($rooms);
    $isBlocked = $step === 'blocked' || ! $selectedProperty;
    $stepNumber = $isBlocked ? 0 : (['start' => 1, 'reading' => 2, 'review' => 3][$step] ?? 4);

    $dot = fn (string $status) => match ($status) {
        'done' => 'bg-emerald-500',
        'attention' => 'bg-amber-500',
        'skipped' => 'bg-slate-300 dark:bg-slate-600',
        default => 'bg-white/0 ring-2 ring-inset ring-slate-300 dark:ring-slate-600',
    };
@endphp

<div
    x-data="rwMobileBilling"
    x-on:focus-reading.window="focusRef($event.detail.ref)"
    class="rw-m-billing"
    id="rw-m-billing"
    data-step="{{ $isBlocked ? 'blocked' : $step }}"
>
    {{-- ── Header: back to Invoices + where we are in the run ── --}}
    <div class="flex items-center gap-1 pt-4 pb-2">
        <a
            href="{{ route('mobile.invoices') }}"
            wire:navigate
            class="-ml-2 inline-flex size-11 shrink-0 items-center justify-center rounded-full text-slate-600 transition active:bg-slate-200 dark:text-slate-300 dark:active:bg-slate-800"
            aria-label="{{ __('Back to invoices') }}"
            id="rw-m-billing-back"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
        </a>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{{ __('Create invoices') }}</h1>
    </div>

    @if ($stepNumber > 0)
        <ol class="mb-4 grid grid-cols-4 gap-1.5" aria-label="{{ __('Progress') }}">
            @foreach ([__('Start'), __('Readings'), __('Review'), __('Done')] as $n => $label)
                <li class="min-w-0" @if ($n + 1 === $stepNumber) aria-current="step" @endif>
                    <span class="block h-1.5 rounded-full {{ $n + 1 <= $stepNumber ? 'bg-brand-600 dark:bg-emerald-500' : 'bg-slate-200 dark:bg-slate-800' }}"></span>
                    <span class="mt-1 block truncate text-xs font-semibold {{ $n + 1 === $stepNumber ? 'text-brand-700 dark:text-emerald-400' : 'text-slate-400 dark:text-slate-500' }}">{{ $label }}</span>
                </li>
            @endforeach
        </ol>
    @endif

    {{-- ── Subscription state ── --}}
    @if ($access === SubscriptionAccess::PastDue)
        <div class="mb-4 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100" id="rw-m-billing-past-due">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
            <div>
                <p class="font-semibold">{{ __('Subscription past due') }}</p>
                <p class="text-sm text-amber-800 dark:text-amber-200">{{ __('Please complete payment to restore full access.') }}</p>
            </div>
        </div>
    @elseif ($readOnly)
        <div class="mb-4 flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-red-900 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-100" id="rw-m-billing-read-only">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="mt-0.5 size-5 shrink-0 text-red-600 dark:text-red-400" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
            <div>
                <p class="font-semibold">{{ __('Write actions are disabled') }}</p>
                <p class="text-sm text-red-800 dark:text-red-200">{{ __('Your subscription is read-only until payment is completed.') }}</p>
            </div>
        </div>
    @endif

    {{-- ════════════════ Blocked: no property, or billing off for it ════════════════ --}}
    @if ($isBlocked)
        <div class="rw-m-card py-8 text-center" id="rw-m-billing-blocked">
            <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" class="size-6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.545M12.75 21h7.5V10.75M2.25 21h1.5m18 0h-18M2.25 9l4.5-1.636M18.75 3l-1.5.545m0 6.205 3 1m1.5.5-1.5-.5M6.75 7.364V3h-3v18m3-13.636 10.5-3.819" /></svg>
            </div>
            @if (! ActiveProperty::id())
                <p class="mt-3 font-semibold">{{ __('Choose a property first') }}</p>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Use the property switcher above to select a property.') }}</p>
            @else
                <p class="mt-3 font-semibold">{{ __('Billing is not available for this property.') }}</p>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Enable monthly billing in property settings to continue.') }}</p>
                <a href="{{ route('mobile.property-settings') }}" wire:navigate class="rw-m-btn rw-m-btn-primary mt-5 w-full" id="rw-m-billing-open-settings">
                    {{ __('Open property settings') }}
                </a>
            @endif
        </div>

    {{-- ════════════════ Start: billing date, which rooms ════════════════ --}}
    @elseif ($step === 'start')
        @php
            $dueCount = $this->dueRoomCount();
            $utilities = $this->activeUtilities();
            $activeRentals = $manualMode ? $this->activeRentals() : collect();
            $selectedCount = count($selectedRentalIds);
        @endphp

        <div class="space-y-4">
            <div class="rw-m-card">
                <label for="rw-m-billing-date" class="mb-1.5 block text-sm font-semibold text-slate-600 dark:text-slate-300">{{ __('Billing date') }}</label>
                <input type="date" id="rw-m-billing-date" wire:model.live="issueDate" class="rw-m-input font-semibold">
                <div class="mt-4 flex items-baseline justify-between border-t border-slate-100 pt-3 dark:border-slate-800">
                    <span class="text-sm text-slate-500 dark:text-slate-400">{{ __('Rooms due') }}</span>
                    <span class="text-3xl font-extrabold tabular-nums" id="rw-m-billing-due-count" wire:loading.class="opacity-50" wire:target="issueDate">{{ $dueCount }}</span>
                </div>
            </div>

            {{-- Which rooms: the ones due, or picked by hand (e.g. a room that moved out mid-month). --}}
            <div class="grid grid-cols-2 gap-2" role="group" aria-label="{{ __('Rooms to bill') }}">
                <button type="button" wire:click="$set('manualMode', false)" class="rw-m-pill min-h-11" aria-pressed="{{ $manualMode ? 'false' : 'true' }}" id="rw-m-billing-mode-due">{{ __('Rooms due') }}</button>
                <button type="button" wire:click="$set('manualMode', true)" class="rw-m-pill min-h-11" aria-pressed="{{ $manualMode ? 'true' : 'false' }}" id="rw-m-billing-mode-manual">{{ __('Choose rooms') }}</button>
            </div>

            @if ($manualMode)
                <div class="rw-m-card p-0" id="rw-m-billing-room-picker">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-2 dark:border-slate-800">
                        <span class="text-sm text-slate-500 dark:text-slate-400">{{ __(':count selected', ['count' => $selectedCount]) }}</span>
                        @if ($activeRentals->isNotEmpty())
                            <button type="button" wire:click="toggleSelectAllRentals" class="-mr-2 min-h-11 rounded-xl px-3 text-sm font-semibold text-brand-700 active:bg-slate-100 dark:text-emerald-400 dark:active:bg-slate-800" id="rw-m-billing-select-all">
                                {{ $selectedCount > 0 && $selectedCount === $activeRentals->count() ? __('Clear all') : __('Select all') }}
                            </button>
                        @endif
                    </div>
                    <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($activeRentals as $rental)
                            <li wire:key="rw-m-billing-pick-{{ $rental->id }}">
                                <label class="flex min-h-14 items-center gap-3 px-4 py-2 active:bg-slate-50 dark:active:bg-slate-800/60">
                                    <input type="checkbox" value="{{ $rental->id }}" wire:model.live="selectedRentalIds" class="size-6 shrink-0 rounded-md accent-emerald-600" id="rw-m-billing-pick-{{ $rental->id }}">
                                    <span class="min-w-0">
                                        <span class="block font-semibold">{{ $rental->unit?->room_number ?? '—' }}</span>
                                        <span class="block truncate text-sm text-slate-500 dark:text-slate-400">{{ $rental->occupant_name ?: ($rental->tenant?->name ?? __('Tenant')) }}</span>
                                    </span>
                                </label>
                            </li>
                        @empty
                            <li class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">{{ __('No active tenancies in this property.') }}</li>
                        @endforelse
                    </ul>
                </div>
            @elseif ($dueCount === 0)
                <div class="rw-m-card text-center" id="rw-m-billing-none-due">
                    <p class="font-semibold">{{ __('No rooms are due for billing on this date.') }}</p>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Change the billing date, or choose rooms by hand.') }}</p>
                </div>
            @endif

            <div class="rw-m-card">
                <p class="mb-2 text-sm font-semibold text-slate-600 dark:text-slate-300">{{ __('Active utilities') }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @forelse ($utilities as $utility)
                        <span class="rw-m-badge bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">{{ __($utility->name) }}</span>
                    @empty
                        <span class="text-sm text-slate-500 dark:text-slate-400">{{ __('No active utilities') }}</span>
                    @endforelse
                </div>
            </div>

            @if ($manualMode || $dueCount > 0)
                <div class="rw-m-billing-bar">
                    <button type="button" wire:click="startBilling" wire:loading.attr="disabled" wire:target="startBilling" class="rw-m-btn rw-m-btn-primary w-full" id="rw-m-billing-start">
                        <span wire:loading.remove wire:target="startBilling">
                            {{ $manualMode ? __('Start billing (:count rooms)', ['count' => $selectedCount]) : __('Start billing (:count rooms)', ['count' => $dueCount]) }}
                        </span>
                        <span wire:loading wire:target="startBilling">{{ __('Loading…') }}</span>
                    </button>
                </div>
            @endif
        </div>

    {{-- ════════════════ Readings: one room card at a time ════════════════ --}}
    @elseif ($step === 'reading')
        @php
            $cardIndex = $currentRoomIndex;
            $cardRoom = $this->currentRoom();
            $cardSummary = $this->roomSummary($cardIndex);
            $isLast = $cardIndex >= $roomCount - 1;
        @endphp

        {{-- Room strip: progress at a glance, tap to jump. --}}
        <div class="-mx-4 mb-3 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]" role="group" aria-label="{{ __('Rooms') }}" id="rw-m-billing-strip">
            @foreach ($rooms as $index => $room)
                <button
                    type="button"
                    wire:key="rw-m-billing-strip-{{ $index }}"
                    wire:click="jumpToRoom({{ $index }})"
                    class="rw-m-pill inline-flex min-h-11 items-center gap-2"
                    aria-pressed="{{ $index === $cardIndex ? 'true' : 'false' }}"
                    data-status="{{ $this->roomStatus($index) }}"
                    id="rw-m-billing-strip-{{ $index }}"
                >
                    <span class="size-2.5 shrink-0 rounded-full {{ $dot($this->roomStatus($index)) }}" aria-hidden="true"></span>
                    {{ $room['room_number'] }}
                </button>
            @endforeach
        </div>

        @if ($cardRoom)
            <article wire:key="rw-m-billing-room-{{ $cardIndex }}" class="rw-m-card space-y-4" id="rw-m-billing-room">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $this->currentRoomProgress() }}</p>
                        <h2 class="flex flex-wrap items-center gap-2 text-2xl leading-tight font-bold">
                            {{ __('Room') }} {{ $cardRoom['room_number'] }}
                            @if ($cardRoom['is_first_invoice'] ?? false)
                                <span class="rw-m-badge bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">{{ __('Prorated') }}</span>
                            @endif
                        </h2>
                        <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ $cardRoom['occupant_name'] }}</p>
                    </div>
                    <button
                        type="button"
                        wire:click="toggleRoomSkip({{ $cardIndex }})"
                        class="rw-m-btn min-h-11 shrink-0 border px-3 text-sm {{ $cardRoom['skipped'] ? 'border-emerald-200 text-emerald-700 active:bg-emerald-50 dark:border-emerald-900/50 dark:text-emerald-400' : 'border-red-200 text-red-600 active:bg-red-50 dark:border-red-900/50 dark:text-red-400' }}"
                        id="rw-m-billing-skip"
                    >{{ $cardRoom['skipped'] ? __('Unskip') : __('Skip') }}</button>
                </div>

                @if ($cardRoom['skipped'])
                    <p class="rounded-xl bg-slate-50 p-4 text-center text-sm text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">{{ __('This room will be skipped from the current billing run.') }}</p>
                @else
                    @php
                        $badPeriod = Carbon::parse($cardRoom['period_start'])->isAfter(Carbon::parse($cardRoom['period_end']));
                        $duplicate = ! $badPeriod && $this->hasDuplicateInvoice($cardIndex);
                    @endphp

                    {{-- Rent + period; the dates open on tap (or by themselves when they need fixing). --}}
                    <div x-data="{ editing: @js($badPeriod || $duplicate) }" class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ __('Rent') }}</p>
                                <p class="text-lg font-bold tabular-nums">{{ Money::format($cardRoom['rent'], $cardRoom['rent_currency'] ?? 'USD') }}</p>
                            </div>
                            <div class="min-w-0 text-right">
                                <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ __('Billing Period') }}</p>
                                <button type="button" x-on:click="editing = ! editing" class="-mr-2 min-h-11 rounded-lg px-2 text-sm font-semibold text-brand-700 active:bg-slate-200 dark:text-emerald-400 dark:active:bg-slate-700" id="rw-m-billing-period-toggle">
                                    {{ Carbon::parse($cardRoom['period_start'])->format('d M') }} – {{ Carbon::parse($cardRoom['period_end'])->format('d M Y') }}
                                </button>
                            </div>
                        </div>
                        <div x-show="editing" x-cloak class="mt-2 grid grid-cols-2 gap-2">
                            <label class="block min-w-0">
                                <span class="mb-1 block text-xs text-slate-500 dark:text-slate-400">{{ __('From') }}</span>
                                <input type="date" wire:model.live="rooms.{{ $cardIndex }}.period_start" class="rw-m-input min-w-0 px-2" id="rw-m-billing-period-start">
                            </label>
                            <label class="block min-w-0">
                                <span class="mb-1 block text-xs text-slate-500 dark:text-slate-400">{{ __('To') }}</span>
                                <input type="date" wire:model.live="rooms.{{ $cardIndex }}.period_end" class="rw-m-input min-w-0 px-2" id="rw-m-billing-period-end">
                            </label>
                        </div>
                    </div>

                    @if ($badPeriod || $duplicate)
                        <p class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300" id="rw-m-billing-period-error">
                            {{ $badPeriod ? __('Period start is after end') : __('Duplicate invoice exists') }} — {{ __('Please check this billing period or duplicate invoice.') }}
                        </p>
                    @endif

                    {{-- Utilities: previous reading next to a big field, usage + charge right under it. --}}
                    <div class="space-y-3">
                        @foreach ($cardRoom['utilities'] as $utilityIndex => $utility)
                            @php
                                $preview = $this->utilityPreview($cardIndex, $utilityIndex);
                                $state = (string) ($utility['state_override'] ?? 'normal');
                                $notBilled = $this->utilityIsNotBilled($utility);
                                $invalid = $this->readingIsInvalid($cardIndex, $utilityIndex);
                                $hasNextReading = collect($cardRoom['utilities'])
                                    ->slice($utilityIndex + 1)
                                    ->contains(fn ($u) => ($u['requires_reading'] ?? true) && ! $this->utilityIsNotBilled($u));
                                $inputId = 'rw-m-reading-'.$cardIndex.'-'.$utilityIndex;
                            @endphp
                            <div wire:key="rw-m-billing-utility-{{ $cardIndex }}-{{ $utilityIndex }}" class="rounded-2xl border border-slate-200 p-3 dark:border-slate-800">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="font-semibold">
                                        {{ $utility['utility_name'] }}
                                        @if (filled($utility['meter_serial'] ?? null))
                                            <span class="text-xs font-normal text-slate-400">#{{ $utility['meter_serial'] }}</span>
                                        @endif
                                    </p>
                                    @if ($state !== 'normal')
                                        <span class="rw-m-badge bg-amber-50 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">{{ ChargeRuleResolver::stateLabel($state) }}</span>
                                    @endif
                                </div>

                                @if ($notBilled)
                                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Not billed this time.') }}</p>
                                @elseif ($utility['requires_reading'] ?? true)
                                    <div class="mt-2 grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] items-end gap-3">
                                        <div class="min-w-0 pb-3">
                                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Previous reading') }}</p>
                                            <p class="truncate text-xl font-semibold text-slate-500 tabular-nums dark:text-slate-400">{{ $preview['old_reading'] !== null ? $this->formatQuantity($preview['old_reading']) : '—' }}</p>
                                        </div>
                                        <div class="min-w-0">
                                            <label for="{{ $inputId }}" class="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300">{{ __('New reading') }}</label>
                                            <input
                                                type="text"
                                                inputmode="decimal"
                                                enterkeyhint="{{ $hasNextReading ? 'next' : 'done' }}"
                                                autocomplete="off"
                                                autocorrect="off"
                                                spellcheck="false"
                                                id="{{ $inputId }}"
                                                data-reading
                                                wire:model.live.debounce.400ms="rooms.{{ $cardIndex }}.utilities.{{ $utilityIndex }}.new_reading"
                                                x-on:keydown.enter.prevent="nextReading($el)"
                                                placeholder="0"
                                                class="rw-m-input rw-m-reading-input text-right text-2xl font-bold {{ $invalid ? 'border-red-400 dark:border-red-500' : '' }}"
                                                @if ($invalid) aria-invalid="true" @endif
                                            >
                                        </div>
                                    </div>

                                    {{-- One line reserved, so typing never makes the card jump. --}}
                                    <p class="mt-1.5 min-h-5 text-sm font-semibold" aria-live="polite">
                                        @if ($invalid)
                                            <span class="text-red-600 dark:text-red-400">{{ __('Enter numbers only, e.g. 1250.5') }}</span>
                                        @elseif ($preview['amount_used'] !== null)
                                            <span class="text-emerald-700 dark:text-emerald-400">{{ __('Usage') }}: {{ $this->formatQuantity($preview['amount_used']) }} {{ $utility['unit_of_measure'] }} · {{ Money::format($preview['charge'], $utility['currency'] ?? 'USD') }}</span>
                                        @endif
                                        @if (! $invalid && $preview['warning'])
                                            <span class="block text-amber-700 dark:text-amber-400">{{ $preview['warning'] }}</span>
                                        @endif
                                    </p>

                                    @if ($preview['is_lower_reading'] ?? false)
                                        <div class="mt-2">
                                            <label for="rw-m-reason-{{ $cardIndex }}-{{ $utilityIndex }}" class="mb-1 block text-xs font-semibold text-amber-800 dark:text-amber-300">{{ __('Lower than previous reading — add a reason to bill it') }}</label>
                                            <input
                                                type="text"
                                                id="rw-m-reason-{{ $cardIndex }}-{{ $utilityIndex }}"
                                                wire:model.live.debounce.400ms="rooms.{{ $cardIndex }}.utilities.{{ $utilityIndex }}.override_reason"
                                                placeholder="{{ __('e.g. Meter replaced') }}"
                                                class="rw-m-input border-amber-300 dark:border-amber-700"
                                                enterkeyhint="done"
                                            >
                                        </div>
                                    @endif
                                @else
                                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Fixed') }}: <span class="font-semibold text-slate-700 dark:text-slate-200">{{ Money::format($preview['charge'], $utility['currency'] ?? 'USD') }}</span></p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="flex items-center justify-between gap-3 rounded-xl bg-emerald-50 px-4 py-3 dark:bg-emerald-500/10">
                        <span class="font-semibold text-emerald-800 dark:text-emerald-300">{{ __('Estimated total') }}</span>
                        <span class="text-xl font-extrabold text-emerald-700 tabular-nums dark:text-emerald-300" id="rw-m-billing-room-total">{{ $cardSummary['estimated_total_display'] }}</span>
                    </div>
                @endif
            </article>
        @endif

        <div class="rw-m-billing-bar grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-2">
            <button type="button" x-on:click="go('previousRoom')" wire:loading.attr="disabled" wire:target="previousRoom,nextRoom" class="rw-m-btn rw-m-btn-secondary" id="rw-m-billing-prev">
                {{ $reviewFocusIndex !== null ? __('Back to review') : ($cardIndex === 0 ? __('Back') : __('Previous')) }}
            </button>
            <button type="button" x-on:click="go('nextRoom')" wire:loading.attr="disabled" wire:target="previousRoom,nextRoom" class="rw-m-btn rw-m-btn-primary" id="rw-m-billing-next">
                {{ $isLast ? __('Review') : __('Next room') }}
            </button>
        </div>

    {{-- ════════════════ Review ════════════════ --}}
    @elseif ($step === 'review')
        @php
            $blockingIndex = $this->firstBlockingRoomIndex();
            $invoiceCount = $this->estimatedInvoiceCount();
        @endphp

        <div
            class="space-y-3"
            @if ($reviewFocusIndex !== null)
                x-init="$nextTick(() => document.getElementById('rw-m-billing-review-{{ $reviewFocusIndex }}')?.scrollIntoView({ block: 'center' }))"
            @endif
        >
            <div class="grid grid-cols-4 gap-2">
                @foreach ([
                    [__('Completed'), $this->completeRoomCount(), ''],
                    [__('Skipped'), $this->skippedRoomCount(), ''],
                    [__('Warnings'), $this->roomsWithWarningsCount(), 'text-amber-600 dark:text-amber-400'],
                    [__('Invoices'), $invoiceCount, 'text-emerald-600 dark:text-emerald-400'],
                ] as [$label, $value, $tone])
                    <div class="rw-m-card p-3 text-center">
                        <p class="text-2xl font-extrabold tabular-nums {{ $tone }}">{{ $value }}</p>
                        <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $label }}</p>
                    </div>
                @endforeach
            </div>

            @if ($blockingIndex !== null)
                <p class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100" id="rw-m-billing-blocking">
                    {{ __('Complete or skip the blocked rooms before creating invoices.') }}
                </p>
            @endif

            @foreach ($rooms as $index => $room)
                @php
                    $summary = $this->roomSummary($index);
                    $blocked = ! $summary['is_skipped'] && ($this->roomHasMissingReadings($index) || $this->roomHasBlockingLowReadings($index) || $this->roomHasInvalidPeriodOrDuplicate($index));
                    [$badgeLabel, $badgeTone] = match (true) {
                        $summary['is_skipped'] => [__('Skipped'), 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'],
                        $blocked => [__('Needs attention'), 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300'],
                        $summary['warning_count'] > 0 => [__('Warning'), 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'],
                        default => [__('Ready'), 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'],
                    };
                @endphp
                <article wire:key="rw-m-billing-review-{{ $index }}" id="rw-m-billing-review-{{ $index }}" class="rw-m-card space-y-3 {{ $summary['is_skipped'] ? 'opacity-70' : '' }}" data-blocked="{{ $blocked ? 'true' : 'false' }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-lg leading-tight font-bold">{{ __('Room') }} {{ $room['room_number'] }}</p>
                            <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ $room['occupant_name'] }}</p>
                        </div>
                        <span class="rw-m-badge shrink-0 {{ $badgeTone }}">{{ $badgeLabel }}</span>
                    </div>

                    @unless ($summary['is_skipped'])
                        <div class="flex items-end justify-between gap-3 text-sm">
                            <div class="text-slate-500 dark:text-slate-400">
                                <p>{{ Carbon::parse($room['period_start'])->format('d M') }} – {{ Carbon::parse($room['period_end'])->format('d M Y') }}</p>
                                <p>{{ __('Rent') }}: <span class="font-semibold text-slate-700 dark:text-slate-200">{{ Money::format($summary['rent'], $summary['rent_currency'] ?? 'USD') }}</span></p>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Estimated total') }}</p>
                                <p class="text-lg font-bold text-emerald-700 tabular-nums dark:text-emerald-400">{{ $summary['estimated_total_display'] }}</p>
                            </div>
                        </div>

                        @foreach ([
                            [__('Charges to bill'), $summary['charges_to_bill'], 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'],
                            [__('Free or waived'), $summary['free_or_waived'], 'bg-amber-50 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'],
                            [__('Not billed'), $summary['not_billed'], 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'],
                        ] as [$groupLabel, $charges, $chipTone])
                            @if ($charges !== [])
                                <div>
                                    <p class="mb-1 text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $groupLabel }}</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($charges as $charge)
                                            <span class="rw-m-badge {{ $chipTone }}">{{ $charge }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach

                        @if ($summary['warnings'] || $this->roomHasMissingReadings($index))
                            <ul class="list-disc space-y-0.5 rounded-xl bg-amber-50 py-2 pr-3 pl-7 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
                                @foreach (array_unique(array_merge($summary['warnings'], $this->roomHasMissingReadings($index) ? [__('Missing reading')] : [])) as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        @endif
                    @endunless

                    <div class="grid grid-cols-2 gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                        <button type="button" wire:click="editRoom({{ $index }})" class="rw-m-btn rw-m-btn-secondary min-h-11 text-sm" id="rw-m-billing-edit-{{ $index }}">{{ __('Edit') }}</button>
                        <button type="button" wire:click="toggleRoomSkip({{ $index }})" class="rw-m-btn min-h-11 border text-sm {{ $summary['is_skipped'] ? 'border-emerald-200 text-emerald-700 active:bg-emerald-50 dark:border-emerald-900/50 dark:text-emerald-400' : 'border-red-200 text-red-600 active:bg-red-50 dark:border-red-900/50 dark:text-red-400' }}" id="rw-m-billing-toggle-skip-{{ $index }}">
                            {{ $summary['is_skipped'] ? __('Unskip room') : __('Skip room') }}
                        </button>
                    </div>
                </article>
            @endforeach

            <div class="rw-m-billing-bar grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-2">
                <button type="button" wire:click="backToReadings" class="rw-m-btn rw-m-btn-secondary" id="rw-m-billing-back-to-readings">{{ __('Back') }}</button>
                @if ($readOnly)
                    <button type="button" disabled class="rw-m-btn rw-m-btn-primary" id="rw-m-billing-create" aria-describedby="rw-m-billing-read-only">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4" aria-hidden="true"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1Zm3 8V5.5a3 3 0 1 0-6 0V9h6Z" clip-rule="evenodd" /></svg>
                        {{ __('Create invoices') }}
                    </button>
                @elseif ($blockingIndex !== null)
                    {{-- Server path: the toast says why, nothing opens. --}}
                    <button type="button" wire:click="openCreateConfirmation" class="rw-m-btn rw-m-btn-primary" id="rw-m-billing-create">{{ __('Create invoices') }} ({{ $invoiceCount }})</button>
                @else
                    <button type="button" x-on:click="$dispatch('rw-m-sheet-open', { name: 'billing-confirm' })" class="rw-m-btn rw-m-btn-primary" id="rw-m-billing-create">{{ __('Create invoices') }} ({{ $invoiceCount }})</button>
                @endif
            </div>
        </div>

    {{-- ════════════════ Result ════════════════ --}}
    @else
        @php
            $counts = $this->resultChargeCounts();
            $failed = $resultSummary['failed'] > 0;
        @endphp
        <div class="space-y-4" id="rw-m-billing-result">
            <div class="rw-m-card py-6 text-center">
                <div class="mx-auto flex size-14 items-center justify-center rounded-full {{ $failed ? 'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400' : 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" class="size-7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                </div>
                <h2 class="mt-3 text-xl font-bold">{{ __('Billing complete') }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('All processed rooms have been billed for this period.') }}</p>
            </div>

            <dl class="rw-m-card divide-y divide-slate-100 p-0 dark:divide-slate-800">
                @foreach ([
                    [__('Invoices created'), $resultSummary['created'], 'text-emerald-600 dark:text-emerald-400', 'created'],
                    [__('Rooms skipped'), $resultSummary['skipped'], '', 'skipped'],
                    [__('Failed runs'), $resultSummary['failed'], $failed ? 'text-red-600 dark:text-red-400' : '', 'failed'],
                ] as [$label, $value, $tone, $key])
                    <div class="flex min-h-12 items-center justify-between px-4">
                        <dt class="text-slate-600 dark:text-slate-300">{{ $label }}</dt>
                        <dd class="text-lg font-bold tabular-nums {{ $tone }}" id="rw-m-billing-result-{{ $key }}">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="rw-m-card">
                <p class="mb-2 text-sm font-semibold text-slate-600 dark:text-slate-300">{{ __('Charges') }}</p>
                <dl class="space-y-1 text-sm">
                    @foreach (array_filter([
                        __('Billed') => $counts['billed'],
                        __('Free or waived') => $counts['free'] + $counts['waived'],
                        __('Not applicable') => $counts['not_applicable'],
                        __('Skipped this cycle') => $counts['skipped_this_cycle'],
                        __('Custom amount') => $counts['custom'],
                    ], fn ($n, $label) => $n > 0 || $label === __('Billed'), ARRAY_FILTER_USE_BOTH) as $label => $n)
                        <div class="flex justify-between">
                            <dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                            <dd class="font-semibold tabular-nums">{{ $n }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            @if ($resultSummary['failures'])
                <div class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-100" id="rw-m-billing-failures">
                    <p class="mb-1 font-semibold">{{ __('Details of failures') }}</p>
                    <ul class="space-y-1">
                        @foreach ($resultSummary['failures'] as $failure)
                            <li><span class="font-semibold">{{ __('Room') }} {{ $failure['room_number'] }}</span>: {{ $failure['message'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid gap-2">
                <a href="{{ route('mobile.invoices') }}" wire:navigate class="rw-m-btn rw-m-btn-primary w-full" id="rw-m-billing-view-invoices">{{ __('View invoices') }}</a>
                <button type="button" wire:click="billAgain" class="rw-m-btn rw-m-btn-secondary w-full" id="rw-m-billing-again">{{ __('Back to start') }}</button>
            </div>
        </div>
    @endif

    {{-- ── Confirmation sheet: always in the DOM so it opens instantly. ── --}}
    @php($rateInfo = $this->getExchangeRateInfo())
    <x-mobile.sheet name="billing-confirm" :label="__('Confirm invoice creation')">
        <div class="space-y-4 px-5 pt-2">
            <div>
                <h2 class="text-lg font-bold">{{ __('Confirm invoice creation') }}</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('You are about to generate monthly invoices for this property.') }}</p>
            </div>

            <dl class="divide-y divide-slate-100 rounded-2xl bg-slate-50 px-4 text-sm dark:divide-slate-800 dark:bg-slate-800/60">
                <div class="flex justify-between py-2.5"><dt class="text-slate-500 dark:text-slate-400">{{ __('Billing date') }}</dt><dd class="font-semibold">{{ $this->issueDateLabel() }}</dd></div>
                <div class="flex justify-between py-2.5"><dt class="text-slate-500 dark:text-slate-400">{{ __('Invoices to create') }}</dt><dd class="font-semibold tabular-nums" id="rw-m-billing-confirm-count">{{ $this->estimatedInvoiceCount() }}</dd></div>
                <div class="flex justify-between py-2.5"><dt class="text-slate-500 dark:text-slate-400">{{ __('Rooms to skip') }}</dt><dd class="font-semibold tabular-nums">{{ $this->skippedRoomCount() }}</dd></div>
                @if ($this->roomsWithWarningsCount() > 0)
                    <div class="flex justify-between py-2.5 text-amber-700 dark:text-amber-400"><dt>{{ __('Rooms with warnings') }}</dt><dd class="font-semibold tabular-nums">{{ $this->roomsWithWarningsCount() }}</dd></div>
                @endif
                <div class="flex justify-between gap-3 py-2.5">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('Exchange rate') }}</dt>
                    <dd class="text-right"><span class="font-semibold">1 USD = {{ number_format($rateInfo['rate'], 0) }} KHR</span><span class="block text-xs text-slate-500 dark:text-slate-400">{{ __('Source') }}: {{ $rateInfo['source'] }}</span></dd>
                </div>
            </dl>

            <div class="grid grid-cols-2 gap-2">
                <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'billing-confirm' })" class="rw-m-btn rw-m-btn-secondary" id="rw-m-billing-confirm-cancel">{{ __('Cancel') }}</button>
                <button type="button" x-on:click="create()" wire:loading.attr="disabled" wire:target="createInvoices" class="rw-m-btn rw-m-btn-primary" id="rw-m-billing-confirm-create">
                    <span wire:loading.remove wire:target="createInvoices">{{ __('Create invoices') }}</span>
                    <span wire:loading wire:target="createInvoices">{{ __('Creating…') }}</span>
                </button>
            </div>
        </div>
    </x-mobile.sheet>

    {{-- Holds the keyboard open between two rooms (see rwMobileBilling.go()). --}}
    <input type="text" inputmode="decimal" x-ref="proxy" class="rw-m-billing-proxy" tabindex="-1" aria-hidden="true" autocomplete="off">
</div>
