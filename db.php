<?php
// db.php - Enhanced Database Configuration for BISU Student Online Clearance System
// Database: bisu_db
// Location: Root directory of the application (C:\xampp\htdocs\clearance\db.php)

// =====================================================
// Database Configuration
// =====================================================

// Database credentials
define('DB_HOST', 'localhost');      // Database host
define('DB_USER', 'root');           // Database username
define('DB_PASS', '');               // Database password
define('DB_NAME', 'bisu_db');        // Your database name from the SQL file

// =====================================================
// Application Settings
// =====================================================

// Define base paths - IMPORTANT: Update this to match your installation
define('BASE_PATH', dirname(__FILE__));  // Gets the directory where this file is located
define('BASE_URL', 'http://localhost/clearance/');  // Change this to your actual URL

// Site information
define('SITE_NAME', 'BISU Student Online Clearance System');
define('SITE_VERSION', 'BETA 1.5.0');
define('ASSET_VERSION', '20260422-2');
define('DEBUG_MODE', file_exists(BASE_PATH . '/.env'));

// Web push defaults (override in environment variables when deploying).
define('WEB_PUSH_VAPID_PUBLIC_KEY', '');
define('WEB_PUSH_VAPID_PRIVATE_KEY_PEM', '');
define('WEB_PUSH_SUBJECT', 'mailto:admin@example.com');
define('WEB_PUSH_DEFAULT_CLICK_URL', 'student/dashboard.php?tab=messages');
define('WEB_PUSH_DEFAULT_ICON', 'assets/img/pwa-icon-192.png');
define('WEB_PUSH_DEFAULT_BADGE', 'assets/img/pwa-icon-maskable-192.png');

// Error reporting (turn off in production)
if (DEBUG_MODE) {
    // Development environment
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    // Production environment
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Timezone setting (Philippines)
date_default_timezone_set('Asia/Manila');

// =====================================================
// Database Connection Class with Enhanced Features
// =====================================================

class Database
{
    private $host = DB_HOST;
    private $user = DB_USER;
    private $pass = DB_PASS;
    private $dbname = DB_NAME;

    private $connection;
    private static $instance = null;
    private $stmt;
    private $error;
    private $queryCount = 0;
    private $queries = [];

    /**
     * Constructor - Establish database connection with UTF8MB4 support
     */
    public function __construct()
    {
        $this->connect();
    }

    /**
     * Establish database connection
     */
    private function connect()
    {
        // Set DSN with charset
        $dsn = 'mysql:host=' . $this->host . ';dbname=' . $this->dbname . ';charset=utf8mb4';

        // Set PDO options for optimal performance and security
        $options = array(
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false
        );

        // Add MySQL-specific PDO attributes only when supported by the runtime.
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci";
        }

        if (defined('PDO::MYSQL_ATTR_FOUND_ROWS')) {
            $options[PDO::MYSQL_ATTR_FOUND_ROWS] = true;
        }

        // Create PDO instance
        try {
            $this->connection = new PDO($dsn, $this->user, $this->pass, $options);
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            error_log("Database Connection Error: " . $this->error);

            // User-friendly error message
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                die("Connection failed: " . $this->error);
            } else {
                die("System is currently unavailable. Please try again later.");
            }
        }
    }

    /**
     * Get singleton instance
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    /**
     * Get the database connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Prepare a query with logging capability
     */
    public function query($sql)
    {
        $this->queries[] = $sql;
        $this->queryCount++;
        $this->stmt = $this->connection->prepare($sql);
        return $this->stmt;
    }

    /**
     * Bind values with automatic type detection
     */
    public function bind($param, $value, $type = null)
    {
        if (is_null($type)) {
            switch (true) {
                case is_int($value):
                    $type = PDO::PARAM_INT;
                    break;
                case is_bool($value):
                    $type = PDO::PARAM_BOOL;
                    break;
                case is_null($value):
                    $type = PDO::PARAM_NULL;
                    break;
                default:
                    $type = PDO::PARAM_STR;
            }
        }
        $this->stmt->bindValue($param, $value, $type);
    }

    /**
     * Execute with error logging
     */
    public function execute()
    {
        try {
            return $this->stmt->execute();
        } catch (PDOException $e) {
            error_log("Query Execution Error: " . $e->getMessage());
            error_log("Failed Query: " . ($this->stmt->queryString ?? 'Unknown'));
            return false;
        }
    }

    /**
     * Get result set
     */
    public function resultSet()
    {
        $this->execute();
        return $this->stmt->fetchAll();
    }

    /**
     * Get single record
     */
    public function single()
    {
        $this->execute();
        return $this->stmt->fetch();
    }

    /**
     * Get row count
     */
    public function rowCount()
    {
        return $this->stmt->rowCount();
    }

    /**
     * Get last insert ID
     */
    public function lastInsertId()
    {
        return $this->connection->lastInsertId();
    }

    /**
     * Transaction methods
     */
    public function beginTransaction()
    {
        return $this->connection->beginTransaction();
    }

    public function commit()
    {
        return $this->connection->commit();
    }

    public function rollback()
    {
        return $this->connection->rollback();
    }

    /**
     * Get query count for debugging
     */
    public function getQueryCount()
    {
        return $this->queryCount;
    }

    /**
     * Get all queries for debugging
     */
    public function getQueries()
    {
        return $this->queries;
    }

    /**
     * Close connection
     */
    public function closeConnection()
    {
        $this->connection = null;
    }
}

// =====================================================
// Enhanced Model Classes for BISU Clearance System
// =====================================================

/**
 * Base Model Class
 */
abstract class BaseModel
{
    protected $db;
    protected $table;
    protected $primaryKey = 'id';

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Find by primary key
     */
    public function find($id)
    {
        $this->db->query("SELECT * FROM {$this->table} WHERE {$this->primaryKey} = :id");
        $this->db->bind(':id', $id);
        return $this->db->single();
    }

    /**
     * Get all records
     */
    public function all($orderBy = null)
    {
        $sql = "SELECT * FROM {$this->table}";
        if ($orderBy) {
            $sql .= " ORDER BY " . $orderBy;
        }
        $this->db->query($sql);
        return $this->db->resultSet();
    }
}

/**
 * User Model - Handles user-related database operations
 */
class UserModel extends BaseModel
{
    protected $table = 'users';
    protected $primaryKey = 'users_id';

    /**
     * Get user with role and related data
     */
    public function getUserWithDetails($userId)
    {
        $sql = "SELECT u.*, 
                       ur.user_role_name, 
                       ur.user_description as role_description,
                       o.office_name,
                       o.office_description,
                       c.college_name,
                       cr.course_name,
                       cr.course_code
                FROM users u
                LEFT JOIN user_role ur ON u.user_role_id = ur.user_role_id
                LEFT JOIN offices o ON u.office_id = o.office_id
                LEFT JOIN college c ON u.college_id = c.college_id
                LEFT JOIN course cr ON u.course_id = cr.course_id
                WHERE u.users_id = :user_id AND u.is_active = 1";

        $this->db->query($sql);
        $this->db->bind(':user_id', $userId);
        return $this->db->single();
    }

    /**
     * Get students by college
     */
    public function getStudentsByCollege($collegeId)
    {
        $sql = "SELECT u.*, c.college_name, cr.course_name, cr.course_code
                FROM users u
                JOIN college c ON u.college_id = c.college_id
                JOIN course cr ON u.course_id = cr.course_id
                JOIN user_role r ON u.user_role_id = r.user_role_id
                WHERE r.user_role_name = 'student' 
                AND u.college_id = :college_id 
                AND u.is_active = 1
                ORDER BY u.lname, u.fname";

        $this->db->query($sql);
        $this->db->bind(':college_id', $collegeId);
        return $this->db->resultSet();
    }

