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

$clearance_id = (int) ($_GET['clearance_id'] ?? 0);

if ($clearance_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Clearance ID is required']);
    exit();
}

$db = Database::getInstance();
$db->query("SELECT
                c.clearance_id,
                CONCAT(u.fname, ' ', u.lname) AS student_name,
                u.ismis_id,
                DATE_FORMAT(c.student_proof_uploaded_at, '%Y-%m-%d %H:%i:%s') AS uploaded_at,
                c.student_proof_file,
                c.student_proof_remarks
            FROM clearance c
            JOIN users u ON c.users_id = u.users_id
            WHERE c.clearance_id = :clearance_id");
$db->bind(':clearance_id', $clearance_id);
$clearance = $db->single();

if (!$clearance) {
    echo json_encode(['success' => false, 'message' => 'Clearance not found']);
    exit();
}

echo json_encode([
    'success' => true,
    'student_name' => $clearance['student_name'],
    'ismis_id' => $clearance['ismis_id'],
    'uploaded_at' => $clearance['uploaded_at'],
    'student_proof_file' => $clearance['student_proof_file'],
    'student_proof_remarks' => $clearance['student_proof_remarks']
]);
