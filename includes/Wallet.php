<?php
/**
 * Wallet Management Class
 * Handles wallet operations and TRON integration
 */

class Wallet {
    private $conn;
    private $table = 'wallets';
    private $tron_api_url = TRON_API_URL;
    private $tron_api_key = TRON_API_KEY;
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Create new wallet for user
     */
    public function createWallet($user_id, $wallet_address, $wallet_type = 'tron') {
        // Validate TRON address format
        if ($wallet_type === 'tron' && !$this->isValidTronAddress($wallet_address)) {
            return ['success' => false, 'message' => 'Invalid TRON wallet address'];
        }
        
        // Check if wallet already exists
        $query = "SELECT id FROM " . $this->table . " WHERE wallet_address = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('s', $wallet_address);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => 'Wallet already registered'];
        }
        
        // Check if user already has primary wallet
        $query = "SELECT COUNT(*) as count FROM " . $this->table . " WHERE user_id = ? AND is_primary = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $is_primary = ($result['count'] === 0) ? 1 : 0;
        
        // Generate verification token
        $verification_token = bin2hex(random_bytes(32));
        
        // Insert wallet
        $query = "INSERT INTO " . $this->table . " 
                  (user_id, wallet_address, wallet_type, is_primary, verification_token, verified)
                  VALUES (?, ?, ?, ?, ?, 1)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('isssi', $user_id, $wallet_address, $wallet_type, $is_primary, $is_primary);
        
        if ($stmt->execute()) {
            return [
                'success' => true, 
                'message' => 'Wallet added successfully',
                'wallet_id' => $stmt->insert_id
            ];
        } else {
            return ['success' => false, 'message' => 'Failed to add wallet: ' . $stmt->error];
        }
    }
    
    /**
     * Get user wallets
     */
    public function getUserWallets($user_id) {
        $query = "SELECT id, wallet_address, wallet_type, balance, balance_locked, is_primary, verified, created_at 
                  FROM " . $this->table . " WHERE user_id = ? ORDER BY is_primary DESC, created_at DESC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Get primary wallet
     */
    public function getPrimaryWallet($user_id) {
        $query = "SELECT id, wallet_address, wallet_type, balance, balance_locked FROM " . $this->table . " 
                  WHERE user_id = ? AND is_primary = 1 LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_assoc();
    }
    
    /**
     * Get wallet by ID
     */
    public function getWallet($wallet_id) {
        $query = "SELECT * FROM " . $this->table . " WHERE id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('i', $wallet_id);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_assoc();
    }
    
    /**
     * Update wallet balance
     */
    public function updateBalance($wallet_id, $amount) {
        $query = "UPDATE " . $this->table . " SET balance = balance + ?, updated_at = NOW() WHERE id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('di', $amount, $wallet_id);
        
        return $stmt->execute();
    }
    
    /**
     * Lock funds for withdrawal
     */
    public function lockFunds($wallet_id, $amount) {
        $query = "UPDATE " . $this->table . " 
                  SET balance_locked = balance_locked + ?, 
                      balance = balance - ?
                  WHERE id = ? AND balance >= ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ddid', $amount, $amount, $wallet_id, $amount);
        
        return $stmt->execute() && $stmt->affected_rows > 0;
    }
    
    /**
     * Unlock funds
     */
    public function unlockFunds($wallet_id, $amount) {
        $query = "UPDATE " . $this->table . " 
                  SET balance = balance + ?, 
                      balance_locked = balance_locked - ?
                  WHERE id = ? AND balance_locked >= ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ddid', $amount, $amount, $wallet_id, $amount);
        
        return $stmt->execute() && $stmt->affected_rows > 0;
    }
    
    /**
     * Validate TRON address format
     */
    private function isValidTronAddress($address) {
        // TRON address starts with T and is 34 characters long
        if (!preg_match('/^T[1-9A-HJ-NP-Z]{33}$/', $address)) {
            return false;
        }
        return true;
    }
    
    /**
     * Set primary wallet
     */
    public function setPrimaryWallet($user_id, $wallet_id) {
        // Remove primary status from other wallets
        $query = "UPDATE " . $this->table . " SET is_primary = 0 WHERE user_id = ? AND id != ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ii', $user_id, $wallet_id);
        $stmt->execute();
        
        // Set new primary wallet
        $query = "UPDATE " . $this->table . " SET is_primary = 1 WHERE id = ? AND user_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ii', $wallet_id, $user_id);
        
        return $stmt->execute();
    }
}

?>