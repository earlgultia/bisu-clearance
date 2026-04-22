(function () {
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
  var swVersion = "20260420-1";
  var useNativeInstallPromptOnly = true;
  var swUrl = appRoot + "service-worker.js?v=" + encodeURIComponent(swVersion);
  var pushConfigUrl = appRoot + "push_notifications.php?action=config";
  var pushSubscribeUrl = appRoot + "push_notifications.php?action=subscribe";
  var pushUnsubscribeUrl = appRoot + "push_notifications.php?action=unsubscribe";
  var pushSyncInProgress = false;
  var deferredInstallPrompt = null;
  var installPromptRequested = false;
  var installStorageKey = "bisu-clearance-install-dismissed-v2";
  var installBannerShown = false;
  var installBannerElement = null;
  var installBannerTimer = null;
  var installDebugElement = null;
  var installTriggerBusy = false;
  var installIntentPending = false;

  function isInstallDebugEnabled() {
    return false;
  }

  function removeInstallDebug() {
    if (installDebugElement && installDebugElement.parentNode) {
      installDebugElement.parentNode.removeChild(installDebugElement);
    }

    installDebugElement = null;
  }

  function clearInstallUi(rememberChoice) {
    dismissInstallBanner(rememberChoice);
    removeInstallDebug();
  }

  function isStandaloneMode() {
    return window.matchMedia("(display-mode: standalone)").matches || window.navigator.standalone === true;
  }

  function isIosDevice() {
    var userAgent = window.navigator.userAgent || "";
    var platform = window.navigator.platform || "";
    var touchPoints = Number(window.navigator.maxTouchPoints || 0);
    return /iphone|ipad|ipod/i.test(userAgent) || (platform === "MacIntel" && touchPoints > 1);
  }

  function isSafariBrowser() {
    var userAgent = window.navigator.userAgent || "";
    return /safari/i.test(userAgent) && !/chrome|chromium|crios|android|fxios|edgios/i.test(userAgent);
  }

  function isAndroidDevice() {
    return /android/i.test(window.navigator.userAgent || "");
  }

  function isDesktopDevice() {
    return !isAndroidDevice() && !isIosDevice();
  }

  function canAskForInstall(ignoreDismissed) {
    if (isStandaloneMode()) {
      return false;
    }

    if (ignoreDismissed) {
      return true;
    }

    try {
      return window.localStorage.getItem(installStorageKey) !== "1";
    } catch (error) {
      return true;
    }
  }

  function markInstallDismissed() {
    try {
      window.localStorage.setItem(installStorageKey, "1");
    } catch (error) {
      return;
    }
  }

  function clearInstallDismissed() {
    try {
      window.localStorage.removeItem(installStorageKey);
    } catch (error) {
      return;
    }
  }

  function dismissInstallBanner(rememberChoice) {
    if (installBannerTimer) {
      window.clearTimeout(installBannerTimer);
      installBannerTimer = null;
    }

    if (rememberChoice) {
      markInstallDismissed();
    }

    if (installBannerElement && installBannerElement.parentNode) {
      installBannerElement.parentNode.removeChild(installBannerElement);
    }

    installBannerElement = null;
    installBannerShown = false;
  }

  function getInstallBannerCopy() {
    if (!window.isSecureContext && !isIosDevice()) {
      return {
        title: "Install Needs HTTPS",
        message: "Open this site over HTTPS to enable app installation in Android browsers.",
        buttonLabel: "Got it",
        mode: "https-help"
      };
    }

    if (isIosDevice() && isSafariBrowser()) {
      return {
        title: "Install on iPhone",
        message: 'Tap Share, then choose "Add to Home Screen" to install BISU Clearance.',
        buttonLabel: "Got it",
        mode: "ios"
      };
    }

    if (deferredInstallPrompt) {
      return {
        title: "Install BISU Clearance",
        message: "Add this app to your home screen for faster access and an app-like experience.",
        buttonLabel: "Install now",
        mode: "prompt"
      };
    }

    if (isAndroidDevice()) {
      return {
        title: "Install BISU Clearance",
        message: 'Open the browser menu and tap "Install app" or "Add to Home screen" if the browser does not show the prompt yet.',
        buttonLabel: "Got it",
        mode: "android-help"
      };
    }

    if (isDesktopDevice()) {
      return {
        title: "Install on Desktop",
        message: 'In Chrome or Edge, click the install icon in the address bar or open the browser menu and choose "Install BISU Clearance".',
        buttonLabel: "Got it",
        mode: "desktop-help"
      };
    }

    return {
      title: "Install BISU Clearance",
      message: "This browser does not currently expose a direct install prompt for this app.",
      buttonLabel: "Got it",
      mode: "unsupported-help"
    };
  }

  function ensureInstallBanner() {
    if (installBannerElement) {
      return installBannerElement;
    }

    var banner = document.createElement("section");
    banner.setAttribute("id", "bisuInstallBanner");
    banner.setAttribute("role", "dialog");
    banner.setAttribute("aria-live", "polite");
    banner.setAttribute("aria-label", "Install BISU Clearance");
    banner.innerHTML =
      '<div class="bisu-install-banner__content">' +
        '<button type="button" class="bisu-install-banner__close" aria-label="Dismiss install message">&times;</button>' +
        '<div class="bisu-install-banner__eyebrow">BISU Clearance</div>' +
        '<h2 class="bisu-install-banner__title"></h2>' +
        '<p class="bisu-install-banner__message"></p>' +
        '<div class="bisu-install-banner__actions">' +
          '<button type="button" class="bisu-install-banner__primary"></button>' +
          '<button type="button" class="bisu-install-banner__secondary">Not now</button>' +
        "</div>" +
      "</div>";

    var style = document.createElement("style");
    style.textContent =
      "#bisuInstallBanner{" +
        "position:fixed;left:16px;right:16px;bottom:16px;z-index:99999;" +
        "font-family:Arial,sans-serif;" +
      "}" +
      "#bisuInstallBanner .bisu-install-banner__content{" +
        "position:relative;background:linear-gradient(135deg,#1f5f99 0%,#143b63 100%);" +
        "color:#fff;border-radius:20px;padding:20px 18px 18px;box-shadow:0 20px 40px rgba(0,0,0,.28);" +
      "}" +
      "#bisuInstallBanner .bisu-install-banner__eyebrow{" +
        "font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.8;margin-bottom:8px;" +
      "}" +
      "#bisuInstallBanner .bisu-install-banner__title{" +
        "margin:0 34px 8px 0;font-size:20px;line-height:1.2;" +
      "}" +
      "#bisuInstallBanner .bisu-install-banner__message{" +
        "margin:0;font-size:14px;line-height:1.5;opacity:.94;" +
      "}" +
      "#bisuInstallBanner .bisu-install-banner__actions{" +
        "display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;" +
      "}" +
      "#bisuInstallBanner .bisu-install-banner__primary," +
      "#bisuInstallBanner .bisu-install-banner__secondary{" +
        "border:0;border-radius:999px;padding:12px 16px;font-size:14px;font-weight:700;cursor:pointer;" +
      "}" +
      "#bisuInstallBanner .bisu-install-banner__primary{" +
        "background:#fff;color:#143b63;" +
      "}" +
      "#bisuInstallBanner .bisu-install-banner__secondary{" +
        "background:rgba(255,255,255,.16);color:#fff;" +
      "}" +
      "#bisuInstallBanner .bisu-install-banner__close{" +
        "position:absolute;top:10px;right:10px;width:32px;height:32px;border:0;border-radius:50%;" +
        "background:rgba(255,255,255,.14);color:#fff;font-size:22px;line-height:1;cursor:pointer;" +
      "}" +
      "@media (min-width: 768px){" +
        "#bisuInstallBanner{left:auto;right:24px;bottom:24px;max-width:380px;}" +
      "}";

    document.head.appendChild(style);
    document.body.appendChild(banner);

    banner.querySelector(".bisu-install-banner__close").addEventListener("click", function () {
      dismissInstallBanner(true);
    });

    banner.querySelector(".bisu-install-banner__secondary").addEventListener("click", function () {
      dismissInstallBanner(true);
    });

    banner.querySelector(".bisu-install-banner__primary").addEventListener("click", function () {
      var copy = getInstallBannerCopy();
      if (!copy) {
        dismissInstallBanner(true);
        return;
      }

      if (copy.mode === "prompt") {
        showInstallPrompt();
        return;
      }

      dismissInstallBanner(true);
    });

    installBannerElement = banner;
    return banner;
  }

  function getInstallDebugState() {
    if (isStandaloneMode()) {
      return "Installed mode detected";
    }

    if (installIntentPending && !deferredInstallPrompt) {
      return "Preparing install prompt";
    }

    if (isIosDevice() && isSafariBrowser()) {
      return "iPhone/iPad Safari: use Share > Add to Home Screen";
    }

    if (!window.isSecureContext && !isIosDevice()) {
      return "Install blocked: HTTPS required";
    }

    if (deferredInstallPrompt) {
      return "Android install prompt ready";
    }

    if (!("serviceWorker" in navigator)) {
      return "Install limited: service workers unsupported";
    }

    if (isAndroidDevice()) {
      return "Android detected: waiting for browser install eligibility";
    }

    if (isDesktopDevice()) {
      return "Desktop detected: use the install prompt or browser install menu";
    }

    return "Install availability depends on this browser";
  }

  function ensureInstallDebug() {
    if (!isInstallDebugEnabled()) {
      return null;
    }

    if (installDebugElement) {
      return installDebugElement;
    }

    var debug = document.createElement("div");
    debug.setAttribute("id", "bisuInstallDebug");
    debug.setAttribute("aria-live", "polite");
    debug.innerHTML =
      '<span class="bisu-install-debug__label">Install status</span>' +
      '<span class="bisu-install-debug__value"></span>';

    var style = document.createElement("style");
    style.textContent =
      "#bisuInstallDebug{" +
        "position:fixed;left:12px;right:12px;bottom:12px;z-index:99998;" +
        "display:flex;flex-direction:column;gap:4px;padding:10px 12px;border-radius:14px;" +
        "background:rgba(15,23,42,.88);color:#fff;font:12px/1.4 Arial,sans-serif;" +
        "box-shadow:0 10px 24px rgba(0,0,0,.22);backdrop-filter:blur(8px);" +
      "}" +
      "#bisuInstallDebug .bisu-install-debug__label{" +
        "text-transform:uppercase;letter-spacing:.08em;opacity:.7;font-size:10px;font-weight:700;" +
      "}" +
      "#bisuInstallDebug .bisu-install-debug__value{" +
        "font-size:12px;font-weight:600;" +
      "}" +
      "@media (min-width:768px){" +
        "#bisuInstallDebug{left:auto;right:16px;bottom:16px;max-width:320px;}" +
      "}";

    document.head.appendChild(style);
    document.body.appendChild(debug);
    installDebugElement = debug;
    return debug;
  }

  function updateInstallDebug() {
    var debug = ensureInstallDebug();
    if (!debug) {
      return;
    }

    debug.querySelector(".bisu-install-debug__value").textContent = getInstallDebugState();
  }

  function showEmergencyInstallMessage(copy) {
    return;
  }

  function showInstallBanner(ignoreDismissed) {
    if (useNativeInstallPromptOnly) {
      return;
    }

    var copy = getInstallBannerCopy();
    var banner;
    if (!copy || !canAskForInstall(ignoreDismissed)) {
      return;
    }

    try {
      banner = ensureInstallBanner();
      banner.querySelector(".bisu-install-banner__title").textContent = copy.title;
      banner.querySelector(".bisu-install-banner__message").textContent = copy.message;
      banner.querySelector(".bisu-install-banner__primary").textContent = copy.buttonLabel;
      installBannerShown = true;
      updateInstallDebug();
    } catch (error) {
      console.warn("Install banner failed:", error);
      showEmergencyInstallMessage(copy);
    }
  }

  function triggerInstallExperience() {
    if (useNativeInstallPromptOnly) {
      return false;
    }

    var availableCopy;
    if (installTriggerBusy) {
      return true;
    }

    installTriggerBusy = true;
    installIntentPending = true;

    try {
      clearInstallDismissed();
      availableCopy = getInstallBannerCopy();

      if (deferredInstallPrompt) {
        showInstallPrompt(true);
        return true;
      }

      if (availableCopy) {
        showInstallBanner(true);
        updateInstallDebug();
        return true;
      }

      installIntentPending = false;
      showEmergencyInstallMessage(null);
      return false;
    } catch (error) {
      installIntentPending = false;
      console.warn("Install trigger failed:", error);
      showEmergencyInstallMessage(availableCopy);
      return false;
    } finally {
      window.setTimeout(function () {
        installTriggerBusy = false;
      }, 400);
    }
  }

  function scheduleInstallBanner(delayMs) {
    if (useNativeInstallPromptOnly) {
      return;
    }

    if (installBannerTimer) {
      window.clearTimeout(installBannerTimer);
    }

    installBannerTimer = window.setTimeout(function () {
      showInstallBanner();
    }, typeof delayMs === "number" ? delayMs : 900);
  }

  function showInstallPrompt(ignoreDismissed) {
    if (useNativeInstallPromptOnly) {
      return false;
    }

    if (!deferredInstallPrompt || installPromptRequested || !canAskForInstall(ignoreDismissed)) {
      showInstallBanner(true);
      return;
    }

    installIntentPending = false;
    installPromptRequested = true;
    deferredInstallPrompt.prompt();

    deferredInstallPrompt.userChoice
      .then(function (choiceResult) {
        if (!choiceResult || choiceResult.outcome !== "accepted") {
          markInstallDismissed();
        } else {
          clearInstallDismissed();
          dismissInstallBanner(false);
        }
      })
      .catch(function () {
        markInstallDismissed();
        showInstallBanner(true);
      })
      .finally(function () {
        installIntentPending = false;
        deferredInstallPrompt = null;
        installPromptRequested = false;
        updateInstallDebug();
        if (!isStandaloneMode()) {
          scheduleInstallBanner(1600);
        }
      });
  }

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

  window.addEventListener("beforeinstallprompt", function (event) {
    if (useNativeInstallPromptOnly) {
      deferredInstallPrompt = null;
      installIntentPending = false;
      installPromptRequested = false;
      clearInstallUi(false);
      clearInstallDismissed();
      return;
    }

    event.preventDefault();
    deferredInstallPrompt = event;
    clearInstallDismissed();
    updateInstallDebug();
    if (installIntentPending) {
      showInstallPrompt(true);
      return;
    }
    scheduleInstallBanner(1200);
  });

  window.addEventListener("appinstalled", function () {
    deferredInstallPrompt = null;
    installPromptRequested = false;
    installIntentPending = false;
    clearInstallDismissed();
    clearInstallUi(false);
  });

  window.addEventListener("load", function () {
    clearInstallUi(false);
    if (!useNativeInstallPromptOnly && !isStandaloneMode() && canAskForInstall()) {
      scheduleInstallBanner(isIosDevice() ? 1200 : 2400);
    }

    if ("serviceWorker" in navigator) {
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
    } else {
      console.warn("Service workers are not available in this browser.");
    }
  });

  function bindInstallTriggers() {
    var triggers = document.querySelectorAll("[data-pwa-install]");

    for (var i = 0; i < triggers.length; i += 1) {
      if (useNativeInstallPromptOnly) {
        if (triggers[i].parentNode) {
          triggers[i].parentNode.removeChild(triggers[i]);
        }
        continue;
      }

      if (triggers[i].getAttribute("data-pwa-install-bound") === "1") {
        continue;
      }

      triggers[i].setAttribute("data-pwa-install-bound", "1");
      triggers[i].addEventListener("click", function (event) {
        event.preventDefault();
        triggerInstallExperience();
        updateInstallDebug();
      });
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bindInstallTriggers);
  } else {
    bindInstallTriggers();
  }

  window.BisuPwaInstall = {
    canPrompt: function () {
      return !useNativeInstallPromptOnly && canAskForInstall(true) && Boolean(getInstallBannerCopy());
    },
    open: function () {
      if (useNativeInstallPromptOnly) {
        return false;
      }

      return triggerInstallExperience();
    }
  };
})();
