@php
    use App\Livewire\Mobile\AddTenant;
    use App\Services\MeterUtilities;
    use App\Support\Money;
    use App\Support\UtilityCatalog;

    $label = 'mb-1 block text-sm font-semibold';
    $error = 'mt-1 text-sm text-red-600 dark:text-red-400';
    $muted = 'text-sm text-slate-500 dark:text-slate-400';
    $required = '<span class="text-red-500" aria-hidden="true">*</span>';
@endphp

<div id="rw-m-add-tenant">
    {{-- ── Header ── --}}
    <div class="flex items-center gap-1">
        <a
            href="{{ route('mobile.room', $unit) }}"
            wire:navigate
            class="-ml-2 inline-flex size-11 shrink-0 items-center justify-center rounded-full text-slate-600 transition active:scale-95 active:bg-slate-200 dark:text-slate-300 dark:active:bg-slate-800"
            aria-label="{{ __('Room :room', ['room' => $unit->room_number]) }}"
            id="rw-m-add-tenant-back"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.25" stroke="currentColor" class="size-6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
        </a>
        <h1 class="rw-m-title min-w-0 flex-1 truncate">{{ __('Add tenant') }}</h1>
    </div>
    <p class="-mt-2 mb-3 {{ $muted }}">{{ __('Room :room', ['room' => $unit->room_number]) }} · {{ $unit->property?->name }}</p>

    @if ($step === 'done' && $result)
        {{-- ── Done: the login details, shown once ── --}}
        <section class="rw-m-card text-center" id="rw-m-add-tenant-done" x-data="{ copied: null, copy(text, key) { navigator.clipboard?.writeText(text).then(() => { this.copied = key; setTimeout(() => { if (this.copied === key) this.copied = null; }, 1500); }); } }">
            <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="size-7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
            </div>
            <h2 class="mt-3 text-xl font-bold">{{ __('Tenancy created') }}</h2>

            <dl class="mt-4 space-y-2 text-left">
                <div class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/60">
                    <dt class="{{ $muted }}">{{ __('Room') }}</dt>
                    <dd class="font-semibold">{{ $result['room_number'] }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-4 py-3 dark:bg-slate-800/60">
                    <dt class="{{ $muted }}">{{ __('Tenant') }}</dt>
                    <dd class="truncate font-semibold">{{ $result['occupant_name'] }}</dd>
                </div>
                @if ($result['username'])
                    <div class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-4 py-2 dark:bg-slate-800/60">
                        <div class="min-w-0">
                            <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Login username') }}</dt>
                            <dd class="truncate font-mono font-semibold" id="rw-m-add-tenant-username">{{ $result['username'] }}</dd>
                        </div>
                        <button type="button" x-on:click="copy(@js($result['username']), 'username')" class="rw-m-btn rw-m-btn-secondary min-h-10 shrink-0 px-3 text-sm">
                            <span x-show="copied !== 'username'">{{ __('Copy') }}</span>
                            <span x-show="copied === 'username'" x-cloak>{{ __('Copied!') }}</span>
                        </button>
                    </div>
                @endif
                @if ($result['password'])
                    <div class="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-4 py-2 dark:bg-slate-800/60">
                        <div class="min-w-0">
                            <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('Login password') }}</dt>
                            <dd class="truncate font-mono font-semibold" id="rw-m-add-tenant-password">{{ $result['password'] }}</dd>
                        </div>
                        <button type="button" x-on:click="copy(@js($result['password']), 'password')" class="rw-m-btn rw-m-btn-secondary min-h-10 shrink-0 px-3 text-sm">
                            <span x-show="copied !== 'password'">{{ __('Copy') }}</span>
                            <span x-show="copied === 'password'" x-cloak>{{ __('Copied!') }}</span>
                        </button>
                    </div>
                @endif
            </dl>

            @if ($result['password'])
                <p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ __('Save this password now — it is shown only once.') }}</p>
            @endif

            <div class="mt-5 grid gap-2">
                <a href="{{ route('mobile.tenant', $result['rental_id']) }}" wire:navigate class="rw-m-btn rw-m-btn-primary" id="rw-m-add-tenant-view">{{ __('View tenant') }}</a>
                <a href="{{ route('mobile.room', $unit) }}" wire:navigate class="rw-m-btn rw-m-btn-secondary" id="rw-m-add-tenant-room">{{ __('Back to room') }}</a>
            </div>
        </section>
    @elseif (! $available)
        {{-- ── The room can't take a tenant (occupied, under maintenance, ...) ── --}}
        <section class="rw-m-card text-center" id="rw-m-add-tenant-unavailable">
            <p class="font-semibold">{{ __('This room is not available for a new tenant.') }}</p>
            <p class="mt-1 {{ $muted }}">{{ __('Room status: :status', ['status' => $unit->status->getLabel()]) }}</p>
            <div class="mt-4 grid gap-2">
                <a href="{{ route('mobile.room', $unit) }}" wire:navigate class="rw-m-btn rw-m-btn-primary">{{ __('Back to room') }}</a>
                <a href="{{ route('mobile.rooms') }}" wire:navigate class="rw-m-btn rw-m-btn-secondary">{{ __('Rooms') }}</a>
            </div>
        </section>
    @else
        <form wire:submit="submit" class="space-y-3" id="rw-m-add-tenant-form" novalidate>
            {{-- ── Tenant ── --}}
            <section class="rw-m-card space-y-4">
                <h2 class="text-base font-bold">{{ __('Tenant') }}</h2>

                <div>
                    <label for="rw-m-at-name" class="{{ $label }}">{{ __('Full name') }} {!! $required !!}</label>
                    <input type="text" id="rw-m-at-name" wire:model="occupantName" class="rw-m-input" placeholder="{{ __('Tenant full name') }}" autocomplete="name" enterkeyhint="next" required>
                    @error('occupantName') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="rw-m-at-phone" class="{{ $label }}">{{ __('Phone') }}</label>
                    <input type="tel" id="rw-m-at-phone" wire:model="occupantPhone" class="rw-m-input" placeholder="012 345 678" inputmode="tel" autocomplete="tel" enterkeyhint="next">
                    @error('occupantPhone') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="rw-m-at-id-card" class="{{ $label }}">{{ __('ID card') }}</label>
                        <input type="text" id="rw-m-at-id-card" wire:model="occupantIdCard" class="rw-m-input" placeholder="{{ __('ID card number') }}" autocomplete="off" enterkeyhint="next">
                        @error('occupantIdCard') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="rw-m-at-gender" class="{{ $label }}">{{ __('Gender') }}</label>
                        <select id="rw-m-at-gender" wire:model="occupantGender" class="rw-m-input">
                            <option value="">{{ __('Select gender') }}</option>
                            <option value="male">{{ __('Male') }}</option>
                            <option value="female">{{ __('Female') }}</option>
                            <option value="other">{{ __('Other') }}</option>
                        </select>
                        @error('occupantGender') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="rw-m-at-dob" class="{{ $label }}">{{ __('Date of birth') }}</label>
                        <input type="date" id="rw-m-at-dob" wire:model="occupantDob" class="rw-m-input">
                        @error('occupantDob') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="rw-m-at-nationality" class="{{ $label }}">{{ __('Nationality') }}</label>
                        <input type="text" id="rw-m-at-nationality" wire:model="occupantNationality" class="rw-m-input" placeholder="{{ __('e.g. Khmer, Vietnamese') }}" enterkeyhint="next">
                        @error('occupantNationality') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="rw-m-at-workplace" class="{{ $label }}">{{ __('Workplace') }}</label>
                    <input type="text" id="rw-m-at-workplace" wire:model="occupantWorkplace" class="rw-m-input" placeholder="{{ __('e.g. company name') }}" autocomplete="organization" enterkeyhint="next">
                    @error('occupantWorkplace') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="rw-m-at-address" class="{{ $label }}">{{ __('Address') }}</label>
                    <textarea id="rw-m-at-address" wire:model="occupantAddress" class="rw-m-input" rows="2" autocomplete="street-address"></textarea>
                    @error('occupantAddress') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
            </section>

            {{-- ── ID card photos: shrunk in the browser, then uploaded one by one ── --}}
            <section class="rw-m-card" x-data="rwMobileIdPhotos({{ AddTenant::MAX_PHOTOS }}, @js(__('Something went wrong. Please try again.')))" id="rw-m-at-photos">
                <h2 class="text-base font-bold">{{ __('ID card photos') }}</h2>
                <p class="mt-1 {{ $muted }}">{{ __('Front/back of national ID, passport, etc.') }}</p>

                <div class="mt-3 flex flex-wrap gap-3">
                    @foreach ($idCardPhotos as $index => $photo)
                        <div class="relative size-24 shrink-0 overflow-hidden rounded-xl border border-slate-200 dark:border-slate-700" wire:key="rw-m-at-photo-{{ $index }}">
                            <img src="{{ $photo->temporaryUrl() }}" class="size-full object-cover" alt="{{ __('ID card photo') }}">
                            <button
                                type="button"
                                wire:click="removeIdCardPhoto({{ $index }})"
                                class="absolute top-1 right-1 flex size-8 items-center justify-center rounded-full bg-slate-950/60 text-white active:scale-95"
                                aria-label="{{ __('Remove') }}"
                                id="rw-m-at-photo-remove-{{ $index }}"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" /></svg>
                            </button>
                        </div>
                    @endforeach

                    @if (count($idCardPhotos) < AddTenant::MAX_PHOTOS)
                        <label
                            for="rw-m-at-photo-input"
                            class="flex size-24 shrink-0 cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-slate-300 text-slate-400 transition active:scale-95 active:bg-slate-50 dark:border-slate-600 dark:text-slate-500 dark:active:bg-slate-800"
                            x-bind:class="uploading && 'pointer-events-none opacity-60'"
                            id="rw-m-at-photo-add"
                        >
                            <svg x-show="! uploading" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-7" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" /></svg>
                            <svg x-show="uploading" x-cloak class="size-6 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4Z"></path></svg>
                            <span class="text-xs font-semibold" x-text="uploading ? @js(__('Uploading…')) : @js(__('Add photo'))">{{ __('Add photo') }}</span>
                        </label>
                        <input type="file" id="rw-m-at-photo-input" accept="image/*" capture="environment" multiple class="sr-only" x-on:change="pick($event)">
                    @endif
                </div>

                @error('newIdCardPhotos.*') <p class="{{ $error }}">{{ $message }}</p> @enderror
                @error('idCardPhotos') <p class="{{ $error }}">{{ $message }}</p> @enderror
                @error('idCardPhotos.*') <p class="{{ $error }}">{{ $message }}</p> @enderror
            </section>

            {{-- ── Emergency contact ── --}}
            <section class="rw-m-card space-y-4">
                <h2 class="text-base font-bold">{{ __('Emergency contact') }}</h2>

                <div>
                    <label for="rw-m-at-ec-name" class="{{ $label }}">{{ __('Name') }}</label>
                    <input type="text" id="rw-m-at-ec-name" wire:model="emergencyContactName" class="rw-m-input" autocomplete="off" enterkeyhint="next">
                    @error('emergencyContactName') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="rw-m-at-ec-phone" class="{{ $label }}">{{ __('Phone') }}</label>
                        <input type="tel" id="rw-m-at-ec-phone" wire:model="emergencyContactPhone" class="rw-m-input" inputmode="tel" autocomplete="off" enterkeyhint="next">
                        @error('emergencyContactPhone') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="rw-m-at-ec-relationship" class="{{ $label }}">{{ __('Relationship') }}</label>
                        <input type="text" id="rw-m-at-ec-relationship" wire:model="emergencyContactRelationship" class="rw-m-input" autocomplete="off" enterkeyhint="next">
                        @error('emergencyContactRelationship') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            {{-- ── Agreement ── --}}
            <section class="rw-m-card space-y-4">
                <h2 class="text-base font-bold">{{ __('Agreement') }}</h2>

                <div>
                    <label for="rw-m-at-start" class="{{ $label }}">{{ __('Start date') }} {!! $required !!}</label>
                    <input type="date" id="rw-m-at-start" wire:model="startDate" class="rw-m-input" required>
                    @error('startDate') <p class="{{ $error }}">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="rw-m-at-rent" class="{{ $label }}">{{ __('Monthly rent') }} {!! $required !!}</label>
                        <input type="text" inputmode="decimal" id="rw-m-at-rent" wire:model="monthlyRent" class="rw-m-input" placeholder="0.00" autocomplete="off" enterkeyhint="next" required>
                        @error('monthlyRent') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="rw-m-at-deposit" class="{{ $label }}">{{ __('Security deposit') }}</label>
                        <input type="text" inputmode="decimal" id="rw-m-at-deposit" wire:model="securityDeposit" class="rw-m-input" placeholder="0.00" autocomplete="off" enterkeyhint="next">
                        @error('securityDeposit') <p class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Listed rent: :amount', ['amount' => Money::formatForRecord($unit->rent_amount, $unit)]) }}</p>
            </section>

            {{-- ── Move-in meter readings ── --}}
            @if ($meteredUtilities->isNotEmpty())
                <section class="rw-m-card space-y-4" id="rw-m-at-readings">
                    <div>
                        <h2 class="text-base font-bold">{{ __('Move-in meter readings') }} <span class="font-normal text-slate-400">({{ __('optional') }})</span></h2>
                        <p class="mt-1 {{ $muted }}">{{ __('Read the meters now so the tenant is only billed from this point.') }}</p>
                    </div>

                    @foreach ($meteredUtilities as $utility)
                        <div>
                            <label for="rw-m-at-reading-{{ $utility->id }}" class="{{ $label }}">
                                {{ UtilityCatalog::label($utility->name) }}
                                @if ($utility->unit_of_measure)<span class="font-normal text-slate-400">({{ $utility->unit_of_measure }})</span>@endif
                            </label>
                            <input type="text" inputmode="decimal" id="rw-m-at-reading-{{ $utility->id }}" wire:model="moveInReadings.{{ $utility->id }}" class="rw-m-input" placeholder="0.000" autocomplete="off" enterkeyhint="{{ $loop->last ? 'done' : 'next' }}">
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ MeterUtilities::readingHint($unit->id, $utility->id, $utility->unit_of_measure) }}</p>
                            @error("moveInReadings.{$utility->id}") <p class="{{ $error }}">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </section>
            @endif

            @error('unitId')
                <div class="rounded-xl bg-red-50 p-3 text-sm font-semibold text-red-700 dark:bg-red-500/10 dark:text-red-300" role="alert" id="rw-m-at-unit-error">{{ $message }}</div>
            @enderror

            @if ($errors->any())
                <p class="text-center text-sm text-red-600 dark:text-red-400" role="alert">{{ __('Please check the highlighted fields.') }}</p>
            @endif

            <button type="submit" class="rw-m-btn rw-m-btn-primary w-full" wire:loading.attr="disabled" wire:target="submit,newIdCardPhotos" id="rw-m-add-tenant-submit">
                <span wire:loading.remove wire:target="submit">{{ __('Confirm') }}</span>
                <span wire:loading wire:target="submit">{{ __('Saving…') }}</span>
            </button>
        </form>
    @endif
</div>
