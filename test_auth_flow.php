<?php
header('Content-Type: text/plain; charset=UTF-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "=== STARTING DIAGNOSTIC AUTH FLOW TEST ===\n\n";

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notify.php';

// The exact initData payload provided by the user
$initData = "user=%7B%22id%22%3A779060335%2C%22first_name%22%3A%22Paxyo%22%2C%22last_name%22%3A%22%22%2C%22username%22%3A%22Paxyo%22%2C%22language_code%22%3A%22en%22%2C%22allows_write_to_pm%22%3Atrue%2C%22photo_url%22%3A%22https%3A%5C%2F%5C%2Ft.me%5C%2Fi%5C%2Fuserpic%5C%2F320%5C%2F5v4C9fGJ6Cx4OHmmGf9y7LoklHAJDK772I0ca5-lRBA.svg%22%7D&chat_instance=-7145432026352380094&chat_type=sender&auth_date=1782294242&signature=puRJ1dfWAGzHQt42mIbvltj-9kmkRCl_UVvLEP_TpgPMR2Uebb8a2MS90NOYpKuLC3knypzeRzpfcaJDOutMDQ&hash=3d784ba3ffe3b24ed914aaa545fb827f50d39f52d5f1268b2b4b9f6f2481867c";

// 1. Parse and validate Telegram User
echo "1. Parsing initData...\n";
$tgUser = getTelegramUser($initData);

if ($tgUser) {
    echo "   [SUCCESS] Telegram user parsed correctly.\n";
    echo "   ID: " . ($tgUser['id'] ?? 'N/A') . "\n";
    echo "   First Name: " . ($tgUser['first_name'] ?? 'N/A') . "\n";
    echo "   Username: " . ($tgUser['username'] ?? 'N/A') . "\n\n";
    $tgId = (string)$tgUser['id'];
    $firstName = $tgUser['first_name'] ?? 'Local';
    $username = $tgUser['username'] ?? 'local_user';
} else {
    echo "   [WARNING] signature validation failed (Soft-auth fallback will be tested next).\n";
    // Parse user manually for test flow
    parse_str($initData, $params);
    $userData = isset($params['user']) ? json_decode($params['user'], true) : null;
    if ($userData) {
        echo "   [INFO] Extracted user metadata from payload: ID=" . $userData['id'] . "\n\n";
        $tgId = (string)$userData['id'];
        $firstName = $userData['first_name'] ?? 'Local';
        $username = $userData['username'] ?? 'local_user';
    } else {
        echo "   [ERROR] Could not parse user from initData.\n";
        exit;
    }
}

// Get Bot ID
$botId = getCurrentBotId();
echo "2. Bot ID evaluated as: {$botId}\n\n";

// Optional Reset parameter
if (isset($_GET['reset']) && $_GET['reset'] == '1') {
    echo "3. [RESET OPTION] Deleting user {$tgId} from bot {$botId} DB to simulate a brand-new user...\n";
    try {
        $stmt = $pdo->prepare('DELETE FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
        $stmt->execute(['tg_id' => $tgId, 'bot_id' => $botId]);
        echo "   [SUCCESS] User deleted from database.\n\n";
    } catch (Exception $e) {
        echo "   [ERROR] Failed to delete user: " . $e->getMessage() . "\n\n";
    }
}

// 3. Database Check
echo "3. Querying database for user...\n";
try {
    $stmt = $pdo->prepare('SELECT * FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id');
    $stmt->execute(['tg_id' => $tgId, 'bot_id' => $botId]);
    $user = $stmt->fetch();

    if (!$user) {
        echo "   [NEW USER DETECTED] User does not exist in DB. Proceeding with registration flow...\n";
        
        // Generate a referral code
        $randomHex = strtoupper(bin2hex(random_bytes(3)));
        $idSuffix = substr($tgId, -3);
        $newRefCode = "REF{$randomHex}{$idSuffix}";
        echo "   Generated Referral Code: {$newRefCode}\n";

        // Insert user
        echo "   Inserting user into database...\n";
        $insertStmt = $pdo->prepare("
            INSERT INTO auth (tg_id, bot_id, username, first_name, last_name, photo_url, balance, auth_provider, last_login, referral_code) 
            VALUES (:tg_id, :bot_id, :username, :first_name, :last_name, '', 0.00, 'telegram', NOW(), :referral_code)
        ");
        $insertStmt->execute([
            'tg_id'         => $tgId,
            'bot_id'        => $botId,
            'username'      => $username,
            'first_name'    => $firstName,
            'last_name'     => '',
            'referral_code' => $newRefCode
        ]);
        echo "   [SUCCESS] User successfully inserted into database.\n\n";

        // Notify Admin Bot
        echo "4. Triggering new user notification to Bot webhook...\n";
        
        $paxyoBotUrl = 'https://abiybot34.onrender.com/api/sendToJohn';
        $payload = ['type' => 'newuser', 'uid' => $tgId, 'uuid' => $firstName];
        
        echo "   Target URL: {$paxyoBotUrl}\n";
        echo "   Payload: " . json_encode($payload) . "\n";
        
        echo "   Sending HTTP POST request...\n";
        $res = curlRequest('POST', $paxyoBotUrl, ['Content-Type: application/json'], json_encode($payload), 10);
        
        echo "   Response Code: {$res['code']}\n";
        echo "   cURL Error: " . ($res['error'] ?: 'None') . "\n";
        echo "   Response Body: " . $res['body'] . "\n\n";
        
        if ($res['code'] === 200) {
            echo "   [SUCCESS] Webhook accepted by bot!\n";
        } else {
            echo "   [FAILURE] Webhook rejected by bot.\n";
        }
        
    } else {
        echo "   [EXISTING USER] User already exists in database.\n";
        echo "   Database Details: \n";
        echo "     Username: " . ($user['username'] ?? 'N/A') . "\n";
        echo "     Balance: " . ($user['balance'] ?? '0.00') . " ETB\n";
        echo "     Last Login: " . ($user['last_login'] ?? 'N/A') . "\n\n";
        echo "   [TIP] To test the new user registration flow again, visit this URL with '?reset=1' (e.g. /test_auth_flow.php?reset=1)\n";
    }
} catch (Exception $e) {
    echo "   [DATABASE ERROR] " . $e->getMessage() . "\n";
}

echo "\n=== DIAGNOSTICS COMPLETE ===";
