<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

echo json_encode([
    'message' => 'Testing Chapa transaction initialize...',
    'secret_key_preview' => $chapaSecretKey ? substr($chapaSecretKey, 0, 12) . '...' : 'NONE',
    'base_url' => $chapaBaseUrl
]);

// Prepare dummy initialization payload similar to api/routes/deposits.php
$generatedTxRef = "DEP-TEST-" . time() . "-" . bin2hex(random_bytes(4));
$baseUrl = (strpos($siteUrl, 'http') === 0) ? $siteUrl : "https://{$siteUrl}";
$callbackUrl = (strpos($baseUrl, 'localhost') !== false) ? 'https://webhook.site/dummy-paxyo-callback' : "{$baseUrl}/api/chapa-callback";

$payload = [
    'amount'        => 10,
    'currency'      => 'ETB',
    'email'         => 'customer@paxyo.com',
    'first_name'    => 'Test',
    'last_name'     => 'User',
    'tx_ref'        => $generatedTxRef,
    'callback_url'  => $callbackUrl,
    'return_url'    => $callbackUrl,
    'meta'          => [
        'hide_receipt' => true
    ],
    'customization' => [
        'title'       => 'Paxyo Deposit Test',
        'description' => 'Wallet deposit'
    ]
];

$url = "{$chapaBaseUrl}/transaction/initialize";
$res = curlRequest('POST', $url, [
    "Authorization: Bearer {$chapaSecretKey}",
    "Content-Type: application/json"
], json_encode($payload), 20);

echo "\n\n--- Chapa Initialize Response ---\n";
echo "HTTP Code: " . $res['code'] . "\n";
echo "Error: " . $res['error'] . "\n";
echo "Body:\n" . $res['body'] . "\n";

echo "\n\n--- Payload Details ---\n";
echo "Callback URL: " . $callbackUrl . "\n";
echo "Site URL: " . $siteUrl . "\n";
