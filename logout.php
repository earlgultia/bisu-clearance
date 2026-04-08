<?php
// logout.php - SweetAlert-based logout confirmation for BISU Online Clearance System

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'] ?? 0;
$user_name = $_SESSION['user_name'] ?? 'User';
$user_role = $_SESSION['user_role'] ?? '';
$role_label = ucfirst(str_replace('_', ' ', $user_role));
$is_confirmed_logout = isset($_GET['confirm']) && $_GET['confirm'] === 'yes';
$logout_completed = false;

if ($is_confirmed_logout) {
    try {
        $logModel = new ActivityLogModel();
        $logModel->log($user_id, 'LOGOUT', "User logged out: $user_name");
    } catch (Exception $e) {
        // Logging should never block the user from signing out.
    }

    if ($user_id > 0 && $user_role !== 'organization') {
        try {
            if (hasDatabaseColumn('users', 'is_online') && hasDatabaseColumn('users', 'last_seen_at')) {
                $db = Database::getInstance();
                $db->query("UPDATE users
                            SET is_online = 0,
                                last_seen_at = NOW()
                            WHERE users_id = :users_id
                            LIMIT 1");
                $db->bind(':users_id', (int) $user_id);
                $db->execute();
            }
        } catch (Exception $e) {
            error_log('Logout presence update error: ' . $e->getMessage());
        }
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
    $logout_completed = true;
}

$json_flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
$user_name_html = htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8');
$role_label_html = htmlspecialchars($role_label, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Logout - BISU Clearance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0f4c81;
            --primary-dark: #0a345c;
            --primary-light: #2f78b7;
            --surface: rgba(255, 255, 255, 0.96);
            --text: #13243a;
            --muted: #5a6878;
            --meta-bg: #eef4fa;
            --meta-text: #1e486f;
            --danger: #dc3545;
            --secondary: #5c637a;
            --border-soft: rgba(15, 76, 129, 0.14);
            --shadow: 0 20px 46px rgba(7, 32, 56, 0.2);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            display: grid;
            place-items: center;
            padding: calc(24px + env(safe-area-inset-top, 0px)) calc(24px + env(safe-area-inset-right, 0px)) calc(24px + env(safe-area-inset-bottom, 0px)) calc(24px + env(safe-area-inset-left, 0px));
            font-family: "Manrope", "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            overflow-x: hidden;
            background:
                radial-gradient(circle at 100% 0%, rgba(47, 120, 183, 0.24), transparent 38%),
                radial-gradient(circle at 0% 100%, rgba(15, 76, 129, 0.18), transparent 45%),
                linear-gradient(135deg, #072844 0%, #0f4c81 52%, #2f78b7 100%);
        }

        .logout-shell {
            width: min(980px, 100%);
            display: grid;
            grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.05fr);
            gap: clamp(18px, 2.2vw, 26px);
            align-items: stretch;
        }

        .visual-panel {
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.22);
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.17), rgba(255, 255, 255, 0.08));
            box-shadow: var(--shadow);
            backdrop-filter: blur(10px);
            color: #f2f7ff;
            padding: clamp(22px, 3vw, 30px);
            display: grid;
            align-content: center;
            gap: 16px;
            position: relative;
            overflow: hidden;
        }

        .visual-panel::before {
            content: "";
            position: absolute;
            width: 220px;
            height: 220px;
            right: -70px;
            top: -70px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.24), transparent 68%);
            pointer-events: none;
        }

        .visual-panel h2 {
            margin: 0;
            font-size: clamp(1.35rem, 2vw, 1.75rem);
            line-height: 1.2;
            letter-spacing: -0.02em;
        }

        .visual-panel p {
            margin: 0;
            opacity: 0.94;
            line-height: 1.6;
            font-size: 0.98rem;
            color: rgba(242, 247, 255, 0.9);
        }

        .visual-points {
            margin: 2px 0 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 10px;
        }

        .visual-points li {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            font-size: 0.95rem;
            color: rgba(242, 247, 255, 0.96);
        }

        .visual-points i {
            margin-top: 3px;
            color: #d8ebff;
        }

        .brand-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0 auto 14px;
            padding: 7px 12px;
            border-radius: 999px;
            color: var(--primary-dark);
            background: #e8f2fb;
            border: 1px solid var(--border-soft);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .brand-chip i {
            color: var(--primary);
        }

        .fallback-card {
            width: 100%;
            max-width: 460px;
            padding: 32px 28px;
            border-radius: 22px;
            background: var(--surface);
            box-shadow: var(--shadow);
            text-align: center;
            border: 1px solid var(--border-soft);
            backdrop-filter: blur(10px);
            position: relative;
            overflow: hidden;
            justify-self: end;
        }

        .fallback-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-dark) 0%, var(--primary) 55%, var(--primary-light) 100%);
            opacity: 0.92;
        }

        .fallback-card h1 {
            margin: 0 0 12px;
            color: var(--text);
            font-size: 28px;
            letter-spacing: -0.02em;
            font-weight: 800;
        }

        .fallback-card p {
            margin: 0 0 18px;
            color: var(--muted);
            line-height: 1.5;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0 auto 14px;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
        }

        .status-pill.success {
            color: #1f6d2f;
            background: #e8f8eb;
            border: 1px solid #b8e6c1;
        }

        .status-pill.warn {
            color: #8c2f3e;
            background: #feecef;
            border: 1px solid #f7c8d0;
        }

        .page-subtitle {
            margin: -4px 0 14px;
            color: var(--muted);
            font-size: 14px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .redirect-note {
            margin-top: 10px;
            color: var(--muted);
            font-size: 14px;
        }

        .redirect-note strong {
            color: var(--primary-dark);
        }

        .user-meta {
            margin: 0 0 20px;
            padding: 14px 16px;
            border-radius: 14px;
            background: var(--meta-bg);
            color: var(--meta-text);
            text-align: left;
            border: 1px solid var(--border-soft);
            display: grid;
            gap: 8px;
        }

        .user-meta div {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .user-meta strong {
            display: inline-block;
            min-width: 54px;
        }

        .actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
            align-items: stretch;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 124px;
            padding: 10px 16px;
            border: 0;
            border-radius: 11px;
            font-weight: 600;
            font-size: 0.94rem;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
            outline: none;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn:active {
            transform: translateY(0);
            filter: brightness(0.98);
        }

        .btn:focus-visible {
            box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.55), 0 0 0 7px rgba(65, 40, 134, 0.35);
        }

        .btn-danger {
            color: #fff;
            background: var(--danger);
            box-shadow: 0 12px 24px rgba(220, 53, 69, 0.22);
        }

        .btn-secondary {
            color: #fff;
            background: var(--secondary);
            box-shadow: 0 12px 24px rgba(92, 99, 122, 0.2);
        }

        .btn-primary {
            color: #fff;
            background: var(--primary);
            box-shadow: 0 12px 24px rgba(15, 76, 129, 0.22);
        }

        @media (prefers-reduced-motion: reduce) {
            .btn { transition: none; }
            .btn:hover { transform: none; }
        }

        .swal2-popup {
            border-radius: 24px;
        }

        .swal2-html-container {
            line-height: 1.6;
        }

        .swal-intro {
            margin-bottom: 8px;
        }

        .swal-count {
            margin-top: 8px;
            color: #5f6272;
            font-size: 14px;
        }

        .swal-meta {
            margin-top: 10px;
            padding: 14px 16px;
            border-radius: 14px;
            background: var(--meta-bg);
            color: var(--meta-text);
            text-align: left;
            border: 1px solid var(--border-soft);
            display: grid;
            gap: 8px;
        }

        .swal-meta div {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .swal-button {
            border: 0;
            border-radius: 12px;
            padding: 12px 20px;
            font-size: 15px;
            font-weight: 600;
            color: #fff;
            cursor: pointer;
        }

        .swal-button:focus-visible {
            outline: none;
            box-shadow: 0 0 0 4px rgba(15, 76, 129, 0.22);
        }

        .swal-confirm {
            background: var(--danger);
            box-shadow: 0 12px 24px rgba(220, 53, 69, 0.22);
        }

        .swal-cancel {
            background: var(--secondary);
            box-shadow: 0 12px 24px rgba(92, 99, 122, 0.2);
        }

        .swal-success {
            background: var(--primary);
            box-shadow: 0 12px 24px rgba(15, 76, 129, 0.22);
        }

        .swal2-actions {
            width: 100%;
            justify-content: center;
            gap: 12px;
            margin-top: 22px;
        }

        .swal2-actions .swal-button {
            min-width: 150px;
        }

        @media (max-width: 520px) {
            .fallback-card {
                padding: 28px 20px;
                border-radius: 18px;
            }

            .fallback-card h1 {
                font-size: 24px;
            }

            .actions {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .actions .btn {
                min-width: 0;
                width: 100%;
                padding: 10px 10px;
                font-size: 0.88rem;
            }

            .user-meta div,
            .swal-meta div {
                align-items: flex-start;
                flex-direction: column;
                gap: 2px;
            }

            .swal2-actions {
                flex-direction: row;
                align-items: center;
                gap: 8px;
            }

            .swal2-actions .swal-button {
                width: auto;
                min-width: 118px;
                padding: 10px 14px;
                font-size: 14px;
            }
        }

        @media (max-width: 860px) {
            .logout-shell {
                width: min(560px, 100%);
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .visual-panel {
                padding: 18px 16px;
                border-radius: 18px;
            }

            .fallback-card {
                max-width: 100%;
                justify-self: stretch;
            }
        }

        @media (max-width: 520px) {
            body {
                padding: calc(14px + env(safe-area-inset-top, 0px)) calc(14px + env(safe-area-inset-right, 0px)) calc(14px + env(safe-area-inset-bottom, 0px)) calc(14px + env(safe-area-inset-left, 0px));
            }

            .visual-panel {
                gap: 12px;
            }

            .visual-panel p,
            .visual-points li {
                font-size: 0.9rem;
            }
        }
    </style>
    <?php if ($logout_completed): ?>
    <meta http-equiv="refresh" content="2;url=login.php">
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="manifest" href="/clearance/manifest.webmanifest">
    <meta name="theme-color" content="#412886">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="BISU Clearance">
    <link rel="apple-touch-icon" href="/clearance/assets/img/logo.png">
    <script defer src="/clearance/assets/js/pwa-register.js"></script>
</head>
<body>
    <div class="logout-shell" id="logoutShell">
        <aside class="visual-panel" aria-hidden="true">
            <h2>Secure Session Exit</h2>
            <p>Your BISU clearance account is protected with a full-session sign out and secure redirect flow.</p>
            <ul class="visual-points">
                <li><i class="fas fa-shield-check"></i><span>Session cookie is cleared after confirmation.</span></li>
                <li><i class="fas fa-clock-rotate-left"></i><span>Automatic redirect returns you to login quickly.</span></li>
                <li><i class="fas fa-user-lock"></i><span>Helps keep your account safe on shared devices.</span></li>
            </ul>
        </aside>

        <div class="fallback-card" id="fallbackCard" aria-live="polite">
            <div class="brand-chip"><i class="fas fa-shield-alt"></i> BISU Clearance</div>
            <?php if ($logout_completed): ?>
                <div class="status-pill success"><i class="fas fa-check-circle"></i> Session Ended</div>
                <div class="page-subtitle">Secure Sign-out</div>
                <h1>Logged out</h1>
                <p>Your session has been closed securely. If the popup does not appear, continue to the login page.</p>
                <p class="redirect-note">Redirecting in <strong><span id="fallbackCountdown">2</span>s</strong>...</p>
                <a href="login.php" class="btn btn-primary">Go to Login</a>
            <?php else: ?>
                <div class="status-pill warn"><i class="fas fa-right-from-bracket"></i> Confirmation Needed</div>
                <div class="page-subtitle">Session Security</div>
                <h1>Logout</h1>
                <p>A SweetAlert confirmation will open automatically. If it does not, you can still continue below.</p>
                <div class="user-meta">
                    <div><strong>Name:</strong> <?php echo $user_name_html; ?></div>
                    <div><strong>Role:</strong> <?php echo $role_label_html; ?></div>
                </div>
                <div class="actions">
                    <a href="logout.php?confirm=yes" class="btn btn-danger">Yes, Logout</a>
                    <a href="login.php" class="btn btn-secondary">Cancel</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const logoutShell = document.getElementById('logoutShell');
        const fallbackCard = document.getElementById('fallbackCard');
        const fallbackCountdown = document.getElementById('fallbackCountdown');
        const loginUrl = 'login.php';
        const confirmUrl = 'logout.php?confirm=yes';
        const userName = <?php echo json_encode($user_name_html, $json_flags); ?>;
        const userRole = <?php echo json_encode($role_label_html, $json_flags); ?>;

        function goBackOrFallback() {
            if (!document.referrer) {
                window.location.href = loginUrl;
                return;
            }

            try {
                const referrerUrl = new URL(document.referrer);

                if (
                    referrerUrl.origin === window.location.origin &&
                    referrerUrl.pathname !== window.location.pathname
                ) {
                    window.location.href = referrerUrl.href;
                    return;
                }
            } catch (error) {
                // Ignore malformed referrers and use the fallback URL below.
            }

            window.location.href = loginUrl;
        }

        if (window.Swal) {
            if (logoutShell) {
                logoutShell.style.display = 'none';
            }

            <?php if ($logout_completed): ?>
            Swal.fire({
                icon: 'success',
                title: 'Logged out',
                html: `
                    <div class="swal-intro">You have been signed out successfully.</div>
                    <div class="swal-count">Redirecting in <strong><span id="swalCountdown">2</span>s</strong>...</div>
                `,
                confirmButtonText: 'Go to login',
                allowOutsideClick: false,
                allowEscapeKey: false,
                timer: 2200,
                timerProgressBar: true,
                buttonsStyling: false,
                customClass: {
                    confirmButton: 'swal-button swal-success'
                },
                didOpen: () => {
                    const countdownElement = document.getElementById('swalCountdown');
                    let secondsLeft = 2;
                    const intervalId = setInterval(() => {
                        secondsLeft = Math.max(0, secondsLeft - 1);
                        if (countdownElement) {
                            countdownElement.textContent = String(secondsLeft);
                        }
                        if (secondsLeft <= 0) {
                            clearInterval(intervalId);
                        }
                    }, 1000);
                }
            }).then(() => {
                window.location.href = loginUrl;
            });

            setTimeout(() => {
                window.location.href = loginUrl;
            }, 1800);
            <?php else: ?>
            Swal.fire({
                icon: 'warning',
                title: 'Logout?',
                html: `
                    <div class="swal-intro">You are about to end your current session.</div>
                    <div class="swal-meta">
                        <div><strong>Name:</strong> ${userName}</div>
                        <div><strong>Role:</strong> ${userRole}</div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Yes, logout',
                cancelButtonText: 'Cancel',
                allowOutsideClick: false,
                allowEscapeKey: true,
                reverseButtons: true,
                buttonsStyling: false,
                customClass: {
                    confirmButton: 'swal-button swal-confirm',
                    cancelButton: 'swal-button swal-cancel'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = confirmUrl;
                    return;
                }

                goBackOrFallback();
            });
            <?php endif; ?>
        } else if (fallbackCountdown) {
            let secondsLeft = 2;
            const intervalId = setInterval(() => {
                secondsLeft = Math.max(0, secondsLeft - 1);
                fallbackCountdown.textContent = String(secondsLeft);
                if (secondsLeft <= 0) {
                    clearInterval(intervalId);
                }
            }, 1000);
        }
    </script>
</body>
</html>

