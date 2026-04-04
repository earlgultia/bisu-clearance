<?php
// clinic_dashboard.php - Clinic Dashboard for BISU Online Clearance System
// Location: C:\xampp\htdocs\clearance\organization\clinic_dashboard.php

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database configuration with correct path
require_once __DIR__ . '/../db.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user is organization
if ($_SESSION['user_role'] !== 'organization') {
    header("Location: ../index.php");
    exit();
}

// Get database instance
$db = Database::getInstance();

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
        error_log("Error ensuring clinic organization proof columns: " . $e->getMessage());
    }
}

ensureOrganizationProofColumns($db);

// Get organization information from session
$org_id = $_SESSION['user_id'];
$org_name = $_SESSION['user_name'] ?? '';
$org_email = $_SESSION['user_email'] ?? '';
$org_type = $_SESSION['org_type'] ?? '';

// Verify that this is a clinic organization
if ($org_type !== 'clinic') {
    // Not authorized for clinic dashboard
    header("Location: ../index.php");
    exit();
}

function extractClinicLackingComment($remarks)
{
    if (!is_string($remarks) || $remarks === '') {
        return '';
    }

    if (preg_match('/\[CLINIC_LACKING\]\s*(.+?)(?=\s*\|\s*|$)/s', $remarks, $matches)) {
        return trim($matches[1]);
    }

    return '';
}

function upsertClinicLackingComment($remarks, $comment)
{
    $base = preg_replace('/\s*\|\s*\[CLINIC_LACKING\]\s*.+?(?=\s*\|\s*|$)/s', '', (string) $remarks);
    $base = preg_replace('/^\[CLINIC_LACKING\]\s*.+?(?=\s*\|\s*|$)/s', '', trim($base));
    $base = trim($base, " \t\n\r\0\x0B|");
    $comment = trim((string) $comment);

    if ($comment === '') {
        return $base;
    }

    return $base !== '' ? $base . ' | [CLINIC_LACKING] ' . $comment : '[CLINIC_LACKING] ' . $comment;
}

function stripClinicLackingMarker($remarks)
{
    $clean = preg_replace('/\s*\|\s*\[CLINIC_LACKING\]\s*.+?(?=\s*\|\s*|$)/s', '', (string) $remarks);
    $clean = preg_replace('/^\[CLINIC_LACKING\]\s*.+?(?=\s*\|\s*|$)/s', '', trim($clean));
    $clean = trim($clean, " \t\n\r\0\x0B|");

    return $clean;
}

