<?php
// student/dashboard.php - Complete Student Dashboard with Proof Upload Functionality
// Students can upload proof files to specific sub-admins/organizations

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent showing stale authenticated pages from cache after logout or back navigation.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Enable verbose errors only in development.
$isDevelopmentEnvironment = file_exists(__DIR__ . '/../.env');
if ($isDevelopmentEnvironment) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

// Include database configuration with correct path
require_once __DIR__ . '/../db.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

if ($_SESSION['user_role'] !== 'student') {
    header("Location: ../index.php");
    exit();
}

// Get database instance
$db = Database::getInstance();
$runStudentSchemaMaintenance = shouldRunMaintenanceTask('student_dashboard_schema_migrations', 21600);

function ensureOrganizationProofColumns($db)
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    if (!shouldRunMaintenanceTask('organization_clearance_student_proof_columns', 21600)) {
        return;
    }

    try {
        if (!hasDatabaseColumn('organization_clearance', 'student_proof_file')) {
            $db->query("ALTER TABLE organization_clearance
                        ADD COLUMN student_proof_file VARCHAR(255) NULL AFTER remarks,
                        ADD COLUMN student_proof_remarks TEXT NULL AFTER student_proof_file,
                        ADD COLUMN student_proof_uploaded_at DATETIME NULL AFTER student_proof_remarks");
            $db->execute();
        }
    } catch (Exception $e) {
        error_log("Error ensuring organization proof columns: " . $e->getMessage());
    }
}

ensureOrganizationProofColumns($db);

// Get student information from session
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? '';
$student_email = $_SESSION['user_email'] ?? '';
$student_ismis = $_SESSION['ismis_id'] ?? '';
$student_fname = $_SESSION['user_fname'] ?? '';
$student_lname = $_SESSION['user_lname'] ?? '';

// Initialize variables
$success = '';
$error = '';
$active_tab = $_GET['tab'] ?? 'dashboard';
$profile_pic = null;

// Get current school year and semesters
$current_year = (int) date('Y');
// Clearance applications always start in 2nd Semester.
$current_semester = '2nd Semester';
// Roll school year forward automatically when calendar year changes.
$school_year = ($current_year - 1) . '-' . $current_year;
$semesters = [$current_semester];
$student_contacts = [];
$student_messages = [];
$inbox_messages = [];
$sent_messages = [];
$selected_chat_id = 0;
$selected_chat_friend = null;
$selected_conversation_messages = [];
$conversation_map = [];
$friends_by_id = [];
$unread_message_count = 0;
$student_friends = [];
$incoming_friend_requests = [];
$outgoing_friend_requests = [];
$incoming_friend_request_count = 0;
$message_tab_notification_count = 0;
$message_reactions_map = [];
$allowed_message_reactions = ['👍', '❤️', '😂', '😮', '😢', '🔥'];
$reply_to_message_prefill_id = 0;
$reply_preview_message = null;
$selected_chat_friend_label = null;
$selected_chat_friend_base_name = null;
$user_presence_columns_available = false;
$user_profile_picture_column_available = false;
$message_time_display_offset_seconds = 0;

function buildMessageSnippet($text, $maxChars = 120, $collapseWhitespace = true)
{
    $snippet = (string) $text;

    if ($collapseWhitespace) {
        $snippet = preg_replace('/\s+/', ' ', $snippet);
    }

    $snippet = trim((string) $snippet);
    if ($snippet === '') {
        return '';
    }

    $maxChars = (int) $maxChars;
    if ($maxChars <= 3) {
        return $snippet;
    }

    $length = function_exists('mb_strlen') ? mb_strlen($snippet, 'UTF-8') : strlen($snippet);
    if ($length <= $maxChars) {
        return $snippet;
    }

    $trimLength = $maxChars - 3;
    $trimmed = function_exists('mb_substr')
        ? mb_substr($snippet, 0, $trimLength, 'UTF-8')
        : substr($snippet, 0, $trimLength);

    return rtrim((string) $trimmed) . '...';
}

function getMessageTimeDisplayOffsetSeconds($db)
{
    static $offset_seconds = null;

    if ($offset_seconds !== null) {
        return $offset_seconds;
    }

    $offset_seconds = 0;

    try {
        $db->query("SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) AS db_offset_seconds");
        $row = $db->single();
        $db_offset_seconds = (int) ($row['db_offset_seconds'] ?? 0);

        $app_timezone_name = date_default_timezone_get();
        if (!is_string($app_timezone_name) || $app_timezone_name === '') {
            $app_timezone_name = 'Asia/Manila';
        }

        $app_now = new DateTimeImmutable('now', new DateTimeZone($app_timezone_name));
        $app_offset_seconds = (int) $app_now->getOffset();

        $calculated_offset = $app_offset_seconds - $db_offset_seconds;

        // Ignore tiny drifts and only correct meaningful timezone deltas.
        if (abs($calculated_offset) >= 60) {
            $offset_seconds = $calculated_offset;
        }
    } catch (Exception $e) {
        error_log("Message time offset detection error: " . $e->getMessage());
    }

    return $offset_seconds;
}

function formatMessageTimeForDisplay($rawDateTime, $format = 'M d, h:i A', $offsetSeconds = 0)
{
    $raw_value = trim((string) $rawDateTime);
    if ($raw_value === '') {
        return '';
    }

    $timestamp = strtotime($raw_value);
    if ($timestamp === false) {
        return '';
    }

    $offsetSeconds = (int) $offsetSeconds;
    if ($offsetSeconds !== 0) {
        $timestamp += $offsetSeconds;
    }

    return date($format, $timestamp);
}

function buildFriendPresenceMeta($isOnlineFlag, $lastSeenAtRaw, $elapsedSecondsRaw = null)
{
    $isOnline = (int) $isOnlineFlag === 1;
    $statusClass = $isOnline ? 'online' : 'offline';
    $statusText = $isOnline ? 'Active now' : 'Offline';
    $minutesSinceLogout = null;

    if (!$isOnline) {
        $elapsedSeconds = null;

        if ($elapsedSecondsRaw !== null && $elapsedSecondsRaw !== '' && is_numeric($elapsedSecondsRaw)) {
            $elapsedSeconds = max(0, (int) floor((float) $elapsedSecondsRaw));
        } else {
            $lastSeenTs = !empty($lastSeenAtRaw) ? strtotime((string) $lastSeenAtRaw) : false;
            if ($lastSeenTs !== false) {
                $elapsedSeconds = max(0, time() - $lastSeenTs);
            }
        }

        if ($elapsedSeconds !== null) {
            $minutesSinceLogout = (int) floor($elapsedSeconds / 60);

            if ($elapsedSeconds < 60) {
                $statusText = 'Offline · just now';
            } elseif ($elapsedSeconds < 3600) {
                $minutes = max(1, (int) floor($elapsedSeconds / 60));
                $statusText = 'Offline · ' . $minutes . ' ' . ($minutes === 1 ? 'minute' : 'minutes') . ' ago';
            } elseif ($elapsedSeconds < 86400) {
                $hours = max(1, (int) floor($elapsedSeconds / 3600));
                $statusText = 'Offline · ' . $hours . ' ' . ($hours === 1 ? 'hour' : 'hours') . ' ago';
            } else {
                $days = max(1, (int) floor($elapsedSeconds / 86400));
                $statusText = 'Offline · ' . $days . ' ' . ($days === 1 ? 'day' : 'days') . ' ago';
            }
        }
    }

    return [
        'is_online' => $isOnline,
        'status_class' => $statusClass,
        'status_text' => $statusText,
        'minutes_since_logout' => $minutesSinceLogout
    ];
}

function ensureUserPresenceColumns($db)
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    if (!shouldRunMaintenanceTask('users_presence_columns', 21600)) {
        return;
    }

    try {
        if (!hasDatabaseColumn('users', 'is_online')) {
            $db->query("ALTER TABLE users ADD COLUMN is_online TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
            $db->execute();
        }

        if (!hasDatabaseColumn('users', 'last_seen_at')) {
            $db->query("ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL AFTER is_online");
            $db->execute();
        }

        if (hasDatabaseColumn('users', 'is_online') && hasDatabaseColumn('users', 'last_seen_at')) {
            $db->query("SHOW INDEX FROM users WHERE Key_name = 'idx_users_presence'");
            if (!$db->single()) {
                $db->query("ALTER TABLE users ADD INDEX idx_users_presence (is_online, last_seen_at)");
                $db->execute();
            }
        }
    } catch (Exception $e) {
        error_log("Error ensuring users presence columns: " . $e->getMessage());
    }
}

function updateUserPresenceHeartbeat($db, $userId)
{
    static $updated = false;

    if ($updated) {
        return;
    }

    $updated = true;
    $userId = (int) $userId;
    if ($userId <= 0) {
        return;
    }

    if (!hasDatabaseColumn('users', 'is_online') || !hasDatabaseColumn('users', 'last_seen_at')) {
        return;
    }

    try {
        $db->query("UPDATE users
                    SET is_online = 1,
                        last_seen_at = NOW()
                    WHERE users_id = :user_id
                      AND is_active = 1
                    LIMIT 1");
        $db->bind(':user_id', $userId);
        $db->execute();
    } catch (Exception $e) {
        error_log("User presence heartbeat update error: " . $e->getMessage());
    }
}

function ensureStudentMessageAttachmentColumns($db)
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    if (!shouldRunMaintenanceTask('student_messages_attachment_columns', 21600)) {
        return;
    }

    $attachment_columns = [
        'attachment_file' => "ALTER TABLE student_messages ADD COLUMN attachment_file VARCHAR(255) NULL",
        'attachment_name' => "ALTER TABLE student_messages ADD COLUMN attachment_name VARCHAR(255) NULL",
        'attachment_type' => "ALTER TABLE student_messages ADD COLUMN attachment_type VARCHAR(30) NULL",
        'attachment_size' => "ALTER TABLE student_messages ADD COLUMN attachment_size INT NULL"
    ];

    foreach ($attachment_columns as $column_name => $alter_sql) {
        try {
            if (!hasDatabaseColumn('student_messages', $column_name)) {
                $db->query($alter_sql);
                $db->execute();
            }
        } catch (Exception $e) {
            error_log("Error ensuring student_messages {$column_name} column: " . $e->getMessage());
        }
    }
}

ensureUserPresenceColumns($db);
$user_presence_columns_available = hasDatabaseColumn('users', 'is_online') && hasDatabaseColumn('users', 'last_seen_at');
$user_profile_picture_column_available = hasDatabaseColumn('users', 'profile_picture');
$message_time_display_offset_seconds = getMessageTimeDisplayOffsetSeconds($db);
updateUserPresenceHeartbeat($db, $student_id);

