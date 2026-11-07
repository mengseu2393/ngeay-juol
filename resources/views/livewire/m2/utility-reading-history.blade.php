@php
    use App\Services\MeterUtilities;
    use App\Support\UtilityCatalog;
    use Illuminate\Support\Carbon;

    $fmt = fn ($v) => MeterUtilities::trimReading($v);
    $uom = $utility?->unit_of_measure;
    $icon = $utility?->name === 'Water' ? 'water' : 'bolt';
    $reset = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16 4v4h-4M4 20v-4h4M5 9a7 7 0 0 1 12-3l2 2M19 15a7 7 0 0 1-12 3l-2-2"/></svg>';
@endphp

<div id="m2-reading-history" class="m2a">
    <x-m2.header :title="__('Reading history')" :subtitle="__('Utility Rates')" :back="route('m2.utilities')" />

    <main class="m2-wrap m2a-main">
        @if ($rooms->isEmpty())
            <div class="m2a-empty">{{ __('No rooms are set up for this property yet.') }}</div>
        @else
            <div class="m2a-two" style="margin-top:4px">
                <div>
                    <label for="m2-history-room" class="m2-label">{{ __('Room') }}</label>
                    <select id="m2-history-room" class="m2-input" wire:change="selectUnit($event.target.value)">
                        @foreach ($rooms as $room)
                            <option value="{{ $room->id }}" @selected($room->id === $unitId)>{{ $room->room_number }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="m2-history-utility" class="m2-label">{{ __('Utility') }}</label>
                    <select id="m2-history-utility" class="m2-input" wire:change="selectUtility($event.target.value)" @disabled($utilities->isEmpty())>
                        @forelse ($utilities as $u)
                            <option value="{{ $u->id }}" @selected($u->id === $utilityId)>{{ UtilityCatalog::label($u->name) }}</option>
                        @empty
                            <option value="">—</option>
                        @endforelse
                    </select>
                </div>
            </div>

            @if ($utilities->isEmpty())
                <div class="m2a-empty">{{ __('No metered utilities are set up for this property yet.') }}</div>

            {{-- ── Correct one reading: enter, then review exactly what changes, then confirm ── --}}
            @elseif ($editingId)
                @php $editing = $readings->firstWhere('id', $editingId); @endphp
                @if (! $confirming)
                    <form wire:submit="review" class="m2a-card" id="m2-history-edit" novalidate>
                        <div>
                            <b style="display:block">{{ __('Correct reading') }}</b>
                            @if ($editing)<p class="m2a-hint" style="margin:2px 0 0">{{ __('Previous: :value', ['value' => $fmt($editing->old_reading).($uom ? ' '.$uom : '')]) }}</p>@endif
                        </div>
                        <div>
                            <label class="m2-label" for="m2-history-new">{{ __('New reading') }}@if ($uom) ({{ $uom }})@endif</label>
                            <input id="m2-history-new" type="text" inputmode="decimal" wire:model="newReading" class="m2-input is-big" autocomplete="off" enterkeyhint="done">
                            @error('newReading') <p class="m2-err">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="m2-label" for="m2-history-date">{{ __('Reading date') }}</label>
                            <input id="m2-history-date" type="date" max="{{ now()->toDateString() }}" wire:model="date" class="m2-input">
                            @error('date') <p class="m2-err">{{ $message }}</p> @enderror
                        </div>
                        <div class="m2a-two">
                            <button type="button" wire:click="cancelEdit" class="m2-btn is-ghost" id="m2-history-cancel">{{ __('Cancel') }}</button>
                            <button type="submit" wire:loading.attr="disabled" wire:target="review" class="m2-btn" id="m2-history-review">{{ __('Review change') }}</button>
                        </div>
                    </form>
                @else
                    <div class="m2a-card" id="m2-history-confirm-box">
                        <div>
                            <b style="display:block">{{ __('Confirm change') }}</b>
                            <p class="m2a-hint" style="margin:2px 0 0">{{ __('Please check what will change before saving.') }}</p>
                        </div>
                        <div class="m2a-list">
                            @foreach ($changes as $c)
                                <div class="m2a-row is-static" style="align-items:flex-start">
                                    <span class="m2a-t">
                                        <b>{{ Carbon::parse($c['after']['date'])->translatedFormat('d M Y') }}</b>
                                        <span>{{ $fmt($c['before']['old_reading']) }} → {{ $fmt($c['before']['new_reading']) }} &nbsp;⇒&nbsp; {{ $fmt($c['after']['old_reading']) }} → {{ $fmt($c['after']['new_reading']) }}</span>
                                    </span>
                                    <span class="m2a-chip {{ $c['is_target'] ? 'is-warn' : 'is-idle' }}">{{ $c['is_target'] ? $fmt($c['after']['amount_used']).($uom ? ' '.$uom : '') : __('Recalculated') }}</span>
                                </div>
                            @endforeach
                        </div>
                        <label class="m2a-check" for="m2-history-confirm">
                            <input type="checkbox" wire:model="confirmed" id="m2-history-confirm">
                            <span>{{ __('I understand this changes the recorded readings and any draft invoice using them.') }}</span>
                        </label>
                        @error('confirmed') <p class="m2-err">{{ $message }}</p> @enderror
                        @error('newReading') <p class="m2-err">{{ $message }}</p> @enderror
                        <div class="m2a-two">
                            <button type="button" wire:click="backToEdit" class="m2-btn is-ghost" id="m2-history-back">{{ __('Back') }}</button>
                            <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="m2-btn is-danger" id="m2-history-save">{{ __('Confirm change') }}</button>
                        </div>
                    </div>
                @endif

            {{-- ── The list ── --}}
            @elseif ($readings->isEmpty())
                <div class="m2a-empty" id="m2-history-empty">{{ __('No readings recorded yet.') }}</div>
            @else
                <div class="m2a-list" id="m2-history-list" wire:loading.class="m2a-busy" wire:target="selectUnit,selectUtility">
                    @foreach ($readings as $r)
                        @php
                            $invoice = $invoices->get($r->id);
                            $editable = $service->isEditable($r) && auth()->user()?->can('update', $r);
                            $sub = $r->is_reset ? __('Meter reset') : $fmt($r->old_reading).' → '.$fmt($r->new_reading);
                            if ($invoice) { $sub .= ' · '.__('Billed'); }
                            if ($r->is_waived) { $sub .= ' · '.__('Waived'); }
                        @endphp
                        @if ($editable)
                            <button type="button" wire:click="edit({{ $r->id }})" class="m2a-row" data-reading-id="{{ $r->id }}" id="m2-history-edit-{{ $r->id }}" wire:key="m2-history-{{ $r->id }}">
                        @else
                            <div class="m2a-row is-static" data-reading-id="{{ $r->id }}" wire:key="m2-history-{{ $r->id }}">
                        @endif
                            @if ($r->is_reset)
                                <span class="m2-ico" aria-hidden="true">{!! $reset !!}</span>
                            @else
                                <x-m2.icon :name="$icon" />
                            @endif
                            <span class="m2a-t">
                                <b>{{ $r->reading_date->translatedFormat('d M Y') }}</b>
                                <span class="m2-trunc">{{ $sub }}</span>
                            </span>
                            @if ($r->is_reset)
                                <span class="m2a-chip is-idle">{{ __('Start :value', ['value' => $fmt($r->new_reading)]) }}</span>
                            @else
                                <span class="m2a-chip is-warn">{{ $fmt($r->amount_used) }}{{ $uom ? ' '.$uom : '' }}</span>
                            @endif
                        @if ($editable)
                            </button>
                        @else
                            </div>
                        @endif
                    @endforeach
                </div>

                @if ($hasMore)
                    <button type="button" wire:click="loadMore" class="m2-btn is-ghost" id="m2-history-more">
                        <span wire:loading.remove wire:target="loadMore">{{ __('Load more') }}</span>
                        <span wire:loading wire:target="loadMore">{{ __('Loading…') }}</span>
                    </button>
                @endif
            @endif
        @endif
    </main>
</div>
