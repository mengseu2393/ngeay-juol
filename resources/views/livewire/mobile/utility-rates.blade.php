@php
    use App\Enums\BillingType;
    use App\Support\ActiveProperty;
    use App\Support\Money;
    use App\Support\UtilityCatalog;

    $filters = ['all' => __('All'), 'active' => __('Active'), 'inactive' => __('Inactive')];
    $blank = [
        'id' => null,
        'name' => 'Electricity',
        'billing_type' => BillingType::Metered->value,
        'rate' => '',
        'unit_of_measure' => 'kWh',
        'provider' => '',
        'account_ref' => '',
        'is_active' => true,
        'label' => '',
        'metered' => true,
    ];
    $error = 'mt-1 text-sm text-red-600 dark:text-red-400';
@endphp

<div
    x-data="rwMobileUtilityRates(@js($blank), @js($nameDefaults))"
    x-on:utility-rate-saved.window="$dispatch('rw-m-sheet-close', { name: 'utility-form' })"
    x-on:utility-rate-toggled.window="$dispatch('rw-m-sheet-close', { name: 'utility-form' })"
    x-on:utility-rate-deleted.window="$dispatch('rw-m-sheet-close', { name: 'utility-delete' })"
    x-on:rw-utility-rate-add.window="openAdd()"
    x-on:rw-m-sheet-closed.window="$event.detail.name === 'utility-history' && $wire.closeHistory()"
