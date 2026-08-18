<?php
/**
 * Bot notification webhook integration
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

function sendNotification($type, $params) {
<<<<<<< HEAD
    $paxyoBotUrl = 'https://pax-bot121-1.onrender.com/api/sendToJohn';
=======
    $botId = getCurrentBotId();
    $paxyoBotUrl = 'https://abiybot34.onrender.com/api/sendToJohn';
    if ($botId === '8998482898' || $botId === '8958935808') {
        $paxyoBotUrl = 'https://pax-bot121-1.onrender.com/api/sendToJohn';
    }
>>>>>>> da924544d7d851ae3f683fd545d038d810548a84
    $payload = array_merge(['type' => $type], $params);
    
    error_log("[DEBUG Notification] Preparing notification webhook payload: " . json_encode($payload));
    
    // Fire-and-forget notification with 5s timeout
    $res = curlRequest('POST', $paxyoBotUrl, ['Content-Type: application/json'], json_encode($payload), 5);
    
    if ($res['code'] !== 200) {
        error_log("[DEBUG Notification ERROR] Webhook failed. Code: {$res['code']} | Error: {$res['error']} | Response: " . substr($res['body'], 0, 200));
    } else {
        error_log("[DEBUG Notification SUCCESS] Webhook sent successfully. Response: " . substr($res['body'], 0, 200));
    }
    
    return ['success' => true];
}

function notifyNewUser($uid, $uuid) {
    return sendNotification('newuser', ['uid' => $uid, 'uuid' => $uuid]);
}

function notifyNewOrder($uid, $uuid, $service, $order, $amount, $panel = 'GodOfPanel', $pb = '0') {
    return sendNotification('neworder', [
        'uid' => $uid,
        'uuid' => $uuid,
        'service' => $service,
        'order' => $order,
        'amount' => $amount,
        'panel' => $panel,
        'pb' => $pb
    ]);
}

function notifyDeposit($uid, $amount, $uuid = 'User') {
    return sendNotification('deposit', [
        'uid' => $uid,
        'amount' => $amount,
        'uuid' => $uuid
    ]);
}
