<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Main API Router Entry Point
 */

// Handle preflight CORS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Allow-Credentials: true");
    http_response_code(200);
    exit;
}

// Global CORS headers for other responses
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
header("Access-Control-Allow-Origin: $origin");
header("Access-Control-Allow-Credentials: true");
// NOTE: Content-Type is NOT set globally here — SSE routes set text/event-stream themselves.
// JSON routes will set their own Content-Type or rely on the default.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Parse incoming request route
$route = isset($_GET['route']) ? $_GET['route'] : null;
if (!$route) {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); // e.g. /apio or /mini-app/apio
    
    if (!empty($scriptDir) && $scriptDir !== '/' && strpos($uri, $scriptDir) === 0) {
        $route = substr($uri, strlen($scriptDir));
    } else {
        $route = $uri;
    }
}

// Clean trailing slashes
$route = '/' . trim($route, '/');

// Parse JSON request body
$rawInput = file_get_contents('php://input');
$requestData = json_decode($rawInput, true) ?: [];

// For GET requests: also try reading initData directly from raw QUERY_STRING
// to avoid any double-decoding issues from mod_rewrite
if (empty($requestData['initData']) && !empty($_SERVER['QUERY_STRING'])) {
    // Parse raw query string manually
    $rawQs = $_SERVER['QUERY_STRING'];
    parse_str($rawQs, $rawQsParams);
    if (!empty($rawQsParams['initData'])) {
        $requestData['initData'] = $rawQsParams['initData'];
    }
}

$requestStartTime = microtime(true);

// Merge query parameters for easier retrieval
$requestData = array_merge($_GET, $_POST, $requestData);

// Register global logging shutdown function
register_shutdown_function(function() use ($requestStartTime, &$route, &$requestData) {
    $duration = round((microtime(true) - $requestStartTime) * 1000, 2);
    $timestamp = date('Y-m-d H:i:s');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $status = http_response_code();
    
    $userId = 'unauthenticated';
    if (!empty($requestData['initData'])) {
        parse_str($requestData['initData'], $params);
        if (!empty($params['user'])) {
            $userData = json_decode($params['user'], true);
            if (!empty($userData['id'])) {
                $userId = $userData['id'];
            }
        }
    }
    if ($userId === 'unauthenticated' && !empty($requestData['user_id'])) {
        $userId = $requestData['user_id'];
    }
    if ($userId === 'unauthenticated' && !empty($requestData['tg_id'])) {
        $userId = $requestData['tg_id'];
    }
    
    $summary = '';
    if ($method !== 'GET') {
        $logData = $requestData;
        unset($logData['initData']);
        $summary = substr(json_encode($logData), 0, 100);
    }
    
    error_log("[{$timestamp}] {$method} {$route} | User: {$userId} | Status: {$status} | Duration: {$duration}ms | Payload: {$summary}");
});

// Basic logger / debug
// file_put_contents(__DIR__ . '/debug.log', "[" . date('Y-m-d H:i:s') . "] Route: $route, Method: " . $_SERVER['REQUEST_METHOD'] . "\n", FILE_APPEND);

// Route mapping to controller files
// Set JSON content type for all routes EXCEPT SSE stream
$isSseRoute = ($route === '/orders/stream');
if (!$isSseRoute) {
    header("Content-Type: application/json; charset=UTF-8");
}

if (strpos($route, '/app/') === 0 || $route === '/app') {
    require_once __DIR__ . '/routes/app.php';
} elseif ($route === '/services' || $route === '/categories' || strpos($route, '/services/') === 0) {
    require_once __DIR__ . '/routes/services.php';
} elseif (strpos($route, '/orders/') === 0 || $route === '/orders') {
    require_once __DIR__ . '/routes/orders.php';
} elseif (
    $route === '/deposit' || 
    $route === '/deposits' || 
    $route === '/complete-deposit' || 
    $route === '/verify-deposit' || 
    $route === '/chapa-callback' || 
    $route === '/balance' ||
    $route === '/test-deposit-notification' ||
    $route === '/simulate-deposit'
) {
    require_once __DIR__ . '/routes/deposits.php';
} elseif ($route === '/chat') {
    require_once __DIR__ . '/routes/chat.php';
} elseif (strpos($route, '/otp/') === 0) {
    require_once __DIR__ . '/routes/otp.php';
} elseif (strpos($route, '/referral/') === 0) {
    require_once __DIR__ . '/routes/referral.php';
} elseif (strpos($route, '/withdraw/') === 0 || $route === '/withdraw') {
    require_once __DIR__ . '/routes/withdraw.php';
} elseif ($route === '/debug/auth') {
    // Full diagnostic endpoint — shows every HMAC step
    $initRaw = isset($requestData['initData']) ? $requestData['initData'] : '';
    $envToken = getenv('BOT_TOKEN');

    // Parse initData
    $params = [];
    if ($initRaw) parse_str($initRaw, $params);
    $hash = isset($params['hash']) ? $params['hash'] : null;
    $userData = isset($params['user']) ? json_decode($params['user'], true) : null;

    // Build dataCheckString
    unset($params['hash'], $params['signature']);
    ksort($params);
    $dcs = implode("\n", array_map(fn($k, $v) => "{$k}={$v}", array_keys($params), $params));

    // Try HMAC validation
    $secret = hash_hmac('sha256', trim($botToken), 'WebAppData', true);
    $calc   = hash_hmac('sha256', $dcs, $secret);
    $match  = $calc === $hash;

    echo json_encode([
        'getenv_BOT_TOKEN'   => $envToken ? substr(trim($envToken), 0, 30) . '...' : 'NOT SET',
        'token_trimmed_len'  => $envToken ? strlen(trim($envToken)) : 0,
        'token_raw_len'      => $envToken ? strlen($envToken) : 0,
        'primary_bot_id'     => $primaryBotId,
        'initData_received'  => !empty($initRaw),
        'initData_length'    => strlen($initRaw),
        'parsed_hash'        => $hash,
        'parsed_auth_date'   => $params['auth_date'] ?? null,
        'parsed_user_id'     => $userData['id'] ?? null,
        'dataCheckString'    => $dcs,
        'calculated_hash'    => $calc,
        'hash_match'         => $match,
        'query_string_raw'   => $_SERVER['QUERY_STRING'] ?? '',
    ]);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found', 'route' => $route]);
}