    /**
     * Get sub admins with their offices
     */
    public function getSubAdmins()
    {
        $sql = "SELECT u.*, o.office_name, sao.can_create_accounts, sao.can_manage_organizations
                FROM users u
                JOIN user_role r ON u.user_role_id = r.user_role_id
                LEFT JOIN sub_admin_offices sao ON u.users_id = sao.users_id
                LEFT JOIN offices o ON sao.office_id = o.office_id
                WHERE r.user_role_name = 'sub_admin' AND u.is_active = 1";

        $this->db->query($sql);
        return $this->db->resultSet();
    }

    /**
     * Get users by office
     */
    public function getUsersByOffice($officeId)
    {
        $sql = "SELECT u.*, r.user_role_name
                FROM users u
                JOIN user_role r ON u.user_role_id = r.user_role_id
                WHERE u.office_id = :office_id AND u.is_active = 1";

        $this->db->query($sql);
        $this->db->bind(':office_id', $officeId);
        return $this->db->resultSet();
    }
}

/**
 * Clearance Model - Handles clearance requests
 */
class ClearanceModel extends BaseModel
{
    protected $table = 'clearance';
    protected $primaryKey = 'clearance_id';

    /**
     * Get clearance requests for a student
     */
    public function getStudentClearances($userId)
    {
        $sql = "SELECT c.*, 
                       ct.clearance_name as clearance_type_name,
                       o.office_name,
                       o.office_description,
                       CONCAT(p.fname, ' ', p.lname) as processed_by_name
                FROM clearance c
                LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                LEFT JOIN offices o ON c.office_id = o.office_id
                LEFT JOIN users p ON c.processed_by = p.users_id
                WHERE c.users_id = :user_id
                ORDER BY c.office_order, c.created_at";

        $this->db->query($sql);
        $this->db->bind(':user_id', $userId);
        return $this->db->resultSet();
    }

    /**
     * Get pending clearances for an office
     */
    public function getPendingByOffice($officeId)
    {
        $sql = "SELECT c.*, 
                       u.fname, u.lname, u.ismis_id,
                       u.course_id, u.college_id,
                       cr.course_name,
                       col.college_name,
                       ct.clearance_name as clearance_type_name
                FROM clearance c
                JOIN users u ON c.users_id = u.users_id
                LEFT JOIN course cr ON u.course_id = cr.course_id
                LEFT JOIN college col ON u.college_id = col.college_id
                LEFT JOIN clearance_type ct ON c.clearance_type_id = ct.clearance_type_id
                WHERE c.office_id = :office_id 
                AND c.status = 'pending'
                ORDER BY c.created_at ASC";

        $this->db->query($sql);
        $this->db->bind(':office_id', $officeId);
        return $this->db->resultSet();
    }

    /**
     * Update clearance status
     */
    public function updateStatus($clearanceId, $status, $processedBy, $remarks = null)
    {
        $sql = "UPDATE clearance 
                SET status = :status, 
                    processed_by = :processed_by, 
                    processed_date = NOW(),
                    remarks = :remarks,
                    updated_at = NOW()
                WHERE clearance_id = :clearance_id";

        $this->db->query($sql);
        $this->db->bind(':clearance_id', $clearanceId);
        $this->db->bind(':status', $status);
        $this->db->bind(':processed_by', $processedBy);
        $this->db->bind(':remarks', $remarks);

        return $this->db->execute();
    }

    /**
     * Create clearance requests for a student
     */
    public function createStudentClearance($userId, $clearanceTypeId, $semester, $schoolYear)
    {
        // Get all offices with their order
        $sql = "SELECT office_id, office_order FROM offices ORDER BY office_order";
        $this->db->query($sql);
        $offices = $this->db->resultSet();

        $success = true;
        $this->db->beginTransaction();

        foreach ($offices as $office) {
            $clearanceName = "Clearance for User {$userId} - " . date('Y-m-d');

            $sql = "INSERT INTO clearance 
                    (clearance_name, users_id, clearance_type_id, semester, school_year, 
                     office_order, office_id, status, created_at)
                    VALUES 
                    (:clearance_name, :users_id, :clearance_type_id, :semester, :school_year,
                     :office_order, :office_id, 'pending', NOW())";

            $this->db->query($sql);
            $this->db->bind(':clearance_name', $clearanceName);
            $this->db->bind(':users_id', $userId);
            $this->db->bind(':clearance_type_id', $clearanceTypeId);
            $this->db->bind(':semester', $semester);
            $this->db->bind(':school_year', $schoolYear);
            $this->db->bind(':office_order', $office['office_order']);
            $this->db->bind(':office_id', $office['office_id']);

            if (!$this->db->execute()) {
                $success = false;
                break;
            }
        }

        if ($success) {
            $this->db->commit();
            return true;
        } else {
            $this->db->rollback();
            return false;
        }
    }

    /**
     * Get clearance statistics
     */
    public function getStats($officeId = null)
    {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                FROM clearance";

        if ($officeId) {
            $sql .= " WHERE office_id = :office_id";
            $this->db->query($sql);
            $this->db->bind(':office_id', $officeId);
        } else {
            $this->db->query($sql);
        }

        return $this->db->single();
    }
}

/**
 * Activity Log Model
 */
class ActivityLogModel extends BaseModel
{
    protected $table = 'activity_logs';
    protected $primaryKey = 'log_id';

    /**
     * Log user activity
     */
    public function log($userId, $action, $description, $ipAddress = null)
    {
        if (!$ipAddress) {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }

        $sql = "INSERT INTO activity_logs (users_id, action, description, ip_address, created_at)
                VALUES (:users_id, :action, :description, :ip_address, NOW())";

        $this->db->query($sql);
        $this->db->bind(':users_id', $userId);
        $this->db->bind(':action', $action);
        $this->db->bind(':description', $description);
        $this->db->bind(':ip_address', $ipAddress);

        return $this->db->execute();
    }

    /**
     * Get recent activities
     */
    public function getRecent($limit = 50)
    {
        $sql = "SELECT a.*, CONCAT(u.fname, ' ', u.lname) as user_name
                FROM activity_logs a
                LEFT JOIN users u ON a.users_id = u.users_id
                ORDER BY a.created_at DESC
                LIMIT :limit";

        $this->db->query($sql);
        $this->db->bind(':limit', $limit, PDO::PARAM_INT);
        return $this->db->resultSet();
    }

    /**
     * Get user activities
     */
    public function getUserActivities($userId, $limit = 20)
    {
        $sql = "SELECT * FROM activity_logs 
                WHERE users_id = :users_id 
                ORDER BY created_at DESC 
                LIMIT :limit";

        $this->db->query($sql);
        $this->db->bind(':users_id', $userId);
        $this->db->bind(':limit', $limit, PDO::PARAM_INT);
        return $this->db->resultSet();
    }
}

/**
 * Office Model
 */
class OfficeModel extends BaseModel
{
    protected $table = 'offices';
    protected $primaryKey = 'office_id';

    /**
     * Get all offices with sub-offices
     */
    public function getAllWithSubOffices()
    {
        $sql = "SELECT o.*, 
                       (SELECT COUNT(*) FROM sub_offices so WHERE so.parent_office_id = o.office_id) as sub_office_count
                FROM offices o
                ORDER BY o.office_name";

        $this->db->query($sql);
        return $this->db->resultSet();
    }

    /**
     * Get sub-offices by parent office
     */
    public function getSubOffices($officeId)
    {
        $sql = "SELECT * FROM sub_offices 
                WHERE parent_office_id = :office_id 
                ORDER BY sub_office_name";

        $this->db->query($sql);
        $this->db->bind(':office_id', $officeId);
        return $this->db->resultSet();
    }
}

