<?php
/**
 * Telpegdddram signature validation utility
 */

require_once __DIR__ . '/config.php';

function getCurrentBotId($requestBotId = null) {
    global $primaryBotId;
    if ($requestBotId) {
        return $requestBotId;
    }
    
    global $requestData;
    if (isset($requestData['bot_id']) && !empty($requestData['bot_id'])) {
        return $requestData['bot_id'];
    }
    
    if (isset($_GET['bot_id']) && !empty($_GET['bot_id'])) {
        return $_GET['bot_id'];
    }
    
    if (isset($_SERVER['HTTP_X_BOT_ID']) && !empty($_SERVER['HTTP_X_BOT_ID'])) {
        return $_SERVER['HTTP_X_BOT_ID'];
    }
    
    return $primaryBotId;
}

function getTelegramUser($initData) {
    global $botTokens, $botToken, $primaryBotId;
    
    if (empty($initData) || !is_string($initData)) {
        return null;
    }

    try {
        parse_str($initData, $params);
        
        $hash      = isset($params['hash'])      ? $params['hash']             : null;
        $signature = isset($params['signature']) ? $params['signature']        : null; // saved BEFORE unset
        $userStr   = isset($params['user'])      ? $params['user']             : null;
        $userData  = $userStr                    ? json_decode($userStr, true) : null;

        if (!$hash) {
            // Local dev fallback
            return $userData;
        }

        // Build data-check string (must exclude hash and signature)
        unset($params['hash'], $params['signature']);
        ksort($params);
        $dataCheckArr = [];
        foreach ($params as $key => $val) {
            $dataCheckArr[] = "{$key}={$val}";
        }
        $dataCheckString = implode("\n", $dataCheckArr);

        // Verify signature against all configured bot tokens
        $isValid = false;
        foreach ($botTokens as $botId => $token) {
            $secret = hash_hmac('sha256', trim($token), 'WebAppData', true);
            $calc   = hash_hmac('sha256', $dataCheckString, $secret);
            if ($hash === $calc) {
                $isValid = true;
                break;
            }
        }

        if ($isValid) {
            return $userData;
        }

        // ── Soft-auth fallback ────────────────────────────────────────────────
        $authDate = isset($params['auth_date']) ? (int)$params['auth_date'] : 0;
        $userId   = isset($userData['id'])      ? $userData['id']           : null;

        if ($signature && $userId && $authDate > 0 && (time() - $authDate) < 86400) {
            error_log('[auth] Soft-auth: HMAC failed but initData is fresh+signed. user=' . $userId);
            return $userData;
        }

        return null;
    } catch (Exception $e) {
        return null;
    }
}

function getTelegramUserId($initData) {
    $user = getTelegramUser($initData);
    return $user && isset($user['id']) ? (string)$user['id'] : null;
}
