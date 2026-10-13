@php
    use App\Enums\MoveInReadinessStatus;
    use App\Enums\OccupantRole;
    use App\Enums\RentalStatus;
    use App\Models\Invoice;
    use App\Services\InvoicePdfService;
    use App\Support\Money;

    $unit = $rental->unit;
    $date = fn ($value) => Invoice::displayDate($value, 'd M Y', '');
    $tel = fn (?string $phone) => 'tel:'.preg_replace('/[^\d+]/', '', (string) $phone);

    $badge = fn (?string $color) => match ($color) {
        'success' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
        'warning' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
        'danger' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
        'info' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
        default => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    };

    $genders = ['male' => __('Male'), 'female' => __('Female'), 'other' => __('Other')];

    $roles = [
        OccupantRole::CoTenant->value => __('Co-tenant'),
        OccupantRole::Dependent->value => __('Dependent'),
    ];

    $moveInStatuses = [
        MoveInReadinessStatus::Draft->value => __('Draft'),
        MoveInReadinessStatus::AwaitingPayment->value => __('Awaiting payment'),
        MoveInReadinessStatus::ReadyForMoveIn->value => __('Ready for move-in'),
        MoveInReadinessStatus::Active->value => __('Moved in'),
    ];

    // Label => value rows; empty values are left out, so a sparse tenancy stays short.
    $occupantRows = array_filter([
        __('Phone') => $rental->occupant_phone,
        __('ID card') => $rental->occupant_id_card,
        __('Gender') => $genders[$rental->occupant_gender] ?? null,
        __('Date of birth') => $date($rental->occupant_dob),
        __('Nationality') => $rental->occupant_nationality,
        __('Workplace') => $rental->occupant_workplace,
        __('Address') => $rental->occupant_address,
    ], 'filled');

    $settlement = $rental->depositSettlement;

    $agreementRows = array_filter([
        __('Start date') => $date($rental->start_date),
        __('End date') => $date($rental->end_date),
        __('Monthly rent') => $rental->monthly_rent !== null ? Money::format($rental->monthly_rent, $rental->monthly_rent_currency ?: Money::forRecord($rental)) : null,
        __('Security deposit') => (float) $rental->security_deposit > 0 ? Money::format($rental->security_deposit, $rental->security_deposit_currency ?: Money::forRecord($rental)) : null,
        __('Move-in status') => $rental->move_in_status ? ($moveInStatuses[$rental->move_in_status->value] ?? null) : null,
        __('Moved in') => $rental->moved_in_at ? $date($rental->moved_in_at) : null,
        __('Promised payment date') => $date($rental->move_in_promised_payment_date),
        __('Signed') => $rental->signed_at ? $date($rental->signed_at) : null,
        __('Agreement reference') => $rental->lease_agreement,
        __('Moved out') => $date($rental->move_out_date) ?: ($rental->moved_out_at ? $date($rental->moved_out_at) : null),
        __('Reason') => $rental->move_out_reason,
        __('Deposit refund') => $settlement ? Money::format($settlement->refund_amount, $settlement->currency ?: $rental->security_deposit_currency) : null,
    ], 'filled');

    $hasEmergency = filled($rental->emergency_contact_name) || filled($rental->emergency_contact_phone);
    $hasGuarantor = filled($rental->guarantor_name) || filled($rental->guarantor_phone) || filled($rental->guarantor_id_number) || filled($rental->guarantor_address);

    $username = $rental->tenant?->username;
    $sectionTitle = 'mb-2 text-xs font-bold tracking-wider text-slate-500 uppercase dark:text-slate-400';
@endphp

