<?php
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function pushApiRespond(array $payload, int $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit();
}

$action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? 'config')));
$config = getWebPushConfiguration();
$isAuthenticated = isset($_SESSION['logged_in'])
    && $_SESSION['logged_in'] === true
    && !empty($_SESSION['user_id']);

if ($action === 'config') {
    pushApiRespond([
        'success' => true,
        'enabled' => !empty($config['enabled']),
        'public_key' => (string) ($config['public_key'] ?? ''),
        'can_subscribe' => $isAuthenticated,
        'reason' => empty($config['enabled']) ? (string) ($config['reason'] ?? 'Push notifications are not configured.') : null
    ]);
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
    pushApiRespond(['success' => false, 'message' => 'Unauthorized'], 403);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    pushApiRespond(['success' => false, 'message' => 'Invalid session'], 403);
}

$db = Database::getInstance();
ensurePushInfrastructure($db);

$input = [];
$rawBody = file_get_contents('php://input');
if (is_string($rawBody) && trim($rawBody) !== '') {
    $decodedBody = json_decode($rawBody, true);
    if (is_array($decodedBody)) {
        $input = $decodedBody;
    }
}
if (!empty($_POST)) {
    $input = array_merge($input, $_POST);
}

if ($action === 'subscribe') {
    if (empty($config['enabled'])) {
        pushApiRespond([
            'success' => false,
            'message' => 'Push notifications are not configured on this server.',
            'reason' => (string) ($config['reason'] ?? 'Missing VAPID configuration')
        ], 503);
    }

    $subscriptionPayload = $input['subscription'] ?? $input;
    if (!is_array($subscriptionPayload)) {
        pushApiRespond(['success' => false, 'message' => 'Invalid subscription payload'], 422);
    }

    $saved = savePushSubscriptionForUser($userId, $subscriptionPayload, $_SERVER['HTTP_USER_AGENT'] ?? '');
    if (!$saved) {
        pushApiRespond(['success' => false, 'message' => 'Failed to save push subscription'], 422);
    }

    // Deliver any queued notifications now that the device is subscribed again.
    dispatchQueuedPushNotifications($userId, 20);

    pushApiRespond(['success' => true, 'message' => 'Push subscription saved']);
}

if ($action === 'unsubscribe') {
    $subscriptionInput = isset($input['subscription']) && is_array($input['subscription'])
        ? $input['subscription']
        : [];
    $endpoint = trim((string) ($input['endpoint'] ?? ($subscriptionInput['endpoint'] ?? '')));
    if ($endpoint === '') {
        pushApiRespond(['success' => false, 'message' => 'Missing endpoint for unsubscribe'], 422);
    }

    $removed = removePushSubscriptionForUser($userId, $endpoint);
    pushApiRespond([
        'success' => $removed,
        'message' => $removed ? 'Push subscription removed' : 'Push subscription was not removed'
    ], $removed ? 200 : 409);
}

if ($action === 'dispatch') {
    if (empty($config['enabled'])) {
        pushApiRespond([
            'success' => false,
            'message' => 'Push notifications are not configured on this server.',
            'reason' => (string) ($config['reason'] ?? 'Missing VAPID configuration')
        ], 503);
    }

    $dispatched = dispatchQueuedPushNotifications($userId, 25);
    pushApiRespond([
        'success' => $dispatched,
        'message' => $dispatched ? 'Dispatch completed' : 'Dispatch was skipped'
    ], $dispatched ? 200 : 409);
}

pushApiRespond(['success' => false, 'message' => 'Unsupported action'], 400);
