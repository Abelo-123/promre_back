<?php
/**
 * Referral System Routes
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// ─── ROUTE: /referral/apply (POST) ────────────────────────────────────────
if ($route === '/referral/apply') {
    $initData = isset($requestData['initData']) ? $requestData['initData'] : '';
    $referralCode = isset($requestData['referralCode']) ? trim($requestData['referralCode']) : '';
    
    $tgId = getTelegramUserId($initData);
    if (!$tgId) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    if (empty($referralCode)) {
        echo json_encode(['success' => false, 'error' => 'Invalid referral code']);
        exit;
    }
    
    try {
        // 1. Get current user
        $stmt = $pdo->prepare('SELECT * FROM auth WHERE tg_id = :tg_id');
        $stmt->execute(['tg_id' => $tgId]);
        $currentUser = $stmt->fetch();
        
        if (!$currentUser) {
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit;
        }
        
        // 2. Check if already referred
        if (!empty($currentUser['referred_by'])) {
            echo json_encode(['success' => false, 'error' => 'You have already used a referral code']);
            exit;
        }
        
        // 3. Check self-referral
        if ($currentUser['referral_code'] === $referralCode) {
            echo json_encode(['success' => false, 'error' => 'You cannot use your own referral code']);
            exit;
        }
        
        // 4. Find referrer
        $stmt = $pdo->prepare('SELECT * FROM auth WHERE referral_code = :code');
        $stmt->execute(['code' => $referralCode]);
        $referrer = $stmt->fetch();
        
        if (!$referrer) {
            echo json_encode(['success' => false, 'error' => 'Invalid referral code']);
            exit;
        }
        
        $refersArray = [];
        if (!empty($referrer['refers'])) {
            $refersArray = is_string($referrer['refers']) ? json_decode($referrer['refers'], true) : $referrer['refers'];
            if (!is_array($refersArray)) $refersArray = [];
        }
        if (!in_array($tgId, $refersArray)) {
            $refersArray[] = $tgId;
        }
        
        // 5. Update inside transaction
        $pdo->beginTransaction();
        try {
            // Bind referred_by to the newly referred user (no balance addition here)
            $stmt = $pdo->prepare('UPDATE auth SET referred_by = :ref_by WHERE tg_id = :tg_id');
            $stmt->execute(['ref_by' => $referrer['tg_id'], 'tg_id' => $tgId]);
            
            // Add referred user tg_id to refers JSON array on referrer
            $stmt = $pdo->prepare('UPDATE auth SET refers = :refers WHERE tg_id = :tg_id');
            $stmt->execute(['refers' => json_encode($refersArray), 'tg_id' => $referrer['tg_id']]);
            
            // Add notification alert for referrer
            $displayName = !empty($currentUser['first_name']) ? $currentUser['first_name'] : (!empty($currentUser['username']) ? $currentUser['username'] : 'Someone');
            $stmt = $pdo->prepare("INSERT INTO alerts (user_id, title, message, type) VALUES (:user_id, 'Referral Success', :msg, 'success')");
            $stmt->execute(['user_id' => $referrer['tg_id'], 'msg' => "User {$displayName} signed up using your code!"]);
            
            $pdo->commit();
        } catch (Exception $txErr) {
            $pdo->rollBack();
            throw $txErr;
        }
        
        // Fetch fresh balance
        $stmt = $pdo->prepare('SELECT balance, referred_by FROM auth WHERE tg_id = :tg_id');
        $stmt->execute(['tg_id' => $tgId]);
        $updatedUser = $stmt->fetch();
        
        echo json_encode([
            'success'     => true,
            'message'     => 'Referral code applied successfully! You will get a 20% bonus on your first deposit.',
            'newBalance'  => $updatedUser ? (float)$updatedUser['balance'] : 0.0,
            'referred_by' => $updatedUser ? $updatedUser['referred_by'] : null
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Internal server error: ' . $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /referral/stats (POST) ────────────────────────────────────────
if ($route === '/referral/stats') {
    $initData = isset($requestData['initData']) ? $requestData['initData'] : '';
    
    $tgId = getTelegramUserId($initData);
    if (!$tgId) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    try {
        // 1. Get total commission earned
        $stmt = $pdo->prepare("SELECT SUM(amount) as total FROM transactions WHERE user_id = :user_id AND type = 'referral_commission'");
        $stmt->execute(['user_id' => $tgId]);
        $row = $stmt->fetch();
        $totalEarned = $row && $row['total'] ? (float)$row['total'] : 0.0;
        
        // 2. Fetch referred users list
        $stmt = $pdo->prepare('SELECT tg_id, username, first_name, last_name, last_login FROM auth WHERE referred_by = :user_id');
        $stmt->execute(['user_id' => $tgId]);
        $referredUsers = $stmt->fetchAll();
        
        $referredList = [];
        foreach ($referredUsers as $u) {
            $refId = $u['tg_id'];
            
            // Get count of deposits made by this user
            $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM transactions WHERE user_id = :user_id AND type = 'deposit'");
            $stmt->execute(['user_id' => $refId]);
            $depRow = $stmt->fetch();
            $depositCount = $depRow ? (int)$depRow['cnt'] : 0;
            
            // Get total commission generated by this user
            $stmt = $pdo->prepare("
                SELECT SUM(amount) as total 
                FROM transactions 
                WHERE user_id = :user_id AND type = 'referral_commission' AND reference_type = 'referral_user' AND reference_id = :ref_id
            ");
            $stmt->execute(['user_id' => $tgId, 'ref_id' => $refId]);
            $commRow = $stmt->fetch();
            $commissionFromUser = $commRow && $commRow['total'] ? (float)$commRow['total'] : 0.0;
            
            $displayName = !empty($u['first_name']) ? $u['first_name'] : (!empty($u['username']) ? $u['username'] : 'User #' . substr($refId, -4));
            
            $referredList[] = [
                'tg_id'             => $refId,
                'name'              => $displayName,
                'deposit_count'     => $depositCount,
                'commission_earned' => $commissionFromUser
            ];
        }
        
        echo json_encode([
            'success'      => true,
            'totalEarned'  => $totalEarned,
            'referredList' => $referredList
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Internal server error: ' . $e->getMessage()]);
    }
    exit;
}