// =====================================================
// Enhanced Helper Functions
// =====================================================

/**
 * Get database instance
 */
function db()
{
    return Database::getInstance();
}

/**
 * Execute query with parameters
 */
function dbQuery($sql, $params = [])
{
    $db = db();
    $db->query($sql);

    foreach ($params as $key => $value) {
        $db->bind(is_numeric($key) ? $key + 1 : $key, $value);
    }

    return $db->execute() ? $db : false;
}

/**
 * Fetch all records
 */
function dbFetchAll($sql, $params = [])
{
    $db = dbQuery($sql, $params);
    return $db ? $db->resultSet() : [];
}

/**
 * Fetch single record
 */
function dbFetchOne($sql, $params = [])
{
    $db = dbQuery($sql, $params);
    return $db ? $db->single() : null;
}

/**
 * Insert and get ID
 */
function dbInsert($sql, $params = [])
{
    $db = db();
    $db->query($sql);

    foreach ($params as $key => $value) {
        $db->bind(is_numeric($key) ? $key + 1 : $key, $value);
    }

    return $db->execute() ? $db->lastInsertId() : false;
}

/**
 * Update and get row count
 */
function dbUpdate($sql, $params = [])
{
    $db = dbQuery($sql, $params);
    return $db ? $db->rowCount() : false;
}

/**
 * Delete and get row count
 */
function dbDelete($sql, $params = [])
{
    return dbUpdate($sql, $params);
}

/**
 * Check if record exists
 */
function dbExists($sql, $params = [])
{
    $result = dbFetchOne($sql, $params);
    return !empty($result);
}

// =====================================================
// Authentication Functions
// =====================================================

/**
 * Login user function
 */
function loginUser($username, $password)
{
    $db = Database::getInstance();

    // Check if username is email or ISMIS ID
    if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
        $sql = "SELECT u.*, ur.user_role_name, ur.user_role_id,
                       o.office_name, o.office_id,
                       c.college_name, c.college_id,
                       cr.course_name, cr.course_id
                FROM users u 
                LEFT JOIN user_role ur ON u.user_role_id = ur.user_role_id
                LEFT JOIN offices o ON u.office_id = o.office_id
                LEFT JOIN college c ON u.college_id = c.college_id
                LEFT JOIN course cr ON u.course_id = cr.course_id
                WHERE u.emails = :username AND u.is_active = 1";
    } else {
        $sql = "SELECT u.*, ur.user_role_name, ur.user_role_id,
                       o.office_name, o.office_id,
                       c.college_name, c.college_id,
                       cr.course_name, cr.course_id
                FROM users u 
                LEFT JOIN user_role ur ON u.user_role_id = ur.user_role_id
                LEFT JOIN offices o ON u.office_id = o.office_id
                LEFT JOIN college c ON u.college_id = c.college_id
                LEFT JOIN course cr ON u.course_id = cr.course_id
                WHERE u.ismis_id = :username AND u.is_active = 1";
    }

    $db->query($sql);
    $db->bind(':username', $username);
    $user = $db->single();

    if ($user && password_verify($password, $user['password'])) {
        // Remove password from array for security
        unset($user['password']);
        return $user;
    }

    return false;
}

/**
 * Login user and create session (simplified version)
 */
