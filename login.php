<?php
// login.php - Enhanced Login Page for BISU Student Online Clearance System
// With support for organization-specific dashboards (Clinic, Town, College, SSG)

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database configuration
require_once __DIR__ . '/db.php';

/**
 * Function to handle organization login from student_organizations table
 * with dashboard type support - FIXED to ensure dashboard_file is retrieved
 */
function loginOrganization($email, $password)
{
    $db = Database::getInstance();

    // First, get organization directly without using stored procedure
    // This ensures we get all fields including dashboard_type
    $db->query("SELECT so.*, 
                       COALESCE(so.dashboard_type, so.org_type) as effective_dashboard_type,
                       odv.view_file as dashboard_file
                FROM student_organizations so
                LEFT JOIN organization_dashboard_views odv ON COALESCE(so.dashboard_type, so.org_type) = odv.dashboard_type
                WHERE so.org_email = :email AND so.status = 'active'");
    $db->bind(':email', $email);
    $org = $db->single();

    if ($org && password_verify($password, $org['org_password'])) {
        // Update last login
        $db->query("UPDATE student_organizations SET last_login = NOW(), login_count = login_count + 1 WHERE org_id = :org_id");
        $db->bind(':org_id', $org['org_id']);
        $db->execute();
        
        return $org;
    }
    return false;
}

/**
 * Get user details with role and office information
 */
function getUserDetails($userId)
{
    $db = Database::getInstance();

    $sql = "SELECT u.*, 
                   ur.user_role_name,
                   ur.user_role_id,
                   o.office_name,
                   o.office_id,
                   c.college_name,
                   c.college_id,
                   cr.course_name,
                   cr.course_id,
                   sao.can_create_accounts,
                   sao.can_manage_organizations
            FROM users u
            LEFT JOIN user_role ur ON u.user_role_id = ur.user_role_id
            LEFT JOIN offices o ON u.office_id = o.office_id
            LEFT JOIN college c ON u.college_id = c.college_id
            LEFT JOIN course cr ON u.course_id = cr.course_id
            LEFT JOIN sub_admin_offices sao ON u.users_id = sao.users_id
            WHERE u.users_id = :user_id AND u.is_active = 1";

    $db->query($sql);
    $db->bind(':user_id', $userId);
    return $db->single();
}

/**
 * Log user activity
 */
function logActivity($userId, $action, $description)
{
    $db = Database::getInstance();

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $sql = "INSERT INTO activity_logs (users_id, action, description, ip_address, created_at) 
            VALUES (:users_id, :action, :description, :ip_address, NOW())";

    $db->query($sql);
    $db->bind(':users_id', $userId);
    $db->bind(':action', $action);
    $db->bind(':description', $description);
    $db->bind(':ip_address', $ipAddress);

    return $db->execute();
}

/**
 * Get organization dashboard URL based on dashboard type - FIXED with better mapping
 */
function getOrganizationDashboardUrl($org)
{
    // Define dashboard file mapping
    $dashboardFiles = [
        'clinic' => 'organization/clinic_dashboard.php',
        'town' => 'organization/town_dashboard.php',
        'college' => 'organization/college_dashboard.php',
        'ssg' => 'organization/ssg_dashboard.php'
    ];

    // Get the effective dashboard type
    $dashboardType = $org['effective_dashboard_type'] ?? $org['dashboard_type'] ?? $org['org_type'] ?? 'organization';
    
    // Log for debugging (remove in production)
    error_log("Organization login - Type: {$dashboardType}, ID: {$org['org_id']}, Name: {$org['org_name']}");

    // Priority 1: If we have dashboard_file from the join, use it
    if (!empty($org['dashboard_file'])) {
        return $org['dashboard_file'];
    }
    
    // Priority 2: Use the mapping based on dashboard type
    if (isset($dashboardFiles[$dashboardType])) {
        return $dashboardFiles[$dashboardType];
    }
    
    // Priority 3: Try using org_type as fallback
    if (isset($dashboardFiles[$org['org_type']])) {
        return $dashboardFiles[$org['org_type']];
    }
    
    // Priority 4: Default fallback
    return 'organization/dashboard.php';
}

/**
 * Get appropriate dashboard URL based on user role and details
 */
function getDashboardUrl($user)
{
    $role = $user['user_role_name'] ?? '';

    switch ($role) {
        case 'super_admin':
            return 'admin/dashboard.php';

        case 'sub_admin':
            // Check which office the sub-admin manages
            if (isset($user['office_name'])) {
                $office = strtolower($user['office_name']);

                if (strpos($office, 'sas') !== false || strpos($office, 'director') !== false) {
                    return 'sub_admin/sas_dashboard.php';
                } elseif (strpos($office, 'libra') !== false) {
                    return 'sub_admin/librarian_dashboard.php';
                } elseif (strpos($office, 'dean') !== false) {
                    return 'sub_admin/dean_dashboard.php';
                } elseif (strpos($office, 'cash') !== false) {
                    return 'sub_admin/cashier_dashboard.php';
                } elseif (strpos($office, 'mis') !== false) {
                    return 'sub_admin/mis_dashboard.php';
                } elseif (strpos($office, 'registrar') !== false) {
                    return 'sub_admin/registrar_dashboard.php';
                } else {
                    return 'sub_admin/dashboard.php';
                }
            }
            return 'sub_admin/dashboard.php';

        case 'office_staff':
            return 'staff/dashboard.php';

        case 'student':
            return 'student/dashboard.php';

        case 'organization':
            return 'organization/dashboard.php';

        default:
            return 'index.php';
    }
}

// Redirect if already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $role = $_SESSION['user_role'] ?? '';

    if ($role === 'super_admin') {
        header("Location: admin/dashboard.php");
    } elseif ($role === 'sub_admin') {
        $office = $_SESSION['office_name'] ?? '';
        $officeLower = strtolower($office);

        if (strpos($officeLower, 'sas') !== false || strpos($officeLower, 'director') !== false) {
            header("Location: sub_admin/sas_dashboard.php");
        } elseif (strpos($officeLower, 'libra') !== false) {
            header("Location: sub_admin/librarian_dashboard.php");
        } elseif (strpos($officeLower, 'dean') !== false) {
            header("Location: sub_admin/dean_dashboard.php");
        } elseif (strpos($officeLower, 'cash') !== false) {
            header("Location: sub_admin/cashier_dashboard.php");
        } elseif (strpos($officeLower, 'mis') !== false) {
            header("Location: sub_admin/mis_dashboard.php");
        } elseif (strpos($officeLower, 'registrar') !== false) {
            header("Location: sub_admin/registrar_dashboard.php");
        } else {
            header("Location: sub_admin/dashboard.php");
        }
    } elseif ($role === 'office_staff') {
        header("Location: staff/dashboard.php");
    } elseif ($role === 'student') {
        header("Location: student/dashboard.php");
    } elseif ($role === 'organization') {
        // For organizations, use the stored dashboard file
        $dashboardFile = $_SESSION['dashboard_file'] ?? 'organization/dashboard.php';
        header("Location: " . $dashboardFile);
    } else {
        header("Location: index.php");
    }
    exit();
}

