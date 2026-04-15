<?php
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

const AVATAR_UPDATE_COOLDOWN_DAYS = 45;

function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

function optimizeAvatarImage(string $filePath, string $mimeType, int $maxDimension = 768): bool
{
    // Resize and recompress common photo formats to keep avatar payloads small.
    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif'], true)) {
        return false;
    }

    $imageSize = @getimagesize($filePath);
    if (!$imageSize || empty($imageSize[0]) || empty($imageSize[1])) {
        return false;
    }

    $sourceWidth = (int) $imageSize[0];
    $sourceHeight = (int) $imageSize[1];
    if ($sourceWidth <= 0 || $sourceHeight <= 0) {
        return false;
    }

    // Avoid very large raster dimensions that can consume too much memory.
    if (($sourceWidth * $sourceHeight) > 25000000) {
        return false;
    }

    $createFunction = $mimeType === 'image/jpeg'
        ? 'imagecreatefromjpeg'
        : ($mimeType === 'image/png' ? 'imagecreatefrompng' : 'imagecreatefromgif');
    if (!function_exists($createFunction)) {
        return false;
    }

    $sourceImage = @$createFunction($filePath);
    if (!$sourceImage) {
        return false;
    }

    $targetWidth = $sourceWidth;
    $targetHeight = $sourceHeight;

    if ($sourceWidth > $maxDimension || $sourceHeight > $maxDimension) {
        $scale = min($maxDimension / $sourceWidth, $maxDimension / $sourceHeight);
        $targetWidth = max(1, (int) floor($sourceWidth * $scale));
        $targetHeight = max(1, (int) floor($sourceHeight * $scale));
    }

    $outputImage = $sourceImage;
    $needsResize = $targetWidth !== $sourceWidth || $targetHeight !== $sourceHeight;

    if ($needsResize) {
        $resizedImage = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$resizedImage) {
            imagedestroy($sourceImage);
            return false;
        }

        if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
            imagealphablending($resizedImage, false);
            imagesavealpha($resizedImage, true);
            $transparent = imagecolorallocatealpha($resizedImage, 0, 0, 0, 127);
            imagefilledrectangle($resizedImage, 0, 0, $targetWidth, $targetHeight, $transparent);
        }

        imagecopyresampled(
            $resizedImage,
            $sourceImage,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        );

        $outputImage = $resizedImage;
    }

    $saved = false;

    if ($mimeType === 'image/jpeg' && function_exists('imagejpeg')) {
        $quality = 82;
        $saved = imagejpeg($outputImage, $filePath, $quality);

        // Keep avatar files lightweight by gradually lowering JPEG quality if still large.
        while ($saved && file_exists($filePath) && filesize($filePath) > 420 * 1024 && $quality > 58) {
            $quality -= 8;
            $saved = imagejpeg($outputImage, $filePath, $quality);
        }
    }

    if ($mimeType === 'image/png' && function_exists('imagepng')) {
        $saved = imagepng($outputImage, $filePath, 7);
    }

    if ($mimeType === 'image/gif' && function_exists('imagegif')) {
        $saved = imagegif($outputImage, $filePath);
    }

    if ($outputImage !== $sourceImage) {
        imagedestroy($outputImage);
    }

    imagedestroy($sourceImage);

    return $saved;
}

function ensureAvatarCooldownColumn(Database $db): void
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    if (!shouldRunMaintenanceTask('users_avatar_updated_at_column', 21600)) {
        return;
    }

    try {
        if (!hasDatabaseColumn('users', 'avatar_updated_at')) {
            $db->query("ALTER TABLE users ADD COLUMN avatar_updated_at DATETIME NULL AFTER profile_picture");
            $db->execute();
        }
    } catch (Exception $e) {
        error_log('Avatar cooldown column ensure failed: ' . $e->getMessage());
    }
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    jsonResponse([
        'success' => false,
        'message' => 'Unauthorized.'
    ], 401);
}

