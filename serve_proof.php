<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unauthorized';
    exit();
}

$raw_path = isset($_GET['file']) ? (string) $_GET['file'] : '';
$normalized_path = str_replace('\\', '/', trim($raw_path));
$normalized_path = preg_replace('#^(?:\.\./|\./)+#', '', $normalized_path);
$normalized_path = ltrim((string) $normalized_path, '/');

if ($normalized_path === '' || strpos($normalized_path, '..') !== false) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid file path.';
    exit();
}

$allowed_prefixes = [
    'uploads/proofs/'
];

$is_allowed = false;
foreach ($allowed_prefixes as $prefix) {
    if (strpos($normalized_path, $prefix) === 0) {
        $is_allowed = true;
        break;
    }
}

if (!$is_allowed) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Access denied.';
    exit();
}

$base_dir = realpath(__DIR__);
if ($base_dir === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Server configuration error.';
    exit();
}

$file_path = $base_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized_path);

if (!is_file($file_path)) {
    http_response_code(200);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html><head><meta charset="UTF-8"><title>Proof File</title><link rel="icon" type="image/png" href="assets/img/favicon.png"></head><body style="font-family: Arial, sans-serif; padding: 24px;"><h2>Proof file not found</h2><p>The proof record exists, but the physical file is missing on the server.</p></body></html>';
    exit();
}

$mime_type = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $detected = finfo_file($finfo, $file_path);
        if (is_string($detected) && $detected !== '') {
            $mime_type = $detected;
        }
        finfo_close($finfo);
    }
}

$download = isset($_GET['download']) && $_GET['download'] === '1';
$filename = basename($file_path);
$safe_filename = str_replace(['"', "\r", "\n"], '', $filename);

header('X-Content-Type-Options: nosniff');
header('Content-Type: ' . $mime_type);
header('Content-Length: ' . (string) filesize($file_path));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $safe_filename . '"');

readfile($file_path);
exit();
