@php use App\Enums\SubscriptionAccess; @endphp

<x-filament-panels::page>
    @if(! $subscription)
        <div class="rounded-2xl border border-dashed border-gray-300 p-8 text-center dark:border-gray-700">
            <x-heroicon-o-credit-card class="mx-auto h-10 w-10 text-gray-400" />
            <p class="mt-3 font-semibold">{{ __('No subscription') }}</p>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('You do not have an active subscription yet. Please contact the administrator.') }}</p>
        </div>
    @else
        {{-- Status banner --}}
        @php $access = $this->getAccess(); @endphp
        @if($access === SubscriptionAccess::PastDue)
            <div class="rounded-3xl border border-amber-200 bg-amber-50 p-4 text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100">
                <div class="flex items-start gap-3">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0" />
                    <div>
                        <p class="font-semibold">{{ __('Subscription past due') }}</p>
                        <p class="mt-1 text-sm">{{ __('Your subscription ended on :date. Please renew to continue using all features.', ['date' => $subscription->ends_at?->format('Y-m-d')]) }}</p>
                    </div>
                </div>
            </div>
        @elseif($access === SubscriptionAccess::ReadOnly)
            <div class="rounded-3xl border border-red-200 bg-red-50 p-4 text-red-900 dark:border-red-900/40 dark:bg-red-950/30 dark:text-red-100">
                <div class="flex items-start gap-3">
                    <x-heroicon-o-x-circle class="mt-0.5 h-5 w-5 shrink-0" />
                    <div>
                        <p class="font-semibold">{{ __('Subscription expired') }}</p>
                        <p class="mt-1 text-sm">{{ __('Your subscription has expired. You are in read-only mode. Please contact the administrator to restore access.') }}</p>
                    </div>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            {{-- Current plan card --}}
            <x-filament::section>
                <x-slot:heading>
                    <div class="flex items-center justify-between">
                        <span>{{ __('Current Plan') }}</span>
                        <x-filament::badge :color="$subscription->status->getColor()">
                            {{ $subscription->status->getLabel() }}
                        </x-filament::badge>
                    </div>
                </x-slot:heading>

                <div class="space-y-4">
                    <div>
                        <p class="text-3xl font-bold tracking-tight">
                            {{ $subscription->plan->name }}
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ number_format((float) $subscription->price, 2) }}
                            {{ $subscription->currency }}
                            / {{ $subscription->interval->getLabel() }}
                        </p>
                    </div>

                    <div class="space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">{{ __('Units used') }}</span>
                            <span>
                                {{ $subscription->current_unit_count ?? 0 }}
                                @if($subscription->max_units)
                                    / {{ $subscription->max_units }}
                                @endif
                            </span>
                        </div>
                        @if($this->getUsagePercent())
                            <div class="h-2 rounded-full bg-gray-200 dark:bg-gray-700">
                                <div
                                    class="h-2 rounded-full bg-primary-500 transition-all"
                                    style="width: {{ min($this->getUsagePercent(), 100) }}%"
                                ></div>
                            </div>
                        @endif

                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">{{ __('Max properties') }}</span>
                            <span>{{ $subscription->max_properties ?? __('Unlimited') }}</span>
                        </div>
                    </div>
                </div>
            </x-filament::section>

            {{-- Expiry countdown --}}
            <x-filament::section>
                <x-slot:heading>{{ __('Expiry') }}</x-slot:heading>

                <div class="space-y-3">
                    @php $days = $this->getDaysToExpiry(); @endphp
                    <div>
                        <p class="text-3xl font-bold tracking-tight @if($days !== null && $days <= 0) text-danger-600 @elseif($days !== null && $days <= 7) text-warning-600 @endif">
                            @if($days !== null && $days > 0)
                                {{ $days }} {{ __('days') }}
                            @elseif($days !== null && $days <= 0)
                                {{ __('Expired') }}
                            @else
                                {{ '—' }}
                            @endif
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            @if($days !== null && $days > 0)
                                {{ __('until :date', ['date' => $subscription->ends_at?->format('Y-m-d')]) }}
                            @elseif($days !== null && $days <= 0)
                                {{ __('ended on :date', ['date' => $subscription->ends_at?->format('Y-m-d')]) }}
                            @endif
                        </p>
                    </div>

                    <div class="flex items-center justify-between">
                        <span class="text-sm text-gray-500">{{ __('Auto-renew') }}</span>
                        <x-filament::badge :color="$subscription->auto_renew ? 'success' : 'gray'">
                            {{ $subscription->auto_renew ? __('On') : __('Off') }}
                        </x-filament::badge>
                    </div>
                </div>
            </x-filament::section>

            {{-- Quick info --}}
            <x-filament::section>
                <x-slot:heading>{{ __('Info') }}</x-slot:heading>

                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">{{ __('Started') }}</dt>
                        <dd>{{ $subscription->starts_at?->format('Y-m-d') }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">{{ __('Grace ends') }}</dt>
                        <dd>{{ $subscription->grace_ends_at?->format('Y-m-d') ?? '—' }}</dd>
                    </div>
                    @if($subscription->cancelled_at)
                        <div class="flex justify-between">
                            <dt class="text-gray-500">{{ __('Cancelled') }}</dt>
                            <dd>{{ $subscription->cancelled_at->format('Y-m-d') }}</dd>
                        </div>
                    @endif
                    @if($subscription->suspended_at)
                        <div class="flex justify-between">
                            <dt class="text-gray-500">{{ __('Suspended') }}</dt>
                            <dd>{{ $subscription->suspended_at->format('Y-m-d') }}</dd>
                        </div>
                    @endif
                </dl>
            </x-filament::section>
        </div>

        {{-- Payment history --}}
        <x-filament::section>
            <x-slot:heading>{{ __('Payment History') }}</x-slot:heading>

            @if($subscription->payments->isEmpty())
                <p class="text-sm text-gray-500">{{ __('No payments recorded yet.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="py-2 pr-4 font-medium">{{ __('Date') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Amount') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Method') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Period') }}</th>
                                <th class="py-2 pr-4 font-medium">{{ __('Status') }}</th>
                                <th class="py-2 font-medium">{{ __('Receipt') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($subscription->payments as $payment)
                                <tr class="border-b border-gray-100 dark:border-gray-800">
                                    <td class="py-2 pr-4">{{ $payment->paid_at?->format('Y-m-d') }}</td>
                                    <td class="py-2 pr-4">{{ number_format((float) $payment->amount, 2) }} {{ $payment->currency }}</td>
                                    <td class="py-2 pr-4">{{ $payment->method?->getLabel() }}</td>
                                    <td class="py-2 pr-4">
                                        {{ $payment->covers_from?->format('Y-m-d') }}
                                        &rarr;
                                        {{ $payment->covers_to?->format('Y-m-d') }}
                                    </td>
                                    <td class="py-2 pr-4">
                                        <x-filament::badge :color="$payment->status->getColor()">
                                            {{ $payment->status->getLabel() }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="py-2">{{ $payment->receipt_number ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
