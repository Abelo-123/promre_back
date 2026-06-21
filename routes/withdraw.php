<?php
/**
 * Wallet Withdrawal Routes
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

function getWithdrawAuthUserId($requestData) {
    $initData = isset($requestData['initData']) ? $requestData['initData'] : '';
    $tgId = getTelegramUserId($initData);
    if (!$tgId) {
        $tgId = isset($requestData['user_id']) ? $requestData['user_id'] : 'unauth_local_user';
    }
    return $tgId;
}

// ─── ROUTE: /withdraw/request (POST) ──────────────────────────────────────
if ($route === '/withdraw/request') {
    try {
        $rawAmount = isset($requestData['amount']) ? $requestData['amount'] : 0;
        $amount = (float)$rawAmount;
        $fullName = isset($requestData['full_name']) ? trim($requestData['full_name']) : '';
        $bankName = isset($requestData['bank_name']) ? trim($requestData['bank_name']) : '';
        $accountNum = isset($requestData['account_number']) ? trim($requestData['account_number']) : '';
        
        $userId = getWithdrawAuthUserId($requestData);
        
        if ($amount <= 0) {
            echo json_encode(['success' => false, 'error' => 'Amount must be greater than zero']);
            exit;
        }
        if (empty($fullName) || empty($bankName) || empty($accountNum)) {
            echo json_encode(['success' => false, 'error' => 'All bank details (Full Name, Bank Name, Account Number) are required']);
            exit;
        }
        
        $pdo->beginTransaction();
        try {
            // Lock user auth row to check referral balance safely
            $stmt = $pdo->prepare('SELECT referral_balance FROM auth WHERE tg_id = :user_id AND bot_id = :bot_id FOR UPDATE');
            $stmt->execute(['user_id' => $userId, 'bot_id' => getCurrentBotId()]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'User not found']);
                exit;
            }
            
            $referralBalance = (float)$user['referral_balance'];
            if ($amount > $referralBalance) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => "Insufficient referral commission balance. You have " . number_format($referralBalance, 2) . " ETB."]);
                exit;
            }
            
            // Deduct referral balance
            $stmt = $pdo->prepare('UPDATE auth SET referral_balance = referral_balance - :amount WHERE tg_id = :user_id AND bot_id = :bot_id');
            $stmt->execute(['amount' => $amount, 'user_id' => $userId, 'bot_id' => getCurrentBotId()]);
            
            // Insert into withdrawals
            $stmt = $pdo->prepare('
                INSERT INTO withdrawals (user_id, bot_id, amount, full_name, bank_name, account_number, status) 
                VALUES (:user_id, :bot_id, :amount, :name, :bank, :account, \'pending\')
            ');
            $stmt->execute([
                'user_id' => $userId,
                'bot_id'  => getCurrentBotId(),
                'amount'  => $amount,
                'name'    => $fullName,
                'bank'    => $bankName,
                'account' => $accountNum
            ]);
            
            // Log ledger transaction
            $newReferralBalance = $referralBalance - $amount;
            $stmt = $pdo->prepare('
                INSERT INTO transactions (user_id, bot_id, type, amount, balance_after, reference_type, description) 
                VALUES (:user_id, :bot_id, \'referral_withdrawal\', :amount, :bal_after, \'withdrawal\', :desc)
            ');
            $stmt->execute([
                'user_id'   => $userId,
                'bot_id'    => getCurrentBotId(),
                'amount'    => -$amount,
                'bal_after' => $newReferralBalance,
                'desc'      => "Referral withdrawal request to {$bankName}"
            ]);
            
            $pdo->commit();
            
            echo json_encode(['success' => true, 'new_referral_balance' => $newReferralBalance]);
        } catch (Exception $txErr) {
            $pdo->rollBack();
            throw $txErr;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /withdraw/history (GET) ───────────────────────────────────────
if ($route === '/withdraw/history') {
    try {
        $userId = getWithdrawAuthUserId($requestData);
        
        $stmt = $pdo->prepare('SELECT * FROM withdrawals WHERE user_id = :user_id AND bot_id = :bot_id ORDER BY created_at DESC');
        $stmt->execute(['user_id' => $userId, 'bot_id' => getCurrentBotId()]);
        $rows = $stmt->fetchAll();
        
        // Normalize outputs
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['amount'] = (float)$r['amount'];
        }
        
        // Fetch current referral balance
        $stmt = $pdo->prepare('SELECT referral_balance FROM auth WHERE tg_id = :user_id AND bot_id = :bot_id');
        $stmt->execute(['user_id' => $userId, 'bot_id' => getCurrentBotId()]);
        $user = $stmt->fetch();
        $referralBalance = $user ? (float)$user['referral_balance'] : 0.0;
        
        echo json_encode(['success' => true, 'history' => $rows, 'referral_balance' => $referralBalance]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /withdraw/admin/list (GET admin) ──────────────────────────────
if ($route === '/withdraw/admin/list') {
    try {
        $stmt = $pdo->prepare('
            SELECT w.*, a.username, a.first_name, a.last_name 
            FROM withdrawals w 
            LEFT JOIN auth a ON w.user_id = a.tg_id AND w.bot_id = a.bot_id
            WHERE w.bot_id = :bot_id
            ORDER BY w.created_at DESC
        ');
        $stmt->execute(['bot_id' => getCurrentBotId()]);
        $rows = $stmt->fetchAll();
        
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['amount'] = (float)$r['amount'];
        }
        
        echo json_encode(['success' => true, 'list' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /withdraw/admin/approve (POST admin) ──────────────────────────
if ($route === '/withdraw/admin/approve') {
    try {
        $id = isset($requestData['id']) ? (int)$requestData['id'] : 0;
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'Missing withdrawal ID']);
            exit;
        }
        
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM withdrawals WHERE id = :id AND bot_id = :bot_id FOR UPDATE');
            $stmt->execute(['id' => $id, 'bot_id' => getCurrentBotId()]);
            $w = $stmt->fetch();
            
            if (!$w) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Withdrawal request not found']);
                exit;
            }
            
            if ($w['status'] === 'done') {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'error' => 'Withdrawal is already completed']);
                exit;
            }
            
            // Mark completed
            $stmt = $pdo->prepare('UPDATE withdrawals SET status = \'done\' WHERE id = :id AND bot_id = :bot_id');
            $stmt->execute(['id' => $id, 'bot_id' => getCurrentBotId()]);
            
            // Add user alert notification
            $stmt = $pdo->prepare('
                INSERT INTO alerts (user_id, bot_id, title, message, type) 
                VALUES (:user_id, :bot_id, \'Withdrawal Done\', :msg, \'success\')
            ');
            $stmt->execute([
                'user_id' => $w['user_id'],
                'bot_id'  => getCurrentBotId(),
                'msg'     => "Your withdrawal request of " . number_format((float)$w['amount'], 2) . " ETB has been marked as DONE and transferred to your bank account!"
            ]);
            
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $txErr) {
            $pdo->rollBack();
            throw $txErr;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}
