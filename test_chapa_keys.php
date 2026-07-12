<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

echo json_encode([
    'message' => 'Testing Chapa connection...',
    'secret_key_configured' => !empty($chapaSecretKey),
    'secret_key_preview' => $chapaSecretKey ? substr($chapaSecretKey, 0, 12) . '...' : 'NONE',
    'base_url' => $chapaBaseUrl
]);

// Let's test calling Chapa's banks endpoint
$url = "{$chapaBaseUrl}/banks";
$res = curlRequest('GET', $url, [
    "Authorization: Bearer {$chapaSecretKey}"
], null, 20);

echo "\n\n--- Chapa Response ---\n";
echo "HTTP Code: " . $res['code'] . "\n";
echo "Error: " . $res['error'] . "\n";
echo "Body:\n" . $res['body'] . "\n";
