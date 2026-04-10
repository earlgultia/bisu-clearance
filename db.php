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
define('DEBUG_MODE', file_exists(BASE_PATH . '/.env'));

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
