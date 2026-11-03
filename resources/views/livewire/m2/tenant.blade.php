@php
    $date = fn ($d) => $d?->translatedFormat('d M Y');

    $rows = array_filter([
        __('Name') => $rental->occupant_name,
        __('Phone') => $rental->occupant_phone,
        __('Gender') => $genders[$rental->occupant_gender] ?? null,
        __('Date of birth') => $date($rental->occupant_dob),
        __('Nationality') => $rental->occupant_nationality,
        __('Address') => $rental->occupant_address,
        __('Workplace') => $rental->occupant_workplace,
        __('Moved in') => $date($rental->start_date),
    ], 'filled');
@endphp

<div class="m2a">
    <x-m2.header
        :title="$rental->occupant_name ?: __('Tenant')"
        :subtitle="$rental->unit ? __('Room').' '.$rental->unit->room_number : null"
        :back="$rental->unit ? route('m2.room', $rental->unit) : route('m2.home')"
        :profile="false"
        :avatar="$avatar"
        :initial="$rental->occupant_name"
    />

    <main class="m2-wrap m2a-main">
        @if ($step === 'done')
            <div class="m2-notice is-ok" role="status" id="m2-tenant-ended">{{ __('Tenancy ended') }}</div>
        @endif

        <h2 class="m2a-h">{{ __('Details') }}</h2>
        <div class="m2a-list" id="m2-tenant-info">
            @foreach ($rows as $label => $value)
                <div class="m2a-kv">
                    <span>{{ $label }}</span>
                    @if ($label === __('Phone'))
                        <b class="m2-num"><a href="tel:{{ preg_replace('/[^\d+]/', '', $value) }}" style="color:var(--m2-brand)">{{ $value }}</a></b>
                    @else
                        <b>{{ $value }}</b>
                    @endif
                </div>
            @endforeach
        </div>

        <h2 class="m2a-h">{{ __('ID card') }}</h2>
        <div class="m2a-list" id="m2-tenant-id">
            <div class="m2a-kv"><span>{{ __('ID card number') }}</span><b class="m2-num">{{ $rental->occupant_id_card ?: '—' }}</b></div>
            <div style="padding:12px 14px;border-top:1px solid var(--m2-line)">
                @if ($photos->isNotEmpty())
                    <div class="m2-photos">
                        @foreach ($photos as $media)
                            <a href="{{ $media->getUrl() }}" target="_blank" rel="noopener" wire:key="m2-photo-{{ $media->id }}"><img src="{{ $media->getUrl() }}" alt="{{ __('ID card photo') }}" loading="lazy"></a>
                        @endforeach
                    </div>
                @else
                    <p class="m2-muted m2-sm" style="margin:0">{{ __('No ID card photo uploaded.') }}</p>
                @endif
            </div>
        </div>

        @if ($coOccupants->isNotEmpty())
            <h2 class="m2a-h">{{ __('Occupants') }}</h2>
            <div class="m2a-list" id="m2-tenant-occupants">
                @foreach ($coOccupants as $occupant)
                    <div class="m2a-row is-static" wire:key="m2-co-{{ $occupant->id }}">
                        <span class="m2a-dot is-idle"></span>
                        <span class="m2a-t">
                            <b class="m2-trunc" style="display:block">{{ $occupant->occupant_name }}</b>
                            <span>{{ $occupant->occupant_phone ?: ($occupant->role === \App\Enums\OccupantRole::Dependent ? __('Dependent') : __('Co-tenant')) }}</span>
                        </span>
                        <span class="m2a-chip is-idle">{{ $occupant->role === \App\Enums\OccupantRole::Dependent ? __('Dependent') : __('Co-tenant') }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ── End tenancy: only the room's current tenant, and only for someone who may edit it ── --}}
        @if ($canEnd)
            <div style="margin-top:20px">
                @if (! $confirming)
                    <button type="button" wire:click="openEnd" class="m2-btn is-danger" id="m2-tenant-end">{{ __('End tenancy') }}</button>
                @else
                    <form wire:submit="submit" class="m2a-card" id="m2-tenant-end-form">
                        <b style="display:block">{{ __('End the tenancy for :tenant?', ['tenant' => $rental->occupant_name]) }}</b>
                        <div>
                            <label for="m2-end-date" class="m2-label">{{ __('End date') }}</label>
                            <input id="m2-end-date" type="date" wire:model="endDate" class="m2-input">
                            @error('endDate') <p class="m2-err">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <span class="m2-label" id="m2-end-outcome-label">{{ __('Outcome') }}</span>
                            <div class="m2-pills" role="group" aria-labelledby="m2-end-outcome-label">
                                @foreach ($outcomes as $value => $label)
                                    <button type="button" wire:click="$set('status', '{{ $value }}')" class="m2-pill" aria-pressed="{{ (string) $status === (string) $value ? 'true' : 'false' }}" id="m2-end-outcome-{{ $value }}">{{ $label }}</button>
                                @endforeach
                            </div>
                            @error('status') <p class="m2-err">{{ $message }}</p> @enderror
                        </div>
                        <label class="m2-row" style="gap:10px;cursor:pointer" for="m2-end-free">
                            <input id="m2-end-free" type="checkbox" wire:model="freeRoom" style="width:20px;height:20px;accent-color:var(--m2-brand)">
                            <span>{{ __('Mark room as available') }}</span>
                        </label>
                        @error('unitId') <p class="m2-err">{{ $message }}</p> @enderror
                        <div class="m2-grid2">
                            <button type="button" wire:click="cancelEnd" class="m2-btn is-ghost">{{ __('Cancel') }}</button>
                            <button type="submit" class="m2-btn is-danger" id="m2-tenant-end-confirm" wire:loading.attr="disabled" wire:target="submit">{{ __('End tenancy') }}</button>
                        </div>
                    </form>
                @endif
            </div>
        @elseif ($step === 'done' && $rental->unit)
            <a href="{{ route('m2.room', $rental->unit) }}" wire:navigate class="m2-btn is-ghost" style="margin-top:16px" id="m2-tenant-back-room">{{ __('Back to room') }}</a>
        @endif
    </main>
</div>
