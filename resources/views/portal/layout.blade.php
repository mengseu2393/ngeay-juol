<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('ngeay juol') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" href="{{ asset('Khmer%20House%20Key.svg') }}" type="image/svg+xml">
    <!-- PWA -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#059669">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="ងាយជួល">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <script src="https://cdn.tailwindcss.com"></script>
    @include('components.rw-loader-head')
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 antialiased">
    <header class="bg-emerald-600 text-white shadow">
        <div class="mx-auto flex max-w-2xl items-center justify-between px-4 py-3">
            <a href="{{ route('portal.dashboard') }}" class="flex items-center gap-2 text-lg font-bold">
                <img src="{{ asset('Khmer%20House%20Key.svg') }}" alt="{{ __('ngeay juol') }}" class="h-7 w-7 rounded">
                {{ __('ngeay juol') }}
            </a>
            <div class="flex items-center gap-2.5">
                @php
                    $currentLocale = app()->getLocale();
                    $nextLocale = $currentLocale === 'en' ? 'km' : 'en';
                    $fullName = $nextLocale === 'en' ? 'English' : 'ខ្មែរ';
                @endphp
                <a href="{{ route('locale.switch', $nextLocale) }}"
                   class="rounded bg-emerald-700 px-3 py-1.5 text-sm font-medium hover:bg-emerald-800 transition-colors"
                   title="{{ __('Switch to') }} {{ $fullName }}">
                    {{ $nextLocale === 'en' ? 'EN' : 'ខ្មែរ' }}
                </a>
                @auth
                    <form method="POST" action="{{ route('portal.logout') }}" class="inline">
                        @csrf
                        <button class="rounded bg-emerald-700 px-3 py-1.5 text-sm font-medium hover:bg-emerald-800">
                            {{ __('Log out') }}
                        </button>
                    </form>
                @endauth
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-2xl px-4 py-6">
        @yield('content')
    </main>

    <footer class="py-6 text-center text-xs text-slate-400">{{ __('ngeay juol') }} · {{ __('Tenant portal') }}</footer>

    @include('filament.components.pwa-install-banner')

    <!-- PWA: Service Worker registration -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js').catch(() => {});
            });
        }
    </script>
</body>
</html>
