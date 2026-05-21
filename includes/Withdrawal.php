<?php
/**
 * Withdrawal Management Class
 * Handles USDT withdrawals and processing
 */

class Withdrawal {
    private $conn;
    private $withdrawals_table = 'withdrawals';
    private $wallets_table = 'wallets';
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Create withdrawal request
     */
    public function createWithdrawal($user_id, $wallet_id, $withdrawal_address, $amount) {
        // Validate amount
        if ($amount < MIN_WITHDRAWAL || $amount > MAX_WITHDRAWAL) {
            return ['success' => false, 'message' => "Withdrawal amount must be between " . MIN_WITHDRAWAL . " and " . MAX_WITHDRAWAL . " USDT"];
        }
        
        // Get wallet
        $query = "SELECT balance FROM " . $this->wallets_table . " WHERE id = ? AND user_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ii', $wallet_id, $user_id);
        $stmt->execute();
        $wallet = $stmt->get_result()->fetch_assoc();
        
        if (!$wallet) {
            return ['success' => false, 'message' => 'Wallet not found'];
        }
        
        if ($wallet['balance'] < $amount) {
            return ['success' => false, 'message' => 'Insufficient balance'];
        }
        
        // Calculate fee
        $fee = WITHDRAWAL_FEE;
        $total_amount = $amount + $fee;
        
        if ($wallet['balance'] < $total_amount) {
            return ['success' => false, 'message' => 'Insufficient balance for withdrawal and fee'];
        }
        
        // Start transaction
        $this->conn->begin_transaction();
        
        try {
            // Lock funds
            $query = "UPDATE " . $this->wallets_table . " 
                      SET balance_locked = balance_locked + ?, 
                          balance = balance - ?
                      WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('ddi', $total_amount, $total_amount, $wallet_id);
            $stmt->execute();
            
            // Create withdrawal record
            $status = 'pending';
            $query = "INSERT INTO " . $this->withdrawals_table . " 
                      (user_id, wallet_id, withdrawal_address, amount, fee, status)
                      VALUES (?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('iissds', $user_id, $wallet_id, $withdrawal_address, $amount, $fee, $status);
            
            if ($stmt->execute()) {
                $withdrawal_id = $stmt->insert_id;
                
                // Log transaction
                $this->logTransaction($user_id, 'withdrawal', 'Withdrawal request created', null, 
                    json_encode(['withdrawal_id' => $withdrawal_id, 'amount' => $amount]));
                
                $this->conn->commit();
                
                return [
                    'success' => true,
                    'message' => 'Withdrawal request created',
                    'withdrawal_id' => $withdrawal_id,
                    'amount' => $amount,
                    'fee' => $fee,
                    'total' => $total_amount
                ];
            } else {
                throw new Exception('Failed to create withdrawal record');
            }
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get user withdrawals
     */
    public function getUserWithdrawals($user_id, $limit = 50, $offset = 0) {
        $query = "SELECT w.*, u.username 
                  FROM " . $this->withdrawals_table . " w
                  LEFT JOIN users u ON w.approved_by = u.id
                  WHERE w.user_id = ?
                  ORDER BY w.created_at DESC
                  LIMIT ? OFFSET ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('iii', $user_id, $limit, $offset);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Get withdrawal details
     */
    public function getWithdrawal($withdrawal_id, $user_id = null) {
        $query = "SELECT * FROM " . $this->withdrawals_table . " WHERE id = ?";
        
        if ($user_id) {
            $query .= " AND user_id = ?";
        }
        
        $stmt = $this->conn->prepare($query);
        
        if ($user_id) {
            $stmt->bind_param('ii', $withdrawal_id, $user_id);
        } else {
            $stmt->bind_param('i', $withdrawal_id);
        }
        
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    
    /**
     * Get pending withdrawals (for admin)
     */
    public function getPendingWithdrawals($limit = 50, $offset = 0) {
        $query = "SELECT w.*, u.username, u.email, u.tron_wallet_address
                  FROM " . $this->withdrawals_table . " w
                  JOIN users u ON w.user_id = u.id
                  WHERE w.status IN ('pending', 'processing')
                  ORDER BY w.created_at ASC
                  LIMIT ? OFFSET ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Approve withdrawal (admin)
     */
    public function approveWithdrawal($withdrawal_id, $admin_id) {
        $this->conn->begin_transaction();
        
        try {
            $query = "UPDATE " . $this->withdrawals_table . " 
                      SET status = 'processing', 
                          approved_by = ?, 
                          approved_at = NOW()
                      WHERE id = ? AND status = 'pending'";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('ii', $admin_id, $withdrawal_id);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $this->conn->commit();
                return ['success' => true, 'message' => 'Withdrawal approved'];
            } else {
                throw new Exception('Failed to approve withdrawal');
            }
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Reject withdrawal (admin)
     */
    public function rejectWithdrawal($withdrawal_id, $admin_id, $reason) {
        $this->conn->begin_transaction();
        
        try {
            // Get withdrawal details
            $query = "SELECT wallet_id, amount, fee FROM " . $this->withdrawals_table . " WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('i', $withdrawal_id);
            $stmt->execute();
            $withdrawal = $stmt->get_result()->fetch_assoc();
            
            if (!$withdrawal) {
                throw new Exception('Withdrawal not found');
            }
            
            $total_amount = $withdrawal['amount'] + $withdrawal['fee'];
            
            // Unlock funds
            $query = "UPDATE wallets 
                      SET balance = balance + ?, 
                          balance_locked = balance_locked - ?
                      WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('ddi', $total_amount, $total_amount, $withdrawal['wallet_id']);
            $stmt->execute();
            
            // Update withdrawal status
            $status = 'failed';
            $query = "UPDATE " . $this->withdrawals_table . " 
                      SET status = ?, 
                          error_message = ?,
                          approved_by = ?,
                          approved_at = NOW()
                      WHERE id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('ssii', $status, $reason, $admin_id, $withdrawal_id);
            $stmt->execute();
            
            $this->conn->commit();
            return ['success' => true, 'message' => 'Withdrawal rejected'];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Cancel withdrawal
     */
    public function cancelWithdrawal($withdrawal_id, $user_id) {
        $this->conn->begin_transaction();
        
        try {
            // Get withdrawal details
            $query = "SELECT wallet_id, amount, fee, status FROM " . $this->withdrawals_table . " 
                      WHERE id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('ii', $withdrawal_id, $user_id);
            $stmt->execute();
            $withdrawal = $stmt->get_result()->fetch_assoc();
            
            if (!$withdrawal || $withdrawal['status'] !== 'pending') {
                throw new Exception('Cannot cancel this withdrawal');
            }
            
            $total_amount = $withdrawal['amount'] + $withdrawal['fee'];
            
            // Unlock funds
            $query = "UPDATE wallets 
                      SET balance = balance + ?, 
                          balance_locked = balance_locked - ?
                      WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('ddi', $total_amount, $total_amount, $withdrawal['wallet_id']);
            $stmt->execute();
            
            // Cancel withdrawal
            $status = 'cancelled';
            $query = "UPDATE " . $this->withdrawals_table . " SET status = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('si', $status, $withdrawal_id);
            $stmt->execute();
            
            $this->conn->commit();
            return ['success' => true, 'message' => 'Withdrawal cancelled'];
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get withdrawal statistics
     */
    public function getStats($user_id) {
        $query = "SELECT 
                    COUNT(*) as total_withdrawals,
                    SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as completed_amount,
                    SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending_amount,
                    SUM(CASE WHEN status = 'processing' THEN amount ELSE 0 END) as processing_amount
                  FROM " . $this->withdrawals_table . " WHERE user_id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_assoc();
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
}

?>