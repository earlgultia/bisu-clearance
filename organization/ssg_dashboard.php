<?php
// ssg_dashboard.php - Supreme Student Government Dashboard for BISU Online Clearance System
// Location: C:\xampp\htdocs\clearance\organization\ssg_dashboard.php

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

// Get organization information from session
$org_id = $_SESSION['user_id'];
$org_name = $_SESSION['user_name'] ?? '';
$org_email = $_SESSION['user_email'] ?? '';
$org_type = $_SESSION['org_type'] ?? '';

// Verify that this is an SSG organization
if ($org_type !== 'ssg') {
    header("Location: ../index.php");
    exit();
}

// Get organization details from database
try {
    $db->query("SELECT so.*, o.office_name, o.office_id as off_id
                FROM student_organizations so
                LEFT JOIN offices o ON so.office_id = o.office_id
                WHERE so.org_id = :org_id");
    $db->bind(':org_id', $org_id);
    $org_details = $db->single();

    $office_id = $org_details['office_id'] ?? null;

} catch (Exception $e) {
    error_log("Error fetching organization details: " . $e->getMessage());
    $office_id = null;
}

// Initialize variables
$success = '';
$error = '';
$warning = '';
$active_tab = $_GET['tab'] ?? 'dashboard';
$profile_pic = null;
$filter_course = $_GET['course'] ?? '';
$filter_college = $_GET['college'] ?? '';
$filter_semester = $_GET['semester'] ?? '';
$filter_school_year = $_GET['school_year'] ?? '';
$filter_year_level = $_GET['year_level'] ?? '';

// ============================================
// HANDLE CLEARANCE APPROVAL/REJECTION
// ============================================
if (isset($_POST['process_clearance'])) {
    $org_clearance_id = $_POST['org_clearance_id'] ?? '';
    $status = $_POST['status'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    if ($org_clearance_id && $status) {
        try {
            $db->beginTransaction();

            // Get current clearance info
            $db->query("SELECT oc.*, c.users_id, u.fname, u.lname, u.ismis_id, u.course_id, u.college_id, u.address, u.year_level
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

            // Update the organization clearance
            $db->query("UPDATE organization_clearance SET 
                        status = :status, 
                        remarks = CONCAT(IFNULL(remarks, ''), ' | SSG: ', :remarks),
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
                $db->commit();

                if ($status == 'approved') {
                    $_SESSION['success_message'] = "Clearance for {$current['fname']} {$current['lname']} has been approved successfully!";
                } else {
                    $_SESSION['success_message'] = "Clearance for {$current['fname']} {$current['lname']} has been rejected.";
                }

                header("Location: ssg_dashboard.php?tab=pending");
                exit();
            } else {
                $db->rollback();
                $error = "Failed to process clearance.";
            }
        } catch (Exception $e) {
            $db->rollback();
            error_log("Error processing SSG clearance: " . $e->getMessage());
            $error = "Error: " . $e->getMessage();
        }
    }
}

// ============================================
// HANDLE BULK APPROVAL
// ============================================
if (isset($_POST['bulk_approve'])) {
    $org_clearance_ids = $_POST['org_clearance_ids'] ?? [];
    $remarks = trim($_POST['bulk_remarks'] ?? '');

    if (!empty($org_clearance_ids)) {
        try {
            $db->beginTransaction();

            $placeholders = implode(',', array_fill(0, count($org_clearance_ids), '?'));

            // Verify all selected clearances belong to this organization and are pending
            $db->query("SELECT COUNT(*) as count 
                       FROM organization_clearance 
                       WHERE org_clearance_id IN ($placeholders)
                       AND org_id = :org_id
                       AND status = 'pending'");

            foreach ($org_clearance_ids as $index => $id) {
                $db->bind($index + 1, $id);
            }
            $db->bind(':org_id', $org_id);
            $verify = $db->single();

            if ($verify['count'] != count($org_clearance_ids)) {
                $db->rollback();
                $error = "Some clearances are not valid for bulk approval.";
                throw new Exception("Invalid clearances");
            }

            // Update all eligible clearances
            $db->query("UPDATE organization_clearance SET 
                        status = 'approved', 
                        remarks = CONCAT(IFNULL(remarks, ''), ' | SSG (Bulk): ', :remarks),
                        processed_by = :processed_by, 
                        processed_date = NOW(),
                        updated_at = NOW()
                        WHERE org_clearance_id IN ($placeholders)");

            $db->bind(':remarks', $remarks);
            $db->bind(':processed_by', $org_id);

            foreach ($org_clearance_ids as $index => $id) {
                $db->bind($index + 1, $id);
            }

            if ($db->execute()) {
                $db->commit();
                $_SESSION['success_message'] = count($org_clearance_ids) . " SSG clearance(s) approved successfully!";

                header("Location: ssg_dashboard.php?tab=pending");
                exit();
            } else {
                $db->rollback();
                $error = "Failed to approve clearances.";
            }
        } catch (Exception $e) {
            if ($e->getMessage() !== "Invalid clearances") {
                $db->rollback();
                error_log("Error in bulk approval: " . $e->getMessage());
                $error = "Database error occurred.";
            }
        }
    }
}

// ============================================
// HANDLE UNDO APPROVAL
// ============================================
if (isset($_POST['undo_approval'])) {
    $org_clearance_id = $_POST['org_clearance_id'] ?? '';
    $reason = trim($_POST['undo_reason'] ?? '');

    if ($org_clearance_id) {
        try {
            $db->beginTransaction();

            // Get current clearance info before updating
            $db->query("SELECT oc.*, c.users_id, u.fname, u.lname, u.ismis_id 
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

            // Check if clearance was actually processed by this organization
            if ($current['processed_by'] != $org_id) {
                throw new Exception("You can only undo your own approvals");
            }

            // Check if clearance is not pending
            if ($current['status'] === 'pending') {
                throw new Exception("This clearance is still pending. No need to undo.");
            }

            // Store the previous status in remarks before reverting
            $undo_remarks = "UNDO by SSG: Previous status was '" . $current['status'] . "' on " . date('Y-m-d H:i:s') . ". Reason: " . $reason;

            // Update the clearance back to pending
            $db->query("UPDATE organization_clearance SET 
                        status = 'pending', 
                        remarks = CONCAT(IFNULL(remarks, ''), ' | ', :undo_remarks),
                        processed_by = NULL, 
                        processed_date = NULL,
                        updated_at = NOW()
                        WHERE org_clearance_id = :id AND org_id = :org_id");
            $db->bind(':undo_remarks', $undo_remarks);
            $db->bind(':id', $org_clearance_id);
            $db->bind(':org_id', $org_id);

            if ($db->execute()) {
                $db->commit();
                $_SESSION['success_message'] = "Approval successfully undone! Clearance has been returned to pending status.";

                header("Location: ssg_dashboard.php?tab=undo");
                exit();
            } else {
                $db->rollback();
                $error = "Failed to undo approval.";
            }
        } catch (Exception $e) {
            $db->rollback();
            error_log("Error undoing approval: " . $e->getMessage());
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Check for session success message
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// ============================================
// FETCH DASHBOARD DATA
// ============================================
$stats = [];
$pending_clearances = [];
$recent_clearances = [];
$clearance_history = [];
$students = [];
$college_stats = [];
$course_stats = [];
$year_level_stats = [];
$approvals_to_undo = [];

try {
    if (!$org_id) {
        throw new Exception("No organization ID found");
    }

    // Count statistics
    $db->query("SELECT COUNT(*) as count FROM organization_clearance WHERE org_id = :org_id AND status = 'pending'");
    $db->bind(':org_id', $org_id);
    $result = $db->single();
    $stats['pending'] = $result ? (int) $result['count'] : 0;

    $db->query("SELECT COUNT(*) as count FROM organization_clearance WHERE org_id = :org_id AND status = 'approved'");
    $db->bind(':org_id', $org_id);
    $result = $db->single();
    $stats['approved'] = $result ? (int) $result['count'] : 0;

    $db->query("SELECT COUNT(*) as count FROM organization_clearance WHERE org_id = :org_id AND status = 'rejected'");
    $db->bind(':org_id', $org_id);
    $result = $db->single();
    $stats['rejected'] = $result ? (int) $result['count'] : 0;

    $db->query("SELECT COUNT(DISTINCT c.users_id) as count 
                FROM organization_clearance oc
                JOIN clearance c ON oc.clearance_id = c.clearance_id
                WHERE oc.org_id = :org_id");
    $db->bind(':org_id', $org_id);
    $result = $db->single();
    $stats['students'] = $result ? (int) $result['count'] : 0;

    // Get pending clearances
    $query = "SELECT 
                oc.*,
                c.clearance_id as main_clearance_id,
                c.users_id,
                c.semester,
                c.school_year,
                c.clearance_type_id,
                u.fname, 
                u.lname, 
                u.ismis_id, 
                u.course_id, 
                u.college_id,
                u.address, 
                u.contacts, 
                u.age,
                u.year_level,
                u.profile_picture,
                cr.course_name, 
                col.college_name,
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
                 AND c4.status = 'approved') as approved_offices,
                DATEDIFF(NOW(), oc.created_at) as days_pending
              FROM organization_clearance oc
              JOIN clearance c ON oc.clearance_id = c.clearance_id
              JOIN users u ON c.users_id = u.users_id
              LEFT JOIN course cr ON u.course_id = cr.course_id
              LEFT JOIN college col ON u.college_id = col.college_id
              LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
              WHERE oc.org_id = :org_id 
              AND oc.status = 'pending'";

    $params = [':org_id' => $org_id];

    if (!empty($filter_college)) {
        $query .= " AND u.college_id = :college_id";
        $params[':college_id'] = $filter_college;
    }

    if (!empty($filter_course)) {
        $query .= " AND u.course_id = :course_id";
        $params[':course_id'] = $filter_course;
    }

    if (!empty($filter_year_level)) {
        $query .= " AND u.year_level = :year_level";
        $params[':year_level'] = $filter_year_level;
    }

    if (!empty($filter_semester)) {
        $query .= " AND c.semester = :semester";
        $params[':semester'] = $filter_semester;
    }

    if (!empty($filter_school_year)) {
        $query .= " AND c.school_year = :school_year";
        $params[':school_year'] = $filter_school_year;
    }

    $query .= " ORDER BY 
                CASE 
                    WHEN u.year_level = '4th Year' THEN 1
                    WHEN u.year_level = '3rd Year' THEN 2
                    WHEN u.year_level = '2nd Year' THEN 3
                    WHEN u.year_level = '1st Year' THEN 4
                    ELSE 5
                END,
                oc.created_at ASC";

    $db->query($query);
    foreach ($params as $key => $value) {
        $db->bind($key, $value);
    }
    $pending_clearances = $db->resultSet();

    // Get recent approved/rejected clearances
    $query = "SELECT 
                oc.*,
                c.clearance_id as main_clearance_id,
                c.users_id,
                c.semester,
                c.school_year,
                u.fname, 
                u.lname, 
                u.ismis_id, 
                u.year_level,
                u.profile_picture,
                cr.course_name, 
                col.college_name,
                ct.clearance_name as clearance_type,
                p.fname as processed_fname, 
                p.lname as processed_lname
              FROM organization_clearance oc
              JOIN clearance c ON oc.clearance_id = c.clearance_id
              JOIN users u ON c.users_id = u.users_id
              LEFT JOIN course cr ON u.course_id = cr.course_id
              LEFT JOIN college col ON u.college_id = col.college_id
              LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
              LEFT JOIN users p ON oc.processed_by = p.users_id
              WHERE oc.org_id = :org_id 
              AND oc.status IN ('approved', 'rejected')
              ORDER BY oc.processed_date DESC
              LIMIT 10";

    $db->query($query);
    $db->bind(':org_id', $org_id);
    $recent_clearances = $db->resultSet();

    // Get approvals for undo
    $db->query("SELECT oc.*, 
                       c.users_id,
                       u.fname, u.lname, u.ismis_id, cr.course_name, u.year_level,
                       ct.clearance_name as clearance_type,
                       DATE_FORMAT(oc.processed_date, '%M %d, %Y %h:%i %p') as formatted_date
                FROM organization_clearance oc
                JOIN clearance c ON oc.clearance_id = c.clearance_id
                JOIN users u ON c.users_id = u.users_id
                LEFT JOIN course cr ON u.course_id = cr.course_id
                LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                WHERE oc.org_id = :org_id 
                AND oc.processed_by = :org_id
                AND oc.status IN ('approved', 'rejected')
                ORDER BY oc.processed_date DESC");
    $db->bind(':org_id', $org_id);
    $approvals_to_undo = $db->resultSet();

    // Get clearance history
    $query = "SELECT 
                oc.*,
                c.clearance_id as main_clearance_id,
                c.users_id,
                c.semester,
                c.school_year,
                u.fname, 
                u.lname, 
                u.ismis_id, 
                u.year_level,
                u.profile_picture,
                cr.course_name, 
                col.college_name,
                ct.clearance_name as clearance_type,
                p.fname as processed_fname, 
                p.lname as processed_lname
              FROM organization_clearance oc
              JOIN clearance c ON oc.clearance_id = c.clearance_id
              JOIN users u ON c.users_id = u.users_id
              LEFT JOIN course cr ON u.course_id = cr.course_id
              LEFT JOIN college col ON u.college_id = col.college_id
              LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
              LEFT JOIN users p ON oc.processed_by = p.users_id
              WHERE oc.org_id = :org_id
              ORDER BY oc.created_at DESC 
              LIMIT 50";

    $db->query($query);
    $db->bind(':org_id', $org_id);
    $clearance_history = $db->resultSet();

    // Get college statistics
    $query = "SELECT 
                col.college_id,
                col.college_name,
                COUNT(DISTINCT u.users_id) as student_count,
                COUNT(oc.org_clearance_id) as total_clearances,
                SUM(CASE WHEN oc.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN oc.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN oc.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
              FROM college col
              LEFT JOIN users u ON u.college_id = col.college_id AND u.user_role_id = 4 AND u.is_active = 1
              LEFT JOIN clearance c ON u.users_id = c.users_id
              LEFT JOIN organization_clearance oc ON c.clearance_id = oc.clearance_id AND oc.org_id = :org_id
              GROUP BY col.college_id, col.college_name
              HAVING student_count > 0 OR total_clearances > 0
              ORDER BY col.college_name";

    $db->query($query);
    $db->bind(':org_id', $org_id);
    $college_stats = $db->resultSet();

    // Get course statistics
    $query = "SELECT 
                cr.course_id,
                cr.course_name,
                cr.course_code,
                col.college_name,
                COUNT(DISTINCT u.users_id) as student_count,
                COUNT(oc.org_clearance_id) as total_clearances,
                SUM(CASE WHEN oc.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN oc.status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN oc.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count
              FROM course cr
              LEFT JOIN college col ON cr.college_id = col.college_id
              LEFT JOIN users u ON u.course_id = cr.course_id AND u.user_role_id = 4 AND u.is_active = 1
              LEFT JOIN clearance c ON u.users_id = c.users_id
              LEFT JOIN organization_clearance oc ON c.clearance_id = oc.clearance_id AND oc.org_id = :org_id
              GROUP BY cr.course_id, cr.course_name, cr.course_code, col.college_name
              HAVING student_count > 0 OR total_clearances > 0
              ORDER BY col.college_name, cr.course_name";

    $db->query($query);
    $db->bind(':org_id', $org_id);
    $course_stats = $db->resultSet();

    // Get year level statistics
    $query = "SELECT 
                u.year_level,
                COUNT(DISTINCT u.users_id) as student_count,
                COUNT(oc.org_clearance_id) as total_clearances,
                SUM(CASE WHEN oc.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN oc.status = 'pending' THEN 1 ELSE 0 END) as pending_count
              FROM users u
              LEFT JOIN clearance c ON u.users_id = c.users_id
              LEFT JOIN organization_clearance oc ON c.clearance_id = oc.clearance_id AND oc.org_id = :org_id
              WHERE u.user_role_id = 4 
              AND u.is_active = 1
              AND u.year_level IS NOT NULL
              GROUP BY u.year_level
              ORDER BY FIELD(u.year_level, '1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year')";

    $db->query($query);
    $db->bind(':org_id', $org_id);
    $year_level_stats = $db->resultSet();

    // Get all students
    $query = "SELECT u.users_id, u.fname, u.lname, u.ismis_id, u.course_id, u.college_id, u.year_level, u.address, u.profile_picture,
                     cr.course_name, cr.course_code,
                     col.college_name
              FROM users u
              LEFT JOIN course cr ON u.course_id = cr.course_id
              LEFT JOIN college col ON u.college_id = col.college_id
              WHERE u.user_role_id = 4 
              AND u.is_active = 1
              ORDER BY u.year_level, u.lname, u.fname";

    $db->query($query);
    $students = $db->resultSet();

    // Get distinct semesters and school years
    $db->query("SELECT DISTINCT semester FROM clearance WHERE semester IS NOT NULL ORDER BY semester");
    $stats['semesters'] = $db->resultSet();

    $db->query("SELECT DISTINCT school_year FROM clearance WHERE school_year IS NOT NULL ORDER BY school_year DESC");
    $stats['school_years'] = $db->resultSet();

    // Get distinct colleges for filter
    $db->query("SELECT college_id, college_name FROM college ORDER BY college_name");
    $stats['colleges'] = $db->resultSet();

    // Get distinct courses for filter
    $db->query("SELECT course_id, course_name, course_code FROM course ORDER BY course_name");
    $stats['courses'] = $db->resultSet();

    // Get distinct year levels
    $db->query("SELECT DISTINCT year_level FROM users 
                WHERE year_level IS NOT NULL AND year_level != ''
                AND user_role_id = 4
                ORDER BY FIELD(year_level, '1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year')");
    $stats['year_levels'] = $db->resultSet();

} catch (Exception $e) {
    error_log("Error fetching SSG data: " . $e->getMessage());
    $error = "Error loading dashboard data: " . $e->getMessage();
}

// Helper functions
function getStatusClass($status)
{
    return $status == 'approved' ? 'status-approved' : ($status == 'rejected' ? 'status-rejected' : 'status-pending');
}

function timeAgo($datetime)
{
    if (!$datetime)
        return 'N/A';
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60) {
        return $diff . ' seconds ago';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 2592000) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', $time);
    }
}

function getYearLevelBadge($year_level)
{
    $colors = [
        '1st Year' => 'info',
        '2nd Year' => 'success',
        '3rd Year' => 'warning',
        '4th Year' => 'primary',
        '5th Year' => 'undo'
    ];
    return $colors[$year_level] ?? 'secondary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SSG Dashboard - BISU Online Clearance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        :root {
            --primary: #e63946;
            --primary-dark: #c82333;
            --primary-light: #ff6b7a;
            --primary-soft: rgba(230, 57, 70, 0.1);
            --primary-glow: rgba(230, 57, 70, 0.2);
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-tertiary: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border-color: #e2e8f0;
            --card-bg: #ffffff;
            --card-shadow: 0 4px 20px rgba(0, 0, 0, 0.02);
            --card-shadow-hover: 0 10px 30px rgba(230, 57, 70, 0.08);
            --header-bg: linear-gradient(135deg, #e63946 0%, #c82333 100%);
            --sidebar-bg: #ffffff;
            --input-bg: #ffffff;
            --input-border: #e2e8f0;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --undo: #8b5cf6;
            --success-soft: rgba(16, 185, 129, 0.1);
            --warning-soft: rgba(245, 158, 11, 0.1);
            --danger-soft: rgba(239, 68, 68, 0.1);
            --info-soft: rgba(59, 130, 246, 0.1);
            --undo-soft: rgba(139, 92, 246, 0.1);
        }

        .dark-mode {
            --primary: #ff6b7a;
            --primary-dark: #e63946;
            --primary-light: #ff8a97;
            --primary-soft: rgba(255, 107, 122, 0.15);
            --primary-glow: rgba(255, 107, 122, 0.25);
            --bg-primary: #1a1b2f;
            --bg-secondary: #22243e;
            --bg-tertiary: #2a2c4a;
            --text-primary: #f0f1fa;
            --text-secondary: #cbd5e0;
            --text-muted: #a0a8b8;
            --border-color: #2d2f4a;
            --card-bg: #22243e;
            --card-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            --card-shadow-hover: 0 10px 30px rgba(255, 107, 122, 0.15);
            --header-bg: linear-gradient(135deg, #e63946 0%, #c82333 100%);
            --sidebar-bg: #22243e;
            --input-bg: #2a2c4a;
            --input-border: #3d3f60;
            --success: #4ade80;
            --warning: #fbbf24;
            --danger: #f87171;
            --info: #60a5fa;
            --undo: #c4b5fd;
            --success-soft: rgba(74, 222, 128, 0.1);
            --warning-soft: rgba(251, 191, 36, 0.1);
            --danger-soft: rgba(248, 113, 113, 0.1);
            --info-soft: rgba(96, 165, 250, 0.1);
            --undo-soft: rgba(139, 92, 246, 0.15);
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
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
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

        .ssg-badge {
            background: var(--primary-soft);
            color: var(--primary);
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.9rem;
            display: inline-block;
            margin-top: 10px;
            font-weight: 600;
        }

        .ssg-badge i {
            margin-right: 5px;
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

        .welcome-banner {
            background: var(--header-bg);
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
            background: var(--primary-soft);
            color: var(--primary);
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

        .info-card {
            background: var(--info-soft);
            border-left: 4px solid var(--info);
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            color: var(--info);
        }

        .info-card i {
            font-size: 1.5rem;
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

        .college-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .college-stat-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 20px;
            border: 1px solid var(--border-color);
            transition: 0.3s;
        }

        .college-stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
        }

        .college-stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-soft);
        }

        .college-stat-header h3 {
            color: var(--primary);
            font-size: 1.1rem;
            font-weight: 600;
        }

        .college-stat-content {
            display: flex;
            justify-content: space-around;
            margin-bottom: 10px;
        }

        .college-stat-item {
            text-align: center;
        }

        .college-stat-item .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .college-stat-item .label {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .course-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .course-stat-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 20px;
            border: 1px solid var(--border-color);
            transition: 0.3s;
        }

        .course-stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
        }

        .course-stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-soft);
        }

        .course-stat-header h3 {
            color: var(--primary);
            font-size: 1.1rem;
            font-weight: 600;
        }

        .course-stat-header .code {
            background: var(--primary-soft);
            color: var(--primary);
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .course-stat-header .college {
            font-size: 0.7rem;
            color: var(--text-muted);
        }

        .course-stat-content {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .course-stat-item {
            text-align: center;
            flex: 1;
        }

        .course-stat-item .value {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .course-stat-item .label {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .year-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .year-stat-card {
            background: var(--bg-secondary);
            border-radius: 16px;
            padding: 15px;
            border: 1px solid var(--border-color);
            text-align: center;
        }

        .year-stat-card h3 {
            color: var(--primary);
            font-size: 1.1rem;
            margin-bottom: 10px;
        }

        .year-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .year-stat-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .mini-progress {
            width: 100%;
            height: 6px;
            background: var(--border-color);
            border-radius: 3px;
            margin-top: 10px;
            overflow: hidden;
        }

        .mini-progress-fill {
            height: 100%;
            background: var(--success);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .undo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 15px;
        }

        .undo-item {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 15px;
            border: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: 0.3s;
        }

        .undo-item:hover {
            border-color: var(--undo);
            box-shadow: 0 5px 15px var(--undo-soft);
        }

        .undo-info {
            flex: 1;
        }

        .undo-info h4 {
            color: var(--text-primary);
            font-size: 1rem;
            margin-bottom: 3px;
        }

        .undo-info p {
            color: var(--text-secondary);
            font-size: 0.85rem;
            margin-bottom: 3px;
        }

        .undo-info .status-badge {
            display: inline-block;
            padding: 2px 8px;
            font-size: 0.7rem;
            margin-right: 5px;
        }

        .undo-info small {
            color: var(--text-muted);
            font-size: 0.7rem;
            display: block;
            margin-top: 5px;
        }

        .undo-btn {
            background: var(--undo-soft);
            color: var(--undo);
            border: 1px solid var(--undo);
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }

        .undo-btn:hover {
            background: var(--undo);
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

        .student-cell {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .student-avatar-small {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: var(--primary-soft);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 1rem;
            overflow: hidden;
        }

        .student-avatar-small img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .student-info-small {
            line-height: 1.3;
        }

        .student-info-small .name {
            font-weight: 600;
            color: var(--text-primary);
        }

        .student-info-small .id {
            font-size: 0.8rem;
            color: var(--text-muted);
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

        .year-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .year-badge.info {
            background: var(--info-soft);
            color: var(--info);
        }

        .year-badge.success {
            background: var(--success-soft);
            color: var(--success);
        }

        .year-badge.warning {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .year-badge.primary {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .year-badge.undo {
            background: var(--undo-soft);
            color: var(--undo);
        }

        .progress-badge {
            background: var(--info-soft);
            color: var(--info);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .action-btns {
            display: flex;
            gap: 6px;
        }

        .action-btn {
            width: 35px;
            height: 35px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
        }

        .action-btn.approve {
            background: var(--success-soft);
            color: var(--success);
        }

        .action-btn.reject {
            background: var(--danger-soft);
            color: var(--danger);
        }

        .action-btn.view {
            background: var(--info-soft);
            color: var(--info);
        }

        .action-btn:hover {
            transform: translateY(-2px);
        }

        .select-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary);
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

        .btn-undo {
            background: var(--undo);
            color: white;
        }

        .btn-undo:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(139, 92, 246, 0.3);
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--border-color);
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
            overflow: hidden;
        }

        .student-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .badge {
            padding: 4px 12px;
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

        .badge-warning {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .badge-undo {
            background: var(--undo-soft);
            color: var(--undo);
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

        .alert-info {
            background: var(--info-soft);
            color: var(--info);
            border: 1px solid var(--info-soft);
        }

        .alert-warning {
            background: var(--warning-soft);
            color: var(--warning);
            border: 1px solid var(--warning-soft);
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

        .waiting-time {
            font-size: 0.8rem;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 3px;
        }

        .waiting-time i {
            font-size: 0.7rem;
        }

        .tooltip {
            position: relative;
            display: inline-block;
        }

        .tooltip .tooltiptext {
            visibility: hidden;
            width: 200px;
            background: var(--card-bg);
            color: var(--text-primary);
            text-align: center;
            border-radius: 6px;
            padding: 5px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -100px;
            opacity: 0;
            transition: opacity 0.3s;
            border: 1px solid var(--border-color);
            box-shadow: var(--card-shadow);
            font-size: 0.8rem;
        }

        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
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

        .student-card .student-info h4 {
            color: var(--text-primary);
            font-size: 1.1rem;
            font-weight: 600;
        }

        .student-card .student-info p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                position: absolute;
                z-index: 1001;
                transition: 0.3s;
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .content-area {
                margin-left: 0;
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

            .students-grid {
                grid-template-columns: 1fr;
            }

            .undo-grid {
                grid-template-columns: 1fr;
            }

            .college-stats-grid {
                grid-template-columns: 1fr;
            }

            .course-stats-grid {
                grid-template-columns: 1fr;
            }

            .year-stats-grid {
                grid-template-columns: 1fr;
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
                <div class="logo-icon">
                    <i class="fas fa-users"></i>
                </div>
                <h2>Supreme Student Government Dashboard</h2>
            </div>
            <div class="user-menu">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($profile_pic) && file_exists('../' . $profile_pic)): ?>
                                <img src="../<?php echo $profile_pic . '?t=' . time(); ?>" alt="Profile">
                        <?php else: ?>
                                <i class="fas fa-users"></i>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($org_name); ?></div>
                        <div class="user-role">Supreme Student Government</div>
                    </div>
                </div>
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>

    <div class="main-container">
        <aside class="sidebar">
            <div class="profile-section">
                <div class="profile-avatar">
                    <i class="fas fa-users" style="font-size: 3rem; line-height: 100px;"></i>
                </div>

                <div class="profile-name"><?php echo htmlspecialchars($org_name); ?></div>
                <div class="profile-email"><?php echo htmlspecialchars($org_email); ?></div>
                <div class="profile-badge">SSG</div>
                <div class="ssg-badge">
                    <i class="fas fa-gavel"></i> Student Government
                </div>
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
                            <span class="badge badge-warning" style="margin-left: auto;"><?php echo $stats['pending']; ?></span>
                    <?php endif; ?>
                </button>
                <button class="nav-item <?php echo $active_tab == 'colleges' ? 'active' : ''; ?>"
                    onclick="switchTab('colleges')">
                    <i class="fas fa-building-columns"></i> College Statistics
                </button>
                <button class="nav-item <?php echo $active_tab == 'courses' ? 'active' : ''; ?>"
                    onclick="switchTab('courses')">
                    <i class="fas fa-book-open"></i> Course Statistics
                </button>
                <button class="nav-item <?php echo $active_tab == 'history' ? 'active' : ''; ?>"
                    onclick="switchTab('history')">
                    <i class="fas fa-history"></i> Clearance History
                </button>
                <button class="nav-item <?php echo $active_tab == 'students' ? 'active' : ''; ?>"
                    onclick="switchTab('students')">
                    <i class="fas fa-users"></i> Student Records
                </button>
                <?php if (!empty($approvals_to_undo)): ?>
                        <button class="nav-item <?php echo $active_tab == 'undo' ? 'active' : ''; ?>"
                            onclick="switchTab('undo')">
                            <i class="fas fa-undo-alt"></i> Undo Approvals
                            <span class="badge badge-undo"
                                style="margin-left: auto;"><?php echo count($approvals_to_undo); ?></span>
                        </button>
                <?php endif; ?>
            </nav>
        </aside>

        <main class="content-area">
            <?php if (!empty($success)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <span><?php echo $success; ?></span>
                    </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <span><?php echo $error; ?></span>
                    </div>
            <?php endif; ?>

            <!-- Dashboard Tab -->
            <div id="dashboard" class="tab-content <?php echo $active_tab == 'dashboard' ? 'active' : ''; ?>">
                <div class="welcome-banner">
                    <h1>Welcome, <?php echo htmlspecialchars(explode(' ', $org_name)[0]); ?>! 👋</h1>
                    <p>Manage SSG clearances and track student government organization records.</p>
                    <div class="org-info">
                        <i class="fas fa-info-circle"></i> Supreme Student Government - <?php echo date('F j, Y'); ?>
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

                <!-- Year Level Statistics -->
                <?php if (!empty($year_level_stats)): ?>
                        <div class="section-card">
                            <div class="section-header">
                                <h2><i class="fas fa-graduation-cap"></i> Year Level Distribution</h2>
                            </div>
                            <div class="year-stats-grid">
                                <?php foreach ($year_level_stats as $year): ?>
                                        <div class="year-stat-card">
                                            <h3><?php echo htmlspecialchars($year['year_level']); ?></h3>
                                            <div class="year-stat-value"><?php echo $year['student_count']; ?></div>
                                            <div class="year-stat-label">Students</div>
                                            <div style="display: flex; justify-content: space-around; margin-top: 10px;">
                                                <div>
                                                    <div class="year-stat-value" style="font-size: 1rem; color: var(--success);"><?php echo $year['approved_count'] ?? 0; ?></div>
                                                    <div class="year-stat-label">Approved</div>
                                                </div>
                                                <div>
                                                    <div class="year-stat-value" style="font-size: 1rem; color: var(--warning);"><?php echo $year['pending_count'] ?? 0; ?></div>
                                                    <div class="year-stat-label">Pending</div>
                                                </div>
                                            </div>
                                            <?php if ($year['total_clearances'] > 0): ?>
                                                    <div class="mini-progress">
                                                        <div class="mini-progress-fill" style="width: <?php echo ($year['approved_count'] / $year['total_clearances']) * 100; ?>%"></div>
                                                    </div>
                                            <?php endif; ?>
                                        </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                <?php endif; ?>

                <!-- Top Colleges -->
                <?php if (!empty($college_stats)): ?>
                        <div class="section-card">
                            <div class="section-header">
                                <h2><i class="fas fa-building-columns"></i> Top Colleges</h2>
                                <button class="btn btn-primary" onclick="switchTab('colleges')">View All</button>
                            </div>
                            <div class="college-stats-grid">
                                <?php foreach (array_slice($college_stats, 0, 4) as $college): ?>
                                        <div class="college-stat-card">
                                            <div class="college-stat-header">
                                                <h3><?php echo htmlspecialchars($college['college_name']); ?></h3>
                                            </div>
                                            <div class="college-stat-content">
                                                <div class="college-stat-item">
                                                    <div class="value"><?php echo $college['student_count']; ?></div>
                                                    <div class="label">Students</div>
                                                </div>
                                                <div class="college-stat-item">
                                                    <div class="value" style="color: var(--success);"><?php echo $college['approved_count']; ?></div>
                                                    <div class="label">Approved</div>
                                                </div>
                                                <div class="college-stat-item">
                                                    <div class="value" style="color: var(--warning);"><?php echo $college['pending_count']; ?></div>
                                                    <div class="label">Pending</div>
                                                </div>
                                            </div>
                                            <?php if ($college['total_clearances'] > 0): ?>
                                                    <div class="mini-progress">
                                                        <div class="mini-progress-fill" style="width: <?php echo ($college['approved_count'] / $college['total_clearances']) * 100; ?>%"></div>
                                                    </div>
                                            <?php endif; ?>
                                        </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                <?php endif; ?>

                <!-- Recent Clearances -->
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-history"></i> Recent Activities</h2>
                        <button class="btn btn-primary" onclick="switchTab('history')">View All</button>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>ID</th>
                                    <th>College</th>
                                    <th>Year</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_clearances)): ?>
                                        <?php foreach ($recent_clearances as $clearance): ?>
                                                <tr>
                                                    <td>
                                                        <div class="student-cell">
                                                            <div class="student-avatar-small">
                                                                <?php if (!empty($clearance['profile_picture'])): ?>
                                                                        <img src="../<?php echo $clearance['profile_picture']; ?>" alt="">
                                                                <?php else: ?>
                                                                        <i class="fas fa-user-graduate"></i>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="student-info-small">
                                                                <div class="name">
                                                                    <?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?>
                                                                </div>
                                                                <div class="id"><?php echo htmlspecialchars($clearance['ismis_id']); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($clearance['ismis_id']); ?></td>
                                                    <td><?php echo htmlspecialchars($clearance['college_name'] ?? 'N/A'); ?></td>
                                                    <td><span class="year-badge <?php echo getYearLevelBadge($clearance['year_level'] ?? ''); ?>"><?php echo htmlspecialchars($clearance['year_level'] ?? 'N/A'); ?></span></td>
                                                    <td>
                                                        <span class="status-badge <?php echo getStatusClass($clearance['status']); ?>">
                                                            <?php echo ucfirst($clearance['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="tooltip">
                                                            <?php echo date('M d, Y', strtotime($clearance['processed_date'] ?? $clearance['created_at'])); ?>
                                                            <span
                                                                class="tooltiptext"><?php echo timeAgo($clearance['processed_date'] ?? $clearance['created_at']); ?></span>
                                                        </div>
                                                    </td>
                                                </tr>
                                        <?php endforeach; ?>
                                <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="empty-state" style="padding: 30px;">
                                                <i class="fas fa-history"></i>
                                                <p>No recent activities</p>
                                            </td>
                                        </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Pending Clearances Tab -->
            <div id="pending" class="tab-content <?php echo $active_tab == 'pending' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-clock"></i> Pending SSG Clearances</h2>
                        <?php if (empty($pending_clearances)): ?>
                                <span class="badge badge-success">No Pending</span>
                        <?php else: ?>
                                <span class="badge badge-warning"><?php echo count($pending_clearances); ?> Pending</span>
                        <?php endif; ?>
                    </div>

                    <!-- Filter Bar -->
                    <div class="filter-bar">
                        <select class="filter-select" id="collegeFilter">
                            <option value="">All Colleges</option>
                            <?php foreach ($stats['colleges'] ?? [] as $college): ?>
                                    <option value="<?php echo $college['college_id']; ?>" <?php echo $filter_college == $college['college_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($college['college_name']); ?>
                                    </option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="courseFilter">
                            <option value="">All Courses</option>
                            <?php foreach ($stats['courses'] ?? [] as $course): ?>
                                    <option value="<?php echo $course['course_id']; ?>" <?php echo $filter_course == $course['course_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']); ?>
                                    </option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="yearLevelFilter">
                            <option value="">All Year Levels</option>
                            <?php foreach ($stats['year_levels'] ?? [] as $year): ?>
                                    <option value="<?php echo $year['year_level']; ?>" <?php echo $filter_year_level == $year['year_level'] ? 'selected' : ''; ?>>
                                        <?php echo $year['year_level']; ?>
                                    </option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="semesterFilter">
                            <option value="">All Semesters</option>
                            <?php foreach ($stats['semesters'] ?? [] as $sem): ?>
                                    <option value="<?php echo $sem['semester']; ?>" <?php echo $filter_semester == $sem['semester'] ? 'selected' : ''; ?>>
                                        <?php echo $sem['semester']; ?>
                                    </option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="schoolYearFilter">
                            <option value="">All School Years</option>
                            <?php foreach ($stats['school_years'] ?? [] as $year): ?>
                                    <option value="<?php echo $year['school_year']; ?>" <?php echo $filter_school_year == $year['school_year'] ? 'selected' : ''; ?>>
                                        <?php echo $year['school_year']; ?>
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
                                <h3>No pending clearances</h3>
                                <p>All SSG clearances have been processed.</p>
                            </div>
                    <?php else: ?>
                            <div class="table-responsive">
                                <table id="pendingTable">
                                    <thead>
                                        <tr>
                                            <th style="width: 40px;">Select</th>
                                            <th>Student</th>
                                            <th>College</th>
                                            <th>Course</th>
                                            <th>Year</th>
                                            <th>Semester</th>
                                            <th>Progress</th>
                                            <th>Waiting</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_clearances as $clearance): ?>
                                                <tr data-college="<?php echo $clearance['college_id']; ?>"
                                                    data-course="<?php echo $clearance['course_id']; ?>"
                                                    data-year="<?php echo $clearance['year_level']; ?>"
                                                    data-semester="<?php echo $clearance['semester']; ?>"
                                                    data-school-year="<?php echo $clearance['school_year']; ?>"
                                                    data-name="<?php echo strtolower($clearance['fname'] . ' ' . $clearance['lname']); ?>"
                                                    data-id="<?php echo strtolower($clearance['ismis_id']); ?>">
                                                    <td>
                                                        <input type="checkbox" class="select-checkbox clearance-checkbox"
                                                            value="<?php echo $clearance['org_clearance_id']; ?>">
                                                    </td>
                                                    <td>
                                                        <div class="student-cell">
                                                            <div class="student-avatar-small">
                                                                <?php if (!empty($clearance['profile_picture'])): ?>
                                                                        <img src="../<?php echo $clearance['profile_picture']; ?>" alt="">
                                                                <?php else: ?>
                                                                        <i class="fas fa-user-graduate"></i>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="student-info-small">
                                                                <div class="name">
                                                                    <?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?>
                                                                </div>
                                                                <div class="id"><?php echo htmlspecialchars($clearance['ismis_id']); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($clearance['college_name'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <div class="tooltip">
                                                            <?php echo htmlspecialchars($clearance['course_name'] ?? 'N/A'); ?>
                                                        </div>
                                                    </td>
                                                    <td><span class="year-badge <?php echo getYearLevelBadge($clearance['year_level'] ?? ''); ?>"><?php echo htmlspecialchars($clearance['year_level'] ?? 'N/A'); ?></span></td>
                                                    <td>
                                                        <span class="type-badge">
                                                            <?php echo ($clearance['semester'] ?? '') . ' ' . ($clearance['school_year'] ?? ''); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <span class="progress-badge tooltip"
                                                            title="Approved offices: <?php echo htmlspecialchars($clearance['approved_offices'] ?? 'None yet'); ?>">
                                                            <i class="fas fa-check-circle"></i>
                                                            <?php echo ($clearance['approved_count'] ?? 0) . '/' . ($clearance['total_count'] ?? 6); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="waiting-time">
                                                            <i class="far fa-clock"></i>
                                                            <?php echo timeAgo($clearance['created_at']); ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="action-btns">
                                                            <button class="action-btn approve"
                                                                onclick="openProcessModal(<?php echo $clearance['org_clearance_id']; ?>, 'approve')"
                                                                title="Approve">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                            <button class="action-btn reject"
                                                                onclick="openProcessModal(<?php echo $clearance['org_clearance_id']; ?>, 'reject')"
                                                                title="Reject">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                            <button class="action-btn view"
                                                                onclick="viewStudentDetails(<?php echo $clearance['users_id']; ?>)"
                                                                title="View Details">
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

            <!-- College Statistics Tab -->
            <div id="colleges" class="tab-content <?php echo $active_tab == 'colleges' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-building-columns"></i> College Statistics</h2>
                    </div>

                    <?php if (empty($college_stats)): ?>
                            <div class="empty-state">
                                <i class="fas fa-building-columns"></i>
                                <h3>No college data available</h3>
                                <p>College statistics will appear here once students apply for clearance.</p>
                            </div>
                    <?php else: ?>
                            <div class="college-stats-grid">
                                <?php foreach ($college_stats as $college): ?>
                                        <div class="college-stat-card">
                                            <div class="college-stat-header">
                                                <h3><?php echo htmlspecialchars($college['college_name']); ?></h3>
                                            </div>
                                            <div class="college-stat-content">
                                                <div class="college-stat-item">
                                                    <div class="value"><?php echo $college['student_count']; ?></div>
                                                    <div class="label">Students</div>
                                                </div>
                                                <div class="college-stat-item">
                                                    <div class="value" style="color: var(--success);"><?php echo $college['approved_count']; ?></div>
                                                    <div class="label">Approved</div>
                                                </div>
                                                <div class="college-stat-item">
                                                    <div class="value" style="color: var(--warning);"><?php echo $college['pending_count']; ?></div>
                                                    <div class="label">Pending</div>
                                                </div>
                                                <div class="college-stat-item">
                                                    <div class="value" style="color: var(--danger);"><?php echo $college['rejected_count'] ?? 0; ?></div>
                                                    <div class="label">Rejected</div>
                                                </div>
                                            </div>
                                            <?php if ($college['total_clearances'] > 0): ?>
                                                    <div class="mini-progress">
                                                        <div class="mini-progress-fill" style="width: <?php echo ($college['approved_count'] / $college['total_clearances']) * 100; ?>%"></div>
                                                    </div>
                                                    <div style="display: flex; justify-content: space-between; margin-top: 8px;">
                                                        <small>Approval Rate: <?php echo round(($college['approved_count'] / $college['total_clearances']) * 100, 1); ?>%</small>
                                                        <small>Total: <?php echo $college['total_clearances']; ?></small>
                                                    </div>
                                            <?php endif; ?>
                                            <div style="margin-top: 15px;">
                                                <button class="btn btn-primary" style="width: 100%; padding: 8px;"
                                                    onclick="filterByCollege(<?php echo $college['college_id']; ?>)">
                                                    <i class="fas fa-filter"></i> View Clearances
                                                </button>
                                            </div>
                                        </div>
                                <?php endforeach; ?>
                            </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Course Statistics Tab -->
            <div id="courses" class="tab-content <?php echo $active_tab == 'courses' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-book-open"></i> Course Statistics</h2>
                    </div>

                    <?php if (empty($course_stats)): ?>
                            <div class="empty-state">
                                <i class="fas fa-book-open"></i>
                                <h3>No course data available</h3>
                                <p>Course statistics will appear here once students apply for clearance.</p>
                            </div>
                    <?php else: ?>
                            <div class="course-stats-grid">
                                <?php foreach ($course_stats as $course): ?>
                                        <div class="course-stat-card">
                                            <div class="course-stat-header">
                                                <h3><?php echo htmlspecialchars($course['course_name']); ?></h3>
                                                <span class="code"><?php echo htmlspecialchars($course['course_code']); ?></span>
                                                <span class="college"><?php echo htmlspecialchars($course['college_name'] ?? ''); ?></span>
                                            </div>
                                            <div class="course-stat-content">
                                                <div class="course-stat-item">
                                                    <div class="value"><?php echo $course['student_count']; ?></div>
                                                    <div class="label">Students</div>
                                                </div>
                                                <div class="course-stat-item">
                                                    <div class="value" style="color: var(--success);"><?php echo $course['approved_count']; ?></div>
                                                    <div class="label">Approved</div>
                                                </div>
                                                <div class="course-stat-item">
                                                    <div class="value" style="color: var(--warning);"><?php echo $course['pending_count']; ?></div>
                                                    <div class="label">Pending</div>
                                                </div>
                                                <div class="course-stat-item">
                                                    <div class="value" style="color: var(--danger);"><?php echo $course['rejected_count'] ?? 0; ?></div>
                                                    <div class="label">Rejected</div>
                                                </div>
                                            </div>
                                            <?php if ($course['total_clearances'] > 0): ?>
                                                    <div class="mini-progress">
                                                        <div class="mini-progress-fill" style="width: <?php echo ($course['approved_count'] / $course['total_clearances']) * 100; ?>%"></div>
                                                    </div>
                                                    <div style="display: flex; justify-content: space-between; margin-top: 8px;">
                                                        <small>Approval Rate: <?php echo round(($course['approved_count'] / $course['total_clearances']) * 100, 1); ?>%</small>
                                                        <small>Total: <?php echo $course['total_clearances']; ?></small>
                                                    </div>
                                            <?php endif; ?>
                                            <div style="margin-top: 15px;">
                                                <button class="btn btn-primary" style="width: 100%; padding: 8px;"
                                                    onclick="filterByCourse(<?php echo $course['course_id']; ?>)">
                                                    <i class="fas fa-filter"></i> View Clearances
                                                </button>
                                            </div>
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
                        <select class="filter-select" id="historyCollegeFilter">
                            <option value="">All Colleges</option>
                            <?php foreach ($stats['colleges'] ?? [] as $college): ?>
                                    <option value="<?php echo $college['college_id']; ?>"><?php echo htmlspecialchars($college['college_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="historyCourseFilter">
                            <option value="">All Courses</option>
                            <?php foreach ($stats['courses'] ?? [] as $course): ?>
                                    <option value="<?php echo $course['course_id']; ?>"><?php echo htmlspecialchars($course['course_code']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="historyYearFilter">
                            <option value="">All Years</option>
                            <?php foreach ($stats['year_levels'] ?? [] as $year): ?>
                                    <option value="<?php echo $year['year_level']; ?>"><?php echo $year['year_level']; ?></option>
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
                                <h3>No clearance history found</h3>
                                <p>Processed clearances will appear here.</p>
                            </div>
                    <?php else: ?>
                            <div class="table-responsive">
                                <table id="historyTable">
                                    <thead>
                                        <tr>
                                            <th>Student</th>
                                            <th>College</th>
                                            <th>Course</th>
                                            <th>Year</th>
                                            <th>Semester</th>
                                            <th>Status</th>
                                            <th>Processed</th>
                                            <th>Remarks</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($clearance_history as $clearance): ?>
                                                <tr data-college="<?php echo $clearance['college_id']; ?>"
                                                    data-course="<?php echo $clearance['course_id']; ?>"
                                                    data-year="<?php echo $clearance['year_level']; ?>"
                                                    data-status="<?php echo $clearance['status']; ?>"
                                                    data-name="<?php echo strtolower($clearance['fname'] . ' ' . $clearance['lname']); ?>"
                                                    data-id="<?php echo strtolower($clearance['ismis_id']); ?>">
                                                    <td>
                                                        <div class="student-cell">
                                                            <div class="student-avatar-small">
                                                                <?php if (!empty($clearance['profile_picture'])): ?>
                                                                        <img src="../<?php echo $clearance['profile_picture']; ?>" alt="">
                                                                <?php else: ?>
                                                                        <i class="fas fa-user-graduate"></i>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="student-info-small">
                                                                <div class="name">
                                                                    <?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?>
                                                                </div>
                                                                <div class="id"><?php echo htmlspecialchars($clearance['ismis_id']); ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($clearance['college_name'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($clearance['course_name'] ?? 'N/A'); ?></td>
                                                    <td><span class="year-badge <?php echo getYearLevelBadge($clearance['year_level'] ?? ''); ?>"><?php echo htmlspecialchars($clearance['year_level'] ?? 'N/A'); ?></span></td>
                                                    <td><?php echo ($clearance['semester'] ?? '') . ' ' . ($clearance['school_year'] ?? ''); ?>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?php echo getStatusClass($clearance['status']); ?>">
                                                            <?php echo ucfirst($clearance['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <div class="tooltip">
                                                            <?php echo date('M d, Y', strtotime($clearance['processed_date'] ?? $clearance['created_at'])); ?>
                                                            <span
                                                                class="tooltiptext"><?php echo timeAgo($clearance['processed_date'] ?? $clearance['created_at']); ?></span>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div class="tooltip">
                                                            <?php echo substr(htmlspecialchars($clearance['remarks'] ?? '—'), 0, 30) . (strlen($clearance['remarks'] ?? '') > 30 ? '...' : ''); ?>
                                                            <?php if (!empty($clearance['remarks'])): ?>
                                                                    <span
                                                                        class="tooltiptext"><?php echo htmlspecialchars($clearance['remarks']); ?></span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <button class="action-btn view"
                                                            onclick="viewStudentDetails(<?php echo $clearance['users_id']; ?>)"
                                                            title="View Details">
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

            <!-- Students Tab -->
            <div id="students" class="tab-content <?php echo $active_tab == 'students' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-users"></i> Student Records</h2>
                    </div>

                    <!-- Search Bar -->
                    <div class="filter-bar">
                        <select class="filter-select" id="studentCollegeFilter">
                            <option value="">All Colleges</option>
                            <?php foreach ($stats['colleges'] ?? [] as $college): ?>
                                    <option value="<?php echo $college['college_id']; ?>"><?php echo htmlspecialchars($college['college_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="studentCourseFilter">
                            <option value="">All Courses</option>
                            <?php foreach ($stats['courses'] ?? [] as $course): ?>
                                    <option value="<?php echo $course['course_id']; ?>"><?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="studentYearFilter">
                            <option value="">All Years</option>
                            <?php foreach ($stats['year_levels'] ?? [] as $year): ?>
                                    <option value="<?php echo $year['year_level']; ?>"><?php echo $year['year_level']; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="filter-input" id="studentSearch" placeholder="Search by name or ID..."
                            style="flex: 1;">
                        <button class="filter-btn" onclick="filterStudents()"><i class="fas fa-search"></i>
                            Search</button>
                        <button class="clear-filter" onclick="clearStudentFilters()"><i class="fas fa-times"></i>
                            Clear</button>
                    </div>

                    <!-- Students Grid -->
                    <div class="students-grid" id="studentsGrid">
                        <?php foreach ($students as $student): ?>
                                <div class="student-card"
                                    data-college="<?php echo $student['college_id']; ?>"
                                    data-course="<?php echo $student['course_id']; ?>"
                                    data-year="<?php echo $student['year_level']; ?>"
                                    data-name="<?php echo strtolower($student['fname'] . ' ' . $student['lname']); ?>"
                                    data-id="<?php echo strtolower($student['ismis_id']); ?>">
                                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                                        <div class="student-avatar">
                                            <?php if (!empty($student['profile_picture'])): ?>
                                                    <img src="../<?php echo $student['profile_picture']; ?>" alt="">
                                            <?php else: ?>
                                                    <i class="fas fa-user-graduate"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="student-info">
                                            <h4><?php echo htmlspecialchars($student['fname'] . ' ' . $student['lname']); ?>
                                            </h4>
                                            <p><?php echo htmlspecialchars($student['ismis_id']); ?></p>
                                            <div style="display: flex; gap: 5px; margin-top: 5px; flex-wrap: wrap;">
                                                <span class="badge badge-primary"><?php echo htmlspecialchars($student['college_name'] ?? 'N/A'); ?></span>
                                                <span class="badge badge-primary"><?php echo htmlspecialchars($student['course_code'] ?? 'N/A'); ?></span>
                                                <span class="year-badge <?php echo getYearLevelBadge($student['year_level'] ?? ''); ?>"><?php echo htmlspecialchars($student['year_level'] ?? 'N/A'); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div style="display: flex; gap: 10px;">
                                        <button class="btn btn-primary" style="flex: 1; padding: 10px;"
                                            onclick="viewStudentDetails(<?php echo $student['users_id']; ?>)">
                                            <i class="fas fa-eye"></i> View Details
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

            <!-- Undo Approvals Tab -->
            <?php if (!empty($approvals_to_undo)): ?>
                    <div id="undo" class="tab-content <?php echo $active_tab == 'undo' ? 'active' : ''; ?>">
                        <div class="section-card">
                            <div class="section-header">
                                <h2><i class="fas fa-undo-alt"></i> Your Processed Clearances</h2>
                                <span class="badge badge-undo"><?php echo count($approvals_to_undo); ?> processed</span>
                            </div>

                            <div class="info-card">
                                <i class="fas fa-info-circle"></i>
                                <div>
                                    <strong>Unlimited undo available:</strong> You can undo any clearance you've processed. This
                                    will revert the clearance back to pending status.
                                </div>
                            </div>

                            <div class="filter-bar">
                                <input type="text" class="filter-input" id="undoSearch"
                                    placeholder="Search by student name or ID..." onkeyup="filterUndo()">
                            </div>

                            <div class="undo-grid" id="undoGrid">
                                <?php foreach ($approvals_to_undo as $approval): ?>
                                        <div class="undo-item"
                                            data-name="<?php echo strtolower($approval['fname'] . ' ' . $approval['lname']); ?>"
                                            data-id="<?php echo strtolower($approval['ismis_id']); ?>">
                                            <div class="undo-info">
                                                <h4><?php echo htmlspecialchars($approval['fname'] . ' ' . $approval['lname']); ?></h4>
                                                <p>
                                                    <span class="status-badge <?php echo getStatusClass($approval['status']); ?>"
                                                        style="font-size: 0.7rem; padding: 2px 8px;">
                                                        <?php echo ucfirst($approval['status']); ?>
                                                    </span>
                                                    <span class="type-badge"
                                                        style="font-size: 0.7rem;"><?php echo ucfirst(str_replace('_', ' ', $approval['clearance_type'] ?? 'N/A')); ?></span>
                                                    <span class="year-badge <?php echo getYearLevelBadge($approval['year_level'] ?? ''); ?>" style="font-size: 0.7rem;"><?php echo htmlspecialchars($approval['year_level'] ?? 'N/A'); ?></span>
                                                </p>
                                                <p><?php echo htmlspecialchars($approval['ismis_id']); ?> |
                                                    <?php echo htmlspecialchars($approval['course_name'] ?? 'N/A'); ?></p>
                                                <small>Processed:
                                                    <?php echo $approval['formatted_date'] ?? date('M d, Y h:i A', strtotime($approval['processed_date'])); ?></small>
                                            </div>
                                            <button class="undo-btn"
                                                onclick="openUndoModal(<?php echo $approval['org_clearance_id']; ?>, '<?php echo htmlspecialchars($approval['fname'] . ' ' . $approval['lname']); ?>', '<?php echo ucfirst($approval['status']); ?>')">
                                                <i class="fas fa-undo-alt"></i> Undo
                                            </button>
                                        </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Process Clearance Modal -->
    <div id="processModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle"></i> <span id="modalTitle">Process Clearance</span></h3>
                <button class="close" onclick="closeProcessModal()">&times;</button>
            </div>
            <form method="POST" action="" id="processForm">
                <div class="modal-body">
                    <input type="hidden" name="org_clearance_id" id="modalClearanceId">
                    <input type="hidden" name="status" id="modalStatus">

                    <div class="form-group">
                        <label><i class="fas fa-comment"></i> Remarks <small>(optional)</small></label>
                        <textarea name="remarks" id="modalRemarks" rows="4"
                            placeholder="Enter any notes or remarks about this decision..."></textarea>
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

    <!-- Undo Approval Modal -->
    <div id="undoModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-undo-alt"></i> Undo Approval</h3>
                <button class="close" onclick="closeUndoModal()">&times;</button>
            </div>
            <form method="POST" action="" id="undoForm">
                <div class="modal-body">
                    <input type="hidden" name="org_clearance_id" id="undoClearanceId">

                    <div
                        style="background: var(--warning-soft); padding: 15px; border-radius: 12px; margin-bottom: 20px; border-left: 4px solid var(--warning);">
                        <i class="fas fa-exclamation-triangle" style="color: var(--warning); margin-right: 10px;"></i>
                        <strong>Warning:</strong> This will revert the clearance back to pending status. The student
                        will need to be re-approved.
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-comment"></i> Reason for Undo <span
                                style="color: var(--danger);">*</span></label>
                        <textarea name="undo_reason" id="undoReason" rows="4"
                            placeholder="Explain why you need to undo this approval..." required></textarea>
                    </div>

                    <div id="undoStudentInfo"
                        style="background: var(--bg-secondary); padding: 15px; border-radius: 12px; margin-top: 15px;">
                        <p style="color: var(--text-secondary);">Loading student information...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeUndoModal()">Cancel</button>
                    <button type="submit" name="undo_approval" class="btn btn-undo">Confirm Undo</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Student Details Modal -->
    <div id="studentModal" class="modal">
        <div class="modal-content large">
            <div class="modal-header">
                <h3><i class="fas fa-user-graduate"></i> Student Details</h3>
                <button class="close" onclick="closeStudentModal()">&times;</button>
            </div>
            <div class="modal-body" id="studentModalBody">
                <div class="empty-state">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading student details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeStudentModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = document.getElementById('themeIcon');
        const body = document.body;

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
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.getElementById(tabName).classList.add('active');

            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });

            event.target.closest('.nav-item').classList.add('active');

            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }

        // Filter by college
        function filterByCollege(collegeId) {
            switchTab('pending');
            document.getElementById('collegeFilter').value = collegeId;
            filterPending();
        }

        // Filter by course
        function filterByCourse(courseId) {
            switchTab('pending');
            document.getElementById('courseFilter').value = courseId;
            filterPending();
        }

        // Process Modal
        function openProcessModal(orgClearanceId, action) {
            document.getElementById('processModal').style.display = 'flex';
            document.getElementById('modalClearanceId').value = orgClearanceId;
            document.getElementById('modalStatus').value = action;

            const modalTitle = document.getElementById('modalTitle');
            const modalSubmitBtn = document.getElementById('modalSubmitBtn');

            if (action === 'approve') {
                modalTitle.innerHTML = '<i class="fas fa-check-circle"></i> Approve Clearance';
                modalSubmitBtn.className = 'btn btn-success';
                modalSubmitBtn.innerHTML = '<i class="fas fa-check"></i> Approve';
            } else {
                modalTitle.innerHTML = '<i class="fas fa-times-circle"></i> Reject Clearance';
                modalSubmitBtn.className = 'btn btn-danger';
                modalSubmitBtn.innerHTML = '<i class="fas fa-times"></i> Reject';
            }
        }

        function closeProcessModal() {
            document.getElementById('processModal').style.display = 'none';
            document.getElementById('modalRemarks').value = '';
        }

        // Undo Modal
        function openUndoModal(orgClearanceId, studentName, status) {
            document.getElementById('undoModal').style.display = 'flex';
            document.getElementById('undoClearanceId').value = orgClearanceId;

            const infoDiv = document.getElementById('undoStudentInfo');
            infoDiv.innerHTML = `
                <p><strong>Student:</strong> ${studentName}</p>
                <p><strong>Clearance ID:</strong> ${orgClearanceId}</p>
                <p><strong>Current Status:</strong> <span class="status-badge ${status === 'Approved' ? 'status-approved' : 'status-rejected'}">${status}</span></p>
                <p><small>Please provide a reason for undoing this approval.</small></p>
            `;
        }

        function closeUndoModal() {
            document.getElementById('undoModal').style.display = 'none';
            document.getElementById('undoReason').value = '';
        }

        // Student Details Modal
        function viewStudentDetails(userId) {
            const modal = document.getElementById('studentModal');
            const modalBody = document.getElementById('studentModalBody');

            modal.style.display = 'flex';

            modalBody.innerHTML = `
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <div style="background: var(--bg-secondary); border-radius: 16px; padding: 20px;">
                        <div style="text-align: center; padding: 20px;">
                            <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: var(--primary);"></i>
                            <p style="margin-top: 10px;">Loading student details for ID: ${userId}...</p>
                        </div>
                    </div>
                </div>
            `;
        }

        function closeStudentModal() {
            document.getElementById('studentModal').style.display = 'none';
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
                alert('Please select at least one clearance to approve.');
                return;
            }

            if (!confirm(`Are you sure you want to approve ${selected.length} clearance(s)?`)) {
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
            const college = document.getElementById('collegeFilter').value;
            const course = document.getElementById('courseFilter').value;
            const year = document.getElementById('yearLevelFilter').value;
            const semester = document.getElementById('semesterFilter').value;
            const schoolYear = document.getElementById('schoolYearFilter').value;
            const search = document.getElementById('pendingSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#pendingTable tbody tr');

            rows.forEach(row => {
                const rowCollege = row.getAttribute('data-college') || '';
                const rowCourse = row.getAttribute('data-course') || '';
                const rowYear = row.getAttribute('data-year') || '';
                const rowSemester = row.getAttribute('data-semester') || '';
                const rowSchoolYear = row.getAttribute('data-school-year') || '';
                const rowName = row.getAttribute('data-name') || '';
                const rowId = row.getAttribute('data-id') || '';

                const matchesCollege = !college || rowCollege === college;
                const matchesCourse = !course || rowCourse === course;
                const matchesYear = !year || rowYear === year;
                const matchesSemester = !semester || rowSemester === semester;
                const matchesSchoolYear = !schoolYear || rowSchoolYear === schoolYear;
                const matchesSearch = !search ||
                    rowName.includes(search) ||
                    rowId.includes(search);

                row.style.display = matchesCollege && matchesCourse && matchesYear && matchesSemester && matchesSchoolYear && matchesSearch ? '' : 'none';
            });
        }

        function clearPendingFilters() {
            document.getElementById('collegeFilter').value = '';
            document.getElementById('courseFilter').value = '';
            document.getElementById('yearLevelFilter').value = '';
            document.getElementById('semesterFilter').value = '';
            document.getElementById('schoolYearFilter').value = '';
            document.getElementById('pendingSearch').value = '';

            const rows = document.querySelectorAll('#pendingTable tbody tr');
            rows.forEach(row => {
                row.style.display = '';
            });
        }

        function filterHistory() {
            const college = document.getElementById('historyCollegeFilter').value;
            const course = document.getElementById('historyCourseFilter').value;
            const year = document.getElementById('historyYearFilter').value;
            const status = document.getElementById('historyStatusFilter').value.toLowerCase();
            const search = document.getElementById('historySearch').value.toLowerCase();

            const rows = document.querySelectorAll('#historyTable tbody tr');

            rows.forEach(row => {
                const rowCollege = row.getAttribute('data-college') || '';
                const rowCourse = row.getAttribute('data-course') || '';
                const rowYear = row.getAttribute('data-year') || '';
                const rowStatus = row.getAttribute('data-status')?.toLowerCase() || '';
                const rowName = row.getAttribute('data-name') || '';
                const rowId = row.getAttribute('data-id') || '';

                const matchesCollege = !college || rowCollege === college;
                const matchesCourse = !course || rowCourse === course;
                const matchesYear = !year || rowYear === year;
                const matchesStatus = !status || rowStatus.includes(status);
                const matchesSearch = !search ||
                    rowName.includes(search) ||
                    rowId.includes(search);

                row.style.display = matchesCollege && matchesCourse && matchesYear && matchesStatus && matchesSearch ? '' : 'none';
            });
        }

        function clearHistoryFilters() {
            document.getElementById('historyCollegeFilter').value = '';
            document.getElementById('historyCourseFilter').value = '';
            document.getElementById('historyYearFilter').value = '';
            document.getElementById('historyStatusFilter').value = '';
            document.getElementById('historySearch').value = '';

            const rows = document.querySelectorAll('#historyTable tbody tr');
            rows.forEach(row => {
                row.style.display = '';
            });
        }

        function filterStudents() {
            const college = document.getElementById('studentCollegeFilter').value;
            const course = document.getElementById('studentCourseFilter').value;
            const year = document.getElementById('studentYearFilter').value;
            const search = document.getElementById('studentSearch').value.toLowerCase();
            const cards = document.querySelectorAll('.student-card');

            cards.forEach(card => {
                const cardCollege = card.getAttribute('data-college') || '';
                const cardCourse = card.getAttribute('data-course') || '';
                const cardYear = card.getAttribute('data-year') || '';
                const name = card.getAttribute('data-name') || '';
                const id = card.getAttribute('data-id') || '';

                const matchesCollege = !college || cardCollege === college;
                const matchesCourse = !course || cardCourse === course;
                const matchesYear = !year || cardYear === year;
                const matchesSearch = !search ||
                    name.includes(search) ||
                    id.includes(search);

                card.style.display = matchesCollege && matchesCourse && matchesYear && matchesSearch ? 'block' : 'none';
            });
        }

        function clearStudentFilters() {
            document.getElementById('studentCollegeFilter').value = '';
            document.getElementById('studentCourseFilter').value = '';
            document.getElementById('studentYearFilter').value = '';
            document.getElementById('studentSearch').value = '';

            const cards = document.querySelectorAll('.student-card');
            cards.forEach(card => {
                card.style.display = 'block';
            });
        }

        function filterUndo() {
            const search = document.getElementById('undoSearch').value.toLowerCase();
            const items = document.querySelectorAll('#undoGrid .undo-item');

            items.forEach(item => {
                const name = item.getAttribute('data-name') || '';
                const id = item.getAttribute('data-id') || '';

                const matches = !search || name.includes(search) || id.includes(search);
                item.style.display = matches ? 'flex' : 'none';
            });
        }

        // Close modals when clicking outside
        window.onclick = function (event) {
            const processModal = document.getElementById('processModal');
            const undoModal = document.getElementById('undoModal');
            const studentModal = document.getElementById('studentModal');

            if (event.target == processModal) processModal.style.display = 'none';
            if (event.target == undoModal) undoModal.style.display = 'none';
            if (event.target == studentModal) studentModal.style.display = 'none';
        };

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