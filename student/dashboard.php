<?php
// student/dashboard.php - Complete Student Dashboard with Proof Upload Functionality
// Students can upload proof files to specific sub-admins/organizations

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include database configuration with correct path
require_once __DIR__ . '/../db.php';

// Check if user is logged in and is a student
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

if ($_SESSION['user_role'] !== 'student') {
    header("Location: ../index.php");
    exit();
}

// Get database instance
$db = Database::getInstance();

// Get student information from session
$student_id = $_SESSION['user_id'];
$student_name = $_SESSION['user_name'] ?? '';
$student_email = $_SESSION['user_email'] ?? '';
$student_ismis = $_SESSION['ismis_id'] ?? '';
$student_fname = $_SESSION['user_fname'] ?? '';
$student_lname = $_SESSION['user_lname'] ?? '';

// Initialize variables
$success = '';
$error = '';
$active_tab = $_GET['tab'] ?? 'dashboard';
$profile_pic = null;

// Get current school year and semesters
$current_year = date('Y');
$current_month = date('n');
// Determine current semester based on month
if ($current_month >= 6 && $current_month <= 10) {
    $current_semester = '1st Semester';
} elseif ($current_month >= 11 || $current_month <= 3) {
    $current_semester = '2nd Semester';
} else {
    $current_semester = 'Summer';
}
$school_year = ($current_month >= 6 ? $current_year . '-' . ($current_year + 1) : ($current_year - 1) . '-' . $current_year);
$semesters = ['1st Semester', '2nd Semester', 'Summer'];

// ============================================
// HANDLE PROOF UPLOAD
// ============================================
if (isset($_POST['upload_proof'])) {
    $clearance_id = $_POST['clearance_id'] ?? '';
    $office_name = $_POST['office_name'] ?? '';
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
                $upload_dir = __DIR__ . '/../uploads/proofs/student/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }

                // Generate unique filename
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'proof_student_' . $student_id . '_' . $clearance_id . '_' . time() . '.' . $extension;
                $filepath = 'uploads/proofs/student/' . $filename;

                if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
                    $db = Database::getInstance();

                    // First, get the office_id from office_name
                    $db->query("SELECT office_id FROM offices WHERE office_name = :office_name");
                    $db->bind(':office_name', $office_name);
                    $office_result = $db->single();

                    if (!$office_result) {
                        throw new Exception("Office not found");
                    }

                    $office_id = $office_result['office_id'];

                    // Check if the columns exist
                    $db->query("SHOW COLUMNS FROM clearance LIKE 'student_proof_file'");
                    $column_exists = $db->single();

                    if ($column_exists) {
                        // Update the specific clearance record with proof
                        $db->query("UPDATE clearance SET 
                                    student_proof_file = :proof_file,
                                    student_proof_remarks = :remarks,
                                    student_proof_uploaded_at = NOW(),
                                    updated_at = NOW()
                                    WHERE clearance_id = :id 
                                    AND users_id = :student_id
                                    AND office_id = :office_id");
                        $db->bind(':proof_file', $filepath);
                        $db->bind(':remarks', $remarks);
                        $db->bind(':id', $clearance_id);
                        $db->bind(':student_id', $student_id);
                        $db->bind(':office_id', $office_id);
                    } else {
                        // Fallback to using remarks field
                        $db->query("UPDATE clearance SET 
                                    remarks = CONCAT(IFNULL(remarks, ''), ' | STUDENT PROOF UPLOADED: ', :remarks, ' - File: ', :proof_file),
                                    updated_at = NOW()
                                    WHERE clearance_id = :id 
                                    AND users_id = :student_id
                                    AND office_id = :office_id");
                        $db->bind(':proof_file', $filename);
                        $db->bind(':remarks', $remarks);
                        $db->bind(':id', $clearance_id);
                        $db->bind(':student_id', $student_id);
                        $db->bind(':office_id', $office_id);
                    }

                    if ($db->execute()) {
                        // Log the activity
                        if (class_exists('ActivityLogModel')) {
                            $logModel = new ActivityLogModel();
                            $logModel->log($student_id, 'UPLOAD_PROOF', "Uploaded proof for clearance ID: $clearance_id to $office_name");
                        }

                        $_SESSION['success_message'] = "Proof uploaded successfully! The office will review your submission.";
                        header("Location: dashboard.php?tab=status");
                        exit();
                    } else {
                        $error = "Failed to update clearance with proof info.";
                    }
                } else {
                    $error = "Failed to upload file.";
                }
            } catch (Exception $e) {
                error_log("Error uploading proof: " . $e->getMessage());
                $error = "Database error occurred: " . $e->getMessage();
            }
        }
    } else {
        $error = "Please select a file to upload.";
    }
}

// Handle clearance application
if (isset($_POST['apply_clearance'])) {
    $clearance_type = $_POST['clearance_type'] ?? '';
    $semester = $_POST['semester'] ?? '';
    $school_year_selected = $_POST['school_year'] ?? '';

    if (empty($clearance_type) || empty($semester) || empty($school_year_selected)) {
        $error = "Please select all fields.";
    } else {
        try {
            // Check if student already has pending clearance
            $db->query("SELECT COUNT(*) as count FROM clearance 
                        WHERE users_id = :user_id AND status = 'pending'");
            $db->bind(':user_id', $student_id);
            $result = $db->single();

            if ($result && $result['count'] > 0) {
                $error = "You already have a pending clearance application. Please wait for it to be completed before applying for a new one.";
            } else {
                // Get clearance type ID
                $db->query("SELECT clearance_type_id FROM clearance_type WHERE clearance_name = :type");
                $db->bind(':type', $clearance_type);
                $type_result = $db->single();

                if ($type_result) {
                    $clearance_type_id = $type_result['clearance_type_id'];

                    // Define all 5 offices in correct order from your database
                    $offices = [
                        ['name' => 'Librarian', 'order' => 1],
                        ['name' => 'Director_SAS', 'order' => 2],
                        ['name' => 'Dean', 'order' => 3],
                        ['name' => 'Cashier', 'order' => 4],
                        ['name' => 'Registrar', 'order' => 5]
                    ];

                    // Begin transaction
                    $db->beginTransaction();

                    $success_count = 0;
                    foreach ($offices as $office) {
                        // Get office ID
                        $db->query("SELECT office_id FROM offices WHERE office_name = :name");
                        $db->bind(':name', $office['name']);
                        $office_result = $db->single();

                        if ($office_result) {
                            $clearance_name = "Clearance for " . $student_name . " - " . date('Y-m-d');

                            $sql = "INSERT INTO clearance (
                                clearance_name, users_id, clearance_type_id, office_id, 
                                semester, school_year, office_order, status, created_at
                            ) VALUES (
                                :clearance_name, :user_id, :type_id, :office_id, 
                                :semester, :school_year, :office_order, 'pending', NOW()
                            )";

                            $db->query($sql);
                            $db->bind(':clearance_name', $clearance_name);
                            $db->bind(':user_id', $student_id);
                            $db->bind(':type_id', $clearance_type_id);
                            $db->bind(':office_id', $office_result['office_id']);
                            $db->bind(':semester', $semester);
                            $db->bind(':school_year', $school_year_selected);
                            $db->bind(':office_order', $office['order']);

                            if ($db->execute()) {
                                $success_count++;
                            }
                        }
                    }

                    if ($success_count == count($offices)) {
                        $db->commit();

                        // Log activity
                        if (class_exists('ActivityLogModel')) {
                            $logModel = new ActivityLogModel();
                            $logModel->log($student_id, 'APPLY_CLEARANCE', "Applied for clearance: $clearance_type - $semester $school_year_selected");
                        }

                        $_SESSION['success_message'] = "Clearance application submitted successfully! You can now track your progress.";
                        header("Location: dashboard.php?tab=status");
                        exit();
                    } else {
                        $db->rollback();
                        $error = "Failed to submit clearance application. Please try again.";
                    }
                } else {
                    $error = "Invalid clearance type.";
                }
            }
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollback();
            }
            error_log("Clearance application error: " . $e->getMessage());
            $error = "An error occurred. Please try again.";
        }
    }
}

