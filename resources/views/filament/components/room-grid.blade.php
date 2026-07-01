@php
    /** @var \App\Filament\Pages\MonthlyBilling $livewire */
    $livewire     = $getLivewire();
    $rooms        = $livewire->availableRooms;
    $selectedIds  = $livewire->selectedRoomIds;
    $total        = count($rooms);
    $totalSelected = count($selectedIds);
    $totalDue      = collect($rooms)->filter(fn($r) => $r['is_due'] ?? false)->count();

    // Map Filament color names → Tailwind classes (same colours the Rooms table uses)
    $colorMap = [
        'success' => ['bg' => 'bg-green-100 dark:bg-green-900/30',  'text' => 'text-green-700 dark:text-green-400',  'dot' => 'bg-green-500'],
        'info'    => ['bg' => 'bg-blue-100 dark:bg-blue-900/30',    'text' => 'text-blue-700 dark:text-blue-400',    'dot' => 'bg-blue-500'],
        'warning' => ['bg' => 'bg-amber-100 dark:bg-amber-900/30',  'text' => 'text-amber-700 dark:text-amber-400',  'dot' => 'bg-amber-500'],
        'gray'    => ['bg' => 'bg-gray-100 dark:bg-gray-700',       'text' => 'text-gray-600 dark:text-gray-400',    'dot' => 'bg-gray-400'],
    ];
@endphp

