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

$user_id = (int) ($_GET['user_id'] ?? 0);

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'No user ID provided']);
    exit();
}

$db = Database::getInstance();

$db->query("SELECT u.*, cr.course_name, col.college_name
            FROM users u
            LEFT JOIN course cr ON u.course_id = cr.course_id
            LEFT JOIN college col ON u.college_id = col.college_id
            WHERE u.users_id = :user_id");
$db->bind(':user_id', $user_id);
$student = $db->single();

if ($student) {
    $db->query("SELECT
                    COUNT(*) as total_records,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                    SUM(CASE WHEN lacking_comment IS NOT NULL THEN 1 ELSE 0 END) as lacking_count,
                    SUM(CASE WHEN student_proof_file IS NOT NULL THEN 1 ELSE 0 END) as student_proof_count,
                    SUM(CASE WHEN proof_file IS NOT NULL THEN 1 ELSE 0 END) as office_proof_count
                FROM clearance
                WHERE users_id = :user_id");
    $db->bind(':user_id', $user_id);
    $summary = $db->single() ?: [];

    $db->query("SELECT
                    c.clearance_id,
                    o.office_name,
                    ct.clearance_name as clearance_type,
                    c.status,
                    c.semester,
                    c.school_year,
                    c.lacking_comment,
                    c.student_proof_file,
                    c.student_proof_remarks,
                    c.proof_file,
                    DATE_FORMAT(COALESCE(c.updated_at, c.created_at), '%Y-%m-%d %H:%i:%s') as updated_at,
                    DATE_FORMAT(COALESCE(c.updated_at, c.created_at), '%b %d, %Y %h:%i %p') as updated_label
                FROM clearance c
                LEFT JOIN offices o ON c.office_id = o.office_id
                LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                WHERE c.users_id = :user_id
                ORDER BY COALESCE(c.updated_at, c.created_at) DESC
                LIMIT 20");
    $db->bind(':user_id', $user_id);
    $records = $db->resultSet();

    echo json_encode([
        'success' => true,
        'student' => $student,
        'summary' => [
            'total_records' => (int) ($summary['total_records'] ?? 0),
            'pending_count' => (int) ($summary['pending_count'] ?? 0),
            'approved_count' => (int) ($summary['approved_count'] ?? 0),
            'rejected_count' => (int) ($summary['rejected_count'] ?? 0),
            'lacking_count' => (int) ($summary['lacking_count'] ?? 0),
            'student_proof_count' => (int) ($summary['student_proof_count'] ?? 0),
            'office_proof_count' => (int) ($summary['office_proof_count'] ?? 0)
        ],
        'records' => $records
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Student not found']);
}
