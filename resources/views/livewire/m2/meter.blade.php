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
        @if ($saved)
            <div class="m2-notice is-ok" role="status" id="m2-meter-saved">{{ $saved }}</div>
        @endif

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

            {{-- Reset: a new starting point after the meter was replaced or rolled over. --}}
            @if ($resetting)
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
                        <button type="submit" class="m2-btn is-danger" id="m2-meter-reset-confirm">{{ __('Reset') }}</button>
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
                    <div class="m2a-row is-static" wire:key="m2-hist-{{ $row->id }}">
                        <x-m2.icon :name="$utility->name === 'Water' ? 'water' : 'bolt'" />
                        <span class="m2a-t">
                            <b>{{ $row->reading_date->translatedFormat('d M Y') }}</b>
                            <span>
                                {{ $row->is_reset ? __('Meter reset') : $trim($row->old_reading).' → '.$trim($row->new_reading) }}
                            </span>
                        </span>
                        <span class="m2a-chip is-warn">{{ $row->is_reset ? '0' : $trim($row->amount_used) }} {{ $uom }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </main>
</div>
