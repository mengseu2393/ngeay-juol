@php
    use App\Support\Money;
    use App\Support\UtilityCatalog;

    $tabs = ['revenue' => __('Income'), 'due' => __('Owed'), 'occupancy' => __('Rooms'), 'history' => __('History')];
    $presets = ['month' => __('This month'), 'last' => __('Last month'), 'quarter' => __('3 months'), 'year' => __('This year')];
    $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
    $chev = '<svg class="m2-chev" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>';
    $ranged = in_array($tab, ['revenue', 'history'], true);
@endphp

<div class="m2a">
    <x-m2.header :title="__('Dashboard')" :subtitle="$property?->name" :back="route('m2.home')" />

    <main class="m2-wrap m2a-main">
        <div class="m2-seg" role="group" aria-label="{{ __('Show') }}" id="m2-dash-tabs">
            @foreach ($tabs as $value => $label)
                <button type="button" wire:click="setTab('{{ $value }}')" aria-pressed="{{ $tab === $value ? 'true' : 'false' }}" id="m2-dash-tab-{{ $value }}">{{ $label }}</button>
            @endforeach
        </div>

        @if (! $propertyId)
            <div class="m2a-empty">
                <p><b>{{ __('Choose a property first') }}</b></p>
                <a href="{{ route('m2.home', ['view' => 'properties']) }}" wire:navigate class="m2-btn" style="margin-top:12px">{{ __('Properties') }}</a>
            </div>
        @else
            @if ($ranged)
                <div id="m2-dash-range">
                    <div class="m2-pills">
                        @foreach ($presets as $value => $label)
                            <button type="button" wire:click="setRange('{{ $value }}')" class="m2-pill" aria-pressed="{{ $preset === $value ? 'true' : 'false' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                    <div class="m2-range">
                        <div>
                            <label for="m2-dash-from" class="m2-label">{{ __('From') }}</label>
                            <input id="m2-dash-from" type="date" value="{{ $from }}" wire:model.live="from" class="m2-input">
                        </div>
                        <div>
                            <label for="m2-dash-to" class="m2-label">{{ __('To') }}</label>
                            <input id="m2-dash-to" type="date" value="{{ $to }}" wire:model.live="to" class="m2-input">
                        </div>
                    </div>
                </div>
            @endif

            {{-- ── Income ── --}}
            @if ($tab === 'revenue')
                <div class="m2a-sum" id="m2-revenue-total">
                    <div>
                        <small>{{ __('Income') }} · {{ __(':count payment(s)', ['count' => $data['count']]) }}</small>
                        <b class="m2-num">{{ Money::format($data['total'], $currency) }}</b>
                    </div>
                    @if ($data['trend'] !== null)
                        <span class="m2a-chip {{ $data['trend'] >= 0 ? 'is-ok' : 'is-late' }}" id="m2-revenue-trend">{{ ($data['trend'] >= 0 ? '+' : '−').abs($data['trend']) }}%</span>
                    @endif
                </div>

                @if ($data['payments']->isEmpty())
                    <div class="m2a-empty" id="m2-revenue-empty">{{ __('No payments in this period.') }}</div>
                @else
                    <div class="m2a-h">{{ __('Payments') }}</div>
                    <div class="m2a-list" id="m2-revenue-rows">
                        @foreach ($data['payments'] as $payment)
                            <div class="m2a-row is-static" wire:key="m2-pay-{{ $payment['id'] }}">
                                <span class="m2a-dot is-ok"></span>
                                <span class="m2a-t">
                                    <b class="m2-trunc" style="display:block">{{ $payment['tenant'] }} · {{ $payment['room'] }}</b>
                                    <span>{{ $payment['date'] }}@if ($payment['method']) · {{ $payment['method'] }}@endif</span>
                                </span>
                                <span class="m2a-r"><b class="m2-num">{{ Money::format($payment['amount'], $currency) }}</b></span>
                            </div>
                        @endforeach
                    </div>
                    @if ($data['count'] > $data['payments']->count())
                        <p class="m2-muted m2-sm" style="padding:0 4px">{{ __('Showing the latest :shown of :count.', ['shown' => $data['payments']->count(), 'count' => $data['count']]) }}</p>
                    @endif
                @endif

            {{-- ── Due ── --}}
            @elseif ($tab === 'due')
                <div class="m2a-sum {{ $data['overdueRooms'] > 0 ? 'is-bad' : '' }}" id="m2-due-total"><div><small>{{ __('Total due') }} · {{ __(':count room(s) late', ['count' => $data['overdueRooms']]) }}</small>
                    <b class="m2-num">{{ Money::format($data['total'], $currency) }}</b></div></div>

                @if ($data['rows']->isEmpty())
                    <div class="m2a-empty" id="m2-due-empty">{{ __('Nobody owes anything.') }}</div>
                @else
                    <div class="m2a-list" id="m2-due-rows">
                        @foreach ($data['rows'] as $row)
                            @php $late = $row['daysLate'] > 0; @endphp
                            <a @if ($row['unitId']) href="{{ route('m2.room', $row['unitId']) }}" wire:navigate @endif class="m2a-row" wire:key="m2-due-{{ $row['unitId'] ?? 'x' }}">
                                <x-m2.icon name="room" :class="$late ? 'is-late' : ''" />
                                <span class="m2a-t">
                                    <span class="m2-title m2-trunc" style="display:block">{{ $row['room'] }}<span class="m2-sub"> · {{ $row['tenant'] ?: '—' }}</span></span>
                                    <span class="m2-sm {{ $late ? 'm2-money is-bad' : 'm2-muted' }}" style="font-size:13px">
                                        {{ $late ? __(':days days late', ['days' => $row['daysLate']]) : __('Due :date', ['date' => $row['oldest']?->translatedFormat('d M')]) }}
                                        @if ($row['invoices'] > 1) · {{ __(':count invoices', ['count' => $row['invoices']]) }} @endif
                                    </span>
                                </span>
                                <span class="m2-money is-bad">{{ Money::format($row['balance'], $currency) }}</span>
                                {!! $chev !!}
                            </a>
                        @endforeach
                    </div>
                @endif

            {{-- ── Occupancy ── --}}
            @elseif ($tab === 'occupancy')
                <div class="m2a-card" id="m2-occupancy-summary">
                    <div class="m2-row">
                        <span class="m2-grow m2-muted">{{ __('Occupancy') }}</span>
                        <b style="font-size:26px" class="m2-num">{{ $data['percent'] }}%</b>
                    </div>
                    @if ($data['total'] > 0)
                        <div class="m2-split" style="margin-top:10px" aria-hidden="true">
                            <i class="is-occ" style="width: {{ $data['occupied'] / $data['total'] * 100 }}%"></i>
                            <i class="is-vac" style="width: {{ $data['available'] / $data['total'] * 100 }}%"></i>
                            <i class="is-oth" style="width: {{ $data['other'] / $data['total'] * 100 }}%"></i>
                        </div>
                    @endif
                    <div class="m2-legend">
                        <span><i style="background:var(--m2-brand)"></i>{{ __('Occupied') }} {{ $data['occupied'] }}</span>
                        <span><i style="background:var(--m2-info)"></i>{{ __('Vacant') }} {{ $data['available'] }}</span>
                        @if ($data['other'] > 0)<span><i style="background:var(--m2-warn)"></i>{{ __('Maintenance') }} {{ $data['other'] }}</span>@endif
                    </div>
                </div>

                @if ($data['rooms']->isEmpty())
                    <div class="m2a-empty">{{ __('No rooms found.') }}</div>
                @else
                    <div class="m2a-list" id="m2-occupancy-rows">
                        @foreach ($data['rooms'] as $room)
                            <a href="{{ route('m2.room', $room) }}" wire:navigate class="m2a-row" wire:key="m2-occ-room-{{ $room->id }}">
                                <x-m2.icon name="room" :class="$room->activeRental ? '' : 'is-vacant'" />
                                <span class="m2a-t">
                                    <span class="m2-title m2-trunc" style="display:block">{{ $room->room_number }}<span class="m2-sub"> · {{ $room->activeRental?->occupant_name ?: $room->status->getLabel() }}</span></span>
                                    @if ($room->activeRental?->start_date)<span class="m2-sm m2-muted">{{ __('Since :date', ['date' => $room->activeRental->start_date->translatedFormat('d M Y')]) }}</span>@endif
                                </span>
                                <span class="m2a-chip is-{{ match ($room->status->getColor()) { 'success' => 'ok', 'warning' => 'warn', default => 'info' } }}">{{ $room->status->getLabel() }}</span>
                                {!! $chev !!}
                            </a>
                        @endforeach
                    </div>
                @endif

            {{-- ── History by room ── --}}
            @else
                <div class="m2-pills" id="m2-history-kinds">
                    @foreach ($meters as $meter)
                        <button type="button" wire:click="$set('kind', '{{ $meter->id }}')" class="m2-pill" aria-pressed="{{ $historyKind === (string) $meter->id ? 'true' : 'false' }}" wire:key="m2-kind-{{ $meter->id }}">{{ UtilityCatalog::label($meter->name) }}</button>
                    @endforeach
                    <button type="button" wire:click="$set('kind', 'bill')" class="m2-pill" aria-pressed="{{ $historyKind === 'bill' ? 'true' : 'false' }}">{{ __('Bill') }}</button>
                </div>

                <div>
                    <label for="m2-history-room" class="m2-label">{{ __('Room') }}</label>
                    <select id="m2-history-room" wire:model.live="room" class="m2-input">
                        <option value="">{{ __('All rooms') }}</option>
                        @foreach ($rooms as $roomOption)
                            <option value="{{ $roomOption->id }}">{{ $roomOption->room_number }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="m2a-sum" id="m2-history-total"><div>@if ($data['kind'] === 'bill')
                        <small>{{ __('Billed') }} · {{ __(':count invoices', ['count' => $data['count']]) }}</small>
                        <b class="m2-num">{{ Money::format($data['total'], $currency) }}</b>
                    @else
                        <small>{{ __('Used') }} · {{ __(':count reading(s)', ['count' => $data['count']]) }}</small>
                        <b class="m2-num">{{ $trim($data['total']) }} {{ $data['utility']?->unit_of_measure }}</b>
                    @endif</div></div>

                @if ($data['rows']->isEmpty())
                    <div class="m2a-empty" id="m2-history-empty">{{ __('Nothing in this period.') }}</div>
                @else
                    <div class="m2a-list" id="m2-history-rows">
                        @foreach ($data['rows'] as $row)
                            @if ($data['kind'] === 'bill')
                                <a href="{{ route('m2.slip', $row) }}" wire:navigate class="m2a-row" wire:key="m2-hist-bill-{{ $row->id }}">
                                    <x-m2.icon name="bill" />
                                    <span class="m2a-t">
                                        <b class="m2-trunc" style="display:block">{{ $row->rental?->unit?->room_number ?? '—' }}<span class="m2-sub"> · {{ $row->invoice_number }}</span></b>
                                        <span class="m2-sm m2-muted">{{ \App\Models\Invoice::displayDate($row->issue_date) }}</span>
                                    </span>
                                    <span class="m2-right">
                                        <span class="m2-money" style="display:block">{{ Money::format($row->amount_due, $currency) }}</span>
                                        <span class="m2a-chip is-{{ match ($row->payment_status->getColor()) { 'success' => 'ok', 'danger' => 'bad', 'warning' => 'warn', default => 'info' } }}">{{ $row->payment_status->getLabel() }}</span>
                                    </span>
                                </a>
                            @else
                                <div class="m2a-row is-static" wire:key="m2-hist-use-{{ $row->id }}">
                                    <x-m2.icon :name="$data['utility']?->name === 'Water' ? 'water' : 'bolt'" :class="$data['utility']?->name === 'Water' ? 'is-w' : 'is-e'" />
                                    <span class="m2a-t">
                                        <b>{{ $row->unit?->room_number ?? '—' }}<span class="m2-sub"> · {{ $row->reading_date->translatedFormat('d M Y') }}</span></b>
                                        <span class="m2-sm m2-muted" style="display:block">{{ $row->is_reset ? __('Meter reset') : $trim($row->old_reading).' → '.$trim($row->new_reading) }}</span>
                                    </span>
                                    <span class="m2a-chip is-{{ $data['utility']?->name === 'Water' ? 'w' : 'e' }}">{{ $row->is_reset ? '0' : $trim($row->amount_used) }} {{ $data['utility']?->unit_of_measure }}</span>
                                </div>
                            @endif
                        @endforeach
                    </div>
                    @if ($data['count'] > $data['rows']->count())
                        <p class="m2-muted m2-sm" style="padding:0 4px">{{ __('Showing the latest :shown of :count.', ['shown' => $data['rows']->count(), 'count' => $data['count']]) }}</p>
                    @endif
                @endif
            @endif
        @endif
    </main>
</div>
