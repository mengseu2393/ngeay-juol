@php
    use App\Enums\UnitStatus;
    use App\Livewire\Mobile\Rooms;
    use App\Models\Rental;
    use App\Models\UtilityUsage;
    use App\Support\ActiveProperty;
    use App\Support\Money;
    use App\Support\UtilityCatalog;

    $filters = ['all' => __('All'), 'available' => __('Vacant'), 'occupied' => __('Occupied'), 'maintenance' => __('Maintenance')];

    $user = auth()->user();
    $canCreateRental = $user->can('create', Rental::class);
    $canRecordReadings = $user->can('create', UtilityUsage::class) && $meteredUtilities->isNotEmpty();
@endphp

<div x-data="rwMobileRooms(@js($filter))">
    <h1 class="rw-m-title">{{ __('Rooms') }}</h1>

    @if (! ActiveProperty::id())
        <div class="rw-m-card mt-2 text-center">
            <p class="font-semibold">{{ __('Choose a property first') }}</p>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Use the property switcher above to select a property.') }}</p>
        </div>
    @else
        {{-- Filters: client-side, every room is already on the page. --}}
        <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]" role="group" aria-label="{{ __('Filter') }}">
            @foreach ($filters as $value => $label)
                <button
                    type="button"
                    x-on:click="setFilter(@js($value))"
                    class="rw-m-pill"
                    aria-pressed="{{ $filter === $value ? 'true' : 'false' }}"
                    x-bind:aria-pressed="filter === @js($value) ? 'true' : 'false'"
                    id="rw-m-room-filter-{{ $value }}"
                >{{ $label }}</button>
            @endforeach
        </div>

        <div class="relative mt-3">
            <svg class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" /></svg>
            <input
                type="search"
                x-model="q"
                placeholder="{{ __('Room or tenant name…') }}"
                class="rw-m-input pl-11"
                id="rw-m-room-search"
                enterkeyhint="search"
                autocomplete="off"
            >
        </div>

        <div class="mt-4 space-y-3">
            @forelse ($rooms as $room)
                @php
                    $rental = $room->activeRental;
                    $tenantName = $rental?->occupant_name ?: $rental?->tenant?->name;
                    $currency = $room->property?->currency;
                    $rent = $rental?->monthly_rent ?? $room->rent_amount;
                    $card = [
                        'id' => $room->id,
                        'room' => $room->room_number,
                        'tenant' => $tenantName,
                        'currency' => $currency,
                        'price' => $room->rent_amount !== null ? (string) $room->rent_amount : '',
                        'roomUrl' => route('mobile.room', $room),
                        'canPrice' => $user->can('update', $room),
                        'needsReading' => $canRecordReadings && $room->utility_usages_count === 0,
                        'addUrl' => $room->status === UnitStatus::Available && ! $rental && $canCreateRental ? route('mobile.room.add-tenant', $room) : null,
                        'endUrl' => $rental && $room->status === UnitStatus::Occupied && $user->can('update', $rental) ? route('mobile.room.end-tenancy', $room) : null,
                        'tenantUrl' => $rental ? route('mobile.tenant', $rental) : null,
                    ];
                @endphp

                <article
                    wire:key="rw-m-room-{{ $room->id }}"
                    id="rw-m-room-{{ $room->id }}"
                    class="rw-m-card overflow-hidden p-0"
                    data-rw-item
                    data-status="{{ Rooms::statusKey($room) }}"
                    data-q="{{ mb_strtolower(trim($room->room_number.' '.($rental?->occupant_name ?? '').' '.($rental?->tenant?->name ?? ''))) }}"
                    x-show="matches($el)"
                    x-data="{ card: @js($card) }"
                >
                    <a
                        href="{{ $card['roomUrl'] }}"
                        wire:navigate
                        class="block p-4 transition-colors active:bg-slate-50 dark:active:bg-slate-800/60"
                        id="rw-m-room-open-{{ $room->id }}"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-lg leading-tight font-bold">{{ $room->room_number }}</p>
                                <p class="truncate text-sm text-slate-500 dark:text-slate-400">{{ $tenantName ?: __('No tenant') }}</p>
                            </div>
                            <span class="rw-m-badge {{ Rooms::badgeClass($room->status->getColor()) }} shrink-0">{{ $room->status->getLabel() }}</span>
                        </div>

                        @if ($rent !== null)
                            <p class="mt-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
                                {{ Money::format($rent, $currency) }}<span class="font-normal text-slate-500 dark:text-slate-400">/{{ __('mo') }}</span>
                            </p>
                        @endif
                    </a>

                    <div class="flex items-center gap-2 border-t border-slate-100 px-4 py-2 dark:border-slate-800">
                        @if ($card['addUrl'])
                            <a href="{{ $card['addUrl'] }}" wire:navigate class="rw-m-btn rw-m-btn-primary min-h-10 px-4 text-sm" id="rw-m-room-add-tenant-{{ $room->id }}">{{ __('Add tenant') }}</a>
                        @elseif ($card['tenantUrl'])
                            <a href="{{ $card['tenantUrl'] }}" wire:navigate class="rw-m-btn rw-m-btn-secondary min-h-10 px-4 text-sm" id="rw-m-room-view-tenant-{{ $room->id }}">{{ __('View tenant') }}</a>
                        @endif

                        @if ($card['needsReading'])
                            <button type="button" x-on:click="openReading(card)" class="rw-m-btn rw-m-btn-secondary min-h-10 px-4 text-sm" id="rw-m-room-set-reading-{{ $room->id }}">{{ __('Set utility reading') }}</button>
                        @endif

                        <button
                            type="button"
                            x-on:click="openActions(card)"
                            class="ml-auto inline-flex size-11 shrink-0 items-center justify-center rounded-full text-slate-500 transition active:scale-95 active:bg-slate-100 dark:text-slate-400 dark:active:bg-slate-800"
                            aria-label="{{ __('Actions') }}"
                            id="rw-m-room-actions-{{ $room->id }}"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M3 10a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0ZM8.5 10a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0ZM15.5 8.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z" /></svg>
                        </button>
                    </div>
                </article>
            @empty
                <div class="rw-m-card py-10 text-center text-slate-500 dark:text-slate-400" id="rw-m-rooms-empty">
                    {{ __('No rooms found.') }}
                </div>
            @endforelse

            <div x-cloak x-show="noMatches()" class="rw-m-card py-10 text-center text-slate-500 dark:text-slate-400">
                {{ __('No rooms found.') }}
            </div>
        </div>
    @endif

    {{-- ── Per-room actions ── --}}
    <x-mobile.sheet name="room-actions" :label="__('Actions')">
        <div class="px-5 pt-2 pb-3">
            <p class="text-lg font-bold">{{ __('Room') }} <span x-text="room?.room"></span></p>
            <p class="truncate text-sm text-slate-500 dark:text-slate-400" x-show="room?.tenant" x-text="room?.tenant"></p>
        </div>

        <nav class="grid gap-2 px-4" aria-label="{{ __('Actions') }}">
            <a x-bind:href="room?.roomUrl" wire:navigate class="rw-m-btn rw-m-btn-secondary justify-start" id="rw-m-room-action-open">{{ __('Open room') }}</a>
            <button type="button" x-show="room?.canPrice" x-on:click="openPrice(room)" class="rw-m-btn rw-m-btn-secondary justify-start" id="rw-m-room-action-price">{{ __('Edit room price') }}</button>
            <button type="button" x-show="room?.needsReading" x-on:click="openReading(room)" class="rw-m-btn rw-m-btn-secondary justify-start" id="rw-m-room-action-reading">{{ __('Set utility reading') }}</button>
            <a x-show="room?.tenantUrl" x-bind:href="room?.tenantUrl" wire:navigate class="rw-m-btn rw-m-btn-secondary justify-start" id="rw-m-room-action-tenant">{{ __('View tenant') }}</a>
            <a x-show="room?.addUrl" x-bind:href="room?.addUrl" wire:navigate class="rw-m-btn rw-m-btn-primary justify-start" id="rw-m-room-action-add-tenant">{{ __('Add tenant') }}</a>
            <a x-show="room?.endUrl" x-bind:href="room?.endUrl" wire:navigate class="rw-m-btn justify-start border border-red-200 bg-red-50 text-red-700 active:bg-red-100 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300" id="rw-m-room-action-end-tenancy">{{ __('End tenancy') }}</a>
        </nav>
    </x-mobile.sheet>

    {{-- ── Edit room price: opens with the card's data; only Save hits the server ── --}}
    <x-mobile.sheet name="room-price" :label="__('Edit room price')">
        <form class="space-y-4 px-5 pt-2" x-on:submit.prevent="savePrice()">
            <div>
                <h2 class="text-lg font-bold">{{ __('Edit room price') }}</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Room') }} <span x-text="price.room"></span></p>
            </div>

            <div>
                <label for="rw-m-room-price" class="mb-1 block text-sm font-semibold">
                    {{ __('Monthly rent') }}
                    <span class="font-normal text-slate-400" x-show="price.currency" x-text="'(' + price.currency + ')'"></span>
                </label>
                <input type="text" inputmode="decimal" id="rw-m-room-price" x-model="price.value" class="rw-m-input" placeholder="0.00" autocomplete="off" enterkeyhint="done">
                @error('priceValue') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('A current tenancy is updated to the new rent too.') }}</p>

            <div class="grid grid-cols-2 gap-2 pt-1">
                <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'room-price' })" class="rw-m-btn rw-m-btn-secondary" id="rw-m-room-price-cancel">{{ __('Cancel') }}</button>
                <button type="submit" class="rw-m-btn rw-m-btn-primary" wire:loading.attr="disabled" wire:target="submitEditPriceFor" id="rw-m-room-price-save">
                    <span wire:loading.remove wire:target="submitEditPriceFor">{{ __('Save') }}</span>
                    <span wire:loading wire:target="submitEditPriceFor">{{ __('Saving…') }}</span>
                </button>
            </div>
        </form>
    </x-mobile.sheet>

    {{-- ── First (baseline) meter reading: metered utilities are property-wide, so one form serves every room ── --}}
    <x-mobile.sheet name="room-reading" :label="__('Set utility reading')">
        <form class="space-y-4 px-5 pt-2" x-on:submit.prevent="saveReading()">
            <div>
                <h2 class="text-lg font-bold">{{ __('Set utility reading') }}</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Room') }} <span x-text="reading.room"></span> · {{ __('Record the starting meter reading for this unit\'s metered utilities.') }}
                </p>
            </div>

            @forelse ($meteredUtilities as $utility)
                <div>
                    <label for="rw-m-room-reading-{{ $utility->id }}" class="mb-1 block text-sm font-semibold">
                        {{ UtilityCatalog::label($utility->name) }}
                        @if ($utility->unit_of_measure)<span class="font-normal text-slate-400">({{ $utility->unit_of_measure }})</span>@endif
                    </label>
                    <input
                        type="text"
                        inputmode="decimal"
                        id="rw-m-room-reading-{{ $utility->id }}"
                        x-model="reading.values['{{ $utility->id }}']"
                        class="rw-m-input"
                        placeholder="0.000"
                        autocomplete="off"
                        enterkeyhint="{{ $loop->last ? 'done' : 'next' }}"
                    >
                    @error('readingValues.'.$utility->id) <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            @empty
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('This property has no metered utilities set up yet.') }}</p>
            @endforelse

            @error('readingValues') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

            <div class="grid grid-cols-2 gap-2 pt-1">
                <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'room-reading' })" class="rw-m-btn rw-m-btn-secondary" id="rw-m-room-reading-cancel">{{ __('Cancel') }}</button>
                @if ($meteredUtilities->isNotEmpty())
                    <button type="submit" class="rw-m-btn rw-m-btn-primary" wire:loading.attr="disabled" wire:target="submitUtilityReadingFor" id="rw-m-room-reading-save">
                        <span wire:loading.remove wire:target="submitUtilityReadingFor">{{ __('Save') }}</span>
                        <span wire:loading wire:target="submitUtilityReadingFor">{{ __('Saving…') }}</span>
                    </button>
                @endif
            </div>
        </form>
    </x-mobile.sheet>
</div>
