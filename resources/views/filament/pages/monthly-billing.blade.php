<x-filament-panels::page>
    <form wire:submit="generate">
        {{ $this->form }}

        <div class="mt-6 flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-document-currency-dollar" size="lg">
                {{ __('Generate invoices') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
