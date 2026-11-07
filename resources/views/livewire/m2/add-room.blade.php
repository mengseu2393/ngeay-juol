<div id="m2-add-room-screen" class="m2a">
    <x-m2.header :title="__('Add room')" :subtitle="$properties->firstWhere('id', $propertyId)?->name" :back="$backUrl" />

    <main class="m2-wrap m2a-main m2a-stack">
        <form wire:submit="save" class="m2a-stack" id="m2-add-room-form" novalidate>
            @if ($properties->count() > 1)
                <div class="m2a-card">
                    <div>
                        <label for="m2-room-property" class="m2-label">{{ __('Property') }} *</label>
                        <select id="m2-room-property" wire:model.live="propertyId" class="m2-input">
                            @foreach ($properties as $item)
                                <option value="{{ $item->id }}" wire:key="m2-room-property-{{ $item->id }}">{{ $item->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            @endif

            @include('livewire.m2.partials.room-fields')

            <h2 class="m2a-h">{{ __('Rent') }}</h2>
            <div class="m2a-card">
                @include('livewire.m2.partials.room-rent-fields')
            </div>

            <div class="m2a-card">
                <div>
                    <label for="m2-room-notes" class="m2-label">{{ __('Notes') }} ({{ __('optional') }})</label>
                    <textarea id="m2-room-notes" wire:model="description" rows="2" class="m2-input m2a-area" autocomplete="off"></textarea>
                    @error('description')<p class="m2-err">{{ $message }}</p>@enderror
                </div>
            </div>

            {{-- The plan's room limit: the Unit model refuses, and the message says what to do. --}}
            @error('subscription')<div class="m2-notice is-bad" role="alert" id="m2-add-room-limit">{{ $message }}</div>@enderror

            <button type="submit" class="m2-btn" wire:loading.attr="disabled" wire:target="save" id="m2-add-room-save">
                <span wire:loading.remove wire:target="save">{{ __('Add room') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
        </form>
    </main>
</div>
