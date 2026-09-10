<?php
/**
 * Keep-Alive Pinger Endpoint
 * Can be called by UptimeRobot, cron jobs, or frontend interval to keep Render services awake.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$targets = [
    'https://promre-back.onrender.com/api/app/heartbeat',
    'https://padmin121-1.onrender.com/api/health',
    'https://primora-node.onrender.com/health'
];

$results = [];

foreach ($targets as $url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $start = microtime(true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $duration = round((microtime(true) - $start) * 1000, 2);
    curl_close($ch);
    
    $results[] = [
        'url' => $url,
        'status' => $httpCode,
        'duration_ms' => $duration,
        'ok' => ($httpCode >= 200 && $httpCode < 400)
    ];
}

echo json_encode([
    'timestamp' => date('Y-m-d H:i:s'),
    'status' => 'success',
    'pings' => $results
]);
