<?php
/**
 * Database Configuration
 * XAMPP MySQL Connection
 */

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'usdt_withdrawal_system');
define('DB_PORT', 3306);

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

// Check connection
if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed: ' . $conn->connect_error]));
}

// Set charset to utf8
$conn->set_charset("utf8mb4");

// Error reporting
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('SESSION_TIMEOUT', 1800); // 30 minutes

// API Keys and Settings
define('TRON_API_KEY', 'your_trongrid_api_key_here');
define('TRON_API_URL', 'https://api.trongrid.io');
define('TRON_MAINNET', true);

// Withdrawal Settings
define('MIN_WITHDRAWAL', 10); // USDT
define('MAX_WITHDRAWAL', 50000); // USDT
define('WITHDRAWAL_FEE', 1); // USDT
define('WITHDRAWAL_TIMEOUT', 3600); // 1 hour

// Security Settings
define('ENCRYPTION_KEY', 'your_secret_encryption_key_change_this');
define('HASH_ALGO', 'sha256');

// Pagination
define('ITEMS_PER_PAGE', 10);

?>