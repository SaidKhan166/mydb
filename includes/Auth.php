<?php
/**
 * Authentication Class
 * Handles user login, registration, and session management
 */

class Auth {
    private $conn;
    private $table = 'users';
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Register new user
     */
    public function register($username, $email, $password, $first_name, $last_name, $phone) {
        // Validate input
        if (empty($username) || empty($email) || empty($password)) {
            return ['success' => false, 'message' => 'Missing required fields'];
        }
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email format'];
        }
        
        // Validate password strength
        if (strlen($password) < 8) {
            return ['success' => false, 'message' => 'Password must be at least 8 characters'];
        }
        
        // Check if user exists
        $query = "SELECT id FROM " . $this->table . " WHERE email = ? OR username = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ss', $email, $username);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => 'User already exists'];
        }
        
        // Hash password
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        
        // Insert user
        $query = "INSERT INTO " . $this->table . " 
                  (username, email, password_hash, first_name, last_name, phone, status) 
                  VALUES (?, ?, ?, ?, ?, ?, 'active')";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ssssss', $username, $email, $password_hash, $first_name, $last_name, $phone);
        
        if ($stmt->execute()) {
            // Log transaction
            $this->logTransaction(null, 'signup', 'User registration', null, $username);
            return ['success' => true, 'message' => 'Registration successful', 'user_id' => $stmt->insert_id];
        } else {
            return ['success' => false, 'message' => 'Registration failed: ' . $stmt->error];
        }
    }
    
    /**
     * Login user
     */
    public function login($email, $password) {
        if (empty($email) || empty($password)) {
            return ['success' => false, 'message' => 'Email and password required'];
        }
        
        $query = "SELECT id, username, email, password_hash, role, status, login_attempts, locked_until 
                  FROM " . $this->table . " WHERE email = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            return ['success' => false, 'message' => 'Invalid credentials'];
        }
        
        $user = $result->fetch_assoc();
        
        // Check if account is locked
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            return ['success' => false, 'message' => 'Account temporarily locked. Try again later'];
        }
        
        // Check if account is active
        if ($user['status'] !== 'active') {
            return ['success' => false, 'message' => 'Account is ' . $user['status']];
        }
        
        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            $this->incrementLoginAttempts($user['id']);
            return ['success' => false, 'message' => 'Invalid credentials'];
        }
        
        // Reset login attempts
        $query = "UPDATE " . $this->table . " SET login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        
        // Set session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['login_time'] = time();
        
        // Log transaction
        $this->logTransaction($user['id'], 'login', 'User login successful', null, $user['email']);
        
        return ['success' => true, 'message' => 'Login successful', 'user' => $user];
    }
    
    /**
     * Logout user
     */
    public function logout() {
        if (isset($_SESSION['user_id'])) {
            $this->logTransaction($_SESSION['user_id'], 'logout', 'User logout', null, null);
        }
        session_destroy();
        return ['success' => true, 'message' => 'Logout successful'];
    }
    
    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        // Check session timeout
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > SESSION_TIMEOUT) {
            $this->logout();
            return false;
        }
        
        // Update session time
        $_SESSION['login_time'] = time();
        
        return true;
    }
    
    /**
     * Get current user
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        $query = "SELECT id, username, email, first_name, last_name, phone, role, status, 
                  tron_wallet_address, kyc_verified, two_factor_enabled, created_at 
                  FROM " . $this->table . " WHERE id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_assoc();
    }
    
    /**
     * Require authentication
     */
    public function requireAuth() {
        if (!$this->isLoggedIn()) {
            header('Location: /mydb/public/login.php');
            exit;
        }
    }
    
    /**
     * Require admin role
     */
    public function requireAdmin() {
        $this->requireAuth();
        
        if ($_SESSION['role'] !== 'admin') {
            http_response_code(403);
            die('Access denied. Admin role required.');
        }
    }
    
    /**
     * Increment login attempts
     */
    private function incrementLoginAttempts($user_id) {
        $query = "UPDATE " . $this->table . " 
                  SET login_attempts = login_attempts + 1,
                      locked_until = IF(login_attempts >= 4, DATE_ADD(NOW(), INTERVAL 30 MINUTE), locked_until)
                  WHERE id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
    }
    
    /**
     * Log transaction for audit trail
     */
    private function logTransaction($user_id, $type, $description, $old_value, $new_value) {
        $query = "INSERT INTO transaction_logs (user_id, transaction_type, description, old_value, new_value, ip_address, user_agent)
                  VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->conn->prepare($query);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $stmt->bind_param('issssss', $user_id, $type, $description, $old_value, $new_value, $ip, $agent);
        $stmt->execute();
    }
    
    /**
     * Change password
     */
    public function changePassword($user_id, $old_password, $new_password) {
        // Get user
        $query = "SELECT password_hash FROM " . $this->table . " WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        
        if (!$user) {
            return ['success' => false, 'message' => 'User not found'];
        }
        
        // Verify old password
        if (!password_verify($old_password, $user['password_hash'])) {
            return ['success' => false, 'message' => 'Current password is incorrect'];
        }
        
        // Hash new password
        $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
        
        // Update password
        $query = "UPDATE " . $this->table . " SET password_hash = ? WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('si', $new_hash, $user_id);
        
        if ($stmt->execute()) {
            $this->logTransaction($user_id, 'password_change', 'Password changed', null, null);
            return ['success' => true, 'message' => 'Password changed successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to change password'];
        }
    }
}

?>