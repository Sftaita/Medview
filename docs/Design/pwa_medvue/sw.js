/* MedVue — service worker
   Stratégie :
   - navigation : réseau d'abord, page hors ligne en secours ;
   - icônes, manifest, polices : cache d'abord ;
   - API et requêtes non GET : jamais mises en cache (données de planning = toujours fraîches).
   Incrémentez VERSION à chaque déploiement qui modifie les fichiers précachés. */

const VERSION = 'medvue-v1';
const PRECACHE = [
  '/offline.html',
  '/manifest.webmanifest',
  '/favicon.svg',
  '/favicon.ico',
  '/apple-touch-icon.png',
  '/icons/icon-192.png',
  '/icons/icon-512.png'
];

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(VERSION).then(function (cache) { return cache.addAll(PRECACHE); })
      .then(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(keys.filter(function (k) { return k !== VERSION; })
        .map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin === self.location.origin && url.pathname.startsWith('/api/')) return;

  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(function () { return caches.match('/offline.html'); })
    );
    return;
  }

  const isStatic = /\.(png|svg|ico|webmanifest|woff2?)$/.test(url.pathname)
    || url.hostname === 'fonts.gstatic.com';
  if (isStatic) {
    event.respondWith(
      caches.match(req).then(function (hit) {
        return hit || fetch(req).then(function (res) {
          if (res.ok || res.type === 'opaque') {
            const copy = res.clone();
            caches.open(VERSION).then(function (cache) { cache.put(req, copy); });
          }
          return res;
        });
      })
    );
  }
});