<div class="space-y-3">

    {{-- ── Toolbar ─────────────────────────────────────────── --}}
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">
                {{ __('Rooms') }}
            </span>
            @if($totalSelected > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-primary-100 dark:bg-primary-900/40 px-2.5 py-0.5 text-xs font-semibold text-primary-700 dark:text-primary-300">
                    <x-heroicon-s-check-circle class="h-3 w-3"/>
                    {{ $totalSelected }} / {{ $total }}
                </span>
            @endif
            @if($totalDue > 0)
                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 dark:bg-amber-900/40 px-2.5 py-0.5 text-xs font-semibold text-amber-700 dark:text-amber-300">
                    <x-heroicon-s-exclamation-circle class="h-3 w-3"/>
                    {{ $totalDue }} {{ __('due') }}
                </span>
            @endif
        </div>

        <div class="flex gap-2">
            @if($totalSelected < $total)
                <button type="button" wire:click="selectAllRooms"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-1.5 text-xs font-medium text-gray-700 dark:text-gray-300 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                    <x-heroicon-o-check-circle class="h-3.5 w-3.5"/> {{ __('Select all') }}
                </button>
            @endif
            @if($totalSelected > 0)
                <button type="button" wire:click="clearRoomSelection"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-1.5 text-xs font-medium text-gray-500 dark:text-gray-400 shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                    <x-heroicon-o-x-circle class="h-3.5 w-3.5"/> {{ __('Clear') }}
                </button>
            @endif
        </div>
    </div>

    {{-- ── Grid ────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3">
        @foreach($rooms as $room)
            @php
                $isSelected = in_array($room['rental_id'], $selectedIds);
                $isDue      = $room['is_due'] ?? false;
                $color      = $colorMap[$room['status_color'] ?? 'gray'] ?? $colorMap['gray'];
            @endphp

            <button
                type="button"
                wire:click="toggleRoom({{ $room['rental_id'] }})"
                wire:key="room-card-{{ $room['rental_id'] }}"
                @class([
                    'group relative flex flex-col w-full text-left rounded-xl border-2 overflow-hidden transition-all duration-200 cursor-pointer focus:outline-none focus:ring-2 focus:ring-primary-500',
                    'border-primary-500 shadow-lg dark:border-primary-400 ring-2 ring-primary-200 dark:ring-primary-800' => $isSelected,
                    'border-amber-400' => !$isSelected && $isDue,
                    'border-gray-200 dark:border-gray-700 hover:border-primary-300 dark:hover:border-primary-600 hover:shadow-md' => !$isSelected && !$isDue,
                ])
            >
                {{-- Coloured top bar (same as status badge colour) --}}
                <div @class([
                    'h-1.5 w-full',
                    'bg-primary-500' => $isSelected,
                    $color['dot']    => !$isSelected,
                ])></div>

                <div class="flex flex-col flex-1 p-3 gap-2 bg-white dark:bg-gray-800">

                    {{-- Room number + checkbox --}}
                    <div class="flex items-start justify-between gap-1">
                        <span @class([
                            'text-base font-extrabold leading-tight truncate',
                            'text-primary-600 dark:text-primary-400' => $isSelected,
                            'text-gray-900 dark:text-gray-100'       => !$isSelected,
                        ])>
                            {{ $room['room_number'] }}
                        </span>

                        <div class="shrink-0 mt-0.5">
                            @if($isSelected)
                                <div class="flex h-5 w-5 items-center justify-center rounded-full bg-primary-500">
                                    <x-heroicon-s-check class="h-3 w-3 text-white"/>
                                </div>
                            @else
                                <div class="h-5 w-5 rounded-full border-2 border-gray-300 dark:border-gray-600 group-hover:border-primary-400 transition-colors"></div>
                            @endif
                        </div>
                    </div>

                    {{-- Floor + room type (exactly like the Rooms table columns) --}}
                    <div class="flex flex-wrap gap-1">
                        @if(filled($room['floor']))
                            <span class="inline-flex items-center rounded-md bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 text-xs font-medium text-gray-500 dark:text-gray-400">
                                {{ __('Floor') }} {{ $room['floor'] }}
                            </span>
                        @endif
                        @if(filled($room['room_type']))
                            <span class="inline-flex items-center rounded-md bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 text-xs font-medium text-gray-500 dark:text-gray-400">
                                {{ $room['room_type'] }}
                            </span>
                        @endif
                        @if($isDue)
                            <span class="inline-flex items-center rounded-md bg-amber-50 dark:bg-amber-900/30 px-1.5 py-0.5 text-xs font-medium text-amber-600 dark:text-amber-400 ring-1 ring-inset ring-amber-600/20">
                                {{ __('Due') }}
                            </span>
                        @endif
                    </div>

                    {{-- Status badge (mirrors the Filament badge column) --}}
                    <span @class([
                        'inline-flex w-fit items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-semibold',
                        $color['bg'],
                        $color['text'],
                    ])>
                        <span @class(['h-1.5 w-1.5 rounded-full shrink-0', $color['dot']])></span>
                        {{ $room['status_label'] ?? '—' }}
                    </span>

                    {{-- Occupant (mirrors "Tenant" column) --}}
                    <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                        <x-heroicon-o-user class="inline h-3 w-3 mr-0.5 -mt-0.5"/>
                        {{ $room['occupant'] }}
                    </p>

                    @if(!empty($room['next_invoice_date']))
                        <p class="text-[10px] text-gray-400 dark:text-gray-500">
                            {{ __('Next:') }} {{ $room['next_invoice_date'] }}
                        </p>
                    @endif

                    {{-- Rent --}}
                    <p @class([
                        'text-xs font-semibold',
                        'text-primary-600 dark:text-primary-400' => $isSelected,
                        'text-gray-400 dark:text-gray-500'       => !$isSelected,
                    ])>
                        ${{ number_format($room['rent'], 2) }}/mo
                    </p>
                </div>
            </button>
        @endforeach
    </div>

    {{-- ── Empty state ─────────────────────────────────────── --}}
    @if($total === 0)
        <div class="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-200 dark:border-gray-700 py-10 text-center text-gray-400 dark:text-gray-500">
            <x-heroicon-o-building-office-2 class="mb-2 h-8 w-8"/>
            <p class="text-sm">{{ __('No occupied rooms found for this property.') }}</p>
        </div>
    @endif

</div>
