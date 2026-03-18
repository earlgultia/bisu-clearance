<?php
// clinic_dashboard.php - Clinic Dashboard for BISU Online Clearance System
// Location: C:\xampp\htdocs\clearance\organization\dashboard.php

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

// Verify that this is a clinic organization
if ($org_type !== 'clinic') {
    // Not authorized for clinic dashboard
    header("Location: ../index.php");
    exit();
}

// Get clinic office ID from offices table
$db->query("SELECT office_id FROM offices WHERE office_name = 'Clinic'");
$clinic_office = $db->single();
$clinic_office_id = $clinic_office ? $clinic_office['office_id'] : 6; // Default to ID 6 if not found

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

// ============================================
// HANDLE CLEARANCE APPROVAL/REJECTION
// ============================================
if (isset($_POST['process_clearance'])) {
    $clearance_id = $_POST['clearance_id'] ?? '';
    $status = $_POST['status'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    if ($clearance_id && $status) {
        try {
            $db->beginTransaction();

            // Get current clearance info
            $db->query("SELECT c.*, u.fname, u.lname, u.ismis_id, u.course_id
                       FROM clearance c
                       JOIN users u ON c.users_id = u.users_id
                       WHERE c.clearance_id = :id");
            $db->bind(':id', $clearance_id);
            $current = $db->single();

            if (!$current) {
                throw new Exception("Clearance not found");
            }

            // Check if this clearance belongs to clinic office
            if ($current['office_id'] != $clinic_office_id) {
                throw new Exception("Unauthorized access to this clearance");
            }

            // Check if clearance is still pending
            if ($current['status'] !== 'pending') {
                throw new Exception("This clearance has already been processed");
            }

            // Update the clearance
            $db->query("UPDATE clearance SET 
                        status = :status, 
                        remarks = CONCAT(IFNULL(remarks, ''), ' | Clinic: ', :remarks),
                        processed_by = :processed_by, 
                        processed_date = NOW(),
                        updated_at = NOW()
                        WHERE clearance_id = :id");
            $db->bind(':status', $status);
            $db->bind(':remarks', $remarks);
            $db->bind(':processed_by', $org_id);
            $db->bind(':id', $clearance_id);

            if ($db->execute()) {
                // Log the activity
                $logModel = new ActivityLogModel();
                $logModel->log($org_id, 'PROCESS_CLEARANCE', ucfirst($status) . " clinic clearance ID: $clearance_id for student: {$current['fname']} {$current['lname']} ({$current['ismis_id']})");

                $db->commit();
                $success = "Clinic clearance " . ($status == 'approved' ? 'approved' : 'rejected') . " successfully!";

                // Redirect to refresh the page
                header("Location: dashboard.php?tab=pending&success=1");
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
    $clearance_ids = $_POST['clearance_ids'] ?? [];
    $remarks = trim($_POST['bulk_remarks'] ?? '');

    if (!empty($clearance_ids)) {
        try {
            $db->beginTransaction();

            $placeholders = implode(',', array_fill(0, count($clearance_ids), '?'));

            // Verify all selected clearances belong to clinic and are pending
            $db->query("SELECT COUNT(*) as count 
                       FROM clearance 
                       WHERE clearance_id IN ($placeholders)
                       AND office_id = :office_id
                       AND status = 'pending'");

            foreach ($clearance_ids as $index => $id) {
                $db->bind($index + 1, $id);
            }
            $db->bind(':office_id', $clinic_office_id);
            $verify = $db->single();

            if ($verify['count'] != count($clearance_ids)) {
                $db->rollback();
                $error = "Some clearances are not valid for bulk approval.";
                throw new Exception("Invalid clearances");
            }

            // Update all eligible clearances
            $db->query("UPDATE clearance SET 
                        status = 'approved', 
                        remarks = CONCAT(IFNULL(remarks, ''), ' | Clinic (Bulk): ', :remarks),
                        processed_by = :processed_by, 
                        processed_date = NOW(),
                        updated_at = NOW()
                        WHERE clearance_id IN ($placeholders)");

            $db->bind(':remarks', $remarks);
            $db->bind(':processed_by', $org_id);

            foreach ($clearance_ids as $index => $id) {
                $db->bind($index + 1, $id);
            }

            if ($db->execute()) {
                // Log bulk approval
                $logModel = new ActivityLogModel();
                $logModel->log($org_id, 'BULK_APPROVE', "Bulk approved " . count($clearance_ids) . " clinic clearances");

                $db->commit();
                $success = count($clearance_ids) . " clinic clearance(s) approved successfully!";

                header("Location: dashboard.php?tab=pending&success=1");
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
        // Pending clearances
        $db->query("SELECT COUNT(*) as count FROM clearance WHERE office_id = :office_id AND status = 'pending'");
        $db->bind(':office_id', $clinic_office_id);
        $result = $db->single();
        $stats['pending'] = $result ? (int) $result['count'] : 0;

        // Approved clearances
        $db->query("SELECT COUNT(*) as count FROM clearance WHERE office_id = :office_id AND status = 'approved'");
        $db->bind(':office_id', $clinic_office_id);
        $result = $db->single();
        $stats['approved'] = $result ? (int) $result['count'] : 0;

        // Rejected clearances
        $db->query("SELECT COUNT(*) as count FROM clearance WHERE office_id = :office_id AND status = 'rejected'");
        $db->bind(':office_id', $clinic_office_id);
        $result = $db->single();
        $stats['rejected'] = $result ? (int) $result['count'] : 0;

        // Total students processed
        $db->query("SELECT COUNT(DISTINCT users_id) as count FROM clearance WHERE office_id = :office_id");
        $db->bind(':office_id', $clinic_office_id);
        $result = $db->single();
        $stats['students'] = $result ? (int) $result['count'] : 0;

        // Get pending clearances with student info - USING NEW DATABASE STRUCTURE
        $query = "SELECT c.*, u.fname, u.lname, u.ismis_id, u.course_id, u.address, u.contacts, u.age,
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
                  FROM clearance c
                  JOIN users u ON c.users_id = u.users_id
                  LEFT JOIN course cr ON u.course_id = cr.course_id
                  LEFT JOIN college col ON u.college_id = col.college_id
                  LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                  WHERE c.office_id = :office_id AND c.status = 'pending'";

        $params = [':office_id' => $clinic_office_id];

        if (!empty($filter_status)) {
            $query .= " AND c.status = :status";
            $params[':status'] = $filter_status;
        }

        $query .= " ORDER BY c.created_at ASC";

        $db->query($query);
        foreach ($params as $key => $value) {
            $db->bind($key, $value);
        }
        $pending_clearances = $db->resultSet();

        // Get recent approved/rejected clearances
        $query = "SELECT c.*, u.fname, u.lname, u.ismis_id, cr.course_name, col.college_name,
                         ct.clearance_name as clearance_type,
                         p.fname as processed_fname, p.lname as processed_lname
                  FROM clearance c
                  JOIN users u ON c.users_id = u.users_id
                  LEFT JOIN course cr ON u.course_id = cr.course_id
                  LEFT JOIN college col ON u.college_id = col.college_id
                  LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                  LEFT JOIN users p ON c.processed_by = p.users_id
                  WHERE c.office_id = :office_id AND c.status IN ('approved', 'rejected')
                  ORDER BY c.processed_date DESC
                  LIMIT 20";

        $db->query($query);
        $db->bind(':office_id', $clinic_office_id);
        $recent_clearances = $db->resultSet();

        // Get clearance history with filters
        $query = "SELECT c.*, u.fname, u.lname, u.ismis_id, cr.course_name, col.college_name,
                         ct.clearance_name as clearance_type,
                         p.fname as processed_fname, p.lname as processed_lname
                  FROM clearance c
                  JOIN users u ON c.users_id = u.users_id
                  LEFT JOIN course cr ON u.course_id = cr.course_id
                  LEFT JOIN college col ON u.college_id = col.college_id
                  LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                  LEFT JOIN users p ON c.processed_by = p.users_id
                  WHERE c.office_id = :office_id";

        $params = [':office_id' => $clinic_office_id];

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinic Dashboard - BISU Online Clearance</title>
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

        .action-btn.view {
            background: var(--info-soft);
            color: var(--info);
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

        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .student-info-grid {
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

            .student-info-grid {
                grid-template-columns: 1fr;
            }

            .students-grid {
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
        <aside class="sidebar">
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
            </nav>
        </aside>

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
                <div class="welcome-banner">
                    <h1>Welcome,
                        <?php echo htmlspecialchars(explode(' ', $org_name)[0]); ?>! 👋
                    </h1>
                    <p>Manage medical clearances and student health records.</p>
                    <div class="org-info">
                        <i class="fas fa-info-circle"></i> University Clinic
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon pending">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-details">
                            <h3>
                                <?php echo $stats['pending'] ?? 0; ?>
                            </h3>
                            <p>Pending</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon approved">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-details">
                            <h3>
                                <?php echo $stats['approved'] ?? 0; ?>
                            </h3>
                            <p>Approved</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon rejected">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-details">
                            <h3>
                                <?php echo $stats['rejected'] ?? 0; ?>
                            </h3>
                            <p>Rejected</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon students">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-details">
                            <h3>
                                <?php echo $stats['students'] ?? 0; ?>
                            </h3>
                            <p>Students</p>
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
                                                    value="<?php echo $clearance['clearance_id']; ?>">
                                            </td>
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
                                                    <button class="action-btn approve"
                                                        onclick="openProcessModal(<?php echo $clearance['clearance_id']; ?>, 'approve')">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button class="action-btn reject"
                                                        onclick="openProcessModal(<?php echo $clearance['clearance_id']; ?>, 'reject')">
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
                                                <?php echo htmlspecialchars($clearance['remarks'] ?? '—'); ?>
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
                    <input type="hidden" name="clearance_id" id="modalClearanceId">
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

        // Process Modal
        function openProcessModal(clearanceId, action) {
            document.getElementById('processModal').style.display = 'flex';
            document.getElementById('modalClearanceId').value = clearanceId;
            document.getElementById('modalStatus').value = action;

            const modalTitle = document.getElementById('modalTitle');
            const modalSubmitBtn = document.getElementById('modalSubmitBtn');

            if (action === 'approve') {
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

        function closeProgressModal() {
            document.getElementById('progressModal').style.display = 'none';
        }

        // View Student Progress
        function viewStudentProgress(userId, semester, schoolYear, studentName, studentId, course, college, address, contact, age) {
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
        }

        function viewStudentRecords(userId) {
            viewStudentProgress(userId, '', '', 'Loading...', '', '', '', '', '', '');
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
                input.name = 'clearance_ids[]';
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
            const search = document.getElementById('quickSearch').value;
            if (!search) {
                alert('Please enter a search term');
                return;
            }

            showToast('Searching for: ' + search, 'info');

            const resultsDiv = document.getElementById('quickSearchResults');
            resultsDiv.style.display = 'block';
            resultsDiv.innerHTML = `
                <div style="background: var(--bg-secondary); border-radius: 12px; padding: 20px;">
                    <h4 style="color: var(--text-primary); margin-bottom: 15px;">Search Results</h4>
                    <p style="color: var(--text-secondary);">Search functionality would show results for "${search}" here.</p>
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
        window.onclick = function (event) {
            const processModal = document.getElementById('processModal');
            const progressModal = document.getElementById('progressModal');

            if (event.target == processModal) {
                processModal.style.display = 'none';
            }
            if (event.target == progressModal) {
                progressModal.style.display = 'none';
            }
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