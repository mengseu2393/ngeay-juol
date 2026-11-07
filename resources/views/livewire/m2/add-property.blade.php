@php
    use App\Support\Money;

    $chev = '<svg class="m2a-chev" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>';
@endphp

<div id="m2-add-property" class="m2a">
    <x-m2.header :title="__('Add property')" :subtitle="__('Settings')" :back="route('m2.settings')" />

    <main class="m2-wrap m2a-main">
        @if ($limitMessage)
            <div class="m2-notice is-warn" role="alert" id="m2-add-property-limit">{{ $limitMessage }}</div>
        @endif
        @error('subscription')
            <div class="m2-notice is-bad" role="alert" id="m2-add-property-error">{{ $message }}</div>
        @enderror

        <form wire:submit="save" novalidate id="m2-add-property-form" class="m2a-stack">
            <div class="m2a-card">
                <div>
                    <label for="m2-new-name" class="m2-label">{{ __('Property name') }}</label>
                    <input type="text" id="m2-new-name" wire:model="name" class="m2-input" autocomplete="off" autocapitalize="words" placeholder="{{ __('e.g. Sunrise Apartment') }}">
                    @error('name') <p class="m2-err">{{ $message }}</p> @enderror
                </div>
                <div>
                    <span class="m2-label" id="m2-new-type-label">{{ __('Property type') }}</span>
                    <div class="m2-pills" role="radiogroup" aria-labelledby="m2-new-type-label">
                        @foreach ($propertyTypes as $value => $typeName)
                            <label class="m2-pill"><input type="radio" class="m2-sr" wire:model="propertyType" value="{{ $value }}" id="m2-new-type-{{ $value }}">{{ $typeName }}</label>
                        @endforeach
                    </div>
                    @error('propertyType') <p class="m2-err">{{ $message }}</p> @enderror
                </div>
                <div>
                    <span class="m2-label" id="m2-new-currency-label">{{ __('Currency for rent and bills') }}</span>
                    <div class="m2-pills" role="radiogroup" aria-labelledby="m2-new-currency-label">
                        @foreach (['USD' => __('US Dollar ($)'), 'KHR' => __('Khmer Riel (៛)')] as $code => $currencyName)
                            <label class="m2-pill"><input type="radio" class="m2-sr" wire:model.live="currency" value="{{ $code }}" id="m2-new-currency-{{ strtolower($code) }}">{{ $currencyName }}</label>
                        @endforeach
                    </div>
                    @error('currency') <p class="m2-err">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Address: optional, folded away until asked for --}}
            <div x-data="{ open: @js(filled($city) || filled($addressLine) || $errors->hasAny(['addressLine', 'city', 'district', 'commune', 'village'])) }">
                <div class="m2a-list">
                    <button type="button" class="m2a-row" x-on:click="open = ! open" x-bind:aria-expanded="open ? 'true' : 'false'" aria-controls="m2-new-address-fields" id="m2-new-address-toggle">
                        <span class="m2a-t"><b>{{ __('Add the address') }}</b><span>{{ __('optional') }}</span></span>
                        <span class="m2a-fold" x-bind:class="open && 'is-open'">{!! $chev !!}</span>
                    </button>
                </div>
                <div class="m2a-card" style="margin-top:12px" x-show="open" x-cloak id="m2-new-address-fields">
                    <div>
                        <label for="m2-new-address" class="m2-label">{{ __('Street, house number') }}</label>
                        <input type="text" id="m2-new-address" wire:model="addressLine" class="m2-input" autocomplete="street-address">
                        @error('addressLine') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                    @foreach ([
                        ['city', __('City / Province'), true],
                        ['district', __('District (Khan / Srok)'), filled($city)],
                        ['commune', __('Commune (Sangkat / Khum)'), filled($district)],
                        ['village', __('Village (Phum)'), filled($commune)],
                    ] as [$field, $fieldLabel, $enabled])
                        <div wire:key="m2-new-loc-{{ $field }}">
                            <label for="m2-new-{{ $field }}" class="m2-label">{{ $fieldLabel }}</label>
                            <select id="m2-new-{{ $field }}" wire:model.live="{{ $field }}" class="m2-input" @disabled(! $enabled)>
                                <option value="">{{ __('Select') }}</option>
                                @foreach ($locations[$field] as $value => $optionLabel)
                                    <option value="{{ $value }}">{{ $optionLabel }}</option>
                                @endforeach
                            </select>
                            @error($field) <p class="m2-err">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
            </div>

            <h2 class="m2a-h">{{ __('Utility prices') }}</h2>
            <div class="m2a-list" id="m2-new-rates">
                @foreach ($utilities as $utility)
                    <div class="m2a-row is-static" wire:key="m2-new-rate-{{ $utility['name'] }}">
                        @if ($utility['name'] === 'Electricity')<span class="m2a-lt is-e">E</span>
                        @elseif ($utility['name'] === 'Water')<span class="m2a-lt is-w">W</span>
                        @else<span class="m2a-dot is-ok"></span>@endif
                        <label class="m2a-t" for="m2-new-rate-{{ $loop->index }}">
                            <b>{{ $utility['label'] }}</b>
                            <span>{{ __('per :unit', ['unit' => $utility['unit_of_measure']]) }}</span>
                            @error('rates.'.$utility['name']) <span class="m2-err">{{ $message }}</span> @enderror
                        </label>
                        <span class="m2a-money-input">
                            <span aria-hidden="true">{{ Money::symbol($currency) }}</span>
                            <input type="text" id="m2-new-rate-{{ $loop->index }}" wire:model="rates.{{ $utility['name'] }}" class="m2-input m2a-row-input" inputmode="decimal" autocomplete="off" placeholder="0">
                        </span>
                    </div>
                @endforeach
            </div>

            <div class="m2a-list">
                <label class="m2a-row" for="m2-new-with-rooms">
                    <span class="m2a-t"><b>{{ __('Create rooms now') }}</b></span>
                    <input type="checkbox" role="switch" class="m2a-sw" wire:model="withRooms" id="m2-new-with-rooms">
                </label>
            </div>

            <div class="m2a-card" x-show="$wire.withRooms" x-cloak id="m2-new-rooms"
                 x-data="{ get total() { const n = (parseInt($wire.floors, 10) || 0) * (parseInt($wire.perFloor, 10) || 0); return n > 0 ? @js(__(':count rooms')).replace(':count', n) : '—'; } }">
                <div class="m2a-two">
                    <div>
                        <label for="m2-new-floors" class="m2-label">{{ __('Floors') }}</label>
                        <input type="number" id="m2-new-floors" wire:model="floors" class="m2-input" inputmode="numeric" min="1" max="50" autocomplete="off">
                        @error('floors') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="m2-new-per-floor" class="m2-label">{{ __('Rooms per floor') }}</label>
                        <input type="number" id="m2-new-per-floor" wire:model="perFloor" class="m2-input" inputmode="numeric" min="1" max="100" autocomplete="off">
                        @error('perFloor') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label for="m2-new-room-price" class="m2-label">{{ __('Price per room') }} ({{ Money::symbol($currency) }})</label>
                    <input type="text" id="m2-new-room-price" wire:model="roomPrice" class="m2-input" inputmode="decimal" autocomplete="off" placeholder="0">
                    @error('roomPrice') <p class="m2-err">{{ $message }}</p> @enderror
                </div>
                <div class="m2a-kv is-total" style="border-radius:12px"><span>{{ __('Rooms') }}</span><b x-text="total" id="m2-new-room-total"></b></div>
            </div>

            <button type="submit" class="m2-btn" wire:loading.attr="disabled" wire:target="save" @disabled($limitMessage) id="m2-add-property-save">
                <span wire:loading.remove wire:target="save">{{ __('Add property') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
        </form>
    </main>
</div>
