/* TikiPickIt — Service Worker v3 (security hardened) */
const CACHE = 'tikipickit-v1';
const PRECACHE = [
  '/tikipickit/',
  '/tikipickit/index.html',
  '/tikipickit/app.js',
  '/tikipickit/manifest.json',
  '/tikipickit/icons/icon-192.svg'
];

self.addEventListener('install', e => {
  self.skipWaiting();
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(PRECACHE))
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    )
  );
});

function isOwnOrigin(url) {
  try { return new URL(url).origin === self.location.origin; }
  catch (_) { return false; }
}

function isAPI(url) {
  if (!isOwnOrigin(url)) return false;
  try { return new URL(url).pathname.includes('/api/'); }
  catch (_) { return false; }
}

function isAsset(url) {
  if (!isOwnOrigin(url)) return false;
  try {
    const path = new URL(url).pathname;
    return path.endsWith('.js') || path.endsWith('.css') || path.endsWith('.html')
      || path.endsWith('.svg') || path.endsWith('.png') || path.endsWith('.json')
      || path === '/tikipickit/' || path === '/tikipickit';
  } catch (_) { return false; }
}

self.addEventListener('fetch', e => {
  const req = e.request;

  // POST/PUT/DELETE — never intercept, let app.js handle failures
  if (req.method !== 'GET') return;

  // GET API — network first, fallback to cache (own origin only)
  if (isAPI(req.url)) {
    e.respondWith(
      fetch(req).then(res => {
        if (res.ok) {
          const clone = res.clone();
          caches.open(CACHE).then(c => c.put(req, clone));
        }
        return res;
      }).catch(() => caches.match(req).then(cached => {
        if (cached) return cached;
        return new Response(JSON.stringify({ offline: true }), {
          status: 503,
          headers: { 'Content-Type': 'application/json' }
        });
      }))
    );
    return;
  }

  // App assets — cache first (own origin only)
  if (isAsset(req.url)) {
    e.respondWith(
      caches.match(req).then(cached => cached || fetch(req).then(res => {
        const clone = res.clone();
        caches.open(CACHE).then(c => c.put(req, clone));
        return res;
      }))
    );
    return;
  }

  // Everything else — network only, no interception
  e.respondWith(fetch(req).catch(() => new Response('Offline', { status: 503 })));
});
