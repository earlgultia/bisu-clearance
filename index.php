<?php
// index.php - Enhanced Landing Page for BISU Student Online Clearance System

// Initialize session and include database configuration
require_once 'db.php';

// Get dynamic data from database
$db = Database::getInstance();

// Fetch offices for display
$officesQuery = "SELECT office_id, office_name, office_description FROM offices ORDER BY office_name";
$db->query($officesQuery);
$offices = $db->resultSet();

// Fetch statistics
$statsQuery = "SELECT 
                    (SELECT COUNT(*) FROM users WHERE user_role_id = (SELECT user_role_id FROM user_role WHERE user_role_name = 'student')) as total_students,
                    (SELECT COUNT(*) FROM clearance WHERE status = 'approved') as total_clearances,
                    (SELECT COUNT(*) FROM offices) as total_offices,
                    (SELECT COUNT(*) FROM users WHERE user_role_id = (SELECT user_role_id FROM user_role WHERE user_role_name = 'sub_admin')) as total_staff";
$db->query($statsQuery);
$stats = $db->single();

// Check if user is already logged in (for redirect purposes only)
$isLoggedIn = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

// Handle newsletter subscription
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newsletter_email'])) {
    $email = filter_var($_POST['newsletter_email'], FILTER_SANITIZE_EMAIL);
    // You can add newsletter subscription logic here
    $_SESSION['newsletter_success'] = "Thank you for subscribing!";
}