$userId = (int) $_SESSION['user_id'];
$db = Database::getInstance();
$oldProfilePicture = null;
$avatarCooldownColumnAvailable = false;
$canUploadAvatar = true;
$cooldownMessage = '';
$remainingDays = 0;
$nextAllowedText = '';
$nextAllowedIso = null;

try {
    ensureAvatarCooldownColumn($db);
    $avatarCooldownColumnAvailable = hasDatabaseColumn('users', 'avatar_updated_at');

    if ($avatarCooldownColumnAvailable) {
        $db->query('SELECT profile_picture, avatar_updated_at FROM users WHERE users_id = :user_id');
    } else {
        $db->query('SELECT profile_picture FROM users WHERE users_id = :user_id');
    }

    $db->bind(':user_id', $userId);
    $user = $db->single();

    if (!$user) {
        jsonResponse([
            'success' => false,
            'message' => 'User account not found.'
        ], 404);
    }

    $oldProfilePicture = $user['profile_picture'] ?? null;
    $lastAvatarUpdatedAtRaw = trim((string) ($user['avatar_updated_at'] ?? ''));

    if ($lastAvatarUpdatedAtRaw === '') {
        // Fallback for existing accounts that updated avatars before cooldown tracking was added.
        $db->query("SELECT created_at
                    FROM activity_logs
                    WHERE users_id = :user_id
                      AND action = 'UPDATE_PROFILE_PICTURE'
                    ORDER BY created_at DESC
                    LIMIT 1");
        $db->bind(':user_id', $userId);
        $lastAvatarLog = $db->single();

        if ($lastAvatarLog && !empty($lastAvatarLog['created_at'])) {
            $lastAvatarUpdatedAtRaw = (string) $lastAvatarLog['created_at'];

            if ($avatarCooldownColumnAvailable) {
                $db->query('UPDATE users
                            SET avatar_updated_at = :avatar_updated_at
                            WHERE users_id = :user_id
                              AND avatar_updated_at IS NULL');
                $db->bind(':avatar_updated_at', $lastAvatarUpdatedAtRaw);
                $db->bind(':user_id', $userId);
                $db->execute();
            }
        }
    }

    if ($lastAvatarUpdatedAtRaw !== '') {
        $lastAvatarUpdatedAtTs = strtotime($lastAvatarUpdatedAtRaw);

        if ($lastAvatarUpdatedAtTs !== false) {
            $cooldownSeconds = AVATAR_UPDATE_COOLDOWN_DAYS * 24 * 60 * 60;
            $nextAllowedTs = $lastAvatarUpdatedAtTs + $cooldownSeconds;
            $nowTs = time();

            if ($nowTs < $nextAllowedTs) {
                $remainingDays = (int) ceil(($nextAllowedTs - $nowTs) / 86400);
                $nextAllowedText = date('M d, Y h:i A', $nextAllowedTs);
                $nextAllowedIso = date('c', $nextAllowedTs);
                $canUploadAvatar = false;
                $cooldownMessage = 'You can only change your profile picture every ' . AVATAR_UPDATE_COOLDOWN_DAYS . ' days. Try again on ' . $nextAllowedText . ' (' . $remainingDays . ' day' . ($remainingDays === 1 ? '' : 's') . ' remaining).';
            }
        }
    }
} catch (Exception $e) {
    error_log('Avatar cooldown validation error: ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'message' => 'Unable to validate avatar update cooldown right now. Please try again later.'
    ], 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['cooldown_status'])) {
    jsonResponse([
        'success' => true,
        'can_upload' => $canUploadAvatar,
        'cooldown_days' => AVATAR_UPDATE_COOLDOWN_DAYS,
        'remaining_days' => $remainingDays,
        'next_allowed_at' => $nextAllowedIso,
        'next_allowed_text' => $nextAllowedText,
        'message' => $canUploadAvatar ? 'You can update your profile picture now.' : $cooldownMessage
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        'success' => false,
        'message' => 'Invalid request method.'
    ], 405);
}

if (!$canUploadAvatar) {
    jsonResponse([
        'success' => false,
        'message' => $cooldownMessage,
        'can_upload' => false,
        'remaining_days' => $remainingDays,
        'next_allowed_at' => $nextAllowedIso,
        'next_allowed_text' => $nextAllowedText
    ], 429);
}

if (!isset($_FILES['avatar'])) {
    jsonResponse([
        'success' => false,
        'message' => 'No file uploaded.'
    ], 400);
}

$file = $_FILES['avatar'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    jsonResponse([
        'success' => false,
        'message' => 'Upload failed. Please try again.'
    ], 400);
}

$maxOriginalUploadSize = 20 * 1024 * 1024; // Larger source files are allowed and then compressed.
if ($file['size'] > $maxOriginalUploadSize) {
    jsonResponse([
        'success' => false,
        'message' => 'File size must be less than 20MB before compression.'
    ], 400);
}

$imageInfo = @getimagesize($file['tmp_name']);
$allowedMimeTypes = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif'
];

