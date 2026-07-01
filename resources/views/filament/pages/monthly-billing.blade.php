<x-filament-panels::page>
    <form wire:submit="generate">
        {{ $this->form }}

        @php
            $rowCount = count($this->data['rows'] ?? []);
        @endphp

        @if(!$this->billingEnabled)
            @if(\App\Support\ActiveProperty::id() || ($this->data['property_id'] ?? null))
                <div class="mt-6 flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-amber-300 dark:border-amber-700 py-14 text-center">
                    <x-heroicon-o-exclamation-triangle class="mb-3 h-10 w-10 text-amber-500"/>
                    <p class="text-base font-semibold text-gray-700 dark:text-gray-200">
                        {{ __('Monthly Billing is Disabled') }}
                    </p>
                    <p class="mt-1 text-sm text-gray-400 dark:text-gray-500 max-w-md">
                        {{ __('To use this feature, you must first enable "Monthly Billing" in your Property Settings.') }}
                    </p>
                    <div class="mt-4">
                        <x-filament::button
                            href="{{ route('filament.landlord.pages.property-settings') }}"
                            tag="a"
                            color="warning"
                        >
                            {{ __('Go to Property Settings') }}
                        </x-filament::button>
                    </div>
                </div>
            @endif
        @elseif($rowCount > 0)
            {{-- ── Summary bar + Generate button ──────────────────────────── --}}
            <div class="mt-6 flex items-center justify-between gap-4 px-1 py-3">
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-100 dark:bg-primary-900/40 px-3 py-1 text-sm font-semibold text-primary-700 dark:text-primary-300">
                        <x-heroicon-s-document-currency-dollar class="h-4 w-4"/>
                        {{ $rowCount }} {{ __('room(s) ready to bill') }}
                    </span>
                </div>

                <x-filament::button
                    type="submit"
                    icon="heroicon-o-document-currency-dollar"
                    size="lg"
                >
                    {{ __('Generate invoices') }}
                </x-filament::button>
            </div>
        @else
            {{-- ── Empty state — nothing due ────────────────────────────────── --}}
            @if(\App\Support\ActiveProperty::id() || ($this->data['property_id'] ?? null))
                <div class="mt-6 flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-200 dark:border-gray-700 py-14 text-center">
                    <x-heroicon-o-check-badge class="mb-3 h-10 w-10 text-success-400 dark:text-success-500"/>
                    <p class="text-base font-semibold text-gray-700 dark:text-gray-200">
                        {{ __('All caught up!') }}
                    </p>
                    <p class="mt-1 text-sm text-gray-400 dark:text-gray-500">
                        {{ __('No rooms are due for billing on this date. Change the issue date to load a different billing period.') }}
                    </p>
                </div>
            @endif
        @endif
    </form>
</x-filament-panels::page>