// Handle contact form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    $name = filter_var($_POST['name'], FILTER_SANITIZE_STRING);
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $subject = filter_var($_POST['subject'], FILTER_SANITIZE_STRING);
    $message = filter_var($_POST['message'], FILTER_SANITIZE_STRING);
    
    // Log the contact inquiry
    $logModel = new ActivityLogModel();
    $logModel->log(null, 'CONTACT_INQUIRY', "Contact form submission from {$name} ({$email}): {$subject}");
    
    $_SESSION['contact_success'] = "Your message has been sent successfully!";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?></title>
    <meta name="description" content="Streamline your student clearance process at Bohol Island State University. Fast, secure, and accessible online clearance system.">
    <meta name="keywords" content="BISU, student clearance, online clearance, Bohol, university clearance">
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    
    <!-- CSS Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    
    <style>
        /* =====================================================
           Enhanced Styles with Dynamic Elements
           ===================================================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        :root {
            /* Light Mode Colors */
            --primary: #412886;
            --primary-dark: #2e1d5e;
            --primary-light: #6b4bb8;
            --primary-soft: rgba(65, 40, 134, 0.05);
            --bg-primary: #ffffff;
            --bg-secondary: #fafafa;
            --bg-tertiary: #f5f5f5;
            --text-primary: #343a40;
            --text-secondary: #6c757d;
            --text-muted: #95a5a6;
            --border-color: #eaeaea;
            --card-bg: #ffffff;
            --card-shadow: 0 5px 30px rgba(0, 0, 0, 0.02);
            --card-shadow-hover: 0 15px 40px rgba(65, 40, 134, 0.1);
            --header-bg: #ffffff;
            --header-shadow: 0 2px 20px rgba(65, 40, 134, 0.08);
            --footer-bg: #ffffff;
            --footer-text: #6c757d;
            --input-bg: #fafafa;
            --input-border: #eaeaea;
            --social-bg: #f5f5f5;
            --scroll-top-shadow: 0 5px 20px rgba(65, 40, 134, 0.3);
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
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
            --card-bg: #16213e;
            --card-shadow: 0 5px 30px rgba(0, 0, 0, 0.3);
            --card-shadow-hover: 0 15px 40px rgba(139, 111, 216, 0.2);
            --header-bg: #16213e;
            --header-shadow: 0 2px 20px rgba(0, 0, 0, 0.3);
            --footer-bg: #16213e;
            --footer-text: #b8b8b8;
            --input-bg: #1a1a2e;
            --input-border: #2a2a4a;
            --social-bg: #1a1a2e;
            --scroll-top-shadow: 0 5px 20px rgba(139, 111, 216, 0.4);
        }

        body {
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            overflow-x: hidden;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-50px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(50px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
            100% { transform: translateY(0px); }
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            margin: 20px auto;
            border-radius: 5px;
            max-width: 1200px;
            animation: slideInLeft 0.5s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .alert-success {
            background: linear-gradient(135deg, #28a74520 0%, #28a74510 100%);
            border-left: 4px solid var(--success-color);
            color: var(--text-primary);
        }

        .alert-close {
            cursor: pointer;
            font-size: 1.2rem;
            opacity: 0.7;
            transition: 0.3s;
        }

        .alert-close:hover {
            opacity: 1;
            transform: scale(1.1);
        }

        /* Dark Mode Toggle */
        .theme-toggle {
            position: relative;
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
            margin-left: 15px;
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

        /* Header */
        header {
            background: var(--header-bg);
            color: var(--text-primary);
            padding: 1rem 5%;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            box-shadow: var(--header-shadow);
            animation: slideInLeft 0.5s ease;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
        }

        nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: bold;
            transition: all 0.3s;
            animation: pulse 2s infinite;
            box-shadow: 0 4px 10px rgba(65, 40, 134, 0.2);
        }

        .logo-icon:hover {
            transform: rotate(360deg);
        }

        .logo-text h1 {
            color: var(--primary);
            font-size: 1.5rem;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .logo-text p {
            color: var(--text-secondary);
            font-size: 0.8rem;
            transition: color 0.3s ease;
        }

        .nav-links {
            display: flex;
            gap: 30px;
            align-items: center;
        }

        .nav-links a {
            color: var(--text-primary);
            text-decoration: none;
            transition: 0.3s;
            position: relative;
            font-weight: 500;
        }

        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--primary);
            transition: width 0.3s;
        }

        .nav-links a:hover {
            color: var(--primary);
        }

        .nav-links a:hover::after {
            width: 100%;
        }

        /* Sign Up and Login Buttons */
        .signup-btn {
            background: transparent;
            color: var(--primary) !important;
            padding: 8px 20px;
            border-radius: 5px;
            font-weight: 600;
            border: 2px solid var(--primary);
            transition: 0.3s;
        }

        .signup-btn:hover {
            background: var(--primary);
            color: white !important;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(65, 40, 134, 0.3);
        }

        .signup-btn::after {
            display: none;
        }

        .login-btn {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white !important;
            padding: 8px 20px;
            border-radius: 5px;
            font-weight: 600;
            animation: bounce 2s infinite;
            box-shadow: 0 4px 15px rgba(65, 40, 134, 0.3);
        }

        .login-btn:hover {
            animation: none;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(65, 40, 134, 0.4);
        }

        .login-btn::after {
            display: none;
        }

        /* Hero Section */
        .hero {
            background: var(--bg-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 80px 5% 0;
            margin-top: 70px;
            position: relative;
            overflow: hidden;
            transition: background-color 0.3s ease;
        }

        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 10% 20%, var(--primary-soft) 0%, transparent 50%);
            pointer-events: none;
        }

        .hero-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 50px;
            align-items: center;
            color: var(--text-primary);
            position: relative;
            z-index: 1;
        }

        .hero-text {
            animation: slideInLeft 0.8s ease;
        }

        .hero-text h1 {
            font-size: 3rem;
            margin-bottom: 20px;
            animation: fadeIn 1s ease 0.3s both;
            color: var(--primary);
            line-height: 1.2;
            transition: color 0.3s ease;
        }

        .hero-text p {
            font-size: 1.2rem;
            margin-bottom: 30px;
            color: var(--text-secondary);
            animation: fadeIn 1s ease 0.5s both;
        }

        .hero-buttons {
            animation: fadeIn 1s ease 0.7s both;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 30px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: 0.3s;
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
            z-index: -1;
        }

        .btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(65, 40, 134, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(65, 40, 134, 0.4);
        }

        .btn-outline {
            border: 2px solid var(--primary);
            color: var(--primary);
            background: transparent;
        }

        .btn-outline:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-3px);
        }

        .hero-image {
            text-align: center;
            animation: float 6s ease-in-out infinite;
        }

        .hero-image i {
            font-size: 20rem;
            color: var(--primary);
            opacity: 0.1;
            transition: 0.3s;
        }

        .hero-image:hover i {
            opacity: 0.2;
            transform: scale(1.1);
        }

        /* Stats Section */
        .stats-section {
            background: var(--bg-secondary);
            padding: 60px 5%;
            border-top: 1px solid var(--border-color);
            border-bottom: 1px solid var(--border-color);
        }

        .stats-container {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .stat-item {
            text-align: center;
            padding: 30px;
            background: var(--card-bg);
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            transition: 0.3s;
            border: 1px solid var(--border-color);
            animation: fadeIn 0.8s ease;
        }

        .stat-item:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--primary);
            margin-bottom: 10px;
        }

        .stat-label {
            color: var(--text-secondary);
            font-size: 1rem;
        }

        /* All content sections */
        .features,
        .offices,
        .how-it-works,
        .testimonials,
        .contact,
        .map-section {
            background: var(--bg-primary);
            padding: 80px 5%;
            transition: background-color 0.3s ease;
        }

        .section-title {
            text-align: center;
            margin-bottom: 50px;
        }

        .section-title h2 {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 15px;
            animation: fadeIn 1s ease;
            position: relative;
            display: inline-block;
            transition: color 0.3s ease;
        }

        .section-title h2::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
            border-radius: 2px;
        }

        .section-title p {
            color: var(--text-secondary);
            max-width: 700px;
            margin: 0 auto;
            animation: fadeIn 1s ease 0.2s both;
        }

        /* Features */
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .feature-card {
            background: var(--card-bg);
            text-align: center;
            padding: 40px 30px;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            animation: fadeIn 0.8s ease;
            animation-fill-mode: both;
            border: 1px solid var(--border-color);
        }

        .feature-card:nth-child(1) { animation-delay: 0.2s; }
        .feature-card:nth-child(2) { animation-delay: 0.4s; }
        .feature-card:nth-child(3) { animation-delay: 0.6s; }
        .feature-card:nth-child(4) { animation-delay: 0.8s; }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--card-shadow-hover);
            border-color: var(--primary-soft);
        }

        .feature-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            color: white;
            font-size: 2rem;
            transition: 0.3s;
            box-shadow: 0 10px 20px rgba(65, 40, 134, 0.2);
        }

        .feature-card:hover .feature-icon {
            transform: rotate(360deg);
        }

        .feature-card h3 {
            color: var(--primary);
            margin-bottom: 15px;
            font-size: 1.3rem;
            transition: color 0.3s ease;
        }

        .feature-card p {
            color: var(--text-secondary);
        }

        /* Offices - Dynamic from Database */
        .offices-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 25px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .office-card {
            background: var(--card-bg);
            padding: 30px 25px;
            border-radius: 10px;
            text-align: center;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            border-bottom: 4px solid transparent;
            animation: fadeIn 0.8s ease;
            animation-fill-mode: both;
            border: 1px solid var(--border-color);
        }

        .office-card:hover {
            border-bottom-color: var(--primary);
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .office-icon {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 15px;
        }

        .office-card h4 {
            color: var(--primary);
            margin-bottom: 10px;
            font-size: 1.2rem;
            transition: color 0.3s ease;
        }

        .office-card p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        /* How It Works */
        .steps-container {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .step {
            text-align: center;
            padding: 40px 30px;
            position: relative;
            animation: fadeIn 0.8s ease;
            animation-fill-mode: both;
            background: var(--card-bg);
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
        }

        .step:nth-child(1) { animation-delay: 0.2s; }
        .step:nth-child(2) { animation-delay: 0.4s; }
        .step:nth-child(3) { animation-delay: 0.6s; }
        .step:nth-child(4) { animation-delay: 0.8s; }

        .step:hover {
            transform: translateY(-10px);
            box-shadow: var(--card-shadow-hover);
            border-color: var(--primary-soft);
        }

        .step-number {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            font-size: 2rem;
            font-weight: bold;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            transition: 0.3s;
            box-shadow: 0 10px 20px rgba(65, 40, 134, 0.2);
        }

        .step:hover .step-number {
            transform: rotate(360deg);
            background: var(--primary-dark);
        }

        .step h4 {
            color: var(--primary);
            margin-bottom: 10px;
            font-size: 1.3rem;
            transition: color 0.3s ease;
        }

        .step p {
            color: var(--text-secondary);
        }

        /* Testimonials */
        .testimonials-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .testimonial-card {
            background: var(--card-bg);
            padding: 30px;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            transition: 0.3s;
            border: 1px solid var(--border-color);
            position: relative;
        }

        .testimonial-card::before {
            content: '"';
            position: absolute;
            top: 10px;
            left: 20px;
            font-size: 5rem;
            color: var(--primary);
            opacity: 0.1;
            font-family: serif;
        }

        .testimonial-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .testimonial-text {
            font-style: italic;
            margin-bottom: 20px;
            color: var(--text-primary);
            position: relative;
            z-index: 1;
        }

        .testimonial-author {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .author-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }

        .author-info h5 {
            color: var(--primary);
            margin-bottom: 5px;
        }

        .author-info p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        /* Contact Section */
        .contact-container {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 50px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .contact-info {
            background: var(--card-bg);
            padding: 40px;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            animation: slideInLeft 0.8s ease;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .contact-info h3 {
            color: var(--primary);
            margin-bottom: 30px;
            font-size: 1.8rem;
            transition: color 0.3s ease;
        }

        .contact-item {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
            transition: 0.3s;
        }

        .contact-item:hover {
            transform: translateX(10px);
        }

        .contact-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
            transition: 0.3s;
            box-shadow: 0 5px 15px rgba(65, 40, 134, 0.2);
        }

        .contact-item:hover .contact-icon {
            transform: rotate(360deg);
        }

        .contact-text h4 {
            color: var(--primary);
            margin-bottom: 5px;
            transition: color 0.3s ease;
        }

        .contact-text p {
            color: var(--text-secondary);
        }

        .contact-form {
            background: var(--card-bg);
            padding: 40px;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            animation: slideInRight 0.8s ease;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .contact-form h3 {
            color: var(--primary);
            margin-bottom: 30px;
            font-size: 1.8rem;
            transition: color 0.3s ease;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--input-border);
            border-radius: 5px;
            font-size: 1rem;
            transition: all 0.3s;
            background: var(--input-bg);
            color: var(--text-primary);
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            background: var(--bg-primary);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(65, 40, 134, 0.1);
        }

        .form-group textarea {
            height: 120px;
            resize: vertical;
        }

        .submit-btn {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            border: none;
            padding: 14px 30px;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            width: 100%;
            position: relative;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(65, 40, 134, 0.2);
        }

        .submit-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .submit-btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .submit-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(65, 40, 134, 0.3);
        }

        .submit-btn i {
            transition: 0.3s;
        }

        .submit-btn:hover i {
            transform: translateX(5px);
        }

        /* Map Section */
        .map-section {
            padding: 0 5% 80px;
        }

        .map-container {
            max-width: 1200px;
            margin: 0 auto;
            background: var(--card-bg);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            animation: fadeIn 1s ease;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .map-placeholder {
            height: 400px;
            background: linear-gradient(135deg, var(--bg-secondary) 0%, var(--bg-tertiary) 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            transition: background 0.3s ease;
        }

        .map-placeholder::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, var(--primary-soft) 0%, transparent 50%);
            animation: shimmer 15s linear infinite;
        }

        .map-content {
            text-align: center;
            position: relative;
            z-index: 1;
        }

        .map-content i {
            font-size: 4rem;
            color: var(--primary);
            margin-bottom: 20px;
            animation: bounce 2s infinite;
            filter: drop-shadow(0 5px 10px rgba(65, 40, 134, 0.2));
        }

        .map-content h3 {
            color: var(--primary);
            margin-bottom: 10px;
            font-size: 2rem;
            transition: color 0.3s ease;
        }

        .map-content p {
            color: var(--text-secondary);
        }

        /* CTA Section */
        .cta {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            text-align: center;
            color: white;
            position: relative;
            overflow: hidden;
            padding: 80px 5%;
            transition: background 0.3s ease;
        }

        .cta::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 60%);
            animation: shimmer 15s linear infinite;
        }

        .cta h2 {
            font-size: 2.5rem;
            margin-bottom: 20px;
            animation: fadeIn 1s ease;
            position: relative;
            z-index: 1;
            color: white;
        }

        .cta p {
            font-size: 1.2rem;
            margin-bottom: 30px;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
            animation: fadeIn 1s ease 0.2s both;
            position: relative;
            z-index: 1;
            opacity: 0.95;
            color: white;
        }

        .cta-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .cta .btn-primary {
            background: white;
            color: var(--primary);
            font-size: 1.1rem;
            padding: 15px 40px;
            animation: pulse 2s infinite;
            position: relative;
            z-index: 1;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .cta .btn-outline-light {
            background: transparent;
            color: white;
            font-size: 1.1rem;
            padding: 15px 40px;
            border: 2px solid white;
            position: relative;
            z-index: 1;
        }

        .cta .btn-outline-light:hover {
            background: white;
            color: var(--primary);
        }

        .cta .btn-primary:hover,
        .cta .btn-outline-light:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
        }

        /* Footer */
        footer {
            background: var(--footer-bg);
            color: var(--text-primary);
            padding: 60px 5% 20px;
            border-top: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .footer-section {
            animation: fadeIn 0.8s ease;
            animation-fill-mode: both;
        }

        .footer-section:nth-child(1) { animation-delay: 0.1s; }
        .footer-section:nth-child(2) { animation-delay: 0.2s; }
        .footer-section:nth-child(3) { animation-delay: 0.3s; }
        .footer-section:nth-child(4) { animation-delay: 0.4s; }

        .footer-section h3 {
            color: var(--primary);
            margin-bottom: 20px;
            position: relative;
            display: inline-block;
            font-size: 1.2rem;
            transition: color 0.3s ease;
        }

        .footer-section h3::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 40px;
            height: 2px;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
            transition: width 0.3s;
        }

        .footer-section:hover h3::after {
            width: 100%;
        }

        .footer-section p,
        .footer-section a {
            color: var(--footer-text);
            text-decoration: none;
            line-height: 2;
            transition: 0.3s;
        }

        .footer-section a:hover {
            color: var(--primary);
            transform: translateX(5px);
            display: inline-block;
        }

        .footer-section ul {
            list-style: none;
        }

        .footer-section ul li {
            transition: 0.3s;
        }

        .footer-section ul li:hover {
            transform: translateX(5px);
        }

        .social-links {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }

        .social-links a {
            width: 40px;
            height: 40px;
            background: var(--social-bg);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.3s;
            color: var(--primary);
            border: 1px solid var(--border-color);
        }

        .social-links a:hover {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            transform: translateY(-3px) scale(1.1);
            border-color: transparent;
        }

        .footer-bottom {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            color: var(--footer-text);
        }

        /* Scroll to top */
        .scroll-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: var(--scroll-top-shadow);
            z-index: 999;
            animation: bounce 2s infinite;
        }

        .scroll-top.show {
            display: flex;
        }

        .scroll-top:hover {
            transform: translateY(-5px) scale(1.1);
            box-shadow: 0 10px 30px rgba(65, 40, 134, 0.4);
            animation: none;
        }

        /* Newsletter */
        .newsletter-form {
            display: flex;
            gap: 10px;
        }

        .newsletter-input {
            flex: 1;
            padding: 12px;
            border: 1px solid var(--input-border);
            border-radius: 5px;
            transition: 0.3s;
            background: var(--input-bg);
            color: var(--text-primary);
        }

        .newsletter-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(65, 40, 134, 0.1);
        }

        .newsletter-btn {
            padding: 12px 20px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            transition: 0.3s;
            font-weight: 600;
        }

        .newsletter-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(65, 40, 134, 0.3);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .hero-content {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .hero-buttons {
                justify-content: center;
            }

            .steps-container {
                grid-template-columns: repeat(2, 1fr);
            }

            .testimonials-grid {
                grid-template-columns: 1fr;
            }

            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            nav {
                flex-direction: column;
                gap: 15px;
            }

            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
            }

            .hero-text h1 {
                font-size: 2rem;
            }

            .steps-container,
            .contact-container,
            .stats-container {
                grid-template-columns: 1fr;
            }

            .hero-image i {
                font-size: 10rem;
            }

            .cta-buttons {
                flex-direction: column;
                align-items: center;
            }

            .cta .btn-primary,
            .cta .btn-outline-light {
                width: 100%;
                max-width: 300px;
            }
        }
    </style>
