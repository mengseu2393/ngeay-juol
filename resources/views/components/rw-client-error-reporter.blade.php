{{--
    Browser-side failure reporter → /admin Error Log (ErrorLogType::Client).

    The "Simple Mode sometimes breaks on my phone" class of bug never reaches the
    server: a JS exception, a Livewire request that died on a flaky connection or
    came back 5xx (Livewire then dumps the raw error page into an overlay), a
    stylesheet/script that failed to load. This reports each one with the device
    context needed to reproduce it — installed app vs browser tab, viewport, and
    pinch/focus zoom (iOS zooms into <16px inputs and stays zoomed).

    Belongs in <head>, signed-in pages only (the endpoint is auth + throttle):
    early enough to catch boot errors, and in the part of the document Livewire's
    SPA navigation keeps, so it is installed once per real page load.

    Must never throw and never report itself. Reports that can't be sent (offline)
    wait in localStorage and go out when the connection returns.
--}}
<script>
(function () {
    'use strict';

    if (window.rwClientErrors) {
        return;
    }

    var ENDPOINT = @js(route('client-errors.store', absolute: false));
    var QUEUE_KEY = 'rw-client-error-queue';
    var MAX_PER_PAGE = 10;
    var MAX_QUEUED = 10;
    var seen = {};
    var sent = 0;

    function csrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');

        return (meta && meta.getAttribute('content'))
            || (window.livewireScriptConfig && window.livewireScriptConfig.csrf)
            || '';
    }

    function str(value, max) {
        if (value === undefined || value === null) {
            return null;
        }

        value = String(value);

        return value.length > max ? value.slice(0, max) : value;
    }

    function clientContext() {
        var context = {};

        try {
            var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
            var vv = window.visualViewport;
            var connection = navigator.connection || {};
            var screen = new URLSearchParams(location.search).get('screen');

            context.display_mode = standalone ? 'standalone' : 'browser';
            context.viewport = window.innerWidth + 'x' + window.innerHeight;
            context.zoom = vv ? String(Math.round(vv.scale * 100) / 100) : null;
            context.dpr = String(window.devicePixelRatio || 1);
            context.online = String(navigator.onLine);
            context.connection = connection.effectiveType || null;
            context.screen = screen;
            context.visibility = document.visibilityState;
            context.focused = document.activeElement && document.activeElement !== document.body
                ? (document.activeElement.tagName.toLowerCase() + (document.activeElement.id ? '#' + document.activeElement.id : ''))
                : null;
        } catch (e) {
            // Context is best-effort.
        }

        Object.keys(context).forEach(function (key) {
            if (context[key] === null || context[key] === undefined) {
                delete context[key];
            } else {
                context[key] = str(context[key], 500);
            }
        });

        return context;
    }

    function readQueue() {
        try {
            return JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]') || [];
        } catch (e) {
            return [];
        }
    }

    function writeQueue(queue) {
        try {
            if (queue.length) {
                localStorage.setItem(QUEUE_KEY, JSON.stringify(queue.slice(-MAX_QUEUED)));
            } else {
                localStorage.removeItem(QUEUE_KEY);
            }
        } catch (e) {
            // Private mode / storage blocked: the report is simply lost.
        }
    }

    function send(report) {
        try {
            return fetch(ENDPOINT, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(report),
            }).then(function (response) {
                // 4xx (validation, throttled, signed out) won't succeed on retry either.
                return response.status < 500;
            }).catch(function () {
                return false;
            });
        } catch (e) {
            return Promise.resolve(false);
        }
    }

    function report(kind, message, extra) {
        try {
            extra = extra || {};

            var key = kind + '|' + message + '|' + (extra.source || '') + '|' + (extra.line || '');

            if (seen[key] || sent >= MAX_PER_PAGE) {
                return;
            }

            seen[key] = true;
            sent++;

            var payload = {
                kind: kind,
                message: str(message, 2000) || '(no message)',
                page: str(location.href, 2000),
                source: str(extra.source, 2000),
                line: typeof extra.line === 'number' ? extra.line : null,
                column: typeof extra.column === 'number' ? extra.column : null,
                stack: str(extra.stack, 20000),
                status: typeof extra.status === 'number' ? extra.status : null,
                client: Object.assign(clientContext(), extra.client || {}),
            };

            send(payload).then(function (ok) {
                if (! ok) {
                    var queue = readQueue();
                    queue.push(payload);
                    writeQueue(queue);
                }
            });
        } catch (e) {
            // Never let the reporter become the error.
        }
    }

    function flushQueue() {
        var queue = readQueue();

        if (! queue.length || ! navigator.onLine) {
            return;
        }

        writeQueue([]);

        queue.forEach(function (payload) {
            payload.client = Object.assign({}, payload.client || {}, { queued: 'true' });

            send(payload).then(function (ok) {
                if (! ok) {
                    var again = readQueue();
                    again.push(payload);
                    writeQueue(again);
                }
            });
        });
    }

    // ── Uncaught errors + failed <script>/<link> loads (capture phase) ──
    window.addEventListener('error', function (event) {
        var target = event.target;

        if (target && target !== window && (target.tagName === 'SCRIPT' || target.tagName === 'LINK')) {
            report('error', 'Failed to load ' + target.tagName.toLowerCase(), {
                source: target.src || target.href,
            });

            return;
        }

        report('error', event.message || 'Script error', {
            source: event.filename,
            line: event.lineno,
            column: event.colno,
            stack: event.error && event.error.stack,
        });
    }, true);

    window.addEventListener('unhandledrejection', function (event) {
        var reason = event.reason;

        report('rejection', (reason && (reason.message || String(reason))) || 'Unhandled rejection', {
            stack: reason && reason.stack,
        });
    });

    // ── Livewire requests that failed (401/419 are handled by the login redirect) ──
    function componentNames(payload) {
        try {
            return JSON.parse(payload).components.map(function (component) {
                return JSON.parse(component.snapshot).memo.name;
            }).join(', ');
        } catch (e) {
            return null;
        }
    }

    function hookLivewire() {
        if (! window.Livewire || hookLivewire.done) {
            return;
        }

        hookLivewire.done = true;

        window.Livewire.hook('request', function (request) {
            request.fail(function (failure) {
                if (failure.status === 401 || failure.status === 419) {
                    return;
                }

                // Livewire reports a fetch that never got a response as a 503 with no body.
                var network = failure.content === null || failure.content === undefined;

                report(network ? 'network' : 'livewire',
                    network ? 'Livewire request never reached the server' : 'Livewire request failed: HTTP ' + failure.status,
                    {
                        status: failure.status,
                        source: request.uri,
                        stack: network ? null : str(failure.content, 4000),
                        client: { components: str(componentNames(request.payload), 500) },
                    });
            });
        });
    }

    document.addEventListener('livewire:init', hookLivewire);
    hookLivewire();

    window.addEventListener('online', flushQueue);
    window.addEventListener('load', flushQueue);

    window.rwClientErrors = { report: report, flush: flushQueue };
})();
</script>
