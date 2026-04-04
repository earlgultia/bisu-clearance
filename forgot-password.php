<?php
// forgot-password.php - secure password reset request and update flow

require_once __DIR__ . '/db.php';

if (function_exists('initSession')) {
    initSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const RESET_TOKEN_TTL_MINUTES = 60;
const RESET_REQUEST_WINDOW_SECONDS = 3600;
const RESET_REQUEST_MAX_PER_IP = 12;
const RESET_REQUEST_MAX_PER_IDENTIFIER = 5;
const RESET_REQUEST_COOLDOWN_SECONDS = 60;
const RESET_SUBMIT_WINDOW_SECONDS = 1800;
const RESET_SUBMIT_MAX_PER_IP = 20;
const RESET_CLEANUP_RETENTION_DAYS = 7;
const RESET_CSRF_SESSION_KEY = '_password_reset_csrf';

function getRuntimeEnvironmentMeta()
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);

    $isDebug = defined('DEBUG_MODE') && DEBUG_MODE;
    $isLocalHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    $appEnv = trim((string) (getenv('APP_ENV') ?: ''));

    if ($appEnv !== '') {
        $label = ucfirst(strtolower($appEnv));
        $isDevelopment = in_array(strtolower($appEnv), ['dev', 'development', 'local', 'staging'], true);
    } elseif ($isDebug || $isLocalHost) {
        $label = 'Development';
        $isDevelopment = true;
    } else {
        $label = 'Production';
        $isDevelopment = false;
    }

    return [
        'label' => $label,
        'is_development' => $isDevelopment,
        'host' => $host !== '' ? $host : 'unknown-host'
    ];
}

function getMailTransportSummary()
{
    $smtp = trim((string) ini_get('SMTP'));
    $smtpPort = trim((string) ini_get('smtp_port'));
    $sendmailPath = trim((string) ini_get('sendmail_path'));

    if (PHP_OS_FAMILY === 'Windows') {
        if ($smtp === '') {
            return 'mail() transport missing: set SMTP in php.ini';
        }

        return 'SMTP transport: ' . $smtp . ($smtpPort !== '' ? ':' . $smtpPort : '');
    }

    if ($sendmailPath === '') {
        return 'mail() transport missing: set sendmail_path in php.ini';
    }

    return 'sendmail transport configured';
}

function shouldExposeResetLinksForTesting()
{
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        return true;
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);

    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function normalizeEmail($email)
{
    return strtolower(trim((string) $email));
}

function normalizeToken($token)
{
    return strtolower(trim((string) $token));
}

function isValidTokenFormat($token)
{
    return (bool) preg_match('/^[a-f0-9]{64}$/', $token);
}

