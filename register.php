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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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

        /* Dark Mode Toggle Button */
        .theme-toggle {
            position: fixed;
            top: calc(12px + env(safe-area-inset-top));
            right: calc(12px + env(safe-area-inset-right));
            width: 60px;
            height: 30px;
            background: var(--bg-tertiary);
            border-radius: 30px;
            border: 2px solid var(--border-color);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 5px;
            z-index: 1000;
            transition: 0.3s;
            backdrop-filter: blur(10px);
        }

        .theme-toggle i {
            font-size: 14px;
            color: var(--text-secondary);
            z-index: 1;
            transition: 0.3s;
        }

        .theme-toggle .toggle-ball {
            position: absolute;
            width: 24px;
            height: 24px;
            background: var(--primary);
            border-radius: 50%;
            top: 1px;
            left: 1px;
            transition: transform 0.3s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }

        .dark-mode .theme-toggle .toggle-ball {
            transform: translateX(30px);
        }

        .theme-toggle:hover {
            border-color: var(--primary);
        }

        .theme-toggle:hover i {
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

        .aside-list i {
            margin-top: 2px;
            color: #dcfce7;
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

        .alert i {
            font-size: 1.2rem;
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

        .form-title i {
            font-size: 1.5rem;
            color: var(--primary);
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

        .section-label i {
            color: var(--primary);
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

        .form-group label i {
            color: var(--primary);
            margin-right: 5px;
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

        .email-hint i {
            font-size: 0.8rem;
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

        /* Password field with eye icon */
        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 45px !important;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            cursor: pointer;
            font-size: 1.2rem;
            transition: color 0.3s;
            z-index: 2;
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

        .ismis-hint i {
            color: var(--primary);
            font-size: 0.8rem;
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

        .requirement i {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        .requirement.valid {
            color: var(--success-text);
        }

        .requirement.valid i {
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
</head>
<body>
    <!-- Dark Mode Toggle -->
    <div class="theme-toggle" id="themeToggle">
        <i class="fas fa-sun"></i>
        <i class="fas fa-moon"></i>
        <div class="toggle-ball"></div>
    </div>

    <div class="register-container">
        <div class="brand">
            <div class="brand-badge"><i class="fas fa-shield-alt"></i> Secure Student Onboarding</div>
            <h1>BISU Online Clearance</h1>
            <p>Create your student account</p>
        </div>

        <div class="register-shell">
        <aside class="register-aside">
            <h3>Before You Register</h3>
            <p>Use your official BISU credentials and complete details to avoid account approval issues.</p>
            <ul class="aside-list">
                <li><i class="fas fa-circle-check"></i><span>Use an active BISU email ending with @bisu.edu.ph</span></li>
                <li><i class="fas fa-circle-check"></i><span>Prepare your 6-digit ISMIS ID before submitting</span></li>
                <li><i class="fas fa-circle-check"></i><span>Choose the correct college and course for accurate records</span></li>
                <li><i class="fas fa-circle-check"></i><span>Create a strong password to protect your account</span></li>
            </ul>
        </aside>

        <div class="register-card">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <div><?php echo $error; ?></div>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div><?php echo $success; ?></div>
                </div>
            <?php endif; ?>

            <div class="form-title">
                <i class="fas fa-user-plus"></i>
                <h2>Student Registration Form</h2>
            </div>

            <form method="POST" action="" id="registerForm" onsubmit="return validateForm()">
                <!-- Personal Information -->
                <div class="form-section">
                <div class="section-label"><i class="fas fa-user"></i> Personal Information</div>
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> First Name *</label>
                        <input type="text" name="fname" id="fname" value="<?php echo htmlspecialchars($form_data['fname']); ?>" 
                               placeholder="Enter first name" autocapitalize="words" autocomplete="given-name" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Last Name *</label>
                        <input type="text" name="lname" id="lname" value="<?php echo htmlspecialchars($form_data['lname']); ?>" 
                               placeholder="Enter last name" autocapitalize="words" autocomplete="family-name" required>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-map-marker-alt"></i> Complete Address *</label>
                    <textarea name="address" id="address" rows="2" placeholder="Enter your complete address" required><?php echo htmlspecialchars($form_data['address']); ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-calendar-alt"></i> Age *</label>
                        <input type="number" name="age" id="age" value="<?php echo htmlspecialchars($form_data['age']); ?>" 
                               placeholder="Enter age" min="15" max="100" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Contact Number *</label>
                        <input type="text" name="contact" id="contact" value="<?php echo htmlspecialchars($form_data['contact']); ?>" 
                               placeholder="Enter contact number" required>
                    </div>
                </div>
                </div>

                <!-- Account Information -->
                <div class="form-section">
                <div class="section-label"><i class="fas fa-id-card"></i> Account Information</div>
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email Address *</label>
                        <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($form_data['email']); ?>" 
                               placeholder="Enter your @bisu.edu.ph email" required onkeyup="checkEmailDomain()">
                        <div class="email-hint" id="emailHint">
                            <i class="fas fa-info-circle"></i>
                            <span>Must be a valid BISU email (@bisu.edu.ph)</span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-id-card"></i> ISMIS ID *</label>
                        <input type="text" name="ismis_id" id="ismis_id" value="<?php echo htmlspecialchars($form_data['ismis_id']); ?>" 
                               placeholder="Enter 6-digit ISMIS ID" maxlength="6" pattern="\d{6}" inputmode="numeric" required>
                        <div class="ismis-hint">
                            <i class="fas fa-info-circle"></i>
                            <span>Must be exactly 6 digits (e.g., 123456)</span>
                        </div>
                    </div>
                </div>
                </div>

                <!-- Academic Information -->
                <div class="form-section">
                <div class="section-label"><i class="fas fa-graduation-cap"></i> Academic Information</div>
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-university"></i> College *</label>
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
                        <label><i class="fas fa-book"></i> Course *</label>
                        <select name="course_id" id="course_id" required>
                            <option value="">-- Select College First --</option>
                        </select>
                    </div>
                </div>
                </div>

                <!-- Password -->
                <div class="form-section">
                <div class="section-label"><i class="fas fa-lock"></i> Security Setup</div>
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Password *</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" id="password" placeholder="Enter password" required onkeyup="checkPasswordStrength()">
                            <i class="fas fa-eye password-toggle" id="togglePassword" onclick="togglePassword('password', 'togglePassword')"></i>
                        </div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Confirm Password *</label>
                        <div class="password-wrapper">
                            <input type="password" name="confirm_password" id="confirm_password" placeholder="Confirm password" required onkeyup="checkPasswordMatch()">
                            <i class="fas fa-eye password-toggle" id="toggleConfirmPassword" onclick="togglePassword('confirm_password', 'toggleConfirmPassword')"></i>
                        </div>
                    </div>
                </div>
                </div>

                <!-- Password Requirements -->
                <div class="password-requirements">
                    <h4>Password Requirements:</h4>
                    <div class="requirement" id="req-length">
                        <i class="fas fa-circle"></i> At least 8 characters
                    </div>
                    <div class="requirement" id="req-uppercase">
                        <i class="fas fa-circle"></i> At least one uppercase letter
                    </div>
                    <div class="requirement" id="req-lowercase">
                        <i class="fas fa-circle"></i> At least one lowercase letter
                    </div>
                    <div class="requirement" id="req-number">
                        <i class="fas fa-circle"></i> At least one number
                    </div>
                    <div class="requirement" id="req-match">
                        <i class="fas fa-circle"></i> Passwords match
                    </div>
                </div>

                <button type="submit" class="btn-register" id="registerBtn">
                    <span class="btn-text">Create Account <i class="fas fa-arrow-right"></i></span>
                    <span class="spinner"></span>
                </button>

                <div class="login-link">
                    Already have an account? <a href="login.php">Login here</a>
                </div>
            </form>
        </div>
        </div>

        <div class="back-home">
            <a href="index.php"><i class="fas fa-arrow-left"></i> Back to Home</a>
        </div>
    </div>

    <script>
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
            
            // Check for saved theme preference
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                document.body.classList.add('dark-mode');
            }
        });

        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        
        themeToggle.addEventListener('click', () => {
            document.body.classList.toggle('dark-mode');
            
            // Save theme preference
            if (document.body.classList.contains('dark-mode')) {
                localStorage.setItem('theme', 'dark');
            } else {
                localStorage.setItem('theme', 'light');
            }
        });

        // Toggle password visibility
        function togglePassword(inputId, toggleId) {
            const passwordInput = document.getElementById(inputId);
            const toggleIcon = document.getElementById(toggleId);
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
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

        // Check email domain
        function checkEmailDomain() {
            const email = document.getElementById('email').value;
            const emailHint = document.getElementById('emailHint');
            
            if (email.includes('@')) {
                const domain = email.split('@')[1].toLowerCase();
                
                if (domain === 'bisu.edu.ph') {
                    emailHint.classList.add('valid');
                    emailHint.classList.remove('invalid');
                    emailHint.innerHTML = '<i class="fas fa-check-circle"></i> <span>Valid BISU email ✓</span>';
                } else {
                    emailHint.classList.add('invalid');
                    emailHint.classList.remove('valid');
                    emailHint.innerHTML = '<i class="fas fa-exclamation-circle"></i> <span>Must be @bisu.edu.ph domain</span>';
                }
            } else {
                emailHint.classList.remove('valid', 'invalid');
                emailHint.innerHTML = '<i class="fas fa-info-circle"></i> <span>Must be a valid BISU email (@bisu.edu.ph)</span>';
            }
        }

        // Check password strength
        function checkPasswordStrength() {
            const password = document.getElementById('password').value;
            
            // Length check
            const lengthReq = document.getElementById('req-length');
            if (password.length >= 8) {
                lengthReq.classList.add('valid');
                lengthReq.innerHTML = '<i class="fas fa-check-circle"></i> At least 8 characters ✓';
            } else {
                lengthReq.classList.remove('valid');
                lengthReq.innerHTML = '<i class="fas fa-circle"></i> At least 8 characters';
            }
            
            // Uppercase check
            const upperReq = document.getElementById('req-uppercase');
            if (/[A-Z]/.test(password)) {
                upperReq.classList.add('valid');
                upperReq.innerHTML = '<i class="fas fa-check-circle"></i> At least one uppercase letter ✓';
            } else {
                upperReq.classList.remove('valid');
                upperReq.innerHTML = '<i class="fas fa-circle"></i> At least one uppercase letter';
            }
            
            // Lowercase check
            const lowerReq = document.getElementById('req-lowercase');
            if (/[a-z]/.test(password)) {
                lowerReq.classList.add('valid');
                lowerReq.innerHTML = '<i class="fas fa-check-circle"></i> At least one lowercase letter ✓';
            } else {
                lowerReq.classList.remove('valid');
                lowerReq.innerHTML = '<i class="fas fa-circle"></i> At least one lowercase letter';
            }
            
            // Number check
            const numReq = document.getElementById('req-number');
            if (/[0-9]/.test(password)) {
                numReq.classList.add('valid');
                numReq.innerHTML = '<i class="fas fa-check-circle"></i> At least one number ✓';
            } else {
                numReq.classList.remove('valid');
                numReq.innerHTML = '<i class="fas fa-circle"></i> At least one number';
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
                matchReq.innerHTML = '<i class="fas fa-check-circle"></i> Passwords match ✓';
            } else {
                matchReq.classList.remove('valid');
                matchReq.innerHTML = '<i class="fas fa-circle"></i> Passwords match';
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