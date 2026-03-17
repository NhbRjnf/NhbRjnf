const SW_VERSION = (() => {
  try {
    return new URL(self.location.href).searchParams.get('v') || '1';
  } catch (_) {
    return '1';
  }
})();

const STATIC_CACHE = `vp-static-${SW_VERSION}`;
const HTML_CACHE = `vp-html-${SW_VERSION}`;
const CACHE_PREFIXES = ['vp-static-', 'vp-html-', 'vp-scan-'];

const PRECACHE = [
  '/scan',
  '/manifest.json',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    (async () => {
      const cache = await caches.open(STATIC_CACHE);
      await cache.addAll(PRECACHE);
      await self.skipWaiting();
    })()
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(
        keys.map((key) => {
          const isVpCache = CACHE_PREFIXES.some((prefix) => key.startsWith(prefix));
          const isCurrent = key === STATIC_CACHE || key === HTML_CACHE;
          if (isVpCache && !isCurrent) {
            return caches.delete(key);
          }
          return Promise.resolve(false);
        })
      );
      await self.clients.claim();
    })()
  );
});

self.addEventListener('message', (event) => {
  if (!event || !event.data) {
    return;
  }

  if (event.data.type === 'VP_HARD_RELOAD') {
    event.waitUntil(
      (async () => {
        const keys = await caches.keys();
        await Promise.all(
          keys.map((key) => {
            if (CACHE_PREFIXES.some((prefix) => key.startsWith(prefix))) {
              return caches.delete(key);
            }
            return Promise.resolve(false);
          })
        );
      })()
    );
  }
});

function isNavigationRequest(request) {
  return request.mode === 'navigate' || (request.headers.get('accept') || '').includes('text/html');
}

function shouldBypassCache(url, request) {
  if (request.method !== 'GET') {
    return true;
  }

  if (url.origin !== self.location.origin) {
    return true;
  }

  if (url.pathname.startsWith('/wp-json/')) {
    return true;
  }

  if (url.pathname.startsWith('/wp-admin/') || url.pathname.startsWith('/wp-login.php')) {
    return true;
  }

  if (url.pathname.startsWith('/dl/')) {
    return true;
  }

  return false;
}

async function networkFirst(request) {
  const cache = await caches.open(HTML_CACHE);
  try {
    const response = await fetch(request);
    if (response && response.ok) {
      cache.put(request, response.clone()).catch(() => {});
    }
    return response;
  } catch (_) {
    const cached = await cache.match(request, { ignoreSearch: false });
    if (cached) {
      return cached;
    }
    throw _;
  }
}

async function staleWhileRevalidate(request) {
  const cache = await caches.open(STATIC_CACHE);
  const cached = await cache.match(request, { ignoreSearch: false });

  const networkPromise = fetch(request)
    .then((response) => {
      if (response && response.ok) {
        cache.put(request, response.clone()).catch(() => {});
      }
      return response;
    })
    .catch(() => null);

  if (cached) {
    return cached;
  }

  const networkResponse = await networkPromise;
  if (networkResponse) {
    return networkResponse;
  }

  return fetch(request);
}

self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  if (shouldBypassCache(url, request)) {
    return;
  }

  if (isNavigationRequest(request)) {
    event.respondWith(networkFirst(request));
    return;
  }

  event.respondWith(staleWhileRevalidate(request));
});