<?php
// dashboard.php - compatibility router for organization dashboards
// Location: C:\xampp\htdocs\clearance\organization\dashboard.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit();
}

if (($_SESSION['user_role'] ?? '') !== 'organization') {
    header('Location: ../index.php');
    exit();
}

$org_type = $_SESSION['org_type'] ?? '';
$target = 'clinic_dashboard.php';

switch ($org_type) {
    case 'clinic':
        $target = 'clinic_dashboard.php';
        break;
    case 'college':
        $target = 'college_dashboard.php';
        break;
    case 'town':
        $target = 'town_dashboard.php';
        break;
    case 'ssg':
        $target = 'ssg_dashboard.php';
        break;
    default:
        header('Location: ../index.php');
        exit();
}

$query = $_SERVER['QUERY_STRING'] ?? '';
if ($query !== '') {
    header('Location: ' . $target . '?' . $query);
} else {
    header('Location: ' . $target);
}
exit();