function login($username, $password)
{
    $userModel = new UserModel();

    // Find user by email or ISMIS ID
    $db = Database::getInstance();
    $db->query("SELECT u.*, ur.user_role_name 
                FROM users u 
                JOIN user_role ur ON u.user_role_id = ur.user_role_id 
                WHERE (u.emails = :username OR u.ismis_id = :username) 
                AND u.is_active = 1");
    $db->bind(':username', $username);
    $user = $db->single();

    if ($user && password_verify($password, $user['password'])) {
        // Set session variables
        $_SESSION['user_id'] = $user['users_id'];
        $_SESSION['user_email'] = $user['emails'];
        $_SESSION['user_name'] = $user['fname'] . ' ' . $user['lname'];
        $_SESSION['user_fname'] = $user['fname'];
        $_SESSION['user_lname'] = $user['lname'];
        $_SESSION['user_role'] = $user['user_role_name'];
        $_SESSION['user_role_id'] = $user['user_role_id'];
        $_SESSION['office_id'] = $user['office_id'] ?? null;
        $_SESSION['college_id'] = $user['college_id'] ?? null;
        $_SESSION['course_id'] = $user['course_id'] ?? null;
        $_SESSION['ismis_id'] = $user['ismis_id'] ?? null;
        $_SESSION['logged_in'] = true;
        $_SESSION['login_time'] = time();

        // Log the login activity
        $logModel = new ActivityLogModel();
        $logModel->log($user['users_id'], 'LOGIN', 'User logged in successfully');

        return $user;
    }

    return false;
}

/**
 * Check login status
 */
function isLoggedIn()
{
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Require login - redirect if not logged in
 */
function requireLogin()
{
    if (!isLoggedIn()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . BASE_URL . 'login.php');
        exit();
    }
}

/**
 * Check role
 */
function hasRole($role)
{
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === $role;
}

/**
 * Require specific role
 */
function requireRole($role)
{
    requireLogin();
    if (!hasRole($role)) {
        header('Location: ' . BASE_URL . 'unauthorized.php');
        exit();
    }
}

/**
 * Get current user data
 */
function getCurrentUser()
{
    if (!isLoggedIn()) {
        return null;
    }

    $userModel = new UserModel();
    return $userModel->getUserWithDetails($_SESSION['user_id']);
}

/**
 * Logout
 */
function logout()
{
    // Log the logout activity if user was logged in
    if (isset($_SESSION['user_id'])) {
        $logModel = new ActivityLogModel();
        $logModel->log($_SESSION['user_id'], 'LOGOUT', 'User logged out: ' . ($_SESSION['user_name'] ?? 'Unknown'));
    }

    // Clear session
    $_SESSION = array();

    // Destroy session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
}

// =====================================================
// Password Hashing Functions
// =====================================================

/**
 * Hash password (using bcrypt)
 */
function hashPassword($password)
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify password
 */
function verifyPassword($password, $hash)
{
    return password_verify($password, $hash);
}

// =====================================================
// Session Configuration
// =====================================================

function initSession()
{
    if (session_status() === PHP_SESSION_NONE) {
        // Secure session settings
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.cookie_samesite', 'Strict');

        // Session lifetime (8 hours)
        ini_set('session.gc_maxlifetime', 28800);
        ini_set('session.cookie_lifetime', 28800);

        session_start();

        // Regenerate session ID periodically for security
        if (!isset($_SESSION['created'])) {
            $_SESSION['created'] = time();
        } else if (time() - $_SESSION['created'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        }
    }
}

/**
 * Configure session (alias for initSession for backward compatibility)
 */
function configureSession()
{
    initSession();
}

/**
 * Decide whether a maintenance task should run now.
 * Uses APCu when available, otherwise falls back to session-scoped throttling.
 */
function shouldRunMaintenanceTask($taskKey, $intervalSeconds = 21600)
{
    static $requestMemo = [];

    $taskKey = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', (string) $taskKey);
    if ($taskKey === '') {
        $taskKey = 'default_task';
    }

    if (array_key_exists($taskKey, $requestMemo)) {
        return false;
    }

    $intervalSeconds = max(60, (int) $intervalSeconds);
    $now = time();
    $shouldRun = false;

    $apcuEnabled = function_exists('apcu_fetch')
        && function_exists('apcu_store')
        && (bool) ini_get(PHP_SAPI === 'cli' ? 'apc.enable_cli' : 'apc.enabled');

    if ($apcuEnabled) {
        $cacheKey = 'clearance:maintenance:' . $taskKey;
        $success = false;
        $lastRun = call_user_func('apcu_fetch', $cacheKey, $success);

        if (!$success || ($now - (int) $lastRun) >= $intervalSeconds) {
            call_user_func('apcu_store', $cacheKey, $now, $intervalSeconds * 2);
            $shouldRun = true;
        }
    } elseif (session_status() === PHP_SESSION_ACTIVE) {
        if (!isset($_SESSION['_maintenance_tasks']) || !is_array($_SESSION['_maintenance_tasks'])) {
            $_SESSION['_maintenance_tasks'] = [];
        }

        $lastRun = (int) ($_SESSION['_maintenance_tasks'][$taskKey] ?? 0);
        if ($lastRun <= 0 || ($now - $lastRun) >= $intervalSeconds) {
            $_SESSION['_maintenance_tasks'][$taskKey] = $now;
            $shouldRun = true;
        }
    } else {
        // No shared storage is available; run once for this request.
        $shouldRun = true;
    }

    $requestMemo[$taskKey] = true;
    return $shouldRun;
}

/**
 * Cached column-existence check to avoid repetitive SHOW COLUMNS calls.
 */
function hasDatabaseColumn($tableName, $columnName, $cacheTtlSeconds = 21600)
{
    static $requestCache = [];

    $tableName = trim((string) $tableName);
    $columnName = trim((string) $columnName);
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName) || !preg_match('/^[A-Za-z0-9_]+$/', $columnName)) {
        return false;
    }

    $cacheKey = strtolower($tableName . '.' . $columnName);
    if (array_key_exists($cacheKey, $requestCache)) {
        return $requestCache[$cacheKey];
    }

    $cacheTtlSeconds = max(60, (int) $cacheTtlSeconds);
    $now = time();

    $apcuEnabled = function_exists('apcu_fetch')
        && function_exists('apcu_store')
        && (bool) ini_get(PHP_SAPI === 'cli' ? 'apc.enable_cli' : 'apc.enabled');

    if ($apcuEnabled) {
        $apcuKey = 'clearance:column_exists:' . $cacheKey;
        $success = false;
        $cached = call_user_func('apcu_fetch', $apcuKey, $success);
        if ($success) {
            $requestCache[$cacheKey] = (bool) $cached;
            return $requestCache[$cacheKey];
        }
    } elseif (session_status() === PHP_SESSION_ACTIVE) {
        if (!isset($_SESSION['_schema_cache']) || !is_array($_SESSION['_schema_cache'])) {
            $_SESSION['_schema_cache'] = [];
        }

        if (isset($_SESSION['_schema_cache'][$cacheKey])) {
            $entry = $_SESSION['_schema_cache'][$cacheKey];
            $expiresAt = (int) ($entry['expires_at'] ?? 0);
            if ($expiresAt >= $now) {
                $requestCache[$cacheKey] = (bool) ($entry['value'] ?? false);
                return $requestCache[$cacheKey];
            }
        }
    }

    $db = Database::getInstance();
    try {
        $db->query("SELECT COUNT(*) AS column_count
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                                            AND TABLE_NAME = '{$tableName}'
                                            AND COLUMN_NAME = '{$columnName}'
                    LIMIT 1");
        $columnCheckRow = $db->single();
        $exists = ((int) ($columnCheckRow['column_count'] ?? 0)) > 0;
    } catch (Exception $e) {
        // Fail safe: avoid breaking page loads when metadata checks fail.
        error_log('Schema column check error for ' . $tableName . '.' . $columnName . ': ' . $e->getMessage());
        $exists = false;
    }
    $requestCache[$cacheKey] = $exists;

    if ($apcuEnabled) {
        call_user_func('apcu_store', 'clearance:column_exists:' . $cacheKey, $exists, $cacheTtlSeconds);
    } elseif (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['_schema_cache'][$cacheKey] = [
            'value' => $exists,
            'expires_at' => $now + $cacheTtlSeconds
        ];
    }

    return $exists;
}

/**
 * Base64URL encode helper.
 */
function base64UrlEncodeSafe($binary)
{
    return rtrim(strtr(base64_encode((string) $binary), '+/', '-_'), '=');
}

/**
 * Base64URL decode helper that returns null on invalid input.
 */
function base64UrlDecodeSafe($value)
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $normalized = strtr(trim($value), '-_', '+/');
    $padding = strlen($normalized) % 4;
    if ($padding > 0) {
        $normalized .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode($normalized, true);
    return $decoded === false ? null : $decoded;
}

/**
 * Normalize a PEM private key source that may be plain PEM or base64-encoded PEM.
 */
function normalizePemPrivateKey($rawValue)
{
    $rawValue = trim((string) $rawValue);
    if ($rawValue === '') {
        return '';
    }

    if (strpos($rawValue, '-----BEGIN') !== false) {
        return $rawValue;
    }

    $decoded = base64_decode($rawValue, true);
    if ($decoded !== false && strpos($decoded, '-----BEGIN') !== false) {
        return trim($decoded);
    }

    return $rawValue;
}

/**
 * Normalize EC coordinate value from OpenSSL details into fixed 32-byte binary.
 */
function normalizeEcCoordinateBinary($value)
{
    if (!is_string($value) || $value === '') {
        return '';
    }

    $binary = '';
    if (preg_match('/^[0-9a-fA-F]+$/', $value) && (strlen($value) % 2 === 0)) {
        $hexDecoded = @hex2bin($value);
        if ($hexDecoded !== false) {
            $binary = $hexDecoded;
        }
    }

    if ($binary === '') {
        $binary = $value;
    }

    if (strlen($binary) > 32) {
        $binary = substr($binary, -32);
    }

    return str_pad($binary, 32, "\0", STR_PAD_LEFT);
}

/**
 * Extract VAPID public key in URL-safe format from a PEM private key.
 */
function deriveVapidPublicKeyFromPrivatePem($privatePem)
{
    if (!function_exists('openssl_pkey_get_private') || !function_exists('openssl_pkey_get_details')) {
        return '';
    }

    $privateKey = @openssl_pkey_get_private((string) $privatePem);
    if (!$privateKey) {
        return '';
    }

    $details = @openssl_pkey_get_details($privateKey);
    if (!is_array($details) || !isset($details['ec']) || !is_array($details['ec'])) {
        return '';
    }

    $x = normalizeEcCoordinateBinary($details['ec']['x'] ?? '');
    $y = normalizeEcCoordinateBinary($details['ec']['y'] ?? '');
    if ($x === '' || $y === '') {
        return '';
    }

    return base64UrlEncodeSafe("\x04" . $x . $y);
}

/**
 * Runtime web push configuration with capability checks.
 */
function getWebPushConfiguration()
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $publicKeyFromEnv = getenv('WEB_PUSH_VAPID_PUBLIC_KEY');
    $privatePemFromEnv = getenv('WEB_PUSH_VAPID_PRIVATE_KEY_PEM');
    $subjectFromEnv = getenv('WEB_PUSH_SUBJECT');

    $publicKey = trim((string) ($publicKeyFromEnv !== false ? $publicKeyFromEnv : WEB_PUSH_VAPID_PUBLIC_KEY));
    $privatePem = normalizePemPrivateKey($privatePemFromEnv !== false ? $privatePemFromEnv : WEB_PUSH_VAPID_PRIVATE_KEY_PEM);
    $subject = trim((string) ($subjectFromEnv !== false ? $subjectFromEnv : WEB_PUSH_SUBJECT));

    if ($publicKey === '' && $privatePem !== '') {
        $publicKey = deriveVapidPublicKeyFromPrivatePem($privatePem);
    }

    if ($subject === '') {
        $subject = 'mailto:admin@example.com';
    }

    $hasOpenSsl = function_exists('openssl_sign')
        && function_exists('openssl_pkey_get_private')
        && function_exists('openssl_pkey_get_public')
        && function_exists('openssl_pkey_get_details')
        && function_exists('openssl_pkey_new')
        && function_exists('openssl_pkey_derive')
        && function_exists('openssl_encrypt');

    $reason = '';
    $enabled = true;

    if (!$hasOpenSsl) {
        $enabled = false;
        $reason = 'PHP OpenSSL extension is not available.';
    } elseif ($publicKey === '') {
        $enabled = false;
        $reason = 'Missing WEB_PUSH_VAPID_PUBLIC_KEY.';
    } elseif ($privatePem === '') {
        $enabled = false;
        $reason = 'Missing WEB_PUSH_VAPID_PRIVATE_KEY_PEM.';
    } elseif (!function_exists('random_bytes')) {
        $enabled = false;
        $reason = 'Secure random generator is unavailable.';
    }

    $privateKeyHandle = null;
    if ($enabled) {
        $privateKeyHandle = @openssl_pkey_get_private($privatePem);
        if (!$privateKeyHandle) {
            $enabled = false;
            $reason = 'WEB_PUSH_VAPID_PRIVATE_KEY_PEM is invalid.';
        }
    }

    $defaultClickUrl = trim((string) (getenv('WEB_PUSH_DEFAULT_CLICK_URL') !== false ? getenv('WEB_PUSH_DEFAULT_CLICK_URL') : WEB_PUSH_DEFAULT_CLICK_URL));
    if ($defaultClickUrl === '') {
        $defaultClickUrl = 'student/dashboard.php?tab=messages';
    }

    $iconPath = trim((string) (getenv('WEB_PUSH_DEFAULT_ICON') !== false ? getenv('WEB_PUSH_DEFAULT_ICON') : WEB_PUSH_DEFAULT_ICON));
    $badgePath = trim((string) (getenv('WEB_PUSH_DEFAULT_BADGE') !== false ? getenv('WEB_PUSH_DEFAULT_BADGE') : WEB_PUSH_DEFAULT_BADGE));

    $config = [
        'enabled' => $enabled,
        'reason' => $reason,
        'public_key' => $publicKey,
        'private_key_pem' => $privatePem,
        'subject' => $subject,
        'default_click_url' => $defaultClickUrl,
        'icon_url' => $iconPath !== '' ? versionedUrl($iconPath) : '',
        'badge_url' => $badgePath !== '' ? versionedUrl($badgePath) : ''
    ];

    if ($privateKeyHandle) {
        if (function_exists('openssl_pkey_free')) {
            @openssl_pkey_free($privateKeyHandle);
        }
        unset($privateKeyHandle);
    }

    return $config;
}

