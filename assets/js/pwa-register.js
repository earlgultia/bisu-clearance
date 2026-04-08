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
  var swUrl = appRoot + "service-worker.js";

  window.addEventListener("load", function () {
    navigator.serviceWorker.register(swUrl, { scope: appRoot }).catch(function (error) {
      console.warn("Service worker registration failed:", error);
    });
  });
})();
