@php
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    $uom = $utility?->unit_of_measure;
@endphp

<div class="rw-rh" id="reading-history-{{ $unitId }}">
    @if($saved)
        <div class="rw-rh-notice rw-rh-notice-ok" role="status">{{ __('Reading corrected.') }}</div>
    @endif

    @if($utilities->isEmpty())
        <p class="rw-rh-muted">{{ __('No metered utilities are set up for this property yet.') }}</p>
    @else
        {{-- Utility switcher --}}
        <div class="rw-rh-tabs" role="tablist">
            @foreach($utilities as $u)
                <button type="button" role="tab"
                    wire:click="selectUtility({{ $u->id }})"
                    class="rw-rh-tab {{ $u->id === $utilityId ? 'is-active' : '' }}"
                    aria-selected="{{ $u->id === $utilityId ? 'true' : 'false' }}">
                    {{ \App\Support\UtilityCatalog::label($u->name) }}
                </button>
            @endforeach
        </div>

        @if($editingId)
            @php $editing = $readings->firstWhere('id', $editingId); @endphp
            <div class="rw-rh-edit">
                @if(! $confirming)
                    <h4 class="rw-rh-title">{{ __('Correct reading') }}</h4>
                    @if($editing)
                        <p class="rw-rh-muted">{{ __('Previous: :value', ['value' => $fmt($editing->old_reading).($uom ? ' '.$uom : '')]) }}</p>
                    @endif

                    <label class="rw-rh-label" for="rh-new">{{ __('New reading') }}@if($uom) ({{ $uom }})@endif</label>
                    <input id="rh-new" type="number" inputmode="decimal" step="0.001" min="0" wire:model="newReading" class="rw-rh-input">
                    @error('newReading') <p class="rw-rh-error">{{ $message }}</p> @enderror

                    <label class="rw-rh-label" for="rh-date">{{ __('Reading date') }}</label>
                    <input id="rh-date" type="date" max="{{ now()->toDateString() }}" wire:model="date" class="rw-rh-input">
                    @error('date') <p class="rw-rh-error">{{ $message }}</p> @enderror

                    <div class="rw-rh-actions">
                        <button type="button" wire:click="cancelEdit" class="rw-rh-btn">{{ __('Cancel') }}</button>
                        <button type="button" wire:click="review" wire:loading.attr="disabled" class="rw-rh-btn rw-rh-btn-primary">{{ __('Review change') }}</button>
                    </div>
                @else
                    <h4 class="rw-rh-title">{{ __('Confirm change') }}</h4>
                    <p class="rw-rh-muted">{{ __('Please check what will change before saving.') }}</p>

                    @foreach($changes as $c)
                        <div class="rw-rh-diff {{ $c['is_target'] ? 'is-target' : '' }}">
                            <div class="rw-rh-diff-head">
                                {{ \Illuminate\Support\Carbon::parse($c['after']['date'])->format('d M Y') }}
                                @unless($c['is_target'])<span class="rw-rh-chip">{{ __('Recalculated') }}</span>@endunless
                            </div>
                            <dl class="rw-rh-diff-grid">
                                <dt>{{ __('Previous') }}</dt>
                                <dd>{{ $fmt($c['before']['old_reading']) }} → <strong>{{ $fmt($c['after']['old_reading']) }}</strong></dd>
                                <dt>{{ __('Current') }}</dt>
                                <dd>{{ $fmt($c['before']['new_reading']) }} → <strong>{{ $fmt($c['after']['new_reading']) }}</strong></dd>
                                <dt>{{ __('Used') }}</dt>
                                <dd>{{ $fmt($c['before']['amount_used']) }} → <strong>{{ $fmt($c['after']['amount_used']) }}</strong>{{ $uom ? ' '.$uom : '' }}</dd>
                            </dl>
                        </div>
                    @endforeach

                    <label class="rw-rh-check">
                        <input type="checkbox" wire:model="confirmed" id="rh-confirm">
                        <span>{{ __('I understand this changes the recorded readings and any draft invoice using them.') }}</span>
                    </label>
                    @error('confirmed') <p class="rw-rh-error">{{ $message }}</p> @enderror
                    @error('newReading') <p class="rw-rh-error">{{ $message }}</p> @enderror

                    <div class="rw-rh-actions">
                        <button type="button" wire:click="backToEdit" class="rw-rh-btn">{{ __('Back') }}</button>
                        <button type="button" wire:click="save" wire:loading.attr="disabled" class="rw-rh-btn rw-rh-btn-danger" id="rh-save">{{ __('Confirm change') }}</button>
                    </div>
                @endif
            </div>
        @else
            @forelse($readings as $r)
                @php $invoice = $invoices->get($r->id); @endphp
                <div class="rw-rh-row" data-reading-id="{{ $r->id }}">
                    <div class="rw-rh-row-top">
                        <span class="rw-rh-date">{{ $r->reading_date->format('d M Y') }}</span>
                        <span class="rw-rh-chips">
                            @if($r->reading_type)<span class="rw-rh-chip">{{ $r->reading_type->getLabel() }}</span>@endif
                            @if($invoice)<span class="rw-rh-chip rw-rh-chip-billed">{{ __('Billed') }} · {{ $invoice->invoice_number }}</span>@endif
                            @if($r->is_waived)<span class="rw-rh-chip">{{ __('Waived') }}</span>@endif
                        </span>
                    </div>
                    <div class="rw-rh-nums">
                        <div><span>{{ __('Previous') }}</span><b>{{ $r->old_reading !== null ? $fmt($r->old_reading) : '—' }}</b></div>
                        <div><span>{{ __('Current') }}</span><b>{{ $r->new_reading !== null ? $fmt($r->new_reading) : '—' }}</b></div>
                        <div><span>{{ __('Used') }}</span><b>{{ $fmt($r->amount_used) }}{{ $uom ? ' '.$uom : '' }}</b></div>
                    </div>
                    @if($service->isEditable($r))
                        @can('update', $r)
                            <button type="button" wire:click="edit({{ $r->id }})" class="rw-rh-btn rw-rh-edit-btn" id="rh-edit-{{ $r->id }}">{{ __('Edit') }}</button>
                        @endcan
                    @endif
                </div>
            @empty
                <p class="rw-rh-muted">{{ __('No readings recorded yet.') }}</p>
            @endforelse

            @if($hasMore)
                <button type="button" wire:click="loadMore" class="rw-rh-btn rw-rh-more">{{ __('Load more') }}</button>
            @endif
        @endif
    @endif
</div>