/**
 * Quick check used by APIs and job hooks.
 */
function isWebPushEnabled()
{
    $config = getWebPushConfiguration();
    return !empty($config['enabled']);
}

/**
 * Ensure storage tables for push subscriptions and queued notifications.
 */
function ensurePushInfrastructure($db = null)
{
    static $checked = false;

    if ($checked) {
        return;
    }

    $checked = true;

    if (!shouldRunMaintenanceTask('web_push_tables', 21600)) {
        return;
    }

    if (!$db) {
        $db = Database::getInstance();
    }

    try {
        $db->query("CREATE TABLE IF NOT EXISTS push_subscriptions (
                    subscription_id INT AUTO_INCREMENT PRIMARY KEY,
                    users_id INT NOT NULL,
                    endpoint_hash CHAR(64) NOT NULL,
                    endpoint TEXT NOT NULL,
                    p256dh_key VARCHAR(255) NOT NULL,
                    auth_secret VARCHAR(255) NOT NULL,
                    content_encoding VARCHAR(30) NULL,
                    user_agent VARCHAR(255) NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    last_http_status SMALLINT NULL,
                    last_error TEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    last_success_at DATETIME NULL,
                    last_failure_at DATETIME NULL,
                    UNIQUE KEY uniq_push_endpoint_hash (endpoint_hash),
                    INDEX idx_push_user_active (users_id, is_active)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->execute();
    } catch (Exception $e) {
        error_log('Error ensuring push_subscriptions table: ' . $e->getMessage());
    }

    try {
        $db->query("CREATE TABLE IF NOT EXISTS app_notifications (
                    notification_id INT AUTO_INCREMENT PRIMARY KEY,
                    recipient_id INT NOT NULL,
                    actor_id INT NULL,
                    event_type VARCHAR(60) NOT NULL,
                    title VARCHAR(180) NOT NULL,
                    body VARCHAR(255) NOT NULL,
                    click_url VARCHAR(255) NULL,
                    payload_json TEXT NULL,
                    dedupe_key VARCHAR(190) NULL,
                    push_sent_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_notification_recipient_sent (recipient_id, push_sent_at, created_at),
                    UNIQUE KEY uniq_notification_dedupe (recipient_id, dedupe_key)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->execute();
    } catch (Exception $e) {
        error_log('Error ensuring app_notifications table: ' . $e->getMessage());
    }
}

/**
 * Persist or refresh a user's push subscription.
 */
function savePushSubscriptionForUser($userId, array $subscriptionData, $userAgent = '')
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return false;
    }

    $endpoint = trim((string) ($subscriptionData['endpoint'] ?? ''));
    $keys = isset($subscriptionData['keys']) && is_array($subscriptionData['keys']) ? $subscriptionData['keys'] : [];
    $p256dh = trim((string) ($keys['p256dh'] ?? ($subscriptionData['p256dh'] ?? '')));
    $auth = trim((string) ($keys['auth'] ?? ($subscriptionData['auth'] ?? '')));
    $encoding = trim((string) ($subscriptionData['contentEncoding'] ?? ($subscriptionData['content_encoding'] ?? '')));

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        return false;
    }

    $p256dhRaw = base64UrlDecodeSafe($p256dh);
    $authRaw = base64UrlDecodeSafe($auth);

    if ($p256dhRaw === null || strlen($p256dhRaw) < 65 || $authRaw === null || strlen($authRaw) < 12) {
        return false;
    }

    $endpointHash = hash('sha256', $endpoint);
    $userAgent = trim((string) $userAgent);
    if ($userAgent !== '' && strlen($userAgent) > 250) {
        $userAgent = substr($userAgent, 0, 250);
    }

    $db = Database::getInstance();
    ensurePushInfrastructure($db);

    try {
        $db->query("INSERT INTO push_subscriptions (
                        users_id,
                        endpoint_hash,
                        endpoint,
                        p256dh_key,
                        auth_secret,
                        content_encoding,
                        user_agent,
                        is_active,
                        created_at,
                        updated_at
                    )
                    VALUES (
                        :users_id,
                        :endpoint_hash,
                        :endpoint,
                        :p256dh_key,
                        :auth_secret,
                        :content_encoding,
                        :user_agent,
                        1,
                        NOW(),
                        NOW()
                    )
                    ON DUPLICATE KEY UPDATE
                        users_id = VALUES(users_id),
                        endpoint = VALUES(endpoint),
                        p256dh_key = VALUES(p256dh_key),
                        auth_secret = VALUES(auth_secret),
                        content_encoding = VALUES(content_encoding),
                        user_agent = VALUES(user_agent),
                        is_active = 1,
                        updated_at = NOW(),
                        last_error = NULL,
                        last_http_status = NULL");
        $db->bind(':users_id', $userId);
        $db->bind(':endpoint_hash', $endpointHash);
        $db->bind(':endpoint', $endpoint);
        $db->bind(':p256dh_key', $p256dh);
        $db->bind(':auth_secret', $auth);
        $db->bind(':content_encoding', $encoding !== '' ? $encoding : null);
        $db->bind(':user_agent', $userAgent !== '' ? $userAgent : null);
        return (bool) $db->execute();
    } catch (Exception $e) {
        error_log('Error saving push subscription: ' . $e->getMessage());
        return false;
    }
}

