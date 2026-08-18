<?php
/**
 * OTP Verification Routes (EthioSMS Provider)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Generate a 4-digit numeric OTP
function generateOTP() {
    return (string)rand(1000, 9999);
}

// ─── ROUTE: /otp/send (POST) ──────────────────────────────────────────────
if ($route === '/otp/send') {
    $initData = isset($requestData['initData']) ? $requestData['initData'] : '';
    $phoneNumber = isset($requestData['phone_number']) ? trim($requestData['phone_number']) : '';
    
    $tgId = getTelegramUserId($initData);
    if (!$tgId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    if (empty($phoneNumber) || strlen($phoneNumber) < 9) {
        echo json_encode(['success' => false, 'error' => 'Invalid phone number']);
        exit;
    }
    
    try {
        $otp = generateOTP();
        // 10 minutes expiry
        $expiresAt = date('Y-m-d H:i:s', time() + 600);
        
        // Save to DB
        $stmt = $pdo->prepare("
            INSERT INTO otp_verifications (tg_id, phone_number, otp, expires_at) 
            VALUES (:tg_id, :phone, :otp, :expires)
            ON DUPLICATE KEY UPDATE 
            phone_number = VALUES(phone_number), 
            otp = VALUES(otp), 
            expires_at = VALUES(expires_at)
        ");
        $stmt->execute([
            'tg_id'   => $tgId,
            'phone'   => $phoneNumber,
            'otp'     => $otp,
            'expires' => $expiresAt
        ]);
        
        // Send SMS through SMS Ethiopia provider (JSON POST)
        $smsPayload = [
            'msisdn' => $phoneNumber,
            'text'   => "Your Ziviop verification code is: {$otp}"
        ];
        
        $res = curlRequest('POST', $smsApiUrl, [
            "Content-Type: application/json",
            "KEY: {$smsApiKey}"
        ], json_encode($smsPayload), 15);
        
        $smsResult = json_decode($res['body'], true);
        
        if ($res['code'] === 200 && $smsResult && (
            (isset($smsResult['status']) && $smsResult['status'] === 'success') || 
            !empty($smsResult['success']) || 
            (isset($smsResult['sent']) && ($smsResult['sent'] === true || $smsResult['sent'] === 'true'))
        )) {
            echo json_encode(['success' => true, 'message' => 'OTP sent successfully']);
        } else {
            echo json_encode([
                'success' => false, 
                'error'   => 'Failed to send SMS through provider. Details: ' . ($smsResult ? json_encode($smsResult) : substr($res['body'], 0, 100))
            ]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Internal server error: ' . $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /otp/verify (POST) ────────────────────────────────────────────
if ($route === '/otp/verify') {
    $initData = isset($requestData['initData']) ? $requestData['initData'] : '';
    $phoneNumber = isset($requestData['phone_number']) ? trim($requestData['phone_number']) : '';
    $otp = isset($requestData['otp']) ? trim($requestData['otp']) : '';
    
    $tgId = getTelegramUserId($initData);
    if (!$tgId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    if (empty($otp)) {
        echo json_encode(['success' => false, 'error' => 'OTP is required']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare('SELECT * FROM otp_verifications WHERE tg_id = :tg_id AND phone_number = :phone AND otp = :otp');
        $stmt->execute(['tg_id' => $tgId, 'phone' => $phoneNumber, 'otp' => $otp]);
        $row = $stmt->fetch();
        
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Invalid or expired OTP']);
            exit;
        }
        
        if (time() > strtotime($row['expires_at'])) {
            echo json_encode(['success' => false, 'error' => 'OTP has expired']);
            exit;
        }
        
        // Update user auth profile
        $stmt = $pdo->prepare('UPDATE auth SET phone_number = :phone, phone_verified = 1 WHERE tg_id = :tg_id');
        $stmt->execute(['phone' => $phoneNumber, 'tg_id' => $tgId]);
        
        // Clear used OTP
        $stmt = $pdo->prepare('DELETE FROM otp_verifications WHERE tg_id = :tg_id');
        $stmt->execute(['tg_id' => $tgId]);
        
        echo json_encode(['success' => true, 'message' => 'Phone number verified successfully!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Internal server error: ' . $e->getMessage()]);
    }
    exit;
}
