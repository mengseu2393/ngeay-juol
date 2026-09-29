<div
    class="space-y-4"
    x-data="{
        formOpen: false,
        form: {},
        defaults: @js($nameDefaults),
        blank() {
            return { id: null, name: 'Electricity', billing_type: {{ \App\Enums\BillingType::Metered->value }}, rate: '', unit_of_measure: 'kWh', provider: '', account_ref: '', is_active: true };
        },
        openAdd() { this.form = this.blank(); this.formOpen = true; },
        openEdit(u) { this.form = { ...u }; this.formOpen = true; },
        applyDefaults() {
            const d = this.defaults[this.form.name];
            if (d) { this.form.unit_of_measure = d.unit; this.form.billing_type = d.billing; }
        },
        deleteOpen: false,
        del: { id: null, label: '' },
        openDelete(u) { this.del = { id: u.id, label: u.label }; this.deleteOpen = true; },
    }"
    @utility-rate-saved.window="formOpen = false"
    @utility-rate-deleted.window="deleteOpen = false"
    @rw-utility-rate-add.window="openAdd()"
>
    <style>[x-cloak] { display: none !important; }</style>

    {{-- ── Filters ── --}}
    <div class="rw-sm-filter-bar flex gap-2 overflow-x-auto pb-1">
        @foreach(['all' => __('All'), 'active' => __('Active'), 'inactive' => __('Inactive')] as $val => $label)
            <button
                wire:click="$set('filter', '{{ $val }}')"
                id="utility-rate-filter-{{ $val }}"
                class="rw-sm-filter-pill {{ $filter === $val ? 'rw-sm-filter-active' : '' }}"
            >{{ $label }}</button>
        @endforeach
    </div>

    {{-- ── Search ── --}}
    <div class="relative">
        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="{{ __('Utility or provider…') }}"
            class="rw-sm-search-input"
            id="utility-rate-search"
        >
        <svg class="rw-sm-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 15.803 7.5 7.5 0 0015.803 15.803z"/></svg>
    </div>

    @if($successMessage)
        <div class="rw-sm-success-banner" role="status">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ $successMessage }}</span>
        </div>
    @endif

    {{-- ── Add / edit popup (Alpine-owned; only Save hits the server) ── --}}
    <div
        x-cloak
        x-show="formOpen"
        class="rw-sm-modal-overlay fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 px-4 py-8"
        @keydown.escape.window="formOpen = false"
        id="utility-rate-form-popup"
    >
        <div class="rw-sm-modal w-full max-w-md mt-6" @click.outside="formOpen = false">
            <h3 class="rw-sm-modal-title" x-text="form.id ? @js(__('Edit utility rate')) : @js(__('Add utility'))"></h3>

            <div class="mt-4 space-y-3">
                <div>
                    <label class="rw-sm-label" for="utility-rate-name">{{ __('Name') }}</label>
                    <select id="utility-rate-name" x-model="form.name" @change="applyDefaults()" class="rw-sm-input">
                        @foreach($nameOptions as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('name') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="rw-sm-label" for="utility-rate-billing">{{ __('Billing type') }}</label>
                    <select id="utility-rate-billing" x-model.number="form.billing_type" class="rw-sm-input">
                        @foreach($billingTypes as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('billing_type') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="rw-sm-label" for="utility-rate-rate">{{ __('Rate') }} <span class="text-gray-400">({{ $currency }})</span></label>
                        <input type="number" id="utility-rate-rate" x-model="form.rate" step="0.0001" min="0" class="rw-sm-input" placeholder="0.00">
                        @error('rate') <p class="rw-sm-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="rw-sm-label" for="utility-rate-unit">{{ __('Unit of measure') }}</label>
                        <input type="text" id="utility-rate-unit" x-model="form.unit_of_measure" class="rw-sm-input" placeholder="kWh">
                        @error('unit_of_measure') <p class="rw-sm-error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="rw-sm-label" for="utility-rate-provider">{{ __('Provider') }}</label>
                    <input type="text" id="utility-rate-provider" x-model="form.provider" class="rw-sm-input" placeholder="EDC, PPWSA…">
                    @error('provider') <p class="rw-sm-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="rw-sm-label" for="utility-rate-account">{{ __('Account #') }}</label>
                    <input type="text" id="utility-rate-account" x-model="form.account_ref" class="rw-sm-input">
                </div>

                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300" for="utility-rate-active">
                    <input type="checkbox" id="utility-rate-active" x-model="form.is_active" class="rw-sm-checkbox">
                    <span>{{ __('Active') }}</span>
                </label>
            </div>

            <div class="mt-5 flex gap-3">
                <button type="button" @click="formOpen = false" class="rw-sm-btn-secondary flex-1" id="utility-rate-cancel-btn">{{ __('Cancel') }}</button>
                <button
                    type="button"
                    @click="$wire.save(form.id, { ...form, rate: String(form.rate ?? '') })"
                    wire:loading.attr="disabled"
                    wire:target="save"
                    class="rw-sm-btn-primary flex-1"
                    id="utility-rate-submit-btn"
                >
                    <span wire:loading.remove wire:target="save">{{ __('Save') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </button>
            </div>
        </div>
    </div>

    {{-- ── Delete confirm popup (in-page, no browser dialog) ── --}}
    <div
        x-cloak
        x-show="deleteOpen"
        class="rw-sm-modal-overlay fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/50 px-4 py-4"
        @keydown.escape.window="deleteOpen = false"
        id="utility-rate-delete-popup"
    >
        <div class="rw-sm-modal w-full max-w-sm" @click.outside="deleteOpen = false">
            <h3 class="rw-sm-modal-title">{{ __('Delete utility?') }}</h3>
            <p class="rw-sm-modal-sub" x-text="del.label"></p>
            <p class="mt-3 text-sm text-gray-600 dark:text-gray-400">{{ __('Past invoices keep their lines. This only removes the rate from this property going forward.') }}</p>
            <div class="mt-5 flex gap-3">
                <button type="button" @click="deleteOpen = false" class="rw-sm-btn-secondary flex-1" id="utility-rate-delete-cancel-btn">{{ __('Cancel') }}</button>
                <button
                    type="button"
                    @click="$wire.delete(del.id)"
                    wire:loading.attr="disabled"
                    wire:target="delete"
                    class="rw-sm-btn-warning flex-1"
                    id="utility-rate-delete-confirm-btn"
                >{{ __('Delete') }}</button>
            </div>
        </div>
    </div>

    {{-- ── Cards ── --}}
    @forelse($utilities as $utility)
        @php
            $label = \App\Filament\Resources\PropertyUtilityResource::utilityLabel((string) $utility->name);
            $editData = [
                'id' => $utility->id,
                'name' => $utility->name,
                'billing_type' => $utility->billing_type?->value,
                'rate' => $utility->rate !== null ? rtrim(rtrim(number_format((float) $utility->rate, 4, '.', ''), '0'), '.') : '',
                'unit_of_measure' => $utility->unit_of_measure,
                'provider' => $utility->provider,
                'account_ref' => $utility->account_ref,
                'is_active' => (bool) $utility->is_active,
                'label' => $label,
            ];
        @endphp

        <div class="rw-sm-invoice-card" id="utility-rate-card-{{ $utility->id }}">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="rw-sm-room-number truncate">{{ $label }}</p>
                    @if($utility->provider)
                        <p class="rw-sm-tenant-name truncate">{{ $utility->provider }}</p>
                    @endif
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <span class="rw-sm-badge {{ $utility->is_active ? 'rw-sm-badge-success' : 'rw-sm-badge-gray' }}">
                        {{ $utility->is_active ? __('Active') : __('Inactive') }}
                    </span>
                    <div class="rw-sm-menu" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                        <button type="button" @click="open = ! open" class="rw-sm-menu-btn" id="utility-rate-actions-btn-{{ $utility->id }}" aria-label="{{ __('Actions') }}" :aria-expanded="open">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a1.5 1.5 0 110 3 1.5 1.5 0 010-3zM10 8.5a1.5 1.5 0 110 3 1.5 1.5 0 010-3zM11.5 15.5a1.5 1.5 0 10-3 0 1.5 1.5 0 003 0z"/></svg>
                        </button>
                        <div x-show="open" x-cloak x-transition.opacity class="rw-sm-menu-panel">
                            <button type="button" @click="open = false; openEdit(@js($editData))" class="rw-sm-menu-item" id="utility-rate-edit-btn-{{ $utility->id }}">{{ __('Edit') }}</button>
                            <button type="button" @click="open = false" wire:click="toggleActive({{ $utility->id }})" class="rw-sm-menu-item" id="utility-rate-toggle-btn-{{ $utility->id }}">
                                {{ $utility->is_active ? __('Deactivate') : __('Activate') }}
                            </button>
                            <button type="button" @click="open = false; openDelete(@js($editData))" class="rw-sm-menu-item rw-sm-menu-item-danger" id="utility-rate-delete-btn-{{ $utility->id }}">{{ __('Delete') }}</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
                <div>
                    <p class="rw-sm-detail-label">{{ __('Rate') }}</p>
                    <p class="rw-sm-detail-value">
                        {{ \App\Support\Money::formatForRecord($utility->rate, $utility) }}@if($utility->unit_of_measure)<span class="text-gray-400 font-normal"> / {{ $utility->unit_of_measure }}</span>@endif
                    </p>
                </div>
                <div>
                    <p class="rw-sm-detail-label">{{ __('Billing type') }}</p>
                    <p class="rw-sm-detail-value">{{ $utility->billing_type?->getLabel() }}</p>
                </div>
            </div>
        </div>
    @empty
        <div class="rw-sm-empty-state rounded-2xl border border-dashed border-gray-200 dark:border-gray-700 p-8 text-center">
            <p class="text-gray-500 dark:text-gray-400">{{ __('No utilities set up yet.') }}</p>
            <button type="button" @click="openAdd()" class="rw-sm-btn-primary mt-4" id="utility-rate-empty-add-btn">{{ __('Add utility') }}</button>
        </div>
    @endforelse
</div>
