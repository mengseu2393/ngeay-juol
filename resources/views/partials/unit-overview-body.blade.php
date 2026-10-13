@php
    use App\Enums\UnitStatus;
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    $uom = $utility?->unit_of_measure;
    $statusKey = $unit->status === UnitStatus::Occupied ? 'occupied' : ($unit->status === UnitStatus::Available ? 'vacant' : 'other');
    $tenantName = $rental?->occupant_name ?: $rental?->tenant?->name;
@endphp

    <div class="rw-uo" id="unit-overview">
        {{-- ── Header ── --}}
        @unless ($simple)
            <a href="{{ $backUrl }}" class="rw-uo-back" id="uo-back">← {{ __('Units') }}</a>
        @endunless

        <section class="rw-uo-card">
            <div class="rw-uo-head">
                <div>
                    <h2 class="rw-uo-title">{{ __('Room :room', ['room' => $unit->room_number]) }}</h2>
                    <p class="rw-um-muted">
                        {{ $unit->property?->name }}
                        @if ($unit->floor_number !== null && $unit->floor_number !== '') · {{ __('Floor :n', ['n' => $unit->floor_number]) }} @endif
                    </p>
                </div>
                <span class="rw-um-badge rw-um-badge-{{ $statusKey }}">{{ $unit->status->getLabel() }}</span>
            </div>
            <dl class="rw-uo-grid">
                <div><dt>{{ __('Rent') }}</dt><dd>{{ \App\Support\Money::formatForRecord($unit->rent_amount, $unit) }}</dd></div>
                <div><dt>{{ __('Room type') }}</dt><dd>{{ $unit->room_type ?: '—' }}</dd></div>
            </dl>
            @if ($can['editRoom'])
                <a class="rw-um-btn" href="{{ \App\Filament\Resources\UnitResource::getUrl('edit', ['record' => $unit]) }}" id="uo-edit-room">{{ __('Edit room') }}</a>
            @endif
        </section>

        @if ($notice)
            <div class="rw-rh-notice rw-rh-notice-ok" role="status" id="uo-notice">{{ $notice }}</div>
        @endif

        @if ($credentials)
            <section class="rw-uo-card" id="uo-credentials">
                <h3 class="rw-uo-h">{{ __('Tenant login') }}</h3>
                <dl class="rw-uo-grid">
                    <div><dt>{{ __('Login username') }}</dt><dd class="rw-uo-mono">{{ $credentials['username'] }}</dd></div>
                    @if ($credentials['password'])
                        <div><dt>{{ __('Login password') }}</dt><dd class="rw-uo-mono">{{ $credentials['password'] }}</dd></div>
                    @endif
                </dl>
                @if ($credentials['password'])
                    <p class="rw-um-muted">{{ __('Save this password now — it is shown only once.') }}</p>
                @endif
            </section>
        @endif

        {{-- ═══════════════ Agreement ═══════════════ --}}
        <section class="rw-uo-card" id="uo-agreement">
            <h3 class="rw-uo-h">{{ __('Agreement') }}</h3>

            @if ($rental)
                <dl class="rw-uo-grid">
                    <div><dt>{{ __('Tenant') }}</dt><dd>{{ $tenantName ?: '—' }}</dd></div>
                    @if ($rental->occupant_phone)<div><dt>{{ __('Phone') }}</dt><dd>{{ $rental->occupant_phone }}</dd></div>@endif
                    <div><dt>{{ __('Start date') }}</dt><dd>{{ $rental->start_date?->format('d M Y') ?: '—' }}</dd></div>
                    <div><dt>{{ __('Monthly rent') }}</dt><dd>{{ \App\Support\Money::formatForRecord($rental->monthly_rent, $rental) }}</dd></div>
                    @if ((float) $rental->security_deposit > 0)<div><dt>{{ __('Security deposit') }}</dt><dd>{{ \App\Support\Money::formatForRecord($rental->security_deposit, $rental) }}</dd></div>@endif
                </dl>
            @else
                <p class="rw-um-muted">{{ __('No active agreement') }}</p>
            @endif

            @if ($panel === 'add')
                <div class="rw-uo-panel" id="uo-add-panel">
                    <h4 class="rw-uo-ph">{{ __('Add agreement') }}</h4>

                    <label class="rw-rh-label" for="uo-name">{{ __('Full name') }} *</label>
                    <input id="uo-name" type="text" class="rw-rh-input" wire:model="occupantName" autocomplete="name">
                    @error('occupantName') <p class="rw-rh-error">{{ $message }}</p> @enderror

                    <label class="rw-rh-label" for="uo-phone">{{ __('Phone') }}</label>
                    <input id="uo-phone" type="tel" inputmode="tel" class="rw-rh-input" wire:model="occupantPhone">
                    @error('occupantPhone') <p class="rw-rh-error">{{ $message }}</p> @enderror

                    <label class="rw-rh-label" for="uo-idcard">{{ __('ID card') }}</label>
                    <input id="uo-idcard" type="text" class="rw-rh-input" wire:model="occupantIdCard">

                    <label class="rw-rh-label" for="uo-start">{{ __('Start date') }} *</label>
                    <input id="uo-start" type="date" class="rw-rh-input" wire:model="startDate">
                    @error('startDate') <p class="rw-rh-error">{{ $message }}</p> @enderror

                    <div class="rw-uo-two">
                        <div>
                            <label class="rw-rh-label" for="uo-rent">{{ __('Monthly rent') }} *</label>
                            <input id="uo-rent" type="number" inputmode="decimal" step="0.01" min="0" class="rw-rh-input" wire:model="monthlyRent">
                            @error('monthlyRent') <p class="rw-rh-error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="rw-rh-label" for="uo-deposit">{{ __('Security deposit') }}</label>
                            <input id="uo-deposit" type="number" inputmode="decimal" step="0.01" min="0" class="rw-rh-input" wire:model="securityDeposit">
                            @error('securityDeposit') <p class="rw-rh-error">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    @if ($utilities->isNotEmpty())
                        <p class="rw-uo-ph">{{ __('Move-in meter readings') }} <span class="rw-um-muted">({{ __('optional') }})</span></p>
                        <p class="rw-um-muted">{{ __('Read the meters now so the tenant is only billed from this point.') }}</p>
                        @foreach ($utilities as $u)
                            <label class="rw-rh-label" for="uo-mi-{{ $u->id }}">
                                {{ \App\Support\UtilityCatalog::label($u->name) }}@if ($u->unit_of_measure) ({{ $u->unit_of_measure }})@endif
                            </label>
                            <input id="uo-mi-{{ $u->id }}" type="number" inputmode="decimal" step="0.001" min="0" class="rw-rh-input" wire:model="moveInReadings.{{ $u->id }}" placeholder="0.000">
                            <p class="rw-um-muted">{{ \App\Services\MeterUtilities::readingHint($unit->id, $u->id, $u->unit_of_measure) }}</p>
                            @error("moveInReadings.{$u->id}") <p class="rw-rh-error">{{ $message }}</p> @enderror
                        @endforeach
                    @endif

                    @error('unitId') <p class="rw-rh-error">{{ $message }}</p> @enderror

                    <div class="rw-rh-actions">
                        <button type="button" class="rw-rh-btn" wire:click="closePanel">{{ __('Cancel') }}</button>
                        <button type="button" class="rw-rh-btn rw-rh-btn-primary" wire:click="startAgreement" wire:loading.attr="disabled" id="uo-add-submit">{{ __('Confirm') }}</button>
                    </div>
                </div>
            @elseif ($panel === 'end')
                <div class="rw-uo-panel" id="uo-end-panel">
                    <h4 class="rw-uo-ph">{{ __('End agreement') }}</h4>

                    <label class="rw-rh-label" for="uo-end-date">{{ __('End date') }}</label>
                    <input id="uo-end-date" type="date" class="rw-rh-input" wire:model="endDate">
                    @error('endDate') <p class="rw-rh-error">{{ $message }}</p> @enderror

                    <label class="rw-rh-label" for="uo-outcome">{{ __('Outcome') }}</label>
                    <select id="uo-outcome" class="rw-rh-input" wire:model="outcome">
                        @foreach ($outcomes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>

                    <label class="rw-rh-check">
                        <input type="checkbox" wire:model="freeRoom" id="uo-free-room">
                        <span>{{ __('Mark the room as available') }}</span>
                    </label>

                    <label class="rw-rh-check">
                        <input type="checkbox" wire:model="confirmEnd" id="uo-end-confirm">
                        <span>{{ __('I understand this ends the agreement for :tenant.', ['tenant' => $tenantName ?: __('this tenant')]) }}</span>
                    </label>
                    @error('confirmEnd') <p class="rw-rh-error">{{ $message }}</p> @enderror
                    @error('unitId') <p class="rw-rh-error">{{ $message }}</p> @enderror

                    <div class="rw-rh-actions">
                        <button type="button" class="rw-rh-btn" wire:click="closePanel">{{ __('Cancel') }}</button>
                        <button type="button" class="rw-rh-btn rw-rh-btn-danger" wire:click="endAgreement" wire:loading.attr="disabled" id="uo-end-submit">{{ __('End agreement') }}</button>
                    </div>
                </div>
            @else
                <div class="rw-uo-actions">
                    @if ($can['add'])
                        <button type="button" class="rw-um-btn rw-um-btn-primary" wire:click="openPanel('add')" id="uo-add">{{ __('Add agreement') }}</button>
                    @endif
                    @if ($can['end'])
                        <button type="button" class="rw-um-btn rw-um-btn-danger" wire:click="openPanel('end')" id="uo-end">{{ __('End agreement') }}</button>
                    @endif
                    @if ($rental)
                        <a class="rw-um-btn" href="{{ \App\Filament\Resources\RentalResource::getUrl('view', ['record' => $rental]) }}" id="uo-view-agreement">{{ __('View agreement') }}</a>
                    @endif
                </div>
            @endif
        </section>

        {{-- ═══════════════ Utilities & readings ═══════════════ --}}
        <section class="rw-uo-card" id="uo-readings">
            <h3 class="rw-uo-h">{{ __('Meter readings') }}</h3>

            @if ($utilities->isEmpty())
                <p class="rw-um-muted">{{ __('No metered utilities are set up for this property yet.') }}</p>
            @else
                <div class="rw-rh-tabs" role="tablist">
                    @foreach ($utilities as $u)
                        <button type="button" role="tab" wire:click="selectUtility({{ $u->id }})"
                            class="rw-rh-tab {{ $u->id === $utilityId ? 'is-active' : '' }}" aria-selected="{{ $u->id === $utilityId ? 'true' : 'false' }}">
                            {{ \App\Support\UtilityCatalog::label($u->name) }}
                        </button>
                    @endforeach
                </div>

                <p class="rw-um-muted">{{ \App\Services\MeterUtilities::readingHint($unit->id, $utility->id, $uom) }}</p>

                @if ($panel === 'reading')
                    <div class="rw-uo-panel" id="uo-reading-panel">
                        <h4 class="rw-uo-ph">{{ __('Record meter reading') }}</h4>
                        <label class="rw-rh-label" for="uo-new">{{ __('New reading') }}@if ($uom) ({{ $uom }})@endif</label>
                        <input id="uo-new" type="number" inputmode="decimal" step="0.001" min="0" class="rw-rh-input" wire:model="newReading" placeholder="0.000">
                        @error('newReading') <p class="rw-rh-error">{{ $message }}</p> @enderror

                        <label class="rw-rh-label" for="uo-date">{{ __('Reading date') }}</label>
                        <input id="uo-date" type="date" max="{{ now()->toDateString() }}" class="rw-rh-input" wire:model="date">
                        @error('date') <p class="rw-rh-error">{{ $message }}</p> @enderror

                        <label class="rw-rh-label" for="uo-type">{{ __('Reading type') }}</label>
                        <select id="uo-type" class="rw-rh-input" wire:model="type">
                            <option value="{{ \App\Enums\ReadingType::Actual->value }}">{{ \App\Enums\ReadingType::Actual->getLabel() }}</option>
                            <option value="{{ \App\Enums\ReadingType::Estimated->value }}">{{ \App\Enums\ReadingType::Estimated->getLabel() }}</option>
                        </select>

                        <div class="rw-rh-actions">
                            <button type="button" class="rw-rh-btn" wire:click="closePanel">{{ __('Cancel') }}</button>
                            <button type="button" class="rw-rh-btn rw-rh-btn-primary" wire:click="saveReading" wire:loading.attr="disabled" id="uo-reading-submit">{{ __('Save') }}</button>
                        </div>
                    </div>
                @elseif ($panel === 'reset')
                    <div class="rw-uo-panel" id="uo-reset-panel">
                        <h4 class="rw-uo-ph">{{ __('Reset reading') }}</h4>
                        <p class="rw-um-muted">{{ __('Use this when the meter was replaced or restarted. Past readings are kept; the next reading is measured from the starting point you enter.') }}</p>

                        <label class="rw-rh-label" for="uo-start-reading">{{ __('New starting reading') }}@if ($uom) ({{ $uom }})@endif</label>
                        <input id="uo-start-reading" type="number" inputmode="decimal" step="0.001" min="0" class="rw-rh-input" wire:model="startingReading">
                        @error('startingReading') <p class="rw-rh-error">{{ $message }}</p> @enderror

                        <label class="rw-rh-label" for="uo-reset-date">{{ __('Reading date') }}</label>
                        <input id="uo-reset-date" type="date" max="{{ now()->toDateString() }}" class="rw-rh-input" wire:model="date">
                        @error('date') <p class="rw-rh-error">{{ $message }}</p> @enderror

                        <div class="rw-rh-notice rw-um-warn">{{ __('Usage between the last reading and this date will not be billed. Record a final reading first if you need to bill it.') }}</div>

                        <label class="rw-rh-check">
                            <input type="checkbox" wire:model="confirmReset" id="uo-reset-confirm">
                            <span>{{ __('I understand this sets a new starting point for this room.') }}</span>
                        </label>
                        @error('confirmReset') <p class="rw-rh-error">{{ $message }}</p> @enderror

                        <div class="rw-rh-actions">
                            <button type="button" class="rw-rh-btn" wire:click="closePanel">{{ __('Cancel') }}</button>
                            <button type="button" class="rw-rh-btn rw-rh-btn-danger" wire:click="resetReading" wire:loading.attr="disabled" id="uo-reset-submit">{{ __('Reset reading') }}</button>
                        </div>
                    </div>
                @elseif (! $editingId)
                    @if ($can['readings'])
                        <div class="rw-uo-actions">
                            <button type="button" class="rw-um-btn rw-um-btn-primary" wire:click="openPanel('reading')" id="uo-record">{{ __('Record meter reading') }}</button>
                            <button type="button" class="rw-um-btn" wire:click="openPanel('reset')" id="uo-reset">{{ __('Reset reading') }}</button>
                        </div>
                    @endif
                @endif
            @endif
        </section>

        {{-- ═══════════════ History ═══════════════ --}}
        @if ($utilities->isNotEmpty())
            <section class="rw-uo-card" id="uo-history">
                <h3 class="rw-uo-h">{{ __('Reading history') }}</h3>

                @if ($editingId)
                    <div class="rw-uo-panel">
                        @if (! $confirming)
                            <h4 class="rw-uo-ph">{{ __('Correct reading') }}</h4>
                            @if ($editingUsage)<p class="rw-um-muted">{{ __('Previous: :value', ['value' => $fmt($editingUsage->old_reading).($uom ? ' '.$uom : '')]) }}</p>@endif

                            <label class="rw-rh-label" for="uo-edit-new">{{ __('New reading') }}</label>
                            <input id="uo-edit-new" type="number" inputmode="decimal" step="0.001" min="0" class="rw-rh-input" wire:model="editReading">
                            @error('editReading') <p class="rw-rh-error">{{ $message }}</p> @enderror

                            <label class="rw-rh-label" for="uo-edit-date">{{ __('Reading date') }}</label>
                            <input id="uo-edit-date" type="date" max="{{ now()->toDateString() }}" class="rw-rh-input" wire:model="editDate">
                            @error('editDate') <p class="rw-rh-error">{{ $message }}</p> @enderror

                            <div class="rw-rh-actions">
                                <button type="button" class="rw-rh-btn" wire:click="cancelEdit">{{ __('Cancel') }}</button>
                                <button type="button" class="rw-rh-btn rw-rh-btn-primary" wire:click="reviewEdit" id="uo-edit-review">{{ __('Review change') }}</button>
                            </div>
                        @else
                            <h4 class="rw-uo-ph">{{ __('Confirm change') }}</h4>
                            <p class="rw-um-muted">{{ __('Please check what will change before saving.') }}</p>
                            @foreach ($changes as $c)
                                <div class="rw-rh-diff {{ $c['is_target'] ? 'is-target' : '' }}">
                                    <div class="rw-rh-diff-head">
                                        {{ \Illuminate\Support\Carbon::parse($c['after']['date'])->format('d M Y') }}
                                        @unless ($c['is_target'])<span class="rw-rh-chip">{{ __('Recalculated') }}</span>@endunless
                                    </div>
                                    <dl class="rw-rh-diff-grid">
                                        <dt>{{ __('Previous') }}</dt><dd>{{ $fmt($c['before']['old_reading']) }} → <strong>{{ $fmt($c['after']['old_reading']) }}</strong></dd>
                                        <dt>{{ __('Current') }}</dt><dd>{{ $fmt($c['before']['new_reading']) }} → <strong>{{ $fmt($c['after']['new_reading']) }}</strong></dd>
                                        <dt>{{ __('Used') }}</dt><dd>{{ $fmt($c['before']['amount_used']) }} → <strong>{{ $fmt($c['after']['amount_used']) }}</strong>{{ $uom ? ' '.$uom : '' }}</dd>
                                    </dl>
                                </div>
                            @endforeach
                            <label class="rw-rh-check">
                                <input type="checkbox" wire:model="confirmedEdit" id="uo-edit-confirm">
                                <span>{{ __('I understand this changes the recorded readings and any draft invoice using them.') }}</span>
                            </label>
                            @error('confirmedEdit') <p class="rw-rh-error">{{ $message }}</p> @enderror
                            @error('editReading') <p class="rw-rh-error">{{ $message }}</p> @enderror
                            <div class="rw-rh-actions">
                                <button type="button" class="rw-rh-btn" wire:click="backToEdit">{{ __('Back') }}</button>
                                <button type="button" class="rw-rh-btn rw-rh-btn-danger" wire:click="saveEdit" wire:loading.attr="disabled" id="uo-edit-save">{{ __('Confirm change') }}</button>
                            </div>
                        @endif
                    </div>
                @else
                    @forelse ($readings as $r)
                        @php $invoice = $invoices->get($r->id); @endphp
                        <div class="rw-rh-row" wire:key="uo-reading-{{ $r->id }}">
                            <div class="rw-rh-row-top">
                                <span class="rw-rh-date">{{ $r->reading_date->format('d M Y') }}</span>
                                <span class="rw-rh-chips">
                                    @if ($r->is_reset)<span class="rw-rh-chip">{{ __('Reset') }}</span>@endif
                                    @if ($r->reading_type)<span class="rw-rh-chip">{{ $r->reading_type->getLabel() }}</span>@endif
                                    @if ($invoice)<span class="rw-rh-chip rw-rh-chip-billed">{{ __('Billed') }} · {{ $invoice->invoice_number }}</span>@endif
                                </span>
                            </div>
                            <div class="rw-rh-nums">
                                <div><span>{{ __('Previous') }}</span><b>{{ $r->old_reading !== null ? $fmt($r->old_reading) : '—' }}</b></div>
                                <div><span>{{ __('Current') }}</span><b>{{ $r->new_reading !== null ? $fmt($r->new_reading) : '—' }}</b></div>
                                <div><span>{{ __('Used') }}</span><b>{{ $fmt($r->amount_used) }}{{ $uom ? ' '.$uom : '' }}</b></div>
                            </div>
                            @if ($service->isEditable($r))
                                @can('update', $r)
                                    <button type="button" class="rw-rh-btn rw-rh-edit-btn" wire:click="edit({{ $r->id }})" id="uo-edit-{{ $r->id }}">{{ __('Edit') }}</button>
                                @endcan
                            @endif
                        </div>
                    @empty
                        <p class="rw-um-muted">{{ __('No readings recorded yet.') }}</p>
                    @endforelse

                    @if ($hasMore)
                        <button type="button" class="rw-rh-btn rw-rh-more" wire:click="loadMore">{{ __('Load more') }}</button>
                    @endif
                @endif
            </section>
        @endif
    </div>
