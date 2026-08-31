<x-filament-panels::page class="fi-page-renewals">
    <div class="flex flex-col gap-y-6">
        <x-filament::tabs :label="__('Renewals & approvals')">
            <x-filament::tabs.item
                icon="heroicon-m-arrow-path"
                :active="! $this->isApprovalsTab()"
                :badge="$this->getRenewalsCount()"
                wire:click="$set('tab', '{{ \App\Filament\Pages\Renewals::TAB_RENEWALS }}')"
            >
                {{ __('Renewals') }}
            </x-filament::tabs.item>

            <x-filament::tabs.item
                icon="heroicon-m-check-circle"
                :active="$this->isApprovalsTab()"
                :badge="$this->getApprovalsCount()"
                :badge-color="$this->getApprovalsCount() > 0 ? 'warning' : null"
                wire:click="$set('tab', '{{ \App\Filament\Pages\Renewals::TAB_APPROVALS }}')"
            >
                {{ __('Awaiting approval') }}
            </x-filament::tabs.item>
        </x-filament::tabs>

        @if ($this->isApprovalsTab())
            @livewire(\App\Filament\Widgets\AdminPendingPaymentsTableWidget::class, [], key('admin-pending-payments-table'))
        @else
            @livewire(\App\Filament\Widgets\AdminRenewalsTableWidget::class, [], key('admin-renewals-table'))
        @endif
    </div>
</x-filament-panels::page>
