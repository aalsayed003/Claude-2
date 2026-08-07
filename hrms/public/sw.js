/*
 * ASSH HRMS service worker.
 *
 * Deliberately conservative for a system that shows per-user HR data:
 *   - Static assets (CSS, icons, logo, this manifest) are cached and served
 *     cache-first with a background refresh (fast, works on flaky signal).
 *   - HTML navigations are ALWAYS network-first and are NEVER cached — pages
 *     carry per-user data and CSRF tokens, so caching them would risk stale or
 *     cross-user content. If the network is unreachable we show offline.html.
 *   - Only GET requests are ever touched; POST (approvals, leave, uploads)
 *     always goes straight to the network.
 *
 * Bump CACHE when static assets change to invalidate old copies.
 */
const CACHE = 'assh-hrms-v1';
const OFFLINE = 'offline.html';

// Resolve asset URLs relative to the SW scope so it works under any base path.
const asset = (p) => new URL(p, self.registration.scope).toString();
const PRECACHE = [
  'offline.html',
  'assets/app.css',
  'assets/assh-logo.jpg',
  'assets/icons/icon-192.png',
  'assets/icons/icon-512.png',
  'manifest.webmanifest',
].map(asset);

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE)
      .then((c) => Promise.allSettled(PRECACHE.map((u) => c.add(u))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

function isStatic(url) {
  return /\.(css|js|png|jpg|jpeg|gif|webp|svg|ico|woff2?|ttf|webmanifest)$/i.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;                       // never touch POST etc.
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;        // ignore cross-origin

  // App navigations: network-first, offline page as the only fallback.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match(asset(OFFLINE)))
    );
    return;
  }

  // Static assets: cache-first with background refresh.
  if (isStatic(url)) {
    event.respondWith(
      caches.match(req).then((hit) => {
        const net = fetch(req).then((res) => {
          if (res && res.ok) {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(req, copy));
          }
          return res;
        }).catch(() => hit);
        return hit || net;
      })
    );
  }
});
