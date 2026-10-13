{{--
    The /m mobile shell's layout (every App\Livewire\Mobile\* screen).

    No Filament here: own <head>, own Tailwind build (resources/css/mobile.css),
    own JS (resources/js/mobile.js). Screens move with wire:navigate; the top bar
    and tab bar are @persist'ed so they never flash or re-render between them.

    Everything in <head> is identical on every screen, so Livewire's SPA
    navigation runs each script exactly once — which is why the shared
    components (error reporter, failed-request toast, print helpers) live here
    and not in <body>, whose scripts Livewire re-runs on every navigation.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    {{-- viewport-fit=cover: draw under the notch / home indicator; the bars pad themselves with env(safe-area-inset-*). --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ __('ngeay juol') }}</title>

    <link rel="manifest" href="/manifest.json">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <meta name="theme-color" content="#059669">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    {{-- The top bar is brand green in both themes and runs under the status bar, so white status text. --}}
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ងាយជួល">
    <meta name="format-detection" content="telephone=no">

    {{-- Theme before first paint (same localStorage key the Filament panels use). --}}
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('theme') || 'system';
                var dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) {}
        })();
    </script>

    @include('components.rw-fonts')
    @include('components.rw-display-mode-cookie')
    @include('components.rw-client-error-reporter')
    @include('components.rw-session-expired-redirect')
    @include('components.rw-print-script')

    @vite(['resources/css/mobile.css', 'resources/js/mobile.js'])
</head>
<body class="h-full bg-slate-50 font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    @persist('rw-m-topbar')
        <header class="rw-m-topbar">
            <div class="mx-auto flex h-14 max-w-2xl items-center gap-3">
                <livewire:mobile.property-switcher />
            </div>
        </header>
    @endpersist

    <main class="rw-m-main mx-auto max-w-2xl">
        {{ $slot }}
    </main>

    @persist('rw-m-tabbar')
        @include('mobile.partials.tab-bar')
    @endpersist
</body>
</html>
