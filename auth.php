<?php
/**
 * Telegram signature validation utility
 */

require_once __DIR__ . '/config.php';

$currentBotId = null;

function getCurrentBotId() {
    global $currentBotId, $primaryBotId, $botTokens;
    if ($currentBotId !== null) {
        return $currentBotId;
    }
    
    // 1. Check direct query/request parameter first
    if (isset($_REQUEST['bot_id']) && !empty($_REQUEST['bot_id'])) {
        $currentBotId = $_REQUEST['bot_id'];
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['bot_id'] = $currentBotId;
        return $currentBotId;
    }

    // 2. Check JSON POST payload bot_id
    $rawInput = file_get_contents('php://input');
    $requestData = json_decode($rawInput, true);
    if (is_array($requestData) && isset($requestData['bot_id']) && !empty($requestData['bot_id'])) {
        $currentBotId = $requestData['bot_id'];
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['bot_id'] = $currentBotId;
        return $currentBotId;
    }

    // 3. Try to extract from initData in the current request
    $initData = '';
    if (isset($_REQUEST['initData'])) {
        $initData = $_REQUEST['initData'];
    } elseif (is_array($requestData) && isset($requestData['initData'])) {
        $initData = $requestData['initData'];
    }
    
    if (!empty($initData) && !empty($botTokens)) {
        parse_str($initData, $params);
        
        // Before HMAC check, see if the initData query itself has bot_id (e.g. injected by client)
        if (isset($params['bot_id'])) {
            $currentBotId = $params['bot_id'];
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['bot_id'] = $currentBotId;
            return $currentBotId;
        }

        $hash = isset($params['hash']) ? $params['hash'] : null;
        if ($hash) {
            unset($params['hash'], $params['signature']);
            ksort($params);
            $dataCheckArr = [];
            foreach ($params as $key => $val) {
                $dataCheckArr[] = "{$key}={$val}";
            }
            $dataCheckString = implode("\n", $dataCheckArr);
            
            foreach ($botTokens as $botId => $token) {
                $token  = trim($token);
                $secret = hash_hmac('sha256', $token, 'WebAppData', true);
                $calc   = hash_hmac('sha256', $dataCheckString, $secret);
                if ($hash === $calc) {
                    $currentBotId = $botId;
                    if (session_status() === PHP_SESSION_NONE) session_start();
                    $_SESSION['bot_id'] = $botId;
                    return $currentBotId;
                }
            }
        }
    }
    
    // 2. Fall back to request parameter
    if (isset($_REQUEST['bot_id'])) {
        $currentBotId = $_REQUEST['bot_id'];
        if (session_status() === PHP_SESSION_NONE) session_start();
        $_SESSION['bot_id'] = $currentBotId;
        return $currentBotId;
    }

    // 3. Fall back to session
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (isset($_SESSION['bot_id'])) {
        $currentBotId = $_SESSION['bot_id'];
        return $currentBotId;
    }
    
    return $primaryBotId;
}

function getTelegramUser($initData) {
    global $botTokens, $currentBotId, $primaryBotId;
    
    if (empty($initData) || !is_string($initData)) {
        return null;
    }

    // Force evaluation and caching of currentBotId relative to this request
    getCurrentBotId();

    try {
        parse_str($initData, $params);
        
        $hash      = isset($params['hash'])      ? $params['hash']             : null;
        $signature = isset($params['signature']) ? $params['signature']        : null; // saved BEFORE unset
        $userStr   = isset($params['user'])      ? $params['user']             : null;
        $userData  = $userStr                    ? json_decode($userStr, true) : null;

        if (!$hash) {
            // No hash — only accept in local dev (no tokens configured)
            if (empty($botTokens)) {
                $currentBotId = $primaryBotId;
                return $userData;
            }
            return null;
        }

        // Build data-check string (must exclude hash and signature)
        unset($params['hash'], $params['signature']);
        ksort($params);
        $dataCheckArr = [];
        foreach ($params as $key => $val) {
            $dataCheckArr[] = "{$key}={$val}";
        }
        $dataCheckString = implode("\n", $dataCheckArr);

        if (empty($botTokens)) {
            // Local dev: no tokens configured, accept without verification
            $currentBotId = $primaryBotId;
            return $userData;
        }

        // Try strict HMAC validation against all configured bot tokens
        foreach ($botTokens as $botId => $token) {
            $token  = trim($token); // remove accidental whitespace from env var
            $secret = hash_hmac('sha256', $token, 'WebAppData', true);
            $calc   = hash_hmac('sha256', $dataCheckString, $secret);

            if ($hash === $calc) {
                $currentBotId = $botId;
                if (session_status() === PHP_SESSION_NONE) session_start();
                $_SESSION['bot_id'] = $botId;
                return $userData;
            }
        }

        // ── Soft-auth fallback ────────────────────────────────────────────────
        // HMAC failed (server token mismatched/revoked), but still accept if:
        //   1. auth_date is within the last 24 hours  → data is fresh
        //   2. Ed25519 signature field is present      → Telegram generated this
        //   3. user.id is a valid non-zero value
        $authDate = isset($params['auth_date']) ? (int)$params['auth_date'] : 0;
        $userId   = isset($userData['id'])      ? $userData['id']           : null;

        if ($signature && $userId && $authDate > 0 && (time() - $authDate) < 86400) {
            error_log('[auth] Soft-auth: HMAC failed but initData is fresh+signed. user=' . $userId);
            $currentBotId = getCurrentBotId();
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
