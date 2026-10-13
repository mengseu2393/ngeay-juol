@php
    use App\Enums\ReadingType;
    use App\Livewire\Mobile\Rooms;
    use App\Services\MeterUtilities;
    use App\Support\Money;
    use App\Support\UtilityCatalog;
    use Illuminate\Support\Carbon;

    $fmt = fn ($v) => MeterUtilities::trimReading($v);
    $uom = $utility?->unit_of_measure;
    $suffix = $uom ? ' '.$uom : '';
    $tenantName = $rental?->occupant_name ?: $rental?->tenant?->name;
    $today = now()->toDateString();

    $label = 'mb-1 block text-sm font-semibold';
    $error = 'mt-1 text-sm text-red-600 dark:text-red-400';
    $muted = 'text-sm text-slate-500 dark:text-slate-400';
    $danger = 'rw-m-btn border border-red-200 bg-red-50 text-red-700 active:bg-red-100 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300';
    $chip = 'rw-m-badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';
@endphp

<div x-data="rwMobileRoom()" x-on:rw-m-sheet-closed.window="closed($event)" id="rw-m-room">
    {{-- ── Header ── --}}
    <div class="flex items-center gap-1">
        <a
            href="{{ route('mobile.rooms') }}"
            wire:navigate
            class="-ml-2 inline-flex size-11 shrink-0 items-center justify-center rounded-full text-slate-600 transition active:scale-95 active:bg-slate-200 dark:text-slate-300 dark:active:bg-slate-800"
            aria-label="{{ __('Rooms') }}"
            id="rw-m-room-back"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.25" stroke="currentColor" class="size-6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
        </a>
        <h1 class="rw-m-title min-w-0 flex-1 truncate">{{ __('Room :room', ['room' => $unit->room_number]) }}</h1>
        <span class="rw-m-badge {{ Rooms::badgeClass($unit->status->getColor()) }} shrink-0">{{ $unit->status->getLabel() }}</span>
    </div>

    <div class="space-y-3">
        {{-- ── The room ── --}}
        <section class="rw-m-card" id="rw-m-room-details">
            <p class="{{ $muted }}">
                {{ $unit->property?->name }}
                @if ($unit->floor_number !== null && $unit->floor_number !== '') · {{ __('Floor :n', ['n' => $unit->floor_number]) }} @endif
            </p>
            <dl class="mt-3 grid grid-cols-2 gap-3">
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Rent') }}</dt>
                    <dd class="font-semibold">{{ Money::formatForRecord($unit->rent_amount, $unit) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Room type') }}</dt>
                    <dd class="font-semibold">{{ $unit->room_type ?: '—' }}</dd>
                </div>
            </dl>
            @if ($can['editRoom'])
                <a href="{{ route('mobile.room.edit', $unit) }}" wire:navigate class="rw-m-btn rw-m-btn-secondary mt-4 w-full" id="rw-m-room-edit">{{ __('Edit room') }}</a>
            @endif
        </section>

        {{-- ── Agreement ── --}}
        <section class="rw-m-card" id="rw-m-room-agreement">
            <h2 class="text-base font-bold">{{ __('Agreement') }}</h2>

            @if ($rental)
                <dl class="mt-3 grid grid-cols-2 gap-3">
                    <div class="col-span-2">
                        <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Tenant') }}</dt>
                        <dd class="text-lg font-semibold">{{ $tenantName ?: '—' }}</dd>
                    </div>
                    @if ($rental->occupant_phone)
                        <div class="col-span-2">
                            <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Phone') }}</dt>
                            <dd><a href="tel:{{ preg_replace('/[^0-9+]/', '', $rental->occupant_phone) }}" class="font-semibold text-brand-700 underline-offset-2 active:underline dark:text-emerald-400">{{ $rental->occupant_phone }}</a></dd>
                        </div>
                    @endif
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Start date') }}</dt>
                        <dd class="font-semibold">{{ $rental->start_date?->format('d M Y') ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Monthly rent') }}</dt>
                        <dd class="font-semibold">{{ Money::formatForRecord($rental->monthly_rent, $rental) }}</dd>
                    </div>
                    @if ((float) $rental->security_deposit > 0)
                        <div>
                            <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Security deposit') }}</dt>
                            <dd class="font-semibold">{{ Money::formatForRecord($rental->security_deposit, $rental) }}</dd>
                        </div>
                    @endif
                </dl>

                <div class="mt-4 grid grid-cols-2 gap-2">
                    <a href="{{ route('mobile.tenant', $rental) }}" wire:navigate class="rw-m-btn rw-m-btn-secondary text-sm {{ $can['end'] ? '' : 'col-span-2' }}" id="rw-m-room-view-agreement">{{ __('View agreement') }}</a>
                    @if ($can['end'])
                        <a href="{{ route('mobile.room.end-tenancy', $unit) }}" wire:navigate class="{{ $danger }} text-sm" id="rw-m-room-end-tenancy">{{ __('End tenancy') }}</a>
                    @endif
                </div>
            @else
                <p class="mt-1 {{ $muted }}">{{ __('No active agreement') }}</p>
                @if ($can['add'])
                    <a href="{{ route('mobile.room.add-tenant', $unit) }}" wire:navigate class="rw-m-btn rw-m-btn-primary mt-4 w-full" id="rw-m-room-add-tenant">{{ __('Add tenant') }}</a>
                @endif
            @endif
        </section>

        {{-- ── Meters ── --}}
        <section class="rw-m-card" id="rw-m-room-meters">
            <h2 class="text-base font-bold">{{ __('Meter readings') }}</h2>

            @if ($utilities->isEmpty())
                <p class="mt-1 {{ $muted }}">{{ __('No metered utilities are set up for this property yet.') }}</p>
            @else
                @if ($utilities->count() > 1)
                    <div class="-mx-4 mt-3 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]" role="group" aria-label="{{ __('Utility') }}">
                        @foreach ($utilities as $u)
                            <button
                                type="button"
                                wire:click="selectUtility({{ $u->id }})"
                                class="rw-m-pill"
                                aria-pressed="{{ $u->id === $utilityId ? 'true' : 'false' }}"
                                id="rw-m-room-utility-{{ $u->id }}"
                            >{{ UtilityCatalog::label($u->name) }}</button>
                        @endforeach
                    </div>
                @else
                    <p class="mt-1 font-semibold">{{ UtilityCatalog::label($utility->name) }}</p>
                @endif

                <p class="mt-2 {{ $muted }}" id="rw-m-room-reading-hint">{{ MeterUtilities::readingHint($unit->id, $utility->id, $uom) }}</p>

                @if ($can['readings'])
                    <div class="mt-4 grid grid-cols-2 gap-2">
                        <button type="button" x-on:click="open('reading', () => $wire.openPanel('reading'))" class="rw-m-btn rw-m-btn-primary text-sm" id="rw-m-room-record">{{ __('Record meter reading') }}</button>
                        <button type="button" x-on:click="open('reset', () => $wire.openPanel('reset'))" class="rw-m-btn rw-m-btn-secondary text-sm" id="rw-m-room-reset">{{ __('Reset reading') }}</button>
                    </div>
                @endif
            @endif
        </section>

        {{-- ── History ── --}}
        @if ($utilities->isNotEmpty())
            <section class="rw-m-card" id="rw-m-room-history">
                <h2 class="text-base font-bold">{{ __('Reading history') }}</h2>

                <div class="mt-3 divide-y divide-slate-100 transition-opacity dark:divide-slate-800" wire:loading.class="opacity-50" wire:target="selectUtility">
                    @forelse ($readings as $r)
                        @php $invoice = $invoices->get($r->id); @endphp
                        <div class="py-3 first:pt-0 last:pb-0" wire:key="rw-m-reading-{{ $r->id }}" id="rw-m-reading-{{ $r->id }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-semibold">{{ $r->reading_date->format('d M Y') }}</span>
                                <span class="flex flex-wrap gap-1">
                                    @if ($r->is_reset)<span class="{{ $chip }}">{{ __('Reset') }}</span>@endif
                                    @if ($r->reading_type)<span class="{{ $chip }}">{{ $r->reading_type->getLabel() }}</span>@endif
                                    @if ($invoice)<span class="rw-m-badge bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">{{ __('Billed') }} · {{ $invoice->invoice_number }}</span>@endif
                                </span>
                            </div>
                            <div class="mt-2 grid grid-cols-3 gap-2 text-sm">
                                <div><p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Previous') }}</p><p class="font-semibold tabular-nums">{{ $r->old_reading !== null ? $fmt($r->old_reading) : '—' }}</p></div>
                                <div><p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Current') }}</p><p class="font-semibold tabular-nums">{{ $r->new_reading !== null ? $fmt($r->new_reading) : '—' }}</p></div>
                                <div><p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Used') }}</p><p class="font-semibold tabular-nums">{{ $fmt($r->amount_used) }}{{ $suffix }}</p></div>
                            </div>
                            @if ($service->isEditable($r))
                                @can('update', $r)
                                    <button
                                        type="button"
                                        x-on:click="open('correct', () => $wire.edit({{ $r->id }}))"
                                        class="rw-m-btn rw-m-btn-secondary mt-2 min-h-10 px-4 text-sm"
                                        id="rw-m-reading-edit-{{ $r->id }}"
                                    >{{ __('Edit') }}</button>
                                @endcan
                            @endif
                        </div>
                    @empty
                        <p class="{{ $muted }}" id="rw-m-room-no-readings">{{ __('No readings recorded yet.') }}</p>
                    @endforelse
                </div>

                @if ($hasMore)
                    <div x-intersect.margin.300px="$wire.loadMore()" class="mt-3">
                        <button type="button" wire:click="loadMore" class="rw-m-btn rw-m-btn-secondary w-full" id="rw-m-room-history-more">
                            <span wire:loading.remove wire:target="loadMore">{{ __('Load more') }}</span>
                            <span wire:loading wire:target="loadMore">{{ __('Loading…') }}</span>
                        </button>
                    </div>
                @endif
            </section>
        @endif
    </div>

    {{-- The sheets' forms are server state (which form is open is ManagesUnit's
         $panel / $editingId), so each sheet opens at once and shows a placeholder
         until its form arrives. --}}

    {{-- ── Record a reading ── --}}
    <x-mobile.sheet name="reading" :label="__('Record meter reading')">
        @if ($panel === 'reading' && $utility)
            <form class="space-y-4 px-5 pt-2" wire:submit="saveReading" id="rw-m-reading-form">
                <div>
                    <h2 class="text-lg font-bold">{{ __('Record meter reading') }}</h2>
                    <p class="{{ $muted }}">{{ __('Room :room', ['room' => $unit->room_number]) }} · {{ UtilityCatalog::label($utility->name) }}</p>
                    <p class="mt-1 {{ $muted }}">{{ MeterUtilities::readingHint($unit->id, $utility->id, $uom) }}</p>
                </div>

                <div>
                    <label for="rw-m-reading-new" class="{{ $label }}">{{ __('New reading') }}@if ($uom) ({{ $uom }})@endif</label>
                    <input id="rw-m-reading-new" type="text" inputmode="decimal" class="rw-m-input" wire:model="newReading" placeholder="0.000" autocomplete="off" enterkeyhint="done">
                    @error('newReading') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="rw-m-reading-date" class="{{ $label }}">{{ __('Reading date') }}</label>
                        <input id="rw-m-reading-date" type="date" max="{{ $today }}" class="rw-m-input" wire:model="date">
                        @error('date') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="rw-m-reading-type" class="{{ $label }}">{{ __('Reading type') }}</label>
                        <select id="rw-m-reading-type" class="rw-m-input" wire:model="type">
                            <option value="{{ ReadingType::Actual->value }}">{{ ReadingType::Actual->getLabel() }}</option>
                            <option value="{{ ReadingType::Estimated->value }}">{{ ReadingType::Estimated->getLabel() }}</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-2 pt-1">
                    <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'reading' })" class="rw-m-btn rw-m-btn-secondary">{{ __('Cancel') }}</button>
                    <button type="submit" class="rw-m-btn rw-m-btn-primary" wire:loading.attr="disabled" wire:target="saveReading" id="rw-m-reading-save">
                        <span wire:loading.remove wire:target="saveReading">{{ __('Save') }}</span>
                        <span wire:loading wire:target="saveReading">{{ __('Saving…') }}</span>
                    </button>
                </div>
            </form>
        @else
            <div class="mx-5 mt-2 h-64 animate-pulse rounded-2xl bg-slate-100 dark:bg-slate-800" aria-busy="true"></div>
        @endif
    </x-mobile.sheet>

    {{-- ── Reset the meter ── --}}
    <x-mobile.sheet name="reset" :label="__('Reset reading')">
        @if ($panel === 'reset' && $utility)
            <form class="space-y-4 px-5 pt-2" wire:submit="resetReading" id="rw-m-reset-form">
                <div>
                    <h2 class="text-lg font-bold">{{ __('Reset reading') }}</h2>
                    <p class="{{ $muted }}">{{ __('Room :room', ['room' => $unit->room_number]) }} · {{ UtilityCatalog::label($utility->name) }}</p>
                    <p class="mt-1 {{ $muted }}">{{ __('Use this when the meter was replaced or restarted. Past readings are kept; the next reading is measured from the starting point you enter.') }}</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="rw-m-reset-start" class="{{ $label }}">{{ __('New starting reading') }}@if ($uom) ({{ $uom }})@endif</label>
                        <input id="rw-m-reset-start" type="text" inputmode="decimal" class="rw-m-input" wire:model="startingReading" autocomplete="off" enterkeyhint="done">
                        @error('startingReading') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="rw-m-reset-date" class="{{ $label }}">{{ __('Reading date') }}</label>
                        <input id="rw-m-reset-date" type="date" max="{{ $today }}" class="rw-m-input" wire:model="date">
                        @error('date') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                </div>

                <p class="rounded-xl bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ __('Usage between the last reading and this date will not be billed. Record a final reading first if you need to bill it.') }}</p>

                <label class="flex min-h-11 items-start gap-3">
                    <input type="checkbox" wire:model="confirmReset" id="rw-m-reset-confirm" class="mt-0.5 size-5 shrink-0 accent-emerald-600">
                    <span class="text-sm">{{ __('I understand this sets a new starting point for this room.') }}</span>
                </label>
                @error('confirmReset') <p class="{{ $error }}">{{ $message }}</p> @enderror

                <div class="grid grid-cols-2 gap-2 pt-1">
                    <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'reset' })" class="rw-m-btn rw-m-btn-secondary">{{ __('Cancel') }}</button>
                    <button type="submit" class="{{ $danger }}" wire:loading.attr="disabled" wire:target="resetReading" id="rw-m-reset-save">{{ __('Reset reading') }}</button>
                </div>
            </form>
        @else
            <div class="mx-5 mt-2 h-72 animate-pulse rounded-2xl bg-slate-100 dark:bg-slate-800" aria-busy="true"></div>
        @endif
    </x-mobile.sheet>

    {{-- ── Correct a past reading: edit → review what changes → confirm ── --}}
    <x-mobile.sheet name="correct" :label="__('Correct reading')">
        @if ($editingId)
            @if (! $confirming)
                <form class="space-y-4 px-5 pt-2" wire:submit="reviewEdit" id="rw-m-correct-form">
                    <div>
                        <h2 class="text-lg font-bold">{{ __('Correct reading') }}</h2>
                        @if ($editingUsage)
                            <p class="{{ $muted }}">{{ __('Previous: :value', ['value' => $fmt($editingUsage->old_reading).$suffix]) }}</p>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="rw-m-correct-new" class="{{ $label }}">{{ __('New reading') }}</label>
                            <input id="rw-m-correct-new" type="text" inputmode="decimal" class="rw-m-input" wire:model="editReading" autocomplete="off" enterkeyhint="done">
                        </div>
                        <div>
                            <label for="rw-m-correct-date" class="{{ $label }}">{{ __('Reading date') }}</label>
                            <input id="rw-m-correct-date" type="date" max="{{ $today }}" class="rw-m-input" wire:model="editDate">
                        </div>
                    </div>
                    @error('editReading') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    @error('editDate') <p class="{{ $error }}">{{ $message }}</p> @enderror

                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'correct' })" class="rw-m-btn rw-m-btn-secondary">{{ __('Cancel') }}</button>
                        <button type="submit" class="rw-m-btn rw-m-btn-primary" wire:loading.attr="disabled" wire:target="reviewEdit" id="rw-m-correct-review">{{ __('Review change') }}</button>
                    </div>
                </form>
            @else
                <form class="space-y-4 px-5 pt-2" wire:submit="saveEdit" id="rw-m-correct-confirm-form">
                    <div>
                        <h2 class="text-lg font-bold">{{ __('Confirm change') }}</h2>
                        <p class="{{ $muted }}">{{ __('Please check what will change before saving.') }}</p>
                    </div>

                    @foreach ($changes as $c)
                        <div class="rounded-xl border p-3 {{ $c['is_target'] ? 'border-brand-500 bg-brand-50 dark:border-emerald-500/50 dark:bg-emerald-500/10' : 'border-slate-200 dark:border-slate-700' }}">
                            <p class="flex items-center justify-between gap-2 font-semibold">
                                {{ Carbon::parse($c['after']['date'])->format('d M Y') }}
                                @unless ($c['is_target'])<span class="{{ $chip }}">{{ __('Recalculated') }}</span>@endunless
                            </p>
                            <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm tabular-nums">
                                <dt class="text-slate-500 dark:text-slate-400">{{ __('Previous') }}</dt><dd>{{ $fmt($c['before']['old_reading']) }} → <strong>{{ $fmt($c['after']['old_reading']) }}</strong></dd>
                                <dt class="text-slate-500 dark:text-slate-400">{{ __('Current') }}</dt><dd>{{ $fmt($c['before']['new_reading']) }} → <strong>{{ $fmt($c['after']['new_reading']) }}</strong></dd>
                                <dt class="text-slate-500 dark:text-slate-400">{{ __('Used') }}</dt><dd>{{ $fmt($c['before']['amount_used']) }} → <strong>{{ $fmt($c['after']['amount_used']) }}</strong>{{ $suffix }}</dd>
                            </dl>
                        </div>
                    @endforeach

                    <label class="flex min-h-11 items-start gap-3">
                        <input type="checkbox" wire:model="confirmedEdit" id="rw-m-correct-confirm" class="mt-0.5 size-5 shrink-0 accent-emerald-600">
                        <span class="text-sm">{{ __('I understand this changes the recorded readings and any draft invoice using them.') }}</span>
                    </label>
                    @error('confirmedEdit') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    @error('editReading') <p class="{{ $error }}">{{ $message }}</p> @enderror

                    <div class="grid grid-cols-2 gap-2 pt-1">
                        <button type="button" wire:click="backToEdit" class="rw-m-btn rw-m-btn-secondary" id="rw-m-correct-back">{{ __('Back') }}</button>
                        <button type="submit" class="{{ $danger }}" wire:loading.attr="disabled" wire:target="saveEdit" id="rw-m-correct-save">{{ __('Confirm change') }}</button>
                    </div>
                </form>
            @endif
        @else
            <div class="mx-5 mt-2 h-56 animate-pulse rounded-2xl bg-slate-100 dark:bg-slate-800" aria-busy="true"></div>
        @endif
    </x-mobile.sheet>
</div>