function getClientIpAddress()
{
    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (!empty($forwarded)) {
        $parts = explode(',', $forwarded);
        $candidate = trim($parts[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

function hashIdentifier($value)
{
    return hash('sha256', strtolower(trim((string) $value)));
}

function buildResetLink($token)
{
    $baseUrl = defined('BASE_URL') ? trim((string) BASE_URL) : '';

    if ($baseUrl !== '' && preg_match('/^https?:\/\//i', $baseUrl)) {
        return rtrim($baseUrl, '/') . '/forgot-password.php?token=' . urlencode($token);
    }

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/clearance/forgot-password.php');
    $scriptDir = str_replace('\\', '/', $scriptDir);
    $scriptDir = rtrim($scriptDir, '/');

    if ($scriptDir === '' || $scriptDir === '.') {
        $scriptDir = '/clearance';
    }

    return $scheme . '://' . $host . $scriptDir . '/forgot-password.php?token=' . urlencode($token);
}

function ensurePasswordResetTables()
{
    $db = Database::getInstance();

    $db->query("CREATE TABLE IF NOT EXISTS password_resets (
                reset_id INT AUTO_INCREMENT PRIMARY KEY,
                account_email VARCHAR(255) NOT NULL,
                user_type ENUM('user', 'organization') NOT NULL,
                user_id INT NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uidx_token_hash (token_hash),
                INDEX idx_account_email (account_email),
                INDEX idx_expires_at (expires_at),
                INDEX idx_user_ref (user_type, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->execute();

    $db->query("CREATE TABLE IF NOT EXISTS password_reset_attempts (
                attempt_id BIGINT AUTO_INCREMENT PRIMARY KEY,
                identifier_hash CHAR(64) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                action_type ENUM('request', 'reset') NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_identifier_action_created (identifier_hash, action_type, created_at),
                INDEX idx_ip_action_created (ip_address, action_type, created_at),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->execute();
}

function cleanupPasswordResetData()
{
    $db = Database::getInstance();

    $db->query("DELETE FROM password_resets
                WHERE used_at IS NOT NULL
                   OR expires_at < NOW() - INTERVAL :retention DAY");
    $db->bind(':retention', RESET_CLEANUP_RETENTION_DAYS);
    $db->execute();

    $db->query("DELETE FROM password_reset_attempts
                WHERE created_at < NOW() - INTERVAL :retention DAY");
    $db->bind(':retention', RESET_CLEANUP_RETENTION_DAYS);
    $db->execute();
}

function recordResetAttempt($identifier, $ipAddress, $actionType)
{
    $db = Database::getInstance();

    $db->query("INSERT INTO password_reset_attempts (identifier_hash, ip_address, action_type)
                VALUES (:identifier_hash, :ip_address, :action_type)");
    $db->bind(':identifier_hash', hashIdentifier($identifier));
    $db->bind(':ip_address', $ipAddress);
    $db->bind(':action_type', $actionType);
    $db->execute();
}

function countRecentAttemptsByIp($ipAddress, $actionType, $windowSeconds)
{
    $db = Database::getInstance();

    $db->query("SELECT COUNT(*) AS attempt_count
                FROM password_reset_attempts
                WHERE ip_address = :ip_address
                  AND action_type = :action_type
                  AND created_at >= :window_start");
    $db->bind(':ip_address', $ipAddress);
    $db->bind(':action_type', $actionType);
    $db->bind(':window_start', date('Y-m-d H:i:s', time() - $windowSeconds));
    $row = $db->single();

    return (int) ($row['attempt_count'] ?? 0);
}

function countRecentAttemptsByIdentifier($identifier, $actionType, $windowSeconds)
{
    $db = Database::getInstance();

    $db->query("SELECT COUNT(*) AS attempt_count
                FROM password_reset_attempts
                WHERE identifier_hash = :identifier_hash
                  AND action_type = :action_type
                  AND created_at >= :window_start");
    $db->bind(':identifier_hash', hashIdentifier($identifier));
    $db->bind(':action_type', $actionType);
    $db->bind(':window_start', date('Y-m-d H:i:s', time() - $windowSeconds));
    $row = $db->single();

    return (int) ($row['attempt_count'] ?? 0);
}

function getLastRequestTimestamp($identifier, $ipAddress)
{
    $db = Database::getInstance();

    $db->query("SELECT created_at
                FROM password_reset_attempts
                WHERE identifier_hash = :identifier_hash
                  AND ip_address = :ip_address
                  AND action_type = 'request'
                ORDER BY created_at DESC
                LIMIT 1");
    $db->bind(':identifier_hash', hashIdentifier($identifier));
    $db->bind(':ip_address', $ipAddress);
    $row = $db->single();

    return !empty($row['created_at']) ? strtotime($row['created_at']) : null;
}

function canRequestReset($email, $ipAddress, &$errorMessage)
{
    $errorMessage = '';

    $ipCount = countRecentAttemptsByIp($ipAddress, 'request', RESET_REQUEST_WINDOW_SECONDS);
    if ($ipCount >= RESET_REQUEST_MAX_PER_IP) {
        $errorMessage = 'Too many reset requests from your network. Please wait and try again.';
        return false;
    }

    $identifierCount = countRecentAttemptsByIdentifier($email, 'request', RESET_REQUEST_WINDOW_SECONDS);
    if ($identifierCount >= RESET_REQUEST_MAX_PER_IDENTIFIER) {
        $errorMessage = 'Too many reset requests for this account. Please wait and try again.';
        return false;
    }

    $lastAttempt = getLastRequestTimestamp($email, $ipAddress);
    if ($lastAttempt !== null && (time() - $lastAttempt) < RESET_REQUEST_COOLDOWN_SECONDS) {
        $errorMessage = 'Please wait a moment before requesting another reset link.';
        return false;
    }

    return true;
}

function canSubmitReset($ipAddress, &$errorMessage)
{
    $errorMessage = '';

    $ipCount = countRecentAttemptsByIp($ipAddress, 'reset', RESET_SUBMIT_WINDOW_SECONDS);
    if ($ipCount >= RESET_SUBMIT_MAX_PER_IP) {
        $errorMessage = 'Too many password reset attempts. Please wait and try again.';
        return false;
    }

    return true;
}

function ensureCsrfToken()
{
    if (empty($_SESSION[RESET_CSRF_SESSION_KEY])) {
        $_SESSION[RESET_CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }

    return $_SESSION[RESET_CSRF_SESSION_KEY];
}

function isValidCsrfToken($token)
{
    $sessionToken = $_SESSION[RESET_CSRF_SESSION_KEY] ?? '';
    $submitted = (string) $token;

    return !empty($sessionToken) && hash_equals($sessionToken, $submitted);
}

function findAccountByEmail($email)
{
    $db = Database::getInstance();

    $db->query("SELECT users_id, fname, lname, emails, password
                FROM users
                WHERE emails = :email AND is_active = 1
                LIMIT 1");
    $db->bind(':email', $email);
    $user = $db->single();

    if ($user) {
        return [
            'user_type' => 'user',
            'user_id' => (int) $user['users_id'],
            'email' => (string) $user['emails'],
            'name' => trim(((string) ($user['fname'] ?? '')) . ' ' . ((string) ($user['lname'] ?? ''))),
            'current_password_hash' => (string) ($user['password'] ?? '')
        ];
    }

    $db->query("SELECT org_id, org_name, org_email, org_password
                FROM student_organizations
                WHERE org_email = :email AND status = 'active'
                LIMIT 1");
    $db->bind(':email', $email);
    $org = $db->single();

    if ($org) {
        return [
            'user_type' => 'organization',
            'user_id' => (int) $org['org_id'],
            'email' => (string) $org['org_email'],
            'name' => (string) ($org['org_name'] ?? 'Organization Account'),
            'current_password_hash' => (string) ($org['org_password'] ?? '')
        ];
    }

    return null;
}

function createResetToken($account)
{
    $db = Database::getInstance();

    $db->beginTransaction();

    try {
        $db->query("DELETE FROM password_resets
                    WHERE (account_email = :email)
                       OR used_at IS NOT NULL
                       OR expires_at < NOW()");
        $db->bind(':email', $account['email']);
        $db->execute();

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', time() + (RESET_TOKEN_TTL_MINUTES * 60));

        $db->query("INSERT INTO password_resets (account_email, user_type, user_id, token_hash, expires_at)
                    VALUES (:account_email, :user_type, :user_id, :token_hash, :expires_at)");
        $db->bind(':account_email', $account['email']);
        $db->bind(':user_type', $account['user_type']);
        $db->bind(':user_id', (int) $account['user_id']);
        $db->bind(':token_hash', $tokenHash);
        $db->bind(':expires_at', $expiresAt);

        if (!$db->execute()) {
            throw new RuntimeException('Token insert failed.');
        }

        $db->commit();
        return $token;
    } catch (Exception $e) {
        if ($db->getConnection()->inTransaction()) {
            $db->rollback();
        }
        error_log('Password reset token creation error: ' . $e->getMessage());
        return null;
    }
}

function getValidTokenRecord($token)
{
    if (!isValidTokenFormat($token)) {
        return null;
    }

    $db = Database::getInstance();
    $tokenHash = hash('sha256', $token);

    $db->query("SELECT pr.reset_id,
                       pr.account_email,
                       pr.user_type,
                       pr.user_id,
                       pr.token_hash,
                       pr.expires_at,
                       pr.used_at,
                       CASE
                           WHEN pr.user_type = 'organization' THEN so.org_password
                           ELSE u.password
                       END AS current_password_hash
                FROM password_resets pr
                LEFT JOIN users u
                    ON pr.user_type = 'user'
                   AND pr.user_id = u.users_id
                   AND u.is_active = 1
                LEFT JOIN student_organizations so
                    ON pr.user_type = 'organization'
                   AND pr.user_id = so.org_id
                   AND so.status = 'active'
                WHERE pr.token_hash = :token_hash
                  AND pr.used_at IS NULL
                  AND pr.expires_at > NOW()
                  AND ((pr.user_type = 'user' AND u.users_id IS NOT NULL)
                    OR (pr.user_type = 'organization' AND so.org_id IS NOT NULL))
                LIMIT 1");
    $db->bind(':token_hash', $tokenHash);

    return $db->single();
}

function markTokenUsed($resetId)
{
    $db = Database::getInstance();

    $db->query("UPDATE password_resets
                SET used_at = NOW()
                WHERE reset_id = :reset_id
                  AND used_at IS NULL");
    $db->bind(':reset_id', (int) $resetId);

    return $db->execute();
}

function invalidateAccountTokens($tokenRecord)
{
    $db = Database::getInstance();

    $db->query("UPDATE password_resets
                SET used_at = NOW()
                WHERE user_type = :user_type
                  AND user_id = :user_id
                  AND used_at IS NULL");
    $db->bind(':user_type', $tokenRecord['user_type']);
    $db->bind(':user_id', (int) $tokenRecord['user_id']);

    return $db->execute();
}

function updateAccountPassword($tokenRecord, $newPasswordHash)
{
    $db = Database::getInstance();

    if (($tokenRecord['user_type'] ?? '') === 'organization') {
        $db->query("UPDATE student_organizations
                    SET org_password = :password
                    WHERE org_id = :id AND status = 'active'");
        $db->bind(':password', $newPasswordHash);
        $db->bind(':id', (int) $tokenRecord['user_id']);
        return $db->execute();
    }

    $db->query("UPDATE users
                SET password = :password,
                    updated_at = NOW()
                WHERE users_id = :id AND is_active = 1");
    $db->bind(':password', $newPasswordHash);
    $db->bind(':id', (int) $tokenRecord['user_id']);
    return $db->execute();
}

function validateStrongPassword($password, &$errorMessage)
{
    $errorMessage = '';
    $password = (string) $password;

    if (strlen($password) < 10) {
        $errorMessage = 'Password must be at least 10 characters long.';
        return false;
    }

    if (strlen($password) > 128) {
        $errorMessage = 'Password is too long.';
        return false;
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errorMessage = 'Password must include at least one uppercase letter.';
        return false;
    }

    if (!preg_match('/[a-z]/', $password)) {
        $errorMessage = 'Password must include at least one lowercase letter.';
        return false;
    }

    if (!preg_match('/\d/', $password)) {
        $errorMessage = 'Password must include at least one number.';
        return false;
    }

    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errorMessage = 'Password must include at least one special character.';
        return false;
    }

    return true;
}

function sendResetEmail($email, $name, $resetLink)
{
    $safeName = trim((string) $name);
    if ($safeName === '') {
        $safeName = 'User';
    }

    $subject = 'BISU Clearance Password Reset';
    $body = "Hello {$safeName},\n\n"
        . "We received a request to reset your password.\n"
        . "Use this link within " . RESET_TOKEN_TTL_MINUTES . " minutes:\n\n"
        . "{$resetLink}\n\n"
        . "If you did not request this, you can ignore this email.\n\n"
        . "- BISU Online Clearance";

    $headers = [
        'From: no-reply@bisu-clearance.local',
        'Reply-To: no-reply@bisu-clearance.local',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8'
    ];

    return @mail($email, $subject, $body, implode("\r\n", $headers));
}

ensurePasswordResetTables();

if (function_exists('shouldRunMaintenanceTask') && shouldRunMaintenanceTask('password_reset_cleanup', 1800)) {
    cleanupPasswordResetData();
}

$environmentMeta = getRuntimeEnvironmentMeta();
$mailTransportSummary = getMailTransportSummary();

$message = '';
$error = '';
$generatedResetLink = '';
$isResetMode = false;
$tokenValid = false;
$clientIp = getClientIpAddress();
$currentToken = normalizeToken($_GET['token'] ?? '');

if ($currentToken !== '') {
    $isResetMode = true;
    $tokenValid = (bool) getValidTokenRecord($currentToken);
}

$csrfToken = ensureCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isValidCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Your session security token is invalid. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'request_reset') {
            $email = normalizeEmail($_POST['email'] ?? '');

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid email address.';
            } else {
                $limitError = '';
                if (!canRequestReset($email, $clientIp, $limitError)) {
                    $error = $limitError;
                } else {
                    recordResetAttempt($email, $clientIp, 'request');

                    $account = findAccountByEmail($email);
                    $message = 'If this email exists in our system, a password reset link will be sent shortly.';

                    if ($account) {
                        $token = createResetToken($account);
                        if ($token !== null) {
                            $resetLink = buildResetLink($token);
                            $mailSent = sendResetEmail($account['email'], $account['name'], $resetLink);

                            if (shouldExposeResetLinksForTesting()) {
                                $generatedResetLink = $resetLink;
                                $message = $mailSent
                                    ? 'Email send was triggered in ' . $environmentMeta['label'] . ' environment. For local testing, use the generated reset link below.'
                                    : 'Mail is unavailable in this environment (' . $environmentMeta['label'] . '). Use the generated reset link below.';
                            }
                        } else {
                            $error = 'Unable to start password reset right now. Please try again.';
                        }
                    }
                }
            }
        }

        if ($action === 'reset_password') {
            $token = normalizeToken($_POST['token'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

            $isResetMode = true;
            $currentToken = $token;

            $limitError = '';
            if (!canSubmitReset($clientIp, $limitError)) {
                $error = $limitError;
                $tokenValid = (bool) getValidTokenRecord($token);
            } else {
                recordResetAttempt($token !== '' ? $token : 'empty-token', $clientIp, 'reset');
                $tokenRecord = getValidTokenRecord($token);

                if (!$tokenRecord) {
                    $tokenValid = false;
                    $error = 'This reset link is invalid or expired. Please request a new one.';
                } elseif (!hash_equals($password, $confirmPassword)) {
                    $tokenValid = true;
                    $error = 'Password confirmation does not match.';
                } else {
                    $passwordError = '';
                    if (!validateStrongPassword($password, $passwordError)) {
                        $tokenValid = true;
                        $error = $passwordError;
                    } elseif (!empty($tokenRecord['current_password_hash']) && password_verify($password, $tokenRecord['current_password_hash'])) {
                        $tokenValid = true;
                        $error = 'Your new password must be different from your current password.';
                    } else {
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        if ($newHash === false) {
                            $tokenValid = true;
                            $error = 'Unable to secure your new password. Please try again.';
                        } else {
                            $updated = updateAccountPassword($tokenRecord, $newHash);

                            if ($updated) {
                                markTokenUsed((int) $tokenRecord['reset_id']);
                                invalidateAccountTokens($tokenRecord);

                                if (($tokenRecord['user_type'] ?? '') === 'user' && !empty($tokenRecord['user_id'])) {
                                    $db = Database::getInstance();
                                    $db->query("INSERT INTO activity_logs (users_id, action, description, ip_address, created_at)
                                                VALUES (:users_id, :action, :description, :ip_address, NOW())");
                                    $db->bind(':users_id', (int) $tokenRecord['user_id']);
                                    $db->bind(':action', 'PASSWORD_RESET');
                                    $db->bind(':description', 'User reset account password successfully');
                                    $db->bind(':ip_address', $clientIp);
                                    $db->execute();
                                }

                                $isResetMode = false;
                                $tokenValid = false;
                                $currentToken = '';
                                $message = 'Your password has been reset successfully. You may now log in.';
                            } else {
                                $tokenValid = true;
                                $error = 'Unable to update password right now. Please try again.';
                            }
                        }
                    }
                }
            }
        }
    }

    $csrfToken = ensureCsrfToken();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - BISU Online Clearance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Manrope', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        :root {
            --primary: #412886;
            --primary-dark: #2e1d5e;
            --primary-light: #6b4bb8;
            --primary-soft: rgba(65, 40, 134, 0.12);
            --success: #15803d;
            --danger: #dc2626;
            --bg: #f8fafc;
            --card-bg: rgba(255, 255, 255, 0.94);
            --text: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                radial-gradient(circle at 15% 12%, rgba(65, 40, 134, 0.12) 0%, transparent 35%),
                radial-gradient(circle at 84% 10%, rgba(56, 189, 248, 0.12) 0%, transparent 32%),
                linear-gradient(145deg, #f8fafc 0%, #f1f5f9 100%);
            padding: 20px;
        }

        .card {
            width: 100%;
            max-width: 560px;
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 24px;
            box-shadow: 0 24px 44px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(12px);
            padding: 34px;
        }

        .brand {
            text-align: center;
            margin-bottom: 22px;
        }

        .logo-wrap {
            width: 72px;
            height: 72px;
            border-radius: 20px;
            margin: 0 auto 14px;
            overflow: hidden;
            box-shadow: 0 10px 22px rgba(65, 40, 134, 0.25);
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
        }

        .logo-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .brand h1 {
            font-family: 'Space Grotesk', 'Manrope', sans-serif;
            font-size: 1.6rem;
            color: var(--primary-dark);
            margin-bottom: 6px;
        }

        .brand p {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        .environment-pill {
            margin-top: 10px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            border: 1px solid transparent;
        }

        .environment-pill.dev {
            color: #1d4ed8;
            background: #dbeafe;
            border-color: #bfdbfe;
        }

        .environment-pill.prod {
            color: #0f766e;
            background: #ccfbf1;
            border-color: #99f6e4;
        }

        .notice {
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 14px;
            font-size: 0.92rem;
            line-height: 1.45;
        }

        .notice.success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: var(--success);
        }

        .notice.error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: var(--danger);
        }

        .reset-link-box {
            margin-top: 10px;
            padding: 10px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            word-break: break-all;
        }

        .reset-link-box a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
        }

        .reset-link-hint {
            margin-top: 8px;
            color: var(--text-muted);
            font-size: 0.82rem;
            line-height: 1.4;
        }

        .form-group {
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text);
            font-size: 0.92rem;
        }

        input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 0.95rem;
            color: var(--text);
            background: #fff;
        }

        input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-soft);
        }

        .password-wrap {
            position: relative;
        }

        .password-wrap input {
            padding-right: 44px;
        }

        .toggle-pass {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #64748b;
            cursor: pointer;
            font-size: 0.95rem;
        }

        .btn {
            width: 100%;
            border: none;
            border-radius: 12px;
            padding: 14px;
            color: #fff;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .links {
            margin-top: 16px;
            text-align: center;
            font-size: 0.9rem;
        }

        .links a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }

        .helper {
            color: var(--text-muted);
            font-size: 0.86rem;
            margin-top: -4px;
            margin-bottom: 14px;
            line-height: 1.4;
        }

        .password-rules {
            margin-top: -4px;
            margin-bottom: 14px;
            color: var(--text-muted);
            font-size: 0.84rem;
            line-height: 1.45;
            padding-left: 18px;
        }

        .password-rules li {
            margin-bottom: 2px;
        }

        @media (max-width: 540px) {
            .card {
                padding: 24px 18px;
                border-radius: 18px;
            }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">
            <div class="logo-wrap">
                <img src="assets/img/logo.png" alt="BISU Logo">
            </div>
            <h1>Reset Your Password</h1>
            <p>BISU Online Clearance Account Recovery</p>
            <div class="environment-pill <?php echo $environmentMeta['is_development'] ? 'dev' : 'prod'; ?>">
                <i class="fas fa-server"></i>
                Environment: <?php echo htmlspecialchars($environmentMeta['label']); ?>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="notice success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                <?php if (!empty($generatedResetLink)): ?>
                    <div class="reset-link-box">
                        <a href="<?php echo htmlspecialchars($generatedResetLink); ?>"><?php echo htmlspecialchars($generatedResetLink); ?></a>
                        <div class="reset-link-hint">
                            Environment: <?php echo htmlspecialchars($environmentMeta['label']); ?> (<?php echo htmlspecialchars($environmentMeta['host']); ?>)<br>
                            Mail transport: <?php echo htmlspecialchars($mailTransportSummary); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="notice error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($isResetMode): ?>
            <?php if ($tokenValid): ?>
                <form method="POST" action="" autocomplete="off">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($currentToken); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                    <div class="form-group">
                        <label for="password">New Password</label>
                        <div class="password-wrap">
                            <input type="password" id="password" name="password" minlength="10" maxlength="128" autocomplete="new-password" required>
                            <button class="toggle-pass" type="button" data-target="password" aria-label="Show password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="password-wrap">
                            <input type="password" id="confirm_password" name="confirm_password" minlength="10" maxlength="128" autocomplete="new-password" required>
                            <button class="toggle-pass" type="button" data-target="confirm_password" aria-label="Show password confirmation">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <ul class="password-rules">
                        <li>Minimum 10 characters</li>
                        <li>At least one uppercase letter</li>
                        <li>At least one lowercase letter</li>
                        <li>At least one number</li>
                        <li>At least one special character</li>
                    </ul>

                    <button type="submit" class="btn">Update Password</button>
                </form>
            <?php else: ?>
                <p class="helper">Your reset link is no longer valid. Request a new password reset link below.</p>
                <form method="POST" action="" autocomplete="off">
                    <input type="hidden" name="action" value="request_reset">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <div class="form-group">
                        <label for="email">Account Email</label>
                        <input type="email" id="email" name="email" placeholder="you@example.com" autocomplete="email" required>
                    </div>
                    <button type="submit" class="btn">Send New Reset Link</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <form method="POST" action="" autocomplete="off">
                <input type="hidden" name="action" value="request_reset">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <div class="form-group">
                    <label for="email">Account Email</label>
                    <input type="email" id="email" name="email" placeholder="you@example.com" autocomplete="email" required>
                </div>

                <p class="helper">Enter the email tied to your BISU clearance account and we will send a reset link.</p>

                <button type="submit" class="btn">Send Reset Link</button>
            </form>
        <?php endif; ?>

        <div class="links">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
        </div>
    </div>

    <script>
        document.querySelectorAll('.toggle-pass').forEach(button => {
            button.addEventListener('click', () => {
                const targetId = button.getAttribute('data-target');
                const input = document.getElementById(targetId);
                if (!input) {
                    return;
                }

                const isPassword = input.type === 'password';
                input.type = isPassword ? 'text' : 'password';
                button.innerHTML = isPassword
                    ? '<i class="fas fa-eye-slash"></i>'
                    : '<i class="fas fa-eye"></i>';
            });
        });
    </script>
</body>
</html>
