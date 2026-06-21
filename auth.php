<?php
/**
 * Telegram signijature validation utility
 */

require_once __DIR__ . '/config.php';

$currentBotId = null;

function getCurrentBotId() {
    global $currentBotId, $primaryBotId;
    if ($currentBotId !== null) {
        return $currentBotId;
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['bot_id'])) {
        $currentBotId = $_SESSION['bot_id'];
        return $currentBotId;
    }
    
    // Check request params/headers as a fallback
    if (isset($_REQUEST['bot_id'])) {
        $currentBotId = $_REQUEST['bot_id'];
        $_SESSION['bot_id'] = $currentBotId;
        return $currentBotId;
    }
    
    return $primaryBotId;
}

function getTelegramUser($initData) {
    global $botTokens, $currentBotId, $primaryBotId;
    
    if (empty($initData) || !is_string($initData)) {
        return null;
    }

    try {
        // Parse the query string parameters
        parse_str($initData, $params);
        
        $hash = isset($params['hash']) ? $params['hash'] : null;
        $userStr = isset($params['user']) ? $params['user'] : null;
        $userData = $userStr ? json_decode($userStr, true) : null;

        if (!$hash) {
            // Development/Local Fallback
            if (empty($botTokens)) {
                $currentBotId = $primaryBotId;
                return $userData;
            }
            return null;
        }

        unset($params['hash']);
        
        // Sort parameters alphabetically
        ksort($params);

        // Format parameters for data check string
        $dataCheckArr = [];
        foreach ($params as $key => $val) {
            $dataCheckArr[] = "{$key}={$val}";
        }
        $dataCheckString = implode("\n", $dataCheckArr);

        if (empty($botTokens)) {
            // Local fallback
            $currentBotId = $primaryBotId;
            return $userData;
        }

        // Try validation against all configured bot tokens
        foreach ($botTokens as $botId => $token) {
            $secret = hash_hmac('sha256', $token, 'WebAppData', true);
            $calculatedHash = hash_hmac('sha256', $dataCheckString, $secret);

            if ($hash === $calculatedHash) {
                // Success! Set the matched bot ID
                $currentBotId = $botId;
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $_SESSION['bot_id'] = $botId;
                return $userData;
            }
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
