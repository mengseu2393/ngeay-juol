/**
 * ងាយជួល — ngeay juol PWA Service Worker v2
 *
 * Caching strategy:
 *   - Shell assets (JS/CSS/icons/fonts): cache-first, long TTL
 *   - App shell routes (/, /login, /app/simple): stale-while-revalidate
 *   - Google Fonts: cache-first, 1-year TTL
 *   - Financial routes (invoices, payments, billing, tenants, rentals, subscription): network-only (NEVER cached)
 *   - Livewire / API / POST: network-only (pass-through)
 *   - All other navigations: network-first → /offline.html fallback, NEVER stored
 *     (this covers every /m2 screen: a bill or slip is never shown from cache)
 *   - Livewire's own JS (/livewire/livewire.min.js?id=<hash>): cache-first — versioned by ?id=
 *   - Navigation preload is on: the page request starts while the worker boots
 *   - 'm2-warm' message: the /m2 layout hands over its css/js/font URLs to cache after the
 *     first visit (they were fetched before the worker existed)
 *
 * Financial data is NEVER cached for offline reads or writes.
 */

const CACHE_VERSION = 'v7';
const SHELL_CACHE   = `ngeay-juol-shell-${CACHE_VERSION}`;
const FONT_CACHE    = `ngeay-juol-fonts-${CACHE_VERSION}`;

/** Static assets and pages to pre-cache on install */
const PRECACHE_ASSETS = [
    '/offline.html',
    '/favicon.ico',
    '/icons/icon-192.png',
    '/icons/icon-512.png'
];

/** Routes that must ALWAYS go to the network — no cache reads or writes */
const NETWORK_ONLY_PREFIXES = [
    '/app/invoices',
    '/app/monthly-billing',
    '/app/payments',
    '/app/rentals',
    '/app/utility-usages',
    // Legacy prefix — the panel moved to /app, but old installs may still request
    // these. They 301 to /app/*; never serve or store a cached redirect for money screens.
    '/landlord',
    '/livewire',
    '/api',
    '/locale',
    '/logout'
];

// ── Install: pre-cache shell + offline page ─────────────────────
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE).then((cache) => {
            return cache.addAll(
                PRECACHE_ASSETS.map((url) => new Request(url, { credentials: 'same-origin' }))
            );
        }).catch((err) => {
            console.warn('[SW] Pre-cache partially failed:', err);
        })
    );
    self.skipWaiting();
});

// ── Activate: delete stale caches ───────────────────────────────
self.addEventListener('activate', (event) => {
    const validCaches = [SHELL_CACHE, FONT_CACHE];
    event.waitUntil(Promise.all([
        caches.keys().then((names) =>
            Promise.all(
                names
                    .filter((n) => !validCaches.includes(n))
                    .map((n) => caches.delete(n))
            )
        ),
        // Start the page request in parallel with the worker's boot instead of after it.
        self.registration.navigationPreload
            ? self.registration.navigationPreload.enable().catch(() => {})
            : Promise.resolve(),
    ]));
    self.clients.claim();
});

// ── Warm: cache the files a page already loaded (sent by the /m2 layout) ──
self.addEventListener('message', (event) => {
    const data = event.data || {};
    if (data.type !== 'm2-warm' || !Array.isArray(data.urls)) return;
    event.waitUntil((async () => {
        const cache = await caches.open(SHELL_CACHE);
        for (const href of data.urls.slice(0, 30)) {
            let url;
            try { url = new URL(href, self.location.origin); } catch { continue; }
            if (url.origin !== self.location.origin) continue;
            if (!(isStaticAsset(url) || isPanelAsset(url) || isLivewireScript(url))) continue;
            const request = new Request(url.href, { credentials: 'same-origin' });
            if (await cache.match(request)) continue;
            try {
                const response = await fetch(request);
                if (response.ok) {
                    await cache.put(request, response);
                    await evictOtherVersions(cache, request);
                }
            } catch { /* offline or gone: the next real load caches it */ }
        }
    })());
});

// ── Helpers ──────────────────────────────────────────────────────

function isNetworkOnly(url) {
    return NETWORK_ONLY_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));
}

/**
 * Panel assets (rentwise-admin.css, Filament's compiled css/js, our loader). They are
 * versioned by ?v=<mtime> / Filament's own hash, so a changed file is a new cache key
 * and cache-first is safe — the app shell no longer re-downloads ~300KB on every launch.
 */
function isPanelAsset(url) {
    return (
        url.pathname.startsWith('/css/') ||
        url.pathname.startsWith('/js/')  ||
        url.pathname.startsWith('/fonts/')
    );
}

