@php
    use App\Enums\BillingType;

    $optional = '<span class="m2a-opt">('.e(__('optional')).')</span>';
    $billingShort = [
        BillingType::Metered->value => __('Metered'),
        BillingType::Flat->value => __('Flat'),
        BillingType::Shared->value => __('Shared'),
    ];
@endphp

<div id="m2-utility-rate" class="m2a">
    <x-m2.header :title="$title" :subtitle="$record ? __('Edit utility rate') : __('Utility Rates')" :back="route('m2.utilities')" />

    <main class="m2-wrap m2a-main">
        <form wire:submit="submit" novalidate id="m2-utility-form" class="m2a-stack">
            <div class="m2a-card">
                <div>
                    <label for="m2-utility-name" class="m2-label">{{ __('Name') }}</label>
                    <select id="m2-utility-name" wire:model.live="form.name" class="m2-input">
                        @foreach ($nameOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('name') <p class="m2-err">{{ $message }}</p> @enderror
                </div>
                <div>
                    <span class="m2-label" id="m2-utility-billing-label">{{ __('Billing type') }}</span>
                    <div class="m2-seg" role="radiogroup" aria-labelledby="m2-utility-billing-label">
                        @foreach ($billingShort as $value => $label)
                            <label title="{{ $billingTypes[$value] ?? $label }}"><input type="radio" class="m2-sr" wire:model="form.billing_type" value="{{ $value }}" id="m2-utility-billing-{{ $value }}">{{ $label }}</label>
                        @endforeach
                    </div>
                    @error('billing_type') <p class="m2-err">{{ $message }}</p> @enderror
                </div>
                <div class="m2a-two">
                    <div>
                        <label for="m2-utility-rate-input" class="m2-label">{{ __('Rate') }} ({{ $currency }})</label>
                        <input type="text" id="m2-utility-rate-input" wire:model="form.rate" class="m2-input" inputmode="decimal" autocomplete="off" placeholder="0.00">
                        @error('rate') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="m2-utility-unit" class="m2-label">{{ __('Unit of measure') }}</label>
                        <input type="text" id="m2-utility-unit" wire:model="form.unit_of_measure" class="m2-input" autocomplete="off" placeholder="kWh">
                        @error('unit_of_measure') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div>
                    <label for="m2-utility-provider" class="m2-label">{{ __('Provider') }} {!! $optional !!}</label>
                    <input type="text" id="m2-utility-provider" wire:model="form.provider" class="m2-input" autocomplete="off" placeholder="EDC, PPWSA…">
                    @error('provider') <p class="m2-err">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="m2-utility-account" class="m2-label">{{ __('Account #') }} {!! $optional !!}</label>
                    <input type="text" id="m2-utility-account" wire:model="form.account_ref" class="m2-input" autocomplete="off">
                    @error('account_ref') <p class="m2-err">{{ $message }}</p> @enderror
                </div>
            </div>

            <button type="submit" class="m2-btn" wire:loading.attr="disabled" wire:target="submit" id="m2-utility-save">
                <span wire:loading.remove wire:target="submit">{{ __('Save') }}</span>
                <span wire:loading wire:target="submit">{{ __('Saving…') }}</span>
            </button>
        </form>

        @if ($record)
            @if ($confirmingDelete)
                <div class="m2a-card" role="alertdialog" aria-labelledby="m2-utility-delete-title" id="m2-utility-delete-box">
                    <div>
                        <b id="m2-utility-delete-title" style="display:block">{{ __('Delete utility?') }}</b>
                        <p class="m2a-hint" style="margin:4px 0 0">{{ __('Past invoices keep their lines. This only removes the rate from this property going forward.') }}</p>
                    </div>
                    <div class="m2a-two">
                        <button type="button" wire:click="cancelDelete" class="m2-btn is-ghost" id="m2-utility-delete-cancel">{{ __('Cancel') }}</button>
                        <button type="button" wire:click="destroy" wire:loading.attr="disabled" wire:target="destroy" class="m2-btn is-danger" id="m2-utility-delete-confirm">{{ __('Delete') }}</button>
                    </div>
                </div>
            @else
                <div class="m2a-two">
                    <button type="button" wire:click="toggle" wire:loading.attr="disabled" wire:target="toggle" class="m2-btn is-ghost" id="m2-utility-toggle">
                        {{ $record->is_active ? __('Deactivate') : __('Activate') }}
                    </button>
                    <button type="button" wire:click="askDelete" class="m2-btn is-danger" id="m2-utility-delete">{{ __('Delete') }}</button>
                </div>
            @endif
        @endif
    </main>
</div>
