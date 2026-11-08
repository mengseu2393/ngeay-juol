@php
    use App\Support\Money;
    use App\Support\UtilityCatalog;

    $tone = match ($utility->name) { 'Electricity' => 'e', 'Water' => 'w', default => '' };
    $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    $uom = $utility->unit_of_measure;
    $cost = $used !== null ? $used * (float) $utility->rate : null;
    $can = auth()->user()->can('create', \App\Models\UtilityUsage::class);
@endphp

<div class="m2a">
    <x-m2.header :title="__('Room').' '.$unit->room_number" :subtitle="UtilityCatalog::label($utility->name)" :back="route('m2.room', $unit)" />

    <main class="m2-wrap m2a-main">
        {{-- Saves show as the top toast (m2-feedback.js), every time — not an inline box that stays put. --}}
        <div class="m2a-tiles">
            <div class="m2a-tile" id="m2-meter-begin">
                <span class="k">{{ __('Begin') }}</span>
                <span class="v">{{ $hasPrevious ? $trim($begin) : '–' }}</span>
                <span class="u">{{ $hasPrevious ? __('Previous reading') : __('First reading') }}</span>
            </div>
            <div class="m2a-tile" id="m2-meter-price">
                <span class="k">{{ __('Price') }}</span>
                <span class="v">{{ Money::format($utility->rate, $utility->currency, Money::decimals($utility->currency) === 0 ? 0 : (((float) $utility->rate) < 1 ? 4 : 2)) }}</span>
                <span class="u">{{ $uom ? __('per :unit', ['unit' => $uom]) : '' }}</span>
            </div>
        </div>

        @if ($can)
            @if ($confirming === 'save')
                <div class="m2a-card" id="m2-meter-save-confirm">
                    <b>{{ __('Save this reading?') }}</b>
                    <div class="m2a-kv" style="padding:0;border:0"><span>{{ __('Begin') }}</span><b class="m2-num">{{ $hasPrevious ? $trim($begin) : '–' }}</b></div>
                    <div class="m2a-kv" style="padding:0;border:0"><span>{{ __('End') }}</span><b class="m2-num">{{ $trim($reading) }}</b></div>
                    <div class="m2a-kv" style="padding:0;border:0"><span>{{ __('Used') }}</span><b class="m2-num">{{ $used !== null ? $trim($used).' '.$uom : '—' }}</b></div>
                    @if ($hasPrevious && is_numeric($reading) && (float) $reading < (float) $begin)
                        <p class="m2-err" id="m2-meter-lower-warning">{{ __('This is lower than the previous reading. If the meter was replaced or went back to zero, use Reset meter instead.') }}</p>
                    @endif
                    <p class="m2-sm m2-muted" style="margin:0">{{ __('It will be used on the next invoice.') }}</p>
                    <div class="m2-grid2">
                        <button type="button" wire:click="backFromConfirm" class="m2-btn is-ghost">{{ __('Go back') }}</button>
                        <button type="button" wire:click="confirmSave" class="m2-btn" wire:loading.attr="disabled" wire:target="confirmSave" id="m2-meter-save-yes">{{ __('Yes, save') }}</button>
                    </div>
                </div>
            @else
            <form wire:submit="save" class="m2a-card" id="m2-meter-form">
                <div>
                    <label for="m2-meter-end" class="m2-label">{{ __('End') }} — {{ __("Today's reading") }}@if ($uom) ({{ $uom }})@endif</label>
                    <input id="m2-meter-end" type="text" inputmode="decimal" wire:model.live.debounce.300ms="reading" class="m2-input is-big" placeholder="0" autocomplete="off">
                    @error('reading') <p class="m2-err">{{ $message }}</p> @enderror
                </div>

                <div class="m2a-kv" style="padding:0;border:0" id="m2-meter-used">
                    <span>{{ __('Used') }}</span>
                    <b class="m2-num">{{ $used !== null ? $trim($used).' '.$uom : '—' }}</b>
                </div>
                @if ($cost !== null && $utility->billing_type === \App\Enums\BillingType::Metered)
                    <div class="m2a-kv" style="padding:0;border:0" id="m2-meter-cost">
                        <span>{{ __('Amount') }}</span>
                        <span class="m2-money">{{ Money::format($cost, $utility->currency) }}</span>
                    </div>
                @endif

                <button type="submit" class="m2-btn" wire:loading.attr="disabled" wire:target="save" id="m2-meter-save">
                    <span wire:loading.remove wire:target="save">{{ __('Save reading') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </button>
            </form>
            @endif

            {{-- Reset: a new starting point after the meter was replaced or rolled over. --}}
            @if ($resetting && $confirming === 'reset')
                <div class="m2a-card" id="m2-meter-reset-sure">
                    <b>{{ __('Reset this meter?') }}</b>
                    <p class="m2-sm" style="margin:0">{{ __('The next reading will count from :n. The jump from the old reading is not billed. Past history stays.', ['n' => $trim($resetTo)]) }}</p>
                    <label style="display:flex;gap:8px;align-items:flex-start">
                        <input type="checkbox" wire:model="understood" id="m2-meter-reset-understood">
                        <span class="m2-sm">{{ __('The meter was replaced or went back to zero.') }}</span>
                    </label>
                    @error('understood') <p class="m2-err">{{ $message }}</p> @enderror
                    {{-- A refused reset (e.g. a reading already exists today) must say why, here, where the tap was. --}}
                    @error('date') <p class="m2-err" id="m2-meter-reset-error">{{ $message }}</p> @enderror
                    @error('startingReading') <p class="m2-err" id="m2-meter-reset-error">{{ $message }}</p> @enderror
                    @error('resetTo') <p class="m2-err" id="m2-meter-reset-error">{{ $message }}</p> @enderror
                    <div class="m2-grid2">
                        <button type="button" wire:click="backFromConfirm" class="m2-btn is-ghost">{{ __('Go back') }}</button>
                        <button type="button" wire:click="applyReset" class="m2-btn is-danger" id="m2-meter-reset-yes">{{ __('Yes, reset') }}</button>
                    </div>
                </div>
            @elseif ($resetting)
                <form wire:submit="confirmReset" class="m2a-card" id="m2-meter-reset-form">
                    <p class="m2-sm m2-muted" style="margin:0">{{ __('Use this when the meter was replaced or went back to zero. History stays; the next reading counts from here and the jump is not billed.') }}</p>
                    <div>
                        <label for="m2-meter-reset-to" class="m2-label">{{ __('New starting reading') }}</label>
                        <input id="m2-meter-reset-to" type="text" inputmode="decimal" wire:model="resetTo" class="m2-input is-big" autocomplete="off">
                        @error('resetTo') <p class="m2-err">{{ $message }}</p> @enderror
                        @error('date') <p class="m2-err">{{ $message }}</p> @enderror
                    </div>
                    <div class="m2-grid2">
                        <button type="button" wire:click="cancelReset" class="m2-btn is-ghost">{{ __('Cancel') }}</button>
                        <button type="submit" class="m2-btn is-danger" id="m2-meter-reset-confirm">{{ __('Continue') }}</button>
                    </div>
                </form>
            @else
                <button type="button" wire:click="openReset" class="m2-btn is-ghost" id="m2-meter-reset">{{ __('Reset meter') }}</button>
            @endif
        @endif

        <h2 class="m2a-h">{{ __('History') }}</h2>
        @if ($history->isEmpty())
            <div class="m2a-empty">{{ __('No reading yet') }}</div>
        @else
            <div class="m2a-list" id="m2-meter-history">
                @foreach ($history as $row)
                    @if ($editingId === $row->id && $confirming === 'edit')
                        <div class="m2a-card" wire:key="m2-hist-{{ $row->id }}" id="m2-meter-edit-sure">
                            <b>{{ __('Change this reading?') }}</b>
                            <div class="m2a-kv" style="padding:0;border:0"><span>{{ __('Before') }}</span><b class="m2-num">{{ $trim($row->new_reading) }} · {{ $row->reading_date->translatedFormat('d M Y') }}</b></div>
                            <div class="m2a-kv" style="padding:0;border:0"><span>{{ __('After') }}</span><b class="m2-num">{{ $trim($editReading) }} · {{ \Carbon\Carbon::parse($editDate)->translatedFormat('d M Y') }}</b></div>
                            <p class="m2-sm m2-muted" style="margin:0">{{ __('Later readings will be recalculated from this one.') }}</p>
                            @if ($billed = $service->billedOn($row))
                                <p class="m2-sm" style="margin:0" id="m2-meter-edit-billed">{{ __('Invoice :number already includes this reading. It will not be changed — only the meter history is corrected.', ['number' => $billed->invoice_number]) }}</p>
                            @endif
                            <div class="m2-grid2">
                                <button type="button" wire:click="backFromConfirm" class="m2-btn is-ghost">{{ __('Go back') }}</button>
                                <button type="button" wire:click="applyEdit" class="m2-btn" id="m2-meter-edit-yes">{{ __('Yes, change') }}</button>
                            </div>
                        </div>
                    @elseif ($editingId === $row->id)
                        <form wire:submit="saveEdit" class="m2a-card" wire:key="m2-hist-{{ $row->id }}" id="m2-meter-edit-form">
                            <div>
                                <label for="m2-meter-edit-reading" class="m2-label">{{ __('Meter reading') }}@if ($uom) ({{ $uom }})@endif</label>
                                <input id="m2-meter-edit-reading" type="text" inputmode="decimal" wire:model="editReading" class="m2-input is-big" autocomplete="off">
                                @error('editReading') <p class="m2-err">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="m2-meter-edit-date" class="m2-label">{{ __('Date') }}</label>
                                <input id="m2-meter-edit-date" type="date" wire:model="editDate" class="m2-input">
                                @error('editDate') <p class="m2-err">{{ $message }}</p> @enderror
                            </div>
                            <div class="m2-grid2">
                                <button type="button" wire:click="cancelEdit" class="m2-btn is-ghost">{{ __('Cancel') }}</button>
                                <button type="submit" class="m2-btn" id="m2-meter-edit-save">{{ __('Save') }}</button>
                            </div>
                        </form>
                    @else
                        <div class="m2a-row is-static" wire:key="m2-hist-{{ $row->id }}">
                            <x-m2.icon :name="$utility->name === 'Water' ? 'water' : 'bolt'" />
                            <span class="m2a-t">
                                <b>{{ $row->reading_date->translatedFormat('d M Y') }}</b>
                                <span>
                                    {{ $row->is_reset ? __('Meter reset') : $trim($row->old_reading).' → '.$trim($row->new_reading) }}
                                </span>
                            </span>
                            <span class="m2a-chip is-warn">{{ $row->is_reset ? '0' : $trim($row->amount_used) }} {{ $uom }}</span>
                            @if ($can && $service->isEditable($row))
                                <button type="button" wire:click="edit({{ $row->id }})" class="m2-mini" id="m2-meter-edit-{{ $row->id }}">
                                    <x-m2.icon name="pencil" class="m2-mini-ico" /><span>{{ __('Change') }}</span>
                                </button>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </main>
</div>