function isStaticAsset(url) {
    return (
        url.pathname.startsWith('/build/')   ||
        url.pathname.startsWith('/icons/')   ||
        url.pathname === '/favicon.ico'
        // Not /manifest.json: it is served by the app and follows the mobile
        // shell's flag (start_url), so it must come from the network.
    );
}

/**
 * Livewire's script, served by PHP (on Vercel every fetch is a function call). Its URL
 * carries ?id=<hash of the file>, so a Livewire upgrade is a new cache key: cache-first
 * is safe. Checked before the network-only '/livewire' prefix, which stays for updates.
 */
function isLivewireScript(url) {
    return (url.pathname === '/livewire/livewire.min.js' || url.pathname === '/livewire/livewire.js')
        && url.searchParams.has('id');
}

function isGoogleFont(url) {
    return url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com';
}

function isShellRoute(url) {
    return false;
}

function isNavigation(request) {
    return request.mode === 'navigate';
}

async function cacheFirst(request, cacheName) {
    const cached = await caches.match(request);
    if (cached) return cached;
    const response = await fetch(request);
    if (response.ok) {
        const cache = await caches.open(cacheName);
        cache.put(request, response.clone());
        evictOtherVersions(cache, request);
    }
    return response;
}

/**
 * Panel assets are versioned by query string (?v=<mtime>): a new version is a new
 * cache key, and the old one would otherwise stay cached forever. Drop every other
 * copy of the same file once the new one is in.
 */
async function evictOtherVersions(cache, request) {
    const url = new URL(request.url);
    if (! url.search) return;
    const keys = await cache.keys();
    await Promise.all(keys
        .filter((key) => {
            const other = new URL(key.url);
            return other.pathname === url.pathname && other.search !== url.search;
        })
        .map((key) => cache.delete(key)));
}

async function staleWhileRevalidate(request, cacheName) {
    const cache   = await caches.open(cacheName);
    const cached  = await cache.match(request);
    const fetchPromise = fetch(request).then((response) => {
        if (response.ok) cache.put(request, response.clone());
        return response;
    }).catch(() => null);
    return cached || fetchPromise;
}

/**
 * Pages are never written to the cache: /m2 screens show money (bills, slips, dues),
 * and an old copy of one must never be shown as current. Offline → /offline.html.
 */
async function networkFirstWithOfflineFallback(event) {
    const request = event.request;
    try {
        const preloaded = await event.preloadResponse;
        if (preloaded) return preloaded;
        return await fetch(request);
    } catch {
        const cached = await caches.match(request);
        if (cached) return cached;
        // Return the styled offline page for navigation requests
        return caches.match('/offline.html');
    }
}

// ── Fetch handler ────────────────────────────────────────────────
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // 1. Only handle same-origin GET (Livewire uses POST — passes through automatically)
    if (event.request.method !== 'GET') return;

    // 2. Cross-origin: delegate to browser except Google Fonts
    if (url.origin !== self.location.origin) {
        if (isGoogleFont(url)) {
            event.respondWith(cacheFirst(event.request, FONT_CACHE));
        }
        return;
    }

    // 3. Livewire's versioned script: cache-first (before the network-only '/livewire' rule)
    if (isLivewireScript(url)) {
        event.respondWith(cacheFirst(event.request, SHELL_CACHE));
        return;
    }

    // 3b. Financial / dynamic routes: network-only, no caching. A navigation still answers with
    //     the preloaded response — with navigation preload on, letting the browser fetch it again
    //     would hit the server twice.
    if (isNetworkOnly(url)) {
        if (isNavigation(event.request)) {
            event.respondWith((async () => (await event.preloadResponse) || fetch(event.request))());
        }
        return;
    }

    // 4. Static assets: cache-first
    if (isStaticAsset(url)) {
        event.respondWith(cacheFirst(event.request, SHELL_CACHE));
        return;
    }

    // 4b. Versioned panel css/js: cache-first (a new ?v= is a new key)
    if (isPanelAsset(url)) {
        event.respondWith(cacheFirst(event.request, SHELL_CACHE));
        return;
    }

    // 5. Shell navigation routes: stale-while-revalidate (fast load)
    if (isShellRoute(url)) {
        event.respondWith(staleWhileRevalidate(event.request, SHELL_CACHE));
        return;
    }

    // 6. All other navigation (page requests): network-first → offline.html fallback
    if (isNavigation(event.request)) {
        event.respondWith(networkFirstWithOfflineFallback(event));
        return;
    }

    // 7. Everything else: let the browser handle it
});
