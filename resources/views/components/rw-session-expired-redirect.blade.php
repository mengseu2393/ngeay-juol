{{--
    How a failed Livewire request (/livewire/update) is shown to the user.

    - 419 / 401 (session or CSRF token expired mid-page): Livewire would show a
      confirm() prompt or the raw error page. The only sane recovery is "log in
      again", so go there silently.
    - Anything else (a 5xx, a proxy's 502/504 on a flaky mobile connection, the
      request never reaching the server): Livewire would dump the raw error page
      into a full-screen overlay, which on a phone looks exactly like the app got
      corrupted. Show a short, translated toast instead. The failure itself is
      already filed in /admin → Error Log (server-side, and by
      components.rw-client-error-reporter for what only the browser saw).

    Self-contained (inline styles) because it also runs in the tenant portal,
    which has neither Filament nor its CSS.
--}}
<script>
    document.addEventListener('livewire:init', () => {
        const messages = {
            offline: @js(__('No internet connection. Please try again.')),
            failed: @js(__('Something went wrong. Please try again.')),
        };

        const toast = (text) => {
            document.getElementById('rw-request-failed-toast')?.remove();

            const el = document.createElement('div');
            el.id = 'rw-request-failed-toast';
            el.setAttribute('role', 'alert');
            el.textContent = text;
            Object.assign(el.style, {
                position: 'fixed',
                left: '50%',
                bottom: 'calc(5.5rem + env(safe-area-inset-bottom, 0px))',
                transform: 'translateX(-50%)',
                zIndex: '2147483001',
                maxWidth: 'calc(100vw - 2rem)',
                padding: '0.75rem 1rem',
                borderRadius: '0.75rem',
                background: '#1f2937',
                color: '#fff',
                font: '600 0.9375rem/1.4 system-ui, sans-serif',
                boxShadow: '0 10px 25px -5px rgba(0, 0, 0, 0.3)',
                textAlign: 'center',
            });
            el.addEventListener('click', () => el.remove());
            document.body.appendChild(el);
            setTimeout(() => el.remove(), 6000);
        };

        Livewire.hook('request', ({ fail }) => {
            fail(({ status, content, preventDefault }) => {
                preventDefault();

                if (status === 419 || status === 401) {
                    window.location.href = '{{ route('login') }}';

                    return;
                }

                // Livewire reports a fetch that never got a response as a 503 with no body.
                const network = content === null || content === undefined || ! navigator.onLine;

                @if (config('app.debug'))
                    console.error('[RentWise] Livewire request failed', status, content);
                @endif

                toast(network ? messages.offline : messages.failed);
            });
        });
    });
</script>
