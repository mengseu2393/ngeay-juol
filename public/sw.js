/**
 * ងាយជួល — ngeay juol PWA Service Worker v2
 *
 * Caching strategy:
 *   - Shell assets (JS/CSS/icons/fonts): cache-first, long TTL
 *   - App shell routes (/, /login, /app/simple): stale-while-revalidate
 *   - Google Fonts: cache-first, 1-year TTL
 *   - Financial routes (invoices, payments, billing, tenants, rentals, subscription): network-only (NEVER cached)
 *   - Livewire / API / POST: network-only (pass-through)
 *   - All other navigations: network-first → /offline.html fallback
 *
 * Financial data is NEVER cached for offline reads or writes.
 */

const CACHE_VERSION = 'v4';
const SHELL_CACHE   = `ngeay-juol-shell-${CACHE_VERSION}`;
const FONT_CACHE    = `ngeay-juol-fonts-${CACHE_VERSION}`;

/** Static assets and pages to pre-cache on install */
const PRECACHE_ASSETS = [
    '/offline.html',
    '/favicon.ico',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/manifest.json'
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
    event.waitUntil(
        caches.keys().then((names) =>
            Promise.all(
                names
                    .filter((n) => !validCaches.includes(n))
                    .map((n) => caches.delete(n))
            )
        )
    );
    self.clients.claim();
});

// ── Helpers ──────────────────────────────────────────────────────

function isNetworkOnly(url) {
    return NETWORK_ONLY_PREFIXES.some((prefix) => url.pathname.startsWith(prefix));
}

function isStaticAsset(url) {
    return (
        url.pathname.startsWith('/build/')   ||
        url.pathname.startsWith('/icons/')   ||
        url.pathname === '/favicon.ico'      ||
        url.pathname === '/manifest.json'
    );
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
    }
    return response;
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

async function networkFirstWithOfflineFallback(request) {
    try {
        const response = await fetch(request);
        return response;
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

    // 3. Financial / dynamic routes: network-only, no caching
    if (isNetworkOnly(url)) return;

    // 4. Static assets: cache-first
    if (isStaticAsset(url)) {
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
        event.respondWith(networkFirstWithOfflineFallback(event.request));
        return;
    }

    // 7. Everything else: let the browser handle it
});
