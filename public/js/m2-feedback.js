/*
 * /m2 feedback: every save or failure is seen, heard and felt, so the app answers like a native one.
 * Hand-written, no build (like m2.css); loaded once in the m2 layout's <head> and kept across
 * wire:navigate page swaps.
 *
 *   m2Feedback.notify(kind, message)   kind: success | error | warning | money | big
 *
 * Where it fires from (no screen has to call it itself):
 *   - the `rw-m-toast` window event components dispatch ({message, kind?|level?}), and
 *     `m2-feedback` ({kind, message?}) for a save with nothing to say (no toast);
 *   - a message flashed before a redirect (session 'm2-toast', printed as #m2-flash);
 *   - Livewire responses: a method call that comes back with field errors (.m2-err) on
 *     screen is a failure (shake, first field pulsed); a failed request is a failure;
 *   - markup appearing after an update: [data-m2-feedback="big|success|error"],
 *     .m2-notice.is-ok (success), .m2-notice.is-bad (error) — both also as a toast.
 *
 * Sound is synthesised with Web Audio (no files). Phones only allow it after a first tap,
 * so the context is unlocked on the first pointerdown. Vibration is Android-only.
 * Both can be switched off in /m2 Settings (localStorage 'm2-sound' / 'm2-vibrate').
 */
