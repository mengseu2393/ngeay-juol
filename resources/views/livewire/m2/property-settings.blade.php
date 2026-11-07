@php
    use App\Enums\FirstMonthBillingMode;
    use App\Services\MeterUtilities;
    use App\Services\OpeningReadingService;
    use App\Support\UtilityCatalog;

    $chev = '<svg class="m2a-chev" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>';
    $optional = '<span class="m2a-opt">('.e(__('optional')).')</span>';
    $back = $section ? route('m2.property') : route('m2.settings');
    // The plan's short first-month names (the enum's labels carry an explanation in brackets).
    $modeShort = [
        FirstMonthBillingMode::Prorated->value => __('Prorated'),
        FirstMonthBillingMode::FullMonth->value => __('Full month'),
        FirstMonthBillingMode::HalfMonth->value => __('Half month'),
    ];
@endphp

<div id="m2-property-settings" class="m2a" data-section="{{ $section ?? 'hub' }}">
    <x-m2.header :title="$title" :subtitle="$property?->name" :back="$back" />

    <main class="m2-wrap m2a-main">
        @if (! $property)
            <div class="m2a-empty" id="m2-prop-none">
                <p>{{ __('Choose a property first') }}</p>
                @if ($canAddProperty)
                    <a href="{{ route('m2.add-property') }}" wire:navigate class="m2-btn" style="margin-top:14px" id="m2-prop-none-add">{{ __('Add property') }}</a>
                @endif
            </div>

        {{-- ════════ Hub ════════ --}}
        @elseif (! $section)
            @php
                $place = collect([$property->property_type?->getLabel(), $property->city])->filter()->implode(' · ');
                $billingHint = collect([
                    $setting->currency ?: 'USD',
                    $setting->due_day_of_month ? __('due on the :day', ['day' => $setting->due_day_of_month]) : null,
                    $setting->invoice_due_days ? __(':count days to pay', ['count' => $setting->invoice_due_days]) : null,
                ])->filter()->implode(' · ');
                $mode = $setting->first_month_billing_mode ?? FirstMonthBillingMode::FullMonth;
                $moveInHint = collect([
                    filled($setting->move_in_preset) ? ($presets[$setting->move_in_preset] ?? $setting->move_in_preset) : null,
                    $modeShort[$mode->value] ?? $mode->getLabel(),
                ])->filter()->implode(' · ');
                $contactHint = collect([
                    filled($setting->caretaker_name) ? __('Caretaker :name', ['name' => $setting->caretaker_name]) : null,
                    $setting->caretaker_phone ?: null,
                ])->filter()->implode(' · ');
            @endphp

            <div class="m2a-sum" style="margin-top:4px" id="m2-prop-summary">
                <div style="min-width:0">
                    @if ($place)<small>{{ $place }}</small>@endif
                    <b class="m2-trunc">{{ $property->name }}</b>
                </div>
                <span class="m2a-chip is-ok">{{ trans_choice(':count room|:count rooms', $roomCount, ['count' => $roomCount]) }}</span>
            </div>

            @if ($canAddRoom)
                <div class="m2a-list" style="margin-top:12px">
                    <a href="{{ route('m2.add-room', ['property' => $property->id, 'from' => 'settings']) }}" wire:navigate class="m2a-row" id="m2-prop-add-room">
                        <x-m2.icon name="plus" /><span class="m2a-t"><b>{{ __('Add room') }}</b></span>{!! $chev !!}
                    </a>
                </div>
            @endif

            <div class="m2a-list" id="m2-prop-sections">
                <a href="{{ route('m2.property.section', 'details') }}" wire:navigate class="m2a-row" id="m2-prop-open-details">
                    <span class="m2a-t"><b>{{ __('Property details') }}</b><span class="m2-trunc">{{ __('Name, type, address') }}</span></span>{!! $chev !!}
                </a>
                <a href="{{ route('m2.property.section', 'billing') }}" wire:navigate class="m2a-row" id="m2-prop-open-billing">
                    <span class="m2a-t"><b>{{ __('Billing') }}</b><span class="m2-trunc">{{ $billingHint }}</span></span>
                    @if ($setting->monthly_billing_enabled)
                        <span class="m2a-chip is-ok" id="m2-prop-billing-on">{{ __('On') }}</span>
                    @else
                        <span class="m2a-chip is-idle" id="m2-prop-billing-off">{{ __('Off') }}</span>
                    @endif
                    {!! $chev !!}
                </a>
                <a href="{{ route('m2.property.section', 'move-in') }}" wire:navigate class="m2a-row" id="m2-prop-open-move-in">
                    <span class="m2a-t"><b>{{ __('Move-in rules') }}</b><span class="m2-trunc">{{ $moveInHint }}</span></span>{!! $chev !!}
                </a>
                <a href="{{ route('m2.property.section', 'contacts') }}" wire:navigate class="m2a-row" id="m2-prop-open-contacts">
                    <span class="m2a-t"><b>{{ __('Contacts & property info') }}</b><span class="m2-trunc">{{ $contactHint ?: __('Not set') }}</span></span>{!! $chev !!}
                </a>
                @if ($meteredUtilities->isNotEmpty())
                    <a href="{{ route('m2.property.section', 'opening') }}" wire:navigate class="m2a-row" id="m2-prop-open-opening">
                        <span class="m2a-t"><b>{{ __('Opening readings') }}</b><span class="m2-trunc">{{ __('Meter numbers on day one') }}</span></span>{!! $chev !!}
                    </a>
                @endif
            </div>

            @if ($others->isNotEmpty())
                <h2 class="m2a-h">{{ __('Other properties') }}</h2>
                <div class="m2a-list" id="m2-prop-others">
                    @foreach ($others as $other)
                        <button type="button" wire:click="switchTo({{ $other->id }})" class="m2a-row" id="m2-prop-other-{{ $other->id }}" wire:key="m2-prop-other-{{ $other->id }}">
                            <span class="m2a-dot is-idle"></span>
                            <span class="m2a-t">
                                <b class="m2-trunc">{{ $other->name }}</b>
                                <span>{{ __(':rooms rooms · :occupied occupied', ['rooms' => $other->units_count, 'occupied' => $other->occupied_count]) }}</span>
                            </span>
                            {!! $chev !!}
                        </button>
                    @endforeach
                </div>
            @endif

            @if ($canAddProperty)
                <a href="{{ route('m2.add-property') }}" wire:navigate class="m2-btn is-ghost" id="m2-prop-add">{{ __('Add another property') }}</a>
            @endif

        {{-- ════════ Property details ════════ --}}
        @elseif ($section === 'details')
            <form wire:submit="saveDetails" novalidate id="m2-prop-details-form" class="m2a-stack">
                <div class="m2a-card">
                    <div>
                        <label for="m2-prop-name" class="m2-label">{{ __('Property name') }}</label>
                        <input type="text" id="m2-prop-name" wire:model="details.name" class="m2-input" autocomplete="off" autocapitalize="words">
                        @error('details.name') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <span class="m2-label" id="m2-prop-type-label">{{ __('Property type') }}</span>
                        <div class="m2-pills" role="radiogroup" aria-labelledby="m2-prop-type-label">
                            @foreach ($propertyTypes as $value => $name)
                                <label class="m2-pill"><input type="radio" class="m2-sr" wire:model="details.property_type" value="{{ $value }}" id="m2-prop-type-{{ $value }}">{{ $name }}</label>
                            @endforeach
                        </div>
                        @error('details.property_type') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                </div>

                <h2 class="m2a-h">{{ __('Address') }}</h2>
                <div class="m2a-card">
                    <div>
                        <label for="m2-prop-address" class="m2-label">{{ __('Street, house number') }} {!! $optional !!}</label>
                        <input type="text" id="m2-prop-address" wire:model="details.address_line" class="m2-input" autocomplete="street-address">
                        @error('details.address_line') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                    @foreach ([
                        ['city', __('City / Province'), true],
                        ['district', __('District (Khan / Srok)'), filled($details['city'] ?? null)],
                        ['commune', __('Commune (Sangkat / Khum)'), filled($details['district'] ?? null)],
                        ['village', __('Village (Phum)'), filled($details['commune'] ?? null)],
                    ] as [$field, $fieldLabel, $enabled])
                        <div wire:key="m2-prop-loc-{{ $field }}">
                            <label for="m2-prop-{{ $field }}" class="m2-label">{{ $fieldLabel }}</label>
                            <select id="m2-prop-{{ $field }}" wire:model.live="details.{{ $field }}" class="m2-input" @disabled(! $enabled)>
                                <option value="">{{ __('Select') }}</option>
                                @foreach ($locations[$field] as $value => $optionLabel)
                                    <option value="{{ $value }}">{{ $optionLabel }}</option>
                                @endforeach
                            </select>
                            @error("details.$field") <p class="m2-err">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>

                <button type="submit" class="m2-btn" wire:loading.attr="disabled" wire:target="saveDetails" id="m2-prop-details-save">{{ __('Save') }}</button>
            </form>

        {{-- ════════ Billing ════════ --}}
        @elseif ($section === 'billing')
            <div class="m2a-list">
                <label class="m2a-row" for="m2-prop-monthly" x-data="{ on: @js((bool) $setting->monthly_billing_enabled) }" wire:key="m2-prop-monthly-{{ (int) $setting->monthly_billing_enabled }}">
                    <span class="m2a-t"><b>{{ __('Monthly billing') }}</b><span>{{ __('Bills each room once a month') }}</span></span>
                    <input type="checkbox" role="switch" class="m2a-sw" id="m2-prop-monthly" x-model="on" x-on:change="$wire.setMonthlyBilling(on).then((ok) => { if (! ok) on = ! on })">
                </label>
            </div>

            <form wire:submit="saveBilling" novalidate id="m2-prop-billing-form" class="m2a-stack">
                <div class="m2a-card">
                    <div>
                        <span class="m2-label" id="m2-prop-currency-label">{{ __('Currency') }}</span>
                        <div class="m2-pills" role="radiogroup" aria-labelledby="m2-prop-currency-label">
                            @foreach (['USD' => __('US Dollar ($)'), 'KHR' => __('Khmer Riel (៛)')] as $code => $name)
                                <label class="m2-pill"><input type="radio" class="m2-sr" wire:model="billing.currency" value="{{ $code }}" id="m2-prop-currency-{{ strtolower($code) }}">{{ $name }}</label>
                            @endforeach
                        </div>
                        @error('billing.currency') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                    <div class="m2a-two">
                        <div>
                            <label for="m2-prop-prefix" class="m2-label">{{ __('Invoice prefix') }}</label>
                            <input type="text" id="m2-prop-prefix" wire:model="billing.invoice_prefix" class="m2-input" autocomplete="off" autocapitalize="characters" placeholder="{{ __('e.g. RIV') }}">
                            @error('billing.invoice_prefix') <p class="m2-err">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="m2-prop-due-day" class="m2-label">{{ __('Due day of month') }}</label>
                            <input type="number" id="m2-prop-due-day" wire:model="billing.due_day_of_month" class="m2-input" inputmode="numeric" min="1" max="28" autocomplete="off">
                            @error('billing.due_day_of_month') <p class="m2-err">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="m2a-two">
                        <div>
                            <label for="m2-prop-due-days" class="m2-label">{{ __('Days to pay') }}</label>
                            <input type="number" id="m2-prop-due-days" wire:model="billing.invoice_due_days" class="m2-input" inputmode="numeric" min="1" autocomplete="off">
                            @error('billing.invoice_due_days') <p class="m2-err">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="m2-prop-late-fee" class="m2-label">{{ __('Late fee') }}</label>
                            <input type="text" id="m2-prop-late-fee" wire:model="billing.late_fee" class="m2-input" inputmode="decimal" autocomplete="off" placeholder="0">
                            @error('billing.late_fee') <p class="m2-err">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <h2 class="m2a-h">{{ __('Exchange rate') }}</h2>
                <div class="m2a-card">
                    <div class="m2a-two">
                        <div>
                            <label for="m2-prop-rate" class="m2-label">{{ __('USD to KHR') }}</label>
                            <input type="text" id="m2-prop-rate" wire:model="billing.usd_khr_exchange_rate" class="m2-input" inputmode="decimal" autocomplete="off" placeholder="4100">
                            @error('billing.usd_khr_exchange_rate') <p class="m2-err">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="m2-prop-rate-date" class="m2-label">{{ __('Rate date') }}</label>
                            <input type="date" id="m2-prop-rate-date" wire:model="billing.exchange_rate_date" class="m2-input">
                            @error('billing.exchange_rate_date') <p class="m2-err">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <button type="button" wire:click="fetchExchangeRate" wire:loading.attr="disabled" wire:target="fetchExchangeRate" class="m2-btn is-ghost" id="m2-prop-rate-fetch">
                        <span wire:loading.remove wire:target="fetchExchangeRate">{{ __('Get today\'s rate') }}</span>
                        <span wire:loading wire:target="fetchExchangeRate">{{ __('Loading…') }}</span>
                    </button>
                </div>

                <button type="submit" class="m2-btn" wire:loading.attr="disabled" wire:target="saveBilling" id="m2-prop-billing-save">{{ __('Save') }}</button>
            </form>

        {{-- ════════ Move-in rules ════════ --}}
        @elseif ($section === 'move-in')
            <form wire:submit="saveMoveIn" novalidate id="m2-prop-move-in-form" class="m2a-stack">
                <div class="m2a-card">
                    <div>
                        <span class="m2-label" id="m2-prop-preset-label">{{ __('Requirements') }}</span>
                        <div class="m2-pills" role="radiogroup" aria-labelledby="m2-prop-preset-label">
                            @foreach ($presets as $value => $name)
                                <label class="m2-pill"><input type="radio" class="m2-sr" wire:model="moveIn.move_in_preset" value="{{ $value }}" id="m2-prop-preset-{{ $value }}">{{ $name }}</label>
                            @endforeach
                        </div>
                        @error('moveIn.move_in_preset') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="m2-prop-deposit" class="m2-label">{{ __('Security deposit') }}</label>
                        <select id="m2-prop-deposit" wire:model="moveIn.upfront_deposit_months" class="m2-input">
                            @foreach ($depositMonths as $value => $name)
                                <option value="{{ $value }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('moveIn.upfront_deposit_months') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                </div>

                <h2 class="m2a-h">{{ __('First month') }}</h2>
                <div class="m2a-card">
                    <div>
                        <span class="m2-label" id="m2-prop-mode-label">{{ __('First-month billing mode') }}</span>
                        <div class="m2-pills" role="radiogroup" aria-labelledby="m2-prop-mode-label">
                            @foreach ($modeShort as $value => $name)
                                <label class="m2-pill" title="{{ $billingModes[$value] ?? $name }}"><input type="radio" class="m2-sr" wire:model="moveIn.first_month_billing_mode" value="{{ $value }}" id="m2-prop-mode-{{ $value }}">{{ $name }}</label>
                            @endforeach
                        </div>
                        @error('moveIn.first_month_billing_mode') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                    {{-- The cutoff only counts for half-month billing (the parent saves it only then). --}}
                    <div x-show="$wire.moveIn.first_month_billing_mode === @js(FirstMonthBillingMode::HalfMonth->value)" x-cloak id="m2-prop-cutoff-box">
                        <label for="m2-prop-cutoff" class="m2-label">{{ __('Half-month cutoff day') }}</label>
                        <input type="number" id="m2-prop-cutoff" wire:model="moveIn.proration_cutoff_day" class="m2-input" inputmode="numeric" min="1" max="28" autocomplete="off">
                        @error('moveIn.proration_cutoff_day') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="m2a-list">
                    <label class="m2a-row" for="m2-prop-invoice-on-move-in">
                        <span class="m2a-t"><b>{{ __('Invoice on move-in') }}</b><span>{{ __('Create the first bill when a tenant moves in') }}</span></span>
                        <input type="checkbox" role="switch" class="m2a-sw" wire:model="moveIn.create_invoice_on_move_in" id="m2-prop-invoice-on-move-in">
                    </label>
                    <label class="m2a-row" for="m2-prop-first-upfront">
                        <span class="m2a-t"><b>{{ __('First month paid before move-in') }}</b></span>
                        <input type="checkbox" role="switch" class="m2a-sw" wire:model="moveIn.require_first_month_upfront" id="m2-prop-first-upfront">
                    </label>
                </div>

                <button type="submit" class="m2-btn" wire:loading.attr="disabled" wire:target="saveMoveIn" id="m2-prop-move-in-save">{{ __('Save') }}</button>
            </form>

        {{-- ════════ Contacts ════════ --}}
        @elseif ($section === 'contacts')
            <form wire:submit="saveContacts" novalidate id="m2-prop-contacts-form" class="m2a-stack">
                <div class="m2a-card">
                    <div>
                        <label for="m2-prop-caretaker" class="m2-label">{{ __('Caretaker name') }}</label>
                        <input type="text" id="m2-prop-caretaker" wire:model="contacts.caretaker_name" class="m2-input" autocomplete="off" autocapitalize="words">
                        @error('contacts.caretaker_name') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="m2-prop-caretaker-phone" class="m2-label">{{ __('Caretaker phone') }}</label>
                        <input type="tel" id="m2-prop-caretaker-phone" wire:model="contacts.caretaker_phone" class="m2-input" inputmode="tel" autocomplete="off">
                        @error('contacts.caretaker_phone') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="m2a-card">
                    <div>
                        <label for="m2-prop-parking" class="m2-label">{{ __('Parking info') }} {!! $optional !!}</label>
                        <textarea id="m2-prop-parking" wire:model="contacts.parking_info" rows="1" class="m2-input m2a-area"></textarea>
                        @error('contacts.parking_info') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="m2-prop-insurance" class="m2-label">{{ __('Insurance info') }} {!! $optional !!}</label>
                        <textarea id="m2-prop-insurance" wire:model="contacts.insurance_info" rows="1" class="m2-input m2a-area" placeholder="{{ __('Not set') }}"></textarea>
                        @error('contacts.insurance_info') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                </div>

                <button type="submit" class="m2-btn" wire:loading.attr="disabled" wire:target="saveContacts" id="m2-prop-contacts-save">{{ __('Save') }}</button>
            </form>

        {{-- ════════ Opening readings ════════ --}}
        @elseif ($section === 'opening')
            @php $utilityUom = $meteredUtilities->firstWhere('id', $openingUtilityId)?->unit_of_measure; @endphp
            <form wire:submit="saveOpeningReadings" novalidate id="m2-prop-opening-form" class="m2a-stack">
                <p class="m2a-hint">{{ __('Sets the starting baseline (no consumption billed).') }}</p>

                @if ($meteredUtilities->count() > 1)
                    <div class="m2-pills" role="group" aria-label="{{ __('Utility') }}">
                        @foreach ($meteredUtilities as $utility)
                            <button type="button" wire:click="$set('openingUtilityId', {{ $utility->id }})" class="m2-pill" aria-pressed="{{ $openingUtilityId === $utility->id ? 'true' : 'false' }}" id="m2-prop-opening-utility-{{ $utility->id }}">{{ UtilityCatalog::label($utility->name) }}</button>
                        @endforeach
                    </div>
                @endif
                @error('openingUtilityId') <p class="m2-err">{{ $message }}</p> @enderror

                <div>
                    <label for="m2-prop-opening-date" class="m2-label">{{ __('Reading date') }}</label>
                    <input type="date" id="m2-prop-opening-date" wire:model="openingDate" max="{{ now()->toDateString() }}" class="m2-input">
                    @error('openingDate') <p class="m2-err">{{ $message }}</p> @enderror
                </div>

                @if ($openingRows->isEmpty())
                    <div class="m2a-empty">{{ __('No rooms are set up for this property yet.') }}</div>
                @else
                    <div class="m2a-list" id="m2-prop-opening-rows" wire:loading.class="m2a-busy" wire:target="openingUtilityId">
                        @foreach ($openingRows as $row)
                            @php
                                $unitId = $row['unit']->id;
                                $open = in_array($row['state'], [OpeningReadingService::OPEN_METER, OpeningReadingService::OPEN_LEGACY], true);
                            @endphp
                            <div class="m2a-row is-static" wire:key="m2-prop-opening-{{ $openingUtilityId }}-{{ $unitId }}">
                                <label class="m2a-t" for="m2-prop-opening-{{ $unitId }}">
                                    <b>{{ $row['unit']->room_number }}</b>
                                    @if (! $open)
                                        <span>{{ __('Already set (:value) — reading from here on.', ['value' => MeterUtilities::trimReading($row['baseline'])]) }}</span>
                                    @elseif ($utilityUom)
                                        <span>{{ $utilityUom }}</span>
                                    @endif
                                    @error('openingReadings.'.$unitId) <span class="m2-err">{{ $message }}</span> @enderror
                                </label>
                                @if ($open)
                                    <input type="text" id="m2-prop-opening-{{ $unitId }}" wire:model="openingReadings.{{ $unitId }}" class="m2-input m2a-row-input" inputmode="decimal" autocomplete="off" enterkeyhint="next" placeholder="0">
                                @else
                                    <input type="text" id="m2-prop-opening-{{ $unitId }}" class="m2-input m2a-row-input" value="{{ MeterUtilities::trimReading($row['baseline']) }}" disabled>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
                @error('openingReadings') <p class="m2-err" role="alert">{{ $message }}</p> @enderror

                <button type="submit" class="m2-btn" wire:loading.attr="disabled" wire:target="saveOpeningReadings" id="m2-prop-opening-save">{{ __('Save readings') }}</button>
            </form>
        @endif
    </main>
</div>
