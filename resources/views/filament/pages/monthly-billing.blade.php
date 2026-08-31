<x-filament-panels::page>
    @php
        $access = $this->getAccess();
        $utilities = $this->activeUtilities();
    @endphp

    {{-- NOTE: no mx-auto here — Filament's page body is a CSS grid, and auto
         margins make a grid item shrink to fit-content instead of stretching. --}}
    <div class="rw-mb rw-monthly-billing-root w-full max-w-full space-y-5" x-data="{ confirmOpen: false }">

        {{-- Subscription banners --}}
        @if($access === \App\Enums\SubscriptionAccess::PastDue)
            <div class="rw-mb-banner rw-mb-banner--amber">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0" />
                <div>
                    <p class="rw-mb-banner__title">{{ __('Subscription past due') }}</p>
                    <p class="rw-mb-banner__body">{{ __('Please complete payment to restore full access.') }}</p>
                </div>
            </div>
        @elseif($access === \App\Enums\SubscriptionAccess::ReadOnly)
            <div class="rw-mb-banner rw-mb-banner--red">
                <x-heroicon-o-lock-closed class="h-5 w-5 shrink-0" />
                <div>
                    <p class="rw-mb-banner__title">{{ __('Write actions are disabled') }}</p>
                    <p class="rw-mb-banner__body">{{ __('Your subscription is read-only until payment is completed.') }}</p>
                </div>
            </div>
        @endif

        {{-- No property selected: pick one --}}
        @if(! $this->propertyId)
            <div class="rw-mb-card rw-mb-card--pad space-y-4">
                <div>
                    <h2 class="rw-mb-card__title">{{ __('Choose a property to bill') }}</h2>
                    <p class="rw-mb-card__subtitle">{{ __('Billing runs one property at a time so rooms never mix.') }}</p>
                </div>

                <div class="rw-mb-checklist">
                    @forelse($this->propertyPickerCards() as $card)
                        <button
                            type="button"
                            class="rw-mb-checklist__item w-full text-left"
                            wire:click="chooseProperty({{ $card['id'] }})"
                            wire:key="property-card-{{ $card['id'] }}"
                        >
                            <span class="min-w-0">
                                <span class="rw-mb-checklist__room">{{ $card['name'] }}</span>
                                <span class="rw-mb-checklist__tenant">
                                    {{ $card['due_count'] > 0 ? __(':count room(s) due', ['count' => $card['due_count']]) : $card['status_label'] }}
                                </span>
                            </span>
                            <span class="rw-mb-badge rw-mb-badge--{{ $card['status_color'] }}">{{ $card['status_label'] }}</span>
                        </button>
                    @empty
                        <div class="rw-mb-checklist__empty">{{ __('No properties found.') }}</div>
                    @endforelse
                </div>
            </div>

        {{-- Billing disabled for this property --}}
        @elseif(! $this->billingEnabled())
            <div class="rw-mb-banner rw-mb-banner--amber">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0" />
                <div>
                    <p class="rw-mb-banner__title">{{ __('Monthly billing is disabled for this property.') }}</p>
                    <p class="rw-mb-banner__body">{{ __('Please enable it in property settings before continuing.') }}</p>
                    {{-- Without this the screen is a dead end: the choice persists across
                         reloads, so picking a billing-disabled property stranded you here. --}}
                    @if($this->visibleProperties()->count() > 1)
                        <p class="rw-mb-banner__body">
                            <button type="button" class="rw-mb-link-btn" wire:click="changeProperty">{{ __('Change property') }}</button>
                        </p>
                    @endif
                </div>
            </div>

        {{-- Billing workspace --}}
        @else
            {{-- Last run summary --}}
            @if($this->lastRun)
                <div class="rw-mb-card rw-mb-card--pad space-y-3" wire:key="last-run-banner">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="rw-mb-card__title">{{ __('Billing complete') }}</h2>
                            <p class="rw-mb-card__subtitle">
                                {{ __('Created :count invoice(s).', ['count' => $this->lastRun['created']]) }}
                                @if($this->lastRun['rooms'])
                                    {{ __('Rooms') }}: {{ implode(', ', $this->lastRun['rooms']) }}
                                @endif
                            </p>
                        </div>
                        <button type="button" class="rw-mb-link-btn shrink-0" wire:click="dismissLastRun">{{ __('Dismiss') }}</button>
                    </div>

                    <div class="rw-mb-tag-row">
                        <span class="rw-mb-tag rw-mb-tag--emerald">{{ __('Created') }}: {{ $this->lastRun['created'] }}</span>
                        <span class="rw-mb-tag rw-mb-tag--gray">{{ __('Skipped') }}: {{ $this->lastRun['skipped'] }}</span>
                        @if($this->lastRun['failed'] > 0)
                            <span class="rw-mb-tag rw-mb-tag--amber">{{ __('Failed') }}: {{ $this->lastRun['failed'] }}</span>
                        @endif
                    </div>

                    @if($this->lastRun['failures'])
                        <div class="rw-mb-failure-list">
                            @foreach($this->lastRun['failures'] as $failure)
                                <div><span class="font-bold">{{ __('Room') }} {{ $failure['room_number'] }}</span>: {{ $failure['message'] }}</div>
                            @endforeach
                        </div>
                    @endif

                    <div>
                        <x-filament::button tag="a" size="sm" href="{{ $this->viewInvoicesUrl() }}" icon="heroicon-o-document-text">
                            {{ __('View invoices') }}
                        </x-filament::button>
                    </div>
                </div>
            @endif

            <div class="rw-mb-toolbar">
                <div>
                    <p class="rw-mb-eyebrow">{{ __('Meter readings') }}</p>
                    <h2>{{ __('Enter this month’s readings') }}</h2>
                    <p class="rw-mb-toolbar__desc">
                        {{ __('Type the new reading for each room — everything else is worked out automatically. Untick a room to leave it out.') }}
                        @if($this->visibleProperties()->count() > 1)
                            <button type="button" class="rw-mb-link-btn" wire:click="changeProperty">{{ __('Change property') }}</button>
                        @endif
                    </p>
                </div>
                <div class="rw-mb-toolbar__stats" aria-label="{{ __('Billing progress') }}">
                    <div><strong>{{ count($this->rooms) }}</strong><span>{{ __('Rooms') }}</span></div>
                    <div><strong>{{ $this->includedRoomCount() }}</strong><span>{{ __('Selected') }}</span></div>
                    <div><strong>{{ $this->readyRoomCount() }}</strong><span>{{ __('Ready') }}</span></div>
                </div>
            </div>

            <div class="rw-mb-stat-grid rw-mb-stat-grid--3">
                <div class="rw-mb-stat-tile">
                    <label for="billing-date-input" class="rw-mb-stat-tile__label">{{ __('Billing date') }}</label>
                    <input
                        id="billing-date-input"
                        type="date"
                        wire:model.live="issueDate"
                        class="rw-mb-date-input"
                    >
                </div>

                <div class="rw-mb-stat-tile">
                    <span class="rw-mb-stat-tile__label">{{ __('Rooms due') }}</span>
                    <p class="rw-mb-stat-tile__value">{{ $this->dueRoomCount() }}</p>
                </div>

                <div class="rw-mb-stat-tile">
                    <span class="rw-mb-stat-tile__label">{{ __('Active utilities') }}</span>
                    <div class="rw-mb-tag-row">
                        @forelse($utilities as $utility)
                            <span class="rw-mb-tag rw-mb-tag--emerald">{{ __($utility->name) }}</span>
                        @empty
                            <span class="rw-mb-muted-text">{{ __('No active utilities') }}</span>
                        @endforelse
                    </div>
                </div>
            </div>

            @if(count($this->rooms) === 0)
                <div class="rw-mb-notice w-full">
                    <p class="rw-mb-notice__title">{{ __('No rooms to bill.') }}</p>
                    <p class="rw-mb-notice__body">{{ __('This property has no active tenancies.') }}</p>
                </div>
            @else
                <div class="rw-mb-hint">
                    <x-heroicon-o-information-circle class="h-5 w-5 shrink-0" />
                    <span>{{ __('Press Enter to jump to the next reading. Charges update as you type.') }}</span>
                </div>

                <div
                    x-data="{
                        focusNextFromEnter(event) {
                            if (event.target.tagName !== 'INPUT' || event.target.type !== 'number') return;
                            event.preventDefault();
                            const focusable = Array.from(this.$el.querySelectorAll('input[type=number]:not(:disabled)'));
                            const idx = focusable.indexOf(event.target);
                            if (idx > -1 && idx < focusable.length - 1) {
                                const next = focusable[idx + 1];
                                next.focus();
                                if (typeof next.select === 'function') next.select();
                            }
                        }
                    }"
                    x-on:keydown.enter.capture="focusNextFromEnter($event)"
                >
                    <div class="rw-mb-table-wrap">
                        <table class="rw-mb-table">
                            <thead>
                                <tr>
                                    <th class="rw-mb-table__select">
                                        <input
                                            type="checkbox"
                                            wire:click="toggleSelectAll"
                                            @checked($this->selectableRoomIndexes() !== [] && $this->includedRoomCount() === count($this->selectableRoomIndexes()))
                                            aria-label="{{ __('Select all rooms') }}"
                                        >
                                    </th>
                                    <th>{{ __('Room') }}</th>
                                    @foreach($utilities as $utility)
                                        <th class="rw-mb-table__utility-head">
                                            <span>{{ __($utility->name) }}</span>
                                            <small>{{ __('Previous → New reading') }}</small>
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($this->rooms as $index => $room)
                                    @php
                                        $status = $this->roomStatus($index);
                                        $locked = ($room['duplicate'] ?? false) || ($room['invalid_period'] ?? false);
                                    @endphp
                                    <tr
                                        wire:key="billing-room-{{ $room['rental_id'] }}"
                                        @class([
                                            'rw-mb-table__row--skipped' => $locked || ! ($room['include'] ?? false),
                                            'rw-mb-table__row--selected' => ! $locked && ($room['include'] ?? false),
                                        ])
                                    >
                                        <td class="rw-mb-table__select">
                                            <input
                                                type="checkbox"
                                                wire:model.live="rooms.{{ $index }}.include"
                                                @disabled($locked)
                                                aria-label="{{ __('Include room :room', ['room' => $room['room_number']]) }}"
                                            >
                                        </td>
                                        <td class="rw-mb-table__room">
                                            <span>{{ $room['room_number'] }}</span>
                                            @if(in_array($status['key'], ['billed', 'nothing'], true))
                                                <div class="mt-1">
                                                    <span class="rw-mb-badge rw-mb-badge--{{ $status['color'] }}">{{ $status['label'] }}</span>
                                                </div>
                                            @endif
                                        </td>

                                        @foreach($room['utilities'] as $utilityIndex => $utility)
                                            @php $preview = $this->utilityPreview($index, $utilityIndex); @endphp
                                            <td
                                                class="rw-mb-table__utility"
                                                wire:key="billing-room-{{ $room['rental_id'] }}-utility-{{ $utility['property_utility_id'] }}"
                                            >
                                                @if(! $preview['billable'])
                                                    <div class="rw-mb-table__empty">{{ $utility['state_label'] }} — {{ __('no charge') }}</div>
                                                @elseif(! $utility['requires_reading'])
                                                    <div class="rw-mb-fixed-charge">
                                                        <span>{{ __('Fixed') }}</span>
                                                        <strong>{{ \App\Support\Money::format($preview['charge'], $utility['currency']) }}</strong>
                                                    </div>
                                                @else
                                                    <div class="rw-mb-meter">
                                                        {{-- Previous reading is the leading addon of the very control the new
                                                             reading goes into, so "old → new" reads as one gesture and the
                                                             focus ring wraps both halves. --}}
                                                        <div class="rw-mb-meter__io">
                                                            <span class="rw-mb-meter__prev">
                                                                <small>{{ __('Previous') }}</small>
                                                                <b>{{ $this->formatQuantity((float) $utility['old_reading']) }}</b>
                                                            </span>
                                                            <label class="rw-mb-meter__field">
                                                                <span class="sr-only">{{ __('New reading for :utility in room :room', ['utility' => $utility['utility_name'], 'room' => $room['room_number']]) }}</span>
                                                                <input
                                                                    type="number"
                                                                    inputmode="decimal"
                                                                    step="any"
                                                                    min="0"
                                                                    placeholder="{{ __('New reading') }}"
                                                                    wire:model.live.debounce.400ms="rooms.{{ $index }}.utilities.{{ $utilityIndex }}.new_reading"
                                                                    @disabled($locked || ! ($room['include'] ?? false))
                                                                >
                                                            </label>
                                                        </div>
                                                        <div @class(['rw-mb-meter__out', 'is-empty' => $preview['missing']])>
                                                            @if($preview['missing'])
                                                                <span>—</span>
                                                            @else
                                                                <span>{{ $this->formatQuantity((float) $preview['amount_used']) }} {{ $utility['unit_of_measure'] }}</span>
                                                                <strong>{{ \App\Support\Money::format($preview['charge'], $utility['currency']) }}</strong>
                                                            @endif
                                                        </div>
                                                        @if($preview['warning'])
                                                            <p class="rw-mb-meter__note">{{ $preview['warning'] }}</p>
                                                        @endif
                                                        @if($utility['state'] !== 'normal')
                                                            <span class="rw-mb-tag rw-mb-tag--amber rw-mb-tag--sm">{{ $utility['state_label'] }}</span>
                                                        @endif
                                                    </div>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rw-mb-sticky-bar">
                    <div class="rw-mb-sticky-bar__summary">
                        <p>{{ __(':count room(s) ready to bill.', ['count' => $this->readyRoomCount()]) }}</p>
                        @if($this->readyRoomCount() > 0)
                            <p class="rw-mb-sticky-bar__total">
                                <span>{{ __('Total') }}</span>
                                <strong>{{ $this->grandTotalDisplay() }}</strong>
                            </p>
                        @endif
                    </div>

                    @if($access !== \App\Enums\SubscriptionAccess::ReadOnly)
                        <x-filament::button
                            type="button"
                            icon="heroicon-o-check-circle"
                            class="w-full sm:w-auto"
                            x-on:click="confirmOpen = true"
                            :disabled="$this->readyRoomCount() === 0"
                        >
                            {{ __('Create invoices') }}
                        </x-filament::button>
                    @endif
                </div>

                {{-- Confirmation modal --}}
                <div
                    x-show="confirmOpen"
                    x-cloak
                    x-on:keydown.escape.window="confirmOpen = false"
                    style="display: none;"
                    class="rw-mb-modal-backdrop"
                >
                    <div class="rw-mb-modal">
                        <div>
                            <h3 class="rw-mb-card__title">{{ __('Confirm invoice creation') }}</h3>
                            <p class="rw-mb-card__subtitle mt-1">{{ __('You are about to generate monthly invoices for this property.') }}</p>
                        </div>

                        <div class="rw-mb-panel space-y-2.5 text-sm">
                            <div class="rw-mb-kv">
                                <span>{{ __('Invoices to create') }}:</span>
                                <strong>{{ $this->readyRoomCount() }}</strong>
                            </div>
                            <div class="rw-mb-kv">
                                <span>{{ __('Estimated total') }}:</span>
                                <strong>{{ $this->grandTotalDisplay() }}</strong>
                            </div>
                            @if($this->includedRoomCount() > $this->readyRoomCount())
                                <div class="rw-mb-kv text-amber-600 dark:text-amber-400">
                                    <span>{{ __('Selected rooms missing readings (will be skipped)') }}:</span>
                                    <strong>{{ $this->includedRoomCount() - $this->readyRoomCount() }}</strong>
                                </div>
                            @endif
                            @php $rateInfo = $this->exchangeRateInfo(); @endphp
                            <div class="rw-mb-rate-box">
                                <div class="rw-mb-rate-box__title">{{ __('Exchange rate for this invoice run') }}:</div>
                                <div class="rw-mb-kv rw-mb-kv--sm">
                                    <span>1 USD = {{ number_format($rateInfo['rate'], 0) }} KHR</span>
                                    <span>{{ __('Source') }}: {{ $rateInfo['source'] }} ({{ $rateInfo['date'] }})</span>
                                </div>
                                <div class="rw-mb-rate-box__note">{{ __('This rate will be saved on each invoice.') }}</div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2.5 sm:flex-row sm:justify-end">
                            <x-filament::button type="button" color="gray" x-on:click="confirmOpen = false" class="w-full sm:w-auto">
                                {{ __('Cancel') }}
                            </x-filament::button>
                            <x-filament::button
                                type="button"
                                x-on:click="confirmOpen = false"
                                wire:click="createInvoices"
                                wire:loading.attr="disabled"
                                class="w-full sm:w-auto"
                            >
                                {{ __('Create invoices') }}
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
