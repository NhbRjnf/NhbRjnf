const CACHE = "vp-scan-v3";

const PRECACHE = [
  "/scan",
  "/manifest.json",
  "/wp-content/themes/twentytwentyfour/assets/vp-scan.css",
  "/wp-content/themes/twentytwentyfour/assets/vp-scan.js",
];

self.addEventListener("install", (e) => {
  e.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)));
  self.skipWaiting();
});

self.addEventListener("activate", (e) => {
  e.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.map((k) => (k !== CACHE ? caches.delete(k) : Promise.resolve())));
    await self.clients.claim();
  })());
});

self.addEventListener("fetch", (e) => {
  const url = new URL(e.request.url);

  // API и wp-json — сеть сначала (чтобы всегда было актуально)
  if (url.pathname.startsWith("/wp-json/")) {
    e.respondWith(fetch(e.request).catch(() => caches.match(e.request, { ignoreSearch: true })));
    return;
  }

  // Статика — кэш сначала
  e.respondWith(
    caches.match(e.request, { ignoreSearch: true }).then((cached) => {
      if (cached) return cached;
      return fetch(e.request).then((resp) => {
        const copy = resp.clone();
        caches.open(CACHE).then((cache) => cache.put(e.request, copy)).catch(() => {});
        return resp;
      }).catch(() => cached);
    })
  );
});
