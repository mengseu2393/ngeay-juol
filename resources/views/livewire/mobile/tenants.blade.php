@php
    use App\Enums\RentalStatus;
    use App\Livewire\Mobile\Tenants;
    use App\Support\ActiveProperty;
    use App\Support\Money;

    $filters = ['active' => __('Active'), 'due' => __('Has balance due'), 'ended' => __('Ended'), 'all' => __('All')];

    $badge = fn (?string $color) => match ($color) {
        'success' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
        'warning' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
        default => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    };
@endphp

<div x-data="rwMobileTenants">
    <div class="flex items-end justify-between gap-3">
        <h1 class="rw-m-title">{{ __('Tenants') }}</h1>
        @if (ActiveProperty::id())
            <button
                type="button"
                x-on:click="$dispatch('rw-m-sheet-open', { name: 'add-tenant' })"
                class="rw-m-btn rw-m-btn-primary mb-3 min-h-10 px-3 text-sm"
                id="rw-m-tenants-add"
            >
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4" aria-hidden="true"><path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" /></svg>
                {{ __('Add tenant') }}
            </button>
        @endif
    </div>

    @if (! ActiveProperty::id())
        <div class="rw-m-card mt-2 text-center">
            <p class="font-semibold">{{ __('Choose a property first') }}</p>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Use the property switcher above to select a property.') }}</p>
        </div>
    @else
        {{-- Filters: server-side, so ended tenancies aren't rendered until asked for. --}}
        <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]" role="group" aria-label="{{ __('Filter') }}">
            @foreach ($filters as $value => $label)
                <button
                    type="button"
                    wire:click="$set('filter', '{{ $value }}')"
                    class="rw-m-pill"
                    aria-pressed="{{ $filter === $value ? 'true' : 'false' }}"
                    id="rw-m-tenant-filter-{{ $value }}"
                >{{ $label }}</button>
            @endforeach
        </div>

        {{-- Search: instant, over the cards already on the page. --}}
        <div class="relative mt-3">
            <svg class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
            <input
                type="search"
                x-model="q"
                placeholder="{{ __('Tenant name, phone or room…') }}"
                class="rw-m-input pl-11"
                id="rw-m-tenant-search"
                enterkeyhint="search"
                autocomplete="off"
                aria-label="{{ __('Search') }}"
            >
        </div>

        <div class="mt-4 space-y-3 transition-opacity" wire:loading.class="opacity-50" wire:target="filter">
            @forelse ($tenants as $rental)
                @php
                    $primaryName = $rental->occupant_name ?: ($rental->tenant?->name ?? __('Tenant'));
                    $coOccupants = $rental->occupants
                        ->filter(fn ($o) => ! $o->isPrimary() && $o->occupant_name && $o->occupant_name !== $primaryName)
                        ->pluck('occupant_name');
                    $phone = $rental->occupant_phone;
                    $totalDue = $rental->invoices->sum(fn ($invoice) => $invoice->balance_usd + $invoice->balance_khr);
                    $searchText = mb_strtolower(trim(implode(' ', array_filter([
                        $primaryName,
                        $phone,
                        $rental->tenant?->name,
                        $rental->unit?->room_number,
                        ...$rental->occupants->flatMap(fn ($o) => [$o->occupant_name, $o->occupant_phone])->all(),
                    ]))));
                @endphp

                <article
                    wire:key="rw-m-tenant-{{ $rental->id }}"
                    id="rw-m-tenant-{{ $rental->id }}"
                    class="rw-m-card flex items-stretch overflow-hidden p-0"
                    data-rw-m-tenant
                    data-q="{{ $searchText }} {{ preg_replace('/\D+/', '', $searchText) }}"
                    x-show="matches($el)"
                >
                    <a
                        href="{{ route('mobile.tenant', $rental) }}"
                        wire:navigate
                        class="flex min-w-0 flex-1 items-center gap-3 p-4 transition-colors active:bg-slate-50 dark:active:bg-slate-800/60"
                        id="rw-m-tenant-open-{{ $rental->id }}"
                    >
                        <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-sm font-bold text-brand-700 dark:bg-emerald-500/15 dark:text-emerald-300" aria-hidden="true">
                            {{ \Illuminate\Support\Str::limit($rental->unit?->room_number ?? '—', 5, '') }}
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-base leading-tight font-bold">{{ $primaryName }}</span>
                            <span class="mt-0.5 block truncate text-sm text-slate-500 dark:text-slate-400">
                                {{ __('Room') }} {{ $rental->unit?->room_number ?? '—' }}@if ($phone) · {{ $phone }}@endif
                            </span>
                            @if ($coOccupants->isNotEmpty())
                                <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ __('With') }}: {{ $coOccupants->implode(', ') }}</span>
                            @endif
                            @if ($rental->status !== RentalStatus::Active)
                                <span class="rw-m-badge {{ $badge($rental->status?->getColor()) }} mt-1">{{ $rental->status?->getLabel() }}</span>
                            @endif
                        </span>

                        <span class="shrink-0 text-right">
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ __('Total due') }}</span>
                            <span class="block text-sm font-bold {{ $totalDue > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                {{ Tenants::totalDueFor($rental) }}
                            </span>
                        </span>
                    </a>

                    @if ($phone)
                        <a
                            href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}"
                            class="flex w-14 shrink-0 items-center justify-center border-l border-slate-100 text-brand-600 transition-colors active:bg-brand-50 dark:border-slate-800 dark:text-emerald-400 dark:active:bg-emerald-500/10"
                            aria-label="{{ __('Call :name', ['name' => $primaryName]) }}"
                            id="rw-m-tenant-call-{{ $rental->id }}"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-6" aria-hidden="true"><path fill-rule="evenodd" d="M1.5 4.5a3 3 0 0 1 3-3h1.372c.86 0 1.61.586 1.819 1.42l1.105 4.423a1.875 1.875 0 0 1-.694 1.955l-1.293.97c-.135.101-.164.249-.126.352a11.285 11.285 0 0 0 6.697 6.697c.103.038.25.009.352-.126l.97-1.293a1.875 1.875 0 0 1 1.955-.694l4.423 1.105c.834.209 1.42.959 1.42 1.82V19.5a3 3 0 0 1-3 3h-2.25C8.552 22.5 1.5 15.448 1.5 6.75V4.5Z" clip-rule="evenodd" /></svg>
                        </a>
                    @endif
                </article>
            @empty
                <div class="rw-m-card py-10 text-center text-slate-500 dark:text-slate-400" id="rw-m-tenants-empty">
                    {{ __('No tenants found.') }}
                </div>
            @endforelse

            <div x-cloak x-show="noMatches()" class="rw-m-card py-10 text-center text-slate-500 dark:text-slate-400">
                {{ __('No tenants found.') }}
            </div>
        </div>
    @endif

    {{-- ── Add tenant: tenants move into a room, so pick the vacant room first ── --}}
    <x-mobile.sheet name="add-tenant" :label="__('Add tenant')">
        <div class="px-5 pt-2 pb-2">
            <h2 class="text-lg font-bold">{{ __('Add tenant') }}</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Choose a vacant room for the new tenant.') }}</p>
        </div>

        <div class="space-y-2 px-4 pt-2">
            @forelse ($vacantRooms as $unit)
                <a
                    href="{{ route('mobile.room.add-tenant', $unit) }}"
                    wire:navigate
                    class="flex min-h-14 items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3 transition-colors active:bg-slate-50 dark:border-slate-700 dark:active:bg-slate-800"
                    id="rw-m-tenants-add-room-{{ $unit->id }}"
                >
                    <span class="min-w-0">
                        <span class="block font-bold">{{ __('Room') }} {{ $unit->room_number }}</span>
                        @if ($unit->room_type)
                            <span class="block truncate text-sm text-slate-500 dark:text-slate-400">{{ $unit->room_type }}</span>
                        @endif
                    </span>
                    <span class="flex shrink-0 items-center gap-2 text-sm font-semibold text-slate-600 dark:text-slate-300">
                        {{ Money::formatForRecord($unit->rent_amount, $unit) }}
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-5 text-slate-400" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                    </span>
                </a>
            @empty
                <div class="rounded-xl border border-dashed border-slate-300 px-4 py-8 text-center dark:border-slate-700" id="rw-m-tenants-no-vacant">
                    <p class="font-semibold">{{ __('No vacant rooms') }}</p>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Every room has a tenant. End a tenancy or add a room first.') }}</p>
                    <a href="{{ route('mobile.rooms') }}" wire:navigate class="rw-m-btn rw-m-btn-secondary mt-4 min-h-11 text-sm">{{ __('Open rooms') }}</a>
                </div>
            @endforelse
        </div>

        <div class="px-4 pt-4">
            <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'add-tenant' })" class="rw-m-btn rw-m-btn-secondary w-full" id="rw-m-tenants-add-cancel">{{ __('Cancel') }}</button>
        </div>
    </x-mobile.sheet>
</div>
