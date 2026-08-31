<?php
/**
 * Deposits and Wallet Balance Handlers (Chapa Integration)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../notify.php';
require_once __DIR__ . '/../wallet.php';

// Chapa API Helper functions
function chapaInitializePayment($data) {
    global $chapaSecretKey, $chapaBaseUrl, $siteUrl;
    
    $email = isset($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL) ? $data['email'] : 'customer@paxyo.com';
    $firstName = !empty($data['first_name']) ? $data['first_name'] : 'User';
    $lastName = !empty($data['last_name']) ? $data['last_name'] : '';
    
    // Ensure siteUrl has protocol
    $baseUrl = (strpos($siteUrl, 'http') === 0) ? $siteUrl : "https://{$siteUrl}";
    $callbackUrl = (strpos($baseUrl, 'localhost') !== false) ? 'https://webhook.site/dummy-paxyo-callback' : "{$baseUrl}/api/chapa-callback";
    $returnUrl = !empty($data['return_url']) ? $data['return_url'] : "{$baseUrl}/api/chapa-callback";
    
    $payload = [
        'amount'        => $data['amount'],
        'currency'      => 'ETB',
        'email'         => $email,
        'first_name'    => $firstName,
        'last_name'     => $lastName,
        'tx_ref'        => $data['tx_ref'],
        'callback_url'  => $callbackUrl,
        'return_url'    => $returnUrl,
        'meta'          => [
            'hide_receipt' => true
        ],
        'customization' => [
            'title'       => 'Paxyo Deposit',
            'description' => 'Wallet deposit'
        ],
        'meta'          => [
            'hide_receipt' => true
        ]
    ];
    
    // Log payload for debugging
    file_put_contents(__DIR__ . '/../chapa_payload.log', "[" . date('Y-m-d H:i:s') . "] URL: {$chapaBaseUrl}/transaction/initialize\nPayload: " . json_encode($payload, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);

    $res = curlRequest('POST', "{$chapaBaseUrl}/transaction/initialize", [
        "Authorization: Bearer {$chapaSecretKey}",
        "Content-Type: application/json"
    ], json_encode($payload), 20);
    
    $decoded = json_decode($res['body'], true);
    return [
        'success'  => $res['code'] === 200 && isset($decoded['status']) && $decoded['status'] === 'success',
        'code'     => $res['code'],
        'data'     => isset($decoded['data']) ? $decoded['data'] : [],
        'message'  => isset($decoded['message']) ? $decoded['message'] : 'Unknown error',
        'raw'      => $decoded
    ];
}

function chapaVerifyPayment($txRef) {
    global $chapaSecretKey, $chapaBaseUrl;
    
    // Add cache buster timestamp
    $url = "{$chapaBaseUrl}/transaction/verify/{$txRef}?_t=" . time();
    
    $res = curlRequest('GET', $url, [
        "Authorization: Bearer {$chapaSecretKey}",
        "Cache-Control: no-cache"
    ], null, 20);
    
    $decoded = json_decode($res['body'], true);
    return [
        'success'  => $res['code'] === 200 && isset($decoded['status']) && $decoded['status'] === 'success',
        'code'     => $res['code'],
        'data'     => isset($decoded['data']) ? $decoded['data'] : [],
        'message'  => isset($decoded['message']) ? $decoded['message'] : 'Unknown error',
        'raw'      => $decoded
    ];
}

// ─── ROUTE: /balance (POST) ───────────────────────────────────────────────
if ($route === '/balance') {
    $initData = isset($requestData['initData']) ? $requestData['initData'] : '';
    $tgId = getTelegramUserId($initData);
    if (!$tgId) {
        $tgId = isset($requestData['user_id']) ? $requestData['user_id'] : '999999999999';
    }
    
    try {
        $stmt = $pdo->prepare('SELECT balance FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
        $stmt->execute(['tg_id' => $tgId, 'bot_id' => getCurrentBotId()]);
        $row = $stmt->fetch();
        $balance = $row ? (float)$row['balance'] : 0.0;
        
        echo json_encode(['success' => true, 'balance' => $balance]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /deposits (GET / POST) ─────────────────────────────────────────
if ($route === '/deposits') {
    $initData = isset($requestData['initData']) ? $requestData['initData'] : '';
    $limitStr = isset($requestData['limit']) ? $requestData['limit'] : '20';
    $limit = (int)$limitStr ?: 20;
    if ($limit <= 0) $limit = 20;
    if ($limit > 50) $limit = 50;
    
    $tgId = getTelegramUserId($initData);
    if (!$tgId) {
        $tgId = isset($requestData['user_id']) ? $requestData['user_id'] : '999999999999';
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, amount, tx_ref as reference_id, status, 'Chapa' as method, created_at, completed_at 
            FROM deposits 
            WHERE user_id = :user_id AND bot_id = :bot_id
            ORDER BY created_at DESC 
            LIMIT :limit
        ");
        $stmt->bindValue(':user_id', $tgId, PDO::PARAM_STR);
        $stmt->bindValue(':bot_id', getCurrentBotId(), PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        
        // Format numeric outputs
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['amount'] = (float)$r['amount'];
        }
        
        echo json_encode($rows);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /deposit (POST) ───────────────────────────────────────────────
if ($route === '/deposit') {
    try {
        $rawAmount = isset($requestData['amount']) ? $requestData['amount'] : 0;
        $amount = (float)$rawAmount;
        $initData = isset($requestData['initData']) ? $requestData['initData'] : '';
        $txRef = isset($requestData['tx_ref']) ? $requestData['tx_ref'] : null;
        $userId = isset($requestData['user_id']) ? $requestData['user_id'] : null;
        $returnUrl = isset($requestData['return_url']) ? $requestData['return_url'] : null;
        
        if ($amount < $minDeposit) {
            echo json_encode(['success' => false, 'error' => "Minimum deposit is {$minDeposit} ETB"]);
            exit;
        }
        if ($amount > $maxDeposit) {
            echo json_encode(['success' => false, 'error' => "Maximum deposit is " . number_format($maxDeposit) . " ETB"]);
            exit;
        }
        
        $tgId = getTelegramUserId($initData);
        if (!$tgId) {
            $tgId = $userId ?: '999999999999';
        }
        
        // Find or create user
        $stmt = $pdo->prepare('SELECT * FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
        $stmt->execute(['tg_id' => $tgId, 'bot_id' => getCurrentBotId()]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $stmt = $pdo->prepare("INSERT INTO auth (tg_id, bot_id, balance, auth_provider, last_login) VALUES (:tg_id, :bot_id, 0.00, 'telegram', NOW())");
            $stmt->execute(['tg_id' => $tgId, 'bot_id' => getCurrentBotId()]);
            
            $stmt = $pdo->prepare('SELECT * FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
            $stmt->execute(['tg_id' => $tgId, 'bot_id' => getCurrentBotId()]);
            $user = $stmt->fetch();
        }
        
        // FLOW A: INLINE SDK MODE (tx_ref provided by client SDK)
        if (!empty($txRef)) {
            $stmt = $pdo->prepare('SELECT id FROM deposits WHERE tx_ref = :tx_ref AND bot_id = :bot_id');
            $stmt->execute(['tx_ref' => $txRef, 'bot_id' => getCurrentBotId()]);
            $existing = $stmt->fetch();
            
            if ($existing) {
                echo json_encode([
                    'success' => true,
                    'tx_ref' => $txRef,
                    'deposit_id' => (int)$existing['id']
                ]);
                exit;
            }
            
            // Create pending deposit
            $stmt = $pdo->prepare("INSERT INTO deposits (user_id, bot_id, amount, tx_ref, status) VALUES (:user_id, :bot_id, :amount, :tx_ref, 'pending')");
            $stmt->execute(['user_id' => $tgId, 'bot_id' => getCurrentBotId(), 'amount' => $amount, 'tx_ref' => $txRef]);
            $depositId = $pdo->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'tx_ref' => $txRef,
                'deposit_id' => (int)$depositId
            ]);
            exit;
        }
        
        // FLOW B: REDIRECT MODE (server generates reference + calls Chapa API)
        $generatedTxRef = "DEP-{$tgId}-" . time() . "-" . bin2hex(random_bytes(4));
        
        $stmt = $pdo->prepare("INSERT INTO deposits (user_id, bot_id, amount, tx_ref, status) VALUES (:user_id, :bot_id, :amount, :tx_ref, 'pending')");
        $stmt->execute(['user_id' => $tgId, 'bot_id' => getCurrentBotId(), 'amount' => $amount, 'tx_ref' => $generatedTxRef]);
        
        // Initialize payment with Chapa
        $chapaResult = chapaInitializePayment([
            'amount'     => $amount,
            'email'      => isset($user['email']) ? $user['email'] : null,
            'first_name' => isset($user['first_name']) ? $user['first_name'] : null,
            'last_name'  => isset($user['last_name']) ? $user['last_name'] : null,
            'tx_ref'     => $generatedTxRef,
            'return_url' => $returnUrl
        ]);
        
        if ($chapaResult['success'] && isset($chapaResult['data']['checkout_url'])) {
            $checkoutUrl = $chapaResult['data']['checkout_url'];
            
            $stmt = $pdo->prepare('UPDATE deposits SET checkout_url = :checkout_url WHERE tx_ref = :tx_ref AND bot_id = :bot_id');
            $stmt->execute(['checkout_url' => $checkoutUrl, 'tx_ref' => $generatedTxRef, 'bot_id' => getCurrentBotId()]);
            
            echo json_encode([
                'success'      => true,
                'checkout_url' => $checkoutUrl,
                'tx_ref'       => $generatedTxRef
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error'   => !empty($chapaResult['message']) ? $chapaResult['message'] : 'Failed to initialize Chapa payment'
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /complete-deposit (POST) ──────────────────────────────────────
if ($route === '/complete-deposit') {
    try {
        $txRef = isset($requestData['tx_ref']) ? $requestData['tx_ref'] : null;
        $amount = isset($requestData['amount']) ? (float)$requestData['amount'] : 0.0;
        $chapaRef = isset($requestData['chapa_ref']) ? $requestData['chapa_ref'] : '';
        $initData = isset($requestData['initData']) ? $requestData['initData'] : '';
        
        $tgId = getTelegramUserId($initData);
        if (!$tgId) {
            $tgId = isset($requestData['user_id']) ? $requestData['user_id'] : '999999999999';
        }
        
        if (empty($txRef)) {
            echo json_encode(['success' => false, 'error' => 'Missing transaction reference']);
            exit;
        }
        
        // 1. Check if already successfully processed
        $stmt = $pdo->prepare('SELECT * FROM deposits WHERE tx_ref = :tx_ref');
        $stmt->execute(['tx_ref' => $txRef]);
        $deposit = $stmt->fetch();
        
        if ($deposit) {
            $_SESSION['bot_id'] = $deposit['bot_id'];
        }
        
        if ($deposit && $deposit['status'] === 'success') {
            $stmt = $pdo->prepare('SELECT balance FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
            $stmt->execute(['tg_id' => $deposit['user_id'], 'bot_id' => getCurrentBotId()]);
            $u = $stmt->fetch();
            echo json_encode([
                'success'           => true,
                'new_balance'       => $u ? (float)$u['balance'] : 0.0,
                'already_completed' => true
            ]);
            exit;
        }
        
        // 2. Call Chapa verification API outside database lock
        $verifyResult = chapaVerifyPayment($txRef);
        $chapaStatus = isset($verifyResult['data']['status']) ? strtolower($verifyResult['data']['status']) : '';
        $isSuccess = $verifyResult['success'] && ($chapaStatus === 'success' || $chapaStatus === 'paid');
        
        // 3. Write updates inside database transaction
        $pdo->beginTransaction();
        try {
            // Lock deposit row FOR UPDATE
            $stmt = $pdo->prepare('SELECT * FROM deposits WHERE tx_ref = :tx_ref FOR UPDATE');
            $stmt->execute(['tx_ref' => $txRef]);
            $deposit = $stmt->fetch();
            
            if ($deposit) {
                $_SESSION['bot_id'] = $deposit['bot_id'];
            }
            
            if (!$deposit) {
                if ($amount > 0) {
                    $stmt = $pdo->prepare("INSERT INTO deposits (user_id, bot_id, amount, tx_ref, status) VALUES (:user_id, :bot_id, :amount, :tx_ref, 'pending')");
                    $stmt->execute(['user_id' => $tgId, 'bot_id' => getCurrentBotId(), 'amount' => $amount, 'tx_ref' => $txRef]);
                    
                    $stmt = $pdo->prepare('SELECT * FROM deposits WHERE tx_ref = :tx_ref FOR UPDATE');
                    $stmt->execute(['tx_ref' => $txRef]);
                    $deposit = $stmt->fetch();
                    if ($deposit) {
                        $_SESSION['bot_id'] = $deposit['bot_id'];
                    }
                } else {
                    $pdo->rollBack();
                    echo json_encode(['success' => false, 'error' => 'Deposit record not found']);
                    exit;
                }
            }
            
            if ($deposit['status'] === 'success') {
                $pdo->rollBack();
                $stmt = $pdo->prepare('SELECT balance FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
                $stmt->execute(['tg_id' => $deposit['user_id'], 'bot_id' => getCurrentBotId()]);
                $u = $stmt->fetch();
                echo json_encode([
                    'success'           => true,
                    'new_balance'       => $u ? (float)$u['balance'] : 0.0,
                    'already_completed' => true
                ]);
                exit;
            }
            
            if ($isSuccess) {
                $verifiedAmount = isset($verifyResult['data']['amount']) ? (float)$verifyResult['data']['amount'] : (float)$deposit['amount'];
                $verifiedChapaRef = isset($verifyResult['data']['reference']) ? $verifyResult['data']['reference'] : $chapaRef;
                $responseJson = json_encode($verifyResult['raw']);
                
                $stmt = $pdo->prepare("UPDATE deposits SET status = 'success', chapa_tx_ref = :chapa_ref, chapa_response = :resp, completed_at = NOW() WHERE id = :id AND bot_id = :bot_id");
                $stmt->execute([
                    'chapa_ref' => $verifiedChapaRef,
                    'resp'      => $responseJson,
                    'id'        => $deposit['id'],
                    'bot_id'    => getCurrentBotId()
                ]);
                
                $newBalance = processTransaction(
                    (string)$deposit['user_id'],
                    'deposit',
                    $verifiedAmount,
                    "Chapa deposit (verified) - {$verifiedChapaRef}",
                    $pdo,
                    'deposit',
                    (int)$deposit['id']
                );

                // Increment total_deposit setting safely
                try {
                    $cBotId = getCurrentBotId();
                    $stmtGet = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'total_deposit' AND bot_id = :bot_id LIMIT 1");
                    $stmtGet->execute(['bot_id' => $cBotId]);
                    $currentTotal = (float)($stmtGet->fetchColumn() ?: 0.0);
                    $newTotal = $currentTotal + $verifiedAmount;

                    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'total_deposit' AND bot_id = :bot_id");
                    $stmtCheck->execute(['bot_id' => $cBotId]);
                    $exists = (int)$stmtCheck->fetchColumn() > 0;

                    if ($exists) {
                        $stmtTot = $pdo->prepare("UPDATE settings SET setting_value = :val WHERE setting_key = 'total_deposit' AND bot_id = :bot_id");
                        $stmtTot->execute(['val' => (string)$newTotal, 'bot_id' => $cBotId]);
                    } else {
                        $stmtTot = $pdo->prepare("INSERT INTO settings (setting_key, bot_id, setting_value) VALUES ('total_deposit', :bot_id, :val)");
                        $stmtTot->execute(['bot_id' => $cBotId, 'val' => (string)$newTotal]);
                    }
                } catch (Exception $totErr) {}
                
                $pdo->commit();
                
                // Trigger Async Notification
                try {
                    $stmt = $pdo->prepare('SELECT first_name FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
                    $stmt->execute(['tg_id' => (string)$deposit['user_id'], 'bot_id' => getCurrentBotId()]);
                    $row = $stmt->fetch();
                    $firstName = $row ? $row['first_name'] : 'User';
                    notifyDeposit((string)$deposit['user_id'], $verifiedAmount, $firstName);
                } catch (Exception $e) {}
                
                echo json_encode([
                    'success'     => true,
                    'new_balance' => $newBalance,
                    'verified'    => true
                ]);
            } else {
                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'pending' => true,
                    'message' => 'Payment is processing. Balance will update shortly.'
                ]);
            }
            
        } catch (Exception $txErr) {
            $pdo->rollBack();
            throw $txErr;
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'System error: ' . $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /verify-deposit (POST) ────────────────────────────────────────
if ($route === '/verify-deposit') {
    try {
        $txRef = isset($requestData['tx_ref']) ? $requestData['tx_ref'] : null;
        $initData = isset($requestData['initData']) ? $requestData['initData'] : '';
        
        $tgId = getTelegramUserId($initData);
        if (!$tgId) {
            $tgId = isset($requestData['user_id']) ? $requestData['user_id'] : '999999999999';
        }
        
        if (empty($txRef)) {
            echo json_encode(['success' => false, 'error' => 'Missing transaction reference']);
            exit;
        }
        
        // Check local database
        $stmt = $pdo->prepare('SELECT status, amount, user_id, bot_id FROM deposits WHERE tx_ref = :tx_ref');
        $stmt->execute(['tx_ref' => $txRef]);
        $depositCheck = $stmt->fetch();
        
        if ($depositCheck) {
            $_SESSION['bot_id'] = $depositCheck['bot_id'];
        }
        
        if (!$depositCheck) {
            echo json_encode(['success' => false, 'message' => 'Deposit record not found in our system']);
            exit;
        }
        
        if ($depositCheck['status'] === 'success') {
            $stmt = $pdo->prepare('SELECT balance FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
            $stmt->execute(['tg_id' => $tgId, 'bot_id' => getCurrentBotId()]);
            $u = $stmt->fetch();
            echo json_encode([
                'success'           => true,
                'new_balance'       => $u ? (float)$u['balance'] : 0.0,
                'already_completed' => true,
                'message'           => 'Payment already verified and credited.'
            ]);
            exit;
        }
        
        // Verify with Chapa API
        $verifyResult = chapaVerifyPayment($txRef);
        
        if (!$verifyResult['success'] && $verifyResult['code'] !== 200) {
            echo json_encode([
                'success'      => false,
                'chapa_status' => 'error',
                'message'      => 'Chapa API error: ' . $verifyResult['message'],
                'bank_message' => "The payment provider returned an error ({$verifyResult['code']}). Contact support with Ref: {$txRef}"
            ]);
            exit;
        }
        
        $chapaData = $verifyResult['data'];
        $chapaStatus = isset($chapaData['status']) ? strtolower($chapaData['status']) : '';
        $isActuallySuccess = ($chapaStatus === 'success' || $chapaStatus === 'paid' || $chapaStatus === 'completed');
        
        if (!$isActuallySuccess) {
            $isFailed = ($chapaStatus === 'failed' || strpos($chapaStatus, 'reject') !== false || strpos($chapaStatus, 'cancel') !== false);
            if ($isFailed) {
                $stmt = $pdo->prepare("UPDATE deposits SET status = 'failed' WHERE tx_ref = :tx_ref AND bot_id = :bot_id");
                $stmt->execute(['tx_ref' => $txRef, 'bot_id' => getCurrentBotId()]);
                
                echo json_encode([
                    'success'      => false,
                    'chapa_status' => 'failed',
                    'message'      => isset($chapaData['failure_reason']) ? $chapaData['failure_reason'] : 'Payment was declined or cancelled.',
                    'bank_message' => isset($chapaData['charge_message']) ? $chapaData['charge_message'] : 'Transaction failed.'
                ]);
            } else {
                echo json_encode([
                    'success'      => false,
                    'chapa_status' => 'pending',
                    'message'      => 'Waiting for provider confirmation...',
                    'bank_message' => isset($chapaData['charge_message']) ? $chapaData['charge_message'] : 'Transaction is processing.'
                ]);
            }
            exit;
        }
        
        // Success Flow
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM deposits WHERE tx_ref = :tx_ref FOR UPDATE');
            $stmt->execute(['tx_ref' => $txRef]);
            $deposit = $stmt->fetch();
            if ($deposit) {
                $_SESSION['bot_id'] = $deposit['bot_id'];
            }
            
            if (!$deposit || $deposit['status'] === 'success') {
                $pdo->rollBack();
                $stmt = $pdo->prepare('SELECT balance FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
                $stmt->execute(['tg_id' => $tgId, 'bot_id' => getCurrentBotId()]);
                $u = $stmt->fetch();
                echo json_encode([
                    'success'           => true,
                    'new_balance'       => $u ? (float)$u['balance'] : 0.0,
                    'already_completed' => true
                ]);
                exit;
            }
            
            $verifiedAmount = isset($chapaData['amount']) ? (float)$chapaData['amount'] : (float)$deposit['amount'];
            $chapaRef = isset($chapaData['reference']) ? $chapaData['reference'] : '';
            $responseJson = json_encode($verifyResult['raw']);
            
            $stmt = $pdo->prepare("UPDATE deposits SET status = 'success', chapa_tx_ref = :chapa_ref, chapa_response = :resp, completed_at = NOW() WHERE id = :id AND bot_id = :bot_id");
            $stmt->execute([
                'chapa_ref' => $chapaRef,
                'resp'      => $responseJson,
                'id'        => $deposit['id'],
                'bot_id'    => getCurrentBotId()
            ]);
            
            $newBalance = processTransaction(
                (string)$deposit['user_id'],
                'deposit',
                $verifiedAmount,
                "Chapa deposit (verified) - {$chapaRef}",
                $pdo,
                'deposit',
                (int)$deposit['id']
            );
            
            // Increment total_deposit setting safely
            try {
                $cBotId = getCurrentBotId();
                $stmtGet = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'total_deposit' AND bot_id = :bot_id LIMIT 1");
                $stmtGet->execute(['bot_id' => $cBotId]);
                $currentTotal = (float)($stmtGet->fetchColumn() ?: 0.0);
                $newTotal = $currentTotal + $verifiedAmount;

                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'total_deposit' AND bot_id = :bot_id");
                $stmtCheck->execute(['bot_id' => $cBotId]);
                $exists = (int)$stmtCheck->fetchColumn() > 0;

                if ($exists) {
                    $stmtTot = $pdo->prepare("UPDATE settings SET setting_value = :val WHERE setting_key = 'total_deposit' AND bot_id = :bot_id");
                    $stmtTot->execute(['val' => (string)$newTotal, 'bot_id' => $cBotId]);
                } else {
                    $stmtTot = $pdo->prepare("INSERT INTO settings (setting_key, bot_id, setting_value) VALUES ('total_deposit', :bot_id, :val)");
                    $stmtTot->execute(['bot_id' => $cBotId, 'val' => (string)$newTotal]);
                }
            } catch (Exception $totErr) {}
            
            $pdo->commit();
            
            // Notify deposit
            try {
                $stmt = $pdo->prepare('SELECT first_name FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
                $stmt->execute(['tg_id' => (string)$deposit['user_id'], 'bot_id' => getCurrentBotId()]);
                $row = $stmt->fetch();
                $firstName = $row ? $row['first_name'] : 'User';
                notifyDeposit((string)$deposit['user_id'], $verifiedAmount, $firstName);
            } catch (Exception $e) {}
            
            echo json_encode([
                'success'     => true,
                'new_balance' => $newBalance,
                'message'     => 'Payment verified and balance updated!'
            ]);
            
        } catch (Exception $txErr) {
            $pdo->rollBack();
            throw $txErr;
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'System error during verification', 'debug' => $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /chapa-callback (GET / POST) ──────────────────────────────────
if ($route === '/chapa-callback') {
    // 1. Signature Verification (Only for POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        $signature = isset($headers['chapa-signature']) ? $headers['chapa-signature'] : null;
        
        if ($signature && $chapaSecretKey) {
            $rawPost = file_get_contents('php://input');
            $hash = hash_hmac('sha256', $rawPost, $chapaSecretKey);
            if ($signature !== $hash) {
                http_response_code(401);
                echo "Forbidden";
                exit;
            }
        }
    }
    
    $txRef = isset($requestData['trx_ref']) ? $requestData['trx_ref'] : (isset($requestData['tx_ref']) ? $requestData['tx_ref'] : '');
    
    if (empty($txRef)) {
        echo json_encode(['success' => false, 'message' => 'Missing tx_ref']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare('SELECT status, bot_id FROM deposits WHERE tx_ref = :tx_ref');
        $stmt->execute(['tx_ref' => $txRef]);
        $depositCheck = $stmt->fetch();
        
        if (!$depositCheck) {
            echo json_encode(['success' => false, 'message' => 'Deposit not found']);
            exit;
        }
        
        // Dynamically set context bot_id
        $currentBotId = $depositCheck['bot_id'];
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['bot_id'] = $currentBotId;
        
        if ($depositCheck['status'] === 'success') {
            echo json_encode(['success' => true, 'message' => 'Already processed']);
            exit;
        }
        
        // 2. Call Chapa verification outside DB lock
        $verifyResult = chapaVerifyPayment($txRef);
        $chapaStatus = isset($verifyResult['data']['status']) ? strtolower($verifyResult['data']['status']) : '';
        $isSuccess = $verifyResult['success'] && ($chapaStatus === 'success' || $chapaStatus === 'paid');
        
        // 3. Write within transaction
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM deposits WHERE tx_ref = :tx_ref AND bot_id = :bot_id FOR UPDATE');
            $stmt->execute(['tx_ref' => $txRef, 'bot_id' => $currentBotId]);
            $deposit = $stmt->fetch();
            
            if (!$deposit || $deposit['status'] === 'success') {
                $pdo->rollBack();
                echo json_encode(['success' => true, 'message' => 'Already processed or not found']);
                exit;
            }
            
            if ($isSuccess) {
                $verifiedAmount = isset($verifyResult['data']['amount']) ? (float)$verifyResult['data']['amount'] : (float)$deposit['amount'];
                $chapaRef = isset($verifyResult['data']['reference']) ? $verifyResult['data']['reference'] : '';
                $responseJson = json_encode($verifyResult['raw']);
                
                $stmt = $pdo->prepare("UPDATE deposits SET status = 'success', chapa_tx_ref = :chapa_ref, chapa_response = :resp, completed_at = NOW() WHERE id = :id AND bot_id = :bot_id");
                $stmt->execute([
                    'chapa_ref' => $chapaRef,
                    'resp'      => $responseJson,
                    'id'        => $deposit['id'],
                    'bot_id'    => $currentBotId
                ]);
                
                processTransaction(
                    (string)$deposit['user_id'],
                    'deposit',
                    $verifiedAmount,
                    "Chapa deposit (callback) - {$chapaRef}",
                    $pdo,
                    'deposit',
                    (int)$deposit['id']
                );
                
                // Increment total_deposit setting safely
                try {
                    $stmtGet = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'total_deposit' AND bot_id = :bot_id LIMIT 1");
                    $stmtGet->execute(['bot_id' => $currentBotId]);
                    $currentTotal = (float)($stmtGet->fetchColumn() ?: 0.0);
                    $newTotal = $currentTotal + $verifiedAmount;

                    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'total_deposit' AND bot_id = :bot_id");
                    $stmtCheck->execute(['bot_id' => $currentBotId]);
                    $exists = (int)$stmtCheck->fetchColumn() > 0;

                    if ($exists) {
                        $stmtTot = $pdo->prepare("UPDATE settings SET setting_value = :val WHERE setting_key = 'total_deposit' AND bot_id = :bot_id");
                        $stmtTot->execute(['val' => (string)$newTotal, 'bot_id' => $currentBotId]);
                    } else {
                        $stmtTot = $pdo->prepare("INSERT INTO settings (setting_key, bot_id, setting_value) VALUES ('total_deposit', :bot_id, :val)");
                        $stmtTot->execute(['bot_id' => $currentBotId, 'val' => (string)$newTotal]);
                    }
                } catch (Exception $totErr) {}
                
                $pdo->commit();
                
                // Notify admin bot
                try {
                    $stmt = $pdo->prepare('SELECT first_name FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
                    $stmt->execute(['tg_id' => (string)$deposit['user_id'], 'bot_id' => $currentBotId]);
                    $row = $stmt->fetch();
                    $firstName = $row ? $row['first_name'] : 'User';
                    notifyDeposit((string)$deposit['user_id'], $verifiedAmount, $firstName);
                } catch (Exception $e) {}
                
                echo json_encode(['success' => true, 'message' => 'Deposit completed successfully']);
            } else {
                $realStatus = isset($verifyResult['data']['status']) ? $verifyResult['data']['status'] : (isset($verifyResult['raw']['status']) ? $verifyResult['raw']['status'] : 'pending');
                if (strtolower($realStatus) === 'failed') {
                    $stmt = $pdo->prepare("UPDATE deposits SET status = 'failed' WHERE id = :id AND bot_id = :bot_id");
                    $stmt->execute(['id' => $deposit['id'], 'bot_id' => $currentBotId]);
                }
                
                $pdo->commit();
                echo json_encode(['success' => false, 'message' => 'Payment verification failed or pending']);
            }
            
        } catch (Exception $txErr) {
            $pdo->rollBack();
            throw $txErr;
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /test-deposit-notification & /simulate-deposit (GET / POST) ─
if (
    $route === '/test-deposit-notification' || 
    $route === '/api/test-deposit-notification' || 
    $route === '/simulate-deposit' || 
    $route === '/api/simulate-deposit'
) {
    try {
        $amount = isset($requestData['amount']) ? (float)$requestData['amount'] : 250.0;
        $userId = isset($requestData['user_id']) ? (string)$requestData['user_id'] : (isset($requestData['tg_id']) ? (string)$requestData['tg_id'] : '5928771903');
        $firstName = isset($requestData['first_name']) ? (string)$requestData['first_name'] : (isset($requestData['name']) ? (string)$requestData['name'] : 'RealUserSim');
        $botId = getCurrentBotId();

        $txRef = "DEP-SIM-" . time() . "-" . bin2hex(random_bytes(3));
        $chapaRef = "CHAPA-SIM-" . time();

        // 1. Find or create user in auth table
        $stmt = $pdo->prepare('SELECT * FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
        $stmt->execute(['tg_id' => $userId, 'bot_id' => $botId]);
        $user = $stmt->fetch();

        if (!$user) {
            $stmt = $pdo->prepare("INSERT INTO auth (tg_id, bot_id, first_name, balance, auth_provider, last_login) VALUES (:tg_id, :bot_id, :first_name, 0.00, 'telegram', NOW())");
            $stmt->execute(['tg_id' => $userId, 'bot_id' => $botId, 'first_name' => $firstName]);
        } elseif (!empty($firstName) && $user['first_name'] !== $firstName) {
            $stmt = $pdo->prepare("UPDATE auth SET first_name = :first_name WHERE tg_id = :tg_id AND bot_id = :bot_id");
            $stmt->execute(['first_name' => $firstName, 'tg_id' => $userId, 'bot_id' => $botId]);
        }

        // 2. Insert successful test deposit
        $stmt = $pdo->prepare("INSERT INTO deposits (user_id, bot_id, amount, tx_ref, chapa_tx_ref, status, completed_at) VALUES (:user_id, :bot_id, :amount, :tx_ref, :chapa_ref, 'success', NOW())");
        $stmt->execute([
            'user_id'   => $userId,
            'bot_id'    => $botId,
            'amount'    => $amount,
            'tx_ref'    => $txRef,
            'chapa_ref' => $chapaRef
        ]);
        $depositId = $pdo->lastInsertId();

        // 3. Update wallet balance via transaction processor
        $newBalance = processTransaction(
            $userId,
            'deposit',
            $amount,
            "Simulated Chapa Deposit - {$txRef}",
            $pdo,
            'deposit',
            (int)$depositId
        );

        // 4. Update total_deposit in settings
        try {
            $stmtGet = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'total_deposit' AND bot_id = :bot_id LIMIT 1");
            $stmtGet->execute(['bot_id' => $botId]);
            $currentTotal = (float)($stmtGet->fetchColumn() ?: 0.0);
            $newTotal = $currentTotal + $amount;

            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'total_deposit' AND bot_id = :bot_id");
            $stmtCheck->execute(['bot_id' => $botId]);
            $exists = (int)$stmtCheck->fetchColumn() > 0;

            if ($exists) {
                $stmtTot = $pdo->prepare("UPDATE settings SET setting_value = :val WHERE setting_key = 'total_deposit' AND bot_id = :bot_id");
                $stmtTot->execute(['val' => (string)$newTotal, 'bot_id' => $botId]);
            } else {
                $stmtTot = $pdo->prepare("INSERT INTO settings (setting_key, bot_id, setting_value) VALUES ('total_deposit', :bot_id, :val)");
                $stmtTot->execute(['bot_id' => $botId, 'val' => (string)$newTotal]);
            }
        } catch (Exception $totErr) {}

        // 5. Trigger Bot Notification Webhook
        $notifyRes = notifyDeposit($userId, $amount, $firstName);

        echo json_encode([
            'success'     => true,
            'simulation'  => true,
            'message'     => 'Deposit notification simulation triggered successfully!',
            'details'     => [
                'tx_ref'       => $txRef,
                'chapa_ref'    => $chapaRef,
                'user_id'      => $userId,
                'first_name'   => $firstName,
                'amount'       => $amount,
                'new_balance'  => $newBalance,
                'bot_id'       => $botId,
                'notification' => $notifyRes
            ]
        ], JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

