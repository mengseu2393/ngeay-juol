{{--
    Global print/share handler for the invoice toolbar (invoice-slip-modal).

    Loaded once per full page load — panel-wide via the BODY_END render hook and
    directly on the standalone invoice pages. It must NOT live inside
    invoice-slip-modal itself: that component is injected into Filament modals by
    Livewire morphing, and scripts inside morphed HTML never execute
    ("rwPrintInvoice is not defined").

    Desktop: opens the inline PDF (browser print preview). Phones/PWA have no
    inline PDF viewer, so there we fetch the PDF and hand it to the system share
    sheet (navigator.share) — from which the user can print or send it (Telegram…).
--}}
<script>
    if (! window.rwPrintInvoice) {
        window.rwPrintInvoice = async function (btn) {
            const mobileLike = window.matchMedia('(pointer: coarse)').matches
                || window.matchMedia('(display-mode: standalone)').matches;

            // Desktop: keep the classic flow — inline PDF tab with print preview.
            if (! mobileLike || typeof navigator.canShare !== 'function') {
                window.open(btn.dataset.streamUrl, '_blank', 'noopener');
                return;
            }

            const label = btn.querySelector('[data-label]');
            const original = label ? label.textContent : null;
            btn.disabled = true;
            if (label && btn.dataset.preparing) label.textContent = btn.dataset.preparing;

            try {
                const res = await fetch(btn.dataset.downloadUrl, { credentials: 'same-origin' });
                if (! res.ok) throw new Error('HTTP ' + res.status);

                const blob = await res.blob();
                const file = new File([blob], btn.dataset.filename, { type: 'application/pdf' });

                if (navigator.canShare({ files: [file] })) {
                    await navigator.share({ files: [file], title: btn.dataset.filename });
                } else {
                    // Share-with-files unsupported: save the already-fetched PDF instead.
                    const url = URL.createObjectURL(blob);
                    const a = Object.assign(document.createElement('a'), { href: url, download: btn.dataset.filename });
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    setTimeout(() => URL.revokeObjectURL(url), 60000);
                }
            } catch (e) {
                // AbortError = user closed the share sheet — not an error.
                if (e.name !== 'AbortError') {
                    window.location.href = btn.dataset.downloadUrl;
                }
            } finally {
                btn.disabled = false;
                if (label && original !== null) label.textContent = original;
            }
        };

        // For <a href> style triggers (e.g. Filament header actions): desktop
        // follows the link normally (inline PDF tab); mobile/PWA intercepts and
        // routes through the share-sheet flow above.
        window.rwPrintInvoiceLink = function (event, el) {
            const mobileLike = window.matchMedia('(pointer: coarse)').matches
                || window.matchMedia('(display-mode: standalone)').matches;

            if (! mobileLike || typeof navigator.canShare !== 'function') {
                return true;
            }

            event.preventDefault();
            window.rwPrintInvoice(el);
            return false;
        };
    }
</script>
