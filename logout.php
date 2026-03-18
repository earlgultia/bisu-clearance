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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout - BISU Clearance</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 24px;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background:
                radial-gradient(circle at top, rgba(255, 255, 255, 0.2), transparent 35%),
                linear-gradient(135deg, #29185c 0%, #5d43b2 100%);
        }

        .fallback-card {
            width: min(100%, 420px);
            padding: 32px 28px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 18px 45px rgba(24, 17, 58, 0.25);
            text-align: center;
        }

        .fallback-card h1 {
            margin: 0 0 12px;
            color: #24164d;
            font-size: 28px;
        }

        .fallback-card p {
            margin: 0 0 18px;
            color: #5f6272;
            line-height: 1.5;
        }

        .user-meta {
            margin: 0 0 20px;
            padding: 14px 16px;
            border-radius: 14px;
            background: #f3f0ff;
            color: #3a2f73;
            text-align: left;
        }

        .user-meta strong {
            display: inline-block;
            min-width: 54px;
        }

        .actions {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 140px;
            padding: 12px 18px;
            border: 0;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-danger {
            color: #fff;
            background: #d43c54;
            box-shadow: 0 12px 24px rgba(212, 60, 84, 0.22);
        }

        .btn-secondary {
            color: #fff;
            background: #5c637a;
            box-shadow: 0 12px 24px rgba(92, 99, 122, 0.2);
        }

        .btn-primary {
            color: #fff;
            background: #4b38a8;
            box-shadow: 0 12px 24px rgba(75, 56, 168, 0.22);
        }

        .swal2-popup {
            border-radius: 24px;
        }

        .swal2-html-container {
            line-height: 1.6;
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

        .swal-confirm {
            background: #d43c54;
            box-shadow: 0 12px 24px rgba(212, 60, 84, 0.22);
        }

        .swal-cancel {
            background: #5c637a;
            box-shadow: 0 12px 24px rgba(92, 99, 122, 0.2);
        }

        .swal-success {
            background: #4b38a8;
            box-shadow: 0 12px 24px rgba(75, 56, 168, 0.22);
        }
    </style>
    <?php if ($logout_completed): ?>
    <meta http-equiv="refresh" content="2;url=login.php">
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
    <div class="fallback-card" id="fallbackCard">
        <?php if ($logout_completed): ?>
            <h1>Logged out</h1>
            <p>Your session has been closed. If the popup does not appear, continue to the login page.</p>
            <a href="login.php" class="btn btn-primary">Go to Login</a>
        <?php else: ?>
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

    <script>
        const fallbackCard = document.getElementById('fallbackCard');
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
            fallbackCard.style.display = 'none';

            <?php if ($logout_completed): ?>
            Swal.fire({
                icon: 'success',
                title: 'Logged out',
                text: 'You have been signed out successfully.',
                confirmButtonText: 'Go to login',
                allowOutsideClick: false,
                allowEscapeKey: false,
                buttonsStyling: false,
                customClass: {
                    confirmButton: 'swal-button swal-success'
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
                    <div style="margin-bottom: 8px;">You are about to end your current session.</div>
                    <div style="padding: 14px 16px; border-radius: 14px; background: #f3f0ff; color: #3a2f73; text-align: left;">
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
        }
    </script>
</body>
</html>
