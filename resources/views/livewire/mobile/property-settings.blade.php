@php
    use App\Enums\FirstMonthBillingMode;
    use App\Services\MeterUtilities;
    use App\Services\OpeningReadingService;
    use App\Support\Money;
    use App\Support\UtilityCatalog;

    $label = 'mb-1 block text-sm font-semibold text-slate-700 dark:text-slate-200';
    $error = 'mt-1 text-sm text-red-600 dark:text-red-400';
    $hint = 'mt-1 text-sm text-slate-500 dark:text-slate-400';
    $heading = 'text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400';
    $editLink = 'inline-flex min-h-11 items-center px-2 text-sm font-semibold text-brand-600 dark:text-emerald-400';
    $notSet = '<span class="text-slate-400">'.e(__('Not set')).'</span>';
    $yesNo = fn ($on) => $on ? __('Yes') : __('No');
@endphp

<div x-data x-on:rw-m-sheet-closed.window="$event.detail.name.startsWith('prop-') && $wire.discard($event.detail.name)">
    <a href="{{ route('mobile.settings') }}" wire:navigate class="-ml-1 inline-flex min-h-11 items-center gap-1 pt-2 font-semibold text-brand-600 dark:text-emerald-400" id="rw-m-prop-back">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-5" aria-hidden="true"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd" /></svg>
        {{ __('Settings') }}
    </a>
    <h1 class="rw-m-title pt-1 pb-1">{{ __('Property Settings') }}</h1>

    @if (! $property)
        <div class="rw-m-card mt-3 text-center">
            <p class="font-semibold">{{ __('Choose a property first') }}</p>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Use the property switcher above to select a property.') }}</p>
            @if ($canAddProperty)
                <a href="{{ route('mobile.add-property') }}" wire:navigate class="rw-m-btn rw-m-btn-primary mt-4 w-full" id="rw-m-prop-empty-add">{{ __('Add property') }}</a>
            @endif
        </div>
    @else
        <p class="mb-4 truncate text-sm text-slate-500 dark:text-slate-400">{{ $property->name }}</p>

        {{-- ── Property details ── --}}
        <section>
            <div class="flex items-end justify-between px-1">
                <h2 class="{{ $heading }} pb-2">{{ __('Property') }}</h2>
                <button type="button" class="{{ $editLink }}" x-on:click="$dispatch('rw-m-sheet-open', { name: 'prop-details' })" id="rw-m-prop-details-edit">{{ __('Edit') }}</button>
            </div>
            <dl class="rw-m-card py-1">
                <div class="rw-m-prop-row"><dt class="text-slate-500 dark:text-slate-400">{{ __('Name') }}</dt><dd class="min-w-0 truncate text-right font-medium">{{ $property->name }}</dd></div>
                <div class="rw-m-prop-row"><dt class="text-slate-500 dark:text-slate-400">{{ __('Property type') }}</dt><dd class="text-right font-medium">{{ $property->property_type?->getLabel() ?? '—' }}</dd></div>
                <div class="rw-m-prop-row items-start"><dt class="shrink-0 text-slate-500 dark:text-slate-400">{{ __('Address') }}</dt><dd class="min-w-0 text-right font-medium">{!! filled($property->formatted_address) ? e($property->formatted_address) : $notSet !!}</dd></div>
            </dl>
        </section>

        {{-- ── Billing ── --}}
        <section class="mt-6">
            <div class="flex items-end justify-between px-1">
                <h2 class="{{ $heading }} pb-2">{{ __('Billing') }}</h2>
                <button type="button" class="{{ $editLink }}" x-on:click="$dispatch('rw-m-sheet-open', { name: 'prop-billing' })" id="rw-m-prop-billing-edit">{{ __('Edit') }}</button>
            </div>

            <div class="rw-m-card mb-3" x-data="{ on: @js((bool) $setting->monthly_billing_enabled) }" wire:key="rw-m-prop-monthly-{{ (int) $setting->monthly_billing_enabled }}">
                <label class="flex min-h-12 cursor-pointer items-center justify-between gap-4">
                    <span>
                        <span class="block font-semibold">{{ __('Monthly billing') }}</span>
                        <span class="block text-sm text-slate-500 dark:text-slate-400">{{ __('Each month, list every room that is due so you can enter readings and create all invoices at once.') }}</span>
                    </span>
                    <span class="rw-m-prop-switch">
                        <input type="checkbox" class="sr-only" x-model="on" x-on:change="$wire.setMonthlyBilling(on).then((ok) => { if (! ok) on = ! on })" id="rw-m-prop-monthly-billing">
                    </span>
                </label>
            </div>

            <dl class="rw-m-card py-1">
                <div class="rw-m-prop-row"><dt class="text-slate-500 dark:text-slate-400">{{ __('Currency') }}</dt><dd class="text-right font-medium">{{ $currencies[$setting->currency] ?? $setting->currency }}</dd></div>
                <div class="rw-m-prop-row"><dt class="text-slate-500 dark:text-slate-400">{{ __('Due day of month') }}</dt><dd class="text-right font-medium">{!! $setting->due_day_of_month ? e($setting->due_day_of_month) : $notSet !!}</dd></div>
                <div class="rw-m-prop-row"><dt class="text-slate-500 dark:text-slate-400">{{ __('Invoice due after') }}</dt><dd class="text-right font-medium">{!! $setting->invoice_due_days ? e(__(':count days', ['count' => $setting->invoice_due_days])) : $notSet !!}</dd></div>
                <div class="rw-m-prop-row"><dt class="text-slate-500 dark:text-slate-400">{{ __('Late fee') }}</dt><dd class="text-right font-medium">{{ Money::format((float) $setting->late_fee, $setting->currency) }}</dd></div>
                <div class="rw-m-prop-row items-start">
                    <dt class="shrink-0 text-slate-500 dark:text-slate-400">{{ __('Exchange rate') }}</dt>
                    <dd class="min-w-0 text-right font-medium">
                        @if (filled($setting->usd_khr_exchange_rate) && $setting->exchange_rate_date)
                            {{ __('1 USD = :rate KHR (:date)', ['rate' => number_format((float) $setting->usd_khr_exchange_rate, 2), 'date' => $setting->exchange_rate_date->toDateString()]) }}
                        @else
                            {!! $notSet !!}
                        @endif
                    </dd>
                </div>
                <div class="rw-m-prop-row"><dt class="text-slate-500 dark:text-slate-400">{{ __('Invoice prefix') }}</dt><dd class="text-right font-medium">{!! filled($setting->invoice_prefix) ? e($setting->invoice_prefix) : $notSet !!}</dd></div>
            </dl>
        </section>

        {{-- ── Move-in rules ── --}}
        <section class="mt-6">
            <div class="flex items-end justify-between px-1">
                <h2 class="{{ $heading }} pb-2">{{ __('Move-in rules') }}</h2>
                <button type="button" class="{{ $editLink }}" x-on:click="$dispatch('rw-m-sheet-open', { name: 'prop-move-in' })" id="rw-m-prop-move-in-edit">{{ __('Edit') }}</button>
            </div>
            <dl class="rw-m-card py-1">
                @php $mode = $setting->first_month_billing_mode ?? FirstMonthBillingMode::FullMonth; @endphp
                <div class="rw-m-prop-row items-start">
                    <dt class="shrink-0 text-slate-500 dark:text-slate-400">{{ __('First month') }}</dt>
                    <dd class="min-w-0 text-right font-medium">
                        {{ $mode->getLabel() }}
                        @if ($mode === FirstMonthBillingMode::HalfMonth)
                            <span class="block text-sm font-normal text-slate-500 dark:text-slate-400">{{ __('After day :day of the month', ['day' => $setting->proration_cutoff_day ?? 15]) }}</span>
                        @endif
                    </dd>
                </div>
                <div class="rw-m-prop-row"><dt class="text-slate-500 dark:text-slate-400">{{ __('Requirements') }}</dt><dd class="text-right font-medium">{!! filled($setting->move_in_preset) ? e($presets[$setting->move_in_preset] ?? $setting->move_in_preset) : $notSet !!}</dd></div>
                <div class="rw-m-prop-row"><dt class="text-slate-500 dark:text-slate-400">{{ __('Security deposit') }}</dt><dd class="text-right font-medium">{{ $depositMonths[(int) $setting->upfront_deposit_months] ?? '—' }}</dd></div>
                <div class="rw-m-prop-row"><dt class="text-slate-500 dark:text-slate-400">{{ __('First month paid before move-in') }}</dt><dd class="shrink-0 text-right font-medium">{{ $yesNo($setting->require_first_month_upfront) }}</dd></div>
                <div class="rw-m-prop-row"><dt class="text-slate-500 dark:text-slate-400">{{ __('Invoice on move-in') }}</dt><dd class="shrink-0 text-right font-medium">{{ $yesNo($setting->create_invoice_on_move_in) }}</dd></div>
            </dl>
        </section>

        {{-- ── Contacts ── --}}
        <section class="mt-6">
            <div class="flex items-end justify-between px-1">
                <h2 class="{{ $heading }} pb-2">{{ __('Contacts & property info') }}</h2>
                <button type="button" class="{{ $editLink }}" x-on:click="$dispatch('rw-m-sheet-open', { name: 'prop-contacts' })" id="rw-m-prop-contacts-edit">{{ __('Edit') }}</button>
            </div>
            <dl class="rw-m-card py-1">
                <div class="rw-m-prop-row"><dt class="text-slate-500 dark:text-slate-400">{{ __('Caretaker name') }}</dt><dd class="min-w-0 truncate text-right font-medium">{!! filled($setting->caretaker_name) ? e($setting->caretaker_name) : $notSet !!}</dd></div>
                <div class="rw-m-prop-row">
                    <dt class="text-slate-500 dark:text-slate-400">{{ __('Caretaker phone') }}</dt>
                    <dd class="text-right font-medium">
                        @if (filled($setting->caretaker_phone))
                            <a href="tel:{{ preg_replace('/[^0-9+]/', '', $setting->caretaker_phone) }}" class="text-brand-600 dark:text-emerald-400">{{ $setting->caretaker_phone }}</a>
                        @else
                            {!! $notSet !!}
                        @endif
                    </dd>
                </div>
                <div class="rw-m-prop-row items-start"><dt class="shrink-0 text-slate-500 dark:text-slate-400">{{ __('Parking info') }}</dt><dd class="min-w-0 text-right font-medium whitespace-pre-line">{!! filled($setting->parking_info) ? e($setting->parking_info) : $notSet !!}</dd></div>
                <div class="rw-m-prop-row items-start"><dt class="shrink-0 text-slate-500 dark:text-slate-400">{{ __('Insurance info') }}</dt><dd class="min-w-0 text-right font-medium whitespace-pre-line">{!! filled($setting->insurance_info) ? e($setting->insurance_info) : $notSet !!}</dd></div>
            </dl>
        </section>

        {{-- ── Rooms, utilities, meters ── --}}
        <section class="mt-6">
            <h2 class="{{ $heading }} px-1 pb-2">{{ __('Rooms & utilities') }}</h2>
            <div class="rw-m-card py-1">
                <a href="{{ route('mobile.rooms') }}" wire:navigate class="rw-m-prop-row" id="rw-m-prop-rooms-link">
                    <span class="font-medium">{{ __('Rooms') }}</span>
                    <span class="flex items-center gap-1 text-slate-500 dark:text-slate-400">{{ $property->units()->count() }}
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-5 text-slate-400" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                    </span>
                </a>
                <a href="{{ route('mobile.utility-rates') }}" wire:navigate class="rw-m-prop-row" id="rw-m-prop-utilities-link">
                    <span class="font-medium">{{ __('Utility prices') }}</span>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-5 text-slate-400" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                </a>
                @if ($meteredUtilities->isNotEmpty())
                    <button type="button" class="rw-m-prop-row" x-on:click="$dispatch('rw-m-sheet-open', { name: 'prop-opening' })" id="rw-m-prop-opening-open">
                        <span>
                            <span class="block font-medium">{{ __('Opening readings') }}</span>
                            <span class="block text-sm text-slate-500 dark:text-slate-400">{{ __('What each meter showed on the day you started.') }}</span>
                        </span>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-5 shrink-0 text-slate-400" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                    </button>
                @endif
            </div>
        </section>

        @if ($canAddProperty)
            <a href="{{ route('mobile.add-property') }}" wire:navigate class="rw-m-btn rw-m-btn-secondary mt-6 w-full" id="rw-m-prop-add">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4" aria-hidden="true"><path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" /></svg>
                {{ __('Add another property') }}
            </a>
        @endif

        {{-- ═══════════════ Sheets ═══════════════ --}}

        {{-- ── Property details ── --}}
        <x-mobile.sheet name="prop-details" :label="__('Property details')">
            <form class="space-y-4 px-5 pt-2" wire:submit="saveDetails" novalidate>
                <h2 class="text-lg font-bold">{{ __('Property details') }}</h2>

                <div>
                    <label for="rw-m-prop-name" class="{{ $label }}">{{ __('Property name') }}</label>
                    <input type="text" id="rw-m-prop-name" wire:model="details.name" class="rw-m-input" autocomplete="off" enterkeyhint="next">
                    @error('details.name') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="rw-m-prop-type" class="{{ $label }}">{{ __('Property type') }}</label>
                    <select id="rw-m-prop-type" wire:model="details.property_type" class="rw-m-input">
                        @foreach ($propertyTypes as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('details.property_type') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="rw-m-prop-address" class="{{ $label }}">{{ __('Address') }} <span class="font-normal text-slate-400">({{ __('optional') }})</span></label>
                    <input type="text" id="rw-m-prop-address" wire:model="details.address_line" class="rw-m-input" autocomplete="street-address" placeholder="{{ __('Street, house number') }}">
                    @error('details.address_line') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                @foreach ([
                    ['city', __('City / Province'), true],
                    ['district', __('District (Khan / Srok)'), filled($details['city'] ?? null)],
                    ['commune', __('Commune (Sangkat / Khum)'), filled($details['district'] ?? null)],
                    ['village', __('Village (Phum)'), filled($details['commune'] ?? null)],
                ] as [$field, $fieldLabel, $enabled])
                    <div wire:key="rw-m-prop-loc-{{ $field }}">
                        <label for="rw-m-prop-{{ $field }}" class="{{ $label }}">{{ $fieldLabel }} <span class="font-normal text-slate-400">({{ __('optional') }})</span></label>
                        <select id="rw-m-prop-{{ $field }}" wire:model.live="details.{{ $field }}" class="rw-m-input disabled:opacity-50" @disabled(! $enabled)>
                            <option value="">{{ __('Select') }}</option>
                            @foreach ($locations[$field] as $value => $optionLabel)
                                <option value="{{ $value }}">{{ $optionLabel }}</option>
                            @endforeach
                        </select>
                        @error("details.$field") <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                @endforeach

                <div class="grid grid-cols-2 gap-2 pt-1">
                    <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'prop-details' })" class="rw-m-btn rw-m-btn-secondary">{{ __('Cancel') }}</button>
                    <button type="submit" class="rw-m-btn rw-m-btn-primary" wire:loading.attr="disabled" wire:target="saveDetails" id="rw-m-prop-details-save">
                        <span wire:loading.remove wire:target="saveDetails">{{ __('Save') }}</span>
                        <span wire:loading wire:target="saveDetails">{{ __('Saving…') }}</span>
                    </button>
                </div>
            </form>
        </x-mobile.sheet>

        {{-- ── Billing ── --}}
        <x-mobile.sheet name="prop-billing" :label="__('Billing')">
            <form class="space-y-4 px-5 pt-2" wire:submit="saveBilling" novalidate>
                <div>
                    <h2 class="text-lg font-bold">{{ __('Billing') }}</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Per-property defaults — never shared with your other properties.') }}</p>
                </div>

                <div>
                    <span class="{{ $label }}" id="rw-m-prop-currency-label">{{ __('Currency') }}</span>
                    <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-labelledby="rw-m-prop-currency-label">
                        @foreach (['USD' => __('US Dollar ($)'), 'KHR' => __('Khmer Riel (៛)')] as $code => $name)
                            <label class="rw-m-prop-choice">
                                <input type="radio" class="sr-only" value="{{ $code }}" wire:model="billing.currency" id="rw-m-prop-currency-{{ strtolower($code) }}">
                                <span>{{ $name }}</span>
                            </label>
                        @endforeach
                    </div>
                    <p class="{{ $hint }}">{{ __('Used as the default when creating rooms, utilities, and fees. Existing prices keep their own currency.') }}</p>
                    @error('billing.currency') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="rw-m-prop-due-day" class="{{ $label }}">{{ __('Due day of month') }}</label>
                        <input type="number" id="rw-m-prop-due-day" wire:model="billing.due_day_of_month" class="rw-m-input" inputmode="numeric" min="1" max="28" autocomplete="off">
                        @error('billing.due_day_of_month') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="rw-m-prop-due-days" class="{{ $label }}">{{ __('Days to pay') }}</label>
                        <input type="number" id="rw-m-prop-due-days" wire:model="billing.invoice_due_days" class="rw-m-input" inputmode="numeric" min="1" autocomplete="off">
                        @error('billing.invoice_due_days') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="-mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Number of days after the issue date before the invoice becomes overdue.') }}</p>

                <div>
                    <label for="rw-m-prop-late-fee" class="{{ $label }}">{{ __('Late fee') }}</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 font-semibold text-slate-400" aria-hidden="true" x-text="$wire.billing.currency === 'KHR' ? '៛' : '$'">{{ Money::symbol($billing['currency'] ?? null) }}</span>
                        <input type="text" id="rw-m-prop-late-fee" wire:model="billing.late_fee" class="rw-m-input pl-9" inputmode="decimal" autocomplete="off">
                    </div>
                    @error('billing.late_fee') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div class="rounded-2xl bg-slate-50 p-3 dark:bg-slate-800/60">
                    <label for="rw-m-prop-rate" class="{{ $label }}">{{ __('USD to KHR exchange rate') }}</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-sm font-semibold text-slate-400" aria-hidden="true">1 USD =</span>
                        <input type="text" id="rw-m-prop-rate" wire:model="billing.usd_khr_exchange_rate" class="rw-m-input pr-14 pl-20" inputmode="decimal" autocomplete="off">
                        <span class="pointer-events-none absolute top-1/2 right-4 -translate-y-1/2 text-sm font-semibold text-slate-400" aria-hidden="true">KHR</span>
                    </div>
                    @error('billing.usd_khr_exchange_rate') <p class="{{ $error }}">{{ $message }}</p> @enderror

                    <label for="rw-m-prop-rate-date" class="{{ $label }} mt-3">{{ __('Exchange rate date') }}</label>
                    <input type="date" id="rw-m-prop-rate-date" wire:model="billing.exchange_rate_date" class="rw-m-input">
                    @error('billing.exchange_rate_date') <p class="{{ $error }}">{{ $message }}</p> @enderror

                    <button type="button" wire:click="fetchExchangeRate" wire:loading.attr="disabled" wire:target="fetchExchangeRate" class="rw-m-btn rw-m-btn-secondary mt-3 w-full text-sm" id="rw-m-prop-rate-fetch">
                        <span wire:loading.remove wire:target="fetchExchangeRate">{{ __('Fetch today\'s exchange rate') }}</span>
                        <span wire:loading wire:target="fetchExchangeRate">{{ __('Loading…') }}</span>
                    </button>
                </div>

                <div>
                    <label for="rw-m-prop-prefix" class="{{ $label }}">{{ __('Invoice prefix') }} <span class="font-normal text-slate-400">({{ __('optional') }})</span></label>
                    <input type="text" id="rw-m-prop-prefix" wire:model="billing.invoice_prefix" class="rw-m-input" autocomplete="off" autocapitalize="characters" placeholder="{{ __('e.g. RIV') }}">
                    @error('billing.invoice_prefix') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-2 pt-1">
                    <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'prop-billing' })" class="rw-m-btn rw-m-btn-secondary">{{ __('Cancel') }}</button>
                    <button type="submit" class="rw-m-btn rw-m-btn-primary" wire:loading.attr="disabled" wire:target="saveBilling" id="rw-m-prop-billing-save">
                        <span wire:loading.remove wire:target="saveBilling">{{ __('Save') }}</span>
                        <span wire:loading wire:target="saveBilling">{{ __('Saving…') }}</span>
                    </button>
                </div>
            </form>
        </x-mobile.sheet>

        {{-- ── Move-in rules ── --}}
        <x-mobile.sheet name="prop-move-in" :label="__('Move-in rules')">
            <form
                class="space-y-4 px-5 pt-2"
                wire:submit="saveMoveIn"
                novalidate
                x-data="{ descriptions: @js(collect(FirstMonthBillingMode::cases())->mapWithKeys(fn ($m) => [$m->value => $m->getDescription()])) }"
            >
                <div>
                    <h2 class="text-lg font-bold">{{ __('Move-in rules') }}</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Configure how the first month\'s rent is calculated and what must be paid before a tenant moves in.') }}</p>
                </div>

                <div>
                    <label for="rw-m-prop-mode" class="{{ $label }}">{{ __('First-month billing mode') }}</label>
                    <select id="rw-m-prop-mode" wire:model="moveIn.first_month_billing_mode" class="rw-m-input">
                        @foreach ($billingModes as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <p class="{{ $hint }}" x-text="descriptions[$wire.moveIn.first_month_billing_mode] ?? ''"></p>
                    @error('moveIn.first_month_billing_mode') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div x-show="$wire.moveIn.first_month_billing_mode === @js(FirstMonthBillingMode::HalfMonth->value)" x-cloak>
                    <label for="rw-m-prop-cutoff" class="{{ $label }}">{{ __('Half-month cutoff day') }}</label>
                    <input type="number" id="rw-m-prop-cutoff" wire:model="moveIn.proration_cutoff_day" class="rw-m-input" inputmode="numeric" min="1" max="28" autocomplete="off">
                    <p class="{{ $hint }}">{{ __('If the tenant moves in AFTER this day of the month, charge half rent. Otherwise charge full rent.') }}</p>
                    @error('moveIn.proration_cutoff_day') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="rw-m-prop-preset" class="{{ $label }}">{{ __('Move-in requirements preset') }}</label>
                    <select id="rw-m-prop-preset" wire:model="moveIn.move_in_preset" class="rw-m-input">
                        <option value="">{{ __('Select') }}</option>
                        @foreach ($presets as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    <p class="{{ $hint }}">{{ __('Presets populate structured rules. Existing tenancy snapshots are never changed.') }}</p>
                    @error('moveIn.move_in_preset') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="rw-m-prop-deposit" class="{{ $label }}">{{ __('Security deposit') }}</label>
                    <select id="rw-m-prop-deposit" wire:model="moveIn.upfront_deposit_months" class="rw-m-input">
                        @foreach ($depositMonths as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('moveIn.upfront_deposit_months') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                @foreach ([
                    ['require_first_month_upfront', __('Require first month paid before move-in'), __('When enabled, the first invoice must be settled before the tenancy is considered active.')],
                    ['create_invoice_on_move_in', __('Auto-create invoice on move-in'), __('Automatically generate the first rent invoice when a new tenant is created.')],
                ] as [$field, $fieldLabel, $fieldHint])
                    <label class="flex min-h-12 cursor-pointer items-start justify-between gap-4">
                        <span>
                            <span class="block text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $fieldLabel }}</span>
                            <span class="block text-sm text-slate-500 dark:text-slate-400">{{ $fieldHint }}</span>
                        </span>
                        <span class="rw-m-prop-switch mt-0.5">
                            <input type="checkbox" class="sr-only" wire:model="moveIn.{{ $field }}" id="rw-m-prop-{{ str_replace('_', '-', $field) }}">
                        </span>
                    </label>
                @endforeach

                <div class="grid grid-cols-2 gap-2 pt-1">
                    <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'prop-move-in' })" class="rw-m-btn rw-m-btn-secondary">{{ __('Cancel') }}</button>
                    <button type="submit" class="rw-m-btn rw-m-btn-primary" wire:loading.attr="disabled" wire:target="saveMoveIn" id="rw-m-prop-move-in-save">
                        <span wire:loading.remove wire:target="saveMoveIn">{{ __('Save') }}</span>
                        <span wire:loading wire:target="saveMoveIn">{{ __('Saving…') }}</span>
                    </button>
                </div>
            </form>
        </x-mobile.sheet>

        {{-- ── Contacts ── --}}
        <x-mobile.sheet name="prop-contacts" :label="__('Contacts & property info')">
            <form class="space-y-4 px-5 pt-2" wire:submit="saveContacts" novalidate>
                <h2 class="text-lg font-bold">{{ __('Contacts & property info') }}</h2>

                <div>
                    <label for="rw-m-prop-caretaker" class="{{ $label }}">{{ __('Caretaker name') }}</label>
                    <input type="text" id="rw-m-prop-caretaker" wire:model="contacts.caretaker_name" class="rw-m-input" autocomplete="off" autocapitalize="words">
                    @error('contacts.caretaker_name') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="rw-m-prop-caretaker-phone" class="{{ $label }}">{{ __('Caretaker phone') }}</label>
                    <input type="tel" id="rw-m-prop-caretaker-phone" wire:model="contacts.caretaker_phone" class="rw-m-input" inputmode="tel" autocomplete="off">
                    @error('contacts.caretaker_phone') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="rw-m-prop-parking" class="{{ $label }}">{{ __('Parking info') }}</label>
                    <textarea id="rw-m-prop-parking" wire:model="contacts.parking_info" rows="2" class="rw-m-input"></textarea>
                    @error('contacts.parking_info') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="rw-m-prop-insurance" class="{{ $label }}">{{ __('Insurance info') }}</label>
                    <textarea id="rw-m-prop-insurance" wire:model="contacts.insurance_info" rows="2" class="rw-m-input"></textarea>
                    @error('contacts.insurance_info') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-2 pt-1">
                    <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'prop-contacts' })" class="rw-m-btn rw-m-btn-secondary">{{ __('Cancel') }}</button>
                    <button type="submit" class="rw-m-btn rw-m-btn-primary" wire:loading.attr="disabled" wire:target="saveContacts" id="rw-m-prop-contacts-save">
                        <span wire:loading.remove wire:target="saveContacts">{{ __('Save') }}</span>
                        <span wire:loading wire:target="saveContacts">{{ __('Saving…') }}</span>
                    </button>
                </div>
            </form>
        </x-mobile.sheet>

        {{-- ── Opening readings ── --}}
        @if ($meteredUtilities->isNotEmpty())
            <x-mobile.sheet name="prop-opening" :label="__('Opening readings')">
                <form class="space-y-4 px-5 pt-2" wire:submit="saveOpeningReadings" novalidate>
                    <div>
                        <h2 class="text-lg font-bold">{{ __('Opening readings') }}</h2>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('What each meter showed on the day you started. Without it the first invoice charges the meter\'s whole lifetime reading.') }}</p>
                    </div>

                    @if ($meteredUtilities->count() > 1)
                        <div class="-mx-5 flex gap-2 overflow-x-auto px-5 pb-1 [scrollbar-width:none]" role="group" aria-label="{{ __('Utility') }}">
                            @foreach ($meteredUtilities as $utility)
                                <button type="button" wire:click="$set('openingUtilityId', {{ $utility->id }})" class="rw-m-pill" aria-pressed="{{ $openingUtilityId === $utility->id ? 'true' : 'false' }}" id="rw-m-prop-opening-utility-{{ $utility->id }}">{{ UtilityCatalog::label($utility->name) }}</button>
                            @endforeach
                        </div>
                    @endif
                    @error('openingUtilityId') <p class="{{ $error }}">{{ $message }}</p> @enderror

                    <div>
                        <label for="rw-m-prop-opening-date" class="{{ $label }}">{{ __('Reading date') }}</label>
                        <input type="date" id="rw-m-prop-opening-date" wire:model="openingDate" max="{{ now()->toDateString() }}" class="rw-m-input">
                        <p class="{{ $hint }}">{{ __('The day these numbers were read off the meters.') }}</p>
                        @error('openingDate') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>

                    @php $utilityUom = $meteredUtilities->firstWhere('id', $openingUtilityId)?->unit_of_measure; @endphp
                    <div class="space-y-3 transition-opacity" wire:loading.class="opacity-50" wire:target="openingUtilityId">
                        @forelse ($openingRows as $row)
                            @php
                                $unitId = $row['unit']->id;
                                $open = in_array($row['state'], [OpeningReadingService::OPEN_METER, OpeningReadingService::OPEN_LEGACY], true);
                            @endphp
                            <div wire:key="rw-m-prop-opening-{{ $openingUtilityId }}-{{ $unitId }}">
                                <label for="rw-m-prop-opening-{{ $unitId }}" class="{{ $label }}">
                                    {{ __('Room :room', ['room' => $row['unit']->room_number]) }}@if ($utilityUom) <span class="font-normal text-slate-400">({{ $utilityUom }})</span>@endif
                                </label>
                                @if ($open)
                                    <input type="text" id="rw-m-prop-opening-{{ $unitId }}" wire:model="openingReadings.{{ $unitId }}" class="rw-m-input" inputmode="decimal" autocomplete="off" enterkeyhint="next">
                                    <p class="{{ $hint }}">
                                        @if ($row['state'] === OpeningReadingService::OPEN_METER)
                                            {{ __('Meter :meter — sets its opening index.', ['meter' => $row['meter']->serial ?: __('Meter #:id', ['id' => $row['meter']->getKey()])]) }}
                                        @else
                                            {{ __('Sets the starting baseline (no consumption billed).') }}
                                        @endif
                                    </p>
                                    @error('openingReadings.'.$unitId) <p class="{{ $error }}">{{ $message }}</p> @enderror
                                @else
                                    <input type="text" id="rw-m-prop-opening-{{ $unitId }}" class="rw-m-input bg-slate-100 text-slate-500 dark:bg-slate-800" value="{{ MeterUtilities::trimReading($row['baseline']) }}" disabled>
                                    <p class="{{ $hint }}">{{ __('Already set (:value) — reading from here on.', ['value' => MeterUtilities::trimReading($row['baseline'])]) }}</p>
                                @endif
                            </div>
                        @empty
                            <p class="{{ $hint }}">{{ __('No rooms are set up for this property yet.') }}</p>
                        @endforelse
                    </div>

                    @error('openingReadings') <p class="{{ $error }}" role="alert">{{ $message }}</p> @enderror

                    <div class="sticky bottom-0 -mx-5 grid grid-cols-2 gap-2 bg-white px-5 pt-2 pb-1 dark:bg-slate-900">
                        <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'prop-opening' })" class="rw-m-btn rw-m-btn-secondary">{{ __('Cancel') }}</button>
                        <button type="submit" class="rw-m-btn rw-m-btn-primary" wire:loading.attr="disabled" wire:target="saveOpeningReadings" id="rw-m-prop-opening-save">
                            <span wire:loading.remove wire:target="saveOpeningReadings">{{ __('Save') }}</span>
                            <span wire:loading wire:target="saveOpeningReadings">{{ __('Saving…') }}</span>
                        </button>
                    </div>
                </form>
            </x-mobile.sheet>
        @endif
    @endif
</div>
