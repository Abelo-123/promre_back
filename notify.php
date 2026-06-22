<?php
/**
 * Bot notification webhook integration
 */

require_once __DIR__ . '/config.php';

function sendNotification($type, $params) {
    $paxyoBotUrl = 'https://abiybot34.onrender.com/api/sendToJohn';
    $payload = array_merge(['type' => $type], $params);
    
    // Fire-and-forget notification with 5s timeout
    $res = curlRequest('POST', $paxyoBotUrl, ['Content-Type: application/json'], json_encode($payload), 5);
    
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
