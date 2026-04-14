/**
 * HomeCare service worker (HC-060).
 *
 * Cache strategy:
 *   - Static assets (CSS, JS, icons, manifest): cache-first with a
 *     stale-while-revalidate fallback. Bumps when CACHE_VERSION
 *     changes — bump it whenever pub/ assets change.
 *   - Everything else (PHP pages, API endpoints): network-only. We
 *     never cache HTML/JSON because adherence numbers, intake
 *     history, and supply alerts must be live.
 *
 * Scope: served from the document root, so the whole `/homecare/`
 * (or `/`) tree is in scope. Registered from print_header().
 */

const CACHE_VERSION = 'homecare-shell-v1';
const SHELL_ASSETS = [
  'manifest.json',
  'pub/bootstrap.min.css',
  'pub/bootstrap.bundle.min.js',
  'pub/jquery.min.js',
  'pub/chart.umd.min.js',
  'pub/icons/icon-192.png',
  'pub/icons/icon-512.png',
  'pub/icons/icon-maskable-512.png',
  'favicon.ico',
];

self.addEventListener('install', (event) => {
  // Pre-warm the cache with the static shell so the first offline
  // visit doesn't 404 on stylesheets.
  event.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => cache.addAll(SHELL_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  // Drop old shell caches when CACHE_VERSION bumps.
  event.waitUntil(
    caches.keys().then((names) =>
      Promise.all(
        names
          .filter((n) => n.startsWith('homecare-shell-') && n !== CACHE_VERSION)
          .map((n) => caches.delete(n))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;

  // Only intercept GETs; POSTs / handlers / API writes go straight to
  // the network so we don't risk replaying a stale form submit.
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  // Same-origin only — never proxy third-party fetches.
  if (url.origin !== self.location.origin) return;

  // Only handle the static-shell paths. Everything else (PHP, API)
  // falls through to the browser's normal network path.
  const isShellAsset =
    /\.(css|js|png|jpg|jpeg|svg|ico|woff2?|ttf)$/i.test(url.pathname) ||
    url.pathname.endsWith('/manifest.json');
  if (!isShellAsset) return;

  event.respondWith(
    caches.match(req).then((cached) => {
      const network = fetch(req)
        .then((resp) => {
          // Stash a copy for next time. Clone because the body stream
          // can only be read once.
          if (resp && resp.status === 200) {
            const copy = resp.clone();
            caches.open(CACHE_VERSION).then((cache) => cache.put(req, copy));
          }
          return resp;
        })
        .catch(() => cached); // offline: serve whatever we have
      return cached || network;
    })
  );
});
