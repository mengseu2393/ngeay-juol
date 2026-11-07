<div id="m2-edit-room" class="m2a">
    <x-m2.header :title="__('Edit room')" :subtitle="__('Room').' '.$unit->room_number" :back="route('m2.room', $unit)" />

    <main class="m2-wrap m2a-main m2a-stack">
        <form wire:submit="save" class="m2a-stack" id="m2-edit-room-form" novalidate>
            @include('livewire.m2.partials.room-fields')

            <h2 class="m2a-h">{{ __('Rent') }}</h2>
            <div class="m2a-card">
                @include('livewire.m2.partials.room-rent-fields')
                {{-- Invoices bill the tenancy's own rent, so a new room price doesn't reach someone already living here. --}}
                @if ($hasTenant)
                    <p class="m2-muted m2-sm" style="margin-bottom:0" id="m2-room-rent-hint">{{ __('This is the price for the next tenant. The current tenant keeps their rent; change it on Edit tenant.') }}</p>
                @endif
            </div>

            <h2 class="m2a-h">{{ __('Status') }}</h2>
            <div class="m2a-card">
                <div>
                    <label for="m2-room-status" class="m2-label">{{ __('Room status') }}</label>
                    <select id="m2-room-status" wire:model="status" class="m2-input" @disabled($hasTenant)>
                        @foreach ($statuses as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </select>
                    @if ($hasTenant)
                        <p class="m2-muted m2-sm" style="margin:4px 0 0" id="m2-room-status-hint">{{ __('Someone lives here. End the tenancy from the room screen to free it.') }}</p>
                    @endif
                    @error('status')<p class="m2-err">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="m2-room-notes" class="m2-label">{{ __('Notes') }} ({{ __('optional') }})</label>
                    <textarea id="m2-room-notes" wire:model="description" rows="3" class="m2-input m2a-area" autocomplete="off"></textarea>
                    @error('description')<p class="m2-err">{{ $message }}</p>@enderror
                </div>
            </div>

            <button type="submit" class="m2-btn" wire:loading.attr="disabled" wire:target="save" id="m2-edit-room-save">
                <span wire:loading.remove wire:target="save">{{ __('Save changes') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
        </form>

        {{-- Delete: only an empty room, and only for someone allowed to --}}
        @if ($canDelete)
            @if (! $confirmingDelete)
                <button type="button" wire:click="openDelete" class="m2-btn is-danger" id="m2-room-delete">{{ __('Delete room') }}</button>
            @else
                <div class="m2a-card" id="m2-room-delete-card">
                    <b style="display:block">{{ __('Delete room :room?', ['room' => $unit->room_number]) }}</b>
                    <p class="m2-muted m2-sm" style="margin-bottom:0">{{ __('The room disappears from your room list. Its past invoices and readings stay in your records.') }}</p>
                    <div class="m2-grid2">
                        <button type="button" wire:click="cancelDelete" class="m2-btn is-ghost" id="m2-room-delete-cancel">{{ __('Cancel') }}</button>
                        <button type="button" wire:click="delete" wire:loading.attr="disabled" wire:target="delete" class="m2-btn is-danger" id="m2-room-delete-confirm">{{ __('Delete') }}</button>
                    </div>
                </div>
            @endif
        @endif
    </main>
</div>
