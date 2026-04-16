<?php
// librarian_dashboard.php - Fixed Librarian Dashboard with proper lacking comment resolution
// Location: C:\xampp\htdocs\clearance\sub_admin\librarian_dashboard.php

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Prevent showing stale authenticated pages from cache after logout or back navigation.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Include database configuration with correct path
require_once __DIR__ . '/../db.php';

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

// Check if user is sub_admin
if ($_SESSION['user_role'] !== 'sub_admin') {
    header("Location: ../index.php");
    exit();
}

// Get database instance
$db = Database::getInstance();

// Verify that this sub_admin is assigned to Librarian office
$db->query("SELECT sao.*, o.office_name, o.office_id, o.office_order
            FROM sub_admin_offices sao 
            JOIN offices o ON sao.office_id = o.office_id 
            WHERE sao.users_id = :user_id");
$db->bind(':user_id', $_SESSION['user_id']);
$office_check = $db->single();

// Check if user has Librarian office
$is_librarian = false;
$librarian_office_id = null;
$librarian_office_order = 1; // Librarian is step 1

if ($office_check && $office_check['office_name'] === 'Librarian') {
    $is_librarian = true;
    $librarian_office_id = $office_check['office_id'];
    $librarian_office_order = $office_check['office_order'] ?? 1;
}

if (!$is_librarian) {
    // Not authorized for librarian dashboard
    header("Location: ../index.php");
    exit();
}

// Get librarian information from session
$librarian_id = $_SESSION['user_id'];
$librarian_name = $_SESSION['user_name'] ?? '';
$librarian_email = $_SESSION['user_email'] ?? '';
$librarian_fname = $_SESSION['user_fname'] ?? '';
$librarian_lname = $_SESSION['user_lname'] ?? '';

// Initialize variables
$success = '';
$error = '';
$warning = '';
$active_tab = $_GET['tab'] ?? 'dashboard';
$profile_pic = null;
$filter_semester = $_GET['semester'] ?? '';
$filter_school_year = $_GET['school_year'] ?? '';
$filter_type = $_GET['type'] ?? '';
$filter_status = $_GET['status'] ?? '';

// ============================================
// UNDO FUNCTIONALITY - Revert mistaken approvals (NO TIME LIMIT)
// ============================================
if (isset($_POST['undo_approval'])) {
    $clearance_id = $_POST['clearance_id'] ?? '';
    $reason = trim($_POST['undo_reason'] ?? '');

    if ($clearance_id) {
        try {
            $db->beginTransaction();

            // Get current clearance info before updating
            $db->query("SELECT c.*, u.fname, u.lname, u.ismis_id 
                       FROM clearance c
                       JOIN users u ON c.users_id = u.users_id
                       WHERE c.clearance_id = :id");
            $db->bind(':id', $clearance_id);
            $current = $db->single();

            if (!$current) {
                throw new Exception("Clearance not found");
            }

            // Verify this clearance belongs to librarian office
            if ($current['office_id'] != $librarian_office_id) {
                throw new Exception("Unauthorized: This clearance does not belong to your office");
            }

            // Check if clearance was actually processed by someone
            if ($current['status'] === 'pending') {
                throw new Exception("This clearance is still pending. No need to undo.");
            }

            // Store the previous status in remarks before reverting
            $undo_remarks = "UNDO: Previous status was '" . $current['status'] . "' on " . date('Y-m-d H:i:s') . ". Reason: " . $reason . ". Undone by: " . $librarian_name;

            // Update the clearance back to pending
            $db->query("UPDATE clearance SET 
                        status = 'pending', 
                        remarks = CONCAT(IFNULL(remarks, ''), ' | ', :undo_remarks),
                        processed_by = NULL, 
                        processed_date = NULL,
                        updated_at = NOW()
                        WHERE clearance_id = :id");
            $db->bind(':undo_remarks', $undo_remarks);
            $db->bind(':id', $clearance_id);

            if ($db->execute()) {
                // Log the undo action
                if (class_exists('ActivityLogModel')) {
                    $logModel = new ActivityLogModel();
                    $logModel->log($librarian_id, 'UNDO_APPROVAL', "Undid " . $current['status'] . " for clearance ID: $clearance_id for student: {$current['fname']} {$current['lname']} ({$current['ismis_id']}). Reason: $reason");
                }

                $db->commit();
                $_SESSION['success_message'] = "Approval successfully undone! Clearance has been returned to pending status.";
                header("Location: librarian_dashboard.php?tab=undo");
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

// ============================================
// LACKING COMMENT FUNCTIONALITY
// ============================================
if (isset($_POST['add_lacking_comment'])) {
    $clearance_id = $_POST['clearance_id'] ?? '';
    $comment = trim($_POST['lacking_comment'] ?? '');

    if ($clearance_id && !empty($comment)) {
        try {
            $db->beginTransaction();

            // Get current clearance info
            $db->query("SELECT c.*, u.fname, u.lname, u.ismis_id, u.emails
                       FROM clearance c
                       JOIN users u ON c.users_id = u.users_id
                       WHERE c.clearance_id = :id");
            $db->bind(':id', $clearance_id);
            $current = $db->single();

            if (!$current) {
                throw new Exception("Clearance not found");
            }

            // Verify this clearance belongs to librarian office
            if ($current['office_id'] != $librarian_office_id) {
                throw new Exception("Unauthorized: This clearance does not belong to your office");
            }

            // Update clearance with lacking comment and clear any existing proof that might be there
            $db->query("UPDATE clearance SET 
                        lacking_comment = :comment,
                        lacking_comment_at = NOW(),
                        lacking_comment_by = :commented_by,
                        status = 'pending',
                        updated_at = NOW()
                        WHERE clearance_id = :id");
            $db->bind(':comment', $comment);
            $db->bind(':commented_by', $librarian_id);
            $db->bind(':id', $clearance_id);

            if ($db->execute()) {
                // Log the activity
                if (class_exists('ActivityLogModel')) {
                    $logModel = new ActivityLogModel();
                    $logModel->log($librarian_id, 'LACKING_COMMENT', "Added lacking comment for clearance ID: $clearance_id for student: {$current['fname']} {$current['lname']}. Comment: $comment");
                }

                $db->commit();
                $_SESSION['success_message'] = "Lacking comment added successfully!";
                header("Location: librarian_dashboard.php?tab=pending");
                exit();
            } else {
                $db->rollback();
                $error = "Failed to add lacking comment.";
            }
        } catch (Exception $e) {
            $db->rollback();
            error_log("Error adding lacking comment: " . $e->getMessage());
            $error = "Error: " . $e->getMessage();
        }
    } else {
        $error = "Please enter a comment about what the student is lacking.";
    }
}

// ============================================
// CLEARANCE APPROVAL - Now works with students who have submitted proof
// ============================================
if (isset($_POST['approve_clearance'])) {
    $clearance_id = $_POST['clearance_id'] ?? '';

    if ($clearance_id) {
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

            // Verify this clearance belongs to librarian office
            if ($current['office_id'] != $librarian_office_id) {
                throw new Exception("Unauthorized: This clearance does not belong to your office");
            }

            // Check if clearance is already approved
            if ($current['status'] === 'approved') {
                throw new Exception("This clearance is already approved.");
            }

            // Update the clearance to approved
            // IMPORTANT: We keep the student_proof_file for reference but mark as approved
            $db->query("UPDATE clearance SET 
                        status = 'approved', 
                        processed_by = :processed_by, 
                        processed_date = NOW(),
                        updated_at = NOW()
                        WHERE clearance_id = :id");
            $db->bind(':processed_by', $librarian_id);
            $db->bind(':id', $clearance_id);

            if ($db->execute()) {
                // Log the activity
                if (class_exists('ActivityLogModel')) {
                    $logModel = new ActivityLogModel();
                    $logModel->log($librarian_id, 'APPROVE_CLEARANCE', "Approved clearance ID: $clearance_id for student: {$current['fname']} {$current['lname']} ({$current['ismis_id']})");
                }

                $db->commit();
                $_SESSION['success_message'] = "Clearance approved successfully!";
                header("Location: librarian_dashboard.php?tab=pending");
                exit();
            } else {
                $db->rollback();
                $error = "Failed to approve clearance.";
            }
        } catch (Exception $e) {
            $db->rollback();
            error_log("Error approving clearance: " . $e->getMessage());
            $error = "Error: " . $e->getMessage();
        }
    }
}

// ============================================
// BULK APPROVAL (NO REMARKS)
// ============================================
if (isset($_POST['bulk_approve'])) {
    $clearance_ids = array_values(array_unique(array_map('intval', $_POST['clearance_ids'] ?? [])));
    $clearance_ids = array_filter($clearance_ids, function ($id) {
        return $id > 0;
    });

    if (!empty($clearance_ids)) {
        try {
            $db->beginTransaction();

            $placeholders = [];
            $clearance_id_params = [];

            foreach ($clearance_ids as $index => $id) {
                $placeholder = ':clearance_id_' . $index;
                $placeholders[] = $placeholder;
                $clearance_id_params[$placeholder] = $id;
            }

            $in_clause = implode(',', $placeholders);

            // Verify all selected clearances belong to this office, are pending,
            // and do not still have unresolved lacking requirements.
            $db->query("SELECT COUNT(*) as count 
                       FROM clearance 
                       WHERE clearance_id IN ($in_clause)
                       AND office_id = :office_id
                       AND status = 'pending'
                       AND (lacking_comment IS NULL OR student_proof_file IS NOT NULL)");

            foreach ($clearance_id_params as $placeholder => $id) {
                $db->bind($placeholder, $id);
            }
            $db->bind(':office_id', $librarian_office_id);
            $verify = $db->single();

            if ($verify['count'] != count($clearance_ids)) {
                throw new Exception("Some selected clearances are no longer valid for bulk approval.");
            }

            // Update all clearances (no remarks)
            $query = "UPDATE clearance SET 
                        status = 'approved', 
                        processed_by = :processed_by, 
                        processed_date = NOW(),
                        updated_at = NOW()
                        WHERE clearance_id IN ($in_clause)";

            $db->query($query);
            $db->bind(':processed_by', $librarian_id);

            foreach ($clearance_id_params as $placeholder => $id) {
                $db->bind($placeholder, $id);
            }

            if ($db->execute()) {
                // Log the bulk action
                if (class_exists('ActivityLogModel')) {
                    $logModel = new ActivityLogModel();
                    $logModel->log($librarian_id, 'BULK_APPROVE', "Bulk approved " . count($clearance_ids) . " clearances");
                }

                $db->commit();
                $_SESSION['success_message'] = count($clearance_ids) . " clearance(s) approved successfully!";
                header("Location: librarian_dashboard.php?tab=pending");
                exit();
            } else {
                $db->rollback();
                $error = "Failed to approve clearances.";
            }
        } catch (Exception $e) {
            if ($db->getConnection()->inTransaction()) {
                $db->rollback();
            }
            error_log("Error in bulk approval: " . $e->getMessage());
            $error = $e->getMessage();
        }
    }
}

// ============================================
// CLEAR LACKING COMMENT (When student has complied)
// ============================================
if (isset($_POST['clear_lacking_comment'])) {
    $clearance_id = $_POST['clearance_id'] ?? '';

    if ($clearance_id) {
        try {
            $db->beginTransaction();

            // Get current clearance info
            $db->query("SELECT c.*, u.fname, u.lname, u.ismis_id
                       FROM clearance c
                       JOIN users u ON c.users_id = u.users_id
                       WHERE c.clearance_id = :id");
            $db->bind(':id', $clearance_id);
            $current = $db->single();

            if (!$current) {
                throw new Exception("Clearance not found");
            }

            // Verify this clearance belongs to librarian office
            if ($current['office_id'] != $librarian_office_id) {
                throw new Exception("Unauthorized: This clearance does not belong to your office");
            }

            // Clear the lacking comment (student has complied)
            $db->query("UPDATE clearance SET 
                        lacking_comment = NULL,
                        lacking_comment_at = NULL,
                        lacking_comment_by = NULL,
                        remarks = CONCAT(IFNULL(remarks, ''), ' | Lacking requirements complied on ', NOW()),
                        updated_at = NOW()
                        WHERE clearance_id = :id");
            $db->bind(':id', $clearance_id);

            if ($db->execute()) {
                // Log the activity
                if (class_exists('ActivityLogModel')) {
                    $logModel = new ActivityLogModel();
                    $logModel->log($librarian_id, 'CLEAR_LACKING', "Cleared lacking comment for clearance ID: $clearance_id for student: {$current['fname']} {$current['lname']}");
                }

                $db->commit();
                $_SESSION['success_message'] = "Lacking comment cleared! Student has complied with requirements.";
                header("Location: librarian_dashboard.php?tab=pending");
                exit();
            } else {
                $db->rollback();
                $error = "Failed to clear lacking comment.";
            }
        } catch (Exception $e) {
            $db->rollback();
            error_log("Error clearing lacking comment: " . $e->getMessage());
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
$approvals_to_undo = [];
$pending_summary = [
    'total' => 0,
    'needs_review' => 0,
    'awaiting_student' => 0,
    'proof_submitted' => 0,
    'complied' => 0,
    'ready_for_bulk' => 0
];
$undo_summary = [
    'total' => 0,
    'approved' => 0,
    'rejected' => 0,
    'with_lacking' => 0,
    'with_student_proof' => 0
];
$history_summary = [
    'total' => 0,
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0,
    'proof_tracked' => 0
];
$student_summary = [
    'total' => 0,
    'with_library_records' => 0,
    'with_pending_library' => 0,
    'with_approved_library' => 0
];
$student_course_filters = [];

try {
    $db = Database::getInstance();

    // Get Librarian office ID (already have from above)
    if (!$librarian_office_id) {
        $db->query("SELECT office_id FROM offices WHERE office_name = 'Librarian'");
        $librarian_office = $db->single();
        $librarian_office_id = $librarian_office['office_id'] ?? null;
    }

    // Count statistics
    $db->query("SELECT COUNT(*) as count FROM clearance WHERE office_id = :office_id AND status = 'pending'");
    $db->bind(':office_id', $librarian_office_id);
    $result = $db->single();
    $stats['pending'] = $result ? (int) $result['count'] : 0;

    $db->query("SELECT COUNT(*) as count FROM clearance WHERE office_id = :office_id AND status = 'approved'");
    $db->bind(':office_id', $librarian_office_id);
    $result = $db->single();
    $stats['approved'] = $result ? (int) $result['count'] : 0;

    $db->query("SELECT COUNT(*) as count FROM clearance WHERE office_id = :office_id AND status = 'rejected'");
    $db->bind(':office_id', $librarian_office_id);
    $result = $db->single();
    $stats['rejected'] = $result ? (int) $result['count'] : 0;

    $db->query("SELECT COUNT(DISTINCT users_id) as count FROM clearance WHERE office_id = :office_id");
    $db->bind(':office_id', $librarian_office_id);
    $result = $db->single();
    $stats['students'] = $result ? (int) $result['count'] : 0;

    // Get pending clearances with full student info
    $query = "SELECT 
                c.*, 
                u.fname, 
                u.lname, 
                u.ismis_id, 
                u.course_id, 
                u.address, 
                u.contacts, 
                u.age, 
                u.emails as email,
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
                lcb.fname as lacking_by_fname,
                lcb.lname as lacking_by_lname,
                pub.fname as proof_by_fname,
                pub.lname as proof_by_lname
              FROM clearance c
              JOIN users u ON c.users_id = u.users_id
              LEFT JOIN course cr ON u.course_id = cr.course_id
              LEFT JOIN college col ON u.college_id = col.college_id
              LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
              LEFT JOIN users lcb ON c.lacking_comment_by = lcb.users_id
              LEFT JOIN users pub ON c.proof_uploaded_by = pub.users_id
              WHERE c.office_id = :office_id AND c.status = 'pending'";

    $params = [':office_id' => $librarian_office_id];

    if (!empty($filter_type)) {
        $query .= " AND ct.clearance_name = :type";
        $params[':type'] = $filter_type;
    }

    $query .= " ORDER BY 
                CASE 
                    WHEN c.lacking_comment IS NOT NULL AND c.student_proof_file IS NOT NULL THEN 1  -- Students who have submitted proof after lacking comment
                    WHEN c.lacking_comment IS NOT NULL THEN 2  -- Students with lacking comment
                    WHEN c.student_proof_file IS NOT NULL THEN 3  -- Students with proof but no lacking comment
                    ELSE 4  -- Regular pending
                END,
                c.created_at ASC";

    $db->query($query);
    foreach ($params as $key => $value) {
        $db->bind($key, $value);
    }
    $pending_clearances = $db->resultSet();

    foreach ($pending_clearances as $pending_item) {
        $pending_summary['total']++;

        $has_lacking = !empty($pending_item['lacking_comment']);
        $has_student_proof = !empty($pending_item['student_proof_file']);
        $is_bulk_ready = !$has_lacking || $has_student_proof;

        if ($has_lacking && $has_student_proof) {
            $pending_summary['complied']++;
        } elseif ($has_lacking) {
            $pending_summary['awaiting_student']++;
        } elseif ($has_student_proof) {
            $pending_summary['proof_submitted']++;
        } else {
            $pending_summary['needs_review']++;
        }

        if ($is_bulk_ready) {
            $pending_summary['ready_for_bulk']++;
        }
    }

    // Get recent approved/rejected clearances
    $query = "SELECT 
                c.*, 
                u.fname, 
                u.lname, 
                u.ismis_id, 
                cr.course_name, 
                col.college_name,
                ct.clearance_name as clearance_type,
                p.fname as processed_fname, 
                p.lname as processed_lname,
                c.proof_file, 
                c.proof_remarks, 
                c.proof_uploaded_at,
                c.lacking_comment, 
                c.lacking_comment_at,
                lcb.fname as lacking_by_fname,
                lcb.lname as lacking_by_lname
              FROM clearance c
              JOIN users u ON c.users_id = u.users_id
              LEFT JOIN course cr ON u.course_id = cr.course_id
              LEFT JOIN college col ON u.college_id = col.college_id
              LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
              LEFT JOIN users p ON c.processed_by = p.users_id
              LEFT JOIN users lcb ON c.lacking_comment_by = lcb.users_id
              WHERE c.office_id = :office_id AND c.status IN ('approved', 'rejected')
              ORDER BY c.processed_date DESC
              LIMIT 20";

    $db->query($query);
    $db->bind(':office_id', $librarian_office_id);
    $recent_clearances = $db->resultSet();

    // Get ALL approvals by this librarian
    $db->query("SELECT 
                c.*, 
                u.fname, 
                u.lname, 
                u.ismis_id, 
                cr.course_name, 
                col.college_name,
                ct.clearance_name as clearance_type,
                DATE_FORMAT(c.processed_date, '%M %d, %Y %h:%i %p') as formatted_date,
                c.lacking_comment,
                c.student_proof_file
              FROM clearance c
              JOIN users u ON c.users_id = u.users_id
              LEFT JOIN course cr ON u.course_id = cr.course_id
              LEFT JOIN college col ON u.college_id = col.college_id
              LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
              WHERE c.office_id = :office_id 
              AND c.processed_by = :librarian_id
              AND c.status IN ('approved', 'rejected')
              ORDER BY c.processed_date DESC");
    $db->bind(':office_id', $librarian_office_id);
    $db->bind(':librarian_id', $librarian_id);
    $approvals_to_undo = $db->resultSet();

    // Get clearance history
    $query = "SELECT 
                c.*, 
                u.fname, 
                u.lname, 
                u.ismis_id, 
                cr.course_name, 
                col.college_name,
                ct.clearance_name as clearance_type,
                p.fname as processed_fname, 
                p.lname as processed_lname,
                c.proof_file, 
                c.proof_remarks, 
                c.proof_uploaded_at,
                c.lacking_comment, 
                c.lacking_comment_at,
                c.student_proof_file,
                c.student_proof_remarks,
                lcb.fname as lacking_by_fname,
                lcb.lname as lacking_by_lname,
                pub.fname as proof_by_fname,
                pub.lname as proof_by_lname
              FROM clearance c
              JOIN users u ON c.users_id = u.users_id
              LEFT JOIN course cr ON u.course_id = cr.course_id
              LEFT JOIN college col ON u.college_id = col.college_id
              LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
              LEFT JOIN users p ON c.processed_by = p.users_id
              LEFT JOIN users lcb ON c.lacking_comment_by = lcb.users_id
              LEFT JOIN users pub ON c.proof_uploaded_by = pub.users_id
              WHERE c.office_id = :office_id";

    $params = [':office_id' => $librarian_office_id];

    if (!empty($filter_semester)) {
        $query .= " AND c.semester = :semester";
        $params[':semester'] = $filter_semester;
    }

    if (!empty($filter_school_year)) {
        $query .= " AND c.school_year = :school_year";
        $params[':school_year'] = $filter_school_year;
    }

    if (!empty($filter_type)) {
        $query .= " AND ct.clearance_name = :type";
        $params[':type'] = $filter_type;
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

    // Get all students
    $db->query("SELECT 
                u.users_id, 
                u.fname, 
                u.lname, 
                u.ismis_id, 
                u.course_id, 
                u.college_id, 
                u.address, 
                u.contacts, 
                u.age, 
                u.emails as email, 
                u.profile_picture,
                cr.course_name, 
                cr.course_code,
                col.college_name,
                COALESCE(ls.library_total_records, 0) as library_total_records,
                COALESCE(ls.library_pending_count, 0) as library_pending_count,
                COALESCE(ls.library_approved_count, 0) as library_approved_count,
                COALESCE(ls.library_rejected_count, 0) as library_rejected_count,
                ls.library_last_activity,
                (SELECT COUNT(*) FROM clearance c WHERE c.users_id = u.users_id AND c.status = 'approved') as approved_count
              FROM users u
              LEFT JOIN course cr ON u.course_id = cr.course_id
              LEFT JOIN college col ON u.college_id = col.college_id
              LEFT JOIN (
                    SELECT
                        users_id,
                        COUNT(*) as library_total_records,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as library_pending_count,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as library_approved_count,
                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as library_rejected_count,
                        MAX(COALESCE(updated_at, created_at)) as library_last_activity
                    FROM clearance
                    WHERE office_id = :student_library_office_id
                    GROUP BY users_id
              ) ls ON ls.users_id = u.users_id
              WHERE u.user_role_id = (SELECT user_role_id FROM user_role WHERE user_role_name = 'student')
              AND u.is_active = 1
              ORDER BY u.lname, u.fname");
    $db->bind(':student_library_office_id', $librarian_office_id);
    $students = $db->resultSet();

    foreach ($approvals_to_undo as $approval) {
        $undo_summary['total']++;

        if (($approval['status'] ?? '') === 'approved') {
            $undo_summary['approved']++;
        } elseif (($approval['status'] ?? '') === 'rejected') {
            $undo_summary['rejected']++;
        }

        if (!empty($approval['lacking_comment'])) {
            $undo_summary['with_lacking']++;
        }

        if (!empty($approval['student_proof_file'])) {
            $undo_summary['with_student_proof']++;
        }
    }

    foreach ($clearance_history as $history_item) {
        $history_summary['total']++;

        if (($history_item['status'] ?? '') === 'approved') {
            $history_summary['approved']++;
        } elseif (($history_item['status'] ?? '') === 'rejected') {
            $history_summary['rejected']++;
        } else {
            $history_summary['pending']++;
        }

        if (!empty($history_item['student_proof_file']) || !empty($history_item['proof_file'])) {
            $history_summary['proof_tracked']++;
        }
    }

    foreach ($students as $student) {
        $student_summary['total']++;

        if (($student['library_total_records'] ?? 0) > 0) {
            $student_summary['with_library_records']++;
        }

        if (($student['library_pending_count'] ?? 0) > 0) {
            $student_summary['with_pending_library']++;
        }

        if (($student['library_approved_count'] ?? 0) > 0) {
            $student_summary['with_approved_library']++;
        }

        if (!empty($student['course_id']) && !empty($student['course_name'])) {
            $student_course_filters[(int) $student['course_id']] = $student['course_name'];
        }
    }

    if (!empty($student_course_filters)) {
        natcasesort($student_course_filters);
    }

    // Get distinct semesters and school years for filters
    $db->query("SELECT DISTINCT semester FROM clearance WHERE semester IS NOT NULL ORDER BY semester");
    $stats['semesters'] = $db->resultSet();

    $db->query("SELECT DISTINCT school_year FROM clearance WHERE school_year IS NOT NULL ORDER BY school_year DESC");
    $stats['school_years'] = $db->resultSet();

    $db->query("SELECT DISTINCT clearance_name FROM clearance_type");
    $stats['clearance_types'] = $db->resultSet();

    // Get profile picture
    $db->query("SELECT profile_picture FROM users WHERE users_id = :user_id");
    $db->bind(':user_id', $librarian_id);
    $user_data = $db->single();
    $profile_pic = $user_data ? $user_data['profile_picture'] : null;

    // Get recent activity logs
    if (class_exists('ActivityLogModel')) {
        $logModel = new ActivityLogModel();
        $stats['recent_activities'] = $logModel->getRecent(10);
    } else {
        $stats['recent_activities'] = [];
    }

} catch (Exception $e) {
    error_log("Error fetching librarian data: " . $e->getMessage());
    $error = "Error loading dashboard data: " . $e->getMessage();
}

$dashboard_name_parts = preg_split('/\s+/', trim((string) $librarian_name));
$dashboard_first_name = $dashboard_name_parts[0] ?? 'Librarian';
$dashboard_focus_items = array_slice($pending_clearances, 0, 4);
$dashboard_recent_processed = array_slice($recent_clearances, 0, 5);
$dashboard_recent_activity = array_slice($stats['recent_activities'] ?? [], 0, 5);

// Helper functions
function getOfficeIcon($office_name)
{
    $icons = [
        'Librarian' => 'book',
        'Director_SAS' => 'users',
        'Dean' => 'chalkboard-teacher',
        'Cashier' => 'coins',
        'Registrar' => 'clipboard-list'
    ];
    return $icons[$office_name] ?? 'building';
}

function getOfficeDisplayName($office_name)
{
    return str_replace('_', ' ', $office_name);
}

function getStatusClass($status)
{
    return $status == 'approved' ? 'status-approved' : ($status == 'rejected' ? 'status-rejected' : 'status-pending');
}

function getActivityIcon($action)
{
    $icons = [
        'LOGIN' => 'sign-in-alt',
        'LOGOUT' => 'sign-out-alt',
        'APPROVE_CLEARANCE' => 'check-circle',
        'REJECT_CLEARANCE' => 'times-circle',
        'ADD_LACKING' => 'exclamation-circle',
        'CLEAR_LACKING' => 'check-double',
        'UNDO_CLEARANCE' => 'undo-alt',
        'UPLOAD_PROOF' => 'paperclip'
    ];

    return $icons[$action] ?? 'circle';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Librarian Dashboard - BISU Online Clearance</title>
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
            --primary-dark: #096348;
            --primary-light: #0e9e72;
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
            --header-bg: linear-gradient(135deg, #0b7d5a 0%, #0e9e72 100%);
            --sidebar-bg: #ffffff;
            --input-bg: #ffffff;
            --input-border: #e2e8f0;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --undo: #8b5cf6;
            --lacking: #f97316;
            --proof: #0ea5e9;
            --success-soft: rgba(16, 185, 129, 0.1);
            --warning-soft: rgba(245, 158, 11, 0.1);
            --danger-soft: rgba(239, 68, 68, 0.1);
            --info-soft: rgba(59, 130, 246, 0.1);
            --undo-soft: rgba(139, 92, 246, 0.1);
            --lacking-soft: rgba(249, 115, 22, 0.1);
            --proof-soft: rgba(14, 165, 233, 0.1);
        }

        .dark-mode {
            --primary: #0e9e72;
            --primary-dark: #0b7d5a;
            --primary-light: #10b981;
            --primary-soft: rgba(14, 158, 114, 0.15);
            --primary-glow: rgba(14, 158, 114, 0.25);
            --bg-primary: #1a1b2f;
            --bg-secondary: #22243e;
            --bg-tertiary: #2a2c4a;
            --text-primary: #f0f1fa;
            --text-secondary: #cbd5e0;
            --text-muted: #a0a8b8;
            --border-color: #2d2f4a;
            --card-bg: #22243e;
            --card-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            --card-shadow-hover: 0 10px 30px rgba(14, 158, 114, 0.15);
            --header-bg: linear-gradient(135deg, #0b7d5a 0%, #096348 100%);
            --sidebar-bg: #22243e;
            --input-bg: #2a2c4a;
            --input-border: #3d3f60;
            --success: #4ade80;
            --warning: #fbbf24;
            --danger: #f87171;
            --info: #60a5fa;
            --undo: #c4b5fd;
            --lacking: #fdba74;
            --proof: #7dd3fc;
            --success-soft: rgba(74, 222, 128, 0.1);
            --warning-soft: rgba(251, 191, 36, 0.1);
            --danger-soft: rgba(248, 113, 113, 0.1);
            --info-soft: rgba(96, 165, 250, 0.1);
            --undo-soft: rgba(139, 92, 246, 0.15);
            --lacking-soft: rgba(253, 186, 116, 0.15);
            --proof-soft: rgba(125, 211, 252, 0.15);
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

        .office-badge {
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

        .office-info {
            background: rgba(255, 255, 255, 0.15);
            padding: 10px 25px;
            border-radius: 40px;
            display: inline-block;
            margin-top: 15px;
            backdrop-filter: blur(10px);
            position: relative;
        }

        .dashboard-kicker {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.16);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 14px;
        }

        .dashboard-hero {
            display: grid;
            grid-template-columns: minmax(0, 1.45fr) minmax(320px, 0.95fr);
            gap: 22px;
            margin-bottom: 30px;
        }

        .dashboard-hero .welcome-banner {
            margin-bottom: 0;
        }

        .dashboard-chip-row {
            position: relative;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .dashboard-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.15);
            color: white;
            font-size: 0.88rem;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }

        .dashboard-chip.warning {
            background: rgba(245, 158, 11, 0.22);
        }

        .dashboard-hero-actions {
            position: relative;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 22px;
        }

        .dashboard-hero-panel {
            background: linear-gradient(180deg, var(--card-bg) 0%, var(--bg-secondary) 100%);
            border: 1px solid var(--border-color);
            border-radius: 22px;
            padding: 24px;
            box-shadow: var(--card-shadow);
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .dashboard-panel-title {
            color: var(--text-primary);
            font-size: 1.15rem;
            font-weight: 700;
        }

        .dashboard-panel-subtitle {
            color: var(--text-secondary);
            font-size: 0.92rem;
            line-height: 1.5;
            margin-top: -8px;
        }

        .dashboard-panel-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .dashboard-panel-item {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 16px;
            display: flex;
            align-items: flex-start;
            gap: 14px;
        }

        .dashboard-panel-icon {
            width: 40px;
            height: 40px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .dashboard-panel-icon.ready {
            background: var(--success-soft);
            color: var(--success);
        }

        .dashboard-panel-icon.followup {
            background: var(--proof-soft);
            color: var(--proof);
        }

        .dashboard-panel-icon.awaiting {
            background: var(--lacking-soft);
            color: var(--lacking);
        }

        .dashboard-panel-icon.undo {
            background: var(--undo-soft);
            color: var(--undo);
        }

        .dashboard-panel-content strong {
            display: block;
            color: var(--text-primary);
            font-size: 1rem;
            margin-bottom: 4px;
        }

        .dashboard-panel-content span,
        .dashboard-panel-content small {
            display: block;
            color: var(--text-secondary);
            line-height: 1.45;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
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

        .stat-icon.ready {
            background: var(--success-soft);
            color: var(--success);
        }

        .stat-icon.awaiting {
            background: var(--lacking-soft);
            color: var(--lacking);
        }

        .stat-icon.complied {
            background: var(--proof-soft);
            color: var(--proof);
        }

        .stat-icon.records {
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

        .stat-details small {
            display: block;
            color: var(--text-muted);
            font-size: 0.8rem;
            margin-top: 6px;
            line-height: 1.4;
        }

        .dashboard-split-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(0, 0.95fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .dashboard-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .dashboard-list-item {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .dashboard-list-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .dashboard-list-title {
            color: var(--text-primary);
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.35;
        }

        .dashboard-list-subtitle {
            color: var(--text-secondary);
            font-size: 0.86rem;
            margin-top: 4px;
        }

        .dashboard-list-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 14px;
            color: var(--text-secondary);
            font-size: 0.84rem;
        }

        .dashboard-list-meta span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .dashboard-list-item .lacking-badge,
        .dashboard-list-item .proof-badge {
            cursor: default;
        }

        .dashboard-action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .dashboard-action-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 18px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .dashboard-action-value {
            color: var(--text-primary);
            font-size: 1.75rem;
            font-weight: 700;
            line-height: 1;
        }

        .dashboard-action-title {
            color: var(--text-primary);
            font-size: 0.96rem;
            font-weight: 700;
        }

        .dashboard-action-meta {
            color: var(--text-secondary);
            font-size: 0.85rem;
            line-height: 1.5;
        }

        .dashboard-search-panel {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 18px;
        }

        .dashboard-search-panel h3 {
            color: var(--text-primary);
            font-size: 1.05rem;
            margin-bottom: 8px;
        }

        .dashboard-search-panel p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 14px;
        }

        .dashboard-search-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .dashboard-search-row .filter-input {
            min-width: 220px;
        }

        .dashboard-shortcuts {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }

        .activity-log {
            display: flex;
            flex-direction: column;
        }

        .activity-item {
            padding: 15px 0;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .activity-item:first-child {
            padding-top: 0;
        }

        .activity-item:last-child {
            border-bottom: none;
            padding-bottom: 0;
        }

        .activity-icon {
            width: 42px;
            height: 42px;
            background: var(--primary-soft);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            flex-shrink: 0;
        }

        .activity-details {
            flex: 1;
            min-width: 0;
        }

        .activity-action {
            color: var(--text-primary);
            font-weight: 700;
            margin-bottom: 4px;
        }

        .activity-meta {
            color: var(--text-secondary);
            font-size: 0.84rem;
            line-height: 1.45;
        }

        .activity-time {
            color: var(--text-muted);
            font-size: 0.8rem;
            white-space: nowrap;
            text-align: right;
        }

        .dashboard-empty-state {
            background: var(--bg-secondary);
            border: 1px dashed var(--border-color);
            border-radius: 18px;
            padding: 26px 20px;
            text-align: center;
            color: var(--text-secondary);
        }

        .dashboard-empty-state i {
            font-size: 2rem;
            opacity: 0.45;
            margin-bottom: 10px;
        }

        .dashboard-empty-state h4 {
            color: var(--text-primary);
            margin-bottom: 6px;
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

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 14px;
            margin: 18px 0 22px;
        }

        .summary-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 18px;
            box-shadow: var(--card-shadow);
        }

        .summary-card .label {
            color: var(--text-secondary);
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .summary-card .value {
            color: var(--text-primary);
            font-size: 1.85rem;
            font-weight: 700;
            line-height: 1;
        }

        .summary-card .meta {
            color: var(--text-muted);
            font-size: 0.85rem;
            margin-top: 8px;
        }

        .section-tools {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin: 16px 0 18px;
        }

        .filter-meta {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .pending-bulk-panel {
            margin-bottom: 20px;
            padding: 16px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: 14px;
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .pending-selection-chip {
            background: var(--primary-soft);
            color: var(--primary);
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 0.9rem;
            font-weight: 700;
        }

        .pending-status-stack {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 6px;
        }

        .pending-state-badge {
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .pending-state-badge.review {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .pending-state-badge.awaiting {
            background: var(--lacking-soft);
            color: var(--lacking);
        }

        .pending-state-badge.proof {
            background: var(--proof-soft);
            color: var(--proof);
        }

        .pending-state-badge.complied {
            background: var(--success-soft);
            color: var(--success);
        }

        .pending-meta {
            color: var(--text-muted);
            font-size: 0.82rem;
            line-height: 1.45;
            margin-top: 6px;
        }

        .pending-table {
            border-collapse: separate;
            border-spacing: 0 12px;
        }

        .pending-table thead th {
            background: transparent;
            color: var(--text-secondary);
            font-size: 0.78rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            padding: 0 14px 8px;
        }

        .pending-table tbody td {
            background: var(--card-bg);
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
            padding: 16px 14px;
            vertical-align: top;
        }

        .pending-table tbody td:first-child {
            border-left: 1px solid var(--border-color);
            border-top-left-radius: 18px;
            border-bottom-left-radius: 18px;
            width: 52px;
        }

        .pending-table tbody td:last-child {
            border-right: 1px solid var(--border-color);
            border-top-right-radius: 18px;
            border-bottom-right-radius: 18px;
        }

        .pending-table tbody tr:hover td {
            border-color: rgba(11, 125, 90, 0.25);
            box-shadow: 0 10px 30px rgba(11, 125, 90, 0.06);
        }

        .pending-row-review td:first-child {
            box-shadow: inset 4px 0 0 var(--warning);
        }

        .pending-row-awaiting td:first-child {
            box-shadow: inset 4px 0 0 var(--lacking);
        }

        .pending-row-proof td:first-child {
            box-shadow: inset 4px 0 0 var(--proof);
        }

        .pending-row-complied td:first-child {
            box-shadow: inset 4px 0 0 var(--success);
        }

        .pending-student-cell {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 220px;
        }

        .pending-student-name {
            color: var(--text-primary);
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.3;
        }

        .pending-subline {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        .pending-subline span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .pending-detail-stack {
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-width: 210px;
        }

        .pending-chip-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .period-badge {
            background: var(--bg-secondary);
            color: var(--text-secondary);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            border: 1px solid var(--border-color);
        }

        .pending-link-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .pending-link-row .lacking-badge,
        .pending-link-row .proof-badge {
            border: none;
            font-family: inherit;
        }

        .pending-action-stack {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            min-width: 220px;
        }

        .pending-action-form {
            display: inline-flex;
        }

        .pending-action-btn {
            border: 1px solid var(--border-color);
            background: var(--bg-secondary);
            color: var(--text-primary);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 0.82rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            transition: 0.2s ease;
            white-space: nowrap;
        }

        .pending-action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
        }

        .pending-action-btn.approve {
            background: var(--success-soft);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.35);
        }

        .pending-action-btn.lacking {
            background: var(--lacking-soft);
            color: var(--lacking);
            border-color: rgba(249, 115, 22, 0.35);
        }

        .pending-action-btn.proof {
            background: var(--proof-soft);
            color: var(--proof);
            border-color: rgba(14, 165, 233, 0.35);
        }

        .pending-action-btn.resolve {
            background: var(--success-soft);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.35);
        }

        .pending-action-btn.neutral {
            background: var(--bg-secondary);
            color: var(--text-primary);
        }

        .filter-empty-state {
            display: none;
            margin-top: 18px;
            padding: 30px 22px;
            border: 1px dashed var(--border-color);
            border-radius: 16px;
            text-align: center;
            color: var(--text-secondary);
            background: var(--bg-secondary);
        }

        .filter-empty-state.show {
            display: block;
        }

        .filter-empty-state i {
            font-size: 2rem;
            margin-bottom: 10px;
            opacity: 0.45;
        }

        .filter-empty-state h4 {
            color: var(--text-primary);
            margin-bottom: 6px;
        }

        .muted-inline {
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        .undo-card {
            background: var(--undo-soft);
            border: 1px solid var(--undo);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .undo-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            color: var(--undo);
        }

        .undo-header i {
            font-size: 1.5rem;
        }

        .undo-header h3 {
            font-size: 1.2rem;
            font-weight: 600;
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
            flex-direction: column;
            align-items: stretch;
            gap: 14px;
            transition: 0.3s;
        }

        .undo-item:hover {
            border-color: var(--undo);
            box-shadow: 0 5px 15px var(--undo-soft);
        }

        .undo-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
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

        .undo-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .undo-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
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

        .lacking-badge {
            background: var(--lacking-soft);
            color: var(--lacking);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
        }

        .proof-badge {
            background: var(--proof-soft);
            color: var(--proof);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
        }

        .progress-badge {
            background: var(--info-soft);
            color: var(--info);
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

        .action-btn.lacking {
            background: var(--lacking-soft);
            color: var(--lacking);
        }

        .action-btn.view-proof {
            background: var(--proof-soft);
            color: var(--proof);
        }

        .action-btn.view {
            background: var(--info-soft);
            color: var(--info);
        }

        .action-btn.undo-action {
            background: var(--undo-soft);
            color: var(--undo);
        }

        .action-btn.clear-lacking {
            background: var(--lacking-soft);
            color: var(--lacking);
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

        .form-group input[type="file"] {
            padding: 10px;
            background: var(--bg-secondary);
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

        .btn-lacking {
            background: var(--lacking);
            color: white;
        }

        .btn-lacking:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(249, 115, 22, 0.3);
        }

        .btn-proof {
            background: var(--proof);
            color: white;
        }

        .btn-proof:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(14, 165, 233, 0.3);
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
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
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
            width: 60px;
            height: 60px;
            background: var(--primary-soft);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 1.8rem;
            overflow: hidden;
        }

        .student-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .student-info {
            flex: 1;
        }

        .student-info h4 {
            color: var(--text-primary);
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .student-info p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 3px;
        }

        .student-badges {
            display: flex;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .student-metrics {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-top: 14px;
        }

        .student-metric {
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 12px;
        }

        .student-metric .metric-label {
            display: block;
            color: var(--text-secondary);
            font-size: 0.78rem;
            margin-bottom: 6px;
        }

        .student-metric .metric-value {
            display: block;
            color: var(--text-primary);
            font-size: 1.15rem;
            font-weight: 700;
        }

        .student-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .student-last-activity {
            color: var(--text-muted);
            font-size: 0.82rem;
        }

        .record-flag-list {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .compact-table th,
        .compact-table td {
            padding: 12px;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }

        .badge-primary {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .badge-info {
            background: var(--info-soft);
            color: var(--info);
        }

        .badge-success {
            background: var(--success-soft);
            color: var(--success);
        }

        .badge-undo {
            background: var(--undo-soft);
            color: var(--undo);
        }

        .badge-lacking {
            background: var(--lacking-soft);
            color: var(--lacking);
        }

        .badge-proof {
            background: var(--proof-soft);
            color: var(--proof);
        }

        .badge-warning {
            background: var(--warning-soft);
            color: var(--warning);
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

        .file-info {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            background: var(--bg-secondary);
            border-radius: 8px;
            margin-top: 10px;
        }

        .file-info i {
            font-size: 1.5rem;
            color: var(--primary);
        }

        .file-info a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .file-info a:hover {
            text-decoration: underline;
        }

        .proof-preview {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            margin-top: 10px;
            cursor: pointer;
        }

        .proof-preview:hover {
            opacity: 0.9;
        }

        .required {
            color: var(--danger);
        }

        .resolution-badge {
            background: var(--success-soft);
            color: var(--success);
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 5px;
        }

        @media (max-width: 1024px) {
            .dashboard-hero,
            .dashboard-split-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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

            .dashboard-hero-actions,
            .dashboard-search-row,
            .dashboard-shortcuts {
                flex-direction: column;
            }

            .dashboard-hero-actions .btn,
            .dashboard-search-row .btn,
            .dashboard-shortcuts .btn {
                width: 100%;
                justify-content: center;
            }

            .dashboard-search-row .filter-input {
                width: 100%;
            }

            .dashboard-list-top,
            .activity-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .activity-time {
                text-align: left;
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

            .undo-grid {
                grid-template-columns: 1fr;
            }

            .summary-grid {
                grid-template-columns: 1fr 1fr;
            }

            .undo-top {
                flex-direction: column;
            }

            .student-metrics {
                grid-template-columns: 1fr;
            }

            .pending-table {
                min-width: 940px;
            }

            .pending-action-stack {
                min-width: 180px;
            }
        }

        @media (max-width: 480px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(versionedUrl('assets/img/favicon.png'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="manifest" href="<?php echo htmlspecialchars(versionedUrl('manifest.webmanifest'), ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="theme-color" content="#412886">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="BISU Clearance">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars(versionedUrl('assets/img/pwa-icon-192.png'), ENT_QUOTES, 'UTF-8'); ?>">
    <script defer src="<?php echo htmlspecialchars(versionedUrl('assets/js/pwa-register.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</head>

<body>
    <div class="theme-toggle" id="themeToggle">
        <i class="fas fa-moon" id="themeIcon"></i>
    </div>

    <header class="header">
        <div class="header-content">
            <div class="logo">
                <button class="menu-toggle" id="menuToggle" type="button" aria-label="Toggle navigation" aria-expanded="false" aria-controls="subAdminSidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="logo-icon">
                    <i class="fas fa-book"></i>
                </div>
                <h2>Librarian Dashboard</h2>
            </div>
            <div class="user-menu">
                <div class="user-info">
                    <div class="user-avatar">
                        <?php if (!empty($profile_pic) && file_exists('../' . $profile_pic)): ?>
                            <img src="../<?php echo $profile_pic; ?>" alt="Profile">
                        <?php else: ?>
                            <i class="fas fa-user-tie"></i>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name">
                            <?php echo htmlspecialchars($librarian_name); ?>
                        </div>
                        <div class="user-role">Librarian</div>
                    </div>
                </div>
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>

    <div class="main-container">
        <aside class="sidebar" id="subAdminSidebar">
            <div class="profile-section">
                <div class="profile-avatar" id="avatarContainer">
                    <?php if (!empty($profile_pic) && file_exists('../' . $profile_pic)): ?>
                        <img src="../<?php echo $profile_pic; ?>" alt="Profile" id="profileImage">
                    <?php else: ?>
                        <i class="fas fa-user-tie" id="avatarIcon" style="font-size: 3rem; line-height: 100px;"></i>
                        <img src="" alt="Profile" id="profileImage" style="display: none;">
                    <?php endif; ?>
                    <div class="avatar-overlay" id="avatarOverlay">
                        <i class="fas fa-camera"></i>
                    </div>
                    <div class="upload-progress" id="uploadProgress">
                        <i class="fas fa-spinner fa-spin"></i>
                    </div>
                </div>
                <input type="file" id="avatarUpload" accept="image/jpeg,image/png,image/gif" style="display: none;">

                <div class="profile-name">
                    <?php echo htmlspecialchars($librarian_name); ?>
                </div>
                <div class="profile-email">
                    <?php echo htmlspecialchars($librarian_email); ?>
                </div>
                <div class="profile-badge">Librarian</div>
                <div class="office-badge"><i class="fas fa-book-open"></i> Library Office</div>
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
                <button class="nav-item <?php echo $active_tab == 'undo' ? 'active' : ''; ?>"
                    onclick="switchTab('undo')">
                    <i class="fas fa-undo-alt"></i> Undo Approvals
                    <?php if (!empty($approvals_to_undo)): ?>
                        <span
                            style="margin-left: auto; background: var(--undo); color: white; padding: 2px 8px; border-radius: 20px; font-size: 0.8rem;">
                            <?php echo count($approvals_to_undo); ?>
                        </span>
                    <?php endif; ?>
                </button>
                <button class="nav-item <?php echo $active_tab == 'history' ? 'active' : ''; ?>"
                    onclick="switchTab('history')">
                    <i class="fas fa-history"></i> Clearance History
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
                <div class="dashboard-hero">
                    <div class="welcome-banner">
                        <div class="dashboard-kicker">
                            <i class="fas fa-book-reader"></i> Library Control Center
                        </div>
                        <h1>Welcome back, <?php echo htmlspecialchars($dashboard_first_name); ?>.</h1>
                        <p>
                            <?php if (($pending_summary['total'] ?? 0) > 0): ?>
                                You have <?php echo $pending_summary['total']; ?> clearance request(s) in the library queue,
                                and <?php echo $pending_summary['ready_for_bulk']; ?> can move forward right now.
                            <?php else: ?>
                                Your library queue is clear right now. Use the records and history tabs to review recent
                                activity and student progress.
                            <?php endif; ?>
                        </p>
                        <div class="dashboard-chip-row">
                            <span class="dashboard-chip">
                                <i class="fas fa-calendar-alt"></i>
                                <?php echo date('l, M d, Y'); ?>
                            </span>
                            <span class="dashboard-chip">
                                <i class="fas fa-clock"></i>
                                <?php echo $pending_summary['total']; ?> pending
                            </span>
                            <span class="dashboard-chip">
                                <i class="fas fa-bolt"></i>
                                <?php echo $pending_summary['ready_for_bulk']; ?> ready now
                            </span>
                            <span class="dashboard-chip warning">
                                <i class="fas fa-user-clock"></i>
                                <?php echo $pending_summary['awaiting_student']; ?> awaiting students
                            </span>
                        </div>
                        <div class="dashboard-hero-actions">
                            <button class="btn btn-primary" onclick="switchTab('pending')">
                                <i class="fas fa-clock"></i> Open Pending Queue
                            </button>
                            <button class="btn btn-secondary" onclick="switchTab('history')">
                                <i class="fas fa-history"></i> Review History
                            </button>
                            <button class="btn btn-secondary" onclick="switchTab('students')">
                                <i class="fas fa-users"></i> Browse Students
                            </button>
                        </div>
                    </div>

                    <div class="dashboard-hero-panel">
                        <div class="dashboard-panel-title">Today's Focus</div>
                        <div class="dashboard-panel-subtitle">Start with the clearest next actions in the library queue.</div>

                        <div class="dashboard-panel-list">
                            <div class="dashboard-panel-item">
                                <div class="dashboard-panel-icon ready">
                                    <i class="fas fa-check"></i>
                                </div>
                                <div class="dashboard-panel-content">
                                    <strong><?php echo $pending_summary['ready_for_bulk']; ?> ready to approve</strong>
                                    <span>These rows have no blocking lacking issue and can move forward after review.</span>
                                </div>
                            </div>

                            <div class="dashboard-panel-item">
                                <div class="dashboard-panel-icon followup">
                                    <i class="fas fa-paperclip"></i>
                                </div>
                                <div class="dashboard-panel-content">
                                    <strong><?php echo $pending_summary['complied']; ?> complied follow-up(s)</strong>
                                    <span>Students responded to a lacking notice and are waiting for librarian confirmation.</span>
                                </div>
                            </div>

                            <div class="dashboard-panel-item">
                                <div class="dashboard-panel-icon awaiting">
                                    <i class="fas fa-user-clock"></i>
                                </div>
                                <div class="dashboard-panel-content">
                                    <strong><?php echo $pending_summary['awaiting_student']; ?> waiting on students</strong>
                                    <span>These requests stay locked until proof is uploaded by the student.</span>
                                </div>
                            </div>

                            <div class="dashboard-panel-item">
                                <div class="dashboard-panel-icon undo">
                                    <i class="fas fa-undo-alt"></i>
                                </div>
                                <div class="dashboard-panel-content">
                                    <strong><?php echo $undo_summary['total']; ?> undo option(s)</strong>
                                    <span>Processed clearances can still be reverted if you need to correct a decision.</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon pending">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $pending_summary['total']; ?></h3>
                            <p>Pending Queue</p>
                            <small>All requests currently waiting for a library action.</small>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon ready">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $pending_summary['ready_for_bulk']; ?></h3>
                            <p>Ready To Approve</p>
                            <small>Rows eligible for quick approval after you review them.</small>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon awaiting">
                            <i class="fas fa-user-clock"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $pending_summary['awaiting_student']; ?></h3>
                            <p>Awaiting Student</p>
                            <small>Requests blocked until students upload missing proof.</small>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon complied">
                            <i class="fas fa-check-double"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $pending_summary['complied']; ?></h3>
                            <p>Complied Follow-Up</p>
                            <small>Students answered a lacking notice and need librarian review.</small>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon approved">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $stats['approved'] ?? 0; ?></h3>
                            <p>Approved By Library</p>
                            <small>Total library approvals already processed.</small>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon records">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $student_summary['with_library_records']; ?></h3>
                            <p>Students With Records</p>
                            <small>Students who already have clearance activity at the library.</small>
                        </div>
                    </div>
                </div>

                <div class="dashboard-split-grid">
                    <div class="section-card">
                        <div class="section-header">
                            <h2><i class="fas fa-bolt"></i> Queue Spotlight</h2>
                            <button class="btn btn-secondary" onclick="switchTab('pending')">
                                <i class="fas fa-arrow-right"></i> Manage Queue
                            </button>
                        </div>

                        <?php if (!empty($dashboard_focus_items)): ?>
                            <div class="dashboard-list">
                                <?php foreach ($dashboard_focus_items as $clearance): ?>
                                    <?php
                                    $has_lacking = !empty($clearance['lacking_comment']);
                                    $has_student_proof = !empty($clearance['student_proof_file']);
                                    $dashboard_state_label = 'Needs Review';
                                    $dashboard_state_class = 'review';

                                    if ($has_lacking && $has_student_proof) {
                                        $dashboard_state_label = 'Complied';
                                        $dashboard_state_class = 'complied';
                                    } elseif ($has_lacking) {
                                        $dashboard_state_label = 'Awaiting Student';
                                        $dashboard_state_class = 'awaiting';
                                    } elseif ($has_student_proof) {
                                        $dashboard_state_label = 'Proof Submitted';
                                        $dashboard_state_class = 'proof';
                                    }

                                    if (!empty($clearance['student_proof_uploaded_at'])) {
                                        $dashboard_activity_text = 'Proof uploaded ' . date('M d, Y h:i A', strtotime($clearance['student_proof_uploaded_at']));
                                    } elseif (!empty($clearance['lacking_comment_at'])) {
                                        $dashboard_activity_text = 'Marked lacking ' . date('M d, Y h:i A', strtotime($clearance['lacking_comment_at']));
                                    } elseif (!empty($clearance['created_at'])) {
                                        $dashboard_activity_text = 'Submitted ' . date('M d, Y h:i A', strtotime($clearance['created_at']));
                                    } else {
                                        $dashboard_activity_text = 'Recently submitted';
                                    }

                                    $dashboard_period_label = trim(($clearance['semester'] ?? '') . ' ' . ($clearance['school_year'] ?? ''));
                                    ?>
                                    <div class="dashboard-list-item">
                                        <div class="dashboard-list-top">
                                            <div>
                                                <div class="dashboard-list-title">
                                                    <?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?>
                                                </div>
                                                <div class="dashboard-list-subtitle">
                                                    <?php echo htmlspecialchars($clearance['ismis_id']); ?> -
                                                    <?php echo htmlspecialchars($clearance['course_name'] ?? 'N/A'); ?>
                                                </div>
                                            </div>
                                            <span class="pending-state-badge <?php echo $dashboard_state_class; ?>">
                                                <?php echo $dashboard_state_label; ?>
                                            </span>
                                        </div>

                                        <div class="pending-chip-row">
                                            <span class="type-badge"><?php echo ucfirst($clearance['clearance_type']); ?></span>
                                            <?php if (!empty($dashboard_period_label)): ?>
                                                <span class="period-badge"><?php echo htmlspecialchars($dashboard_period_label); ?></span>
                                            <?php endif; ?>
                                            <span class="progress-badge">
                                                <?php echo ($clearance['approved_count'] ?? 0) . '/' . ($clearance['total_count'] ?? 5); ?>
                                                Approved
                                            </span>
                                            <?php if ($has_lacking): ?>
                                                <span class="lacking-badge"><i class="fas fa-exclamation-triangle"></i> Lacking Notice</span>
                                            <?php endif; ?>
                                            <?php if ($has_student_proof): ?>
                                                <span class="proof-badge"><i class="fas fa-paperclip"></i> Proof Attached</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="dashboard-list-meta">
                                            <span><i class="fas fa-hourglass-half"></i> <?php echo htmlspecialchars($dashboard_activity_text); ?></span>
                                            <span><i class="fas fa-sitemap"></i>
                                                <?php echo max(0, ((int) ($clearance['total_count'] ?? 0)) - ((int) ($clearance['approved_count'] ?? 0))); ?>
                                                office(s) still pending this period</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="dashboard-empty-state">
                                <i class="fas fa-check-circle"></i>
                                <h4>No pending clearances right now</h4>
                                <p>The library queue is clear. New requests will appear here as they arrive.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="section-card">
                        <div class="section-header">
                            <h2><i class="fas fa-compass"></i> Action Center</h2>
                        </div>

                        <div class="dashboard-action-grid">
                            <div class="dashboard-action-card">
                                <div class="dashboard-action-value"><?php echo $pending_summary['proof_submitted']; ?></div>
                                <div class="dashboard-action-title">Proof To Review</div>
                                <div class="dashboard-action-meta">Student uploads that need a librarian check before you decide.</div>
                                <button class="btn btn-secondary" onclick="switchTab('pending')">
                                    <i class="fas fa-eye"></i> Review Queue
                                </button>
                            </div>

                            <div class="dashboard-action-card">
                                <div class="dashboard-action-value"><?php echo $undo_summary['total']; ?></div>
                                <div class="dashboard-action-title">Undo Available</div>
                                <div class="dashboard-action-meta">Processed clearances that can still be reverted if needed.</div>
                                <button class="btn btn-secondary" onclick="switchTab('undo')">
                                    <i class="fas fa-undo-alt"></i> Open Undo
                                </button>
                            </div>

                            <div class="dashboard-action-card">
                                <div class="dashboard-action-value"><?php echo $student_summary['with_pending_library']; ?></div>
                                <div class="dashboard-action-title">Students Waiting</div>
                                <div class="dashboard-action-meta">Students with at least one pending library record.</div>
                                <button class="btn btn-secondary" onclick="switchTab('students')">
                                    <i class="fas fa-users"></i> Student Records
                                </button>
                            </div>
                        </div>

                        <div class="dashboard-search-panel">
                            <h3><i class="fas fa-search"></i> Quick Student Search</h3>
                            <p>Jump straight into the student records area using a name or ISMIS ID.</p>
                            <div class="dashboard-search-row">
                                <input type="text" class="filter-input" id="quickSearch"
                                    placeholder="Enter student name or ISMIS ID..." style="flex: 1;">
                                <button class="btn btn-primary" onclick="searchStudent()">
                                    <i class="fas fa-search"></i> Search Student
                                </button>
                            </div>
                            <div class="dashboard-shortcuts">
                                <button class="btn btn-secondary" onclick="switchTab('pending')">
                                    <i class="fas fa-clock"></i> Pending
                                </button>
                                <button class="btn btn-secondary" onclick="switchTab('history')">
                                    <i class="fas fa-history"></i> History
                                </button>
                                <button class="btn btn-secondary" onclick="switchTab('students')">
                                    <i class="fas fa-address-card"></i> Records
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dashboard-split-grid">
                    <div class="section-card">
                        <div class="section-header">
                            <h2><i class="fas fa-check-circle"></i> Recent Library Decisions</h2>
                            <button class="btn btn-secondary" onclick="switchTab('history')">
                                <i class="fas fa-arrow-right"></i> See History
                            </button>
                        </div>

                        <?php if (!empty($dashboard_recent_processed)): ?>
                            <div class="dashboard-list">
                                <?php foreach ($dashboard_recent_processed as $clearance): ?>
                                    <?php $processed_period = trim(($clearance['semester'] ?? '') . ' ' . ($clearance['school_year'] ?? '')); ?>
                                    <div class="dashboard-list-item">
                                        <div class="dashboard-list-top">
                                            <div>
                                                <div class="dashboard-list-title">
                                                    <?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?>
                                                </div>
                                                <div class="dashboard-list-subtitle">
                                                    <?php echo htmlspecialchars($clearance['ismis_id']); ?> -
                                                    <?php echo htmlspecialchars($clearance['course_name'] ?? 'N/A'); ?>
                                                </div>
                                            </div>
                                            <span class="status-badge <?php echo getStatusClass($clearance['status']); ?>">
                                                <?php echo ucfirst($clearance['status']); ?>
                                            </span>
                                        </div>

                                        <div class="pending-chip-row">
                                            <span class="type-badge"><?php echo ucfirst($clearance['clearance_type']); ?></span>
                                            <?php if (!empty($processed_period)): ?>
                                                <span class="period-badge"><?php echo htmlspecialchars($processed_period); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($clearance['lacking_comment'])): ?>
                                                <span class="lacking-badge"><i class="fas fa-comment"></i> Lacking Logged</span>
                                            <?php endif; ?>
                                            <?php if (!empty($clearance['student_proof_file'])): ?>
                                                <span class="proof-badge"><i class="fas fa-paperclip"></i> Student Proof</span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="dashboard-list-meta">
                                            <span><i class="fas fa-calendar-check"></i>
                                                <?php echo isset($clearance['processed_date']) ? date('M d, Y h:i A', strtotime($clearance['processed_date'])) : 'Not processed'; ?></span>
                                            <span><i class="fas fa-user"></i>
                                                <?php echo htmlspecialchars(trim(($clearance['processed_fname'] ?? '') . ' ' . ($clearance['processed_lname'] ?? '')) ?: 'Library office'); ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="dashboard-empty-state">
                                <i class="fas fa-history"></i>
                                <h4>No processed decisions yet</h4>
                                <p>Approved and rejected library actions will appear here once processing starts.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="section-card">
                        <div class="section-header">
                            <h2><i class="fas fa-history"></i> Office Activity</h2>
                        </div>

                        <?php if (!empty($dashboard_recent_activity)): ?>
                            <div class="activity-log">
                                <?php foreach ($dashboard_recent_activity as $activity): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <i class="fas fa-<?php echo getActivityIcon($activity['action'] ?? ''); ?>"></i>
                                        </div>
                                        <div class="activity-details">
                                            <div class="activity-action">
                                                <?php echo htmlspecialchars($activity['action'] ?? 'Activity'); ?>
                                            </div>
                                            <div class="activity-meta">
                                                <?php echo htmlspecialchars($activity['description'] ?? 'No description available.'); ?>
                                            </div>
                                            <div class="activity-meta">
                                                By: <?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?>
                                            </div>
                                        </div>
                                        <div class="activity-time">
                                            <?php echo isset($activity['created_at']) ? date('M d, h:i A', strtotime($activity['created_at'])) : 'N/A'; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="dashboard-empty-state">
                                <i class="fas fa-stream"></i>
                                <h4>No recent office activity</h4>
                                <p>System and user actions will appear here once the library office is active.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="display: none;" aria-hidden="true">
                <div class="welcome-banner">
                    <h1>Welcome, Librarian
                        <?php echo htmlspecialchars(explode(' ', $librarian_name)[1] ?? ''); ?>.
                    </h1>
                    <p>Manage library clearances and track student library records.</p>
                    <div class="office-info">
                        <i class="fas fa-info-circle"></i> Library Office
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
                            <p>Pending Clearances</p>
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
                            <p>Students Processed</p>
                        </div>
                    </div>
                </div>

                <!-- Recent Clearances -->
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-clock"></i> Recent Clearance Activities</h2>
                        <button class="btn btn-primary" onclick="switchTab('pending')">View All</button>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>ID</th>
                                    <th>Course</th>
                                    <th>Type</th>
                                    <th>Semester</th>
                                    <th>Status</th>
                                    <th>Lacking Comment</th>
                                    <th>Processed</th>
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
                                            <td><span class="type-badge">
                                                    <?php echo ucfirst($clearance['clearance_type']); ?>
                                                </span>
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
                                                <?php if (!empty($clearance['lacking_comment'])): ?>
                                                    <span class="lacking-badge"
                                                        title="<?php echo htmlspecialchars($clearance['lacking_comment']); ?>"
                                                        onclick="viewLackingComment('<?php echo htmlspecialchars(addslashes($clearance['lacking_comment'])); ?>', '<?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?>', '<?php echo htmlspecialchars($clearance['lacking_by_fname'] ?? '' . ' ' . $clearance['lacking_by_lname'] ?? ''); ?>', '<?php echo $clearance['lacking_comment_at']; ?>')">
                                                        <i class="fas fa-comment"></i> View
                                                    </span>
                                                <?php else: ?>
                                                    None
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo isset($clearance['processed_date']) ? date('M d, Y', strtotime($clearance['processed_date'])) : 'N/A'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; color: var(--text-muted);">No recent
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
                        <input type="text" class="filter-input" id="quickSearchLegacy"
                            placeholder="Enter student name or ISMIS ID..." style="flex: 1;">
                        <button class="btn btn-primary" onclick="searchStudent()">Search</button>
                    </div>
                    <div id="quickSearchResultsLegacy" style="margin-top: 20px; display: none;"></div>
                </div>
                </div>
            </div>

            <!-- Pending Clearances Tab -->
            <div id="pending" class="tab-content <?php echo $active_tab == 'pending' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-clock"></i> Pending Clearances</h2>
                        <span>Found:
                            <?php echo count($pending_clearances); ?> pending
                        </span>
                    </div>

                    <div class="summary-grid">
                        <div class="summary-card">
                            <div class="label">Total Pending</div>
                            <div class="value"><?php echo $pending_summary['total']; ?></div>
                            <div class="meta">Current library requests waiting in the queue</div>
                        </div>
                        <div class="summary-card">
                            <div class="label">Needs Review</div>
                            <div class="value"><?php echo $pending_summary['needs_review']; ?></div>
                            <div class="meta">Fresh submissions with no lacking comment yet</div>
                        </div>
                        <div class="summary-card">
                            <div class="label">Awaiting Student</div>
                            <div class="value"><?php echo $pending_summary['awaiting_student']; ?></div>
                            <div class="meta">Locked until the student submits proof</div>
                        </div>
                        <div class="summary-card">
                            <div class="label">Proof Submitted</div>
                            <div class="value"><?php echo $pending_summary['proof_submitted']; ?></div>
                            <div class="meta">Rows with uploaded student proof ready to inspect</div>
                        </div>
                        <div class="summary-card">
                            <div class="label">Complied</div>
                            <div class="value"><?php echo $pending_summary['complied']; ?></div>
                            <div class="meta">Students who answered a lacking requirement</div>
                        </div>
                    </div>

                    <!-- Filter Bar -->
                    <div class="filter-bar">
                        <select class="filter-select" id="pendingTypeFilter">
                            <option value="">All Types</option>
                            <?php foreach ($stats['clearance_types'] ?? [] as $type): ?>
                                <option value="<?php echo $type['clearance_name']; ?>">
                                    <?php echo ucfirst($type['clearance_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="pendingStateFilter">
                            <option value="">All Queue States</option>
                            <option value="needs-review">Needs Review</option>
                            <option value="awaiting-student">Awaiting Student</option>
                            <option value="proof-submitted">Proof Submitted</option>
                            <option value="complied">Complied</option>
                        </select>
                        <input type="text" class="filter-input" id="pendingSearch"
                            placeholder="Search by student name or ID...">
                        <button class="filter-btn" onclick="filterPending()"><i class="fas fa-filter"></i>
                            Filter</button>
                        <button class="clear-filter" onclick="clearPendingFilters()"><i class="fas fa-times"></i>
                            Clear</button>
                    </div>

                    <div class="section-tools">
                        <div class="filter-meta" id="pendingVisibleCount">Showing <?php echo count($pending_clearances); ?>
                            of <?php echo count($pending_clearances); ?> pending clearances</div>
                        <div class="filter-meta">Use queue states to separate new reviews, follow-ups, and complied
                            submissions.</div>
                    </div>

                    <!-- Bulk Actions -->
                    <?php if (!empty($pending_clearances)): ?>
                        <div class="pending-bulk-panel">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" id="selectAll" class="select-checkbox">
                                <label for="selectAll" style="color: var(--text-primary);">Select Ready Rows</label>
                            </div>
                            <div style="flex: 1; min-width: 250px;">
                                <div style="color: var(--text-primary); font-weight: 700;">Bulk approve without
                                    remarks</div>
                                <div class="muted-inline">
                                    <?php echo $pending_summary['ready_for_bulk']; ?> row(s) are ready now. Unresolved
                                    lacking items stay locked until proof is uploaded.
                                </div>
                            </div>
                            <div class="pending-selection-chip" id="pendingSelectionCount">0 selected</div>
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
                            <p>All clearances have been processed.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table id="pendingTable" class="pending-table">
                                <thead>
                                    <tr>
                                        <th style="width: 40px;">Select</th>
                                        <th>Student</th>
                                        <th>Clearance Details</th>
                                        <th>Queue Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_clearances as $clearance): ?>
                                        <?php
                                        $has_lacking = !empty($clearance['lacking_comment']);
                                        $has_student_proof = !empty($clearance['student_proof_file']);
                                        $is_selectable = !$has_lacking || $has_student_proof;
                                        $resolved_lacking = $has_lacking && $has_student_proof;
                                        $pending_state = 'needs-review';
                                        $pending_state_label = 'Needs Review';
                                        $pending_state_class = 'review';
                                        $pending_row_class = 'pending-row-review';
                                        $pending_state_meta = 'No issues flagged yet. Review the request and decide the next action.';

                                        if ($has_lacking && $has_student_proof) {
                                            $pending_state = 'complied';
                                            $pending_state_label = 'Complied';
                                            $pending_state_class = 'complied';
                                            $pending_row_class = 'pending-row-complied';
                                            $pending_state_meta = 'The student responded to the lacking notice and is ready for your follow-up.';
                                        } elseif ($has_lacking) {
                                            $pending_state = 'awaiting-student';
                                            $pending_state_label = 'Awaiting Student';
                                            $pending_state_class = 'awaiting';
                                            $pending_row_class = 'pending-row-awaiting';
                                            $pending_state_meta = 'Approval is locked until the student uploads the required proof.';
                                        } elseif ($has_student_proof) {
                                            $pending_state = 'proof-submitted';
                                            $pending_state_label = 'Proof Submitted';
                                            $pending_state_class = 'proof';
                                            $pending_row_class = 'pending-row-proof';
                                            $pending_state_meta = 'Student proof is attached and ready for checking.';
                                        }

                                        if (!empty($clearance['student_proof_uploaded_at'])) {
                                            $pending_activity_text = 'Latest proof uploaded ' . date('M d, Y h:i A', strtotime($clearance['student_proof_uploaded_at']));
                                        } elseif (!empty($clearance['lacking_comment_at'])) {
                                            $pending_activity_text = 'Marked lacking ' . date('M d, Y h:i A', strtotime($clearance['lacking_comment_at']));
                                        } elseif (!empty($clearance['created_at'])) {
                                            $pending_activity_text = 'Submitted ' . date('M d, Y h:i A', strtotime($clearance['created_at']));
                                        } else {
                                            $pending_activity_text = 'Recently submitted';
                                        }

                                        $remaining_steps = max(0, ((int) ($clearance['total_count'] ?? 0)) - ((int) ($clearance['approved_count'] ?? 0)));
                                        $remaining_steps_text = $remaining_steps === 1
                                            ? '1 office still pending in this clearance period'
                                            : $remaining_steps . ' offices still pending in this clearance period';
                                        $period_label = trim(($clearance['semester'] ?? '') . ' ' . ($clearance['school_year'] ?? ''));
                                        $approved_offices_text = !empty($clearance['approved_offices'])
                                            ? 'Approved offices: ' . $clearance['approved_offices']
                                            : 'No offices approved yet for this period';
                                        $proof_meta_text = !empty($clearance['student_proof_uploaded_at'])
                                            ? 'Uploaded ' . date('M d, Y h:i A', strtotime($clearance['student_proof_uploaded_at']))
                                            : 'Student proof is attached';
                                        $action_hint = $is_selectable
                                            ? 'This request is eligible for approval after review.'
                                            : 'Bulk approval stays disabled until the student submits proof.';
                                        ?>
                                        <tr data-type="<?php echo $clearance['clearance_type']; ?>"
                                            data-state="<?php echo $pending_state; ?>"
                                            data-name="<?php echo strtolower($clearance['fname'] . ' ' . $clearance['lname']); ?>"
                                            data-id="<?php echo strtolower($clearance['ismis_id']); ?>"
                                            class="<?php echo trim($pending_row_class . ' ' . ($resolved_lacking ? 'resolved-lacking-row' : '')); ?>">
                                            <td>
                                                <input type="checkbox" class="select-checkbox clearance-checkbox"
                                                    value="<?php echo $clearance['clearance_id']; ?>" <?php echo !$is_selectable ? 'disabled' : ''; ?>>
                                            </td>
                                            <td>
                                                <div class="pending-student-cell">
                                                    <div class="pending-student-name">
                                                        <?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?>
                                                    </div>
                                                    <div class="pending-subline">
                                                        <span><i class="fas fa-id-card"></i>
                                                            <?php echo htmlspecialchars($clearance['ismis_id']); ?></span>
                                                        <span><i class="fas fa-book"></i>
                                                            <?php echo htmlspecialchars($clearance['course_name'] ?? 'N/A'); ?></span>
                                                        <?php if (!empty($clearance['college_name'])): ?>
                                                            <span><i class="fas fa-building"></i>
                                                                <?php echo htmlspecialchars($clearance['college_name']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="pending-meta"><?php echo htmlspecialchars($pending_activity_text); ?></div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="pending-detail-stack">
                                                    <div class="pending-chip-row">
                                                        <span class="type-badge">
                                                            <?php echo ucfirst($clearance['clearance_type']); ?>
                                                        </span>
                                                        <?php if (!empty($period_label)): ?>
                                                            <span class="period-badge"><?php echo htmlspecialchars($period_label); ?></span>
                                                        <?php endif; ?>
                                                        <span class="progress-badge"
                                                            title="<?php echo htmlspecialchars($approved_offices_text); ?>">
                                                            <?php echo ($clearance['approved_count'] ?? 0) . '/' . ($clearance['total_count'] ?? 5); ?>
                                                            Approved
                                                        </span>
                                                    </div>
                                                    <div class="pending-meta"><?php echo htmlspecialchars($remaining_steps_text); ?></div>
                                                    <div class="pending-meta"><?php echo htmlspecialchars($approved_offices_text); ?></div>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="pending-detail-stack">
                                                    <div class="pending-status-stack">
                                                        <span
                                                            class="pending-state-badge <?php echo $pending_state_class; ?>"><?php echo $pending_state_label; ?></span>
                                                    </div>
                                                    <div class="pending-meta"><?php echo htmlspecialchars($pending_state_meta); ?></div>
                                                    <div class="pending-link-row">
                                                        <?php if ($has_lacking): ?>
                                                            <button type="button" class="lacking-badge"
                                                                title="<?php echo htmlspecialchars($clearance['lacking_comment']); ?>"
                                                                onclick="viewLackingComment('<?php echo htmlspecialchars(addslashes($clearance['lacking_comment'])); ?>', '<?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?>', '<?php echo htmlspecialchars(($clearance['lacking_by_fname'] ?? '') . ' ' . ($clearance['lacking_by_lname'] ?? '')); ?>', '<?php echo $clearance['lacking_comment_at']; ?>')">
                                                                <i class="fas fa-exclamation-triangle"></i> View Lacking
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php if ($has_student_proof): ?>
                                                            <button type="button" class="proof-badge"
                                                                onclick="viewStudentProof('<?php echo $clearance['clearance_id']; ?>', '<?php echo $clearance['student_proof_file']; ?>', '<?php echo htmlspecialchars(addslashes($clearance['student_proof_remarks'] ?? '')); ?>')">
                                                                <i class="fas fa-paperclip"></i> View Proof
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($has_student_proof): ?>
                                                        <div class="pending-meta"><?php echo htmlspecialchars($proof_meta_text); ?></div>
                                                    <?php elseif ($has_lacking): ?>
                                                        <div class="pending-meta">Waiting on the student response before this row can move forward.</div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="pending-action-stack">
                                                    <?php if ($resolved_lacking || !$has_lacking): ?>
                                                        <button type="button" class="pending-action-btn approve"
                                                            onclick="approveClearance(<?php echo $clearance['clearance_id']; ?>)"
                                                            title="<?php echo $resolved_lacking ? 'Student has complied - Approve' : 'Approve'; ?>">
                                                            <i class="fas fa-check"></i> Approve
                                                        </button>
                                                    <?php endif; ?>

                                                    <button type="button" class="pending-action-btn lacking"
                                                        onclick="openLackingModal(<?php echo $clearance['clearance_id']; ?>)"
                                                        title="Mark as Lacking">
                                                        <i class="fas fa-exclamation-circle"></i> Mark Lacking
                                                    </button>

                                                    <?php if ($has_lacking && $has_student_proof): ?>
                                                        <form method="POST" class="pending-action-form">
                                                            <input type="hidden" name="clearance_id"
                                                                value="<?php echo $clearance['clearance_id']; ?>">
                                                            <button type="submit" name="clear_lacking_comment"
                                                                class="pending-action-btn resolve"
                                                                title="Clear lacking comment (student has complied)"
                                                                onclick="return confirm('Mark this lacking requirement as resolved? The student can now be approved.')">
                                                                <i class="fas fa-check-double"></i> Clear Lacking
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>

                                                    <button type="button" class="pending-action-btn neutral"
                                                        onclick="viewStudentProgress(<?php echo $clearance['users_id']; ?>, '<?php echo $clearance['semester']; ?>', '<?php echo $clearance['school_year']; ?>', '<?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?>', '<?php echo $clearance['ismis_id']; ?>', '<?php echo htmlspecialchars($clearance['course_name'] ?? 'N/A'); ?>', '<?php echo htmlspecialchars($clearance['college_name'] ?? 'N/A'); ?>', '<?php echo $clearance['address'] ?? ''; ?>', '<?php echo $clearance['contacts'] ?? ''; ?>', '<?php echo $clearance['age'] ?? ''; ?>')">
                                                        <i class="fas fa-eye"></i> Progress
                                                    </button>
                                                </div>
                                                <div class="pending-meta"><?php echo htmlspecialchars($action_hint); ?></div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="filter-empty-state" id="pendingNoResults">
                            <i class="fas fa-filter"></i>
                            <h4>No pending clearances match the current filters</h4>
                            <p>Try a different queue state, clearance type, or search term to bring matching requests
                                back into view.</p>
                        </div>

                        <div style="margin-top: 20px; padding: 15px; background: var(--success-soft); border-radius: 12px;">
                            <p style="color: var(--success);">
                                <i class="fas fa-info-circle"></i>
                                <strong>Note:</strong> Use <span class="pending-state-badge complied">Complied</span>
                                to spot students who answered a lacking comment, and <span
                                    class="pending-state-badge awaiting">Awaiting Student</span> for requests that are
                                still locked until proof arrives.
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Undo Approvals Tab -->
            <div id="undo" class="tab-content <?php echo $active_tab == 'undo' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-undo-alt"></i> Your Processed Clearances</h2>
                        <span class="badge badge-undo">Unlimited undo available</span>
                    </div>

                    <div class="info-card">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <strong>No time limit:</strong> You can undo any clearance you've processed at any time.
                            This will revert the clearance back to pending status.
                        </div>
                    </div>

                    <div class="summary-grid">
                        <div class="summary-card">
                            <div class="label">Processed By You</div>
                            <div class="value"><?php echo $undo_summary['total']; ?></div>
                            <div class="meta">Clearances currently eligible for undo</div>
                        </div>
                        <div class="summary-card">
                            <div class="label">Approved</div>
                            <div class="value"><?php echo $undo_summary['approved']; ?></div>
                            <div class="meta">Approved records you can still revert</div>
                        </div>
                        <div class="summary-card">
                            <div class="label">Rejected</div>
                            <div class="value"><?php echo $undo_summary['rejected']; ?></div>
                            <div class="meta">Rejected records that can be reopened</div>
                        </div>
                        <div class="summary-card">
                            <div class="label">With Evidence</div>
                            <div class="value"><?php echo $undo_summary['with_student_proof']; ?></div>
                            <div class="meta">Undo records that had student proof attached</div>
                        </div>
                    </div>

                    <?php if (empty($approvals_to_undo)): ?>
                        <div class="empty-state">
                            <i class="fas fa-undo-alt"></i>
                            <h3>No processed clearances</h3>
                            <p>You haven't processed any clearances yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="filter-bar">
                            <input type="text" class="filter-input" id="undoSearch"
                                placeholder="Search by student name or ID..." onkeyup="filterUndo()">
                            <select class="filter-select" id="undoTypeFilter" onchange="filterUndo()">
                                <option value="">All Types</option>
                                <?php foreach ($stats['clearance_types'] ?? [] as $type): ?>
                                    <option value="<?php echo $type['clearance_name']; ?>">
                                        <?php echo ucfirst($type['clearance_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select class="filter-select" id="undoStatusFilter" onchange="filterUndo()">
                                <option value="">All Status</option>
                                <option value="approved">Approved</option>
                                <option value="rejected">Rejected</option>
                            </select>
                            <button class="clear-filter" onclick="clearUndoFilters()"><i class="fas fa-times"></i>
                                Clear</button>
                        </div>

                        <div class="section-tools">
                            <div class="filter-meta" id="undoVisibleCount">Showing <?php echo count($approvals_to_undo); ?>
                                of <?php echo count($approvals_to_undo); ?> processed clearances</div>
                            <div class="filter-meta">Review the period and record flags before undoing a clearance.</div>
                        </div>

                        <div class="undo-grid" id="undoGrid">
                            <?php foreach ($approvals_to_undo as $approval): ?>
                                <?php
                                $approval_period = trim(($approval['semester'] ?? '') . ' ' . ($approval['school_year'] ?? ''));
                                $undo_payload = [
                                    'clearanceId' => (int) $approval['clearance_id'],
                                    'studentName' => $approval['fname'] . ' ' . $approval['lname'],
                                    'studentId' => $approval['ismis_id'],
                                    'status' => ucfirst($approval['status']),
                                    'clearanceType' => ucfirst($approval['clearance_type']),
                                    'courseName' => $approval['course_name'] ?? 'N/A',
                                    'periodLabel' => $approval_period ?: 'Not specified',
                                    'processedDate' => $approval['formatted_date'] ?? (isset($approval['processed_date']) ? date('M d, Y h:i A', strtotime($approval['processed_date'])) : 'N/A'),
                                    'hadLacking' => !empty($approval['lacking_comment']),
                                    'hadStudentProof' => !empty($approval['student_proof_file'])
                                ];
                                ?>
                                <div class="undo-item" data-type="<?php echo $approval['clearance_type']; ?>"
                                    data-status="<?php echo $approval['status']; ?>"
                                    data-name="<?php echo strtolower($approval['fname'] . ' ' . $approval['lname']); ?>"
                                    data-id="<?php echo strtolower($approval['ismis_id']); ?>">
                                    <div class="undo-top">
                                        <div class="undo-info">
                                            <h4>
                                                <?php echo htmlspecialchars($approval['fname'] . ' ' . $approval['lname']); ?>
                                            </h4>
                                            <p>
                                                <span class="status-badge <?php echo getStatusClass($approval['status']); ?>"
                                                    style="font-size: 0.7rem; padding: 2px 8px;">
                                                    <?php echo ucfirst($approval['status']); ?>
                                                </span>
                                                <span class="type-badge" style="font-size: 0.7rem;">
                                                    <?php echo ucfirst($approval['clearance_type']); ?>
                                                </span>
                                            </p>
                                            <p>
                                                <?php echo htmlspecialchars($approval['ismis_id']); ?> |
                                                <?php echo htmlspecialchars($approval['course_name'] ?? 'N/A'); ?>
                                            </p>
                                            <small>Processed:
                                                <?php echo $approval['formatted_date'] ?? date('M d, Y h:i A', strtotime($approval['processed_date'])); ?>
                                            </small>
                                            <div class="undo-meta">
                                                <span class="badge badge-info">
                                                    <i class="fas fa-calendar-alt"></i>
                                                    <?php echo htmlspecialchars($approval_period ?: 'Period not set'); ?>
                                                </span>
                                                <?php if (!empty($approval['lacking_comment'])): ?>
                                                    <span class="badge badge-lacking">Had lacking comment</span>
                                                <?php endif; ?>
                                                <?php if (!empty($approval['student_proof_file'])): ?>
                                                    <span class="badge badge-proof">Had student proof</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <span class="badge badge-undo">ID
                                            <?php echo htmlspecialchars($approval['clearance_id']); ?></span>
                                    </div>
                                    <div class="undo-actions">
                                        <button class="undo-btn"
                                            onclick='openUndoModal(<?php echo json_encode($undo_payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'>
                                            <i class="fas fa-undo-alt"></i> Undo
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="filter-empty-state" id="undoNoResults">
                            <i class="fas fa-search"></i>
                            <h4>No matching processed clearances</h4>
                            <p>Try changing the name, type, or status filters to find the record you want to undo.</p>
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

                    <div class="summary-grid">
                        <div class="summary-card">
                            <div class="label">Total Records</div>
                            <div class="value"><?php echo $history_summary['total']; ?></div>
                            <div class="meta">Latest 50 library clearance entries</div>
                        </div>
                        <div class="summary-card">
                            <div class="label">Approved</div>
                            <div class="value"><?php echo $history_summary['approved']; ?></div>
                            <div class="meta">Processed successfully by the library</div>
                        </div>
                        <div class="summary-card">
                            <div class="label">Pending</div>
                            <div class="value"><?php echo $history_summary['pending']; ?></div>
                            <div class="meta">Still awaiting a final library action</div>
                        </div>
                        <div class="summary-card">
                            <div class="label">Rejected</div>
                            <div class="value"><?php echo $history_summary['rejected']; ?></div>
                            <div class="meta">Returned for further student action</div>
                        </div>
                        <div class="summary-card">
                            <div class="label">Proof Tracked</div>
                            <div class="value"><?php echo $history_summary['proof_tracked']; ?></div>
                            <div class="meta">Records with student or librarian proof attached</div>
                        </div>
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
                        <select class="filter-select" id="historyTypeFilter">
                            <option value="">All Types</option>
                            <?php foreach ($stats['clearance_types'] ?? [] as $type): ?>
                                <option value="<?php echo $type['clearance_name']; ?>">
                                    <?php echo ucfirst($type['clearance_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select class="filter-select" id="historyStatusFilter">
                            <option value="">All Status</option>
                            <option value="pending">Pending</option>
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

                    <div class="section-tools">
                        <div class="filter-meta" id="historyVisibleCount">Showing <?php echo count($clearance_history); ?>
                            of <?php echo count($clearance_history); ?> history entries</div>
                        <div class="filter-meta">Use period, type, and status filters to audit specific library actions.</div>
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
                                        <th>ID</th>
                                        <th>Course</th>
                                        <th>Type</th>
                                        <th>Semester</th>
                                        <th>Status</th>
                                        <th>Lacking Comment</th>
                                        <th>Student Proof</th>
                                        <th>Librarian Proof</th>
                                        <th>Processed By</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($clearance_history as $clearance): ?>
                                        <tr data-semester="<?php echo $clearance['semester']; ?>"
                                            data-year="<?php echo $clearance['school_year']; ?>"
                                            data-type="<?php echo $clearance['clearance_type']; ?>"
                                            data-status="<?php echo $clearance['status']; ?>"
                                            data-name="<?php echo strtolower($clearance['fname'] . ' ' . $clearance['lname']); ?>"
                                            data-id="<?php echo strtolower($clearance['ismis_id']); ?>">
                                            <td><strong>
                                                    <?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?>
                                                </strong>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($clearance['ismis_id']); ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($clearance['course_name'] ?? 'N/A'); ?>
                                            </td>
                                            <td><span class="type-badge">
                                                    <?php echo ucfirst($clearance['clearance_type']); ?>
                                                </span>
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
                                                <?php if (!empty($clearance['lacking_comment'])): ?>
                                                    <span class="lacking-badge"
                                                        title="<?php echo htmlspecialchars($clearance['lacking_comment']); ?>"
                                                        onclick="viewLackingComment('<?php echo htmlspecialchars(addslashes($clearance['lacking_comment'])); ?>', '<?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?>', '<?php echo htmlspecialchars(($clearance['lacking_by_fname'] ?? '') . ' ' . ($clearance['lacking_by_lname'] ?? '')); ?>', '<?php echo $clearance['lacking_comment_at']; ?>')">
                                                        <i class="fas fa-comment"></i> View
                                                    </span>
                                                <?php else: ?>
                                                    None
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($clearance['student_proof_file'])): ?>
                                                    <?php
                                                    $file_ext = strtolower(pathinfo($clearance['student_proof_file'], PATHINFO_EXTENSION));
                                                    $is_image = in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif']);
                                                    ?>
                                                    <?php if ($is_image): ?>
                                                        <img src="<?php echo htmlspecialchars('serve_proof.php?file=' . rawurlencode(ltrim((string) preg_replace('#^(?:\.\./|\./)+#', '', str_replace('\\', '/', (string) ($clearance['student_proof_file'] ?? ''))), '/')), ENT_QUOTES, 'UTF-8'); ?>"
                                                            style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px; cursor: pointer;"
                                                            onclick="viewStudentProof('<?php echo $clearance['clearance_id']; ?>', '<?php echo $clearance['student_proof_file']; ?>', '<?php echo htmlspecialchars(addslashes($clearance['student_proof_remarks'] ?? '')); ?>')">
                                                    <?php else: ?>
                                                        <span class="proof-badge"
                                                            onclick="viewStudentProof('<?php echo $clearance['clearance_id']; ?>', '<?php echo $clearance['student_proof_file']; ?>', '<?php echo htmlspecialchars(addslashes($clearance['student_proof_remarks'] ?? '')); ?>')">
                                                            <i class="fas fa-file"></i> View
                                                        </span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    None
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($clearance['proof_file'])): ?>
                                                    <?php
                                                    $file_ext = strtolower(pathinfo($clearance['proof_file'], PATHINFO_EXTENSION));
                                                    $is_image = in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif']);
                                                    ?>
                                                    <?php if ($is_image): ?>
                                                        <img src="<?php echo htmlspecialchars('serve_proof.php?file=' . rawurlencode(ltrim((string) preg_replace('#^(?:\.\./|\./)+#', '', str_replace('\\', '/', (string) ($clearance['proof_file'] ?? ''))), '/')), ENT_QUOTES, 'UTF-8'); ?>"
                                                            style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px; cursor: pointer;"
                                                            onclick="viewLibrarianProof('<?php echo $clearance['proof_file']; ?>', '<?php echo htmlspecialchars(addslashes($clearance['proof_remarks'] ?? '')); ?>', '<?php echo htmlspecialchars(($clearance['proof_by_fname'] ?? '') . ' ' . ($clearance['proof_by_lname'] ?? '')); ?>', '<?php echo $clearance['proof_uploaded_at']; ?>')">
                                                    <?php else: ?>
                                                        <span class="proof-badge"
                                                            onclick="viewLibrarianProof('<?php echo $clearance['proof_file']; ?>', '<?php echo htmlspecialchars(addslashes($clearance['proof_remarks'] ?? '')); ?>', '<?php echo htmlspecialchars(($clearance['proof_by_fname'] ?? '') . ' ' . ($clearance['proof_by_lname'] ?? '')); ?>', '<?php echo $clearance['proof_uploaded_at']; ?>')">
                                                            <i class="fas fa-file"></i> View
                                                        </span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    None
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars(trim(($clearance['processed_fname'] ?? '') . ' ' . ($clearance['processed_lname'] ?? '')) ?: 'Not processed'); ?>
                                            </td>
                                            <td>
                                                <?php echo isset($clearance['processed_date']) ? date('M d, Y h:i A', strtotime($clearance['processed_date'])) : 'N/A'; ?>
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

                        <div class="filter-empty-state" id="historyNoResults">
                            <i class="fas fa-filter"></i>
                            <h4>No history entries match the current filters</h4>
                            <p>Adjust the period, type, status, or search text to bring matching records back into
                                view.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Students Tab -->
            <div id="students" class="tab-content <?php echo $active_tab == 'students' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-users"></i> Student Records</h2>
                        <span class="badge badge-primary">
                            <?php echo count($students); ?> Total Students
                        </span>
                    </div>

                    <div class="summary-grid">
                        <div class="summary-card">
                            <div class="label">Total Students</div>
                            <div class="value"><?php echo $student_summary['total']; ?></div>
                            <div class="meta">Active student accounts available to the library</div>
                        </div>
                        <div class="summary-card">
                            <div class="label">With Library Records</div>
                            <div class="value"><?php echo $student_summary['with_library_records']; ?></div>
                            <div class="meta">Students who already have library activity</div>
                        </div>
                        <div class="summary-card">
                            <div class="label">Pending At Library</div>
                            <div class="value"><?php echo $student_summary['with_pending_library']; ?></div>
                            <div class="meta">Students currently waiting for library action</div>
                        </div>
                        <div class="summary-card">
                            <div class="label">Approved At Library</div>
                            <div class="value"><?php echo $student_summary['with_approved_library']; ?></div>
                            <div class="meta">Students with at least one approved library record</div>
                        </div>
                    </div>

                    <!-- Search Bar -->
                    <div class="filter-bar">
                        <select class="filter-select" id="studentCourseFilter">
                            <option value="">All Courses</option>
                            <?php foreach ($student_course_filters as $course_id => $course_name): ?>
                                <option value="<?php echo (int) $course_id; ?>">
                                    <?php echo htmlspecialchars($course_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="filter-input" id="studentSearch"
                            placeholder="Search by name or ISMIS ID..." style="flex: 1;">
                        <button class="filter-btn" onclick="searchStudents()"><i class="fas fa-search"></i>
                            Search</button>
                        <button class="clear-filter" onclick="clearStudentSearch()"><i class="fas fa-times"></i>
                            Clear</button>
                    </div>

                    <div class="section-tools">
                        <div class="filter-meta" id="studentsVisibleCount">Showing <?php echo count($students); ?> of
                            <?php echo count($students); ?> students</div>
                        <div class="filter-meta">Open any student to see recent clearance records and summary activity.</div>
                    </div>

                    <!-- Students Grid -->
                    <div class="students-grid" id="studentsGrid">
                        <?php if (!empty($students)): ?>
                            <?php foreach ($students as $student): ?>
                                <div class="student-card"
                                    data-course="<?php echo (int) ($student['course_id'] ?? 0); ?>"
                                    data-name="<?php echo strtolower($student['fname'] . ' ' . $student['lname']); ?>"
                                    data-id="<?php echo strtolower($student['ismis_id']); ?>">
                                    <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                                        <div class="student-avatar">
                                            <?php if (!empty($student['profile_picture']) && file_exists('../' . $student['profile_picture'])): ?>
                                                <img src="../<?php echo $student['profile_picture']; ?>" alt="Profile">
                                            <?php else: ?>
                                                <i class="fas fa-user-graduate"></i>
                                            <?php endif; ?>
                                        </div>
                                        <div class="student-info">
                                            <h4>
                                                <?php echo htmlspecialchars($student['fname'] . ' ' . $student['lname']); ?>
                                            </h4>
                                            <p><i class="fas fa-id-card"></i>
                                                <?php echo htmlspecialchars($student['ismis_id']); ?>
                                            </p>
                                            <p><i class="fas fa-envelope"></i>
                                                <?php echo htmlspecialchars($student['email'] ?? 'No email'); ?>
                                            </p>
                                        </div>
                                    </div>

                                    <div class="student-badges">
                                        <?php if (!empty($student['course_name'])): ?>
                                            <span class="badge badge-primary">
                                                <i class="fas fa-book"></i>
                                                <?php echo htmlspecialchars($student['course_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($student['college_name'])): ?>
                                            <span class="badge badge-info">
                                                <i class="fas fa-building"></i>
                                                <?php echo htmlspecialchars($student['college_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (($student['approved_count'] ?? 0) > 0): ?>
                                            <span class="badge badge-success">
                                                <i class="fas fa-check-circle"></i>
                                                <?php echo $student['approved_count']; ?> approved
                                            </span>
                                        <?php endif; ?>
                                        <?php if (($student['library_pending_count'] ?? 0) > 0): ?>
                                            <span class="badge badge-warning">
                                                <i class="fas fa-clock"></i>
                                                <?php echo (int) $student['library_pending_count']; ?> library pending
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="student-metrics">
                                        <div class="student-metric">
                                            <span class="metric-label">Library Records</span>
                                            <span class="metric-value"><?php echo (int) ($student['library_total_records'] ?? 0); ?></span>
                                        </div>
                                        <div class="student-metric">
                                            <span class="metric-label">Library Pending</span>
                                            <span class="metric-value"><?php echo (int) ($student['library_pending_count'] ?? 0); ?></span>
                                        </div>
                                        <div class="student-metric">
                                            <span class="metric-label">Library Approved</span>
                                            <span class="metric-value"><?php echo (int) ($student['library_approved_count'] ?? 0); ?></span>
                                        </div>
                                    </div>

                                    <?php if (!empty($student['address']) || !empty($student['contacts'])): ?>
                                        <div style="margin-top: 10px; font-size: 0.85rem; color: var(--text-secondary);">
                                            <?php if (!empty($student['address'])): ?>
                                                <p><i class="fas fa-map-marker-alt"></i>
                                                    <?php echo htmlspecialchars($student['address']); ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if (!empty($student['contacts'])): ?>
                                                <p><i class="fas fa-phone"></i>
                                                    <?php echo htmlspecialchars($student['contacts']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="student-card-footer">
                                        <div class="student-last-activity">
                                            <i class="fas fa-clock"></i>
                                            Last library activity:
                                            <?php echo !empty($student['library_last_activity']) ? date('M d, Y h:i A', strtotime($student['library_last_activity'])) : 'No library activity yet'; ?>
                                        </div>
                                        <button class="btn btn-primary" style="padding: 10px 16px;"
                                            onclick="viewStudentRecords(<?php echo $student['users_id']; ?>)">
                                            <i class="fas fa-eye"></i> View Records
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state" style="grid-column: 1/-1;">
                                <i class="fas fa-users"></i>
                                <h3>No students found</h3>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="filter-empty-state" id="studentsNoResults">
                        <i class="fas fa-user-slash"></i>
                        <h4>No students match the current filters</h4>
                        <p>Try a different name, ISMIS ID, or course filter to find the student record you need.</p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Approve Clearance Modal (No Remarks) -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle"></i> Approve Clearance</h3>
                <button class="close" onclick="closeApproveModal()">&times;</button>
            </div>
            <form method="POST" action="" id="approveForm">
                <div class="modal-body">
                    <input type="hidden" name="clearance_id" id="approveClearanceId">

                    <div class="info-card" style="background: var(--success-soft); border-color: var(--success);">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <strong>Confirm Approval:</strong> Are you sure you want to approve this clearance? This
                            action can be undone later if needed.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeApproveModal()">Cancel</button>
                    <button type="submit" name="approve_clearance" class="btn btn-success">Approve</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Lacking Comment Modal -->
    <div id="lackingModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-exclamation-circle"></i> Mark as Lacking</h3>
                <button class="close" onclick="closeLackingModal()">&times;</button>
            </div>
            <form method="POST" action="" id="lackingForm">
                <div class="modal-body">
                    <input type="hidden" name="clearance_id" id="lackingClearanceId">

                    <div class="form-group">
                        <label><i class="fas fa-comment"></i> What is the student lacking? <span
                                class="required">*</span></label>
                        <textarea name="lacking_comment" id="lackingComment" rows="4"
                            placeholder="e.g., Unreturned books: 'Introduction to Programming', 'Database Systems'. Library fine of PHP 150.00"
                            required></textarea>
                    </div>

                    <div class="info-card" style="background: var(--lacking-soft); border-color: var(--lacking);">
                        <i class="fas fa-info-circle"></i>
                        <div>
                            <strong>Note:</strong> The clearance will remain pending. The student will see this comment
                            and can submit proof when the lacking items are resolved.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeLackingModal()">Cancel</button>
                    <button type="submit" name="add_lacking_comment" class="btn btn-lacking">Submit Comment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Lacking Comment Modal -->
    <div id="viewLackingModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-comment"></i> Lacking Comment Details</h3>
                <button class="close" onclick="closeViewLackingModal()">&times;</button>
            </div>
            <div class="modal-body" id="viewLackingBody">
                <div class="student-info-card">
                    <p><strong>Student:</strong> <span id="lackingStudentName"></span></p>
                    <p><strong>Added by:</strong> <span id="lackingAddedBy"></span></p>
                    <p><strong>Date:</strong> <span id="lackingDate"></span></p>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-comment"></i> Comment:</label>
                    <div id="lackingCommentText"
                        style="background: var(--bg-secondary); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); white-space: pre-wrap;">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeViewLackingModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- View Student Proof Modal -->
    <div id="viewStudentProofModal" class="modal">
        <div class="modal-content large">
            <div class="modal-header">
                <h3><i class="fas fa-file"></i> Student Proof</h3>
                <button class="close" onclick="closeViewStudentProofModal()">&times;</button>
            </div>
            <div class="modal-body" id="viewStudentProofBody">
                <div class="student-info-card" id="studentProofInfo"></div>
                <div class="form-group">
                    <label><i class="fas fa-file"></i> Proof File:</label>
                    <div id="studentProofPreview" style="text-align: center;"></div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-comment"></i> Student Remarks:</label>
                    <div id="studentProofRemarks"
                        style="background: var(--bg-secondary); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); white-space: pre-wrap;">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeViewStudentProofModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- View Librarian Proof Modal -->
    <div id="viewLibrarianProofModal" class="modal">
        <div class="modal-content large">
            <div class="modal-header">
                <h3><i class="fas fa-file"></i> Librarian Proof</h3>
                <button class="close" onclick="closeViewLibrarianProofModal()">&times;</button>
            </div>
            <div class="modal-body" id="viewLibrarianProofBody">
                <div class="student-info-card" id="librarianProofInfo"></div>
                <div class="form-group">
                    <label><i class="fas fa-file"></i> Proof File:</label>
                    <div id="librarianProofPreview" style="text-align: center;"></div>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-comment"></i> Remarks:</label>
                    <div id="librarianProofRemarks"
                        style="background: var(--bg-secondary); padding: 15px; border-radius: 8px; border: 1px solid var(--border-color); white-space: pre-wrap;">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeViewLibrarianProofModal()">Close</button>
            </div>
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
                    <input type="hidden" name="clearance_id" id="undoClearanceId">

                    <div class="info-card" style="background: var(--undo-soft); border-color: var(--undo);">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <strong>Warning:</strong> This will revert the clearance back to pending status. The student
                            will need to be re-approved by all subsequent offices.
                        </div>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-comment"></i> Reason for Undo <span class="required">*</span></label>
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

    <!-- Student Progress Modal -->
    <div id="progressModal" class="modal">
        <div class="modal-content large">
            <div class="modal-header">
                <h3 id="progressModalTitle"><i class="fas fa-tasks"></i> Student Clearance Progress</h3>
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
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('subAdminSidebar');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');
        const body = document.body;

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

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function toTitleCase(value) {
            return String(value ?? '')
                .replace(/_/g, ' ')
                .replace(/\b\w/g, char => char.toUpperCase());
        }

        function formatDateTime(value, fallback = 'N/A') {
            if (!value) {
                return fallback;
            }

            const date = new Date(value);
            return Number.isNaN(date.getTime()) ? fallback : date.toLocaleString();
        }

        function setProgressModalTitle(iconClass, titleText) {
            const title = document.getElementById('progressModalTitle');
            if (!title) {
                return;
            }

            title.innerHTML = `<i class="${iconClass}"></i> ${escapeHtml(titleText)}`;
        }

        function setFilterFeedback(items, visibleCountId, emptyStateId, noun) {
            const visibleCount = Array.from(items).filter(item => item.style.display !== 'none').length;
            const totalCount = items.length;
            const countLabel = document.getElementById(visibleCountId);
            if (countLabel) {
                countLabel.textContent = `Showing ${visibleCount} of ${totalCount} ${noun}`;
            }

            const emptyState = document.getElementById(emptyStateId);
            if (emptyState) {
                emptyState.classList.toggle('show', totalCount > 0 && visibleCount === 0);
            }
        }

        const dashboardLogoutLinks = Array.from(document.querySelectorAll('a[href*="logout.php"]'));
        const dashboardBackGuardKey = '__librarianDashboardBackGuard';
        let allowDashboardBackExit = false;
        let lastBackGuardNoticeAt = 0;

        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.getElementById(tabName).classList.add('active');

            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });

            const activeNav = (typeof event !== 'undefined' && event && event.target && typeof event.target.closest === 'function')
                ? event.target.closest('.nav-item')
                : document.querySelector(`.nav-item[onclick*="switchTab('${tabName}')"], .nav-item[onclick*="switchTab(\"${tabName}\")"]`);
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

        function setDashboardExitAllowed(value) {
            allowDashboardBackExit = value === true;
        }

        function notifyBackGuard() {
            const now = Date.now();
            if (now - lastBackGuardNoticeAt < 1800) {
                return;
            }

            lastBackGuardNoticeAt = now;
            if (typeof showToast === 'function') {
                showToast('Use Logout to leave your account safely.', 'info');
            }
        }

        function initializeDashboardBackGuard() {
            if (!window.history || typeof window.history.pushState !== 'function' || typeof window.history.replaceState !== 'function') {
                return;
            }

            const existingState = (window.history.state && typeof window.history.state === 'object')
                ? window.history.state
                : {};

            if (!existingState[dashboardBackGuardKey]) {
                const rootState = Object.assign({}, existingState, {
                    [dashboardBackGuardKey]: 'root'
                });
                window.history.replaceState(rootState, '', window.location.href);
            }

            window.history.pushState({
                [dashboardBackGuardKey]: 'lock',
                at: Date.now()
            }, '', window.location.href);

            window.addEventListener('popstate', () => {
                if (allowDashboardBackExit) {
                    return;
                }

                const currentUrl = new URL(window.location.href);
                const currentTab = currentUrl.searchParams.get('tab') || 'dashboard';
                const hasDashboardTab = !!document.getElementById('dashboard');

                if (hasDashboardTab && currentTab !== 'dashboard') {
                    switchTab('dashboard');
                } else {
                    window.history.pushState({
                        [dashboardBackGuardKey]: 'lock',
                        at: Date.now()
                    }, '', window.location.href);
                }

                notifyBackGuard();
            });
        }

        dashboardLogoutLinks.forEach((link) => {
            link.addEventListener('click', () => {
                setDashboardExitAllowed(true);
            });
        });

        initializeDashboardBackGuard();

        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', () => {
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

        // Approve Modal
        function approveClearance(clearanceId) {
            document.getElementById('approveClearanceId').value = clearanceId;
            document.getElementById('approveModal').style.display = 'flex';
        }

        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
        }

        // Lacking Modal
        function openLackingModal(clearanceId) {
            document.getElementById('lackingClearanceId').value = clearanceId;
            document.getElementById('lackingModal').style.display = 'flex';
        }

        function closeLackingModal() {
            document.getElementById('lackingModal').style.display = 'none';
            document.getElementById('lackingComment').value = '';
        }

        // View Lacking Comment
        function viewLackingComment(comment, studentName, addedBy, date) {
            document.getElementById('lackingStudentName').textContent = studentName;
            document.getElementById('lackingAddedBy').textContent = addedBy || 'Unknown';
            document.getElementById('lackingDate').textContent = date ? new Date(date).toLocaleString() : 'Unknown';
            document.getElementById('lackingCommentText').textContent = comment;
            document.getElementById('viewLackingModal').style.display = 'flex';
        }

        function closeViewLackingModal() {
            document.getElementById('viewLackingModal').style.display = 'none';
        }

        // View Student Proof
        function viewStudentProof(clearanceId, proofFile, remarks) {
            const safeProofFile = String(proofFile || '')
                .replace(/\\/g, '/')
                .replace(/^(\.\.\/|\.\/)+/, '')
                .replace(/^\/+/, '');
            const proofUrl = safeProofFile ? `serve_proof.php?file=${encodeURIComponent(safeProofFile)}` : '';
            const fileExt = safeProofFile.split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'gif'].includes(fileExt);

            let previewHtml = '';
            if (isImage) {
                previewHtml = `<img src="${proofUrl}" style="max-width: 100%; max-height: 400px; border-radius: 8px; cursor: pointer;" onclick="window.open('${proofUrl}', '_blank')">`;
            } else {
                previewHtml = `<a href="${proofUrl}" target="_blank" class="btn btn-primary"><i class="fas fa-download"></i> Download File</a>`;
            }

            document.getElementById('studentProofPreview').innerHTML = previewHtml;
            document.getElementById('studentProofRemarks').textContent = remarks || 'No remarks provided';

            // Fetch student info
            fetch(`../get_clearance_info.php?clearance_id=${encodeURIComponent(clearanceId)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('studentProofInfo').innerHTML = `
                            <p><strong>Student:</strong> ${data.student_name}</p>
                            <p><strong>ID:</strong> ${data.ismis_id}</p>
                            <p><strong>Uploaded:</strong> ${data.uploaded_at ? new Date(data.uploaded_at).toLocaleString() : 'Unknown'}</p>
                        `;
                    }
                })
                .catch(() => {
                    document.getElementById('studentProofInfo').innerHTML = '<p>Student information unavailable</p>';
                });

            document.getElementById('viewStudentProofModal').style.display = 'flex';
        }

        function closeViewStudentProofModal() {
            document.getElementById('viewStudentProofModal').style.display = 'none';
        }

        // View Librarian Proof
        function viewLibrarianProof(proofFile, remarks, uploadedBy, date) {
            const safeProofFile = String(proofFile || '')
                .replace(/\\/g, '/')
                .replace(/^(\.\.\/|\.\/)+/, '')
                .replace(/^\/+/, '');
            const proofUrl = safeProofFile ? `serve_proof.php?file=${encodeURIComponent(safeProofFile)}` : '';
            const fileExt = safeProofFile.split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'gif'].includes(fileExt);

            let previewHtml = '';
            if (isImage) {
                previewHtml = `<img src="${proofUrl}" style="max-width: 100%; max-height: 400px; border-radius: 8px; cursor: pointer;" onclick="window.open('${proofUrl}', '_blank')">`;
            } else {
                previewHtml = `<a href="${proofUrl}" target="_blank" class="btn btn-primary"><i class="fas fa-download"></i> Download File</a>`;
            }

            document.getElementById('librarianProofPreview').innerHTML = previewHtml;
            document.getElementById('librarianProofRemarks').textContent = remarks || 'No remarks provided';
            document.getElementById('librarianProofInfo').innerHTML = `
                <p><strong>Uploaded by:</strong> ${uploadedBy || 'Unknown'}</p>
                <p><strong>Date:</strong> ${date ? new Date(date).toLocaleString() : 'Unknown'}</p>
            `;

            document.getElementById('viewLibrarianProofModal').style.display = 'flex';
        }

        function closeViewLibrarianProofModal() {
            document.getElementById('viewLibrarianProofModal').style.display = 'none';
        }

        // Undo Modal
        function openUndoModal(record) {
            if (typeof record !== 'object' || record === null) {
                record = {
                    clearanceId: arguments[0],
                    studentName: arguments[1],
                    status: arguments[2]
                };
            }

            document.getElementById('undoModal').style.display = 'flex';
            document.getElementById('undoClearanceId').value = record.clearanceId;

            const infoDiv = document.getElementById('undoStudentInfo');
            infoDiv.innerHTML = `
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <div style="display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: center;">
                        <p><strong>Student:</strong> ${escapeHtml(record.studentName || 'Unknown')}</p>
                        <span class="status-badge ${record.status === 'Approved' ? 'status-approved' : 'status-rejected'}">${escapeHtml(record.status || 'Unknown')}</span>
                    </div>
                    <p><strong>Student ID:</strong> ${escapeHtml(record.studentId || 'N/A')}</p>
                    <p><strong>Clearance ID:</strong> ${escapeHtml(record.clearanceId || 'N/A')}</p>
                    <p><strong>Type:</strong> ${escapeHtml(record.clearanceType || 'N/A')}</p>
                    <p><strong>Course:</strong> ${escapeHtml(record.courseName || 'N/A')}</p>
                    <p><strong>Period:</strong> ${escapeHtml(record.periodLabel || 'Not specified')}</p>
                    <p><strong>Processed:</strong> ${escapeHtml(record.processedDate || 'N/A')}</p>
                    <div class="record-flag-list">
                        ${record.hadLacking ? '<span class="badge badge-lacking">Had lacking comment</span>' : ''}
                        ${record.hadStudentProof ? '<span class="badge badge-proof">Had student proof</span>' : ''}
                    </div>
                    <p><small>Please provide a clear reason for undoing this processed clearance.</small></p>
                </div>
            `;
        }

        function closeUndoModal() {
            document.getElementById('undoModal').style.display = 'none';
            document.getElementById('undoReason').value = '';
        }

        function closeProgressModal() {
            setProgressModalTitle('fas fa-tasks', 'Student Clearance Progress');
            document.getElementById('progressModal').style.display = 'none';
        }

        // View Student Progress
        function viewStudentProgress(userId, semester, schoolYear, studentName, studentId, course, college, address, contact, age) {
            const modal = document.getElementById('progressModal');
            const modalBody = document.getElementById('progressModalBody');
            const periodLabel = [semester, schoolYear].filter(Boolean).join(' ');

            setProgressModalTitle('fas fa-tasks', 'Student Clearance Progress');
            modal.style.display = 'flex';
            modalBody.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading student progress...</p>
                </div>
            `;

            // Fetch student's clearance progress from all offices
            fetch(`../get_student_progress.php?user_id=${encodeURIComponent(userId)}&semester=${encodeURIComponent(semester)}&school_year=${encodeURIComponent(schoolYear)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let officesHtml = '';
                        data.offices.forEach(office => {
                            officesHtml += `
                                <tr>
                                    <td>${escapeHtml(office.office_name || 'Unknown')}</td>
                                    <td><span class="status-badge ${getStatusClass(office.status)}">${escapeHtml(toTitleCase(office.status || 'pending'))}</span></td>
                                    <td>${escapeHtml(office.processed_date || 'Not processed')}</td>
                                    <td>${escapeHtml(office.remarks || 'None')}</td>
                                </tr>
                            `;
                        });

                        modalBody.innerHTML = `
                            <div style="display: flex; flex-direction: column; gap: 20px;">
                                <div class="student-info-card">
                                    <div class="student-info-header">
                                        <h4><i class="fas fa-user-graduate"></i> Student Information</h4>
                                        <span class="badge badge-primary">ID: ${escapeHtml(studentId || 'N/A')}</span>
                                    </div>
                                    ${periodLabel ? `<div style="margin-bottom: 15px;"><span class="badge badge-info">Period: ${escapeHtml(periodLabel)}</span></div>` : ''}
                                    <div class="student-info-grid">
                                        <div class="student-info-item">
                                            <span class="label">Full Name</span>
                                            <span class="value">${escapeHtml(studentName || 'N/A')}</span>
                                        </div>
                                        <div class="student-info-item">
                                            <span class="label">College</span>
                                            <span class="value">${escapeHtml(college || 'N/A')}</span>
                                        </div>
                                        <div class="student-info-item">
                                            <span class="label">Course</span>
                                            <span class="value">${escapeHtml(course || 'N/A')}</span>
                                        </div>
                                        <div class="student-info-item">
                                            <span class="label">Age</span>
                                            <span class="value">${escapeHtml(age || 'N/A')}</span>
                                        </div>
                                        <div class="student-info-item">
                                            <span class="label">Contact</span>
                                            <span class="value">${escapeHtml(contact || 'N/A')}</span>
                                        </div>
                                        <div class="student-info-item">
                                            <span class="label">Address</span>
                                            <span class="value">${escapeHtml(address || 'N/A')}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="compact-table">
                                        <thead>
                                            <tr>
                                                <th>Office</th>
                                                <th>Status</th>
                                                <th>Processed Date</th>
                                                <th>Remarks</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${officesHtml}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        `;
                    } else {
                        modalBody.innerHTML = `
                            <div class="empty-state">
                                <i class="fas fa-exclamation-circle"></i>
                                <h3>Error loading progress</h3>
                                <p>${escapeHtml(data.message || 'Unable to load student progress')}</p>
                            </div>
                        `;
                    }
                })
                .catch(() => {
                    modalBody.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-circle"></i>
                            <h3>Error</h3>
                            <p>Failed to load student progress</p>
                        </div>
                    `;
                });
        }

        function viewStudentRecords(userId) {
            const modal = document.getElementById('progressModal');
            const modalBody = document.getElementById('progressModalBody');

            setProgressModalTitle('fas fa-address-card', 'Student Records Overview');
            modal.style.display = 'flex';
            modalBody.innerHTML = `
                <div class="empty-state">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading student records...</p>
                </div>
            `;

            fetch(`../get_student_records.php?user_id=${encodeURIComponent(userId)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const student = data.student || {};
                        const summary = data.summary || {};
                        const records = Array.isArray(data.records) ? data.records : [];

                        const recordsRows = records.length > 0
                            ? records.map(record => {
                                const period = [record.semester, record.school_year].filter(Boolean).join(' ') || 'N/A';
                                const flags = [];

                                if (record.lacking_comment) {
                                    flags.push('<span class="badge badge-lacking">Lacking</span>');
                                }
                                if (record.student_proof_file) {
                                    flags.push('<span class="badge badge-proof">Student proof</span>');
                                }
                                if (record.proof_file) {
                                    flags.push('<span class="badge badge-info">Office proof</span>');
                                }

                                return `
                                    <tr>
                                        <td>${escapeHtml(record.office_name || 'Unknown')}</td>
                                        <td><span class="type-badge">${escapeHtml(toTitleCase(record.clearance_type || 'Clearance'))}</span></td>
                                        <td>${escapeHtml(period)}</td>
                                        <td><span class="status-badge ${getStatusClass(record.status)}">${escapeHtml(toTitleCase(record.status || 'pending'))}</span></td>
                                        <td><div class="record-flag-list">${flags.join('') || '<span class="muted-inline">None</span>'}</div></td>
                                        <td>${escapeHtml(record.updated_label || 'N/A')}</td>
                                    </tr>
                                `;
                            }).join('')
                            : `
                                <tr>
                                    <td colspan="6" class="muted-inline" style="text-align: center;">No clearance records found for this student yet.</td>
                                </tr>
                            `;

                        modalBody.innerHTML = `
                            <div style="display: flex; flex-direction: column; gap: 20px;">
                                <div class="student-info-card">
                                    <div class="student-info-header">
                                        <h4><i class="fas fa-address-card"></i> Student Record Overview</h4>
                                        <span class="badge badge-primary">ID: ${escapeHtml(student.ismis_id || 'N/A')}</span>
                                    </div>
                                    <div class="student-info-grid">
                                        <div class="student-info-item">
                                            <span class="label">Full Name</span>
                                            <span class="value">${escapeHtml(`${student.fname || ''} ${student.lname || ''}`.trim() || 'N/A')}</span>
                                        </div>
                                        <div class="student-info-item">
                                            <span class="label">College</span>
                                            <span class="value">${escapeHtml(student.college_name || 'N/A')}</span>
                                        </div>
                                        <div class="student-info-item">
                                            <span class="label">Course</span>
                                            <span class="value">${escapeHtml(student.course_name || 'N/A')}</span>
                                        </div>
                                        <div class="student-info-item">
                                            <span class="label">Email</span>
                                            <span class="value">${escapeHtml(student.emails || student.email || 'N/A')}</span>
                                        </div>
                                        <div class="student-info-item">
                                            <span class="label">Contact</span>
                                            <span class="value">${escapeHtml(student.contacts || 'N/A')}</span>
                                        </div>
                                        <div class="student-info-item">
                                            <span class="label">Address</span>
                                            <span class="value">${escapeHtml(student.address || 'N/A')}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="summary-grid" style="margin: 0;">
                                    <div class="summary-card">
                                        <div class="label">Total Records</div>
                                        <div class="value">${escapeHtml(summary.total_records ?? 0)}</div>
                                        <div class="meta">All clearance entries for this student</div>
                                    </div>
                                    <div class="summary-card">
                                        <div class="label">Pending</div>
                                        <div class="value">${escapeHtml(summary.pending_count ?? 0)}</div>
                                        <div class="meta">Still waiting on an office action</div>
                                    </div>
                                    <div class="summary-card">
                                        <div class="label">Approved</div>
                                        <div class="value">${escapeHtml(summary.approved_count ?? 0)}</div>
                                        <div class="meta">Successfully cleared offices</div>
                                    </div>
                                    <div class="summary-card">
                                        <div class="label">Rejected</div>
                                        <div class="value">${escapeHtml(summary.rejected_count ?? 0)}</div>
                                        <div class="meta">Returned for follow-up requirements</div>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="compact-table">
                                        <thead>
                                            <tr>
                                                <th>Office</th>
                                                <th>Type</th>
                                                <th>Period</th>
                                                <th>Status</th>
                                                <th>Flags</th>
                                                <th>Last Updated</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${recordsRows}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        `;
                    } else {
                        modalBody.innerHTML = `
                            <div class="empty-state">
                                <i class="fas fa-exclamation-circle"></i>
                                <h3>Unable to load student records</h3>
                                <p>${escapeHtml(data.message || 'The student record could not be retrieved right now.')}</p>
                            </div>
                        `;
                    }
                })
                .catch(() => {
                    modalBody.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-exclamation-circle"></i>
                            <h3>Error loading student records</h3>
                            <p>Please try again in a moment.</p>
                        </div>
                    `;
                });
        }

        // Bulk Actions
        const selectAllCheckbox = document.getElementById('selectAll');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function (e) {
                document.querySelectorAll('.clearance-checkbox:not(:disabled)').forEach(cb => {
                    if (cb.closest('tr')?.style.display === 'none') {
                        cb.checked = false;
                        return;
                    }
                    cb.checked = e.target.checked;
                });
                updatePendingSelectionState();
            });
        }

        document.querySelectorAll('.clearance-checkbox').forEach(cb => {
            cb.addEventListener('change', updatePendingSelectionState);
        });

        function updatePendingSelectionState() {
            const selectionLabel = document.getElementById('pendingSelectionCount');
            const readyCheckboxes = Array.from(document.querySelectorAll('.clearance-checkbox:not(:disabled)'));
            const visibleReadyCheckboxes = readyCheckboxes.filter(cb => cb.closest('tr')?.style.display !== 'none');
            const selectedReadyCheckboxes = visibleReadyCheckboxes.filter(cb => cb.checked);

            if (selectionLabel) {
                selectionLabel.textContent = `${selectedReadyCheckboxes.length} selected`;
            }

            if (selectAllCheckbox) {
                selectAllCheckbox.disabled = visibleReadyCheckboxes.length === 0;
                selectAllCheckbox.checked = visibleReadyCheckboxes.length > 0 && selectedReadyCheckboxes.length === visibleReadyCheckboxes.length;
                selectAllCheckbox.indeterminate = selectedReadyCheckboxes.length > 0 && selectedReadyCheckboxes.length < visibleReadyCheckboxes.length;
            }
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

            if (confirm(`Are you sure you want to approve ${selected.length} clearance(s)?`)) {
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

                const submitInput = document.createElement('input');
                submitInput.type = 'hidden';
                submitInput.name = 'bulk_approve';
                submitInput.value = '1';
                form.appendChild(submitInput);

                document.body.appendChild(form);
                form.submit();
            }
        }

        // Filter functions
        function filterPending() {
            const type = document.getElementById('pendingTypeFilter').value.toLowerCase();
            const state = document.getElementById('pendingStateFilter').value.toLowerCase();
            const search = document.getElementById('pendingSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#pendingTable tbody tr');

            rows.forEach(row => {
                const typeCell = row.getAttribute('data-type')?.toLowerCase() || '';
                const stateCell = row.getAttribute('data-state')?.toLowerCase() || '';
                const nameCell = row.getAttribute('data-name') || '';
                const idCell = row.getAttribute('data-id') || '';
                const checkbox = row.querySelector('.clearance-checkbox');

                const matchesType = !type || typeCell.includes(type);
                const matchesState = !state || stateCell === state;
                const matchesSearch = !search ||
                    nameCell.includes(search) ||
                    idCell.includes(search);

                const shouldShow = matchesType && matchesState && matchesSearch;

                row.style.display = shouldShow ? '' : 'none';
                if (!shouldShow && checkbox) {
                    checkbox.checked = false;
                }
            });

            setFilterFeedback(rows, 'pendingVisibleCount', 'pendingNoResults', 'pending clearances');
            updatePendingSelectionState();
        }

        function clearPendingFilters() {
            document.getElementById('pendingTypeFilter').value = '';
            document.getElementById('pendingStateFilter').value = '';
            document.getElementById('pendingSearch').value = '';
            filterPending();
        }

        function filterHistory() {
            const semester = document.getElementById('historySemesterFilter').value;
            const year = document.getElementById('historyYearFilter').value;
            const type = document.getElementById('historyTypeFilter').value.toLowerCase();
            const status = document.getElementById('historyStatusFilter').value.toLowerCase();
            const search = document.getElementById('historySearch').value.toLowerCase();

            const rows = document.querySelectorAll('#historyTable tbody tr');

            rows.forEach(row => {
                const rowSemester = row.getAttribute('data-semester') || '';
                const rowYear = row.getAttribute('data-year') || '';
                const rowType = row.getAttribute('data-type')?.toLowerCase() || '';
                const rowStatus = row.getAttribute('data-status')?.toLowerCase() || '';
                const rowName = row.getAttribute('data-name') || '';
                const rowId = row.getAttribute('data-id') || '';

                const matchesSemester = !semester || rowSemester === semester;
                const matchesYear = !year || rowYear === year;
                const matchesType = !type || rowType.includes(type);
                const matchesStatus = !status || rowStatus.includes(status);
                const matchesSearch = !search ||
                    rowName.includes(search) ||
                    rowId.includes(search);

                row.style.display = matchesSemester && matchesYear && matchesType && matchesStatus && matchesSearch ? '' : 'none';
            });

            setFilterFeedback(rows, 'historyVisibleCount', 'historyNoResults', 'history entries');
        }

        function clearHistoryFilters() {
            document.getElementById('historySemesterFilter').value = '';
            document.getElementById('historyYearFilter').value = '';
            document.getElementById('historyTypeFilter').value = '';
            document.getElementById('historyStatusFilter').value = '';
            document.getElementById('historySearch').value = '';
            filterHistory();
        }

        // Undo filter function
        function clearUndoFilters() {
            const undoSearch = document.getElementById('undoSearch');
            const undoTypeFilter = document.getElementById('undoTypeFilter');
            const undoStatusFilter = document.getElementById('undoStatusFilter');

            if (undoSearch) {
                undoSearch.value = '';
            }
            if (undoTypeFilter) {
                undoTypeFilter.value = '';
            }
            if (undoStatusFilter) {
                undoStatusFilter.value = '';
            }

            filterUndo();
        }

        function filterUndo() {
            const typeFilter = document.getElementById('undoTypeFilter')?.value.toLowerCase() || '';
            const statusFilter = document.getElementById('undoStatusFilter')?.value.toLowerCase() || '';
            const searchFilter = document.getElementById('undoSearch')?.value.toLowerCase() || '';

            const items = document.querySelectorAll('#undoGrid .undo-item');

            items.forEach(item => {
                const itemType = item.getAttribute('data-type')?.toLowerCase() || '';
                const itemStatus = item.getAttribute('data-status')?.toLowerCase() || '';
                const itemName = item.getAttribute('data-name') || '';
                const itemId = item.getAttribute('data-id') || '';

                const matchesType = !typeFilter || itemType.includes(typeFilter);
                const matchesStatus = !statusFilter || itemStatus.includes(statusFilter);
                const matchesSearch = !searchFilter ||
                    itemName.includes(searchFilter) ||
                    itemId.includes(searchFilter);

                item.style.display = matchesType && matchesStatus && matchesSearch ? 'flex' : 'none';
            });

            setFilterFeedback(items, 'undoVisibleCount', 'undoNoResults', 'processed clearances');
        }

        // Student search functions
        function searchStudents() {
            const search = document.getElementById('studentSearch').value.toLowerCase();
            const selectedCourse = document.getElementById('studentCourseFilter')?.value || '';
            const cards = document.querySelectorAll('.student-card');

            cards.forEach(card => {
                const name = card.getAttribute('data-name') || '';
                const id = card.getAttribute('data-id') || '';
                const course = card.getAttribute('data-course') || '';
                const matchesSearch = !search || name.includes(search) || id.includes(search);
                const matchesCourse = !selectedCourse || course === selectedCourse;

                card.style.display = matchesSearch && matchesCourse ? 'block' : 'none';
            });

            setFilterFeedback(cards, 'studentsVisibleCount', 'studentsNoResults', 'students');
        }

        function clearStudentSearch() {
            document.getElementById('studentSearch').value = '';
            const studentCourseFilter = document.getElementById('studentCourseFilter');
            if (studentCourseFilter) {
                studentCourseFilter.value = '';
            }
            const cards = document.querySelectorAll('.student-card');
            cards.forEach(card => {
                card.style.display = 'block';
            });
            setFilterFeedback(cards, 'studentsVisibleCount', 'studentsNoResults', 'students');
        }

        document.getElementById('pendingTypeFilter')?.addEventListener('change', filterPending);
        document.getElementById('pendingStateFilter')?.addEventListener('change', filterPending);
        document.getElementById('pendingSearch')?.addEventListener('input', filterPending);
        document.getElementById('historySemesterFilter')?.addEventListener('change', filterHistory);
        document.getElementById('historyYearFilter')?.addEventListener('change', filterHistory);
        document.getElementById('historyTypeFilter')?.addEventListener('change', filterHistory);
        document.getElementById('historyStatusFilter')?.addEventListener('change', filterHistory);
        document.getElementById('historySearch')?.addEventListener('input', filterHistory);
        document.getElementById('undoSearch')?.addEventListener('input', filterUndo);
        document.getElementById('undoTypeFilter')?.addEventListener('change', filterUndo);
        document.getElementById('undoStatusFilter')?.addEventListener('change', filterUndo);
        document.getElementById('studentSearch')?.addEventListener('input', searchStudents);
        document.getElementById('studentCourseFilter')?.addEventListener('change', searchStudents);

        filterPending();
        filterHistory();
        filterUndo();
        searchStudents();

        function searchStudent() {
            const search = document.getElementById('quickSearch').value;
            if (!search) {
                alert('Please enter a search term');
                return;
            }

            switchTab('students');
            document.getElementById('studentSearch').value = search;
            const studentCourseFilter = document.getElementById('studentCourseFilter');
            if (studentCourseFilter) {
                studentCourseFilter.value = '';
            }
            searchStudents();
            showToast('Searching for: ' + search, 'info');
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

        // Avatar upload
        const avatarInput = document.getElementById('avatarUpload');
        const avatarContainer = document.getElementById('avatarContainer');
        const uploadProgress = document.getElementById('uploadProgress');
        const profileImage = document.getElementById('profileImage');
        const avatarIcon = document.getElementById('avatarIcon');
        const avatarCooldownStatusUrl = '../upload_avatar.php?cooldown_status=1';
        let avatarCooldownState = null;

        function fetchAvatarCooldownStatus(forceRefresh = false) {
            if (!forceRefresh && avatarCooldownState !== null) {
                return Promise.resolve(avatarCooldownState);
            }

            return fetch(avatarCooldownStatusUrl, {
                method: 'GET',
                credentials: 'same-origin'
            })
                .then(response => response.json())
                .then(data => {
                    if (data && data.success) {
                        avatarCooldownState = {
                            canUpload: !!data.can_upload,
                            message: String(data.message || '')
                        };
                        return avatarCooldownState;
                    }

                    avatarCooldownState = { canUpload: true, message: '' };
                    return avatarCooldownState;
                })
                .catch(() => ({ canUpload: true, message: '' }));
        }

        if (avatarContainer) {
            avatarContainer.addEventListener('click', function () {
                fetchAvatarCooldownStatus()
                    .then(status => {
                        if (!status.canUpload) {
                            showToast(status.message || 'You can update your profile picture after the cooldown period.', 'error');
                            return;
                        }

                        avatarInput.click();
                    });
            });
        }

        if (avatarInput) {
            avatarInput.addEventListener('change', function (e) {
                const file = e.target.files[0];

                if (!file) return;

                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
                if (!validTypes.includes(file.type)) {
                    showToast('Please select a valid image file (JPG, PNG, GIF)', 'error');
                    avatarInput.value = '';
                    return;
                }

                uploadProgress.classList.add('show');

                const formData = new FormData();
                formData.append('avatar', file);

                fetch('../upload_avatar.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        uploadProgress.classList.remove('show');

                        if (data.success) {
                            const avatarPath = '../' + data.filepath + '?t=' + new Date().getTime();

                            if (profileImage) {
                                profileImage.src = avatarPath;
                                profileImage.style.display = 'block';
                            }
                            if (avatarIcon) {
                                avatarIcon.style.display = 'none';
                            }

                            const headerAvatarContainer = document.querySelector('.header .user-avatar');
                            const headerAvatar = headerAvatarContainer ? headerAvatarContainer.querySelector('img') : null;
                            if (headerAvatar) {
                                headerAvatar.src = avatarPath;
                            } else if (headerAvatarContainer) {
                                headerAvatarContainer.innerHTML = `<img src="${avatarPath}" alt="Profile">`;
                            }

                            avatarInput.value = '';
                            avatarCooldownState = null;
                            showToast('Profile picture updated successfully!', 'success');
                        } else {
                            avatarInput.value = '';
                            if (data && data.can_upload === false) {
                                avatarCooldownState = {
                                    canUpload: false,
                                    message: String(data.message || '')
                                };
                            }
                            showToast(data.message || 'Upload failed', 'error');
                        }
                    })
                    .catch(error => {
                        uploadProgress.classList.remove('show');
                        avatarInput.value = '';
                        showToast('Upload failed. Please try again.', 'error');
                        console.error('Error:', error);
                    });
            });
        }

        // Close modals when clicking outside
        window.onclick = function (event) {
            const modals = ['approveModal', 'lackingModal', 'viewLackingModal', 'viewStudentProofModal', 'viewLibrarianProofModal', 'undoModal', 'progressModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        };

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Helper function for status class
        function getStatusClass(status) {
            return status == 'approved' ? 'status-approved' : (status == 'rejected' ? 'status-rejected' : 'status-pending');
        }
    </script>
</body>

</html>


