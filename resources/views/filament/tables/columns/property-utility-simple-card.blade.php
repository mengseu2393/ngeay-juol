@php
    use App\Filament\Resources\PropertyUtilityResource;
    use App\Support\Money;

    /** @var \App\Models\PropertyUtility $utility */
    $utility = $getRecord();
@endphp

{{-- Filament wraps a ViewColumn in a `flex w-full` container with no padding
     of its own, so this card supplies all of its own spacing. --}}
<div class="w-full min-w-0 px-4 py-4">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="truncate text-sm font-bold text-gray-900 dark:text-white">
                {{ PropertyUtilityResource::utilityLabel($utility->name) }}
            </p>
            <x-filament::badge color="gray" class="mt-1.5">
                {{ $utility->billing_type->getLabel() }}
            </x-filament::badge>
        </div>

        <x-filament::badge :color="$utility->is_active ? 'success' : 'gray'" class="shrink-0">
            {{ $utility->is_active ? __('Active') : __('Inactive') }}
        </x-filament::badge>
    </div>

    <div class="mt-3 flex items-baseline justify-between gap-3">
        <span class="text-lg font-bold text-primary-600 dark:text-primary-400">
            {{ Money::formatForRecord($utility->rate, $utility) }}
        </span>
        @if($utility->unit_of_measure)
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('per :unit', ['unit' => $utility->unit_of_measure]) }}
            </span>
        @endif
    </div>

    @if($utility->provider)
        <p class="mt-2.5 flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-3.5 w-3.5 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349M3.75 21V9.349m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.017a3.001 3.001 0 003.75.617M21.75 9.349V6.5a.75.75 0 00-.128-.42l-1.845-2.767a1.125 1.125 0 00-.936-.501H4.923a1.125 1.125 0 00-.936.501L2.128 6.079A.75.75 0 002 6.5v2.849"/></svg>
            {{ $utility->provider }}
        </p>
    @endif
</div>
