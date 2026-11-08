{{--
    The /m2 app's layout (every App\Livewire\M2\* screen). Standalone: no Filament,
    and none of /m's Vite/Tailwind either — public/css/m2.css is hand-written and
    cache-busted by its mtime. Screens move with wire:navigate. There is no tab bar:
    Home is the hub, and every other screen has a Back button in its header.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ __('ngeay juol') }}</title>

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="/icons/icon-192.png">
    <meta name="theme-color" content="#0b7a55">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="format-detection" content="telephone=no">
    <meta name="apple-mobile-web-app-title" content="ងាយជួល">
    <link rel="manifest" href="/manifest.json">

    {{-- PWA. Here and not in a screen: scripts in a page swapped in by wire:navigate don't run, and
         beforeinstallprompt fires once at load — Settings' "Install app" row reads window.m2InstallPrompt.
         The worker is registered when the browser is idle so it never competes with the first paint; once
         ready, it is handed this page's css/js/font URLs to cache (they were fetched before it existed),
         so the next launch loads them from the phone. Pages themselves are never cached (public/sw.js). --}}
    <script>
        (function () {
            if (window.__m2PwaBound) { return; }
            window.__m2PwaBound = true;

            window.addEventListener('beforeinstallprompt', function (e) {
                e.preventDefault();
                window.m2InstallPrompt = e;
                window.dispatchEvent(new Event('m2-install-ready'));
            });
            window.addEventListener('appinstalled', function () {
                window.m2InstallPrompt = null;
                window.dispatchEvent(new Event('m2-install-ready'));
            });

            if (!('serviceWorker' in navigator)) { return; }
            var idle = window.requestIdleCallback || function (fn) { return setTimeout(fn, 1500); };
            window.addEventListener('load', function () {
                idle(function () {
                    navigator.serviceWorker.register('/sw.js').catch(function () {});
                    navigator.serviceWorker.ready.then(function (reg) {
                        if (!reg.active) { return; }
                        var urls = [@js(asset('fonts/kantumruy-pro-khmer-efd3b52e.woff2'))];
                        document.querySelectorAll('link[rel="stylesheet"][href], script[src]').forEach(function (el) {
                            urls.push(el.href || el.src);
                        });
                        reg.active.postMessage({ type: 'm2-warm', urls: urls });
                    }).catch(function () {});
                });
            });
        })();
    </script>

    {{-- Theme before first paint (same localStorage key as the panels and /m). It is applied again after every
         wire:navigate page swap, and when another tab changes the choice, so Dark chosen in Settings holds on Home. --}}
    <script>
        (function () {
            function apply() {
                try {
                    var theme = localStorage.getItem('theme') || 'system';
                    var dark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                    document.documentElement.classList.toggle('dark', dark);
                } catch (e) {}
            }
            apply();
            if (!window.__m2ThemeBound) {
                window.__m2ThemeBound = true;
                document.addEventListener('livewire:navigated', apply);
                window.addEventListener('storage', function (e) { if (e.key === 'theme') { apply(); } });
                window.addEventListener('pageshow', apply);
            }
        })();
    </script>

    {{-- Shrinks a picked photo in the browser before upload. A phone photo is 3–12MB and a default
         server (PHP upload_max_filesize 2M) rejects it before Laravel sees it. Resolves the original
         file when the browser can't decode it, so it can only help. Same approach as /m's. --}}
    <script>
        window.m2ResizeImage = window.m2ResizeImage || async function (file, maxSide, quality) {
            maxSide = maxSide || 800;
            quality = quality || 0.85;
            if (!file || !file.type || file.type.indexOf('image/') !== 0 || file.type === 'image/gif') { return file; }
            try {
                var bitmap = await createImageBitmap(file);
                var scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
                var canvas = document.createElement('canvas');
                canvas.width = Math.max(1, Math.round(bitmap.width * scale));
                canvas.height = Math.max(1, Math.round(bitmap.height * scale));
                canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                var blob = await new Promise(function (resolve) { canvas.toBlob(resolve, 'image/jpeg', quality); });
                if (!blob || blob.size >= file.size) { return file; }
                return new File([blob], 'avatar.jpg', { type: 'image/jpeg' });
            } catch (e) { return file; }
        };

        // The Slip's Telegram button is a tg:// link, which opens the app but can only carry text. So the tap
        // also saves the PDF, for attaching in the chat. Here and not in the Slip's view: scripts in a page
        // swapped in by wire:navigate don't run.
        window.m2SaveInvoicePdf = window.m2SaveInvoicePdf || function (link) {
            var a = document.createElement('a');
            a.href = link.dataset.pdfUrl;
            a.download = link.dataset.filename;
            document.body.appendChild(a);
            a.click();
            a.remove();
        };
    </script>

    <x-rw-fonts :preload="false" />
    @include('components.rw-client-error-reporter')
    @include('components.rw-session-expired-redirect')

    <link rel="stylesheet" href="{{ asset('css/m2.css') }}?v={{ @filemtime(public_path('css/m2.css')) }}">

    {{-- Feedback for every save / failure: sound, vibration, toast, motion (public/js/m2-feedback.js). --}}
    <script>
        window.m2FeedbackText = @js([
            'checkFields' => __('Please check the highlighted fields.'),
            'network' => __('Could not save. Check your connection and try again.'),
        ]);
    </script>
    <script src="{{ asset('js/m2-feedback.js') }}?v={{ @filemtime(public_path('js/m2-feedback.js')) }}"></script>
</head>
<body class="m2">
    {{ $slot }}

    {{-- A message flashed before a redirect (session 'm2-toast': a string, or [message, kind]). m2-feedback.js
         shows it as a toast — with sound — on this page, including one swapped in by wire:navigate. --}}
    @php
        $flash = session('m2-toast');
        $flash = is_array($flash) ? $flash : ($flash ? ['message' => $flash, 'kind' => 'success'] : null);
    @endphp
    @if ($flash)
        <script type="application/json" id="m2-flash">@json($flash)</script>
    @endif
</body>
</html>
