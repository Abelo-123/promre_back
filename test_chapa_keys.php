<?php
require_once __DIR__ . '/config.php';

// Prepare dummy initialization payload similar to api/routes/deposits.php
$generatedTxRef = "DEP-TEST-" . time() . "-" . bin2hex(random_bytes(4));
$baseUrl = (strpos($siteUrl, 'http') === 0) ? $siteUrl : "https://{$siteUrl}";
$callbackUrl = (strpos($baseUrl, 'localhost') !== false) ? 'https://webhook.site/dummy-paxyo-callback' : "{$baseUrl}/api/chapa-callback";

// 1. Fetch Banks
$bankUrl = "{$chapaBaseUrl}/banks";
$bankRes = curlRequest('GET', $bankUrl, [
    "Authorization: Bearer {$chapaSecretKey}"
], null, 15);
$bankData = json_decode($bankRes['body'], true);
$banksSuccess = ($bankRes['code'] === 200 && isset($bankData['status']) && $bankData['status'] === 'success');

// 2. Try to Initialize Payment
$payload = [
    'amount'        => 10,
    'currency'      => 'ETB',
    'email'         => 'test_customer@paxyo.com',
    'first_name'    => 'Diagnostic',
    'last_name'     => 'Test',
    'tx_ref'        => $generatedTxRef,
    'callback_url'  => $callbackUrl,
    'return_url'    => $callbackUrl,
    'meta'          => [
        'hide_receipt' => true
    ],
    'customization' => [
        'title'       => 'Paxyo Test',
        'description' => 'Diagnosing Chapa Checkout Integration'
    ]
];

$initUrl = "{$chapaBaseUrl}/transaction/initialize";
$initRes = curlRequest('POST', $initUrl, [
    "Authorization: Bearer {$chapaSecretKey}",
    "Content-Type: application/json"
], json_encode($payload), 15);

$initData = json_decode($initRes['body'], true);
$initSuccess = ($initRes['code'] === 200 && isset($initData['status']) && $initData['status'] === 'success');
$checkoutUrl = isset($initData['data']['checkout_url']) ? $initData['data']['checkout_url'] : null;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chapa Integration Diagnostics</title>
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #0d0e15;
            color: #e2e8f0;
            padding: 24px;
            max-width: 800px;
            margin: 0 auto;
        }
        h1 {
            color: #38bdf8;
            font-size: 24px;
            border-bottom: 1px solid #1e293b;
            padding-bottom: 12px;
            margin-bottom: 24px;
        }
        .card {
            background-color: #1e293b;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #334155;
        }
        .status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: bold;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
        }
        .status-success {
            background-color: rgba(16, 185, 129, 0.15);
            color: #10b981;
        }
        .status-error {
            background-color: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }
        .btn {
            display: inline-block;
            background: linear-gradient(135deg, #38bdf8, #0ea5e9);
            color: #ffffff;
            font-weight: bold;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 8px;
            margin-top: 16px;
            box-shadow: 0 4px 12px rgba(56, 189, 248, 0.25);
            transition: all 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(56, 189, 248, 0.4);
        }
        .btn:active {
            transform: translateY(0);
        }
        .code-block {
            background-color: #0f172a;
            color: #38bdf8;
            padding: 16px;
            border-radius: 8px;
            overflow-x: auto;
            font-family: 'Fira Code', 'Courier New', Courier, monospace;
            font-size: 13px;
            margin-top: 12px;
            border: 1px solid #1e293b;
        }
        .key-val {
            display: flex;
            justify-content: space-between;
            border-bottom: 1px solid #334155;
            padding: 8px 0;
            font-size: 14px;
        }
        .key-val:last-child {
            border-bottom: none;
        }
        .key {
            color: #94a3b8;
        }
        .val {
            font-weight: 500;
        }
    </style>
</head>
<body>
    <h1>🚀 Chapa Checkout Diagnostics & Simulation</h1>

    <!-- 1. Environment Config -->
    <div class="card">
        <h2>1. Server Configuration Check</h2>
        <div class="key-val">
            <span class="key">Chapa Base URL:</span>
            <span class="val"><?= htmlspecialchars($chapaBaseUrl) ?></span>
        </div>
        <div class="key-val">
            <span class="key">Chapa Secret Key (Decoded):</span>
            <span class="val"><?= htmlspecialchars($chapaSecretKey ? substr($chapaSecretKey, 0, 12) . '...' . substr($chapaSecretKey, -4) : 'Not Configured') ?></span>
        </div>
        <div class="key-val">
            <span class="key">Site URL configured:</span>
            <span class="val"><?= htmlspecialchars($siteUrl) ?></span>
        </div>
        <div class="key-val">
            <span class="key">Calculated Callback URL:</span>
            <span class="val" style="color: #60a5fa;"><?= htmlspecialchars($callbackUrl) ?></span>
        </div>
    </div>

    <!-- 2. Bank Retrieval -->
    <div class="card">
        <h2>2. Endpoint Connectivity: banks</h2>
        <p>Checks if your API keys are authorized to connect to Chapa.</p>
        <div class="key-val">
            <span class="key">HTTP Status:</span>
            <span class="val"><?= $bankRes['code'] ?></span>
        </div>
        <div class="key-val">
            <span class="key">Result Status:</span>
            <span>
                <?php if ($banksSuccess): ?>
                    <span class="status status-success">✓ Authorized & Connected</span>
                <?php else: ?>
                    <span class="status status-error">✗ Failed</span>
                <?php endif; ?>
            </span>
        </div>
    </div>

    <!-- 3. Transaction Initialize -->
    <div class="card">
        <h2>3. Payment Initialization test: transaction/initialize</h2>
        <p>Checks if your account is active and permitted to accept payments.</p>
        <div class="key-val">
            <span class="key">HTTP Status:</span>
            <span class="val"><?= $initRes['code'] ?></span>
        </div>
        <div class="key-val">
            <span class="key">Initialization Result:</span>
            <span>
                <?php if ($initSuccess): ?>
                    <span class="status status-success">✓ Ready for Checkout</span>
                <?php else: ?>
                    <span class="status status-error">✗ Restricted / Unauthorized</span>
                <?php endif; ?>
            </span>
        </div>

        <?php if ($initSuccess && $checkoutUrl): ?>
            <div style="text-align: center; padding: 12px 0;">
                <p style="color: #10b981;">✓ Successfully generated checkout session!</p>
                <a href="<?= htmlspecialchars($checkoutUrl) ?>" target="_blank" class="btn">🚀 Click to Launch Checkout Page</a>
            </div>
        <?php else: ?>
            <p style="color: #f87171; margin-top: 12px;">Chapa rejected payment creation. Error response:</p>
            <pre class="code-block"><?= htmlspecialchars(json_encode($initData, JSON_PRETTY_PRINT)) ?></pre>
        <?php endif; ?>
    </div>

    <!-- 4. Raw Request Payload -->
    <div class="card">
        <h2>4. Request Payload Details</h2>
        <p>This is the exact JSON body sent to Chapa:</p>
        <pre class="code-block"><?= htmlspecialchars(json_encode($payload, JSON_PRETTY_PRINT)) ?></pre>
    </div>
</body>
</html>
