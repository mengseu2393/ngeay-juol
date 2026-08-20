<div class="rw-mb-table-wrap">
    <table class="rw-mb-table">
        <thead>
            <tr>
                <th class="rw-mb-table__select">
                    <input
                        type="checkbox"
                        wire:click="toggleSelectAllRooms"
                        @checked(count($this->rooms) > 0 && count($this->selectedRoomIndexes) === count($this->rooms))
                        aria-label="{{ __('Select all rooms') }}"
                    >
                </th>
                <th>{{ __('Room') }}</th>
                <th>{{ __('Tenant') }}</th>
                <th class="rw-mb-table__money">{{ __('Rent') }}</th>
                @foreach($this->activeUtilities() as $utility)
                    <th class="rw-mb-table__utility-head">
                        <span>{{ __($utility->name) }}</span>
                        <small>{{ __('Previous → New reading') }}</small>
                    </th>
                @endforeach
                <th class="rw-mb-table__money">{{ __('Estimated total') }}</th>
                <th class="rw-mb-table__action">{{ __('Status') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($this->rooms as $index => $room)
                @php $summary = $this->roomSummary($index); @endphp
                <tr
                    wire:key="billing-room-{{ $room['rental_id'] }}"
                    @class([
                        'rw-mb-table__row--skipped' => $room['skipped'],
                        'rw-mb-table__row--selected' => in_array((string) $index, $this->selectedRoomIndexes, true),
                        'rw-mb-table__row--grouped' => $room['is_grouped_with_previous'] ?? false,
                    ])
                >
                    <td class="rw-mb-table__select">
                        <input
                            type="checkbox"
                            wire:model.live="selectedRoomIndexes"
                            value="{{ $index }}"
                            aria-label="{{ __('Select room :room', ['room' => $room['room_number']]) }}"
                        >
                    </td>
                    <td class="rw-mb-table__room">
                        <span>{{ $room['room_number'] }}</span>
                    </td>
                    <td>
                        @unless($room['is_grouped_with_previous'] ?? false)
                            <div class="rw-mb-table__tenant">{{ $room['occupant_name'] }}</div>
                        @else
                            <div class="rw-mb-table__tenant rw-mb-table__tenant--same">{{ __('Same tenant') }}</div>
                        @endunless
                    </td>
                    <td class="rw-mb-table__money">
                        <strong>{{ $this->formatMoney($room['rent']) }}</strong>
                        @if($room['is_first_invoice'] ?? false)
                            <small>{{ __('Prorated') }}</small>
                        @endif
                    </td>

                    @foreach($room['utilities'] as $utilityIndex => $utility)
                        @php
                            $preview = $this->utilityPreview($index, $utilityIndex);
                            $state = $utility['state_override'] ?? 'normal';
                            $requiresReading = (bool) ($utility['requires_reading'] ?? true);
                            $needsReadingInput = ! $room['skipped'] && $requiresReading && in_array($state, ['normal', 'free', 'waived'], true);
                        @endphp
                        <td
                            class="rw-mb-table__utility"
                            wire:key="billing-room-{{ $room['rental_id'] }}-utility-{{ $utility['property_utility_id'] }}"
                        >
                            <select
                                class="rw-mb-state-select"
                                data-state="{{ $state }}"
                                wire:model.live="rooms.{{ $index }}.utilities.{{ $utilityIndex }}.state_override"
                                {{ $room['skipped'] ? 'disabled' : '' }}
                                aria-label="{{ __('Billing state for :utility in room :room', ['utility' => $utility['utility_name'], 'room' => $room['room_number']]) }}"
                            >
                                <option value="normal">{{ __('Normal') }}</option>
                                <option value="free">{{ __('Free') }}</option>
                                <option value="waived">{{ __('Waived') }}</option>
                                <option value="not_applicable">{{ __('Not applicable') }}</option>
                                <option value="skipped_this_cycle">{{ __('Skip this cycle') }}</option>
                                <option value="custom">{{ __('Custom amount') }}</option>
                            </select>

                            @if($room['skipped'])
                                <div class="rw-mb-table__empty">{{ __('Room skipped') }}</div>
                            @elseif($state === 'custom')
                                <div class="rw-mb-custom-row">
                                    <input
                                        type="number"
                                        step="any"
                                        wire:model.live.debounce.300ms="rooms.{{ $index }}.utilities.{{ $utilityIndex }}.override_amount"
                                        placeholder="{{ __('Amount') }}"
                                    >
                                    <select wire:model.live="rooms.{{ $index }}.utilities.{{ $utilityIndex }}.override_currency">
                                        <option value="USD">USD</option>
                                        <option value="KHR">KHR</option>
                                    </select>
                                </div>
                            @elseif($state === 'not_applicable')
                                <div class="rw-mb-table__empty">{{ __('Not applicable — no charge') }}</div>
                            @elseif($state === 'skipped_this_cycle')
                                <div class="rw-mb-table__empty">{{ __('Skipped this cycle') }}</div>
                            @elseif(! $requiresReading)
                                <div class="rw-mb-fixed-charge">
                                    <span>{{ __('Fixed') }}</span>
                                    <strong>{{ \App\Support\Money::format($preview['charge'], $utility['currency'] ?? 'USD') }}</strong>
                                </div>
                            @elseif($needsReadingInput)
                                <div class="rw-mb-reading-row">
                                    <div class="rw-mb-reading-row__previous">
                                        <span>{{ __('Previous') }}</span>
                                        <strong>{{ $preview['old_reading'] !== null ? $this->formatQuantity($preview['old_reading']) : '—' }}</strong>
                                    </div>
                                    <label>
                                        <span class="sr-only">{{ __('New reading for :utility in room :room', ['utility' => $utility['utility_name'], 'room' => $room['room_number']]) }}</span>
                                        <input
                                            type="number"
                                            step="any"
                                            inputmode="decimal"
                                            wire:model.live.debounce.300ms="rooms.{{ $index }}.utilities.{{ $utilityIndex }}.new_reading"
                                            placeholder="{{ __('New reading') }}"
                                        >
                                    </label>
                                </div>

                                @if($preview['is_lower_reading'])
                                    <input
                                        class="rw-mb-reading-row__reason"
                                        type="text"
                                        wire:model.live.debounce.300ms="rooms.{{ $index }}.utilities.{{ $utilityIndex }}.override_reason"
                                        placeholder="{{ __('Reason for lower reading') }}"
                                    >
                                @endif

                                <div class="rw-mb-reading-row__result">
                                    @if($preview['amount_used'] !== null)
                                        <span>{{ $this->formatQuantity($preview['amount_used']) }} {{ $utility['unit_of_measure'] }}</span>
                                        <strong>{{ \App\Support\Money::format($preview['charge'], $utility['currency'] ?? 'USD') }}</strong>
                                    @else
                                        <span>{{ __('Waiting for reading') }}</span>
                                    @endif
                                </div>
                            @endif
                        </td>
                    @endforeach

                    <td class="rw-mb-table__money rw-mb-table__total">
                        <strong>{{ $summary['estimated_total_display'] }}</strong>
                    </td>
                    <td class="rw-mb-table__action">
                        <div class="rw-mb-move-btns">
                            <button
                                type="button"
                                wire:click="moveRoomUp({{ $index }})"
                                @disabled($index === 0)
                                class="rw-mb-move-btn"
                                aria-label="{{ __('Move :room up', ['room' => $room['room_number']]) }}"
                            >&uarr;</button>
                            <button
                                type="button"
                                wire:click="moveRoomDown({{ $index }})"
                                @disabled($index === count($this->rooms) - 1)
                                class="rw-mb-move-btn"
                                aria-label="{{ __('Move :room down', ['room' => $room['room_number']]) }}"
                            >&darr;</button>
                        </div>
                        <button
                            type="button"
                            wire:click="toggleRoomSkip({{ $index }})"
                            @class(['rw-mb-skip-btn', 'rw-mb-skip-btn--restore' => $room['skipped']])
                        >
                            {{ $room['skipped'] ? __('Restore') : __('Skip') }}
                        </button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