/**
 * Deactivate a user's push subscription for a given endpoint.
 */
function removePushSubscriptionForUser($userId, $endpoint)
{
    $userId = (int) $userId;
    $endpoint = trim((string) $endpoint);
    if ($userId <= 0 || $endpoint === '') {
        return false;
    }

    $endpointHash = hash('sha256', $endpoint);

    $db = Database::getInstance();
    ensurePushInfrastructure($db);

    try {
        $db->query("UPDATE push_subscriptions
                    SET is_active = 0,
                        updated_at = NOW(),
                        last_failure_at = NOW(),
                        last_error = 'Unsubscribed by client'
                    WHERE users_id = :users_id
                      AND endpoint_hash = :endpoint_hash");
        $db->bind(':users_id', $userId);
        $db->bind(':endpoint_hash', $endpointHash);
        return (bool) $db->execute();
    } catch (Exception $e) {
        error_log('Error removing push subscription: ' . $e->getMessage());
        return false;
    }
}

/**
 * Queue a notification for later push delivery.
 */
function queueAppNotification($recipientId, $eventType, $title, $body, $clickUrl = '', array $payload = [], $dedupeKey = null, $actorId = null)
{
    $recipientId = (int) $recipientId;
    $actorId = $actorId === null ? null : (int) $actorId;
    $eventType = trim((string) $eventType);
    $title = trim((string) $title);
    $body = trim((string) $body);
    $clickUrl = trim((string) $clickUrl);

    if ($recipientId <= 0 || $eventType === '' || $title === '' || $body === '') {
        return false;
    }

    if (strlen($eventType) > 60) {
        $eventType = substr($eventType, 0, 60);
    }
    if (strlen($title) > 180) {
        $title = substr($title, 0, 180);
    }
    if (strlen($body) > 255) {
        $body = substr($body, 0, 255);
    }
    if ($clickUrl !== '' && strlen($clickUrl) > 255) {
        $clickUrl = substr($clickUrl, 0, 255);
    }

    $normalizedDedupeKey = null;
    if ($dedupeKey !== null) {
        $normalizedDedupeKey = trim((string) $dedupeKey);
        if ($normalizedDedupeKey !== '' && strlen($normalizedDedupeKey) > 190) {
            $normalizedDedupeKey = substr($normalizedDedupeKey, 0, 190);
        }
        if ($normalizedDedupeKey === '') {
            $normalizedDedupeKey = null;
        }
    }

    $payloadJson = null;
    if (!empty($payload)) {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            $payloadJson = $encoded;
        }
    }

    $db = Database::getInstance();
    ensurePushInfrastructure($db);

    try {
        $db->query("INSERT INTO app_notifications (
                        recipient_id,
                        actor_id,
                        event_type,
                        title,
                        body,
                        click_url,
                        payload_json,
                        dedupe_key,
                        created_at
                    )
                    VALUES (
                        :recipient_id,
                        :actor_id,
                        :event_type,
                        :title,
                        :body,
                        :click_url,
                        :payload_json,
                        :dedupe_key,
                        NOW()
                    )
                    ON DUPLICATE KEY UPDATE notification_id = notification_id");
        $db->bind(':recipient_id', $recipientId);
        $db->bind(':actor_id', $actorId);
        $db->bind(':event_type', $eventType);
        $db->bind(':title', $title);
        $db->bind(':body', $body);
        $db->bind(':click_url', $clickUrl !== '' ? $clickUrl : null);
        $db->bind(':payload_json', $payloadJson);
        $db->bind(':dedupe_key', $normalizedDedupeKey);
        return (bool) $db->execute();
    } catch (Exception $e) {
        error_log('Error queueing app notification: ' . $e->getMessage());
        return false;
    }
}

/**
 * Read ASN.1 length for DER parsing.
 */
function asn1ReadLength($binary, &$offset)
{
    if (!isset($binary[$offset])) {
        return -1;
    }

    $length = ord($binary[$offset]);
    $offset++;

    if (($length & 0x80) === 0) {
        return $length;
    }

    $byteCount = $length & 0x7F;
    if ($byteCount <= 0 || $byteCount > 4) {
        return -1;
    }

    $length = 0;
    for ($i = 0; $i < $byteCount; $i++) {
        if (!isset($binary[$offset])) {
            return -1;
        }
        $length = ($length << 8) | ord($binary[$offset]);
        $offset++;
    }

    return $length;
}

/**
 * Convert DER-encoded ECDSA signature to JOSE format required by JWT ES256.
 */
function derToJoseSignature($derSignature, $partLength = 32)
{
    if (!is_string($derSignature) || $derSignature === '') {
        return '';
    }

    $offset = 0;
    if (!isset($derSignature[$offset]) || ord($derSignature[$offset]) !== 0x30) {
        return '';
    }
    $offset++;

    $sequenceLength = asn1ReadLength($derSignature, $offset);
    if ($sequenceLength < 0) {
        return '';
    }

    if (!isset($derSignature[$offset]) || ord($derSignature[$offset]) !== 0x02) {
        return '';
    }
    $offset++;
    $rLength = asn1ReadLength($derSignature, $offset);
    if ($rLength <= 0) {
        return '';
    }
    $r = substr($derSignature, $offset, $rLength);
    $offset += $rLength;

    if (!isset($derSignature[$offset]) || ord($derSignature[$offset]) !== 0x02) {
        return '';
    }
    $offset++;
    $sLength = asn1ReadLength($derSignature, $offset);
    if ($sLength <= 0) {
        return '';
    }
    $s = substr($derSignature, $offset, $sLength);

    $r = ltrim($r, "\0");
    $s = ltrim($s, "\0");

    if (strlen($r) > $partLength) {
        $r = substr($r, -$partLength);
    }
    if (strlen($s) > $partLength) {
        $s = substr($s, -$partLength);
    }

    $r = str_pad($r, $partLength, "\0", STR_PAD_LEFT);
    $s = str_pad($s, $partLength, "\0", STR_PAD_LEFT);

    return $r . $s;
}

/**
 * Build signed VAPID JWT token.
 */