// Handle cancel application
if (isset($_POST['cancel_application'])) {
    $semester = $_POST['semester'] ?? '';
    $school_year = $_POST['school_year'] ?? '';

    try {
        $db->beginTransaction();

        // Delete all pending clearances for this semester
        $db->query("DELETE FROM clearance 
                    WHERE users_id = :user_id 
                    AND semester = :semester 
                    AND school_year = :school_year 
                    AND status = 'pending'");
        $db->bind(':user_id', $student_id);
        $db->bind(':semester', $semester);
        $db->bind(':school_year', $school_year);
        $db->execute();

        $db->commit();

        // Log activity
        if (class_exists('ActivityLogModel')) {
            $logModel = new ActivityLogModel();
            $logModel->log($student_id, 'CANCEL_CLEARANCE', "Cancelled clearance application for $semester $school_year");
        }

        $_SESSION['success_message'] = "Clearance application cancelled successfully.";
        header("Location: dashboard.php?tab=status");
        exit();

    } catch (Exception $e) {
        $db->rollback();
        error_log("Cancel clearance error: " . $e->getMessage());
        $error = "Failed to cancel application. Please try again.";
    }
}

// Check for session success message
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Get student's complete information
try {
    $db->query("SELECT u.*, c.college_name, cr.course_name, cr.course_code
                FROM users u
                LEFT JOIN college c ON u.college_id = c.college_id
                LEFT JOIN course cr ON u.course_id = cr.course_id
                WHERE u.users_id = :user_id");
    $db->bind(':user_id', $student_id);
    $student_info = $db->single();

    if (!$student_info) {
        $student_info = [];
    }
} catch (Exception $e) {
    error_log("Error fetching student info: " . $e->getMessage());
    $student_info = [];
}

// Get student's profile picture
$profile_pic = $student_info['profile_picture'] ?? null;

// Get all clearance applications with proof information
try {
    // Check if the new columns exist
    $has_student_proof = false;
    $db->query("SHOW COLUMNS FROM clearance LIKE 'student_proof_file'");
    if ($db->single()) {
        $has_student_proof = true;
    }

    if ($has_student_proof) {
        // Query with new columns
        $db->query("SELECT c.*, 
                    o.office_name, 
                    o.office_id,
                    o.office_description,
                    ct.clearance_name as clearance_type_name,
                    DATE_FORMAT(c.created_at, '%M %d, %Y') as formatted_date,
                    DATE_FORMAT(c.created_at, '%Y-%m-%d') as sort_date,
                    DATE_FORMAT(c.processed_date, '%M %d, %Y %h:%i %p') as formatted_processed_date,
                    CONCAT(p.fname, ' ', p.lname) as processed_by_name,
                    c.lacking_comment,
                    c.lacking_comment_at,
                    c.student_proof_file,
                    c.student_proof_remarks,
                    c.student_proof_uploaded_at
                    FROM clearance c
                    LEFT JOIN offices o ON c.office_id = o.office_id
                    LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                    LEFT JOIN users p ON c.processed_by = p.users_id
                    WHERE c.users_id = :user_id
                    ORDER BY c.created_at DESC, c.office_order ASC");
    } else {
        // Query without new columns
        $db->query("SELECT c.*, 
                    o.office_name, 
                    o.office_id,
                    o.office_description,
                    ct.clearance_name as clearance_type_name,
                    DATE_FORMAT(c.created_at, '%M %d, %Y') as formatted_date,
                    DATE_FORMAT(c.created_at, '%Y-%m-%d') as sort_date,
                    DATE_FORMAT(c.processed_date, '%M %d, %Y %h:%i %p') as formatted_processed_date,
                    CONCAT(p.fname, ' ', p.lname) as processed_by_name,
                    c.lacking_comment,
                    c.lacking_comment_at
                    FROM clearance c
                    LEFT JOIN offices o ON c.office_id = o.office_id
                    LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                    LEFT JOIN users p ON c.processed_by = p.users_id
                    WHERE c.users_id = :user_id
                    ORDER BY c.created_at DESC, c.office_order ASC");
    }

    $db->bind(':user_id', $student_id);
    $clearance_data = $db->resultSet();

    if (!$clearance_data) {
        $clearance_data = [];
    }
} catch (Exception $e) {
    error_log("Error fetching clearance data: " . $e->getMessage());
    $clearance_data = [];
    $error = "Error loading clearance data. Please refresh the page.";
}

// Group clearances by semester and school year
$grouped_clearances = [];
$clearance_summary = [
    'total' => 0,
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0,
    'completed' => 0
];

foreach ($clearance_data as $item) {
    $key = $item['semester'] . ' ' . $item['school_year'];
    if (!isset($grouped_clearances[$key])) {
        $grouped_clearances[$key] = [
            'semester' => $item['semester'],
            'school_year' => $item['school_year'],
            'applications' => [],
            'total_offices' => 0,
            'approved_offices' => 0,
            'rejected_offices' => 0,
            'pending_offices' => 0,
            'status' => 'pending',
            'applied_date' => $item['created_at'],
            'clearance_type' => $item['clearance_type_name'] ?? 'Unknown',
            'can_cancel' => false
        ];
    }

    $grouped_clearances[$key]['applications'][] = $item;
    $grouped_clearances[$key]['total_offices']++;

    if ($item['status'] == 'approved') {
        $grouped_clearances[$key]['approved_offices']++;
        $clearance_summary['approved']++;
    } elseif ($item['status'] == 'rejected') {
        $grouped_clearances[$key]['rejected_offices']++;
        $clearance_summary['rejected']++;
    } elseif ($item['status'] == 'pending') {
        $grouped_clearances[$key]['pending_offices']++;
        $clearance_summary['pending']++;
    }

    $clearance_summary['total']++;
}

// Determine overall status for each group and if it can be cancelled
foreach ($grouped_clearances as &$group) {
    if ($group['rejected_offices'] > 0) {
        $group['status'] = 'rejected';
    } elseif ($group['approved_offices'] == $group['total_offices']) {
        $group['status'] = 'approved';
        $clearance_summary['completed']++;
    } else {
        $group['status'] = 'pending';
        // Can only cancel if all offices are still pending
        $group['can_cancel'] = ($group['pending_offices'] == $group['total_offices']);
    }
}

// Get current active clearance (most recent pending/in-progress)
$current_clearance = null;
foreach ($grouped_clearances as $key => $group) {
    if ($group['status'] == 'pending') {
        // Sort applications by office order for this group
        usort($group['applications'], function ($a, $b) {
            return ($a['office_order'] ?? 0) - ($b['office_order'] ?? 0);
        });
        $current_clearance = $group;
        break;
    }
}

// Get clearance types for dropdown
try {
    $db->query("SELECT * FROM clearance_type ORDER BY clearance_name");
    $clearance_types = $db->resultSet();
    if (!$clearance_types) {
        $clearance_types = [];
    }
} catch (Exception $e) {
    error_log("Error fetching clearance types: " . $e->getMessage());
    $clearance_types = [];
}

// Determine the current step in the clearance process
function getCurrentStep($applications)
{
    $step = 1;
    foreach ($applications as $app) {
        if ($app['status'] != 'approved') {
            return $step;
        }
        $step++;
    }
    return $step;
}

// Get office icons
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

// Get office display name
function getOfficeDisplayName($office_name)
{
    return str_replace('_', ' ', $office_name);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | BISU Online Clearance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #412886;
            --primary-light: #6b4bb8;
            --primary-dark: #2e1d5e;
            --secondary: #917FB3;
            --accent: #E5BEEC;
            --success: #2E7D32;
            --success-light: #4CAF50;
            --warning: #F9A826;
            --danger: #C62828;
            --danger-light: #EF5350;
            --info: #1976D2;
            --info-light: #42A5F5;
            --lacking: #f97316;
            --proof: #0ea5e9;
            --bg: #F8F9FA;
            --bg-dark: #E9ECEF;
            --text: #2C3E50;
            --text-light: #7B8A9B;
            --white: #FFFFFF;
            --border: #E0E0E0;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --shadow-hover: 0 15px 40px rgba(0, 0, 0, 0.15);
            --card-gradient: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        }

        .dark-mode {
            --primary: #8b6fd8;
            --primary-light: #a58bd1;
            --primary-dark: #6b4bb8;
            --secondary: #F8B195;
            --bg: #1a1a2e;
            --bg-dark: #16213e;
            --text: #e9e9e9;
            --text-light: #b8b8b8;
            --border: #2a2a4a;
            --white: #16213e;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
        }

        body {
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            transition: background-color 0.3s, color 0.3s;
        }

        /* Modern Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--bg-dark);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--secondary);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary);
        }

        /* Header */
        .header {
            background: var(--white);
            box-shadow: var(--shadow);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 1rem 2rem;
            border-bottom: 1px solid var(--border);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }

        .logo-area {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .logo-icon {
            width: 45px;
            height: 45px;
            background: var(--primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5rem;
            transform: rotate(-5deg);
            transition: transform 0.3s;
        }

        .logo-icon:hover {
            transform: rotate(0deg);
        }

        .logo-text h2 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.2rem;
        }

        .logo-text p {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.5rem 1.5rem;
            background: var(--bg-dark);
            border-radius: 50px;
            transition: all 0.3s;
            cursor: pointer;
        }

        .user-info:hover {
            background: var(--border);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
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
            color: var(--text);
        }

        .user-role {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 0.7rem 1.5rem;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            background: var(--danger-light);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(198, 40, 40, 0.3);
        }

        .theme-toggle {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 999;
            color: white;
            font-size: 1.3rem;
            box-shadow: var(--shadow);
            transition: all 0.3s;
        }

        .theme-toggle:hover {
            transform: rotate(180deg) scale(1.1);
        }

        /* Main Container */
        .main-container {
            display: flex;
            max-width: 1400px;
            margin: 80px auto 0;
            padding: 2rem;
            gap: 2rem;
        }

        /* Sidebar */
        .sidebar {
            width: 300px;
            background: var(--white);
            border-radius: 20px;
            box-shadow: var(--shadow);
            padding: 2rem 0;
            position: sticky;
            top: 100px;
            height: calc(100vh - 120px);
            overflow-y: auto;
            transition: all 0.3s;
            border: 1px solid var(--border);
        }

        .profile-section {
            text-align: center;
            padding: 0 2rem 2rem;
            border-bottom: 2px solid var(--border);
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
            margin: 0 auto 1.5rem;
            position: relative;
            cursor: pointer;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary);
            transition: all 0.3s;
        }

        .profile-avatar:hover img {
            transform: scale(1.05);
        }

        .avatar-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            opacity: 0;
            transition: opacity 0.3s;
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
            font-size: 1.5rem;
        }

        .upload-progress.show {
            display: flex;
        }

        .profile-name {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.3rem;
        }

        .profile-email {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 1rem;
        }

        .profile-id {
            background: var(--primary);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-block;
        }

        .nav-menu {
            padding: 2rem;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            width: 100%;
            border: none;
            background: none;
            color: var(--text);
            font-size: 1rem;
            font-weight: 500;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 0.5rem;
            text-align: left;
        }

        .nav-item i {
            width: 24px;
            font-size: 1.2rem;
            color: var(--text-light);
            transition: all 0.3s;
        }

        .nav-item:hover {
            background: var(--bg-dark);
            transform: translateX(5px);
        }

        .nav-item:hover i {
            color: var(--primary);
        }

        .nav-item.active {
            background: var(--primary);
            color: white;
        }

        .nav-item.active i {
            color: white;
        }

        /* Content Area */
        .content-area {
            flex: 1;
            min-width: 0;
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Welcome Card */
        .welcome-card {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 3rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }

        .welcome-card::before {
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

        .welcome-card h1 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            position: relative;
        }

        .welcome-card p {
            font-size: 1.1rem;
            opacity: 0.9;
            position: relative;
            max-width: 600px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--white);
            padding: 1.5rem;
            border-radius: 16px;
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 1.5rem;
            transition: all 0.3s;
            border: 1px solid var(--border);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
        }

        .stat-icon.total {
            background: var(--primary);
            color: white;
        }

        .stat-icon.approved {
            background: var(--success);
            color: white;
        }

        .stat-icon.pending {
            background: var(--warning);
            color: white;
        }

        .stat-icon.rejected {
            background: var(--danger);
            color: white;
        }

        .stat-icon.completed {
            background: var(--info);
            color: white;
        }

        .stat-details h3 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.3rem;
        }

        .stat-details p {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        /* Section Card */
        .section-card {
            background: var(--white);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            transition: all 0.3s;
        }

        .section-card:hover {
            box-shadow: var(--shadow-hover);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border);
        }

        .section-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-header h2 i {
            color: var(--primary);
        }

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }

        .info-item {
            padding: 1.5rem;
            background: var(--bg);
            border-radius: 12px;
            border: 1px solid var(--border);
        }

        .info-item .label {
            color: var(--text-light);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .info-item .value {
            color: var(--text);
            font-size: 1.2rem;
            font-weight: 600;
        }

        /* Progress Steps - 5 Steps */
        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin: 2rem 0;
            position: relative;
        }

        .progress-steps::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--border);
            z-index: 1;
        }

        .step {
            position: relative;
            z-index: 2;
            text-align: center;
            flex: 1;
        }

        .step-icon {
            width: 40px;
            height: 40px;
            background: var(--white);
            border: 2px solid var(--border);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: 600;
            color: var(--text-light);
            transition: all 0.3s;
        }

        .step.completed .step-icon {
            background: var(--success);
            border-color: var(--success);
            color: white;
        }

        .step.current .step-icon {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
            transform: scale(1.1);
            box-shadow: 0 0 0 5px rgba(65, 40, 134, 0.1);
        }

        .step-label {
            font-size: 0.85rem;
            color: var(--text-light);
            font-weight: 500;
        }

        .step.completed .step-label {
            color: var(--success);
        }

        .step.current .step-label {
            color: var(--primary);
            font-weight: 600;
        }

        /* Office Cards Grid - 5 Columns */
        .offices-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1rem;
            margin: 1.5rem 0;
        }

        @media (max-width: 1200px) {
            .offices-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .offices-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .offices-grid {
                grid-template-columns: 1fr;
            }
        }

        .office-card {
            background: var(--bg);
            border-radius: 12px;
            padding: 1.2rem;
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 0.8rem;
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
        }

        .office-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }

        .office-card.lacking {
            border-left: 4px solid var(--lacking);
        }

        .office-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .office-icon.approved {
            background: var(--success);
            color: white;
        }

        .office-icon.pending {
            background: var(--warning);
            color: white;
        }

        .office-icon.rejected {
            background: var(--danger);
            color: white;
        }

        .office-details {
            width: 100%;
        }

        .office-name {
            font-weight: 600;
            color: var(--text);
            margin-bottom: 0.3rem;
            font-size: 0.95rem;
        }

        .office-status {
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            display: inline-block;
            margin-bottom: 0.3rem;
        }

        .office-status.approved {
            background: var(--success);
            color: white;
        }

        .office-status.pending {
            background: var(--warning);
            color: white;
        }

        .office-status.rejected {
            background: var(--danger);
            color: white;
        }

        .lacking-badge {
            background: var(--lacking);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            margin-top: 0.3rem;
        }

        .proof-badge {
            background: var(--proof);
            color: white;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
            margin-top: 0.3rem;
        }

        .office-remarks {
            font-size: 0.7rem;
            color: var(--text-light);
            margin-top: 0.5rem;
            word-break: break-word;
            max-width: 100%;
        }

        .office-remarks i {
            font-size: 0.6rem;
            margin-right: 0.2rem;
        }

        .office-date {
            font-size: 0.65rem;
            color: var(--text-light);
            margin-top: 0.2rem;
        }

        .lacking-comment {
            background: rgba(249, 115, 22, 0.1);
            color: var(--lacking);
            padding: 0.8rem;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-top: 0.5rem;
            border-left: 3px solid var(--lacking);
            text-align: left;
        }

        .lacking-comment i {
            margin-right: 0.5rem;
        }

        .upload-btn {
            background: var(--proof);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            margin-top: 0.5rem;
            transition: all 0.3s;
            width: 100%;
            justify-content: center;
        }

        .upload-btn:hover {
            background: var(--info);
            transform: translateY(-2px);
        }

        .view-proof-btn {
            background: var(--proof);
            color: white;
            border: none;
            padding: 0.3rem 0.8rem;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.3s;
            text-decoration: none;
        }

        .view-proof-btn:hover {
            background: var(--info);
        }

        /* Apply Card */
        .apply-card {
            max-width: 600px;
            margin: 0 auto;
        }

        .apply-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .apply-icon {
            width: 80px;
            height: 80px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: white;
            font-size: 2rem;
        }

        .apply-header h3 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.5rem;
        }

        .apply-header p {
            color: var(--text-light);
        }

        .apply-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group label {
            font-weight: 600;
            color: var(--text);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group label i {
            color: var(--primary);
        }

        .form-control {
            padding: 1rem;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 0.95rem;
            transition: all 0.3s;
            background: var(--white);
            color: var(--text);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(65, 40, 134, 0.1);
        }

        .apply-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 1.2rem;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .apply-btn:hover {
            background: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .cancel-btn {
            background: var(--danger);
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .cancel-btn:hover {
            background: var(--danger-light);
            transform: translateY(-2px);
        }

        /* Pending Banner */
        .pending-banner {
            background: linear-gradient(135deg, var(--warning) 0%, #FBC02D 100%);
            color: white;
            padding: 3rem;
            border-radius: 20px;
            text-align: center;
        }

        .pending-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
        }

        .pending-banner h3 {
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        .pending-banner p {
            margin-bottom: 2rem;
            opacity: 0.9;
        }

        .pending-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
        }

        .btn {
            padding: 1rem 2rem;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: white;
            color: var(--warning);
        }

        .btn-outline {
            background: transparent;
            border: 2px solid white;
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        /* Timeline */
        .timeline-item {
            background: var(--white);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border);
            transition: all 0.3s;
        }

        .timeline-item:hover {
            box-shadow: var(--shadow);
        }

        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .timeline-title {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .timeline-title h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text);
        }

        .timeline-badge {
            padding: 0.4rem 1.2rem;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .badge-approved {
            background: var(--success);
            color: white;
        }

        .badge-pending {
            background: var(--warning);
            color: white;
        }

        .badge-rejected {
            background: var(--danger);
            color: white;
        }

        .timeline-date {
            color: var(--text-light);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* Filter Bar */
        .filter-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            background: var(--bg);
            padding: 1rem;
            border-radius: 50px;
            border: 1px solid var(--border);
        }

        .filter-select {
            padding: 0.8rem 1.5rem;
            border: 2px solid var(--border);
            border-radius: 50px;
            background: var(--white);
            color: var(--text);
            font-size: 0.95rem;
            cursor: pointer;
            min-width: 150px;
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .filter-btn {
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 50px;
            background: var(--primary);
            color: white;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
        }

        .filter-btn:hover {
            background: var(--primary-light);
            transform: translateY(-2px);
        }

        .clear-filter {
            padding: 0.8rem 2rem;
            border: 2px solid var(--danger);
            border-radius: 50px;
            background: transparent;
            color: var(--danger);
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
        }

        .clear-filter:hover {
            background: var(--danger);
            color: white;
        }

        /* Summary Stats */
        .summary-stats {
            display: flex;
            gap: 2rem;
            margin-top: 1.5rem;
            padding: 1rem;
            background: var(--bg);
            border-radius: 12px;
            flex-wrap: wrap;
        }

        .summary-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .summary-item i {
            font-size: 1.2rem;
        }

        .summary-item span {
            color: var(--text);
            font-weight: 500;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: var(--white);
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: var(--shadow-hover);
            border: 1px solid var(--border);
        }

        .modal-content.large {
            max-width: 700px;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 2px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: var(--white);
            z-index: 10;
        }

        .modal-header h3 {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--text);
        }

        .modal-header h3 i {
            color: var(--primary);
        }

        .close {
            width: 35px;
            height: 35px;
            background: var(--bg);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            font-size: 1.2rem;
        }

        .close:hover {
            background: var(--danger);
            color: white;
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 2px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            position: sticky;
            bottom: 0;
            background: var(--white);
        }

        .btn-secondary {
            background: var(--bg);
            color: var(--text);
            border: 1px solid var(--border);
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-secondary:hover {
            background: var(--border);
        }

        .btn-proof-upload {
            background: var(--proof);
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
        }

        .btn-proof-upload:hover {
            background: var(--info);
            transform: translateY(-2px);
        }

        /* Toast */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 2rem;
            border-radius: 50px;
            color: white;
            font-weight: 500;
            z-index: 10000;
            animation: slideIn 0.3s ease;
            box-shadow: var(--shadow);
        }

        .toast.success {
            background: var(--success);
        }

        .toast.error {
            background: var(--danger);
        }

        .toast.info {
            background: var(--info);
        }

        @keyframes slideIn {
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }

        .empty-state i {
            font-size: 5rem;
            color: var(--text-light);
            margin-bottom: 1.5rem;
            opacity: 0.3;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            color: var(--text);
            margin-bottom: 1rem;
        }

        .empty-state p {
            color: var(--text-light);
            margin-bottom: 2rem;
        }

        /* File Info */
        .file-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: var(--bg);
            border-radius: 8px;
            margin-top: 0.5rem;
        }

        .file-info i {
            font-size: 1.2rem;
            color: var(--proof);
        }

        .file-info a {
            color: var(--proof);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.85rem;
        }

        .file-info a:hover {
            text-decoration: underline;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }

            .main-container {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                position: static;
                height: auto;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .filter-bar {
                flex-direction: column;
                border-radius: 20px;
            }

            .filter-bar select,
            .filter-bar button {
                width: 100%;
            }

            .pending-actions {
                flex-direction: column;
            }

            .progress-steps {
                flex-direction: column;
                gap: 1rem;
            }

            .progress-steps::before {
                display: none;
            }

            .step {
                display: flex;
                align-items: center;
                gap: 1rem;
                text-align: left;
            }

            .step-icon {
                margin: 0;
            }

            .timeline-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <!-- Theme Toggle -->
    <div class="theme-toggle" id="themeToggle">
        <i class="fas fa-moon" id="themeIcon"></i>
    </div>

    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="logo-area">
                <div class="logo-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <div class="logo-text">
                    <h2>BISU Online Clearance</h2>
                    <p>Student Portal</p>
                </div>
            </div>
            <div class="user-menu">
                <div class="user-info" onclick="switchTab('dashboard')">
                    <div class="user-avatar">
                        <?php if (!empty($profile_pic) && file_exists('../' . $profile_pic)): ?>
                            <img src="../<?php echo $profile_pic . '?t=' . time(); ?>" alt="Profile">
                        <?php else: ?>
                            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($student_name); ?>&background=412886&color=fff&size=100"
                                alt="Profile">
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name">
                            <?php echo htmlspecialchars(explode(' ', $student_name)[0]); ?>
                        </div>
                        <div class="user-role">Student</div>
                    </div>
                </div>
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="profile-section">
                <div class="profile-avatar" id="avatarContainer">
                    <?php if (!empty($profile_pic) && file_exists('../' . $profile_pic)): ?>
                        <img src="../<?php echo $profile_pic . '?t=' . time(); ?>" alt="Profile" id="profileImage">
                    <?php else: ?>
                        <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($student_name); ?>&background=412886&color=fff&size=150"
                            alt="Profile" id="profileImage">
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
                    <?php echo htmlspecialchars($student_name); ?>
                </div>
                <div class="profile-email">
                    <?php echo htmlspecialchars($student_email); ?>
                </div>
                <div class="profile-id">
                    <?php echo htmlspecialchars($student_info['ismis_id'] ?? 'N/A'); ?>
                </div>
            </div>

            <nav class="nav-menu">
                <button class="nav-item <?php echo $active_tab == 'dashboard' ? 'active' : ''; ?>"
                    onclick="switchTab('dashboard')">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </button>
                <button class="nav-item <?php echo $active_tab == 'apply' ? 'active' : ''; ?>"
                    onclick="switchTab('apply')">
                    <i class="fas fa-file-signature"></i>
                    <span>Apply Clearance</span>
                </button>
                <button class="nav-item <?php echo $active_tab == 'status' ? 'active' : ''; ?>"
                    onclick="switchTab('status')">
                    <i class="fas fa-chart-line"></i>
                    <span>Track Status</span>
                </button>
                <button class="nav-item <?php echo $active_tab == 'history' ? 'active' : ''; ?>"
                    onclick="switchTab('history')">
                    <i class="fas fa-history"></i>
                    <span>History</span>
                </button>
            </nav>
        </aside>

        <!-- Content Area -->
        <main class="content-area">
            <!-- Alert Messages -->
            <?php if ($success): ?>
                <div class="toast success">
                    <i class="fas fa-check-circle"></i>
                    <span>
                        <?php echo $success; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="toast error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>
                        <?php echo $error; ?>
                    </span>
                </div>
            <?php endif; ?>

            <!-- Dashboard Tab -->
            <div id="dashboard" class="tab-content <?php echo $active_tab == 'dashboard' ? 'active' : ''; ?>">
                <!-- Welcome Card -->
                <div class="welcome-card">
                    <h1>Welcome back,
                        <?php echo htmlspecialchars(explode(' ', $student_name)[0]); ?>! 👋
                    </h1>
                    <p>Track your clearance progress and manage your applications from here. Complete all 5 steps to get
                        your clearance.</p>
                </div>

                <!-- Stats Grid - 5 Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon total">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="stat-details">
                            <h3>
                                <?php echo $clearance_summary['total']; ?>
                            </h3>
                            <p>Total Applications</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon approved">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-details">
                            <h3>
                                <?php echo $clearance_summary['approved']; ?>
                            </h3>
                            <p>Approved</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon pending">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-details">
                            <h3>
                                <?php echo $clearance_summary['pending']; ?>
                            </h3>
                            <p>Pending</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon rejected">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-details">
                            <h3>
                                <?php echo $clearance_summary['rejected']; ?>
                            </h3>
                            <p>Rejected</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon completed">
                            <i class="fas fa-check-double"></i>
                        </div>
                        <div class="stat-details">
                            <h3>
                                <?php echo $clearance_summary['completed']; ?>
                            </h3>
                            <p>Completed</p>
                        </div>
                    </div>
                </div>

                <!-- Student Information -->
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-graduation-cap"></i> Your Information</h2>
                    </div>
                    <div class="info-grid">
                        <div class="info-item">
                            <div class="label">College</div>
                            <div class="value">
                                <?php echo htmlspecialchars($student_info['college_name'] ?? 'N/A'); ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="label">Course</div>
                            <div class="value">
                                <?php echo htmlspecialchars($student_info['course_name'] ?? 'N/A') . ' (' . htmlspecialchars($student_info['course_code'] ?? '') . ')'; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="label">ISMIS ID</div>
                            <div class="value">
                                <?php echo htmlspecialchars($student_info['ismis_id'] ?? 'N/A'); ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="label">Contact</div>
                            <div class="value">
                                <?php echo htmlspecialchars($student_info['contacts'] ?? 'N/A'); ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="label">Address</div>
                            <div class="value">
                                <?php echo htmlspecialchars($student_info['address'] ?? 'N/A'); ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="label">Age</div>
                            <div class="value">
                                <?php echo htmlspecialchars($student_info['age'] ?? 'N/A'); ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Current Clearance Progress -->
                <?php if ($current_clearance): ?>
                    <div class="section-card">
                        <div class="section-header">
                            <h2><i class="fas fa-tasks"></i> Current Clearance Progress</h2>
                            <span class="timeline-badge badge-pending">In Progress</span>
                        </div>

                        <?php
                        $current_step = getCurrentStep($current_clearance['applications']);
                        $steps = ['Librarian', 'Director SAS', 'Dean', 'Cashier', 'Registrar'];
                        ?>

                        <!-- Progress Steps - All 5 steps -->
                        <div class="progress-steps">
                            <?php foreach ($steps as $index => $step):
                                $status = '';
                                if ($index + 1 < $current_step)
                                    $status = 'completed';
                                elseif ($index + 1 == $current_step)
                                    $status = 'current';
                                ?>
                                <div class="step <?php echo $status; ?>">
                                    <div class="step-icon">
                                        <?php echo $index + 1; ?>
                                    </div>
                                    <div class="step-label">
                                        <?php echo $step; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Office Cards - All 5 offices -->
                        <div class="offices-grid">
                            <?php foreach ($current_clearance['applications'] as $office): ?>
                                <div class="office-card <?php echo !empty($office['lacking_comment']) ? 'lacking' : ''; ?>"
                                    onclick='viewDetails(<?php echo json_encode($office); ?>)'>
                                    <div class="office-icon <?php echo $office['status']; ?>">
                                        <i class="fas fa-<?php echo getOfficeIcon($office['office_name']); ?>"></i>
                                    </div>
                                    <div class="office-details">
                                        <div class="office-name">
                                            <?php echo getOfficeDisplayName($office['office_name']); ?>
                                        </div>
                                        <div class="office-status <?php echo $office['status']; ?>">
                                            <?php echo ucfirst($office['status']); ?>
                                        </div>

                                        <?php if (!empty($office['lacking_comment'])): ?>
                                            <div class="lacking-badge">
                                                <i class="fas fa-exclamation-triangle"></i> Lacking Items
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($office['student_proof_file'])): ?>
                                            <div class="proof-badge">
                                                <i class="fas fa-check-circle"></i> Proof Uploaded
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($office['remarks'])): ?>
                                            <div class="office-remarks" title="<?php echo htmlspecialchars($office['remarks']); ?>">
                                                <i class="fas fa-comment"></i>
                                                <?php echo htmlspecialchars(substr($office['remarks'], 0, 20)) . '...'; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($current_clearance['can_cancel']): ?>
                            <div style="text-align: right; margin-top: 1rem;">
                                <form method="POST"
                                    onsubmit="return confirm('Are you sure you want to cancel this application? This action cannot be undone.');">
                                    <input type="hidden" name="semester" value="<?php echo $current_clearance['semester']; ?>">
                                    <input type="hidden" name="school_year"
                                        value="<?php echo $current_clearance['school_year']; ?>">
                                    <button type="submit" name="cancel_application" class="cancel-btn">
                                        <i class="fas fa-times-circle"></i> Cancel Application
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- No Applications Yet -->
                <?php if (empty($clearance_data)): ?>
                    <div class="section-card">
                        <div class="empty-state">
                            <i class="fas fa-file-alt"></i>
                            <h3>No Clearance Applications Yet</h3>
                            <p>Get started by applying for your first clearance. It's quick and easy!</p>
                            <button class="btn btn-primary" onclick="switchTab('apply')"
                                style="background: var(--primary); color: white;">
                                <i class="fas fa-file-signature"></i> Apply Now
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Apply Clearance Tab -->
            <div id="apply" class="tab-content <?php echo $active_tab == 'apply' ? 'active' : ''; ?>">
                <div class="apply-card">
                    <?php if ($current_clearance): ?>
                        <div class="pending-banner">
                            <div class="pending-icon">
                                <i class="fas fa-hourglass-half"></i>
                            </div>
                            <h3>Application in Progress</h3>
                            <p>You already have a pending clearance application for
                                <?php echo $current_clearance['semester'] . ' ' . $current_clearance['school_year']; ?>. You
                                can only have one active clearance at a time.
                            </p>
                            <div class="pending-actions">
                                <button class="btn btn-primary" onclick="switchTab('status')">
                                    <i class="fas fa-eye"></i> View Status
                                </button>
                                <button class="btn btn-outline" onclick="switchTab('dashboard')">
                                    <i class="fas fa-home"></i> Dashboard
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="apply-header">
                            <div class="apply-icon">
                                <i class="fas fa-file-signature"></i>
                            </div>
                            <h3>Apply for Clearance</h3>
                            <p>Fill in the details below to submit your clearance application</p>
                        </div>

                        <form method="POST" action="" class="apply-form">
                            <div class="form-group">
                                <label><i class="fas fa-tag"></i> Clearance Type</label>
                                <select name="clearance_type" class="form-control" required>
                                    <option value="">Select clearance type</option>
                                    <?php foreach ($clearance_types as $type): ?>
                                        <option value="<?php echo $type['clearance_name']; ?>">
                                            <?php echo ucfirst($type['clearance_name']); ?> Clearance
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label><i class="fas fa-calendar"></i> School Year</label>
                                    <select name="school_year" class="form-control" required>
                                        <option value="">Select year</option>
                                        <option value="<?php echo $school_year; ?>" selected>
                                            <?php echo $school_year; ?>
                                        </option>
                                        <option value="<?php echo ($current_year - 1) . '-' . $current_year; ?>">
                                            <?php echo ($current_year - 1) . '-' . $current_year; ?>
                                        </option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label><i class="fas fa-clock"></i> Semester</label>
                                    <select name="semester" class="form-control" required>
                                        <option value="">Select semester</option>
                                        <?php foreach ($semesters as $semester): ?>
                                            <option value="<?php echo $semester; ?>" <?php echo ($semester == $current_semester) ? 'selected' : ''; ?>>
                                                <?php echo $semester; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <button type="submit" name="apply_clearance" class="apply-btn">
                                <i class="fas fa-paper-plane"></i>
                                Submit Application
                            </button>
                        </form>

                        <!-- Process Flow Info - All 5 offices -->
                        <div style="margin-top: 2rem; background: var(--bg); border-radius: 12px; padding: 1.5rem;">
                            <h4
                                style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem; color: var(--text);">
                                <i class="fas fa-info-circle" style="color: var(--primary);"></i>
                                Clearance Process Flow (5 Steps)
                            </h4>
                            <div style="display: flex; flex-wrap: wrap; gap: 1rem;">
                                <div
                                    style="display: flex; align-items: center; gap: 0.5rem; background: var(--white); padding: 0.5rem 1rem; border-radius: 50px;">
                                    <span
                                        style="width: 25px; height: 25px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 600;">1</span>
                                    <span style="color: var(--text);">Librarian</span>
                                </div>
                                <div
                                    style="display: flex; align-items: center; gap: 0.5rem; background: var(--white); padding: 0.5rem 1rem; border-radius: 50px;">
                                    <span
                                        style="width: 25px; height: 25px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 600;">2</span>
                                    <span style="color: var(--text);">Director SAS</span>
                                </div>
                                <div
                                    style="display: flex; align-items: center; gap: 0.5rem; background: var(--white); padding: 0.5rem 1rem; border-radius: 50px;">
                                    <span
                                        style="width: 25px; height: 25px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 600;">3</span>
                                    <span style="color: var(--text);">Dean</span>
                                </div>
                                <div
                                    style="display: flex; align-items: center; gap: 0.5rem; background: var(--white); padding: 0.5rem 1rem; border-radius: 50px;">
                                    <span
                                        style="width: 25px; height: 25px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 600;">4</span>
                                    <span style="color: var(--text);">Cashier</span>
                                </div>
                                <div
                                    style="display: flex; align-items: center; gap: 0.5rem; background: var(--white); padding: 0.5rem 1rem; border-radius: 50px;">
                                    <span
                                        style="width: 25px; height: 25px; background: var(--primary); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 600;">5</span>
                                    <span style="color: var(--text);">Registrar</span>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Status Tab -->
            <div id="status" class="tab-content <?php echo $active_tab == 'status' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-chart-line"></i> Clearance Status</h2>
                    </div>

                    <!-- Filter Bar -->
                    <div class="filter-bar">
                        <select class="filter-select" id="statusFilter">
                            <option value="">All Status</option>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                        <input type="text" class="filter-select" id="dateFilter" placeholder="Filter by date...">
                        <button class="filter-btn" onclick="filterStatusTable()">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <button class="clear-filter" onclick="clearStatusFilters()">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>

                    <?php if (empty($grouped_clearances)): ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <h3>No Clearance Applications Found</h3>
                            <p>Apply for clearance to see your status here.</p>
                            <button class="btn btn-primary" onclick="switchTab('apply')"
                                style="background: var(--primary); color: white;">
                                <i class="fas fa-file-signature"></i> Apply Now
                            </button>
                        </div>
                    <?php else: ?>
                        <!-- Timeline View -->
                        <div class="clearance-timeline" id="statusTimeline">
                            <?php foreach ($grouped_clearances as $key => $group): ?>
                                <div class="timeline-item" data-status="<?php echo $group['status']; ?>"
                                    data-date="<?php echo date('Y-m-d', strtotime($group['applied_date'])); ?>">

                                    <!-- Timeline Header -->
                                    <div class="timeline-header">
                                        <div class="timeline-title">
                                            <i class="fas fa-calendar-alt"
                                                style="color: var(--primary); font-size: 1.3rem;"></i>
                                            <h3>
                                                <?php echo $key; ?>
                                            </h3>
                                            <span class="timeline-badge badge-<?php echo $group['status']; ?>">
                                                <?php echo ucfirst($group['status']); ?>
                                            </span>
                                            <span style="color: var(--text-light); font-size: 0.9rem;">
                                                (
                                                <?php echo $group['clearance_type']; ?>)
                                            </span>
                                        </div>
                                        <div class="timeline-date">
                                            <i class="fas fa-clock"></i>
                                            Applied:
                                            <?php echo date('M d, Y', strtotime($group['applied_date'])); ?>
                                        </div>
                                    </div>

                                    <!-- Progress Steps -->
                                    <div class="progress-steps">
                                        <?php
                                        $current_step = getCurrentStep($group['applications']);
                                        foreach ($steps as $index => $step):
                                            $status = '';
                                            if ($group['status'] == 'approved') {
                                                $status = 'completed';
                                            } elseif ($group['status'] == 'rejected' && $index + 1 <= $group['rejected_offices']) {
                                                $status = '';
                                            } elseif ($index + 1 < $current_step) {
                                                $status = 'completed';
                                            } elseif ($index + 1 == $current_step && $group['status'] == 'pending') {
                                                $status = 'current';
                                            }
                                            ?>
                                            <div class="step <?php echo $status; ?>">
                                                <div class="step-icon">
                                                    <?php echo $index + 1; ?>
                                                </div>
                                                <div class="step-label">
                                                    <?php echo $step; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <!-- Office Cards -->
                                    <h4 style="margin: 1.5rem 0 1rem; color: var(--text);">Office Status</h4>
                                    <div class="offices-grid">
                                        <?php foreach ($group['applications'] as $app): ?>
                                            <div class="office-card <?php echo !empty($app['lacking_comment']) ? 'lacking' : ''; ?>"
                                                onclick='viewDetails(<?php echo json_encode($app); ?>)'>
                                                <div class="office-icon <?php echo $app['status']; ?>">
                                                    <i class="fas fa-<?php echo getOfficeIcon($app['office_name']); ?>"></i>
                                                </div>
                                                <div class="office-details">
                                                    <div class="office-name">
                                                        <?php echo getOfficeDisplayName($app['office_name']); ?>
                                                    </div>
                                                    <div class="office-status <?php echo $app['status']; ?>">
                                                        <?php echo ucfirst($app['status']); ?>
                                                    </div>

                                                    <?php if (!empty($app['lacking_comment'])): ?>
                                                        <div class="lacking-badge">
                                                            <i class="fas fa-exclamation-triangle"></i> Lacking Items
                                                        </div>

                                                        <?php if (empty($app['student_proof_file'])): ?>
                                                            <button class="upload-btn"
                                                                onclick="event.stopPropagation(); openUploadModal(<?php echo $app['clearance_id']; ?>, '<?php echo $app['office_name']; ?>')">
                                                                <i class="fas fa-upload"></i> Upload Proof
                                                            </button>
                                                        <?php else: ?>
                                                            <div class="proof-badge">
                                                                <i class="fas fa-check-circle"></i> Proof Uploaded
                                                            </div>
                                                            <?php if (!empty($app['student_proof_file'])): ?>
                                                                <a href="../<?php echo $app['student_proof_file']; ?>" target="_blank"
                                                                    class="view-proof-btn" onclick="event.stopPropagation();">
                                                                    <i class="fas fa-eye"></i> View Proof
                                                                </a>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    <?php endif; ?>

                                                    <?php if (!empty($app['processed_date'])): ?>
                                                        <div class="office-date">
                                                            <i class="fas fa-check-circle"></i>
                                                            <?php echo date('M d, Y', strtotime($app['processed_date'])); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <!-- Lacking Comments Summary -->
                                    <?php
                                    $has_lacking = false;
                                    $lacking_offices = [];
                                    foreach ($group['applications'] as $app) {
                                        if (!empty($app['lacking_comment'])) {
                                            $has_lacking = true;
                                            $lacking_offices[] = [
                                                'office' => getOfficeDisplayName($app['office_name']),
                                                'comment' => $app['lacking_comment']
                                            ];
                                        }
                                    }
                                    ?>

                                    <?php if ($has_lacking): ?>
                                        <div
                                            style="margin-top: 1rem; padding: 1rem; background: rgba(249, 115, 22, 0.1); border-radius: 8px;">
                                            <h5
                                                style="display: flex; align-items: center; gap: 0.5rem; color: var(--lacking); margin-bottom: 0.5rem;">
                                                <i class="fas fa-exclamation-triangle"></i> Items Needed
                                            </h5>
                                            <?php foreach ($lacking_offices as $lo): ?>
                                                <div
                                                    style="margin-bottom: 0.5rem; padding: 0.5rem; background: var(--white); border-radius: 4px;">
                                                    <strong>
                                                        <?php echo $lo['office']; ?>:
                                                    </strong>
                                                    <?php echo htmlspecialchars($lo['comment']); ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Summary Stats -->
                                    <div class="summary-stats">
                                        <div class="summary-item">
                                            <i class="fas fa-check-circle" style="color: var(--success);"></i>
                                            <span>Approved:
                                                <?php echo $group['approved_offices']; ?>/5
                                            </span>
                                        </div>
                                        <?php if ($group['pending_offices'] > 0): ?>
                                            <div class="summary-item">
                                                <i class="fas fa-clock" style="color: var(--warning);"></i>
                                                <span>Pending:
                                                    <?php echo $group['pending_offices']; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($group['rejected_offices'] > 0): ?>
                                            <div class="summary-item">
                                                <i class="fas fa-times-circle" style="color: var(--danger);"></i>
                                                <span>Rejected:
                                                    <?php echo $group['rejected_offices']; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
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
                        <select class="filter-select" id="historyStatusFilter">
                            <option value="">All Status</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                        <select class="filter-select" id="historyYearFilter">
                            <option value="">All Years</option>
                            <?php
                            $years = [];
                            foreach ($grouped_clearances as $group) {
                                $year = substr($group['school_year'], 0, 4);
                                if (!in_array($year, $years)) {
                                    $years[] = $year;
                                }
                            }
                            sort($years);
                            foreach ($years as $year): ?>
                                <option value="<?php echo $year; ?>">
                                    <?php echo $year; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="filter-btn" onclick="filterHistory()">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <button class="clear-filter" onclick="clearHistoryFilters()">
                            <i class="fas fa-times"></i> Clear
                        </button>
                    </div>

                    <?php
                    $completed_clearances = array_filter($grouped_clearances, function ($group) {
                        return in_array($group['status'], ['approved', 'rejected']);
                    });
                    ?>

                    <?php if (empty($completed_clearances)): ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <h3>No Clearance History Found</h3>
                            <p>Your completed clearances will appear here.</p>
                        </div>
                    <?php else: ?>
                        <div class="clearance-timeline" id="historyTimeline">
                            <?php foreach ($completed_clearances as $key => $group): ?>
                                <div class="timeline-item" data-status="<?php echo $group['status']; ?>"
                                    data-year="<?php echo substr($group['school_year'], 0, 4); ?>">

                                    <!-- Timeline Header -->
                                    <div class="timeline-header">
                                        <div class="timeline-title">
                                            <i class="fas fa-calendar-check" style="color: var(--primary);"></i>
                                            <h3>
                                                <?php echo $key; ?>
                                            </h3>
                                            <span class="timeline-badge badge-<?php echo $group['status']; ?>">
                                                <?php echo ucfirst($group['status']); ?>
                                            </span>
                                            <span style="color: var(--text-light); font-size: 0.9rem;">
                                                (
                                                <?php echo $group['clearance_type']; ?>)
                                            </span>
                                        </div>
                                        <div class="timeline-date">
                                            <i class="fas fa-clock"></i>
                                            <?php
                                            $last_processed = end($group['applications']);
                                            echo !empty($last_processed['processed_date']) ? 'Completed: ' . date('M d, Y', strtotime($last_processed['processed_date'])) : 'N/A';
                                            ?>
                                        </div>
                                    </div>

                                    <!-- Stats Summary -->
                                    <div
                                        style="display: flex; gap: 2rem; margin-bottom: 1.5rem; padding: 1rem; background: var(--bg); border-radius: 12px; flex-wrap: wrap;">
                                        <div>
                                            <div style="color: var(--text-light); font-size: 0.9rem;">Total Offices</div>
                                            <div style="color: var(--text); font-size: 1.5rem; font-weight: 700;">5</div>
                                        </div>
                                        <div>
                                            <div style="color: var(--text-light); font-size: 0.9rem;">Approved</div>
                                            <div style="color: var(--success); font-size: 1.5rem; font-weight: 700;">
                                                <?php echo $group['approved_offices']; ?>
                                            </div>
                                        </div>
                                        <?php if ($group['rejected_offices'] > 0): ?>
                                            <div>
                                                <div style="color: var(--text-light); font-size: 0.9rem;">Rejected</div>
                                                <div style="color: var(--danger); font-size: 1.5rem; font-weight: 700;">
                                                    <?php echo $group['rejected_offices']; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Office Cards -->
                                    <h4 style="margin: 1rem 0; color: var(--text);">Office Details</h4>
                                    <div class="offices-grid">
                                        <?php foreach ($group['applications'] as $app): ?>
                                            <div class="office-card" onclick='viewDetails(<?php echo json_encode($app); ?>)'>
                                                <div class="office-icon <?php echo $app['status']; ?>">
                                                    <i class="fas fa-<?php echo getOfficeIcon($app['office_name']); ?>"></i>
                                                </div>
                                                <div class="office-details">
                                                    <div class="office-name">
                                                        <?php echo getOfficeDisplayName($app['office_name']); ?>
                                                    </div>
                                                    <div class="office-status <?php echo $app['status']; ?>">
                                                        <?php echo ucfirst($app['status']); ?>
                                                    </div>
                                                    <?php if (!empty($app['remarks'])): ?>
                                                        <div class="office-remarks"
                                                            title="<?php echo htmlspecialchars($app['remarks']); ?>">
                                                            <i class="fas fa-comment"></i>
                                                            <?php echo htmlspecialchars(substr($app['remarks'], 0, 20)) . '...'; ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <?php if (!empty($app['student_proof_file'])): ?>
                                                        <div style="margin-top: 0.5rem;">
                                                            <a href="../<?php echo $app['student_proof_file']; ?>" target="_blank"
                                                                class="view-proof-btn" onclick="event.stopPropagation();">
                                                                <i class="fas fa-eye"></i> View Proof
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Upload Proof Modal -->
    <div id="uploadModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-upload"></i> Upload Proof</h3>
                <button class="close" onclick="closeUploadModal()">&times;</button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data" id="uploadForm">
                <div class="modal-body">
                    <input type="hidden" name="clearance_id" id="uploadClearanceId">
                    <input type="hidden" name="office_name" id="uploadOfficeName">

                    <div class="info-card"
                        style="background: rgba(14, 165, 233, 0.1); padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                        <i class="fas fa-info-circle" style="color: var(--proof);"></i>
                        <span style="color: var(--proof);">Upload proof that you have resolved the lacking items. This
                            will be sent to the office for review.</span>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-file"></i> Select File <span class="required">*</span></label>
                        <input type="file" name="proof_file" id="proofFile" class="form-control"
                            accept=".pdf,.jpg,.jpeg,.png" required>
                        <small style="color: var(--text-light);">Allowed: PDF, JPG, PNG (Max 5MB)</small>
                    </div>

                    <div class="form-group">
                        <label><i class="fas fa-comment"></i> Remarks (Optional)</label>
                        <textarea name="proof_remarks" id="proofRemarks" class="form-control" rows="3"
                            placeholder="e.g., I have returned the books and paid the fines."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeUploadModal()">Cancel</button>
                    <button type="submit" name="upload_proof" class="btn-proof-upload">
                        <i class="fas fa-upload"></i> Upload Proof
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content large">
            <div class="modal-header">
                <h3><i class="fas fa-info-circle"></i> Clearance Details</h3>
                <button class="close" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div class="modal-body" id="detailsModalBody">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button class="btn-secondary" onclick="closeDetailsModal()">Close</button>
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

        // Upload Modal
        function openUploadModal(clearanceId, officeName) {
            document.getElementById('uploadClearanceId').value = clearanceId;
            document.getElementById('uploadOfficeName').value = officeName;
            document.getElementById('uploadModal').style.display = 'flex';
        }

        function closeUploadModal() {
            document.getElementById('uploadModal').style.display = 'none';
            document.getElementById('proofFile').value = '';
            document.getElementById('proofRemarks').value = '';
        }

        // Auto-hide toasts
        setTimeout(() => {
            document.querySelectorAll('.toast').forEach(toast => {
                toast.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            });
        }, 5000);

        // Filter functions
        function filterStatusTable() {
            const statusFilter = document.getElementById('statusFilter').value.toLowerCase();
            const dateFilter = document.getElementById('dateFilter').value.toLowerCase();

            document.querySelectorAll('#statusTimeline .timeline-item').forEach(item => {
                const status = item.getAttribute('data-status').toLowerCase();
                const date = item.getAttribute('data-date').toLowerCase();

                const matchesStatus = !statusFilter || status.includes(statusFilter);
                const matchesDate = !dateFilter || date.includes(dateFilter);

                item.style.display = matchesStatus && matchesDate ? 'block' : 'none';
            });
        }

        function clearStatusFilters() {
            document.getElementById('statusFilter').value = '';
            document.getElementById('dateFilter').value = '';
            document.querySelectorAll('#statusTimeline .timeline-item').forEach(item => {
                item.style.display = 'block';
            });
        }

        function filterHistory() {
            const statusFilter = document.getElementById('historyStatusFilter').value.toLowerCase();
            const yearFilter = document.getElementById('historyYearFilter').value;

            document.querySelectorAll('#historyTimeline .timeline-item').forEach(item => {
                const status = item.getAttribute('data-status').toLowerCase();
                const year = item.getAttribute('data-year');

                const matchesStatus = !statusFilter || status.includes(statusFilter);
                const matchesYear = !yearFilter || year === yearFilter;

                item.style.display = matchesStatus && matchesYear ? 'block' : 'none';
            });
        }

        function clearHistoryFilters() {
            document.getElementById('historyStatusFilter').value = '';
            document.getElementById('historyYearFilter').value = '';
            document.querySelectorAll('#historyTimeline .timeline-item').forEach(item => {
                item.style.display = 'block';
            });
        }

        // View Details
        function viewDetails(item) {
            const modal = document.getElementById('detailsModal');
            const modalBody = document.getElementById('detailsModalBody');

            let lackingHtml = '';
            if (item.lacking_comment) {
                lackingHtml = `
                    <div style="margin-top: 1rem; padding: 1rem; background: rgba(249, 115, 22, 0.1); border-radius: 8px;">
                        <h4 style="color: var(--lacking); margin-bottom: 0.5rem;">
                            <i class="fas fa-exclamation-triangle"></i> Lacking Items
                        </h4>
                        <p>${item.lacking_comment}</p>
                        ${item.lacking_comment_at ? '<small>Since: ' + new Date(item.lacking_comment_at).toLocaleString() + '</small>' : ''}
                    </div>
                `;
            }

            let proofHtml = '';
            if (item.student_proof_file) {
                proofHtml = `
                    <div style="margin-top: 1rem; padding: 1rem; background: rgba(14, 165, 233, 0.1); border-radius: 8px;">
                        <h4 style="color: var(--proof); margin-bottom: 0.5rem;">
                            <i class="fas fa-check-circle"></i> Your Proof
                        </h4>
                        <div class="file-info">
                            <i class="fas fa-file"></i>
                            <a href="../${item.student_proof_file}" target="_blank">View Uploaded Proof</a>
                        </div>
                        ${item.student_proof_remarks ? '<p><strong>Remarks:</strong> ' + item.student_proof_remarks + '</p>' : ''}
                        ${item.student_proof_uploaded_at ? '<small>Uploaded: ' + new Date(item.student_proof_uploaded_at).toLocaleString() + '</small>' : ''}
                    </div>
                `;
            }

            modalBody.innerHTML = `
                <div style="display: grid; gap: 1rem;">
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                        <strong>Office:</strong> 
                        <span>${item.office_name ? item.office_name.replace('_', ' ') : 'N/A'}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                        <strong>Type:</strong> 
                        <span>${item.clearance_type_name || 'N/A'}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                        <strong>Semester:</strong> 
                        <span>${item.semester || 'N/A'} ${item.school_year || ''}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                        <strong>Status:</strong> 
                        <span class="office-status ${item.status}" style="display: inline-block;">${item.status}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                        <strong>Applied:</strong> 
                        <span>${item.formatted_date || 'N/A'}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                        <strong>Processed:</strong> 
                        <span>${item.formatted_processed_date || 'Not processed yet'}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--border);">
                        <strong>Processed By:</strong> 
                        <span>${item.processed_by_name || 'N/A'}</span>
                    </div>
                    ${lackingHtml}
                    ${proofHtml}
                    <div style="display: flex; flex-direction: column; gap: 0.5rem; padding: 0.5rem 0;">
                        <strong>Remarks:</strong> 
                        <p style="background: var(--bg); padding: 1rem; border-radius: 8px;">${item.remarks || 'No remarks'}</p>
                    </div>
                </div>
            `;

            modal.style.display = 'flex';
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }

        // Avatar upload
        const avatarInput = document.getElementById('avatarUpload');
        const avatarContainer = document.getElementById('avatarContainer');
        const uploadProgress = document.getElementById('uploadProgress');
        const profileImage = document.getElementById('profileImage');

        if (avatarContainer) {
            avatarContainer.addEventListener('click', () => avatarInput.click());
        }

        if (avatarInput) {
            avatarInput.addEventListener('change', function (e) {
                const file = e.target.files[0];
                if (!file) return;

                const validTypes = ['image/jpeg', 'image/png', 'image/gif'];
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
                formData.append('user_id', '<?php echo $student_id; ?>');

                fetch('../upload_avatar.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        uploadProgress.classList.remove('show');
                        if (data.success) {
                            profileImage.src = '../' + data.filepath + '?t=' + new Date().getTime();
                            document.querySelector('.user-avatar img').src = '../' + data.filepath + '?t=' + new Date().getTime();
                            showToast('Profile picture updated!', 'success');
                        } else {
                            showToast(data.message || 'Upload failed', 'error');
                        }
                    })
                    .catch(error => {
                        uploadProgress.classList.remove('show');
                        showToast('Upload failed', 'error');
                    });
            });
        }

        // Toast function
        function showToast(message, type) {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${message}`;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Close modal when clicking outside
        window.onclick = function (event) {
            const uploadModal = document.getElementById('uploadModal');
            const detailsModal = document.getElementById('detailsModal');

            if (event.target == uploadModal) {
                uploadModal.style.display = 'none';
            }
            if (event.target == detailsModal) {
                detailsModal.style.display = 'none';
            }
        };
    </script>
</body>

</html>