// Initialize variables
$error = '';
$success = '';
$remembered_email = $_COOKIE['remembered_email'] ?? '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (empty($username) || empty($password)) {
        $error = "Please enter both username/email and password.";
    } else {
        // Try user login first (students, staff, admins)
        $user = loginUser($username, $password);

        if ($user) {
            // Get full user details with role and office information
            $userDetails = getUserDetails($user['users_id']);

            if ($userDetails) {
                // Set session variables for user
                $_SESSION['user_id'] = $userDetails['users_id'];
                $_SESSION['user_fname'] = $userDetails['fname'];
                $_SESSION['user_lname'] = $userDetails['lname'];
                $_SESSION['user_name'] = $userDetails['fname'] . ' ' . $userDetails['lname'];
                $_SESSION['user_email'] = $userDetails['emails'];
                $_SESSION['user_role'] = $userDetails['user_role_name'];
                $_SESSION['user_role_id'] = $userDetails['user_role_id'];
                $_SESSION['office_id'] = $userDetails['office_id'] ?? null;
                $_SESSION['office_name'] = $userDetails['office_name'] ?? null;
                $_SESSION['college_id'] = $userDetails['college_id'] ?? null;
                $_SESSION['college_name'] = $userDetails['college_name'] ?? null;
                $_SESSION['course_id'] = $userDetails['course_id'] ?? null;
                $_SESSION['course_name'] = $userDetails['course_name'] ?? null;
                $_SESSION['ismis_id'] = $userDetails['ismis_id'] ?? null;
                $_SESSION['can_create_accounts'] = $userDetails['can_create_accounts'] ?? false;
                $_SESSION['can_manage_organizations'] = $userDetails['can_manage_organizations'] ?? false;
                $_SESSION['user_type'] = 'user';
                $_SESSION['logged_in'] = true;
                $_SESSION['login_time'] = time();

                // Set remember me cookie
                if ($remember) {
                    setcookie('remembered_email', $username, time() + (30 * 24 * 60 * 60), '/', '', false, true);
                } else {
                    setcookie('remembered_email', '', time() - 3600, '/');
                }

                // Regenerate session ID for security
                session_regenerate_id(true);

                // Log successful login to activity_logs
                logActivity(
                    $userDetails['users_id'],
                    'LOGIN',
                    "User logged in successfully: " . $userDetails['fname'] . ' ' . $userDetails['lname']
                );

                // Redirect based on role
                $role = $userDetails['user_role_name'];

                if ($role === 'super_admin') {
                    header("Location: admin/dashboard.php");
                } elseif ($role === 'sub_admin') {
                    $office = $userDetails['office_name'] ?? '';
                    $officeLower = strtolower($office);

                    if (strpos($officeLower, 'sas') !== false || strpos($officeLower, 'director') !== false) {
                        header("Location: sub_admin/sas_dashboard.php");
                    } elseif (strpos($officeLower, 'libra') !== false) {
                        header("Location: sub_admin/librarian_dashboard.php");
                    } elseif (strpos($officeLower, 'dean') !== false) {
                        header("Location: sub_admin/dean_dashboard.php");
                    } elseif (strpos($officeLower, 'cash') !== false) {
                        header("Location: sub_admin/cashier_dashboard.php");
                    } elseif (strpos($officeLower, 'mis') !== false) {
                        header("Location: sub_admin/mis_dashboard.php");
                    } elseif (strpos($officeLower, 'registrar') !== false) {
                        header("Location: sub_admin/registrar_dashboard.php");
                    } else {
                        header("Location: sub_admin/dashboard.php");
                    }
                } elseif ($role === 'office_staff') {
                    header("Location: staff/dashboard.php");
                } elseif ($role === 'student') {
                    header("Location: student/dashboard.php");
                } else {
                    header("Location: index.php");
                }
                exit();

            } else {
                $error = "User account not found or inactive.";
            }

        } else {
            // Try organization login from student_organizations table
            $org = loginOrganization($username, $password);

            if ($org && !empty($org['org_id'])) {
                // Get dashboard URL for this organization
                $dashboardUrl = getOrganizationDashboardUrl($org);

                // Set session variables for organization
                $_SESSION['user_id'] = $org['org_id'];
                $_SESSION['user_name'] = $org['org_name'];
                $_SESSION['user_email'] = $org['org_email'];
                $_SESSION['user_role'] = 'organization';
                $_SESSION['org_type'] = $org['org_type'];
                $_SESSION['dashboard_type'] = $org['effective_dashboard_type'] ?? $org['dashboard_type'] ?? $org['org_type'];
                $_SESSION['dashboard_file'] = $dashboardUrl;
                $_SESSION['office_id'] = $org['office_id'] ?? null;
                $_SESSION['org_status'] = $org['status'] ?? 'active';
                $_SESSION['user_type'] = 'organization';
                $_SESSION['logged_in'] = true;
                $_SESSION['login_time'] = time();

                // Set remember me cookie
                if ($remember) {
                    setcookie('remembered_email', $username, time() + (30 * 24 * 60 * 60), '/', '', false, true);
                }

                // Regenerate session ID for security
                session_regenerate_id(true);

                // Log organization login (optional - organizations don't have activity_logs table)
                error_log("Organization login successful: {$org['org_name']} ({$org['org_email']}) - Dashboard: {$dashboardUrl}");

                // Redirect to organization-specific dashboard
                header("Location: " . $dashboardUrl);
                exit();

            } else {
                $error = "Invalid email/username or password. Please try again.";
                error_log("Failed organization login attempt for username: $username from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BISU Online Clearance • Login</title>
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
            --success: #10b981;
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-tertiary: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border-color: #e2e8f0;
            --card-bg: rgba(255, 255, 255, 0.9);
            --input-bg: #ffffff;
            --input-border: #e2e8f0;
            --shadow-color: rgba(0, 0, 0, 0.08);
            --demo-bg: rgba(255, 255, 255, 0.8);
            --org-clinic: #0b7d5a;
            --org-town: #b45f2e;
            --org-college: #3b82f6;
            --org-ssg: #8b5cf6;
        }

        /* Dark Mode Colors */
        .dark-mode {
            --primary: #8b6fd8;
            --primary-dark: #6b4bb8;
            --primary-light: #a58bd1;
            --primary-soft: rgba(139, 111, 216, 0.15);
            --primary-glow: rgba(139, 111, 216, 0.4);
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
            --shadow-color: rgba(0, 0, 0, 0.3);
            --demo-bg: rgba(22, 33, 62, 0.9);
            --org-clinic: #4fd1b5;
            --org-town: #f6ad55;
            --org-college: #90cdf4;
            --org-ssg: #d6bcfa;
        }

        body {
            background:
                radial-gradient(circle at 12% 20%, rgba(65, 40, 134, 0.12) 0%, transparent 38%),
                radial-gradient(circle at 90% 10%, rgba(56, 189, 248, 0.10) 0%, transparent 34%),
                linear-gradient(145deg, var(--bg-secondary) 0%, var(--bg-tertiary) 100%);
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: max(16px, env(safe-area-inset-top)) max(16px, env(safe-area-inset-right)) max(16px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
            position: relative;
            overflow-x: hidden;
            transition: background 0.3s ease;
            -webkit-text-size-adjust: 100%;
        }

        /* Dark Mode Toggle Button */
        .theme-toggle {
            position: fixed;
            top: calc(10px + env(safe-area-inset-top));
            right: calc(10px + env(safe-area-inset-right));
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

        /* Animated Background Elements */
        .bg-shape {
            position: fixed;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            z-index: -1;
            overflow: hidden;
        }

        .shape {
            position: absolute;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-soft) 0%, rgba(107, 75, 184, 0.1) 100%);
            animation: float 20s infinite;
            transition: background 0.3s ease;
        }

        .shape-1 {
            width: 500px;
            height: 500px;
            top: -250px;
            right: -100px;
            animation-delay: 0s;
        }

        .shape-2 {
            width: 400px;
            height: 400px;
            bottom: -200px;
            left: -100px;
            animation-delay: -5s;
        }

        .shape-3 {
            width: 300px;
            height: 300px;
            bottom: 20%;
            right: 10%;
            animation-delay: -10s;
        }

        @keyframes float {

            0%,
            100% {
                transform: translate(0, 0) rotate(0deg);
            }

            33% {
                transform: translate(30px, -30px) rotate(120deg);
            }

            66% {
                transform: translate(-20px, 20px) rotate(240deg);
            }
        }

        /* Login Wrapper */
        .login-wrapper {
            width: 100%;
            max-width: 520px;
            position: relative;
            z-index: 1;
            animation: scaleIn 0.8s ease;
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.9);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        /* Brand Header */
        .brand {
            text-align: center;
            margin-bottom: 24px;
            animation: slideDown 0.8s ease;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            padding: 7px 12px;
            border-radius: 999px;
            color: var(--primary-dark);
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid rgba(65, 40, 134, 0.12);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .brand-badge i {
            color: var(--primary);
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .brand-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            box-shadow: 0 10px 30px var(--primary-glow);
            position: relative;
            overflow: hidden;
            transition: background 0.3s ease;
        }

        .brand-icon i {
            font-size: 40px;
            color: white;
        }

        .brand-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 24px;
            display: block;
        }

        .brand h1 {
            font-family: var(--font-display);
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 8px;
            transition: color 0.3s ease;
        }

        .brand p {
            color: var(--text-secondary);
            font-size: 15px;
            transition: color 0.3s ease;
        }

        /* Login Container */
        .login-container {
            background: var(--card-bg);
            backdrop-filter: blur(14px);
            border-radius: 24px;
            box-shadow: 0 24px 45px var(--shadow-color);
            padding: 40px;
            border: 1px solid var(--border-color);
            animation: slideUp 0.8s ease 0.2s both;
            transition: all 0.3s ease;
        }

        .login-header {
            margin-bottom: 20px;
        }

        .login-header h2 {
            font-family: var(--font-display);
            color: var(--text-primary);
            font-size: 24px;
            margin-bottom: 4px;
        }

        .login-header p {
            color: var(--text-secondary);
            font-size: 14px;
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

        /* Alert Styles */
        .alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
            transition: all 0.3s ease;
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

        .alert i {
            font-size: 18px;
        }

        .alert-error {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }

        .dark-mode .alert-error {
            background: rgba(220, 38, 38, 0.1);
            color: #f87171;
            border-color: rgba(220, 38, 38, 0.2);
        }

        .alert-success {
            background: #f0fdf4;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .dark-mode .alert-success {
            background: rgba(22, 163, 74, 0.1);
            color: #4ade80;
            border-color: rgba(22, 163, 74, 0.2);
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 22px;
        }

        .form-group label {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 8px;
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 14px;
            transition: color 0.3s ease;
        }

        .form-group label i {
            color: var(--primary);
            font-size: 14px;
            transition: color 0.3s ease;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 18px;
            transition: all 0.3s;
            z-index: 1;
        }

        .input-wrapper input {
            width: 100%;
            padding: 16px 50px 16px 48px;
            border: 1px solid var(--input-border);
            border-radius: 14px;
            font-size: 16px;
            transition: all 0.3s;
            background: var(--input-bg);
            color: var(--text-primary);
            position: relative;
            z-index: 0;
            min-height: 48px;
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-soft);
            transform: translateY(-1px);
        }

        .input-wrapper input::placeholder {
            color: var(--text-muted);
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            cursor: pointer;
            font-size: 18px;
            transition: all 0.3s;
            z-index: 2;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .password-toggle:hover {
            color: var(--primary);
            transform: translateY(-50%) scale(1.1);
        }

        /* Form Options */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            font-size: 14px;
            cursor: pointer;
            transition: color 0.3s ease;
            min-height: 44px;
        }

        .remember-me input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
        }

        .forgot-link {
            color: var(--primary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.3s ease;
            min-height: 44px;
            display: inline-flex;
            align-items: center;
        }

        .forgot-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        /* Login Button */
        .login-btn {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            color: white;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            min-height: 48px;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px var(--primary-glow);
        }

        .login-btn i {
            font-size: 18px;
        }

        .login-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        /* Loading Spinner */
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
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .login-btn.loading .btn-text {
            display: none;
        }

        .login-btn.loading .spinner {
            display: inline-block;
        }

        /* Links */
        .register-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .trust-note {
            margin-top: 12px;
            color: var(--text-muted);
            font-size: 12px;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .trust-note i {
            color: var(--success);
        }

        .register-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .register-link a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .back-home {
            text-align: center;
            margin-top: 20px;
        }

        .back-home a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 44px;
        }

        .back-home a:hover {
            color: var(--primary);
        }

        @media (prefers-reduced-motion: reduce) {
            * {
                animation: none !important;
                transition: none !important;
            }
        }

        @media (max-width: 1024px) {
            .login-wrapper {
                max-width: 600px;
            }

            .login-container {
                padding: 34px 30px;
            }

        }

        /* Responsive */
        @media (max-width: 480px) {
            .brand h1 {
                font-size: 24px;
            }

            .brand p {
                font-size: 14px;
            }

            .login-container {
                padding: 30px 20px;
                border-radius: 18px;
            }

            .form-options {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .theme-toggle {
                transform: scale(0.95);
            }
        }

        @supports (-webkit-touch-callout: none) {
            input,
            button,
            textarea,
            select {
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

    <!-- Animated Background -->
    <div class="bg-shape">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
    </div>

    <div class="login-wrapper">
        <!-- Brand Header -->
        <div class="brand">
            <div class="brand-badge"><i class="fas fa-shield-alt"></i> Secure Access Portal</div>
            <div class="brand-icon">
                <img src="assets/img/logo.png" alt="BISU Logo">
            </div>
            <h1>BISU Online Clearance</h1>
            <p>Bohol Island State University</p>
        </div>

        <!-- Login Container -->
        <div class="login-container">
            <div class="login-header">
                <h2>Sign in to continue</h2>
                <p>Use your BISU account credentials to access your dashboard.</p>
            </div>

            <!-- Alerts -->
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form method="POST" action="" id="loginForm">
                <div class="form-group">
                    <label for="username">
                        <i class="fas fa-envelope"></i>
                        Email or ISMIS ID
                    </label>
                    <div class="input-wrapper">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" id="username" name="username" 
                               placeholder="Enter your email or ISMIS ID"
                               value="<?php echo htmlspecialchars($_POST['username'] ?? $remembered_email); ?>"
                               autocomplete="username" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock"></i>
                        Password
                    </label>
                    <div class="input-wrapper">
                        <i class="fas fa-key input-icon"></i>
                        <input type="password" id="password" name="password" placeholder="Enter your password"
                               autocomplete="current-password" required>
                        <i class="fas fa-eye password-toggle" id="togglePassword" onclick="togglePassword()"></i>
                    </div>
                </div>

                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember" value="1"
                               <?php echo isset($_COOKIE['remembered_email']) ? 'checked' : ''; ?>>
                        Remember me
                    </label>
                    <a href="forgot-password.php" class="forgot-link">
                        <i class="fas fa-question-circle"></i> Forgot Password?
                    </a>
                </div>

                <button type="submit" class="login-btn" id="loginBtn">
                    <span class="btn-text"><i class="fas fa-sign-in-alt"></i> Login to Your Account</span>
                    <span class="spinner"></span>
                </button>

                <div class="trust-note">
                    <i class="fas fa-lock"></i>
                    Your sign-in is protected with session security controls.
                </div>
            </form>

            <div class="register-link">
                Don't have an account? <a href="register.php">Register here</a>
            </div>

            <div class="back-home">
                <a href="index.php">
                    <i class="fas fa-arrow-left"></i> Back to Home
                </a>
            </div>
        </div>

    </div>

    <script>
        // Password toggle function
        window.togglePassword = function () {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('togglePassword');
            
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

        // Wait for DOM to be fully loaded
        document.addEventListener('DOMContentLoaded', function () {
            // Form validation
            const loginForm = document.getElementById('loginForm');
            if (loginForm) {
                loginForm.addEventListener('submit', function (e) {
                    const username = document.getElementById('username').value.trim();
                    const password = document.getElementById('password').value.trim();

                    if (!username || !password) {
                        e.preventDefault();
                        alert('Please fill in all fields');
                        return false;
                    }

                    // Show loading state
                    document.getElementById('loginBtn').classList.add('loading');
                    document.getElementById('loginBtn').disabled = true;
                });
            }

            // Remove loading state when navigating back
            window.addEventListener('pageshow', function () {
                const btn = document.getElementById('loginBtn');
                if (btn) {
                    btn.classList.remove('loading');
                    btn.disabled = false;
                }
            });

            // Dark Mode Toggle
            const themeToggle = document.getElementById('themeToggle');
            const body = document.body;

            // Check for saved theme preference
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                body.classList.add('dark-mode');
            }

            themeToggle.addEventListener('click', () => {
                body.classList.toggle('dark-mode');
                
                // Save theme preference
                if (body.classList.contains('dark-mode')) {
                    localStorage.setItem('theme', 'dark');
                } else {
                    localStorage.setItem('theme', 'light');
                }
            });

            // Auto-focus username field
            document.getElementById('username').focus();
        });
    </script>
</body>
</html>