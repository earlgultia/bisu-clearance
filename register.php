<?php
// register.php - Enhanced User Registration for BISU Student Online Clearance System

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database configuration
require_once 'db.php';

// Redirect if already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $role = $_SESSION['user_role'] ?? '';
    
    if ($role === 'super_admin') {
        header("Location: admin/dashboard.php");
    } elseif ($role === 'sub_admin') {
        header("Location: sub_admin/dashboard.php");
    } elseif ($role === 'office_staff') {
        header("Location: staff/dashboard.php");
    } elseif ($role === 'student') {
        header("Location: student/dashboard.php");
    } elseif ($role === 'organization') {
        header("Location: organization/dashboard.php");
    } else {
        header("Location: index.php");
    }
    exit();
}

// Initialize variables
$error = '';
$success = '';
$form_data = [
    'fname' => '',
    'lname' => '',
    'email' => '',
    'ismis_id' => '',
    'contact' => '',
    'address' => '',
    'age' => '',
    'college_id' => '',
    'course_id' => ''
];

function normalizeNameCase($value)
{
    $normalized = trim((string) $value);
    if ($normalized === '') {
        return '';
    }

    $normalized = preg_replace('/\s+/', ' ', $normalized);

    if (function_exists('mb_convert_case') && function_exists('mb_strtolower')) {
        return mb_convert_case(mb_strtolower($normalized, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    return ucwords(strtolower($normalized));
}

// Fetch colleges for dropdown
$colleges = [];
try {
    $db = Database::getInstance();
    $db->query("SELECT college_id, college_name FROM college ORDER BY college_name");
    $colleges = $db->resultSet();
} catch (Exception $e) {
    error_log("Error fetching colleges: " . $e->getMessage());
}

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize form data
    $form_data = [
        'fname' => normalizeNameCase($_POST['fname'] ?? ''),
        'lname' => normalizeNameCase($_POST['lname'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'ismis_id' => trim($_POST['ismis_id'] ?? ''),
        'contact' => trim($_POST['contact'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'age' => trim($_POST['age'] ?? ''),
        'college_id' => trim($_POST['college_id'] ?? ''),
        'course_id' => trim($_POST['course_id'] ?? '')
    ];

    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validate required fields
    $errors = [];

    if (empty($form_data['fname'])) {
        $errors[] = "First name is required";
    }

    if (empty($form_data['lname'])) {
        $errors[] = "Last name is required";
    }

    if (empty($form_data['email'])) {
        $errors[] = "Email is required";
    } elseif (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address";
    } else {
        // Check if email is from BISU domain
        $email_parts = explode('@', $form_data['email']);
        $domain = strtolower($email_parts[1] ?? '');

        if ($domain !== 'bisu.edu.ph') {
            $errors[] = "Only BISU email addresses (@bisu.edu.ph) are allowed";
        }
    }

    if (empty($form_data['ismis_id'])) {
        $errors[] = "ISMIS ID is required";
    } elseif (!preg_match('/^\d{6}$/', $form_data['ismis_id'])) {
        $errors[] = "ISMIS ID must be exactly 6 digits";
    }

    if (empty($form_data['contact'])) {
        $errors[] = "Contact number is required";
    }

    if (empty($form_data['address'])) {
        $errors[] = "Address is required";
    }

    if (empty($form_data['age'])) {
        $errors[] = "Age is required";
    } elseif (!is_numeric($form_data['age']) || $form_data['age'] < 15 || $form_data['age'] > 100) {
        $errors[] = "Please enter a valid age between 15 and 100";
    }

    if (empty($form_data['college_id'])) {
        $errors[] = "Please select your college";
    }

    if (empty($form_data['course_id'])) {
        $errors[] = "Please select your course";
    }

    if (empty($password)) {
        $errors[] = "Password is required";
    } elseif (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    } elseif (!preg_match("/[A-Z]/", $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    } elseif (!preg_match("/[a-z]/", $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    } elseif (!preg_match("/[0-9]/", $password)) {
        $errors[] = "Password must contain at least one number";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match";
    }

    // Check if email already exists
    if (empty($errors)) {
        try {
            $db->query("SELECT users_id FROM users WHERE emails = :email");
            $db->bind(':email', $form_data['email']);
            if ($db->single()) {
                $errors[] = "Email already registered";
            }
        } catch (Exception $e) {
            error_log("Error checking email: " . $e->getMessage());
        }
    }

    // Check if ISMIS ID already exists
    if (empty($errors)) {
        try {
            $db->query("SELECT users_id FROM users WHERE ismis_id = :ismis_id");
            $db->bind(':ismis_id', $form_data['ismis_id']);
            if ($db->single()) {
                $errors[] = "ISMIS ID already registered";
            }
        } catch (Exception $e) {
            error_log("Error checking ISMIS ID: " . $e->getMessage());
        }
    }

    // If no errors, insert into database
    if (empty($errors)) {
        try {
            // Get student role ID
            $db->query("SELECT user_role_id FROM user_role WHERE user_role_name = 'student'");
            $role = $db->single();
            
            if (!$role) {
                throw new Exception("Student role not found in database");
            }
            
            $student_role_id = $role['user_role_id'];

            // Hash the password
            $hashed_password = hashPassword($password);

            // Insert new user
            $sql = "INSERT INTO users (
                        fname, lname, address, age, contacts, 
                        password, emails, ismis_id, user_role_id, 
                        college_id, course_id, is_active, created_at
                    ) VALUES (
                        :fname, :lname, :address, :age, :contacts,
                        :password, :email, :ismis_id, :role_id,
                        :college_id, :course_id, 1, NOW()
                    )";

            $db->query($sql);
            $db->bind(':fname', $form_data['fname']);
            $db->bind(':lname', $form_data['lname']);
            $db->bind(':address', $form_data['address']);
            $db->bind(':age', $form_data['age']);
            $db->bind(':contacts', $form_data['contact']);
            $db->bind(':password', $hashed_password);
            $db->bind(':email', $form_data['email']);
            $db->bind(':ismis_id', $form_data['ismis_id']);
            $db->bind(':role_id', $student_role_id);
            $db->bind(':college_id', $form_data['college_id']);
            $db->bind(':course_id', $form_data['course_id']);

            if ($db->execute()) {
                $user_id = $db->lastInsertId();
                
                // Log the registration activity
                $logModel = new ActivityLogModel();
                $logModel->log($user_id, 'REGISTER', "New student registered: " . $form_data['fname'] . ' ' . $form_data['lname']);
                
                $success = "Registration successful! You can now login with your credentials.";

                // Clear form data
                $form_data = array_fill_keys(array_keys($form_data), '');

                // Redirect to login page after 3 seconds
                header("refresh:3;url=login.php");
            } else {
                $errors[] = "Registration failed. Please try again.";
            }

        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            $errors[] = "An error occurred. Please try again later.";
        }
    }

    // Set error message if any
    if (!empty($errors)) {
        $error = implode("<br>", $errors);
    }
}

// Handle AJAX request for courses
if (isset($_GET['get_courses']) && isset($_GET['college_id'])) {
    header('Content-Type: application/json');
    try {
        $db = Database::getInstance();
        $db->query("SELECT course_id, course_name, course_code FROM course WHERE college_id = :college_id ORDER BY course_name");
        $db->bind(':college_id', $_GET['college_id']);
        $courses = $db->resultSet();
        echo json_encode(['success' => true, 'courses' => $courses]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - BISU Online Clearance System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Manrope', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            -webkit-tap-highlight-color: transparent;
        }

        /* Light Mode Colors */
        :root {
            --font-display: 'Space Grotesk', 'Manrope', sans-serif;
            --primary: #412886;
            --primary-dark: #2e1d5e;
            --primary-light: #6b4bb8;
            --primary-soft: rgba(65, 40, 134, 0.1);
            --primary-glow: rgba(65, 40, 134, 0.3);
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-tertiary: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border-color: #e2e8f0;
            --card-bg: rgba(255, 255, 255, 0.95);
            --input-bg: #ffffff;
            --input-border: #e2e8f0;
            --shadow-color: rgba(0, 0, 0, 0.1);
            --error-bg: #fef2f2;
            --error-text: #dc2626;
            --error-border: #fecaca;
            --success-bg: #f0fdf4;
            --success-text: #16a34a;
            --success-border: #bbf7d0;
            --hint-bg: #f8fafc;
        }

        /* Dark Mode Colors */
        .dark-mode {
            --primary: #8b6fd8;
            --primary-dark: #6b4bb8;
            --primary-light: #a58bd1;
            --primary-soft: rgba(139, 111, 216, 0.15);
            --bg-primary: #1a1a2e;
            --bg-secondary: #16213e;
            --bg-tertiary: #0f3460;
            --text-primary: #e9e9e9;
            --text-secondary: #b8b8b8;
            --text-muted: #8a8a8a;
            --border-color: #2a2a4a;
            --card-bg: rgba(22, 33, 62, 0.95);
            --input-bg: #1a1a2e;
            --input-border: #2a2a4a;
            --shadow-color: rgba(0, 0, 0, 0.4);
            --error-bg: rgba(220, 38, 38, 0.1);
            --error-text: #f87171;
            --error-border: rgba(220, 38, 38, 0.2);
            --success-bg: rgba(22, 163, 74, 0.1);
            --success-text: #4ade80;
            --success-border: rgba(22, 163, 74, 0.2);
            --hint-bg: #16213e;
        }

        body {
            background:
                radial-gradient(circle at 90% 8%, rgba(255, 255, 255, 0.16) 0%, transparent 32%),
                radial-gradient(circle at 10% 85%, rgba(255, 255, 255, 0.12) 0%, transparent 36%),
                linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            min-height: 100dvh;
            padding: max(24px, env(safe-area-inset-top)) max(16px, env(safe-area-inset-right)) max(24px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.3s ease;
            -webkit-text-size-adjust: 100%;
        }

        body.modal-open {
            overflow: hidden;
        }

        /* Dark Mode Toggle Button */
        .theme-toggle {
            position: fixed;
            top: calc(12px + env(safe-area-inset-top));
            right: calc(12px + env(safe-area-inset-right));
            min-height: 40px;
            padding: 0 14px;
            background: var(--bg-tertiary);
            border-radius: 999px;
            border: 2px solid var(--border-color);
            cursor: pointer;
            z-index: 1000;
            transition: 0.3s;
            backdrop-filter: blur(10px);
            color: var(--text-primary);
            font-size: 0.8rem;
            font-weight: 700;
            font-family: inherit;
        }

        .theme-toggle:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .register-container {
            max-width: 1120px;
            width: 100%;
            margin: 0 auto;
        }

        .register-shell {
            display: grid;
            grid-template-columns: minmax(260px, 330px) minmax(0, 1fr);
            gap: 20px;
            align-items: start;
            max-width: 920px;
            margin: 0 auto;
        }

        .register-aside {
            background: rgba(255, 255, 255, 0.14);
            border: 1px solid rgba(255, 255, 255, 0.26);
            border-radius: 20px;
            padding: 22px 18px;
            backdrop-filter: blur(10px);
            color: #fff;
            box-shadow: 0 14px 30px rgba(16, 23, 42, 0.16);
        }

        .before-register-modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px 16px;
            background: rgba(15, 23, 42, 0.58);
            backdrop-filter: blur(5px);
            z-index: 1300;
        }

        .before-register-modal.show {
            display: flex;
        }

        .before-register-dialog {
            width: min(100%, 560px);
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 22px;
            box-shadow: 0 24px 44px var(--shadow-color);
            padding: 24px;
            color: var(--text-primary);
            position: relative;
            animation: slideUp 0.28s ease;
        }

        .before-register-close {
            position: absolute;
            top: 10px;
            right: 10px;
            min-height: 36px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid var(--border-color);
            background: var(--bg-secondary);
            color: var(--text-secondary);
            cursor: pointer;
            transition: 0.3s;
            font: inherit;
            font-size: 0.82rem;
            font-weight: 700;
        }

        .before-register-close:hover {
            color: var(--primary);
            border-color: var(--primary);
        }

        .before-register-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 999px;
            background: var(--primary-soft);
            color: var(--primary-dark);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 14px;
        }

        .before-register-title {
            font-family: var(--font-display);
            font-size: clamp(1.35rem, 2.4vw, 1.7rem);
            margin-bottom: 10px;
            color: var(--text-primary);
        }

        .before-register-description {
            color: var(--text-secondary);
            line-height: 1.65;
            margin-bottom: 14px;
        }

        .before-register-list {
            list-style: none;
            display: grid;
            gap: 10px;
            margin-bottom: 18px;
        }

        .before-register-list li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            color: var(--text-primary);
            line-height: 1.5;
            font-size: 0.92rem;
            padding: 10px 12px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            background: var(--hint-bg);
        }

        .before-register-action {
            width: 100%;
            min-height: 46px;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            transition: 0.3s;
        }

        .before-register-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px var(--primary-glow);
        }

        .register-aside h3 {
            font-family: var(--font-display);
            font-size: 1.15rem;
            margin-bottom: 12px;
            letter-spacing: -0.01em;
        }

        .register-aside p {
            font-size: 0.92rem;
            line-height: 1.6;
            opacity: 0.95;
            margin-bottom: 14px;
        }

        .aside-list {
            list-style: none;
            display: grid;
            gap: 10px;
        }

        .aside-list li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 0.88rem;
            line-height: 1.5;
        }

        .brand {
            text-align: center;
            margin-bottom: 22px;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.26);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .brand h1 {
            font-family: var(--font-display);
            font-size: clamp(1.8rem, 4vw, 2.25rem);
            margin-bottom: 10px;
            color: white;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .brand p {
            opacity: 0.9;
            color: white;
            font-size: 1.1rem;
        }

        .register-card {
            background: var(--card-bg);
            backdrop-filter: blur(14px);
            border-radius: 24px;
            padding: clamp(22px, 4vw, 40px);
            box-shadow: 0 24px 44px var(--shadow-color);
            animation: slideUp 0.5s ease;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            grid-column: 1 / -1;
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

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
            transition: all 0.3s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .alert-error {
            background: var(--error-bg);
            color: var(--error-text);
            border: 1px solid var(--error-border);
        }

        .alert-success {
            background: var(--success-bg);
            color: var(--success-text);
            border: 1px solid var(--success-border);
        }

        .form-title {
            color: var(--primary);
            margin-bottom: 22px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-title h2 {
            color: var(--text-primary);
            font-size: 1.45rem;
            font-family: var(--font-display);
        }

        .form-section {
            margin-bottom: 14px;
            padding: 16px;
            border-radius: 16px;
            border: 1px solid var(--border-color);
            background: linear-gradient(180deg, color-mix(in srgb, var(--hint-bg) 74%, white 26%) 0%, transparent 100%);
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
        }

        .section-label {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            font-weight: 700;
            color: var(--primary-dark);
            font-size: 0.88rem;
            letter-spacing: 0.01em;
            text-transform: uppercase;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 16px;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.95rem;
            transition: color 0.3s ease;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 14px;
            min-height: 48px;
            border: 1px solid var(--input-border);
            border-radius: 12px;
            font-size: 16px;
            transition: all 0.3s;
            background: var(--input-bg);
            color: var(--text-primary);
            appearance: none;
            -webkit-appearance: none;
        }

        .form-group textarea {
            min-height: 82px;
            resize: vertical;
        }

        .form-group select {
            background-image: linear-gradient(45deg, transparent 50%, var(--text-muted) 50%), linear-gradient(135deg, var(--text-muted) 50%, transparent 50%);
            background-position: calc(100% - 22px) calc(50% - 3px), calc(100% - 16px) calc(50% - 3px);
            background-size: 6px 6px, 6px 6px;
            background-repeat: no-repeat;
            padding-right: 36px;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-soft);
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: var(--text-muted);
        }

        .form-group input.error,
        .form-group select.error {
            border-color: var(--error-text);
        }

        .form-group .hint {
            font-size: 0.85rem;
            color: var(--text-secondary);
            margin-top: 5px;
        }

        /* Email domain hint */
        .email-hint {
            font-size: 0.8rem;
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }

        .email-hint.valid {
            color: var(--success-text);
        }

        .email-hint.invalid {
            color: var(--error-text);
        }

        .email-hint:not(.valid):not(.invalid) {
            color: var(--text-secondary);
        }

        .address-hint,
        .address-status {
            font-size: 0.82rem;
            line-height: 1.5;
            margin-top: 6px;
        }

        .address-hint {
            color: var(--text-secondary);
        }

        .address-status {
            color: var(--text-secondary);
            min-height: 20px;
        }

        .address-status.loading {
            color: var(--primary);
        }

        .address-status.error {
            color: var(--error-text);
        }

        .address-suggestions {
            margin-top: 10px;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            background: var(--card-bg);
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.12);
            overflow: hidden;
            max-height: 280px;
            overflow-y: auto;
        }

        .address-suggestions[hidden] {
            display: none;
        }

        .address-suggestion {
            width: 100%;
            border: none;
            background: transparent;
            text-align: left;
            padding: 12px 14px;
            display: grid;
            gap: 4px;
            cursor: pointer;
            transition: background 0.2s ease;
            border-bottom: 1px solid var(--border-color);
            font: inherit;
            color: inherit;
        }

        .address-suggestion:last-child {
            border-bottom: none;
        }

        .address-suggestion:hover,
        .address-suggestion.active {
            background: var(--primary-soft);
        }

        .address-suggestion-title {
            color: var(--text-primary);
            font-weight: 700;
            font-size: 0.94rem;
        }

        .address-suggestion-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            color: var(--text-secondary);
            font-size: 0.8rem;
        }

        .address-suggestion-type {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 3px 8px;
            border-radius: 999px;
            background: var(--bg-secondary);
            color: var(--primary-dark);
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            font-size: 0.7rem;
        }

        /* Password field visibility toggle */
        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 72px !important;
        }

        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            cursor: pointer;
            transition: color 0.3s;
            z-index: 2;
            border: none;
            background: transparent;
            font: inherit;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            padding: 0;
        }

        .password-toggle:hover {
            color: var(--primary);
        }

        /* ISMIS ID hint */
        .ismis-hint {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .password-requirements {
            background: var(--bg-secondary);
            padding: 16px;
            border-radius: 12px;
            margin: 16px 0;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .password-requirements h4 {
            color: var(--primary);
            margin-bottom: 10px;
            font-size: 0.95rem;
        }

        .requirement {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 5px;
            transition: color 0.3s ease;
        }

        .requirement.valid {
            color: var(--success-text);
        }

        .btn-register {
            width: 100%;
            padding: 15px 16px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin: 18px 0 18px;
            min-height: 48px;
        }

        .btn-register:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px var(--primary-glow);
        }

        .btn-register i {
            transition: transform 0.3s;
        }

        .btn-register:hover i {
            transform: translateX(5px);
        }

        .login-link {
            text-align: center;
            color: var(--text-secondary);
        }

        .login-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }

        .login-link a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .back-home {
            text-align: center;
            margin-top: 20px;
        }

        .back-home a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: color 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            min-height: 44px;
            padding: 0 6px;
        }

        .back-home a:hover {
            color: white;
        }

        /* Loading spinner */
        .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top: 3px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .btn-register.loading .btn-text {
            display: none;
        }

        .btn-register.loading .spinner {
            display: inline-block;
        }

        /* Loading indicator for courses */
        .loading-courses {
            text-align: center;
            padding: 10px;
            color: var(--text-secondary);
        }

        .loading-courses i {
            animation: spin 1s linear infinite;
            margin-right: 5px;
        }

        /* Select dropdown styling */
        select {
            background-color: var(--input-bg);
            color: var(--text-primary);
        }

        select option {
            background-color: var(--input-bg);
            color: var(--text-primary);
        }

        @media (max-width: 1024px) {
            .register-container {
                max-width: 760px;
            }

            .register-shell {
                grid-template-columns: 1fr;
            }

            .register-aside {
                order: 2;
            }

            .register-card {
                order: 1;
            }

            .register-card {
                border-radius: 20px;
            }

            .form-row {
                gap: 14px;
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }

            .brand p {
                font-size: 1rem;
            }

            .form-section {
                padding: 12px;
                border-radius: 14px;
            }

            .theme-toggle {
                transform: scale(0.95);
            }
        }

        @media (max-width: 480px) {
            body {
                padding-top: max(18px, env(safe-area-inset-top));
            }

            .before-register-dialog {
                padding: 20px 16px;
                border-radius: 16px;
            }

            .before-register-list li {
                font-size: 0.88rem;
            }

            .register-card {
                border-radius: 16px;
            }

            .form-title h2 {
                font-size: 1.25rem;
            }

            .btn-register {
                font-size: 1rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation: none !important;
                transition: none !important;
            }
        }

        @supports (-webkit-touch-callout: none) {
            input,
            select,
            textarea,
            button {
                font-size: 16px;
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
    <!-- Dark Mode Toggle -->
    <button type="button" class="theme-toggle" id="themeToggle">Switch Theme</button>

    <div class="before-register-modal" id="beforeRegisterModal" role="dialog" aria-modal="true" aria-labelledby="beforeRegisterTitle" aria-describedby="beforeRegisterDescription">
        <div class="before-register-dialog">
            <button type="button" class="before-register-close" id="closeBeforeRegister" aria-label="Close notification">Close</button>
            <span class="before-register-badge">Before You Register</span>
            <h2 class="before-register-title" id="beforeRegisterTitle">Before You Register</h2>
            <p class="before-register-description" id="beforeRegisterDescription">Use your official BISU credentials and complete details to avoid account approval issues.</p>
            <ul class="before-register-list">
                <li><span>Use an active BISU email ending with @bisu.edu.ph</span></li>
                <li><span>Prepare your 6-digit ISMIS ID before submitting</span></li>
                <li><span>Choose the correct college and course for accurate records</span></li>
                <li><span>Create a strong password to protect your account</span></li>
            </ul>
            <button type="button" class="before-register-action" id="continueRegistrationBtn">I Understand, Continue</button>
        </div>
    </div>

    <div class="register-container">
        <div class="brand">
            <div class="brand-badge">Secure Student Onboarding</div>
            <h1>BISU Online Clearance</h1>
            <p>Create your student account</p>
        </div>

        <div class="register-shell">
        <div class="register-card">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <div><?php echo $error; ?></div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <div><?php echo $success; ?></div>
                </div>
            <?php endif; ?>

            <div class="form-title">
                <h2>Student Registration Form</h2>
            </div>

            <form method="POST" action="" id="registerForm" onsubmit="return validateForm()">
                <!-- Personal Information -->
                <div class="form-section">
                <div class="section-label">Personal Information</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="fname" id="fname" value="<?php echo htmlspecialchars($form_data['fname']); ?>" 
                               placeholder="Enter first name" autocapitalize="words" autocomplete="given-name" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="lname" id="lname" value="<?php echo htmlspecialchars($form_data['lname']); ?>" 
                               placeholder="Enter last name" autocapitalize="words" autocomplete="family-name" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Complete Address *</label>
                    <textarea name="address" id="address" rows="2" placeholder="Start typing your barangay, city, municipality, province, or region" autocomplete="off" spellcheck="false" required><?php echo htmlspecialchars($form_data['address']); ?></textarea>
                    <div class="address-hint">Suggestions come from the PSGC API. You can still add street, purok, or house details manually.</div>
                    <div class="address-suggestions" id="addressSuggestions" hidden></div>
                    <div class="address-status" id="addressStatus" aria-live="polite">Location suggestions will appear as you type.</div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Age *</label>
                        <input type="number" name="age" id="age" value="<?php echo htmlspecialchars($form_data['age']); ?>" 
                               placeholder="Enter age" min="15" max="100" required>
                    </div>
                    <div class="form-group">
                        <label>Contact Number *</label>
                        <input type="text" name="contact" id="contact" value="<?php echo htmlspecialchars($form_data['contact']); ?>" 
                               placeholder="Enter contact number" required>
                    </div>
                </div>
                </div>

                <!-- Account Information -->
                <div class="form-section">
                <div class="section-label">Account Information</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Email Address *</label>
                        <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($form_data['email']); ?>" 
                               placeholder="Enter your @bisu.edu.ph email" required onkeyup="checkEmailDomain()">
                        <div class="email-hint" id="emailHint">Must be a valid BISU email (@bisu.edu.ph)</div>
                    </div>
                    <div class="form-group">
                        <label>ISMIS ID *</label>
                        <input type="text" name="ismis_id" id="ismis_id" value="<?php echo htmlspecialchars($form_data['ismis_id']); ?>" 
                               placeholder="Enter 6-digit ISMIS ID" maxlength="6" pattern="\d{6}" inputmode="numeric" required>
                        <div class="ismis-hint">Must be exactly 6 digits (e.g., 123456)</div>
                    </div>
                </div>
                </div>

                <!-- Academic Information -->
                <div class="form-section">
                <div class="section-label">Academic Information</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>College *</label>
                        <select name="college_id" id="college_id" required onchange="loadCourses()">
                            <option value="">-- Select College --</option>
                            <?php foreach ($colleges as $college): ?>
                                <option value="<?php echo $college['college_id']; ?>" <?php echo ($form_data['college_id'] == $college['college_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($college['college_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Course *</label>
                        <select name="course_id" id="course_id" required>
                            <option value="">-- Select College First --</option>
                        </select>
                    </div>
                </div>
                </div>

                <!-- Password -->
                <div class="form-section">
                <div class="section-label">Security Setup</div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Password *</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="password" placeholder="Enter password" required onkeyup="checkPasswordStrength()">
                            <button type="button" class="password-toggle" id="togglePassword" onclick="togglePassword('password', 'togglePassword')" aria-label="Show password">Show</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Confirm Password *</label>
                        <div class="password-wrapper">
                            <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm password" required onkeyup="checkPasswordMatch()">
                            <button type="button" class="password-toggle" id="toggleConfirmPassword" onclick="togglePassword('confirm_password', 'toggleConfirmPassword')" aria-label="Show password">Show</button>
                        </div>
                    </div>
                </div>
                </div>

                <!-- Password Requirements -->
                <div class="password-requirements">
                    <h4>Password Requirements:</h4>
                    <div class="requirement" id="req-length">At least 8 characters</div>
                    <div class="requirement" id="req-uppercase">At least one uppercase letter</div>
                    <div class="requirement" id="req-lowercase">At least one lowercase letter</div>
                    <div class="requirement" id="req-number">At least one number</div>
                    <div class="requirement" id="req-match">Passwords match</div>
                </div>

                <button type="submit" class="btn-register" id="registerBtn">
                    <span class="btn-text">Create Account</span>
                    <span class="spinner"></span>
                </button>

                <div class="login-link">
                    Already have an account? <a href="login.php">Login here</a>
                </div>
            </form>
        </div>
        </div>

        <div class="back-home">
            <a href="index.php">Back to Home</a>
        </div>
    </div>

    <script>
        const beforeRegisterModal = document.getElementById('beforeRegisterModal');
        const closeBeforeRegister = document.getElementById('closeBeforeRegister');
        const continueRegistrationBtn = document.getElementById('continueRegistrationBtn');
        const shouldShowBeforeRegisterModal = <?php echo $success ? 'false' : 'true'; ?>;
        let lastFocusedElement = null;

        function openBeforeRegisterModal() {
            if (!beforeRegisterModal) {
                return;
            }

            lastFocusedElement = document.activeElement;
            beforeRegisterModal.classList.add('show');
            document.body.classList.add('modal-open');

            setTimeout(() => {
                continueRegistrationBtn?.focus();
            }, 30);
        }

        function closeBeforeRegisterModal() {
            if (!beforeRegisterModal) {
                return;
            }

            beforeRegisterModal.classList.remove('show');
            document.body.classList.remove('modal-open');

            if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
                lastFocusedElement.focus();
            }
        }

        // Function to load courses based on selected college
        function loadCourses() {
            const collegeId = document.getElementById('college_id').value;
            const courseSelect = document.getElementById('course_id');
            
            if (!collegeId) {
                courseSelect.innerHTML = '<option value="">-- Select College First --</option>';
                return;
            }

            // Show loading state
            courseSelect.innerHTML = '<option value="">Loading courses...</option>';
            courseSelect.disabled = true;

            // Fetch courses via AJAX
            fetch(`register.php?get_courses=1&college_id=${collegeId}`)
                .then(response => response.json())
                .then(data => {
                    courseSelect.disabled = false;
                    
                    if (data.success && data.courses.length > 0) {
                        let options = '<option value="">-- Select Course --</option>';
                        data.courses.forEach(course => {
                            options += `<option value="${course.course_id}">${course.course_name} (${course.course_code})</option>`;
                        });
                        courseSelect.innerHTML = options;
                        
                        // Preselect if there was a previous value
                        <?php if (!empty($form_data['course_id'])): ?>
                            courseSelect.value = "<?php echo $form_data['course_id']; ?>";
                        <?php endif; ?>
                    } else {
                        courseSelect.innerHTML = '<option value="">-- No courses available --</option>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    courseSelect.disabled = false;
                    courseSelect.innerHTML = '<option value="">-- Error loading courses --</option>';
                });
        }

        // Load courses on page load if college is already selected
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!empty($form_data['college_id'])): ?>
                loadCourses();
            <?php endif; ?>

            if (shouldShowBeforeRegisterModal) {
                openBeforeRegisterModal();
            }
            
            // Check for saved theme preference
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode');
            }

            updateThemeToggleText();
        });

        closeBeforeRegister?.addEventListener('click', closeBeforeRegisterModal);
        continueRegistrationBtn?.addEventListener('click', closeBeforeRegisterModal);

        beforeRegisterModal?.addEventListener('click', function(event) {
            if (event.target === beforeRegisterModal) {
                closeBeforeRegisterModal();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && beforeRegisterModal?.classList.contains('show')) {
                closeBeforeRegisterModal();
            }
        });

        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        
        function updateThemeToggleText() {
            if (!themeToggle) {
                return;
            }

            themeToggle.textContent = document.body.classList.contains('dark-mode')
                ? 'Switch to Light Mode'
                : 'Switch to Dark Mode';
        }

        themeToggle.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            
            // Save theme preference
            if (document.body.classList.contains('dark-mode')) {
                localStorage.setItem('theme', 'dark');
            } else {
                localStorage.setItem('theme', 'light');
            }

            updateThemeToggleText();
        });

        // Toggle password visibility
        function togglePassword(inputId, toggleId) {
            const passwordInput = document.getElementById(inputId);
            const toggleButton = document.getElementById(toggleId);
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleButton.textContent = 'Hide';
                toggleButton.setAttribute('aria-label', 'Hide password');
            } else {
                passwordInput.type = 'password';
                toggleButton.textContent = 'Show';
                toggleButton.setAttribute('aria-label', 'Show password');
            }
        }

        function toProperCase(value) {
            return value
                .toLowerCase()
                .replace(/\s+/g, ' ')
                .trim()
                .replace(/(^|[\s\-'])[a-z]/g, match => match.toUpperCase());
        }

        function normalizePersonalNameInput(inputId) {
            const input = document.getElementById(inputId);
            if (!input) {
                return '';
            }

            input.value = toProperCase(input.value);
            return input.value;
        }

        const PSGC_API_BASE = 'https://psgc.gitlab.io/api';
        const ADDRESS_DEFAULT_STATUS = 'Location suggestions will appear as you type.';
        const ADDRESS_RESULT_LIMIT = 8;
        const LOCATION_TYPE_LABELS = {
            barangay: 'Barangay',
            city: 'City / Municipality',
            province: 'Province',
            region: 'Region'
        };
        const LOCATION_TYPE_WEIGHT = {
            barangay: 0,
            city: 1,
            province: 2,
            region: 3
        };
        const locationState = {
            basePromise: null,
            barangayPromise: null,
            baseLoaded: false,
            barangaysLoaded: false,
            baseItems: [],
            barangayItems: [],
            regionsByCode: new Map(),
            provincesByCode: new Map(),
            citiesByCode: new Map()
        };
        const addressInput = document.getElementById('address');
        const addressSuggestions = document.getElementById('addressSuggestions');
        const addressStatus = document.getElementById('addressStatus');
        let currentAddressMatches = [];
        let activeAddressSuggestionIndex = -1;
        let currentAddressSelectionContext = { preservePrefix: '' };
        let addressSearchDebounce = null;
        let addressSearchToken = 0;

        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, function(character) {
                return ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                })[character];
            });
        }

        function normalizeLocationSearchValue(value) {
            return String(value || '')
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-z0-9\s]/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        }

        function setAddressStatus(message, tone = '') {
            if (!addressStatus) {
                return;
            }

            addressStatus.textContent = message;
            addressStatus.classList.toggle('loading', tone === 'loading');
            addressStatus.classList.toggle('error', tone === 'error');
        }

        function hideAddressSuggestions() {
            if (!addressSuggestions) {
                return;
            }

            addressSuggestions.hidden = true;
            addressSuggestions.innerHTML = '';
            currentAddressMatches = [];
            activeAddressSuggestionIndex = -1;
        }

        function setActiveAddressSuggestion(index) {
            activeAddressSuggestionIndex = index;

            if (!addressSuggestions) {
                return;
            }

            addressSuggestions.querySelectorAll('.address-suggestion').forEach(function(button, buttonIndex) {
                const isActive = buttonIndex === index;
                button.classList.toggle('active', isActive);
                button.setAttribute('aria-selected', isActive ? 'true' : 'false');

                if (isActive) {
                    button.scrollIntoView({ block: 'nearest' });
                }
            });
        }

        function deriveAddressSearchContexts(rawValue) {
            const compactValue = String(rawValue || '').replace(/\s+/g, ' ').trim();
            const contexts = [];
            const fullQuery = normalizeLocationSearchValue(compactValue);

            if (fullQuery) {
                contexts.push({
                    query: fullQuery,
                    preservePrefix: ''
                });
            }

            const lastCommaIndex = compactValue.lastIndexOf(',');
            if (lastCommaIndex !== -1) {
                const prefix = compactValue.slice(0, lastCommaIndex).trim();
                const trailingFragment = compactValue.slice(lastCommaIndex + 1).trim();
                const trailingQuery = normalizeLocationSearchValue(trailingFragment);

                if (trailingQuery && trailingQuery !== fullQuery) {
                    contexts.push({
                        query: trailingQuery,
                        preservePrefix: prefix
                    });
                }
            }

            return contexts;
        }

        function fetchPsgcJson(endpoint) {
            return fetch(endpoint).then(function(response) {
                if (!response.ok) {
                    throw new Error(`Failed to load ${endpoint}`);
                }

                return response.json();
            });
        }

        function buildLocationSearchItem(type, primaryText, valueText, subtitleText, extraSearchTerms) {
            const extraTerms = Array.isArray(extraSearchTerms) ? extraSearchTerms : [];

            return {
                type: type,
                primaryText: primaryText,
                valueText: valueText,
                subtitleText: subtitleText,
                searchText: normalizeLocationSearchValue([primaryText, valueText, subtitleText].concat(extraTerms).join(' ')),
                primarySearchText: normalizeLocationSearchValue(primaryText),
                valueSearchText: normalizeLocationSearchValue(valueText)
            };
        }

        async function ensureBaseLocationData() {
            if (locationState.baseLoaded) {
                return;
            }

            if (!locationState.basePromise) {
                locationState.basePromise = Promise.all([
                    fetchPsgcJson(`${PSGC_API_BASE}/regions/`),
                    fetchPsgcJson(`${PSGC_API_BASE}/provinces/`),
                    fetchPsgcJson(`${PSGC_API_BASE}/cities-municipalities/`)
                ]).then(function(results) {
                    const [regions, provinces, cities] = results;

                    locationState.regionsByCode = new Map();
                    locationState.provincesByCode = new Map();
                    locationState.citiesByCode = new Map();

                    regions.forEach(function(region) {
                        locationState.regionsByCode.set(String(region.code), region);
                    });

                    provinces.forEach(function(province) {
                        locationState.provincesByCode.set(String(province.code), province);
                    });

                    cities.forEach(function(city) {
                        locationState.citiesByCode.set(String(city.code), city);
                    });

                    const regionItems = regions.map(function(region) {
                        return buildLocationSearchItem(
                            'region',
                            region.name,
                            region.name,
                            'Region',
                            [region.regionName, region.islandGroupCode]
                        );
                    });

                    const provinceItems = provinces.map(function(province) {
                        const region = locationState.regionsByCode.get(String(province.regionCode));
                        const subtitleParts = ['Province'];

                        if (region && region.name) {
                            subtitleParts.push(region.name);
                        }

                        return buildLocationSearchItem(
                            'province',
                            province.name,
                            province.name,
                            subtitleParts.join(' | '),
                            [region ? region.name : '', region ? region.regionName : '', province.islandGroupCode]
                        );
                    });

                    const cityItems = cities.map(function(city) {
                        const province = city.provinceCode ? locationState.provincesByCode.get(String(city.provinceCode)) : null;
                        const region = locationState.regionsByCode.get(String(city.regionCode));
                        const subtitleLocation = province && province.name ? province.name : (region ? region.name : '');
                        const subtitleParts = ['City / Municipality'];

                        if (subtitleLocation) {
                            subtitleParts.push(subtitleLocation);
                        }

                        return buildLocationSearchItem(
                            'city',
                            city.name,
                            [city.name, subtitleLocation].filter(Boolean).join(', '),
                            subtitleParts.join(' | '),
                            [city.oldName || '', province ? province.name : '', region ? region.name : '', region ? region.regionName : '', city.isCity ? 'city' : 'municipality']
                        );
                    });

                    locationState.baseItems = regionItems.concat(provinceItems, cityItems);
                    locationState.baseLoaded = true;
                }).catch(function(error) {
                    locationState.basePromise = null;
                    throw error;
                });
            }

            return locationState.basePromise;
        }

        async function ensureBarangayLocationData() {
            if (locationState.barangaysLoaded) {
                return;
            }

            await ensureBaseLocationData();

            if (!locationState.barangayPromise) {
                locationState.barangayPromise = fetchPsgcJson(`${PSGC_API_BASE}/barangays/`).then(function(barangays) {
                    locationState.barangayItems = barangays.map(function(barangay) {
                        const cityCode = barangay.cityCode || barangay.municipalityCode || barangay.subMunicipalityCode;
                        const city = cityCode ? locationState.citiesByCode.get(String(cityCode)) : null;
                        const province = barangay.provinceCode ? locationState.provincesByCode.get(String(barangay.provinceCode)) : null;
                        const region = locationState.regionsByCode.get(String(barangay.regionCode));
                        const locationParts = [];

                        if (city && city.name) {
                            locationParts.push(city.name);
                        }

                        if (province && province.name) {
                            locationParts.push(province.name);
                        } else if (region && region.name) {
                            locationParts.push(region.name);
                        }

                        return buildLocationSearchItem(
                            'barangay',
                            barangay.name,
                            [barangay.name].concat(locationParts).filter(Boolean).join(', '),
                            ['Barangay'].concat(locationParts).join(' | '),
                            [barangay.oldName || '', city ? city.name : '', province ? province.name : '', region ? region.name : '', region ? region.regionName : '']
                        );
                    });

                    locationState.barangaysLoaded = true;
                }).catch(function(error) {
                    locationState.barangayPromise = null;
                    throw error;
                });
            }

            return locationState.barangayPromise;
        }

        function getLocationMatchScore(item, query) {
            const matchIndex = item.searchText.indexOf(query);

            if (matchIndex === -1) {
                return null;
            }

            let baseScore = 30;

            if (item.primarySearchText.startsWith(query)) {
                baseScore = 0;
            } else if (item.valueSearchText.startsWith(query)) {
                baseScore = 6;
            } else if (item.searchText.includes(` ${query}`)) {
                baseScore = 12;
            }

            return baseScore + (LOCATION_TYPE_WEIGHT[item.type] || 9) + (item.valueText.length / 1000);
        }

        function collectLocationMatches(sourceItems, query, results) {
            sourceItems.forEach(function(item) {
                const score = getLocationMatchScore(item, query);

                if (score === null) {
                    return;
                }

                const candidate = { item: item, score: score };
                const insertAt = results.findIndex(function(existing) {
                    return candidate.score < existing.score;
                });

                if (insertAt === -1) {
                    if (results.length < ADDRESS_RESULT_LIMIT) {
                        results.push(candidate);
                    }

                    return;
                }

                results.splice(insertAt, 0, candidate);

                if (results.length > ADDRESS_RESULT_LIMIT) {
                    results.pop();
                }
            });
        }

        function searchLocationItems(query) {
            const matches = [];

            collectLocationMatches(locationState.baseItems, query, matches);

            if (locationState.barangaysLoaded) {
                collectLocationMatches(locationState.barangayItems, query, matches);
            }

            return matches.map(function(match) {
                return match.item;
            });
        }

        function findLocationMatchesForInput(rawValue) {
            const contexts = deriveAddressSearchContexts(rawValue);

            for (const context of contexts) {
                if (context.query.length < 2) {
                    continue;
                }

                const matches = searchLocationItems(context.query);

                if (matches.length > 0) {
                    return {
                        matches: matches,
                        context: context
                    };
                }
            }

            return {
                matches: [],
                context: contexts.find(function(context) {
                    return context.query.length >= 2;
                }) || { preservePrefix: '' }
            };
        }

        function renderAddressSuggestions(matches) {
            if (!addressSuggestions) {
                return;
            }

            currentAddressMatches = matches;
            activeAddressSuggestionIndex = -1;
            addressSuggestions.innerHTML = matches.map(function(match, index) {
                return `
                    <button type="button" class="address-suggestion" data-address-index="${index}" aria-selected="false">
                        <span class="address-suggestion-title">${escapeHtml(match.primaryText)}</span>
                        <span class="address-suggestion-meta">
                            <span class="address-suggestion-type">${escapeHtml(LOCATION_TYPE_LABELS[match.type] || 'Location')}</span>
                            <span>${escapeHtml(match.valueText)}</span>
                        </span>
                    </button>
                `;
            }).join('');
            addressSuggestions.hidden = false;
        }

        function applyAddressSuggestion(index) {
            const selectedMatch = currentAddressMatches[index];

            if (!selectedMatch || !addressInput) {
                return;
            }

            const prefix = currentAddressSelectionContext.preservePrefix
                ? `${currentAddressSelectionContext.preservePrefix}, `
                : '';

            addressInput.value = `${prefix}${selectedMatch.valueText}`;
            hideAddressSuggestions();
            setAddressStatus(`Selected ${selectedMatch.valueText}. Add street or purok details if needed.`);
            addressInput.focus();
            addressInput.setSelectionRange(addressInput.value.length, addressInput.value.length);
        }

        async function updateAddressSuggestions() {
            if (!addressInput) {
                return;
            }

            const rawValue = addressInput.value;
            const normalizedValue = normalizeLocationSearchValue(rawValue);
            const searchToken = ++addressSearchToken;

            if (normalizedValue.length < 2) {
                hideAddressSuggestions();
                setAddressStatus(ADDRESS_DEFAULT_STATUS);
                return;
            }

            setAddressStatus('Loading PSGC location suggestions...', 'loading');

            try {
                await ensureBaseLocationData();

                if (searchToken !== addressSearchToken) {
                    return;
                }

                let searchResult = findLocationMatchesForInput(rawValue);
                const needsBarangayLookup = deriveAddressSearchContexts(rawValue).some(function(context) {
                    return context.query.length >= 3;
                });

                if (!searchResult.matches.length && needsBarangayLookup && !locationState.barangaysLoaded) {
                    setAddressStatus('Loading barangay suggestions from PSGC...', 'loading');
                    await ensureBarangayLocationData();

                    if (searchToken !== addressSearchToken) {
                        return;
                    }

                    searchResult = findLocationMatchesForInput(rawValue);
                }

                currentAddressSelectionContext = searchResult.context || { preservePrefix: '' };

                if (!searchResult.matches.length) {
                    hideAddressSuggestions();
                    setAddressStatus('No PSGC match yet. Keep typing or enter the address manually.');
                    return;
                }

                renderAddressSuggestions(searchResult.matches);

                if (locationState.barangaysLoaded) {
                    setAddressStatus('Select a location suggestion to fill the address faster.');
                } else {
                    setAddressStatus('Select a location suggestion to fill the address faster.');
                }
            } catch (error) {
                console.error('PSGC lookup failed:', error);
                hideAddressSuggestions();
                setAddressStatus('PSGC suggestions are unavailable right now. You can still type your address manually.', 'error');
            }
        }

        function scheduleAddressSuggestionRefresh(delay = 180) {
            clearTimeout(addressSearchDebounce);
            addressSearchDebounce = setTimeout(updateAddressSuggestions, delay);
        }

        // Check email domain
        function checkEmailDomain() {
            const email = document.getElementById('email').value;
            const emailHint = document.getElementById('emailHint');
            if (email.includes('@')) {
                const domain = email.split('@')[1].toLowerCase();
                if (domain === 'bisu.edu.ph') {
                    emailHint.classList.add('valid');
                    emailHint.classList.remove('invalid');
                    emailHint.textContent = 'Valid BISU email address';
                } else {
                    emailHint.classList.add('invalid');
                    emailHint.classList.remove('valid');
                    emailHint.textContent = 'Must use the @bisu.edu.ph domain';
                }
            } else {
                emailHint.classList.remove('valid', 'invalid');
                emailHint.textContent = 'Must be a valid BISU email (@bisu.edu.ph)';
            }
        }

        // Check password strength
        function checkPasswordStrength() {
            const password = document.getElementById('password').value;
            // Length check
            const lengthReq = document.getElementById('req-length');
            if (password.length >= 8) {
                lengthReq.classList.add('valid');
                lengthReq.textContent = 'At least 8 characters - complete';
            } else {
                lengthReq.classList.remove('valid');
                lengthReq.textContent = 'At least 8 characters';
            }
            // Uppercase check
            const upperReq = document.getElementById('req-uppercase');
            if (/[A-Z]/.test(password)) {
                upperReq.classList.add('valid');
                upperReq.textContent = 'At least one uppercase letter - complete';
            } else {
                upperReq.classList.remove('valid');
                upperReq.textContent = 'At least one uppercase letter';
            }
            // Lowercase check
            const lowerReq = document.getElementById('req-lowercase');
            if (/[a-z]/.test(password)) {
                lowerReq.classList.add('valid');
                lowerReq.textContent = 'At least one lowercase letter - complete';
            } else {
                lowerReq.classList.remove('valid');
                lowerReq.textContent = 'At least one lowercase letter';
            }
            // Number check
            const numReq = document.getElementById('req-number');
            if (/[0-9]/.test(password)) {
                numReq.classList.add('valid');
                numReq.textContent = 'At least one number - complete';
            } else {
                numReq.classList.remove('valid');
                numReq.textContent = 'At least one number';
            }
            checkPasswordMatch();
        }

        // Check if passwords match
        function checkPasswordMatch() {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            const matchReq = document.getElementById('req-match');
            if (password && confirm && password === confirm) {
                matchReq.classList.add('valid');
                matchReq.textContent = 'Passwords match - complete';
            } else {
                matchReq.classList.remove('valid');
                matchReq.textContent = 'Passwords match';
            }
        }

        // Form validation
        function validateForm() {
            const fname = normalizePersonalNameInput('fname');
            const lname = normalizePersonalNameInput('lname');
            const email = document.getElementById('email').value.trim();
            const ismis = document.getElementById('ismis_id').value.trim();
            const contact = document.getElementById('contact').value.trim();
            const address = document.getElementById('address').value.trim();
            const age = document.getElementById('age').value;
            const college = document.getElementById('college_id').value;
            const course = document.getElementById('course_id').value;
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            
            // Basic validation
            if (!fname || !lname || !email || !ismis || !contact || !address || !age || !college || !course || !password || !confirm) {
                alert('Please fill in all required fields');
                return false;
            }
            
            // Email validation with BISU domain
            const emailRegex = /^[^\s@]+@bisu\.edu\.ph$/;
            if (!emailRegex.test(email)) {
                alert('Please enter a valid BISU email address (@bisu.edu.ph)');
                return false;
            }
            
            // ISMIS ID validation - exactly 6 digits
            const ismisRegex = /^\d{6}$/;
            if (!ismisRegex.test(ismis)) {
                alert('ISMIS ID must be exactly 6 digits');
                return false;
            }
            
            // Age validation
            if (age < 15 || age > 100) {
                alert('Please enter a valid age between 15 and 100');
                return false;
            }
            
            // Password validation
            if (password.length < 8) {
                alert('Password must be at least 8 characters long');
                return false;
            }
            
            if (!/[A-Z]/.test(password)) {
                alert('Password must contain at least one uppercase letter');
                return false;
            }
            
            if (!/[a-z]/.test(password)) {
                alert('Password must contain at least one lowercase letter');
                return false;
            }
            
            if (!/[0-9]/.test(password)) {
                alert('Password must contain at least one number');
                return false;
            }
            
            if (password !== confirm) {
                alert('Passwords do not match');
                return false;
            }
            
            // Show loading state
            document.getElementById('registerBtn').classList.add('loading');
            document.getElementById('registerBtn').disabled = true;
            
            return true;
        }

        // Auto-format ISMIS ID to only allow digits
        document.getElementById('ismis_id').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '').substring(0, 6);
        });

        // Normalize personal name fields when user leaves the input.
        document.getElementById('fname').addEventListener('blur', function() {
            normalizePersonalNameInput('fname');
        });

        document.getElementById('lname').addEventListener('blur', function() {
            normalizePersonalNameInput('lname');
        });

        addressInput?.addEventListener('focus', function() {
            ensureBaseLocationData().catch(function(error) {
                console.error('Unable to prepare PSGC lookup:', error);
            });

            if (addressInput.value.trim().length >= 2) {
                scheduleAddressSuggestionRefresh(0);
            }
        });

        addressInput?.addEventListener('input', function() {
            scheduleAddressSuggestionRefresh();
        });

        addressInput?.addEventListener('keydown', function(event) {
            if (!addressSuggestions || addressSuggestions.hidden || currentAddressMatches.length === 0) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                const nextIndex = activeAddressSuggestionIndex < currentAddressMatches.length - 1
                    ? activeAddressSuggestionIndex + 1
                    : 0;
                setActiveAddressSuggestion(nextIndex);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                const nextIndex = activeAddressSuggestionIndex > 0
                    ? activeAddressSuggestionIndex - 1
                    : currentAddressMatches.length - 1;
                setActiveAddressSuggestion(nextIndex);
            } else if (event.key === 'Enter' && activeAddressSuggestionIndex >= 0) {
                event.preventDefault();
                applyAddressSuggestion(activeAddressSuggestionIndex);
            } else if (event.key === 'Escape') {
                hideAddressSuggestions();
                setAddressStatus(ADDRESS_DEFAULT_STATUS);
            }
        });

        addressSuggestions?.addEventListener('mousedown', function(event) {
            const suggestionButton = event.target.closest('[data-address-index]');

            if (!suggestionButton) {
                return;
            }

            event.preventDefault();
            applyAddressSuggestion(Number(suggestionButton.getAttribute('data-address-index')));
        });

        document.addEventListener('click', function(event) {
            if (!addressSuggestions || !addressInput) {
                return;
            }

            if (!addressSuggestions.contains(event.target) && event.target !== addressInput) {
                hideAddressSuggestions();
            }
        });

        // Real-time email validation
        document.getElementById('email').addEventListener('input', checkEmailDomain);

        // Remove loading state when navigating back
        window.addEventListener('pageshow', function() {
            document.getElementById('registerBtn').classList.remove('loading');
            document.getElementById('registerBtn').disabled = false;
        });
    </script>
</body>
</html>

