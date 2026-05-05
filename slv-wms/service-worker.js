// SLV WMS — service-worker.js
//
// Caching strategy (v0.9.0 — aggressive cleanup of v0.7.0 leftovers):
//
//   /assets/* + /manifest.json + favicon       → cache-first (truly static)
//   /api/*                                     → network-first, JSON only
//   everything else (.php, /, dashboard, etc.) → NETWORK-FIRST with
//                                                cache: 'reload' so the
//                                                browser HTTP cache is
//                                                also bypassed (otherwise
//                                                a v0.7.0-era cached
//                                                /index.php with admin's
//                                                HTML keeps coming back
//                                                until the user manually
//                                                clears their cache).
//
// Plus on every dynamic-page GET we proactively delete that URL from
// every cache the SW owns — so any leftover from an older SW version
// drops on first visit instead of waiting for activate.

const CACHE_VERSION = 'slvwms-v0.9.0';

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
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

function isStatic(url) {
  if (url.pathname.startsWith('/assets/')) return true;
  if (url.pathname === '/manifest.json')   return true;
  if (url.pathname === '/favicon.ico')     return true;
  return false;
}

// Wipe a URL from every cache the SW currently owns. Used to evict
// leftover entries from older SW versions on the way past.
async function wipeFromAllCaches(req) {
  const keys = await caches.keys();
  await Promise.all(keys.map(async (k) => {
    const c = await caches.open(k);
    await c.delete(req);
    await c.delete(new Request(req.url, { method: 'GET' }));
  }));
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // 1) Static assets — cache-first.
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

  // 2) /api/* JSON — network-first, cache as offline fallback.
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

  // 3) Everything else (dynamic .php). NEVER cache, NEVER touch the
  //    browser HTTP cache. cache:'reload' tells fetch() to ignore the
  //    browser HTTP cache entirely and revalidate from the origin —
  //    this is the lever that finally evicts leftover admin-rendered
  //    /index.php from earlier SW versions.
  event.respondWith((async () => {
    // Sweep this URL out of any cache the SW still has.
    await wipeFromAllCaches(req);
    try {
      return await fetch(req, { cache: 'reload', credentials: 'same-origin' });
    } catch (_) {
      return new Response(
        '<!doctype html><meta charset=utf-8><title>Offline</title>' +
        '<body style="font-family:system-ui;padding:24px;color:#1f2937;">' +
        '<h1>Offline</h1><p>SLV WMS needs the network to render this page. ' +
        'Reconnect and reload.</p>',
        { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 }
      );
    }
  })());
});
