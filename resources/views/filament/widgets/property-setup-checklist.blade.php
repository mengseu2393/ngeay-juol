{{--
    Setup checklist for one property. Styling is hand-written `rw-setup-*` CSS in
    public/css/rentwise-admin.css — Filament ships a precompiled Tailwind build
    that only contains the utilities its own components use, so arbitrary classes
    written here would silently compile to nothing.
--}}
@php
    $steps = $this->getSteps();
    $remaining = collect($steps)->reject(fn (array $step) => $step['done'])->count();
@endphp

<x-filament-widgets::widget>
    @if ($steps !== [] && $remaining > 0)
        <x-filament::section>
            <x-slot name="heading">
                {{ __('Finish setting up :property', ['property' => $this->getPropertyName()]) }}
            </x-slot>

            <x-slot name="description">
                {{ trans_choice(':count step left|:count steps left', $remaining, ['count' => $remaining]) }}
            </x-slot>

            <ol class="rw-setup">
                @foreach ($steps as $step)
                    <li @class([
                        'rw-setup__step',
                        'rw-setup__step--done' => $step['done'],
                        {{-- Not yet actionable: waiting on an earlier step. --}}
                        'rw-setup__step--blocked' => $step['blocked'],
                    ])>
                        <span class="rw-setup__marker">
                            @if ($step['done'])
                                <x-filament::icon icon="heroicon-m-check-circle" class="rw-setup__icon" />
                            @elseif ($step['blocked'])
                                <x-filament::icon icon="heroicon-m-ellipsis-horizontal-circle" class="rw-setup__icon" />
                            @else
                                <x-filament::icon :icon="$step['icon']" class="rw-setup__icon" />
                            @endif
                        </span>

                        <span class="rw-setup__body">
                            <span class="rw-setup__label">{{ $step['label'] }}</span>
                            <span class="rw-setup__description">{{ $step['description'] }}</span>
                        </span>

                        <span class="rw-setup__action">
                            @unless ($step['done'] || $step['blocked'])
                                <x-filament::button
                                    size="sm"
                                    color="primary"
                                    wire:click="go('{{ $step['key'] }}')"
                                    wire:loading.attr="disabled"
                                >
                                    {{ $step['cta'] }}
                                </x-filament::button>
                            @endunless
                        </span>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
