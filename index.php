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

// Merge query parameters for easier retrieval
$requestData = array_merge($_GET, $_POST, $requestData);

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
    $route === '/balance'
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
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found', 'route' => $route]);
}
