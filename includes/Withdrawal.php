<?php
/**
 * Withdrawal Management Class
 * Handles USDT withdrawals and processing
 */

class Withdrawal {
    private $conn;
    private $withdrawals_table = 'withdrawals';
    private $wallets_table = 'wallets';
    private $deposits_table = 'deposits';
    private $tron_api_url = TRON_API_URL;
    private $tron_api_key = TRON_API_KEY;
    
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
                $this->logTransaction($user_id, 'withdrawal_request', 'Withdrawal request created', null, 
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
        $query = "UPDATE " . $this->withdrawals_table . " 
                  SET status = 'processing', 
                      approved_by = ?, 
                      approved_at = NOW()
                  WHERE id = ? AND status = 'pending'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ii', $admin_id, $withdrawal_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $this->logTransaction($admin_id, 'withdrawal_approved', 'Withdrawal approved', null, 
                json_encode(['withdrawal_id' => $withdrawal_id]));
            
            return ['success' => true, 'message' => 'Withdrawal approved'];
        } else {
            return ['success' => false, 'message' => 'Failed to approve withdrawal'];
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
            $query = "UPDATE " . $this->wallets_table . " 
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
            
            if ($stmt->execute()) {
                $this->conn->commit();
                return ['success' => true, 'message' => 'Withdrawal rejected'];
            } else {
                throw new Exception('Failed to update withdrawal');
            }
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Process withdrawal (send to blockchain)
     */
    public function processWithdrawal($withdrawal_id) {
        // Get withdrawal details
        $query = "SELECT w.*, u.tron_wallet_address, u.id as user_id
                  FROM " . $this->withdrawals_table . " w
                  JOIN users u ON w.user_id = u.id
                  WHERE w.id = ? AND w.status = 'processing'";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('i', $withdrawal_id);
        $stmt->execute();
        $withdrawal = $stmt->get_result()->fetch_assoc();
        
        if (!$withdrawal) {
            return ['success' => false, 'message' => 'Withdrawal not found or not in processing status'];
        }
        
        // Send transaction to TRON blockchain
        $tx_result = $this->sendTronTransaction(
            $withdrawal['tron_wallet_address'],
            $withdrawal['withdrawal_address'],
            $withdrawal['amount']
        );
        
        if (!$tx_result['success']) {
            // Increment retry count
            $query = "UPDATE " . $this->withdrawals_table . " 
                      SET retry_count = retry_count + 1,
                          error_message = ?
                      WHERE id = ? AND retry_count < max_retries";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('si', $tx_result['message'], $withdrawal_id);
            $stmt->execute();
            
            if ($stmt->affected_rows === 0) {
                // Max retries exceeded, mark as failed
                $status = 'failed';
                $query = "UPDATE " . $this->withdrawals_table . " SET status = ? WHERE id = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->bind_param('si', $status, $withdrawal_id);
                $stmt->execute();
            }
            
            return $tx_result;
        }
        
        // Update withdrawal with transaction hash
        $status = 'completed';
        $tx_hash = $tx_result['transaction_hash'];
        
        $query = "UPDATE " . $this->withdrawals_table . " 
                  SET status = ?, 
                      transaction_hash = ?,
                      processed_at = NOW(),
                      completed_at = NOW()
                  WHERE id = ?";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param('ssi', $status, $tx_hash, $withdrawal_id);
        
        if ($stmt->execute()) {
            // Log transaction
            $this->logTransaction($withdrawal['user_id'], 'withdrawal_completed', 'Withdrawal completed', null, 
                json_encode(['withdrawal_id' => $withdrawal_id, 'tx_hash' => $tx_hash]));
            
            return [
                'success' => true,
                'message' => 'Withdrawal completed',
                'transaction_hash' => $tx_hash
            ];
        } else {
            return ['success' => false, 'message' => 'Failed to update withdrawal status'];
        }
    }
    
    /**
     * Send TRON transaction (simplified implementation)
     */
    private function sendTronTransaction($from_address, $to_address, $amount) {
        // In production, implement actual TRON transaction signing and broadcasting
        // This is a placeholder for security reasons
        
        // Generate mock transaction hash
        $tx_hash = '0x' . bin2hex(random_bytes(32));
        
        // TODO: Implement actual TRON transaction:
        // 1. Create transaction object
        // 2. Sign with private key
        // 3. Broadcast to TRON network
        // 4. Wait for confirmation
        
        return [
            'success' => true,
            'transaction_hash' => $tx_hash,
            'message' => 'Transaction sent (mock)'
        ];
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
            $query = "UPDATE " . $this->wallets_table . " 
                      SET balance = balance + ?, 
                          balance_locked = balance_locked - ?
                      WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('ddi', $total_amount, $total_amount, $withdrawal['wallet_id']);
            $stmt->execute();
            
            // Cancel withdrawal
            $status = 'cancelled';
            $query = "UPDATE " . $this->withdrawals_table . " SET status = ? WHERE id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param('si', $status, $withdrawal_id);
            
            if ($stmt->execute()) {
                $this->conn->commit();
                return ['success' => true, 'message' => 'Withdrawal cancelled'];
            } else {
                throw new Exception('Failed to cancel withdrawal');
            }
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
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
