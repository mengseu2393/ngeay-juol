@php
    use App\Support\Money;

    $label = 'mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-200';
    $error = 'mt-1 text-sm text-red-600 dark:text-red-400';
    $hint = 'mt-1 text-sm text-slate-500 dark:text-slate-400';
    $heading = 'px-1 pb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400';
    $optional = '<span class="font-normal text-slate-400">('.e(__('optional')).')</span>';
@endphp

<div x-data="rwMobileAddProperty(@js(__('rooms')))">
    <a href="{{ route('mobile.settings') }}" wire:navigate class="-ml-1 inline-flex min-h-11 items-center gap-1 pt-2 font-semibold text-brand-600 dark:text-emerald-400" id="rw-m-add-property-back">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-5" aria-hidden="true"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>
        {{ __('Settings') }}
    </a>
    <h1 class="rw-m-title pt-1 pb-1">{{ __('Add property') }}</h1>
    <p class="mb-4 text-sm text-slate-500 dark:text-slate-400">{{ __('A new building or house. You can change everything later in Property Settings.') }}</p>

    @if ($limitMessage)
        <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-medium text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" role="alert" id="rw-m-add-property-limit">{{ $limitMessage }}</div>
    @endif

    @error('subscription')
        <div class="mb-4 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200" role="alert" id="rw-m-add-property-error">{{ $message }}</div>
    @enderror

    <form wire:submit="save" id="rw-m-add-property-form" novalidate>
        {{-- ── Details ── --}}
        <section>
            <h2 class="{{ $heading }}">{{ __('Property details') }}</h2>
            <div class="rw-m-card space-y-4">
                <div>
                    <label for="rw-m-new-name" class="{{ $label }}">{{ __('Property name') }}</label>
                    <input type="text" id="rw-m-new-name" wire:model="name" class="rw-m-input" autocomplete="off" autocapitalize="words" enterkeyhint="next" placeholder="{{ __('e.g. Sunrise Apartment') }}">
                    @error('name') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="rw-m-new-type" class="{{ $label }}">{{ __('Property type') }}</label>
                    <select id="rw-m-new-type" wire:model="propertyType" class="rw-m-input">
                        @foreach ($propertyTypes as $value => $typeName)
                            <option value="{{ $value }}">{{ $typeName }}</option>
                        @endforeach
                    </select>
                    @error('propertyType') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <span class="{{ $label }}" id="rw-m-new-currency-label">{{ __('Currency for rent and bills') }}</span>
                    <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-labelledby="rw-m-new-currency-label">
                        @foreach (['USD' => __('US Dollar ($)'), 'KHR' => __('Khmer Riel (៛)')] as $code => $currencyName)
                            <label class="rw-m-prop-choice">
                                <input type="radio" class="sr-only" value="{{ $code }}" wire:model="currency" id="rw-m-new-currency-{{ strtolower($code) }}">
                                <span>{{ $currencyName }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('currency') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
            </div>
        </section>

        {{-- ── Address (optional, folded away) ── --}}
        <section class="mt-6" x-data="{ open: @js(filled($city) || filled($addressLine)) }">
            <h2 class="{{ $heading }}">{{ __('Address') }}</h2>
            <div class="rw-m-card">
                <button type="button" class="flex min-h-11 w-full items-center justify-between gap-3 text-left" x-on:click="open = ! open" x-bind:aria-expanded="open" id="rw-m-new-address-toggle">
                    <span class="font-medium">{{ __('Add the address') }} {!! $optional !!}</span>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-5 shrink-0 text-slate-400 transition-transform" x-bind:class="open && 'rotate-180'" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                </button>

                <div class="mt-3 space-y-4" x-show="open" x-cloak>
                    <div>
                        <label for="rw-m-new-address" class="{{ $label }}">{{ __('Address') }}</label>
                        <input type="text" id="rw-m-new-address" wire:model="addressLine" class="rw-m-input" autocomplete="street-address" placeholder="{{ __('Street, house number') }}">
                        @error('addressLine') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>

                    @foreach ([
                        ['city', __('City / Province'), true],
                        ['district', __('District (Khan / Srok)'), filled($city)],
                        ['commune', __('Commune (Sangkat / Khum)'), filled($district)],
                        ['village', __('Village (Phum)'), filled($commune)],
                    ] as [$field, $fieldLabel, $enabled])
                        <div wire:key="rw-m-new-loc-{{ $field }}">
                            <label for="rw-m-new-{{ $field }}" class="{{ $label }}">{{ $fieldLabel }}</label>
                            <select id="rw-m-new-{{ $field }}" wire:model.live="{{ $field }}" class="rw-m-input disabled:opacity-50" @disabled(! $enabled)>
                                <option value="">{{ __('Select') }}</option>
                                @foreach ($locations[$field] as $value => $optionLabel)
                                    <option value="{{ $value }}">{{ $optionLabel }}</option>
                                @endforeach
                            </select>
                            @error($field) <p class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- ── Utility prices ── --}}
        <section class="mt-6">
            <h2 class="{{ $heading }}">{{ __('Utility prices') }}</h2>
            <div class="rw-m-card space-y-4">
                @foreach ($utilities as $utility)
                    <div wire:key="rw-m-new-rate-{{ $utility['name'] }}">
                        <label for="rw-m-new-rate-{{ $loop->index }}" class="{{ $label }}">{{ $utility['label'] }} <span class="font-normal text-slate-400">({{ __('per :unit', ['unit' => $utility['unit_of_measure']]) }})</span></label>
                        <div class="relative">
                            <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 font-semibold text-slate-400" aria-hidden="true" x-text="$wire.currency === 'KHR' ? '៛' : '$'">{{ Money::symbol($currency) }}</span>
                            <input type="text" id="rw-m-new-rate-{{ $loop->index }}" wire:model="rates.{{ $utility['name'] }}" class="rw-m-input pl-9" inputmode="decimal" autocomplete="off" placeholder="0">
                        </div>
                        @error('rates.'.$utility['name']) <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                @endforeach
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('What you charge tenants. Leave blank to set it later; add other fees (trash, internet…) under Utility prices.') }}</p>
            </div>
        </section>

        {{-- ── Rooms ── --}}
        <section class="mt-6">
            <h2 class="{{ $heading }}">{{ __('Rooms') }}</h2>
            <div class="rw-m-card space-y-4">
                <label class="flex min-h-12 cursor-pointer items-center justify-between gap-4">
                    <span>
                        <span class="block font-semibold">{{ __('Create rooms now') }}</span>
                        <span class="block text-sm text-slate-500 dark:text-slate-400">{{ __('Rooms in one go; change any room\'s price afterwards.') }}</span>
                    </span>
                    <span class="rw-m-prop-switch">
                        <input type="checkbox" class="sr-only" wire:model="withRooms" id="rw-m-new-with-rooms">
                    </span>
                </label>

                <div class="space-y-4" x-show="$wire.withRooms" x-cloak>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="rw-m-new-floors" class="{{ $label }}">{{ __('Floors') }}</label>
                            <input type="number" id="rw-m-new-floors" wire:model="floors" class="rw-m-input" inputmode="numeric" min="1" max="50" autocomplete="off">
                            @error('floors') <p class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="rw-m-new-per-floor" class="{{ $label }}">{{ __('Rooms per floor') }}</label>
                            <input type="number" id="rw-m-new-per-floor" wire:model="perFloor" class="rw-m-input" inputmode="numeric" min="1" max="100" autocomplete="off">
                            @error('perFloor') <p class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label for="rw-m-new-room-price" class="{{ $label }}">{{ __('Price per room') }}</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 font-semibold text-slate-400" aria-hidden="true" x-text="$wire.currency === 'KHR' ? '៛' : '$'">{{ Money::symbol($currency) }}</span>
                            <input type="text" id="rw-m-new-room-price" wire:model="roomPrice" class="rw-m-input pl-9" inputmode="decimal" autocomplete="off" enterkeyhint="done">
                        </div>
                        @error('roomPrice') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>

                    <p class="rounded-xl bg-slate-50 px-3 py-2 text-sm font-medium text-slate-600 dark:bg-slate-800/60 dark:text-slate-300" x-show="roomPreview" x-text="roomPreview" id="rw-m-new-room-preview"></p>
                </div>
            </div>
        </section>
    </form>

    {{-- Room for the sticky Save bar. --}}
    <div class="h-20" aria-hidden="true"></div>

    <div class="rw-m-prop-savebar">
        <div class="mx-auto max-w-2xl">
            <button type="submit" form="rw-m-add-property-form" class="rw-m-btn rw-m-btn-primary w-full" wire:loading.attr="disabled" wire:target="save" @disabled($limitMessage) id="rw-m-add-property-save">
                <span wire:loading.remove wire:target="save">{{ __('Add property') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
        </div>
    </div>
</div>
