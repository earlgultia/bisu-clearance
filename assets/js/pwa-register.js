(function () {
  if (!("serviceWorker" in navigator)) {
    return;
  }

  function resolveAppRoot() {
    var manifestLink = document.querySelector('link[rel="manifest"]');
    if (!manifestLink) {
      return "/";
    }

    try {
      var manifestUrl = new URL(manifestLink.getAttribute("href"), window.location.href);
      var root = manifestUrl.pathname.replace(/\/manifest\.webmanifest$/i, "/");
      return root.endsWith("/") ? root : root + "/";
    } catch (error) {
      return "/";
    }
  }

  var appRoot = resolveAppRoot();
  var swVersion = "20260416-3";
  var swUrl = appRoot + "service-worker.js?v=" + encodeURIComponent(swVersion);
  var pushConfigUrl = appRoot + "push_notifications.php?action=config";
  var pushSubscribeUrl = appRoot + "push_notifications.php?action=subscribe";
  var pushUnsubscribeUrl = appRoot + "push_notifications.php?action=unsubscribe";
  var pushSyncInProgress = false;

  function requestActivation(registration) {
    if (registration && registration.waiting) {
      registration.waiting.postMessage({ type: "SKIP_WAITING" });
    }
  }

  function urlBase64ToUint8Array(base64String) {
    var normalized = String(base64String || "").replace(/-/g, "+").replace(/_/g, "/");
    var padding = "=".repeat((4 - (normalized.length % 4)) % 4);
    var binary = atob(normalized + padding);
    var output = new Uint8Array(binary.length);

    for (var i = 0; i < binary.length; i += 1) {
      output[i] = binary.charCodeAt(i);
    }

    return output;
  }

  function postJson(url, payload) {
    return fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json"
      },
      body: JSON.stringify(payload || {})
    });
  }

  function serializeSubscription(subscription) {
    if (!subscription) {
      return null;
    }

    if (typeof subscription.toJSON === "function") {
      return subscription.toJSON();
    }

    return {
      endpoint: subscription.endpoint,
      expirationTime: subscription.expirationTime || null,
      keys: {}
    };
  }

  function requestNotificationPermission() {
    if (!("Notification" in window)) {
      return Promise.resolve("unsupported");
    }

    if (Notification.permission === "granted" || Notification.permission === "denied") {
      return Promise.resolve(Notification.permission);
    }

    try {
      var permissionResult = Notification.requestPermission();
      if (permissionResult && typeof permissionResult.then === "function") {
        return permissionResult;
      }
    } catch (error) {
      return Promise.resolve(Notification.permission);
    }

    return Promise.resolve(Notification.permission);
  }

  function unsubscribeFromServer(subscription) {
    var serialized = serializeSubscription(subscription);
    if (!serialized || !serialized.endpoint) {
      return Promise.resolve();
    }

    return postJson(pushUnsubscribeUrl, {
      endpoint: serialized.endpoint
    }).catch(function () {
      return null;
    });
  }

  function syncPushSubscription(registration) {
    if (!registration || !("PushManager" in window) || !registration.pushManager) {
      return Promise.resolve();
    }

    if (pushSyncInProgress) {
      return Promise.resolve();
    }

    pushSyncInProgress = true;

    return fetch(pushConfigUrl, {
      method: "GET",
      credentials: "same-origin",
      cache: "no-store"
    })
      .then(function (response) {
        if (!response.ok) {
          return null;
        }
        return response.json();
      })
      .then(function (config) {
        if (!config || !config.success || !config.enabled || !config.public_key || !config.can_subscribe) {
          return null;
        }

        return registration.pushManager.getSubscription().then(function (existingSubscription) {
          if (Notification.permission === "denied") {
            if (existingSubscription) {
              return unsubscribeFromServer(existingSubscription)
                .then(function () {
                  return existingSubscription.unsubscribe();
                })
                .catch(function () {
                  return null;
                });
            }
            return null;
          }

          return requestNotificationPermission().then(function (permission) {
            if (permission !== "granted") {
              return null;
            }

            if (existingSubscription) {
              return existingSubscription;
            }

            var applicationServerKey = urlBase64ToUint8Array(String(config.public_key));
            return registration.pushManager.subscribe({
              userVisibleOnly: true,
              applicationServerKey: applicationServerKey
            });
          });
        });
      })
      .then(function (subscription) {
        var serialized = serializeSubscription(subscription);
        if (!serialized || !serialized.endpoint) {
          return null;
        }

        return postJson(pushSubscribeUrl, {
          subscription: serialized
        }).catch(function () {
          return null;
        });
      })
      .catch(function () {
        return null;
      })
      .finally(function () {
        pushSyncInProgress = false;
      });
  }

  window.addEventListener("load", function () {
    navigator.serviceWorker
      .register(swUrl, { scope: appRoot })
      .then(function (registration) {
        requestActivation(registration);
        syncPushSubscription(registration);

        registration.addEventListener("updatefound", function () {
          var newWorker = registration.installing;
          if (!newWorker) {
            return;
          }

          newWorker.addEventListener("statechange", function () {
            if (newWorker.state === "installed") {
              requestActivation(registration);
            }
          });
        });

        registration.update().catch(function () {
          return null;
        });

        navigator.serviceWorker.ready
          .then(function (readyRegistration) {
            return syncPushSubscription(readyRegistration);
          })
          .catch(function () {
            return null;
          });
      })
      .catch(function (error) {
        console.warn("Service worker registration failed:", error);
      });
  });
})();
