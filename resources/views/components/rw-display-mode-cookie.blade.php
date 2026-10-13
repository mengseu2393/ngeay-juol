{{--
    Tells the server whether this page runs as the installed app
    (display-mode: standalone) — only the browser knows. Read by
    App\Support\DeviceSession for the long-session policy. Rewritten on every
    page load, so a browser tab and the installed app that share one cookie jar
    (Android) each correct it the moment they are used.
--}}
<script>
    (function () {
        try {
            var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

            document.cookie = @js(\App\Support\DeviceSession::DISPLAY_MODE_COOKIE) + '=' + (standalone ? 'standalone' : 'browser')
                + '; path=/; max-age=31536000; samesite=lax' + (location.protocol === 'https:' ? '; secure' : '');
        } catch (e) {
            // Cookies blocked: the server falls back to the user agent.
        }
    })();
</script>