(function () {
    if (window.m2Feedback) {
        return;
    }

    var RANK = { success: 1, warning: 2, error: 3, money: 4, big: 5 };
    var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ── preferences ─────────────────────────────────────────────────────────
    function pref(key) {
        try { return localStorage.getItem(key) !== 'off'; } catch (e) { return true; }
    }
    function setPref(key, on) {
        try { localStorage.setItem(key, on ? 'on' : 'off'); } catch (e) {}
    }

    // ── sound ───────────────────────────────────────────────────────────────
    var ctx = null;
    function audio() {
        if (!ctx) {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) { return null; }
            try { ctx = new AC(); } catch (e) { return null; }
        }
        if (ctx.state === 'suspended') { ctx.resume().catch(function () {}); }
        return ctx;
    }
    // One soft note: frequency, start offset, length (s), wave, peak volume.
    function note(ac, freq, at, len, type, vol) {
        var t = ac.currentTime + at;
        var osc = ac.createOscillator();
        var gain = ac.createGain();
        osc.type = type || 'sine';
        osc.frequency.setValueAtTime(freq, t);
        gain.gain.setValueAtTime(0.0001, t);
        gain.gain.exponentialRampToValueAtTime(vol || 0.12, t + 0.012);
        gain.gain.exponentialRampToValueAtTime(0.0001, t + len);
        osc.connect(gain).connect(ac.destination);
        osc.start(t);
        osc.stop(t + len + 0.02);
    }
    var SOUNDS = {
        // two rising notes: "ding-ding"
        success: function (ac) { note(ac, 784, 0, 0.16, 'sine', 0.11); note(ac, 1175, 0.09, 0.24, 'sine', 0.1); },
        big: function (ac) { note(ac, 659, 0, 0.14, 'sine', 0.1); note(ac, 880, 0.08, 0.16, 'sine', 0.1); note(ac, 1319, 0.16, 0.32, 'sine', 0.1); },
        // one low, soft "bonk"
        error: function (ac) { note(ac, 196, 0, 0.22, 'triangle', 0.16); note(ac, 147, 0.09, 0.26, 'triangle', 0.13); },
        warning: function (ac) { note(ac, 523, 0, 0.2, 'triangle', 0.1); },
        // "cha-ching": a short metallic hit, then two bright bell notes
        money: function (ac) {
            note(ac, 2637, 0, 0.05, 'square', 0.035);
            note(ac, 1568, 0.02, 0.08, 'triangle', 0.07);
            note(ac, 2093, 0.12, 0.34, 'sine', 0.09);
            note(ac, 2637, 0.16, 0.42, 'sine', 0.07);
        }
    };
    function play(kind) {
        if (!pref('m2-sound') || !SOUNDS[kind]) { return; }
        var ac = audio();
        if (!ac) { return; }
        try { SOUNDS[kind](ac); } catch (e) {}
    }

    // ── vibration (Android; iPhone browsers have none) ──────────────────────
    var BUZZ = { tap: [10], success: [14], big: [14, 50, 22], money: [14, 40, 14, 40, 24], warning: [24], error: [32, 45, 32] };
    function buzz(kind) {
        if (!pref('m2-vibrate') || !navigator.vibrate) { return; }
        try { navigator.vibrate(BUZZ[kind] || 0); } catch (e) {}
    }

    // ── toast ───────────────────────────────────────────────────────────────
    var ICONS = {
        ok: '<path d="m5 12.5 4.2 4.2L19 7"/>',
        bad: '<path d="M7 7l10 10M17 7 7 17"/>',
        warn: '<path d="M12 7.5v6M12 17h.01"/>'
    };
    var TONE = { success: 'ok', big: 'ok', money: 'ok', error: 'bad', warning: 'warn' };
    var toastTimer = null;
    function toast(kind, message) {
        if (!message) { return; }
        var tone = TONE[kind] || 'ok';
        var el = document.getElementById('m2-toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'm2-toast';
            el.className = 'm2-toast';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            el.addEventListener('click', hideToast);
            swipeToDismiss(el);
            document.body.appendChild(el);
        }
        el.className = 'm2-toast is-' + tone;
        el.setAttribute('role', tone === 'bad' ? 'alert' : 'status');
        el.innerHTML = '<span class="m2-toast-ico"><svg viewBox="0 0 24 24" aria-hidden="true">' + ICONS[tone] + '</svg></span>'
            + '<span class="m2-toast-msg"></span><i class="m2-toast-bar"></i>';
        el.querySelector('.m2-toast-msg').textContent = message;
        // restart the entrance + countdown animations
        void el.offsetWidth;
        el.classList.add('is-in');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(hideToast, tone === 'bad' ? 4500 : 3200);
        el.style.setProperty('--m2-toast-ms', (tone === 'bad' ? 4500 : 3200) + 'ms');
    }
    function hideToast() {
        var el = document.getElementById('m2-toast');
        if (el) { el.classList.remove('is-in'); el.classList.add('is-out'); }
    }
    function swipeToDismiss(el) {
        var startY = null;
        el.addEventListener('touchstart', function (e) { startY = e.touches[0].clientY; }, { passive: true });
        el.addEventListener('touchend', function (e) {
            if (startY !== null && startY - e.changedTouches[0].clientY > 24) { hideToast(); } // swipe up: it sits at the top
            startY = null;
        }, { passive: true });
    }

    // ── big moment: a check that draws itself over the screen ───────────────
    function celebrate(kind) {
        var el = document.createElement('div');
        el.className = 'm2-burst' + (kind === 'money' ? ' is-money' : '');
        el.setAttribute('aria-hidden', 'true');
        el.innerHTML = '<span class="m2-burst-disc"><svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="m15 27 7.5 7.5L38 19"/></svg></span>';
        document.body.appendChild(el);
        setTimeout(function () { el.remove(); }, reducedMotion ? 700 : 1100);
    }

    // ── failure on a form: shake it, bring the first wrong field into view ───
    function shakeErrors(root) {
        var err = (root || document).querySelector('.m2-err');
        if (!err) { return; }
        var box = err.closest('form, .m2a-card, .m2a-list, .m2-card') || err.parentElement;
        var field = err.parentElement && err.parentElement.querySelector('input:not([type=hidden]):not(.m2-sr), select, textarea');
        try { err.scrollIntoView({ block: 'center', behavior: reducedMotion ? 'auto' : 'smooth' }); } catch (e) {}
        [box, field].forEach(function (node) {
            if (!node) { return; }
            var cls = node === box ? 'm2-shake' : 'm2-pulse-bad';
            node.classList.remove(cls);
            void node.offsetWidth;
            node.classList.add(cls);
            setTimeout(function () { node.classList.remove(cls); }, 900);
        });
    }

    // ── the pressed button flashes ✓ after a save that worked ───────────────
    var lastButton = null;
    document.addEventListener('pointerdown', function (e) {
        audio(); // unlock sound on the first touch
        lastButton = e.target.closest && e.target.closest('.m2-btn');
        var el = e.button > 0 ? null : pressable(e.target);
        if (el) { pressIn(el); }
    }, true);
    document.addEventListener('pointerup', pressOut, true);
    document.addEventListener('pointercancel', pressOut, true); // the finger started a scroll
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.form) { lastButton = e.target.form.querySelector('.m2-btn[type=submit], button[type=submit].m2-btn'); }
    }, true);
    function tickButton() {
        var btn = lastButton;
        lastButton = null;
        if (!btn || !btn.isConnected || btn.classList.contains('is-ghost')) { return; }
        btn.classList.remove('is-done');
        void btn.offsetWidth;
        btn.classList.add('is-done');
        setTimeout(function () { btn.classList.remove('is-done'); }, 1000);
    }

    // ── every tap (back, buttons, rows, links): a tiny buzz and a press-in ──
    // Held, the thing sinks in (at least ~90ms, so a quick tap still shows), then springs back.
    // Wide things (rows, full-width buttons) sink less than tiles, tiles less than small round ones (Back).
    var PRESSABLE = 'button, a[href], [role="button"], label.m2a-row, .m2-btn, .m2-item, .m2a-row, .m2-seg > label';
    function pressable(target) {
        var el = target && target.closest ? target.closest(PRESSABLE) : null;
        if (!el || el.disabled || el.getAttribute('aria-disabled') === 'true'
            || el.classList.contains('is-static') || el.closest('.m2-toast')) { return null; }
        return el;
    }
    var pressedEl = null;
    var pressedAt = 0;
    function pressIn(el) {
        el.classList.remove('m2-unpress');
        var w = el.offsetWidth;
        el.style.setProperty('--m2-press', w > 220 ? '.98' : (w > 100 ? '.95' : '.9'));
        el.classList.add('m2-press');
        pressedEl = el;
        pressedAt = Date.now();
    }
    function pressOut() {
        var el = pressedEl;
        pressedEl = null;
        if (!el) { return; }
        setTimeout(function () {
            el.classList.remove('m2-press');
            el.classList.add('m2-unpress');
            setTimeout(function () { el.classList.remove('m2-unpress'); }, 320);
        }, Math.max(0, 90 - (Date.now() - pressedAt)));
    }
    // On click, not pointerdown: phones only allow vibrate() inside a real tap. One buzz per tap
    // (a label's click also clicks its checkbox).
    var lastTap = 0;
    document.addEventListener('click', function (e) {
        if (!pressable(e.target) || Date.now() - lastTap < 150) { return; }
        lastTap = Date.now();
        buzz('tap');
    }, true);

    // ── one feedback per moment: the strongest kind wins ────────────────────
    var pending = null;
    var flushTimer = null;
    function notify(kind, message) {
        kind = RANK[kind] ? kind : 'success';
        if (!pending || RANK[kind] > RANK[pending.kind] || (RANK[kind] === RANK[pending.kind] && message)) {
            pending = { kind: kind, message: message || (pending && pending.message) };
        } else if (!pending.message && message) {
            pending.message = message;
        }
        clearTimeout(flushTimer);
        flushTimer = setTimeout(flush, 40);
    }
    function flush() {
        var p = pending;
        pending = null;
        if (!p) { return; }
        play(p.kind);
        buzz(p.kind);
        if (p.kind === 'big' || p.kind === 'money') { celebrate(p.kind); }
        if (p.kind !== 'error') { tickButton(); }
        toast(p.kind, p.message || (p.kind === 'error' ? window.m2FeedbackText.checkFields : null));
    }

    // ── sources ─────────────────────────────────────────────────────────────
    var LEVEL = { danger: 'error', error: 'error', warning: 'warning', success: 'success', info: 'success' };
    window.addEventListener('rw-m-toast', function (e) {
        var d = e.detail || {};
        notify(d.kind || LEVEL[d.level] || 'success', d.message);
    });

    // A component that saved without a message to show (e.g. a setup step): sound, buzz, ✓ — no toast.
    window.addEventListener('m2-feedback', function (e) {
        var d = e.detail || {};
        notify(d.kind || 'success', d.message || null);
    });

    // A message flashed before a redirect (printed by the layout on each page).
    function readFlash() {
        var node = document.getElementById('m2-flash');
        if (!node) { return; }
        try {
            var f = JSON.parse(node.textContent || 'null');
            node.remove();
            if (f && f.message) { notify(f.kind || 'success', f.message); }
        } catch (e) {}
    }
    document.addEventListener('DOMContentLoaded', readFlash);
    document.addEventListener('livewire:navigated', readFlash);

    // Markup that appears after an update and means "it worked" / "it failed".
    function scanAdded(el) {
        if (!el || el.nodeType !== 1) { return; }
        var marked = el.matches('[data-m2-feedback]') ? el : el.querySelector('[data-m2-feedback]');
        if (marked) { notify(marked.getAttribute('data-m2-feedback'), marked.getAttribute('data-m2-feedback-message') || null); return; }
        var ok = el.matches('.m2-notice.is-ok') ? el : el.querySelector('.m2-notice.is-ok');
        if (ok) { notify('success', ok.textContent.trim()); return; }
        var bad = el.matches('.m2-notice.is-bad') ? el : el.querySelector('.m2-notice.is-bad');
        if (bad) { notify('error', bad.textContent.trim()); }
    }

    document.addEventListener('livewire:init', function () {
        var Livewire = window.Livewire;

        Livewire.hook('morph.added', function (payload) { scanAdded(payload.el); });

        Livewire.hook('commit', function (payload) {
            var called = payload.commit && payload.commit.calls && payload.commit.calls.length > 0;
            payload.succeed(function () {
                if (!called) { return; }
                // after the morph has put the response on screen
                setTimeout(function () {
                    var root = payload.component && payload.component.el;
                    if (root && root.querySelector('.m2-err')) {
                        notify('error', null);
                        shakeErrors(root);
                    }
                }, 30);
            });
            payload.fail(function () {
                notify('error', window.m2FeedbackText.network);
            });
        });
    });

    window.m2Feedback = {
        notify: notify,
        play: play,
        buzz: buzz,
        canVibrate: !!navigator.vibrate,
        pref: pref,
        setPref: setPref
    };
})();
