@php
    use App\Support\Money;

    $label = 'mb-1 block text-sm font-semibold';
    $error = 'mt-1 text-sm text-red-600 dark:text-red-400';
    $muted = 'text-sm text-slate-500 dark:text-slate-400';
    $row = 'flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/60';
    $tenantName = $rental?->occupant_name ?: ($rental?->tenant?->name ?? '—');
@endphp

<div id="rw-m-end-tenancy">
    {{-- ── Header ── --}}
    <div class="flex items-center gap-1">
        <a
            href="{{ route('mobile.room', $unit) }}"
            wire:navigate
            class="-ml-2 inline-flex size-11 shrink-0 items-center justify-center rounded-full text-slate-600 transition active:scale-95 active:bg-slate-200 dark:text-slate-300 dark:active:bg-slate-800"
            aria-label="{{ __('Room :room', ['room' => $unit->room_number]) }}"
            id="rw-m-end-tenancy-back"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.25" stroke="currentColor" class="size-6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
        </a>
        <h1 class="rw-m-title min-w-0 flex-1 truncate">{{ __('End tenancy') }}</h1>
    </div>
    <p class="-mt-2 mb-3 {{ $muted }}">{{ __('Room :room', ['room' => $unit->room_number]) }} · {{ $unit->property?->name }}</p>

    @if ($step === 'done' && $result)
        {{-- ── Done ── --}}
        <section class="rw-m-card text-center" id="rw-m-end-tenancy-done">
            <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="size-7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            </div>
            <h2 class="mt-3 text-xl font-bold">{{ __('Tenancy ended') }}</h2>

            <dl class="mt-4 space-y-2 text-left">
                <div class="{{ $row }}"><dt class="{{ $muted }}">{{ __('Room') }}</dt><dd class="font-semibold">{{ $result['room_number'] }}</dd></div>
                <div class="{{ $row }}"><dt class="{{ $muted }}">{{ __('Tenant') }}</dt><dd class="truncate font-semibold">{{ $result['tenant_name'] }}</dd></div>
                <div class="{{ $row }}"><dt class="{{ $muted }}">{{ __('Outcome') }}</dt><dd class="font-semibold">{{ $result['rental_status'] }}</dd></div>
                <div class="{{ $row }}"><dt class="{{ $muted }}">{{ __('Room status') }}</dt><dd class="font-semibold" id="rw-m-end-tenancy-room-status">{{ $result['unit_status'] }}</dd></div>
            </dl>

            <div class="mt-5 grid gap-2">
                <a href="{{ route('mobile.room', $unit) }}" wire:navigate class="rw-m-btn rw-m-btn-primary" id="rw-m-end-tenancy-room">{{ __('Back to room') }}</a>
                <a href="{{ route('mobile.rooms') }}" wire:navigate class="rw-m-btn rw-m-btn-secondary">{{ __('Rooms') }}</a>
            </div>
        </section>
    @elseif (! $available)
        {{-- ── Nothing to end ── --}}
        <section class="rw-m-card text-center" id="rw-m-end-tenancy-none">
            <p class="font-semibold">{{ __('This room has no active tenancy.') }}</p>
            <p class="mt-1 {{ $muted }}">{{ __('Room status: :status', ['status' => $unit->status->getLabel()]) }}</p>
            <div class="mt-4 grid gap-2">
                <a href="{{ route('mobile.room', $unit) }}" wire:navigate class="rw-m-btn rw-m-btn-primary">{{ __('Back to room') }}</a>
                <a href="{{ route('mobile.rooms') }}" wire:navigate class="rw-m-btn rw-m-btn-secondary">{{ __('Rooms') }}</a>
            </div>
        </section>
    @else
        <form
            wire:submit="submit"
            wire:confirm="{{ __('End the tenancy for :tenant?', ['tenant' => $tenantName]) }}"
            class="space-y-3"
            id="rw-m-end-tenancy-form"
        >
            {{-- ── Whose tenancy ── --}}
            <section class="rw-m-card">
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Tenant') }}</p>
                <p class="text-lg font-bold">{{ $tenantName }}</p>
                <dl class="mt-3 grid grid-cols-2 gap-3">
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Start date') }}</dt>
                        <dd class="font-semibold">{{ $rental->start_date?->format('d M Y') ?: '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Monthly rent') }}</dt>
                        <dd class="font-semibold">{{ Money::formatForRecord($rental->monthly_rent, $rental) }}</dd>
                    </div>
                </dl>
            </section>

            {{-- ── How it ends ── --}}
            <section class="rw-m-card space-y-4">
                <div>
                    <label for="rw-m-et-date" class="{{ $label }}">{{ __('End date') }} <span class="text-red-500" aria-hidden="true">*</span></label>
                    <input type="date" id="rw-m-et-date" wire:model="endDate" class="rw-m-input" @if ($rental->start_date) min="{{ $rental->start_date->toDateString() }}" @endif required>
                    @error('endDate') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="rw-m-et-status" class="{{ $label }}">{{ __('Outcome') }} <span class="text-red-500" aria-hidden="true">*</span></label>
                    <select id="rw-m-et-status" wire:model="status" class="rw-m-input">
                        @foreach ($outcomes as $value => $outcome)
                            <option value="{{ $value }}">{{ $outcome }}</option>
                        @endforeach
                    </select>
                    @error('status') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <label class="flex min-h-11 items-center justify-between gap-3" for="rw-m-et-free">
                    <span class="text-sm font-semibold">{{ __('Mark room as available') }}</span>
                    <input type="checkbox" id="rw-m-et-free" wire:model="freeRoom" class="size-6 shrink-0 accent-emerald-600">
                </label>
            </section>

            @error('unitId')
                <div class="rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700 dark:bg-red-500/10 dark:text-red-300" role="alert" id="rw-m-et-unit-error">{{ $message }}</div>
            @enderror

            <button
                type="submit"
                class="rw-m-btn w-full bg-red-600 text-white active:bg-red-700 dark:bg-red-600 dark:active:bg-red-700"
                wire:loading.attr="disabled"
                wire:target="submit"
                id="rw-m-end-tenancy-submit"
            >
                <span wire:loading.remove wire:target="submit">{{ __('End tenancy') }}</span>
                <span wire:loading wire:target="submit">{{ __('Saving…') }}</span>
            </button>
        </form>
    @endif
</div>
