@php
    use App\Services\ChargeRuleResolver;
    use App\Support\Money;

    $currency = Money::forPropertyId($propertyId);
@endphp

<div id="m2-create-invoice" class="m2a">
    <x-m2.header :title="__('Room').' '.$unit->room_number" :subtitle="__('Create invoice')" :back="route('m2.room', $unit)" />

    <main class="m2-wrap m2a-main">
        @if ($notice)
            <div class="m2-notice {{ $noticeLevel === 'success' ? 'is-ok' : 'is-warn' }}" role="status" id="m2-invoice-notice">{{ $notice }}</div>
        @endif

        @if ($step === 'vacant')
            <div class="m2a-empty" id="m2-invoice-vacant">{{ __('This room has no tenant, so there is nothing to bill.') }}</div>

        @elseif ($step === 'blocked')
            <div class="m2a-empty" id="m2-invoice-blocked">{{ __('Monthly billing is disabled for this property.') }}</div>

        @elseif ($step === 'result')
            {{-- data-m2-feedback: appearing after the create call, it plays the big check (m2-feedback.js). --}}
            <div class="m2a-card" id="m2-invoice-result" style="text-align:center"
                 @if ($resultSummary['created'] > 0) data-m2-feedback="big" data-m2-feedback-message="{{ __('Created :count invoice(s).', ['count' => $resultSummary['created']]) }}"
                 @elseif ($resultSummary['failures'] === []) data-m2-feedback="warning" data-m2-feedback-message="{{ __('No invoice was created.') }}" @endif>
                @if ($resultSummary['created'] > 0)
                    <x-m2.icon name="check" style="margin:0 auto;width:48px;height:48px" />
                    <b>{{ __('Created :count invoice(s).', ['count' => $resultSummary['created']]) }}</b>
                @else
                    <b>{{ __('No invoice was created.') }}</b>
                @endif
                @foreach ($resultSummary['failures'] as $failure)
                    <p class="m2-err" style="margin:0">{{ $failure['message'] }}</p>
                @endforeach
            </div>
            @if ($createdId)
                <a href="{{ route('m2.slip', $createdId) }}" wire:navigate class="m2-btn" id="m2-invoice-open-slip">{{ __('Open slip') }}</a>
            @endif
            <a href="{{ route('m2.room', $unit) }}" wire:navigate class="m2-btn is-ghost">{{ __('Back to room') }}</a>

        @elseif ($room)
            @php
                $readingUtilities = collect($room['utilities'])->filter(fn ($u) => $u['requires_reading'] && ! $this->utilityIsNotBilled($u));
            @endphp

            <p class="m2-muted m2-sm" style="margin:0 4px" id="m2-invoice-tenant">
                {{ $room['occupant_name'] }}@if ($room['is_first_invoice']) · {{ __('First invoice') }}@endif
            </p>

            <div class="m2a-card" id="m2-invoice-period">
                <div>
                    <label for="m2-invoice-date" class="m2-label">{{ __('Billing date') }}</label>
                    <input id="m2-invoice-date" type="date" value="{{ $issueDate }}" wire:model.live="issueDate" class="m2-input">
                </div>
                <div>
                    <span class="m2-label">{{ __('Billing period') }}</span>
                    <div class="m2-input is-static" id="m2-invoice-period-range">{{ $room['period_display'] }}</div>
                </div>
                <div>
                    <span class="m2-label">{{ __('Due date') }}</span>
                    <div class="m2-input is-static" id="m2-invoice-due">{{ $this->dueDateDisplay() }}</div>
                </div>
            </div>

            @if ($readingUtilities->isNotEmpty())
                <div class="m2a-h">{{ __('Meter readings') }}</div>
            @endif

            @foreach ($room['utilities'] as $ui => $utility)
                @continue(! $utility['requires_reading'] || $this->utilityIsNotBilled($utility))
                @php
                    $preview = $this->utilityPreview(0, $ui);
                    $isWater = ($utility['utility_name'] ?? '') === __('Water');
                    $uCurrency = $utility['currency'] ?? 'USD';
                @endphp
                <div class="m2a-card" wire:key="m2-invoice-utility-{{ $ui }}" id="m2-invoice-utility-{{ $ui }}">
                    <div class="m2-row">
                        <x-m2.icon :name="$isWater ? 'water' : 'bolt'" :class="$isWater ? 'is-w' : 'is-e'" />
                        <span class="m2-grow">
                            <b class="m2-trunc" style="display:block">{{ $utility['utility_name'] }}</b>
                            <span class="m2-muted m2-sm">
                                {{ Money::format($utility['rate'], $uCurrency, $uCurrency === 'KHR' ? 0 : (((float) $utility['rate']) < 1 ? 4 : 2)) }}@if ($utility['unit_of_measure']) / {{ $utility['unit_of_measure'] }}@endif
                            </span>
                        </span>
                    </div>
                    <div>
                        <label for="m2-invoice-reading-{{ $ui }}" class="m2-label">
                            {{ __('Reading now') }} · {{ __('Previous') }} {{ \App\Services\MeterUtilities::trimReading($utility['old_reading']) }}
                        </label>
                        <input id="m2-invoice-reading-{{ $ui }}" type="text" inputmode="decimal" wire:model.live.debounce.400ms="rooms.0.utilities.{{ $ui }}.new_reading" class="m2-input is-big" placeholder="0" autocomplete="off">
                        @if ($preview['amount_used'] !== null)
                            <p class="m2-muted m2-sm" style="margin:6px 2px 0">{{ __('Used') }} {{ \App\Services\MeterUtilities::trimReading($preview['amount_used']) }} {{ $utility['unit_of_measure'] }}</p>
                        @endif
                        @if ($preview['warning'])<p class="m2-err">{{ $preview['warning'] }}</p>@endif
                    </div>
                    @if ($preview['is_lower_reading'] ?? false)
                        <div>
                            <label for="m2-invoice-reason-{{ $ui }}" class="m2-label">{{ __('Why is it lower? (e.g. meter replaced)') }}</label>
                            <input id="m2-invoice-reason-{{ $ui }}" type="text" wire:model.live.debounce.400ms="rooms.0.utilities.{{ $ui }}.override_reason" class="m2-input">
                        </div>
                    @endif
                </div>
            @endforeach

            <div class="m2a-h">{{ __('What is included') }}</div>
            <div class="m2a-list" id="m2-invoice-lines">
                <div class="m2a-kv">
                    <span>{{ __('Monthly rent') }}</span>
                    <b class="m2-num" id="m2-invoice-rent">{{ Money::format($room['rent'], $room['rent_currency']) }}</b>
                </div>
                @foreach ($room['utilities'] as $ui => $utility)
                    @continue($this->utilityIsNotBilled($utility))
                    @php
                        $preview = $this->utilityPreview(0, $ui);
                        $state = (string) ($utility['state_override'] ?? 'normal');
                        $uCurrency = $utility['currency'] ?? 'USD';
                    @endphp
                    <div class="m2a-kv" wire:key="m2-invoice-line-{{ $ui }}">
                        <span>
                            {{ $utility['utility_name'] }}
                            @if ($utility['requires_reading'] && $preview['amount_used'] !== null) · {{ \App\Services\MeterUtilities::trimReading($preview['amount_used']) }} {{ $utility['unit_of_measure'] }}@endif
                            @if ($state !== 'normal') · {{ ChargeRuleResolver::stateLabel($state) }}@endif
                        </span>
                        <b class="m2-num">{{ Money::format($preview['charge'], $uCurrency) }}</b>
                    </div>
                @endforeach
                <div class="m2a-kv is-total">
                    <span>{{ __('Total') }}</span>
                    <b class="m2-num" id="m2-invoice-total">{{ $summary['estimated_total_display'] }}</b>
                </div>
            </div>

            @foreach ($summary['warnings'] as $warning)
                @if (! in_array($warning, [__('Lower than previous reading'), __('Unusually high usage')], true))
                    <div class="m2-notice is-warn">{{ $warning }}</div>
                @endif
            @endforeach

            @if ($showCreateConfirmation)
                <div class="m2a-card" id="m2-invoice-confirm">
                    <b>{{ __('Create this invoice?') }}</b>
                    <p class="m2-muted m2-sm" style="margin:0">{{ __('Room :room · :total', ['room' => $room['room_number'], 'total' => $summary['estimated_total_display']]) }}</p>
                    <div class="m2-grid2">
                        <button type="button" wire:click="cancelCreateConfirmation" class="m2-btn is-ghost">{{ __('Cancel') }}</button>
                        <button type="button" wire:click="createInvoices" wire:loading.attr="disabled" wire:target="createInvoices" class="m2-btn" id="m2-invoice-confirm-create">
                            <span wire:loading.remove wire:target="createInvoices">{{ __('Create invoice') }}</span>
                            <span wire:loading wire:target="createInvoices">{{ __('Creating…') }}</span>
                        </button>
                    </div>
                </div>
            @else
                <button type="button" wire:click="openCreateConfirmation" class="m2-btn" id="m2-invoice-create" @disabled($blocked)>{{ __('Create invoice') }}</button>
                @if ($blocked)<p class="m2-muted m2-sm" style="text-align:center;margin:0">{{ __('Enter every reading to continue.') }}</p>@endif
            @endif
        @else
            <div class="m2a-empty" id="m2-invoice-none">{{ $notice ?? __('Nothing to bill for this room.') }}</div>
        @endif
    </main>
</div>
