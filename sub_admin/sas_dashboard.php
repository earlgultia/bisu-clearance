<?php
// sas_dashboard.php - Director of SAS Dashboard for BISU Online Clearance System
// Location: C:\xampp\htdocs\clearance\sub_admin\sas_dashboard.php

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

// Check if user is sub_admin
if ($_SESSION['user_role'] !== 'sub_admin') {
    header("Location: ../index.php");
    exit();
}

// Get database instance
$db = Database::getInstance();

// Verify that this sub_admin is assigned to Director_SAS office
$db->query("SELECT sao.*, o.office_name, o.office_id 
            FROM sub_admin_offices sao 
            JOIN offices o ON sao.office_id = o.office_id 
            WHERE sao.users_id = :user_id");
$db->bind(':user_id', $_SESSION['user_id']);
$office_check = $db->single();

// Check if user has Director_SAS office
$is_sas_director = false;
$sas_office_id = null;

if ($office_check && $office_check['office_name'] === 'Director_SAS') {
    $is_sas_director = true;
    $sas_office_id = $office_check['office_id'];
}

if (!$is_sas_director) {
    // Not authorized for SAS director dashboard
    header("Location: ../index.php");
    exit();
}

// Get director information from session
$director_id = $_SESSION['user_id'];
$director_name = $_SESSION['user_name'] ?? '';
$director_email = $_SESSION['user_email'] ?? '';
$director_fname = $_SESSION['user_fname'] ?? '';
$director_lname = $_SESSION['user_lname'] ?? '';

// Initialize variables
$success = '';
$error = '';
$active_tab = $_GET['tab'] ?? 'dashboard';
$profile_pic = null;

// ============================================
// HANDLE ADD ORGANIZATION
// ============================================
if (isset($_POST['add_organization'])) {
    $org_name = trim($_POST['org_name'] ?? '');
    $org_type = $_POST['org_type'] ?? '';
    $org_email = trim($_POST['org_email'] ?? '');
    $org_password = $_POST['org_password'] ?? '';

    if (empty($org_name) || empty($org_type) || empty($org_email) || empty($org_password)) {
        $error = "All fields are required.";
    } elseif (!filter_var($org_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($org_password) < 8) {
        $error = "Password must be at least 8 characters.";
    } else {
        try {
            $db = Database::getInstance();

            // Check if organization already exists
            $db->query("SELECT org_id FROM student_organizations WHERE org_name = :name OR org_email = :email");
            $db->bind(':name', $org_name);
            $db->bind(':email', $org_email);
            if ($db->single()) {
                $error = "Organization name or email already exists.";
            } else {
                // Hash the password
                $hashed_password = password_hash($org_password, PASSWORD_DEFAULT);

                // Determine office_id based on org_type
                $office_id = null;
                switch ($org_type) {
                    case 'clinic':
                        $office_id = 6;
                        break;
                    case 'town':
                        $office_id = 7;
                        break;
                    case 'college':
                        $office_id = 8;
                        break;
                    case 'ssg':
                        $office_id = 9;
                        break;
                    default:
                        $office_id = null;
                }

                // Insert into student_organizations table
                $db->query("INSERT INTO student_organizations (
                    org_name, 
                    org_type, 
                    org_email, 
                    org_password, 
                    status, 
                    office_id, 
                    dashboard_type,
                    created_by, 
                    created_at
                ) VALUES (
                    :name, 
                    :type, 
                    :email, 
                    :password, 
                    'active', 
                    :office_id, 
                    :dashboard_type, 
                    :created_by, 
                    NOW()
                )");

                $db->bind(':name', $org_name);
                $db->bind(':type', $org_type);
                $db->bind(':email', $org_email);
                $db->bind(':password', $hashed_password);
                $db->bind(':office_id', $office_id);
                $db->bind(':dashboard_type', $org_type);
                $db->bind(':created_by', $director_id);

                if ($db->execute()) {
                    $org_id = $db->lastInsertId();

                    // Log the activity
                    if (class_exists('ActivityLogModel')) {
                        $logModel = new ActivityLogModel();
                        $logModel->log($director_id, 'ADD_ORGANIZATION', "Added new organization: $org_name ($org_type) with ID: $org_id");
                    }

                    $_SESSION['success_message'] = "Organization added successfully!<br>Email: $org_email";
                    header("Location: sas_dashboard.php?tab=organizations");
                    exit();
                } else {
                    $error = "Failed to add organization.";
                }
            }
        } catch (Exception $e) {
            error_log("Error adding organization: " . $e->getMessage());
            $error = "Database error occurred.";
        }
    }
}

// ============================================
// HANDLE ADD STAFF
// ============================================
if (isset($_POST['add_staff'])) {
    $fname = trim($_POST['fname'] ?? '');
    $lname = trim($_POST['lname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $assignment = $_POST['assignment'] ?? '';

    if (empty($fname) || empty($lname) || empty($email) || empty($password) || empty($assignment)) {
        $error = "All required fields must be filled.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } else {
        try {
            $db = Database::getInstance();

            // Check if email already exists
            $db->query("SELECT users_id FROM users WHERE emails = :email");
            $db->bind(':email', $email);
            if ($db->single()) {
                $error = "Email already exists.";
            } else {
                // Get office_staff role ID
                $db->query("SELECT user_role_id FROM user_role WHERE user_role_name = 'office_staff'");
                $role = $db->single();

                if ($role) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    // Insert new staff
                    $db->query("INSERT INTO users (
                        fname, lname, emails, password, user_role_id, office_id, 
                        is_active, assignment, created_at
                    ) VALUES (
                        :fname, :lname, :email, :password, :role_id, :office_id, 1, :assignment, NOW()
                    )");

                    $db->bind(':fname', $fname);
                    $db->bind(':lname', $lname);
                    $db->bind(':email', $email);
                    $db->bind(':password', $hashed_password);
                    $db->bind(':role_id', $role['user_role_id']);
                    $db->bind(':office_id', $sas_office_id);
                    $db->bind(':assignment', $assignment);

                    if ($db->execute()) {
                        $staff_id = $db->lastInsertId();

                        // Get assignment display name
                        $assignment_names = [
                            'clinic' => 'Clinic',
                            'town_org' => 'Town Organizations',
                            'college_org' => 'College Organizations',
                            'ssg' => 'Supreme Student Government'
                        ];
                        $display_name = $assignment_names[$assignment] ?? $assignment;

                        // Log the activity
                        if (class_exists('ActivityLogModel')) {
                            $logModel = new ActivityLogModel();
                            $logModel->log($director_id, 'ADD_STAFF', "Added new staff: $fname $lname for $display_name");
                        }

                        $_SESSION['success_message'] = "Staff account created successfully for {$display_name}!";
                        header("Location: sas_dashboard.php?tab=staff");
                        exit();
                    } else {
                        $error = "Failed to add staff.";
                    }
                } else {
                    $error = "System error: Staff role not found.";
                }
            }
        } catch (Exception $e) {
            error_log("Error adding staff: " . $e->getMessage());
            $error = "Database error occurred.";
        }
    }
}

// ============================================
// HANDLE UPDATE ORGANIZATION
// ============================================
if (isset($_POST['update_organization'])) {
    $org_id = (int) ($_POST['org_id'] ?? 0);
    $org_name = trim($_POST['org_name'] ?? '');
    $org_type = trim($_POST['org_type'] ?? '');
    $org_email = trim($_POST['org_email'] ?? '');
    $org_status = trim($_POST['org_status'] ?? 'active');
    $org_password = $_POST['org_password'] ?? '';

    $valid_types = ['clinic', 'town', 'college', 'ssg'];
    $valid_status = ['active', 'inactive'];

    if ($org_id <= 0 || empty($org_name) || empty($org_type) || empty($org_email) || empty($org_status)) {
        $error = "All required organization fields must be filled.";
    } elseif (!in_array($org_type, $valid_types, true)) {
        $error = "Invalid organization type.";
    } elseif (!in_array($org_status, $valid_status, true)) {
        $error = "Invalid organization status.";
    } elseif (!filter_var($org_email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid organization email address.";
    } elseif (!empty($org_password) && strlen($org_password) < 8) {
        $error = "Organization password must be at least 8 characters.";
    } else {
        try {
            $db = Database::getInstance();

            $db->query("SELECT org_id, org_name FROM student_organizations WHERE org_id = :id");
            $db->bind(':id', $org_id);
            $existing_org = $db->single();

            if (!$existing_org) {
                $error = "Organization not found.";
            } else {
                $db->query("SELECT org_id FROM student_organizations WHERE (org_name = :name OR org_email = :email) AND org_id != :id");
                $db->bind(':name', $org_name);
                $db->bind(':email', $org_email);
                $db->bind(':id', $org_id);

                if ($db->single()) {
                    $error = "Another organization already uses this name or email.";
                } else {
                    $office_id = null;
                    switch ($org_type) {
                        case 'clinic':
                            $office_id = 6;
                            break;
                        case 'town':
                            $office_id = 7;
                            break;
                        case 'college':
                            $office_id = 8;
                            break;
                        case 'ssg':
                            $office_id = 9;
                            break;
                    }

                    if (!empty($org_password)) {
                        $db->query("UPDATE student_organizations
                                    SET org_name = :name,
                                        org_type = :type,
                                        org_email = :email,
                                        org_password = :password,
                                        status = :status,
                                        office_id = :office_id,
                                        dashboard_type = :dashboard_type
                                    WHERE org_id = :id");
                        $db->bind(':password', password_hash($org_password, PASSWORD_DEFAULT));
                    } else {
                        $db->query("UPDATE student_organizations
                                    SET org_name = :name,
                                        org_type = :type,
                                        org_email = :email,
                                        status = :status,
                                        office_id = :office_id,
                                        dashboard_type = :dashboard_type
                                    WHERE org_id = :id");
                    }

                    $db->bind(':name', $org_name);
                    $db->bind(':type', $org_type);
                    $db->bind(':email', $org_email);
                    $db->bind(':status', $org_status);
                    $db->bind(':office_id', $office_id);
                    $db->bind(':dashboard_type', $org_type);
                    $db->bind(':id', $org_id);

                    if ($db->execute()) {
                        if (class_exists('ActivityLogModel')) {
                            $logModel = new ActivityLogModel();
                            $logModel->log($director_id, 'UPDATE_ORGANIZATION', "Updated organization ID {$org_id}: {$org_name}");
                        }

                        $_SESSION['success_message'] = "Organization updated successfully!";
                        header("Location: sas_dashboard.php?tab=organizations");
                        exit();
                    } else {
                        $error = "Failed to update organization.";
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error updating organization: " . $e->getMessage());
            $error = "Database error occurred.";
        }
    }
}

// ============================================
// HANDLE UPDATE STAFF
// ============================================
if (isset($_POST['update_staff'])) {
    $staff_id = (int) ($_POST['staff_id'] ?? 0);
    $fname = trim($_POST['fname'] ?? '');
    $lname = trim($_POST['lname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $assignment = trim($_POST['assignment'] ?? '');
    $password = $_POST['password'] ?? '';

    $valid_assignments = ['clinic', 'town_org', 'college_org', 'ssg'];

    if ($staff_id <= 0 || empty($fname) || empty($lname) || empty($email) || empty($assignment)) {
        $error = "All required staff fields must be filled.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid staff email address.";
    } elseif (!in_array($assignment, $valid_assignments, true)) {
        $error = "Invalid staff assignment.";
    } elseif (!empty($password) && strlen($password) < 8) {
        $error = "Staff password must be at least 8 characters.";
    } else {
        try {
            $db = Database::getInstance();

            $db->query("SELECT users_id, fname, lname FROM users
                        WHERE users_id = :id
                        AND office_id = :office_id
                        AND user_role_id = (SELECT user_role_id FROM user_role WHERE user_role_name = 'office_staff')");
            $db->bind(':id', $staff_id);
            $db->bind(':office_id', $sas_office_id);
            $existing_staff = $db->single();

            if (!$existing_staff) {
                $error = "Staff member not found or not authorized for this office.";
            } else {
                $db->query("SELECT users_id FROM users WHERE emails = :email AND users_id != :id");
                $db->bind(':email', $email);
                $db->bind(':id', $staff_id);

                if ($db->single()) {
                    $error = "Another account already uses this email.";
                } else {
                    if (!empty($password)) {
                        $db->query("UPDATE users
                                    SET fname = :fname,
                                        lname = :lname,
                                        emails = :email,
                                        assignment = :assignment,
                                        password = :password
                                    WHERE users_id = :id");
                        $db->bind(':password', password_hash($password, PASSWORD_DEFAULT));
                    } else {
                        $db->query("UPDATE users
                                    SET fname = :fname,
                                        lname = :lname,
                                        emails = :email,
                                        assignment = :assignment
                                    WHERE users_id = :id");
                    }

                    $db->bind(':fname', $fname);
                    $db->bind(':lname', $lname);
                    $db->bind(':email', $email);
                    $db->bind(':assignment', $assignment);
                    $db->bind(':id', $staff_id);

                    if ($db->execute()) {
                        if (class_exists('ActivityLogModel')) {
                            $logModel = new ActivityLogModel();
                            $logModel->log($director_id, 'UPDATE_STAFF', "Updated staff ID {$staff_id}: {$fname} {$lname}");
                        }

                        $_SESSION['success_message'] = "Staff member updated successfully!";
                        header("Location: sas_dashboard.php?tab=staff");
                        exit();
                    } else {
                        $error = "Failed to update staff member.";
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error updating staff: " . $e->getMessage());
            $error = "Database error occurred.";
        }
    }
}

// ============================================
// HANDLE DELETE STAFF
// ============================================
if (isset($_POST['delete_staff'])) {
    $staff_id = (int) ($_POST['staff_id'] ?? 0);

    if ($staff_id > 0) {
        try {
            $db = Database::getInstance();

            $db->query("SELECT users_id, fname, lname FROM users
                        WHERE users_id = :id
                        AND office_id = :office_id
                        AND user_role_id = (SELECT user_role_id FROM user_role WHERE user_role_name = 'office_staff')");
            $db->bind(':id', $staff_id);
            $db->bind(':office_id', $sas_office_id);
            $staff = $db->single();

            if (!$staff) {
                $error = "Staff member not found or not authorized for this office.";
            } else {
                $db->query("DELETE FROM users WHERE users_id = :id");
                $db->bind(':id', $staff_id);

                if ($db->execute()) {
                    if (class_exists('ActivityLogModel')) {
                        $logModel = new ActivityLogModel();
                        $logModel->log($director_id, 'DELETE_STAFF', "Deleted staff: {$staff['fname']} {$staff['lname']}");
                    }

                    $_SESSION['success_message'] = "Staff member deleted successfully!";
                    header("Location: sas_dashboard.php?tab=staff");
                    exit();
                } else {
                    $error = "Failed to delete staff member.";
                }
            }
        } catch (Exception $e) {
            error_log("Error deleting staff: " . $e->getMessage());
            $error = "Database error occurred.";
        }
    }
}

// ============================================
// HANDLE APPROVE CLEARANCE
// ============================================
if (isset($_POST['approve_clearance'])) {
    $clearance_id = $_POST['clearance_id'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    if ($clearance_id) {
        try {
            $db = Database::getInstance();
            $db->beginTransaction();

            // Get current clearance info
            $db->query("SELECT c.*, u.fname, u.lname, u.ismis_id, u.course_id, u.college_id
                       FROM clearance c
                       JOIN users u ON c.users_id = u.users_id
                       WHERE c.clearance_id = :id");
            $db->bind(':id', $clearance_id);
            $current = $db->single();

            if (!$current) {
                throw new Exception("Clearance not found");
            }

            // Check if this is SAS office
            if ($current['office_id'] != $sas_office_id) {
                throw new Exception("You can only approve SAS clearances");
            }

            // Check if clearance is pending
            if ($current['status'] !== 'pending') {
                throw new Exception("This clearance has already been processed");
            }

            // Backfill missing organization_clearance rows for this exact SAS clearance.
            $current_clearance_id = (int) $current['clearance_id'];
            $current_college_id = isset($current['college_id']) ? (int) $current['college_id'] : 0;
            $college_org_condition = "1=1";
            if ($current_college_id > 0) {
                $college_org_condition = "(
                    so.org_type <> 'college'
                    OR so.college_id IS NULL
                    OR so.college_id = {$current_college_id}
                    OR NOT EXISTS (
                        SELECT 1
                        FROM student_organizations so_match
                        WHERE so_match.org_type = 'college'
                          AND COALESCE(so_match.status, 'active') = 'active'
                          AND so_match.college_id = {$current_college_id}
                    )
                )";
            }

            $syncOrgQuery = "INSERT INTO organization_clearance (clearance_id, org_id, office_id, status, created_at, updated_at)
                             SELECT {$current_clearance_id}, so.org_id, so.office_id, 'pending', NOW(), NOW()
                             FROM student_organizations so
                             LEFT JOIN organization_clearance oc
                               ON oc.clearance_id = {$current_clearance_id}
                              AND oc.org_id = so.org_id
                             WHERE COALESCE(so.status, 'active') = 'active'
                               AND so.office_id IS NOT NULL
                               AND {$college_org_condition}
                               AND oc.org_clearance_id IS NULL";
            $db->query($syncOrgQuery);
            if (!$db->execute()) {
                error_log("Warning: SAS approval sync failed for clearance_id=" . $current_clearance_id);
            }

            // Recover legacy invalid enum writes (approve/reject) stored as empty status.
            $db->query("UPDATE organization_clearance
                        SET status = CASE
                            WHEN LOWER(IFNULL(remarks, '')) REGEXP 'reject|declin|deny|disapprov' THEN 'rejected'
                            ELSE 'approved'
                        END,
                        updated_at = NOW()
                        WHERE clearance_id = :clearance_id
                        AND status = ''");
            $db->bind(':clearance_id', $current_clearance_id);
            if (!$db->execute()) {
                error_log("Warning: SAS status normalization failed for clearance_id=" . $current_clearance_id);
            }

                        // Reconcile clinic organization decision from the matching clinic-office clearance.
                        $db->query("UPDATE organization_clearance oc
                                                JOIN student_organizations so
                                                    ON oc.org_id = so.org_id
                                                 AND so.org_type = 'clinic'
                                                 AND COALESCE(so.status, 'active') = 'active'
                                                JOIN clearance c_sas
                                                    ON c_sas.clearance_id = oc.clearance_id
                                                 AND c_sas.office_id = :sas_office_id
                                                JOIN clearance c_clinic
                                                    ON c_clinic.users_id = c_sas.users_id
                                                 AND c_clinic.semester = c_sas.semester
                                                 AND c_clinic.school_year = c_sas.school_year
                                                 AND c_clinic.office_id = so.office_id
                                                SET oc.status = c_clinic.status,
                                                        oc.remarks = CONCAT(IFNULL(oc.remarks, ''), ' | Synced from clinic clearance #', c_clinic.clearance_id),
                                                        oc.processed_by = COALESCE(c_clinic.processed_by, oc.processed_by),
                                                        oc.processed_date = COALESCE(c_clinic.processed_date, oc.processed_date),
                                                        oc.updated_at = NOW()
                                                WHERE oc.clearance_id = :clearance_id
                                                    AND c_clinic.status IN ('approved', 'rejected')");
                        $db->bind(':sas_office_id', $sas_office_id);
                        $db->bind(':clearance_id', $current_clearance_id);
                        if (!$db->execute()) {
                                error_log("Warning: SAS clinic reconciliation failed for clearance_id=" . $current_clearance_id);
                        }

            // Check if all organizations under SAS have approved
            $db->query("SELECT COUNT(DISTINCT oc.org_id) as total,
                       COUNT(DISTINCT CASE WHEN oc.status = 'approved' THEN oc.org_id END) as approved
                       FROM organization_clearance oc
                       JOIN student_organizations so ON oc.org_id = so.org_id
                       WHERE oc.clearance_id = :clearance_id
                       AND COALESCE(so.status, 'active') = 'active'");
            $db->bind(':clearance_id', $current['clearance_id']);
            $org_check = $db->single();

            if ($org_check['total'] > 0 && $org_check['approved'] < $org_check['total']) {
                $db->rollback();
                $error = "Cannot approve yet. Some organizations under SAS have not approved this clearance.";
                throw new Exception("Organizations pending");
            }

            // Update the clearance
            $db->query("UPDATE clearance SET 
                        status = 'approved', 
                        remarks = CONCAT(IFNULL(remarks, ''), ' | SAS Approved: ', :remarks),
                        processed_by = :processed_by, 
                        processed_date = NOW(),
                        updated_at = NOW()
                        WHERE clearance_id = :id");
            $db->bind(':remarks', $remarks);
            $db->bind(':processed_by', $director_id);
            $db->bind(':id', $clearance_id);

            if ($db->execute()) {
                // Log the activity
                if (class_exists('ActivityLogModel')) {
                    $logModel = new ActivityLogModel();
                    $logModel->log($director_id, 'APPROVE_CLEARANCE', "Approved clearance ID: $clearance_id for student: {$current['fname']} {$current['lname']} ({$current['ismis_id']})");
                }

                $db->commit();
                $_SESSION['success_message'] = "Clearance approved successfully!";
                header("Location: sas_dashboard.php?tab=clearances");
                exit();
            } else {
                $db->rollback();
                $error = "Failed to approve clearance.";
            }
        } catch (Exception $e) {
            if ($e->getMessage() !== "Organizations pending") {
                $db->rollback();
                error_log("Error approving clearance: " . $e->getMessage());
                $error = "Database error occurred.";
            }
        }
    }
}

// ============================================
// HANDLE REJECT CLEARANCE
// ============================================
if (isset($_POST['reject_clearance'])) {
    $clearance_id = $_POST['clearance_id'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    if ($clearance_id) {
        try {
            $db = Database::getInstance();
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

            // Check if this is SAS office
            if ($current['office_id'] != $sas_office_id) {
                throw new Exception("You can only reject SAS clearances");
            }

            // Check if clearance is pending
            if ($current['status'] !== 'pending') {
                throw new Exception("This clearance has already been processed");
            }

            // Update the clearance
            $db->query("UPDATE clearance SET 
                        status = 'rejected', 
                        remarks = CONCAT(IFNULL(remarks, ''), ' | SAS Rejected: ', :remarks),
                        processed_by = :processed_by, 
                        processed_date = NOW(),
                        updated_at = NOW()
                        WHERE clearance_id = :id");
            $db->bind(':remarks', $remarks);
            $db->bind(':processed_by', $director_id);
            $db->bind(':id', $clearance_id);

            if ($db->execute()) {
                // Log the activity
                if (class_exists('ActivityLogModel')) {
                    $logModel = new ActivityLogModel();
                    $logModel->log($director_id, 'REJECT_CLEARANCE', "Rejected clearance ID: $clearance_id for student: {$current['fname']} {$current['lname']} ({$current['ismis_id']}). Reason: $remarks");
                }

                $db->commit();
                $_SESSION['success_message'] = "Clearance rejected successfully!";
                header("Location: sas_dashboard.php?tab=clearances");
                exit();
            } else {
                $db->rollback();
                $error = "Failed to reject clearance.";
            }
        } catch (Exception $e) {
            $db->rollback();
            error_log("Error rejecting clearance: " . $e->getMessage());
            $error = "Database error occurred.";
        }
    }
}

// ============================================
// HANDLE UPLOAD PROOF
// ============================================
if (isset($_POST['upload_proof'])) {
    $clearance_id = $_POST['clearance_id'] ?? '';
    $remarks = trim($_POST['proof_remarks'] ?? '');

    if ($clearance_id && isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['proof_file'];
        $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/jpg'];
        $max_size = 5 * 1024 * 1024; // 5MB

        if (!in_array($file['type'], $allowed_types)) {
            $error = "Only PDF, JPG, and PNG files are allowed.";
        } elseif ($file['size'] > $max_size) {
            $error = "File size must be less than 5MB.";
        } else {
            try {
                // Create upload directory if it doesn't exist
                $upload_dir = __DIR__ . '/../uploads/proofs/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                // Generate unique filename
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'proof_' . $clearance_id . '_' . time() . '.' . $extension;
                $filepath = 'uploads/proofs/' . $filename;

                if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                    $db = Database::getInstance();

                    // Update clearance with proof info
                    $db->query("UPDATE clearance SET 
                                proof_file = :proof_file,
                                proof_remarks = CONCAT(IFNULL(proof_remarks, ''), ' | ', :remarks),
                                proof_uploaded_at = NOW()
                                WHERE clearance_id = :id");
                    $db->bind(':proof_file', $filepath);
                    $db->bind(':remarks', $remarks);
                    $db->bind(':id', $clearance_id);

                    if ($db->execute()) {
                        // Log the activity
                        if (class_exists('ActivityLogModel')) {
                            $logModel = new ActivityLogModel();
                            $logModel->log($director_id, 'UPLOAD_PROOF', "Uploaded proof for clearance ID: $clearance_id");
                        }

                        $_SESSION['success_message'] = "Proof uploaded successfully!";
                        header("Location: sas_dashboard.php?tab=clearances");
                        exit();
                    } else {
                        $error = "Failed to update clearance with proof info.";
                    }
                } else {
                    $error = "Failed to upload file.";
                }
            } catch (Exception $e) {
                error_log("Error uploading proof: " . $e->getMessage());
                $error = "Database error occurred.";
            }
        }
    } else {
        $error = "Please select a file to upload.";
    }
}

// ============================================
// HANDLE DELETE ORGANIZATION
// ============================================
if (isset($_POST['delete_organization'])) {
    $org_id = $_POST['org_id'] ?? '';

    if ($org_id) {
        try {
            $db = Database::getInstance();

            // Get org info for logging
            $db->query("SELECT org_name FROM student_organizations WHERE org_id = :id");
            $db->bind(':id', $org_id);
            $org = $db->single();
            $org_name = $org['org_name'] ?? 'Unknown';

            $db->query("DELETE FROM student_organizations WHERE org_id = :id");
            $db->bind(':id', $org_id);

            if ($db->execute()) {
                // Log the activity
                if (class_exists('ActivityLogModel')) {
                    $logModel = new ActivityLogModel();
                    $logModel->log($director_id, 'DELETE_ORGANIZATION', "Deleted organization: $org_name");
                }

                $_SESSION['success_message'] = "Organization deleted successfully!";
                header("Location: sas_dashboard.php?tab=organizations");
                exit();
            } else {
                $error = "Failed to delete organization.";
            }
        } catch (Exception $e) {
            error_log("Error deleting organization: " . $e->getMessage());
            $error = "Database error occurred.";
        }
    }
}

// ============================================
// HANDLE TOGGLE ORGANIZATION STATUS
// ============================================
if (isset($_POST['toggle_org_status'])) {
    $org_id = $_POST['org_id'] ?? '';
    $current_status = $_POST['current_status'] ?? '';

    if ($org_id) {
        try {
            $db = Database::getInstance();
            $new_status = $current_status == 'active' ? 'inactive' : 'active';

            $db->query("UPDATE student_organizations SET status = :status WHERE org_id = :id");
            $db->bind(':status', $new_status);
            $db->bind(':id', $org_id);

            if ($db->execute()) {
                // Get org info for logging
                $db->query("SELECT org_name FROM student_organizations WHERE org_id = :id");
                $db->bind(':id', $org_id);
                $org = $db->single();
                $org_name = $org['org_name'] ?? 'Unknown';

                // Log the activity
                if (class_exists('ActivityLogModel')) {
                    $logModel = new ActivityLogModel();
                    $logModel->log($director_id, 'TOGGLE_ORG_STATUS', "Changed organization status: $org_name to $new_status");
                }

                $_SESSION['success_message'] = "Organization status updated successfully!";
                header("Location: sas_dashboard.php?tab=organizations");
                exit();
            } else {
                $error = "Failed to update organization status.";
            }
        } catch (Exception $e) {
            error_log("Error toggling organization status: " . $e->getMessage());
            $error = "Database error occurred.";
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

try {
    $db = Database::getInstance();

    // Get SAS office ID if not already set
    if (!$sas_office_id) {
        $db->query("SELECT office_id FROM offices WHERE office_name = 'Director_SAS'");
        $sas_office = $db->single();
        $sas_office_id = $sas_office['office_id'] ?? null;
    }

    // Get all offices in order for the progress display
    $db->query("SELECT office_id, office_name, 
                CASE 
                    WHEN office_name = 'Librarian' THEN 1
                    WHEN office_name = 'Director_SAS' THEN 2
                    WHEN office_name = 'Dean' THEN 3
                    WHEN office_name = 'Cashier' THEN 4
                    WHEN office_name = 'Registrar' THEN 5
                    ELSE 6
                END as step_order
                FROM offices 
                WHERE office_name IN ('Librarian', 'Director_SAS', 'Dean', 'Cashier', 'Registrar')
                ORDER BY step_order");
    $stats['all_offices'] = $db->resultSet();

    // Recover legacy invalid enum writes (approve/reject) stored as empty status.
    $db->query("UPDATE organization_clearance
                SET status = CASE
                    WHEN LOWER(IFNULL(remarks, '')) REGEXP 'reject|declin|deny|disapprov' THEN 'rejected'
                    WHEN processed_by IS NULL AND processed_date IS NULL THEN 'pending'
                    ELSE 'approved'
                END,
                updated_at = NOW()
                WHERE status = ''");
    if (!$db->execute()) {
        error_log("Warning: SAS global organization status normalization failed");
    }

        // Backfill missing organization_clearance rows for all pending SAS clearances.
        $syncAllPendingOrgRows = "INSERT INTO organization_clearance (clearance_id, org_id, office_id, status, created_at, updated_at)
                                                            SELECT c.clearance_id, so.org_id, so.office_id, 'pending', NOW(), NOW()
                                                            FROM clearance c
                                                            JOIN users u ON c.users_id = u.users_id
                                                            JOIN student_organizations so
                                                                ON COALESCE(so.status, 'active') = 'active'
                                                             AND so.office_id IS NOT NULL
                                                            LEFT JOIN organization_clearance oc
                                                                ON oc.clearance_id = c.clearance_id
                                                             AND oc.org_id = so.org_id
                                                            WHERE c.office_id = :sync_office_id
                                                                AND c.status = 'pending'
                                                                AND (
                                                                        so.org_type <> 'college'
                                                                        OR so.college_id IS NULL
                                                                        OR so.college_id = u.college_id
                                                                        OR NOT EXISTS (
                                                                                SELECT 1
                                                                                FROM student_organizations so_match
                                                                                WHERE so_match.org_type = 'college'
                                                                                    AND COALESCE(so_match.status, 'active') = 'active'
                                                                                    AND so_match.college_id = u.college_id
                                                                        )
                                                                )
                                                                AND oc.org_clearance_id IS NULL";
        $db->query($syncAllPendingOrgRows);
        $db->bind(':sync_office_id', $sas_office_id);
        if (!$db->execute()) {
                error_log("Warning: SAS pending org sync failed for office_id=" . $sas_office_id);
        }

    // Reconcile clinic organization decisions for pending SAS clearances from clinic-office clearance status.
    $db->query("UPDATE organization_clearance oc
                JOIN student_organizations so
                  ON oc.org_id = so.org_id
                 AND so.org_type = 'clinic'
                 AND COALESCE(so.status, 'active') = 'active'
                JOIN clearance c_sas
                  ON c_sas.clearance_id = oc.clearance_id
                 AND c_sas.office_id = :sas_office_id
                 AND c_sas.status = 'pending'
                JOIN clearance c_clinic
                  ON c_clinic.users_id = c_sas.users_id
                 AND c_clinic.semester = c_sas.semester
                 AND c_clinic.school_year = c_sas.school_year
                 AND c_clinic.office_id = so.office_id
                SET oc.status = c_clinic.status,
                    oc.remarks = CONCAT(IFNULL(oc.remarks, ''), ' | Synced from clinic clearance #', c_clinic.clearance_id),
                    oc.processed_by = COALESCE(c_clinic.processed_by, oc.processed_by),
                    oc.processed_date = COALESCE(c_clinic.processed_date, oc.processed_date),
                    oc.updated_at = NOW()
                WHERE c_clinic.status IN ('approved', 'rejected')");
    $db->bind(':sas_office_id', $sas_office_id);
    if (!$db->execute()) {
        error_log("Warning: SAS clinic reconciliation for pending clearances failed");
    }

    // Get pending clearances for SAS with organization approval status
    $db->query("SELECT c.*, u.fname, u.lname, u.ismis_id, u.course_id, u.address, u.contacts, u.age,
                cr.course_name, col.college_name,
                ct.clearance_name as clearance_type,
                (SELECT c_lib.status
                 FROM clearance c_lib
                 JOIN offices o_lib ON c_lib.office_id = o_lib.office_id
                 WHERE c_lib.users_id = c.users_id
                 AND c_lib.semester = c.semester
                 AND c_lib.school_year = c.school_year
                 AND o_lib.office_name = 'Librarian'
                 LIMIT 1) as librarian_status,
                (SELECT COUNT(*) FROM clearance c2 
                 WHERE c2.users_id = c.users_id 
                 AND c2.semester = c.semester 
                 AND c2.school_year = c.school_year 
                 AND c2.status = 'approved') as total_approved,
                (SELECT COUNT(*) FROM clearance c3 
                 WHERE c3.users_id = c.users_id 
                 AND c3.semester = c.semester 
                 AND c3.school_year = c.school_year) as total_offices,
                (SELECT COUNT(DISTINCT oc.org_id) FROM organization_clearance oc
                 JOIN student_organizations so4 ON oc.org_id = so4.org_id
                 WHERE oc.clearance_id = c.clearance_id
                 AND oc.status = 'approved'
                 AND COALESCE(so4.status, 'active') = 'active') as approved_orgs,
                (SELECT COUNT(DISTINCT oc.org_id) FROM organization_clearance oc
                 JOIN student_organizations so5 ON oc.org_id = so5.org_id
                 WHERE oc.clearance_id = c.clearance_id
                 AND COALESCE(so5.status, 'active') = 'active') as total_orgs
                FROM clearance c
                JOIN users u ON c.users_id = u.users_id
                LEFT JOIN course cr ON u.course_id = cr.course_id
                LEFT JOIN college col ON u.college_id = col.college_id
                LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                WHERE c.office_id = :office_id AND c.status = 'pending'
                ORDER BY c.created_at ASC");
    $db->bind(':office_id', $sas_office_id);
    $stats['pending_clearances'] = $db->resultSet();

    // Get approved/rejected clearances for history
    $db->query("SELECT c.*, u.fname, u.lname, u.ismis_id, cr.course_name, col.college_name,
                ct.clearance_name as clearance_type,
                p.fname as processed_fname, p.lname as processed_lname,
                c.proof_file, c.proof_remarks, c.proof_uploaded_at
                FROM clearance c
                JOIN users u ON c.users_id = u.users_id
                LEFT JOIN course cr ON u.course_id = cr.course_id
                LEFT JOIN college col ON u.college_id = col.college_id
                LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                LEFT JOIN users p ON c.processed_by = p.users_id
                WHERE c.office_id = :office_id AND c.status IN ('approved', 'rejected')
                ORDER BY c.processed_date DESC
                LIMIT 20");
    $db->bind(':office_id', $sas_office_id);
    $stats['clearance_history'] = $db->resultSet();

    // Get all organizations with pending count
    $db->query("SELECT so.*, o.office_name,
                (SELECT COUNT(*) FROM organization_clearance oc 
                 JOIN clearance c ON oc.clearance_id = c.clearance_id
                 WHERE oc.org_id = so.org_id AND oc.status = 'pending') as pending_count
                FROM student_organizations so
                LEFT JOIN offices o ON so.office_id = o.office_id
                ORDER BY so.created_at DESC");
    $stats['organizations'] = $db->resultSet();

    // Get all staff under SAS
    $db->query("SELECT u.*, ur.user_role_name, u.assignment
                FROM users u 
                JOIN user_role ur ON u.user_role_id = ur.user_role_id
                WHERE u.office_id = :office_id 
                AND u.user_role_id = (SELECT user_role_id FROM user_role WHERE user_role_name = 'office_staff')
                ORDER BY u.created_at DESC");
    $db->bind(':office_id', $sas_office_id);
    $stats['sas_staff'] = $db->resultSet();

    // Count statistics
    $db->query("SELECT COUNT(*) as count FROM student_organizations");
    $result = $db->single();
    $stats['org_count'] = $result['count'] ?? 0;

    $db->query("SELECT COUNT(*) as count FROM users WHERE office_id = :office_id AND user_role_id = (SELECT user_role_id FROM user_role WHERE user_role_name = 'office_staff')");
    $db->bind(':office_id', $sas_office_id);
    $result = $db->single();
    $stats['staff_count'] = $result['count'] ?? 0;

    $db->query("SELECT COUNT(*) as count FROM clearance WHERE office_id = :office_id AND status = 'pending'");
    $db->bind(':office_id', $sas_office_id);
    $result = $db->single();
    $stats['pending_count'] = $result['count'] ?? 0;

    // Get sub-offices under SAS
    $db->query("SELECT * FROM sub_offices WHERE parent_office_id = :office_id ORDER BY sub_office_name");
    $db->bind(':office_id', $sas_office_id);
    $stats['sub_offices'] = $db->resultSet();

    // Get profile picture
    $db->query("SELECT profile_picture FROM users WHERE users_id = :user_id");
    $db->bind(':user_id', $director_id);
    $user_data = $db->single();
    $profile_pic = $user_data['profile_picture'] ?? null;

    // Get recent activity logs
    if (class_exists('ActivityLogModel')) {
        $logModel = new ActivityLogModel();
        $stats['recent_activities'] = $logModel->getRecent(10);
    } else {
        $stats['recent_activities'] = [];
    }

} catch (Exception $e) {
    error_log("Error fetching SAS data: " . $e->getMessage());
    $error = "Error loading dashboard data. Please refresh the page.";
}

// Helper function to get office icon
function getOfficeIcon($office_name)
{
    $icons = [
        'Librarian' => 'book',
        'Director_SAS' => 'users',
        'Dean' => 'chalkboard-teacher',
        'Cashier' => 'coins',
        'Registrar' => 'clipboard-list',
        'Town Organizations' => 'users',
        'Clinic' => 'hospital',
        'College Organizations' => 'university',
        'Supreme Student Council' => 'crown'
    ];
    return $icons[$office_name] ?? 'building';
}

// Helper function to get assignment display name
function getAssignmentName($assignment)
{
    $names = [
        'clinic' => 'Clinic',
        'town_org' => 'Town Organizations',
        'college_org' => 'College Organizations',
        'ssg' => 'Supreme Student Government'
    ];
    return $names[$assignment] ?? $assignment;
}

// Helper function to get organization type badge color
function getOrgTypeBadge($type)
{
    $colors = [
        'clinic' => 'badge-clinic',
        'town' => 'badge-town',
        'college' => 'badge-college',
        'ssg' => 'badge-ssg'
    ];
    return $colors[$type] ?? 'type-badge';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>SAS Director Dashboard - BISU Online Clearance</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Source+Sans+3:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Source Sans 3', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        :root {
            --header-height: 76px;
            --radius-sm: 10px;
            --radius-md: 16px;
            --radius-lg: 20px;
            --primary: #0f4c81;
            --primary-dark: #0a345c;
            --primary-light: #2f78b7;
            --primary-soft: rgba(15, 76, 129, 0.12);
            --primary-glow: rgba(15, 76, 129, 0.28);
            --bg-primary: #ffffff;
            --bg-secondary: #f4f7fb;
            --bg-tertiary: #e8eef5;
            --text-primary: #112032;
            --text-secondary: #4f6478;
            --text-muted: #7f90a3;
            --border-color: #d5dfeb;
            --card-bg: #ffffff;
            --card-shadow: 0 6px 18px rgba(17, 32, 50, 0.06);
            --card-shadow-hover: 0 16px 32px rgba(15, 76, 129, 0.14);
            --surface-soft: rgba(15, 76, 129, 0.04);
            --header-bg: linear-gradient(122deg, #0a345c 0%, #0f4c81 50%, #2f78b7 100%);
            --sidebar-bg: #ffffff;
            --input-bg: #ffffff;
            --input-border: #cad8e8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --success-soft: rgba(16, 185, 129, 0.1);
            --warning-soft: rgba(245, 158, 11, 0.1);
            --danger-soft: rgba(239, 68, 68, 0.1);
            --info-soft: rgba(59, 130, 246, 0.1);
            --clinic: #0b7d5a;
            --clinic-soft: rgba(11, 125, 90, 0.1);
            --town: #b45f2e;
            --town-soft: rgba(180, 95, 46, 0.1);
            --college: #3b82f6;
            --college-soft: rgba(59, 130, 246, 0.1);
            --ssg: #8b5cf6;
            --ssg-soft: rgba(139, 92, 246, 0.1);
        }

        .dark-mode {
            --primary: #5ca7e1;
            --primary-dark: #3b7fb2;
            --primary-light: #8fc8f0;
            --primary-soft: rgba(92, 167, 225, 0.2);
            --primary-glow: rgba(92, 167, 225, 0.35);
            --bg-primary: #101a27;
            --bg-secondary: #142233;
            --bg-tertiary: #1b2c40;
            --text-primary: #e6eef7;
            --text-secondary: #b4c5d8;
            --text-muted: #8ea2b8;
            --border-color: #24405a;
            --card-bg: #172536;
            --card-shadow: 0 10px 24px rgba(0, 0, 0, 0.38);
            --card-shadow-hover: 0 16px 34px rgba(0, 0, 0, 0.44);
            --header-bg: linear-gradient(122deg, #0d1622 0%, #0f2e49 55%, #1d4f79 100%);
            --sidebar-bg: #172536;
            --input-bg: #1b2c40;
            --input-border: #2d4661;
            --success: #4ade80;
            --warning: #fbbf24;
            --danger: #f87171;
            --info: #60a5fa;
            --success-soft: rgba(74, 222, 128, 0.1);
            --warning-soft: rgba(251, 191, 36, 0.1);
            --danger-soft: rgba(248, 113, 113, 0.1);
            --info-soft: rgba(96, 165, 250, 0.1);
            --clinic: #4fd1b5;
            --clinic-soft: rgba(79, 209, 181, 0.15);
            --town: #f6ad55;
            --town-soft: rgba(246, 173, 85, 0.15);
            --college: #90cdf4;
            --college-soft: rgba(144, 205, 244, 0.15);
            --ssg: #d6bcfa;
            --ssg-soft: rgba(214, 188, 250, 0.15);
        }

        body {
            background: var(--bg-secondary);
            color: var(--text-primary);
            min-height: 100vh;
            min-height: 100dvh;
            overflow-x: hidden;
            transition: background-color 0.3s ease, color 0.3s ease;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: -1;
            background:
                radial-gradient(circle at 100% 0%, rgba(47, 120, 183, 0.16) 0%, transparent 42%),
                radial-gradient(circle at 0% 100%, rgba(15, 76, 129, 0.12) 0%, transparent 44%);
        }

        button,
        .btn,
        .nav-item,
        .add-btn,
        .logout-btn,
        .action-btn,
        .filter-btn,
        .clear-filter {
            min-height: 44px;
        }

        button:focus-visible,
        a:focus-visible,
        input:focus-visible,
        select:focus-visible,
        textarea:focus-visible {
            outline: 3px solid var(--primary-glow);
            outline-offset: 2px;
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
            padding: calc(0.75rem + env(safe-area-inset-top, 0px)) calc(1rem + env(safe-area-inset-right, 0px)) 0.75rem calc(1rem + env(safe-area-inset-left, 0px));
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            box-shadow: 0 10px 28px rgba(8, 28, 47, 0.24);
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
            min-height: calc(var(--header-height) - 1.5rem);
            gap: 12px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .menu-toggle {
            display: none;
            align-items: center;
            justify-content: center;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.35);
            background: rgba(255, 255, 255, 0.14);
            color: white;
            cursor: pointer;
            transition: background-color 0.25s ease, transform 0.25s ease;
        }

        .menu-toggle:hover {
            background: rgba(255, 255, 255, 0.24);
            transform: translateY(-1px);
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo-text {
            display: flex;
            flex-direction: column;
            line-height: 1.15;
        }

        .app-subtitle {
            font-size: 0.78rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            opacity: 0.82;
            font-weight: 600;
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
            font-family: 'Manrope', sans-serif;
            font-size: 1.3rem;
            font-weight: 700;
            letter-spacing: 0.01em;
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
            border: 1px solid rgba(255, 255, 255, 0.2);
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

        .mobile-logout-wrap {
            display: none;
            padding: 0 20px 20px;
            flex-shrink: 0;
        }

        .mobile-logout-btn {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            text-decoration: none;
            border-radius: 12px;
            padding: 12px 16px;
            font-weight: 700;
            background: var(--danger-soft);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.25);
            transition: 0.25s ease;
        }

        .mobile-logout-btn:hover {
            background: var(--danger);
            color: #fff;
            transform: translateY(-1px);
        }

        .main-container {
            display: flex;
            margin-top: var(--header-height);
            min-height: calc(100vh - var(--header-height));
            min-height: calc(100dvh - var(--header-height));
            max-width: 1400px;
            margin-left: auto;
            margin-right: auto;
        }

        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            padding: 30px 0;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: var(--header-height);
            height: calc(100vh - var(--header-height));
            height: calc(100dvh - var(--header-height));
            overflow-y: auto;
            transition: background-color 0.3s ease, border-color 0.3s ease;
            padding-bottom: calc(18px + env(safe-area-inset-bottom, 0px));
            box-shadow: 10px 0 28px rgba(8, 28, 47, 0.06);
        }

        .sidebar-backdrop {
            position: fixed;
            inset: var(--header-height) 0 0 0;
            background: rgba(15, 23, 42, 0.45);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
            z-index: 1000;
        }

        .sidebar-backdrop.show {
            opacity: 1;
            pointer-events: auto;
        }

        .profile-section {
            text-align: center;
            padding: 0 20px 25px;
            border-bottom: 1px solid var(--border-color);
            background: linear-gradient(180deg, var(--surface-soft) 0%, transparent 100%);
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
            font-weight: 600;
            display: inline-block;
            margin-bottom: 10px;
        }

        .office-badge {
            background: var(--info-soft);
            color: var(--info);
            padding: 5px 20px;
            border-radius: 30px;
            font-size: 0.85rem;
            display: inline-block;
        }

        .nav-menu {
            padding: 20px;
            flex: 1 1 auto;
            overflow-y: auto;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: var(--text-secondary);
            border-radius: 12px;
            transition: all 0.25s ease;
            margin-bottom: 5px;
            cursor: pointer;
            border: 1px solid transparent;
            width: 100%;
            background: none;
            font-size: 0.95rem;
            text-align: left;
            font-weight: 600;
        }

        .nav-item:hover {
            background: var(--primary-soft);
            color: var(--primary);
            border-color: rgba(15, 76, 129, 0.22);
            transform: translateX(2px);
        }

        .nav-item.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            box-shadow: 0 10px 20px var(--primary-glow);
        }

        .nav-item i {
            width: 22px;
            font-size: 1.2rem;
        }

        .content-area {
            flex: 1;
            margin-left: 280px;
            padding: 30px;
            padding-bottom: calc(30px + env(safe-area-inset-bottom, 0px));
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
            border-radius: var(--radius-lg);
            margin-bottom: 30px;
            box-shadow: 0 10px 30px var(--primary-glow);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.18);
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
            font-family: 'Manrope', sans-serif;
            font-size: 2rem;
            margin-bottom: 10px;
            font-weight: 800;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: var(--radius-md);
            box-shadow: var(--card-shadow);
            display: flex;
            align-items: center;
            gap: 15px;
            transition: transform 0.24s ease, box-shadow 0.24s ease;
            border: 1px solid var(--border-color);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .stat-card::before {
            content: '';
            width: 5px;
            align-self: stretch;
            border-radius: 8px;
            background: linear-gradient(180deg, var(--primary-light) 0%, var(--primary-dark) 100%);
            margin-right: 2px;
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

        .stat-icon.org {
            background: var(--info-soft);
            color: var(--info);
        }

        .stat-icon.staff {
            background: var(--success-soft);
            color: var(--success);
        }

        .stat-icon.offices {
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

        .section-card {
            background: var(--card-bg);
            border-radius: var(--radius-md);
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .section-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-dark) 0%, var(--primary) 55%, var(--primary-light) 100%);
            opacity: 0.7;
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
            font-family: 'Manrope', sans-serif;
            font-size: 1.4rem;
            font-weight: 700;
        }

        .section-header h2 i {
            color: var(--primary);
            margin-right: 10px;
        }

        .add-btn {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 30px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px var(--primary-glow);
        }

        .add-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px var(--primary-glow);
        }

        .filter-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
            align-items: center;
            background: var(--bg-secondary);
            padding: 15px 20px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
        }

        .filter-select {
            padding: 10px 20px;
            border: 1px solid var(--input-border);
            border-radius: 12px;
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

        .search-input {
            flex: 1;
            padding: 10px 20px;
            border: 1px solid var(--input-border);
            border-radius: 12px;
            background: var(--input-bg);
            color: var(--text-primary);
            font-size: 0.95rem;
            min-width: 250px;
        }

        .search-input:focus {
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
            border-radius: var(--radius-md);
            border: 1px solid var(--border-color);
            background: var(--card-bg);
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
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 3;
            backdrop-filter: blur(4px);
        }

        td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        tbody tr {
            transition: background-color 0.2s ease;
        }

        tbody tr:nth-child(even) {
            background: var(--surface-soft);
        }

        tbody tr:hover {
            background: rgba(15, 76, 129, 0.04);
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

        .status-active {
            background: var(--success-soft);
            color: var(--success);
        }

        .status-inactive {
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

        .badge-clinic {
            background: var(--clinic-soft);
            color: var(--clinic);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-town {
            background: var(--town-soft);
            color: var(--town);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-college {
            background: var(--college-soft);
            color: var(--college);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-ssg {
            background: var(--ssg-soft);
            color: var(--ssg);
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
            border: 1px solid transparent;
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

        .action-btn.edit {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .action-btn.delete {
            background: var(--danger-soft);
            color: var(--danger);
        }

        .action-btn.toggle {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .action-btn.proof {
            background: var(--info-soft);
            color: var(--info);
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 14px rgba(15, 76, 129, 0.16);
        }

        .action-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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

        .org-status {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .org-status.completed {
            background: var(--success-soft);
            color: var(--success);
        }

        .org-status.pending {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .org-progress {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 3px;
        }

        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.05);
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
            border-left: 5px solid var(--success);
        }

        .alert-error {
            background: var(--danger-soft);
            color: var(--danger);
            border: 1px solid var(--danger-soft);
            border-left: 5px solid var(--danger);
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
            box-shadow: 0 24px 54px rgba(14, 31, 48, 0.32);
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
            background: linear-gradient(135deg, rgba(15, 76, 129, 0.16) 0%, transparent 100%);
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

        .close-btn {
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

        .close-btn:hover {
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

        .form-grid {
            display: grid;
            gap: 15px;
        }

        .form-group {
            margin-bottom: 0;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: var(--text-primary);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-group label i {
            color: var(--primary);
            margin-right: 6px;
        }

        .form-group label .required {
            color: var(--danger);
            margin-left: 3px;
        }

        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--input-border);
            border-radius: 12px;
            font-size: 0.95rem;
            transition: 0.3s;
            background: var(--input-bg);
            color: var(--text-primary);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-soft);
        }

        .form-control[type="file"] {
            padding: 10px;
            background: var(--bg-secondary);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .password-field {
            position: relative;
        }

        .password-field input {
            padding-right: 45px;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            cursor: pointer;
            font-size: 1.1rem;
        }

        .password-toggle:hover {
            color: var(--primary);
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

        .badge-warning {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .badge-info {
            background: var(--info-soft);
            color: var(--info);
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

        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .student-info-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            :root {
                --header-height: 74px;
            }

            .header-logout-btn {
                display: none;
            }

            .mobile-logout-wrap {
                display: block;
                margin-top: auto;
                padding: 14px 20px calc(14px + env(safe-area-inset-bottom, 0px));
                border-top: 1px solid var(--border-color);
                background: linear-gradient(180deg, rgba(15, 76, 129, 0.02) 0%, var(--sidebar-bg) 55%);
                position: sticky;
                bottom: 0;
            }

            .menu-toggle {
                display: inline-flex;
                flex-shrink: 0;
            }

            .logo {
                gap: 10px;
            }

            .logo h2 {
                font-size: 1.05rem;
                white-space: nowrap;
            }

            .app-subtitle {
                display: none;
            }

            .user-menu {
                gap: 10px;
            }

            .user-info {
                padding: 6px 10px;
            }

            .user-details {
                display: none;
            }

            .logout-btn {
                padding: 9px 14px;
            }

            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                top: var(--header-height);
                left: 0;
                width: min(82vw, 320px);
                height: calc(100dvh - var(--header-height));
                z-index: 1001;
                transition: transform 0.3s ease;
                box-shadow: 22px 0 45px rgba(2, 8, 23, 0.26);
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

            .section-card {
                padding: 18px;
                border-radius: 16px;
            }

            .table-responsive {
                -webkit-overflow-scrolling: touch;
            }

            th,
            td {
                padding: 12px 10px;
                font-size: 0.85rem;
                white-space: nowrap;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .filter-bar {
                flex-direction: column;
                border-radius: 16px;
                padding: 14px;
            }

            .filter-bar select,
            .filter-bar input,
            .filter-bar button {
                width: 100%;
            }

            .search-input {
                min-width: 0;
            }

            .content-area {
                padding: 16px;
            }

            .action-btns {
                flex-wrap: wrap;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-content {
                width: calc(100% - 24px);
                border-radius: 18px;
            }

            .btn {
                width: 100%;
            }

            .student-info-grid {
                grid-template-columns: 1fr;
            }

            .theme-toggle {
                right: calc(14px + env(safe-area-inset-right, 0px));
                bottom: calc(14px + env(safe-area-inset-bottom, 0px));
            }
        }

        @media (max-width: 480px) {
            .logo-icon {
                width: 38px;
                height: 38px;
                font-size: 1rem;
            }

            .logout-btn {
                padding: 8px 10px;
                font-size: 0.86rem;
            }

            .welcome-banner {
                padding: 20px;
                border-radius: 16px;
            }

            .welcome-banner h1 {
                font-size: 1.35rem;
            }

            .welcome-banner p {
                font-size: 0.94rem;
            }

            .table-responsive {
                border-radius: 12px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            * {
                animation: none !important;
                transition: none !important;
            }
        }
    </style>
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#412886">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="BISU Clearance">
    <link rel="apple-touch-icon" href="/assets/img/pwa-icon-192.png">
    <script defer src="/assets/js/pwa-register.js"></script>
</head>
<body>
    <div class="theme-toggle" id="themeToggle">
        <i class="fas fa-moon" id="themeIcon"></i>
    </div>

    <header class="header">
        <div class="header-content">
            <div class="header-left">
                <button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation" aria-expanded="false" aria-controls="sidebarMenu">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="logo-text">
                        <h2>SAS Director Dashboard</h2>
                        <span class="app-subtitle">BISU Online Clearance</span>
                    </div>
                </div>
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
                        <div class="user-name"><?php echo htmlspecialchars($director_name); ?></div>
                        <div class="user-role">Director of Student Affairs</div>
                    </div>
                </div>
                <a href="../logout.php" class="logout-btn header-logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>

    <div class="main-container">
        <aside class="sidebar" id="sidebarMenu">
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

                <div class="profile-name"><?php echo htmlspecialchars($director_name); ?></div>
                <div class="profile-email"><?php echo htmlspecialchars($director_email); ?></div>
                <div class="profile-badge">SAS Director</div>
                <div class="office-badge"><i class="fas fa-building"></i> Student Affairs</div>
            </div>

            <nav class="nav-menu">
                <button class="nav-item <?php echo $active_tab == 'dashboard' ? 'active' : ''; ?>" data-tab="dashboard" onclick="switchTab('dashboard', this)">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </button>
                <button class="nav-item <?php echo $active_tab == 'clearances' ? 'active' : ''; ?>" data-tab="clearances" onclick="switchTab('clearances', this)">
                    <i class="fas fa-file-alt"></i> Clearances
                    <?php if (($stats['pending_count'] ?? 0) > 0): ?>
                        <span style="margin-left: auto; background: var(--warning); color: white; padding: 2px 8px; border-radius: 20px; font-size: 0.8rem;">
                            <?php echo $stats['pending_count']; ?>
                        </span>
                    <?php endif; ?>
                </button>
                <button class="nav-item <?php echo $active_tab == 'organizations' ? 'active' : ''; ?>" data-tab="organizations" onclick="switchTab('organizations', this)">
                    <i class="fas fa-users"></i> Organizations
                </button>
                <button class="nav-item <?php echo $active_tab == 'staff' ? 'active' : ''; ?>" data-tab="staff" onclick="switchTab('staff', this)">
                    <i class="fas fa-user-tie"></i> Staff
                </button>
                <button class="nav-item <?php echo $active_tab == 'history' ? 'active' : ''; ?>" data-tab="history" onclick="switchTab('history', this)">
                    <i class="fas fa-history"></i> Clearance History
                </button>
            </nav>
            <div class="mobile-logout-wrap">
                <a href="../logout.php" class="mobile-logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </aside>
        <div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

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
                    <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', $director_name)[1] ?? $director_name); ?>! 👋</h1>
                    <p>Manage student organizations, staff, and approve clearances after all organizations have approved.</p>
                    <div class="office-info">
                        <i class="fas fa-info-circle"></i> Office of Student Affairs & Services
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon pending">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $stats['pending_count'] ?? 0; ?></h3>
                            <p>Pending Clearances</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon org">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $stats['org_count'] ?? 0; ?></h3>
                            <p>Organizations</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon staff">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $stats['staff_count'] ?? 0; ?></h3>
                            <p>Staff</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon offices">
                            <i class="fas fa-sitemap"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo count($stats['sub_offices'] ?? []); ?></h3>
                            <p>Sub-Offices</p>
                        </div>
                    </div>
                </div>

                <!-- Clearance Flow Information -->
                <div class="section-card" style="background: var(--info-soft); border-color: var(--info);">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <i class="fas fa-info-circle" style="font-size: 2rem; color: var(--info);"></i>
                        <div>
                            <h3 style="color: var(--info); margin-bottom: 5px;">Clearance Flow</h3>
                                <p style="color: var(--text-secondary);">Complete clearance flow: <strong>Librarian →
                                    Organizations (under SAS) → SAS Director → Dean → Cashier → Registrar</strong></p>
                            <p style="color: var(--text-secondary); margin-top: 5px;">You can only approve clearances
                                AFTER all organizations under SAS have approved.</p>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-history"></i> Recent Activity</h2>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Action</th>
                                    <th>Description</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($stats['recent_activities'] ?? [], 0, 5) as $activity): ?>
                                    <tr>
                                        <td><span class="type-badge"><?php echo $activity['action']; ?></span></td>
                                        <td><?php echo htmlspecialchars($activity['description']); ?></td>
                                        <td><?php echo date('M d, Y h:i A', strtotime($activity['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($stats['recent_activities'])): ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center; color: var(--text-muted);">No recent activity</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Clearances Tab -->
            <div id="clearances" class="tab-content <?php echo $active_tab == 'clearances' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-file-alt"></i> Pending Clearance Approvals</h2>
                    </div>

                    <!-- Search Filter Bar -->
                    <div class="filter-bar">
                        <select class="filter-select" id="clearanceTypeFilter">
                            <option value="">All Types</option>
                            <option value="graduating">Graduating</option>
                            <option value="non_graduating">Non-Graduating</option>
                        </select>
                        <select class="filter-select" id="clearanceSemesterFilter">
                            <option value="">All Semesters</option>
                            <option value="1st Semester">1st Semester</option>
                            <option value="2nd Semester">2nd Semester</option>
                            <option value="Summer">Summer</option>
                        </select>
                        <input type="text" class="search-input" id="clearanceSearch"
                            placeholder="Search by student name, ID, or course...">
                        <button class="filter-btn" onclick="filterClearances()">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <button class="clear-filter" onclick="clearClearanceFilters()">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>

                    <?php if (empty($stats['pending_clearances'])): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <h3>No pending clearances</h3>
                            <p>All clearances have been processed.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table id="clearancesTable">
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>ID</th>
                                        <th>Course</th>
                                        <th>Type</th>
                                        <th>Semester</th>
                                        <th>Organizations Status</th>
                                        <th>Progress</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats['pending_clearances'] as $clearance): ?>
                                        <?php
                                        $all_orgs_approved = ($clearance['approved_orgs'] == $clearance['total_orgs'] && $clearance['total_orgs'] > 0);
                                        $org_status_text = $clearance['approved_orgs'] . '/' . $clearance['total_orgs'] . ' organizations';
                                        $remaining_orgs = max(0, (int) $clearance['total_orgs'] - (int) $clearance['approved_orgs']);
                                        ?>
                                        <tr data-type="<?php echo $clearance['clearance_type']; ?>"
                                            data-semester="<?php echo $clearance['semester']; ?>"
                                            data-name="<?php echo strtolower($clearance['fname'] . ' ' . $clearance['lname']); ?>"
                                            data-id="<?php echo strtolower($clearance['ismis_id']); ?>"
                                            data-course="<?php echo strtolower($clearance['course_name'] ?? ''); ?>">
                                            <td><strong><?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($clearance['ismis_id']); ?></td>
                                            <td><?php echo htmlspecialchars($clearance['course_name'] ?? 'N/A'); ?></td>
                                            <td><span class="type-badge"><?php echo ucfirst($clearance['clearance_type']); ?></span></td>
                                            <td><?php echo $clearance['semester'] . ' ' . $clearance['school_year']; ?></td>
                                            <td>
                                                <span class="org-status <?php echo $all_orgs_approved ? 'completed' : 'pending'; ?>">
                                                    <?php echo $org_status_text; ?>
                                                </span>
                                                <div class="org-progress">
                                                    <?php echo $all_orgs_approved ? 'Ready for SAS approval' : ($remaining_orgs . ' organization approval(s) remaining'); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="progress-indicator">
                                                    <span class="progress-dot <?php echo ($clearance['total_approved'] ?? 0) >= 1 ? 'completed' : ''; ?>"></span>
                                                    <span class="progress-dot <?php echo ($clearance['total_approved'] ?? 0) >= 2 ? 'completed' : ''; ?>"></span>
                                                    <span class="progress-dot <?php echo ($clearance['total_approved'] ?? 0) >= 3 ? ($all_orgs_approved ? 'completed' : 'current') : ''; ?>"></span>
                                                    <span class="progress-dot <?php echo ($clearance['total_approved'] ?? 0) >= 4 ? 'completed' : ''; ?>"></span>
                                                    <span class="progress-dot <?php echo ($clearance['total_approved'] ?? 0) >= 5 ? 'completed' : ''; ?>"></span>
                                                    <span style="margin-left: 5px;">
                                                        <?php echo $clearance['total_approved'] ?? 0; ?>/5
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="action-btns">
                                                    <button class="action-btn approve"
                                                        onclick="openApproveModal(<?php echo $clearance['clearance_id']; ?>)"
                                                        <?php echo !$all_orgs_approved ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : ''; ?>>
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button class="action-btn reject"
                                                        onclick="openRejectModal(<?php echo $clearance['clearance_id']; ?>)">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                    <button class="action-btn proof"
                                                        onclick="openProofModal(<?php echo $clearance['clearance_id']; ?>)">
                                                        <i class="fas fa-paperclip"></i>
                                                    </button>
                                                    <button class="action-btn view"
                                                        onclick="viewClearanceDetails(<?php echo $clearance['clearance_id']; ?>, '<?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?>', '<?php echo $clearance['ismis_id']; ?>', '<?php echo htmlspecialchars($clearance['course_name'] ?? 'N/A'); ?>', '<?php echo htmlspecialchars($clearance['college_name'] ?? 'N/A'); ?>', '<?php echo $clearance['address'] ?? ''; ?>', '<?php echo $clearance['contacts'] ?? ''; ?>', '<?php echo $clearance['age'] ?? ''; ?>', <?php echo $clearance['approved_orgs']; ?>, <?php echo $clearance['total_orgs']; ?>, '<?php echo htmlspecialchars($clearance['librarian_status'] ?? 'pending', ENT_QUOTES); ?>')">
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

            <!-- Clearance History Tab -->
            <div id="history" class="tab-content <?php echo $active_tab == 'history' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-history"></i> Processed Clearances</h2>
                    </div>

                    <div class="filter-bar">
                        <select class="filter-select" id="historyStatusFilter">
                            <option value="">All Status</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                        <input type="text" class="search-input" id="historySearch"
                            placeholder="Search by student name or ID...">
                        <button class="filter-btn" onclick="filterHistory()"><i class="fas fa-search"></i> Search</button>
                        <button class="clear-filter" onclick="clearHistoryFilters()"><i class="fas fa-times"></i> Clear</button>
                    </div>

                    <?php if (empty($stats['clearance_history'])): ?>
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
                                        <th>Status</th>
                                        <th>Processed By</th>
                                        <th>Date</th>
                                        <th>Remarks</th>
                                        <th>Proof</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($stats['clearance_history'] as $clearance): ?>
                                        <tr data-status="<?php echo $clearance['status']; ?>"
                                            data-name="<?php echo strtolower($clearance['fname'] . ' ' . $clearance['lname']); ?>"
                                            data-id="<?php echo strtolower($clearance['ismis_id']); ?>">
                                            <td><strong><?php echo htmlspecialchars($clearance['fname'] . ' ' . $clearance['lname']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($clearance['ismis_id']); ?></td>
                                            <td><?php echo htmlspecialchars($clearance['course_name'] ?? 'N/A'); ?></td>
                                            <td><span class="type-badge"><?php echo ucfirst($clearance['clearance_type']); ?></span></td>
                                            <td>
                                                <span class="status-badge <?php echo $clearance['status'] == 'approved' ? 'status-approved' : 'status-rejected'; ?>">
                                                    <?php echo ucfirst($clearance['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars(($clearance['processed_fname'] ?? '') . ' ' . ($clearance['processed_lname'] ?? '')); ?></td>
                                            <td><?php echo isset($clearance['processed_date']) ? date('M d, Y', strtotime($clearance['processed_date'])) : 'N/A'; ?></td>
                                            <td><?php echo htmlspecialchars($clearance['remarks'] ?? '—'); ?></td>
                                            <td>
                                                <?php if (!empty($clearance['proof_file'])): ?>
                                                    <a href="../<?php echo $clearance['proof_file']; ?>" target="_blank" class="action-btn proof" title="View Proof">
                                                        <i class="fas fa-file"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Organizations Tab -->
            <div id="organizations" class="tab-content <?php echo $active_tab == 'organizations' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-users"></i> Organizations</h2>
                        <button class="add-btn" onclick="openOrgModal()">
                            <i class="fas fa-plus"></i> Add Organization
                        </button>
                    </div>

                    <div class="filter-bar">
                        <select class="filter-select" id="orgTypeFilter">
                            <option value="">All Types</option>
                            <option value="town">Town Organizations</option>
                            <option value="college">College Organizations</option>
                            <option value="clinic">Clinic</option>
                            <option value="ssg">Supreme Student Government</option>
                        </select>
                        <input type="text" class="search-input" id="orgSearch" placeholder="Search organizations...">
                        <button class="filter-btn" onclick="filterOrganizations()"><i class="fas fa-search"></i> Filter</button>
                        <button class="clear-filter" onclick="clearOrgFilters()"><i class="fas fa-times"></i> Clear</button>
                    </div>

                    <div class="table-responsive">
                        <table id="orgTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Organization Name</th>
                                    <th>Type</th>
                                    <th>Email</th>
                                    <th>Dashboard</th>
                                    <th>Status</th>
                                    <th>Pending</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($stats['organizations'])): ?>
                                    <tr>
                                        <td colspan="9" style="text-align: center; padding: 30px; color: var(--text-muted);">
                                            <i class="fas fa-info-circle"></i> No organizations found. Click "Add Organization" to create one.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($stats['organizations'] as $org): ?>
                                        <tr data-type="<?php echo $org['org_type'] ?? ''; ?>"
                                            data-name="<?php echo strtolower($org['org_name'] ?? ''); ?>">
                                            <td><?php echo $org['org_id'] ?? 0; ?></td>
                                            <td><strong><?php echo htmlspecialchars($org['org_name'] ?? 'Unknown'); ?></strong></td>
                                            <td>
                                                <span class="<?php echo getOrgTypeBadge($org['org_type'] ?? ''); ?>">
                                                    <?php echo ucfirst($org['org_type'] ?? 'unknown'); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($org['org_email'] ?? 'No email'); ?></td>
                                            <td>
                                                <span class="type-badge">
                                                    <?php echo $org['dashboard_type'] ?? $org['org_type'] ?? 'standard'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $org['status'] ?? 'active'; ?>">
                                                    <?php echo ucfirst($org['status'] ?? 'active'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-warning"><?php echo $org['pending_count'] ?? 0; ?></span>
                                            </td>
                                            <td><?php echo isset($org['created_at']) ? date('M d, Y', strtotime($org['created_at'])) : 'N/A'; ?></td>
                                            <td>
                                                <div class="action-btns">
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="org_id" value="<?php echo $org['org_id']; ?>">
                                                        <input type="hidden" name="current_status" value="<?php echo $org['status']; ?>">
                                                        <button type="submit" name="toggle_org_status" class="action-btn toggle" title="Toggle Status">
                                                            <i class="fas fa-power-off"></i>
                                                        </button>
                                                    </form>
                                                    <button class="action-btn edit"
                                                        data-org-id="<?php echo $org['org_id']; ?>"
                                                        data-org-name="<?php echo htmlspecialchars($org['org_name'] ?? '', ENT_QUOTES); ?>"
                                                        data-org-type="<?php echo htmlspecialchars($org['org_type'] ?? '', ENT_QUOTES); ?>"
                                                        data-org-email="<?php echo htmlspecialchars($org['org_email'] ?? '', ENT_QUOTES); ?>"
                                                        data-org-status="<?php echo htmlspecialchars($org['status'] ?? 'active', ENT_QUOTES); ?>"
                                                        onclick="editOrg(this)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this organization?');">
                                                        <input type="hidden" name="org_id" value="<?php echo $org['org_id']; ?>">
                                                        <button type="submit" name="delete_organization" class="action-btn delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Staff Tab -->
            <div id="staff" class="tab-content <?php echo $active_tab == 'staff' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-user-tie"></i> Staff Management</h2>
                        <button class="add-btn" onclick="openStaffModal()">
                            <i class="fas fa-plus"></i> Add Staff
                        </button>
                    </div>

                    <div class="filter-bar">
                        <select class="filter-select" id="staffTypeFilter">
                            <option value="">All Assignments</option>
                            <option value="clinic">Clinic</option>
                            <option value="town_org">Town Organizations</option>
                            <option value="college_org">College Organizations</option>
                            <option value="ssg">Supreme Student Government</option>
                        </select>
                        <input type="text" class="search-input" id="staffSearch" placeholder="Search staff...">
                        <button class="filter-btn" onclick="filterStaff()"><i class="fas fa-search"></i> Filter</button>
                        <button class="clear-filter" onclick="clearStaffFilters()"><i class="fas fa-times"></i> Clear</button>
                    </div>

                    <div class="table-responsive">
                        <table id="staffTable">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Assignment</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($stats['sas_staff'])): ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 30px; color: var(--text-muted);">
                                            <i class="fas fa-info-circle"></i> No staff found. Click "Add Staff" to create one.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($stats['sas_staff'] as $staff): ?>
                                        <tr data-assignment="<?php echo $staff['assignment'] ?? ''; ?>"
                                            data-name="<?php echo strtolower(($staff['fname'] ?? '') . ' ' . ($staff['lname'] ?? '')); ?>"
                                            data-email="<?php echo strtolower($staff['emails'] ?? ''); ?>">
                                            <td><strong><?php echo htmlspecialchars(($staff['fname'] ?? '') . ' ' . ($staff['lname'] ?? '')); ?></strong></td>
                                            <td><?php echo htmlspecialchars($staff['emails'] ?? ''); ?></td>
                                            <td><span class="type-badge"><?php echo getAssignmentName($staff['assignment'] ?? ''); ?></span></td>
                                            <td><span class="status-badge status-approved">Active</span></td>
                                            <td>
                                                <div class="action-btns">
                                                    <button class="action-btn edit"
                                                        data-staff-id="<?php echo $staff['users_id']; ?>"
                                                        data-fname="<?php echo htmlspecialchars($staff['fname'] ?? '', ENT_QUOTES); ?>"
                                                        data-lname="<?php echo htmlspecialchars($staff['lname'] ?? '', ENT_QUOTES); ?>"
                                                        data-email="<?php echo htmlspecialchars($staff['emails'] ?? '', ENT_QUOTES); ?>"
                                                        data-assignment="<?php echo htmlspecialchars($staff['assignment'] ?? '', ENT_QUOTES); ?>"
                                                        onclick="editStaff(this)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this staff member?');">
                                                        <input type="hidden" name="staff_id" value="<?php echo $staff['users_id']; ?>">
                                                        <button type="submit" name="delete_staff" class="action-btn delete">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add Organization Modal -->
    <div id="orgModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-building"></i> Add Organization</h3>
                <button class="close-btn" onclick="closeOrgModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="info-card" style="background: var(--info-soft); padding: 15px; border-radius: 12px; margin-bottom: 20px;">
                        <i class="fas fa-info-circle" style="color: var(--info); margin-right: 10px;"></i>
                        <span style="color: var(--info);">The organization will have its own dashboard based on the type selected.</span>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Organization Name <span class="required">*</span></label>
                            <input type="text" name="org_name" class="form-control" placeholder="Enter organization name" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tasks"></i> Type <span class="required">*</span></label>
                            <select name="org_type" id="org_type_select" class="form-control" required>
                                <option value="">Select Type</option>
                                <option value="clinic">Clinic</option>
                                <option value="town">Town Organization</option>
                                <option value="college">College Organization</option>
                                <option value="ssg">Supreme Student Government</option>
                            </select>
                            <small style="color: var(--text-muted); margin-top: 5px; display: block;">
                                This determines the organization's dashboard and office assignment.
                            </small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email <span class="required">*</span></label>
                            <input type="email" name="org_email" class="form-control" placeholder="email@example.com" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Password <span class="required">*</span></label>
                            <div class="password-field">
                                <input type="password" name="org_password" id="orgPassword" class="form-control" placeholder="Enter password" required>
                                <i class="fas fa-eye password-toggle" onclick="togglePassword('orgPassword', this)"></i>
                            </div>
                            <small style="color: var(--text-muted);">Minimum 8 characters</small>
                        </div>
                    </div>
                    
                    <!-- Preview of dashboard assignment -->
                    <div id="dashboard_preview" style="margin-top: 20px; padding: 15px; background: var(--bg-secondary); border-radius: 12px; display: none;">
                        <h4 style="color: var(--text-primary); margin-bottom: 10px; font-size: 1rem;">
                            <i class="fas fa-tachometer-alt"></i> Dashboard Assignment
                        </h4>
                        <p id="dashboard_preview_text" style="color: var(--text-secondary);"></p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeOrgModal()">Cancel</button>
                    <button type="submit" name="add_organization" class="btn btn-primary">Create Organization</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Organization Modal -->
    <div id="editOrgModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-pen"></i> Edit Organization</h3>
                <button class="close-btn" onclick="closeEditOrgModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="org_id" id="editOrgId">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Organization Name <span class="required">*</span></label>
                            <input type="text" name="org_name" id="editOrgName" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-tasks"></i> Type <span class="required">*</span></label>
                            <select name="org_type" id="editOrgType" class="form-control" required>
                                <option value="clinic">Clinic</option>
                                <option value="town">Town Organization</option>
                                <option value="college">College Organization</option>
                                <option value="ssg">Supreme Student Government</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email <span class="required">*</span></label>
                            <input type="email" name="org_email" id="editOrgEmail" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-toggle-on"></i> Status <span class="required">*</span></label>
                            <select name="org_status" id="editOrgStatus" class="form-control" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> New Password (Optional)</label>
                            <div class="password-field">
                                <input type="password" name="org_password" id="editOrgPassword" class="form-control" placeholder="Leave blank to keep current password">
                                <i class="fas fa-eye password-toggle" onclick="togglePassword('editOrgPassword', this)"></i>
                            </div>
                            <small style="color: var(--text-muted);">At least 8 characters when changing password.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditOrgModal()">Cancel</button>
                    <button type="submit" name="update_organization" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Staff Modal -->
    <div id="staffModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus"></i> Add Staff</h3>
                <button class="close-btn" onclick="closeStaffModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> First Name <span class="required">*</span></label>
                            <input type="text" name="fname" class="form-control" placeholder="First name" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Last Name <span class="required">*</span></label>
                            <input type="text" name="lname" class="form-control" placeholder="Last name" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email <span class="required">*</span></label>
                            <input type="email" name="email" class="form-control" placeholder="email@example.com" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Password <span class="required">*</span></label>
                            <div class="password-field">
                                <input type="password" name="password" id="staffPassword" class="form-control" placeholder="Enter password" required>
                                <i class="fas fa-eye password-toggle" onclick="togglePassword('staffPassword', this)"></i>
                            </div>
                            <small style="color: var(--text-muted);">Minimum 8 characters</small>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-briefcase"></i> Assignment <span class="required">*</span></label>
                            <select name="assignment" class="form-control" required>
                                <option value="">Select Assignment</option>
                                <option value="clinic">Clinic</option>
                                <option value="town_org">Town Organizations</option>
                                <option value="college_org">College Organizations</option>
                                <option value="ssg">Supreme Student Government</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeStaffModal()">Cancel</button>
                    <button type="submit" name="add_staff" class="btn btn-primary">Create Staff</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Staff Modal -->
    <div id="editStaffModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-user-edit"></i> Edit Staff</h3>
                <button class="close-btn" onclick="closeEditStaffModal()">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="staff_id" id="editStaffId">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> First Name <span class="required">*</span></label>
                            <input type="text" name="fname" id="editStaffFname" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Last Name <span class="required">*</span></label>
                            <input type="text" name="lname" id="editStaffLname" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email <span class="required">*</span></label>
                            <input type="email" name="email" id="editStaffEmail" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-briefcase"></i> Assignment <span class="required">*</span></label>
                            <select name="assignment" id="editStaffAssignment" class="form-control" required>
                                <option value="clinic">Clinic</option>
                                <option value="town_org">Town Organizations</option>
                                <option value="college_org">College Organizations</option>
                                <option value="ssg">Supreme Student Government</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> New Password (Optional)</label>
                            <div class="password-field">
                                <input type="password" name="password" id="editStaffPassword" class="form-control" placeholder="Leave blank to keep current password">
                                <i class="fas fa-eye password-toggle" onclick="togglePassword('editStaffPassword', this)"></i>
                            </div>
                            <small style="color: var(--text-muted);">At least 8 characters when changing password.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditStaffModal()">Cancel</button>
                    <button type="submit" name="update_staff" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Approve Clearance Modal -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle"></i> Approve Clearance</h3>
                <button class="close-btn" onclick="closeApproveModal()">&times;</button>
            </div>
            <form method="POST" action="" id="approveForm">
                <div class="modal-body">
                    <input type="hidden" name="clearance_id" id="approveClearanceId">
                    <div class="form-group">
                        <label><i class="fas fa-comment"></i> Remarks (Optional)</label>
                        <textarea name="remarks" class="form-control" rows="4" placeholder="Enter any remarks..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeApproveModal()">Cancel</button>
                    <button type="submit" name="approve_clearance" class="btn btn-success">Approve</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Clearance Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle"></i> Reject Clearance</h3>
                <button class="close-btn" onclick="closeRejectModal()">&times;</button>
            </div>
            <form method="POST" action="" id="rejectForm">
                <div class="modal-body">
                    <input type="hidden" name="clearance_id" id="rejectClearanceId">
                    <div class="form-group">
                        <label><i class="fas fa-comment"></i> Reason for Rejection <span class="required">*</span></label>
                        <textarea name="remarks" class="form-control" rows="4" placeholder="Please explain why this clearance is being rejected..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" name="reject_clearance" class="btn btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Upload Proof Modal -->
    <div id="proofModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-paperclip"></i> Upload Proof</h3>
                <button class="close-btn" onclick="closeProofModal()">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data" id="proofForm">
                <div class="modal-body">
                    <input type="hidden" name="clearance_id" id="proofClearanceId">
                    <div class="form-group">
                        <label><i class="fas fa-file"></i> Select File <span class="required">*</span></label>
                        <input type="file" name="proof_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
                        <small style="color: var(--text-muted);">Allowed: PDF, JPG, PNG (Max 5MB)</small>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-comment"></i> Remarks (Optional)</label>
                        <textarea name="proof_remarks" class="form-control" rows="3" placeholder="Enter any notes about this proof..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeProofModal()">Cancel</button>
                    <button type="submit" name="upload_proof" class="btn btn-primary">Upload</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Clearance Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content large">
            <div class="modal-header">
                <h3><i class="fas fa-file-alt"></i> Clearance Details</h3>
                <button class="close-btn" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div class="modal-body" id="detailsModalBody">
                <div class="empty-state">
                    <i class="fas fa-spinner fa-spin"></i>
                    <p>Loading details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeDetailsModal()">Close</button>
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

        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebarMenu');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');

        function closeSidebar() {
            if (!sidebar) return;
            sidebar.classList.remove('show');
            if (sidebarBackdrop) {
                sidebarBackdrop.classList.remove('show');
            }
            if (menuToggle) {
                menuToggle.setAttribute('aria-expanded', 'false');
            }
        }

        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', () => {
                const isOpen = sidebar.classList.toggle('show');
                if (sidebarBackdrop) {
                    sidebarBackdrop.classList.toggle('show', isOpen);
                }
                menuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
        }

        if (sidebarBackdrop) {
            sidebarBackdrop.addEventListener('click', closeSidebar);
        }

        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                closeSidebar();
            }
        });

        // Tab switching
        function switchTab(tabName, triggerElement = null) {
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            const tabPanel = document.getElementById(tabName);
            if (!tabPanel) {
                return;
            }
            tabPanel.classList.add('active');

            document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
            const activeNav = triggerElement || document.querySelector(`.nav-item[data-tab="${tabName}"]`);
            if (activeNav) {
                activeNav.classList.add('active');
            }

            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);

            if (window.innerWidth <= 768) {
                closeSidebar();
            }
        }

        // Modal functions
        function openOrgModal() {
            document.getElementById('orgModal').style.display = 'flex';
        }

        function closeOrgModal() {
            document.getElementById('orgModal').style.display = 'none';
        }

        function openStaffModal() {
            document.getElementById('staffModal').style.display = 'flex';
        }

        function closeStaffModal() {
            document.getElementById('staffModal').style.display = 'none';
        }

        function closeEditOrgModal() {
            document.getElementById('editOrgModal').style.display = 'none';
        }

        function closeEditStaffModal() {
            document.getElementById('editStaffModal').style.display = 'none';
        }

        function openApproveModal(clearanceId) {
            document.getElementById('approveClearanceId').value = clearanceId;
            document.getElementById('approveModal').style.display = 'flex';
        }

        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
        }

        function openRejectModal(clearanceId) {
            document.getElementById('rejectClearanceId').value = clearanceId;
            document.getElementById('rejectModal').style.display = 'flex';
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
        }

        function openProofModal(clearanceId) {
            document.getElementById('proofClearanceId').value = clearanceId;
            document.getElementById('proofModal').style.display = 'flex';
        }

        function closeProofModal() {
            document.getElementById('proofModal').style.display = 'none';
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }

        // View Clearance Details
        function viewClearanceDetails(clearanceId, studentName, studentId, course, college, address, contact, age, approvedOrgs, totalOrgs, librarianStatus) {
            const modal = document.getElementById('detailsModal');
            const modalBody = document.getElementById('detailsModalBody');

            const allOrgsApproved = (approvedOrgs == totalOrgs && totalOrgs > 0);
            const orgStatusText = approvedOrgs + '/' + totalOrgs + ' organizations approved';
            const normalizedLibrarianStatus = (librarianStatus || 'pending').toLowerCase();
            const librarianApproved = normalizedLibrarianStatus === 'approved';

            modalBody.innerHTML = `
                <div style="display: flex; flex-direction: column; gap: 20px;">
                    <div class="student-info-card">
                        <div class="student-info-header">
                            <h4><i class="fas fa-user-graduate"></i> Student Information</h4>
                            <span class="badge badge-primary">ID: ${studentId}</span>
                        </div>
                        <div class="student-info-grid">
                            <div class="student-info-item">
                                <span class="label">Full Name</span>
                                <span class="value">${studentName}</span>
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
                    
                    <div style="background: var(--bg-secondary); padding: 20px; border-radius: 12px;">
                        <h4 style="color: var(--text-primary); margin-bottom: 15px;">
                            <i class="fas fa-tasks"></i> Organizations Status
                        </h4>
                        <div style="margin-bottom: 15px; padding: 10px; background: var(--card-bg); border-radius: 8px;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span style="font-weight: 600;">Organization Approvals:</span>
                                <span class="badge ${allOrgsApproved ? 'badge-success' : 'badge-warning'}">
                                    ${orgStatusText}
                                </span>
                            </div>
                            ${!allOrgsApproved ?
                    '<p style="margin-top: 10px; color: var(--warning);"><i class="fas fa-info-circle"></i> Waiting for organization approvals before SAS can approve.</p>' :
                    '<p style="margin-top: 10px; color: var(--success);"><i class="fas fa-check-circle"></i> All organizations have approved. You can now approve this clearance.</p>'
                }
                        </div>
                    </div>
                    
                    <div style="background: var(--bg-secondary); padding: 20px; border-radius: 12px;">
                        <h4 style="color: var(--text-primary); margin-bottom: 15px;">
                            <i class="fas fa-tasks"></i> Clearance Progress
                        </h4>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: var(--card-bg); border-radius: 8px;">
                                <span class="status-badge ${librarianApproved ? 'status-approved' : 'status-pending'}" style="width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                    ${librarianApproved ? '<i class="fas fa-check"></i>' : '<i class="fas fa-clock"></i>'}
                                </span>
                                <span style="flex: 1;">Librarian - ${librarianApproved ? 'Approved' : 'Pending'}</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: var(--card-bg); border-radius: 8px;">
                                <span class="status-badge ${allOrgsApproved ? 'status-approved' : 'status-pending'}" style="width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                                    ${allOrgsApproved ? '<i class="fas fa-check"></i>' : '<i class="fas fa-clock"></i>'}
                                </span>
                                <span style="flex: 1;">Organizations - ${orgStatusText}</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: var(--primary-soft); border-radius: 8px;">
                                <span class="status-badge status-pending" style="width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: var(--primary-soft); color: var(--primary);">
                                    <i class="fas fa-clock"></i>
                                </span>
                                <span style="flex: 1;"><strong>Director SAS - ${allOrgsApproved ? 'Ready to Approve' : 'Waiting for Organizations'}</strong></span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: var(--card-bg); border-radius: 8px;">
                                <span class="status-badge status-pending" style="width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: var(--warning-soft); color: var(--warning);">
                                    4
                                </span>
                                <span style="flex: 1;">Dean - Waiting</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: var(--card-bg); border-radius: 8px;">
                                <span class="status-badge status-pending" style="width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: var(--warning-soft); color: var(--warning);">
                                    5
                                </span>
                                <span style="flex: 1;">Cashier - Waiting</span>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: var(--card-bg); border-radius: 8px;">
                                <span class="status-badge status-pending" style="width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: var(--warning-soft); color: var(--warning);">
                                    6
                                </span>
                                <span style="flex: 1;">Registrar - Waiting</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            modal.style.display = 'flex';
        }

        // Password toggle
        function togglePassword(inputId, element) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                element.classList.remove('fa-eye');
                element.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                element.classList.remove('fa-eye-slash');
                element.classList.add('fa-eye');
            }
        }

        // Dashboard preview in org modal
        document.getElementById('org_type_select')?.addEventListener('change', function() {
            const type = this.value;
            const preview = document.getElementById('dashboard_preview');
            const previewText = document.getElementById('dashboard_preview_text');
            
            if (type) {
                let dashboardType = '';
                let officeName = '';
                
                switch(type) {
                    case 'clinic':
                        dashboardType = 'Clinic Dashboard';
                        officeName = 'Clinic Office (ID: 6)';
                        break;
                    case 'town':
                        dashboardType = 'Town Organizations Dashboard';
                        officeName = 'Town Organizations Office (ID: 7)';
                        break;
                    case 'college':
                        dashboardType = 'College Organizations Dashboard';
                        officeName = 'College Organizations Office (ID: 8)';
                        break;
                    case 'ssg':
                        dashboardType = 'SSG Dashboard';
                        officeName = 'Supreme Student Government Office (ID: 9)';
                        break;
                }
                
                previewText.innerHTML = `<strong>Dashboard:</strong> ${dashboardType}<br><strong>Office:</strong> ${officeName}`;
                preview.style.display = 'block';
            } else {
                preview.style.display = 'none';
            }
        });

        // Close modals when clicking outside
        window.addEventListener('click', function (event) {
            const modals = ['orgModal', 'editOrgModal', 'staffModal', 'editStaffModal', 'approveModal', 'rejectModal', 'proofModal', 'detailsModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        });

        // Filter Functions
        function filterClearances() {
            const typeFilter = document.getElementById('clearanceTypeFilter').value.toLowerCase();
            const semesterFilter = document.getElementById('clearanceSemesterFilter').value.toLowerCase();
            const searchFilter = document.getElementById('clearanceSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#clearancesTable tbody tr');

            rows.forEach(row => {
                const rowType = row.getAttribute('data-type')?.toLowerCase() || '';
                const rowSemester = row.getAttribute('data-semester')?.toLowerCase() || '';
                const rowName = row.getAttribute('data-name') || '';
                const rowId = row.getAttribute('data-id') || '';
                const rowCourse = row.getAttribute('data-course') || '';

                const matchesType = !typeFilter || rowType.includes(typeFilter);
                const matchesSemester = !semesterFilter || rowSemester.includes(semesterFilter);
                const matchesSearch = !searchFilter ||
                    rowName.includes(searchFilter) ||
                    rowId.includes(searchFilter) ||
                    rowCourse.includes(searchFilter);

                row.style.display = matchesType && matchesSemester && matchesSearch ? '' : 'none';
            });
        }

        function clearClearanceFilters() {
            document.getElementById('clearanceTypeFilter').value = '';
            document.getElementById('clearanceSemesterFilter').value = '';
            document.getElementById('clearanceSearch').value = '';

            const rows = document.querySelectorAll('#clearancesTable tbody tr');
            rows.forEach(row => row.style.display = '');
        }

        function filterOrganizations() {
            const typeFilter = document.getElementById('orgTypeFilter').value.toLowerCase();
            const searchFilter = document.getElementById('orgSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#orgTable tbody tr');

            rows.forEach(row => {
                if (row.cells.length < 9) return;
                const type = row.cells[2].textContent.toLowerCase();
                const name = row.cells[1].textContent.toLowerCase();
                const matchesType = !typeFilter || type.includes(typeFilter);
                const matchesSearch = !searchFilter || name.includes(searchFilter);
                row.style.display = matchesType && matchesSearch ? '' : 'none';
            });
        }

        function clearOrgFilters() {
            document.getElementById('orgTypeFilter').value = '';
            document.getElementById('orgSearch').value = '';
            const rows = document.querySelectorAll('#orgTable tbody tr');
            rows.forEach(row => row.style.display = '');
        }

        function filterStaff() {
            const typeFilter = document.getElementById('staffTypeFilter').value.toLowerCase();
            const searchFilter = document.getElementById('staffSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#staffTable tbody tr');

            rows.forEach(row => {
                if (row.cells.length < 5) return;
                const assignment = row.cells[2].textContent.toLowerCase();
                const name = row.cells[0].textContent.toLowerCase();
                const email = row.cells[1].textContent.toLowerCase();
                const matchesType = !typeFilter || assignment.includes(typeFilter.replace('_', ' '));
                const matchesSearch = !searchFilter || name.includes(searchFilter) || email.includes(searchFilter);
                row.style.display = matchesType && matchesSearch ? '' : 'none';
            });
        }

        function clearStaffFilters() {
            document.getElementById('staffTypeFilter').value = '';
            document.getElementById('staffSearch').value = '';
            const rows = document.querySelectorAll('#staffTable tbody tr');
            rows.forEach(row => row.style.display = '');
        }

        function filterHistory() {
            const statusFilter = document.getElementById('historyStatusFilter').value.toLowerCase();
            const searchFilter = document.getElementById('historySearch').value.toLowerCase();
            const rows = document.querySelectorAll('#historyTable tbody tr');

            rows.forEach(row => {
                const rowStatus = row.getAttribute('data-status')?.toLowerCase() || '';
                const rowName = row.getAttribute('data-name') || '';
                const rowId = row.getAttribute('data-id') || '';

                const matchesStatus = !statusFilter || rowStatus.includes(statusFilter);
                const matchesSearch = !searchFilter ||
                    rowName.includes(searchFilter) ||
                    rowId.includes(searchFilter);

                row.style.display = matchesStatus && matchesSearch ? '' : 'none';
            });
        }

        function clearHistoryFilters() {
            document.getElementById('historyStatusFilter').value = '';
            document.getElementById('historySearch').value = '';
            const rows = document.querySelectorAll('#historyTable tbody tr');
            rows.forEach(row => row.style.display = '');
        }

        // Edit organization and staff
        function editOrg(button) {
            document.getElementById('editOrgId').value = button.getAttribute('data-org-id') || '';
            document.getElementById('editOrgName').value = button.getAttribute('data-org-name') || '';
            document.getElementById('editOrgType').value = button.getAttribute('data-org-type') || 'clinic';
            document.getElementById('editOrgEmail').value = button.getAttribute('data-org-email') || '';
            document.getElementById('editOrgStatus').value = button.getAttribute('data-org-status') || 'active';
            document.getElementById('editOrgPassword').value = '';
            document.getElementById('editOrgModal').style.display = 'flex';
        }

        function editStaff(button) {
            document.getElementById('editStaffId').value = button.getAttribute('data-staff-id') || '';
            document.getElementById('editStaffFname').value = button.getAttribute('data-fname') || '';
            document.getElementById('editStaffLname').value = button.getAttribute('data-lname') || '';
            document.getElementById('editStaffEmail').value = button.getAttribute('data-email') || '';
            document.getElementById('editStaffAssignment').value = button.getAttribute('data-assignment') || 'clinic';
            document.getElementById('editStaffPassword').value = '';
            document.getElementById('editStaffModal').style.display = 'flex';
        }

        // Avatar upload
        const avatarInput = document.getElementById('avatarUpload');
        const avatarContainer = document.getElementById('avatarContainer');
        const uploadProgress = document.getElementById('uploadProgress');
        const profileImage = document.getElementById('profileImage');
        const avatarIcon = document.getElementById('avatarIcon');

        avatarContainer.addEventListener('click', () => avatarInput.click());

        avatarInput.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;

            const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
            if (!validTypes.includes(file.type)) {
                alert('Please select a valid image file (JPG, PNG, GIF)');
                return;
            }

            if (file.size > 2 * 1024 * 1024) {
                alert('File size must be less than 2MB');
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
                        if (profileImage) {
                            profileImage.src = '../' + data.filepath + '?t=' + new Date().getTime();
                            profileImage.style.display = 'block';
                        }
                        if (avatarIcon) avatarIcon.style.display = 'none';

                        const headerAvatar = document.querySelector('.user-avatar img');
                        if (headerAvatar) headerAvatar.src = '../' + data.filepath + '?t=' + new Date().getTime();

                        showToast('Profile picture updated successfully!', 'success');
                    } else {
                        showToast(data.message || 'Upload failed', 'error');
                    }
                })
                .catch(error => {
                    uploadProgress.classList.remove('show');
                    showToast('Upload failed. Please try again.', 'error');
                });
        });

        // Toast notification
        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.className = `alert alert-${type}`;
            toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
            document.querySelector('.content-area').prepend(toast);

            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 500);
            }, 3000);
        }

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

