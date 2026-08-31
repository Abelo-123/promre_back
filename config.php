<?php
/**
 * Global Configuration & Database Connection Helper
 */

// Simple dotenv parser
function loadEnv($dir) {
    $paths = [
        $dir . '/.env',
        $dir . '/../.env',
        $dir . '/../server/.env'
    ];
    foreach ($paths as $path) {
        if (file_exists($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                
                // Strip quotes if present
                if (preg_match('/^"(.*)"$/', $value, $matches)) {
                    $value = $matches[1];
                } elseif (preg_match('/^\'(.*)\'$/', $value, $matches)) {
                    $value = $matches[1];
                }
                
                if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                    putenv("{$name}={$value}");
                    $_ENV[$name] = $value;
                    $_SERVER[$name] = $value;
                }
            }
            break; // Load first found .env
        }
    }
}

loadEnv(__DIR__);

// Helper to get environment variables reliably
function getEnvVar($key, $default = null) {
    if (getenv($key) !== false) return getenv($key);
    if (isset($_ENV[$key])) return $_ENV[$key];
    if (isset($_SERVER[$key])) return $_SERVER[$key];
    return $default;
}

// DB Credentials
$dbHost = 'localhost';
$dbPort = '3306';
$dbUser = 'paxyocom_newRender';
$dbPass = '_[xgm!h,PT0MUx,y';
$dbName = 'paxyocom_paxyov3';

// Override DB credentials from environment variables if specified
$dbHost = getEnvVar('DB_HOST', $dbHost);
$dbPort = getEnvVar('DB_PORT', $dbPort);
$dbUser = getEnvVar('DB_USER', $dbUser);
$dbPass = getEnvVar('DB_PASS', $dbPass);
$dbName = getEnvVar('DB_NAME', $dbName);

try {
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
    
    // Ensure transactions table columns are wide enough for referral types
    try {
        $pdo->exec("ALTER TABLE transactions MODIFY COLUMN type VARCHAR(50) NOT NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE transactions MODIFY COLUMN reference_type VARCHAR(50) DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE transactions MODIFY COLUMN reference_id VARCHAR(50) DEFAULT NULL");
    } catch (Exception $e) {}
} catch (PDOException $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed', 'details' => $e->getMessage()]);
    exit;
}

// Global configurations
$botTokens = [];
$rawTokens = getEnvVar('BOT_TOKENS');
if ($rawTokens) {
    $pairs = explode(',', $rawTokens);
    foreach ($pairs as $pair) {
        $trimmed = trim($pair);
        if (empty($trimmed)) continue;
        if (strpos($trimmed, ':') !== false) {
            $parts = explode(':', $trimmed);
            $botId = trim($parts[0]);
            $botTokens[$botId] = $trimmed;
        }
    }
}

$botToken = getEnvVar('BOT_TOKEN');
if ($botToken && strpos($botToken, ':') !== false) {
    $parts = explode(':', $botToken);
    $primaryBotId = trim($parts[0]);
    if (!isset($botTokens[$primaryBotId])) {
        $botTokens[$primaryBotId] = $botToken;
    }
} else {
    $primaryBotId = '8671087034';
    $botToken = '8671087034:AAH75-nIpyl1xUlkOO9PCnHE875Zn41P9CE';
    if (!isset($botTokens[$primaryBotId])) {
        $botTokens[$primaryBotId] = $botToken;
    }
}

$gopApiKey = getEnvVar('GODOFPANEL_API_KEY');
$chapaSecretKey = getEnvVar('CHAPA_SECRET_KEY');
if (!$chapaSecretKey || strpos($chapaSecretKey, 'tEs') !== false || strpos($chapaSecretKey, 'Mg2Kc') !== false) {
    $chapaSecretKey = 'CHASECK-WGUq6JVPIxSmjVSWTebh5UOOcshNscEd';
}
$chapaBaseUrl = getEnvVar('CHAPA_BASE_URL', 'https://api.chapa.co/v1');
$siteUrl = getEnvVar('SITE_URL', 'https://paxyo.com');
$minDeposit = (int)(getEnvVar('MIN_DEPOSIT', 10));
$maxDeposit = (int)(getEnvVar('MAX_DEPOSIT', 100000));
$smsApiKey = getEnvVar('SMS_API_KEY', 'PEQBNQ8X1P6MBJH76701ZUGIX5DP7UOZ:1098');
$smsApiUrl = 'https://smsethiopia.com/api/sms/send';

// Helper for HTTP requests (replacing fetch)
function curlRequest($method, $url, $headers = [], $body = null, $timeout = 30) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Keep simple for shared hosting cert trust issues
    
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
        }
    } elseif (strtoupper($method) === 'GET' && $body) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($body);
        curl_setopt($ch, CURLOPT_URL, $url);
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'code' => $httpCode,
        'body' => $response,
        'error' => $error
    ];
}

function getCleanJoadminUrl() {
    $url = getEnvVar('JOADMIN_SERVER_URL', 'https://padmin121-1.onrender.com');
    if (strpos($url, 'padmin121.onrender.com') !== false && strpos($url, 'padmin121-1.onrender.com') === false) {
        $url = str_replace('padmin121.onrender.com', 'padmin121-1.onrender.com', $url);
    }
    return rtrim($url, '/');
}

// Helper to dynamically fetch joadmin's rate multiplier (with local fast cache)
function getJoadminMultiplier() {
    static $cachedVal = null;
    static $cachedTime = 0;
    
    $isRefresh = isset($_GET['refresh']);
    if ($cachedVal !== null && !$isRefresh && (time() - $cachedTime) < 5) {
        return $cachedVal;
    }
    
    $cacheDir = __DIR__ . '/cache';
    if (!file_exists($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $cacheFile = "{$cacheDir}/cache_joadmin_multiplier.json";
    
    if (file_exists($cacheFile) && !$isRefresh) {
        $cData = json_decode(@file_get_contents($cacheFile), true);
        if ($cData && isset($cData['time']) && (time() - $cData['time']) < 5 && isset($cData['val'])) {
            $cachedVal = (float)$cData['val'];
            $cachedTime = time();
            return $cachedVal;
        }
    }
    
    try {
        $joadminUrl = getCleanJoadminUrl();
        $res = curlRequest('GET', "{$joadminUrl}/api/admin/reseller/min-multiplier", [], null, 5);
        if ($res['code'] === 200 && !empty($res['body'])) {
            $data = json_decode($res['body'], true);
            $val = 55.0;
            if (isset($data['joadmin_multiplier'])) $val = (float)$data['joadmin_multiplier'];
            elseif (isset($data['min_rate_multiplier'])) $val = (float)$data['min_rate_multiplier'];
            elseif (isset($data['rate_multiplier'])) $val = (float)$data['rate_multiplier'];
            
            if ($val > 0) {
                $cachedVal = $val;
                $cachedTime = time();
                @file_put_contents($cacheFile, json_encode(['time' => time(), 'val' => $val]));
                return $val;
            }
        }
    } catch (Exception $e) {}
    
    // Fallback to stale cache if cURL fails
    if (file_exists($cacheFile)) {
        $cData = json_decode(@file_get_contents($cacheFile), true);
        if ($cData && isset($cData['val'])) {
            return (float)$cData['val'];
        }
    }
    
    return 55.0; // Default baseline if unreachable
}
