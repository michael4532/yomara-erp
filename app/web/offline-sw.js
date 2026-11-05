// Yomara ERP — cache de l'application web pour un démarrage hors ligne.
// Les appels /api ne sont jamais mis en cache (les données hors ligne vivent dans IndexedDB).
const CACHE = 'yomara-erp-shell-v2';

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(['./', 'index.html', 'flutter_bootstrap.js', 'main.dart.js', 'manifest.json'])).catch(() => {}));
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))));
  self.clients.claim();
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.pathname.includes('/api/') || url.origin !== self.location.origin) return;

  // Réseau d'abord (toujours la dernière version), cache en secours hors ligne.
  e.respondWith(
    // no-cache : le navigateur revalide auprès du serveur, une mise à jour est visible dès le rechargement
    fetch(e.request, { cache: 'no-cache' })
      .then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(e.request, copy));
        }
        return res;
      })
      .catch(() => caches.match(e.request).then((r) => r || caches.match('index.html')))
  );
});
