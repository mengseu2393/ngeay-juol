<x-filament-panels::page>
    @if($fromSimpleMode)
        {{-- Simple Mode: no manual save button — changes save automatically
             shortly after the landlord stops typing/toggling a field. --}}
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
