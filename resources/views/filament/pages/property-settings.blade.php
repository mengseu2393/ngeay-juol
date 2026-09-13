<x-filament-panels::page>
    @if($fromSimpleMode)
        {{-- Simple Mode: changes still save automatically shortly after the
             landlord stops typing/toggling a field, but a manual Save button
             stays available too — for an immediate save or just reassurance. --}}
        <div
            x-data="{
                timer: null,
                queueSave() {
                    clearTimeout(this.timer);
                    this.timer = setTimeout(() => $wire.save(), 800);
                },
            }"
            @input.capture="queueSave()"
            @change.capture="queueSave()"
            class="space-y-6"
        >
            {{ $this->form }}

            <div class="flex justify-end">
                <x-filament::button wire:click="save" size="lg" icon="heroicon-m-check">
                    {{ __('Save changes') }}
                </x-filament::button>
            </div>
        </div>
    @else
        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}

            <div class="flex justify-end">
                <x-filament::button type="submit" size="lg" icon="heroicon-m-check">
                    {{ __('Save changes') }}
                </x-filament::button>
            </div>
        </form>
    @endif
</x-filament-panels::page>
