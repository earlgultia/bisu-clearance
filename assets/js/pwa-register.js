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
  var swVersion = "20260416-2";
  var swUrl = appRoot + "service-worker.js?v=" + encodeURIComponent(swVersion);

  function requestActivation(registration) {
    if (registration && registration.waiting) {
      registration.waiting.postMessage({ type: "SKIP_WAITING" });
    }
  }

  window.addEventListener("load", function () {
    navigator.serviceWorker
      .register(swUrl, { scope: appRoot })
      .then(function (registration) {
        requestActivation(registration);

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
      })
      .catch(function (error) {
        console.warn("Service worker registration failed:", error);
      });
  });
})();
