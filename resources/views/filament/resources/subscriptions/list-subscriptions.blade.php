{{--
    Tab switching is deliberately client-side only: both tables are rendered up front
    and Alpine toggles them, so switching costs no server round-trip at all. (Livewire
    lazy-loading the payments widget was tried instead, to keep the first paint
    cheaper, and it hung the page — do not reintroduce it.)

    Alpine expressions here are kept free of quotes and Blade directives on purpose:
    directives are not compiled inside component-tag attributes, and Filament escapes
    quotes in the attribute bag, so both break silently. Hence the boolean flag and
    the helper defined in a real <script> block below.
--}}
<x-filament-panels::page
    class="fi-resource-list-records-page fi-resource-subscriptions"
    x-data="window.rentwiseSubscriptionTabs({{ $this->isPaymentsTab() ? 'true' : 'false' }})"
>
    <script>
        window.rentwiseSubscriptionTabs = (isPayments) => ({
            payments: isPayments,

            select(payments) {
                if (this.payments === payments) return

                this.payments = payments

                // Keep the server property in sync without firing a request, so a
                // later round-trip re-renders with the right panel showing.
                this.$wire.set('tab', payments ? 'payments' : 'subscriptions', false)

                const url = new URL(window.location)
                payments ? url.searchParams.set('tab', 'payments') : url.searchParams.delete('tab')
                window.history.replaceState({}, '', url)
            },
        })
    </script>

    <div class="flex flex-col gap-y-6">
        <x-filament::tabs :label="__('Subscriptions')">
            <x-filament::tabs.item
                icon="heroicon-m-credit-card"
                alpine-active="! payments"
                :badge="$this->getSubscriptionsCount()"
                x-on:click="select(false)"
            >
                {{ __('Subscriptions') }}
            </x-filament::tabs.item>

            <x-filament::tabs.item
                icon="heroicon-m-receipt-percent"
                alpine-active="payments"
                :badge="$this->getPaymentsCount()"
                x-on:click="select(true)"
            >
                {{ __('Payments') }}
            </x-filament::tabs.item>
        </x-filament::tabs>

        <div
            x-show="! payments"
            @style(['display: none' => $this->isPaymentsTab()])
        >
            {{ $this->table }}
        </div>

        <div
            x-show="payments"
            @style(['display: none' => ! $this->isPaymentsTab()])
        >
            @livewire(\App\Filament\Widgets\SubscriptionPaymentsTableWidget::class, [], key('subscription-payments-table'))
        </div>
    </div>
</x-filament-panels::page>
