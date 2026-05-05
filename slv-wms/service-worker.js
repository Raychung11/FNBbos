// SLV WMS — service-worker.js
//
// Caching strategy (rewritten v0.8.0 to fix a stale-page bug):
//
//   /assets/* + /manifest.json + favicon       → cache-first (truly static)
//   /api/*                                     → network-first, JSON only
//   everything else (.php, /, dashboard, etc.) → NETWORK-FIRST, never serve a
//                                                cached HTML body unless the
//                                                network fails outright.
//
// Why: the previous version cache-first'd EVERY GET, including dashboard and
// login responses. Once admin visited /index.php once, that admin-rendered
// HTML was returned forever — even when a different user was signed in. The
// symptom was "all roles see the super admin dashboard". A .php page is
// dynamic by definition; we must hit the server.
//
// Bump CACHE_VERSION whenever shipping a new client build so old caches are
// purged on the next activation.

const CACHE_VERSION = 'slvwms-v0.8.0';

// Truly static assets — safe to cache aggressively.
const STATIC_ASSETS = [
  '/assets/js/scanner.js',
  '/manifest.json',
  '/assets/img/icon-192.png',
  '/assets/img/icon-512.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then((cache) =>
      cache.addAll(STATIC_ASSETS).catch(() => null)
    )
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  // Wipe every old cache. This drops the bad cache-first entries from
  // earlier versions on first activation.
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

function isStatic(url) {
  if (url.pathname.startsWith('/assets/')) return true;
  if (url.pathname === '/manifest.json')  return true;
  if (url.pathname === '/favicon.ico')    return true;
  return false;
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // 1) Static assets — cache-first, fall through to network on miss.
  if (isStatic(url)) {
    event.respondWith(
      caches.match(req).then((hit) => {
        if (hit) return hit;
        return fetch(req).then((res) => {
          if (res && res.status === 200 && res.type === 'basic') {
            const copy = res.clone();
            caches.open(CACHE_VERSION).then((c) => c.put(req, copy));
          }
          return res;
        });
      })
    );
    return;
  }

  // 2) /api/* JSON — network-first, fall back to cache only if offline.
  //    We DO put successful JSON in the cache so the phone has *something*
  //    to render when the network drops mid-walk; the actual scan POSTs
  //    are still rejected offline (idempotent retry on next reconnect).
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      fetch(req)
        .then((res) => {
          if (res && res.status === 200) {
            const copy = res.clone();
            caches.open(CACHE_VERSION).then((c) => c.put(req, copy));
          }
          return res;
        })
        .catch(() => caches.match(req))
    );
    return;
  }

  // 3) Everything else — including all .php pages, the bare /, /m/*, etc.
  //    NETWORK-FIRST. We do NOT save the HTML body in cache because it's
  //    user-specific (CSRF token, session-derived nav, role banner) and
  //    serving it to a different user would leak / confuse. Falling back
  //    to cache here would re-introduce the v0.7.0 bug.
  event.respondWith(
    fetch(req).catch(() => {
      // True offline — return a minimal text response so the browser shows
      // *something* instead of "no internet". The user can reload when
      // they're back online.
      return new Response(
        '<!doctype html><meta charset=utf-8><title>Offline</title>' +
        '<body style="font-family:system-ui;padding:24px;color:#1f2937;">' +
        '<h1>Offline</h1><p>SLV WMS needs the network to render this page. ' +
        'Reconnect and reload.</p>',
        { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 }
      );
    })
  );
});