if ($runStudentSchemaMaintenance) {
// Ensure student messaging table exists.
try {
    $db->query("CREATE TABLE IF NOT EXISTS student_messages (
                message_id INT AUTO_INCREMENT PRIMARY KEY,
                sender_id INT NOT NULL,
                recipient_id INT NOT NULL,
                message_body TEXT NOT NULL,
                reply_to_message_id INT NULL DEFAULT NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at DATETIME NULL DEFAULT NULL,
                INDEX idx_recipient_read (recipient_id, read_at),
                INDEX idx_participants (sender_id, recipient_id),
                INDEX idx_sent_at (sent_at),
                INDEX idx_reply_to_message (reply_to_message_id),
                CONSTRAINT fk_student_messages_sender FOREIGN KEY (sender_id) REFERENCES users(users_id) ON DELETE CASCADE,
                CONSTRAINT fk_student_messages_recipient FOREIGN KEY (recipient_id) REFERENCES users(users_id) ON DELETE CASCADE,
                CONSTRAINT fk_student_messages_reply FOREIGN KEY (reply_to_message_id) REFERENCES student_messages(message_id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->execute();
} catch (Exception $e) {
    error_log("Error ensuring student_messages table with foreign keys: " . $e->getMessage());

    // Fallback for legacy environments where FK constraints cannot be created.
    try {
        $db->query("CREATE TABLE IF NOT EXISTS student_messages (
                    message_id INT AUTO_INCREMENT PRIMARY KEY,
                    sender_id INT NOT NULL,
                    recipient_id INT NOT NULL,
                    message_body TEXT NOT NULL,
                    reply_to_message_id INT NULL DEFAULT NULL,
                    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    read_at DATETIME NULL DEFAULT NULL,
                    INDEX idx_recipient_read (recipient_id, read_at),
                    INDEX idx_participants (sender_id, recipient_id),
                    INDEX idx_sent_at (sent_at),
                    INDEX idx_reply_to_message (reply_to_message_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->execute();
    } catch (Exception $fallback_exception) {
        error_log("Error ensuring student_messages fallback table: " . $fallback_exception->getMessage());
    }
}

// Backward-compatible migration for reply support on student messages.
try {
    if (!hasDatabaseColumn('student_messages', 'reply_to_message_id')) {
        $db->query("ALTER TABLE student_messages ADD COLUMN reply_to_message_id INT NULL DEFAULT NULL AFTER message_body");
        $db->execute();

        $db->query("ALTER TABLE student_messages ADD INDEX idx_reply_to_message (reply_to_message_id)");
        $db->execute();
    }
} catch (Exception $e) {
    error_log("Error ensuring student_messages reply_to_message_id column: " . $e->getMessage());
}

ensureStudentMessageAttachmentColumns($db);

// Add missing indexes for frequently polled messaging queries.
try {
    $db->query("SHOW INDEX FROM student_messages WHERE Key_name = 'idx_recipient_read_sent'");
    if (!$db->single()) {
        $db->query("ALTER TABLE student_messages ADD INDEX idx_recipient_read_sent (recipient_id, read_at, sent_at)");
        $db->execute();
    }
} catch (Exception $e) {
    error_log("Error ensuring student_messages idx_recipient_read_sent index: " . $e->getMessage());
}

// Ensure student friendship table exists.
try {
    $db->query("CREATE TABLE IF NOT EXISTS student_friendships (
                friendship_id INT AUTO_INCREMENT PRIMARY KEY,
                user_one_id INT NOT NULL,
                user_two_id INT NOT NULL,
                requested_by INT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'accepted',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                accepted_at DATETIME NULL DEFAULT NULL,
                UNIQUE KEY uniq_friend_pair (user_one_id, user_two_id),
                INDEX idx_user_one (user_one_id),
                INDEX idx_user_two (user_two_id),
                CONSTRAINT fk_friend_user_one FOREIGN KEY (user_one_id) REFERENCES users(users_id) ON DELETE CASCADE,
                CONSTRAINT fk_friend_user_two FOREIGN KEY (user_two_id) REFERENCES users(users_id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->execute();

    // Backward-compatible migration for existing installs.
    $db->query("SHOW COLUMNS FROM student_friendships LIKE 'requested_by'");
    if (!$db->single()) {
        $db->query("ALTER TABLE student_friendships ADD COLUMN requested_by INT NULL AFTER user_two_id");
        $db->execute();
    }

    $db->query("SHOW COLUMNS FROM student_friendships LIKE 'status'");
    if (!$db->single()) {
        $db->query("ALTER TABLE student_friendships ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'accepted' AFTER requested_by");
        $db->execute();
    }

    $db->query("SHOW COLUMNS FROM student_friendships LIKE 'accepted_at'");
    if (!$db->single()) {
        $db->query("ALTER TABLE student_friendships ADD COLUMN accepted_at DATETIME NULL DEFAULT NULL AFTER created_at");
        $db->execute();
    }
} catch (Exception $e) {
    error_log("Error ensuring student_friendships table with foreign keys: " . $e->getMessage());

    // Fallback for legacy environments where FK constraints cannot be created.
    try {
        $db->query("CREATE TABLE IF NOT EXISTS student_friendships (
                    friendship_id INT AUTO_INCREMENT PRIMARY KEY,
                    user_one_id INT NOT NULL,
                    user_two_id INT NOT NULL,
                    requested_by INT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'accepted',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    accepted_at DATETIME NULL DEFAULT NULL,
                    UNIQUE KEY uniq_friend_pair (user_one_id, user_two_id),
                    INDEX idx_user_one (user_one_id),
                    INDEX idx_user_two (user_two_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->execute();
    } catch (Exception $fallback_exception) {
        error_log("Error ensuring student_friendships fallback table: " . $fallback_exception->getMessage());
    }
}

try {
    $db->query("SHOW INDEX FROM student_friendships WHERE Key_name = 'idx_status_requested'");
    if (!$db->single()) {
        $db->query("ALTER TABLE student_friendships ADD INDEX idx_status_requested (status, requested_by)");
        $db->execute();
    }
} catch (Exception $e) {
    error_log("Error ensuring student_friendships idx_status_requested index: " . $e->getMessage());
}

// Ensure message reactions table exists.
try {
    $db->query("CREATE TABLE IF NOT EXISTS student_message_reactions (
                reaction_id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                reactor_id INT NOT NULL,
                reaction_emoji VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_message_reactor (message_id, reactor_id),
                INDEX idx_message_reaction (message_id),
                INDEX idx_reactor (reactor_id),
                CONSTRAINT fk_message_reactions_message FOREIGN KEY (message_id) REFERENCES student_messages(message_id) ON DELETE CASCADE,
                CONSTRAINT fk_message_reactions_reactor FOREIGN KEY (reactor_id) REFERENCES users(users_id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->execute();
} catch (Exception $e) {
    error_log("Error ensuring student_message_reactions table with foreign keys: " . $e->getMessage());

    try {
        $db->query("CREATE TABLE IF NOT EXISTS student_message_reactions (
                    reaction_id INT AUTO_INCREMENT PRIMARY KEY,
                    message_id INT NOT NULL,
                    reactor_id INT NOT NULL,
                    reaction_emoji VARCHAR(16) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_message_reactor (message_id, reactor_id),
                    INDEX idx_message_reaction (message_id),
                    INDEX idx_reactor (reactor_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->execute();
    } catch (Exception $fallback_exception) {
        error_log("Error ensuring student_message_reactions fallback table: " . $fallback_exception->getMessage());
    }
}

// Ensure per-friend nicknames table exists.
try {
    $db->query("CREATE TABLE IF NOT EXISTS student_friend_nicknames (
                nickname_id INT AUTO_INCREMENT PRIMARY KEY,
                owner_id INT NOT NULL,
                friend_id INT NOT NULL,
                nickname VARCHAR(60) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_owner_friend (owner_id, friend_id),
                INDEX idx_owner (owner_id),
                INDEX idx_friend (friend_id),
                CONSTRAINT fk_friend_nickname_owner FOREIGN KEY (owner_id) REFERENCES users(users_id) ON DELETE CASCADE,
                CONSTRAINT fk_friend_nickname_friend FOREIGN KEY (friend_id) REFERENCES users(users_id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->execute();
} catch (Exception $e) {
    error_log("Error ensuring student_friend_nicknames table with foreign keys: " . $e->getMessage());

    try {
        $db->query("CREATE TABLE IF NOT EXISTS student_friend_nicknames (
                    nickname_id INT AUTO_INCREMENT PRIMARY KEY,
                    owner_id INT NOT NULL,
                    friend_id INT NOT NULL,
                    nickname VARCHAR(60) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_owner_friend (owner_id, friend_id),
                    INDEX idx_owner (owner_id),
                    INDEX idx_friend (friend_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->execute();
    } catch (Exception $fallback_exception) {
        error_log("Error ensuring student_friend_nicknames fallback table: " . $fallback_exception->getMessage());
    }
}
}

// Lightweight JSON endpoint for live message notifications.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'message_notifications') {
    header('Content-Type: application/json');

    $response = [
        'success' => true,
        'unread_count' => 0,
        'pending_request_count' => 0,
        'notification_count' => 0,
        'friend_presence' => [],
        'latest_sender' => null,
        'latest_message' => null,
        'latest_sent_at' => null,
        'latest_office_comment_at' => null,
        'latest_office_comment' => null,
        'latest_office_name' => null,
        'latest_org_comment_at' => null,
        'latest_org_comment' => null,
        'latest_org_name' => null
    ];

    try {
        $db->query("SELECT COUNT(*) AS unread_count
                    FROM student_messages sm
                    INNER JOIN student_friendships sf
                        ON sf.user_one_id = LEAST(sm.sender_id, sm.recipient_id)
                       AND sf.user_two_id = GREATEST(sm.sender_id, sm.recipient_id)
                    WHERE sm.recipient_id = :user_id
                      AND sm.read_at IS NULL
                      AND sf.status = 'accepted'");
        $db->bind(':user_id', $student_id);
        $row = $db->single();
        $response['unread_count'] = (int) ($row['unread_count'] ?? 0);

        $db->query("SELECT COUNT(*) AS pending_request_count
                    FROM student_friendships sf
                    WHERE sf.status = 'pending'
                      AND sf.requested_by != :viewer_id_req
                      AND (sf.user_one_id = :viewer_id_one OR sf.user_two_id = :viewer_id_two)");
        $db->bind(':viewer_id_req', $student_id);
        $db->bind(':viewer_id_one', $student_id);
        $db->bind(':viewer_id_two', $student_id);
        $pending_row = $db->single();
        $response['pending_request_count'] = (int) ($pending_row['pending_request_count'] ?? 0);
        $response['notification_count'] = $response['unread_count'] + $response['pending_request_count'];

        if ($response['unread_count'] > 0) {
            $db->query("SELECT sm.message_body,
                            sm.attachment_name,
                            sm.sent_at,
                            COALESCE(
                                NULLIF(TRIM(CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, ''))), ''),
                                u.emails,
                                CONCAT('Student #', u.users_id)
                            ) AS sender_name
                        FROM student_messages sm
                        INNER JOIN users u ON u.users_id = sm.sender_id
                        INNER JOIN user_role ur ON ur.user_role_id = u.user_role_id AND ur.user_role_name = 'student'
                        INNER JOIN student_friendships sf
                            ON sf.user_one_id = LEAST(sm.sender_id, sm.recipient_id)
                           AND sf.user_two_id = GREATEST(sm.sender_id, sm.recipient_id)
                        WHERE sm.recipient_id = :user_id
                          AND sm.read_at IS NULL
                          AND sf.status = 'accepted'
                        ORDER BY sm.sent_at DESC
                        LIMIT 1");
            $db->bind(':user_id', $student_id);
            $latest = $db->single();

            if ($latest) {
                $response['latest_sender'] = $latest['sender_name'] ?? null;
                $latest_message_preview = buildMessageSnippet($latest['message_body'] ?? '', 120, true);
                if ($latest_message_preview === '' && !empty($latest['attachment_name'])) {
                    $latest_message_preview = '[Attachment] ' . buildMessageSnippet($latest['attachment_name'], 80, false);
                }
                $response['latest_message'] = $latest_message_preview !== '' ? $latest_message_preview : null;
                $response['latest_sent_at'] = $latest['sent_at'] ?? null;
            }
        }
    } catch (Exception $e) {
        $response['success'] = false;
        $response['error'] = 'Unable to load notifications';
        error_log("Message notification endpoint error: " . $e->getMessage());
    }

    // Load non-messaging notification metadata without breaking message polling.
    if ($response['success']) {
        try {
            $db->query("SELECT
                            c.lacking_comment,
                            c.lacking_comment_at,
                            COALESCE(o.office_name, 'Office') AS office_name
                        FROM clearance c
                        LEFT JOIN offices o ON o.office_id = c.office_id
                        WHERE c.users_id = :comment_user_id
                          AND c.lacking_comment_at IS NOT NULL
                          AND c.lacking_comment IS NOT NULL
                          AND TRIM(c.lacking_comment) <> ''
                        ORDER BY c.lacking_comment_at DESC
                        LIMIT 1");
            $db->bind(':comment_user_id', $student_id);
            $latest_office_comment = $db->single();

            if ($latest_office_comment) {
                $response['latest_office_comment_at'] = $latest_office_comment['lacking_comment_at'] ?? null;
                $office_comment_preview = buildMessageSnippet($latest_office_comment['lacking_comment'] ?? '', 140, true);
                $response['latest_office_comment'] = $office_comment_preview !== '' ? $office_comment_preview : null;
                $response['latest_office_name'] = $latest_office_comment['office_name'] ?? 'Office';
            }
        } catch (Exception $e) {
            error_log("Office comment notification metadata error: " . $e->getMessage());
        }

        try {
            $org_commented_at_expression = "COALESCE(oc.processed_date, c.created_at)";

            if (hasDatabaseColumn('organization_clearance', 'updated_at')) {
                $org_commented_at_expression = "COALESCE(oc.updated_at, oc.processed_date, c.created_at)";
            }

            $db->query("SELECT
                            oc.remarks,
                            {$org_commented_at_expression} AS commented_at,
                            COALESCE(so.org_name, 'Organization') AS org_name
                        FROM organization_clearance oc
                        INNER JOIN clearance c ON c.clearance_id = oc.clearance_id
                        INNER JOIN student_organizations so ON so.org_id = oc.org_id
                        WHERE c.users_id = :org_comment_user_id
                          AND oc.remarks IS NOT NULL
                          AND TRIM(oc.remarks) <> ''
                          AND oc.remarks REGEXP '\\\\[[A-Z_]+_LACKING\\\\]'
                        ORDER BY commented_at DESC
                        LIMIT 1");
            $db->bind(':org_comment_user_id', $student_id);
            $latest_org_comment = $db->single();

            if ($latest_org_comment) {
                $org_comment_text = '';
                if (preg_match('/\[(?:[A-Z]+_LACKING|ORG_LACKING)\]\s*(.+?)(?=\s*\|\s*|$)/s', (string) ($latest_org_comment['remarks'] ?? ''), $org_matches)) {
                    $org_comment_text = trim((string) ($org_matches[1] ?? ''));
                }

                $response['latest_org_comment_at'] = $latest_org_comment['commented_at'] ?? null;
                $org_comment_preview = buildMessageSnippet($org_comment_text, 140, true);
                $response['latest_org_comment'] = $org_comment_preview !== '' ? $org_comment_preview : null;
                $response['latest_org_name'] = $latest_org_comment['org_name'] ?? 'Organization';
            }
        } catch (Exception $e) {
            error_log("Organization comment notification metadata error: " . $e->getMessage());
        }

        if ($user_presence_columns_available) {
            try {
                $db->query("SELECT u.users_id,
                                   COALESCE(u.is_online, 0) AS is_online_flag,
                                   u.last_seen_at,
                                   CASE
                                       WHEN u.last_seen_at IS NULL THEN NULL
                                       ELSE GREATEST(TIMESTAMPDIFF(SECOND, u.last_seen_at, NOW()), 0)
                                   END AS seconds_since_last_seen
                            FROM student_friendships sf
                            INNER JOIN users u ON u.users_id = CASE
                                WHEN sf.user_one_id = :viewer_id_case THEN sf.user_two_id
                                ELSE sf.user_one_id
                            END
                            INNER JOIN user_role ur ON ur.user_role_id = u.user_role_id
                            WHERE (sf.user_one_id = :viewer_id_one OR sf.user_two_id = :viewer_id_two)
                              AND sf.status = 'accepted'
                              AND ur.user_role_name = 'student'");
                $db->bind(':viewer_id_case', $student_id);
                $db->bind(':viewer_id_one', $student_id);
                $db->bind(':viewer_id_two', $student_id);
                $friend_presence_rows = $db->resultSet() ?: [];

                foreach ($friend_presence_rows as $presence_row) {
                    $friend_id = (int) ($presence_row['users_id'] ?? 0);
                    if ($friend_id <= 0) {
                        continue;
                    }

                    $presence_meta = buildFriendPresenceMeta(
                        $presence_row['is_online_flag'] ?? 0,
                        $presence_row['last_seen_at'] ?? null,
                        $presence_row['seconds_since_last_seen'] ?? null
                    );

                    $response['friend_presence'][(string) $friend_id] = [
                        'is_online' => $presence_meta['is_online'] ? 1 : 0,
                        'status_text' => $presence_meta['status_text'],
                        'status_class' => $presence_meta['status_class'],
                        'minutes_since_logout' => $presence_meta['minutes_since_logout']
                    ];
                }
            } catch (Exception $e) {
                error_log("Friend presence notification metadata error: " . $e->getMessage());
            }
        }
    }

    echo json_encode($response);
    exit();
}

// Handle student-to-student message sending.
if (isset($_POST['add_friend_by_ismis'])) {
    $friend_ismis_id = trim($_POST['friend_ismis_id'] ?? '');
    $active_tab = 'messages';

    if ($friend_ismis_id === '') {
        $error = "Please enter an ISMIS ID.";
    } else {
        try {
            $db->query("SELECT u.users_id, u.ismis_id
                        FROM users u
                        INNER JOIN user_role ur ON ur.user_role_id = u.user_role_id
                        WHERE ur.user_role_name = 'student'
                          AND u.ismis_id = :ismis_id
                        LIMIT 1");
            $db->bind(':ismis_id', $friend_ismis_id);
            $friend_user = $db->single();

            if (!$friend_user) {
                $error = "No student found with that ISMIS ID.";
            } elseif ((int) $friend_user['users_id'] === (int) $student_id) {
                $error = "You cannot add yourself as a friend.";
            } else {
                $user_one_id = min((int) $student_id, (int) $friend_user['users_id']);
                $user_two_id = max((int) $student_id, (int) $friend_user['users_id']);

                                $db->query("SELECT friendship_id, status, requested_by, accepted_at
                            FROM student_friendships
                            WHERE user_one_id = :user_one_id
                              AND user_two_id = :user_two_id
                            LIMIT 1");
                $db->bind(':user_one_id', $user_one_id);
                $db->bind(':user_two_id', $user_two_id);
                $existing_friendship = $db->single();

                if ($existing_friendship) {
                    $existing_status = strtolower((string) ($existing_friendship['status'] ?? 'accepted'));
                    $existing_requested_by = (int) ($existing_friendship['requested_by'] ?? 0);
                    $existing_accepted_at = $existing_friendship['accepted_at'] ?? null;

                    if ($existing_status === 'accepted') {
                        // Legacy rows from the old instant-add flow can be re-used as a pending request.
                        if ($existing_requested_by === 0 && empty($existing_accepted_at)) {
                            $db->query("UPDATE student_friendships
                                        SET status = 'pending',
                                            requested_by = :requested_by,
                                            created_at = NOW(),
                                            accepted_at = NULL
                                        WHERE friendship_id = :friendship_id");
                            $db->bind(':requested_by', $student_id);
                            $db->bind(':friendship_id', (int) $existing_friendship['friendship_id']);

                            if ($db->execute()) {
                                if (class_exists('ActivityLogModel')) {
                                    $logModel = new ActivityLogModel();
                                    $logModel->log($student_id, 'SEND_FRIEND_REQUEST', "Sent friend request via ISMIS: {$friend_ismis_id}");
                                }

                                $_SESSION['success_message'] = "Friend request sent successfully.";
                                header("Location: dashboard.php?tab=messages");
                                exit();
                            }

                            $error = "Failed to send friend request. Please try again.";
                        } else {
                            $error = "This student is already in your friend list.";
                        }
                    } elseif ($existing_status === 'pending' && $existing_requested_by === (int) $student_id) {
                        $error = "Friend request already sent. Please wait for approval.";
                    } elseif ($existing_status === 'pending' && $existing_requested_by !== (int) $student_id) {
                        $error = "This student already sent you a friend request. Accept it below.";
                    } else {
                        $db->query("UPDATE student_friendships
                                    SET status = 'pending',
                                        requested_by = :requested_by,
                                        created_at = NOW(),
                                        accepted_at = NULL
                                    WHERE friendship_id = :friendship_id");
                        $db->bind(':requested_by', $student_id);
                        $db->bind(':friendship_id', (int) $existing_friendship['friendship_id']);

                        if ($db->execute()) {
                            if (class_exists('ActivityLogModel')) {
                                $logModel = new ActivityLogModel();
                                $logModel->log($student_id, 'SEND_FRIEND_REQUEST', "Re-sent friend request via ISMIS: {$friend_ismis_id}");
                            }

                            $_SESSION['success_message'] = "Friend request sent successfully.";
                            header("Location: dashboard.php?tab=messages");
                            exit();
                        }

                        $error = "Failed to send friend request. Please try again.";
                    }
                } else {
                    $db->query("INSERT INTO student_friendships (user_one_id, user_two_id, requested_by, status, created_at, accepted_at)
                                VALUES (:user_one_id, :user_two_id, :requested_by, 'pending', NOW(), NULL)");
                    $db->bind(':user_one_id', $user_one_id);
                    $db->bind(':user_two_id', $user_two_id);
                    $db->bind(':requested_by', $student_id);

                    if ($db->execute()) {
                        if (class_exists('ActivityLogModel')) {
                            $logModel = new ActivityLogModel();
                            $logModel->log($student_id, 'SEND_FRIEND_REQUEST', "Sent friend request via ISMIS: {$friend_ismis_id}");
                        }

                        $_SESSION['success_message'] = "Friend request sent successfully.";
                        header("Location: dashboard.php?tab=messages");
                        exit();
                    }

                    $error = "Failed to send friend request. Please try again.";
                }
            }
        } catch (Exception $e) {
            error_log("Add friend by ISMIS error: " . $e->getMessage());
            $error = "Unable to add friend right now.";
        }
    }
}

if (isset($_POST['accept_friend_request'])) {
    $friendship_id = (int) ($_POST['friendship_id'] ?? 0);
    $active_tab = 'messages';

    if ($friendship_id <= 0) {
        $error = "Invalid friend request.";
    } else {
        try {
            $db->query("SELECT friendship_id, user_one_id, user_two_id, requested_by, status
                        FROM student_friendships
                        WHERE friendship_id = :friendship_id
                        LIMIT 1");
            $db->bind(':friendship_id', $friendship_id);
            $request_row = $db->single();

            if (!$request_row) {
                $error = "Friend request not found.";
            } else {
                $requested_by = (int) ($request_row['requested_by'] ?? 0);
                $status = strtolower((string) ($request_row['status'] ?? ''));
                $user_one_id = (int) ($request_row['user_one_id'] ?? 0);
                $user_two_id = (int) ($request_row['user_two_id'] ?? 0);
                $is_participant = ($user_one_id === (int) $student_id || $user_two_id === (int) $student_id);

                if (!$is_participant || $requested_by === (int) $student_id) {
                    $error = "You cannot accept this friend request.";
                } elseif ($status !== 'pending') {
                    $error = "This friend request is no longer pending.";
                } else {
                    $db->query("UPDATE student_friendships
                                SET status = 'accepted',
                                    accepted_at = NOW()
                                WHERE friendship_id = :friendship_id
                                  AND status = 'pending'");
                    $db->bind(':friendship_id', $friendship_id);

                    if ($db->execute()) {
                        if (class_exists('ActivityLogModel')) {
                            $logModel = new ActivityLogModel();
                            $logModel->log($student_id, 'ACCEPT_FRIEND_REQUEST', "Accepted friend request #{$friendship_id}");
                        }

                        $_SESSION['success_message'] = "Friend request accepted. You can now message each other.";
                        header("Location: dashboard.php?tab=messages");
                        exit();
                    }

                    $error = "Failed to accept friend request.";
                }
            }
        } catch (Exception $e) {
            error_log("Accept friend request error: " . $e->getMessage());
            $error = "Unable to accept request right now.";
        }
    }
}

if (isset($_POST['save_friend_nickname'])) {
    $friend_id = (int) ($_POST['friend_id'] ?? 0);
    $friend_nickname = trim((string) ($_POST['friend_nickname'] ?? ''));
    $active_tab = 'messages';

    if ($friend_id <= 0 || $friend_id === (int) $student_id) {
        $error = "Invalid friend selected for nickname update.";
    } elseif ((function_exists('mb_strlen') ? mb_strlen($friend_nickname, 'UTF-8') : strlen($friend_nickname)) > 40) {
        $error = "Nickname must be 40 characters or less.";
    } else {
        try {
            $user_one_id = min((int) $student_id, $friend_id);
            $user_two_id = max((int) $student_id, $friend_id);

            $db->query("SELECT friendship_id
                        FROM student_friendships
                        WHERE user_one_id = :user_one_id
                          AND user_two_id = :user_two_id
                          AND status = 'accepted'
                        LIMIT 1");
            $db->bind(':user_one_id', $user_one_id);
            $db->bind(':user_two_id', $user_two_id);
            $friendship = $db->single();

            if (!$friendship) {
                $error = "You can only set a nickname for accepted friends.";
            } else {
                if ($friend_nickname === '') {
                    $db->query("DELETE FROM student_friend_nicknames
                                WHERE owner_id = :owner_id
                                  AND friend_id = :friend_id");
                    $db->bind(':owner_id', $student_id);
                    $db->bind(':friend_id', $friend_id);
                    if ($db->execute()) {
                        $_SESSION['success_message'] = "Nickname cleared.";
                    } else {
                        $error = "Unable to clear nickname right now.";
                    }
                } else {
                    $db->query("INSERT INTO student_friend_nicknames (owner_id, friend_id, nickname, created_at, updated_at)
                                VALUES (:owner_id, :friend_id, :nickname, NOW(), NOW())
                                ON DUPLICATE KEY UPDATE nickname = VALUES(nickname), updated_at = NOW()");
                    $db->bind(':owner_id', $student_id);
                    $db->bind(':friend_id', $friend_id);
                    $db->bind(':nickname', $friend_nickname);

                    if ($db->execute()) {
                        $_SESSION['success_message'] = "Nickname saved.";
                    } else {
                        $error = "Unable to save nickname right now.";
                    }
                }

                if ($error === '') {
                    header("Location: dashboard.php?tab=messages&chat_with=" . $friend_id);
                    exit();
                }
            }
        } catch (Exception $e) {
            error_log("Save friend nickname error: " . $e->getMessage());
            $error = "Unable to save nickname right now.";
        }
    }
}

if (isset($_POST['react_to_message'])) {
    $message_id = (int) ($_POST['message_id'] ?? 0);
    $chat_with_id = (int) ($_POST['recipient_id'] ?? 0);
    $reaction_emoji = trim((string) ($_POST['reaction_emoji'] ?? ''));
    $active_tab = 'messages';

    if ($message_id <= 0 || $chat_with_id <= 0) {
        $error = "Invalid message reaction request.";
    } elseif (!in_array($reaction_emoji, $allowed_message_reactions, true)) {
        $error = "Unsupported emoji reaction.";
    } else {
        try {
            $db->query("SELECT sm.message_id
                        FROM student_messages sm
                        INNER JOIN student_friendships sf
                            ON sf.user_one_id = LEAST(sm.sender_id, sm.recipient_id)
                           AND sf.user_two_id = GREATEST(sm.sender_id, sm.recipient_id)
                           AND sf.status = 'accepted'
                        WHERE sm.message_id = :message_id
                          AND ((sm.sender_id = :viewer_id AND sm.recipient_id = :peer_id)
                            OR (sm.sender_id = :peer_id_alt AND sm.recipient_id = :viewer_id_alt))
                        LIMIT 1");
            $db->bind(':message_id', $message_id);
            $db->bind(':viewer_id', $student_id);
            $db->bind(':peer_id', $chat_with_id);
            $db->bind(':peer_id_alt', $chat_with_id);
            $db->bind(':viewer_id_alt', $student_id);
            $message_exists = $db->single();

            if (!$message_exists) {
                $error = "Message not found for this conversation.";
            } else {
                $db->query("SELECT reaction_emoji
                            FROM student_message_reactions
                            WHERE message_id = :message_id
                              AND reactor_id = :reactor_id
                            LIMIT 1");
                $db->bind(':message_id', $message_id);
                $db->bind(':reactor_id', $student_id);
                $existing_reaction = $db->single();

                if ($existing_reaction && (string) ($existing_reaction['reaction_emoji'] ?? '') === $reaction_emoji) {
                    $db->query("DELETE FROM student_message_reactions
                                WHERE message_id = :message_id
                                  AND reactor_id = :reactor_id");
                    $db->bind(':message_id', $message_id);
                    $db->bind(':reactor_id', $student_id);
                    $db->execute();
                } else {
                    $db->query("INSERT INTO student_message_reactions (message_id, reactor_id, reaction_emoji, created_at, updated_at)
                                VALUES (:message_id, :reactor_id, :reaction_emoji, NOW(), NOW())
                                ON DUPLICATE KEY UPDATE reaction_emoji = VALUES(reaction_emoji), updated_at = NOW()");
                    $db->bind(':message_id', $message_id);
                    $db->bind(':reactor_id', $student_id);
                    $db->bind(':reaction_emoji', $reaction_emoji);
                    $db->execute();
                }

                header("Location: dashboard.php?tab=messages&chat_with=" . $chat_with_id);
                exit();
            }
        } catch (Exception $e) {
            error_log("Message reaction error: " . $e->getMessage());
            $error = "Unable to react to this message right now.";
        }
    }
}

if (isset($_POST['delete_conversation'])) {
    $friend_id = (int) ($_POST['friend_id'] ?? 0);
    $active_tab = 'messages';

    if ($friend_id <= 0 || $friend_id === (int) $student_id) {
        $error = "Invalid conversation selected for deletion.";
    } else {
        try {
            $user_one_id = min((int) $student_id, $friend_id);
            $user_two_id = max((int) $student_id, $friend_id);

            $db->query("SELECT sf.friendship_id
                        FROM student_friendships sf
                        INNER JOIN users u ON u.users_id = :friend_user_id
                        INNER JOIN user_role ur ON ur.user_role_id = u.user_role_id
                        WHERE sf.user_one_id = :user_one_id
                          AND sf.user_two_id = :user_two_id
                          AND sf.status = 'accepted'
                          AND ur.user_role_name = 'student'
                        LIMIT 1");
            $db->bind(':friend_user_id', $friend_id);
            $db->bind(':user_one_id', $user_one_id);
            $db->bind(':user_two_id', $user_two_id);
            $friendship_row = $db->single();

            if (!$friendship_row) {
                $error = "Conversation partner was not found.";
            } else {
                $db->beginTransaction();

                // Keep reply chains valid in fallback schemas without FK constraints.
                $db->query("UPDATE student_messages
                            SET reply_to_message_id = NULL
                            WHERE reply_to_message_id IN (
                                SELECT conversation_message_id
                                FROM (
                                    SELECT message_id AS conversation_message_id
                                    FROM student_messages
                                    WHERE (sender_id = :viewer_reply_one AND recipient_id = :friend_reply_one)
                                       OR (sender_id = :friend_reply_two AND recipient_id = :viewer_reply_two)
                                ) AS conversation_message_ids
                            )");
                $db->bind(':viewer_reply_one', $student_id);
                $db->bind(':friend_reply_one', $friend_id);
                $db->bind(':friend_reply_two', $friend_id);
                $db->bind(':viewer_reply_two', $student_id);
                $db->execute();

                // Clear reactions first so legacy schemas without FK constraints stay clean.
                try {
                    $db->query("DELETE smr
                                FROM student_message_reactions smr
                                INNER JOIN student_messages sm ON sm.message_id = smr.message_id
                                WHERE (sm.sender_id = :viewer_react_one AND sm.recipient_id = :friend_react_one)
                                   OR (sm.sender_id = :friend_react_two AND sm.recipient_id = :viewer_react_two)");
                    $db->bind(':viewer_react_one', $student_id);
                    $db->bind(':friend_react_one', $friend_id);
                    $db->bind(':friend_react_two', $friend_id);
                    $db->bind(':viewer_react_two', $student_id);
                    $db->execute();
                } catch (Exception $reaction_cleanup_error) {
                    error_log("Conversation reaction cleanup warning: " . $reaction_cleanup_error->getMessage());
                }

                $db->query("DELETE FROM student_messages
                            WHERE (sender_id = :viewer_delete_one AND recipient_id = :friend_delete_one)
                               OR (sender_id = :friend_delete_two AND recipient_id = :viewer_delete_two)");
                $db->bind(':viewer_delete_one', $student_id);
                $db->bind(':friend_delete_one', $friend_id);
                $db->bind(':friend_delete_two', $friend_id);
                $db->bind(':viewer_delete_two', $student_id);
                $db->execute();
                $deleted_message_count = (int) $db->rowCount();

                $db->commit();

                $_SESSION['success_message'] = $deleted_message_count > 0
                    ? "Conversation deleted successfully."
                    : "Conversation is already empty.";
                header("Location: dashboard.php?tab=messages");
                exit();
            }
        } catch (Exception $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->rollback();
            }
            error_log("Delete conversation error: " . $e->getMessage());
            $error = "Unable to delete this conversation right now.";
        }
    }
}

if (isset($_POST['delete_message'])) {
    $message_id = (int) ($_POST['message_id'] ?? 0);
    $chat_with_id = (int) ($_POST['recipient_id'] ?? 0);
    $active_tab = 'messages';

    if ($message_id <= 0 || $chat_with_id <= 0) {
        $error = "Invalid delete message request.";
    } else {
        try {
            $db->query("SELECT sm.message_id, sm.sender_id
                        FROM student_messages sm
                        INNER JOIN student_friendships sf
                            ON sf.user_one_id = LEAST(sm.sender_id, sm.recipient_id)
                           AND sf.user_two_id = GREATEST(sm.sender_id, sm.recipient_id)
                           AND sf.status = 'accepted'
                        WHERE sm.message_id = :message_id
                          AND ((sm.sender_id = :viewer_id AND sm.recipient_id = :peer_id)
                            OR (sm.sender_id = :peer_id_alt AND sm.recipient_id = :viewer_id_alt))
                        LIMIT 1");
            $db->bind(':message_id', $message_id);
            $db->bind(':viewer_id', $student_id);
            $db->bind(':peer_id', $chat_with_id);
            $db->bind(':peer_id_alt', $chat_with_id);
            $db->bind(':viewer_id_alt', $student_id);
            $message_row = $db->single();

            if (!$message_row) {
                $error = "Message not found for this conversation.";
            } elseif ((int) ($message_row['sender_id'] ?? 0) !== (int) $student_id) {
                $error = "You can only delete messages you sent.";
            } else {
                $db->beginTransaction();

                // Keep reply chains valid in fallback schemas without FK constraints.
                $db->query("UPDATE student_messages
                            SET reply_to_message_id = NULL
                            WHERE reply_to_message_id = :message_id");
                $db->bind(':message_id', $message_id);
                $db->execute();

                $db->query("DELETE FROM student_message_reactions
                            WHERE message_id = :message_id");
                $db->bind(':message_id', $message_id);
                $db->execute();

                $db->query("DELETE FROM student_messages
                            WHERE message_id = :message_id
                              AND sender_id = :sender_id
                            LIMIT 1");
                $db->bind(':message_id', $message_id);
                $db->bind(':sender_id', $student_id);
                $db->execute();

                $db->commit();

                $_SESSION['success_message'] = "Message deleted.";
                header("Location: dashboard.php?tab=messages&chat_with=" . $chat_with_id);
                exit();
            }
        } catch (Exception $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->rollback();
            }
            error_log("Delete message error: " . $e->getMessage());
            $error = "Unable to delete this message right now.";
        }
    }
}

if (isset($_POST['send_message'])) {
    $recipient_id = (int) ($_POST['recipient_id'] ?? 0);
    $message_body = trim($_POST['message_body'] ?? '');
    $attachment_upload = $_FILES['message_attachment'] ?? null;
    $has_attachment_upload = is_array($attachment_upload)
        && (int) ($attachment_upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $message_attachment_file = null;
    $message_attachment_name = null;
    $message_attachment_type = null;
    $message_attachment_size = null;
    $reply_to_message_id = (int) ($_POST['reply_to_message_id'] ?? 0);
    if ($reply_to_message_id <= 0) {
        $reply_to_message_id = null;
    }
    $message_length = function_exists('mb_strlen') ? mb_strlen($message_body, 'UTF-8') : strlen($message_body);
    $active_tab = 'messages';

    if ($recipient_id <= 0 || $recipient_id === (int) $student_id) {
        $error = "Please select a valid student recipient.";
    } elseif ($message_body === '' && !$has_attachment_upload) {
        $error = "Message cannot be empty unless you attach a file.";
    } elseif ($message_length > 1000) {
        $error = "Message must be 1000 characters or less.";
    } else {
        try {
                        $db->query("SELECT u.users_id
                        FROM users u
                        INNER JOIN user_role ur ON ur.user_role_id = u.user_role_id
                        WHERE u.users_id = :recipient_id
                          AND ur.user_role_name = 'student'
                        LIMIT 1");
            $db->bind(':recipient_id', $recipient_id);
            $recipient = $db->single();

            if (!$recipient) {
                $error = "Recipient must be an active student account.";
            } else {
                $user_one_id = min((int) $student_id, (int) $recipient_id);
                $user_two_id = max((int) $student_id, (int) $recipient_id);

                                $db->query("SELECT friendship_id
                            FROM student_friendships
                            WHERE user_one_id = :user_one_id
                              AND user_two_id = :user_two_id
                                                            AND status = 'accepted'
                            LIMIT 1");
                $db->bind(':user_one_id', $user_one_id);
                $db->bind(':user_two_id', $user_two_id);
                $friendship = $db->single();

                if (!$friendship) {
                    $error = "You can only message students in your friend list. Add them by ISMIS ID first.";
                } else {
                    if ($reply_to_message_id !== null) {
                        $db->query("SELECT message_id
                                    FROM student_messages
                                    WHERE message_id = :reply_to_message_id
                                      AND ((sender_id = :viewer_id AND recipient_id = :peer_id)
                                        OR (sender_id = :peer_id_alt AND recipient_id = :viewer_id_alt))
                                    LIMIT 1");
                        $db->bind(':reply_to_message_id', $reply_to_message_id);
                        $db->bind(':viewer_id', $student_id);
                        $db->bind(':peer_id', $recipient_id);
                        $db->bind(':peer_id_alt', $recipient_id);
                        $db->bind(':viewer_id_alt', $student_id);
                        $reply_message = $db->single();

                        if (!$reply_message) {
                            $error = "The message you selected to reply to is no longer available.";
                            $reply_to_message_id = null;
                        }
                    }

                    if ($error === '') {
                        if ($has_attachment_upload) {
                            $upload_error = (int) ($attachment_upload['error'] ?? UPLOAD_ERR_NO_FILE);

                            if ($upload_error !== UPLOAD_ERR_OK) {
                                $error = "Attachment upload failed. Please try again.";
                            } else {
                                $max_attachment_size = 15 * 1024 * 1024; // 15 MB
                                $attachment_size_bytes = (int) ($attachment_upload['size'] ?? 0);

                                if ($attachment_size_bytes <= 0 || $attachment_size_bytes > $max_attachment_size) {
                                    $error = "Attachment must be between 1 byte and 15 MB.";
                                } else {
                                    $original_attachment_name = trim((string) ($attachment_upload['name'] ?? ''));
                                    $attachment_extension = strtolower((string) pathinfo($original_attachment_name, PATHINFO_EXTENSION));
                                    $allowed_attachment_extensions = [
                                        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp',
                                        'mp3', 'wav', 'ogg', 'm4a', 'aac',
                                        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
                                        'txt', 'csv', 'zip', 'rar', '7z'
                                    ];

                                    if ($attachment_extension === '' || !in_array($attachment_extension, $allowed_attachment_extensions, true)) {
                                        $error = "Unsupported attachment type.";
                                    } else {
                                        $message_upload_dir = __DIR__ . '/../uploads/messages/student/';
                                        if (!file_exists($message_upload_dir) && !mkdir($message_upload_dir, 0777, true)) {
                                            $error = "Unable to prepare attachment upload folder.";
                                        } else {
                                            $attachment_filename = 'msg_' . (int) $student_id . '_' . (int) $recipient_id . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $attachment_extension;
                                            $attachment_target_path = $message_upload_dir . $attachment_filename;

                                            if (!move_uploaded_file((string) ($attachment_upload['tmp_name'] ?? ''), $attachment_target_path)) {
                                                $error = "Unable to upload attachment right now.";
                                            } else {
                                                $attachment_mime_type = strtolower((string) ($attachment_upload['type'] ?? ''));
                                                if ($attachment_mime_type === '' && function_exists('finfo_open')) {
                                                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                                                    if ($finfo) {
                                                        $detected_mime = finfo_file($finfo, $attachment_target_path);
                                                        finfo_close($finfo);
                                                        $attachment_mime_type = strtolower((string) ($detected_mime ?? ''));
                                                    }
                                                }

                                                $image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
                                                $audio_extensions = ['mp3', 'wav', 'ogg', 'm4a', 'aac'];

                                                $message_attachment_type = 'file';
                                                if (strpos($attachment_mime_type, 'image/') === 0 || in_array($attachment_extension, $image_extensions, true)) {
                                                    $message_attachment_type = 'image';
                                                } elseif (strpos($attachment_mime_type, 'audio/') === 0 || in_array($attachment_extension, $audio_extensions, true)) {
                                                    $message_attachment_type = 'audio';
                                                }

                                                $message_attachment_file = 'uploads/messages/student/' . $attachment_filename;
                                                $message_attachment_name = $original_attachment_name !== '' ? $original_attachment_name : ('attachment.' . $attachment_extension);
                                                $message_attachment_size = $attachment_size_bytes;
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        if ($message_attachment_name !== null) {
                            $attachment_name_length = function_exists('mb_strlen')
                                ? mb_strlen($message_attachment_name, 'UTF-8')
                                : strlen($message_attachment_name);
                            if ($attachment_name_length > 250) {
                                $message_attachment_name = function_exists('mb_substr')
                                    ? mb_substr($message_attachment_name, 0, 250, 'UTF-8')
                                    : substr($message_attachment_name, 0, 250);
                            }
                        }
                    }

                    if ($error === '') {
                        $db->query("INSERT INTO student_messages (
                                        sender_id,
                                        recipient_id,
                                        message_body,
                                        attachment_file,
                                        attachment_name,
                                        attachment_type,
                                        attachment_size,
                                        reply_to_message_id,
                                        sent_at
                                    )
                                    VALUES (:sender_id, :recipient_id, :message_body, :attachment_file, :attachment_name, :attachment_type, :attachment_size, :reply_to_message_id, NOW())");
                        $db->bind(':sender_id', $student_id);
                        $db->bind(':recipient_id', $recipient_id);
                        $db->bind(':message_body', $message_body);
                        $db->bind(':attachment_file', $message_attachment_file);
                        $db->bind(':attachment_name', $message_attachment_name);
                        $db->bind(':attachment_type', $message_attachment_type);
                        $db->bind(':attachment_size', $message_attachment_size);
                        $db->bind(':reply_to_message_id', $reply_to_message_id);

                        if ($db->execute()) {
                            if (class_exists('ActivityLogModel')) {
                                $logModel = new ActivityLogModel();
                                $logModel->log($student_id, 'SEND_MESSAGE', "Sent message to student ID: {$recipient_id}");
                            }

                            $_SESSION['success_message'] = "Message sent successfully.";
                            header("Location: dashboard.php?tab=messages&chat_with=" . (int) $recipient_id);
                            exit();
                        }

                        $error = "Failed to send message. Please try again.";
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Student message send error: " . $e->getMessage());
            $error = "Unable to send message right now.";
        }
    }
}

// ============================================
// HANDLE PROOF UPLOAD
// ============================================
if (isset($_POST['upload_proof'])) {
    $clearance_id = (int) ($_POST['clearance_id'] ?? 0);
    $office_name = trim($_POST['office_name'] ?? '');
    $org_clearance_id = (int) ($_POST['org_clearance_id'] ?? 0);
    $upload_target_type = trim($_POST['upload_target_type'] ?? 'office');
    $upload_target_name = trim($_POST['upload_target_name'] ?? $office_name);
    $remarks = trim($_POST['proof_remarks'] ?? '');

    if ($clearance_id > 0 && isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['proof_file'];
        $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
        $max_size = 5 * 1024 * 1024; // 5MB

        if (!in_array($file['type'], $allowed_types)) {
            $error = "Only PDF, JPG, and PNG files are allowed.";
        } elseif ($file['size'] > $max_size) {
            $error = "File size must be less than 5MB.";
        } else {
            try {
                // Create upload directory if it doesn't exist
                $upload_dir = __DIR__ . '/../uploads/proofs/student/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                // Generate unique filename
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'proof_student_' . $student_id . '_' . $clearance_id . '_' . time() . '.' . $extension;
                $filepath = 'uploads/proofs/student/' . $filename;

                if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                    $db = Database::getInstance();
                    ensureOrganizationProofColumns($db);
                    $is_org_upload = $upload_target_type === 'organization' && $org_clearance_id > 0;

                    if ($is_org_upload) {
                        $db->query("SELECT oc.org_clearance_id, oc.clearance_id, so.org_name
                                    FROM organization_clearance oc
                                    JOIN clearance c ON oc.clearance_id = c.clearance_id
                                    JOIN student_organizations so ON oc.org_id = so.org_id
                                    WHERE oc.org_clearance_id = :org_clearance_id
                                    AND oc.clearance_id = :clearance_id
                                    AND c.users_id = :student_id");
                        $db->bind(':org_clearance_id', $org_clearance_id);
                        $db->bind(':clearance_id', $clearance_id);
                        $db->bind(':student_id', $student_id);
                        $org_upload_target = $db->single();

                        if (!$org_upload_target) {
                            throw new Exception("Organization clearance not found");
                        }

                        if ($upload_target_name === '') {
                            $upload_target_name = $org_upload_target['org_name'] ?? 'Organization';
                        }
                    } else {
                        // First, get the office_id from office_name
                        $db->query("SELECT office_id FROM offices WHERE office_name = :office_name");
                        $db->bind(':office_name', $office_name);
                        $office_result = $db->single();

                        if (!$office_result) {
                            throw new Exception("Office not found");
                        }

                        $office_id = $office_result['office_id'];
                    }

                    // Check if the columns exist
                    $column_exists = hasDatabaseColumn('clearance', 'student_proof_file');

                    if ($column_exists) {
                        $proof_remarks_value = $remarks;
                        if ($is_org_upload && $upload_target_name !== '') {
                            $proof_remarks_value = trim(($remarks !== '' ? $remarks . ' | ' : '') . '[ORG_PROOF] Submitted for ' . $upload_target_name);
                        }

                        if ($is_org_upload) {
                            $db->query("UPDATE organization_clearance oc
                                        JOIN clearance c ON oc.clearance_id = c.clearance_id
                                        SET oc.student_proof_file = :proof_file,
                                            oc.student_proof_remarks = :remarks,
                                            oc.student_proof_uploaded_at = NOW(),
                                            oc.updated_at = NOW(),
                                            c.updated_at = NOW()
                                        WHERE oc.org_clearance_id = :org_clearance_id
                                        AND oc.clearance_id = :id
                                        AND c.users_id = :student_id");
                            $db->bind(':proof_file', $filepath);
                            $db->bind(':remarks', $proof_remarks_value);
                            $db->bind(':org_clearance_id', $org_clearance_id);
                            $db->bind(':id', $clearance_id);
                            $db->bind(':student_id', $student_id);
                        } else {
                            $db->query("UPDATE clearance SET
                                        student_proof_file = :proof_file,
                                        student_proof_remarks = :remarks,
                                        student_proof_uploaded_at = NOW(),
                                        updated_at = NOW()
                                        WHERE clearance_id = :id
                                        AND users_id = :student_id
                                        AND office_id = :office_id");
                            $db->bind(':proof_file', $filepath);
                            $db->bind(':remarks', $remarks);
                            $db->bind(':id', $clearance_id);
                            $db->bind(':student_id', $student_id);
                            $db->bind(':office_id', $office_id);
                        }
                    } else {
                        $proof_remarks_value = $is_org_upload && $upload_target_name !== ''
                            ? trim(($remarks !== '' ? $remarks . ' | ' : '') . '[ORG_PROOF] Submitted for ' . $upload_target_name)
                            : $remarks;

                        if ($is_org_upload) {
                            $db->query("UPDATE organization_clearance oc
                                        JOIN clearance c ON oc.clearance_id = c.clearance_id
                                        SET oc.remarks = CONCAT(IFNULL(oc.remarks, ''), ' | STUDENT PROOF UPLOADED: ', :remarks, ' - File: ', :proof_file),
                                            oc.updated_at = NOW(),
                                            c.updated_at = NOW()
                                        WHERE oc.org_clearance_id = :org_clearance_id
                                        AND oc.clearance_id = :id
                                        AND c.users_id = :student_id");
                            $db->bind(':proof_file', $filename);
                            $db->bind(':remarks', $proof_remarks_value);
                            $db->bind(':org_clearance_id', $org_clearance_id);
                            $db->bind(':id', $clearance_id);
                            $db->bind(':student_id', $student_id);
                        } else {
                            $db->query("UPDATE clearance SET
                                        remarks = CONCAT(IFNULL(remarks, ''), ' | STUDENT PROOF UPLOADED: ', :remarks, ' - File: ', :proof_file),
                                        updated_at = NOW()
                                        WHERE clearance_id = :id
                                        AND users_id = :student_id
                                        AND office_id = :office_id");
                            $db->bind(':proof_file', $filename);
                            $db->bind(':remarks', $remarks);
                            $db->bind(':id', $clearance_id);
                            $db->bind(':student_id', $student_id);
                            $db->bind(':office_id', $office_id);
                        }
                    }

                    if ($db->execute()) {
                        // Log the activity
                        if (class_exists('ActivityLogModel')) {
                            $logModel = new ActivityLogModel();
                            $logModel->log($student_id, 'UPLOAD_PROOF', "Uploaded proof for clearance ID: $clearance_id to " . ($upload_target_name !== '' ? $upload_target_name : $office_name));
                        }

                        $_SESSION['success_message'] = "Proof uploaded successfully! The office will review your submission.";
                        header("Location: dashboard.php?tab=status");
                        exit();
                    } else {
                        $error = "Failed to update clearance with proof info.";
                    }
                } else {
                    $error = "Failed to upload file.";
                }
            } catch (Exception $e) {
                error_log("Error uploading proof: " . $e->getMessage());
                $error = "Database error occurred: " . $e->getMessage();
            }
        }
    } else {
        $error = "Please select a file to upload.";
    }
}

// Handle clearance application
if (isset($_POST['apply_clearance'])) {
    $clearance_type = trim((string) ($_POST['clearance_type'] ?? ''));
    $normalized_clearance_type = strtolower((string) preg_replace('/[^a-z0-9]+/', '', $clearance_type));
    $is_non_graduating_clearance = strpos($normalized_clearance_type, 'nongraduating') !== false;
    $is_graduating_only_clearance = strpos($normalized_clearance_type, 'graduating') !== false && !$is_non_graduating_clearance;
    // Enforce fixed semester and year server-side.
    $semester = '2nd Semester';
    $apply_year = (int) date('Y');
    $school_year_selected = ($apply_year - 1) . '-' . $apply_year;

    if ($clearance_type === '') {
        $error = "Please select a clearance type.";
    } elseif ($is_graduating_only_clearance) {
        $error = "Graduating Clearance is coming soon. Please select Non-Graduating Clearance for now.";
    } elseif (!$is_non_graduating_clearance) {
        $error = "Only Non-Graduating Clearance is available right now.";
    } else {
        try {
            // Check if student already has pending clearance
            $db->query("SELECT COUNT(*) as count FROM clearance 
                        WHERE users_id = :user_id AND status = 'pending'");
            $db->bind(':user_id', $student_id);
            $result = $db->single();

            if ($result && $result['count'] > 0) {
                $error = "You already have a pending clearance application. Please wait for it to be completed before applying for a new one.";
            } else {
                // Get clearance type ID
                $db->query("SELECT clearance_type_id FROM clearance_type WHERE clearance_name = :type");
                $db->bind(':type', $clearance_type);
                $type_result = $db->single();

                if ($type_result) {
                    $clearance_type_id = $type_result['clearance_type_id'];

                    // Define all 5 offices in correct order from your database
                    $offices = [
                        ['name' => 'Librarian', 'order' => 1],
                        ['name' => 'Director_SAS', 'order' => 2],
                        ['name' => 'Dean', 'order' => 3],
                        ['name' => 'Cashier', 'order' => 4],
                        ['name' => 'Registrar', 'order' => 5]
                    ];

                    // Begin transaction
                    $db->beginTransaction();

                    $success_count = 0;
                    $director_clearance_id = null;
                    foreach ($offices as $office) {
                        // Get office ID
                        $db->query("SELECT office_id FROM offices WHERE office_name = :name");
                        $db->bind(':name', $office['name']);
                        $office_result = $db->single();

                        if ($office_result) {
                            $clearance_name = "Clearance for " . $student_name . " - " . date('Y-m-d');

                            $sql = "INSERT INTO clearance (
                                clearance_name, users_id, clearance_type_id, office_id, 
                                semester, school_year, office_order, status, created_at
                            ) VALUES (
                                :clearance_name, :user_id, :type_id, :office_id, 
                                :semester, :school_year, :office_order, 'pending', NOW()
                            )";

                            $db->query($sql);
                            $db->bind(':clearance_name', $clearance_name);
                            $db->bind(':user_id', $student_id);
                            $db->bind(':type_id', $clearance_type_id);
                            $db->bind(':office_id', $office_result['office_id']);
                            $db->bind(':semester', $semester);
                            $db->bind(':school_year', $school_year_selected);
                            $db->bind(':office_order', $office['order']);

                            if ($db->execute()) {
                                $success_count++;
                                if ($office['name'] === 'Director_SAS') {
                                    $director_clearance_id = (int) $db->lastInsertId();
                                }
                            }
                        }
                    }

                    if ($success_count == count($offices)) {
                        // Use the Director_SAS clearance row as the organization tracking anchor.
                        if (!$director_clearance_id) {
                            $db->query("SELECT c.clearance_id
                                        FROM clearance c
                                        JOIN offices o ON c.office_id = o.office_id
                                        WHERE c.users_id = :user_id
                                        AND c.semester = :semester
                                        AND c.school_year = :school_year
                                        AND o.office_name = 'Director_SAS'
                                        ORDER BY c.clearance_id DESC
                                        LIMIT 1");
                            $db->bind(':user_id', $student_id);
                            $db->bind(':semester', $semester);
                            $db->bind(':school_year', $school_year_selected);
                            $director_row = $db->single();
                            $director_clearance_id = $director_row ? (int) $director_row['clearance_id'] : 0;
                        }

                        if ($director_clearance_id > 0) {
                            $db->query("SELECT college_id FROM users WHERE users_id = :user_id LIMIT 1");
                            $db->bind(':user_id', $student_id);
                            $student_college = $db->single();
                            $student_college_id = $student_college['college_id'] ?? null;

                                                        $orgInsert = "INSERT INTO organization_clearance (clearance_id, org_id, office_id, status, created_at, updated_at)
                                                                                    SELECT :clearance_id_insert, so.org_id, so.office_id, 'pending', NOW(), NOW()
                                                                                    FROM student_organizations so
                                                                                    LEFT JOIN organization_clearance oc
                                                                                        ON oc.clearance_id = :clearance_id_match
                                                                                     AND oc.org_id = so.org_id
                                                                                    WHERE COALESCE(so.status, 'active') = 'active'
                                                                                        AND so.office_id IS NOT NULL
                                                                                        AND oc.org_clearance_id IS NULL
                                                                                        AND (
                                                                                                so.org_type <> 'college'
                                                                                                OR so.college_id IS NULL
                                                                                                OR so.college_id = :student_college_id_eq
                                                                                                OR NOT EXISTS (
                                                                                                        SELECT 1
                                                                                                        FROM student_organizations so_match
                                                                                                        WHERE so_match.org_type = 'college'
                                                                                                            AND COALESCE(so_match.status, 'active') = 'active'
                                                                                                            AND so_match.college_id = :student_college_id_match
                                                                                                )
                                                                                        )";

                            $db->query($orgInsert);
                                                        $db->bind(':clearance_id_insert', $director_clearance_id, PDO::PARAM_INT);
                                                        $db->bind(':clearance_id_match', $director_clearance_id, PDO::PARAM_INT);
                            if ($student_college_id === null) {
                                $db->bind(':student_college_id_eq', null, PDO::PARAM_NULL);
                                $db->bind(':student_college_id_match', null, PDO::PARAM_NULL);
                            } else {
                                $db->bind(':student_college_id_eq', (int) $student_college_id, PDO::PARAM_INT);
                                $db->bind(':student_college_id_match', (int) $student_college_id, PDO::PARAM_INT);
                            }

                            if (!$db->execute()) {
                                throw new Exception("Failed to create organization pending clearances");
                            }
                        }

                        $db->commit();

                        // Log activity
                        if (class_exists('ActivityLogModel')) {
                            $logModel = new ActivityLogModel();
                            $logModel->log($student_id, 'APPLY_CLEARANCE', "Applied for clearance: $clearance_type - $semester $school_year_selected");
                        }

                        $_SESSION['success_message'] = "Clearance application submitted successfully! You can now track your progress.";
                        header("Location: dashboard.php?tab=status");
                        exit();
                    } else {
                        $db->rollback();
                        $error = "Failed to submit clearance application. Please try again.";
                    }
                } else {
                    $error = "Invalid clearance type.";
                }
            }
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollback();
            }
            error_log("Clearance application error: " . $e->getMessage());
            $error = "An error occurred. Please try again.";
        }
    }
}

// Handle cancel application
if (isset($_POST['cancel_application'])) {
    $semester = $_POST['semester'] ?? '';
    $school_year = $_POST['school_year'] ?? '';

    try {
        $db->beginTransaction();

        // Verify this application is still in progress before cancelling.
        $db->query("SELECT 
                        COUNT(*) as total_count,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count
                    FROM clearance
                    WHERE users_id = :user_id
                    AND semester = :semester
                    AND school_year = :school_year");
        $db->bind(':user_id', $student_id);
        $db->bind(':semester', $semester);
        $db->bind(':school_year', $school_year);
        $app_state = $db->single();

        if (!$app_state || (int) ($app_state['total_count'] ?? 0) === 0) {
            throw new Exception("No matching clearance application found.");
        }

        if ((int) ($app_state['pending_count'] ?? 0) === 0) {
            throw new Exception("This clearance is already completed and cannot be cancelled.");
        }

        // Delete the whole in-progress clearance cycle so student can re-apply cleanly.
        $db->query("DELETE FROM clearance 
                    WHERE users_id = :user_id 
                    AND semester = :semester 
                    AND school_year = :school_year");
        $db->bind(':user_id', $student_id);
        $db->bind(':semester', $semester);
        $db->bind(':school_year', $school_year);
        $db->execute();

        $db->commit();

        // Log activity
        if (class_exists('ActivityLogModel')) {
            $logModel = new ActivityLogModel();
            $logModel->log($student_id, 'CANCEL_CLEARANCE', "Cancelled clearance application for $semester $school_year");
        }

        $_SESSION['success_message'] = "Clearance application cancelled successfully.";
        header("Location: dashboard.php?tab=status");
        exit();

    } catch (Exception $e) {
        $db->rollback();
        error_log("Cancel clearance error: " . $e->getMessage());
        $error = "Failed to cancel application. Please try again.";
    }
}

// Handle student profile update
if (isset($_POST['update_profile'])) {
    $new_fname = trim($_POST['fname'] ?? '');
    $new_lname = trim($_POST['lname'] ?? '');
    $new_email = trim($_POST['email'] ?? '');
    $new_contact = trim($_POST['contact'] ?? '');
    $new_address = trim($_POST['address'] ?? '');
    $new_age_raw = trim($_POST['age'] ?? '');
    $new_age = $new_age_raw === '' ? null : (int) $new_age_raw;
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_new_password = $_POST['confirm_new_password'] ?? '';
    $wants_password_change = ($current_password !== '' || $new_password !== '' || $confirm_new_password !== '');

    if ($new_fname === '' || $new_lname === '' || $new_email === '') {
        $error = "First name, last name, and email are required.";
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($new_age !== null && ($new_age < 10 || $new_age > 120)) {
        $error = "Please enter a valid age between 10 and 120.";
    } elseif ($wants_password_change && ($current_password === '' || $new_password === '' || $confirm_new_password === '')) {
        $error = "To change password, fill in current password, new password, and confirm password.";
    } elseif ($wants_password_change && strlen($new_password) < 8) {
        $error = "New password must be at least 8 characters long.";
    } elseif ($wants_password_change && $new_password !== $confirm_new_password) {
        $error = "New password and confirm password do not match.";
    } else {
        try {
            // Ensure email stays unique across users.
            $db->query("SELECT users_id FROM users WHERE emails = :email AND users_id != :user_id LIMIT 1");
            $db->bind(':email', $new_email);
            $db->bind(':user_id', $student_id);
            $existing_user = $db->single();

            if ($existing_user) {
                $error = "This email is already used by another account.";
            } else {
                $db->beginTransaction();

                $db->query("UPDATE users
                            SET fname = :fname,
                                lname = :lname,
                                emails = :email,
                                contacts = :contacts,
                                address = :address,
                                age = :age
                            WHERE users_id = :user_id AND user_role_id = (SELECT user_role_id FROM user_role WHERE user_role_name = 'student' LIMIT 1)");
                $db->bind(':fname', $new_fname);
                $db->bind(':lname', $new_lname);
                $db->bind(':email', $new_email);
                $db->bind(':contacts', $new_contact);
                $db->bind(':address', $new_address);
                $db->bind(':age', $new_age);
                $db->bind(':user_id', $student_id);

                if ($db->execute()) {
                    if ($wants_password_change) {
                        $db->query("SELECT password FROM users WHERE users_id = :user_id LIMIT 1");
                        $db->bind(':user_id', $student_id);
                        $password_row = $db->single();
                        $current_hash = $password_row['password'] ?? '';

                        if (!$current_hash || !password_verify($current_password, $current_hash)) {
                            throw new Exception('INVALID_CURRENT_PASSWORD');
                        }

                        $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                        $db->query("UPDATE users SET password = :password WHERE users_id = :user_id");
                        $db->bind(':password', $new_password_hash);
                        $db->bind(':user_id', $student_id);

                        if (!$db->execute()) {
                            throw new Exception('PASSWORD_UPDATE_FAILED');
                        }
                    }

                    $db->commit();

                    $_SESSION['user_fname'] = $new_fname;
                    $_SESSION['user_lname'] = $new_lname;
                    $_SESSION['user_name'] = $new_fname . ' ' . $new_lname;
                    $_SESSION['user_email'] = $new_email;

                    if (class_exists('ActivityLogModel')) {
                        $logModel = new ActivityLogModel();
                        $log_description = $wants_password_change
                            ? 'Student profile and password updated'
                            : 'Student profile updated';
                        $logModel->log($student_id, 'UPDATE_PROFILE', $log_description);
                    }

                    $_SESSION['success_message'] = $wants_password_change
                        ? "Profile and password updated successfully."
                        : "Profile updated successfully.";
                    header("Location: dashboard.php?tab=dashboard");
                    exit();
                } else {
                    $db->rollback();
                    $error = "Unable to update profile right now. Please try again.";
                }
            }
        } catch (Exception $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->rollback();
            }

            if ($e->getMessage() === 'INVALID_CURRENT_PASSWORD') {
                $error = "Current password is incorrect.";
            } elseif ($e->getMessage() === 'PASSWORD_UPDATE_FAILED') {
                $error = "Profile updated but password change failed. Please try again.";
            } else {
                $error = "An error occurred while updating profile.";
            }

            error_log("Student profile update error: " . $e->getMessage());
        }
    }
}

// Check for session success message
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Get student's complete information
try {
    $db->query("SELECT u.*, c.college_name, cr.course_name, cr.course_code
                FROM users u
                LEFT JOIN college c ON u.college_id = c.college_id
                LEFT JOIN course cr ON u.course_id = cr.course_id
                WHERE u.users_id = :user_id");
    $db->bind(':user_id', $student_id);
    $student_info = $db->single();

    if (!$student_info) {
        $student_info = [];
    }
} catch (Exception $e) {
    error_log("Error fetching student info: " . $e->getMessage());
    $student_info = [];
}

// Get student's profile picture
$profile_pic = $student_info['profile_picture'] ?? null;

// Fetch friend list for student-to-student direct messaging.
try {
    $friend_profile_column_sql = $user_profile_picture_column_available
        ? "u.profile_picture,"
        : "NULL AS profile_picture,";
    $friend_presence_column_sql = $user_presence_columns_available
        ? "COALESCE(u.is_online, 0) AS is_online_flag,
                    u.last_seen_at,
                    CASE
                        WHEN u.last_seen_at IS NULL THEN NULL
                        ELSE GREATEST(TIMESTAMPDIFF(SECOND, u.last_seen_at, NOW()), 0)
                    END AS seconds_since_last_seen,"
        : "0 AS is_online_flag,
                    NULL AS last_seen_at,
                    NULL AS seconds_since_last_seen,";

    $db->query("SELECT u.users_id,
                    u.ismis_id,
                    u.emails,
                    {$friend_profile_column_sql}
                    {$friend_presence_column_sql}
                    COALESCE(
                        NULLIF(TRIM(CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, ''))), ''),
                        u.emails,
                        CONCAT('Student #', u.users_id)
                    ) AS display_name,
                    sfn.nickname AS custom_nickname,
                    sf.created_at AS friended_at
                FROM student_friendships sf
                INNER JOIN users u ON u.users_id = CASE
                    WHEN sf.user_one_id = :viewer_id_case THEN sf.user_two_id
                    ELSE sf.user_one_id
                END
                INNER JOIN user_role ur ON ur.user_role_id = u.user_role_id AND ur.user_role_name = 'student'
                LEFT JOIN student_friend_nicknames sfn ON sfn.owner_id = :viewer_id_nick AND sfn.friend_id = u.users_id
                WHERE (sf.user_one_id = :viewer_id_one OR sf.user_two_id = :viewer_id_two)
                  AND sf.status = 'accepted'
                ORDER BY display_name ASC");
        $db->bind(':viewer_id_case', $student_id);
        $db->bind(':viewer_id_nick', $student_id);
        $db->bind(':viewer_id_one', $student_id);
        $db->bind(':viewer_id_two', $student_id);
    $student_friends = $db->resultSet() ?: [];

    foreach ($student_friends as &$friend) {
        $base_display_name = (string) ($friend['display_name'] ?? ('Student #' . (int) ($friend['users_id'] ?? 0)));
        $custom_nickname = trim((string) ($friend['custom_nickname'] ?? ''));
        $profile_picture_path = trim((string) ($friend['profile_picture'] ?? ''));

        $friend['profile_picture_url'] = '';
        if ($profile_picture_path !== '' && strpos($profile_picture_path, '..') === false) {
            $friend['profile_picture_url'] = '../' . ltrim($profile_picture_path, '/\\');
        }

        $presence_meta = buildFriendPresenceMeta(
            $friend['is_online_flag'] ?? 0,
            $friend['last_seen_at'] ?? null,
            $friend['seconds_since_last_seen'] ?? null
        );

        $friend['base_display_name'] = $base_display_name;
        $friend['chat_display_name'] = $custom_nickname !== '' ? $custom_nickname : $base_display_name;
        $friend['presence_is_online'] = $presence_meta['is_online'] ? 1 : 0;
        $friend['presence_status_class'] = $presence_meta['status_class'];
        $friend['presence_status_text'] = $presence_meta['status_text'];
        $friend['presence_minutes_since_logout'] = $presence_meta['minutes_since_logout'];
    }
    unset($friend);

    $student_contacts = $student_friends;
} catch (Exception $e) {
    error_log("Error fetching friend contacts: " . $e->getMessage());
    $student_friends = [];
    $student_contacts = [];
}

// Incoming friend requests for current student to accept.
try {
    $db->query("SELECT sf.friendship_id,
                    sf.created_at,
                    sf.requested_by,
                    u.ismis_id,
                    u.emails,
                    COALESCE(
                        NULLIF(TRIM(CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, ''))), ''),
                        u.emails,
                        CONCAT('Student #', u.users_id)
                    ) AS requester_name
                FROM student_friendships sf
                INNER JOIN users u ON u.users_id = sf.requested_by
                INNER JOIN user_role ur ON ur.user_role_id = u.user_role_id AND ur.user_role_name = 'student'
                WHERE sf.status = 'pending'
                                    AND sf.requested_by != :viewer_id_req
                                    AND (sf.user_one_id = :viewer_id_one OR sf.user_two_id = :viewer_id_two)
                ORDER BY sf.created_at DESC");
        $db->bind(':viewer_id_req', $student_id);
        $db->bind(':viewer_id_one', $student_id);
        $db->bind(':viewer_id_two', $student_id);
    $incoming_friend_requests = $db->resultSet() ?: [];
        $incoming_friend_request_count = count($incoming_friend_requests);
} catch (Exception $e) {
    error_log("Error fetching incoming friend requests: " . $e->getMessage());
    $incoming_friend_requests = [];
        $incoming_friend_request_count = 0;
}

// Outgoing friend requests sent by current student.
try {
    $db->query("SELECT sf.friendship_id,
                    sf.created_at,
                    u.ismis_id,
                    u.emails,
                    COALESCE(
                        NULLIF(TRIM(CONCAT(COALESCE(u.fname, ''), ' ', COALESCE(u.lname, ''))), ''),
                        u.emails,
                        CONCAT('Student #', u.users_id)
                    ) AS target_name
                FROM student_friendships sf
                INNER JOIN users u ON u.users_id = CASE
                                        WHEN sf.user_one_id = :viewer_id_case THEN sf.user_two_id
                    ELSE sf.user_one_id
                END
                INNER JOIN user_role ur ON ur.user_role_id = u.user_role_id AND ur.user_role_name = 'student'
                WHERE sf.status = 'pending'
                                    AND sf.requested_by = :viewer_id_req
                ORDER BY sf.created_at DESC");
        $db->bind(':viewer_id_case', $student_id);
        $db->bind(':viewer_id_req', $student_id);
    $outgoing_friend_requests = $db->resultSet() ?: [];
} catch (Exception $e) {
    error_log("Error fetching outgoing friend requests: " . $e->getMessage());
    $outgoing_friend_requests = [];
}

// Get unread message count for sidebar badge.
try {
    $db->query("SELECT COUNT(*) AS unread_count
                                FROM student_messages sm
                                INNER JOIN student_friendships sf
                                        ON sf.user_one_id = LEAST(sm.sender_id, sm.recipient_id)
                                     AND sf.user_two_id = GREATEST(sm.sender_id, sm.recipient_id)
                                WHERE sm.recipient_id = :user_id
                                    AND sm.read_at IS NULL
                                    AND sf.status = 'accepted'");
    $db->bind(':user_id', $student_id);
    $unread_row = $db->single();
    $unread_message_count = (int) ($unread_row['unread_count'] ?? 0);
} catch (Exception $e) {
    error_log("Error fetching unread message count: " . $e->getMessage());
    $unread_message_count = 0;
}

// Mark incoming messages as read when student opens the Messages tab.
if ($active_tab === 'messages') {
    try {
        $db->query("UPDATE student_messages
                    SET read_at = NOW()
                    WHERE recipient_id = :user_id
                      AND read_at IS NULL");
        $db->bind(':user_id', $student_id);
        $db->execute();
        $unread_message_count = 0;
    } catch (Exception $e) {
        error_log("Error marking messages as read: " . $e->getMessage());
    }
}

$message_tab_notification_count = $unread_message_count + $incoming_friend_request_count;

// Determine selected chat peer for Messenger-like conversation view.
$has_explicit_chat_target = false;
if (isset($_GET['chat_with']) && (int) $_GET['chat_with'] > 0) {
    $has_explicit_chat_target = true;
} elseif (isset($_POST['send_message']) && isset($_POST['recipient_id']) && (int) ($_POST['recipient_id'] ?? 0) > 0) {
    $has_explicit_chat_target = true;
}

$selected_chat_id = isset($_GET['chat_with']) ? (int) $_GET['chat_with'] : 0;
if ($selected_chat_id <= 0 && isset($_POST['recipient_id'])) {
    $selected_chat_id = (int) $_POST['recipient_id'];
}

// Fetch student messages involving current student.
try {
    $db->query("SELECT sm.message_id,
                    sm.sender_id,
                    sm.recipient_id,
                    sm.message_body,
                    sm.attachment_file,
                    sm.attachment_name,
                    sm.attachment_type,
                    sm.attachment_size,
                    sm.reply_to_message_id,
                    sm.sent_at,
                    sm.read_at,
                    CASE WHEN sm.sender_id = :direction_user_id THEN 'sent' ELSE 'received' END AS direction,
                    COALESCE(
                        NULLIF(TRIM(CONCAT(COALESCE(s.fname, ''), ' ', COALESCE(s.lname, ''))), ''),
                        s.emails,
                        CONCAT('Student #', s.users_id)
                    ) AS sender_name,
                    COALESCE(
                        NULLIF(TRIM(CONCAT(COALESCE(r.fname, ''), ' ', COALESCE(r.lname, ''))), ''),
                        r.emails,
                        CONCAT('Student #', r.users_id)
                    ) AS recipient_name,
                    rm.message_body AS reply_message_body,
                    rm.sender_id AS reply_sender_id
                FROM student_messages sm
                LEFT JOIN student_messages rm ON rm.message_id = sm.reply_to_message_id
                INNER JOIN users s ON s.users_id = sm.sender_id
                INNER JOIN users r ON r.users_id = sm.recipient_id
                INNER JOIN user_role sr ON sr.user_role_id = s.user_role_id AND sr.user_role_name = 'student'
                INNER JOIN user_role rr ON rr.user_role_id = r.user_role_id AND rr.user_role_name = 'student'
                INNER JOIN student_friendships sf
                    ON sf.user_one_id = LEAST(sm.sender_id, sm.recipient_id)
                   AND sf.user_two_id = GREATEST(sm.sender_id, sm.recipient_id)
                WHERE (sm.sender_id = :sender_user_id OR sm.recipient_id = :recipient_user_id)
                  AND sf.status = 'accepted'
                ORDER BY sm.sent_at DESC
                LIMIT 200");
    $db->bind(':direction_user_id', $student_id);
    $db->bind(':sender_user_id', $student_id);
    $db->bind(':recipient_user_id', $student_id);
    $student_messages = $db->resultSet() ?: [];

    foreach ($student_messages as $message) {
        if (($message['direction'] ?? '') === 'sent') {
            $sent_messages[] = $message;
        } else {
            $inbox_messages[] = $message;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching student messages: " . $e->getMessage());
    $student_messages = [];
    $inbox_messages = [];
    $sent_messages = [];
}

foreach ($student_friends as $friend) {
    $friend_id = (int) ($friend['users_id'] ?? 0);
    if ($friend_id > 0) {
        $friends_by_id[$friend_id] = $friend;
    }
}

// Build per-friend conversation previews from latest message to oldest.
foreach ($student_messages as $message) {
    $sender_id = (int) ($message['sender_id'] ?? 0);
    $recipient_id = (int) ($message['recipient_id'] ?? 0);
    $peer_id = $sender_id === (int) $student_id ? $recipient_id : $sender_id;

    if ($peer_id <= 0 || !isset($friends_by_id[$peer_id])) {
        continue;
    }

    if (!isset($conversation_map[$peer_id])) {
        $latest_preview_message = buildMessageSnippet((string) ($message['message_body'] ?? ''), 80, true);
        if ($latest_preview_message === '' && !empty($message['attachment_name'])) {
            $latest_preview_message = '[Attachment] ' . buildMessageSnippet((string) $message['attachment_name'], 48, false);
        }

        $conversation_map[$peer_id] = [
            'peer_id' => $peer_id,
            'latest_at' => $message['sent_at'] ?? null,
            'latest_message' => $latest_preview_message,
            'latest_direction' => (string) ($message['direction'] ?? 'received')
        ];
    }
}

foreach ($student_friends as $friend) {
    $friend_id = (int) ($friend['users_id'] ?? 0);
    if ($friend_id > 0 && !isset($conversation_map[$friend_id])) {
        $conversation_map[$friend_id] = [
            'peer_id' => $friend_id,
            'latest_at' => null,
            'latest_message' => '',
            'latest_direction' => 'received'
        ];
    }
}

// Sort friends by latest message activity (Messenger-like ordering).
usort($student_friends, function ($a, $b) use ($conversation_map) {
    $a_id = (int) ($a['users_id'] ?? 0);
    $b_id = (int) ($b['users_id'] ?? 0);
    $a_time = !empty($conversation_map[$a_id]['latest_at']) ? strtotime((string) $conversation_map[$a_id]['latest_at']) : 0;
    $b_time = !empty($conversation_map[$b_id]['latest_at']) ? strtotime((string) $conversation_map[$b_id]['latest_at']) : 0;

    if ($a_time === $b_time) {
        return strcasecmp((string) ($a['chat_display_name'] ?? ''), (string) ($b['chat_display_name'] ?? ''));
    }

    return $a_time < $b_time ? 1 : -1;
});

$student_contacts = $student_friends;

if ($selected_chat_id <= 0 || !isset($friends_by_id[$selected_chat_id])) {
    if (!empty($student_friends)) {
        $selected_chat_id = (int) ($student_friends[0]['users_id'] ?? 0);
    } else {
        $selected_chat_id = 0;
    }
}

if ($selected_chat_id > 0 && isset($friends_by_id[$selected_chat_id])) {
    $selected_chat_friend = $friends_by_id[$selected_chat_id];
    $selected_chat_friend_base_name = (string) ($selected_chat_friend['base_display_name'] ?? ('Student #' . $selected_chat_id));
    $selected_chat_friend_label = (string) ($selected_chat_friend['chat_display_name'] ?? $selected_chat_friend_base_name);
}

if ($selected_chat_id > 0) {
    foreach ($student_messages as $message) {
        $sender_id = (int) ($message['sender_id'] ?? 0);
        $recipient_id = (int) ($message['recipient_id'] ?? 0);
        $peer_id = $sender_id === (int) $student_id ? $recipient_id : $sender_id;

        if ($peer_id === $selected_chat_id) {
            $selected_conversation_messages[] = $message;
        }
    }

    // Query is DESC, so reverse for natural chat timeline.
    $selected_conversation_messages = array_reverse($selected_conversation_messages);
}

if (!empty($selected_conversation_messages)) {
    try {
        $message_ids = array_values(array_filter(array_map(function ($message) {
            return (int) ($message['message_id'] ?? 0);
        }, $selected_conversation_messages)));

        if (!empty($message_ids)) {
            $message_id_list = implode(',', array_unique($message_ids));
            $db->query("SELECT message_id, reactor_id, reaction_emoji
                        FROM student_message_reactions
                        WHERE message_id IN ({$message_id_list})");
            $reaction_rows = $db->resultSet() ?: [];

            foreach ($reaction_rows as $reaction_row) {
                $reaction_message_id = (int) ($reaction_row['message_id'] ?? 0);
                $reaction_emoji = (string) ($reaction_row['reaction_emoji'] ?? '');
                $reaction_user_id = (int) ($reaction_row['reactor_id'] ?? 0);

                if ($reaction_message_id <= 0 || $reaction_emoji === '' || !in_array($reaction_emoji, $allowed_message_reactions, true)) {
                    continue;
                }

                if (!isset($message_reactions_map[$reaction_message_id])) {
                    $message_reactions_map[$reaction_message_id] = [
                        'counts' => [],
                        'mine' => ''
                    ];
                }

                if (!isset($message_reactions_map[$reaction_message_id]['counts'][$reaction_emoji])) {
                    $message_reactions_map[$reaction_message_id]['counts'][$reaction_emoji] = 0;
                }

                $message_reactions_map[$reaction_message_id]['counts'][$reaction_emoji]++;

                if ($reaction_user_id === (int) $student_id) {
                    $message_reactions_map[$reaction_message_id]['mine'] = $reaction_emoji;
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching message reactions: " . $e->getMessage());
        $message_reactions_map = [];
    }
}

if (
    isset($_POST['reply_to_message_id'])
    && (int) ($_POST['recipient_id'] ?? 0) === (int) $selected_chat_id
) {
    $reply_to_message_prefill_id = (int) ($_POST['reply_to_message_id'] ?? 0);
}

if ($reply_to_message_prefill_id > 0) {
    foreach ($selected_conversation_messages as $message) {
        $message_id = (int) ($message['message_id'] ?? 0);
        if ($message_id !== $reply_to_message_prefill_id) {
            continue;
        }

        $reply_sender_name = ((int) ($message['sender_id'] ?? 0) === (int) $student_id)
            ? 'You'
            : (string) ($selected_chat_friend_label ?? 'Classmate');

        $reply_body = (string) ($message['message_body'] ?? '');
        $reply_body = preg_replace('/\s+/', ' ', $reply_body);
        if ((function_exists('mb_strlen') ? mb_strlen($reply_body, 'UTF-8') : strlen($reply_body)) > 140) {
            $reply_body = (function_exists('mb_substr') ? mb_substr($reply_body, 0, 137, 'UTF-8') : substr($reply_body, 0, 137)) . '...';
        }

        $reply_preview_message = [
            'id' => $message_id,
            'sender' => $reply_sender_name,
            'body' => $reply_body
        ];
        break;
    }
}

// Get all clearance applications with proof information
try {
    // Check if the new columns exist
    $has_student_proof = hasDatabaseColumn('clearance', 'student_proof_file');

    if ($has_student_proof) {
        // Query with new columns
        $db->query("SELECT c.*, 
                    o.office_name, 
                    o.office_id,
                    o.office_description,
                    ct.clearance_name as clearance_type_name,
                    DATE_FORMAT(c.created_at, '%M %d, %Y') as formatted_date,
                    DATE_FORMAT(c.created_at, '%Y-%m-%d') as sort_date,
                    DATE_FORMAT(c.processed_date, '%M %d, %Y %h:%i %p') as formatted_processed_date,
                    CONCAT(p.fname, ' ', p.lname) as processed_by_name,
                    c.lacking_comment,
                    c.lacking_comment_at,
                    c.student_proof_file,
                    c.student_proof_remarks,
                    c.student_proof_uploaded_at
                    FROM clearance c
                    LEFT JOIN offices o ON c.office_id = o.office_id
                    LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                    LEFT JOIN users p ON c.processed_by = p.users_id
                    WHERE c.users_id = :user_id
                    ORDER BY c.created_at DESC, c.office_order ASC");
    } else {
        // Query without new columns
        $db->query("SELECT c.*, 
                    o.office_name, 
                    o.office_id,
                    o.office_description,
                    ct.clearance_name as clearance_type_name,
                    DATE_FORMAT(c.created_at, '%M %d, %Y') as formatted_date,
                    DATE_FORMAT(c.created_at, '%Y-%m-%d') as sort_date,
                    DATE_FORMAT(c.processed_date, '%M %d, %Y %h:%i %p') as formatted_processed_date,
                    CONCAT(p.fname, ' ', p.lname) as processed_by_name,
                    c.lacking_comment,
                    c.lacking_comment_at
                    FROM clearance c
                    LEFT JOIN offices o ON c.office_id = o.office_id
                    LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                    LEFT JOIN users p ON c.processed_by = p.users_id
                    WHERE c.users_id = :user_id
                    ORDER BY c.created_at DESC, c.office_order ASC");
    }

    $db->bind(':user_id', $student_id);
    $clearance_data = $db->resultSet();

    if (!$clearance_data) {
        $clearance_data = [];
    }
} catch (Exception $e) {
    error_log("Error fetching clearance data: " . $e->getMessage());
    $clearance_data = [];
    $error = "Error loading clearance data. Please refresh the page.";
}

function extractOrganizationLackingComment($remarks)
{
    if (!is_string($remarks) || $remarks === '') {
        return '';
    }

    if (preg_match('/\[(?:[A-Z]+_LACKING|ORG_LACKING)\]\s*(.+?)(?=\s*\|\s*|$)/s', $remarks, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function cleanOrganizationRemarks($remarks)
{
    $clean = preg_replace('/\s*\|\s*\[(?:[A-Z]+_LACKING|ORG_LACKING)\]\s*.+?(?=\s*\|\s*|$)/s', '', (string) $remarks);
    $clean = preg_replace('/^\[(?:[A-Z]+_LACKING|ORG_LACKING)\]\s*.+?(?=\s*\|\s*|$)/s', '', trim($clean));
    return trim($clean, " \t\n\r\0\x0B|");
}

$organization_clearance_data = [];
try {
    ensureOrganizationProofColumns($db);
    $db->query("SELECT
                    oc.org_clearance_id,
                    oc.clearance_id,
                    oc.org_id,
                    oc.status,
                    oc.remarks,
                    oc.student_proof_file,
                    oc.student_proof_remarks,
                    oc.student_proof_uploaded_at,
                    oc.processed_date,
                    so.org_name,
                    so.org_type,
                    c.users_id,
                    c.semester,
                    c.school_year,
                    c.created_at,
                    c.lacking_comment,
                    c.lacking_comment_at,
                    ct.clearance_name as clearance_type_name
                FROM organization_clearance oc
                JOIN clearance c ON oc.clearance_id = c.clearance_id
                JOIN student_organizations so ON oc.org_id = so.org_id
                LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                WHERE c.users_id = :user_id
                ORDER BY c.created_at DESC, so.org_name ASC");
    $db->bind(':user_id', $student_id);
    $organization_clearance_data = $db->resultSet() ?: [];

    foreach ($organization_clearance_data as &$org_item) {
        $org_display_name = (strtolower((string) ($org_item['org_type'] ?? '')) === 'college')
            ? 'College Org'
            : ($org_item['org_name'] ?? 'Organization');

        $org_item['item_type'] = 'organization';
        $org_item['display_name'] = $org_display_name;
        $org_item['target_name'] = $org_display_name;
        $org_item['target_type'] = 'organization';
        $org_item['lacking_comment'] = extractOrganizationLackingComment($org_item['remarks'] ?? '');
        $org_item['clean_remarks'] = cleanOrganizationRemarks($org_item['remarks'] ?? '');
        $org_item['formatted_date'] = !empty($org_item['created_at']) ? date('F d, Y', strtotime($org_item['created_at'])) : 'N/A';
        $org_item['formatted_processed_date'] = !empty($org_item['processed_date']) ? date('F d, Y h:i A', strtotime($org_item['processed_date'])) : '';
        $org_item['processed_by_name'] = $org_display_name;
    }
    unset($org_item);
} catch (Exception $e) {
    error_log("Error fetching organization clearance data: " . $e->getMessage());
    $organization_clearance_data = [];
}

// Group clearances by semester and school year
$grouped_clearances = [];
$clearance_summary = [
    'total' => 0,
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0,
    'completed' => 0
];

foreach ($clearance_data as $item) {
    $key = $item['semester'] . ' ' . $item['school_year'];
    if (!isset($grouped_clearances[$key])) {
        $grouped_clearances[$key] = [
            'semester' => $item['semester'],
            'school_year' => $item['school_year'],
            'applications' => [],
            'organization_applications' => [],
            'total_offices' => 0,
            'approved_offices' => 0,
            'rejected_offices' => 0,
            'pending_offices' => 0,
            'total_organizations' => 0,
            'approved_organizations' => 0,
            'rejected_organizations' => 0,
            'pending_organizations' => 0,
            'status' => 'pending',
            'applied_date' => $item['created_at'],
            'clearance_type' => $item['clearance_type_name'] ?? 'Unknown',
            'can_cancel' => false
        ];
    }

    $grouped_clearances[$key]['applications'][] = $item;
    $grouped_clearances[$key]['total_offices']++;

    if ($item['status'] == 'approved') {
        $grouped_clearances[$key]['approved_offices']++;
        $clearance_summary['approved']++;
    } elseif ($item['status'] == 'rejected') {
        $grouped_clearances[$key]['rejected_offices']++;
        $clearance_summary['rejected']++;
    } elseif ($item['status'] == 'pending') {
        $grouped_clearances[$key]['pending_offices']++;
        $clearance_summary['pending']++;
    }

    $clearance_summary['total']++;
}

foreach ($organization_clearance_data as $org_item) {
    $key = $org_item['semester'] . ' ' . $org_item['school_year'];
    if (!isset($grouped_clearances[$key])) {
        $grouped_clearances[$key] = [
            'semester' => $org_item['semester'],
            'school_year' => $org_item['school_year'],
            'applications' => [],
            'organization_applications' => [],
            'total_offices' => 0,
            'approved_offices' => 0,
            'rejected_offices' => 0,
            'pending_offices' => 0,
            'total_organizations' => 0,
            'approved_organizations' => 0,
            'rejected_organizations' => 0,
            'pending_organizations' => 0,
            'status' => 'pending',
            'applied_date' => $org_item['created_at'],
            'clearance_type' => $org_item['clearance_type_name'] ?? 'Unknown',
            'can_cancel' => false
        ];
    }

    $grouped_clearances[$key]['organization_applications'][] = $org_item;
    $grouped_clearances[$key]['total_organizations']++;

    if (($org_item['status'] ?? '') === 'approved') {
        $grouped_clearances[$key]['approved_organizations']++;
    } elseif (($org_item['status'] ?? '') === 'rejected') {
        $grouped_clearances[$key]['rejected_organizations']++;
    } else {
        $grouped_clearances[$key]['pending_organizations']++;
    }
}

// Determine overall status for each group and if it can be cancelled
foreach ($grouped_clearances as &$group) {
    if ($group['rejected_offices'] > 0 || ($group['rejected_organizations'] ?? 0) > 0) {
        $group['status'] = 'rejected';
    } elseif (
        $group['approved_offices'] == $group['total_offices']
        && (($group['approved_organizations'] ?? 0) == ($group['total_organizations'] ?? 0))
    ) {
        $group['status'] = 'approved';
        $clearance_summary['completed']++;
    } else {
        $group['status'] = 'pending';
        // Allow cancel for any in-progress application.
        $group['can_cancel'] = ($group['pending_offices'] > 0 || ($group['pending_organizations'] ?? 0) > 0);
    }

    if (!empty($group['organization_applications'])) {
        usort($group['organization_applications'], function ($a, $b) {
            return strcasecmp($a['display_name'] ?? '', $b['display_name'] ?? '');
        });
    }
}

// Get current active clearance (most recent pending/in-progress)
$current_clearance = null;
foreach ($grouped_clearances as $key => $group) {
    if ($group['status'] == 'pending') {
        // Sort applications by office order for this group
        usort($group['applications'], function ($a, $b) {
            return ($a['office_order'] ?? 0) - ($b['office_order'] ?? 0);
        });
        if (!empty($group['organization_applications'])) {
            usort($group['organization_applications'], function ($a, $b) {
                return strcasecmp($a['display_name'] ?? '', $b['display_name'] ?? '');
            });
        }
        $current_clearance = $group;
        break;
    }
}

$current_pending_organizations = (int) (($current_clearance['pending_organizations'] ?? 0));
$current_total_organizations = (int) (($current_clearance['total_organizations'] ?? 0));
$current_approved_organizations = (int) (($current_clearance['approved_organizations'] ?? 0));
$current_term_label = $current_clearance ? trim(($current_clearance['semester'] ?? '') . ' ' . ($current_clearance['school_year'] ?? '')) : 'No active clearance';
$current_progress_label = $current_clearance
    ? (($current_clearance['approved_offices'] ?? 0) . '/' . ($current_clearance['total_offices'] ?? 0) . ' offices cleared')
    : 'Ready for a new clearance application';
$hero_support_text = $current_clearance
    ? ($current_pending_organizations > 0
        ? $current_pending_organizations . ' organization requirement(s) still need your attention.'
        : 'Your current clearance is moving through the required offices.')
    : 'You can start a new clearance request anytime from the apply tab.';

// Get clearance types for dropdown
try {
    $db->query("SELECT * FROM clearance_type ORDER BY clearance_name");
    $clearance_types = $db->resultSet();
    if (!$clearance_types) {
        $clearance_types = [];
    }
} catch (Exception $e) {
    error_log("Error fetching clearance types: " . $e->getMessage());
    $clearance_types = [];
}

// Determine the current step in the clearance process
function getCurrentStep($applications)
{
    $step = 1;
    foreach ($applications as $app) {
        if ($app['status'] != 'approved') {
            return $step;
        }
        $step++;
    }
    return $step;
}

// Get office icons
function getOfficeIcon($office_name)
{
    $icons = [
        'Librarian' => 'book',
        'Director_SAS' => 'users',
        'Dean' => 'chalkboard-teacher',
        'Cashier' => 'coins',
        'Registrar' => 'clipboard-list'
    ];
    return $icons[$office_name] ?? 'building';
}

// Get office display name
function getOfficeDisplayName($office_name)
{
    return str_replace('_', ' ', $office_name);
}

function getOrganizationIcon($org_type)
{
    $icons = [
        'town' => 'map-marker-alt',
        'college' => 'graduation-cap',
        'clinic' => 'briefcase-medical',
        'ssg' => 'users'
    ];

    return $icons[$org_type] ?? 'building';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | BISU Online Clearance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --font-body: 'Manrope', 'Segoe UI', sans-serif;
            --font-display: 'Space Grotesk', 'Segoe UI', sans-serif;
            --primary: #3a2475;
            --primary-light: #6d55b6;
            --primary-dark: #27184f;
            --secondary: #52a79f;
            --accent: #d9f3f0;
            --success: #2E7D32;
            --success-light: #4CAF50;
            --warning: #F9A826;
            --danger: #C62828;
            --danger-light: #EF5350;
            --info: #1976D2;
            --info-light: #42A5F5;
            --lacking: #f97316;
            --proof: #0ea5e9;
            --bg: #f4f6fb;
            --bg-dark: #e8ebf5;
            --text: #243349;
            --text-light: #6e7f98;
            --white: #ffffff;
            --border: #dbe2ef;
            --shadow: 0 12px 30px rgba(17, 22, 35, 0.08);
            --shadow-hover: 0 18px 40px rgba(17, 22, 35, 0.14);
            --card-gradient: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 62%, var(--secondary) 100%);
        }

        .dark-mode {
            --primary: #8b6fd8;
            --primary-light: #a58bd1;
            --primary-dark: #6b4bb8;
            --secondary: #F8B195;
            --bg: #1a1a2e;
            --bg-dark: #16213e;
            --text: #e9e9e9;
            --text-light: #b8b8b8;
            --border: #2a2a4a;
            --white: #16213e;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        body {
            background: var(--bg);
            color: var(--text);
            min-height: 100dvh;
            font-family: var(--font-body);
            transition: background-color 0.3s, color 0.3s;
            background-image:
                radial-gradient(circle at 10% 15%, rgba(82, 167, 159, 0.12) 0%, transparent 30%),
                radial-gradient(circle at 85% 12%, rgba(58, 36, 117, 0.08) 0%, transparent 26%);
            -webkit-text-size-adjust: 100%;
        }

        h1,
        h2,
        h3,
        h4,
        h5 {
            font-family: var(--font-display);
            letter-spacing: -0.02em;
        }

        a:focus-visible,
        button:focus-visible,
        input:focus-visible,
        select:focus-visible,
        textarea:focus-visible {
            outline: 3px solid rgba(58, 36, 117, 0.35);
            outline-offset: 2px;
        }

        /* Modern Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--bg-dark);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--secondary);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary);
        }

        /* Header */
        .header {
            background: color-mix(in srgb, var(--white) 85%, transparent);
            box-shadow: var(--shadow);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: calc(1rem + env(safe-area-inset-top)) max(1rem, env(safe-area-inset-right)) 1rem max(1rem, env(safe-area-inset-left));
            border-bottom: 1px solid var(--border);
            backdrop-filter: blur(10px);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: min(1400px, 100%);
            margin: 0 auto;
            gap: 1rem;
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            transform: rotate(-5deg);
            transition: transform 0.3s;
            box-shadow: 0 10px 22px rgba(58, 36, 117, 0.25);
        }

        .logo-icon:hover {
            transform: rotate(0deg);
        }

        .logo-text h2 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.2rem;
        }

        .logo-text p {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 1.5rem;
            background: var(--bg-dark);
            border-radius: 50px;
            transition: all 0.3s;
            cursor: pointer;
            border: 1px solid var(--border);
        }

        .user-info:hover {
            background: var(--border);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            overflow: hidden;
        }

        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-details {
            line-height: 1.4;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--text);
        }

        .user-role {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .theme-toggle {
            position: fixed;
            bottom: calc(20px + env(safe-area-inset-bottom));
            right: calc(20px + env(safe-area-inset-right));
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 999;
            color: white;
            font-size: 1.3rem;
            box-shadow: var(--shadow);
            transition: all 0.3s;
        }

        .theme-toggle:hover {
            transform: rotate(180deg) scale(1.1);
        }

        /* Main Container */
        .main-container {
            display: flex;
            width: min(1400px, 100%);
            margin: calc(80px + env(safe-area-inset-top)) auto 0;
            padding: clamp(1rem, 2.2vw, 2rem);
            gap: 2rem;
        }

        /* Sidebar */
        .sidebar {
            width: 300px;
            background: color-mix(in srgb, var(--white) 92%, transparent);
            border-radius: 20px;
            box-shadow: var(--shadow);
            padding: 2rem 0;
            position: sticky;
            top: 100px;
            height: calc(100vh - 120px);
            overflow-y: auto;
            transition: all 0.3s;
            border: 1px solid var(--border);
        }

        .profile-section {
            text-align: center;
            padding: 0 2rem 2rem;
            border-bottom: 2px solid var(--border);
            background: linear-gradient(180deg, rgba(58, 36, 117, 0.05), transparent 65%);
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            margin: 0 auto 1.5rem;
            position: relative;
            cursor: pointer;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary);
            transition: all 0.3s;
        }

        .profile-avatar:hover img {
            transform: scale(1.05);
        }

        .avatar-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .profile-avatar:hover .avatar-overlay {
            opacity: 1;
        }

        .upload-progress {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            border-radius: 50%;
            display: none;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
        }

        .upload-progress.show {
            display: flex;
        }

        .profile-name {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.3rem;
        }

        .profile-email {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .profile-id {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-block;
        }

        .nav-menu {
            padding: 2rem;
            display: grid;
            gap: 0.5rem;
        }

        .mobile-nav-toggle {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            min-height: 46px;
            padding: 0.8rem 1rem;
            border-radius: 14px;
            border: 1px solid var(--border);
            background: var(--white);
            color: var(--text);
            font-weight: 700;
            box-shadow: var(--shadow);
            cursor: pointer;
            transition: all 0.25s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .mobile-nav-toggle i {
            color: var(--primary);
            transition: transform 0.25s ease, color 0.25s ease;
        }

        .mobile-nav-toggle:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-hover);
        }

        .mobile-nav-backdrop {
            display: none;
        }

        body.mobile-nav-open {
            overflow: hidden;
            touch-action: none;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            width: 100%;
            border: none;
            background: none;
            color: var(--text);
            font-size: 1rem;
            font-weight: 500;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 0.5rem;
            text-align: left;
            border: 1px solid transparent;
            min-height: 46px;
            white-space: nowrap;
        }

        .nav-item i {
            width: 24px;
            font-size: 1.2rem;
            color: var(--text-light);
            transition: all 0.3s;
        }

        .nav-item:hover {
            background: var(--bg-dark);
            transform: translateX(5px);
            border-color: var(--border);
        }

        .nav-item:hover i {
            color: var(--primary);
        }

        .nav-item.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            box-shadow: 0 10px 24px rgba(58, 36, 117, 0.28);
        }

        .nav-item.active i {
            color: white;
        }

        .mobile-logout-nav {
            display: flex;
            margin-top: 0.35rem;
            background: rgba(198, 40, 40, 0.1);
            border: 1px solid rgba(198, 40, 40, 0.25);
            color: var(--danger);
        }

        .mobile-logout-nav i {
            color: var(--danger);
        }

        .mobile-logout-nav:hover {
            background: var(--danger);
            color: #fff;
            border-color: transparent;
        }

        .mobile-logout-nav:hover i {
            color: #fff;
        }

        /* Content Area */
        .content-area {
            flex: 1;
            min-width: 0;
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Welcome Card */
        .welcome-card {
            background: var(--card-gradient);
            color: white;
            padding: clamp(1.4rem, 3vw, 3rem);
            border-radius: 20px;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.15);
        }

        .welcome-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        .welcome-card h1 {
            font-size: clamp(1.5rem, 3.5vw, 2.5rem);
            font-weight: 800;
            margin-bottom: 1rem;
            position: relative;
        }

        .welcome-card p {
            font-size: clamp(0.95rem, 1.8vw, 1.1rem);
            opacity: 0.96;
            position: relative;
            max-width: 600px;
        }

        .welcome-layout {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(250px, 0.9fr);
            gap: 1.5rem;
            align-items: stretch;
        }

        .welcome-copy {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .hero-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
        }

        .hero-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.55rem 0.9rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.2);
            font-size: 0.85rem;
            font-weight: 700;
            backdrop-filter: blur(8px);
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 0.25rem;
        }

        .hero-action {
            min-height: 46px;
            border: 1px solid transparent;
            border-radius: 14px;
            padding: 0.85rem 1.15rem;
            font-weight: 700;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 0.55rem;
            cursor: pointer;
            transition: transform 0.25s, box-shadow 0.25s, background-color 0.25s;
        }

        .hero-action.primary {
            background: #fff;
            color: var(--primary);
            box-shadow: 0 12px 28px rgba(16, 18, 37, 0.18);
        }

        .hero-action.secondary {
            background: rgba(255, 255, 255, 0.12);
            color: #fff;
            border-color: rgba(255, 255, 255, 0.22);
            backdrop-filter: blur(10px);
        }

        .hero-action:hover {
            transform: translateY(-2px);
        }

        .hero-panel {
            background: rgba(11, 14, 32, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 18px;
            padding: 1.2rem;
            display: grid;
            gap: 0.95rem;
            backdrop-filter: blur(12px);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
        }

        .hero-panel-label {
            opacity: 0.78;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 800;
        }

        .hero-panel-value {
            font-family: var(--font-display);
            font-size: clamp(1.2rem, 2vw, 1.85rem);
            line-height: 1.15;
            font-weight: 700;
        }

        .hero-panel-text {
            font-size: 0.95rem;
            line-height: 1.6;
            opacity: 0.92;
        }

        .hero-panel-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.8rem;
        }

        .hero-panel-stat {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 0.9rem;
            display: grid;
            gap: 0.18rem;
        }

        .hero-panel-stat strong {
            font-size: 1.15rem;
        }

        .hero-panel-stat span {
            font-size: 0.8rem;
            opacity: 0.78;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(185px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.35rem;
            border-radius: 18px;
            box-shadow: var(--shadow);
            display: grid;
            grid-template-columns: auto 1fr;
            align-items: start;
            gap: 1rem;
            transition: all 0.3s;
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }

        .dashboard-tap-card {
            cursor: pointer;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }

        .stat-card::after {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
            opacity: 0;
            transition: opacity 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .stat-card:hover::after {
            opacity: 1;
        }

        .dashboard-tap-card:active {
            transform: translateY(-1px) scale(0.99);
        }

        .dashboard-shortcut-grid {
            display: none;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.85rem;
            margin: 1rem 0 1.6rem;
        }

        .dashboard-shortcut-card {
            background:
                linear-gradient(145deg, rgba(58, 36, 117, 0.06), rgba(82, 167, 159, 0.12)),
                var(--white);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 1rem;
            display: grid;
            gap: 0.45rem;
            box-shadow: var(--shadow);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }

        .dashboard-shortcut-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
            border-color: rgba(58, 36, 117, 0.18);
        }

        .dashboard-shortcut-card i {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(58, 36, 117, 0.1);
            color: var(--primary);
            font-size: 1.05rem;
        }

        .dashboard-shortcut-card strong {
            color: var(--text);
            font-size: 0.95rem;
        }

        .dashboard-shortcut-card span {
            color: var(--text-light);
            font-size: 0.8rem;
            line-height: 1.5;
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: inset 0 -10px 20px rgba(255, 255, 255, 0.18);
        }

        .stat-icon.total {
            background: var(--primary);
            color: white;
        }

        .stat-icon.approved {
            background: var(--success);
            color: white;
        }

        .stat-icon.pending {
            background: var(--warning);
            color: white;
        }

        .stat-icon.rejected {
            background: var(--danger);
            color: white;
        }

        .stat-icon.completed {
            background: var(--info);
            color: white;
        }

        .stat-details h3 {
            font-size: clamp(1.55rem, 2.8vw, 2rem);
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.18rem;
        }

        .stat-details p {
            color: var(--text-light);
            font-size: 0.82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .dashboard-overview {
            display: grid;
            grid-template-columns: minmax(0, 1.25fr) minmax(280px, 0.75fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
            align-items: start;
        }

        .overview-stack {
            display: grid;
            gap: 1.5rem;
        }

        .profile-highlight {
            display: grid;
            gap: 1rem;
        }

        .profile-highlight-card {
            background:
                linear-gradient(155deg, rgba(58, 36, 117, 0.05), rgba(82, 167, 159, 0.08)),
                var(--white);
            border-radius: 18px;
            padding: 1.2rem;
            border: 1px solid var(--border);
        }

        .profile-highlight-card h3 {
            font-size: 1rem;
            margin-bottom: 0.35rem;
        }

        .profile-highlight-card p {
            color: var(--text-light);
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .spotlight-card {
            background:
                radial-gradient(circle at top right, rgba(82, 167, 159, 0.18), transparent 34%),
                linear-gradient(180deg, rgba(58, 36, 117, 0.05), transparent 65%),
                var(--white);
            border-radius: 20px;
            padding: 1.35rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            display: grid;
            gap: 1rem;
        }

        .spotlight-label {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-light);
            font-weight: 800;
        }

        .spotlight-title {
            font-family: var(--font-display);
            font-size: 1.35rem;
            color: var(--text);
            line-height: 1.25;
        }

        .spotlight-copy {
            color: var(--text-light);
            line-height: 1.65;
            font-size: 0.94rem;
        }

        .spotlight-metrics {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.85rem;
        }

        .spotlight-metric {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 0.85rem 0.95rem;
            display: grid;
            gap: 0.2rem;
        }

        .spotlight-metric strong {
            font-size: 1.15rem;
            color: var(--text);
        }

        .spotlight-metric span {
            font-size: 0.82rem;
            color: var(--text-light);
        }

        /* Section Card */
        .section-card {
            background: var(--white);
            border-radius: 20px;
            padding: clamp(1rem, 2vw, 2rem);
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: all 0.3s;
        }

        .section-card:hover {
            box-shadow: var(--shadow-hover);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border);
        }

        .section-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-header h2 i {
            color: var(--primary);
        }

        .edit-profile-btn {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            border: none;
            padding: 0.7rem 1.2rem;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            min-height: 44px;
        }

        .edit-profile-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(58, 36, 117, 0.28);
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        .info-item {
            padding: 1.5rem;
            background: var(--bg);
            border-radius: 12px;
            border: 1px solid var(--border);
        }

        .info-item .label {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .info-item .value {
            color: var(--text);
            font-size: 1.2rem;
            font-weight: 600;
        }

        /* Progress Steps - 5 Steps */
        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin: 2rem 0;
            position: relative;
        }

        .progress-steps::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--border);
            z-index: 1;
        }

        .step {
            position: relative;
            z-index: 2;
            text-align: center;
            flex: 1;
        }

        .step-icon {
            width: 40px;
            height: 40px;
            background: var(--white);
            border: 2px solid var(--border);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: 600;
            color: var(--text-light);
            transition: all 0.3s;
        }

        .step.completed .step-icon {
            background: var(--success);
            border-color: var(--success);
            color: white;
        }

        .step.current .step-icon {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
            transform: scale(1.1);
            box-shadow: 0 0 0 5px rgba(65, 40, 134, 0.1);
        }

        .step-label {
            font-size: 0.85rem;
            color: var(--text-light);
            font-weight: 500;
        }

        .step.completed .step-label {
            color: var(--success);
        }

        .step.current .step-label {
            color: var(--primary);
            font-weight: 600;
        }

        /* Office Cards Grid - 5 Columns */
        .offices-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1rem;
            margin: 1.5rem 0;
        }

        @media (max-width: 1200px) {
            .offices-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .offices-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .offices-grid {
                grid-template-columns: 1fr;
            }
        }

        .office-card {
            background: var(--bg);
            border-radius: 12px;
            padding: 1.2rem;
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 0.8rem;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
        }

        .office-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }

        .office-card.lacking {
            border-left: 4px solid var(--lacking);
        }

        .office-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .office-icon.approved {
            background: var(--success);
            color: white;
        }

        .office-icon.pending {
            background: var(--warning);
            color: white;
        }

        .office-icon.rejected {
            background: var(--danger);
            color: white;
        }

        .office-details {
            width: 100%;
        }

        .office-name {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 0.3rem;
            font-size: 0.95rem;
        }

        .office-status {
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            display: inline-block;
            margin-bottom: 0.3rem;
        }

        .office-status.approved {
            background: var(--success);
            color: white;
        }

        .office-status.pending {
            background: var(--warning);
            color: white;
        }

        .office-status.rejected {
            background: var(--danger);
            color: white;
        }

        .lacking-badge {
            background: var(--lacking);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            margin-top: 0.3rem;
        }

        .proof-badge {
            background: var(--proof);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
            margin-top: 0.3rem;
            max-width: 100%;
            text-align: center;
        }

        .proof-action-group {
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 0.45rem;
            width: 100%;
            margin-top: 0.5rem;
        }

        .office-remarks {
            font-size: 0.7rem;
            color: var(--text-light);
            margin-top: 0.5rem;
            word-break: break-word;
            max-width: 100%;
        }

        .office-remarks i {
            font-size: 0.6rem;
            margin-right: 0.2rem;
        }

        .office-date {
            font-size: 0.65rem;
            color: var(--text-light);
            margin-top: 0.2rem;
        }

        .lacking-comment {
            background: rgba(249, 115, 22, 0.1);
            color: var(--lacking);
            padding: 0.8rem;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-top: 0.5rem;
            border-left: 3px solid var(--lacking);
            text-align: left;
        }

        .lacking-comment i {
            margin-right: 0.5rem;
        }

        .upload-btn {
            background: var(--proof);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            margin-top: 0.5rem;
            transition: all 0.3s;
            width: 100%;
            justify-content: center;
        }

        .upload-btn:hover {
            background: var(--info);
            transform: translateY(-2px);
        }

        .view-proof-btn {
            background: var(--proof);
            color: white;
            border: none;
            padding: 0.45rem 0.8rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
            transition: all 0.3s;
            text-decoration: none;
            width: 100%;
            max-width: 100%;
            white-space: normal;
            text-align: center;
        }

        .view-proof-btn:hover {
            background: var(--info);
        }

        /* Apply Card */
        .apply-card {
            max-width: 600px;
            margin: 0 auto;
        }

        .apply-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .apply-icon {
            width: 80px;
            height: 80px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: white;
            font-size: 2rem;
        }

        .apply-header h3 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.5rem;
        }

        .apply-header p {
            color: var(--text-light);
        }

        .apply-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .clearance-type-select {
            font-weight: 600;
        }

        .clearance-type-helper {
            margin-top: 0.6rem;
            padding: 0.85rem 1rem;
            border-radius: 12px;
            border: 1px solid rgba(249, 168, 38, 0.32);
            background: rgba(249, 168, 38, 0.12);
            color: var(--text);
            font-size: 0.88rem;
            line-height: 1.5;
            display: flex;
            align-items: flex-start;
            gap: 0.55rem;
        }

        .clearance-type-helper i {
            color: var(--warning);
            margin-top: 0.1rem;
            flex-shrink: 0;
        }

        .clearance-process-flow {
            margin-top: 2rem;
            background: var(--bg);
            border-radius: 16px;
            border: 1px solid var(--border);
            padding: 1.35rem;
            display: grid;
            gap: 1rem;
        }

        .clearance-process-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
            color: var(--text);
        }

        .clearance-process-title i {
            color: var(--primary);
        }

        .clearance-step-pills {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 0.7rem;
        }

        .clearance-step-pill {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 999px;
            min-height: 44px;
            padding: 0.55rem 0.8rem;
            text-align: center;
        }

        .clearance-step-number {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
            font-weight: 700;
            flex-shrink: 0;
        }

        .clearance-step-label {
            color: var(--text);
            font-size: 0.84rem;
            font-weight: 700;
            line-height: 1.25;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group label {
            font-weight: 600;
            color: var(--text);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group label i {
            color: var(--primary);
        }

        .form-control {
            padding: 1rem;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s;
            background: var(--white);
            color: var(--text);
            min-height: 46px;
        }

        .password-field {
            position: relative;
            display: flex;
            align-items: center;
        }

        .password-field .form-control {
            width: 100%;
            padding-right: 3.75rem;
        }

        .password-toggle-btn {
            position: absolute;
            right: 0.45rem;
            top: 50%;
            transform: translateY(-50%);
            width: 38px;
            height: 38px;
            padding: 0;
            border: none;
            border-radius: 50%;
            background: transparent;
            color: var(--text-light);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            z-index: 2;
            line-height: 1;
            -webkit-appearance: none;
            appearance: none;
        }

        .password-toggle-btn i {
            pointer-events: none;
        }

        .password-toggle-btn:hover {
            background: var(--bg-dark);
            color: var(--primary);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(65, 40, 134, 0.1);
        }

        .apply-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 1.2rem;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .apply-btn:hover {
            background: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .cancel-btn {
            background: var(--danger);
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .cancel-btn:hover {
            background: var(--danger-light);
            transform: translateY(-2px);
        }

        /* Pending Banner */
        .pending-banner {
            background: linear-gradient(135deg, var(--warning) 0%, #FBC02D 100%);
            color: white;
            padding: 3rem;
            border-radius: 20px;
            text-align: center;
        }

        .pending-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
        }

        .pending-banner h3 {
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        .pending-banner p {
            margin-bottom: 2rem;
            opacity: 0.9;
        }

        .pending-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: white;
            color: var(--warning);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid white;
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        /* Timeline */
        .timeline-item {
            background: var(--white);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border);
            transition: all 0.3s;
            position: relative;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            border-radius: 20px 0 0 20px;
            background: linear-gradient(180deg, var(--primary), var(--secondary));
        }

        .timeline-item:hover {
            box-shadow: var(--shadow);
        }

        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .timeline-title {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .timeline-title h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text);
        }

        .timeline-badge {
            padding: 0.4rem 1.2rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .badge-approved {
            background: var(--success);
            color: white;
        }

        .badge-pending {
            background: var(--warning);
            color: white;
        }

        .badge-rejected {
            background: var(--danger);
            color: white;
        }

        .timeline-date {
            color: var(--text-light);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .history-summary {
            cursor: pointer;
            border-radius: 14px;
            padding: 0.9rem 1rem;
            margin: -0.8rem -0.8rem 0;
            transition: background-color 0.2s ease;
        }

        .history-summary:hover {
            background: var(--bg);
        }

        .history-summary-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-left: auto;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .history-toggle-btn {
            min-height: 40px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--white);
            color: var(--text);
            font-weight: 700;
            font-size: 0.82rem;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .history-toggle-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .history-toggle-icon {
            transition: transform 0.2s ease;
        }

        .timeline-item.history-open .history-toggle-icon {
            transform: rotate(180deg);
        }

        .timeline-item.history-open .history-summary {
            background: color-mix(in srgb, var(--primary) 10%, var(--bg));
        }

        .history-details-panel {
            margin-top: 1rem;
        }

        .history-details-panel[hidden] {
            display: none !important;
        }

        .dark-mode .history-summary:hover {
            background: rgba(139, 111, 216, 0.12);
        }

        .dark-mode .timeline-item.history-open .history-summary {
            background: rgba(139, 111, 216, 0.16);
        }

        .dark-mode .history-toggle-btn {
            background: var(--bg-dark);
            color: var(--text);
            border-color: var(--border);
        }

        @media (max-width: 768px) {
            .history-summary {
                margin: -0.5rem -0.5rem 0;
                padding: 0.75rem;
            }

            .history-summary-actions {
                width: 100%;
                margin-left: 0;
                justify-content: space-between;
            }
        }

        /* Filter Bar */
        .filter-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            background: var(--bg);
            padding: 1rem;
            border-radius: 50px;
            border: 1px solid var(--border);
        }

        .filter-select {
            padding: 0.8rem 1.5rem;
            border: 2px solid var(--border);
            border-radius: 50px;
            background: var(--white);
            color: var(--text);
            font-size: 16px;
            cursor: pointer;
            min-width: 150px;
            min-height: 44px;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .filter-btn {
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 50px;
            background: var(--primary);
            color: white;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
        }

        .filter-btn:hover {
            background: var(--primary-light);
            transform: translateY(-2px);
        }

        .clear-filter {
            padding: 0.8rem 2rem;
            border: 2px solid var(--danger);
            border-radius: 50px;
            background: transparent;
            color: var(--danger);
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
        }

        .clear-filter:hover {
            background: var(--danger);
            color: white;
        }

        .nav-badge {
            margin-left: auto;
            min-width: 22px;
            height: 22px;
            border-radius: 999px;
            background: var(--danger);
            color: white;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
        }

        .nav-badge.is-hidden {
            display: none;
        }

        /* Messenger-style messaging UI */
        .messenger-shell {
            --messenger-accent: #0084ff;
            display: grid;
            grid-template-columns: 340px minmax(0, 1fr);
            min-height: 680px;
            background: var(--white);
            border-radius: 20px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .messenger-shell,
        .messenger-sidebar,
        .messenger-chat-panel {
            min-width: 0;
        }

        .messenger-sidebar {
            display: grid;
            grid-template-rows: auto auto minmax(0, 1fr);
            border-right: 1px solid var(--border);
            background: linear-gradient(180deg, #f7fbff 0%, #f6f8fe 35%, var(--white) 100%);
            min-width: 0;
        }

        .messenger-sidebar-top {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
            display: grid;
            gap: 0.85rem;
        }

        .messenger-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .messenger-title-icon {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            background: linear-gradient(145deg, #17a8ff, #0572ff);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            box-shadow: 0 10px 20px rgba(0, 132, 255, 0.25);
        }

        .messenger-title h2 {
            font-size: 1.18rem;
            color: var(--text);
            margin: 0;
        }

        .messenger-title p {
            margin: 0;
            color: var(--text-light);
            font-size: 0.82rem;
        }

        .messenger-add-friend-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 0.55rem;
        }

        .messenger-add-input {
            min-height: 42px;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 0.65rem 0.8rem;
            font-size: 0.9rem;
            background: var(--white);
            color: var(--text);
        }

        .messenger-add-input:focus {
            outline: none;
            border-color: var(--messenger-accent);
            box-shadow: 0 0 0 3px rgba(0, 132, 255, 0.15);
        }

        .messenger-add-btn {
            min-height: 42px;
            min-width: 42px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(145deg, #1098ff, #006fff);
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .messenger-add-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(0, 132, 255, 0.25);
        }

        .messenger-request-wrap {
            border-bottom: 1px solid var(--border);
            padding: 0.8rem 1rem;
            display: grid;
            gap: 0.55rem;
        }

        .messenger-request-heading {
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-light);
            font-weight: 800;
        }

        .messenger-request-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.6rem;
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 0.55rem 0.6rem;
        }

        .messenger-request-main {
            min-width: 0;
        }

        .messenger-request-main strong {
            display: block;
            font-size: 0.84rem;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .messenger-request-meta {
            font-size: 0.72rem;
            color: var(--text-light);
        }

        .messenger-request-btn {
            min-height: 34px;
            padding: 0.35rem 0.75rem;
            border: none;
            border-radius: 999px;
            background: #e8f2ff;
            color: #006de4;
            font-weight: 700;
            cursor: pointer;
            font-size: 0.75rem;
            white-space: nowrap;
        }

        .messenger-request-btn:hover {
            background: #d9e9ff;
        }

        .messenger-conversation-list {
            overflow-y: auto;
            padding: 0.55rem;
            display: grid;
            gap: 0.32rem;
            min-height: 0;
        }

        .messenger-conversation-item {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            gap: 0.68rem;
            align-items: center;
            padding: 0.62rem;
            border-radius: 14px;
            text-decoration: none;
            transition: background-color 0.2s ease, transform 0.2s ease;
            color: inherit;
        }

        .messenger-conversation-item:hover {
            background: #eef5ff;
            transform: translateY(-1px);
        }

        .messenger-conversation-item.active {
            background: linear-gradient(145deg, rgba(0, 132, 255, 0.16), rgba(0, 132, 255, 0.08));
            box-shadow: inset 0 0 0 1px rgba(0, 132, 255, 0.22);
        }

        .messenger-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(145deg, #8ec7ff, #4a92ff);
            color: #fff;
            font-size: 0.88rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(37, 88, 163, 0.2);
        }

        .messenger-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .messenger-conversation-main {
            min-width: 0;
            display: grid;
            gap: 0.15rem;
        }

        .messenger-conversation-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }

        .messenger-conversation-name {
            font-size: 0.88rem;
            font-weight: 700;
            color: var(--text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .messenger-conversation-time {
            font-size: 0.72rem;
            color: var(--text-light);
            white-space: nowrap;
        }

        .messenger-conversation-preview {
            font-size: 0.78rem;
            color: var(--text-light);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .messenger-chat-panel {
            display: grid;
            grid-template-rows: auto minmax(0, 1fr) auto;
            min-width: 0;
            background: var(--white);
        }

        .messenger-chat-header {
            border-bottom: 1px solid var(--border);
            padding: 0.9rem 1.1rem;
            background: linear-gradient(180deg, #ffffff, #f7fbff);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.65rem;
        }

        .messenger-mobile-back {
            width: 38px;
            height: 38px;
            border: 1px solid var(--border);
            border-radius: 11px;
            background: #fff;
            color: var(--primary);
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .messenger-mobile-back:hover {
            background: #edf4ff;
            border-color: #cfe2ff;
        }

        .messenger-chat-user {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            min-width: 0;
            flex: 1;
        }

        .messenger-chat-user>div {
            min-width: 0;
        }

        .messenger-chat-status {
            font-size: 0.78rem;
            color: var(--text-light);
            max-width: 100%;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.45rem;
            line-height: 1.35;
        }

        .messenger-chat-status-extra {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .messenger-presence-line {
            display: inline-flex;
            align-items: center;
            gap: 0.32rem;
            font-size: 0.72rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .messenger-presence-line.online {
            color: #0a8f4f;
        }

        .messenger-presence-line.offline {
            color: #71839f;
        }

        .messenger-presence-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            display: inline-block;
            flex-shrink: 0;
            background: #9caabf;
        }

        .messenger-avatar > .messenger-presence-dot {
            position: absolute;
            right: 1px;
            bottom: 1px;
            width: 11px;
            height: 11px;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(15, 23, 42, 0.22);
        }

        .messenger-presence-dot.online {
            background: #22c55e;
        }

        .messenger-presence-dot.offline {
            background: #94a3b8;
        }

        .messenger-nickname-wrap {
            margin-left: auto;
            position: relative;
            display: grid;
            gap: 0.4rem;
            width: min(100%, 320px);
        }

        .messenger-nickname-toggle-btn {
            min-height: 36px;
            border: 1px solid #cfe2ff;
            border-radius: 10px;
            padding: 0.45rem 0.7rem;
            background: #eef5ff;
            color: #1f5fbe;
            font-weight: 700;
            font-size: 0.78rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
        }

        .messenger-nickname-toggle-btn:hover {
            background: #e1edff;
        }

        .messenger-nickname-panel {
            display: none;
            position: absolute;
            top: calc(100% + 4px);
            right: 0;
            width: min(100%, 320px);
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            padding: 0.55rem;
            box-shadow: 0 14px 24px rgba(15, 23, 42, 0.14);
            z-index: 20;
        }

        .messenger-nickname-panel.show {
            display: grid;
            gap: 0.45rem;
        }

        .messenger-nickname-hint {
            font-size: 0.72rem;
            color: var(--text-light);
        }

        .messenger-nickname-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 0.45rem;
        }

        .messenger-nickname-input {
            flex: 1;
            min-height: 36px;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 0.45rem 0.65rem;
            font-size: 0.82rem;
            background: #fff;
            color: var(--text);
        }

        .messenger-nickname-input:focus {
            outline: none;
            border-color: var(--messenger-accent);
            box-shadow: 0 0 0 3px rgba(0, 132, 255, 0.14);
        }

        .messenger-nickname-btn {
            min-height: 36px;
            border: none;
            border-radius: 10px;
            padding: 0.45rem 0.7rem;
            background: #e8f2ff;
            color: #006de4;
            font-weight: 700;
            font-size: 0.78rem;
            cursor: pointer;
            white-space: nowrap;
        }

        .messenger-nickname-btn:hover {
            background: #d9e9ff;
        }

        .messenger-chat-thread {
            overflow-y: auto;
            padding: 1.1rem;
            display: grid;
            gap: 0.4rem;
            background:
                radial-gradient(circle at 18% 10%, rgba(0, 132, 255, 0.06), transparent 28%),
                radial-gradient(circle at 85% 84%, rgba(58, 36, 117, 0.06), transparent 30%),
                #f7f9ff;
        }

        .messenger-bubble-row {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.18rem;
            min-width: 0;
        }

        .messenger-bubble-row.mine {
            align-items: flex-end;
        }

        .messenger-bubble {
            display: inline-block;
            width: auto;
            width: fit-content;
            max-width: min(54%, 420px);
            min-width: 0;
            padding: 0.5rem 0.74rem;
            border-radius: 16px;
            font-size: 0.83rem;
            line-height: 1.35;
            word-break: break-word;
            overflow-wrap: anywhere;
            box-shadow: 0 6px 14px rgba(13, 21, 42, 0.08);
        }

        .messenger-bubble-row.theirs .messenger-bubble {
            background: #ffffff;
            border: 1px solid #d9e6ff;
            border-bottom-left-radius: 6px;
            color: var(--text);
        }

        .messenger-bubble-row.mine .messenger-bubble {
            background: linear-gradient(145deg, #1498ff, #006fff 65%);
            color: #ffffff;
            border-bottom-right-radius: 6px;
            max-width: min(50%, 390px);
        }

        .messenger-bubble-meta {
            font-size: 0.71rem;
            color: var(--text-light);
            padding: 0 0.25rem;
        }

        .messenger-reply-quote {
            display: grid;
            gap: 0.1rem;
            margin-bottom: 0.35rem;
            padding: 0.42rem 0.55rem;
            border-left: 3px solid rgba(255, 255, 255, 0.45);
            background: rgba(10, 25, 53, 0.18);
            border-radius: 8px;
        }

        .messenger-bubble-row.theirs .messenger-reply-quote {
            border-left-color: rgba(0, 132, 255, 0.35);
            background: rgba(0, 132, 255, 0.08);
        }

        .messenger-reply-quote strong {
            font-size: 0.7rem;
            font-weight: 800;
            opacity: 0.9;
        }

        .messenger-reply-quote span {
            font-size: 0.78rem;
            line-height: 1.35;
            opacity: 0.92;
            word-break: break-word;
        }

        .messenger-message-text {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            max-width: 100%;
        }

        .messenger-attachment-wrap {
            margin-top: 0.45rem;
        }

        .messenger-attachment-link {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.6rem;
            border-radius: 10px;
            text-decoration: none;
            font-size: 0.8rem;
            font-weight: 700;
            border: 1px solid rgba(0, 132, 255, 0.25);
            background: rgba(0, 132, 255, 0.08);
            color: #1a4f9f;
            max-width: 100%;
            word-break: break-word;
        }

        .messenger-bubble-row.mine .messenger-attachment-link {
            border-color: rgba(255, 255, 255, 0.35);
            background: rgba(255, 255, 255, 0.16);
            color: #fff;
        }

        .messenger-attachment-image {
            display: block;
            max-width: min(100%, 280px);
            max-height: 220px;
            border-radius: 12px;
            border: 1px solid rgba(15, 23, 42, 0.12);
            object-fit: cover;
        }

        .messenger-attachment-audio {
            width: min(100%, 280px);
            height: 38px;
        }

        .messenger-message-actions {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            flex-wrap: wrap;
            position: relative;
        }

        .messenger-bubble-row.mine .messenger-message-actions {
            justify-content: flex-end;
        }

        .messenger-reply-btn {
            border: none;
            border-radius: 999px;
            background: #e7efff;
            color: #1a59c2;
            font-size: 0.74rem;
            font-weight: 700;
            padding: 0.28rem 0.62rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .messenger-reply-btn:hover {
            background: #d8e6ff;
        }

        .messenger-react-toggle-btn {
            border: none;
            border-radius: 999px;
            background: #eaf3ff;
            color: #1d5ec0;
            font-size: 0.74rem;
            font-weight: 700;
            padding: 0.28rem 0.62rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        .messenger-react-toggle-btn:hover {
            background: #dbeaff;
        }

        .messenger-reaction-picker {
            display: none;
            position: absolute;
            top: auto;
            bottom: calc(100% + 8px);
            left: 0;
            background: #fff;
            border: 1px solid #cfe2ff;
            border-radius: 14px;
            padding: 0.45rem;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.14);
            z-index: 25;
            min-width: 220px;
            max-width: min(260px, calc(100vw - 2rem));
        }

        .messenger-bubble-row.mine .messenger-reaction-picker {
            left: auto;
            right: 0;
        }

        .messenger-reaction-picker.show {
            display: block;
        }

        .messenger-reaction-form {
            margin: 0;
        }

        .messenger-delete-message-form {
            margin-top: 0.5rem;
            padding-top: 0.45rem;
            border-top: 1px solid #e6eefb;
        }

        .messenger-delete-message-btn {
            width: 100%;
            border: 1px solid rgba(198, 40, 40, 0.35);
            background: #fff5f5;
            color: #b42323;
            border-radius: 10px;
            min-height: 32px;
            padding: 0.3rem 0.55rem;
            font-size: 0.74rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.3rem;
        }

        .messenger-delete-message-btn:hover {
            background: #ffe8e8;
            border-color: rgba(198, 40, 40, 0.52);
        }

        .messenger-reaction-list {
            display: flex;
            align-items: center;
            gap: 0.26rem;
            flex-wrap: wrap;
            width: 100%;
        }

        .messenger-reaction-btn {
            border: 1px solid #d4e3ff;
            background: #f6faff;
            color: #2b4b84;
            border-radius: 999px;
            min-height: 30px;
            padding: 0.14rem 0.44rem;
            display: inline-flex;
            align-items: center;
            gap: 0.24rem;
            font-size: 0.82rem;
            cursor: pointer;
        }

        .messenger-reaction-btn small {
            font-size: 0.68rem;
            font-weight: 800;
            color: #315ca6;
            min-width: 12px;
            text-align: center;
        }

        .messenger-reaction-btn.active {
            border-color: #2891ff;
            background: #e4f1ff;
            box-shadow: inset 0 0 0 1px rgba(40, 145, 255, 0.2);
        }

        .messenger-reaction-summary {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            flex-wrap: wrap;
        }

        .messenger-reaction-chip {
            border: 1px solid #d3e3ff;
            background: #f8fbff;
            border-radius: 999px;
            padding: 0.1rem 0.36rem;
            display: inline-flex;
            align-items: center;
            gap: 0.2rem;
            font-size: 0.74rem;
            color: #25529a;
            font-weight: 700;
        }

        .messenger-reaction-chip.active {
            border-color: #2891ff;
            background: #e7f2ff;
        }

        .messenger-reaction-chip small {
            font-size: 0.66rem;
            font-weight: 800;
            color: #2e5ca8;
        }

        @media (max-width: 768px) {
            .messenger-nickname-wrap {
                margin-left: 0;
                width: 100%;
            }

            .messenger-nickname-panel {
                position: static;
                width: 100%;
                box-shadow: none;
                border-radius: 10px;
            }

            .messenger-reaction-picker {
                position: static;
                bottom: auto;
                margin-top: 0.2rem;
                border-radius: 12px;
                min-width: 0;
                max-width: 100%;
                width: 100%;
                padding: 0.42rem;
            }
        }

        .messenger-reply-preview {
            display: none;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.6rem;
            border: 1px solid #cfe2ff;
            background: #f4f9ff;
            border-radius: 12px;
            padding: 0.55rem 0.62rem;
        }

        .messenger-reply-preview.show {
            display: flex;
        }

        .messenger-reply-preview-main {
            min-width: 0;
            display: grid;
            gap: 0.14rem;
        }

        .messenger-reply-preview-main strong {
            color: #1f59b8;
            font-size: 0.76rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .messenger-reply-preview-main span {
            color: var(--text-light);
            font-size: 0.77rem;
            line-height: 1.35;
            word-break: break-word;
        }

        .messenger-reply-clear {
            border: none;
            border-radius: 8px;
            width: 28px;
            height: 28px;
            flex-shrink: 0;
            background: #e5eeff;
            color: #335ca1;
            cursor: pointer;
            font-size: 1rem;
            line-height: 1;
        }

        .messenger-reply-clear:hover {
            background: #d6e5ff;
        }

        .messenger-composer {
            border-top: 1px solid var(--border);
            padding: 0.9rem;
            background: #ffffff;
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 0.6rem;
        }

        .messenger-composer > * {
            min-width: 0;
        }

        .messenger-input-row {
            position: relative;
            display: block;
        }

        .messenger-composer textarea {
            width: 100%;
            min-height: 48px;
            max-height: 140px;
            resize: vertical;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 0.72rem 3.5rem 0.72rem 0.9rem;
            font-size: 0.92rem;
            color: var(--text);
            background: #fff;
        }

        .messenger-composer textarea:focus {
            outline: none;
            border-color: var(--messenger-accent);
            box-shadow: 0 0 0 3px rgba(0, 132, 255, 0.15);
        }

        .messenger-attachment-picker {
            display: grid;
            gap: 0.32rem;
        }

        .messenger-attachment-input {
            position: absolute;
            width: 1px;
            height: 1px;
            opacity: 0;
            pointer-events: none;
        }

        .messenger-attachment-controls {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            min-height: 40px;
            min-width: 0;
            max-width: 100%;
        }

        .messenger-attachment-trigger-group {
            display: flex;
            align-items: center;
            gap: 0.42rem;
            flex-shrink: 0;
        }

        .messenger-attach-trigger {
            width: 40px;
            height: 40px;
            border: none;
            border-radius: 50%;
            background: #e7f3ff;
            color: #0b74ff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
            flex-shrink: 0;
        }

        .messenger-attach-trigger i {
            font-size: 1rem;
            transform: rotate(-20deg);
        }

        .messenger-attach-image-trigger {
            background: #e9f8ef;
            color: #138a4e;
        }

        .messenger-attach-image-trigger i {
            font-size: 0.96rem;
            transform: none;
        }

        .messenger-attach-trigger:hover {
            background: #d9ebff;
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(8, 110, 255, 0.22);
        }

        .messenger-attach-image-trigger:hover {
            background: #d8f1e3;
            box-shadow: 0 8px 18px rgba(19, 138, 78, 0.2);
        }

        .messenger-attach-trigger:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px rgba(47, 134, 255, 0.24);
        }

        .messenger-attachment-selected {
            flex: 1 1 auto;
            min-width: 0;
            max-width: 100%;
            border: 1px solid #d5e6ff;
            background: #f4f8ff;
            color: #2f4f7b;
            border-radius: 999px;
            padding: 0.32rem 0.5rem;
            display: none;
            align-items: center;
            gap: 0.42rem;
            font-size: 0.74rem;
            line-height: 1.2;
            overflow: hidden;
        }

        .messenger-attachment-selected.show {
            display: inline-flex;
        }

        .messenger-attachment-selected.is-error {
            border-color: rgba(198, 40, 40, 0.5);
            background: #fff2f2;
            color: #aa2c2c;
        }

        .messenger-attachment-selected i {
            font-size: 0.72rem;
            flex-shrink: 0;
        }

        .messenger-attachment-selected span {
            min-width: 0;
            max-width: 260px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 700;
        }

        .messenger-attachment-clear {
            border: none;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: rgba(32, 74, 138, 0.12);
            color: inherit;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            transition: background-color 0.2s ease;
        }

        .messenger-attachment-clear:hover {
            background: rgba(32, 74, 138, 0.2);
        }

        .messenger-attachment-hint {
            font-size: 0.72rem;
            color: var(--text-light);
        }

        .messenger-composer-actions {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: 0.7rem;
            flex-wrap: wrap;
        }

        .messenger-empty-chat-note {
            font-size: 0.75rem;
            color: var(--text-light);
            min-width: 0;
        }

        .messenger-send-btn {
            min-height: 40px;
            padding: 0.55rem 1.05rem;
            border: none;
            border-radius: 12px;
            background: linear-gradient(145deg, #1098ff, #006fff);
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            flex-shrink: 0;
        }

        .messenger-input-row .messenger-send-btn {
            position: absolute;
            right: 0.46rem;
            top: 50%;
            transform: translateY(-50%);
            width: 38px;
            min-width: 38px;
            height: 38px;
            min-height: 38px;
            padding: 0;
            border-radius: 50%;
            justify-content: center;
            gap: 0;
            z-index: 2;
        }

        .messenger-input-row .messenger-send-btn i {
            font-size: 0.9rem;
            margin-left: 1px;
        }

        .messenger-send-inline-label {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .messenger-send-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 22px rgba(0, 132, 255, 0.25);
        }

        .messenger-input-row .messenger-send-btn:hover {
            transform: translateY(calc(-50% - 1px));
        }

        .messenger-chat-empty {
            height: 100%;
            display: grid;
            place-items: center;
            text-align: center;
            color: var(--text-light);
            padding: 2rem;
        }

        .messenger-chat-empty i {
            font-size: 2rem;
            color: #80b4ff;
            margin-bottom: 0.6rem;
        }

        .dark-mode .messenger-shell {
            background: #101a2f;
            border-color: #2b3d60;
            box-shadow: 0 20px 34px rgba(0, 0, 0, 0.38);
        }

        .dark-mode .messenger-sidebar {
            background: linear-gradient(180deg, #111d35 0%, #0f1a31 35%, #101a2f 100%);
            border-right-color: #2b3d60;
        }

        .dark-mode .messenger-sidebar-top,
        .dark-mode .messenger-request-wrap,
        .dark-mode .messenger-chat-header,
        .dark-mode .messenger-composer {
            border-color: #2b3d60;
        }

        .dark-mode .messenger-chat-panel,
        .dark-mode .messenger-composer {
            background: #101a2f;
        }

        .dark-mode .messenger-chat-header {
            background: linear-gradient(180deg, #131f39, #101a2f);
        }

        .dark-mode .messenger-conversation-item:hover {
            background: #1a2948;
        }

        .dark-mode .messenger-conversation-item.active {
            background: linear-gradient(145deg, rgba(74, 149, 255, 0.34), rgba(38, 93, 186, 0.24));
            box-shadow: inset 0 0 0 1px rgba(156, 201, 255, 0.4);
        }

        .dark-mode .messenger-title p,
        .dark-mode .messenger-request-meta,
        .dark-mode .messenger-conversation-time,
        .dark-mode .messenger-conversation-preview,
        .dark-mode .messenger-bubble-meta,
        .dark-mode .messenger-empty-chat-note,
        .dark-mode .messenger-attachment-hint,
        .dark-mode .messenger-chat-status-extra {
            color: #9fb2d4;
        }

        .dark-mode .messenger-avatar {
            background: linear-gradient(145deg, #3f6bad, #2f538e);
            border-color: rgba(177, 205, 255, 0.28);
        }

        .dark-mode .messenger-avatar > .messenger-presence-dot {
            border-color: #101a2f;
            box-shadow: 0 0 0 2px rgba(14, 23, 42, 0.85);
        }

        .dark-mode .messenger-presence-line.online {
            color: #65e194;
        }

        .dark-mode .messenger-presence-line.offline {
            color: #aeb8c8;
        }

        .dark-mode .messenger-presence-dot.online {
            background: #32d374;
        }

        .dark-mode .messenger-presence-dot.offline {
            background: #7d8698;
        }

        .dark-mode .messenger-chat-thread {
            background:
                radial-gradient(circle at 20% 14%, rgba(44, 110, 215, 0.16), transparent 30%),
                radial-gradient(circle at 80% 86%, rgba(101, 132, 199, 0.14), transparent 30%),
                #0e162a;
        }

        .dark-mode .messenger-bubble-row.theirs .messenger-bubble {
            background: #1a2744;
            border-color: #324f81;
            color: #e8efff;
        }

        .dark-mode .messenger-reply-quote {
            background: rgba(4, 18, 42, 0.42);
        }

        .dark-mode .messenger-bubble-row.theirs .messenger-reply-quote {
            background: rgba(63, 122, 218, 0.2);
            border-left-color: rgba(141, 194, 255, 0.52);
        }

        .dark-mode .messenger-reply-btn,
        .dark-mode .messenger-react-toggle-btn,
        .dark-mode .messenger-mobile-back,
        .dark-mode .messenger-request-btn,
        .dark-mode .messenger-nickname-btn {
            background: #1c2f52;
            color: #cfe0ff;
            border-color: rgba(129, 167, 230, 0.3);
        }

        .dark-mode .messenger-reply-btn:hover,
        .dark-mode .messenger-react-toggle-btn:hover,
        .dark-mode .messenger-mobile-back:hover,
        .dark-mode .messenger-request-btn:hover,
        .dark-mode .messenger-nickname-btn:hover {
            background: #24406d;
        }

        .dark-mode .messenger-nickname-panel,
        .dark-mode .messenger-reaction-picker {
            background: #13213d;
            border-color: #355789;
            box-shadow: 0 14px 24px rgba(3, 10, 20, 0.46);
        }

        .dark-mode .messenger-reaction-btn,
        .dark-mode .messenger-reaction-chip {
            border-color: rgba(120, 161, 230, 0.42);
            background: rgba(34, 54, 90, 0.75);
            color: #d4e4ff;
        }

        .dark-mode .messenger-reaction-btn.active,
        .dark-mode .messenger-reaction-chip.active {
            background: rgba(58, 111, 203, 0.5);
            border-color: rgba(145, 187, 255, 0.72);
        }

        .dark-mode .messenger-composer textarea,
        .dark-mode .messenger-add-input,
        .dark-mode .messenger-nickname-input {
            background: #0f1c34;
            border-color: #335381;
            color: #ecf2ff;
        }

        .dark-mode .messenger-add-input::placeholder,
        .dark-mode .messenger-nickname-input::placeholder,
        .dark-mode .messenger-composer textarea::placeholder {
            color: #8fa5ca;
        }

        .dark-mode .messenger-attach-trigger {
            background: #163056;
            color: #a9cdff;
        }

        .dark-mode .messenger-attach-trigger:hover {
            background: #1b3964;
        }

        .dark-mode .messenger-attach-image-trigger {
            background: #1a3f35;
            color: #a9eac9;
        }

        .dark-mode .messenger-attach-image-trigger:hover {
            background: #235041;
        }

        .dark-mode .messenger-attachment-selected {
            border-color: #385685;
            background: #16294b;
            color: #cfe3ff;
        }

        .dark-mode .messenger-attachment-selected.is-error {
            border-color: rgba(239, 83, 80, 0.55);
            background: rgba(160, 42, 42, 0.34);
            color: #ffd9d9;
        }

        .dark-mode .messenger-attachment-clear {
            background: rgba(152, 186, 238, 0.18);
        }

        .dark-mode .messenger-attachment-clear:hover {
            background: rgba(152, 186, 238, 0.3);
        }

        /* Summary Stats */
        .summary-stats {
            display: flex;
            gap: 2rem;
            margin-top: 1.5rem;
            padding: 1rem;
            background: var(--bg);
            border-radius: 12px;
            flex-wrap: wrap;
        }

        .summary-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .summary-item i {
            font-size: 1.2rem;
        }

        .summary-item span {
            color: var(--text);
            font-weight: 500;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--white);
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: var(--shadow-hover);
            border: 1px solid var(--border);
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation: none !important;
                transition: none !important;
            }
        }

        .modal-content.large {
            max-width: 700px;
        }

        .guide-modal-content {
            max-width: 680px;
        }

        .guide-step-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            background: rgba(58, 36, 117, 0.1);
            color: var(--primary);
            font-size: 0.82rem;
            font-weight: 700;
            margin-bottom: 0.7rem;
        }

        .guide-title {
            font-size: 1.45rem;
            color: var(--text);
            margin-bottom: 0.65rem;
        }

        .guide-description {
            color: var(--text-light);
            line-height: 1.7;
        }

        .guide-points {
            margin-top: 1rem;
            display: grid;
            gap: 0.65rem;
        }

        .guide-point {
            display: flex;
            gap: 0.55rem;
            align-items: flex-start;
            color: var(--text);
            font-size: 0.92rem;
            line-height: 1.5;
        }

        .guide-point i {
            color: var(--primary);
            margin-top: 0.15rem;
        }

        .guide-progress {
            display: flex;
            gap: 0.5rem;
            margin-top: 1.15rem;
            flex-wrap: wrap;
        }

        .guide-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--border);
        }

        .guide-dot.active {
            background: var(--primary);
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 2px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: var(--white);
            z-index: 10;
        }

        .modal-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text);
        }

        .modal-header h3 i {
            color: var(--primary);
        }

        .close {
            width: 35px;
            height: 35px;
            background: var(--bg);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            font-size: 1.2rem;
        }

        .close:hover {
            background: var(--danger);
            color: white;
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 2px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            position: sticky;
            bottom: 0;
            background: var(--white);
        }

        .btn-secondary {
            background: var(--bg);
            color: var(--text);
            border: 1px solid var(--border);
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-secondary:hover {
            background: var(--border);
        }

        .btn-proof-upload {
            background: var(--proof);
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
        }

        .btn-proof-upload:hover {
            background: var(--info);
            transform: translateY(-2px);
        }

        .btn,
        .apply-btn,
        .cancel-btn,
        .filter-btn,
        .clear-filter,
        .btn-secondary,
        .btn-proof-upload,
        .upload-btn,
        .view-proof-btn {
            min-height: 44px;
        }

        /* Toast */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 2rem;
            border-radius: 50px;
            color: white;
            font-weight: 500;
            z-index: 10000;
            animation: slideIn 0.3s ease;
            box-shadow: var(--shadow);
        }

        .toast.success {
            background: var(--success);
        }

        .toast.error {
            background: var(--danger);
        }

        .toast.info {
            background: var(--info);
        }

        .graduating-alert-popup {
            width: min(92vw, 420px);
            border-radius: 16px;
            padding: 1rem 0.95rem 0.9rem;
        }

        .graduating-alert-popup .swal2-icon {
            margin: 0.35rem auto 0.65rem;
        }

        .graduating-alert-title {
            font-size: 1.08rem;
            line-height: 1.25;
            margin-bottom: 0.45rem;
        }

        .graduating-alert-text {
            font-size: 0.9rem;
            line-height: 1.5;
            margin: 0;
        }

        .graduating-alert-confirm {
            min-height: 38px;
            border: none;
            border-radius: 10px;
            padding: 0.52rem 0.95rem;
            background: var(--primary);
            color: #fff;
            font-size: 0.84rem;
            font-weight: 700;
            cursor: pointer;
        }

        .graduating-alert-confirm:hover {
            background: var(--primary-light);
        }

        .conversation-delete-alert-popup {
            width: min(92vw, 420px);
            border-radius: 16px;
            padding: 1rem 0.95rem 0.9rem;
        }

        .conversation-delete-alert-container.swal2-container {
            z-index: 1700;
        }

        .conversation-delete-alert-title {
            font-size: 1.04rem;
            line-height: 1.24;
            margin-bottom: 0.4rem;
        }

        .conversation-delete-alert-text {
            font-size: 0.89rem;
            line-height: 1.45;
            margin: 0;
            word-break: break-word;
        }

        .conversation-delete-alert-popup .swal2-icon {
            margin: 0.3rem auto 0.65rem;
            transform-origin: center;
        }

        .conversation-delete-alert-popup .swal2-actions {
            width: 100%;
            margin: 0.9rem 0 0;
            gap: 0.55rem;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .conversation-delete-alert-confirm,
        .conversation-delete-alert-cancel {
            width: 100%;
            min-height: 38px;
            border-radius: 10px;
            padding: 0.5rem 0.9rem;
            font-size: 0.83rem;
            font-weight: 700;
            border: 1px solid transparent;
            margin: 0;
            cursor: pointer;
        }

        .conversation-delete-alert-confirm {
            background: var(--danger);
            color: #fff;
        }

        .conversation-delete-alert-confirm:hover {
            background: var(--danger-light);
        }

        .conversation-delete-alert-cancel {
            background: var(--bg);
            color: var(--text);
            border-color: var(--border);
        }

        .conversation-delete-alert-cancel:hover {
            background: var(--bg-dark);
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }

        .empty-state i {
            font-size: 5rem;
            color: var(--text-light);
            margin-bottom: 1.5rem;
            opacity: 0.3;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            color: var(--text);
            margin-bottom: 1rem;
        }

        .empty-state p {
            color: var(--text-light);
            margin-bottom: 2rem;
        }

        .track-status-empty-state .track-status-apply-btn {
            padding: 0.46rem 0.9rem;
            min-height: 32px;
            font-size: 0.78rem;
            border-radius: 12px;
            gap: 0.35rem;
        }

        /* File Info */
        .file-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: var(--bg);
            border-radius: 8px;
            margin-top: 0.5rem;
        }

        .file-info i {
            font-size: 1.2rem;
            color: var(--proof);
        }

        .file-info a {
            color: var(--proof);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.85rem;
        }

        .file-info a:hover {
            text-decoration: underline;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .welcome-layout,
            .dashboard-overview {
                grid-template-columns: 1fr;
            }

            .clearance-step-pills {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .messenger-shell {
                grid-template-columns: 300px minmax(0, 1fr);
                min-height: 620px;
            }

            .main-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                position: static;
                height: auto;
                padding: 0;
            }

            .profile-section {
                padding: 1rem;
                border-bottom: 1px solid var(--border);
            }

            .nav-menu {
                display: flex;
                gap: 0.55rem;
                overflow-x: auto;
                overflow-y: hidden;
                padding: 0.85rem;
                scroll-snap-type: x proximity;
            }

            .nav-item {
                flex: 0 0 auto;
                min-width: 172px;
                margin-bottom: 0;
                justify-content: flex-start;
                scroll-snap-align: start;
            }

            .nav-menu::-webkit-scrollbar {
                height: 6px;
            }
        }

        @media (max-width: 768px) {
            .header {
                position: sticky;
                top: 0;
                padding: calc(0.8rem + env(safe-area-inset-top)) 1rem 0.8rem;
                z-index: 1400;
            }

            .track-status-empty-state .track-status-apply-btn {
                padding: 0.44rem 0.84rem;
                min-height: 31px;
                font-size: 0.76rem;
            }

            .header-content {
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                align-items: center;
                gap: 0.7rem;
            }

            .logo-area {
                width: 100%;
                min-width: 0;
            }

            .mobile-nav-toggle {
                display: inline-flex;
                width: auto;
                justify-content: space-between;
                padding-inline: 1rem 1.1rem;
                min-width: 168px;
            }

            .mobile-nav-toggle.active {
                background: linear-gradient(135deg, var(--primary), var(--primary-light));
                color: #fff;
                border-color: transparent;
                box-shadow: 0 12px 26px rgba(58, 36, 117, 0.3);
            }

            .mobile-nav-toggle.active i {
                color: #fff;
                transform: rotate(90deg);
            }

            .user-menu {
                width: 100%;
                grid-column: 1 / -1;
                justify-content: space-between;
                align-items: stretch;
                gap: 0.8rem;
            }

            .user-info {
                flex: 1;
                min-width: 0;
            }

            .main-container {
                margin-top: 0.8rem;
                padding: 1rem;
                gap: 1rem;
                position: relative;
            }

            .sidebar {
                display: block;
                width: min(86vw, 340px);
                position: fixed;
                top: var(--mobile-header-offset, 0px);
                left: 0;
                right: auto;
                height: calc(100dvh - var(--mobile-header-offset, 0px));
                max-height: none;
                margin-top: 0;
                padding: calc(0.6rem + env(safe-area-inset-top)) 0 calc(0.6rem + env(safe-area-inset-bottom));
                overflow: hidden auto;
                pointer-events: none;
                transform: translateX(-108%);
                transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1);
                z-index: 1300;
                border-radius: 0 18px 18px 0;
                overscroll-behavior: contain;
                -webkit-overflow-scrolling: touch;
            }

            .sidebar.mobile-open {
                pointer-events: auto;
                transform: translateX(0);
            }

            .mobile-nav-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                background: rgba(14, 16, 29, 0.4);
                backdrop-filter: blur(3px);
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                transition: opacity 0.24s ease, visibility 0.24s ease;
                z-index: 1200;
            }

            .mobile-nav-backdrop.show {
                opacity: 1;
                visibility: visible;
                pointer-events: auto;
            }

            .profile-section {
                padding: 0.85rem 1rem;
            }

            .content-area {
                position: relative;
                z-index: 1;
            }

            .nav-menu {
                display: grid;
                padding: 0.85rem;
                gap: 0.65rem;
                overflow: visible;
            }

            .nav-item {
                min-width: 100%;
                width: 100%;
                margin-bottom: 0;
                padding: 0.95rem 1rem;
                font-size: 0.92rem;
                justify-content: flex-start;
                transition: background-color 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
            }

            .mobile-logout-nav {
                margin-top: 0.25rem;
            }

            .section-card,
            .timeline-item,
            .contact-form,
            .contact-info,
            .pending-banner {
                padding: 1.2rem;
                border-radius: 14px;
            }

            .welcome-card {
                padding: 1.25rem;
                border-radius: 18px;
            }

            .dashboard-shortcut-grid {
                display: grid;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0.9rem;
            }

            .stat-card {
                padding: 1rem;
                grid-template-columns: 1fr;
                gap: 0.75rem;
                border-radius: 16px;
            }

            .stat-icon {
                width: 48px;
                height: 48px;
                border-radius: 14px;
                font-size: 1.2rem;
            }

            .stat-details h3 {
                font-size: 1.35rem;
            }

            .stat-details p {
                font-size: 0.74rem;
                line-height: 1.35;
            }

            .hero-actions,
            .hero-panel-grid,
            .spotlight-metrics {
                grid-template-columns: 1fr;
            }

            .hero-action {
                width: 100%;
                justify-content: center;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .clearance-step-pills {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .clearance-step-pill {
                justify-content: flex-start;
                border-radius: 14px;
            }

            .filter-bar {
                flex-direction: column;
                border-radius: 20px;
            }

            .filter-bar select,
            .filter-bar button {
                width: 100%;
            }

            .pending-actions {
                flex-direction: column;
            }

            .progress-steps {
                flex-direction: column;
                gap: 1rem;
            }

            .progress-steps::before {
                display: none;
            }

            .step {
                display: flex;
                align-items: center;
                gap: 1rem;
                text-align: left;
            }

            .step-icon {
                margin: 0;
            }

            .timeline-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .messenger-shell {
                grid-template-columns: 1fr;
                min-height: 0;
            }

            .messenger-sidebar {
                border-right: none;
                border-bottom: 1px solid var(--border);
                grid-template-rows: auto auto minmax(0, 1fr);
                min-height: min(78dvh, 680px);
            }

            .messenger-conversation-list {
                max-height: none;
            }

            .messenger-chat-panel {
                display: none;
                min-height: min(78dvh, 680px);
            }

            .messenger-shell.mobile-chat-open .messenger-sidebar {
                display: none;
            }

            .messenger-shell.mobile-chat-open .messenger-chat-panel {
                display: grid;
                min-height: min(78dvh, 680px);
            }

            .messenger-mobile-back {
                display: inline-flex;
            }

            .messenger-chat-thread {
                padding: 1rem;
            }

            .messenger-chat-header {
                align-items: stretch;
            }

            .messenger-chat-user {
                width: 100%;
            }

            .messenger-chat-status {
                white-space: normal;
                line-height: 1.35;
            }

            .messenger-chat-status-extra {
                white-space: normal;
            }

            .messenger-nickname-form {
                width: 100%;
                margin-left: 0;
            }

            .messenger-bubble {
                max-width: 80%;
                font-size: 0.8rem;
                padding: 0.46rem 0.7rem;
            }

            .messenger-bubble-row.mine .messenger-bubble {
                max-width: 76%;
            }

            .messenger-message-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .messenger-reaction-list {
                row-gap: 0.35rem;
            }

            .messenger-composer {
                padding: 0.8rem;
            }

            .messenger-composer-actions {
                flex-direction: row;
                align-items: center;
                justify-content: flex-start;
                gap: 0.55rem;
            }

            .messenger-attachment-controls {
                width: 100%;
            }

            .messenger-attachment-trigger-group {
                gap: 0.4rem;
            }

            .messenger-attach-trigger {
                width: 36px;
                height: 36px;
            }

            .messenger-attach-trigger i {
                font-size: 0.9rem;
            }

            .messenger-attach-image-trigger i {
                font-size: 0.86rem;
            }

            .messenger-attachment-selected {
                flex: 1;
                min-width: 0;
            }

            .messenger-attachment-selected span {
                max-width: none;
            }

            .messenger-empty-chat-note {
                order: 0;
                flex: 1;
                text-align: left;
                font-size: 0.7rem;
            }

            .messenger-send-btn {
                width: auto;
                min-width: 36px;
                padding: 0.48rem 0.82rem;
                justify-content: center;
            }

            .messenger-input-row .messenger-send-btn {
                width: 36px;
                min-width: 36px;
                height: 36px;
                min-height: 36px;
                padding: 0;
                right: 0.4rem;
                top: 50%;
            }

            .messenger-input-row .messenger-send-btn i {
                font-size: 0.82rem;
                margin-left: 0;
            }

            .graduating-alert-popup {
                width: calc(100vw - 1.4rem);
                max-width: 360px;
                padding: 0.85rem 0.78rem 0.75rem;
            }

            .graduating-alert-title {
                font-size: 0.98rem;
            }

            .graduating-alert-text {
                font-size: 0.84rem;
                line-height: 1.42;
            }

            .graduating-alert-confirm {
                min-height: 36px;
                font-size: 0.8rem;
                padding: 0.45rem 0.85rem;
            }

            .conversation-delete-alert-popup {
                width: calc(100vw - 1.4rem);
                max-width: 340px;
                padding: 0.86rem 0.78rem 0.76rem;
                margin: 0;
                max-height: calc(100dvh - var(--mobile-header-offset, 0px) - env(safe-area-inset-top) - 1.15rem);
                overflow-y: auto;
            }

            .conversation-delete-alert-container.swal2-container {
                align-items: center;
                justify-content: flex-start;
                padding-top: calc(var(--mobile-header-offset, 0px) + env(safe-area-inset-top) + 0.45rem);
                padding-right: 0.7rem;
                padding-bottom: max(0.7rem, env(safe-area-inset-bottom));
                padding-left: 0.7rem;
            }

            .conversation-delete-alert-popup .swal2-icon {
                transform: scale(0.9);
                margin-bottom: 0.42rem;
            }

            .conversation-delete-alert-title {
                font-size: 0.95rem;
            }

            .conversation-delete-alert-text {
                font-size: 0.82rem;
                line-height: 1.42;
            }

            .conversation-delete-alert-popup .swal2-actions {
                grid-template-columns: 1fr;
                margin-top: 0.75rem;
                gap: 0.45rem;
            }

            .conversation-delete-alert-confirm,
            .conversation-delete-alert-cancel {
                min-height: 36px;
                font-size: 0.79rem;
                padding: 0.45rem 0.8rem;
            }
        }

        @media (max-width: 480px) {
            .header {
                padding-top: calc(0.65rem + env(safe-area-inset-top));
            }

            .track-status-empty-state .track-status-apply-btn {
                padding: 0.4rem 0.78rem;
                min-height: 30px;
                font-size: 0.74rem;
            }

            .header-content {
                grid-template-columns: 1fr;
            }

            .mobile-nav-toggle {
                width: 100%;
                min-width: 0;
            }

            .logo-text h2 {
                font-size: 1.05rem;
            }

            .user-menu {
                flex-direction: column;
                align-items: stretch;
            }

            .user-info {
                width: 100%;
                justify-content: center;
            }

            .profile-avatar {
                width: 92px;
                height: 92px;
                margin-bottom: 0.8rem;
            }

            .profile-name {
                font-size: 1.05rem;
            }

            .profile-email {
                font-size: 0.78rem;
                margin-bottom: 0.65rem;
            }

            .profile-id {
                font-size: 0.78rem;
                padding: 0.35rem 0.7rem;
            }

            .clearance-type-helper {
                font-size: 0.82rem;
                padding: 0.75rem 0.85rem;
            }

            .graduating-alert-popup {
                width: calc(100vw - 1rem);
                max-width: 320px;
                border-radius: 14px;
                padding: 0.8rem 0.72rem 0.7rem;
            }

            .graduating-alert-popup .swal2-icon {
                transform: scale(0.86);
                margin-top: 0.15rem;
                margin-bottom: 0.35rem;
            }

            .graduating-alert-title {
                font-size: 0.92rem;
            }

            .graduating-alert-text {
                font-size: 0.8rem;
            }

            .graduating-alert-confirm {
                min-height: 34px;
                font-size: 0.78rem;
            }

            .conversation-delete-alert-popup {
                width: calc(100vw - 1rem);
                max-width: 312px;
                border-radius: 14px;
                padding: 0.8rem 0.72rem 0.7rem;
                max-height: calc(100dvh - var(--mobile-header-offset, 0px) - env(safe-area-inset-top) - 0.95rem);
            }

            .conversation-delete-alert-container.swal2-container {
                padding-top: calc(var(--mobile-header-offset, 0px) + env(safe-area-inset-top) + 0.35rem);
                padding-right: 0.5rem;
                padding-bottom: max(0.55rem, env(safe-area-inset-bottom));
                padding-left: 0.5rem;
            }

            .conversation-delete-alert-title {
                font-size: 0.9rem;
            }

            .conversation-delete-alert-text {
                font-size: 0.79rem;
            }

            .conversation-delete-alert-confirm,
            .conversation-delete-alert-cancel {
                min-height: 34px;
                font-size: 0.76rem;
            }

            .clearance-process-flow {
                padding: 1rem;
                border-radius: 14px;
            }

            .clearance-step-pills {
                grid-template-columns: 1fr;
            }

            .nav-item {
                min-width: 100%;
                padding: 0.8rem 0.9rem;
                font-size: 0.85rem;
                gap: 0.55rem;
            }

            .dashboard-shortcut-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }

            .nav-item i {
                width: 18px;
                font-size: 0.95rem;
            }

            .theme-toggle {
                width: 44px;
                height: 44px;
                font-size: 1.05rem;
                right: calc(14px + env(safe-area-inset-right));
                bottom: calc(14px + env(safe-area-inset-bottom));
            }

            .toast {
                left: 12px;
                right: 12px;
                top: calc(8px + env(safe-area-inset-top));
                width: auto;
                border-radius: 14px;
                padding: 0.8rem 1rem;
            }

            .messenger-shell {
                border-radius: 14px;
            }

            .messenger-sidebar-top {
                padding: 0.85rem;
            }

            .messenger-title h2 {
                font-size: 1.05rem;
            }

            .messenger-title p {
                font-size: 0.76rem;
            }

            .messenger-conversation-item {
                padding: 0.55rem;
                border-radius: 12px;
            }

            .messenger-avatar {
                width: 38px;
                height: 38px;
                font-size: 0.8rem;
            }

            .messenger-conversation-name {
                font-size: 0.83rem;
            }

            .messenger-conversation-head {
                align-items: flex-start;
            }

            .messenger-conversation-preview,
            .messenger-conversation-time,
            .messenger-chat-status {
                font-size: 0.7rem;
            }

            .messenger-presence-line {
                font-size: 0.66rem;
            }

            .messenger-conversation-time {
                display: none;
            }

            .messenger-chat-header {
                padding: 0.8rem;
            }

            .messenger-nickname-input {
                font-size: 0.76rem;
            }

            .messenger-nickname-btn {
                min-height: 34px;
                font-size: 0.72rem;
            }

            .messenger-chat-thread {
                padding: 0.82rem;
            }

            .messenger-bubble {
                max-width: 86%;
                font-size: 0.78rem;
                padding: 0.42rem 0.62rem;
            }

            .messenger-bubble-row.mine .messenger-bubble {
                max-width: 82%;
            }

            .messenger-bubble-meta {
                font-size: 0.66rem;
            }

            .messenger-reply-quote span,
            .messenger-reply-preview-main span {
                font-size: 0.72rem;
            }

            .messenger-reaction-btn {
                min-height: 28px;
                font-size: 0.75rem;
            }

            .messenger-composer {
                padding: 0.7rem;
            }

            .messenger-composer textarea {
                min-height: 44px;
                font-size: 0.87rem;
            }

            .messenger-attachment-controls {
                gap: 0.42rem;
            }

            .messenger-attachment-trigger-group {
                gap: 0.34rem;
            }

            .messenger-attach-trigger {
                width: 33px;
                height: 33px;
            }

            .messenger-attach-trigger i {
                font-size: 0.8rem;
            }

            .messenger-attach-image-trigger i {
                font-size: 0.76rem;
            }

            .messenger-attachment-selected span {
                max-width: min(52vw, 170px);
            }

            .messenger-attachment-clear {
                width: 20px;
                height: 20px;
            }

            .messenger-input-row .messenger-send-btn {
                width: 34px;
                min-width: 34px;
                height: 34px;
                min-height: 34px;
                right: 0.34rem;
                top: 50%;
            }

            .messenger-input-row .messenger-send-btn i {
                font-size: 0.78rem;
            }

            .messenger-chat-empty {
                padding: 1.25rem;
            }

            .hero-pill,
            .timeline-badge,
            .profile-id {
                width: 100%;
                justify-content: center;
                text-align: center;
            }

            .spotlight-card,
            .profile-highlight-card {
                padding: 1rem;
            }
        }

        @keyframes mobileDropdownIn {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @supports (-webkit-touch-callout: none) {
            input,
            select,
            textarea,
            button {
                font-size: 16px;
            }
        }
    </style>
    <link rel="manifest" href="<?php echo htmlspecialchars(versionedUrl('manifest.webmanifest'), ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="theme-color" content="#412886">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="BISU Clearance">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars(versionedUrl('assets/img/pwa-icon-192.png'), ENT_QUOTES, 'UTF-8'); ?>">
    <script defer src="<?php echo htmlspecialchars(versionedUrl('assets/js/pwa-register.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</head>

<body>
    <!-- Theme Toggle -->
    <div class="theme-toggle" id="themeToggle">
        <i class="fas fa-moon" id="themeIcon"></i>
    </div>

    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="logo-area">
                <div class="logo-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="logo-text">
                    <h2>BISU Online Clearance</h2>
                    <p>Student Portal</p>
                </div>
            </div>
            <button type="button" class="mobile-nav-toggle" id="mobileNavToggle" aria-expanded="false" aria-controls="studentSidebar">
                <i class="fas fa-bars"></i>
                <span>Open Navigation</span>
            </button>
            <div class="user-menu">
                <div class="user-info" onclick="switchTab('dashboard')">
                    <div class="user-avatar">
                        <?php if (!empty($profile_pic) && file_exists('../' . $profile_pic)): ?>
                            <img src="../<?php echo $profile_pic; ?>" alt="Profile">
                        <?php else: ?>
                            <img src="../assets/img/default-avatar.svg"
                                alt="Profile">
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name">
                            <?php echo htmlspecialchars(explode(' ', $student_name)[0]); ?>
                        </div>
                        <div class="user-role">Student</div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="mobile-nav-backdrop" id="mobileNavBackdrop"></div>
    <div class="main-container">
        <!-- Sidebar -->
        <aside class="sidebar" id="studentSidebar">
            <div class="profile-section">
                <div class="profile-avatar" id="avatarContainer">
                    <?php if (!empty($profile_pic) && file_exists('../' . $profile_pic)): ?>
                        <img src="../<?php echo $profile_pic; ?>" alt="Profile" id="profileImage">
                    <?php else: ?>
                        <img src="../assets/img/default-avatar.svg"
                            alt="Profile" id="profileImage">
                    <?php endif; ?>
                    <div class="avatar-overlay" id="avatarOverlay">
                        <i class="fas fa-camera"></i>
                    </div>
                    <div class="upload-progress" id="uploadProgress">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                </div>
                <input type="file" id="avatarUpload" accept="image/jpeg,image/png,image/gif" style="display: none;">

                <div class="profile-name">
                    <?php echo htmlspecialchars($student_name); ?>
                </div>
                <div class="profile-email">
                    <?php echo htmlspecialchars($student_email); ?>
                </div>
                <div class="profile-id">
                    <?php echo htmlspecialchars($student_info['ismis_id'] ?? 'N/A'); ?>
                </div>
            </div>

            <nav class="nav-menu">
                <button class="nav-item <?php echo $active_tab == 'dashboard' ? 'active' : ''; ?>" data-tab="dashboard"
                    onclick="switchTab('dashboard')">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </button>
                <button class="nav-item <?php echo $active_tab == 'apply' ? 'active' : ''; ?>" data-tab="apply"
                    onclick="switchTab('apply')">
                    <i class="fas fa-file-signature"></i>
                    <span>Apply Clearance</span>
                </button>
                <button class="nav-item <?php echo $active_tab == 'status' ? 'active' : ''; ?>" data-tab="status"
                    onclick="switchTab('status')">
                    <i class="fas fa-chart-line"></i>
                    <span>Track Status</span>
                </button>
                <button class="nav-item <?php echo $active_tab == 'history' ? 'active' : ''; ?>" data-tab="history"
                    onclick="switchTab('history')">
                    <i class="fas fa-history"></i>
                    <span>History</span>
                </button>
                <button id="messagesNavButton" class="nav-item <?php echo $active_tab == 'messages' ? 'active' : ''; ?>" data-tab="messages"
                    onclick="switchTab('messages')">
                    <i class="fas fa-comments"></i>
                    <span>Messages</span>
                    <span id="messageUnreadBadge" class="nav-badge <?php echo $message_tab_notification_count > 0 ? '' : 'is-hidden'; ?>"><?php echo (int) $message_tab_notification_count; ?></span>
                </button>
                <button type="button" class="nav-item" onclick="openProfileModal(); closeMobileNav();">
                    <i class="fas fa-user-edit"></i>
                    <span>Your Information</span>
                </button>
                <a href="../logout.php" class="nav-item mobile-logout-nav">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </nav>
        </aside>

        <!-- Content Area -->
        <main class="content-area">
            <!-- Alert Messages -->
            <?php if ($success): ?>
                <div class="toast success">
                    <i class="fas fa-check-circle"></i>
                    <span>
                        <?php echo $success; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="toast error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>
                        <?php echo $error; ?>
                    </span>
                </div>
            <?php endif; ?>

            <!-- Dashboard Tab -->
            <div id="dashboard" class="tab-content <?php echo $active_tab == 'dashboard' ? 'active' : ''; ?>">
                <!-- Welcome Card -->
                <div class="welcome-card">
                    <h1>Welcome back,
                        <?php echo htmlspecialchars(explode(' ', $student_name)[0]); ?>! 👋
                    </h1>
                    <p>Track your clearance progress and manage your applications from here. Complete all 5 steps to get
                        your clearance.</p>
                    <div class="dashboard-shortcut-grid">
                        <div class="dashboard-shortcut-card dashboard-tap-card" data-switch-tab="status" role="button" tabindex="0">
                            <i class="fas fa-chart-line"></i>
                            <strong>Track Status</strong>
                            <span>Open your latest office and organization progress in one tap.</span>
                        </div>
                        <div class="dashboard-shortcut-card dashboard-tap-card" data-switch-tab="<?php echo $current_clearance ? 'status' : 'apply'; ?>" role="button" tabindex="0">
                            <i class="fas fa-<?php echo $current_clearance ? 'file-circle-check' : 'file-signature'; ?>"></i>
                            <strong><?php echo $current_clearance ? 'Current Clearance' : 'Apply Now'; ?></strong>
                            <span><?php echo htmlspecialchars($current_clearance ? 'Review your active request and respond quickly to updates.' : 'Start a new clearance request from your phone.'); ?></span>
                        </div>
                        <div class="dashboard-shortcut-card dashboard-tap-card" data-switch-tab="history" role="button" tabindex="0">
                            <i class="fas fa-history"></i>
                            <strong>History</strong>
                            <span>Review completed terms and past approval activity.</span>
                        </div>
                        <div class="dashboard-shortcut-card dashboard-tap-card" data-switch-tab="messages" role="button" tabindex="0">
                            <i class="fas fa-comments"></i>
                            <strong>Messages</strong>
                            <span>Check updates, friends, and student conversations faster.</span>
                        </div>
                    </div>
                    <div class="welcome-layout" style="margin-top: 1.5rem;">
                        <div class="welcome-copy">
                            <div class="hero-pills">
                                <span class="hero-pill"><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($current_term_label); ?></span>
                                <span class="hero-pill"><i class="fas fa-route"></i> <?php echo htmlspecialchars($current_progress_label); ?></span>
                                <span class="hero-pill"><i class="fas fa-building"></i> <?php echo (int) $current_total_organizations; ?> org checks</span>
                            </div>
                            <div class="hero-actions">
                                <button type="button" class="hero-action primary" onclick="switchTab('status')">
                                    <i class="fas fa-chart-line"></i> Track Status
                                </button>
                                <button type="button" class="hero-action secondary" onclick="switchTab('<?php echo $current_clearance ? 'status' : 'apply'; ?>')">
                                    <i class="fas fa-<?php echo $current_clearance ? 'file-circle-check' : 'file-signature'; ?>"></i>
                                    <?php echo $current_clearance ? 'Review Current Clearance' : 'Start New Clearance'; ?>
                                </button>
                                <button type="button" class="hero-action secondary" onclick="openOnboardingGuide(true)">
                                    <i class="fas fa-circle-question"></i> System Guide
                                </button>
                            </div>
                        </div>
                        <div class="hero-panel">
                            <div>
                                <div class="hero-panel-label">Student Snapshot</div>
                                <div class="hero-panel-value"><?php echo htmlspecialchars($current_term_label); ?></div>
                            </div>
                            <div class="hero-panel-text"><?php echo htmlspecialchars($hero_support_text); ?></div>
                            <div class="hero-panel-grid">
                                <div class="hero-panel-stat">
                                    <strong><?php echo (int) ($clearance_summary['pending'] ?? 0); ?></strong>
                                    <span>Pending checks</span>
                                </div>
                                <div class="hero-panel-stat">
                                    <strong><?php echo (int) $current_pending_organizations; ?></strong>
                                    <span>Org actions waiting</span>
                                </div>
                                <div class="hero-panel-stat">
                                    <strong><?php echo (int) ($clearance_summary['completed'] ?? 0); ?></strong>
                                    <span>Completed clearances</span>
                                </div>
                                <div class="hero-panel-stat">
                                    <strong><?php echo (int) $current_approved_organizations; ?>/<?php echo (int) $current_total_organizations; ?></strong>
                                    <span>Organizations cleared</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stats Grid - 5 Stats -->
                <div class="stats-grid">
                    <div class="stat-card dashboard-tap-card" data-switch-tab="history" role="button" tabindex="0">
                        <div class="stat-icon total">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="stat-details">
                            <h3>
                                <?php echo $clearance_summary['total']; ?>
                            </h3>
                            <p>Total Applications</p>
                        </div>
                    </div>
                    <div class="stat-card dashboard-tap-card" data-switch-tab="history" role="button" tabindex="0">
                        <div class="stat-icon approved">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-details">
                            <h3>
                                <?php echo $clearance_summary['approved']; ?>
                            </h3>
                            <p>Approved</p>
                        </div>
                    </div>
                    <div class="stat-card dashboard-tap-card" data-switch-tab="status" role="button" tabindex="0">
                        <div class="stat-icon pending">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-details">
                            <h3>
                                <?php echo $clearance_summary['pending']; ?>
                            </h3>
                            <p>Pending</p>
                        </div>
                    </div>
                    <div class="stat-card dashboard-tap-card" data-switch-tab="status" role="button" tabindex="0">
                        <div class="stat-icon rejected">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-details">
                            <h3>
                                <?php echo $clearance_summary['rejected']; ?>
                            </h3>
                            <p>Rejected</p>
                        </div>
                    </div>
                    <div class="stat-card dashboard-tap-card" data-switch-tab="history" role="button" tabindex="0">
                        <div class="stat-icon completed">
                            <i class="fas fa-check-double"></i>
                        </div>
                        <div class="stat-details">
                            <h3>
                                <?php echo $clearance_summary['completed']; ?>
                            </h3>
                            <p>Completed</p>
                        </div>
                    </div>
                </div>

                <div class="dashboard-overview">
                    <div class="overview-stack">
                        <!-- Current Clearance Progress -->
                        <?php if ($current_clearance): ?>
                    <div class="section-card">
                        <div class="section-header">
                            <h2><i class="fas fa-tasks"></i> Current Clearance Progress</h2>
                            <span class="timeline-badge badge-pending">In Progress</span>
                        </div>

                        <?php
                        $current_step = getCurrentStep($current_clearance['applications']);
                        $steps = ['Librarian', 'Director SAS', 'Dean', 'Cashier', 'Registrar'];
                        ?>

                        <!-- Progress Steps - All 5 steps -->
                        <div class="progress-steps">
                            <?php foreach ($steps as $index => $step):
                                $status = '';
                                if ($index + 1 < $current_step)
                                    $status = 'completed';
                                elseif ($index + 1 == $current_step)
                                    $status = 'current';
                                ?>
                                <div class="step <?php echo $status; ?>">
                                    <div class="step-icon">
                                        <?php echo $index + 1; ?>
                                    </div>
                                    <div class="step-label">
                                        <?php echo $step; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Office Cards - All 5 offices -->
                        <div class="offices-grid">
                            <?php foreach ($current_clearance['applications'] as $office): ?>
                                <div class="office-card <?php echo !empty($office['lacking_comment']) ? 'lacking' : ''; ?>"
                                    onclick='viewDetails(<?php echo json_encode($office); ?>)'>
                                    <div class="office-icon <?php echo $office['status']; ?>">
                                        <i class="fas fa-<?php echo getOfficeIcon($office['office_name']); ?>"></i>
                                    </div>
                                    <div class="office-details">
                                        <div class="office-name">
                                            <?php echo getOfficeDisplayName($office['office_name']); ?>
                                        </div>
                                        <div class="office-status <?php echo $office['status']; ?>">
                                            <?php echo ucfirst($office['status']); ?>
                                        </div>

                                        <?php if (!empty($office['lacking_comment'])): ?>
                                            <div class="lacking-badge">
                                                <i class="fas fa-exclamation-triangle"></i> Lacking Items
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($office['student_proof_file'])): ?>
                                            <div class="proof-badge">
                                                <i class="fas fa-check-circle"></i> Proof Uploaded
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($office['remarks'])): ?>
                                            <div class="office-remarks" title="<?php echo htmlspecialchars($office['remarks']); ?>">
                                                <i class="fas fa-comment"></i>
                                                <?php echo htmlspecialchars(substr($office['remarks'], 0, 20)) . '...'; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($current_clearance['can_cancel']): ?>
                            <div style="text-align: right; margin-top: 1rem;">
                                <form method="POST"
                                    onsubmit="return confirm('Are you sure you want to cancel this application? This action cannot be undone.');">
                                    <input type="hidden" name="semester" value="<?php echo $current_clearance['semester']; ?>">
                                    <input type="hidden" name="school_year"
                                        value="<?php echo $current_clearance['school_year']; ?>">
                                    <button type="submit" name="cancel_application" class="cancel-btn">
                                        <i class="fas fa-times-circle"></i> Cancel Application
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                    </div>

                    <div class="profile-highlight">
                        <div class="spotlight-card">
                            <div class="spotlight-label">Live Focus</div>
                            <div class="spotlight-title"><?php echo htmlspecialchars($current_clearance ? 'Stay on top of your active clearance' : 'You are ready to apply for clearance'); ?></div>
                            <div class="spotlight-copy"><?php echo htmlspecialchars($hero_support_text); ?></div>
                            <div class="spotlight-metrics">
                                <div class="spotlight-metric">
                                    <strong><?php echo (int) ($current_clearance ? ($current_clearance['approved_offices'] ?? 0) : 0); ?>/<?php echo (int) ($current_clearance ? ($current_clearance['total_offices'] ?? 5) : 5); ?></strong>
                                    <span>Office approvals</span>
                                </div>
                                <div class="spotlight-metric">
                                    <strong><?php echo (int) $current_approved_organizations; ?>/<?php echo (int) $current_total_organizations; ?></strong>
                                    <span>Organization checks</span>
                                </div>
                                <div class="spotlight-metric">
                                    <strong><?php echo (int) ($clearance_summary['rejected'] ?? 0); ?></strong>
                                    <span>Items needing fixes</span>
                                </div>
                                <div class="spotlight-metric">
                                    <strong><?php echo (int) ($message_tab_notification_count ?? 0); ?></strong>
                                    <span>Unread updates</span>
                                </div>
                            </div>
                        </div>

                        <div class="profile-highlight-card">
                            <h3><i class="fas fa-lightbulb" style="color: var(--warning); margin-right: 0.45rem;"></i>Best Next Step</h3>
                            <p><?php echo htmlspecialchars($current_clearance ? ($current_pending_organizations > 0 ? 'Open Track Status and upload proof for any organization asking for additional documents so your clearance does not stall.' : 'Keep checking your current progress and respond quickly to any office or organization comments.') : 'Open the Apply tab, choose your term, and submit a new clearance request when you are ready.'); ?></p>
                        </div>

                        <div class="profile-highlight-card">
                            <h3><i class="fas fa-mobile-alt" style="color: var(--info); margin-right: 0.45rem;"></i>Mobile Friendly</h3>
                            <p>The layout now keeps your main actions, stats, and progress blocks easier to scan on smaller screens so you can manage clearance updates comfortably from your phone.</p>
                        </div>
                    </div>
                </div>

                <!-- No Applications Yet -->
                <?php if (empty($clearance_data)): ?>
                    <div class="section-card">
                        <div class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <h3>No Clearance Applications Yet</h3>
                            <p>Get started by applying for your first clearance. It's quick and easy!</p>
                            <button class="btn btn-primary" onclick="switchTab('apply')"
                                style="background: var(--primary); color: white;">
                                <i class="fas fa-file-signature"></i> Apply Now
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Apply Clearance Tab -->
            <div id="apply" class="tab-content <?php echo $active_tab == 'apply' ? 'active' : ''; ?>">
                <div class="apply-card">
                    <?php if ($current_clearance): ?>
                        <div class="pending-banner">
                            <div class="pending-icon">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                            <h3>Application in Progress</h3>
                            <p>You already have a pending clearance application for
                                <?php echo $current_clearance['semester'] . ' ' . $current_clearance['school_year']; ?>. You
                                can only have one active clearance at a time.
                            </p>
                            <div class="pending-actions">
                                <button class="btn btn-primary" onclick="switchTab('status')">
                                    <i class="fas fa-eye"></i> View Status
                                </button>
                                <button class="btn btn-outline" onclick="switchTab('dashboard')">
                                    <i class="fas fa-home"></i> Dashboard
                                </button>
                                <?php if ($current_clearance['can_cancel']): ?>
                                    <form method="POST"
                                        onsubmit="return confirm('Are you sure you want to cancel this application? This action cannot be undone.');"
                                        style="display: inline-flex;">
                                        <input type="hidden" name="semester" value="<?php echo $current_clearance['semester']; ?>">
                                        <input type="hidden" name="school_year"
                                            value="<?php echo $current_clearance['school_year']; ?>">
                                        <button type="submit" name="cancel_application" class="btn btn-outline">
                                            <i class="fas fa-times-circle"></i> Cancel Application
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="apply-header">
                            <div class="apply-icon">
                                <i class="fas fa-file-signature"></i>
                            </div>
                            <h3>Apply for Clearance</h3>
                            <p>Fill in the details below to submit your clearance application</p>
                        </div>

                        <form method="POST" action="" class="apply-form" id="applyClearanceForm">
                            <div class="form-group">
                                <label><i class="fas fa-tag"></i> Clearance Type</label>
                                <select name="clearance_type" id="applyClearanceType" class="form-control clearance-type-select" required aria-describedby="clearanceTypeHelper">
                                    <option value="">Select clearance type</option>
                                    <?php
                                    $selected_clearance_type = trim((string) ($_POST['clearance_type'] ?? ''));
                                    foreach ($clearance_types as $type):
                                        $type_value = (string) ($type['clearance_name'] ?? '');
                                        $type_label = ucwords(str_replace(['_', '-'], ' ', $type_value));
                                        $normalized_type = strtolower((string) preg_replace('/[^a-z0-9]+/', '', $type_value));
                                        $is_non_graduating_option = strpos($normalized_type, 'nongraduating') !== false;
                                        $is_graduating_option = strpos($normalized_type, 'graduating') !== false && !$is_non_graduating_option;
                                        $status_suffix = $is_non_graduating_option
                                            ? ' (Available)'
                                            : ($is_graduating_option ? ' (Coming Soon)' : '');
                                        $is_selected = $selected_clearance_type === $type_value && $is_non_graduating_option;
                                        ?>
                                        <option value="<?php echo htmlspecialchars($type_value); ?>"
                                            data-available="<?php echo $is_non_graduating_option ? '1' : '0'; ?>"
                                            data-coming-soon="<?php echo $is_graduating_option ? '1' : '0'; ?>"
                                            <?php echo $is_selected ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type_label . ' Clearance' . $status_suffix); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="clearance-type-helper" id="clearanceTypeHelper">
                                    <i class="fas fa-info-circle" aria-hidden="true"></i>
                                    <span><strong>Note:</strong> Non-Graduating Clearance is currently the only available option. Graduating Clearance will show as COMING SOON.</span>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label><i class="fas fa-calendar"></i> School Year</label>
                                    <input type="hidden" name="school_year" value="<?php echo htmlspecialchars($school_year); ?>">
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($school_year); ?>" readonly>
                                </div>

                                <div class="form-group">
                                    <label><i class="fas fa-clock"></i> Semester</label>
                                    <input type="hidden" name="semester" value="<?php echo htmlspecialchars($current_semester); ?>">
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($current_semester); ?>" readonly>
                                </div>
                            </div>

                            <button type="submit" name="apply_clearance" class="apply-btn">
                                <i class="fas fa-paper-plane"></i>
                                Submit Application
                            </button>
                        </form>

                        <!-- Process Flow Info - All 5 offices -->
                        <div class="clearance-process-flow">
                            <h4 class="clearance-process-title">
                                <i class="fas fa-info-circle"></i>
                                Clearance Process Flow (5 Steps)
                            </h4>
                            <div class="clearance-step-pills">
                                <div class="clearance-step-pill">
                                    <span class="clearance-step-number">1</span>
                                    <span class="clearance-step-label">Librarian</span>
                                </div>
                                <div class="clearance-step-pill">
                                    <span class="clearance-step-number">2</span>
                                    <span class="clearance-step-label">Director SAS</span>
                                </div>
                                <div class="clearance-step-pill">
                                    <span class="clearance-step-number">3</span>
                                    <span class="clearance-step-label">Dean</span>
                                </div>
                                <div class="clearance-step-pill">
                                    <span class="clearance-step-number">4</span>
                                    <span class="clearance-step-label">Cashier</span>
                                </div>
                                <div class="clearance-step-pill">
                                    <span class="clearance-step-number">5</span>
                                    <span class="clearance-step-label">Registrar</span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Status Tab -->
            <div id="status" class="tab-content <?php echo $active_tab == 'status' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-chart-line"></i> Clearance Status</h2>
                    </div>

                    <!-- Filter Bar -->
                    <div class="filter-bar">
                        <select class="filter-select" id="statusFilter">
                            <option value="">All Status</option>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                        <input type="text" class="filter-select" id="dateFilter" placeholder="Filter by date...">
                        <button class="filter-btn" onclick="filterStatusTable()">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <button class="clear-filter" onclick="clearStatusFilters()">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>

                    <?php if (empty($grouped_clearances)): ?>
                        <div class="empty-state track-status-empty-state">
                            <i class="fas fa-inbox"></i>
                            <h3>No Clearance Applications Found</h3>
                            <p>Apply for clearance to see your status here.</p>
                            <button class="btn btn-primary track-status-apply-btn" onclick="switchTab('apply')"
                                style="background: var(--primary); color: white;">
                                <i class="fas fa-file-signature"></i> Apply Now
                            </button>
                        </div>
                    <?php else: ?>
                        <!-- Timeline View -->
                        <div class="clearance-timeline" id="statusTimeline">
                            <?php foreach ($grouped_clearances as $key => $group): ?>
                                <div class="timeline-item" data-status="<?php echo $group['status']; ?>"
                                    data-date="<?php echo date('Y-m-d', strtotime($group['applied_date'])); ?>">

                                    <!-- Timeline Header -->
                                    <div class="timeline-header">
                                        <div class="timeline-title">
                                            <i class="fas fa-calendar-alt"
                                                style="color: var(--primary); font-size: 1.3rem;"></i>
                                            <h3>
                                                <?php echo $key; ?>
                                            </h3>
                                            <span class="timeline-badge badge-<?php echo $group['status']; ?>">
                                                <?php echo ucfirst($group['status']); ?>
                                            </span>
                                            <span style="color: var(--text-light); font-size: 0.9rem;">
                                                (
                                                <?php echo $group['clearance_type']; ?>)
                                            </span>
                                        </div>
                                        <div class="timeline-date">
                                            <i class="fas fa-clock"></i>
                                            Applied:
                                            <?php echo date('M d, Y', strtotime($group['applied_date'])); ?>
                                        </div>
                                    </div>

                                    <!-- Progress Steps -->
                                    <div class="progress-steps">
                                        <?php
                                        $current_step = getCurrentStep($group['applications']);
                                        foreach ($steps as $index => $step):
                                            $status = '';
                                            if ($group['status'] == 'approved') {
                                                $status = 'completed';
                                            } elseif ($group['status'] == 'rejected' && $index + 1 <= $group['rejected_offices']) {
                                                $status = '';
                                            } elseif ($index + 1 < $current_step) {
                                                $status = 'completed';
                                            } elseif ($index + 1 == $current_step && $group['status'] == 'pending') {
                                                $status = 'current';
                                            }
                                            ?>
                                            <div class="step <?php echo $status; ?>">
                                                <div class="step-icon">
                                                    <?php echo $index + 1; ?>
                                                </div>
                                                <div class="step-label">
                                                    <?php echo $step; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <!-- Office Cards -->
                                    <h4 style="margin: 1.5rem 0 1rem; color: var(--text);">Office Status</h4>
                                    <div class="offices-grid">
                                        <?php foreach ($group['applications'] as $app): ?>
                                            <div class="office-card <?php echo !empty($app['lacking_comment']) ? 'lacking' : ''; ?>"
                                                onclick='viewDetails(<?php echo json_encode($app); ?>)'>
                                                <div class="office-icon <?php echo $app['status']; ?>">
                                                    <i class="fas fa-<?php echo getOfficeIcon($app['office_name']); ?>"></i>
                                                </div>
                                                <div class="office-details">
                                                    <div class="office-name">
                                                        <?php echo getOfficeDisplayName($app['office_name']); ?>
                                                    </div>
                                                    <div class="office-status <?php echo $app['status']; ?>">
                                                        <?php echo ucfirst($app['status']); ?>
                                                    </div>

                                                    <?php if (!empty($app['lacking_comment'])): ?>
                                                        <div class="lacking-badge">
                                                            <i class="fas fa-exclamation-triangle"></i> Lacking Items
                                                        </div>

                                                        <?php if (empty($app['student_proof_file'])): ?>
                                                            <button class="upload-btn"
                                                                onclick="event.stopPropagation(); openUploadModal(<?php echo $app['clearance_id']; ?>, '<?php echo $app['office_name']; ?>')">
                                                                <i class="fas fa-upload"></i> Upload Proof
                                                            </button>
                                                        <?php else: ?>
                                                            <div class="proof-action-group">
                                                                <div class="proof-badge">
                                                                    <i class="fas fa-check-circle"></i> Proof Uploaded
                                                                </div>
                                                                <?php if (!empty($app['student_proof_file'])): ?>
                                                                    <a href="../<?php echo $app['student_proof_file']; ?>" target="_blank"
                                                                        class="view-proof-btn" onclick="event.stopPropagation();">
                                                                        <i class="fas fa-eye"></i> View Proof
                                                                    </a>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>

                                                    <?php if (!empty($app['processed_date'])): ?>
                                                        <div class="office-date">
                                                            <i class="fas fa-check-circle"></i>
                                                            <?php echo date('M d, Y', strtotime($app['processed_date'])); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <?php if (!empty($group['organization_applications'])): ?>
                                        <h4 style="margin: 1.5rem 0 1rem; color: var(--text);">Organization Status</h4>
                                        <div class="offices-grid">
                                            <?php foreach ($group['organization_applications'] as $orgApp): ?>
                                                <div class="office-card <?php echo !empty($orgApp['lacking_comment']) ? 'lacking' : ''; ?>"
                                                    onclick='viewDetails(<?php echo json_encode($orgApp); ?>)'>
                                                    <div class="office-icon <?php echo $orgApp['status']; ?>">
                                                        <i class="fas fa-<?php echo getOrganizationIcon($orgApp['org_type'] ?? ''); ?>"></i>
                                                    </div>
                                                    <div class="office-details">
                                                        <div class="office-name">
                                                            <?php echo htmlspecialchars($orgApp['display_name'] ?? $orgApp['org_name'] ?? 'Organization'); ?>
                                                        </div>
                                                        <div class="office-status <?php echo $orgApp['status']; ?>">
                                                            <?php echo ucfirst($orgApp['status']); ?>
                                                        </div>

                                                        <?php if (!empty($orgApp['lacking_comment'])): ?>
                                                            <div class="lacking-badge">
                                                                <i class="fas fa-exclamation-triangle"></i> Organization Proof Needed
                                                            </div>

                                                            <?php if (empty($orgApp['student_proof_file'])): ?>
                                                                <button class="upload-btn"
                                                                    onclick="event.stopPropagation(); openUploadModal(<?php echo (int) $orgApp['clearance_id']; ?>, '<?php echo htmlspecialchars($orgApp['display_name'] ?? $orgApp['org_name'] ?? 'Organization', ENT_QUOTES); ?>', 'organization', <?php echo (int) $orgApp['org_clearance_id']; ?>)">
                                                                    <i class="fas fa-upload"></i> Upload Proof
                                                                </button>
                                                            <?php else: ?>
                                                                <div class="proof-action-group">
                                                                    <div class="proof-badge">
                                                                        <i class="fas fa-check-circle"></i> Proof Uploaded
                                                                    </div>
                                                                    <a href="../<?php echo $orgApp['student_proof_file']; ?>" target="_blank"
                                                                        class="view-proof-btn" onclick="event.stopPropagation();">
                                                                        <i class="fas fa-eye"></i> View Proof
                                                                    </a>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php endif; ?>

                                                        <?php if (!empty($orgApp['processed_date'])): ?>
                                                            <div class="office-date">
                                                                <i class="fas fa-check-circle"></i>
                                                                <?php echo date('M d, Y', strtotime($orgApp['processed_date'])); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Lacking Comments Summary -->
                                    <?php
                                    $has_lacking = false;
                                    $lacking_offices = [];
                                    foreach ($group['applications'] as $app) {
                                        if (!empty($app['lacking_comment'])) {
                                            $has_lacking = true;
                                            $lacking_offices[] = [
                                                'office' => getOfficeDisplayName($app['office_name']),
                                                'comment' => $app['lacking_comment']
                                            ];
                                        }
                                    }
                                    ?>

                                    <?php if ($has_lacking): ?>
                                        <div
                                            style="margin-top: 1rem; padding: 1rem; background: rgba(249, 115, 22, 0.1); border-radius: 8px;">
                                            <h5
                                                style="display: flex; align-items: center; gap: 0.5rem; color: var(--lacking); margin-bottom: 0.5rem;">
                                                <i class="fas fa-exclamation-triangle"></i> Items Needed
                                            </h5>
                                            <?php foreach ($lacking_offices as $lo): ?>
                                                <div
                                                    style="margin-bottom: 0.5rem; padding: 0.5rem; background: var(--white); border-radius: 4px;">
                                                    <strong>
                                                        <?php echo $lo['office']; ?>:
                                                    </strong>
                                                    <?php echo htmlspecialchars($lo['comment']); ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php
                                    $organization_lacking_items = [];
                                    foreach ($group['organization_applications'] ?? [] as $orgApp) {
                                        if (!empty($orgApp['lacking_comment'])) {
                                            $organization_lacking_items[] = [
                                                'organization' => $orgApp['display_name'] ?? $orgApp['org_name'] ?? 'Organization',
                                                'comment' => $orgApp['lacking_comment']
                                            ];
                                        }
                                    }
                                    ?>

                                    <?php if (!empty($organization_lacking_items)): ?>
                                        <div
                                            style="margin-top: 1rem; padding: 1rem; background: rgba(14, 165, 233, 0.1); border-radius: 8px;">
                                            <h5
                                                style="display: flex; align-items: center; gap: 0.5rem; color: var(--proof); margin-bottom: 0.5rem;">
                                                <i class="fas fa-building"></i> Organization Requirements
                                            </h5>
                                            <?php foreach ($organization_lacking_items as $orgLacking): ?>
                                                <div
                                                    style="margin-bottom: 0.5rem; padding: 0.5rem; background: var(--white); border-radius: 4px;">
                                                    <strong>
                                                        <?php echo htmlspecialchars($orgLacking['organization']); ?>:
                                                    </strong>
                                                    <?php echo htmlspecialchars($orgLacking['comment']); ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Summary Stats -->
                                    <div class="summary-stats">
                                        <div class="summary-item">
                                            <i class="fas fa-check-circle" style="color: var(--success);"></i>
                                            <span>Approved:
                                                <?php echo $group['approved_offices']; ?>/5
                                            </span>
                                        </div>
                                        <?php if ($group['pending_offices'] > 0): ?>
                                            <div class="summary-item">
                                                <i class="fas fa-clock" style="color: var(--warning);"></i>
                                                <span>Pending:
                                                    <?php echo $group['pending_offices']; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($group['rejected_offices'] > 0): ?>
                                            <div class="summary-item">
                                                <i class="fas fa-times-circle" style="color: var(--danger);"></i>
                                                <span>Rejected:
                                                    <?php echo $group['rejected_offices']; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (($group['total_organizations'] ?? 0) > 0): ?>
                                            <div class="summary-item">
                                                <i class="fas fa-building" style="color: var(--primary);"></i>
                                                <span>Organizations:
                                                    <?php echo $group['approved_organizations']; ?>/<?php echo $group['total_organizations']; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($group['can_cancel'])): ?>
                                        <div style="text-align: right; margin-top: 1rem;">
                                            <form method="POST"
                                                onsubmit="return confirm('Are you sure you want to cancel this application? This action cannot be undone.');">
                                                <input type="hidden" name="semester" value="<?php echo $group['semester']; ?>">
                                                <input type="hidden" name="school_year" value="<?php echo $group['school_year']; ?>">
                                                <button type="submit" name="cancel_application" class="cancel-btn">
                                                    <i class="fas fa-times-circle"></i> Cancel Application
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- History Tab -->
            <div id="history" class="tab-content <?php echo $active_tab == 'history' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-history"></i> Clearance History</h2>
                    </div>

                    <!-- Filter Bar -->
                    <div class="filter-bar">
                        <select class="filter-select" id="historyStatusFilter">
                            <option value="">All Completed</option>
                            <option value="approved">Completed</option>
                        </select>
                        <select class="filter-select" id="historyYearFilter">
                            <option value="">All Years</option>
                            <?php
                            $years = [];
                            foreach ($grouped_clearances as $group) {
                                $year = substr($group['school_year'], 0, 4);
                                if (!in_array($year, $years)) {
                                    $years[] = $year;
                                }
                            }
                            sort($years);
                            foreach ($years as $year): ?>
                                <option value="<?php echo $year; ?>">
                                    <?php echo $year; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="filter-btn" onclick="filterHistory()">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <button class="clear-filter" onclick="clearHistoryFilters()">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>

                    <p style="margin: -0.6rem 0 1.2rem; color: var(--text-light); font-size: 0.9rem;">
                        Completed clearances appear here. Click a record to view the full details of that previous clearance.
                    </p>

                    <?php
                    $completed_clearances = array_filter($grouped_clearances, function ($group) {
                        $is_approved = (($group['status'] ?? '') === 'approved');
                        $offices_completed = (int) ($group['approved_offices'] ?? 0) === (int) ($group['total_offices'] ?? 0);
                        $organizations_completed = (int) ($group['approved_organizations'] ?? 0) === (int) ($group['total_organizations'] ?? 0);

                        return $is_approved && $offices_completed && $organizations_completed;
                    });
                    ?>

                    <?php if (empty($completed_clearances)): ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h3>No Completed Clearance Yet</h3>
                            <p>Once you finish all clearance requirements, the record will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="clearance-timeline" id="historyTimeline">
                            <?php foreach ($completed_clearances as $key => $group): ?>
                                <?php
                                $history_detail_id = 'historyDetails_' . md5($key . '_' . ($group['applied_date'] ?? ''));
                                $last_processed = end($group['applications']);
                                ?>
                                <div class="timeline-item" data-status="<?php echo $group['status']; ?>"
                                    data-year="<?php echo substr($group['school_year'], 0, 4); ?>">

                                    <!-- Timeline Header -->
                                    <div class="timeline-header history-summary"
                                        role="button"
                                        tabindex="0"
                                        onclick="toggleHistoryDetails('<?php echo $history_detail_id; ?>')"
                                        onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); toggleHistoryDetails('<?php echo $history_detail_id; ?>'); }">
                                        <div class="timeline-title">
                                            <i class="fas fa-calendar-check" style="color: var(--primary);"></i>
                                            <h3>
                                                <?php echo $key; ?>
                                            </h3>
                                            <span class="timeline-badge badge-<?php echo $group['status']; ?>">
                                                Completed
                                            </span>
                                            <span style="color: var(--text-light); font-size: 0.9rem;">
                                                (
                                                <?php echo $group['clearance_type']; ?>)
                                            </span>
                                        </div>
                                        <div class="history-summary-actions">
                                            <div class="timeline-date">
                                                <i class="fas fa-clock"></i>
                                                <?php echo !empty($last_processed['processed_date']) ? 'Completed: ' . date('M d, Y', strtotime($last_processed['processed_date'])) : 'N/A'; ?>
                                            </div>
                                            <button type="button"
                                                class="history-toggle-btn"
                                                aria-controls="<?php echo $history_detail_id; ?>"
                                                aria-expanded="false"
                                                onclick="event.stopPropagation(); toggleHistoryDetails('<?php echo $history_detail_id; ?>');">
                                                <span class="history-toggle-label">View Details</span>
                                                <i class="fas fa-chevron-down history-toggle-icon"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div id="<?php echo $history_detail_id; ?>" class="history-details-panel" hidden>
                                        <!-- Stats Summary -->
                                        <div
                                            style="display: flex; gap: 2rem; margin-bottom: 1.5rem; padding: 1rem; background: var(--bg); border-radius: 12px; flex-wrap: wrap;">
                                            <div>
                                                <div style="color: var(--text-light); font-size: 0.9rem;">Total Offices</div>
                                                <div style="color: var(--text); font-size: 1.5rem; font-weight: 700;">5</div>
                                            </div>
                                            <div>
                                                <div style="color: var(--text-light); font-size: 0.9rem;">Approved</div>
                                                <div style="color: var(--success); font-size: 1.5rem; font-weight: 700;">
                                                    <?php echo $group['approved_offices']; ?>
                                                </div>
                                            </div>
                                            <?php if (($group['total_organizations'] ?? 0) > 0): ?>
                                                <div>
                                                    <div style="color: var(--text-light); font-size: 0.9rem;">Organizations</div>
                                                    <div style="color: var(--primary); font-size: 1.5rem; font-weight: 700;">
                                                        <?php echo $group['approved_organizations']; ?>/<?php echo $group['total_organizations']; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Office Cards -->
                                        <h4 style="margin: 1rem 0; color: var(--text);">Office Details</h4>
                                        <div class="offices-grid">
                                            <?php foreach ($group['applications'] as $app): ?>
                                                <div class="office-card" onclick='viewDetails(<?php echo json_encode($app); ?>)'>
                                                    <div class="office-icon <?php echo $app['status']; ?>">
                                                        <i class="fas fa-<?php echo getOfficeIcon($app['office_name']); ?>"></i>
                                                    </div>
                                                    <div class="office-details">
                                                        <div class="office-name">
                                                            <?php echo getOfficeDisplayName($app['office_name']); ?>
                                                        </div>
                                                        <div class="office-status <?php echo $app['status']; ?>">
                                                            <?php echo ucfirst($app['status']); ?>
                                                        </div>

                                                        <?php if (!empty($app['remarks'])): ?>
                                                            <div class="office-remarks"
                                                                title="<?php echo htmlspecialchars($app['remarks']); ?>">
                                                                <i class="fas fa-comment"></i>
                                                                <?php echo htmlspecialchars(substr($app['remarks'], 0, 20)) . '...'; ?>
                                                            </div>
                                                        <?php endif; ?>

                                                        <?php if (!empty($app['student_proof_file'])): ?>
                                                            <div class="proof-action-group">
                                                                <a href="../<?php echo $app['student_proof_file']; ?>" target="_blank"
                                                                    class="view-proof-btn" onclick="event.stopPropagation();">
                                                                    <i class="fas fa-eye"></i> View Proof
                                                                </a>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <?php if (!empty($group['organization_applications'])): ?>
                                            <h4 style="margin: 1rem 0; color: var(--text);">Organization Details</h4>
                                            <div class="offices-grid">
                                                <?php foreach ($group['organization_applications'] as $orgApp): ?>
                                                    <div class="office-card <?php echo !empty($orgApp['lacking_comment']) ? 'lacking' : ''; ?>"
                                                        onclick='viewDetails(<?php echo json_encode($orgApp); ?>)'>
                                                        <div class="office-icon <?php echo $orgApp['status']; ?>">
                                                            <i class="fas fa-<?php echo getOrganizationIcon($orgApp['org_type'] ?? ''); ?>"></i>
                                                        </div>
                                                        <div class="office-details">
                                                            <div class="office-name">
                                                                <?php echo htmlspecialchars($orgApp['display_name'] ?? $orgApp['org_name'] ?? 'Organization'); ?>
                                                            </div>
                                                            <div class="office-status <?php echo $orgApp['status']; ?>">
                                                                <?php echo ucfirst($orgApp['status']); ?>
                                                            </div>
                                                            <?php if (!empty($orgApp['clean_remarks'])): ?>
                                                                <div class="office-remarks"
                                                                    title="<?php echo htmlspecialchars($orgApp['clean_remarks']); ?>">
                                                                    <i class="fas fa-comment"></i>
                                                                    <?php echo htmlspecialchars(strlen($orgApp['clean_remarks']) > 20 ? substr($orgApp['clean_remarks'], 0, 20) . '...' : $orgApp['clean_remarks']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($orgApp['student_proof_file'])): ?>
                                                                <div class="proof-action-group">
                                                                    <a href="../<?php echo $orgApp['student_proof_file']; ?>" target="_blank"
                                                                        class="view-proof-btn" onclick="event.stopPropagation();">
                                                                        <i class="fas fa-eye"></i> View Proof
                                                                    </a>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Messages Tab -->
            <div id="messages" class="tab-content <?php echo $active_tab == 'messages' ? 'active' : ''; ?>">
                <div id="messengerShell" class="messenger-shell <?php echo $has_explicit_chat_target ? 'mobile-chat-open' : ''; ?>">
                    <aside class="messenger-sidebar">
                        <div class="messenger-sidebar-top">
                            <div class="messenger-title">
                                <span class="messenger-title-icon"><i class="fas fa-comments"></i></span>
                                <div>
                                    <h2>Messages</h2>
                                    <p><?php echo count($student_friends); ?> classmate<?php echo count($student_friends) === 1 ? '' : 's'; ?></p>
                                </div>
                            </div>

                            <form method="POST" action="" class="messenger-add-friend-form">
                                <input
                                    type="text"
                                    name="friend_ismis_id"
                                    class="messenger-add-input"
                                    placeholder="Add friend by ISMIS ID"
                                    maxlength="40"
                                    required
                                >
                                <button type="submit" name="add_friend_by_ismis" class="messenger-add-btn" title="Send friend request">
                                    <i class="fas fa-user-plus"></i>
                                </button>
                            </form>

                            <?php if (!empty($outgoing_friend_requests)): ?>
                                <div class="messenger-request-meta">
                                    Sent requests: <?php echo count($outgoing_friend_requests); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($incoming_friend_requests)): ?>
                            <div class="messenger-request-wrap">
                                <div class="messenger-request-heading">Incoming Requests</div>
                                <?php foreach ($incoming_friend_requests as $request): ?>
                                    <form method="POST" action="" class="messenger-request-row">
                                        <div class="messenger-request-main">
                                            <strong><?php echo htmlspecialchars($request['requester_name']); ?></strong>
                                            <?php if (!empty($request['ismis_id'])): ?>
                                                <div class="messenger-request-meta">ISMIS: <?php echo htmlspecialchars($request['ismis_id']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <input type="hidden" name="friendship_id" value="<?php echo (int) $request['friendship_id']; ?>">
                                        <button type="submit" name="accept_friend_request" class="messenger-request-btn">Accept</button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="messenger-conversation-list">
                            <?php if (empty($student_friends)): ?>
                                <div class="messenger-chat-empty" style="height: auto;">
                                    <div>
                                        <i class="fas fa-user-friends"></i>
                                        <p>No friends yet. Add classmates to start chatting.</p>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($student_friends as $friend): ?>
                                    <?php
                                    $friend_id = (int) ($friend['users_id'] ?? 0);
                                    $friend_name = (string) ($friend['chat_display_name'] ?? ('Student #' . $friend_id));
                                    $friend_base_name = (string) ($friend['base_display_name'] ?? $friend_name);
                                    $friend_profile_picture_url = (string) ($friend['profile_picture_url'] ?? '');
                                    $friend_presence_class = (string) ($friend['presence_status_class'] ?? 'offline');
                                    $friend_presence_text = (string) ($friend['presence_status_text'] ?? 'Offline');
                                    $name_parts = preg_split('/\s+/', trim($friend_name));
                                    $initials = '';
                                    if (!empty($name_parts[0])) {
                                        $initials .= strtoupper(substr($name_parts[0], 0, 1));
                                    }
                                    if (!empty($name_parts[1])) {
                                        $initials .= strtoupper(substr($name_parts[1], 0, 1));
                                    }
                                    if ($initials === '') {
                                        $initials = 'S';
                                    }

                                    $preview_entry = $conversation_map[$friend_id] ?? null;
                                    $preview_text = 'Start a conversation';
                                    $preview_time = '';

                                    if (!empty($preview_entry['latest_message'])) {
                                        $prefix = (($preview_entry['latest_direction'] ?? 'received') === 'sent') ? 'You: ' : '';
                                        $preview_body = buildMessageSnippet((string) $preview_entry['latest_message'], 60, true);
                                        if ($preview_body !== '') {
                                            $preview_text = $prefix . $preview_body;
                                        }
                                    }

                                    if (!empty($preview_entry['latest_at'])) {
                                        $preview_time = formatMessageTimeForDisplay(
                                            (string) $preview_entry['latest_at'],
                                            'M d',
                                            $message_time_display_offset_seconds
                                        );
                                    }
                                    ?>
                                    <a
                                        href="dashboard.php?tab=messages&chat_with=<?php echo $friend_id; ?>"
                                        class="messenger-conversation-item <?php echo $selected_chat_id === $friend_id ? 'active' : ''; ?>"
                                        data-conversation-friend-id="<?php echo $friend_id; ?>"
                                        data-conversation-friend-name="<?php echo htmlspecialchars($friend_name, ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        <span class="messenger-avatar">
                                            <?php if ($friend_profile_picture_url !== ''): ?>
                                                <img src="<?php echo htmlspecialchars($friend_profile_picture_url); ?>" alt="<?php echo htmlspecialchars($friend_name); ?>" loading="lazy">
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($initials); ?>
                                            <?php endif; ?>
                                            <span class="messenger-presence-dot <?php echo htmlspecialchars($friend_presence_class); ?>" data-friend-avatar-dot="<?php echo $friend_id; ?>"></span>
                                        </span>
                                        <div class="messenger-conversation-main">
                                            <div class="messenger-conversation-head">
                                                <span class="messenger-conversation-name" title="<?php echo htmlspecialchars($friend_base_name); ?>"><?php echo htmlspecialchars($friend_name); ?></span>
                                                <?php if ($preview_time !== ''): ?>
                                                    <span class="messenger-conversation-time"><?php echo htmlspecialchars($preview_time); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <span class="messenger-conversation-preview"><?php echo htmlspecialchars($preview_text); ?></span>
                                            <span class="messenger-presence-line <?php echo htmlspecialchars($friend_presence_class); ?>" data-friend-presence-line="<?php echo $friend_id; ?>">
                                                <span class="messenger-presence-dot <?php echo htmlspecialchars($friend_presence_class); ?>"></span>
                                                <span class="messenger-presence-text" data-friend-presence-text="<?php echo $friend_id; ?>"><?php echo htmlspecialchars($friend_presence_text); ?></span>
                                            </span>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </aside>

                    <section class="messenger-chat-panel">
                        <?php if ($selected_chat_friend): ?>
                            <?php
                            $chat_friend_name = (string) ($selected_chat_friend_label ?? ('Student #' . $selected_chat_id));
                            $chat_friend_base_name = (string) ($selected_chat_friend_base_name ?? $chat_friend_name);
                            $chat_friend_custom_nickname = trim((string) ($selected_chat_friend['custom_nickname'] ?? ''));
                            $chat_friend_profile_picture_url = (string) ($selected_chat_friend['profile_picture_url'] ?? '');
                            $chat_presence_class = (string) ($selected_chat_friend['presence_status_class'] ?? 'offline');
                            $chat_presence_text = (string) ($selected_chat_friend['presence_status_text'] ?? 'Offline');
                            $chat_parts = preg_split('/\s+/', trim($chat_friend_name));
                            $chat_initials = '';
                            if (!empty($chat_parts[0])) {
                                $chat_initials .= strtoupper(substr($chat_parts[0], 0, 1));
                            }
                            if (!empty($chat_parts[1])) {
                                $chat_initials .= strtoupper(substr($chat_parts[1], 0, 1));
                            }
                            if ($chat_initials === '') {
                                $chat_initials = 'S';
                            }
                            ?>
                            <header class="messenger-chat-header">
                                <button type="button" id="messengerMobileBackBtn" class="messenger-mobile-back" aria-label="Back to conversations">
                                    <i class="fas fa-arrow-left"></i>
                                </button>
                                <div class="messenger-chat-user">
                                    <span class="messenger-avatar">
                                        <?php if ($chat_friend_profile_picture_url !== ''): ?>
                                            <img src="<?php echo htmlspecialchars($chat_friend_profile_picture_url); ?>" alt="<?php echo htmlspecialchars($chat_friend_name); ?>" loading="lazy">
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($chat_initials); ?>
                                        <?php endif; ?>
                                        <span class="messenger-presence-dot <?php echo htmlspecialchars($chat_presence_class); ?>" data-friend-avatar-dot="<?php echo (int) $selected_chat_id; ?>"></span>
                                    </span>
                                    <div>
                                        <div class="messenger-conversation-name"><?php echo htmlspecialchars($chat_friend_name); ?></div>
                                        <div class="messenger-chat-status">
                                            <span class="messenger-presence-line <?php echo htmlspecialchars($chat_presence_class); ?>" data-friend-presence-line="<?php echo (int) $selected_chat_id; ?>">
                                                <span class="messenger-presence-dot <?php echo htmlspecialchars($chat_presence_class); ?>"></span>
                                                <span class="messenger-presence-text" data-friend-presence-text="<?php echo (int) $selected_chat_id; ?>"><?php echo htmlspecialchars($chat_presence_text); ?></span>
                                            </span>
                                            <span class="messenger-chat-status-extra">
                                                <?php if ($chat_friend_custom_nickname !== '' && $chat_friend_base_name !== $chat_friend_name): ?>
                                                    Name: <?php echo htmlspecialchars($chat_friend_base_name); ?>
                                                    <?php if (!empty($selected_chat_friend['ismis_id'])): ?>
                                                        · ISMIS: <?php echo htmlspecialchars($selected_chat_friend['ismis_id']); ?>
                                                    <?php endif; ?>
                                                <?php elseif (!empty($selected_chat_friend['ismis_id'])): ?>
                                                    ISMIS: <?php echo htmlspecialchars($selected_chat_friend['ismis_id']); ?>
                                                <?php else: ?>
                                                    Classmate
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="messenger-nickname-wrap">
                                    <button type="button" id="messengerNicknameToggleBtn" class="messenger-nickname-toggle-btn">
                                        <i class="fas fa-id-badge"></i>
                                        <?php echo $chat_friend_custom_nickname !== '' ? 'Nickname: ' . htmlspecialchars($chat_friend_custom_nickname) : 'Set Nickname'; ?>
                                    </button>
                                    <div id="messengerNicknamePanel" class="messenger-nickname-panel">
                                        <div class="messenger-nickname-hint">
                                            <?php echo $chat_friend_custom_nickname !== ''
                                                ? 'Current nickname: ' . htmlspecialchars($chat_friend_custom_nickname)
                                                : 'No nickname yet for this classmate.'; ?>
                                        </div>
                                        <form method="POST" action="dashboard.php?tab=messages&chat_with=<?php echo (int) $selected_chat_id; ?>" class="messenger-nickname-form">
                                            <input type="hidden" name="save_friend_nickname" value="1">
                                            <input type="hidden" name="friend_id" value="<?php echo (int) $selected_chat_id; ?>">
                                            <input
                                                type="text"
                                                name="friend_nickname"
                                                class="messenger-nickname-input"
                                                maxlength="40"
                                                placeholder="Set nickname"
                                                value="<?php echo htmlspecialchars($chat_friend_custom_nickname); ?>"
                                            >
                                            <button type="submit" class="messenger-nickname-btn">Save</button>
                                        </form>
                                    </div>
                                </div>
                            </header>

                            <div class="messenger-chat-thread" id="messengerChatThread">
                                <?php if (empty($selected_conversation_messages)): ?>
                                    <div class="messenger-chat-empty">
                                        <div>
                                            <i class="fas fa-comment-dots"></i>
                                            <p>No messages yet. Say hello to start the conversation.</p>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($selected_conversation_messages as $msg): ?>
                                        <?php
                                        $is_mine = ((int) ($msg['sender_id'] ?? 0) === (int) $student_id);
                                        $msg_id = (int) ($msg['message_id'] ?? 0);
                                        $message_raw_text = (string) ($msg['message_body'] ?? '');
                                        $message_attachment_file = trim((string) ($msg['attachment_file'] ?? ''));
                                        $message_attachment_name = trim((string) ($msg['attachment_name'] ?? ''));
                                        $message_attachment_type = strtolower(trim((string) ($msg['attachment_type'] ?? 'file')));
                                        if (strpos($message_attachment_file, '..') !== false) {
                                            $message_attachment_file = '';
                                        }
                                        $message_attachment_href = $message_attachment_file !== ''
                                            ? '../' . ltrim($message_attachment_file, '/\\')
                                            : '';
                                        $message_attachment_label = $message_attachment_name !== ''
                                            ? $message_attachment_name
                                            : 'Attachment';
                                        $reply_raw_text = trim((string) ($msg['reply_message_body'] ?? ''));
                                        $reply_sender_label = ((int) ($msg['reply_sender_id'] ?? 0) === (int) $student_id)
                                            ? 'You'
                                            : (string) ($selected_chat_friend_label ?? 'Classmate');
                                        if ($reply_raw_text !== '') {
                                            $reply_raw_text = preg_replace('/\s+/', ' ', $reply_raw_text);
                                            if ((function_exists('mb_strlen') ? mb_strlen($reply_raw_text, 'UTF-8') : strlen($reply_raw_text)) > 120) {
                                                $reply_raw_text = (function_exists('mb_substr') ? mb_substr($reply_raw_text, 0, 117, 'UTF-8') : substr($reply_raw_text, 0, 117)) . '...';
                                            }
                                        }
                                        $reaction_info = $message_reactions_map[$msg_id] ?? ['counts' => [], 'mine' => ''];
                                        $my_reaction = (string) ($reaction_info['mine'] ?? '');
                                        ?>
                                        <div class="messenger-bubble-row <?php echo $is_mine ? 'mine' : 'theirs'; ?>" data-message-id="<?php echo $msg_id; ?>">
                                            <div class="messenger-bubble messenger-reaction-target" data-message-id="<?php echo $msg_id; ?>">
                                                <?php if (!empty($msg['reply_to_message_id']) && $reply_raw_text !== ''): ?>
                                                    <div class="messenger-reply-quote">
                                                        <strong>Replying to <?php echo htmlspecialchars($reply_sender_label); ?></strong>
                                                        <span><?php echo htmlspecialchars($reply_raw_text); ?></span>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (trim($message_raw_text) !== ''): ?>
                                                    <div class="messenger-message-text"><?php echo nl2br(htmlspecialchars($message_raw_text)); ?></div>
                                                <?php endif; ?>
                                                <?php if ($message_attachment_href !== ''): ?>
                                                    <div class="messenger-attachment-wrap">
                                                        <?php if ($message_attachment_type === 'image'): ?>
                                                            <a href="<?php echo htmlspecialchars($message_attachment_href); ?>" target="_blank" rel="noopener" class="messenger-attachment-link">
                                                                <img src="<?php echo htmlspecialchars($message_attachment_href); ?>" alt="<?php echo htmlspecialchars($message_attachment_label); ?>" class="messenger-attachment-image" loading="lazy">
                                                            </a>
                                                        <?php elseif ($message_attachment_type === 'audio'): ?>
                                                            <audio controls preload="none" class="messenger-attachment-audio">
                                                                <source src="<?php echo htmlspecialchars($message_attachment_href); ?>">
                                                            </audio>
                                                            <a href="<?php echo htmlspecialchars($message_attachment_href); ?>" target="_blank" rel="noopener" class="messenger-attachment-link" style="margin-top: 0.35rem;">
                                                                <i class="fas fa-download"></i> <?php echo htmlspecialchars($message_attachment_label); ?>
                                                            </a>
                                                        <?php else: ?>
                                                            <a href="<?php echo htmlspecialchars($message_attachment_href); ?>" target="_blank" rel="noopener" class="messenger-attachment-link">
                                                                <i class="fas fa-paperclip"></i> <?php echo htmlspecialchars($message_attachment_label); ?>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="messenger-bubble-meta">
                                                <?php
                                                $message_sent_display_time = formatMessageTimeForDisplay(
                                                    (string) ($msg['sent_at'] ?? ''),
                                                    'M d, h:i A',
                                                    $message_time_display_offset_seconds
                                                );
                                                echo htmlspecialchars($message_sent_display_time !== '' ? $message_sent_display_time : date('M d, h:i A'));
                                                ?>
                                                <?php if ($is_mine): ?>
                                                    <?php echo !empty($msg['read_at']) ? ' · Seen' : ' · Delivered'; ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="messenger-message-actions">
                                                <button
                                                    type="button"
                                                    class="messenger-reply-btn"
                                                    data-reply-id="<?php echo $msg_id; ?>"
                                                    data-reply-sender="<?php echo htmlspecialchars($is_mine ? 'You' : (string) ($selected_chat_friend_label ?? 'Classmate'), ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-reply-text="<?php echo htmlspecialchars(preg_replace('/\s+/', ' ', $message_raw_text), ENT_QUOTES, 'UTF-8'); ?>"
                                                >
                                                    <i class="fas fa-reply"></i> Reply
                                                </button>
                                                <button
                                                    type="button"
                                                    class="messenger-react-toggle-btn"
                                                    data-toggle-reactions
                                                    data-message-id="<?php echo $msg_id; ?>"
                                                    aria-expanded="false"
                                                    aria-controls="reactionPicker<?php echo $msg_id; ?>"
                                                >
                                                    <i class="fas fa-smile"></i> React
                                                </button>
                                                <?php
                                                $has_reaction_counts = false;
                                                foreach ($allowed_message_reactions as $emoji_option) {
                                                    if ((int) ($reaction_info['counts'][$emoji_option] ?? 0) > 0) {
                                                        $has_reaction_counts = true;
                                                        break;
                                                    }
                                                }
                                                ?>
                                                <?php if ($has_reaction_counts): ?>
                                                    <div class="messenger-reaction-summary">
                                                        <?php foreach ($allowed_message_reactions as $emoji): ?>
                                                            <?php $emoji_count = (int) ($reaction_info['counts'][$emoji] ?? 0); ?>
                                                            <?php if ($emoji_count <= 0) {
                                                                continue;
                                                            } ?>
                                                            <span class="messenger-reaction-chip <?php echo $my_reaction === $emoji ? 'active' : ''; ?>">
                                                                <span><?php echo htmlspecialchars($emoji); ?></span>
                                                                <small><?php echo $emoji_count; ?></small>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="messenger-reaction-picker" id="reactionPicker<?php echo $msg_id; ?>" data-reaction-picker-for="<?php echo $msg_id; ?>">
                                                    <form method="POST" action="dashboard.php?tab=messages&chat_with=<?php echo (int) $selected_chat_id; ?>" class="messenger-reaction-form">
                                                        <input type="hidden" name="react_to_message" value="1">
                                                        <input type="hidden" name="recipient_id" value="<?php echo (int) $selected_chat_id; ?>">
                                                        <input type="hidden" name="message_id" value="<?php echo $msg_id; ?>">
                                                        <div class="messenger-reaction-list">
                                                            <?php foreach ($allowed_message_reactions as $emoji): ?>
                                                                <button type="submit" name="reaction_emoji" value="<?php echo htmlspecialchars($emoji); ?>" class="messenger-reaction-btn <?php echo $my_reaction === $emoji ? 'active' : ''; ?>">
                                                                    <span><?php echo htmlspecialchars($emoji); ?></span>
                                                                </button>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </form>
                                                    <?php if ($is_mine): ?>
                                                        <form
                                                            method="POST"
                                                            action="dashboard.php?tab=messages&chat_with=<?php echo (int) $selected_chat_id; ?>"
                                                            class="messenger-delete-message-form"
                                                            onsubmit="return confirm('Delete this message? This cannot be undone.');"
                                                        >
                                                            <input type="hidden" name="delete_message" value="1">
                                                            <input type="hidden" name="recipient_id" value="<?php echo (int) $selected_chat_id; ?>">
                                                            <input type="hidden" name="message_id" value="<?php echo $msg_id; ?>">
                                                            <button type="submit" class="messenger-delete-message-btn">
                                                                <i class="fas fa-trash"></i> Delete Message
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <form method="POST" action="dashboard.php?tab=messages&chat_with=<?php echo (int) $selected_chat_id; ?>" class="messenger-composer" id="messengerComposerForm" enctype="multipart/form-data">
                                <input type="hidden" name="recipient_id" value="<?php echo (int) $selected_chat_id; ?>">
                                <input type="hidden" name="send_message" value="1">
                                <input type="hidden" name="reply_to_message_id" id="replyToMessageId" value="<?php echo (int) $reply_to_message_prefill_id; ?>">
                                <div class="messenger-reply-preview <?php echo !empty($reply_preview_message) ? 'show' : ''; ?>" id="messengerReplyPreview">
                                    <div class="messenger-reply-preview-main">
                                        <strong id="replyPreviewSender"><?php echo htmlspecialchars((string) ($reply_preview_message['sender'] ?? '')); ?></strong>
                                        <span id="replyPreviewText"><?php echo htmlspecialchars((string) ($reply_preview_message['body'] ?? '')); ?></span>
                                    </div>
                                    <button type="button" class="messenger-reply-clear" id="clearReplyBtn" aria-label="Cancel reply">&times;</button>
                                </div>
                                <div class="messenger-input-row">
                                    <textarea
                                        id="messengerMessageInput"
                                        name="message_body"
                                        maxlength="1000"
                                        placeholder="Type a message..."
                                    ><?php echo (isset($_POST['message_body']) && (int) ($_POST['recipient_id'] ?? 0) === (int) $selected_chat_id) ? htmlspecialchars((string) $_POST['message_body']) : ''; ?></textarea>
                                    <button id="messengerSendButton" type="submit" class="messenger-send-btn" aria-label="Send message">
                                        <i class="fas fa-paper-plane"></i>
                                        <span class="messenger-send-inline-label">Send</span>
                                    </button>
                                </div>
                                <div class="messenger-attachment-picker">
                                    <input
                                        type="file"
                                        id="messageAttachmentInput"
                                        class="messenger-attachment-input"
                                        name="message_attachment"
                                        accept="image/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.rar,.7z"
                                    >
                                    <div class="messenger-attachment-controls">
                                        <div class="messenger-attachment-trigger-group">
                                            <button type="button" class="messenger-attach-trigger" id="messageAttachmentTrigger" aria-label="Attach a file" title="Attach a file">
                                                <i class="fas fa-paperclip"></i>
                                            </button>
                                            <button type="button" class="messenger-attach-trigger messenger-attach-image-trigger" id="messageImageTrigger" aria-label="Attach from gallery" title="Attach from gallery">
                                                <i class="fas fa-image"></i>
                                            </button>
                                        </div>
                                        <div class="messenger-attachment-selected" id="messageAttachmentSelected" aria-live="polite">
                                            <i class="fas fa-file" id="messageAttachmentSelectedIcon"></i>
                                            <span id="messageAttachmentName">No file selected</span>
                                            <button type="button" class="messenger-attachment-clear" id="messageAttachmentClearBtn" aria-label="Remove selected file">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="messenger-attachment-hint" id="messageAttachmentHint">Optional: use file or gallery buttons (max 15 MB).</div>
                                </div>
                                <div class="messenger-composer-actions">
                                    <span class="messenger-empty-chat-note">Press Enter to send. Shift + Enter for a new line.</span>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="messenger-chat-empty">
                                <div>
                                    <i class="fas fa-comments"></i>
                                    <p>Select a friend from the left panel to start chatting.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>

                <form method="POST" action="dashboard.php?tab=messages" id="deleteConversationForm" style="display: none;">
                    <input type="hidden" name="delete_conversation" value="1">
                    <input type="hidden" name="friend_id" id="deleteConversationFriendId" value="0">
                </form>
            </div>
        </main>
    </div>

    <!-- Upload Proof Modal -->
    <div id="uploadModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-upload"></i> Upload Proof</h3>
                <button class="close" onclick="closeUploadModal()">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data" id="uploadForm">
                <div class="modal-body">
                    <input type="hidden" name="clearance_id" id="uploadClearanceId">
                    <input type="hidden" name="office_name" id="uploadOfficeName">
                    <input type="hidden" name="org_clearance_id" id="uploadOrgClearanceId">
                    <input type="hidden" name="upload_target_type" id="uploadTargetType" value="office">
                    <input type="hidden" name="upload_target_name" id="uploadTargetName">

                    <div class="info-card"
                        style="background: rgba(14, 165, 233, 0.1); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                        <i class="fas fa-info-circle" style="color: var(--proof);"></i>
                        <span style="color: var(--proof);" id="uploadTargetHelp">Upload proof that you have resolved the lacking items. This
                            will be sent to the office for review.</span>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-file"></i> Select File <span class="required">*</span></label>
                        <input type="file" name="proof_file" id="proofFile" class="form-control"
                            accept=".pdf,.jpg,.jpeg,.png" required>
                        <small style="color: var(--text-light);">Allowed: PDF, JPG, PNG (Max 5MB)</small>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-comment"></i> Remarks (Optional)</label>
                        <textarea name="proof_remarks" id="proofRemarks" class="form-control" rows="3"
                            placeholder="e.g., I have returned the books and paid the fines."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeUploadModal()">Cancel</button>
                    <button type="submit" name="upload_proof" class="btn-proof-upload">
                        <i class="fas fa-upload"></i> Upload Proof
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content large">
            <div class="modal-header">
                <h3><i class="fas fa-info-circle"></i> Clearance Details</h3>
                <button class="close" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div class="modal-body" id="detailsModalBody">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeDetailsModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- Edit Profile Modal -->
    <div id="profileModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> Edit Profile</h3>
                <button class="close" onclick="closeProfileModal()">&times;</button>
            </div>
            <form method="POST" action="" id="profileForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label for="profileFname"><i class="fas fa-user"></i> First Name</label>
                        <input type="text" id="profileFname" name="fname" class="form-control"
                            value="<?php echo htmlspecialchars($student_info['fname'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="profileLname"><i class="fas fa-user"></i> Last Name</label>
                        <input type="text" id="profileLname" name="lname" class="form-control"
                            value="<?php echo htmlspecialchars($student_info['lname'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="profileEmail"><i class="fas fa-envelope"></i> Email</label>
                        <input type="email" id="profileEmail" name="email" class="form-control"
                            value="<?php echo htmlspecialchars($student_info['emails'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="profileContact"><i class="fas fa-phone"></i> Contact</label>
                        <input type="text" id="profileContact" name="contact" class="form-control"
                            value="<?php echo htmlspecialchars($student_info['contacts'] ?? ''); ?>"
                            placeholder="e.g. 09XXXXXXXXX">
                    </div>

                    <div class="form-group">
                        <label for="profileAddress"><i class="fas fa-map-marker-alt"></i> Address</label>
                        <textarea id="profileAddress" name="address" class="form-control" rows="3"
                            placeholder="Current address"><?php echo htmlspecialchars($student_info['address'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="profileAge"><i class="fas fa-birthday-cake"></i> Age</label>
                        <input type="number" id="profileAge" name="age" class="form-control" min="10" max="120"
                            value="<?php echo htmlspecialchars((string) ($student_info['age'] ?? '')); ?>">
                    </div>

                    <div style="height: 1px; background: var(--border); margin: 1rem 0 1.2rem;"></div>

                    <div class="form-group">
                        <label for="profileCurrentPassword"><i class="fas fa-lock"></i> Current Password</label>
                        <div class="password-field">
                            <input type="password" id="profileCurrentPassword" name="current_password" class="form-control"
                                autocomplete="current-password" placeholder="Enter current password to change it">
                            <button type="button" class="password-toggle-btn"
                                onclick="togglePasswordVisibility('profileCurrentPassword', 'profileCurrentPasswordIcon')"
                                aria-label="Show or hide current password">
                                <i class="fas fa-eye" id="profileCurrentPasswordIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="profileNewPassword"><i class="fas fa-key"></i> New Password</label>
                        <div class="password-field">
                            <input type="password" id="profileNewPassword" name="new_password" class="form-control"
                                minlength="8" autocomplete="new-password" placeholder="Minimum 8 characters">
                            <button type="button" class="password-toggle-btn"
                                onclick="togglePasswordVisibility('profileNewPassword', 'profileNewPasswordIcon')"
                                aria-label="Show or hide new password">
                                <i class="fas fa-eye" id="profileNewPasswordIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="profileConfirmNewPassword"><i class="fas fa-key"></i> Confirm New Password</label>
                        <div class="password-field">
                            <input type="password" id="profileConfirmNewPassword" name="confirm_new_password"
                                class="form-control" minlength="8" autocomplete="new-password"
                                placeholder="Re-enter new password">
                            <button type="button" class="password-toggle-btn"
                                onclick="togglePasswordVisibility('profileConfirmNewPassword', 'profileConfirmNewPasswordIcon')"
                                aria-label="Show or hide confirm password">
                                <i class="fas fa-eye" id="profileConfirmNewPasswordIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="info-card"
                        style="background: rgba(25, 118, 210, 0.08); padding: 0.9rem; border-radius: 8px; margin-top: 0.5rem;">
                        <i class="fas fa-info-circle" style="color: var(--info);"></i>
                        <span style="color: var(--info);">College, course, and ISMIS ID are managed by the registrar and cannot be edited here. To change password, complete all 3 password fields.</span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeProfileModal()">Cancel</button>
                    <button type="submit" name="update_profile" class="btn-proof-upload">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- First Login Guide Modal -->
    <div id="onboardingModal" class="modal" aria-hidden="true">
        <div class="modal-content guide-modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-map-signs"></i> Student System Guide</h3>
                <button class="close" type="button" onclick="closeOnboardingGuide(true)">&times;</button>
            </div>
            <div class="modal-body">
                <div id="onboardingStepPill" class="guide-step-pill"></div>
                <h4 id="onboardingStepTitle" class="guide-title"></h4>
                <p id="onboardingStepDescription" class="guide-description"></p>
                <div id="onboardingStepPoints" class="guide-points"></div>
                <div id="onboardingProgress" class="guide-progress" aria-hidden="true"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="onboardingPrevBtn">Back</button>
                <button type="button" class="btn-secondary" id="onboardingSkipBtn">Skip</button>
                <button type="button" class="btn-proof-upload" id="onboardingNextBtn">Next</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = document.getElementById('themeIcon');
        const body = document.body;
        const mobileNavToggle = document.getElementById('mobileNavToggle');
        const mobileNavBackdrop = document.getElementById('mobileNavBackdrop');
        const studentSidebar = document.getElementById('studentSidebar');
        const messengerShell = document.getElementById('messengerShell');
        const messengerMobileBackBtn = document.getElementById('messengerMobileBackBtn');
        const onboardingModal = document.getElementById('onboardingModal');
        const onboardingStepPill = document.getElementById('onboardingStepPill');
        const onboardingStepTitle = document.getElementById('onboardingStepTitle');
        const onboardingStepDescription = document.getElementById('onboardingStepDescription');
        const onboardingStepPoints = document.getElementById('onboardingStepPoints');
        const onboardingProgress = document.getElementById('onboardingProgress');
        const onboardingPrevBtn = document.getElementById('onboardingPrevBtn');
        const onboardingSkipBtn = document.getElementById('onboardingSkipBtn');
        const onboardingNextBtn = document.getElementById('onboardingNextBtn');
        const onboardingStorageKey = 'student_guide_seen_<?php echo (int) $student_id; ?>';
        const applyClearanceForm = document.getElementById('applyClearanceForm');
        const applyClearanceType = document.getElementById('applyClearanceType');
        const dashboardLogoutLinks = Array.from(document.querySelectorAll('a[href*="logout.php"]'));
        const dashboardBackGuardKey = '__studentDashboardBackGuard';
        let allowDashboardBackExit = false;
        let lastBackGuardToastAt = 0;

        function normalizeClearanceTypeValue(value) {
            return String(value || '')
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '');
        }

        function isGraduatingOnlyClearanceValue(value) {
            const normalizedValue = normalizeClearanceTypeValue(value);
            if (normalizedValue.includes('nongraduating')) {
                return false;
            }

            return normalizedValue.includes('graduating');
        }

        function showGraduatingComingSoonAlert() {
            const title = 'COMING SOON';
            const message = 'Graduating Clearance is not available yet. Please select Non-Graduating Clearance for now.';

            if (window.Swal && typeof window.Swal.fire === 'function') {
                window.Swal.fire({
                    icon: 'info',
                    title,
                    text: message,
                    confirmButtonText: 'Exit',
                    customClass: {
                        popup: 'graduating-alert-popup',
                        title: 'graduating-alert-title',
                        htmlContainer: 'graduating-alert-text',
                        confirmButton: 'graduating-alert-confirm'
                    },
                    buttonsStyling: false
                });
                return;
            }

            window.alert(`${title}\n${message}`);
        }

        function enforceAvailableClearanceTypeSelection(selectElement) {
            if (!selectElement) {
                return true;
            }

            const selectedOption = selectElement.options[selectElement.selectedIndex] || null;
            const selectedValue = String(selectElement.value || '');
            const isComingSoonOption = selectedOption && selectedOption.dataset.comingSoon === '1';

            if (!isComingSoonOption && !isGraduatingOnlyClearanceValue(selectedValue)) {
                return true;
            }

            selectElement.value = '';
            showGraduatingComingSoonAlert();
            return false;
        }

        applyClearanceType?.addEventListener('change', () => {
            enforceAvailableClearanceTypeSelection(applyClearanceType);
        });

        applyClearanceForm?.addEventListener('submit', (event) => {
            if (!enforceAvailableClearanceTypeSelection(applyClearanceType)) {
                event.preventDefault();
            }
        });

        const onboardingSteps = [
            {
                icon: 'house',
                title: 'Dashboard Overview',
                description: 'This home screen summarizes your progress for the current semester and school year.',
                points: [
                    'Use the hero card to quickly track your active clearance.',
                    'Use the stat cards to monitor pending, approved, and rejected checks.'
                ]
            },
            {
                icon: 'file-signature',
                title: 'Apply For Clearance',
                description: 'Open the Apply tab to submit a new clearance request when you have no active pending application.',
                points: [
                    'Choose clearance type, semester, and school year carefully.',
                    'Only one pending clearance cycle is allowed at a time.'
                ]
            },
            {
                icon: 'clipboard-check',
                title: 'Track Status And Upload Proof',
                description: 'The Status tab shows each required office and organization plus any lacking comments.',
                points: [
                    'If an office or organization asks for proof, use Upload Proof directly from the card.',
                    'Watch status badges to know what still needs action.'
                ]
            },
            {
                icon: 'clock-rotate-left',
                title: 'Review Your History',
                description: 'The History tab keeps your previous clearance records for reference.',
                points: [
                    'Filter by year and status to quickly find past applications.',
                    'Open details to review remarks and uploaded files.'
                ]
            },
            {
                icon: 'comments',
                title: 'Messages And Notifications',
                description: 'Use Messages for student communication and monitor the red badge for new activity.',
                points: [
                    'You can add classmates by ISMIS ID before sending messages.',
                    'New office and organization comments will trigger notifications.'
                ]
            }
        ];

        let onboardingIndex = 0;

        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
            body.classList.add('dark-mode');
            themeIcon.classList.remove('fa-moon');
            themeIcon.classList.add('fa-sun');
        }

        themeToggle.addEventListener('click', () => {
            body.classList.toggle('dark-mode');
            if (body.classList.contains('dark-mode')) {
                localStorage.setItem('theme', 'dark');
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
            } else {
                localStorage.setItem('theme', 'light');
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
            }
        });

        // Tab switching
        function switchTab(tabName) {
            if (tabName === 'messages') {
                const messagesUrl = new URL(window.location.href);
                messagesUrl.searchParams.set('tab', 'messages');
                window.location.href = messagesUrl.pathname + messagesUrl.search;
                return;
            }

            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.getElementById(tabName).classList.add('active');

            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });

            const activeTrigger = document.querySelector(`.nav-item[data-tab="${tabName}"]`);

            if (activeTrigger) {
                activeTrigger.classList.add('active');
            }

            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);

            closeMobileNav();
        }

        function setDashboardExitAllowed(value) {
            allowDashboardBackExit = value === true;
        }

        function initializeDashboardBackGuard() {
            if (!window.history || typeof window.history.pushState !== 'function') {
                return;
            }

            const existingState = (window.history.state && typeof window.history.state === 'object')
                ? window.history.state
                : {};

            if (!existingState[dashboardBackGuardKey]) {
                const rootState = Object.assign({}, existingState, {
                    [dashboardBackGuardKey]: 'root'
                });
                window.history.replaceState(rootState, '', window.location.href);
            }

            window.history.pushState({
                [dashboardBackGuardKey]: 'lock',
                at: Date.now()
            }, '', window.location.href);

            window.addEventListener('popstate', () => {
                if (allowDashboardBackExit) {
                    return;
                }

                const currentUrl = new URL(window.location.href);
                const currentTab = currentUrl.searchParams.get('tab') || 'dashboard';

                if (currentTab !== 'dashboard') {
                    switchTab('dashboard');
                } else {
                    window.history.pushState({
                        [dashboardBackGuardKey]: 'lock',
                        at: Date.now()
                    }, '', window.location.href);
                }

                const now = Date.now();
                if (now - lastBackGuardToastAt > 1800) {
                    lastBackGuardToastAt = now;
                    showToast('Use Logout to leave your account safely.', 'info');
                }
            });
        }

        dashboardLogoutLinks.forEach((link) => {
            link.addEventListener('click', () => {
                setDashboardExitAllowed(true);
            });
        });

        initializeDashboardBackGuard();

        function setMobileNavToggleState(isOpen) {
            if (!mobileNavToggle) {
                return;
            }

            mobileNavToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            mobileNavToggle.classList.toggle('active', isOpen);
            mobileNavToggle.innerHTML = isOpen
                ? '<i class="fas fa-times"></i><span>Close Navigation</span>'
                : '<i class="fas fa-bars"></i><span>Open Navigation</span>';
        }

        function updateMobileNavLayout() {
            if (!studentSidebar) {
                return;
            }

            const headerEl = document.querySelector('.header');
            const headerHeight = headerEl ? headerEl.offsetHeight : 0;
            document.documentElement.style.setProperty('--mobile-header-offset', `${headerHeight}px`);
        }

        function renderOnboardingStep() {
            if (!onboardingModal || !onboardingSteps.length) {
                return;
            }

            const step = onboardingSteps[onboardingIndex];
            onboardingStepPill.innerHTML = `<i class="fas fa-${step.icon}"></i> Step ${onboardingIndex + 1} of ${onboardingSteps.length}`;
            onboardingStepTitle.textContent = step.title;
            onboardingStepDescription.textContent = step.description;
            onboardingStepPoints.innerHTML = step.points
                .map(point => `<div class="guide-point"><i class="fas fa-check-circle"></i><span>${point}</span></div>`)
                .join('');

            onboardingProgress.innerHTML = onboardingSteps
                .map((_, idx) => `<span class="guide-dot ${idx === onboardingIndex ? 'active' : ''}"></span>`)
                .join('');

            onboardingPrevBtn.disabled = onboardingIndex === 0;
            onboardingNextBtn.innerHTML = onboardingIndex === onboardingSteps.length - 1
                ? '<i class="fas fa-flag-checkered"></i> Finish Guide'
                : 'Next';
        }

        function openOnboardingGuide(forceOpen = false) {
            if (!onboardingModal) {
                return;
            }

            const hasSeenGuide = localStorage.getItem(onboardingStorageKey) === '1';
            if (!forceOpen && hasSeenGuide) {
                return;
            }

            onboardingIndex = 0;
            renderOnboardingStep();
            onboardingModal.style.display = 'flex';
            onboardingModal.setAttribute('aria-hidden', 'false');
        }

        function closeOnboardingGuide(markAsSeen = true) {
            if (!onboardingModal) {
                return;
            }

            onboardingModal.style.display = 'none';
            onboardingModal.setAttribute('aria-hidden', 'true');
            if (markAsSeen) {
                localStorage.setItem(onboardingStorageKey, '1');
            }
        }

        onboardingPrevBtn?.addEventListener('click', () => {
            if (onboardingIndex > 0) {
                onboardingIndex -= 1;
                renderOnboardingStep();
            }
        });

        onboardingNextBtn?.addEventListener('click', () => {
            if (onboardingIndex >= onboardingSteps.length - 1) {
                closeOnboardingGuide(true);
                return;
            }

            onboardingIndex += 1;
            renderOnboardingStep();
        });

        onboardingSkipBtn?.addEventListener('click', () => {
            closeOnboardingGuide(true);
        });

        function openMobileNav() {
            if (!studentSidebar || !mobileNavToggle || window.innerWidth > 768) {
                return;
            }

            updateMobileNavLayout();
            studentSidebar.classList.add('mobile-open');
            mobileNavBackdrop?.classList.add('show');
            body.classList.add('mobile-nav-open');
            setMobileNavToggleState(true);
        }

        function closeMobileNav() {
            if (!studentSidebar || !mobileNavToggle) {
                return;
            }

            studentSidebar.classList.remove('mobile-open');
            mobileNavBackdrop?.classList.remove('show');
            body.classList.remove('mobile-nav-open');
            setMobileNavToggleState(false);
        }

        function toggleMobileNav() {
            if (!studentSidebar || window.innerWidth > 768) {
                return;
            }

            if (studentSidebar.classList.contains('mobile-open')) {
                closeMobileNav();
            } else {
                openMobileNav();
            }
        }

        function openMessengerConversationList() {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', 'messages');
            url.searchParams.delete('chat_with');
            window.location.href = url.pathname + url.search;
        }

        mobileNavToggle?.addEventListener('click', toggleMobileNav);
        mobileNavBackdrop?.addEventListener('click', closeMobileNav);
        messengerMobileBackBtn?.addEventListener('click', openMessengerConversationList);
        updateMobileNavLayout();
        setMobileNavToggleState(false);
        window.addEventListener('resize', () => {
            updateMobileNavLayout();
            if (window.innerWidth > 768) {
                closeMobileNav();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeOnboardingGuide(false);
                closeMobileNav();
            }
        });

        document.querySelectorAll('[data-switch-tab]').forEach(card => {
            card.addEventListener('click', (event) => {
                if (event.target.closest('button, a, form, input, select, textarea')) {
                    return;
                }

                const tabName = card.getAttribute('data-switch-tab');
                if (tabName) {
                    switchTab(tabName);
                }
            });

            card.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }

                event.preventDefault();
                const tabName = card.getAttribute('data-switch-tab');
                if (tabName) {
                    switchTab(tabName);
                }
            });
        });

        // Upload Modal
        function openUploadModal(clearanceId, officeName, targetType = 'office', orgClearanceId = 0) {
            document.getElementById('uploadClearanceId').value = clearanceId;
            document.getElementById('uploadOfficeName').value = officeName;
            document.getElementById('uploadOrgClearanceId').value = orgClearanceId || '';
            document.getElementById('uploadTargetType').value = targetType;
            document.getElementById('uploadTargetName').value = officeName;
            document.getElementById('uploadTargetHelp').textContent = targetType === 'organization'
                ? `Upload proof for ${officeName}. This will be sent to the organization for review.`
                : 'Upload proof that you have resolved the lacking items. This will be sent to the office for review.';
            document.getElementById('uploadModal').style.display = 'flex';
        }

        function closeUploadModal() {
            document.getElementById('uploadModal').style.display = 'none';
            document.getElementById('proofFile').value = '';
            document.getElementById('proofRemarks').value = '';
            document.getElementById('uploadOrgClearanceId').value = '';
            document.getElementById('uploadTargetType').value = 'office';
            document.getElementById('uploadTargetName').value = '';
            document.getElementById('uploadTargetHelp').textContent = 'Upload proof that you have resolved the lacking items. This will be sent to the office for review.';
        }

        // Auto-hide toasts
        setTimeout(() => {
            document.querySelectorAll('.toast').forEach(toast => {
                toast.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            });
        }, 5000);

        // Filter functions
        function filterStatusTable() {
            const statusFilter = document.getElementById('statusFilter').value.toLowerCase();
            const dateFilter = document.getElementById('dateFilter').value.toLowerCase();

            document.querySelectorAll('#statusTimeline .timeline-item').forEach(item => {
                const status = item.getAttribute('data-status').toLowerCase();
                const date = item.getAttribute('data-date').toLowerCase();

                const matchesStatus = !statusFilter || status.includes(statusFilter);
                const matchesDate = !dateFilter || date.includes(dateFilter);

                item.style.display = matchesStatus && matchesDate ? 'block' : 'none';
            });
        }

        function clearStatusFilters() {
            document.getElementById('statusFilter').value = '';
            document.getElementById('dateFilter').value = '';
            document.querySelectorAll('#statusTimeline .timeline-item').forEach(item => {
                item.style.display = 'block';
            });
        }

        function filterHistory() {
            const statusFilter = document.getElementById('historyStatusFilter').value.toLowerCase();
            const yearFilter = document.getElementById('historyYearFilter').value;

            document.querySelectorAll('#historyTimeline .timeline-item').forEach(item => {
                const status = item.getAttribute('data-status').toLowerCase();
                const year = item.getAttribute('data-year');

                const matchesStatus = !statusFilter || status.includes(statusFilter);
                const matchesYear = !yearFilter || year === yearFilter;

                item.style.display = matchesStatus && matchesYear ? 'block' : 'none';
            });
        }

        function clearHistoryFilters() {
            document.getElementById('historyStatusFilter').value = '';
            document.getElementById('historyYearFilter').value = '';
            document.querySelectorAll('#historyTimeline .timeline-item').forEach(item => {
                item.style.display = 'block';
            });
        }

        function toggleHistoryDetails(detailId) {
            const detailsPanel = document.getElementById(detailId);
            if (!detailsPanel) {
                return;
            }

            const detailsItem = detailsPanel.closest('.timeline-item');
            const detailsToggleButton = detailsItem ? detailsItem.querySelector('.history-toggle-btn') : null;
            const detailsToggleLabel = detailsToggleButton ? detailsToggleButton.querySelector('.history-toggle-label') : null;
            const willOpen = detailsPanel.hasAttribute('hidden');

            document.querySelectorAll('#historyTimeline .history-details-panel').forEach(panel => {
                if (panel.id === detailId) {
                    return;
                }

                panel.setAttribute('hidden', 'hidden');

                const panelItem = panel.closest('.timeline-item');
                if (panelItem) {
                    panelItem.classList.remove('history-open');
                    const panelButton = panelItem.querySelector('.history-toggle-btn');
                    if (panelButton) {
                        panelButton.setAttribute('aria-expanded', 'false');
                        const panelLabel = panelButton.querySelector('.history-toggle-label');
                        if (panelLabel) {
                            panelLabel.textContent = 'View Details';
                        }
                    }
                }
            });

            if (willOpen) {
                detailsPanel.removeAttribute('hidden');
                if (detailsItem) {
                    detailsItem.classList.add('history-open');
                }
                if (detailsToggleButton) {
                    detailsToggleButton.setAttribute('aria-expanded', 'true');
                }
                if (detailsToggleLabel) {
                    detailsToggleLabel.textContent = 'Hide Details';
                }
            } else {
                detailsPanel.setAttribute('hidden', 'hidden');
                if (detailsItem) {
                    detailsItem.classList.remove('history-open');
                }
                if (detailsToggleButton) {
                    detailsToggleButton.setAttribute('aria-expanded', 'false');
                }
                if (detailsToggleLabel) {
                    detailsToggleLabel.textContent = 'View Details';
                }
            }
        }

        // View Details
        function viewDetails(item) {
            const modal = document.getElementById('detailsModal');
            const modalBody = document.getElementById('detailsModalBody');
            const isOrganization = item.item_type === 'organization';
            const displayName = isOrganization ? (item.display_name || item.org_name || 'Organization') : (item.office_name ? item.office_name.replace('_', ' ') : 'N/A');
            const targetLabel = isOrganization ? 'Organization' : 'Office';
            const safeDisplayNameForHandler = String(displayName || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");

            let lackingHtml = '';
            if (item.lacking_comment) {
                lackingHtml = `
                    <div style="margin-top: 1rem; padding: 1rem; background: rgba(249, 115, 22, 0.1); border-radius: 8px;">
                        <h4 style="color: var(--lacking); margin-bottom: 0.5rem;">
                            <i class="fas fa-exclamation-triangle"></i> Lacking Items
                        </h4>
                        <p>${item.lacking_comment}</p>
                        ${item.lacking_comment_at ? '<small>Since: ' + new Date(item.lacking_comment_at).toLocaleString() + '</small>' : ''}
                        ${isOrganization ? '<div style="margin-top: 0.75rem;"><button class="upload-btn" onclick="openUploadModal(' + Number(item.clearance_id || 0) + ', \'' + safeDisplayNameForHandler + '\', \'organization\', ' + Number(item.org_clearance_id || 0) + '); closeDetailsModal();"><i class="fas fa-upload"></i> Upload Proof for ' + displayName + '</button></div>' : ''}
                    </div>
                `;
            }

            let proofHtml = '';
            if (item.student_proof_file) {
                proofHtml = `
                    <div style="margin-top: 1rem; padding: 1rem; background: rgba(14, 165, 233, 0.1); border-radius: 8px;">
                        <h4 style="color: var(--proof); margin-bottom: 0.5rem;">
                            <i class="fas fa-check-circle"></i> Your Proof
                        </h4>
                        <div class="file-info">
                            <i class="fas fa-file"></i>
                            <a href="../${item.student_proof_file}" target="_blank">View Uploaded Proof</a>
                        </div>
                        ${item.student_proof_remarks ? '<p><strong>Remarks:</strong> ' + item.student_proof_remarks + '</p>' : ''}
                        ${item.student_proof_uploaded_at ? '<small>Uploaded: ' + new Date(item.student_proof_uploaded_at).toLocaleString() + '</small>' : ''}
                    </div>
                `;
            }

            modalBody.innerHTML = `
                <div style="display: grid; gap: 1rem;">
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                        <strong>${targetLabel}:</strong> 
                        <span>${displayName}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                        <strong>Type:</strong> 
                        <span>${item.clearance_type_name || 'N/A'}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                        <strong>Semester:</strong> 
                        <span>${item.semester || 'N/A'} ${item.school_year || ''}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                        <strong>Status:</strong> 
                        <span class="office-status ${item.status}" style="display: inline-block;">${item.status}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                        <strong>Applied:</strong> 
                        <span>${item.formatted_date || 'N/A'}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                        <strong>Processed:</strong> 
                        <span>${item.formatted_processed_date || 'Not processed yet'}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                        <strong>Processed By:</strong> 
                        <span>${item.processed_by_name || 'N/A'}</span>
                    </div>
                    ${lackingHtml}
                    ${proofHtml}
                    <div style="display: flex; flex-direction: column; gap: 0.5rem; padding: 0.5rem 0;">
                        <strong>Remarks:</strong> 
                        <p style="background: var(--bg); padding: 1rem; border-radius: 8px;">${(isOrganization ? (item.clean_remarks || item.remarks) : item.remarks) || 'No remarks'}</p>
                    </div>
                </div>
            `;

            modal.style.display = 'flex';
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }

        // Profile modal
        function openProfileModal() {
            document.getElementById('profileModal').style.display = 'flex';
        }

        function closeProfileModal() {
            document.getElementById('profileModal').style.display = 'none';
        }

        function togglePasswordVisibility(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);

            if (!input || !icon) {
                return;
            }

            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';

            icon.classList.toggle('fa-eye', !isPassword);
            icon.classList.toggle('fa-eye-slash', isPassword);
        }

        // Avatar upload
        const avatarInput = document.getElementById('avatarUpload');
        const avatarContainer = document.getElementById('avatarContainer');
        const uploadProgress = document.getElementById('uploadProgress');
        const profileImage = document.getElementById('profileImage');

        if (avatarContainer) {
            avatarContainer.addEventListener('click', () => avatarInput.click());
        }

        if (avatarInput) {
            avatarInput.addEventListener('change', function (e) {
                const file = e.target.files[0];
                if (!file) return;

                const validTypes = ['image/jpeg', 'image/png', 'image/gif'];
                if (!validTypes.includes(file.type)) {
                    alert('Please select a valid image file (JPG, PNG, GIF)');
                    return;
                }

                if (file.size > 5 * 1024 * 1024) {
                    alert('File size must be less than 5MB');
                    return;
                }

                uploadProgress.classList.add('show');

                const formData = new FormData();
                formData.append('avatar', file);
                formData.append('user_id', '<?php echo $student_id; ?>');

                fetch('../upload_avatar.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        uploadProgress.classList.remove('show');
                        if (data.success) {
                            profileImage.src = '../' + data.filepath + '?t=' + new Date().getTime();
                            document.querySelector('.user-avatar img').src = '../' + data.filepath + '?t=' + new Date().getTime();
                            showToast('Profile picture updated!', 'success');
                        } else {
                            showToast(data.message || 'Upload failed', 'error');
                        }
                    })
                    .catch(error => {
                        uploadProgress.classList.remove('show');
                        showToast('Upload failed', 'error');
                    });
            });
        }

        // Toast function
        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            const iconName = type === 'success'
                ? 'check-circle'
                : (type === 'info' ? 'info-circle' : 'exclamation-circle');
            toast.innerHTML = `<i class="fas fa-${iconName}"></i><span class="toast-text"></span>`;
            const textContainer = toast.querySelector('.toast-text');
            if (textContainer) {
                textContainer.textContent = String(message || '');
            }
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Live message notifications
        const messageUnreadBadge = document.getElementById('messageUnreadBadge');
        const messagesTabContent = document.getElementById('messages');
        let lastUnreadCount = <?php echo (int) $unread_message_count; ?>;
        let lastPendingRequestCount = <?php echo (int) $incoming_friend_request_count; ?>;
        let lastLatestSentAt = null;
        let lastLatestOfficeCommentAt = null;
        let lastLatestOrgCommentAt = null;
        let hasNotificationBaseline = false;
        let messagePollTimer = null;
        let messagePollInFlight = false;
        let messagePollIntervalMs = 12000;

        function isMessagesTabOpen() {
            return !!(messagesTabContent && messagesTabContent.classList.contains('active'));
        }

        function updateMessageBadge(count) {
            if (!messageUnreadBadge) {
                return;
            }

            messageUnreadBadge.textContent = count;
            messageUnreadBadge.classList.toggle('is-hidden', count <= 0);
        }

        function applyFriendPresenceMap(presenceMap) {
            if (!presenceMap || typeof presenceMap !== 'object') {
                return;
            }

            Object.entries(presenceMap).forEach(([friendIdKey, presenceValue]) => {
                const friendId = Number(friendIdKey || 0);
                if (friendId <= 0) {
                    return;
                }

                const isOnline = Number(presenceValue?.is_online || 0) === 1;
                const statusClass = isOnline ? 'online' : 'offline';
                const fallbackText = isOnline ? 'Active now' : 'Offline';
                const statusText = String(presenceValue?.status_text || fallbackText);

                document.querySelectorAll(`[data-friend-presence-line="${friendId}"]`).forEach((line) => {
                    line.classList.remove('online', 'offline');
                    line.classList.add(statusClass);
                });

                document.querySelectorAll(`[data-friend-presence-line="${friendId}"] .messenger-presence-dot`).forEach((dot) => {
                    dot.classList.remove('online', 'offline');
                    dot.classList.add(statusClass);
                });

                document.querySelectorAll(`[data-friend-presence-text="${friendId}"]`).forEach((textNode) => {
                    textNode.textContent = statusText;
                });

                document.querySelectorAll(`[data-friend-avatar-dot="${friendId}"]`).forEach((dot) => {
                    dot.classList.remove('online', 'offline');
                    dot.classList.add(statusClass);
                });
            });
        }

        function notifyBrowser(title, bodyText) {
            if (!('Notification' in window)) {
                return;
            }

            if (Notification.permission === 'granted') {
                new Notification(title, { body: bodyText });
                return;
            }

            if (Notification.permission !== 'denied') {
                Notification.requestPermission();
            }
        }

        async function pollMessageNotifications() {
            if (messagePollInFlight) {
                return;
            }

            messagePollInFlight = true;
            try {
                const response = await fetch('dashboard.php?ajax=message_notifications', {
                    method: 'GET',
                    cache: 'no-store'
                });

                if (!response.ok) {
                    return;
                }

                const data = await response.json();
                if (!data || data.success !== true) {
                    return;
                }

                if (!hasNotificationBaseline) {
                    lastUnreadCount = Number(data.unread_count || 0);
                    lastPendingRequestCount = Number(data.pending_request_count || 0);
                    lastLatestSentAt = data.latest_sent_at || null;
                    lastLatestOfficeCommentAt = data.latest_office_comment_at || null;
                    lastLatestOrgCommentAt = data.latest_org_comment_at || null;
                    hasNotificationBaseline = true;
                }

                const unread = Number(data.unread_count || 0);
                const pendingRequests = Number(data.pending_request_count || 0);
                const totalNotifications = Number(data.notification_count || (unread + pendingRequests));
                updateMessageBadge(totalNotifications);
                applyFriendPresenceMap(data.friend_presence || {});

                const hasNewUnread = unread > lastUnreadCount;
                const isNewMessageMarker = data.latest_sent_at && data.latest_sent_at !== lastLatestSentAt;
                if (hasNewUnread && isNewMessageMarker) {
                    const messageInput = document.getElementById('messengerMessageInput');
                    const hasDraftMessage = !!(messageInput && String(messageInput.value || '').trim() !== '');
                    if (isMessagesTabOpen() && !hasDraftMessage) {
                        const refreshUrl = new URL(window.location.href);
                        refreshUrl.searchParams.set('tab', 'messages');
                        window.location.href = refreshUrl.pathname + refreshUrl.search;
                        return;
                    }

                    const sender = data.latest_sender || 'a student';
                    const preview = data.latest_message ? `: ${data.latest_message}` : '';
                    showToast(`New message from ${sender}${preview}`, 'info');
                    notifyBrowser('New student message', `From ${sender}`);
                }

                if (pendingRequests > lastPendingRequestCount) {
                    showToast('You received a new friend request.', 'info');
                    notifyBrowser('New friend request', 'Open Messages to accept the request.');

                    // Refresh server-rendered friend request list when user is already viewing Messages.
                    if (isMessagesTabOpen()) {
                        const refreshUrl = new URL(window.location.href);
                        refreshUrl.searchParams.set('tab', 'messages');
                        window.location.href = refreshUrl.pathname + refreshUrl.search;
                        return;
                    }
                }

                const hasNewOfficeComment = data.latest_office_comment_at
                    && data.latest_office_comment_at !== lastLatestOfficeCommentAt;
                if (hasNewOfficeComment && data.latest_office_comment) {
                    const officeName = data.latest_office_name || 'Office';
                    showToast(`New comment from ${officeName}: ${data.latest_office_comment}`, 'info');
                    notifyBrowser('New office comment', `${officeName}: ${data.latest_office_comment}`);
                }

                const hasNewOrgComment = data.latest_org_comment_at
                    && data.latest_org_comment_at !== lastLatestOrgCommentAt;
                if (hasNewOrgComment && data.latest_org_comment) {
                    const orgName = data.latest_org_name || 'Organization';
                    showToast(`New comment from ${orgName}: ${data.latest_org_comment}`, 'info');
                    notifyBrowser('New organization comment', `${orgName}: ${data.latest_org_comment}`);
                }

                lastUnreadCount = unread;
                lastPendingRequestCount = pendingRequests;
                lastLatestSentAt = data.latest_sent_at || lastLatestSentAt;
                lastLatestOfficeCommentAt = data.latest_office_comment_at || lastLatestOfficeCommentAt;
                lastLatestOrgCommentAt = data.latest_org_comment_at || lastLatestOrgCommentAt;

                const isTabVisible = document.visibilityState === 'visible';
                messagePollIntervalMs = isTabVisible ? 12000 : 25000;
            } catch (err) {
                // Keep silent to avoid noisy UI during transient network errors.
                messagePollIntervalMs = 30000;
            } finally {
                messagePollInFlight = false;
            }
        }

        function scheduleNotificationPolling(nextIntervalMs) {
            if (messagePollTimer) {
                clearTimeout(messagePollTimer);
            }

            const waitMs = Number(nextIntervalMs || messagePollIntervalMs || 12000);
            messagePollTimer = setTimeout(async () => {
                await pollMessageNotifications();
                scheduleNotificationPolling(messagePollIntervalMs);
            }, waitMs);
        }

        updateMessageBadge(lastUnreadCount + lastPendingRequestCount);
        pollMessageNotifications();
        scheduleNotificationPolling(12000);

        document.addEventListener('visibilitychange', () => {
            messagePollIntervalMs = document.visibilityState === 'visible' ? 12000 : 25000;
            scheduleNotificationPolling(600);
        });

        document.addEventListener('DOMContentLoaded', () => {
            const messengerChatThread = document.getElementById('messengerChatThread');
            const messengerMessageInput = document.getElementById('messengerMessageInput');
            const messageAttachmentInput = document.getElementById('messageAttachmentInput');
            const messageAttachmentHint = document.getElementById('messageAttachmentHint');
            const messageAttachmentTrigger = document.getElementById('messageAttachmentTrigger');
            const messageImageTrigger = document.getElementById('messageImageTrigger');
            const messageAttachmentSelected = document.getElementById('messageAttachmentSelected');
            const messageAttachmentSelectedIcon = document.getElementById('messageAttachmentSelectedIcon');
            const messageAttachmentName = document.getElementById('messageAttachmentName');
            const messageAttachmentClearBtn = document.getElementById('messageAttachmentClearBtn');
            const messengerSendButton = document.getElementById('messengerSendButton');
            const messengerComposerForm = document.getElementById('messengerComposerForm');
            const replyToMessageInput = document.getElementById('replyToMessageId');
            const replyPreview = document.getElementById('messengerReplyPreview');
            const replyPreviewSender = document.getElementById('replyPreviewSender');
            const replyPreviewText = document.getElementById('replyPreviewText');
            const clearReplyBtn = document.getElementById('clearReplyBtn');
            const nicknameToggleButton = document.getElementById('messengerNicknameToggleBtn');
            const nicknamePanel = document.getElementById('messengerNicknamePanel');
            const conversationItems = document.querySelectorAll('.messenger-conversation-item[data-conversation-friend-id]');
            const deleteConversationForm = document.getElementById('deleteConversationForm');
            const deleteConversationFriendIdInput = document.getElementById('deleteConversationFriendId');
            const replyButtons = document.querySelectorAll('.messenger-reply-btn');
            const reactionTargets = document.querySelectorAll('.messenger-reaction-target[data-message-id]');
            const reactionToggleButtons = document.querySelectorAll('[data-toggle-reactions]');
            const reactionPickers = document.querySelectorAll('[data-reaction-picker-for]');
            const defaultAttachmentHint = 'Optional: use file or gallery buttons (max 15 MB).';
            const defaultAttachmentAccept = messageAttachmentInput?.getAttribute('accept') || '';
            const imageOnlyAttachmentAccept = 'image/*';
            let isSendingMessage = false;
            let activeReactionPickerMessageId = 0;

            const submitConversationDelete = (friendId) => {
                const normalizedFriendId = Number(friendId || 0);
                if (normalizedFriendId <= 0 || !deleteConversationForm || !deleteConversationFriendIdInput) {
                    return;
                }

                deleteConversationFriendIdInput.value = String(normalizedFriendId);
                deleteConversationForm.submit();
            };

            const promptConversationDelete = (friendId, friendName) => {
                const normalizedFriendId = Number(friendId || 0);
                if (normalizedFriendId <= 0) {
                    return;
                }

                const label = String(friendName || 'this classmate').trim() || 'this classmate';
                const confirmText = `Delete your entire conversation with ${label}? This cannot be undone.`;
                const isCompactViewport = window.innerWidth <= 768;

                if (window.Swal && typeof window.Swal.fire === 'function') {
                    window.Swal.fire({
                        icon: 'warning',
                        title: 'Delete conversation?',
                        text: confirmText,
                        showCancelButton: true,
                        confirmButtonText: isCompactViewport ? 'Delete' : 'Delete Conversation',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#c62828',
                        reverseButtons: true,
                        position: isCompactViewport ? 'top' : 'center',
                        heightAuto: false,
                        customClass: {
                            container: 'conversation-delete-alert-container',
                            popup: 'conversation-delete-alert-popup',
                            title: 'conversation-delete-alert-title',
                            htmlContainer: 'conversation-delete-alert-text',
                            confirmButton: 'conversation-delete-alert-confirm',
                            cancelButton: 'conversation-delete-alert-cancel'
                        },
                        buttonsStyling: false
                    }).then((result) => {
                        if (result.isConfirmed) {
                            submitConversationDelete(normalizedFriendId);
                        }
                    });
                    return;
                }

                if (window.confirm(confirmText)) {
                    submitConversationDelete(normalizedFriendId);
                }
            };

            conversationItems.forEach((item) => {
                const friendId = Number(item.dataset.conversationFriendId || 0);
                const friendName = item.dataset.conversationFriendName || 'this classmate';
                if (friendId <= 0) {
                    return;
                }

                let longPressTimer = null;
                let suppressNextTapOpen = false;

                const clearLongPressTimer = () => {
                    if (longPressTimer !== null) {
                        clearTimeout(longPressTimer);
                        longPressTimer = null;
                    }
                };

                item.addEventListener('contextmenu', (event) => {
                    if (window.innerWidth <= 768) {
                        return;
                    }

                    event.preventDefault();
                    promptConversationDelete(friendId, friendName);
                });

                item.addEventListener('touchstart', (event) => {
                    if (window.innerWidth > 768) {
                        return;
                    }

                    clearLongPressTimer();
                    longPressTimer = window.setTimeout(() => {
                        suppressNextTapOpen = true;
                        promptConversationDelete(friendId, friendName);
                        if (navigator.vibrate) {
                            navigator.vibrate(12);
                        }
                    }, 560);
                }, { passive: true });

                item.addEventListener('touchmove', clearLongPressTimer, { passive: true });
                item.addEventListener('touchend', clearLongPressTimer);
                item.addEventListener('touchcancel', clearLongPressTimer);

                item.addEventListener('click', (event) => {
                    if (!suppressNextTapOpen) {
                        return;
                    }

                    suppressNextTapOpen = false;
                    event.preventDefault();
                    event.stopPropagation();
                });
            });

            const applyReplyPreview = (messageId, sender, text) => {
                if (!replyPreview || !replyToMessageInput || !replyPreviewSender || !replyPreviewText) {
                    return;
                }

                const normalizedText = String(text || '').replace(/\s+/g, ' ').trim();
                if (!messageId || normalizedText === '') {
                    replyToMessageInput.value = '';
                    replyPreview.classList.remove('show');
                    replyPreviewSender.textContent = '';
                    replyPreviewText.textContent = '';
                    return;
                }

                const shortenedText = normalizedText.length > 140
                    ? `${normalizedText.slice(0, 137)}...`
                    : normalizedText;

                replyToMessageInput.value = String(messageId);
                replyPreviewSender.textContent = sender || 'Classmate';
                replyPreviewText.textContent = shortenedText;
                replyPreview.classList.add('show');
            };

            if (messengerShell && window.innerWidth <= 768 && !messengerShell.classList.contains('mobile-chat-open')) {
                messengerChatThread?.scrollTo({ top: 0, behavior: 'auto' });
            }

            if (messengerChatThread) {
                messengerChatThread.scrollTop = messengerChatThread.scrollHeight;
            }

            const closeReactionPickers = () => {
                reactionPickers.forEach((picker) => {
                    picker.classList.remove('show');
                });

                reactionToggleButtons.forEach((button) => {
                    button.setAttribute('aria-expanded', 'false');
                });

                activeReactionPickerMessageId = 0;
            };

            const openReactionPicker = (messageId) => {
                const normalizedId = Number(messageId || 0);
                if (normalizedId <= 0) {
                    return;
                }

                reactionPickers.forEach((picker) => {
                    const pickerId = Number(picker.dataset.reactionPickerFor || 0);
                    picker.classList.toggle('show', pickerId === normalizedId);
                });

                reactionToggleButtons.forEach((button) => {
                    const buttonId = Number(button.dataset.messageId || 0);
                    button.setAttribute('aria-expanded', buttonId === normalizedId ? 'true' : 'false');
                });

                activeReactionPickerMessageId = normalizedId;
            };

            const toggleReactionPicker = (messageId) => {
                const normalizedId = Number(messageId || 0);
                if (normalizedId <= 0) {
                    return;
                }

                if (activeReactionPickerMessageId === normalizedId) {
                    closeReactionPickers();
                    return;
                }

                openReactionPicker(normalizedId);
            };

            reactionToggleButtons.forEach((button) => {
                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();

                    const messageId = Number(button.dataset.messageId || 0);
                    toggleReactionPicker(messageId);
                });
            });

            reactionTargets.forEach((target) => {
                const messageId = Number(target.dataset.messageId || 0);
                if (messageId <= 0) {
                    return;
                }

                let longPressTimer = null;

                const cancelLongPress = () => {
                    if (longPressTimer !== null) {
                        clearTimeout(longPressTimer);
                        longPressTimer = null;
                    }
                };

                target.addEventListener('click', (event) => {
                    if (window.innerWidth <= 768) {
                        return;
                    }

                    if (event.target.closest('a, button, input, textarea, form, audio')) {
                        return;
                    }

                    toggleReactionPicker(messageId);
                });

                target.addEventListener('touchstart', (event) => {
                    if (window.innerWidth > 768) {
                        return;
                    }

                    if (event.target.closest('a, button, input, textarea, form, audio')) {
                        return;
                    }

                    cancelLongPress();
                    longPressTimer = window.setTimeout(() => {
                        openReactionPicker(messageId);
                        if (navigator.vibrate) {
                            navigator.vibrate(10);
                        }
                    }, 450);
                }, { passive: true });

                target.addEventListener('touchend', cancelLongPress);
                target.addEventListener('touchmove', cancelLongPress);
                target.addEventListener('touchcancel', cancelLongPress);
            });

            nicknameToggleButton?.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();

                nicknamePanel?.classList.toggle('show');
            });

            document.addEventListener('click', (event) => {
                const reactionArea = event.target.closest('.messenger-message-actions, .messenger-reaction-target');
                if (!reactionArea) {
                    closeReactionPickers();
                }

                if (nicknamePanel && !event.target.closest('#messengerNicknamePanel, #messengerNicknameToggleBtn')) {
                    nicknamePanel.classList.remove('show');
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeReactionPickers();
                    nicknamePanel?.classList.remove('show');
                }
            });

            replyButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    const messageId = Number(button.dataset.replyId || 0);
                    const sender = button.dataset.replySender || 'Classmate';
                    const text = button.dataset.replyText || '';

                    applyReplyPreview(messageId, sender, text);
                    messengerMessageInput?.focus();
                });
            });

            clearReplyBtn?.addEventListener('click', () => {
                applyReplyPreview(0, '', '');
                messengerMessageInput?.focus();
            });

            const resolveAttachmentPreviewIconClass = (file) => {
                if (!file) {
                    return 'fas fa-file';
                }

                const mimeType = String(file.type || '').toLowerCase();
                const fileName = String(file.name || '').toLowerCase();
                if (
                    mimeType.indexOf('image/') === 0
                    || /\.(jpg|jpeg|png|gif|webp|bmp|heic|heif)$/i.test(fileName)
                ) {
                    return 'fas fa-image';
                }

                if (
                    mimeType.indexOf('audio/') === 0
                    || /\.(mp3|wav|ogg|m4a|aac)$/i.test(fileName)
                ) {
                    return 'fas fa-music';
                }

                return 'fas fa-file';
            };

            const openAttachmentPicker = (acceptValue) => {
                if (!messageAttachmentInput) {
                    return;
                }

                const normalizedAccept = String(acceptValue || '').trim();
                if (normalizedAccept !== '') {
                    messageAttachmentInput.setAttribute('accept', normalizedAccept);
                } else {
                    messageAttachmentInput.removeAttribute('accept');
                }

                messageAttachmentInput.click();
            };

            const updateAttachmentPreview = () => {
                const selectedFile = messageAttachmentInput?.files && messageAttachmentInput.files[0]
                    ? messageAttachmentInput.files[0]
                    : null;

                if (!selectedFile) {
                    if (messageAttachmentHint) {
                        messageAttachmentHint.textContent = defaultAttachmentHint;
                    }
                    if (messageAttachmentName) {
                        messageAttachmentName.textContent = 'No file selected';
                    }
                    if (messageAttachmentSelectedIcon) {
                        messageAttachmentSelectedIcon.className = 'fas fa-file';
                    }
                    messageAttachmentSelected?.classList.remove('show', 'is-error');
                    return;
                }

                const maxAttachmentSize = 15 * 1024 * 1024;
                const sizeLabel = selectedFile.size >= 1024 * 1024
                    ? `${(selectedFile.size / (1024 * 1024)).toFixed(2)} MB`
                    : `${Math.max(1, Math.round(selectedFile.size / 1024))} KB`;

                if (messageAttachmentName) {
                    messageAttachmentName.textContent = `${selectedFile.name} (${sizeLabel})`;
                }
                if (messageAttachmentSelectedIcon) {
                    messageAttachmentSelectedIcon.className = resolveAttachmentPreviewIconClass(selectedFile);
                }

                messageAttachmentSelected?.classList.add('show');

                if (selectedFile.size > maxAttachmentSize) {
                    messageAttachmentSelected?.classList.add('is-error');
                    if (messageAttachmentHint) {
                        messageAttachmentHint.textContent = 'Selected file is too large. Maximum is 15 MB.';
                    }
                    return;
                }

                messageAttachmentSelected?.classList.remove('is-error');
                if (messageAttachmentHint) {
                    messageAttachmentHint.textContent = 'File ready to send.';
                }
            };

            messageAttachmentTrigger?.addEventListener('click', () => {
                openAttachmentPicker(defaultAttachmentAccept);
            });

            messageImageTrigger?.addEventListener('click', () => {
                openAttachmentPicker(imageOnlyAttachmentAccept);
            });

            messageAttachmentClearBtn?.addEventListener('click', () => {
                if (!messageAttachmentInput) {
                    return;
                }

                messageAttachmentInput.value = '';
                if (defaultAttachmentAccept !== '') {
                    messageAttachmentInput.setAttribute('accept', defaultAttachmentAccept);
                }
                updateAttachmentPreview();
            });

            messageAttachmentInput?.addEventListener('change', () => {
                updateAttachmentPreview();

                if (defaultAttachmentAccept !== '') {
                    messageAttachmentInput.setAttribute('accept', defaultAttachmentAccept);
                }
            });
            updateAttachmentPreview();

            messengerMessageInput?.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    if (messengerSendButton) {
                        messengerSendButton.click();
                    } else {
                        messengerMessageInput.form?.requestSubmit();
                    }
                }
            });

            messengerComposerForm?.addEventListener('submit', (event) => {
                if (!messengerMessageInput) {
                    return;
                }

                const trimmedMessage = messengerMessageInput.value.trim();
                messengerMessageInput.value = trimmedMessage;

                const selectedAttachment = messageAttachmentInput?.files && messageAttachmentInput.files[0]
                    ? messageAttachmentInput.files[0]
                    : null;
                const hasAttachment = !!selectedAttachment;

                if (trimmedMessage === '' && !hasAttachment) {
                    event.preventDefault();
                    showToast('Type a message or attach a file.', 'error');
                    return;
                }

                if (selectedAttachment && selectedAttachment.size > (15 * 1024 * 1024)) {
                    event.preventDefault();
                    showToast('Attachment must be 15 MB or less.', 'error');
                    return;
                }

                if (isSendingMessage) {
                    event.preventDefault();
                    return;
                }

                isSendingMessage = true;
                if (messengerSendButton) {
                    messengerSendButton.disabled = true;
                    messengerSendButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span class="messenger-send-inline-label">Sending...</span>';
                }
            });

            setTimeout(() => {
                openOnboardingGuide(false);
            }, 550);
        });

        // Close modal when clicking outside
        window.onclick = function (event) {
            const uploadModal = document.getElementById('uploadModal');
            const detailsModal = document.getElementById('detailsModal');
            const profileModal = document.getElementById('profileModal');
            const onboardingDialog = document.getElementById('onboardingModal');

            if (event.target == uploadModal) {
                uploadModal.style.display = 'none';
            }
            if (event.target == detailsModal) {
                detailsModal.style.display = 'none';
            }
            if (event.target == profileModal) {
                profileModal.style.display = 'none';
            }
            if (event.target == onboardingDialog) {
                closeOnboardingGuide(true);
            }
        };
    </script>
</body>

</html>