function createVapidJwtToken($audience, $subject, $privatePem, &$errorMessage = '')
{
    $header = ['typ' => 'JWT', 'alg' => 'ES256'];
    $payload = [
        'aud' => (string) $audience,
        'exp' => time() + 43200,
        'sub' => (string) $subject
    ];

    $encodedHeader = base64UrlEncodeSafe(json_encode($header, JSON_UNESCAPED_SLASHES));
    $encodedPayload = base64UrlEncodeSafe(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $toSign = $encodedHeader . '.' . $encodedPayload;

    $derSignature = '';
    $signOk = @openssl_sign($toSign, $derSignature, (string) $privatePem, OPENSSL_ALGO_SHA256);
    if (!$signOk || $derSignature === '') {
        $errorMessage = 'Unable to sign VAPID JWT.';
        return '';
    }

    $joseSignature = derToJoseSignature($derSignature, 32);
    if ($joseSignature === '') {
        $errorMessage = 'Unable to convert VAPID signature.';
        return '';
    }

    return $toSign . '.' . base64UrlEncodeSafe($joseSignature);
}

/**
 * Build HKDF (SHA-256) output.
 */
function hkdfSha256($salt, $ikm, $info, $length)
{
    $length = (int) $length;
    if ($length <= 0) {
        return '';
    }

    $salt = is_string($salt) ? $salt : '';
    $ikm = is_string($ikm) ? $ikm : '';
    $info = is_string($info) ? $info : '';

    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $output = '';
    $block = '';
    $blockIndex = 1;

    while (strlen($output) < $length) {
        $block = hash_hmac('sha256', $block . $info . chr($blockIndex), $prk, true);
        $output .= $block;
        $blockIndex++;
        if ($blockIndex > 255) {
            break;
        }
    }

    return substr($output, 0, $length);
}

/**
 * Convert a raw uncompressed P-256 key into PEM format.
 */
function rawEcPublicKeyToPem($rawKey)
{
    if (!is_string($rawKey) || strlen($rawKey) !== 65 || $rawKey[0] !== "\x04") {
        return '';
    }

    $derPrefix = @hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200');
    if ($derPrefix === false) {
        return '';
    }

    $der = $derPrefix . $rawKey;
    $pem = "-----BEGIN PUBLIC KEY-----\n";
    $pem .= chunk_split(base64_encode($der), 64, "\n");
    $pem .= "-----END PUBLIC KEY-----\n";

    return $pem;
}

/**
 * Extract endpoint audience used in VAPID JWT.
 */
function getPushAudienceFromEndpoint($endpoint)
{
    $parts = @parse_url((string) $endpoint);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $audience = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
    if (isset($parts['port']) && (int) $parts['port'] > 0) {
        $audience .= ':' . (int) $parts['port'];
    }

    return $audience;
}

/**
 * Normalize notification click URL into an absolute app URL.
 */
function normalizePushClickUrl($rawUrl, array $config)
{
    $rawUrl = trim((string) $rawUrl);
    if ($rawUrl === '') {
        return baseUrl($config['default_click_url'] ?? 'student/dashboard.php?tab=messages');
    }

    if (preg_match('/^https?:\/\//i', $rawUrl)) {
        return $rawUrl;
    }

    return baseUrl(ltrim($rawUrl, '/'));
}

/**
 * Send an HTTP push request and return status details.
 */
function sendPushHttpRequest($endpoint, array $headers, $body)
{
    $headerLines = implode("\r\n", $headers) . "\r\n";

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => $headerLines,
            'content' => (string) $body,
            'ignore_errors' => true,
            'timeout' => 15
        ]
    ]);

    $responseBody = @file_get_contents((string) $endpoint, false, $context);
    $statusCode = 0;

    if (isset($http_response_header) && is_array($http_response_header) && !empty($http_response_header[0])) {
        if (preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $match)) {
            $statusCode = (int) ($match[1] ?? 0);
        }
    }

    return [
        'ok' => $statusCode >= 200 && $statusCode < 300,
        'status' => $statusCode,
        'body' => is_string($responseBody) ? $responseBody : ''
    ];
}

/**
 * Encrypt and dispatch a single web push payload.
 */
function sendWebPushToSubscription(array $subscription, array $notificationPayload, array $config)
{
    $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
    $subscriberPublicB64 = trim((string) ($subscription['p256dh_key'] ?? ''));
    $subscriberAuthB64 = trim((string) ($subscription['auth_secret'] ?? ''));

    if ($endpoint === '' || $subscriberPublicB64 === '' || $subscriberAuthB64 === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Incomplete subscription payload'];
    }

    $subscriberPublicRaw = base64UrlDecodeSafe($subscriberPublicB64);
    $subscriberAuthRaw = base64UrlDecodeSafe($subscriberAuthB64);

    if ($subscriberPublicRaw === null || strlen($subscriberPublicRaw) !== 65 || $subscriberPublicRaw[0] !== "\x04") {
        return ['ok' => false, 'status' => 0, 'error' => 'Invalid p256dh key'];
    }
    if ($subscriberAuthRaw === null || strlen($subscriberAuthRaw) < 12) {
        return ['ok' => false, 'status' => 0, 'error' => 'Invalid auth key'];
    }

    $audience = getPushAudienceFromEndpoint($endpoint);
    if ($audience === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Invalid endpoint URL'];
    }

    $jwtError = '';
    $vapidJwt = createVapidJwtToken($audience, (string) ($config['subject'] ?? ''), (string) ($config['private_key_pem'] ?? ''), $jwtError);
    if ($vapidJwt === '') {
        return ['ok' => false, 'status' => 0, 'error' => $jwtError !== '' ? $jwtError : 'Failed to generate JWT'];
    }

    $ephemeralKey = @openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1'
    ]);
    if (!$ephemeralKey) {
        return ['ok' => false, 'status' => 0, 'error' => 'Failed to generate ephemeral key'];
    }

    $ephemeralDetails = @openssl_pkey_get_details($ephemeralKey);
    $ephemeralEc = is_array($ephemeralDetails) ? ($ephemeralDetails['ec'] ?? null) : null;
    $ephemeralX = normalizeEcCoordinateBinary(is_array($ephemeralEc) ? ($ephemeralEc['x'] ?? '') : '');
    $ephemeralY = normalizeEcCoordinateBinary(is_array($ephemeralEc) ? ($ephemeralEc['y'] ?? '') : '');
    if ($ephemeralX === '' || $ephemeralY === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Failed to extract ephemeral public key'];
    }
    $ephemeralPublicRaw = "\x04" . $ephemeralX . $ephemeralY;

    $subscriberPublicPem = rawEcPublicKeyToPem($subscriberPublicRaw);
    if ($subscriberPublicPem === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Failed to build subscriber public key'];
    }

    $subscriberPublicKey = @openssl_pkey_get_public($subscriberPublicPem);
    if (!$subscriberPublicKey) {
        return ['ok' => false, 'status' => 0, 'error' => 'Failed to parse subscriber public key'];
    }

    $sharedSecret = @openssl_pkey_derive($subscriberPublicKey, $ephemeralKey, 32);
    if (!is_string($sharedSecret) || strlen($sharedSecret) !== 32) {
        return ['ok' => false, 'status' => 0, 'error' => 'Failed to derive shared secret'];
    }

    $salt = random_bytes(16);
    $keyInfo = "WebPush: info\x00" . $subscriberPublicRaw . $ephemeralPublicRaw;
    $ikm = hkdfSha256($subscriberAuthRaw, $sharedSecret, $keyInfo, 32);
    if ($ikm === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Failed to derive IKM'];
    }

    $contentEncryptionKey = hkdfSha256($salt, $ikm, "Content-Encoding: aes128gcm\x00", 16);
    $nonce = hkdfSha256($salt, $ikm, "Content-Encoding: nonce\x00", 12);
    if ($contentEncryptionKey === '' || $nonce === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Failed to derive CEK/nonce'];
    }

    $payloadJson = json_encode($notificationPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson)) {
        $payloadJson = '{}';
    }

    // aes128gcm payload requires the record delimiter byte at the end.
    $plaintext = $payloadJson . "\x02";
    $tag = '';
    $ciphertext = @openssl_encrypt($plaintext, 'aes-128-gcm', $contentEncryptionKey, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if (!is_string($ciphertext) || !is_string($tag) || strlen($tag) !== 16) {
        return ['ok' => false, 'status' => 0, 'error' => 'Failed to encrypt payload'];
    }

    $recordSize = 4096;
    $encryptedBody = $salt
        . pack('N', $recordSize)
        . chr(strlen($ephemeralPublicRaw))
        . $ephemeralPublicRaw
        . $ciphertext
        . $tag;

    $headers = [
        'TTL: 120',
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'Authorization: WebPush t=' . $vapidJwt . ', k=' . (string) ($config['public_key'] ?? ''),
        'Content-Length: ' . strlen($encryptedBody)
    ];

    return sendPushHttpRequest($endpoint, $headers, $encryptedBody);
}

/**
 * Dispatch queued notifications through active push subscriptions.
 */
