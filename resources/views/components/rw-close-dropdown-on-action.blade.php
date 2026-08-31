{{--
    Filament's table-row dropdown ("...") is supposed to close itself the instant a
    menu item is clicked (Filament wires x-on:click="close()" onto every action for
    exactly this), but that Alpine call does not reliably fire before the
    mountTableAction request lands. On a fast server the gap is imperceptible; on
    this project's dev server (single PHP worker, no OPcache — see the
    PHP_CLI_SERVER_WORKERS note) a mountTableAction round trip can take several
    seconds, during which the dropdown stayed open on top of the action's modal
    backdrop, looking like two popups stacked on each other.

    This forces the same visual effect Alpine's own close() produces — the dropdown
    panel's floating-ui positioner already renders as `display: none` when shut, so
    setting that directly the moment a mount(Table)Action click starts is
    indistinguishable from a normal close, just synchronous instead of racing the
    network request.
--}}
<script>
    document.addEventListener('click', (event) => {
        if (! event.target.closest('[wire\\:click^="mountTableAction"], [wire\\:click^="mountAction"]')) {
            return;
        }

        document.querySelectorAll('.fi-dropdown-panel').forEach((panel) => {
            panel.style.display = 'none';
        });
    }, true);
</script>
