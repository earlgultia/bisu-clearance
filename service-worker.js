const CACHE_VERSION = "bisu-clearance-v2";
const CORE_CACHE = `${CACHE_VERSION}-core`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;

const appRoot = (() => {
  const scopePath = new URL(self.registration.scope).pathname;
  return scopePath.endsWith("/") ? scopePath : `${scopePath}/`;
})();

const coreAssets = [
  appRoot,
  `${appRoot}index.php`,
  `${appRoot}login.php`,
  `${appRoot}register.php`,
  `${appRoot}forgot-password.php`,
  `${appRoot}offline.html`,
  `${appRoot}manifest.webmanifest`,
  `${appRoot}assets/js/pwa-register.js`,
  `${appRoot}assets/img/pwa-icon-192.png`,
  `${appRoot}assets/img/pwa-icon-512.png`,
  `${appRoot}assets/img/pwa-icon-maskable-192.png`,
  `${appRoot}assets/img/pwa-icon-maskable-512.png`,
  `${appRoot}assets/img/pwa-icon.svg`,
  `${appRoot}assets/img/logo.png`
].map((path) => new URL(path, self.location.origin).toString());

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(CORE_CACHE)
      .then((cache) => cache.addAll(coreAssets))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter((key) => key.startsWith("bisu-clearance-") && key !== CORE_CACHE && key !== RUNTIME_CACHE)
            .map((key) => caches.delete(key))
        )
      )
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (event) => {
  if (event.request.method !== "GET") {
    return;
  }

  const requestUrl = new URL(event.request.url);
  if (requestUrl.origin !== self.location.origin || !requestUrl.pathname.startsWith(appRoot)) {
    return;
  }

  if (requestUrl.pathname.endsWith("logout.php") || requestUrl.pathname.includes("/get_")) {
    return;
  }

  if (event.request.mode === "navigate") {
    event.respondWith(handleNavigation(event.request));
    return;
  }

  if (isStaticAsset(requestUrl.pathname)) {
    event.respondWith(staleWhileRevalidate(event.request));
    return;
  }

  event.respondWith(networkFirst(event.request));
});

self.addEventListener("message", (event) => {
  if (event.data && event.data.type === "SKIP_WAITING") {
    self.skipWaiting();
  }
});

async function handleNavigation(request) {
  try {
    const networkResponse = await fetch(request);
    const cache = await caches.open(RUNTIME_CACHE);
    cache.put(request, networkResponse.clone());
    return networkResponse;
  } catch (error) {
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }

    const offlineFallback = await caches.match(`${appRoot}offline.html`);
    return offlineFallback || new Response("Offline", { status: 503, statusText: "Offline" });
  }
}

async function networkFirst(request) {
  const cache = await caches.open(RUNTIME_CACHE);

  try {
    const networkResponse = await fetch(request);
    if (networkResponse && networkResponse.ok) {
      cache.put(request, networkResponse.clone());
    }
    return networkResponse;
  } catch (error) {
    const cachedResponse = await cache.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }
    return caches.match(request);
  }
}

async function staleWhileRevalidate(request) {
  const cache = await caches.open(RUNTIME_CACHE);
  const cachedResponse = await cache.match(request);

  const networkFetch = fetch(request)
    .then((networkResponse) => {
      if (networkResponse && networkResponse.ok) {
        cache.put(request, networkResponse.clone());
      }
      return networkResponse;
    })
    .catch(() => null);

  return cachedResponse || networkFetch || caches.match(request);
}

function isStaticAsset(pathname) {
  return /\.(?:css|js|png|jpg|jpeg|svg|gif|webp|ico|woff|woff2|ttf)$/i.test(pathname);
}