>
    <a href="{{ route('mobile.settings') }}" wire:navigate class="-ml-2 inline-flex min-h-11 items-center gap-1 pr-3 pl-1 pt-3 font-semibold text-brand-600 active:opacity-60 dark:text-emerald-400" id="rw-m-utility-rates-back">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-6" aria-hidden="true"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>
        {{ __('Settings') }}
    </a>

    <div class="flex items-end justify-between gap-3">
        <h1 class="rw-m-title pt-1">{{ __('Utility Rates') }}</h1>
        @if (ActiveProperty::id())
            <button type="button" x-on:click="openAdd()" class="rw-m-btn rw-m-btn-primary mb-3 min-h-10 px-3 text-sm" id="rw-m-utility-add">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4" aria-hidden="true"><path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" /></svg>
                {{ __('Add utility') }}
            </button>
        @endif
    </div>

    @if (! ActiveProperty::id())
        <div class="rw-m-card mt-2 text-center">
            <p class="font-semibold">{{ __('Choose a property first') }}</p>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Use the property switcher above to select a property.') }}</p>
        </div>
    @else
        <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]" role="group" aria-label="{{ __('Filter') }}">
            @foreach ($filters as $value => $label)
                <button
                    type="button"
                    wire:click="$set('filter', '{{ $value }}')"
                    class="rw-m-pill"
                    aria-pressed="{{ $filter === $value ? 'true' : 'false' }}"
                    id="rw-m-utility-filter-{{ $value }}"
                >{{ $label }}</button>
            @endforeach
        </div>

        <div class="relative mt-3">
            <svg class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
            <input
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="{{ __('Utility or provider…') }}"
                class="rw-m-input pl-11"
                id="rw-m-utility-search"
                enterkeyhint="search"
                autocomplete="off"
            >
        </div>

        <div class="mt-4 space-y-3 transition-opacity" wire:loading.class="opacity-50" wire:target="filter,search">
            @forelse ($utilities as $utility)
                @php
                    $label = UtilityCatalog::label((string) $utility->name);
                    $card = [
                        'id' => $utility->id,
                        'name' => $utility->name,
                        'billing_type' => $utility->billing_type?->value,
                        'rate' => $utility->rate !== null ? rtrim(rtrim(number_format((float) $utility->rate, 4, '.', ''), '0'), '.') : '',
                        'unit_of_measure' => $utility->unit_of_measure,
                        'provider' => $utility->provider,
                        'account_ref' => $utility->account_ref,
                        'is_active' => (bool) $utility->is_active,
                        'label' => $label,
                        'metered' => in_array($utility->billing_type, [BillingType::Metered, BillingType::Shared], true),
                    ];
                @endphp

                <button
                    type="button"
                    wire:key="rw-m-utility-{{ $utility->id }}"
                    x-on:click="openEdit(@js($card))"
                    class="rw-m-card block w-full text-left transition-colors active:bg-slate-50 dark:active:bg-slate-800/60 {{ $utility->is_active ? '' : 'opacity-70' }}"
                    id="rw-m-utility-{{ $utility->id }}"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-lg leading-tight font-bold">{{ $label }}</p>
                            @if ($utility->provider)
                                <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ $utility->provider }}</p>
                            @endif
                        </div>
                        <span class="rw-m-badge shrink-0 {{ $utility->is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">
                            {{ $utility->is_active ? __('Active') : __('Inactive') }}
                        </span>
                    </div>

                    <div class="mt-3 flex items-end justify-between gap-3">
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $utility->billing_type?->getLabel() }}</p>
                        <p class="shrink-0 text-right text-lg font-bold">
                            {{ Money::formatForRecord($utility->rate, $utility) }}@if ($utility->unit_of_measure)<span class="text-sm font-normal text-slate-500 dark:text-slate-400"> / {{ $utility->unit_of_measure }}</span>@endif
                        </p>
                    </div>
                </button>
            @empty
                <div class="rw-m-card py-10 text-center">
                    @if ($search !== '' || $filter !== 'all')
                        <p class="text-slate-500 dark:text-slate-400">{{ __('No utilities found.') }}</p>
                    @else
                        <p class="text-slate-500 dark:text-slate-400">{{ __('No utilities set up yet.') }}</p>
                        <button type="button" x-on:click="openAdd()" class="rw-m-btn rw-m-btn-primary mt-4" id="rw-m-utility-empty-add">{{ __('Add utility') }}</button>
                    @endif
                </div>
            @endforelse
        </div>
    @endif

    {{-- ── Add / edit: opens instantly from the card's data; only Save hits the server ── --}}
    <x-mobile.sheet name="utility-form" :label="__('Utility')">
        <form class="space-y-4 px-5 pt-2" x-on:submit.prevent="save()" id="rw-m-utility-form">
            <h2 class="text-lg font-bold" x-text="form.id ? @js(__('Edit utility rate')) : @js(__('Add utility'))"></h2>

            <div>
                <label for="rw-m-utility-name" class="mb-1 block text-sm font-semibold">{{ __('Name') }}</label>
                <select id="rw-m-utility-name" x-model="form.name" x-on:change="applyDefaults()" class="rw-m-input">
                    @foreach ($nameOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('name') <p class="{{ $error }}" data-rw-m-error>{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="rw-m-utility-billing" class="mb-1 block text-sm font-semibold">{{ __('Billing type') }}</label>
                <select id="rw-m-utility-billing" x-model.number="form.billing_type" class="rw-m-input">
                    @foreach ($billingTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                @error('billing_type') <p class="{{ $error }}" data-rw-m-error>{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="rw-m-utility-rate" class="mb-1 block text-sm font-semibold">{{ __('Rate') }} <span class="font-normal text-slate-400">({{ $currency }})</span></label>
                    <input type="text" inputmode="decimal" id="rw-m-utility-rate" x-model="form.rate" class="rw-m-input" placeholder="0.00" autocomplete="off" enterkeyhint="next">
                    @error('rate') <p class="{{ $error }}" data-rw-m-error>{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="rw-m-utility-unit" class="mb-1 block text-sm font-semibold">{{ __('Unit of measure') }}</label>
                    <input type="text" id="rw-m-utility-unit" x-model="form.unit_of_measure" class="rw-m-input" placeholder="kWh" autocomplete="off" enterkeyhint="next">
                    @error('unit_of_measure') <p class="{{ $error }}" data-rw-m-error>{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="rw-m-utility-provider" class="mb-1 block text-sm font-semibold">{{ __('Provider') }}</label>
                <input type="text" id="rw-m-utility-provider" x-model="form.provider" class="rw-m-input" placeholder="EDC, PPWSA…" autocomplete="off" enterkeyhint="next">
                @error('provider') <p class="{{ $error }}" data-rw-m-error>{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="rw-m-utility-account" class="mb-1 block text-sm font-semibold">{{ __('Account #') }}</label>
                <input type="text" id="rw-m-utility-account" x-model="form.account_ref" class="rw-m-input" autocomplete="off" enterkeyhint="done">
                @error('account_ref') <p class="{{ $error }}" data-rw-m-error>{{ $message }}</p> @enderror
            </div>

            <label for="rw-m-utility-active" class="flex min-h-11 items-center justify-between gap-3">
                <span class="font-semibold">{{ __('Active') }}</span>
                <input type="checkbox" id="rw-m-utility-active" x-model="form.is_active" class="peer sr-only">
                <span class="rw-m-set-switch" aria-hidden="true"></span>
            </label>

            <div class="grid grid-cols-2 gap-2 pt-1">
                <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'utility-form' })" class="rw-m-btn rw-m-btn-secondary" id="rw-m-utility-cancel">{{ __('Cancel') }}</button>
                <button type="submit" class="rw-m-btn rw-m-btn-primary" wire:loading.attr="disabled" wire:target="save" id="rw-m-utility-save">
                    <span wire:loading.remove wire:target="save">{{ __('Save') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </button>
            </div>

            {{-- Existing utility: what else can be done with it. --}}
            <div x-show="form.id" class="rw-m-card divide-y divide-slate-100 overflow-hidden p-0 dark:divide-slate-800">
                <button type="button" x-show="form.metered" x-on:click="openHistory()" class="flex min-h-12 w-full items-center justify-between gap-3 px-4 text-left font-medium active:bg-slate-100 dark:active:bg-slate-800" id="rw-m-utility-history">
                    {{ __('Reading history') }}
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-5 text-slate-300 dark:text-slate-600" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                </button>
                <button type="button" x-on:click="$wire.toggleActive(form.id)" wire:loading.attr="disabled" wire:target="toggleActive" class="flex min-h-12 w-full items-center px-4 text-left font-medium active:bg-slate-100 dark:active:bg-slate-800" id="rw-m-utility-toggle">
                    <span x-text="form.is_active ? @js(__('Deactivate')) : @js(__('Activate'))"></span>
                </button>
                <button type="button" x-on:click="openDelete()" class="flex min-h-12 w-full items-center px-4 text-left font-medium text-red-600 active:bg-red-50 dark:text-red-400 dark:active:bg-red-500/10" id="rw-m-utility-delete">
                    {{ __('Delete') }}
                </button>
            </div>
        </form>
    </x-mobile.sheet>

    {{-- ── Delete confirmation ── --}}
    <x-mobile.sheet name="utility-delete" :label="__('Delete utility?')">
        <div class="space-y-3 px-5 pt-2">
            <h2 class="text-lg font-bold">{{ __('Delete utility?') }}</h2>
            <p class="font-semibold" x-text="form.label"></p>
            <p class="text-sm text-slate-600 dark:text-slate-400">{{ __('Past invoices keep their lines. This only removes the rate from this property going forward.') }}</p>
            <div class="grid grid-cols-2 gap-2 pt-2">
                <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'utility-delete' })" class="rw-m-btn rw-m-btn-secondary" id="rw-m-utility-delete-cancel">{{ __('Cancel') }}</button>
                <button type="button" x-on:click="$wire.delete(form.id)" wire:loading.attr="disabled" wire:target="delete" class="rw-m-btn bg-red-600 text-white active:bg-red-700" id="rw-m-utility-delete-confirm">{{ __('Delete') }}</button>
            </div>
        </div>
    </x-mobile.sheet>

    {{-- ── Reading history: pick a room, then view / correct its readings ── --}}
    <x-mobile.sheet name="utility-history" :label="__('Reading history')">
        <div class="px-5 pt-2">
            <h2 class="text-lg font-bold">
                {{ __('Reading history') }}@if ($historyUtility) · {{ UtilityCatalog::label((string) $historyUtility->name) }}@endif
            </h2>

            <div wire:loading.flex wire:target="openHistory" class="mt-3 h-40 animate-pulse rounded-2xl bg-slate-100 dark:bg-slate-800" aria-busy="true"></div>

            <div wire:loading.remove wire:target="openHistory" class="mt-3">
                @if ($historyUtility)
                    @if ($historyRooms->isEmpty())
                        <p class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">{{ __('No rooms found.') }}</p>
                    @else
                        <label for="rw-m-history-room" class="mb-1 block text-sm font-semibold">{{ __('Room') }}</label>
                        <select id="rw-m-history-room" class="rw-m-input mb-3" wire:change="selectHistoryRoom($event.target.value)">
                            @foreach ($historyRooms as $room)
                                <option value="{{ $room->id }}" @selected($room->id === $historyUnitId)>{{ $room->room_number }}</option>
                            @endforeach
                        </select>

                        @if ($historyUnitId)
                            <livewire:mobile.utility-rates-reading-history :unit-id="$historyUnitId" :utility-id="$historyUtilityId" :key="'rw-m-history-'.$historyUnitId.'-'.$historyUtilityId" />
                        @endif
                    @endif
                @endif
            </div>
        </div>
    </x-mobile.sheet>
</div>