</head>

<body>
    <!-- Scroll to top -->
    <button class="scroll-top" id="scrollTop" onclick="scrollToTop()">
        <i class="fas fa-arrow-up"></i>
    </button>

    <!-- Header -->
    <header id="header">
        <nav>
            <div class="logo">
                <div class="logo-icon">BISU</div>
                <div class="logo-text">
                    <h1>Online Student Clearance</h1>
                    <p>Bohol Island State University</p>
                </div>
            </div>
            <div class="nav-links">
                <a href="#home">Home</a>
                <a href="#features">Features</a>
                <a href="#offices">Offices</a>
                <a href="#how-it-works">How It Works</a>
                <a href="#contact">Contact</a>
                
                <!-- Sign Up and Login Buttons Only -->
                <a href="signup.php" class="signup-btn">
                    <i class="fas fa-user-plus"></i> Sign Up
                </a>
                <a href="login.php" class="login-btn">
                    <i class="fas fa-sign-in-alt"></i> Login
                </a>
                
                <!-- Dark Mode Toggle -->
                <div class="theme-toggle" id="themeToggle">
                    <i class="fas fa-sun"></i>
                    <i class="fas fa-moon"></i>
                    <div class="toggle-ball"></div>
                </div>
            </div>
        </nav>
    </header>

    <!-- Display Success Messages -->
    <?php if (isset($_SESSION['newsletter_success'])): ?>
    <div class="alert alert-success" id="newsletterAlert">
        <span><i class="fas fa-check-circle"></i> <?php echo $_SESSION['newsletter_success']; ?></span>
        <span class="alert-close" onclick="this.parentElement.remove()">&times;</span>
    </div>
    <?php unset($_SESSION['newsletter_success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['contact_success'])): ?>
    <div class="alert alert-success" id="contactAlert">
        <span><i class="fas fa-check-circle"></i> <?php echo $_SESSION['contact_success']; ?></span>
        <span class="alert-close" onclick="this.parentElement.remove()">&times;</span>
    </div>
    <?php unset($_SESSION['contact_success']); ?>
    <?php endif; ?>

    <!-- Hero Section -->
    <section class="hero" id="home">
        <div class="hero-content">
            <div class="hero-text">
                <h1>Streamline Your Student Clearance Process</h1>
                <p>Experience a faster, more efficient way to complete your clearance requirements online. No more long
                    queues and paper forms!</p>
                <div class="hero-buttons">
                    <a href="signup.php" class="btn btn-primary">
                        <i class="fas fa-user-plus"></i> Sign Up Now
                    </a>
                    <a href="login.php" class="btn btn-outline">
                        <i class="fas fa-sign-in-alt"></i> Login
                    </a>
                </div>
            </div>
            <div class="hero-image">
                <i class="fas fa-university"></i>
            </div>
        </div>
    </section>

    <!-- Statistics Section -->
    <section class="stats-section">
        <div class="stats-container">
            <div class="stat-item">
                <div class="stat-number"><?php echo number_format($stats['total_students'] ?? 0); ?></div>
                <div class="stat-label">Active Students</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo number_format($stats['total_clearances'] ?? 0); ?></div>
                <div class="stat-label">Clearances Processed</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo number_format($stats['total_offices'] ?? 0); ?></div>
                <div class="stat-label">University Offices</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo number_format($stats['total_staff'] ?? 0); ?></div>
                <div class="stat-label">Office Staff</div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features" id="features">
        <div class="section-title">
            <h2>Why Choose Our System?</h2>
            <p>Designed to make your clearance process seamless and hassle-free</p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">🚀</div>
                <h3>Fast Processing</h3>
                <p>Get your clearance requirements processed in minutes, not days. Real-time updates on your application
                    status.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🔒</div>
                <h3>Secure & Reliable</h3>
                <p>Your data is protected with enterprise-grade security. Safe and encrypted transactions guaranteed.
                </p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📱</div>
                <h3>Accessible Anywhere</h3>
                <p>Access your clearance from any device, anytime. Mobile-responsive design for on-the-go convenience.
                </p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📊</div>
                <h3>Real-time Tracking</h3>
                <p>Track your clearance status in real-time. Know exactly which offices have approved your requirements.
                </p>
            </div>
        </div>
    </section>

    <!-- Offices Section - Dynamic from Database -->
    <section class="offices" id="offices">
        <div class="section-title">
            <h2>Offices You'll Encounter</h2>
            <p>Complete your clearance with these university offices</p>
        </div>
        <div class="offices-grid">
            <?php foreach ($offices as $index => $office): ?>
            <div class="office-card" style="animation-delay: <?php echo $index * 0.1; ?>s">
                <div class="office-icon">
                    <?php
                    // Assign icons based on office name
                    $icon = 'fa-building';
                    if (strpos(strtolower($office['office_name']), 'libra') !== false) $icon = 'fa-book';
                    elseif (strpos(strtolower($office['office_name']), 'sas') !== false) $icon = 'fa-users';
                    elseif (strpos(strtolower($office['office_name']), 'dean') !== false) $icon = 'fa-user-tie';
                    elseif (strpos(strtolower($office['office_name']), 'cash') !== false) $icon = 'fa-money-bill';
                    elseif (strpos(strtolower($office['office_name']), 'mis') !== false) $icon = 'fa-laptop';
                    elseif (strpos(strtolower($office['office_name']), 'registrar') !== false) $icon = 'fa-archive';
                    ?>
                    <i class="fas <?php echo $icon; ?>"></i>
                </div>
                <h4><?php echo htmlspecialchars($office['office_name']); ?></h4>
                <p><?php echo htmlspecialchars($office['office_description'] ?? 'Office clearance'); ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- How It Works Section -->
    <section class="how-it-works" id="how-it-works">
        <div class="section-title">
            <h2>How It Works</h2>
            <p>Get cleared in four simple steps</p>
        </div>
        <div class="steps-container">
            <div class="step">
                <div class="step-number">1</div>
                <h4>Sign Up / Login</h4>
                <p>Create your account or login using your BISU credentials</p>
            </div>
            <div class="step">
                <div class="step-number">2</div>
                <h4>Apply</h4>
                <p>Submit your clearance request online for the current semester</p>
            </div>
            <div class="step">
                <div class="step-number">3</div>
                <h4>Track</h4>
                <p>Monitor your clearance status in real-time as offices process your request</p>
            </div>
            <div class="step">
                <div class="step-number">4</div>
                <h4>Download</h4>
                <p>Get your approved clearance digitally once all offices have approved</p>
            </div>
        </div>
    </section>

    <!-- Testimonials Section -->
    <section class="testimonials" id="testimonials">
        <div class="section-title">
            <h2>What Students Say</h2>
            <p>Hear from students who have used our system</p>
        </div>
        <div class="testimonials-grid">
            <div class="testimonial-card">
                <p class="testimonial-text">"The online clearance system saved me so much time! I no longer need to line up in different offices. Everything is tracked online."</p>
                <div class="testimonial-author">
                    <div class="author-avatar">JD</div>
                    <div class="author-info">
                        <h5>John Doe</h5>
                        <p>BSCS Graduate</p>
                    </div>
                </div>
            </div>
            <div class="testimonial-card">
                <p class="testimonial-text">"As a working student, this system is a lifesaver. I can track my clearance status anytime, anywhere."</p>
                <div class="testimonial-author">
                    <div class="author-avatar">JS</div>
                    <div class="author-info">
                        <h5>Jane Smith</h5>
                        <p>BSBA Student</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section class="contact" id="contact">
        <div class="section-title">
            <h2>Contact Us</h2>
            <p>Get in touch with us for any questions or concerns</p>
        </div>
        <div class="contact-container">
            <div class="contact-info">
                <h3>Get in Touch</h3>
                <div class="contact-details">
                    <div class="contact-item">
                        <div class="contact-icon"><i class="fas fa-map-marker-alt"></i></div>
                        <div class="contact-text">
                            <h4>Address</h4>
                            <p>Tagbilaran City, Bohol, Philippines</p>
                        </div>
                    </div>
                    <div class="contact-item">
                        <div class="contact-icon"><i class="fas fa-phone"></i></div>
                        <div class="contact-text">
                            <h4>Phone</h4>
                            <p>(038) 123-4567</p>
                        </div>
                    </div>
                    <div class="contact-item">
                        <div class="contact-icon"><i class="fas fa-envelope"></i></div>
                        <div class="contact-text">
                            <h4>Email</h4>
                            <p>clearance@bisu.edu</p>
                        </div>
                    </div>
                    <div class="contact-item">
                        <div class="contact-icon"><i class="fas fa-clock"></i></div>
                        <div class="contact-text">
                            <h4>Office Hours</h4>
                            <p>Monday - Friday: 8:00 AM - 5:00 PM</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="contact-form">
                <h3>Send Message</h3>
                <form method="POST" action="#contact">
                    <div class="form-group">
                        <input type="text" name="name" placeholder="Your Name" required>
                    </div>
                    <div class="form-group">
                        <input type="email" name="email" placeholder="Your Email" required>
                    </div>
                    <div class="form-group">
                        <input type="text" name="subject" placeholder="Subject" required>
                    </div>
                    <div class="form-group">
                        <textarea name="message" placeholder="Your Message" required></textarea>
                    </div>
                    <button type="submit" name="contact_submit" class="submit-btn">
                        Send Message <i class="fas fa-paper-plane"></i>
                    </button>
                </form>
            </div>
        </div>
    </section>

    <!-- Map Section -->
    <section class="map-section">
        <div class="map-container">
            <div class="map-placeholder">
                <div class="map-content">
                    <i class="fas fa-map-marked-alt"></i>
                    <h3>Find Us Here</h3>
                    <p>Bohol Island State University - Main Campus</p>
                    <p>Tagbilaran City, Bohol</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta">
        <h2>Ready to Get Cleared?</h2>
        <p>Join thousands of BISU students who have experienced hassle-free clearance processing</p>
        <div class="cta-buttons">
            <a href="signup.php" class="btn btn-primary">
                <i class="fas fa-user-plus"></i> Sign Up Now
            </a>
            <a href="login.php" class="btn btn-outline-light">
                <i class="fas fa-sign-in-alt"></i> Login
            </a>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="footer-content">
            <div class="footer-section">
                <h3>BISU Online Clearance</h3>
                <p>Modernizing student clearance processes for a better academic experience at Bohol Island State
                    University.</p>
                <div class="social-links">
                    <a href="#"><i class="fab fa-facebook-f"></i></a>
                    <a href="#"><i class="fab fa-twitter"></i></a>
                    <a href="#"><i class="fab fa-instagram"></i></a>
                    <a href="#"><i class="fab fa-linkedin-in"></i></a>
                </div>
            </div>
            <div class="footer-section">
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="#home">Home</a></li>
                    <li><a href="#features">Features</a></li>
                    <li><a href="#offices">Offices</a></li>
                    <li><a href="#how-it-works">How It Works</a></li>
                    <li><a href="#contact">Contact</a></li>
                </ul>
            </div>
            <div class="footer-section">
                <h3>Support</h3>
                <ul>
                    <li><a href="#">Help Center</a></li>
                    <li><a href="#">FAQs</a></li>
                    <li><a href="#">Privacy Policy</a></li>
                    <li><a href="#">Terms of Service</a></li>
                </ul>
            </div>
            <div class="footer-section">
                <h3>Newsletter</h3>
                <p>Subscribe to get updates</p>
                <form method="POST" action="#footer" class="newsletter-form">
                    <input type="email" name="newsletter_email" placeholder="Enter your email" class="newsletter-input" required>
                    <button type="submit" class="newsletter-btn">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </form>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> Bohol Island State University. All rights reserved. | Version <?php echo SITE_VERSION; ?></p>
        </div>
    </footer>

    <script>
        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Scroll to top button
        window.addEventListener('scroll', function () {
            const scrollTop = document.getElementById('scrollTop');
            if (window.scrollY > 300) {
                scrollTop.classList.add('show');
            } else {
                scrollTop.classList.remove('show');
            }
        });

        function scrollToTop() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }

        // Header scroll effect
        window.addEventListener('scroll', function () {
            const header = document.getElementById('header');
            if (window.scrollY > 100) {
                header.style.boxShadow = '0 4px 30px rgba(65, 40, 134, 0.15)';
            } else {
                header.style.boxShadow = '0 2px 20px rgba(65, 40, 134, 0.08)';
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

        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Add animation on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        document.querySelectorAll('.feature-card, .office-card, .step, .testimonial-card').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.style.transition = 'all 0.6s ease';
            observer.observe(el);
        });
    </script>
</body>

</html>