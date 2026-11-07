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
</head>
<body class="m2">
    {{ $slot }}

    {{-- Toast: the `rw-m-toast` window event the shared /m logic dispatches, or a message flashed
         before a redirect (session 'm2-toast'). Rendered with each page, so it survives wire:navigate. --}}
    <div class="m2-toast" id="m2-toast" role="status" aria-live="polite"
         x-data="{ msg: @js(session('m2-toast')), timer: null,
                   show(m) { if (! m) { return; } this.msg = m; clearTimeout(this.timer); this.timer = setTimeout(() => { this.msg = null; }, 3200); } }"
         x-init="show(msg)"
         x-on:rw-m-toast.window="show($event.detail.message)"
         x-show="msg" x-transition.opacity x-cloak x-text="msg" x-on:click="msg = null"></div>
</body>
</html>
