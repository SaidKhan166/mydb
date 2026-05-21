<?php
/**
 * Helper Functions
 */

/**
 * Format currency
 */
function formatCurrency($amount, $decimals = 2) {
    return number_format($amount, $decimals, '.', ',');
}

/**
 * Format date
 */
function formatDate($date, $format = 'd-m-Y H:i') {
    return date($format, strtotime($date));
}

/**
 * Get status badge
 */
function getStatusBadge($status) {
    $badges = [
        'pending' => '<span class="badge badge-warning">Pending</span>',
        'processing' => '<span class="badge badge-info">Processing</span>',
        'completed' => '<span class="badge badge-success">Completed</span>',
        'failed' => '<span class="badge badge-danger">Failed</span>',
        'cancelled' => '<span class="badge badge-secondary">Cancelled</span>',
        'confirmed' => '<span class="badge badge-success">Confirmed</span>',
        'active' => '<span class="badge badge-success">Active</span>',
    ];
    
    return isset($badges[$status]) ? $badges[$status] : '<span class="badge badge-secondary">' . ucfirst($status) . '</span>';
}

/**
 * Redirect with message
 */
function redirect($url, $message = null, $type = 'info') {
    if ($message) {
        $_SESSION['message'] = $message;
        $_SESSION['message_type'] = $type;
    }
    header('Location: ' . $url);
    exit;
}

/**
 * Get flash message
 */
function getFlashMessage() {
    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        $type = $_SESSION['message_type'] ?? 'info';
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
        return [
            'message' => $message,
            'type' => $type
        ];
    }
    return null;
}

/**
 * Display flash message HTML
 */
function displayFlashMessage() {
    $flash = getFlashMessage();
    if ($flash) {
        $type_map = [
            'success' => 'alert-success',
            'error' => 'alert-danger',
            'warning' => 'alert-warning',
            'info' => 'alert-info'
        ];
        $class = $type_map[$flash['type']] ?? 'alert-info';
        echo '<div class="alert ' . $class . ' alert-dismissible fade show" role="alert">
                ' . htmlspecialchars($flash['message']) . '
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
              </div>';
    }
}

/**
 * Validate TRON address
 */
function isValidTronAddress($address) {
    return preg_match('/^T[1-9A-HJ-NP-Z]{33}$/', $address) === 1;
}

/**
 * Sanitize input
 */
function sanitize($data) {
    return htmlspecialchars(strip_tags($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Check if user is admin
 */
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Get user ID from session
 */
function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user from session
 */
function getCurrentUserInfo() {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'email' => $_SESSION['email'] ?? null,
        'role' => $_SESSION['role'] ?? null
    ];
}

?>