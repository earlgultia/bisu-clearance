<?php
require_once __DIR__ . '/db.php';

$homeUrl = baseUrl('index.php');
$manifestUrl = versionedUrl('manifest.webmanifest');
$pwaRegisterUrl = versionedUrl('assets/js/pwa-register.js');
$faviconUrl = versionedUrl('assets/img/favicon.png');
$touchIconUrl = versionedUrl('assets/img/pwa-icon-192.png');
$assetVersion = defined('ASSET_VERSION') ? ASSET_VERSION : SITE_VERSION;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PWA Diagnostics | BISU Clearance</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#412886">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="manifest" href="<?php echo htmlspecialchars($manifestUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($touchIconUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <script defer src="<?php echo htmlspecialchars($pwaRegisterUrl, ENT_QUOTES, 'UTF-8'); ?>"></script>
    <style>
        :root {
            --bg: #f7f8fc;
            --surface: #ffffff;
            --text: #1e2433;
            --muted: #667089;
            --border: #d8ddeb;
            --primary: #412886;
            --success: #0f766e;
            --warning: #b45309;
            --danger: #b42318;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(160deg, #eef1fb 0%, #f9fafc 55%, #f0f4ff 100%);
            color: var(--text);
            min-height: 100vh;
        }

        .wrap {
            width: min(1100px, 94vw);
            margin: 28px auto;
            padding-bottom: 28px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 18px;
        }

        .title h1 {
            margin: 0;
            font-size: 1.6rem;
        }

        .title p {
            margin: 6px 0 0;
            color: var(--muted);
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text);
            text-decoration: none;
            padding: 10px 14px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn.primary {
            border-color: transparent;
            background: linear-gradient(130deg, #412886 0%, #6244b6 100%);
            color: #fff;
        }

        .meta {
            margin-bottom: 14px;
            padding: 10px 12px;
            border-radius: 10px;
            background: #f2f5ff;
            border: 1px solid #dce4ff;
            color: #2f3962;
            font-size: 0.92rem;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px;
            box-shadow: 0 8px 22px rgba(25, 24, 48, 0.06);
        }

        .card h2 {
            margin: 0 0 10px;
            font-size: 1.03rem;
        }

        .kv {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.92rem;
        }

        .kv th,
        .kv td {
            text-align: left;
            border-top: 1px solid #edf0f6;
            padding: 8px 6px;
            vertical-align: top;
        }

        .kv th {
            color: var(--muted);
            width: 180px;
            font-weight: 600;
            background: #fcfdff;
        }

        .mono {
            font-family: "Consolas", "Courier New", monospace;
            word-break: break-all;
        }

        .status-ok {
            color: var(--success);
            font-weight: 700;
        }

        .status-warn {
            color: var(--warning);
            font-weight: 700;
        }

        .status-bad {
            color: var(--danger);
            font-weight: 700;
        }

        .icons-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.89rem;
        }

        .icons-table th,
        .icons-table td {
            border-top: 1px solid #edf0f6;
            padding: 8px 6px;
            text-align: left;
            vertical-align: middle;
        }

        .icon-preview {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 1px solid #d7ddef;
            object-fit: contain;
            background: #fff;
        }

        .log {
            margin: 0;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #dde3f5;
            background: #f8faff;
            font-size: 0.86rem;
            line-height: 1.45;
            max-height: 260px;
            overflow: auto;
            white-space: pre-wrap;
        }

        @media (max-width: 900px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .kv th {
                width: 140px;
            }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="topbar">
            <div class="title">
                <h1>PWA Diagnostics</h1>
                <p>Inspect what this browser is currently using for install metadata and icons.</p>
            </div>
            <div class="actions">
                <a class="btn" href="<?php echo htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8'); ?>">Back to Home</a>
                <button class="btn primary" id="refreshBtn" type="button">Refresh Diagnostics</button>
            </div>
        </div>

        <div class="meta">
            Asset version: <strong><?php echo htmlspecialchars((string) $assetVersion, ENT_QUOTES, 'UTF-8'); ?></strong>.
            If icon updates are not visible, uninstall previous app shortcut, clear site data, then install again.
        </div>

        <div class="grid">
            <section class="card">
                <h2>Environment</h2>
                <table class="kv">
                    <tr><th>Page URL</th><td class="mono" id="pageUrl">-</td></tr>
                    <tr><th>Online</th><td id="onlineStatus">-</td></tr>
                    <tr><th>Display Mode</th><td id="displayMode">-</td></tr>
                    <tr><th>Manifest Tag Href</th><td class="mono" id="manifestTagHref">-</td></tr>
                    <tr><th>Resolved Manifest URL</th><td class="mono" id="manifestResolvedUrl">-</td></tr>
                    <tr><th>Manifest HTTP</th><td id="manifestHttpStatus">-</td></tr>
                </table>
            </section>

            <section class="card">
                <h2>Manifest Fields</h2>
                <table class="kv">
                    <tr><th>Name</th><td id="manifestName">-</td></tr>
                    <tr><th>Short Name</th><td id="manifestShortName">-</td></tr>
                    <tr><th>Start URL</th><td class="mono" id="manifestStartUrl">-</td></tr>
                    <tr><th>Scope</th><td class="mono" id="manifestScope">-</td></tr>
                    <tr><th>Display</th><td id="manifestDisplay">-</td></tr>
                    <tr><th>Theme Color</th><td id="manifestThemeColor">-</td></tr>
                </table>
            </section>

            <section class="card">
                <h2>Manifest Icons</h2>
                <table class="icons-table">
                    <thead>
                        <tr>
                            <th>Preview</th>
                            <th>Sizes/Purpose</th>
                            <th>Resolved URL</th>
                            <th>Fetch Status</th>
                        </tr>
                    </thead>
                    <tbody id="iconsBody">
                        <tr><td colspan="4">Loading icon checks...</td></tr>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <h2>Service Worker</h2>
                <table class="kv">
                    <tr><th>Support</th><td id="swSupport">-</td></tr>
                    <tr><th>Registrations</th><td id="swCount">-</td></tr>
                </table>
                <pre class="log" id="swLog">Loading service worker details...</pre>
            </section>
        </div>
    </div>

    <script>
        (function () {
            var els = {
                pageUrl: document.getElementById('pageUrl'),
                onlineStatus: document.getElementById('onlineStatus'),
                displayMode: document.getElementById('displayMode'),
                manifestTagHref: document.getElementById('manifestTagHref'),
                manifestResolvedUrl: document.getElementById('manifestResolvedUrl'),
                manifestHttpStatus: document.getElementById('manifestHttpStatus'),
                manifestName: document.getElementById('manifestName'),
                manifestShortName: document.getElementById('manifestShortName'),
                manifestStartUrl: document.getElementById('manifestStartUrl'),
                manifestScope: document.getElementById('manifestScope'),
                manifestDisplay: document.getElementById('manifestDisplay'),
                manifestThemeColor: document.getElementById('manifestThemeColor'),
                iconsBody: document.getElementById('iconsBody'),
                swSupport: document.getElementById('swSupport'),
                swCount: document.getElementById('swCount'),
                swLog: document.getElementById('swLog')
            };

            function setStatus(node, message, kind) {
                if (!node) {
                    return;
                }
                node.textContent = message;
                node.classList.remove('status-ok', 'status-warn', 'status-bad');
                if (kind === 'ok') {
                    node.classList.add('status-ok');
                } else if (kind === 'warn') {
                    node.classList.add('status-warn');
                } else if (kind === 'bad') {
                    node.classList.add('status-bad');
                }
            }

            function setText(node, value) {
                if (node) {
                    node.textContent = value;
                }
            }

            function detectDisplayMode() {
                if (window.matchMedia('(display-mode: standalone)').matches) {
                    return 'standalone';
                }
                if (window.matchMedia('(display-mode: minimal-ui)').matches) {
                    return 'minimal-ui';
                }
                if (window.navigator.standalone === true) {
                    return 'ios-standalone';
                }
                if (document.referrer.indexOf('android-app://') === 0) {
                    return 'twa';
                }
                return 'browser-tab';
            }

            async function checkIconAvailability(url) {
                try {
                    var response = await fetch(url, { cache: 'no-store' });
                    var contentType = response.headers.get('content-type') || 'unknown';
                    return {
                        ok: response.ok,
                        status: response.status,
                        contentType: contentType
                    };
                } catch (error) {
                    return {
                        ok: false,
                        status: 0,
                        contentType: String(error)
                    };
                }
            }

            async function loadServiceWorkers() {
                if (!('serviceWorker' in navigator)) {
                    setStatus(els.swSupport, 'Not supported', 'bad');
                    setText(els.swCount, '0');
                    setText(els.swLog, 'This browser does not support service workers.');
                    return;
                }

                setStatus(els.swSupport, 'Supported', 'ok');

                try {
                    var regs = await navigator.serviceWorker.getRegistrations();
                    setText(els.swCount, String(regs.length));

                    if (!regs.length) {
                        setText(els.swLog, 'No active registrations found for this origin.');
                        return;
                    }

                    var lines = [];
                    regs.forEach(function (reg, index) {
                        var activeState = reg.active ? reg.active.state : 'none';
                        var waitingState = reg.waiting ? reg.waiting.state : 'none';
                        var installingState = reg.installing ? reg.installing.state : 'none';

                        lines.push(
                            [
                                'Registration #' + (index + 1),
                                'scope: ' + reg.scope,
                                'active script: ' + (reg.active ? reg.active.scriptURL : 'none'),
                                'active state: ' + activeState,
                                'waiting state: ' + waitingState,
                                'installing state: ' + installingState,
                                'updateViaCache: ' + reg.updateViaCache
                            ].join('\n')
                        );
                    });

                    setText(els.swLog, lines.join('\n\n'));
                } catch (error) {
                    setText(els.swLog, 'Error reading service worker registrations: ' + error);
                }
            }

            async function loadManifestAndIcons() {
                var manifestLink = document.querySelector('link[rel="manifest"]');
                if (!manifestLink) {
                    setText(els.manifestTagHref, 'Missing');
                    setText(els.manifestResolvedUrl, 'Missing');
                    setStatus(els.manifestHttpStatus, 'No manifest link tag found', 'bad');
                    els.iconsBody.innerHTML = '<tr><td colspan="4">Manifest link tag is missing.</td></tr>';
                    return;
                }

                var rawHref = manifestLink.getAttribute('href') || '';
                setText(els.manifestTagHref, rawHref || '(empty)');

                var resolvedManifestUrl = '';
                try {
                    resolvedManifestUrl = new URL(rawHref, window.location.href).href;
                } catch (error) {
                    setText(els.manifestResolvedUrl, 'Invalid URL: ' + error);
                    setStatus(els.manifestHttpStatus, 'Manifest URL parse failed', 'bad');
                    els.iconsBody.innerHTML = '<tr><td colspan="4">Manifest URL could not be resolved.</td></tr>';
                    return;
                }

                setText(els.manifestResolvedUrl, resolvedManifestUrl);

                var manifestResponse;
                try {
                    manifestResponse = await fetch(resolvedManifestUrl, { cache: 'no-store' });
                } catch (error) {
                    setStatus(els.manifestHttpStatus, 'Manifest fetch failed', 'bad');
                    els.iconsBody.innerHTML = '<tr><td colspan="4">Manifest fetch error: ' + String(error) + '</td></tr>';
                    return;
                }

                var statusLabel = manifestResponse.status + ' ' + (manifestResponse.ok ? 'OK' : 'NOT OK');
                setStatus(els.manifestHttpStatus, statusLabel, manifestResponse.ok ? 'ok' : 'bad');

                if (!manifestResponse.ok) {
                    els.iconsBody.innerHTML = '<tr><td colspan="4">Manifest request did not return OK.</td></tr>';
                    return;
                }

                var manifest;
                try {
                    manifest = await manifestResponse.json();
                } catch (error) {
                    setStatus(els.manifestHttpStatus, 'Manifest JSON parse failed', 'bad');
                    els.iconsBody.innerHTML = '<tr><td colspan="4">Manifest JSON is invalid.</td></tr>';
                    return;
                }

                setText(els.manifestName, manifest.name || '-');
                setText(els.manifestShortName, manifest.short_name || '-');
                setText(els.manifestStartUrl, manifest.start_url || '-');
                setText(els.manifestScope, manifest.scope || '-');
                setText(els.manifestDisplay, manifest.display || '-');
                setText(els.manifestThemeColor, manifest.theme_color || '-');

                var icons = Array.isArray(manifest.icons) ? manifest.icons : [];
                if (!icons.length) {
                    els.iconsBody.innerHTML = '<tr><td colspan="4">No icons array in manifest.</td></tr>';
                    return;
                }

                els.iconsBody.innerHTML = '';

                for (var i = 0; i < icons.length; i += 1) {
                    var icon = icons[i] || {};
                    var iconSrc = typeof icon.src === 'string' ? icon.src : '';
                    var resolvedIconUrl = '';

                    try {
                        resolvedIconUrl = new URL(iconSrc, resolvedManifestUrl).href;
                    } catch (error) {
                        resolvedIconUrl = 'Invalid icon src: ' + String(error);
                    }

                    var row = document.createElement('tr');

                    var previewCell = document.createElement('td');
                    var preview = document.createElement('img');
                    preview.className = 'icon-preview';
                    preview.alt = 'Icon preview';
                    if (resolvedIconUrl.indexOf('http') === 0) {
                        preview.src = resolvedIconUrl;
                    }
                    previewCell.appendChild(preview);

                    var metaCell = document.createElement('td');
                    metaCell.textContent = [icon.sizes || 'unknown', icon.purpose || 'any'].join(' / ');

                    var urlCell = document.createElement('td');
                    urlCell.className = 'mono';
                    urlCell.textContent = resolvedIconUrl;

                    var statusCell = document.createElement('td');
                    statusCell.textContent = 'Checking...';

                    row.appendChild(previewCell);
                    row.appendChild(metaCell);
                    row.appendChild(urlCell);
                    row.appendChild(statusCell);
                    els.iconsBody.appendChild(row);

                    if (resolvedIconUrl.indexOf('http') !== 0) {
                        statusCell.textContent = 'Invalid URL';
                        statusCell.className = 'status-bad';
                        continue;
                    }

                    var iconStatus = await checkIconAvailability(resolvedIconUrl);
                    statusCell.textContent = iconStatus.status + ' (' + iconStatus.contentType + ')';
                    statusCell.className = iconStatus.ok ? 'status-ok' : 'status-bad';
                }
            }

            async function runDiagnostics() {
                setText(els.pageUrl, window.location.href);
                setStatus(els.onlineStatus, navigator.onLine ? 'Online' : 'Offline', navigator.onLine ? 'ok' : 'warn');
                setText(els.displayMode, detectDisplayMode());

                await Promise.all([loadManifestAndIcons(), loadServiceWorkers()]);
            }

            document.getElementById('refreshBtn').addEventListener('click', function () {
                runDiagnostics();
            });

            window.addEventListener('online', runDiagnostics);
            window.addEventListener('offline', runDiagnostics);

            runDiagnostics();
        })();
    </script>
</body>
</html>
