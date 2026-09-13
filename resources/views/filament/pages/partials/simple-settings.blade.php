{{--
    Simple Mode — Settings hub partial.

    Plain Blade partial (no Livewire — just navigation links + two POST forms,
    nothing stateful to manage). Intended to be @include()d inside a new
    x-show="screen === 'settings'" section of simple-dashboard.blade.php by a
    later integration pass — this file intentionally has no screen header /
    back button of its own.

    Styling note: every Tailwind utility and rw-sm-* class below already
    appears in resources/views/filament/pages/simple-dashboard.blade.php
    and/or resources/views/livewire/simple-room-list.blade.php. No new
    utility combination is invented here.
--}}
<div class="space-y-5">

    {{-- ── Property Settings ── --}}
    <div class="rw-sm-panel rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
        <a
            href="{{ \App\Filament\Pages\PropertySettings::getUrl(['from' => 'simple']) }}"
            class="flex items-center justify-between gap-3"
            id="simple-settings-property"
        >
            <div class="flex items-center gap-3">
                <div class="rw-sm-action-icon bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28zM15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                </div>
                <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Property Settings') }}</span>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5 text-gray-500 dark:text-gray-400 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
        </a>
    </div>

    {{-- ── Account ── --}}
    {{-- No ->profile() route on the landlord panel (grepped, no match), so
         this is the in-page "profile" Alpine screen (SimpleProfile Livewire
         component) rather than a link to a Filament profile page. --}}
    <div class="rw-sm-panel rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
        <a href="?screen=profile" class="flex items-center justify-between gap-3" id="simple-settings-profile">
            <div class="flex items-center gap-3">
                <div class="rw-sm-action-icon bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                </div>
                <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Profile') }}</span>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5 text-gray-500 dark:text-gray-400 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
        </a>
    </div>

    {{-- ── More ── --}}
    <div class="rw-sm-panel rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
        <p class="rw-sm-property-label text-xs font-semibold uppercase tracking-widest text-primary-600 dark:text-primary-400 mb-1">{{ __('More') }}</p>
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <a href="{{ \App\Filament\Resources\PropertyResource::getUrl(parameters: ['from' => 'simple']) }}" class="flex items-center justify-between gap-3 py-3.5 px-1 -mx-1 rounded-lg transition-colors hover:bg-gray-50 dark:hover:bg-white/5" id="simple-settings-more-properties">
                <div class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 text-gray-500 dark:text-gray-400"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3.75h9v16.5h-9V3.75zm9 6.75h6v9.75h-6M8.25 6.75h.008v.008H8.25V6.75zm0 3h.008v.008H8.25v-.008zm0 3h.008v.008H8.25v-.008zm0 3h.008v.008H8.25v-.008z"/></svg>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Properties') }}</span>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5 text-gray-500 dark:text-gray-400 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </a>
            <a href="{{ \App\Filament\Resources\RentalResource::getUrl(parameters: ['from' => 'simple']) }}" class="flex items-center justify-between gap-3 py-3.5 px-1 -mx-1 rounded-lg transition-colors hover:bg-gray-50 dark:hover:bg-white/5" id="simple-settings-more-rentals">
                <div class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 text-gray-500 dark:text-gray-400"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Tenancies / Rentals') }}</span>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5 text-gray-500 dark:text-gray-400 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </a>
            <a href="{{ \App\Filament\Resources\MaintenanceRequestResource::getUrl(parameters: ['from' => 'simple']) }}" class="flex items-center justify-between gap-3 py-3.5 px-1 -mx-1 rounded-lg transition-colors hover:bg-gray-50 dark:hover:bg-white/5" id="simple-settings-more-maintenance">
                <div class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 text-gray-500 dark:text-gray-400"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.276a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z"/></svg>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Maintenance Requests') }}</span>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5 text-gray-500 dark:text-gray-400 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </a>
            <a href="{{ \App\Filament\Resources\PropertyUtilityResource::getUrl(parameters: ['from' => 'simple']) }}" class="flex items-center justify-between gap-3 py-3.5 px-1 -mx-1 rounded-lg transition-colors hover:bg-gray-50 dark:hover:bg-white/5" id="simple-settings-more-utility-rates">
                <div class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 text-gray-500 dark:text-gray-400"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('Utility Rates') }}</span>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5 text-gray-500 dark:text-gray-400 shrink-0"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </a>
        </div>
    </div>

    {{-- ── Log out ── --}}
    <div class="rw-sm-panel rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
        <form method="POST" action="{{ route('filament.landlord.auth.logout') }}">
            @csrf
            <div class="flex gap-3">
                <button type="submit" class="rw-sm-btn-warning flex-1 gap-2" id="simple-settings-logout">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l3 3m0 0l-3 3m3-3H3"/></svg>
                    <span>{{ __('Log out') }}</span>
                </button>
            </div>
        </form>
    </div>

</div>