<div x-data="rwMobileTenant(@js(__('Something went wrong. Please try again.')))">
    {{-- ── Header ── --}}
    <div class="flex items-center gap-1 pt-3">
        <a
            href="{{ route('mobile.tenants') }}"
            wire:navigate
            class="-ml-2 flex size-11 shrink-0 items-center justify-center rounded-full text-slate-600 transition active:bg-slate-200 dark:text-slate-300 dark:active:bg-slate-800"
            aria-label="{{ __('Back to tenants') }}"
            id="rw-m-tenant-back"
        >
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" class="size-6" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
        </a>
        <span class="text-sm font-semibold text-slate-500 dark:text-slate-400">{{ __('Tenants') }}</span>
    </div>

    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="rw-m-title truncate pt-1 pb-1">{{ $primaryName }}</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ __('Room') }} {{ $unit?->room_number ?? '—' }}
            </p>
        </div>
        <span class="rw-m-badge {{ $badge($rental->status?->getColor()) }} mt-3 shrink-0" id="rw-m-tenant-status">{{ $rental->status?->getLabel() }}</span>
    </div>

    {{-- ── Quick actions ── --}}
    <div class="mt-4 grid grid-cols-3 gap-2">
        @if ($rental->occupant_phone)
            <a href="{{ $tel($rental->occupant_phone) }}" class="rw-m-btn rw-m-btn-primary flex-col gap-0.5 py-2 text-xs" id="rw-m-tenant-call">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-5" aria-hidden="true"><path fill-rule="evenodd" d="M1.5 4.5a3 3 0 0 1 3-3h1.372c.86 0 1.61.586 1.819 1.42l1.105 4.423a1.875 1.875 0 0 1-.694 1.955l-1.293.97c-.135.101-.164.249-.126.352a11.285 11.285 0 0 0 6.697 6.697c.103.038.25.009.352-.126l.97-1.293a1.875 1.875 0 0 1 1.955-.694l4.423 1.105c.834.209 1.42.959 1.42 1.82V19.5a3 3 0 0 1-3 3h-2.25C8.552 22.5 1.5 15.448 1.5 6.75V4.5Z" clip-rule="evenodd" /></svg>
                {{ __('Call') }}
            </a>
        @else
            <span class="rw-m-btn rw-m-btn-secondary flex-col gap-0.5 py-2 text-xs opacity-50" aria-disabled="true">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-5" aria-hidden="true"><path fill-rule="evenodd" d="M1.5 4.5a3 3 0 0 1 3-3h1.372c.86 0 1.61.586 1.819 1.42l1.105 4.423a1.875 1.875 0 0 1-.694 1.955l-1.293.97c-.135.101-.164.249-.126.352a11.285 11.285 0 0 0 6.697 6.697c.103.038.25.009.352-.126l.97-1.293a1.875 1.875 0 0 1 1.955-.694l4.423 1.105c.834.209 1.42.959 1.42 1.82V19.5a3 3 0 0 1-3 3h-2.25C8.552 22.5 1.5 15.448 1.5 6.75V4.5Z" clip-rule="evenodd" /></svg>
                {{ __('No phone') }}
            </span>
        @endif

        @can('update', $rental)
            <button type="button" x-on:click="$dispatch('rw-m-sheet-open', { name: 'tenant-edit' })" class="rw-m-btn rw-m-btn-secondary flex-col gap-0.5 py-2 text-xs" id="rw-m-tenant-edit">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="size-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" /></svg>
                {{ __('Edit') }}
            </button>
        @endcan

        @if ($rental->unit_id)
            <a href="{{ route('mobile.room', $rental->unit_id) }}" wire:navigate class="rw-m-btn rw-m-btn-secondary flex-col gap-0.5 py-2 text-xs" id="rw-m-tenant-room">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="size-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                {{ __('Open room') }}
            </a>
        @endif
    </div>

    <div class="mt-4 space-y-4">
        {{-- ── Balance + recent invoices ── --}}
        <section class="rw-m-card p-0" aria-labelledby="rw-m-tenant-balance-title">
            <div class="flex items-center justify-between gap-3 p-4">
                <h2 id="rw-m-tenant-balance-title" class="text-sm font-semibold text-slate-500 dark:text-slate-400">{{ __('Total due') }}</h2>
                <p class="text-xl font-bold {{ $hasBalance ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}" id="rw-m-tenant-total-due">{{ $totalDue }}</p>
            </div>

            @if ($recentInvoices->isNotEmpty())
                <ul class="divide-y divide-slate-100 border-t border-slate-100 dark:divide-slate-800 dark:border-slate-800">
                    @foreach ($recentInvoices as $invoice)
                        @php
                            $balance = (float) $invoice->balance;
                            $card = [
                                'id' => $invoice->id,
                                'number' => $invoice->invoice_number,
                                'slipUrl' => route('invoices.slip', $invoice),
                                'thermalStreamUrl' => route('invoices.pdf', ['invoice' => $invoice->id, 'size' => '58mm', 'mode' => 'stream']),
                                'thermalDownloadUrl' => route('invoices.pdf', ['invoice' => $invoice->id, 'size' => '58mm']),
                                'pdfStreamUrl' => route('invoices.pdf', ['invoice' => $invoice->id, 'mode' => 'stream']),
                                'pdfDownloadUrl' => route('invoices.pdf', ['invoice' => $invoice->id]),
                                'filename' => InvoicePdfService::filename($invoice, 'pdf'),
                            ];
                        @endphp
                        <li wire:key="rw-m-tenant-invoice-{{ $invoice->id }}">
                            <button
                                type="button"
                                x-on:click="openInvoice(@js($card))"
                                class="flex min-h-14 w-full items-center justify-between gap-3 px-4 py-3 text-left transition-colors active:bg-slate-50 dark:active:bg-slate-800/60"
                                id="rw-m-tenant-invoice-{{ $invoice->id }}"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate text-sm font-semibold">{{ $invoice->invoice_number }}</span>
                                    <span class="block truncate text-xs text-slate-500 dark:text-slate-400">{{ $invoice->billingPeriodLabel() }}</span>
                                </span>
                                <span class="flex shrink-0 flex-col items-end gap-1">
                                    <span class="text-sm font-bold {{ $balance > 0.009 ? 'text-red-600 dark:text-red-400' : 'text-slate-700 dark:text-slate-200' }}">
                                        {{ Money::formatForRecord($balance > 0.009 ? $balance : $invoice->amount_due, $invoice) }}
                                    </span>
                                    <span class="rw-m-badge {{ $badge($invoice->payment_status?->getColor()) }}">{{ $invoice->payment_status?->getLabel() ?? '—' }}</span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>

                <a
                    href="{{ route('mobile.invoices', array_filter(['filter' => 'all', 'search' => $rental->occupant_name ?: $unit?->room_number])) }}"
                    wire:navigate
                    class="flex min-h-12 items-center justify-center gap-1 border-t border-slate-100 text-sm font-semibold text-brand-700 transition-colors active:bg-slate-50 dark:border-slate-800 dark:text-emerald-400 dark:active:bg-slate-800/60"
                    id="rw-m-tenant-all-invoices"
                >
                    {{ $invoiceCount > $recentInvoices->count() ? __('All :count invoices', ['count' => $invoiceCount]) : __('Open in Invoices') }}
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
                </a>
            @else
                <p class="border-t border-slate-100 px-4 py-4 text-sm text-slate-500 dark:border-slate-800 dark:text-slate-400">{{ __('No invoices yet.') }}</p>
            @endif
        </section>

        {{-- ── Occupant ── --}}
        <section class="rw-m-card" aria-labelledby="rw-m-tenant-occupant-title">
            <h2 id="rw-m-tenant-occupant-title" class="{{ $sectionTitle }}">{{ __('Tenant / Occupant') }}</h2>
            @if ($occupantRows)
                <dl class="grid grid-cols-2 gap-x-3 gap-y-3">
                    @foreach ($occupantRows as $label => $value)
                        <div class="{{ $label === __('Address') ? 'col-span-2' : '' }} min-w-0">
                            <dt class="text-xs text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                            <dd class="font-semibold break-words">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            @else
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('No details yet.') }}</p>
            @endif

            @if ($hasEmergency)
                <div class="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Emergency contact') }}</p>
                    <div class="flex items-center justify-between gap-3">
                        <p class="min-w-0 font-semibold break-words">
                            {{ $rental->emergency_contact_name }}
                            @if ($rental->emergency_contact_relationship)
                                <span class="font-normal text-slate-500 dark:text-slate-400">({{ $rental->emergency_contact_relationship }})</span>
                            @endif
                            @if ($rental->emergency_contact_phone)
                                <span class="block font-normal">{{ $rental->emergency_contact_phone }}</span>
                            @endif
                        </p>
                        @if ($rental->emergency_contact_phone)
                            <a href="{{ $tel($rental->emergency_contact_phone) }}" class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700 active:bg-brand-100 dark:bg-emerald-500/15 dark:text-emerald-300" aria-label="{{ __('Call :name', ['name' => $rental->emergency_contact_name ?: __('Emergency contact')]) }}" id="rw-m-tenant-call-emergency">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-5" aria-hidden="true"><path fill-rule="evenodd" d="M1.5 4.5a3 3 0 0 1 3-3h1.372c.86 0 1.61.586 1.819 1.42l1.105 4.423a1.875 1.875 0 0 1-.694 1.955l-1.293.97c-.135.101-.164.249-.126.352a11.285 11.285 0 0 0 6.697 6.697c.103.038.25.009.352-.126l.97-1.293a1.875 1.875 0 0 1 1.955-.694l4.423 1.105c.834.209 1.42.959 1.42 1.82V19.5a3 3 0 0 1-3 3h-2.25C8.552 22.5 1.5 15.448 1.5 6.75V4.5Z" clip-rule="evenodd" /></svg>
                            </a>
                        @endif
                    </div>
                </div>
            @endif

            @if ($hasGuarantor)
                <div class="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Guarantor') }}</p>
                    <p class="font-semibold break-words">{{ $rental->guarantor_name ?: '—' }}</p>
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        {{ collect([$rental->guarantor_phone, $rental->guarantor_id_number ? __('ID number').': '.$rental->guarantor_id_number : null, $rental->guarantor_address])->filter()->implode(' · ') }}
                    </p>
                </div>
            @endif
        </section>

        {{-- ── Co-occupants ── --}}
        @if ($coOccupants->isNotEmpty())
            <section class="rw-m-card p-0" aria-labelledby="rw-m-tenant-occupants-title">
                <h2 id="rw-m-tenant-occupants-title" class="{{ $sectionTitle }} px-4 pt-4">{{ __('Occupants') }}</h2>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($coOccupants as $occupant)
                        <li class="flex min-h-14 items-center justify-between gap-3 px-4 py-2" wire:key="rw-m-tenant-occupant-{{ $occupant->id }}" id="rw-m-tenant-occupant-{{ $occupant->id }}">
                            <span class="min-w-0">
                                <span class="block truncate font-semibold">{{ $occupant->occupant_name ?: '—' }}</span>
                                <span class="block truncate text-xs text-slate-500 dark:text-slate-400">
                                    {{ collect([$roles[$occupant->role?->value] ?? null, $occupant->occupant_phone])->filter()->implode(' · ') }}
                                </span>
                            </span>
                            @if ($occupant->occupant_phone)
                                <a href="{{ $tel($occupant->occupant_phone) }}" class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-50 text-brand-700 active:bg-brand-100 dark:bg-emerald-500/15 dark:text-emerald-300" aria-label="{{ __('Call :name', ['name' => $occupant->occupant_name ?: __('Tenant')]) }}">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-5" aria-hidden="true"><path fill-rule="evenodd" d="M1.5 4.5a3 3 0 0 1 3-3h1.372c.86 0 1.61.586 1.819 1.42l1.105 4.423a1.875 1.875 0 0 1-.694 1.955l-1.293.97c-.135.101-.164.249-.126.352a11.285 11.285 0 0 0 6.697 6.697c.103.038.25.009.352-.126l.97-1.293a1.875 1.875 0 0 1 1.955-.694l4.423 1.105c.834.209 1.42.959 1.42 1.82V19.5a3 3 0 0 1-3 3h-2.25C8.552 22.5 1.5 15.448 1.5 6.75V4.5Z" clip-rule="evenodd" /></svg>
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- ── ID card photos (Rental's id_cards collection — same one RentalResource and the add-tenant upload use) ── --}}
        @if ($photos->isNotEmpty())
            <section class="rw-m-card" aria-labelledby="rw-m-tenant-photos-title">
                <h2 id="rw-m-tenant-photos-title" class="{{ $sectionTitle }}">{{ __('ID card photos') }}</h2>
                <div class="grid grid-cols-3 gap-2">
                    @foreach ($photos as $media)
                        <button
                            type="button"
                            x-on:click="openPhoto(@js($media->getUrl()))"
                            class="aspect-square overflow-hidden rounded-xl border border-slate-200 transition active:scale-95 dark:border-slate-700"
                            aria-label="{{ __('Open ID card photo') }}"
                            id="rw-m-tenant-photo-{{ $media->id }}"
                        >
                            <img src="{{ $media->getUrl() }}" class="size-full object-cover" alt="{{ __('ID card photo') }}" loading="lazy">
                        </button>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ── Agreement ── --}}
        <section class="rw-m-card" aria-labelledby="rw-m-tenant-agreement-title">
            <h2 id="rw-m-tenant-agreement-title" class="{{ $sectionTitle }}">{{ __('Agreement') }}</h2>
            <dl class="grid grid-cols-2 gap-x-3 gap-y-3">
                @foreach ($agreementRows as $label => $value)
                    <div class="min-w-0">
                        <dt class="text-xs text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                        <dd class="font-semibold break-words">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
            @if (filled($rental->notes))
                <div class="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('Notes') }}</p>
                    <p class="text-sm whitespace-pre-line">{{ $rental->notes }}</p>
                </div>
            @endif
            @if (filled($rental->terms_conditions))
                <details class="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
                    <summary class="flex min-h-11 cursor-pointer items-center text-sm font-semibold">{{ __('Terms and conditions') }}</summary>
                    <p class="text-sm whitespace-pre-line text-slate-600 dark:text-slate-300">{{ $rental->terms_conditions }}</p>
                </details>
            @endif
        </section>

        {{-- ── Tenant portal login ── --}}
        <section class="rw-m-card" aria-labelledby="rw-m-tenant-login-title">
            <h2 id="rw-m-tenant-login-title" class="{{ $sectionTitle }}">{{ __('Tenant portal login') }}</h2>

            @if ($username)
                <div class="flex items-center justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800">
                    <span class="min-w-0">
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ __('Username') }}</span>
                        <span class="block truncate font-mono text-sm" id="rw-m-tenant-username">{{ $username }}</span>
                    </span>
                    <button type="button" x-on:click="copy(@js($username), 'username')" class="rw-m-btn rw-m-btn-secondary min-h-11 shrink-0 px-3 text-sm" id="rw-m-tenant-copy-username">
                        <span x-show="copied !== 'username'">{{ __('Copy') }}</span>
                        <span x-show="copied === 'username'" x-cloak>{{ __('Copied!') }}</span>
                    </button>
                </div>
            @else
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('This tenant has no login of their own yet.') }}</p>
            @endif

            @if ($newPassword)
                {{-- Shown exactly once, right after (re)generating — same rule as
                     RentalResource\Actions\TenantLogin: it can't be retrieved again. --}}
                <div class="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-900/20" id="rw-m-tenant-new-password">
                    <p class="text-xs font-semibold text-amber-800 dark:text-amber-300">{{ __('New password — copy it now, it will not be shown again.') }}</p>
                    <div class="mt-2 flex items-center justify-between gap-2 rounded-lg bg-white px-3 py-2 dark:bg-slate-900">
                        <span class="truncate font-mono text-sm">{{ $newPassword }}</span>
                        <button type="button" x-on:click="copy(@js($newPassword), 'password')" class="rw-m-btn rw-m-btn-secondary min-h-11 shrink-0 px-3 text-sm" id="rw-m-tenant-copy-password">
                            <span x-show="copied !== 'password'">{{ __('Copy') }}</span>
                            <span x-show="copied === 'password'" x-cloak>{{ __('Copied!') }}</span>
                        </button>
                    </div>
                    <button
                        type="button"
                        x-on:click="copy(@js(__('Username').': '.$newUsername."\n".__('Password').': '.$newPassword."\n".route('portal.dashboard')), 'both')"
                        class="rw-m-btn rw-m-btn-secondary mt-2 w-full min-h-11 text-sm"
                        id="rw-m-tenant-copy-both"
                    >
                        <span x-show="copied !== 'both'">{{ __('Copy username and password') }}</span>
                        <span x-show="copied === 'both'" x-cloak>{{ __('Copied!') }}</span>
                    </button>
                </div>
            @else
                @can('update', $rental)
                    <button
                        type="button"
                        x-on:click="if (! @js((bool) $username) || confirm(@js(__('This replaces the current password — the tenant will need the new one to sign in. Continue?')))) { $wire.resetLogin() }"
                        wire:loading.attr="disabled"
                        wire:target="resetLogin"
                        class="rw-m-btn rw-m-btn-secondary mt-3 w-full"
                        id="rw-m-tenant-reset-login"
                    >
                        <span wire:loading.remove wire:target="resetLogin">{{ $username ? __('Reset password') : __('Create login') }}</span>
                        <span wire:loading wire:target="resetLogin">{{ __('Loading…') }}</span>
                    </button>
                @endcan
            @endif
        </section>

        {{-- ── End tenancy (its own /m screen) ── --}}
        @if ($rental->status === RentalStatus::Active && $rental->unit_id)
            @can('update', $rental)
                <a
                    href="{{ route('mobile.room.end-tenancy', $rental->unit_id) }}"
                    wire:navigate
                    class="rw-m-btn w-full border border-red-200 bg-white text-red-700 active:bg-red-50 dark:border-red-900/60 dark:bg-slate-900 dark:text-red-400 dark:active:bg-red-950/40"
                    id="rw-m-tenant-end"
                >{{ __('End tenancy') }}</a>
            @endcan
        @endif
    </div>

    {{-- ── Edit tenant sheet ── --}}
    @can('update', $rental)
        <x-mobile.sheet name="tenant-edit" :label="__('Edit tenant')">
            @livewire(\App\Livewire\Mobile\TenantEdit::class, ['rentalId' => $rental->id], key('rw-m-tenant-edit-'.$rental->id))
        </x-mobile.sheet>
    @endcan

    {{-- ── ID photo, full size ── --}}
    <x-mobile.sheet name="tenant-photo" :label="__('ID card photo')">
        <div class="px-3 pt-1">
            <img x-bind:src="photo" x-show="photo" class="mx-auto max-h-[75dvh] w-auto rounded-xl object-contain" alt="{{ __('ID card photo') }}">
        </div>
        <div class="grid grid-cols-2 gap-2 px-4 pt-4">
            <a x-bind:href="photo" target="_blank" rel="noopener" class="rw-m-btn rw-m-btn-secondary text-sm" id="rw-m-tenant-photo-open">{{ __('Open original') }}</a>
            <button type="button" x-on:click="$dispatch('rw-m-sheet-close', { name: 'tenant-photo' })" class="rw-m-btn rw-m-btn-secondary text-sm" id="rw-m-tenant-photo-close">{{ __('Close') }}</button>
        </div>
    </x-mobile.sheet>

    {{-- ── Invoice slip ── --}}
    <x-mobile.sheet name="tenant-invoice" :label="__('Invoice')">
        <div class="flex items-center justify-between gap-3 px-5 pt-2 pb-3">
            <p class="text-lg font-bold" x-text="invoice?.number"></p>
        </div>

        <div x-show="slipLoading" class="mx-4 h-72 animate-pulse rounded-2xl bg-slate-100 dark:bg-slate-800" aria-busy="true"></div>
        {{-- Filled client-side with the invoices.slip fragment; Livewire must never morph it away. --}}
        <div id="rw-m-tenant-slip" wire:ignore x-show="! slipLoading" class="rw-m-slip px-2"></div>

        <div class="grid grid-cols-2 gap-2 px-4 pt-4">
            <button
                type="button"
                onclick="rwPrintInvoice58mm(this)"
                x-bind:data-stream-url="invoice?.thermalStreamUrl"
                x-bind:data-download-url="invoice?.thermalDownloadUrl"
                x-bind:data-filename="invoice?.filename"
                data-preparing="{{ __('Preparing…') }}"
                class="rw-m-btn rw-m-btn-secondary text-sm"
                id="rw-m-tenant-invoice-58mm"
            ><span data-label>{{ __('Print 58mm') }}</span></button>
            <button
                type="button"
                onclick="rwPrintInvoice(this)"
                x-bind:data-stream-url="invoice?.pdfStreamUrl"
                x-bind:data-download-url="invoice?.pdfDownloadUrl"
                x-bind:data-filename="invoice?.filename"
                data-preparing="{{ __('Preparing…') }}"
                class="rw-m-btn rw-m-btn-secondary text-sm"
                id="rw-m-tenant-invoice-pdf"
            ><span data-label>PDF</span></button>
        </div>
    </x-mobile.sheet>
</div>
