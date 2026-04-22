const CACHE_VERSION = "bisu-clearance-v15";
const CORE_CACHE = `${CACHE_VERSION}-core`;
const RUNTIME_CACHE = `${CACHE_VERSION}-runtime`;
const APP_ASSET_VERSION = "20260422-2";

const appRoot = (() => {
  const scopePath = new URL(self.registration.scope).pathname;
  return scopePath.endsWith("/") ? scopePath : `${scopePath}/`;
})();

const baseCoreAssets = [
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
  `${appRoot}assets/img/logo.png`,
  `${appRoot}assets/img/bisu-candijay-campus-offline-map.svg`
];

const versionedCoreAssets = [
  `${appRoot}manifest.webmanifest?v=${APP_ASSET_VERSION}`,
  `${appRoot}assets/js/pwa-register.js?v=${APP_ASSET_VERSION}`,
  `${appRoot}assets/img/pwa-icon-192.png?v=${APP_ASSET_VERSION}`,
  `${appRoot}assets/img/pwa-icon-512.png?v=${APP_ASSET_VERSION}`,
  `${appRoot}assets/img/pwa-icon-maskable-192.png?v=${APP_ASSET_VERSION}`,
  `${appRoot}assets/img/pwa-icon-maskable-512.png?v=${APP_ASSET_VERSION}`,
  `${appRoot}assets/img/pwa-icon.svg?v=${APP_ASSET_VERSION}`
];

const coreAssets = [...baseCoreAssets, ...versionedCoreAssets].map((path) => new URL(path, self.location.origin).toString());

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

  if (requestUrl.pathname.endsWith("logout.php") || requestUrl.pathname.includes("/get_") || requestUrl.pathname.endsWith("push_notifications.php")) {
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

self.addEventListener("push", (event) => {
  const payload = parsePushPayload(event);
  const notificationTitle = payload.title || "BISU Clearance Update";
  const notificationOptions = buildNotificationOptions(payload);

  event.waitUntil(self.registration.showNotification(notificationTitle, notificationOptions));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();

  const data = event.notification && event.notification.data ? event.notification.data : {};
  const targetUrl = resolveNotificationUrl(data.url || "");

  event.waitUntil(focusOrOpenUrl(targetUrl));
});

self.addEventListener("pushsubscriptionchange", (event) => {
  event.waitUntil(refreshPushSubscription());
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

function parsePushPayload(event) {
  if (!event || !event.data) {
    return {};
  }

  try {
    const jsonPayload = event.data.json();
    if (jsonPayload && typeof jsonPayload === "object") {
      return jsonPayload;
    }
  } catch (error) {
    // Fall back to plain text payload parsing.
  }

  try {
    const textPayload = event.data.text();
    return textPayload ? { body: textPayload } : {};
  } catch (error) {
    return {};
  }
}

function buildNotificationOptions(payload) {
  const defaultIcon = `${appRoot}assets/img/pwa-icon-192.png?v=${APP_ASSET_VERSION}`;
  const defaultBadge = `${appRoot}assets/img/pwa-icon-maskable-192.png?v=${APP_ASSET_VERSION}`;
  const bodyText = typeof payload.body === "string" && payload.body.trim() !== ""
    ? payload.body
    : "You have a new clearance update.";

  return {
    body: bodyText,
    icon: payload.icon || defaultIcon,
    badge: payload.badge || defaultBadge,
    tag: payload.tag || "bisu-clearance-notification",
    renotify: false,
    data: {
      url: resolveNotificationUrl(payload.url || ""),
      eventType: payload.event_type || "notice",
      notificationId: payload.notification_id || null,
      meta: payload.meta || null
    }
  };
}

function resolveNotificationUrl(rawUrl) {
  const fallbackUrl = new URL(`${appRoot}student/dashboard.php?tab=messages`, self.location.origin).toString();

  if (typeof rawUrl !== "string" || rawUrl.trim() === "") {
    return fallbackUrl;
  }

  try {
    return new URL(rawUrl, self.location.origin).toString();
  } catch (error) {
    return fallbackUrl;
  }
}

async function focusOrOpenUrl(targetUrl) {
  const normalizedTargetUrl = resolveNotificationUrl(targetUrl || "");
  const targetOrigin = new URL(normalizedTargetUrl).origin;
  const windowClients = await clients.matchAll({ type: "window", includeUncontrolled: true });

  for (const client of windowClients) {
    if (!client || typeof client.url !== "string") {
      continue;
    }

    try {
      const clientOrigin = new URL(client.url).origin;
      if (clientOrigin === targetOrigin) {
        if (typeof client.navigate === "function" && client.url !== normalizedTargetUrl) {
          try {
            await client.navigate(normalizedTargetUrl);
          } catch (error) {
            // Ignore navigate errors and still focus current client.
          }
        }

        if (typeof client.focus === "function") {
          return client.focus();
        }
      }
    } catch (error) {
      // Ignore malformed client URLs.
    }
  }

  return clients.openWindow(normalizedTargetUrl);
}

async function refreshPushSubscription() {
  if (!(self.registration && self.registration.pushManager)) {
    return;
  }

  try {
    const configResponse = await fetch(`${appRoot}push_notifications.php?action=config`, {
      method: "GET",
      credentials: "include",
      cache: "no-store"
    });

    if (!configResponse.ok) {
      return;
    }

    const config = await configResponse.json();
    if (!config || !config.enabled || !config.public_key) {
      return;
    }

    const applicationServerKey = urlBase64ToUint8Array(String(config.public_key));
    const freshSubscription = await self.registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey
    });

    await fetch(`${appRoot}push_notifications.php?action=subscribe`, {
      method: "POST",
      credentials: "include",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify({
        subscription: freshSubscription.toJSON ? freshSubscription.toJSON() : freshSubscription
      })
    });
  } catch (error) {
    // Keep silent; subscription refresh can retry on next app open.
  }
}

function urlBase64ToUint8Array(base64String) {
  const normalized = String(base64String || "").replace(/-/g, "+").replace(/_/g, "/");
  const padding = "=".repeat((4 - (normalized.length % 4)) % 4);
  const binary = atob(normalized + padding);
  const outputArray = new Uint8Array(binary.length);

  for (let i = 0; i < binary.length; i += 1) {
    outputArray[i] = binary.charCodeAt(i);
  }

  return outputArray;
}