function dispatchQueuedPushNotifications($recipientId = null, $limit = 25)
{
    $config = getWebPushConfiguration();
    if (empty($config['enabled'])) {
        return false;
    }

    $db = Database::getInstance();
    ensurePushInfrastructure($db);

    $limit = max(1, min(100, (int) $limit));
    $recipientId = $recipientId === null ? null : (int) $recipientId;

    try {
        $query = "SELECT n.notification_id,
                         n.recipient_id,
                         n.actor_id,
                         n.event_type,
                         n.title,
                         n.body,
                         n.click_url,
                         n.payload_json,
                         n.created_at
                  FROM app_notifications n
                  WHERE n.push_sent_at IS NULL
                    AND EXISTS (
                        SELECT 1
                        FROM push_subscriptions ps
                        WHERE ps.users_id = n.recipient_id
                          AND ps.is_active = 1
                    )";

        if ($recipientId !== null && $recipientId > 0) {
            $query .= " AND n.recipient_id = :recipient_id";
        }

        $query .= " ORDER BY n.created_at ASC LIMIT :limit_count";

        $db->query($query);
        if ($recipientId !== null && $recipientId > 0) {
            $db->bind(':recipient_id', $recipientId);
        }
        $db->bind(':limit_count', $limit, PDO::PARAM_INT);
        $notifications = $db->resultSet() ?: [];

        if (empty($notifications)) {
            return true;
        }

        foreach ($notifications as $notification) {
            $targetUserId = (int) ($notification['recipient_id'] ?? 0);
            if ($targetUserId <= 0) {
                continue;
            }

            $db->query("SELECT subscription_id,
                               endpoint,
                               p256dh_key,
                               auth_secret,
                               content_encoding
                        FROM push_subscriptions
                        WHERE users_id = :recipient_id
                          AND is_active = 1");
            $db->bind(':recipient_id', $targetUserId);
            $subscriptions = $db->resultSet() ?: [];

            if (empty($subscriptions)) {
                continue;
            }

            $extraPayload = [];
            if (!empty($notification['payload_json'])) {
                $decodedPayload = json_decode((string) $notification['payload_json'], true);
                if (is_array($decodedPayload)) {
                    $extraPayload = $decodedPayload;
                }
            }

            $pushPayload = [
                'title' => (string) ($notification['title'] ?? 'BISU Clearance Update'),
                'body' => (string) ($notification['body'] ?? ''),
                'url' => normalizePushClickUrl((string) ($notification['click_url'] ?? ''), $config),
                'tag' => 'bisu-' . preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($notification['event_type'] ?? 'notice')) . '-' . (int) ($notification['notification_id'] ?? 0),
                'icon' => (string) ($config['icon_url'] ?? ''),
                'badge' => (string) ($config['badge_url'] ?? ''),
                'event_type' => (string) ($notification['event_type'] ?? 'notice'),
                'notification_id' => (int) ($notification['notification_id'] ?? 0),
                'created_at' => (string) ($notification['created_at'] ?? '')
            ];

            if (!empty($extraPayload)) {
                $pushPayload['meta'] = $extraPayload;
            }

            $successCount = 0;

            foreach ($subscriptions as $subscription) {
                $result = sendWebPushToSubscription($subscription, $pushPayload, $config);
                $subscriptionId = (int) ($subscription['subscription_id'] ?? 0);
                $httpStatus = (int) ($result['status'] ?? 0);
                $errorMessage = trim((string) ($result['body'] ?? ($result['error'] ?? '')));

                if ($subscriptionId > 0) {
                    if (!empty($result['ok'])) {
                        $successCount++;
                        $db->query("UPDATE push_subscriptions
                                    SET last_success_at = NOW(),
                                        last_http_status = :http_status,
                                        last_error = NULL,
                                        updated_at = NOW()
                                    WHERE subscription_id = :subscription_id");
                        $db->bind(':http_status', $httpStatus > 0 ? $httpStatus : 201);
                        $db->bind(':subscription_id', $subscriptionId);
                        $db->execute();
                    } else {
                        $shouldDeactivate = in_array($httpStatus, [404, 410], true);
                        $db->query("UPDATE push_subscriptions
                                    SET is_active = :is_active,
                                        last_failure_at = NOW(),
                                        last_http_status = :http_status,
                                        last_error = :last_error,
                                        updated_at = NOW()
                                    WHERE subscription_id = :subscription_id");
                        $db->bind(':is_active', $shouldDeactivate ? 0 : 1);
                        $db->bind(':http_status', $httpStatus > 0 ? $httpStatus : null);
                        $db->bind(':last_error', $errorMessage !== '' ? substr($errorMessage, 0, 1000) : 'Push send failed');
                        $db->bind(':subscription_id', $subscriptionId);
                        $db->execute();
                    }
                }
            }

            if ($successCount > 0) {
                $db->query("UPDATE app_notifications
                            SET push_sent_at = NOW()
                            WHERE notification_id = :notification_id");
                $db->bind(':notification_id', (int) ($notification['notification_id'] ?? 0));
                $db->execute();
            }
        }
    } catch (Exception $e) {
        error_log('Error dispatching push notifications: ' . $e->getMessage());
        return false;
    }

    return true;
}

// =====================================================
// Utility Functions
// =====================================================

/**
 * Get user role name from ID
 */
function getUserRoleName($roleId)
{
    $db = Database::getInstance();
    $db->query("SELECT user_role_name FROM user_role WHERE user_role_id = :role_id");
    $db->bind(':role_id', $roleId);
    $result = $db->single();
    return $result ? $result['user_role_name'] : 'Unknown';
}

/**
 * Get office name from ID
 */
function getOfficeName($officeId)
{
    if (!$officeId)
        return 'N/A';

    $db = Database::getInstance();
    $db->query("SELECT office_name FROM offices WHERE office_id = :office_id");
    $db->bind(':office_id', $officeId);
    $result = $db->single();
    return $result ? $result['office_name'] : 'Unknown';
}

/**
 * Format date
 */
function formatDate($date, $format = 'M d, Y h:i A')
{
    if (!$date)
        return 'N/A';
    return date($format, strtotime($date));
}

/**
 * Get status badge HTML
 */
function getStatusBadge($status)
{
    $colors = [
        'pending' => 'warning',
        'approved' => 'success',
        'rejected' => 'danger'
    ];

    $color = $colors[$status] ?? 'secondary';
    return "<span class='badge bg-{$color}'>{$status}</span>";
}

/**
 * Get base URL for redirects
 */
function baseUrl($path = '')
{
    return BASE_URL . ltrim($path, '/');
}

/**
 * Build cache-busted absolute app URL
 */
function versionedUrl($path = '', $version = null)
{
    $url = baseUrl($path);
    $defaultVersion = defined('ASSET_VERSION') ? (string) ASSET_VERSION : (string) SITE_VERSION;
    $resolvedVersion = $version === null ? $defaultVersion : (string) $version;

    if ($resolvedVersion === '') {
        return $url;
    }

    $separator = strpos($url, '?') === false ? '?' : '&';
    return $url . $separator . 'v=' . rawurlencode($resolvedVersion);
}

/**
 * Get base path for file includes
 */
function basePath($path = '')
{
    return BASE_PATH . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
}

// =====================================================
// Initialize on load
// =====================================================

// Start session
initSession();

// Test database connection (uncomment for debugging)
/*
try {
    $testDb = Database::getInstance();
    $testDb->query("SELECT COUNT(*) as count FROM users");
    $result = $testDb->single();
    error_log("Database connection successful. Total users: " . ($result['count'] ?? 0));
} catch (Exception $e) {
    error_log("Database initialization error: " . $e->getMessage());
}
*/
?>