if (!$imageInfo || !isset($allowedMimeTypes[$imageInfo['mime']])) {
    jsonResponse([
        'success' => false,
        'message' => 'Please upload a valid JPG, PNG, or GIF image.'
    ], 400);
}

$uploadDir = __DIR__ . '/uploads/avatars/';

if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    jsonResponse([
        'success' => false,
        'message' => 'Failed to create the upload directory.'
    ], 500);
}

$extension = $allowedMimeTypes[$imageInfo['mime']];
$uniqueId = str_replace('.', '', uniqid('', true));
$fileName = sprintf('avatar_%d_%s.%s', $userId, $uniqueId, $extension);
$storedPath = 'uploads/avatars/' . $fileName;
$destination = $uploadDir . $fileName;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    jsonResponse([
        'success' => false,
        'message' => 'Unable to save the uploaded file.'
    ], 500);
}

optimizeAvatarImage($destination, $imageInfo['mime']);

try {
    if ($avatarCooldownColumnAvailable) {
        $db->query('UPDATE users
                    SET profile_picture = :profile_picture,
                        avatar_updated_at = NOW()
                    WHERE users_id = :user_id');
        $db->bind(':profile_picture', $storedPath);
        $db->bind(':user_id', $userId);
    } else {
        $db->query('UPDATE users SET profile_picture = :profile_picture WHERE users_id = :user_id');
        $db->bind(':profile_picture', $storedPath);
        $db->bind(':user_id', $userId);
    }

    if (!$db->execute()) {
        if (file_exists($destination)) {
            unlink($destination);
        }

        jsonResponse([
            'success' => false,
            'message' => 'Failed to update your profile picture.'
        ], 500);
    }

    $_SESSION['profile_picture'] = $storedPath;

    if (class_exists('ActivityLogModel')) {
        try {
            $logModel = new ActivityLogModel();
            $logModel->log($userId, 'UPDATE_PROFILE_PICTURE', 'Updated profile picture');
        } catch (Exception $e) {
            error_log('Avatar activity log failed: ' . $e->getMessage());
        }
    }

    if (!empty($oldProfilePicture)) {
        $oldFilePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $oldProfilePicture));
        $avatarsRoot = realpath($uploadDir);

        if (
            $oldFilePath &&
            $avatarsRoot &&
            strpos($oldFilePath, $avatarsRoot) === 0 &&
            is_file($oldFilePath)
        ) {
            unlink($oldFilePath);
        }
    }

    jsonResponse([
        'success' => true,
        'message' => 'Profile picture updated successfully.',
        'filepath' => $storedPath
    ]);
} catch (Exception $e) {
    if (file_exists($destination)) {
        unlink($destination);
    }

    error_log('Avatar upload error: ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'message' => 'An unexpected error occurred while updating your profile picture.'
    ], 500);
}
