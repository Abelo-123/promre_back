<?php
/**
 * Masked Environment Variables Diagnostic Endpoint
 */
require_once __DIR__ . '/config.php';

$secret = isset($_GET['secret']) ? $_GET['secret'] : '';
if ($secret !== 'paxyo_secure_2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

header('Content-Type: application/json');

$envVars = [];
// List of all keys we care about
$keys = [
    'DB_HOST', 'DB_PORT', 'DB_USER', 'DB_NAME',
    'BOT_TOKEN', 'BOT_TOKENS', 'GODOFPANEL_API_KEY',
    'CHAPA_SECRET_KEY', 'CHAPA_BASE_URL', 'SITE_URL',
    'MIN_DEPOSIT', 'MAX_DEPOSIT', 'SMS_API_KEY',
    'PORT', 'NODE_ENV'
];

foreach ($keys as $key) {
    $val = getEnvVar($key);
    if ($val !== null && $val !== '') {
        // Mask passwords / secrets
        if (in_array($key, ['DB_PASS', 'GODOFPANEL_API_KEY', 'CHAPA_SECRET_KEY', 'SMS_API_KEY'])) {
            $envVars[$key] = substr($val, 0, 4) . '***' . substr($val, -4);
        } elseif ($key === 'BOT_TOKEN' || $key === 'BOT_TOKENS') {
            // Mask bot tokens slightly less but keep secure
            $envVars[$key] = preg_replace('/:[A-Za-z0-9_-]{10,}/', ':***', $val);
        } else {
            $envVars[$key] = $val;
        }
    } else {
        $envVars[$key] = '(NOT SET)';
    }
}

// Add server info
$envVars['SERVER_SOFTWARE'] = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
$envVars['REQUEST_TIME'] = date('Y-m-d H:i:s');

echo json_encode($envVars, JSON_PRETTY_PRINT);
exit;
