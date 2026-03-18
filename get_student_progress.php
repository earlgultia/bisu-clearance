<?php
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_GET['user_id'] ?? 0;
$semester = $_GET['semester'] ?? '';
$school_year = $_GET['school_year'] ?? '';

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'No user ID provided']);
    exit();
}

$db = Database::getInstance();

$query = "SELECT o.office_name, c.status, 
          DATE_FORMAT(c.processed_date, '%Y-%m-%d %H:%i:%s') as processed_date,
          c.remarks,
          c.lacking_comment
          FROM clearance c
          JOIN offices o ON c.office_id = o.office_id
          WHERE c.users_id = :user_id";

$params = [':user_id' => $user_id];

if (!empty($semester)) {
    $query .= " AND c.semester = :semester";
    $params[':semester'] = $semester;
}

if (!empty($school_year)) {
    $query .= " AND c.school_year = :school_year";
    $params[':school_year'] = $school_year;
}

$query .= " ORDER BY o.office_order";

$db->query($query);
foreach ($params as $key => $value) {
    $db->bind($key, $value);
}
$offices = $db->resultSet();

echo json_encode([
    'success' => true,
    'offices' => $offices
]);
