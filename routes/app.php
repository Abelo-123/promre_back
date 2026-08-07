<?php
/**
 * Application Core routes (/app/*)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../notify.php';

// Route: /app/heartbeat (GET)
if ($route === '/app/heartbeat') {
    echo json_encode(['ok' => 1]);
    exit;
}

// Route: /app/log-init-data (POST)
if ($route === '/app/log-init-data') {
    echo json_encode(['success' => true]);
    exit;
}

// Route: /app/settings (GET)
if ($route === '/app/settings') {
    try {
        $stmt = $pdo->prepare('SELECT setting_key, setting_value FROM settings ORDER BY (bot_id = :bot_id) DESC');
        $stmt->execute(['bot_id' => getCurrentBotId()]);
        $rows = $stmt->fetchAll();
        
        if (empty($rows)) {
            // Auto-seed for this bot by copying primary bot settings
            try {
                $copyStmt = $pdo->prepare("
                    INSERT INTO settings (setting_key, bot_id, setting_value)
                    SELECT setting_key, :new_bot_id, setting_value 
                    FROM settings 
                    WHERE bot_id = :primary_bot_id
                ");
                $copyStmt->execute([
                    'new_bot_id' => getCurrentBotId(),
                    'primary_bot_id' => $primaryBotId
                ]);
                
                // Re-fetch
                $stmt->execute(['bot_id' => getCurrentBotId()]);
                $rows = $stmt->fetchAll();
            } catch (Exception $e) {}
        }
        
        $settings = [
            'rateMultiplier' => 55.0,
            'discountPercent' => 0.0,
            'holidayName' => '',
            'maintenanceMode' => false,
            'userCanOrder' => true,
            'marqueeText' => 'Welcome to Paxyo SMM!',
            'topServicesIds' => '',
            'botUsername' => 'testtyer_bot'
        ];
        
        foreach ($rows as $row) {
            $key = $row['setting_key'];
            $val = $row['setting_value'];
            
            if ($key === 'rate_multiplier') $settings['rateMultiplier'] = (float)$val ?: 55.0;
            if ($key === 'discount_percent') $settings['discountPercent'] = (float)$val ?: 0.0;
            if ($key === 'holiday_name') $settings['holidayName'] = $val;
            if ($key === 'maintenance_mode') $settings['maintenanceMode'] = ($val === '1' || $val === 'true');
            if ($key === 'user_can_order') $settings['userCanOrder'] = ($val === '1' || $val === 'true');
            if ($key === 'marquee_text') $settings['marqueeText'] = $val;
            if ($key === 'top_services_ids') $settings['topServicesIds'] = $val ?: '';
            if ($key === 'bot_username') $settings['botUsername'] = $val ?: 'testtyer_bot';
        }

        $joadminMultiplier = getJoadminMultiplier();
        $pMult = $settings['rateMultiplier'];
        if ($pMult < 10.0) {
            $settings['rateMultiplier'] = $pMult * $joadminMultiplier;
        } else {
            $settings['rateMultiplier'] = $pMult * ($joadminMultiplier / 55.0);
        }
        
        echo json_encode($settings);
    } catch (Exception $e) {
        echo json_encode([
            'rateMultiplier' => 55.0,
            'discountPercent' => 0.0,
            'holidayName' => '',
            'maintenanceMode' => false,
            'userCanOrder' => true,
            'marqueeText' => '',
            'topServicesIds' => '',
            'botUsername' => 'testtyer_bot'
        ]);
    }
    exit;
}

// Route: /app/recommended (GET)
if ($route === '/app/recommended') {
    try {
        $stmt = $pdo->prepare('SELECT service_id FROM recommended_services WHERE bot_id = :bot_id');
        $stmt->execute(['bot_id' => getCurrentBotId()]);
        $rows = $stmt->fetchAll();
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int)$r['service_id'];
        }
        echo json_encode($ids);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// Route: /app/alerts (POST)
if ($route === '/app/alerts') {
    $initData = isset($requestData['initData']) ? $requestData['initData'] : '';
    $tgId = getTelegramUserId($initData);
    
    if (!$tgId) {
        echo json_encode(['success' => false, 'unreadCount' => 0, 'alerts' => []]);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare('SELECT * FROM alerts WHERE user_id = :user_id AND bot_id = :bot_id ORDER BY created_at DESC LIMIT 50');
        $stmt->execute(['user_id' => $tgId, 'bot_id' => getCurrentBotId()]);
        $alerts = $stmt->fetchAll();
        
        $unreadCount = 0;
        foreach ($alerts as &$a) {
            // Normalize columns to match javascript frontend expectations
            $a['id'] = (int)$a['id'];
            $a['is_read'] = (int)$a['is_read'];
            if ($a['is_read'] === 0) {
                $unreadCount++;
            }
        }
        
        echo json_encode(['success' => true, 'unreadCount' => $unreadCount, 'alerts' => $alerts]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'unreadCount' => 0, 'alerts' => []]);
    }
    exit;
}

// Route: /app/alerts/mark-read (POST)
if ($route === '/app/alerts/mark-read') {
    $initData = isset($requestData['initData']) ? $requestData['initData'] : '';
    $tgId = getTelegramUserId($initData);
    
    if (!$tgId) {
        echo json_encode(['success' => false]);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare('UPDATE alerts SET is_read = 1 WHERE user_id = :user_id AND bot_id = :bot_id');
        $stmt->execute(['user_id' => $tgId, 'bot_id' => getCurrentBotId()]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false]);
    }
    exit;
}

// Route: /app/auth (POST)
if ($route === '/app/auth') {
    $initData = isset($requestData['initData']) ? $requestData['initData'] : '';
    $userIdFallback = isset($requestData['user_id']) ? $requestData['user_id'] : '999999999999';
    
    $tgUser = getTelegramUser($initData);
    $tgId = $tgUser && isset($tgUser['id']) ? (string)$tgUser['id'] : null;
    
    if (!$tgId) {
        $tgId = $userIdFallback;
    }
    
    $firstName = $tgUser && isset($tgUser['first_name']) ? $tgUser['first_name'] : 'Local';
    $lastName = $tgUser && isset($tgUser['last_name']) ? $tgUser['last_name'] : 'User';
    $username = $tgUser && isset($tgUser['username']) ? $tgUser['username'] : 'local_user';
    $photoUrl = $tgUser && isset($tgUser['photo_url']) ? $tgUser['photo_url'] : '';
    
    // Force currentBotId evaluation BEFORE user lookup to make sure session/globals are populated
    $botId = getCurrentBotId();
    
    error_log("[DEBUG Auth] Starting auth request. tgId: {$tgId}, firstName: {$firstName}, username: {$username}, botId: {$botId}");
    
    try {
        // Look up user
        error_log("[DEBUG Auth] Executing DB lookup for tg_id={$tgId} AND bot_id={$botId}");
        $stmt = $pdo->prepare('SELECT * FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
        $stmt->execute(['tg_id' => $tgId, 'bot_id' => $botId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            error_log("[DEBUG Auth] User NOT found in DB. Initiating new registration...");
            // Generate a fresh unique referral code
            $randomHex = strtoupper(bin2hex(random_bytes(3)));
            $idSuffix = substr($tgId, -3);
            $newRefCode = "REF{$randomHex}{$idSuffix}";
            
            error_log("[DEBUG Auth] Inserting new user into auth table with referral code: {$newRefCode}");
            $stmt = $pdo->prepare("
                INSERT INTO auth (tg_id, bot_id, username, first_name, last_name, photo_url, balance, auth_provider, last_login, referral_code) 
                VALUES (:tg_id, :bot_id, :username, :first_name, :last_name, :photo_url, 0.00, 'telegram', NOW(), :referral_code)
            ");
            $stmt->execute([
                'tg_id'         => $tgId,
                'bot_id'        => $botId,
                'username'      => $username,
                'first_name'    => $firstName,
                'last_name'     => $lastName,
                'photo_url'     => $photoUrl,
                'referral_code' => $newRefCode
            ]);
            
            error_log("[DEBUG Auth] Database insert complete. Triggering notifyNewUser...");
            // Notify Admin Bot Async/Parallel
            try {
                notifyNewUser($tgId, $firstName);
                error_log("[DEBUG Auth] notifyNewUser called successfully");
            } catch (Exception $e) {
                error_log("[DEBUG Auth ERROR] notifyNewUser failed: " . $e->getMessage());
            }
            
            // Fetch newly created user
            $stmt = $pdo->prepare('SELECT * FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
            $stmt->execute(['tg_id' => $tgId, 'bot_id' => $botId]);
            $user = $stmt->fetch();
        } else {
            error_log("[DEBUG Auth] User already exists in DB. Last login was: " . ($user['last_login'] ?? 'unknown'));
            // User exists, update referral code if missing
            $refCode = isset($user['referral_code']) ? $user['referral_code'] : null;
            if (empty($refCode)) {
                $randomHex = strtoupper(bin2hex(random_bytes(3)));
                $idSuffix = substr($tgId, -3);
                $refCode = "REF{$randomHex}{$idSuffix}";
                
                $stmt = $pdo->prepare('UPDATE auth SET referral_code = :ref_code WHERE tg_id = :tg_id AND bot_id = :bot_id');
                $stmt->execute(['ref_code' => $refCode, 'tg_id' => $tgId, 'bot_id' => $botId]);
            }
            
            // Update last login details
            $stmt = $pdo->prepare('
                UPDATE auth 
                SET username = :username, first_name = :first_name, last_name = :last_name, photo_url = :photo_url, last_login = NOW() 
                WHERE tg_id = :tg_id AND bot_id = :bot_id
            ');
            $stmt->execute([
                'username'   => $username,
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'photo_url'  => $photoUrl,
                'tg_id'      => $tgId,
                'bot_id'     => $botId
            ]);
            
            // Re-fetch updated user
            $stmt = $pdo->prepare('SELECT * FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
            $stmt->execute(['tg_id' => $tgId, 'bot_id' => $botId]);
            $user = $stmt->fetch();
        }
        
        $refers = [];
        if (!empty($user['refers'])) {
            $refers = is_string($user['refers']) ? json_decode($user['refers'], true) : $user['refers'];
            if (!is_array($refers)) $refers = [];
        }
        
        // Debugging info
        $debugInfo = [
            'input_user_id' => $tgId,
            'resolved_bot_id' => $botId,
            'db_user_found' => !empty($user),
            'db_user_balance' => !empty($user) ? (float)$user['balance'] : null,
            'is_telegram_payload' => !empty($tgUser),
            'raw_first_name' => $firstName,
            'raw_username' => $username,
        ];

        // Fetch GodOfPanel (upstream provider) balance for debugging if API key exists
        if (!empty($gopApiKey)) {
            try {
                $gopRes = curlRequest('POST', 'https://godofpanel.com/api/v2', [], [
                    'key' => $gopApiKey,
                    'action' => 'balance'
                ], 10);
                $gopData = json_decode($gopRes['body'], true);
                if ($gopData && isset($gopData['balance'])) {
                    $debugInfo['upstream_provider'] = [
                        'name' => 'GodOfPanel',
                        'balance' => $gopData['balance'],
                        'currency' => isset($gopData['currency']) ? $gopData['currency'] : 'USD'
                    ];
                } else {
                    $debugInfo['upstream_provider'] = [
                        'name' => 'GodOfPanel',
                        'error' => isset($gopData['error']) ? $gopData['error'] : 'Invalid response format',
                        'raw_response' => substr($gopRes['body'], 0, 500)
                    ];
                }
            } catch (Exception $e) {
                $debugInfo['upstream_provider'] = [
                    'name' => 'GodOfPanel',
                    'error' => $e->getMessage()
                ];
            }
        } else {
            $debugInfo['upstream_provider'] = [
                'name' => 'GodOfPanel',
                'error' => 'API Key is empty or missing'
            ];
        }
        
        echo json_encode([
            'success' => true,
            'user' => [
                'id'             => $user['tg_id'],
                'tg_id'          => $user['tg_id'],
                'bot_id'         => $user['bot_id'],
                'username'       => !empty($user['username']) ? $user['username'] : $username,
                'first_name'     => !empty($user['first_name']) ? $user['first_name'] : $firstName,
                'last_name'      => !empty($user['last_name']) ? $user['last_name'] : $lastName,
                'photo_url'      => !empty($user['photo_url']) ? $user['photo_url'] : $photoUrl,
                'balance'        => (float)$user['balance'],
                'role'           => isset($user['role']) ? $user['role'] : 'user',
                'phone_number'   => isset($user['phone_number']) ? $user['phone_number'] : null,
                'phone_verified' => !empty($user['phone_verified']),
                'referral_code'  => $user['referral_code'],
                'referred_by'    => isset($user['referred_by']) ? $user['referred_by'] : null,
                'refers'         => $refers
            ],
            'debug' => $debugInfo
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
