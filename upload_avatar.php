<?php
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

function optimizeAvatarImage(string $filePath, string $mimeType, int $maxDimension = 512): void
{
    // Resize and recompress common photo formats to keep avatar payloads small.
    if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
        return;
    }

    $imageSize = @getimagesize($filePath);
    if (!$imageSize || empty($imageSize[0]) || empty($imageSize[1])) {
        return;
    }

    $sourceWidth = (int) $imageSize[0];
    $sourceHeight = (int) $imageSize[1];

    $createFunction = $mimeType === 'image/jpeg' ? 'imagecreatefromjpeg' : 'imagecreatefrompng';
    if (!function_exists($createFunction)) {
        return;
    }

    $sourceImage = @$createFunction($filePath);
    if (!$sourceImage) {
        return;
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
            return;
        }

        if ($mimeType === 'image/png') {
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

    if ($mimeType === 'image/jpeg' && function_exists('imagejpeg')) {
        imagejpeg($outputImage, $filePath, 82);
    }

    if ($mimeType === 'image/png' && function_exists('imagepng')) {
        imagepng($outputImage, $filePath, 6);
    }

    if ($outputImage !== $sourceImage) {
        imagedestroy($outputImage);
    }

    imagedestroy($sourceImage);
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    jsonResponse([
        'success' => false,
        'message' => 'Unauthorized.'
    ], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        'success' => false,
        'message' => 'Invalid request method.'
    ], 405);
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

if ($file['size'] > 5 * 1024 * 1024) {
    jsonResponse([
        'success' => false,
        'message' => 'File size must be less than 5MB.'
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

$userId = (int) $_SESSION['user_id'];
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

$db = Database::getInstance();
$oldProfilePicture = null;

try {
    $db->query('SELECT profile_picture FROM users WHERE users_id = :user_id');
    $db->bind(':user_id', $userId);
    $user = $db->single();
    $oldProfilePicture = $user['profile_picture'] ?? null;

    $db->query('UPDATE users SET profile_picture = :profile_picture WHERE users_id = :user_id');
    $db->bind(':profile_picture', $storedPath);
    $db->bind(':user_id', $userId);

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