// Get clinic organization details from student_organizations.
$db->query("SELECT so.*, o.office_name, o.office_order
            FROM student_organizations so
            LEFT JOIN offices o ON so.office_id = o.office_id
            WHERE so.org_id = :org_id");
$db->bind(':org_id', $org_id);
$clinic_org = $db->single();
$clinic_office_id = $clinic_org['office_id'] ?? null;

if (!$clinic_office_id) {
    $error = "Clinic office not configured in the system.";
}

// Initialize variables
$success = '';
$error = '';
$active_tab = $_GET['tab'] ?? 'dashboard';
$profile_pic = null;
$filter_semester = $_GET['semester'] ?? '';
$filter_school_year = $_GET['school_year'] ?? '';
$filter_status = $_GET['status'] ?? '';

// Get SAS office ID once for organization_clearance sync.
$db->query("SELECT office_id FROM offices WHERE office_name = 'Director_SAS' LIMIT 1");
$sas_office_row = $db->single();
$sas_office_id = $sas_office_row['office_id'] ?? null;

/**
 * Mirror clinic organization decision into SAS organization_clearance.
 */
function syncClinicOrganizationDecision($db, $source_clearance_id, $org_id, $status, $remarks, $sas_office_id)
{
        if (!$sas_office_id || !$source_clearance_id || !$org_id || !$status) {
                return;
        }

        // Ensure org row exists for the matching SAS clearance of the same student/term.
        $db->query("INSERT INTO organization_clearance (clearance_id, org_id, office_id, status, remarks, processed_by, processed_date, created_at, updated_at)
                                SELECT c_sas.clearance_id, :org_id_insert, c_sas.office_id, :status_insert, :remarks_insert, :processed_by_insert, NOW(), NOW(), NOW()
                                FROM clearance c_src
                                JOIN clearance c_sas
                                    ON c_sas.users_id = c_src.users_id
                                 AND c_sas.semester = c_src.semester
                                 AND c_sas.school_year = c_src.school_year
                                 AND c_sas.office_id = :sas_office_id
                                LEFT JOIN organization_clearance oc
                                    ON oc.clearance_id = c_sas.clearance_id
                                 AND oc.org_id = :org_id_match
                                WHERE c_src.clearance_id = :source_clearance_id
                                    AND oc.org_clearance_id IS NULL
                                LIMIT 1");
        $db->bind(':org_id_insert', $org_id);
        $db->bind(':status_insert', $status);
        $db->bind(':remarks_insert', $remarks);
        $db->bind(':processed_by_insert', $org_id);
        $db->bind(':sas_office_id', $sas_office_id);
        $db->bind(':org_id_match', $org_id);
        $db->bind(':source_clearance_id', $source_clearance_id);
        $db->execute();

        // Update existing org row for the same SAS clearance.
        $db->query("UPDATE organization_clearance oc
                                JOIN clearance c_sas ON oc.clearance_id = c_sas.clearance_id
                                JOIN clearance c_src
                                    ON c_sas.users_id = c_src.users_id
                                 AND c_sas.semester = c_src.semester
                                 AND c_sas.school_year = c_src.school_year
                                SET oc.status = :status,
                                        oc.remarks = CONCAT(IFNULL(oc.remarks, ''), ' | Clinic: ', :remarks),
                                        oc.processed_by = :processed_by,
                                        oc.processed_date = NOW(),
                                        oc.updated_at = NOW()
                                WHERE c_src.clearance_id = :source_clearance_id
                                    AND c_sas.office_id = :sas_office_id
                                    AND oc.org_id = :org_id");
        $db->bind(':status', $status);
        $db->bind(':remarks', $remarks);
        $db->bind(':processed_by', $org_id);
        $db->bind(':source_clearance_id', $source_clearance_id);
        $db->bind(':sas_office_id', $sas_office_id);
        $db->bind(':org_id', $org_id);
        $db->execute();
}

// ============================================
// HANDLE LACKING COMMENT
// ============================================
if (isset($_POST['add_lacking_comment'])) {
    $clearance_id = (int) ($_POST['clearance_id'] ?? 0);
    $comment = trim($_POST['lacking_comment'] ?? '');

    if ($clearance_id > 0 && $comment !== '') {
        try {
            $db->beginTransaction();

            $db->query("SELECT oc.org_clearance_id, oc.org_id, oc.status as org_status, oc.remarks as org_remarks,
                               oc.student_proof_file,
                               c.clearance_id, c.office_id,
                               u.fname, u.lname
                        FROM organization_clearance oc
                        JOIN clearance c ON oc.clearance_id = c.clearance_id
                        JOIN users u ON c.users_id = u.users_id
                        WHERE oc.org_id = :org_id AND c.clearance_id = :clearance_id");
            $db->bind(':org_id', $org_id);
            $db->bind(':clearance_id', $clearance_id);
            $current = $db->single();

            if (!$current) {
                throw new Exception("Clearance not found for this clinic organization.");
            }

            if ($current['org_status'] !== 'pending') {
                throw new Exception("You can only add a lacking comment to pending clearances.");
            }

            $updated_remarks = upsertClinicLackingComment($current['org_remarks'] ?? '', $comment);

            $db->query("UPDATE organization_clearance SET
                        remarks = :remarks,
                        updated_at = NOW()
                        WHERE org_clearance_id = :org_clearance_id AND org_id = :org_id");
            $db->bind(':remarks', $updated_remarks);
            $db->bind(':org_clearance_id', $current['org_clearance_id']);
            $db->bind(':org_id', $org_id);

            if (!$db->execute()) {
                throw new Exception("Failed to save lacking comment.");
            }

            $db->commit();
            $success = "Lacking comment saved for {$current['fname']} {$current['lname']}.";
            header("Location: clinic_dashboard.php?tab=pending&success=1");
            exit();
        } catch (Exception $e) {
            $db->rollback();
            error_log("Error adding clinic lacking comment: " . $e->getMessage());
            $error = "Error: " . $e->getMessage();
        }
    } else {
        $error = "Please enter the lacking details before saving.";
    }
}

// ============================================
// HANDLE CLEARANCE APPROVAL/REJECTION
// ============================================
if (isset($_POST['process_clearance'])) {
    $org_clearance_id = (int) ($_POST['org_clearance_id'] ?? 0);
    $status_input = strtolower(trim($_POST['status'] ?? ''));
    $status_map = [
        'approve' => 'approved',
        'approved' => 'approved',
        'reject' => 'rejected',
        'rejected' => 'rejected'
    ];
    $status = $status_map[$status_input] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    if ($org_clearance_id > 0 && $status) {
        try {
            $db->beginTransaction();

            // Get current clearance info
            $db->query("SELECT oc.org_clearance_id, oc.clearance_id, oc.status, oc.remarks as org_remarks,
                               oc.student_proof_file,
                               c.users_id, u.fname, u.lname, u.ismis_id, u.course_id
                       FROM organization_clearance oc
                       JOIN clearance c ON oc.clearance_id = c.clearance_id
                       JOIN users u ON c.users_id = u.users_id
                       WHERE oc.org_clearance_id = :id AND oc.org_id = :org_id");
            $db->bind(':id', $org_clearance_id);
            $db->bind(':org_id', $org_id);
            $current = $db->single();

            if (!$current) {
                throw new Exception("Clearance not found");
            }

            // Check if clearance is still pending
            if ($current['status'] !== 'pending') {
                throw new Exception("This clearance has already been processed");
            }

            $clinic_lacking_comment = extractClinicLackingComment($current['org_remarks'] ?? '');

            if ($status === 'approved' && $clinic_lacking_comment !== '' && empty($current['student_proof_file'])) {
                throw new Exception("This clearance has an active lacking comment. Wait for the student proof before approving.");
            }

            // Update the clinic organization clearance
            $db->query("UPDATE organization_clearance SET 
                        status = :status, 
                        remarks = CONCAT(IFNULL(remarks, ''), ' | Clinic: ', :remarks),
                        processed_by = :processed_by, 
                        processed_date = NOW(),
                        updated_at = NOW()
                        WHERE org_clearance_id = :id AND org_id = :org_id");
            $db->bind(':status', $status);
            $db->bind(':remarks', $remarks);
            $db->bind(':processed_by', $org_id);
            $db->bind(':id', $org_clearance_id);
            $db->bind(':org_id', $org_id);

            if ($db->execute()) {
                // Log the activity
                $logModel = new ActivityLogModel();
                $logModel->log($org_id, 'PROCESS_CLEARANCE', ucfirst($status) . " clinic org clearance ID: {$current['org_clearance_id']} for student: {$current['fname']} {$current['lname']} ({$current['ismis_id']})");

                $db->commit();
                $success = "Clinic clearance " . ($status == 'approved' ? 'approved' : 'rejected') . " successfully!";

                // Redirect to refresh the page
                header("Location: clinic_dashboard.php?tab=pending&success=1");
                exit();
            } else {
                $db->rollback();
                $error = "Failed to process clearance.";
            }
        } catch (Exception $e) {
            $db->rollback();
            error_log("Error processing clinic clearance: " . $e->getMessage());
            $error = "Error: " . $e->getMessage();
        }
    }
}

// ============================================
// HANDLE BULK APPROVAL
// ============================================
if (isset($_POST['bulk_approve'])) {
    $org_clearance_ids = array_values(array_filter(array_map('intval', $_POST['org_clearance_ids'] ?? [])));
    $remarks = trim($_POST['bulk_remarks'] ?? '');

    if (!empty($org_clearance_ids)) {
        try {
            $db->beginTransaction();

            $named_placeholders = [];
            foreach ($org_clearance_ids as $index => $id) {
                $named_placeholders[] = ':id_' . $index;
            }
            $placeholders = implode(',', $named_placeholders);

            $db->query("SELECT COUNT(*) as count 
                       FROM organization_clearance
                       WHERE org_clearance_id IN ($placeholders)
                       AND org_id = :org_id
                       AND status = 'pending'");

            foreach ($org_clearance_ids as $index => $id) {
                $db->bind(':id_' . $index, $id);
            }
            $db->bind(':org_id', $org_id);
            $verify = $db->single();

            if ($verify['count'] != count($org_clearance_ids)) {
                $db->rollback();
                $error = "Some clearances are not valid for bulk approval.";
                throw new Exception("Invalid clearances");
            }

            $db->query("SELECT oc.org_clearance_id, oc.remarks, oc.student_proof_file
                       FROM organization_clearance oc
                       WHERE oc.org_clearance_id IN ($placeholders)
                       AND oc.org_id = :org_id");

            foreach ($org_clearance_ids as $index => $id) {
                $db->bind(':id_' . $index, $id);
            }
            $db->bind(':org_id', $org_id);
            $selected_clearances = $db->resultSet();

            foreach ($selected_clearances as $selected_clearance) {
                $has_lacking_comment = extractClinicLackingComment($selected_clearance['remarks'] ?? '') !== '';
                $has_student_proof = !empty($selected_clearance['student_proof_file']);

                if ($has_lacking_comment && !$has_student_proof) {
                    $db->rollback();
                    $error = "One or more selected clinic clearances still require student proof.";
                    throw new Exception("Missing student proof");
                }
            }

            $db->query("UPDATE organization_clearance SET 
                        status = 'approved', 
                        remarks = CONCAT(IFNULL(remarks, ''), ' | Clinic (Bulk): ', :remarks),
                        processed_by = :processed_by, 
                        processed_date = NOW(),
                        updated_at = NOW()
                        WHERE org_clearance_id IN ($placeholders)
                        AND org_id = :org_id");

            $db->bind(':remarks', $remarks);
            $db->bind(':processed_by', $org_id);
            $db->bind(':org_id', $org_id);

            foreach ($org_clearance_ids as $index => $id) {
                $db->bind(':id_' . $index, $id);
            }

            if ($db->execute()) {
                // Log bulk approval
                $logModel = new ActivityLogModel();
                $logModel->log($org_id, 'BULK_APPROVE', "Bulk approved " . count($org_clearance_ids) . " clinic clearances");

                $db->commit();
                $success = count($org_clearance_ids) . " clinic clearance(s) approved successfully!";

                header("Location: clinic_dashboard.php?tab=pending&success=1");
                exit();
            } else {
                $db->rollback();
                $error = "Failed to approve clearances.";
            }
        } catch (Exception $e) {
            if (!in_array($e->getMessage(), ["Invalid clearances", "Missing student proof"], true)) {
                $db->rollback();
                error_log("Error in bulk approval: " . $e->getMessage());
                $error = "Database error occurred.";
            }
        }
    }
}

// ============================================
// FETCH DASHBOARD DATA
// ============================================
$stats = [];
$pending_clearances = [];
$recent_clearances = [];
$clearance_history = [];
$students = [];
$clinic_records = [];

try {
    // Count statistics for clinic
    if ($clinic_office_id) {
        ensureOrganizationProofColumns($db);
        // Pending clearances
        $db->query("SELECT COUNT(*) as count FROM organization_clearance WHERE org_id = :org_id AND status = 'pending'");
        $db->bind(':org_id', $org_id);
        $result = $db->single();
        $stats['pending'] = $result ? (int) $result['count'] : 0;

        // Approved clearances
        $db->query("SELECT COUNT(*) as count FROM organization_clearance WHERE org_id = :org_id AND status = 'approved'");
        $db->bind(':org_id', $org_id);
        $result = $db->single();
        $stats['approved'] = $result ? (int) $result['count'] : 0;

        // Rejected clearances
        $db->query("SELECT COUNT(*) as count FROM organization_clearance WHERE org_id = :org_id AND status = 'rejected'");
        $db->bind(':org_id', $org_id);
        $result = $db->single();
        $stats['rejected'] = $result ? (int) $result['count'] : 0;

        // Total students processed
        $db->query("SELECT COUNT(DISTINCT c.users_id) as count
                    FROM organization_clearance oc
                    JOIN clearance c ON oc.clearance_id = c.clearance_id
                    WHERE oc.org_id = :org_id");
        $db->bind(':org_id', $org_id);
        $result = $db->single();
        $stats['students'] = $result ? (int) $result['count'] : 0;

        // Get pending clinic organization clearances with student info.
        $query = "SELECT oc.org_clearance_id, oc.status, oc.remarks, oc.student_proof_file, oc.student_proof_remarks, oc.student_proof_uploaded_at, oc.processed_by, oc.processed_date, oc.updated_at as org_updated_at,
                         c.clearance_id, c.users_id, c.semester, c.school_year, c.created_at,
                         u.fname, u.lname, u.ismis_id, u.course_id, u.address, u.contacts, u.age,
                         cr.course_name, col.college_name,
                         ct.clearance_name as clearance_type,
                         (SELECT COUNT(*) FROM clearance c2 
                          WHERE c2.users_id = c.users_id 
                          AND c2.semester = c.semester 
                          AND c2.school_year = c.school_year 
                          AND c2.status = 'approved') as approved_count,
                         (SELECT COUNT(*) FROM clearance c3 
                          WHERE c3.users_id = c.users_id 
                          AND c3.semester = c.semester 
                          AND c3.school_year = c.school_year) as total_count,
                         (SELECT GROUP_CONCAT(o.office_name SEPARATOR ', ') 
                          FROM clearance c4
                          JOIN offices o ON c4.office_id = o.office_id
                          WHERE c4.users_id = c.users_id 
                          AND c4.semester = c.semester 
                          AND c4.school_year = c.school_year 
                          AND c4.status = 'approved') as approved_offices
                  FROM organization_clearance oc
                  JOIN clearance c ON oc.clearance_id = c.clearance_id
                  JOIN users u ON c.users_id = u.users_id
                  LEFT JOIN course cr ON u.course_id = cr.course_id
                  LEFT JOIN college col ON u.college_id = col.college_id
                  LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                  WHERE oc.org_id = :org_id AND oc.status = 'pending'";

        $params = [':org_id' => $org_id];

        if (!empty($filter_status)) {
            $query .= " AND oc.status = :status";
            $params[':status'] = $filter_status;
        }

        $query .= " ORDER BY c.created_at ASC";

        $db->query($query);
        foreach ($params as $key => $value) {
            $db->bind($key, $value);
        }
        $pending_clearances = $db->resultSet();
        foreach ($pending_clearances as &$pending_clearance) {
            $pending_clearance['lacking_comment'] = extractClinicLackingComment($pending_clearance['remarks'] ?? '');
            $pending_clearance['remarks_display'] = stripClinicLackingMarker($pending_clearance['remarks'] ?? '');
        }
        unset($pending_clearance);

        // Get recent approved/rejected clearances
         $query = "SELECT oc.org_clearance_id, oc.status, oc.remarks, oc.processed_by, oc.processed_date,
                    c.clearance_id, c.users_id, c.semester, c.school_year,
                    u.fname, u.lname, u.ismis_id, cr.course_name, col.college_name,
                    ct.clearance_name as clearance_type,
                    COALESCE(p.fname, so_proc.org_name, 'Clinic') as processed_fname,
                    COALESCE(p.lname, '') as processed_lname
                  FROM organization_clearance oc
                  JOIN clearance c ON oc.clearance_id = c.clearance_id
                  JOIN users u ON c.users_id = u.users_id
                  LEFT JOIN course cr ON u.course_id = cr.course_id
                  LEFT JOIN college col ON u.college_id = col.college_id
                  LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                  LEFT JOIN users p ON oc.processed_by = p.users_id
                LEFT JOIN student_organizations so_proc ON oc.processed_by = so_proc.org_id
                  WHERE oc.org_id = :org_id AND oc.status IN ('approved', 'rejected')
                  ORDER BY oc.processed_date DESC
                  LIMIT 20";

        $db->query($query);
        $db->bind(':org_id', $org_id);
        $recent_clearances = $db->resultSet();
        foreach ($recent_clearances as &$recent_clearance) {
            $recent_clearance['remarks_display'] = stripClinicLackingMarker($recent_clearance['remarks'] ?? '');
        }
        unset($recent_clearance);

        // Get clearance history with filters
         $query = "SELECT oc.org_clearance_id, oc.status, oc.remarks, oc.processed_by, oc.processed_date,
                    c.clearance_id, c.users_id, c.semester, c.school_year, c.created_at,
                    u.fname, u.lname, u.ismis_id, u.address, u.contacts, u.age, cr.course_name, col.college_name,
                    ct.clearance_name as clearance_type,
                    COALESCE(p.fname, so_proc.org_name, 'Clinic') as processed_fname,
                    COALESCE(p.lname, '') as processed_lname
                  FROM organization_clearance oc
                  JOIN clearance c ON oc.clearance_id = c.clearance_id
                  JOIN users u ON c.users_id = u.users_id
                  LEFT JOIN course cr ON u.course_id = cr.course_id
                  LEFT JOIN college col ON u.college_id = col.college_id
                  LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                  LEFT JOIN users p ON oc.processed_by = p.users_id
                LEFT JOIN student_organizations so_proc ON oc.processed_by = so_proc.org_id
                  WHERE oc.org_id = :org_id";

        $params = [':org_id' => $org_id];

        if (!empty($filter_semester)) {
            $query .= " AND c.semester = :semester";
            $params[':semester'] = $filter_semester;
        }

        if (!empty($filter_school_year)) {
            $query .= " AND c.school_year = :school_year";
            $params[':school_year'] = $filter_school_year;
        }

        if (!empty($filter_status)) {
            $query .= " AND c.status = :status";
            $params[':status'] = $filter_status;
        }

        $query .= " ORDER BY c.created_at DESC LIMIT 50";

        $db->query($query);
        foreach ($params as $key => $value) {
            $db->bind($key, $value);
        }
        $clearance_history = $db->resultSet();
        foreach ($clearance_history as &$history_clearance) {
            $history_clearance['remarks_display'] = stripClinicLackingMarker($history_clearance['remarks'] ?? '');
        }
        unset($history_clearance);

        // Get clinic records from clinic_records table (if exists)
        $db->query("SELECT cr.*, u.fname, u.lname, u.ismis_id, u.course_id,
                           crs.course_name
                    FROM clinic_records cr
                    JOIN users u ON cr.users_id = u.users_id
                    LEFT JOIN course crs ON u.course_id = crs.course_id
                    ORDER BY cr.created_at DESC
                    LIMIT 50");
        $clinic_records = $db->resultSet();

        // Get all students for quick access
        $db->query("SELECT users_id, fname, lname, ismis_id, course_id 
                    FROM users 
                    WHERE user_role_id = (SELECT user_role_id FROM user_role WHERE user_role_name = 'student')
                    AND is_active = 1
                    ORDER BY lname, fname");
        $students = $db->resultSet();

        // Get distinct semesters and school years for filters
        $db->query("SELECT DISTINCT semester FROM clearance WHERE semester IS NOT NULL ORDER BY semester");
        $stats['semesters'] = $db->resultSet();

        $db->query("SELECT DISTINCT school_year FROM clearance WHERE school_year IS NOT NULL ORDER BY school_year DESC");
        $stats['school_years'] = $db->resultSet();
    }

    // Get organization profile picture (if any)
    $profile_pic = null;

} catch (Exception $e) {
    error_log("Error fetching clinic data: " . $e->getMessage());
    $error = "Error loading dashboard data: " . $e->getMessage();
}

$approval_total = ($stats['approved'] ?? 0) + ($stats['rejected'] ?? 0);
$approval_rate = $approval_total > 0 ? round((($stats['approved'] ?? 0) / $approval_total) * 100) : 0;
$longest_wait_days = !empty($pending_clearances) ? max(array_map(static function ($clearance) {
    return isset($clearance['created_at']) ? max(0, (int) floor((time() - strtotime($clearance['created_at'])) / 86400)) : 0;
}, $pending_clearances)) : 0;
$recent_activity_count = count($recent_clearances ?? []);
$today_label = date('l, F j, Y');

// Helper function to get status class
function getStatusClass($status)
{
    return $status == 'approved' ? 'status-approved' : ($status == 'rejected' ? 'status-rejected' : 'status-pending');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Clinic Dashboard - BISU Online Clearance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=Source+Sans+3:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        :root {
            --primary: #0b7d5a;
            --primary-dark: #096b4c;
            --primary-light: #2e9b7a;
            --primary-soft: rgba(11, 125, 90, 0.1);
            --primary-glow: rgba(11, 125, 90, 0.2);
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-tertiary: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border-color: #e2e8f0;
            --card-bg: #ffffff;
            --card-shadow: 0 4px 20px rgba(0, 0, 0, 0.02);
            --card-shadow-hover: 0 10px 30px rgba(11, 125, 90, 0.08);
            --header-bg: linear-gradient(135deg, #0b7d5a 0%, #2e9b7a 100%);
            --sidebar-bg: #ffffff;
            --input-bg: #ffffff;
            --input-border: #e2e8f0;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --success-soft: rgba(16, 185, 129, 0.1);
            --warning-soft: rgba(245, 158, 11, 0.1);
            --danger-soft: rgba(239, 68, 68, 0.1);
            --info-soft: rgba(59, 130, 246, 0.1);
        }

        .dark-mode {
            --primary: #4fd1b5;
            --primary-dark: #3bb59a;
            --primary-light: #6bdebc;
            --primary-soft: rgba(79, 209, 181, 0.15);
            --primary-glow: rgba(79, 209, 181, 0.25);
            --bg-primary: #1a1b2f;
            --bg-secondary: #22243e;
            --bg-tertiary: #2a2c4a;
            --text-primary: #f0f1fa;
            --text-secondary: #cbd5e0;
            --text-muted: #a0a8b8;
            --border-color: #2d2f4a;
            --card-bg: #22243e;
            --card-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            --card-shadow-hover: 0 10px 30px rgba(79, 209, 181, 0.15);
            --header-bg: linear-gradient(135deg, #1f4037 0%, #2d5a4a 100%);
            --sidebar-bg: #22243e;
            --input-bg: #2a2c4a;
            --input-border: #3d3f60;
            --success: #4ade80;
            --warning: #fbbf24;
            --danger: #f87171;
            --info: #60a5fa;
            --success-soft: rgba(74, 222, 128, 0.1);
            --warning-soft: rgba(251, 191, 36, 0.1);
            --danger-soft: rgba(248, 113, 113, 0.1);
            --info-soft: rgba(96, 165, 250, 0.1);
        }

        body {
            background: var(--bg-secondary);
            color: var(--text-primary);
            min-height: 100vh;
            transition: background-color 0.3s ease, color 0.2s ease;
        }

        .theme-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 9999;
            box-shadow: 0 4px 15px var(--primary-glow);
            border: 2px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .theme-toggle i {
            font-size: 1.5rem;
            color: white;
            transition: transform 0.3s ease;
        }

        .theme-toggle:hover {
            transform: scale(1.1);
        }

        .theme-toggle:hover i {
            transform: rotate(360deg);
        }

        .header {
            background: var(--header-bg);
            color: white;
            padding: 1rem 5%;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
            gap: 16px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .menu-toggle {
            display: none;
            width: 46px;
            height: 46px;
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.14);
            color: white;
            cursor: pointer;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            backdrop-filter: blur(10px);
        }

        .logo-icon {
            width: 45px;
            height: 45px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            color: white;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .logo h2 {
            font-size: 1.3rem;
            font-weight: 500;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            background: rgba(255, 255, 255, 0.1);
            padding: 8px 20px;
            border-radius: 30px;
            backdrop-filter: blur(10px);
            cursor: pointer;
            transition: 0.3s;
        }

        .user-info:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
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
        }

        .user-role {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.15);
            color: white;
            padding: 10px 25px;
            border-radius: 30px;
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 500;
            transition: 0.3s;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.25);
            transform: translateY(-2px);
        }

        .nav-item.mobile-logout-item {
            display: none;
            margin-top: 8px;
            background: rgba(220, 38, 38, 0.12);
            color: #b91c1c;
        }

        .nav-item.mobile-logout-item:hover {
            background: #dc2626;
            color: #fff;
        }

        .main-container {
            display: flex;
            margin-top: 70px;
            min-height: calc(100vh - 70px);
            max-width: 1400px;
            margin-left: auto;
            margin-right: auto;
        }

        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            padding: 30px 0;
            position: fixed;
            height: calc(100vh - 70px);
            overflow-y: auto;
            transition: all 0.3s ease;
        }

        .sidebar-backdrop {
            position: fixed;
            inset: 70px 0 0;
            background: rgba(2, 8, 23, 0.42);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.25s ease, visibility 0.25s ease;
            z-index: 1000;
        }

        .sidebar-backdrop.show {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }

        .profile-section {
            text-align: center;
            padding: 0 20px 25px;
            border-bottom: 1px solid var(--border-color);
        }

        .profile-avatar {
            width: 100px;
            height: 100px;
            margin: 0 auto 15px;
            position: relative;
            cursor: pointer;
            border-radius: 50%;
            overflow: hidden;
            border: 4px solid var(--primary-soft);
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }

        .profile-avatar:hover img {
            transform: scale(1.1);
        }

        .avatar-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            opacity: 0;
            transition: opacity 0.3s;
            border-radius: 50%;
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
            font-size: 1.2rem;
            z-index: 2;
        }

        .upload-progress.show {
            display: flex;
        }

        .profile-name {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .profile-email {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 10px;
        }

        .profile-badge {
            background: var(--primary-soft);
            color: var(--primary);
            padding: 5px 20px;
            border-radius: 30px;
            font-size: 0.85rem;
            display: inline-block;
        }

        .org-badge {
            background: var(--info-soft);
            color: var(--info);
            padding: 5px 20px;
            border-radius: 30px;
            font-size: 0.85rem;
            display: inline-block;
            margin-top: 10px;
        }

        .nav-menu {
            padding: 20px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: var(--text-secondary);
            border-radius: 12px;
            transition: 0.3s;
            margin-bottom: 5px;
            cursor: pointer;
            border: none;
            width: 100%;
            background: none;
            font-size: 0.95rem;
            text-align: left;
        }

        .nav-item:hover {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .nav-item.active {
            background: var(--primary);
            color: white;
        }

        .nav-item i {
            width: 22px;
            font-size: 1.2rem;
        }

        .content-area {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .dashboard-stack {
            display: grid;
            gap: 24px;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            padding: 30px 35px;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px var(--primary-glow);
            position: relative;
            overflow: hidden;
        }

        .welcome-banner::before {
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

        .welcome-banner h1 {
            font-size: 2rem;
            margin-bottom: 10px;
            position: relative;
        }

        .welcome-banner p {
            opacity: 0.95;
            font-size: 1.1rem;
            position: relative;
        }

        .org-info {
            background: rgba(255, 255, 255, 0.15);
            padding: 10px 25px;
            border-radius: 40px;
            display: inline-block;
            margin-top: 15px;
            backdrop-filter: blur(10px);
            position: relative;
        }

        .hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) minmax(280px, 0.95fr);
            gap: 24px;
            align-items: start;
        }

        .hero-copy {
            max-width: 720px;
        }

        .hero-kpis {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 22px;
        }

        .hero-kpi {
            min-width: 140px;
            padding: 14px 16px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.18);
            backdrop-filter: blur(12px);
        }

        .hero-kpi strong {
            display: block;
            font-size: 1.2rem;
            font-family: 'Manrope', sans-serif;
        }

        .hero-kpi span {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .hero-panel {
            padding: 22px;
            border-radius: 22px;
            background: rgba(8, 38, 29, 0.24);
            border: 1px solid rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(14px);
        }

        .hero-panel h3 {
            font-family: 'Manrope', sans-serif;
            font-size: 1.05rem;
            margin-bottom: 14px;
        }

        .hero-actions {
            display: grid;
            gap: 12px;
        }

        .hero-action {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 14px 16px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.14);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.12);
            cursor: pointer;
            transition: transform 0.2s ease, background 0.2s ease;
        }

        .hero-action:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, 0.2);
        }

        .hero-action-label strong,
        .hero-action-label span {
            display: block;
        }

        .hero-action-label span {
            opacity: 0.84;
            font-size: 0.88rem;
            margin-top: 3px;
        }

        .insights-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
        }

        .insight-card {
            padding: 20px;
            border-radius: 18px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(248, 250, 252, 0.96));
            border: 1px solid var(--border-color);
            box-shadow: var(--card-shadow);
        }

        .insight-card .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            padding: 6px 10px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary);
            font-size: 0.82rem;
            font-weight: 700;
        }

        .insight-card h3 {
            font-family: 'Manrope', sans-serif;
            font-size: 1.55rem;
            color: var(--text-primary);
            margin-bottom: 6px;
        }

        .insight-card p {
            color: var(--text-secondary);
            line-height: 1.55;
        }

        .search-results-grid {
            display: grid;
            gap: 12px;
        }

        .search-result-card {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: center;
            padding: 16px 18px;
            border-radius: 16px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
        }

        .search-result-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 6px;
        }

        .office-progress-list {
            display: grid;
            gap: 12px;
            margin-top: 20px;
        }

        .office-progress-item {
            padding: 14px 16px;
            border-radius: 16px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
        }

        .office-progress-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }

        .office-progress-note {
            color: var(--text-secondary);
            line-height: 1.5;
            font-size: 0.92rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: 0.3s;
            border: 1px solid var(--border-color);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.pending {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .stat-icon.approved {
            background: var(--success-soft);
            color: var(--success);
        }

        .stat-icon.rejected {
            background: var(--danger-soft);
            color: var(--danger);
        }

        .stat-icon.students {
            background: var(--info-soft);
            color: var(--info);
        }

        .stat-details h3 {
            font-size: 1.8rem;
            margin-bottom: 3px;
            color: var(--text-primary);
        }

        .stat-details p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .section-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .section-card:hover {
            box-shadow: var(--card-shadow-hover);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .section-header h2 {
            color: var(--text-primary);
            font-size: 1.4rem;
            font-weight: 600;
        }

        .section-header h2 i {
            color: var(--primary);
            margin-right: 10px;
        }

        .filter-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
            align-items: center;
            background: var(--bg-secondary);
            padding: 15px 20px;
            border-radius: 50px;
            border: 1px solid var(--border-color);
        }

        .filter-select {
            padding: 10px 20px;
            border: 2px solid var(--input-border);
            border-radius: 30px;
            background: var(--input-bg);
            color: var(--text-primary);
            font-size: 0.95rem;
            cursor: pointer;
            min-width: 140px;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .filter-input {
            flex: 1;
            padding: 10px 20px;
            border: 2px solid var(--input-border);
            border-radius: 30px;
            background: var(--input-bg);
            color: var(--text-primary);
            font-size: 0.95rem;
            min-width: 250px;
        }

        .filter-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .filter-btn {
            padding: 10px 25px;
            border: none;
            border-radius: 30px;
            background: var(--primary);
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .filter-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px var(--primary-glow);
        }

        .clear-filter {
            background: var(--danger-soft);
            color: var(--danger);
            padding: 10px 20px;
            border-radius: 30px;
            cursor: pointer;
            transition: 0.3s;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border: 1px solid var(--danger-soft);
        }

        .clear-filter:hover {
            background: var(--danger);
            color: white;
        }

        .table-responsive {
            overflow-x: auto;
            border-radius: 15px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 15px;
            background: var(--primary-soft);
            color: var(--primary);
            font-weight: 600;
            font-size: 0.95rem;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-secondary);
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-pending {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .status-approved {
            background: var(--success-soft);
            color: var(--success);
        }

        .status-rejected {
            background: var(--danger-soft);
            color: var(--danger);
        }

        .type-badge {
            background: var(--primary-soft);
            color: var(--primary);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .progress-badge {
            background: var(--info-soft);
            color: var(--info);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .medical-badge {
            background: var(--success-soft);
            color: var(--success);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .action-btns {
            display: flex;
            gap: 6px;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
        }

        .action-btn.approve {
            background: var(--success-soft);
            color: var(--success);
        }

        .action-btn.reject {
            background: var(--danger-soft);
            color: var(--danger);
        }

        .action-btn.lacking {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .action-btn.view {
            background: var(--info-soft);
            color: var(--info);
        }

        .action-btn.view-proof {
            background: rgba(14, 165, 233, 0.12);
            color: #0ea5e9;
        }

        .action-btn:hover {
            transform: translateY(-2px);
        }

        .action-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .select-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary);
        }

        .pending-flags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 6px;
        }

        .mini-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
        }

        .mini-badge.lacking {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .mini-badge.proof {
            background: var(--info-soft);
            color: var(--info);
        }

        .proof-preview {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 16px;
            text-align: center;
        }

        .proof-preview img {
            max-width: 100%;
            max-height: 400px;
            border-radius: 12px;
            cursor: pointer;
        }

        .detail-list {
            display: grid;
            gap: 12px;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .detail-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .detail-item span:first-child {
            color: var(--text-secondary);
        }

        .detail-item span:last-child {
            color: var(--text-primary);
            font-weight: 600;
        }

        .progress-indicator {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 10px;
            background: var(--bg-secondary);
            border-radius: 30px;
            font-size: 0.85rem;
        }

        .progress-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--border-color);
        }

        .progress-dot.completed {
            background: var(--success);
        }

        .progress-dot.current {
            background: var(--primary);
            box-shadow: 0 0 0 2px var(--primary-soft);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .modal-content {
            background: var(--card-bg);
            border-radius: 24px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            border: 1px solid var(--border-color);
            animation: slideUp 0.3s ease;
        }

        .modal-content.large {
            max-width: 700px;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--primary-soft) 0%, transparent 100%);
            border-radius: 24px 24px 0 0;
        }

        .modal-header h3 {
            color: var(--text-primary);
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h3 i {
            color: var(--primary);
            font-size: 1.4rem;
        }

        .close {
            width: 35px;
            height: 35px;
            background: var(--danger-soft);
            color: var(--danger);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            cursor: pointer;
            transition: 0.3s;
            border: none;
        }

        .close:hover {
            background: var(--danger);
            color: white;
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 25px;
        }

        .modal-footer {
            padding: 20px 25px 25px;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            border-top: 1px solid var(--border-color);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.95rem;
        }

        .form-group label i {
            color: var(--primary);
            margin-right: 8px;
        }

        .form-group textarea,
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--input-border);
            border-radius: 12px;
            font-size: 0.95rem;
            transition: 0.3s;
            background: var(--input-bg);
            color: var(--text-primary);
        }

        .form-group textarea:focus,
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 40px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            box-shadow: 0 4px 12px var(--primary-glow);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px var(--primary-glow);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3);
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--border-color);
        }

        .student-info-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
        }

        .student-info-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .student-info-header h4 {
            color: var(--text-primary);
            font-size: 1.1rem;
            font-weight: 600;
        }

        .student-info-header h4 i {
            color: var(--primary);
            margin-right: 8px;
        }

        .student-info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }

        .student-info-item {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .student-info-item .label {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        .student-info-item .value {
            color: var(--text-primary);
            font-weight: 600;
            font-size: 1rem;
        }

        .students-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .student-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 20px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .student-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
        }

        .student-avatar {
            width: 50px;
            height: 50px;
            background: var(--primary-soft);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 1.5rem;
        }

        .student-info h4 {
            color: var(--text-primary);
            font-size: 1.1rem;
            font-weight: 600;
        }

        .student-info p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-primary {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .badge-success {
            background: var(--success-soft);
            color: var(--success);
        }

        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: var(--success-soft);
            color: var(--success);
            border: 1px solid var(--success-soft);
        }

        .alert-error {
            background: var(--danger-soft);
            color: var(--danger);
            border: 1px solid var(--danger-soft);
        }

        .empty-state {
            text-align: center;
            padding: 50px;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        .empty-state h3 {
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .empty-state p {
            color: var(--text-muted);
            margin-bottom: 20px;
        }

        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: 10px;
            color: white;
            font-weight: 500;
            z-index: 10000;
            animation: slideInRight 0.3s ease;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
        }

        .toast-success {
            background: var(--success);
        }

        .toast-error {
            background: var(--danger);
        }

        .toast-info {
            background: var(--info);
        }

        @keyframes slideInRight {
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

        /* Professional UI polish */
        body {
            min-height: 100dvh;
            background:
                radial-gradient(circle at 100% 0%, rgba(46, 155, 122, 0.12), transparent 36%),
                radial-gradient(circle at 0% 100%, rgba(11, 125, 90, 0.09), transparent 42%),
                var(--bg-secondary);
            font-family: 'Source Sans 3', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .logo h2,
        .section-header h2,
        .welcome-banner h1 {
            font-family: 'Manrope', sans-serif;
            letter-spacing: 0.01em;
        }

        .header {
            padding: calc(0.85rem + env(safe-area-inset-top, 0px)) calc(1rem + env(safe-area-inset-right, 0px)) 0.85rem calc(1rem + env(safe-area-inset-left, 0px));
            box-shadow: 0 10px 30px rgba(8, 38, 29, 0.22);
        }

        .section-card,
        .stat-card,
        .college-stat-card,
        .course-stat-card,
        .year-stat-card,
        .student-card,
        .undo-item,
        .info-card {
            border-radius: 16px;
            border: 1px solid var(--border-color);
            box-shadow: var(--card-shadow);
        }

        .filter-select,
        .filter-input,
        .form-group textarea,
        .form-group input,
        .form-group select {
            border-radius: 12px;
            border-width: 1px;
        }

        .table-responsive {
            border: 1px solid var(--border-color);
            border-radius: 14px;
            background: var(--card-bg);
        }

        th {
            position: sticky;
            top: 0;
            z-index: 2;
            backdrop-filter: blur(5px);
        }

        tbody tr:nth-child(even) {
            background: rgba(11, 125, 90, 0.03);
        }

        .btn,
        .action-btn,
        .filter-btn,
        .clear-filter,
        .nav-item,
        .logout-btn {
            min-height: 44px;
        }

        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .hero-grid,
            .insights-grid {
                grid-template-columns: 1fr;
            }

            .student-info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .menu-toggle {
                display: inline-flex;
            }

            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: 70px;
                left: 0;
                z-index: 1001;
                height: calc(100vh - 70px);
                transition: 0.3s;
                box-shadow: 0 20px 40px rgba(15, 23, 42, 0.25);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .header-content {
                align-items: flex-start;
            }

            .logo h2 {
                font-size: 1.05rem;
            }

            .user-menu {
                gap: 10px;
                margin-left: auto;
            }

            .user-info {
                display: none;
            }

            .logout-btn {
                display: none;
            }

            .nav-item.mobile-logout-item {
                display: flex;
            }

            .content-area {
                margin-left: 0;
                padding: 20px 16px 32px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filter-bar {
                flex-direction: column;
                border-radius: 20px;
            }

            .filter-bar select,
            .filter-bar input,
            .filter-bar button {
                width: 100%;
            }

            .action-btns {
                flex-wrap: wrap;
            }

            .student-info-grid {
                grid-template-columns: 1fr;
            }

            .students-grid {
                grid-template-columns: 1fr;
            }

            .welcome-banner {
                padding: 24px 20px;
            }

            .hero-kpis {
                flex-direction: column;
            }

            .search-result-card {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>

<body>
    <div class="theme-toggle" id="themeToggle">
        <i class="fas fa-moon" id="themeIcon"></i>
    </div>

    <header class="header">
        <div class="header-content">
            <div class="logo">
                <button class="menu-toggle" id="menuToggle" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="orgSidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="logo-icon">
                    <i class="fas fa-clinic-medical"></i>
                </div>
                <h2>Clinic Dashboard</h2>
            </div>
            <div class="user-menu">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($profile_pic) && file_exists('../' . $profile_pic)): ?>
                            <img src="../<?php echo $profile_pic . '?t=' . time(); ?>" alt="Profile">
                        <?php else: ?>
                            <i class="fas fa-hospital"></i>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name">
                            <?php echo htmlspecialchars($org_name); ?>
                        </div>
                        <div class="user-role">Clinic</div>
                    </div>
                </div>
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>

    <div class="main-container">
        <aside class="sidebar" id="orgSidebar">
            <div class="profile-section">
                <div class="profile-avatar">
                    <i class="fas fa-hospital" style="font-size: 3rem; line-height: 100px;"></i>
                </div>

                <div class="profile-name">
                    <?php echo htmlspecialchars($org_name); ?>
                </div>
                <div class="profile-email">
                    <?php echo htmlspecialchars($org_email); ?>
                </div>
                <div class="profile-badge">Clinic</div>
                <div class="org-badge"><i class="fas fa-building"></i> University Clinic</div>
            </div>

            <nav class="nav-menu">
                <button class="nav-item <?php echo $active_tab == 'dashboard' ? 'active' : ''; ?>"
                    onclick="switchTab('dashboard')">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </button>
                <button class="nav-item <?php echo $active_tab == 'pending' ? 'active' : ''; ?>"
                    onclick="switchTab('pending')">
                    <i class="fas fa-clock"></i> Pending Clearances
                    <?php if (($stats['pending'] ?? 0) > 0): ?>
                        <span
                            style="margin-left: auto; background: var(--warning); color: white; padding: 2px 8px; border-radius: 20px; font-size: 0.8rem;">
                            <?php echo $stats['pending']; ?>
                        </span>
                    <?php endif; ?>
                </button>
                <button class="nav-item <?php echo $active_tab == 'history' ? 'active' : ''; ?>"
                    onclick="switchTab('history')">
                    <i class="fas fa-history"></i> Clearance History
                </button>
                <button class="nav-item <?php echo $active_tab == 'records' ? 'active' : ''; ?>"
                    onclick="switchTab('records')">
                    <i class="fas fa-notes-medical"></i> Medical Records
                </button>
                <button class="nav-item <?php echo $active_tab == 'students' ? 'active' : ''; ?>"
                    onclick="switchTab('students')">
                    <i class="fas fa-users"></i> Student Records
                </button>
                <a href="../logout.php" class="nav-item mobile-logout-item">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </nav>
        </aside>
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <main class="content-area">
            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <span>
                        <?php echo $success; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <span>
                        <?php echo $error; ?>
                    </span>
                </div>
            <?php endif; ?>

            <!-- Dashboard Tab -->
            <div id="dashboard" class="tab-content <?php echo $active_tab == 'dashboard' ? 'active' : ''; ?>">
                <div class="dashboard-stack">
                    <div class="welcome-banner">
                        <div class="hero-grid">
                            <div class="hero-copy">
                                <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', trim($org_name))[0] ?: 'Clinic'); ?></h1>
                                <p>Monitor medical clearances, catch unresolved requirements earlier, and review student progress from one focused clinic workspace.</p>
                                <div class="org-info">
                                    <i class="fas fa-info-circle"></i> University Clinic • <?php echo htmlspecialchars($today_label); ?>
                                </div>
                                <div class="hero-kpis">
                                    <div class="hero-kpi">
                                        <strong><?php echo (int) ($stats['pending'] ?? 0); ?></strong>
                                        <span>Pending right now</span>
                                    </div>
                                    <div class="hero-kpi">
                                        <strong><?php echo $approval_rate; ?>%</strong>
                                        <span>Approval rate</span>
                                    </div>
                                    <div class="hero-kpi">
                                        <strong><?php echo $longest_wait_days; ?> day<?php echo $longest_wait_days === 1 ? '' : 's'; ?></strong>
                                        <span>Longest current wait</span>
                                    </div>
                                </div>
                            </div>
                            <div class="hero-panel">
                                <h3>Quick actions</h3>
                                <div class="hero-actions">
                                    <button class="hero-action" type="button" onclick="switchTab('pending')">
                                        <span class="hero-action-label">
                                            <strong>Review pending queue</strong>
                                            <span>Handle clearances, check proof, and resolve lacking cases.</span>
                                        </span>
                                        <i class="fas fa-arrow-right"></i>
                                    </button>
                                    <button class="hero-action" type="button" onclick="switchTab('history')">
                                        <span class="hero-action-label">
                                            <strong>Audit recent decisions</strong>
                                            <span>Inspect approved and rejected clinic outcomes.</span>
                                        </span>
                                        <i class="fas fa-history"></i>
                                    </button>
                                    <button class="hero-action" type="button" onclick="switchTab('records')">
                                        <span class="hero-action-label">
                                            <strong>Open medical records</strong>
                                            <span>Jump to clinic records and student health status.</span>
                                        </span>
                                        <i class="fas fa-notes-medical"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="insights-grid">
                        <div class="insight-card">
                            <div class="eyebrow"><i class="fas fa-stethoscope"></i> Queue focus</div>
                            <h3><?php echo (int) ($stats['pending'] ?? 0); ?></h3>
                            <p>Medical clearances currently waiting for clinic action.</p>
                        </div>
                        <div class="insight-card">
                            <div class="eyebrow"><i class="fas fa-clipboard-check"></i> Activity</div>
                            <h3><?php echo $recent_activity_count; ?></h3>
                            <p>Recent clinic decisions available for quick follow-up and review.</p>
                        </div>
                        <div class="insight-card">
                            <div class="eyebrow"><i class="fas fa-users"></i> Student reach</div>
                            <h3><?php echo (int) ($stats['students'] ?? 0); ?></h3>
                            <p>Distinct students already tracked in the clinic clearance process.</p>
                        </div>
                    </div>

                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon pending">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="stat-details">
                                <h3><?php echo $stats['pending'] ?? 0; ?></h3>
                                <p>Pending</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon approved">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-details">
                                <h3><?php echo $stats['approved'] ?? 0; ?></h3>
                                <p>Approved</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon rejected">
                                <i class="fas fa-times-circle"></i>
                            </div>
                            <div class="stat-details">
                                <h3><?php echo $stats['rejected'] ?? 0; ?></h3>
                                <p>Rejected</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon students">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-details">
                                <h3><?php echo $stats['students'] ?? 0; ?></h3>
                                <p>Students</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Clearances -->
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-clock"></i> Recent Medical Clearances</h2>
                        <button class="btn btn-primary" onclick="switchTab('pending')">View All</button>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>ID</th>
                                    <th>Course</th>
                                    <th>Semester</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_clearances)): ?>
                                    <?php foreach (array_slice($recent_clearances, 0, 5) as $clearance): ?>
                                        <tr>
                                            <td>
                                                <?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($clearance['ismis_id']); ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($clearance['course_name'] ?? 'N/A'); ?>
                                            </td>
                                            <td>
                                                <?php echo ($clearance['semester'] ?? '') . ' ' . ($clearance['school_year'] ?? ''); ?>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo getStatusClass($clearance['status']); ?>">
                                                    <?php echo ucfirst($clearance['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo isset($clearance['processed_date']) ? date('M d, Y', strtotime($clearance['processed_date'])) : 'N/A'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: var(--text-muted);">No recent
                                            activities</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Quick Student Search -->
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-search"></i> Quick Student Search</h2>
                    </div>
                    <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                        <input type="text" class="filter-input" id="quickSearch"
                            placeholder="Enter student name or ISMIS ID..." style="flex: 1;">
                        <button class="btn btn-primary" onclick="searchStudent()">Search</button>
                    </div>
                    <div id="quickSearchResults" style="margin-top: 20px; display: none;"></div>
                </div>
            </div>

            <!-- Pending Clearances Tab -->
            <div id="pending" class="tab-content <?php echo $active_tab == 'pending' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-clock"></i> Pending Medical Clearances</h2>
                    </div>

                    <!-- Filter Bar -->
                    <div class="filter-bar">
                        <select class="filter-select" id="pendingSemesterFilter">
                            <option value="">All Semesters</option>
                            <?php foreach ($stats['semesters'] ?? [] as $sem): ?>
                                <option value="<?php echo $sem['semester']; ?>">
                                    <?php echo $sem['semester']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="filter-input" id="pendingSearch"
                            placeholder="Search by student name or ID...">
                        <button class="filter-btn" onclick="filterPending()"><i class="fas fa-filter"></i>
                            Filter</button>
                        <button class="clear-filter" onclick="clearPendingFilters()"><i class="fas fa-times"></i>
                            Clear</button>
                    </div>

                    <!-- Bulk Actions -->
                    <?php if (!empty($pending_clearances)): ?>
                        <div
                            style="margin-bottom: 20px; padding: 15px; background: var(--bg-secondary); border-radius: 12px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" id="selectAll" class="select-checkbox">
                                <label for="selectAll" style="color: var(--text-primary);">Select All</label>
                            </div>
                            <div style="flex: 1;">
                                <input type="text" id="bulkRemarks" class="filter-input"
                                    placeholder="Remarks for selected (optional)" style="width: 100%;">
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <button class="btn btn-success" onclick="bulkApprove()"><i class="fas fa-check-circle"></i>
                                    Approve Selected</button>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (empty($pending_clearances)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <h3>No pending medical clearances</h3>
                            <p>All medical clearances have been processed.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table id="pendingTable">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">Select</th>
                                        <th>Student</th>
                                        <th>ID</th>
                                        <th>Course</th>
                                        <th>Semester</th>
                                        <th>Progress</th>
                                        <th>Date Applied</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_clearances as $clearance): ?>
                                        <tr data-semester="<?php echo $clearance['semester']; ?>"
                                            data-name="<?php echo strtolower($clearance['fname'] . ' ' . $clearance['lname']); ?>"
                                            data-id="<?php echo strtolower($clearance['ismis_id']); ?>">
                                            <td>
                                                <input type="checkbox" class="select-checkbox clearance-checkbox"
                                                    value="<?php echo $clearance['org_clearance_id']; ?>">
                                            </td>
                                            <td>
                                                <div class="student-info-small">
                                                    <div class="name"><?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?></div>
                                                    <?php if (!empty($clearance['lacking_comment']) || !empty($clearance['student_proof_file'])): ?>
                                                        <div class="pending-flags">
                                                            <?php if (!empty($clearance['lacking_comment'])): ?>
                                                                <span class="mini-badge lacking"><i class="fas fa-comment-dots"></i> Lacking</span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($clearance['student_proof_file'])): ?>
                                                                <span class="mini-badge proof"><i class="fas fa-paperclip"></i> Proof Uploaded</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($clearance['ismis_id']); ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($clearance['course_name'] ?? 'N/A'); ?>
                                            </td>
                                            <td>
                                                <?php echo ($clearance['semester'] ?? '') . ' ' . ($clearance['school_year'] ?? ''); ?>
                                            </td>
                                            <td>
                                                <span class="progress-badge"
                                                    title="Approved offices: <?php echo htmlspecialchars($clearance['approved_offices'] ?? ''); ?>">
                                                    <?php echo ($clearance['approved_count'] ?? 0) . '/' . ($clearance['total_count'] ?? 6); ?>
                                                    offices
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo isset($clearance['created_at']) ? date('M d, Y', strtotime($clearance['created_at'])) : 'N/A'; ?>
                                            </td>
                                            <td>
                                                <div class="action-btns">
                                                    <button class="action-btn lacking"
                                                        onclick='openLackingModal(
                                                            <?php echo (int) $clearance["clearance_id"]; ?>,
                                                            <?php echo htmlspecialchars(json_encode($clearance["fname"] . " " . $clearance["lname"]), ENT_QUOTES, "UTF-8"); ?>,
                                                            <?php echo htmlspecialchars(json_encode($clearance["lacking_comment"] ?? ""), ENT_QUOTES, "UTF-8"); ?>
                                                        )'
                                                        title="Add Lacking Comment">
                                                        <i class="fas fa-comment-medical"></i>
                                                    </button>
                                                    <?php if (!empty($clearance['student_proof_file'])): ?>
                                                        <button class="action-btn view-proof"
                                                            onclick='viewStudentProof(
                                                                <?php echo (int) $clearance["clearance_id"]; ?>,
                                                                <?php echo htmlspecialchars(json_encode($clearance["student_proof_file"]), ENT_QUOTES, "UTF-8"); ?>,
                                                                <?php echo htmlspecialchars(json_encode($clearance["student_proof_remarks"] ?? ""), ENT_QUOTES, "UTF-8"); ?>
                                                            )'
                                                            title="View Student Proof">
                                                            <i class="fas fa-paperclip"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button class="action-btn approve"
                                                        onclick="openProcessModal(<?php echo $clearance['org_clearance_id']; ?>, 'approve')">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button class="action-btn reject"
                                                        onclick="openProcessModal(<?php echo $clearance['org_clearance_id']; ?>, 'reject')">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                    <button class="action-btn view"
                                                        onclick="viewStudentProgress(<?php echo $clearance['users_id']; ?>, '<?php echo $clearance['semester']; ?>', '<?php echo $clearance['school_year']; ?>', '<?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?>', '<?php echo $clearance['ismis_id']; ?>', '<?php echo htmlspecialchars($clearance['course_name'] ?? 'N/A'); ?>', '<?php echo htmlspecialchars($clearance['college_name'] ?? 'N/A'); ?>', '<?php echo $clearance['address'] ?? ''; ?>', '<?php echo $clearance['contacts'] ?? ''; ?>', '<?php echo $clearance['age'] ?? ''; ?>')">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- History Tab -->
            <div id="history" class="tab-content <?php echo $active_tab == 'history' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-history"></i> Medical Clearance History</h2>
                    </div>

                    <!-- Filter Bar -->
                    <div class="filter-bar">
                        <select class="filter-select" id="historySemesterFilter">
                            <option value="">All Semesters</option>
                            <?php foreach ($stats['semesters'] ?? [] as $sem): ?>
                                <option value="<?php echo $sem['semester']; ?>">
                                    <?php echo $sem['semester']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="historyYearFilter">
                            <option value="">All School Years</option>
                            <?php foreach ($stats['school_years'] ?? [] as $year): ?>
                                <option value="<?php echo $year['school_year']; ?>">
                                    <?php echo $year['school_year']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="historyStatusFilter">
                            <option value="">All Status</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                        <input type="text" class="filter-input" id="historySearch"
                            placeholder="Search by student name or ID...">
                        <button class="filter-btn" onclick="filterHistory()"><i class="fas fa-search"></i>
                            Search</button>
                        <button class="clear-filter" onclick="clearHistoryFilters()"><i class="fas fa-times"></i>
                            Clear</button>
                    </div>

                    <?php if (empty($clearance_history)): ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h3>No medical clearance history found</h3>
                            <p>Processed medical clearances will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table id="historyTable">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>ID</th>
                                        <th>Course</th>
                                        <th>Semester</th>
                                        <th>Status</th>
                                        <th>Processed By</th>
                                        <th>Processed Date</th>
                                        <th>Remarks</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($clearance_history as $clearance): ?>
                                        <tr data-semester="<?php echo $clearance['semester']; ?>"
                                            data-year="<?php echo $clearance['school_year']; ?>"
                                            data-status="<?php echo $clearance['status']; ?>"
                                            data-name="<?php echo strtolower($clearance['fname'] . ' ' . $clearance['lname']); ?>"
                                            data-id="<?php echo strtolower($clearance['ismis_id']); ?>">
                                            <td><strong>
                                                    <?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?>
                                                </strong></td>
                                            <td>
                                                <?php echo htmlspecialchars($clearance['ismis_id']); ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($clearance['course_name'] ?? 'N/A'); ?>
                                            </td>
                                            <td>
                                                <?php echo ($clearance['semester'] ?? '') . ' ' . ($clearance['school_year'] ?? ''); ?>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo getStatusClass($clearance['status']); ?>">
                                                    <?php echo ucfirst($clearance['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars(($clearance['processed_fname'] ?? 'Clinic') . ' ' . ($clearance['processed_lname'] ?? '')); ?>
                                            </td>
                                            <td>
                                                <?php echo isset($clearance['processed_date']) ? date('M d, Y', strtotime($clearance['processed_date'])) : 'N/A'; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($clearance['remarks_display'] !== '' ? $clearance['remarks_display'] : '-'); ?>
                                            </td>
                                            <td>
                                                <button class="action-btn view"
                                                    onclick="viewStudentProgress(<?php echo $clearance['users_id']; ?>, '<?php echo $clearance['semester']; ?>', '<?php echo $clearance['school_year']; ?>', '<?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?>', '<?php echo $clearance['ismis_id']; ?>', '<?php echo htmlspecialchars($clearance['course_name'] ?? 'N/A'); ?>', '<?php echo htmlspecialchars($clearance['college_name'] ?? 'N/A'); ?>', '<?php echo $clearance['address'] ?? ''; ?>', '<?php echo $clearance['contacts'] ?? ''; ?>', '<?php echo $clearance['age'] ?? ''; ?>')">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Medical Records Tab -->
            <div id="records" class="tab-content <?php echo $active_tab == 'records' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-notes-medical"></i> Medical Records</h2>
                    </div>

                    <?php if (empty($clinic_records)): ?>
                        <div class="empty-state">
                            <i class="fas fa-notes-medical"></i>
                            <h3>No medical records found</h3>
                            <p>Medical records will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>ID</th>
                                        <th>Course</th>
                                        <th>Medical Clearance</th>
                                        <th>Clearance Date</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($clinic_records as $record): ?>
                                        <tr>
                                            <td><strong>
                                                    <?php echo htmlspecialchars($record['fname'] . ' ' . $record['lname']); ?>
                                                </strong></td>
                                            <td>
                                                <?php echo htmlspecialchars($record['ismis_id']); ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($record['course_name'] ?? 'N/A'); ?>
                                            </td>
                                            <td>
                                                <span
                                                    class="status-badge <?php echo $record['medical_clearance'] ? 'status-approved' : 'status-pending'; ?>">
                                                    <?php echo $record['medical_clearance'] ? 'Cleared' : 'Pending'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo isset($record['clearance_date']) ? date('M d, Y', strtotime($record['clearance_date'])) : 'N/A'; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($record['remarks'] ?? '—'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Students Tab -->
            <div id="students" class="tab-content <?php echo $active_tab == 'students' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-users"></i> Student Records</h2>
                    </div>

                    <!-- Search Bar -->
                    <div class="filter-bar">
                        <input type="text" class="filter-input" id="studentSearch"
                            placeholder="Search by name or ISMIS ID..." style="flex: 1;">
                        <button class="filter-btn" onclick="searchStudents()"><i class="fas fa-search"></i>
                            Search</button>
                        <button class="clear-filter" onclick="clearStudentSearch()"><i class="fas fa-times"></i>
                            Clear</button>
                    </div>

                    <!-- Students Grid -->
                    <div class="students-grid" id="studentsGrid">
                        <?php foreach ($students as $student): ?>
                            <div class="student-card"
                                data-name="<?php echo strtolower($student['fname'] . ' ' . $student['lname']); ?>"
                                data-id="<?php echo strtolower($student['ismis_id']); ?>">
                                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                                    <div class="student-avatar">
                                        <i class="fas fa-user-graduate"></i>
                                    </div>
                                    <div class="student-info">
                                        <h4>
                                            <?php echo htmlspecialchars($student['fname'] . ' ' . $student['lname']); ?>
                                        </h4>
                                        <p>
                                            <?php echo htmlspecialchars($student['ismis_id']); ?>
                                        </p>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 10px;">
                                    <button class="btn btn-primary" style="flex: 1; padding: 10px;"
                                        onclick="viewStudentRecords(<?php echo $student['users_id']; ?>)">
                                        <i class="fas fa-eye"></i> View Records
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($students)): ?>
                            <div class="empty-state" style="grid-column: 1/-1;">
                                <i class="fas fa-users"></i>
                                <h3>No students found</h3>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Process Clearance Modal -->
    <div id="processModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle"></i> <span id="modalTitle">Process Medical Clearance</span></h3>
                <button class="close" onclick="closeProcessModal()">&times;</button>
            </div>
            <form method="POST" action="" id="processForm">
                <div class="modal-body">
                    <input type="hidden" name="org_clearance_id" id="modalClearanceId">
                    <input type="hidden" name="status" id="modalStatus">

                    <div class="form-group">
                        <label><i class="fas fa-comment"></i> Medical Remarks</label>
                        <textarea name="remarks" id="modalRemarks" rows="4"
                            placeholder="Enter medical remarks (e.g., clearance status, requirements, etc.)"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeProcessModal()">Cancel</button>
                    <button type="submit" name="process_clearance" class="btn btn-primary"
                        id="modalSubmitBtn">Process</button>
                </div>
            </form>
        </div>
    </div>

    <div id="lackingModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-comment-medical"></i> Add Lacking Comment</h3>
                <button class="close" onclick="closeLackingModal()">&times;</button>
            </div>
            <form method="POST" action="" id="lackingForm">
                <div class="modal-body">
                    <input type="hidden" name="clearance_id" id="lackingClearanceId">
                    <div id="lackingStudentInfo" style="background: var(--bg-secondary); padding: 15px; border-radius: 12px; margin-bottom: 18px;">
                        <p style="color: var(--text-secondary);">Loading student information...</p>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-list-check"></i> Lacking Comment <span style="color: var(--danger);">*</span></label>
                        <textarea name="lacking_comment" id="lackingComment" rows="4" placeholder="Describe what is lacking and what proof the student needs to submit..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeLackingModal()">Cancel</button>
                    <button type="submit" name="add_lacking_comment" class="btn btn-primary">Save Comment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Student Progress Modal -->
    <div id="progressModal" class="modal">
        <div class="modal-content large">
            <div class="modal-header">
                <h3><i class="fas fa-tasks"></i> Student Clearance Progress</h3>
                <button class="close" onclick="closeProgressModal()">&times;</button>
            </div>
            <div class="modal-body" id="progressModalBody">
                <div class="empty-state">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading student progress...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeProgressModal()">Close</button>
            </div>
        </div>
    </div>

    <div id="studentProofModal" class="modal">
        <div class="modal-content large">
            <div class="modal-header">
                <h3><i class="fas fa-paperclip"></i> Student Proof</h3>
                <button class="close" onclick="closeStudentProofModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="studentProofPreview" class="proof-preview"></div>
                <div class="section-card" style="margin: 18px 0 0;">
                    <div class="section-header">
                        <h2><i class="fas fa-circle-info"></i> Proof Details</h2>
                    </div>
                    <div id="studentProofInfo" class="detail-list">
                        <p style="color: var(--text-secondary);">Loading proof information...</p>
                    </div>
                    <div class="detail-item" style="padding-top: 16px; border-bottom: none;">
                        <span>Student remarks</span>
                        <span id="studentProofRemarks">No remarks provided</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeStudentProofModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = document.getElementById('themeIcon');
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('orgSidebar');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');
        const body = document.body;
        const clinicStudents = <?php
            echo json_encode(array_map(static function ($student) {
                return [
                    'users_id' => (int) ($student['users_id'] ?? 0),
                    'fname' => $student['fname'] ?? '',
                    'lname' => $student['lname'] ?? '',
                    'ismis_id' => $student['ismis_id'] ?? ''
                ];
            }, $students ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ?>;

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

        function closeMobileSidebar() {
            if (!sidebar) return;
            sidebar.classList.remove('show');
            if (sidebarBackdrop) {
                sidebarBackdrop.classList.remove('show');
            }
            if (menuToggle) {
                menuToggle.setAttribute('aria-expanded', 'false');
            }
        }

        // Tab switching
        function switchTab(tabName, triggerElement = null) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            const tabPanel = document.getElementById(tabName);
            if (!tabPanel) return;
            tabPanel.classList.add('active');

            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });

            const activeNav = triggerElement || document.querySelector(`.nav-item[onclick*="switchTab('${tabName}')"]`);
            if (activeNav) {
                activeNav.classList.add('active');
            }

            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);

            if (window.innerWidth <= 768) {
                closeMobileSidebar();
            }
        }

        // Process Modal
        function openProcessModal(clearanceId, action) {
            document.getElementById('processModal').style.display = 'flex';
            document.getElementById('modalClearanceId').value = clearanceId;
            const normalizedStatus = action === 'approve' ? 'approved' : 'rejected';
            document.getElementById('modalStatus').value = normalizedStatus;

            const modalTitle = document.getElementById('modalTitle');
            const modalSubmitBtn = document.getElementById('modalSubmitBtn');

            if (normalizedStatus === 'approved') {
                modalTitle.innerHTML = '<i class="fas fa-check-circle"></i> Approve Medical Clearance';
                modalSubmitBtn.className = 'btn btn-success';
                modalSubmitBtn.innerHTML = '<i class="fas fa-check"></i> Approve';
            } else {
                modalTitle.innerHTML = '<i class="fas fa-times-circle"></i> Reject Medical Clearance';
                modalSubmitBtn.className = 'btn btn-danger';
                modalSubmitBtn.innerHTML = '<i class="fas fa-times"></i> Reject';
            }
        }

        function closeProcessModal() {
            document.getElementById('processModal').style.display = 'none';
            document.getElementById('modalRemarks').value = '';
        }

        function openLackingModal(clearanceId, studentName, currentComment = '') {
            document.getElementById('lackingModal').style.display = 'flex';
            document.getElementById('lackingClearanceId').value = clearanceId;
            document.getElementById('lackingComment').value = currentComment || '';
            document.getElementById('lackingStudentInfo').innerHTML = `
                <p><strong>Student:</strong> ${escapeHtml(studentName || 'Unknown')}</p>
                <p><small>Leave a clear note about what is lacking and what proof the student must submit.</small></p>
            `;
        }

        function closeLackingModal() {
            document.getElementById('lackingModal').style.display = 'none';
            document.getElementById('lackingComment').value = '';
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function viewStudentProof(clearanceId, proofFile, remarks) {
            const safeProofFile = String(proofFile || '');
            const fileExt = safeProofFile.split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(fileExt);

            document.getElementById('studentProofModal').style.display = 'flex';
            document.getElementById('studentProofRemarks').textContent = remarks || 'No remarks provided';

            if (isImage) {
                document.getElementById('studentProofPreview').innerHTML = `<img src="../${encodeURI(safeProofFile)}" alt="Student proof" onclick="window.open('../${encodeURI(safeProofFile)}', '_blank')">`;
            } else {
                document.getElementById('studentProofPreview').innerHTML = `<a href="../${encodeURI(safeProofFile)}" target="_blank" class="btn btn-primary"><i class="fas fa-download"></i> Open Proof File</a>`;
            }

            document.getElementById('studentProofInfo').innerHTML = '<p style="color: var(--text-secondary);">Loading proof information...</p>';

            fetch(`../get_clearance_info.php?clearance_id=${encodeURIComponent(clearanceId)}`)
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.message || 'Proof details unavailable.');
                    }

                    document.getElementById('studentProofInfo').innerHTML = `
                        <div class="detail-item"><span>Student</span><span>${escapeHtml(data.student_name || 'Unknown')}</span></div>
                        <div class="detail-item"><span>ID</span><span>${escapeHtml(data.ismis_id || 'N/A')}</span></div>
                        <div class="detail-item"><span>Uploaded</span><span>${data.uploaded_at ? new Date(data.uploaded_at).toLocaleString() : 'Unknown'}</span></div>
                    `;
                })
                .catch(error => {
                    document.getElementById('studentProofInfo').innerHTML = `<p style="color: var(--danger);">${escapeHtml(error.message || 'Proof details unavailable.')}</p>`;
                });
        }

        function closeStudentProofModal() {
            document.getElementById('studentProofModal').style.display = 'none';
            document.getElementById('studentProofPreview').innerHTML = '';
            document.getElementById('studentProofRemarks').textContent = 'No remarks provided';
            document.getElementById('studentProofInfo').innerHTML = '<p style="color: var(--text-secondary);">Loading proof information...</p>';
        }

        function closeProgressModal() {
            document.getElementById('progressModal').style.display = 'none';
        }

        // View Student Progress
        async function viewStudentProgress(userId, semester, schoolYear, studentName, studentId, course, college, address, contact, age) {
            const modal = document.getElementById('progressModal');
            const modalBody = document.getElementById('progressModalBody');

            modal.style.display = 'flex';

            modalBody.innerHTML = `
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <div class="student-info-card">
                        <div class="student-info-header">
                            <h4><i class="fas fa-user-graduate"></i> Student Information</h4>
                            <span class="badge badge-primary">ID: ${studentId || 'N/A'}</span>
                        </div>
                        <div class="student-info-grid">
                            <div class="student-info-item">
                                <span class="label">Full Name</span>
                                <span class="value">${studentName || 'N/A'}</span>
                            </div>
                            <div class="student-info-item">
                                <span class="label">College</span>
                                <span class="value">${college || 'N/A'}</span>
                            </div>
                            <div class="student-info-item">
                                <span class="label">Course</span>
                                <span class="value">${course || 'N/A'}</span>
                            </div>
                            <div class="student-info-item">
                                <span class="label">Age</span>
                                <span class="value">${age || 'N/A'}</span>
                            </div>
                            <div class="student-info-item">
                                <span class="label">Contact</span>
                                <span class="value">${contact || 'N/A'}</span>
                            </div>
                            <div class="student-info-item">
                                <span class="label">Address</span>
                                <span class="value">${address || 'N/A'}</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            try {
                const response = await fetch(`../get_student_progress.php?user_id=${encodeURIComponent(userId)}&semester=${encodeURIComponent(semester || '')}&school_year=${encodeURIComponent(schoolYear || '')}`, {
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                if (!response.ok) {
                    throw new Error(`Request failed with status ${response.status}`);
                }

                const data = await response.json();
                if (!data.success) {
                    throw new Error(data.message || 'Progress data unavailable.');
                }

                const officeMarkup = (data.offices || []).length
                    ? data.offices.map(office => `
                        <div class="office-progress-item">
                            <div class="office-progress-top">
                                <strong>${escapeHtml(office.office_name || 'Unknown office')}</strong>
                                <span class="status-badge ${office.status === 'approved' ? 'status-approved' : office.status === 'rejected' ? 'status-rejected' : 'status-pending'}">${escapeHtml(office.status || 'pending')}</span>
                            </div>
                            <div class="office-progress-note">
                                ${office.processed_date ? `Updated ${new Date(office.processed_date).toLocaleString()}` : 'No processing date yet'}
                            </div>
                            ${office.lacking_comment ? `<div class="office-progress-note"><strong>Lacking:</strong> ${escapeHtml(office.lacking_comment)}</div>` : ''}
                            ${office.remarks ? `<div class="office-progress-note"><strong>Remarks:</strong> ${escapeHtml(office.remarks)}</div>` : ''}
                        </div>
                    `).join('')
                    : '<div class="empty-inline">No clearance progress records found for this student yet.</div>';

                modalBody.innerHTML = `
                    <div style="display: flex; flex-direction: column; gap: 20px;">
                        <div class="student-info-card">
                            <div class="student-info-header">
                                <h4><i class="fas fa-user-graduate"></i> Student Information</h4>
                                <span class="badge badge-primary">ID: ${escapeHtml(studentId || 'N/A')}</span>
                            </div>
                            <div class="student-info-grid">
                                <div class="student-info-item"><span class="label">Full Name</span><span class="value">${escapeHtml(studentName || 'N/A')}</span></div>
                                <div class="student-info-item"><span class="label">College</span><span class="value">${escapeHtml(college || 'N/A')}</span></div>
                                <div class="student-info-item"><span class="label">Course</span><span class="value">${escapeHtml(course || 'N/A')}</span></div>
                                <div class="student-info-item"><span class="label">Age</span><span class="value">${escapeHtml(age || 'N/A')}</span></div>
                                <div class="student-info-item"><span class="label">Contact</span><span class="value">${escapeHtml(contact || 'N/A')}</span></div>
                                <div class="student-info-item"><span class="label">Address</span><span class="value">${escapeHtml(address || 'N/A')}</span></div>
                            </div>
                        </div>
                        <div class="section-card" style="margin-bottom: 0;">
                            <div class="section-header">
                                <h2><i class="fas fa-list-check"></i> Clearance Progress</h2>
                                <span class="badge badge-primary">${escapeHtml(`${semester || 'All'} ${schoolYear || ''}`.trim() || 'All Terms')}</span>
                            </div>
                            <div class="office-progress-list">${officeMarkup}</div>
                        </div>
                    </div>
                `;
            } catch (error) {
                modalBody.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-exclamation-circle"></i>
                        <h3>Unable to load progress</h3>
                        <p>${escapeHtml(error.message || 'Please try again.')}</p>
                    </div>
                `;
            }
        }

        function viewStudentRecords(userId) {
            const modal = document.getElementById('progressModal');
            const modalBody = document.getElementById('progressModalBody');

            modal.style.display = 'flex';
            modalBody.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-spinner fa-spin"></i>
                    <h3>Loading student records</h3>
                    <p>Please wait while the clinic dashboard gathers the latest record details.</p>
                </div>
            `;

            fetch(`../get_student_records.php?user_id=${encodeURIComponent(userId)}`, {
                headers: {
                    'Accept': 'application/json'
                }
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`Request failed with status ${response.status}`);
                    }

                    return response.json();
                })
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.message || 'Student records unavailable.');
                    }

                    const student = data.student || {};
                    const summary = data.summary || {};
                    const records = Array.isArray(data.records) ? data.records : [];

                    const formatLabel = value => String(value || '')
                        .replace(/_/g, ' ')
                        .replace(/\b\w/g, char => char.toUpperCase());

                    const recordsMarkup = records.length
                        ? `
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Office</th>
                                            <th>Type</th>
                                            <th>Period</th>
                                            <th>Status</th>
                                            <th>Flags</th>
                                            <th>Updated</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${records.map(record => {
                                            const period = [record.semester, record.school_year].filter(Boolean).join(' ') || 'N/A';
                                            const flags = [];

                                            if (record.lacking_comment) {
                                                flags.push('<span class="badge badge-warning">Lacking</span>');
                                            }
                                            if (record.student_proof_file) {
                                                flags.push('<span class="badge badge-primary">Student Proof</span>');
                                            }
                                            if (record.proof_file) {
                                                flags.push('<span class="badge badge-success">Office Proof</span>');
                                            }

                                            return `
                                                <tr>
                                                    <td>${escapeHtml(record.office_name || 'Unknown')}</td>
                                                    <td>${escapeHtml(formatLabel(record.clearance_type || 'clearance'))}</td>
                                                    <td>${escapeHtml(period)}</td>
                                                    <td><span class="status-badge ${getStatusClass(record.status || 'pending')}">${escapeHtml(formatLabel(record.status || 'pending'))}</span></td>
                                                    <td>${flags.length ? flags.join(' ') : '<span style="color: var(--text-secondary);">None</span>'}</td>
                                                    <td>${escapeHtml(record.updated_label || 'N/A')}</td>
                                                </tr>
                                            `;
                                        }).join('')}
                                    </tbody>
                                </table>
                            </div>
                        `
                        : '<div class="empty-inline">No clearance records found for this student yet.</div>';

                    modalBody.innerHTML = `
                        <div style="display: flex; flex-direction: column; gap: 20px;">
                            <div class="student-info-card">
                                <div class="student-info-header">
                                    <h4><i class="fas fa-address-card"></i> Student Record Overview</h4>
                                    <span class="badge badge-primary">ID: ${escapeHtml(student.ismis_id || 'N/A')}</span>
                                </div>
                                <div class="student-info-grid">
                                    <div class="student-info-item"><span class="label">Full Name</span><span class="value">${escapeHtml(`${student.fname || ''} ${student.lname || ''}`.trim() || 'N/A')}</span></div>
                                    <div class="student-info-item"><span class="label">College</span><span class="value">${escapeHtml(student.college_name || 'N/A')}</span></div>
                                    <div class="student-info-item"><span class="label">Course</span><span class="value">${escapeHtml(student.course_name || 'N/A')}</span></div>
                                    <div class="student-info-item"><span class="label">Email</span><span class="value">${escapeHtml(student.emails || student.email || 'N/A')}</span></div>
                                    <div class="student-info-item"><span class="label">Contact</span><span class="value">${escapeHtml(student.contacts || 'N/A')}</span></div>
                                    <div class="student-info-item"><span class="label">Address</span><span class="value">${escapeHtml(student.address || 'N/A')}</span></div>
                                </div>
                            </div>

                            <div class="summary-grid" style="margin: 0;">
                                <div class="summary-card">
                                    <div class="label">Total Records</div>
                                    <div class="value">${Number(summary.total_records || 0)}</div>
                                    <div class="meta">All clearance rows linked to this student</div>
                                </div>
                                <div class="summary-card">
                                    <div class="label">Pending</div>
                                    <div class="value">${Number(summary.pending_count || 0)}</div>
                                    <div class="meta">Still waiting for office or organization action</div>
                                </div>
                                <div class="summary-card">
                                    <div class="label">Approved</div>
                                    <div class="value">${Number(summary.approved_count || 0)}</div>
                                    <div class="meta">Records already cleared</div>
                                </div>
                                <div class="summary-card">
                                    <div class="label">With Proof</div>
                                    <div class="value">${Number(summary.student_proof_count || 0)}</div>
                                    <div class="meta">Student-uploaded proof currently on file</div>
                                </div>
                            </div>

                            <div class="section-card" style="margin-bottom: 0;">
                                <div class="section-header">
                                    <h2><i class="fas fa-folder-open"></i> Recent Clearance Records</h2>
                                    <span class="badge badge-primary">${records.length} shown</span>
                                </div>
                                ${recordsMarkup}
                            </div>
                        </div>
                    `;
                })
                .catch(error => {
                    modalBody.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-circle"></i>
                            <h3>Unable to load records</h3>
                            <p>${escapeHtml(error.message || 'Please try again.')}</p>
                        </div>
                    `;
                });
        }

        // Bulk Actions
        const selectAllCheckbox = document.getElementById('selectAll');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function (e) {
                document.querySelectorAll('.clearance-checkbox').forEach(cb => {
                    cb.checked = e.target.checked;
                });
            });
        }

        function bulkApprove() {
            const selected = [];
            document.querySelectorAll('.clearance-checkbox:checked').forEach(cb => {
                selected.push(cb.value);
            });

            if (selected.length === 0) {
                alert('Please select at least one medical clearance to approve.');
                return;
            }

            const remarks = document.getElementById('bulkRemarks').value;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';

            selected.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'org_clearance_ids[]';
                input.value = id;
                form.appendChild(input);
            });

            const remarksInput = document.createElement('input');
            remarksInput.type = 'hidden';
            remarksInput.name = 'bulk_remarks';
            remarksInput.value = remarks;
            form.appendChild(remarksInput);

            const submitInput = document.createElement('input');
            submitInput.type = 'hidden';
            submitInput.name = 'bulk_approve';
            submitInput.value = '1';
            form.appendChild(submitInput);

            document.body.appendChild(form);
            form.submit();
        }

        // Filter functions
        function filterPending() {
            const semester = document.getElementById('pendingSemesterFilter').value;
            const search = document.getElementById('pendingSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#pendingTable tbody tr');

            rows.forEach(row => {
                if (row.cells.length < 8) return;

                const rowSemester = row.getAttribute('data-semester') || '';
                const nameCell = row.getAttribute('data-name') || '';
                const idCell = row.getAttribute('data-id') || '';

                const matchesSemester = !semester || rowSemester === semester;
                const matchesSearch = !search ||
                    nameCell.includes(search) ||
                    idCell.includes(search);

                row.style.display = matchesSemester && matchesSearch ? '' : 'none';
            });
        }

        function clearPendingFilters() {
            document.getElementById('pendingSemesterFilter').value = '';
            document.getElementById('pendingSearch').value = '';

            const rows = document.querySelectorAll('#pendingTable tbody tr');
            rows.forEach(row => {
                row.style.display = '';
            });
        }

        function filterHistory() {
            const semester = document.getElementById('historySemesterFilter').value;
            const year = document.getElementById('historyYearFilter').value;
            const status = document.getElementById('historyStatusFilter').value.toLowerCase();
            const search = document.getElementById('historySearch').value.toLowerCase();

            const rows = document.querySelectorAll('#historyTable tbody tr');

            rows.forEach(row => {
                const rowSemester = row.getAttribute('data-semester') || '';
                const rowYear = row.getAttribute('data-year') || '';
                const rowStatus = row.getAttribute('data-status')?.toLowerCase() || '';
                const rowName = row.getAttribute('data-name') || '';
                const rowId = row.getAttribute('data-id') || '';

                const matchesSemester = !semester || rowSemester === semester;
                const matchesYear = !year || rowYear === year;
                const matchesStatus = !status || rowStatus.includes(status);
                const matchesSearch = !search ||
                    rowName.includes(search) ||
                    rowId.includes(search);

                row.style.display = matchesSemester && matchesYear && matchesStatus && matchesSearch ? '' : 'none';
            });
        }

        function clearHistoryFilters() {
            document.getElementById('historySemesterFilter').value = '';
            document.getElementById('historyYearFilter').value = '';
            document.getElementById('historyStatusFilter').value = '';
            document.getElementById('historySearch').value = '';

            const rows = document.querySelectorAll('#historyTable tbody tr');
            rows.forEach(row => {
                row.style.display = '';
            });
        }

        function searchStudents() {
            const search = document.getElementById('studentSearch').value.toLowerCase();
            const cards = document.querySelectorAll('.student-card');

            cards.forEach(card => {
                const name = card.getAttribute('data-name') || '';
                const id = card.getAttribute('data-id') || '';

                if (name.includes(search) || id.includes(search)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function clearStudentSearch() {
            document.getElementById('studentSearch').value = '';

            const cards = document.querySelectorAll('.student-card');
            cards.forEach(card => {
                card.style.display = 'block';
            });
        }

        function searchStudent() {
            const search = document.getElementById('quickSearch').value.trim().toLowerCase();
            if (!search) {
                alert('Please enter a search term');
                return;
            }

            const resultsDiv = document.getElementById('quickSearchResults');
            const matches = clinicStudents.filter(student => {
                const fullName = `${student.fname || ''} ${student.lname || ''}`.toLowerCase();
                const id = String(student.ismis_id || '').toLowerCase();
                return fullName.includes(search) || id.includes(search);
            }).slice(0, 8);

            resultsDiv.style.display = 'block';
            if (!matches.length) {
                resultsDiv.innerHTML = `
                    <div class="empty-inline">No students matched "${escapeHtml(search)}".</div>
                `;
                return;
            }

            resultsDiv.innerHTML = `
                <div class="search-results-grid">
                    ${matches.map(student => `
                        <div class="search-result-card">
                            <div>
                                <strong>${escapeHtml(`${student.fname || ''} ${student.lname || ''}`.trim())}</strong>
                                <div class="search-result-meta">
                                    <span class="badge badge-primary">${escapeHtml(student.ismis_id || 'No ISMIS ID')}</span>
                                </div>
                            </div>
                            <button type="button" class="btn btn-primary" onclick="viewStudentRecords(${Number(student.users_id || 0)})">
                                <i class="fas fa-eye"></i> View Records
                            </button>
                        </div>
                    `).join('')}
                </div>
            `;
        }

        // Toast notification
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast-notification toast-${type}`;
            toast.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span style="margin-left: 10px;">${message}</span>
            `;

            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Close modals when clicking outside
        window.addEventListener('click', function (event) {
            const processModal = document.getElementById('processModal');
            const lackingModal = document.getElementById('lackingModal');
            const progressModal = document.getElementById('progressModal');
            const studentProofModal = document.getElementById('studentProofModal');

            if (event.target == processModal) {
                processModal.style.display = 'none';
            }
            if (event.target == lackingModal) {
                closeLackingModal();
            }
            if (event.target == progressModal) {
                progressModal.style.display = 'none';
            }
            if (event.target == studentProofModal) {
                closeStudentProofModal();
            }
        });

        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', function () {
                const willOpen = !sidebar.classList.contains('show');
                sidebar.classList.toggle('show', willOpen);
                if (sidebarBackdrop) {
                    sidebarBackdrop.classList.toggle('show', willOpen);
                }
                menuToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
        }

        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', closeMobileSidebar);
        }

        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                closeMobileSidebar();
            }
        });

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>

</html>
