<div class="rw-monthly-billing-root">
@if(! $this->embedded)
<x-filament-panels::page>
@endif
    @php
        $access = $this->getAccess();
        $selectedProperty = $this->selectedProperty();
        $rooms = $this->rooms;
    @endphp

    <div
        x-data="{
            focusReading(event) {
                const el = this.$refs[event.detail.ref];
                if (el) {
                    el.focus();
                    if (typeof el.select === 'function') {
                        el.select();
                    }
                }
            }
        }"
        x-on:focus-reading.window="focusReading($event)"
        class="rw-mb mx-auto max-w-full space-y-5 px-4 py-2 sm:px-6 lg:px-8"
    >
        {{-- Subscription Warnings --}}
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

        {{-- Page Header --}}
        <header class="rw-mb-hero">
            <div class="rw-mb-hero__top">
                <div class="rw-mb-hero__identity">
                    <div class="rw-mb-hero__icon">
                        <x-heroicon-o-calendar-days class="h-5 w-5" />
                    </div>
                    <div>
                        <h1>{{ __('Monthly billing') }}</h1>
                        <p>{{ $this->sidebarPropertyLabel() }}</p>
                    </div>
                </div>

                <div class="rw-mb-hero__meta">
                    <span class="rw-mb-chip">
                        <x-heroicon-m-calendar class="h-3.5 w-3.5" />
                        {{ $this->issueDateLabel() }}
                    </span>
                    <span class="rw-mb-chip">
                        <x-heroicon-m-home-modern class="h-3.5 w-3.5" />
                        @if($selectedProperty)
                            @if($this->step === 'billing')
                                {{ __(':count rooms selected', ['count' => count($this->rooms)]) }}
                            @else
                                {{ __(':count rooms due', ['count' => $this->dueRoomCount()]) }}
                            @endif
                        @else
                            {{ __('All properties') }}
                        @endif
                    </span>
                </div>
            </div>
            <p class="rw-mb-hero__hint">
                <x-heroicon-m-information-circle class="h-3.5 w-3.5" />
                {{ __('To switch properties, use the selector in the sidebar.') }}
            </p>
        </header>

        {{-- Step Progress --}}
        <nav class="rw-mb-steps" aria-label="Progress">
            @php
                $flowSteps = [
                    'billing' => ['label' => __('Billing'), 'order' => 1, 'icon' => 'heroicon-o-pencil-square'],
                    'result' => ['label' => __('Done'), 'order' => 2, 'icon' => 'heroicon-o-check-badge'],
                ];
                $currentOrder = $flowSteps[$this->step]['order'] ?? 0;
            @endphp
            @foreach($flowSteps as $key => $stepData)
                @php
                    $isCurrent = $this->step === $key;
                    $isCompleted = $currentOrder > $stepData['order'];
                @endphp
                <div class="rw-mb-step {{ $isCurrent ? 'is-current' : ($isCompleted ? 'is-done' : '') }}">
                    <span class="rw-mb-step__dot">
                        @if($isCompleted)
                            <x-heroicon-s-check class="h-3.5 w-3.5" />
                        @else
                            {{ $stepData['order'] }}
                        @endif
                    </span>
                    <span class="rw-mb-step__label">{{ $stepData['label'] }}</span>
                </div>
                @if(! $loop->last)
                    <span class="rw-mb-step__line {{ $isCompleted ? 'is-done' : '' }}"></span>
                @endif
            @endforeach
        </nav>

        {{-- Blocked state --}}
        @if(! $selectedProperty)
            <div class="rw-mb-empty">
                <div class="rw-mb-empty__icon">
                    <x-heroicon-o-home-modern class="h-6 w-6" />
                </div>
                <h3>{{ __('Select a property from the sidebar to start billing.') }}</h3>
                <p>{{ __('Billing runs one property at a time so rooms never mix.') }}</p>
                <span class="rw-mb-chip rw-mb-chip--muted">{{ __('All properties') }}</span>
            </div>

        {{-- Billing workspace: due rooms load automatically; add more below, everything recalculates live --}}
        @elseif($this->step === 'billing')
            @php
                $dueIds = $this->propertyId
                    ? $this->dueRentalIds($this->propertyId, \Carbon\Carbon::parse($this->issueDate ?: now()->toDateString()))
                    : [];
            @endphp
            <div class="rw-mb-workspace space-y-5">
                <div class="rw-mb-toolbar">
                    <div>
                        <p class="rw-mb-eyebrow">{{ __('Meter readings') }}</p>
                        <h2>{{ __('Enter this month’s readings') }}</h2>
                        <p class="rw-mb-toolbar__desc">{{ __('Rooms due for billing load automatically. Add more rooms below if needed — totals recalculate live.') }}</p>
                    </div>
                    <div class="rw-mb-toolbar__stats" aria-label="{{ __('Billing progress') }}">
                        <div><strong>{{ count($this->rooms) }}</strong><span>{{ __('Rooms') }}</span></div>
                        <div><strong>{{ $this->completeRoomCount() }}</strong><span>{{ __('Ready') }}</span></div>
                        <div><strong>{{ $this->roomsWithWarningsCount() }}</strong><span>{{ __('Warnings') }}</span></div>
                        <div><strong>{{ $this->skippedRoomCount() }}</strong><span>{{ __('Skipped') }}</span></div>
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
                            @forelse($this->activeUtilities() as $utility)
                                <span class="rw-mb-tag rw-mb-tag--emerald">{{ __($utility->name) }}</span>
                            @empty
                                <span class="rw-mb-muted-text">{{ __('No active utilities') }}</span>
                            @endforelse
                        </div>
                    </div>
                </div>

                @if(! $this->billingEnabled())
                    <div class="rw-mb-banner rw-mb-banner--amber">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0" />
                        <div>
                            <p class="rw-mb-banner__title">{{ __('Monthly billing is disabled for this property.') }}</p>
                            <p class="rw-mb-banner__body">{{ __('Please enable it in property settings before continuing.') }}</p>
                        </div>
                    </div>
                @else
                    <details class="rw-mb-panel">
                        <summary class="rw-mb-panel__head" style="cursor: pointer;">
                            <span class="rw-mb-eyebrow">{{ __('Add or remove rooms') }}</span>
                            <button
                                type="button"
                                wire:click.stop="toggleSelectAllRentals"
                                class="rw-mb-link-btn"
                                id="btn-toggle-select-all-rentals"
                            >
                                {{ __('Toggle all') }}
                            </button>
                        </summary>
                        <div class="rw-mb-checklist">
                            @forelse($this->activeRentals() as $rental)
                                <label class="rw-mb-checklist__item">
                                    <input
                                        type="checkbox"
                                        value="{{ $rental->id }}"
                                        wire:model.live="selectedRentalIds"
                                    >
                                    <span class="min-w-0">
                                        <span class="rw-mb-checklist__room">{{ $rental->unit?->room_number }}</span>
                                        <span class="rw-mb-checklist__tenant">{{ $rental->occupant_name }}</span>
                                    </span>
                                    @if(in_array($rental->id, $dueIds, true))
                                        <span class="rw-mb-badge rw-mb-badge--emerald">{{ __('Due for billing') }}</span>
                                    @endif
                                </label>
                            @empty
                                <div class="rw-mb-checklist__empty">
                                    {{ __('No active tenancies in this property.') }}
                                </div>
                            @endforelse
                        </div>
                    </details>

                    @if(count($this->rooms) === 0)
                        <div class="rw-mb-notice w-full">
                            <p class="rw-mb-notice__title">{{ __('No rooms selected for billing.') }}</p>
                            <p class="rw-mb-notice__body">{{ __('No rooms are due on this date — change the billing date above, or add a room from "Add or remove rooms".') }}</p>
                        </div>
                    @else
                        <div class="rw-mb-hint">
                            <x-heroicon-o-information-circle class="h-5 w-5 shrink-0" />
                            <span>{{ __('Complete each room below. Press Enter to jump to the next field. Utility charges update as you enter readings.') }}</span>
                        </div>

                        <div class="rw-mb-bulk" @if(count($this->selectedRoomIndexes) === 0) data-empty @endif>
                            <span class="rw-mb-bulk__count">
                                {{ __(':count room(s) selected', ['count' => count($this->selectedRoomIndexes)]) }}
                            </span>

                            <x-filament::button type="button" size="xs" color="gray" wire:click="bulkSkipSelected(true)" :disabled="count($this->selectedRoomIndexes) === 0">
                                {{ __('Skip selected') }}
                            </x-filament::button>

                            <x-filament::button type="button" size="xs" color="gray" wire:click="bulkSkipSelected(false)" :disabled="count($this->selectedRoomIndexes) === 0">
                                {{ __('Restore selected') }}
                            </x-filament::button>

                            <div class="rw-mb-bulk__apply">
                                <select wire:model.live="bulkUtilityId" class="rw-mb-select-sm">
                                    <option value="">{{ __('Choose utility…') }}</option>
                                    @foreach($this->activeUtilities() as $utility)
                                        <option value="{{ $utility->id }}">{{ __($utility->name) }}</option>
                                    @endforeach
                                </select>
                                <select wire:model.live="bulkUtilityState" class="rw-mb-select-sm">
                                    <option value="normal">{{ __('Normal') }}</option>
                                    <option value="free">{{ __('Free') }}</option>
                                    <option value="waived">{{ __('Waived') }}</option>
                                    <option value="not_applicable">{{ __('Not applicable') }}</option>
                                    <option value="skipped_this_cycle">{{ __('Skip this cycle') }}</option>
                                </select>
                                <x-filament::button
                                    type="button"
                                    size="xs"
                                    wire:click="applyBulkUtilityState"
                                    :disabled="count($this->selectedRoomIndexes) === 0 || ! $this->bulkUtilityId"
                                >
                                    {{ __('Apply to selected') }}
                                </x-filament::button>
                            </div>
                        </div>

                        <div
                            x-data="{
                                focusNextFromEnter(event) {
                                    if (! ['INPUT', 'SELECT'].includes(event.target.tagName)) return;
                                    event.preventDefault();
                                    const focusable = Array.from(this.$el.querySelectorAll('input:not(:disabled), select:not(:disabled)'));
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
                            @include('filament.pages.partials.monthly-billing-reading-table')
                        </div>

                        <div class="rw-mb-sticky-bar">
                            <p>{{ __(':count room(s) ready. Create invoices when you\'re done.', ['count' => $this->completeRoomCount()]) }}</p>

                            @if($access !== \App\Enums\SubscriptionAccess::ReadOnly)
                                @if($this->firstBlockingRoomIndex() !== null)
                                    <x-filament::button type="button" icon="heroicon-o-check-circle" wire:click="openCreateConfirmation" class="w-full sm:w-auto text-sm py-2 px-4">
                                        {{ __('Create invoices') }}
                                    </x-filament::button>
                                @else
                                    <x-filament::button type="button" icon="heroicon-o-check-circle" x-data="" x-on:click="$dispatch('open-billing-confirm')" class="w-full sm:w-auto text-sm py-2 px-4">
                                        {{ __('Create invoices') }}
                                    </x-filament::button>
                                @endif
                            @endif
                        </div>
                    @endif
                @endif
            </div>

        {{-- Result --}}
        @else
            <div class="rw-mb-result">
                <div class="rw-mb-result__banner">
                    <div class="rw-mb-result__icon">
                        <x-heroicon-o-check class="h-6 w-6" />
                    </div>
                    <h2>{{ __('Billing complete') }}</h2>
                    <p>{{ __('All processed rooms have been billed for this period.') }}</p>
                </div>

                <div class="space-y-3">
                    <h3 class="rw-mb-eyebrow">{{ __('Billing run summary') }}</h3>
                    <div class="rw-mb-panel space-y-2.5 text-sm">
                        <div class="rw-mb-kv">
                            <span>{{ __('Invoices created') }}:</span>
                            <strong class="text-emerald-600 dark:text-emerald-400">{{ $this->resultSummary['created'] }}</strong>
                        </div>
                        <div class="rw-mb-kv">
                            <span>{{ __('Rooms skipped') }}:</span>
                            <strong>{{ $this->resultSummary['skipped'] }}</strong>
                        </div>
                        <div class="rw-mb-kv">
                            <span>{{ __('Failed runs') }}:</span>
                            <strong class="{{ $this->resultSummary['failed'] > 0 ? 'text-red-600 dark:text-red-400' : '' }}">
                                {{ $this->resultSummary['failed'] }}
                            </strong>
                        </div>
                    </div>
                </div>

                @if($this->resultSummary['failures'])
                    <div class="space-y-2">
                        <h4 class="rw-mb-eyebrow rw-mb-eyebrow--red">{{ __('Details of failures') }}</h4>
                        <div class="rw-mb-failure-list">
                            @foreach($this->resultSummary['failures'] as $failure)
                                <div><span class="font-bold">{{ __('Room') }} {{ $failure['room_number'] }}</span>: {{ $failure['message'] }}</div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex flex-col gap-2.5 pt-2">
                    <x-filament::button tag="a" href="{{ $this->viewInvoicesUrl() }}" icon="heroicon-o-document-text" class="w-full text-xs py-2 px-4">
                        {{ __('View invoices') }}
                    </x-filament::button>

                    @if($this->visibleProperties()->count() > 1)
                        <span class="rw-mb-muted-text text-center my-1">{{ __('To bill another property, use the sidebar selector.') }}</span>
                    @endif

                    <x-filament::button tag="a" color="gray" href="{{ $this->dashboardUrl() }}" class="w-full text-xs py-2 px-4">
                        {{ __('Back to dashboard') }}
                    </x-filament::button>
                </div>
            </div>
        @endif

        {{-- Confirmation Modal --}}
        @if($this->rooms !== [])
            <div
                x-data="{ show: $wire.entangle('showCreateConfirmation') }"
                x-show="show"
                x-cloak
                x-on:open-billing-confirm.window="show = true"
                x-on:keydown.escape.window="show = false"
                style="display: none;"
                class="rw-mb-modal-backdrop">
                <div class="rw-mb-modal">
                    <div>
                        <h3 class="rw-mb-card__title">{{ __('Confirm invoice creation') }}</h3>
                        <p class="rw-mb-card__subtitle mt-1">{{ __('You are about to generate monthly invoices for this property.') }}</p>
                    </div>

                    <div class="rw-mb-panel space-y-2.5 text-sm">
                        <div class="rw-mb-kv">
                            <span>{{ __('Invoices to create') }}:</span>
                            <strong>{{ $this->estimatedInvoiceCount() }}</strong>
                        </div>
                        <div class="rw-mb-kv">
                            <span>{{ __('Rooms to skip') }}:</span>
                            <strong>{{ $this->skippedRoomCount() }}</strong>
                        </div>
                        @if($this->roomsWithWarningsCount() > 0)
                            <div class="rw-mb-kv text-amber-600 dark:text-amber-400">
                                <span>{{ __('Rooms with warnings') }}:</span>
                                <strong>{{ $this->roomsWithWarningsCount() }}</strong>
                            </div>
                        @endif
                        @php $rateInfo = $this->getExchangeRateInfo(); @endphp
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
                        <x-filament::button type="button" color="gray" x-on:click="show = false" class="w-full sm:w-auto">
                            {{ __('Cancel') }}
                        </x-filament::button>
                        <x-filament::button type="button" wire:click="createInvoices" class="w-full sm:w-auto">
                            {{ __('Create invoices') }}
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @endif
    </div>
@if(! $this->embedded)
</x-filament-panels::page>
@endif
</div>
