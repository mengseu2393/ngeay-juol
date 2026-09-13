{{--
    Livewire's default behaviour on a failed component request (e.g. the
    session/CSRF token expired mid-page, so the /livewire/update POST comes
    back 419 instead of a normal Livewire response) is to surface the raw
    failure — either a browser confirm() prompt or, worse, the actual error
    page HTML dumped into an overlay. A landlord/tenant has no idea what that
    means; the only sane recovery is "log in again", so do that silently
    instead of showing them anything.
--}}
<script>
    document.addEventListener('livewire:init', () => {
        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                if (status === 419 || status === 401) {
                    preventDefault();
                    window.location.href = '{{ route('login') }}';
                }
            });
        });
    });
</script>
