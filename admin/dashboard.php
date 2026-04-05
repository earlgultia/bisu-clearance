<?php
// admin_dashboard.php - Super Admin Dashboard for BISU Online Clearance System
// Location: C:\xampp\htdocs\clearance\admin\dashboard.php

// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database configuration with CORRECT path - Go up one directory
require_once __DIR__ . '/../db.php';

// Check if user is logged in and is a super admin
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: ../login.php");
    exit();
}

if ($_SESSION['user_role'] !== 'super_admin') {
    header("Location: ../index.php");
    exit();
}

// Get admin information from session
$admin_id = $_SESSION['user_id'];
$admin_name = $_SESSION['user_name'] ?? '';
$admin_email = $_SESSION['user_email'] ?? '';
$admin_fname = $_SESSION['user_fname'] ?? '';
$admin_lname = $_SESSION['user_lname'] ?? '';

// Initialize variables
$success = '';
$error = '';
$active_tab = $_GET['tab'] ?? 'dashboard';
$profile_pic = null;

// Get database instance
$db = Database::getInstance();

// Handle different POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Add College
    if (isset($_POST['add_college'])) {
        $college_name = trim($_POST['college_name'] ?? '');

        if (empty($college_name)) {
            $error = "College name is required.";
        } else {
            try {
                $db->query("INSERT INTO college (college_name, created_at) VALUES (:name, NOW())");
                $db->bind(':name', $college_name);

                if ($db->execute()) {
                    // Log activity
                    $logModel = new ActivityLogModel();
                    $logModel->log($admin_id, 'ADD_COLLEGE', "Added new college: $college_name");

                    $success = "College added successfully!";
                } else {
                    $error = "Failed to add college.";
                }
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    $error = "College already exists.";
                } else {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }

    // Edit College
    if (isset($_POST['edit_college'])) {
        $college_id = $_POST['college_id'] ?? '';
        $college_name = trim($_POST['college_name'] ?? '');

        if (empty($college_id) || empty($college_name)) {
            $error = "College name is required.";
        } else {
            try {
                $db->query("UPDATE college SET college_name = :name, updated_at = NOW() WHERE college_id = :id");
                $db->bind(':name', $college_name);
                $db->bind(':id', $college_id);

                if ($db->execute()) {
                    // Log activity
                    $logModel = new ActivityLogModel();
                    $logModel->log($admin_id, 'EDIT_COLLEGE', "Updated college ID $college_id to: $college_name");

                    $success = "College updated successfully!";
                } else {
                    $error = "Failed to update college.";
                }
            } catch (Exception $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }

    // Delete College
    if (isset($_POST['delete_college'])) {
        $college_id = $_POST['college_id'] ?? '';

        if (empty($college_id)) {
            $error = "College ID is required.";
        } else {
            try {
                // Check if college has courses
                $db->query("SELECT COUNT(*) as count FROM course WHERE college_id = :id");
                $db->bind(':id', $college_id);
                $result = $db->single();
                $course_count = $result['count'] ?? 0;

                if ($course_count > 0) {
                    $error = "Cannot delete college with existing courses. Delete courses first.";
                } else {
                    // Get college name for logging
                    $db->query("SELECT college_name FROM college WHERE college_id = :id");
                    $db->bind(':id', $college_id);
                    $college = $db->single();
                    $college_name = $college['college_name'] ?? 'Unknown';

                    $db->query("DELETE FROM college WHERE college_id = :id");
                    $db->bind(':id', $college_id);

                    if ($db->execute()) {
                        // Log activity
                        $logModel = new ActivityLogModel();
                        $logModel->log($admin_id, 'DELETE_COLLEGE', "Deleted college: $college_name");

                        $success = "College deleted successfully!";
                    } else {
                        $error = "Failed to delete college.";
                    }
                }
            } catch (Exception $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }

    // Add Course
    if (isset($_POST['add_course'])) {
        $course_name = trim($_POST['course_name'] ?? '');
        $course_code = trim($_POST['course_code'] ?? '');
        $college_id = trim($_POST['college_id'] ?? '');

        if (empty($course_name) || empty($course_code) || empty($college_id)) {
            $error = "All fields are required.";
        } else {
            try {
                $db->query("INSERT INTO course (course_name, course_code, college_id, created_at) VALUES (:name, :code, :college_id, NOW())");
                $db->bind(':name', $course_name);
                $db->bind(':code', $course_code);
                $db->bind(':college_id', $college_id);

                if ($db->execute()) {
                    // Log activity
                    $logModel = new ActivityLogModel();
                    $logModel->log($admin_id, 'ADD_COURSE', "Added new course: $course_name ($course_code)");

                    $success = "Course added successfully!";
                } else {
                    $error = "Failed to add course.";
                }
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    $error = "Course code already exists.";
                } else {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }

    // Edit Course
    if (isset($_POST['edit_course'])) {
        $course_id = $_POST['course_id'] ?? '';
        $course_name = trim($_POST['course_name'] ?? '');
        $course_code = trim($_POST['course_code'] ?? '');
        $college_id = trim($_POST['college_id'] ?? '');

        if (empty($course_id) || empty($course_name) || empty($course_code) || empty($college_id)) {
            $error = "All fields are required.";
        } else {
            try {
                $db->query("UPDATE course SET course_name = :name, course_code = :code, college_id = :college_id, updated_at = NOW() WHERE course_id = :id");
                $db->bind(':name', $course_name);
                $db->bind(':code', $course_code);
                $db->bind(':college_id', $college_id);
                $db->bind(':id', $course_id);

                if ($db->execute()) {
                    // Log activity
                    $logModel = new ActivityLogModel();
                    $logModel->log($admin_id, 'EDIT_COURSE', "Updated course ID $course_id: $course_name ($course_code)");

                    $success = "Course updated successfully!";
                } else {
                    $error = "Failed to update course.";
                }
            } catch (Exception $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }

    // Delete Course
    if (isset($_POST['delete_course'])) {
        $course_id = $_POST['course_id'] ?? '';

        if (empty($course_id)) {
            $error = "Course ID is required.";
        } else {
            try {
                // Check if course has students
                $db->query("SELECT COUNT(*) as count FROM users WHERE course_id = :id");
                $db->bind(':id', $course_id);
                $result = $db->single();
                $student_count = $result['count'] ?? 0;

                if ($student_count > 0) {
                    $error = "Cannot delete course with enrolled students.";
                } else {
                    // Get course info for logging
                    $db->query("SELECT course_name, course_code FROM course WHERE course_id = :id");
                    $db->bind(':id', $course_id);
                    $course = $db->single();
                    $course_name = $course['course_name'] ?? 'Unknown';
                    $course_code = $course['course_code'] ?? '';

                    $db->query("DELETE FROM course WHERE course_id = :id");
                    $db->bind(':id', $course_id);

                    if ($db->execute()) {
                        // Log activity
                        $logModel = new ActivityLogModel();
                        $logModel->log($admin_id, 'DELETE_COURSE', "Deleted course: $course_name ($course_code)");

                        $success = "Course deleted successfully!";
                    } else {
                        $error = "Failed to delete course.";
                    }
                }
            } catch (Exception $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }

    // Add Office
    if (isset($_POST['add_office'])) {
        $office_name = trim($_POST['office_name'] ?? '');
        $office_description = trim($_POST['office_description'] ?? '');

        if (empty($office_name)) {
            $error = "Office name is required.";
        } else {
            try {
                $db->query("INSERT INTO offices (office_name, office_description, created_at) VALUES (:name, :description, NOW())");
                $db->bind(':name', $office_name);
                $db->bind(':description', $office_description);

                if ($db->execute()) {
                    // Log activity
                    $logModel = new ActivityLogModel();
                    $logModel->log($admin_id, 'ADD_OFFICE', "Added new office: $office_name");

                    $success = "Office added successfully!";
                } else {
                    $error = "Failed to add office.";
                }
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate') !== false) {
                    $error = "Office already exists.";
                } else {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }

    // Edit Office
    if (isset($_POST['edit_office'])) {
        $office_id = $_POST['office_id'] ?? '';
        $office_name = trim($_POST['office_name'] ?? '');
        $office_description = trim($_POST['office_description'] ?? '');

        if (empty($office_id) || empty($office_name)) {
            $error = "Office name is required.";
        } else {
            try {
                $db->query("UPDATE offices SET office_name = :name, office_description = :description, updated_at = NOW() WHERE office_id = :id");
                $db->bind(':name', $office_name);
                $db->bind(':description', $office_description);
                $db->bind(':id', $office_id);

                if ($db->execute()) {
                    // Log activity
                    $logModel = new ActivityLogModel();
                    $logModel->log($admin_id, 'EDIT_OFFICE', "Updated office ID $office_id to: $office_name");

                    $success = "Office updated successfully!";
                } else {
                    $error = "Failed to update office.";
                }
            } catch (Exception $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }

    // Delete Office
    if (isset($_POST['delete_office'])) {
        $office_id = $_POST['office_id'] ?? '';

        if (empty($office_id)) {
            $error = "Office ID is required.";
        } else {
            try {
                // Check if office has users
                $db->query("SELECT COUNT(*) as count FROM users WHERE office_id = :id");
                $db->bind(':id', $office_id);
                $result = $db->single();
                $user_count = $result['count'] ?? 0;

                if ($user_count > 0) {
                    $error = "Cannot delete office with assigned users.";
                } else {
                    // Check if office has sub-offices
                    $db->query("SELECT COUNT(*) as count FROM sub_offices WHERE parent_office_id = :id");
                    $db->bind(':id', $office_id);
                    $result = $db->single();
                    $sub_office_count = $result['count'] ?? 0;

                    if ($sub_office_count > 0) {
                        $error = "Cannot delete office with existing sub-offices.";
                    } else {
                        // Get office name for logging
                        $db->query("SELECT office_name FROM offices WHERE office_id = :id");
                        $db->bind(':id', $office_id);
                        $office = $db->single();
                        $office_name = $office['office_name'] ?? 'Unknown';

                        $db->query("DELETE FROM offices WHERE office_id = :id");
                        $db->bind(':id', $office_id);

                        if ($db->execute()) {
                            // Log activity
                            $logModel = new ActivityLogModel();
                            $logModel->log($admin_id, 'DELETE_OFFICE', "Deleted office: $office_name");

                            $success = "Office deleted successfully!";
                        } else {
                            $error = "Failed to delete office.";
                        }
                    }
                }
            } catch (Exception $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }

    // Add User
    if (isset($_POST['add_user'])) {
        $active_tab = 'users';
        $fname = trim($_POST['fname'] ?? '');
        $lname = trim($_POST['lname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $user_role = $_POST['user_role'] ?? '';
        $office_id = !empty($_POST['office_id']) ? $_POST['office_id'] : null;
        $college_id = !empty($_POST['college_id']) ? $_POST['college_id'] : null;
        $ismis_id = !empty($_POST['ismis_id']) ? trim($_POST['ismis_id']) : null;
        $address = !empty($_POST['address']) ? trim($_POST['address']) : '';
        $contact = !empty($_POST['contact']) ? trim($_POST['contact']) : '';
        $age = !empty($_POST['age']) ? trim($_POST['age']) : null;

        if (empty($fname) || empty($lname) || empty($email) || empty($password) || empty($user_role)) {
            $error = "All required fields must be filled.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters.";
        } elseif ($user_role === 'sub_admin' && empty($office_id)) {
            $error = "Office assignment is required for Sub Admin.";
        } elseif (in_array($user_role, ['dean', 'student'], true) && empty($college_id)) {
            $error = "College assignment is required for this role.";
        } else {
            try {
                // Check if email already exists
                $db->query("SELECT users_id FROM users WHERE emails = :email");
                $db->bind(':email', $email);
                if ($db->single()) {
                    $error = "Email already exists.";
                } else {
                    // Check if ISMIS ID already exists (if provided)
                    if ($ismis_id) {
                        $db->query("SELECT users_id FROM users WHERE ismis_id = :ismis_id");
                        $db->bind(':ismis_id', $ismis_id);
                        if ($db->single()) {
                            $error = "ISMIS ID already exists.";
                            throw new Exception("ISMIS ID exists");
                        }
                    }

                    // Get role ID
                    $db->query("SELECT user_role_id FROM user_role WHERE user_role_name = :role");
                    $db->bind(':role', $user_role);
                    $role = $db->single();

                    if ($role) {
                        $hashed_password = hashPassword($password);

                        // Insert into users table
                        $sql = "INSERT INTO users (
                            fname, lname, emails, password, user_role_id, 
                            office_id, college_id, is_active, ismis_id, address, contacts, age, created_at
                        ) VALUES (
                            :fname, :lname, :email, :password, :role_id, 
                            :office_id, :college_id, 1, :ismis_id, :address, :contacts, :age, NOW()
                        )";

                        $db->query($sql);
                        $db->bind(':fname', $fname);
                        $db->bind(':lname', $lname);
                        $db->bind(':email', $email);
                        $db->bind(':password', $hashed_password);
                        $db->bind(':role_id', $role['user_role_id']);
                        $db->bind(':office_id', $office_id);
                        $db->bind(':college_id', $college_id);
                        $db->bind(':ismis_id', $ismis_id);
                        $db->bind(':address', $address);
                        $db->bind(':contacts', $contact);
                        $db->bind(':age', $age);

                        if ($db->execute()) {
                            $user_id = $db->lastInsertId();

                            // If sub-admin, add to sub_admin_offices
                            if ($user_role === 'sub_admin' && $office_id) {
                                $db->query("INSERT INTO sub_admin_offices (users_id, office_id, can_create_accounts, created_at) VALUES (:user_id, :office_id, 1, NOW())");
                                $db->bind(':user_id', $user_id);
                                $db->bind(':office_id', $office_id);
                                $db->execute();
                            }

                            // If dean, add to department_chairpersons
                            if ($user_role === 'dean' && $college_id) {
                                $db->query("INSERT INTO department_chairpersons (users_id, department_name, college_id, created_by, created_at) 
                                           VALUES (:user_id, :dept_name, :college_id, :created_by, NOW())");
                                $db->bind(':user_id', $user_id);
                                $db->bind(':dept_name', $fname . ' ' . $lname . ' - Dean');
                                $db->bind(':college_id', $college_id);
                                $db->bind(':created_by', $admin_id);
                                $db->execute();
                            }

                            // Log activity
                            $logModel = new ActivityLogModel();
                            $logModel->log($admin_id, 'ADD_USER', "Created new user: $email with role: $user_role");

                            $success = "User account created successfully!";
                        } else {
                            $error = "Failed to add user.";
                        }
                    } else {
                        $error = "Invalid user role.";
                    }
                }
            } catch (Exception $e) {
                if ($e->getMessage() !== "ISMIS ID exists") {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }

    // Edit User
    if (isset($_POST['edit_user'])) {
        $active_tab = 'users';
        $user_id = (int) ($_POST['user_id'] ?? 0);
        $fname = trim($_POST['fname'] ?? '');
        $lname = trim($_POST['lname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $user_role = $_POST['user_role'] ?? '';
        $office_id_raw = trim((string) ($_POST['office_id'] ?? ''));
        $college_id_raw = trim((string) ($_POST['college_id'] ?? ''));
        $office_id = ($office_id_raw === '' || $office_id_raw === '0') ? null : $office_id_raw;
        $college_id = ($college_id_raw === '' || $college_id_raw === '0') ? null : $college_id_raw;
        $ismis_id = !empty($_POST['ismis_id']) ? trim($_POST['ismis_id']) : null;
        $address = !empty($_POST['address']) ? trim($_POST['address']) : '';
        $contact = !empty($_POST['contact']) ? trim($_POST['contact']) : '';
        $age = !empty($_POST['age']) ? trim($_POST['age']) : null;
        $is_active = isset($_POST['is_active']) ? (int) $_POST['is_active'] : 1;
        $is_active = $is_active === 0 ? 0 : 1;

        if (empty($user_id) || empty($fname) || empty($lname) || empty($email) || empty($user_role)) {
            $error = "All required fields must be filled.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            try {
                // Get current user assignment so we can preserve existing values
                // when the form omits them unintentionally.
                $db->query("SELECT office_id, college_id
                            FROM users
                            WHERE users_id = :user_id
                            LIMIT 1");
                $db->bind(':user_id', $user_id);
                $current_user = $db->single();

                if (!$current_user) {
                    $error = "User account not found.";
                    throw new Exception("USER_NOT_FOUND");
                }

                if ($user_role === 'sub_admin' && empty($office_id) && !empty($current_user['office_id'])) {
                    $office_id = $current_user['office_id'];
                }

                if (in_array($user_role, ['dean', 'student'], true) && empty($college_id) && !empty($current_user['college_id'])) {
                    $college_id = $current_user['college_id'];
                }

                if ($user_role === 'sub_admin' && empty($office_id)) {
                    $error = "Office assignment is required for Sub Admin.";
                    throw new Exception("MISSING_OFFICE_ASSIGNMENT");
                }

                if (in_array($user_role, ['dean', 'student'], true) && empty($college_id)) {
                    $error = "College assignment is required for this role.";
                    throw new Exception("MISSING_COLLEGE_ASSIGNMENT");
                }

                // Check if email already exists for another user
                $db->query("SELECT users_id FROM users WHERE emails = :email AND users_id != :user_id");
                $db->bind(':email', $email);
                $db->bind(':user_id', $user_id);
                if ($db->single()) {
                    $error = "Email already exists for another user.";
                } else {
                    // Check if ISMIS ID already exists for another user
                    if ($ismis_id) {
                        $db->query("SELECT users_id FROM users WHERE ismis_id = :ismis_id AND users_id != :user_id");
                        $db->bind(':ismis_id', $ismis_id);
                        $db->bind(':user_id', $user_id);
                        if ($db->single()) {
                            $error = "ISMIS ID already exists for another user.";
                            throw new Exception("ISMIS ID exists");
                        }
                    }

                    // Get role ID
                    $db->query("SELECT user_role_id FROM user_role WHERE user_role_name = :role");
                    $db->bind(':role', $user_role);
                    $role = $db->single();

                    if ($role) {
                        // Update users table
                        $db->query("UPDATE users SET 
                            fname = :fname, lname = :lname, emails = :email,
                            user_role_id = :role_id, office_id = :office_id, college_id = :college_id,
                            ismis_id = :ismis_id, address = :address, contacts = :contacts, age = :age,
                            is_active = :is_active, updated_at = NOW()
                            WHERE users_id = :user_id");

                        $db->bind(':fname', $fname);
                        $db->bind(':lname', $lname);
                        $db->bind(':email', $email);
                        $db->bind(':role_id', $role['user_role_id']);
                        $db->bind(':office_id', $office_id);
                        $db->bind(':college_id', $college_id);
                        $db->bind(':ismis_id', $ismis_id);
                        $db->bind(':address', $address);
                        $db->bind(':contacts', $contact);
                        $db->bind(':age', $age);
                        $db->bind(':is_active', $is_active);
                        $db->bind(':user_id', $user_id);

                        if ($db->execute()) {
                            // Update sub_admin_offices if needed
                            if ($user_role === 'sub_admin' && $office_id) {
                                // Check if entry exists
                                $db->query("SELECT * FROM sub_admin_offices WHERE users_id = :user_id");
                                $db->bind(':user_id', $user_id);
                                if ($db->single()) {
                                    $db->query("UPDATE sub_admin_offices SET office_id = :office_id, updated_at = NOW() WHERE users_id = :user_id");
                                } else {
                                    $db->query("INSERT INTO sub_admin_offices (users_id, office_id, can_create_accounts, created_at) VALUES (:user_id, :office_id, 1, NOW())");
                                }
                                $db->bind(':user_id', $user_id);
                                $db->bind(':office_id', $office_id);
                                $db->execute();
                            }

                            // Update department_chairpersons if needed
                            if ($user_role === 'dean' && $college_id) {
                                $db->query("SELECT * FROM department_chairpersons WHERE users_id = :user_id");
                                $db->bind(':user_id', $user_id);
                                if ($db->single()) {
                                    $db->query("UPDATE department_chairpersons SET college_id = :college_id, department_name = :dept_name WHERE users_id = :user_id");
                                } else {
                                    $db->query("INSERT INTO department_chairpersons (users_id, department_name, college_id, created_by, created_at) 
                                               VALUES (:user_id, :dept_name, :college_id, :created_by, NOW())");
                                }
                                $db->bind(':user_id', $user_id);
                                $db->bind(':dept_name', $fname . ' ' . $lname . ' - Dean');
                                $db->bind(':college_id', $college_id);
                                $db->bind(':created_by', $admin_id);
                                $db->execute();
                            }

                            // Log activity
                            $logModel = new ActivityLogModel();
                            $logModel->log($admin_id, 'EDIT_USER', "Updated user ID $user_id: $email");

                            $success = "User updated successfully!";
                        } else {
                            $error = "Failed to update user.";
                        }
                    } else {
                        $error = "Invalid user role.";
                    }
                }
            } catch (Exception $e) {
                if (!in_array($e->getMessage(), ['ISMIS ID exists', 'USER_NOT_FOUND', 'MISSING_OFFICE_ASSIGNMENT', 'MISSING_COLLEGE_ASSIGNMENT'], true)) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }

    // Toggle User Status (Activate/Deactivate)
    if (isset($_POST['toggle_user_status'])) {
        $active_tab = 'users';
        $user_id = $_POST['user_id'] ?? '';
        $current_status = $_POST['current_status'] ?? '';

        if ($user_id && $user_id != $admin_id) { // Can't deactivate yourself
            try {
                $new_status = $current_status == 1 ? 0 : 1;
                $db->query("UPDATE users SET is_active = :status, updated_at = NOW() WHERE users_id = :user_id");
                $db->bind(':status', $new_status);
                $db->bind(':user_id', $user_id);

                if ($db->execute()) {
                    // Get user email for logging
                    $db->query("SELECT emails FROM users WHERE users_id = :user_id");
                    $db->bind(':user_id', $user_id);
                    $user = $db->single();
                    $email = $user['emails'] ?? 'Unknown';

                    // Log activity
                    $logModel = new ActivityLogModel();
                    $logModel->log($admin_id, 'TOGGLE_USER', ($new_status ? 'Activated' : 'Deactivated') . " user: $email");

                    $success = "User status updated successfully!";
                }
            } catch (Exception $e) {
                $error = "Failed to update user status.";
            }
        } else {
            $error = "Cannot modify your own account.";
        }
    }

    // Delete User
    if (isset($_POST['delete_user'])) {
        $active_tab = 'users';
        $user_id = $_POST['user_id'] ?? '';

        if ($user_id && $user_id != $admin_id) { // Can't delete yourself
            try {
                // Get user info for logging
                $db->query("SELECT emails, fname, lname FROM users WHERE users_id = :user_id");
                $db->bind(':user_id', $user_id);
                $user = $db->single();
                $email = $user['emails'] ?? 'Unknown';
                $name = ($user['fname'] ?? '') . ' ' . ($user['lname'] ?? '');

                // Check if user has related records
                $db->query("SELECT COUNT(*) as count FROM clearance WHERE users_id = :user_id");
                $db->bind(':user_id', $user_id);
                $result = $db->single();
                $clearance_count = $result['count'] ?? 0;

                if ($clearance_count > 0) {
                    $error = "Cannot delete user with existing clearance records. Deactivate instead.";
                } else {
                    // Delete from related tables first
                    $db->query("DELETE FROM sub_admin_offices WHERE users_id = :user_id");
                    $db->bind(':user_id', $user_id);
                    $db->execute();

                    $db->query("DELETE FROM department_chairpersons WHERE users_id = :user_id");
                    $db->bind(':user_id', $user_id);
                    $db->execute();

                    $db->query("DELETE FROM clinic_records WHERE users_id = :user_id");
                    $db->bind(':user_id', $user_id);
                    $db->execute();

                    // Delete activity logs (set to NULL)
                    $db->query("UPDATE activity_logs SET users_id = NULL WHERE users_id = :user_id");
                    $db->bind(':user_id', $user_id);
                    $db->execute();

                    // Delete user
                    $db->query("DELETE FROM users WHERE users_id = :user_id");
                    $db->bind(':user_id', $user_id);

                    if ($db->execute()) {
                        // Log activity
                        $logModel = new ActivityLogModel();
                        $logModel->log($admin_id, 'DELETE_USER', "Deleted user: $email ($name)");

                        $success = "User deleted successfully!";
                    } else {
                        $error = "Failed to delete user.";
                    }
                }
            } catch (Exception $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Cannot delete your own account.";
        }
    }

    // System Settings
    if (isset($_POST['update_settings'])) {
        $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $auto_backup = isset($_POST['auto_backup']) ? 1 : 0;

        // Here you would save settings to a settings table
        // For now, just log the action
        $logModel = new ActivityLogModel();
        $logModel->log($admin_id, 'UPDATE_SETTINGS', "Updated system settings");

        $success = "System settings updated successfully!";
    }
}

// Fetch statistics and data
$stats = [];
try {
    // Total users by role
    $db->query("SELECT ur.user_role_name, COUNT(u.users_id) as count 
                FROM user_role ur 
                LEFT JOIN users u ON ur.user_role_id = u.user_role_id 
                GROUP BY ur.user_role_id, ur.user_role_name
                ORDER BY ur.user_role_name");
    $stats['users_by_role'] = $db->resultSet();

    $total_users = 0;
    foreach ($stats['users_by_role'] as $role) {
        $total_users += $role['count'];
    }
    $stats['total_users'] = $total_users;

    // Total colleges
    $db->query("SELECT COUNT(*) as count FROM college");
    $result = $db->single();
    $stats['total_colleges'] = $result['count'] ?? 0;

    // Total courses
    $db->query("SELECT COUNT(*) as count FROM course");
    $result = $db->single();
    $stats['total_courses'] = $result['count'] ?? 0;

    // Total offices
    $db->query("SELECT COUNT(*) as count FROM offices");
    $result = $db->single();
    $stats['total_offices'] = $result['count'] ?? 0;

    // Clearance statistics
    $db->query("SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                FROM clearance");
    $clearance_stats = $db->single();
    $stats['pending_clearances'] = $clearance_stats['pending'] ?? 0;
    $stats['approved_clearances'] = $clearance_stats['approved'] ?? 0;
    $stats['rejected_clearances'] = $clearance_stats['rejected'] ?? 0;
    $stats['total_clearances'] = $clearance_stats['total'] ?? 0;

    // Recent users
    $db->query("SELECT u.*, ur.user_role_name 
                FROM users u 
                JOIN user_role ur ON u.user_role_id = ur.user_role_id 
                ORDER BY u.created_at DESC 
                LIMIT 5");
    $stats['recent_users'] = $db->resultSet();

    // All colleges for dropdown
    $db->query("SELECT * FROM college ORDER BY college_name");
    $stats['colleges'] = $db->resultSet();

    // All offices for dropdown
    $db->query("SELECT * FROM offices ORDER BY office_name");
    $stats['offices'] = $db->resultSet();

    // All user roles including dean but excluding super_admin
    $db->query("SELECT * FROM user_role WHERE user_role_name != 'super_admin' ORDER BY user_role_name");
    $stats['roles'] = $db->resultSet();

    // All users for management
    $db->query("SELECT u.*, ur.user_role_name, o.office_name, c.college_name,
                CASE 
                    WHEN u.user_role_id = (SELECT user_role_id FROM user_role WHERE user_role_name = 'sub_admin') AND o.office_name IS NOT NULL THEN o.office_name
                    WHEN u.user_role_id = (SELECT user_role_id FROM user_role WHERE user_role_name = 'dean') AND c.college_name IS NOT NULL THEN c.college_name
                    ELSE 'N/A'
                END as assignment
                FROM users u 
                LEFT JOIN user_role ur ON u.user_role_id = ur.user_role_id
                LEFT JOIN offices o ON u.office_id = o.office_id
                LEFT JOIN college c ON u.college_id = c.college_id
                ORDER BY u.created_at DESC");
    $stats['all_users'] = $db->resultSet();

    // Get profile picture
    $db->query("SELECT profile_picture FROM users WHERE users_id = :user_id");
    $db->bind(':user_id', $admin_id);
    $user_data = $db->single();
    $profile_pic = $user_data['profile_picture'] ?? null;

    // Get report data - User registration trends (last 30 days)
    $db->query("SELECT DATE(created_at) as date, COUNT(*) as count 
                FROM users 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY DATE(created_at)
                ORDER BY date");
    $stats['user_trends'] = $db->resultSet();

    // Clearance statistics by office
    $db->query("SELECT o.office_name, 
                COUNT(c.clearance_id) as total,
                SUM(CASE WHEN c.status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN c.status = 'rejected' THEN 1 ELSE 0 END) as rejected
                FROM offices o
                LEFT JOIN clearance c ON o.office_id = c.office_id
                GROUP BY o.office_id
                ORDER BY total DESC");
    $stats['clearance_by_office'] = $db->resultSet();

    // Top 10 most active users (students only)
    $db->query("SELECT u.fname, u.lname, u.emails, COUNT(c.clearance_id) as clearance_count
                FROM users u
                JOIN clearance c ON u.users_id = c.users_id
                WHERE u.user_role_id = (SELECT user_role_id FROM user_role WHERE user_role_name = 'student')
                GROUP BY u.users_id
                ORDER BY clearance_count DESC
                LIMIT 10");
    $stats['top_users'] = $db->resultSet();

    // Get all courses with college names
    $db->query("SELECT c.*, col.college_name 
                FROM course c 
                JOIN college col ON c.college_id = col.college_id 
                ORDER BY c.created_at DESC");
    $stats['courses'] = $db->resultSet();

    // Recent activity logs
    $db->query("SELECT a.*, CONCAT(u.fname, ' ', u.lname) as user_name
                FROM activity_logs a
                LEFT JOIN users u ON a.users_id = u.users_id
                ORDER BY a.created_at DESC
                LIMIT 10");
    $stats['recent_activities'] = $db->resultSet();

} catch (Exception $e) {
    error_log("Error fetching admin stats: " . $e->getMessage());
}

// Helper function to get role badge class
function getRoleBadgeClass($role)
{
    $classes = [
        'super_admin' => 'super_admin',
        'sub_admin' => 'sub_admin',
        'dean' => 'dean',
        'student' => 'student',
        'office_staff' => 'office_staff'
    ];
    return $classes[$role] ?? '';
}

// The rest of your HTML/CSS/JavaScript goes here...
// (Copy all the HTML/CSS/JavaScript from your previous version)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - BISU Online Clearance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Copy ALL your CSS styles from your previous version here */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: var(--font-body);
        }

        /* Light Mode Colors */
        :root {
            --font-body: 'Manrope', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --font-display: 'Space Grotesk', 'Manrope', sans-serif;
            --primary: #0f766e;
            --primary-dark: #115e59;
            --primary-light: #14b8a6;
            --primary-soft: rgba(15, 118, 110, 0.12);
            --primary-glow: rgba(20, 184, 166, 0.28);
            --bg-primary: #ffffff;
            --bg-secondary: #f4f7fb;
            --bg-tertiary: #eaf1f7;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --border-color: #dbe5ef;
            --card-bg: #ffffff;
            --card-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            --card-shadow-hover: 0 16px 36px rgba(15, 118, 110, 0.18);
            --header-bg: linear-gradient(120deg, #0b3b61 0%, #0f766e 56%, #14b8a6 100%);
            --sidebar-bg: #f7fbfd;
            --input-bg: #ffffff;
            --input-border: #dbe5ef;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --success-soft: rgba(16, 185, 129, 0.1);
            --warning-soft: rgba(245, 158, 11, 0.1);
            --danger-soft: rgba(239, 68, 68, 0.1);
            --info-soft: rgba(59, 130, 246, 0.1);
        }

        /* Dark Mode Colors */
        .dark-mode {
            --primary: #2dd4bf;
            --primary-dark: #14b8a6;
            --primary-light: #5eead4;
            --primary-soft: rgba(45, 212, 191, 0.16);
            --primary-glow: rgba(45, 212, 191, 0.24);
            --bg-primary: #0f172a;
            --bg-secondary: #111c31;
            --bg-tertiary: #1a2840;
            --text-primary: #e2e8f0;
            --text-secondary: #b6c2d2;
            --text-muted: #8a9ab0;
            --border-color: #243247;
            --card-bg: #14223a;
            --card-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            --card-shadow-hover: 0 10px 30px rgba(45, 212, 191, 0.16);
            --header-bg: linear-gradient(120deg, #0b1220 0%, #0f2a3a 100%);
            --sidebar-bg: #132236;
            --input-bg: #0f1c31;
            --input-border: #2a3c54;
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
            background:
                radial-gradient(circle at 2% 6%, rgba(15, 118, 110, 0.1) 0%, transparent 36%),
                radial-gradient(circle at 95% 12%, rgba(11, 59, 97, 0.1) 0%, transparent 32%),
                var(--bg-secondary);
            color: var(--text-primary);
            min-height: 100vh;
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        h1,
        h2,
        h3,
        h4,
        h5 {
            font-family: var(--font-display);
            letter-spacing: -0.015em;
        }

        /* Dark Mode Toggle */
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

        /* Header */
        .header {
            background: var(--header-bg);
            color: white;
            padding: 0.95rem 4.5%;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            box-shadow: 0 10px 30px rgba(9, 20, 37, 0.18);
            border-bottom: 1px solid rgba(255, 255, 255, 0.18);
            backdrop-filter: blur(6px);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1440px;
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
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .logo-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .logo h2 {
            font-size: 1.3rem;
            font-weight: 600;
        }

        .mobile-menu-btn {
            display: none;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.25);
            background: rgba(255, 255, 255, 0.14);
            color: #ffffff;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            cursor: pointer;
            transition: 0.25s ease;
        }

        .mobile-menu-btn:hover {
            background: rgba(255, 255, 255, 0.24);
            transform: translateY(-1px);
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

        /* Main Container */
        .main-container {
            display: flex;
            margin-top: 70px;
            min-height: calc(100vh - 70px);
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            border-right: 1px solid var(--border-color);
            padding: 30px 0;
            position: fixed;
            height: calc(100vh - 70px);
            overflow-y: auto;
            transition: background-color 0.3s ease, border-color 0.3s ease;
            box-shadow: inset -1px 0 0 var(--border-color);
        }

        .sidebar-backdrop {
            position: fixed;
            inset: 70px 0 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(2px);
            z-index: 999;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
        }

        .sidebar-backdrop.show {
            opacity: 1;
            pointer-events: auto;
        }

        .profile-section {
            text-align: center;
            padding: 0 20px 25px;
            border-bottom: 1px solid var(--border-color);
        }

        .profile-avatar {
            width: 120px;
            height: 120px;
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
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .profile-email {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 15px;
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

        .nav-menu {
            padding: 20px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: 12px;
            transition: 0.3s;
            margin-bottom: 5px;
            cursor: pointer;
            border: none;
            width: 100%;
            background: none;
            font-size: 0.95rem;
            text-align: left;
            font-weight: 600;
        }

        .nav-item:hover {
            background: var(--primary-soft);
            color: var(--primary);
            transform: translateX(4px);
        }

        .nav-item.active {
            background: linear-gradient(120deg, var(--primary-dark) 0%, var(--primary) 100%);
            color: white;
            box-shadow: 0 10px 22px rgba(15, 118, 110, 0.24);
        }

        .nav-item i {
            width: 22px;
            font-size: 1.2rem;
        }

        .nav-item.mobile-logout-item {
            display: none;
        }

        /* Content Area */
        .content-area {
            flex: 1;
            margin-left: 280px;
            padding: 34px;
            max-width: calc(100vw - 280px);
        }

        /* Welcome Banner */
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

        .welcome-banner::after {
            content: '';
            position: absolute;
            width: 220px;
            height: 220px;
            top: -100px;
            right: -80px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.12);
        }

        .welcome-banner h1 {
            font-size: 2rem;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .welcome-banner p {
            opacity: 0.95;
            font-size: 1.1rem;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 25px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 25px;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: 0.3s;
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg, var(--primary-light) 0%, var(--primary-dark) 100%);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
        }

        .stat-icon.primary {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .stat-icon.success {
            background: var(--success-soft);
            color: var(--success);
        }

        .stat-icon.warning {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .stat-icon.info {
            background: var(--info-soft);
            color: var(--info);
        }

        .stat-details h3 {
            font-size: 2rem;
            margin-bottom: 5px;
            color: var(--text-primary);
        }

        .stat-details p {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        /* Tab Content */
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Section Cards */
        .section-card {
            background: var(--card-bg);
            border-radius: 22px;
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

        /* Add Buttons */
        .add-btn {
            background: var(--primary);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 40px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 15px var(--primary-glow);
        }

        .add-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px var(--primary-glow);
        }

        .add-btn i {
            font-size: 1rem;
        }

        /* Modal */
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
            max-height: 85vh;
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
            padding: 25px 30px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--primary-soft) 0%, transparent 100%);
            border-radius: 24px 24px 0 0;
        }

        .modal-header h3 {
            color: var(--text-primary);
            font-size: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-header h3 i {
            color: var(--primary);
            font-size: 1.8rem;
        }

        .close {
            width: 40px;
            height: 40px;
            background: var(--danger-soft);
            color: var(--danger);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
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
            padding: 30px;
        }

        .modal-footer {
            padding: 20px 30px 30px;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            border-top: 1px solid var(--border-color);
        }

        /* Filter Bar */
        .filter-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .filter-select {
            padding: 10px 20px;
            border: 2px solid var(--border-color);
            border-radius: 30px;
            background: var(--input-bg);
            color: var(--text-primary);
            font-size: 0.95rem;
            cursor: pointer;
            min-width: 150px;
        }

        .search-input {
            flex: 1;
            padding: 10px 20px;
            border: 2px solid var(--border-color);
            border-radius: 30px;
            background: var(--input-bg);
            color: var(--text-primary);
            font-size: 0.95rem;
            min-width: 250px;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
        }

        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group.full-width {
            grid-column: span 2;
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

        .form-group label .required {
            color: var(--danger);
            margin-left: 4px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper input,
        .input-wrapper select,
        .input-wrapper textarea {
            width: 100%;
            padding: 14px 15px;
            border: 2px solid var(--input-border);
            border-radius: 14px;
            font-size: 0.95rem;
            transition: 0.3s;
            background: var(--input-bg);
            color: var(--text-primary);
            font-weight: 500;
        }

        .input-wrapper textarea {
            resize: vertical;
            min-height: 100px;
        }

        .input-wrapper input:focus,
        .input-wrapper select:focus,
        .input-wrapper textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--primary-soft);
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            width: 100%;
            padding: 14px 45px 14px 15px;
            border: 2px solid var(--input-border);
            border-radius: 14px;
            font-size: 0.95rem;
            transition: 0.3s;
            background: var(--input-bg);
            color: var(--text-primary);
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            cursor: pointer;
            font-size: 1.2rem;
            transition: 0.3s;
        }

        .password-toggle:hover {
            color: var(--primary);
        }

        /* Buttons */
        .btn {
            padding: 14px 30px;
            border: none;
            border-radius: 40px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            letter-spacing: 0.01em;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            box-shadow: 0 4px 15px var(--primary-glow);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px var(--primary-glow);
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--border-color);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        /* Tables */
        .table-responsive {
            overflow-x: auto;
            border-radius: 15px;
            border: 1px solid var(--border-color);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card-bg);
        }

        th {
            text-align: left;
            padding: 15px 15px;
            background: color-mix(in srgb, var(--primary-soft) 75%, var(--bg-primary));
            color: var(--primary-dark);
            font-weight: 600;
            font-size: 0.95rem;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-secondary);
        }

        tbody tr:nth-child(even) {
            background: color-mix(in srgb, var(--primary-soft) 16%, transparent);
        }

        tbody tr:hover {
            background: color-mix(in srgb, var(--primary-soft) 28%, transparent);
        }

        .status-badge {
            padding: 6px 15px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-active {
            background: var(--success-soft);
            color: var(--success);
        }

        .status-inactive {
            background: var(--danger-soft);
            color: var(--danger);
        }

        .status-pending {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .role-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .role-badge.super_admin {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .role-badge.sub_admin {
            background: var(--info-soft);
            color: var(--info);
        }

        .role-badge.dean {
            background: var(--success-soft);
            color: var(--success);
        }

        .role-badge.student {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .role-badge.office_staff {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .action-btns {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            width: 35px;
            height: 35px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
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
            background: var(--info-soft);
            color: var(--info);
        }

        .action-btn.view {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .action-btn:hover {
            transform: translateY(-2px);
        }

        .action-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* Conditional fields */
        .conditional-field {
            display: none;
        }

        .conditional-field.show {
            display: block;
        }

        /* Toast Notification */
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

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
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

        /* Settings Card */
        .settings-card {
            margin-top: 30px;
            background: var(--card-bg);
            border-radius: 20px;
            padding: 25px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border-color);
        }

        .settings-card h3 {
            color: var(--text-primary);
            font-size: 1.2rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .settings-card h3 i {
            color: var(--primary);
        }

        .settings-option {
            padding: 15px;
            background: var(--bg-secondary);
            border-radius: 12px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .settings-option:last-child {
            margin-bottom: 0;
        }

        .settings-option .info h4 {
            color: var(--text-primary);
            font-size: 1rem;
            margin-bottom: 5px;
        }

        .settings-option .info p {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .settings-option .toggle-switch {
            width: 50px;
            height: 26px;
            background: var(--border-color);
            border-radius: 30px;
            position: relative;
            cursor: pointer;
            transition: 0.3s;
        }

        .settings-option .toggle-switch.active {
            background: var(--success);
        }

        .settings-option .toggle-slider {
            width: 22px;
            height: 22px;
            background: white;
            border-radius: 50%;
            position: absolute;
            top: 2px;
            left: 2px;
            transition: 0.3s;
        }

        .settings-option .toggle-switch.active .toggle-slider {
            left: 26px;
        }

        /* Report Charts */
        .report-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
            margin-bottom: 25px;
        }

        .report-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 20px;
            border: 1px solid var(--border-color);
            box-shadow: var(--card-shadow);
        }

        .report-card h3 {
            color: var(--text-primary);
            font-size: 1.1rem;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .report-card h3 i {
            color: var(--primary);
        }

        .report-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .report-stat-item {
            text-align: center;
            padding: 15px;
            background: var(--bg-secondary);
            border-radius: 15px;
        }

        .report-stat-item .value {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .report-stat-item .label {
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        .chart-container {
            height: 300px;
            position: relative;
        }

        /* Activity Log */
        .activity-log {
            max-height: 400px;
            overflow-y: auto;
        }

        .activity-log::-webkit-scrollbar,
        .sidebar::-webkit-scrollbar {
            width: 10px;
        }

        .activity-log::-webkit-scrollbar-thumb,
        .sidebar::-webkit-scrollbar-thumb {
            background: color-mix(in srgb, var(--primary) 42%, transparent);
            border-radius: 999px;
        }

        .activity-log::-webkit-scrollbar-track,
        .sidebar::-webkit-scrollbar-track {
            background: transparent;
        }

        .activity-item {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            background: var(--primary-soft);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }

        .activity-details {
            flex: 1;
        }

        .activity-action {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 3px;
        }

        .activity-meta {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .activity-time {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .report-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .header {
                padding: 0.85rem 1rem;
            }

            .logo h2 {
                font-size: 1rem;
            }

            .mobile-menu-btn {
                display: inline-flex;
                margin-right: 8px;
            }

            .user-menu {
                gap: 10px;
            }

            .user-info {
                padding: 6px;
                border-radius: 14px;
                background: rgba(255, 255, 255, 0.12);
            }

            .user-details {
                display: none;
            }

            .logout-btn {
                display: none;
            }

            .nav-item.mobile-logout-item {
                display: flex;
                margin-top: 8px;
                background: var(--danger-soft);
                color: var(--danger);
            }

            .nav-item.mobile-logout-item:hover {
                background: var(--danger);
                color: #fff;
            }

            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                z-index: 1001;
                transition: 0.3s;
                top: 70px;
                height: calc(100vh - 70px);
                box-shadow: 14px 0 30px rgba(2, 8, 23, 0.18);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .content-area {
                margin-left: 0;
                max-width: 100%;
                padding: 22px 16px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full-width {
                grid-column: span 1;
            }

            .modal-footer {
                flex-direction: column;
            }

            .filter-bar {
                flex-direction: column;
            }

            .filter-bar select,
            .filter-bar input {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Dark Mode Toggle -->
    <div class="theme-toggle" id="themeToggle">
        <i class="fas fa-moon" id="themeIcon"></i>
    </div>

    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <div class="logo">
                <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Toggle navigation" type="button">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="logo-icon">
                    <img src="../assets/img/logo.png" alt="BISU Logo">
                </div>
                <h2>Super Admin Dashboard</h2>
            </div>
            <div class="user-menu">
                <div class="user-info">
                        <div class="user-avatar">
                        <?php if ($profile_pic && file_exists(__DIR__ . '/../' . ltrim($profile_pic, '/\\'))): ?>
                            <img src="../<?php echo ltrim($profile_pic, '/\\') . '?t=' . time(); ?>" alt="Profile">
                        <?php else: ?>
                                <i class="fas fa-user-shield"></i>
                        <?php endif; ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?php echo htmlspecialchars($admin_name); ?></div>
                        <div class="user-role">Super Administrator</div>
                    </div>
                </div>
                <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="profile-section">
                <div class="profile-avatar" id="avatarContainer">
                    <?php if ($profile_pic && file_exists(__DIR__ . '/../' . ltrim($profile_pic, '/\\'))): ?>
                        <img src="../<?php echo ltrim($profile_pic, '/\\') . '?t=' . time(); ?>" alt="Profile" id="profileImage">
                    <?php else: ?>
                            <i class="fas fa-user-shield" id="avatarIcon" style="font-size: 3rem; line-height: 120px;"></i>
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

                <div class="profile-name"><?php echo htmlspecialchars($admin_name); ?></div>
                <div class="profile-email"><?php echo htmlspecialchars($admin_email); ?></div>
                <div class="profile-badge">Super Admin</div>
            </div>

            <nav class="nav-menu">
                <button class="nav-item <?php echo $active_tab == 'dashboard' ? 'active' : ''; ?>"
                    onclick="switchTab('dashboard', this)">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </button>
                <button class="nav-item <?php echo $active_tab == 'users' ? 'active' : ''; ?>"
                    onclick="switchTab('users', this)">
                    <i class="fas fa-users"></i> Manage Users
                </button>
                <button class="nav-item <?php echo $active_tab == 'colleges' ? 'active' : ''; ?>"
                    onclick="switchTab('colleges', this)">
                    <i class="fas fa-university"></i> Manage Colleges
                </button>
                <button class="nav-item <?php echo $active_tab == 'courses' ? 'active' : ''; ?>"
                    onclick="switchTab('courses', this)">
                    <i class="fas fa-book"></i> Manage Courses
                </button>
                <button class="nav-item <?php echo $active_tab == 'offices' ? 'active' : ''; ?>"
                    onclick="switchTab('offices', this)">
                    <i class="fas fa-building"></i> Manage Offices
                </button>
                <button class="nav-item <?php echo $active_tab == 'reports' ? 'active' : ''; ?>"
                    onclick="switchTab('reports', this)">
                    <i class="fas fa-chart-bar"></i> Reports
                </button>
                <a href="../logout.php" class="nav-item mobile-logout-item">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </nav>
        </aside>

        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <!-- Content Area -->
        <main class="content-area">
            <!-- Alert Messages -->
            <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <span><?php echo $success; ?></span>
                    </div>
            <?php endif; ?>

            <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo $error; ?></span>
                    </div>
            <?php endif; ?>

            <!-- Dashboard Tab -->
            <div id="dashboard" class="tab-content <?php echo $active_tab == 'dashboard' ? 'active' : ''; ?>">
                <!-- Welcome Banner -->
                <div class="welcome-banner">
                    <h1>Welcome back, <?php echo htmlspecialchars(explode(' ', $admin_name)[0]); ?>! 👋</h1>
                    <p>Manage the entire clearance system from here. You have full administrative access.</p>
                </div>

                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon primary">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $stats['total_users']; ?></h3>
                            <p>Total Users</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon success">
                            <i class="fas fa-university"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $stats['total_colleges']; ?></h3>
                            <p>Colleges</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon warning">
                            <i class="fas fa-book"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $stats['total_courses']; ?></h3>
                            <p>Courses</p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon info">
                            <i class="fas fa-building"></i>
                        </div>
                        <div class="stat-details">
                            <h3><?php echo $stats['total_offices']; ?></h3>
                            <p>Offices</p>
                        </div>
                    </div>
                </div>

                <!-- Users by Role -->
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-chart-pie"></i> Users by Role</h2>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Role</th>
                                    <th>Count</th>
                                    <th>Percentage</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['users_by_role'] as $role): ?>
                                        <tr>
                                            <td>
                                                <span class="role-badge <?php echo getRoleBadgeClass($role['user_role_name']); ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $role['user_role_name'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo $role['count']; ?></td>
                                            <td>
                                                <?php
                                                $percentage = $stats['total_users'] > 0 ? round(($role['count'] / $stats['total_users']) * 100, 1) : 0;
                                                echo $percentage . '%';
                                                ?>
                                            </td>
                                        </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Users -->
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-user-plus"></i> Recently Registered Users</h2>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Registered</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['recent_users'] as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?></td>
                                            <td><?php echo htmlspecialchars($user['emails']); ?></td>
                                            <td>
                                                <span class="role-badge <?php echo getRoleBadgeClass($user['user_role_name']); ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $user['user_role_name'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span
                                                    class="status-badge status-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                        </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-history"></i> Recent Activity</h2>
                    </div>
                    <div class="activity-log">
                        <?php foreach ($stats['recent_activities'] as $activity): ?>
                                <div class="activity-item">
                                    <div class="activity-icon">
                                        <i class="fas fa-<?php
                                        echo $activity['action'] == 'LOGIN' ? 'sign-in-alt' :
                                            ($activity['action'] == 'LOGOUT' ? 'sign-out-alt' :
                                                ($activity['action'] == 'ADD_USER' ? 'user-plus' : 'circle'));
                                        ?>"></i>
                                    </div>
                                    <div class="activity-details">
                                        <div class="activity-action"><?php echo $activity['action']; ?></div>
                                        <div class="activity-meta"><?php echo htmlspecialchars($activity['description']); ?></div>
                                        <div class="activity-meta">By: <?php echo htmlspecialchars($activity['user_name'] ?? 'System'); ?></div>
                                    </div>
                                    <div class="activity-time"><?php echo date('M d, h:i A', strtotime($activity['created_at'])); ?></div>
                                </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Users Management Tab -->
            <div id="users" class="tab-content <?php echo $active_tab == 'users' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-users"></i> User Management</h2>
                        <button class="add-btn" onclick="openUserModal('add')">
                            <i class="fas fa-user-plus"></i> Add New User
                        </button>
                    </div>

                    <!-- Filter Bar -->
                    <div class="filter-bar">
                        <select class="filter-select" id="roleFilter">
                            <option value="">All Roles</option>
                            <option value="super_admin">Super Admin</option>
                            <option value="sub_admin">Sub Admin</option>
                            <option value="dean">Dean</option>
                            <option value="office_staff">Office Staff</option>
                            <option value="student">Student</option>
                        </select>
                        <select class="filter-select" id="statusFilter">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <input type="text" class="search-input" id="userSearch" placeholder="Search users by name or email...">
                    </div>

                    <!-- Users List -->
                    <div class="table-responsive">
                        <table id="usersTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Assignment</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['all_users'] as $user): ?>
                                        <tr>
                                            <td><?php echo $user['users_id']; ?></td>
                                            <td><?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?></td>
                                            <td><?php echo htmlspecialchars($user['emails']); ?></td>
                                            <td>
                                                <span class="role-badge <?php echo getRoleBadgeClass($user['user_role_name']); ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $user['user_role_name'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                if ($user['user_role_name'] == 'sub_admin' && $user['office_name']) {
                                                    echo '<i class="fas fa-building"></i> ' . htmlspecialchars($user['office_name']);
                                                } elseif ($user['user_role_name'] == 'dean' && $user['college_name']) {
                                                    echo '<i class="fas fa-university"></i> ' . htmlspecialchars($user['college_name']);
                                                } elseif ($user['user_role_name'] == 'student') {
                                                    if (!empty($user['college_name'])) {
                                                        echo '<i class="fas fa-graduation-cap"></i> ' . htmlspecialchars($user['college_name']);
                                                    } else {
                                                        echo '<i class="fas fa-graduation-cap"></i> No college assigned';
                                                    }
                                                } else {
                                                    echo '—';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $user['is_active'] ? 'active' : 'inactive'; ?>">
                                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-btns">
                                                    <form method="POST" action="" style="display: inline;">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['users_id']; ?>">
                                                        <input type="hidden" name="current_status" value="<?php echo $user['is_active']; ?>">
                                                        <button type="submit" name="toggle_user_status" class="action-btn toggle"
                                                            <?php echo ($user['users_id'] == $admin_id) ? 'disabled' : ''; ?>
                                                            title="<?php echo ($user['users_id'] == $admin_id) ? 'Cannot modify yourself' : 'Toggle Status'; ?>">
                                                            <i class="fas fa-power-off"></i>
                                                        </button>
                                                    </form>
                                                    <button class="action-btn edit" onclick="editUser(<?php echo htmlspecialchars(json_encode([
                                                        'id' => $user['users_id'],
                                                        'fname' => $user['fname'],
                                                        'lname' => $user['lname'],
                                                        'email' => $user['emails'],
                                                        'role' => $user['user_role_name'],
                                                        'ismis' => $user['ismis_id'] ?? '',
                                                        'contact' => $user['contacts'] ?? '',
                                                        'address' => $user['address'] ?? '',
                                                        'age' => $user['age'] ?? '',
                                                        'is_active' => (int) $user['is_active'],
                                                        'office' => $user['office_id'] ?? '',
                                                        'college' => $user['college_id'] ?? ''
                                                    ]), ENT_QUOTES, 'UTF-8'); ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" name="delete_user" class="action-btn delete"
                                                        onclick="deleteUser(<?php echo (int) $user['users_id']; ?>, <?php echo ($user['users_id'] == $admin_id) ? 'true' : 'false'; ?>)"
                                                        <?php echo ($user['users_id'] == $admin_id) ? 'disabled' : ''; ?>>
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Colleges Management Tab -->
            <div id="colleges" class="tab-content <?php echo $active_tab == 'colleges' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-university"></i> Colleges Management</h2>
                        <button class="add-btn" onclick="openAddCollegeModal()">
                            <i class="fas fa-plus"></i> Add College
                        </button>
                    </div>

                    <!-- Colleges List -->
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>College Name</th>
                                    <th>Courses</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                foreach ($stats['colleges'] as $college):
                                    // Count courses per college
                                    $db->query("SELECT COUNT(*) as course_count FROM course WHERE college_id = :id");
                                    $db->bind(':id', $college['college_id']);
                                    $result = $db->single();
                                    $course_count = $result['course_count'] ?? 0;
                                    ?>
                                        <tr>
                                            <td><?php echo $college['college_id']; ?></td>
                                            <td><?php echo htmlspecialchars($college['college_name']); ?></td>
                                            <td><?php echo $course_count; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($college['created_at'])); ?></td>
                                            <td>
                                                <div class="action-btns">
                                                    <button class="action-btn edit"
                                                        onclick="editCollege(<?php echo $college['college_id']; ?>, '<?php echo htmlspecialchars($college['college_name']); ?>')">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="action-btn delete"
                                                        onclick="deleteCollege(<?php echo $college['college_id']; ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Add College Modal -->
            <div id="addCollegeModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><i class="fas fa-plus-circle"></i> Add New College</h3>
                        <span class="close" onclick="closeAddCollegeModal()">&times;</span>
                    </div>
                    <form method="POST" action="">
                        <div class="modal-body">
                            <div class="form-group">
                                <label><i class="fas fa-university"></i> College Name *</label>
                                <div class="input-wrapper">
                                    <input type="text" name="college_name" placeholder="e.g., College of Sciences" required>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeAddCollegeModal()">Cancel</button>
                            <button type="submit" name="add_college" class="btn btn-primary">Add College</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit College Modal -->
            <div id="editCollegeModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><i class="fas fa-edit"></i> Edit College</h3>
                        <span class="close" onclick="closeEditCollegeModal()">&times;</span>
                    </div>
                    <form method="POST" action="">
                        <div class="modal-body">
                            <input type="hidden" name="college_id" id="edit_college_id">
                            <div class="form-group">
                                <label><i class="fas fa-university"></i> College Name *</label>
                                <div class="input-wrapper">
                                    <input type="text" name="college_name" id="edit_college_name" placeholder="Enter college name" required>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeEditCollegeModal()">Cancel</button>
                            <button type="submit" name="edit_college" class="btn btn-primary">Update College</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Courses Management Tab -->
            <div id="courses" class="tab-content <?php echo $active_tab == 'courses' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-book"></i> Courses Management</h2>
                        <button class="add-btn" onclick="openAddCourseModal()">
                            <i class="fas fa-plus"></i> Add Course
                        </button>
                    </div>

                    <!-- Courses List -->
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Course Code</th>
                                    <th>Course Name</th>
                                    <th>College</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['courses'] as $course): ?>
                                        <tr>
                                            <td><?php echo $course['course_id']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($course['course_code']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($course['course_name']); ?></td>
                                            <td><?php echo htmlspecialchars($course['college_name']); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($course['created_at'])); ?></td>
                                            <td>
                                                <div class="action-btns">
                                                    <button class="action-btn edit"
                                                        onclick="editCourse(<?php echo $course['course_id']; ?>, '<?php echo htmlspecialchars($course['course_name']); ?>', '<?php echo htmlspecialchars($course['course_code']); ?>', <?php echo $course['college_id']; ?>)">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="action-btn delete"
                                                        onclick="deleteCourse(<?php echo $course['course_id']; ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Add Course Modal -->
            <div id="addCourseModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><i class="fas fa-plus-circle"></i> Add New Course</h3>
                        <span class="close" onclick="closeAddCourseModal()">&times;</span>
                    </div>
                    <form method="POST" action="">
                        <div class="modal-body">
                            <div class="form-group">
                                <label><i class="fas fa-book"></i> Course Name *</label>
                                <div class="input-wrapper">
                                    <input type="text" name="course_name" placeholder="e.g., Computer Science" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-code"></i> Course Code *</label>
                                <div class="input-wrapper">
                                    <input type="text" name="course_code" placeholder="e.g., BSCS" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-university"></i> College *</label>
                                <div class="input-wrapper">
                                    <select name="college_id" required>
                                        <option value="">Select College</option>
                                        <?php foreach ($stats['colleges'] as $college): ?>
                                                <option value="<?php echo $college['college_id']; ?>">
                                                    <?php echo htmlspecialchars($college['college_name']); ?>
                                                </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeAddCourseModal()">Cancel</button>
                            <button type="submit" name="add_course" class="btn btn-primary">Add Course</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit Course Modal -->
            <div id="editCourseModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><i class="fas fa-edit"></i> Edit Course</h3>
                        <span class="close" onclick="closeEditCourseModal()">&times;</span>
                    </div>
                    <form method="POST" action="">
                        <div class="modal-body">
                            <input type="hidden" name="course_id" id="edit_course_id">
                            <div class="form-group">
                                <label><i class="fas fa-book"></i> Course Name *</label>
                                <div class="input-wrapper">
                                    <input type="text" name="course_name" id="edit_course_name" placeholder="Enter course name" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-code"></i> Course Code *</label>
                                <div class="input-wrapper">
                                    <input type="text" name="course_code" id="edit_course_code" placeholder="Enter course code" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-university"></i> College *</label>
                                <div class="input-wrapper">
                                    <select name="college_id" id="edit_course_college" required>
                                        <option value="">Select College</option>
                                        <?php foreach ($stats['colleges'] as $college): ?>
                                                <option value="<?php echo $college['college_id']; ?>">
                                                    <?php echo htmlspecialchars($college['college_name']); ?>
                                                </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeEditCourseModal()">Cancel</button>
                            <button type="submit" name="edit_course" class="btn btn-primary">Update Course</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Offices Management Tab -->
            <div id="offices" class="tab-content <?php echo $active_tab == 'offices' ? 'active' : ''; ?>">
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-building"></i> Offices Management</h2>
                        <button class="add-btn" onclick="openAddOfficeModal()">
                            <i class="fas fa-plus"></i> Add Office
                        </button>
                    </div>

                    <!-- Offices List -->
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Office Name</th>
                                    <th>Description</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['offices'] as $office): ?>
                                        <tr>
                                            <td><?php echo $office['office_id']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($office['office_name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($office['office_description'] ?? '—'); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($office['created_at'])); ?></td>
                                            <td>
                                                <div class="action-btns">
                                                    <button class="action-btn edit"
                                                        onclick="editOffice(<?php echo $office['office_id']; ?>, '<?php echo htmlspecialchars($office['office_name']); ?>', '<?php echo htmlspecialchars($office['office_description'] ?? ''); ?>')">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="action-btn delete"
                                                        onclick="deleteOffice(<?php echo $office['office_id']; ?>)">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Add Office Modal -->
            <div id="addOfficeModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><i class="fas fa-plus-circle"></i> Add New Office</h3>
                        <span class="close" onclick="closeAddOfficeModal()">&times;</span>
                    </div>
                    <form method="POST" action="">
                        <div class="modal-body">
                            <div class="form-group">
                                <label><i class="fas fa-building"></i> Office Name *</label>
                                <div class="input-wrapper">
                                    <input type="text" name="office_name" placeholder="e.g., Librarian, Cashier" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-align-left"></i> Description</label>
                                <div class="input-wrapper">
                                    <textarea name="office_description" rows="3" placeholder="Office description..."></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeAddOfficeModal()">Cancel</button>
                            <button type="submit" name="add_office" class="btn btn-primary">Add Office</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Edit Office Modal -->
            <div id="editOfficeModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3><i class="fas fa-edit"></i> Edit Office</h3>
                        <span class="close" onclick="closeEditOfficeModal()">&times;</span>
                    </div>
                    <form method="POST" action="">
                        <div class="modal-body">
                            <input type="hidden" name="office_id" id="edit_office_id">
                            <div class="form-group">
                                <label><i class="fas fa-building"></i> Office Name *</label>
                                <div class="input-wrapper">
                                    <input type="text" name="office_name" id="edit_office_name" placeholder="Enter office name" required>
                                </div>
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-align-left"></i> Description</label>
                                <div class="input-wrapper">
                                    <textarea name="office_description" id="edit_office_description" rows="3" placeholder="Office description..."></textarea>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="closeEditOfficeModal()">Cancel</button>
                            <button type="submit" name="edit_office" class="btn btn-primary">Update Office</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Reports Tab -->
            <div id="reports" class="tab-content <?php echo $active_tab == 'reports' ? 'active' : ''; ?>">
                <!-- Report Stats -->
                <div class="report-grid">
                    <div class="report-card">
                        <h3><i class="fas fa-file-alt"></i> Clearance Overview</h3>
                        <div class="report-stats">
                            <div class="report-stat-item">
                                <div class="value"><?php echo $stats['pending_clearances']; ?></div>
                                <div class="label">Pending</div>
                            </div>
                            <div class="report-stat-item">
                                <div class="value"><?php echo $stats['approved_clearances']; ?></div>
                                <div class="label">Approved</div>
                            </div>
                            <div class="report-stat-item">
                                <div class="value"><?php echo $stats['rejected_clearances']; ?></div>
                                <div class="label">Rejected</div>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="clearanceChart"></canvas>
                        </div>
                    </div>

                    <div class="report-card">
                        <h3><i class="fas fa-users"></i> User Registration Trends (Last 30 Days)</h3>
                        <div class="chart-container">
                            <canvas id="userTrendsChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Clearance by Office -->
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-building"></i> Clearance Status by Office</h2>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Office</th>
                                    <th>Total</th>
                                    <th>Pending</th>
                                    <th>Approved</th>
                                    <th>Rejected</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['clearance_by_office'] as $office): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($office['office_name']); ?></td>
                                            <td><?php echo $office['total']; ?></td>
                                            <td><span class="status-badge status-pending"><?php echo $office['pending']; ?></span></td>
                                            <td><span class="status-badge status-active"><?php echo $office['approved']; ?></span></td>
                                            <td><span class="status-badge status-inactive"><?php echo $office['rejected']; ?></span></td>
                                        </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Top Users -->
                <div class="section-card">
                    <div class="section-header">
                        <h2><i class="fas fa-trophy"></i> Top 10 Most Active Students</h2>
                    </div>
                    <div class="table-responsive">
                        <table>
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Student Name</th>
                                    <th>Email</th>
                                    <th>Clearance Applications</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $rank = 1;
                                foreach ($stats['top_users'] as $user):
                                    ?>
                                        <tr>
                                            <td><strong>#<?php echo $rank++; ?></strong></td>
                                            <td><?php echo htmlspecialchars($user['fname'] . ' ' . $user['lname']); ?></td>
                                            <td><?php echo htmlspecialchars($user['emails']); ?></td>
                                            <td><?php echo $user['clearance_count']; ?></td>
                                        </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Add/Edit User Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content large">
            <div class="modal-header">
                <h3 id="modalTitle">
                    <i class="fas fa-user-plus"></i>
                    <span id="modalTitleText">Create New User Account</span>
                </h3>
                <button class="close" onclick="closeUserModal()">&times;</button>
            </div>
            <form method="POST" action="" id="userForm">
                <div class="modal-body">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> First Name <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <input type="text" name="fname" id="edit_fname" placeholder="Enter first name" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Last Name <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <input type="text" name="lname" id="edit_lname" placeholder="Enter last name" required>
                            </div>
                        </div>
                        <div class="form-group full-width">
                            <label><i class="fas fa-envelope"></i> Email <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <input type="email" name="email" id="edit_email" placeholder="Enter email address" required>
                            </div>
                        </div>
                        <div class="form-group full-width" id="passwordField">
                            <label><i class="fas fa-lock"></i> Password <span class="required">*</span></label>
                            <div class="password-wrapper">
                                <input type="password" name="password" id="userPassword" placeholder="Enter password (min. 8 chars)" <?php echo !isset($_GET['edit']) ? 'required' : ''; ?>>
                                <i class="fas fa-eye password-toggle" onclick="togglePassword('userPassword', this)"></i>
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-id-card"></i> ISMIS ID</label>
                            <div class="input-wrapper">
                                <input type="text" name="ismis_id" id="edit_ismis" placeholder="Enter ISMIS ID (optional)">
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Contact Number</label>
                            <div class="input-wrapper">
                                <input type="text" name="contact" id="edit_contact" placeholder="Enter contact number (optional)">
                            </div>
                        </div>
                        <div class="form-group full-width">
                            <label><i class="fas fa-map-marker-alt"></i> Address</label>
                            <div class="input-wrapper">
                                <input type="text" name="address" id="edit_address" placeholder="Enter address (optional)">
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-calendar-alt"></i> Age</label>
                            <div class="input-wrapper">
                                <input type="number" name="age" id="edit_age" placeholder="Enter age (optional)" min="15" max="100">
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user-tag"></i> Role <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <select name="user_role" id="modal_user_role" required onchange="toggleModalRoleFields()">
                                    <option value="">Select Role</option>
                                    <?php foreach ($stats['roles'] as $role): ?>
                                            <option value="<?php echo $role['user_role_name']; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $role['user_role_name'])); ?>
                                            </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Office field (for sub_admin) -->
                        <div class="form-group conditional-field" id="modal_office_field">
                            <label><i class="fas fa-building"></i> Office <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <select name="office_id" id="edit_office">
                                    <option value="">Select Office</option>
                                    <?php foreach ($stats['offices'] as $office): ?>
                                            <option value="<?php echo $office['office_id']; ?>">
                                                <?php echo htmlspecialchars($office['office_name']); ?>
                                            </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- College field (for dean) -->
                        <div class="form-group conditional-field" id="modal_college_field">
                            <label><i class="fas fa-university"></i> College <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <select name="college_id" id="edit_college">
                                    <option value="">Select College</option>
                                    <?php foreach ($stats['colleges'] as $college): ?>
                                            <option value="<?php echo $college['college_id']; ?>">
                                                <?php echo htmlspecialchars($college['college_name']); ?>
                                            </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Status field (for edit mode) -->
                        <div class="form-group conditional-field" id="modal_status_field">
                            <label><i class="fas fa-toggle-on"></i> Status</label>
                            <div class="input-wrapper">
                                <select name="is_active" id="edit_status">
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeUserModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" name="add_user" id="modalSubmitBtn" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Account
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Dark Mode Toggle
        const themeToggle = document.getElementById('themeToggle');
        const themeIcon = document.getElementById('themeIcon');
        const body = document.body;

        // Check for saved theme
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
        function switchTab(tabName, triggerEl = null) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.getElementById(tabName).classList.add('active');

            document.querySelectorAll('.nav-item').forEach(item => {
                item.classList.remove('active');
            });

            const targetItem = triggerEl || document.querySelector(`.nav-item[onclick*=\"${tabName}\"]`);
            if (targetItem) {
                targetItem.classList.add('active');
            }

            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);

            closeMobileSidebar();
            
            // Initialize charts when switching to reports tab
            if (tabName === 'reports') {
                setTimeout(initCharts, 100);
            }
        }

        // Mobile sidebar controls
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.querySelector('.sidebar');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');

        function openMobileSidebar() {
            if (!sidebar || window.innerWidth > 768) return;
            sidebar.classList.add('show');
            if (sidebarBackdrop) sidebarBackdrop.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeMobileSidebar() {
            if (!sidebar) return;
            sidebar.classList.remove('show');
            if (sidebarBackdrop) sidebarBackdrop.classList.remove('show');
            document.body.style.overflow = '';
        }

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', () => {
                if (sidebar.classList.contains('show')) {
                    closeMobileSidebar();
                } else {
                    openMobileSidebar();
                }
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

        // College Modal Functions
        function openAddCollegeModal() {
            document.getElementById('addCollegeModal').style.display = 'flex';
        }

        function closeAddCollegeModal() {
            document.getElementById('addCollegeModal').style.display = 'none';
        }

        function editCollege(collegeId, collegeName) {
            document.getElementById('edit_college_id').value = collegeId;
            document.getElementById('edit_college_name').value = collegeName;
            document.getElementById('editCollegeModal').style.display = 'flex';
        }

        function closeEditCollegeModal() {
            document.getElementById('editCollegeModal').style.display = 'none';
        }

        function deleteCollege(collegeId) {
            if (confirm('Are you sure you want to delete this college? All associated courses will also be deleted.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'college_id';
                input.value = collegeId;
                form.appendChild(input);
                
                const submitInput = document.createElement('input');
                submitInput.type = 'hidden';
                submitInput.name = 'delete_college';
                submitInput.value = '1';
                form.appendChild(submitInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Course Modal Functions
        function openAddCourseModal() {
            document.getElementById('addCourseModal').style.display = 'flex';
        }

        function closeAddCourseModal() {
            document.getElementById('addCourseModal').style.display = 'none';
        }

        function editCourse(courseId, courseName, courseCode, collegeId) {
            document.getElementById('edit_course_id').value = courseId;
            document.getElementById('edit_course_name').value = courseName;
            document.getElementById('edit_course_code').value = courseCode;
            document.getElementById('edit_course_college').value = collegeId;
            document.getElementById('editCourseModal').style.display = 'flex';
        }

        function closeEditCourseModal() {
            document.getElementById('editCourseModal').style.display = 'none';
        }

        function deleteCourse(courseId) {
            if (confirm('Are you sure you want to delete this course?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'course_id';
                input.value = courseId;
                form.appendChild(input);
                
                const submitInput = document.createElement('input');
                submitInput.type = 'hidden';
                submitInput.name = 'delete_course';
                submitInput.value = '1';
                form.appendChild(submitInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        // Office Modal Functions
        function openAddOfficeModal() {
            document.getElementById('addOfficeModal').style.display = 'flex';
        }

        function closeAddOfficeModal() {
            document.getElementById('addOfficeModal').style.display = 'none';
        }

        function editOffice(officeId, officeName, officeDescription) {
            document.getElementById('edit_office_id').value = officeId;
            document.getElementById('edit_office_name').value = officeName;
            document.getElementById('edit_office_description').value = officeDescription;
            document.getElementById('editOfficeModal').style.display = 'flex';
        }

        function closeEditOfficeModal() {
            document.getElementById('editOfficeModal').style.display = 'none';
        }

        function deleteOffice(officeId) {
            if (confirm('Are you sure you want to delete this office?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'office_id';
                input.value = officeId;
                form.appendChild(input);
                
                const submitInput = document.createElement('input');
                submitInput.type = 'hidden';
                submitInput.name = 'delete_office';
                submitInput.value = '1';
                form.appendChild(submitInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        }

        // User Modal Functions
        function openUserModal(mode, userData = null) {
            const modal = document.getElementById('userModal');
            const titleText = document.getElementById('modalTitleText');
            const submitBtn = document.getElementById('modalSubmitBtn');
            const passwordField = document.getElementById('passwordField');
            const statusField = document.getElementById('modal_status_field');
            
            if (mode === 'edit' && userData) {
                titleText.textContent = 'Edit User Account';
                submitBtn.textContent = 'Update Account';
                submitBtn.name = 'edit_user';
                passwordField.style.display = 'none';
                statusField.classList.add('show');
                
                // Fill form with user data
                document.getElementById('edit_user_id').value = userData.id;
                document.getElementById('edit_fname').value = userData.fname;
                document.getElementById('edit_lname').value = userData.lname;
                document.getElementById('edit_email').value = userData.email;
                document.getElementById('edit_ismis').value = userData.ismis || '';
                document.getElementById('edit_contact').value = userData.contact || '';
                document.getElementById('edit_address').value = userData.address || '';
                document.getElementById('edit_age').value = userData.age || '';
                document.getElementById('modal_user_role').value = userData.role;
                document.getElementById('edit_status').value = userData.is_active;
                toggleModalRoleFields();

                const officeValue = (userData.office !== undefined && userData.office !== null)
                    ? String(userData.office)
                    : '';
                const collegeValue = (userData.college !== undefined && userData.college !== null)
                    ? String(userData.college)
                    : '';

                document.getElementById('edit_office').value = officeValue === '0' ? '' : officeValue;
                document.getElementById('edit_college').value = collegeValue === '0' ? '' : collegeValue;
            } else {
                titleText.textContent = 'Create New User Account';
                submitBtn.textContent = 'Create Account';
                submitBtn.name = 'add_user';
                passwordField.style.display = 'block';
                statusField.classList.remove('show');
                
                // Reset form
                document.getElementById('userForm').reset();
                document.getElementById('edit_user_id').value = '';
            }
            
            modal.style.display = 'flex';
        }

        function closeUserModal() {
            document.getElementById('userModal').style.display = 'none';
            // Reset form
            document.getElementById('userForm').reset();
            document.getElementById('edit_user_id').value = '';
            // Hide conditional fields
            document.getElementById('modal_office_field').classList.remove('show');
            document.getElementById('modal_college_field').classList.remove('show');
            document.getElementById('modal_status_field').classList.remove('show');
        }

        // Toggle conditional fields in modal
        function toggleModalRoleFields() {
            const role = document.getElementById('modal_user_role').value;
            const officeField = document.getElementById('modal_office_field');
            const collegeField = document.getElementById('modal_college_field');
            const officeInput = document.querySelector('#modal_office_field select[name="office_id"]');
            const collegeInput = document.querySelector('#modal_college_field select[name="college_id"]');

            officeField.classList.remove('show');
            collegeField.classList.remove('show');
            if (officeInput) officeInput.required = false;
            if (collegeInput) collegeInput.required = false;

            if (role === 'sub_admin') {
                officeField.classList.add('show');
                if (officeInput) officeInput.required = true;
            } else if (role === 'dean' || role === 'student') {
                collegeField.classList.add('show');
                if (collegeInput) collegeInput.required = true;
            }
        }

        // Password Toggle
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

        // Close modal when clicking outside
        window.onclick = function (event) {
            const modals = [
                'userModal', 
                'addCollegeModal', 'editCollegeModal',
                'addCourseModal', 'editCourseModal',
                'addOfficeModal', 'editOfficeModal'
            ];
            
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }

        // User filter functions
        document.getElementById('roleFilter').addEventListener('change', filterUsers);
        document.getElementById('statusFilter').addEventListener('change', filterUsers);
        document.getElementById('userSearch').addEventListener('keyup', filterUsers);

        function filterUsers() {
            const roleFilter = document.getElementById('roleFilter').value.toLowerCase();
            const statusFilter = document.getElementById('statusFilter').value.toLowerCase();
            const searchFilter = document.getElementById('userSearch').value.toLowerCase();
            const rows = document.querySelectorAll('#usersTable tbody tr');

            rows.forEach(row => {
                const role = row.cells[3].textContent.toLowerCase().trim();
                const status = row.cells[5].textContent.toLowerCase().trim();
                const name = row.cells[1].textContent.toLowerCase();
                const email = row.cells[2].textContent.toLowerCase();

                const matchesRole = !roleFilter || role.includes(roleFilter.replace('_', ' '));
                const matchesStatus = !statusFilter || status.includes(statusFilter);
                const matchesSearch = !searchFilter ||
                    name.includes(searchFilter) ||
                    email.includes(searchFilter);

                row.style.display = matchesRole && matchesStatus && matchesSearch ? '' : 'none';
            });
        }

        // User action functions
        function editUser(userData) {
            openUserModal('edit', userData);
        }

        function deleteUser(userId, isCurrentAdmin = false) {
            if (isCurrentAdmin) {
                showToast('Cannot delete your own account.', 'error');
                return;
            }

            const confirmed = confirm('Are you sure you want to delete this user? This action cannot be undone.');
            if (!confirmed) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';

            const userIdInput = document.createElement('input');
            userIdInput.type = 'hidden';
            userIdInput.name = 'user_id';
            userIdInput.value = String(userId);
            form.appendChild(userIdInput);

            const submitInput = document.createElement('input');
            submitInput.type = 'hidden';
            submitInput.name = 'delete_user';
            submitInput.value = '1';
            form.appendChild(submitInput);

            document.body.appendChild(form);
            form.submit();
        }

        // Toast notification function
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `toast-notification toast-${type}`;
            toast.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                <span style="margin-left: 10px;">${message}</span>
            `;

            document.body.appendChild(toast);

            setTimeout(() => {
                toast.style.animation = 'slideOutRight 0.3s ease';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Avatar upload functionality
        const avatarInput = document.getElementById('avatarUpload');
        const avatarContainer = document.getElementById('avatarContainer');
        const uploadProgress = document.getElementById('uploadProgress');
        const profileImage = document.getElementById('profileImage');
        const avatarIcon = document.getElementById('avatarIcon');

        avatarContainer.addEventListener('click', function () {
            avatarInput.click();
        });

        avatarInput.addEventListener('change', function (e) {
            const file = e.target.files[0];

            if (!file) return;

            const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
            if (!validTypes.includes(file.type)) {
                showToast('Please select a valid image file (JPG, PNG, GIF)', 'error');
                avatarInput.value = '';
                return;
            }

            if (file.size > 2 * 1024 * 1024) {
                showToast('File size must be less than 2MB', 'error');
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
                        const cleanPath = String(data.filepath || '')
                            .replace(/^(\.\.\/|\.\/)+/, '')
                            .replace(/^\/+/, '');
                        const avatarUrl = '../' + cleanPath + '?t=' + new Date().getTime();

                        if (profileImage) {
                            profileImage.src = avatarUrl;
                            profileImage.style.display = 'block';
                        }
                        if (avatarIcon) {
                            avatarIcon.style.display = 'none';
                        }

                        let headerAvatar = document.querySelector('.user-avatar img');
                        if (!headerAvatar) {
                            const headerAvatarContainer = document.querySelector('.user-avatar');
                            if (headerAvatarContainer) {
                                const headerAvatarIcon = headerAvatarContainer.querySelector('i');
                                if (headerAvatarIcon) {
                                    headerAvatarIcon.remove();
                                }
                                headerAvatar = document.createElement('img');
                                headerAvatar.alt = 'Profile';
                                headerAvatarContainer.appendChild(headerAvatar);
                            }
                        }
                        if (headerAvatar) {
                            headerAvatar.src = avatarUrl;
                        }

                        showToast('Profile picture updated successfully!', 'success');
                    } else {
                        showToast(data.message || 'Upload failed', 'error');
                    }
                })
                .catch(error => {
                    uploadProgress.classList.remove('show');
                    showToast('Upload failed. Please try again.', 'error');
                    console.error('Error:', error);
                });
        });

        // Settings toggles
        document.getElementById('maintenanceToggle')?.addEventListener('click', function () {
            this.classList.toggle('active');
            showToast('Maintenance mode ' + (this.classList.contains('active') ? 'enabled' : 'disabled'));
        });

        document.getElementById('emailToggle')?.addEventListener('click', function () {
            this.classList.toggle('active');
            showToast('Email notifications ' + (this.classList.contains('active') ? 'enabled' : 'disabled'));
        });

        document.getElementById('backupToggle')?.addEventListener('click', function () {
            this.classList.toggle('active');
            showToast('Auto backup ' + (this.classList.contains('active') ? 'enabled' : 'disabled'));
        });

        // Initialize Charts
        function initCharts() {
            // Clearance Chart
            const ctx1 = document.getElementById('clearanceChart')?.getContext('2d');
            if (ctx1) {
                new Chart(ctx1, {
                    type: 'doughnut',
                    data: {
                        labels: ['Pending', 'Approved', 'Rejected'],
                        datasets: [{
                            data: [
                                <?php echo $stats['pending_clearances']; ?>,
                                <?php echo $stats['approved_clearances']; ?>,
                                <?php echo $stats['rejected_clearances']; ?>
                            ],
                            backgroundColor: [
                                '#f59e0b',
                                '#10b981',
                                '#ef4444'
                            ],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom'
                            }
                        }
                    }
                });
            }

            // User Trends Chart
            const ctx2 = document.getElementById('userTrendsChart')?.getContext('2d');
            if (ctx2) {
                const dates = [];
                const counts = [];
                
                <?php foreach ($stats['user_trends'] as $trend): ?>
                        dates.push('<?php echo date('M d', strtotime($trend['date'])); ?>');
                        counts.push(<?php echo $trend['count']; ?>);
                <?php endforeach; ?>

                new Chart(ctx2, {
                    type: 'line',
                    data: {
                        labels: dates,
                        datasets: [{
                            label: 'New Users',
                            data: counts,
                            borderColor: '#412886',
                            backgroundColor: 'rgba(65, 40, 134, 0.1)',
                            tension: 0.4,
                            fill: true
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        }
                    }
                });
            }
        }

        // Auto-hide alerts
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);

        // Initialize charts on page load if reports tab is active
        document.addEventListener('DOMContentLoaded', function() {
            if (window.location.href.includes('tab=reports')) {
                setTimeout(initCharts, 100);
            }
        });
    </script>
</body>
</html>