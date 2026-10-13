{{-- One room's meter readings (Mobile\UtilityRatesReadingHistory) — the phone take on livewire.unit-reading-history. --}}
@php
    use App\Services\MeterUtilities;
    use App\Support\UtilityCatalog;
    use Illuminate\Support\Carbon;

    $fmt = fn ($v) => MeterUtilities::trimReading($v);
    $uom = $utility?->unit_of_measure;
    $chip = 'rw-m-badge bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300';
@endphp

<div id="rw-m-reading-history-{{ $unitId }}" class="space-y-3">
    @if ($utilities->isEmpty())
        <p class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">{{ __('No metered utilities are set up for this property yet.') }}</p>
    @else
        @if ($utilities->count() > 1)
            <div class="-mx-5 flex gap-2 overflow-x-auto px-5 pb-1 [scrollbar-width:none]" role="group" aria-label="{{ __('Utility') }}">
                @foreach ($utilities as $u)
                    <button
                        type="button"
                        wire:click="selectUtility({{ $u->id }})"
                        class="rw-m-pill"
                        aria-pressed="{{ $u->id === $utilityId ? 'true' : 'false' }}"
                        id="rw-m-history-utility-{{ $u->id }}"
                    >{{ UtilityCatalog::label($u->name) }}</button>
                @endforeach
            </div>
        @endif

        @if ($editingId)
            @php $editing = $readings->firstWhere('id', $editingId); @endphp

            @if (! $confirming)
                <div class="space-y-3">
                    <h3 class="font-bold">{{ __('Correct reading') }}</h3>
                    @if ($editing)
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Previous: :value', ['value' => $fmt($editing->old_reading).($uom ? ' '.$uom : '')]) }}</p>
                    @endif

                    <div>
                        <label class="mb-1 block text-sm font-semibold" for="rw-m-history-new">{{ __('New reading') }}@if ($uom) ({{ $uom }})@endif</label>
                        <input id="rw-m-history-new" type="text" inputmode="decimal" wire:model="newReading" class="rw-m-input text-xl font-semibold" autocomplete="off" enterkeyhint="done">
                        @error('newReading') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold" for="rw-m-history-date">{{ __('Reading date') }}</label>
                        <input id="rw-m-history-date" type="date" max="{{ now()->toDateString() }}" wire:model="date" class="rw-m-input">
                        @error('date') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" wire:click="cancelEdit" class="rw-m-btn rw-m-btn-secondary" id="rw-m-history-cancel">{{ __('Cancel') }}</button>
                        <button type="button" wire:click="review" wire:loading.attr="disabled" wire:target="review" class="rw-m-btn rw-m-btn-primary" id="rw-m-history-review">{{ __('Review change') }}</button>
                    </div>
                </div>
            @else
                <div class="space-y-3">
                    <div>
                        <h3 class="font-bold">{{ __('Confirm change') }}</h3>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Please check what will change before saving.') }}</p>
                    </div>

                    @foreach ($changes as $c)
                        <div class="rounded-xl border p-3 text-sm {{ $c['is_target'] ? 'border-amber-300 bg-amber-50 dark:border-amber-500/40 dark:bg-amber-500/10' : 'border-slate-200 dark:border-slate-700' }}">
                            <div class="mb-2 flex items-center justify-between gap-2 font-semibold">
                                {{ Carbon::parse($c['after']['date'])->format('d M Y') }}
                                @unless ($c['is_target'])<span class="{{ $chip }}">{{ __('Recalculated') }}</span>@endunless
                            </div>
                            <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
                                <dt class="text-slate-500 dark:text-slate-400">{{ __('Previous') }}</dt>
                                <dd>{{ $fmt($c['before']['old_reading']) }} → <strong>{{ $fmt($c['after']['old_reading']) }}</strong></dd>
                                <dt class="text-slate-500 dark:text-slate-400">{{ __('Current') }}</dt>
                                <dd>{{ $fmt($c['before']['new_reading']) }} → <strong>{{ $fmt($c['after']['new_reading']) }}</strong></dd>
                                <dt class="text-slate-500 dark:text-slate-400">{{ __('Used') }}</dt>
                                <dd>{{ $fmt($c['before']['amount_used']) }} → <strong>{{ $fmt($c['after']['amount_used']) }}</strong>{{ $uom ? ' '.$uom : '' }}</dd>
                            </dl>
                        </div>
                    @endforeach

                    <label class="flex min-h-11 items-start gap-3 text-sm" for="rw-m-history-confirm">
                        <input type="checkbox" wire:model="confirmed" id="rw-m-history-confirm" class="mt-0.5 size-5 shrink-0 accent-emerald-600">
                        <span>{{ __('I understand this changes the recorded readings and any draft invoice using them.') }}</span>
                    </label>
                    @error('confirmed') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    @error('newReading') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" wire:click="backToEdit" class="rw-m-btn rw-m-btn-secondary" id="rw-m-history-back">{{ __('Back') }}</button>
                        <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="rw-m-btn bg-red-600 text-white active:bg-red-700" id="rw-m-history-save">{{ __('Confirm change') }}</button>
                    </div>
                </div>
            @endif
        @else
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($readings as $r)
                    @php $invoice = $invoices->get($r->id); @endphp
                    <div class="py-3" data-reading-id="{{ $r->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold">{{ $r->reading_date->format('d M Y') }}</span>
                            <span class="flex flex-wrap gap-1">
                                @if ($r->reading_type)<span class="{{ $chip }}">{{ $r->reading_type->getLabel() }}</span>@endif
                                @if ($invoice)<span class="rw-m-badge bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">{{ __('Billed') }} · {{ $invoice->invoice_number }}</span>@endif
                                @if ($r->is_waived)<span class="{{ $chip }}">{{ __('Waived') }}</span>@endif
                            </span>
                        </div>
                        <div class="mt-2 grid grid-cols-3 gap-2 text-sm">
                            <div><p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Previous') }}</p><p class="font-semibold">{{ $r->old_reading !== null ? $fmt($r->old_reading) : '—' }}</p></div>
                            <div><p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Current') }}</p><p class="font-semibold">{{ $r->new_reading !== null ? $fmt($r->new_reading) : '—' }}</p></div>
                            <div><p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Used') }}</p><p class="font-semibold">{{ $fmt($r->amount_used) }}{{ $uom ? ' '.$uom : '' }}</p></div>
                        </div>
                        @if ($service->isEditable($r))
                            @can('update', $r)
                                <button type="button" wire:click="edit({{ $r->id }})" class="rw-m-btn rw-m-btn-secondary mt-2 min-h-11 w-full text-sm" id="rw-m-history-edit-{{ $r->id }}">{{ __('Edit') }}</button>
                            @endcan
                        @endif
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-slate-500 dark:text-slate-400">{{ __('No readings recorded yet.') }}</p>
                @endforelse
            </div>

            @if ($hasMore)
                <button type="button" wire:click="loadMore" class="rw-m-btn rw-m-btn-secondary w-full" id="rw-m-history-more">
                    <span wire:loading.remove wire:target="loadMore">{{ __('Load more') }}</span>
                    <span wire:loading wire:target="loadMore">{{ __('Loading…') }}</span>
                </button>
            @endif
        @endif
    @endif
</div>
