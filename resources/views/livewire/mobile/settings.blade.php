@php
    $icons = [
        'cog' => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        'plus' => 'M12 9v6m3-3H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
        'home' => 'm2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
        'users' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
        'bolt' => 'm3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z',
        'gauge' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z',
        'user' => 'M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
        'language' => 'm10.5 21 5.25-11.25L21 21m-9-3h7.5M3 5.621a48.474 48.474 0 0 1 6-.371m0 0c1.12 0 2.233.038 3.334.114M9 5.25V3m3.334 2.364C11.176 10.658 7.69 15.08 3 17.502m9.334-12.138c.896.061 1.785.147 2.666.257m-4.589 8.495a18.023 18.023 0 0 1-3.827-5.802',
        'logout' => 'M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15m3 0 3-3m0 0-3-3m3 3H9',
    ];

    $group = 'rw-m-card divide-y divide-slate-100 overflow-hidden p-0 dark:divide-slate-800';
    $groupLabel = 'px-4 pb-1.5 text-xs font-semibold tracking-wide text-slate-500 uppercase dark:text-slate-400';
@endphp

<div class="space-y-6 pb-2" id="rw-m-settings">
    <h1 class="rw-m-title pb-0">{{ __('Settings') }}</h1>

    {{-- ── Who is signed in → Profile ── --}}
    <a
        href="{{ route('mobile.profile') }}"
        wire:navigate
        id="rw-m-settings-account"
        class="rw-m-card flex min-h-16 items-center gap-3 transition-colors active:bg-slate-50 dark:active:bg-slate-800/60"
    >
        <span class="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500">
            @if ($avatarUrl)
                <img src="{{ $avatarUrl }}" alt="{{ __('Profile photo') }}" class="size-full object-cover">
            @else
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['user'] }}" /></svg>
            @endif
        </span>
        <span class="min-w-0 flex-1">
            <span class="block truncate text-lg font-bold">{{ $user?->name }}</span>
            <span class="block truncate text-sm text-slate-500 dark:text-slate-400">{{ $user?->email ?: $user?->phone_number }}</span>
        </span>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-5 shrink-0 text-slate-300 dark:text-slate-600" aria-hidden="true"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
    </a>

    {{-- ── Property ── --}}
    <section aria-labelledby="rw-m-settings-property-label">
        <h2 id="rw-m-settings-property-label" class="{{ $groupLabel }}">{{ __('Property') }}</h2>
        <div class="{{ $group }}">
            @include('livewire.mobile.settings-row', ['href' => route('mobile.property-settings'), 'id' => 'rw-m-settings-property', 'label' => __('Property Settings'), 'icon' => $icons['cog'], 'tint' => 'bg-sky-500'])
            @include('livewire.mobile.settings-row', ['href' => route('mobile.add-property'), 'id' => 'rw-m-settings-add-property', 'label' => __('Add property'), 'icon' => $icons['plus'], 'tint' => 'bg-emerald-500'])
            @include('livewire.mobile.settings-row', ['href' => route('mobile.rooms'), 'id' => 'rw-m-settings-rooms', 'label' => __('Rooms'), 'icon' => $icons['home'], 'tint' => 'bg-indigo-500'])
            @include('livewire.mobile.settings-row', ['href' => route('mobile.tenants'), 'id' => 'rw-m-settings-tenants', 'label' => __('Tenancies / Rentals'), 'icon' => $icons['users'], 'tint' => 'bg-violet-500'])
        </div>
    </section>

    {{-- ── Utilities ── --}}
    <section aria-labelledby="rw-m-settings-utilities-label">
        <h2 id="rw-m-settings-utilities-label" class="{{ $groupLabel }}">{{ __('Utilities') }}</h2>
        <div class="{{ $group }}">
            @include('livewire.mobile.settings-row', ['href' => route('mobile.utility-rates'), 'id' => 'rw-m-settings-utility-rates', 'label' => __('Utility Rates'), 'icon' => $icons['bolt'], 'tint' => 'bg-amber-500'])
            @include('livewire.mobile.settings-row', ['href' => route('mobile.readings'), 'id' => 'rw-m-settings-readings', 'label' => __('Meter readings'), 'icon' => $icons['gauge'], 'tint' => 'bg-teal-500'])
        </div>
    </section>

    {{-- ── Account ── --}}
    <section aria-labelledby="rw-m-settings-account-label">
        <h2 id="rw-m-settings-account-label" class="{{ $groupLabel }}">{{ __('Account') }}</h2>
        <div class="{{ $group }}">
            @include('livewire.mobile.settings-row', ['href' => route('mobile.profile'), 'id' => 'rw-m-settings-profile', 'label' => __('Profile'), 'icon' => $icons['user'], 'tint' => 'bg-blue-500'])

            {{-- Language: plain links, a full load — the choice lives in the session/cookie. --}}
            <div class="flex min-h-12 items-center gap-3 px-4 py-2" id="rw-m-settings-language">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-rose-500 text-white" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-5"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['language'] }}" /></svg>
                </span>
                <span class="min-w-0 flex-1 truncate font-medium" id="rw-m-settings-language-label">{{ __('Language') }}</span>
                <div class="flex shrink-0 rounded-xl bg-slate-100 p-1 dark:bg-slate-800" role="group" aria-labelledby="rw-m-settings-language-label">
                    @foreach ($locales as $code => $name)
                        <a
                            href="{{ route('locale.switch', $code) }}"
                            id="rw-m-settings-locale-{{ $code }}"
                            lang="{{ $code }}"
                            @if ($locale === $code) aria-current="true" @endif
                            class="flex min-h-9 min-w-14 items-center justify-center rounded-lg px-3 text-sm font-semibold transition active:scale-95 {{ $locale === $code ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-600 dark:text-white' : 'text-slate-500 dark:text-slate-400' }}"
                        >{{ $name }}</a>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ── Sign out: a real POST with CSRF, never wire:navigate ── --}}
    <form method="POST" action="{{ route('logout') }}" class="rw-m-card overflow-hidden p-0" id="rw-m-settings-logout-form">
        @csrf
        <button
            type="submit"
            id="rw-m-settings-logout"
            class="flex min-h-12 w-full items-center justify-center gap-2 px-4 font-semibold text-red-600 transition-colors active:bg-red-50 dark:text-red-400 dark:active:bg-red-500/10"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="size-5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons['logout'] }}" /></svg>
            {{ __('Log out') }}
        </button>
    </form>

    <p class="text-center text-xs text-slate-400 dark:text-slate-500">{{ __('ngeay juol') }}</p>
</div>
