<?php
/**
 * Admin Routes — Primora Admin Backend (PHP)
 * Complete feature suite for Primora Admin Panel
 */

global $pdo, $requestData, $route;

function syncActiveHolidayToSettings($pdo) {
    try {
        $stmt = $pdo->query("SELECT name, discount_percent FROM holidays WHERE status = 'active' ORDER BY id DESC LIMIT 1");
        $activeRows = $stmt->fetchAll();
        if (!empty($activeRows)) {
            $hName = (string)($activeRows[0]['name'] ?? '');
            $hDisc = (string)($activeRows[0]['discount_percent'] ?? 0);
            
            $u1 = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('holiday_name', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $u1->execute([$hName, $hName]);
            
            $u2 = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('discount_percent', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $u2->execute([$hDisc, $hDisc]);
        } else {
            $u1 = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('holiday_name', '') ON DUPLICATE KEY UPDATE setting_value = ''");
            $u1->execute();
            
            $u2 = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('discount_percent', '0') ON DUPLICATE KEY UPDATE setting_value = '0'");
            $u2->execute();
        }
    } catch (Exception $e) {
        error_log('[syncActiveHolidayToSettings] Error: ' . $e->getMessage());
    }
}

function getEffectiveAdminPassword($pdo) {
    try {
        $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'admin_password' LIMIT 1");
        $row = $stmt->fetch();
        if ($row && !empty($row['setting_value'])) {
            return $row['setting_value'];
        }
    } catch (Exception $e) {}
    return getenv('ADMIN_PASSWORD') ?: 'primora2026';
}

function verifyAdminAuth($pdo) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $adminPass = getEffectiveAdminPassword($pdo);

    $providedPass = '';
    if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        $providedPass = trim($matches[1]);
        if (strpos($providedPass, ':') !== false) {
            $parts = explode(':', $providedPass);
            if (count($parts) >= 2) {
                $providedPass = $parts[1];
            }
        }
    }

    if (!$providedPass || $providedPass !== $adminPass) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$method = $_SERVER['REQUEST_METHOD'];

// Public route: /admin/login
if ($route === '/admin/login' && $method === 'POST') {
    $password = $requestData['password'] ?? '';
    $adminPass = getEffectiveAdminPassword($pdo);
    if ($password === $adminPass) {
        echo json_encode(['success' => true, 'token' => $adminPass]);
        exit;
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid password']);
        exit;
    }
}

// All remaining /admin/ routes require auth
verifyAdminAuth($pdo);

// ─── Dashboard ────────────────────────────────────────────────────────
if ($route === '/admin/dashboard' && $method === 'GET') {
    try {
        $uStmt = $pdo->query("SELECT COUNT(*) as total FROM auth");
        $totalUsers = (int)($uStmt->fetch()['total'] ?? 0);

        $oStmt = $pdo->query("SELECT COUNT(*) as total FROM orders");
        $totalOrders = (int)($oStmt->fetch()['total'] ?? 0);

        $dStmt = $pdo->query("SELECT COUNT(*) as total FROM deposits WHERE status IN ('completed', 'success')");
        $totalDeposits = (int)($dStmt->fetch()['total'] ?? 0);

        $rStmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM deposits WHERE status IN ('completed', 'success')");
        $totalRevenue = (float)($rStmt->fetch()['total'] ?? 0);

        $roStmt = $pdo->query("
            SELECT o.*, a.username, a.first_name 
            FROM orders o 
            LEFT JOIN auth a ON o.user_id = a.tg_id 
            ORDER BY o.created_at DESC LIMIT 10
        ");
        $recentOrders = $roStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recentOrders as &$o) {
            $o['id'] = (int)$o['id'];
            $o['quantity'] = (int)$o['quantity'];
            $o['charge'] = (float)($o['charge'] ?? 0);
            $o['cost'] = (float)($o['cost'] ?? $o['charge'] ?? 0);
        }

        $rdStmt = $pdo->query("
            SELECT d.*, a.username, a.first_name 
            FROM deposits d 
            LEFT JOIN auth a ON d.user_id = a.tg_id 
            ORDER BY d.created_at DESC LIMIT 10
        ");
        $recentDeposits = $rdStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recentDeposits as &$d) {
            $d['id'] = (int)$d['id'];
            $d['amount'] = (float)$d['amount'];
        }

        echo json_encode([
            'totalUsers' => $totalUsers,
            'totalOrders' => $totalOrders,
            'totalDeposits' => $totalDeposits,
            'totalRevenue' => $totalRevenue,
            'recentOrders' => $recentOrders,
            'recentDeposits' => $recentDeposits
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load dashboard: ' . $e->getMessage()]);
        exit;
    }
}

// ─── Settings ──────────────────────────────────────────────────────────
if ($route === '/admin/settings' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $settings = [];
        foreach ($rows as $r) {
            $settings[$r['setting_key']] = $r['setting_value'];
        }
        // Defaults if missing
        if (!isset($settings['rate_multiplier'])) $settings['rate_multiplier'] = '220';
        if (!isset($settings['min_rate_multiplier'])) $settings['min_rate_multiplier'] = '200';
        if (!isset($settings['discount_percent'])) $settings['discount_percent'] = '0';
        if (!isset($settings['holiday_name'])) $settings['holiday_name'] = '';
        if (!isset($settings['maintenance_mode'])) $settings['maintenance_mode'] = '0';
        if (!isset($settings['user_can_order'])) $settings['user_can_order'] = '1';
        if (!isset($settings['marquee_text'])) $settings['marquee_text'] = 'Welcome to Primora!';
        if (!isset($settings['top_services_ids'])) $settings['top_services_ids'] = '';

        echo json_encode($settings);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch settings']);
        exit;
    }
}

if ($route === '/admin/settings' && $method === 'POST') {
    try {
        $key = $requestData['key'] ?? $requestData['setting_key'] ?? null;
        $val = $requestData['value'] ?? $requestData['setting_value'] ?? '';
        if ($key) {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, (string)$val, (string)$val]);
        }
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update setting']);
        exit;
    }
}

// ─── Change Password ──────────────────────────────────────────────────
if ($route === '/admin/change-password' && $method === 'POST') {
    try {
        $newPass = trim($requestData['newPassword'] ?? '');
        if (!$newPass) {
            http_response_code(400);
            echo json_encode(['error' => 'New password required']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('admin_password', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$newPass, $newPass]);
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to change password']);
        exit;
    }
}

// ─── Reseller Management ──────────────────────────────────────────────
if ($route === '/admin/reseller/status' && $method === 'GET') {
    try {
        $stmt = $pdo->query(
            "SELECT setting_key, setting_value
               FROM settings
              WHERE setting_key IN (
                  'reseller_balance',
                  'total_deposit',
                  'min_rate_multiplier',
                  'rate_multiplier'
              )"
        );

        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $resellerBalanceRaw = $rows['reseller_balance'] ?? '0';
        if (!is_numeric($resellerBalanceRaw)) {
            $resellerBalanceRaw = '0';
        }

        echo json_encode([
            'success' => true,
            'reseller_balance' => (float)number_format((float)$resellerBalanceRaw, 2, '.', ''),
            'total_deposit' => (float)($rows['total_deposit'] ?? 0),
            'min_rate_multiplier' => (float)($rows['min_rate_multiplier'] ?? 200),
            'rate_multiplier' => (float)($rows['rate_multiplier'] ?? 220),
            'balance_key' => 'reseller_balance'
        ]);

        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to load reseller status'
        ]);
        exit;
    }
}

if ($route === '/admin/reseller/add-balance' && $method === 'POST') {
    try {
        $amount = (float)($requestData['amount'] ?? 0);

        if ($amount <= 0) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'error' => 'Amount must be greater than zero'
            ]);
            exit;
        }

        $startedTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $stmt = $pdo->prepare(
            "SELECT setting_value
               FROM settings
              WHERE setting_key = 'reseller_balance'
              LIMIT 1
              FOR UPDATE"
        );
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $currentRaw = $row['setting_value'] ?? '0';

        if (!is_numeric($currentRaw)) {
            $currentRaw = '0';
        }

        $current = (float)number_format((float)$currentRaw, 4, '.', '');
        $amount = (float)number_format($amount, 4, '.', '');
        $newBal = (float)number_format($current + $amount, 4, '.', '');

        $u = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value, created_at)
             VALUES ('reseller_balance', ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = ?"
        );
        $u->execute([
            number_format($newBal, 4, '.', ''),
            number_format($newBal, 4, '.', '')
        ]);

        try {
            $audit = $pdo->prepare(
                "INSERT INTO settings_audit (
                    setting_key,
                    old_value,
                    new_value,
                    change_reason,
                    created_at
                 )
                 VALUES (
                    'reseller_balance',
                    ?,
                    ?,
                    'admin_add_balance',
                    NOW()
                 )"
            );
            $audit->execute([
                number_format($current, 4, '.', ''),
                number_format($newBal, 4, '.', '')
            ]);
        } catch (Exception $auditErr) {
            // Ignore audit table absence if not yet migrated
        }

        if ($startedTransaction) {
            $pdo->commit();
        }

        echo json_encode([
            'success' => true,
            'new_balance' => (float)number_format($newBal, 2, '.', ''),
            'balance_key' => 'reseller_balance'
        ]);

        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to add reseller balance'
        ]);
        exit;
    }
}

if ($route === '/admin/reseller/withdraw-deposit' && $method === 'POST') {
    header('Content-Type: application/json');

    $amount = isset($requestData['amount']) ? (float)$requestData['amount'] : 0.0;
    $bankName = isset($requestData['bank_name']) ? trim((string)$requestData['bank_name']) : '';
    $accountNumber = isset($requestData['account_number']) ? trim((string)$requestData['account_number']) : '';
    $accountName = isset($requestData['account_name']) && trim((string)$requestData['account_name']) !== ''
        ? trim((string)$requestData['account_name'])
        : 'Admin';

    if ($amount <= 0 || $bankName === '' || $accountNumber === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'amount, bank_name, and account_number are required'
        ]);
        exit;
    }

    $env = function (string $key, string $default = ''): string {
        $value = getenv($key);

        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }

        return (string)$value;
    };

    $httpJson = function (string $method, string $url, array $headers, string $body, int $timeout = 10): array {
        if (!function_exists('curl_init')) {
            return [
                'code' => 0,
                'body' => '',
                'error' => 'cURL is not available'
            ];
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        if ($method === 'POST' && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        return [
            'code' => $statusCode,
            'body' => $responseBody === false ? '' : (string)$responseBody,
            'error' => $error
        ];
    };

    try {
        $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('total_deposit', '0')");

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'total_deposit' FOR UPDATE");
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalDeposit = $row && isset($row['setting_value']) ? (float)$row['setting_value'] : 0.0;

        if ($totalDeposit < $amount) {
            $pdo->rollBack();

            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => "Withdrawal amount ({$amount} ETB) exceeds available Total Deposit balance (" . number_format($totalDeposit, 2) . " ETB)"
            ]);
            exit;
        }

        $newTotal = max(0.0, $totalDeposit - $amount);

        $stmt = $pdo->prepare("UPDATE settings SET setting_value = :new_total WHERE setting_key = 'total_deposit'");
        $stmt->execute([
            'new_total' => number_format($newTotal, 2, '.', '')
        ]);

        $stmt = $pdo->prepare("INSERT INTO admin_withdrawals (amount, bank_name, account_number, account_name, status) VALUES (:amount, :bank_name, :account_number, :account_name, 'pending')");
        $stmt->execute([
            'amount' => $amount,
            'bank_name' => $bankName,
            'account_number' => $accountNumber,
            'account_name' => $accountName
        ]);

        $localId = (int)$pdo->lastInsertId();

        $pdo->commit();
    } catch (Exception $txErr) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to process withdrawal: ' . $txErr->getMessage()
        ]);
        exit;
    }

    $joadminRequestId = null;

    try {
        $joadminUrl = rtrim($env('JOADMIN_SERVER_URL', 'https://padmin121-1.onrender.com'), '/');
        $joadminApiKey = $env('JOADMIN_API_KEY', '');
        $resellerId = $env('RESELLER_ID', 'primore');
        $siteUrl = $env('SITE_URL', 'https://promre-back.onrender.com');
        $baseUrl = strpos($siteUrl, 'http') === 0 ? $siteUrl : "https://{$siteUrl}";
        $callbackUrl = "{$baseUrl}/api/admin/reseller/withdrawal/callback";

        if ($joadminApiKey !== '') {
            $payload = json_encode([
                'reseller_id' => $resellerId,
                'local_id' => $localId,
                'amount' => $amount,
                'bank_name' => $bankName,
                'account_number' => $accountNumber,
                'account_name' => $accountName,
                'callback_url' => $callbackUrl
            ]);

            $fwRes = $httpJson(
                'POST',
                "{$joadminUrl}/api/admin/reseller/withdrawal-request",
                [
                    'x-api-key: ' . $joadminApiKey,
                    'Content-Type: application/json'
                ],
                $payload,
                10
            );

            if ($fwRes['code'] === 200) {
                $fwData = json_decode($fwRes['body'], true);

                if (is_array($fwData) && isset($fwData['request_id'])) {
                    $joadminRequestId = (int)$fwData['request_id'];

                    $stmt = $pdo->prepare("UPDATE admin_withdrawals SET joadmin_request_id = :joadmin_request_id WHERE id = :id");
                    $stmt->execute([
                        'joadmin_request_id' => $joadminRequestId,
                        'id' => $localId
                    ]);
                }
            }
        }
    } catch (Exception $forwardErr) {
    }

    try {
        $withdrawBotToken = $env('WITHDRAWAL_BOT_TOKEN', '');

        if ($withdrawBotToken !== '') {
            $adminChatIds = [5928771903, 779060335, 460529558];
            $currentTime = date('Y-m-d H:i:s');

            $msg = "💸 <b>New Reseller Withdrawal Request</b>\n"
                . "👤 Reseller: <b>" . htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8') . "</b>\n"
                . "💵 Amount: <b>" . number_format($amount, 2, '.', '') . " ETB</b>\n"
                . "🏦 Bank: <b>" . htmlspecialchars($bankName, ENT_QUOTES, 'UTF-8') . "</b>\n"
                . "🔢 Account Number: <code>" . htmlspecialchars($accountNumber, ENT_QUOTES, 'UTF-8') . "</code>\n"
                . "🆔 Local Request ID: <code>#" . $localId . "</code>\n"
                . "🕒 Time: " . $currentTime;

            foreach ($adminChatIds as $chatId) {
                $httpJson(
                    'POST',
                    "https://api.telegram.org/bot{$withdrawBotToken}/sendMessage",
                    [
                        'Content-Type: application/json'
                    ],
                    json_encode([
                        'chat_id' => $chatId,
                        'text' => $msg,
                        'parse_mode' => 'HTML'
                    ]),
                    5
                );
            }
        }
    } catch (Exception $tgErr) {
    }

    echo json_encode([
        'success' => true,
        'new_total_deposit' => (float)$newTotal,
        'local_id' => $localId,
        'joadmin_request_id' => $joadminRequestId,
        'status' => 'pending',
        'message' => 'Withdrawal request submitted. Awaiting joadmin confirmation.'
    ]);

    exit;
}

// Ensure reseller_deposits table exists (Auto-migration safety net)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reseller_deposits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            amount DECIMAL(10, 2) NOT NULL,
            tx_ref VARCHAR(255) NOT NULL UNIQUE,
            status VARCHAR(50) DEFAULT 'pending',
            chapa_tx_ref VARCHAR(255) DEFAULT NULL,
            chapa_response TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {
    // Log error but do not block execution if table already exists
}

// ─── ROUTE: /admin/reseller/deposit/test-init (GET) ──────────────────
if ($route === '/admin/reseller/deposit/test-init' && $method === 'GET') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => "Reseller deposit router is fully active!"]);
    exit;
}

// ─── ROUTE: /admin/reseller/deposit/init (POST) ──────────────────────
if ($route === '/admin/reseller/deposit/init' && $method === 'POST') {
    header('Content-Type: application/json');
    $rawAmount = isset($requestData['amount']) ? $requestData['amount'] : 0;
    $amount = (float)$rawAmount;

    $envGetter = function (string $key, string $default = ''): string {
        $val = getenv($key);
        if ($val === false) {
            $val = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }
        return (string)$val;
    };

    $minDep = (int)$envGetter('MIN_DEPOSIT', '10');
    $maxDep = (int)$envGetter('MAX_DEPOSIT', '100000');

    if ($amount < $minDep) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Minimum deposit is {$minDep} ETB"]);
        exit;
    }
    if ($amount > $maxDep) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Maximum deposit is " . number_format($maxDep) . " ETB"]);
        exit;
    }

    $txRef = "RADM-" . time() . "-" . bin2hex(random_bytes(4));

    try {
        $stmt = $pdo->prepare("INSERT INTO reseller_deposits (amount, tx_ref, status) VALUES (:amount, :tx_ref, 'pending')");
        $stmt->execute(['amount' => $amount, 'tx_ref' => $txRef]);

        $chapaSecretKey = $envGetter('CHAPA_SECRET_KEY', 'CHASECK-WGUq6JVPIxSmjVSWTebh5UOOcshNscEd');
        $chapaBaseUrl = rtrim($envGetter('CHAPA_BASE_URL', 'https://api.chapa.co/v1'), '/');
        $siteUrl = $envGetter('SITE_URL', 'https://promre-back.onrender.com');
        $baseUrl = (strpos($siteUrl, 'http') === 0) ? $siteUrl : "https://{$siteUrl}";

        $chapaCallbackUrl = "{$baseUrl}/api/admin/reseller/deposit/callback";
        $chapaReturnUrl = isset($requestData['return_url']) ? $requestData['return_url'] : "{$baseUrl}/api/admin/reseller/deposit/callback?tx_ref={$txRef}";

        $payload = [
            'amount'        => $amount,
            'currency'      => 'ETB',
            'email'         => 'admin@primore.com',
            'first_name'    => 'Admin',
            'last_name'     => 'Reseller',
            'tx_ref'        => $txRef,
            'callback_url'  => $chapaCallbackUrl,
            'return_url'    => $chapaReturnUrl,
            'customization' => [
                'title'       => 'Primore Topup',
                'description' => 'Admin balance deposit'
            ]
        ];

        $res = function_exists('curlRequest')
            ? curlRequest('POST', "{$chapaBaseUrl}/transaction/initialize", [
                "Authorization: Bearer {$chapaSecretKey}",
                "Content-Type: application/json"
            ], json_encode($payload), 20)
            : ['code' => 0, 'body' => ''];

        $chapaData = json_decode($res['body'], true);
        $success = $res['code'] === 200 && isset($chapaData['status']) && $chapaData['status'] === 'success';

        if ($success && isset($chapaData['data']['checkout_url'])) {
            $checkoutUrl = $chapaData['data']['checkout_url'];
            $stmt = $pdo->prepare("UPDATE reseller_deposits SET status = 'initiated' WHERE tx_ref = :tx_ref");
            $stmt->execute(['tx_ref' => $txRef]);
            echo json_encode([
                'success'      => true,
                'checkout_url' => $checkoutUrl,
                'tx_ref'       => $txRef
            ]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM reseller_deposits WHERE tx_ref = :tx_ref");
            $stmt->execute(['tx_ref' => $txRef]);
            http_response_code(400);
            $errMsg = 'Failed to initialize Chapa payment';
            if (isset($chapaData['message'])) {
                $errMsg = is_array($chapaData['message']) ? json_encode($chapaData['message']) : (string)$chapaData['message'];
            }
            if (empty($errMsg) && isset($chapaData['error'])) {
                $errMsg = is_array($chapaData['error']) ? json_encode($chapaData['error']) : (string)$chapaData['error'];
            }
            echo json_encode([
                'success' => false,
                'error' => "Chapa Error: {$errMsg}",
                'debug' => isset($res['body']) ? $res['body'] : ''
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'System error: ' . $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /admin/reseller/deposit/callback (GET / POST) ────────────
if ($route === '/admin/reseller/deposit/callback') {
    header('Content-Type: application/json');
    $envGetter = function (string $key, string $default = ''): string {
        $val = getenv($key);
        if ($val === false) {
            $val = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }
        return (string)$val;
    };
    $chapaSecretKey = $envGetter('CHAPA_SECRET_KEY', 'CHASECK-WGUq6JVPIxSmjVSWTebh5UOOcshNscEd');
    $chapaBaseUrl = rtrim($envGetter('CHAPA_BASE_URL', 'https://api.chapa.co/v1'), '/');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        $signature = isset($headers['chapa-signature']) ? $headers['chapa-signature'] : null;
        if ($signature && $chapaSecretKey) {
            $rawPost = file_get_contents('php://input');
            $hash = hash_hmac('sha256', $rawPost, $chapaSecretKey);
            if ($signature !== $hash) {
                http_response_code(401);
                echo "Forbidden";
                exit;
            }
        }
    }

    $txRef = isset($requestData['trx_ref']) ? $requestData['trx_ref'] : (isset($requestData['tx_ref']) ? $requestData['tx_ref'] : '');
    if (empty($txRef)) {
        echo json_encode(['success' => false, 'message' => 'Missing tx_ref']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT status, amount FROM reseller_deposits WHERE tx_ref = :tx_ref FOR UPDATE');
        $stmt->execute(['tx_ref' => $txRef]);
        $depositCheck = $stmt->fetch();

        if (!$depositCheck) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Deposit not found']);
            exit;
        }
        if ($depositCheck['status'] === 'success') {
            $pdo->rollBack();
            echo json_encode(['success' => true, 'message' => 'Already processed']);
            exit;
        }

        $url = "{$chapaBaseUrl}/transaction/verify/{$txRef}?_t=" . time();
        $res = function_exists('curlRequest')
            ? curlRequest('GET', $url, [
                "Authorization: Bearer {$chapaSecretKey}",
                "Cache-Control: no-cache"
            ], null, 20)
            : ['code' => 0, 'body' => ''];

        $verifyData = json_decode($res['body'], true);
        $chapaStatus = isset($verifyData['data']['status']) ? strtolower($verifyData['data']['status']) : '';
        $isSuccess = $res['code'] === 200 && ($chapaStatus === 'success' || $chapaStatus === 'paid');

        if ($isSuccess) {
            $verifiedAmount = isset($verifyData['data']['amount']) ? (float)$verifyData['data']['amount'] : (float)$depositCheck['amount'];
            $chapaRef = isset($verifyData['data']['reference']) ? $verifyData['data']['reference'] : '';
            $responseJson = json_encode($verifyData);

            $stmt = $pdo->prepare("UPDATE reseller_deposits SET status = 'success', chapa_tx_ref = :chapa_ref, chapa_response = :resp, completed_at = NOW() WHERE tx_ref = :tx_ref");
            $stmt->execute(['chapa_ref' => $chapaRef, 'resp' => $responseJson, 'tx_ref' => $txRef]);

            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'reseller_balance' LIMIT 1");
            $stmt->execute();
            $sRow = $stmt->fetch();
            $currentBalance = $sRow ? (float)$sRow['setting_value'] : 0.0;
            $newBalance = $currentBalance + $verifiedAmount;

            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('reseller_balance', :val) ON DUPLICATE KEY UPDATE setting_value = :val_up");
            $stmt->execute(['val' => (string)$newBalance, 'val_up' => (string)$newBalance]);

            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'total_deposit' LIMIT 1");
            $stmt->execute();
            $tdRow = $stmt->fetch();
            $currentTotalDeposit = $tdRow ? (float)$tdRow['setting_value'] : 0.0;
            $newTotalDeposit = $currentTotalDeposit + $verifiedAmount;

            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('total_deposit', :val) ON DUPLICATE KEY UPDATE setting_value = :val_up");
            $stmt->execute(['val' => (string)$newTotalDeposit, 'val_up' => (string)$newTotalDeposit]);

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Deposit credited successfully', 'reseller_balance' => $newBalance]);
        } else {
            $realStatus = isset($verifyData['data']['status']) ? $verifyData['data']['status'] : 'pending';
            if (strtolower($realStatus) === 'failed') {
                $stmt = $pdo->prepare("UPDATE reseller_deposits SET status = 'failed' WHERE tx_ref = :tx_ref");
                $stmt->execute(['tx_ref' => $txRef]);
            }
            $pdo->commit();
            echo json_encode(['success' => false, 'message' => 'Payment verification pending or failed']);
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /admin/reseller/deposit/verify (POST / GET) ──────────────
if ($route === '/admin/reseller/deposit/verify' && ($method === 'POST' || $method === 'GET')) {
    header('Content-Type: application/json');
    $txRef = isset($requestData['tx_ref']) ? $requestData['tx_ref'] : null;
    if (empty($txRef)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing transaction reference']);
        exit;
    }

    $envGetter = function (string $key, string $default = ''): string {
        $val = getenv($key);
        if ($val === false) {
            $val = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
        }
        return (string)$val;
    };
    $chapaSecretKey = $envGetter('CHAPA_SECRET_KEY', 'CHASECK-WGUq6JVPIxSmjVSWTebh5UOOcshNscEd');
    $chapaBaseUrl = rtrim($envGetter('CHAPA_BASE_URL', 'https://api.chapa.co/v1'), '/');

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT status, amount FROM reseller_deposits WHERE tx_ref = :tx_ref FOR UPDATE');
        $stmt->execute(['tx_ref' => $txRef]);
        $depositCheck = $stmt->fetch();

        if (!$depositCheck) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Deposit record not found']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'reseller_balance' LIMIT 1");
        $stmt->execute();
        $sRow = $stmt->fetch();
        $resellerBalance = $sRow ? (float)$sRow['setting_value'] : 0.0;

        if ($depositCheck['status'] === 'success') {
            $pdo->rollBack();
            echo json_encode([
                'success'           => true,
                'reseller_balance'  => $resellerBalance,
                'message'           => 'Payment verified and credited.'
            ]);
            exit;
        }

        $url = "{$chapaBaseUrl}/transaction/verify/{$txRef}?_t=" . time();
        $res = function_exists('curlRequest')
            ? curlRequest('GET', $url, [
                "Authorization: Bearer {$chapaSecretKey}",
                "Cache-Control: no-cache"
            ], null, 20)
            : ['code' => 0, 'body' => ''];

        $verifyData = json_decode($res['body'], true);
        $chapaStatus = isset($verifyData['data']['status']) ? strtolower($verifyData['data']['status']) : '';
        $isSuccess = $res['code'] === 200 && ($chapaStatus === 'success' || $chapaStatus === 'paid');

        if ($isSuccess) {
            $verifiedAmount = isset($verifyData['data']['amount']) ? (float)$verifyData['data']['amount'] : (float)$depositCheck['amount'];
            $chapaRef = isset($verifyData['data']['reference']) ? $verifyData['data']['reference'] : '';
            $responseJson = json_encode($verifyData);

            $stmt = $pdo->prepare("UPDATE reseller_deposits SET status = 'success', chapa_tx_ref = :chapa_ref, chapa_response = :resp, completed_at = NOW() WHERE tx_ref = :tx_ref");
            $stmt->execute(['chapa_ref' => $chapaRef, 'resp' => $responseJson, 'tx_ref' => $txRef]);

            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'reseller_balance' LIMIT 1");
            $stmt->execute();
            $sRow = $stmt->fetch();
            $currentBalance = $sRow ? (float)$sRow['setting_value'] : 0.0;
            $newBalance = $currentBalance + $verifiedAmount;

            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('reseller_balance', :val) ON DUPLICATE KEY UPDATE setting_value = :val_up");
            $stmt->execute(['val' => (string)$newBalance, 'val_up' => (string)$newBalance]);

            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'total_deposit' LIMIT 1");
            $stmt->execute();
            $tdRow = $stmt->fetch();
            $currentTotalDeposit = $tdRow ? (float)$tdRow['setting_value'] : 0.0;
            $newTotalDeposit = $currentTotalDeposit + $verifiedAmount;

            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('total_deposit', :val) ON DUPLICATE KEY UPDATE setting_value = :val_up");
            $stmt->execute(['val' => (string)$newTotalDeposit, 'val_up' => (string)$newTotalDeposit]);

            $pdo->commit();
            echo json_encode([
                'success'           => true,
                'reseller_balance'  => $newBalance,
                'message'           => 'Payment verified and balance updated!'
            ]);
        } else {
            $isFailed = ($chapaStatus === 'failed' || strpos($chapaStatus, 'reject') !== false || strpos($chapaStatus, 'cancel') !== false);
            if ($isFailed) {
                $stmt = $pdo->prepare("UPDATE reseller_deposits SET status = 'failed' WHERE tx_ref = :tx_ref");
                $stmt->execute(['tx_ref' => $txRef]);
            }
            $pdo->commit();

            if ($isFailed) {
                echo json_encode([
                    'success' => false,
                    'message' => 'failed',
                    'error'   => 'Payment was declined or cancelled by user.'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'pending',
                    'error'   => 'Payment verification pending. Please complete transaction on your phone.'
                ]);
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => 'error', 'error' => 'Verification error: ' . $e->getMessage()]);
    }
    exit;
}

if ($route === '/admin/reseller/deposit/history' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT * FROM reseller_deposits ORDER BY id DESC LIMIT 50");
        $deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($deposits as &$d) {
            $d['id'] = (int)$d['id'];
            $d['amount'] = (float)$d['amount'];
        }
        echo json_encode(['success' => true, 'deposits' => $deposits]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load deposit history']);
        exit;
    }
}

if ($route === '/admin/reseller/withdrawal-history' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT * FROM admin_withdrawals ORDER BY id DESC LIMIT 50");
        $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($withdrawals as &$w) {
            $w['id'] = (int)$w['id'];
            $w['amount'] = (float)$w['amount'];
        }
        echo json_encode(['success' => true, 'withdrawals' => $withdrawals]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load withdrawal history']);
        exit;
    }
}

// ─── Broadcasts ────────────────────────────────────────────────────────
if ($route === '/admin/broadcasts' && $method === 'GET') {
    try {
        $stmt = $pdo->query("
            SELECT b.*, 
                   (SELECT COUNT(*) FROM broadcast_messages bm WHERE bm.broadcast_id = b.id AND bm.status = 'sent') as sent_count,
                   (SELECT COUNT(*) FROM broadcast_messages bm WHERE bm.broadcast_id = b.id AND bm.status = 'failed') as failed_count
            FROM broadcasts b ORDER BY b.id DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['sent_count'] = (int)$r['sent_count'];
            $r['failed_count'] = (int)$r['failed_count'];
        }
        echo json_encode($rows);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load broadcasts']);
        exit;
    }
}

if ($route === '/admin/broadcasts' && $method === 'POST') {
    try {
        $msg = $requestData['message'] ?? '';
        $img = $requestData['imageUrl'] ?? null;
        $btnText = $requestData['btnText'] ?? 'Open App';
        $btnUrl = $requestData['btnUrl'] ?? '';

        $stmt = $pdo->prepare("INSERT INTO broadcasts (message, image_url, btn_text, btn_url) VALUES (?, ?, ?, ?)");
        $stmt->execute([$msg, $img, $btnText, $btnUrl]);
        $bId = $pdo->lastInsertId();

        echo json_encode(['success' => true, 'broadcast_id' => (int)$bId, 'sent_count' => 0, 'failed_count' => 0]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to create broadcast']);
        exit;
    }
}

if (preg_match('#^/admin/broadcasts/(\d+)$#', $route, $m) && $method === 'DELETE') {
    try {
        $bId = (int)$m[1];
        $stmt = $pdo->prepare("DELETE FROM broadcasts WHERE id = ?");
        $stmt->execute([$bId]);
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete broadcast']);
        exit;
    }
}

if (preg_match('#^/admin/broadcasts/(\d+)/messages$#', $route, $m) && $method === 'GET') {
    try {
        $bId = (int)$m[1];
        $stmt = $pdo->prepare("
            SELECT bm.*, a.first_name, a.username 
            FROM broadcast_messages bm 
            LEFT JOIN auth a ON bm.tg_id = a.tg_id 
            WHERE bm.broadcast_id = ? ORDER BY bm.id DESC
        ");
        $stmt->execute([$bId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['broadcast_id'] = (int)$r['broadcast_id'];
        }
        echo json_encode($rows);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load broadcast message details']);
        exit;
    }
}

if (preg_match('#^/admin/broadcasts/messages/(\d+)$#', $route, $m) && $method === 'DELETE') {
    try {
        $mId = (int)$m[1];
        $stmt = $pdo->prepare("DELETE FROM broadcast_messages WHERE id = ?");
        $stmt->execute([$mId]);
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete message']);
        exit;
    }
}

// ─── Users List & Controls ─────────────────────────────────────────────
if ($route === '/admin/users' && $method === 'GET') {
    try {
        $page = (int)($requestData['page'] ?? 1);
        $limit = (int)($requestData['limit'] ?? 20);
        $offset = ($page - 1) * $limit;
        $search = trim($requestData['search'] ?? '');
        $sortBy = $requestData['sortBy'] ?? 'last_login';
        $sortOrder = strtoupper($requestData['sortOrder'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $where = "WHERE 1=1";
        $params = [];
        if ($search !== '') {
            $where .= " AND (tg_id LIKE ? OR username LIKE ? OR first_name LIKE ? OR last_name LIKE ?)";
            $s = "%{$search}%";
            $params = [$s, $s, $s, $s];
        }

        $orderSql = "ORDER BY last_login DESC";
        if ($sortBy === 'big_balance') $orderSql = "ORDER BY balance $sortOrder";
        elseif ($sortBy === 'recent_registration') $orderSql = "ORDER BY tg_id $sortOrder";

        $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM auth $where");
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['total'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM auth $where $orderSql LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as &$u) {
            $u['balance'] = (float)$u['balance'];
        }

        echo json_encode(['users' => $users, 'total' => $total]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load users']);
        exit;
    }
}

if ($route === '/admin/users/balance' && $method === 'POST') {
    try {
        $tgId = $requestData['tg_id'] ?? null;
        $amount = (float)($requestData['amount'] ?? 0);
        if (!$tgId) {
            http_response_code(400);
            echo json_encode(['error' => 'tg_id is required']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE auth SET balance = balance + ? WHERE tg_id = ?");
        $stmt->execute([$amount, $tgId]);

        $bStmt = $pdo->prepare("SELECT balance FROM auth WHERE tg_id = ?");
        $bStmt->execute([$tgId]);
        $newBal = (float)($bStmt->fetch()['balance'] ?? 0);

        echo json_encode(['success' => true, 'newBalance' => $newBal]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update balance']);
        exit;
    }
}

// ─── Orders List ──────────────────────────────────────────────────────
if ($route === '/admin/orders' && $method === 'GET') {
    try {
        $page = (int)($requestData['page'] ?? 1);
        $limit = (int)($requestData['limit'] ?? 20);
        $offset = ($page - 1) * $limit;
        $search = trim($requestData['search'] ?? '');
        $status = trim($requestData['status'] ?? '');

        $where = "WHERE 1=1";
        $params = [];
        if ($search !== '') {
            $where .= " AND (o.id LIKE ? OR o.user_id LIKE ? OR o.link LIKE ?)";
            $s = "%{$search}%";
            $params[] = $s; $params[] = $s; $params[] = $s;
        }
        if ($status !== '') {
            $where .= " AND o.status = ?";
            $params[] = $status;
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM orders o $where");
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['total'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT o.*, a.username, a.first_name 
            FROM orders o 
            LEFT JOIN auth a ON o.user_id = a.tg_id 
            $where ORDER BY o.created_at DESC LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orders as &$o) {
            $o['id'] = (int)$o['id'];
            $o['quantity'] = (int)$o['quantity'];
            $o['charge'] = (float)($o['charge'] ?? 0);
            $o['cost'] = (float)($o['cost'] ?? $o['charge'] ?? 0);
            $o['target_link'] = $o['target_link'] ?? $o['link'] ?? '';
            $o['provider_order_id'] = $o['api_order_id'] ?? '';
        }

        echo json_encode(['orders' => $orders, 'total' => $total]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load orders']);
        exit;
    }
}

// ─── Deposits List & Manual Approval ──────────────────────────────────
if ($route === '/admin/deposits' && $method === 'GET') {
    try {
        $page = (int)($requestData['page'] ?? 1);
        $limit = (int)($requestData['limit'] ?? 20);
        $offset = ($page - 1) * $limit;
        $search = trim($requestData['search'] ?? '');
        $status = trim($requestData['status'] ?? '');

        $where = "WHERE 1=1";
        $params = [];
        if ($search !== '') {
            $where .= " AND (d.tx_ref LIKE ? OR d.user_id LIKE ?)";
            $s = "%{$search}%";
            $params[] = $s; $params[] = $s;
        }
        if ($status !== '') {
            $where .= " AND d.status = ?";
            $params[] = $status;
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM deposits d $where");
        $countStmt->execute($params);
        $total = (int)($countStmt->fetch()['total'] ?? 0);

        $stmt = $pdo->prepare("
            SELECT d.*, a.username, a.first_name 
            FROM deposits d 
            LEFT JOIN auth a ON d.user_id = a.tg_id 
            $where ORDER BY d.created_at DESC LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $deposits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($deposits as &$d) {
            $d['id'] = (int)$d['id'];
            $d['amount'] = (float)$d['amount'];
        }

        echo json_encode(['deposits' => $deposits, 'total' => $total]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load deposits']);
        exit;
    }
}

if ($route === '/admin/deposits/status' && $method === 'POST') {
    try {
        $depId = (int)($requestData['deposit_id'] ?? 0);
        $newStat = $requestData['status'] ?? 'completed';
        $updateBal = !empty($requestData['update_balance']);

        $stmt = $pdo->prepare("SELECT * FROM deposits WHERE id = ?");
        $stmt->execute([$depId]);
        $dep = $stmt->fetch();
        if (!$dep) {
            http_response_code(404);
            echo json_encode(['error' => 'Deposit not found']);
            exit;
        }

        $oldStat = $dep['status'];
        $uStmt = $pdo->prepare("UPDATE deposits SET status = ?, completed_at = NOW() WHERE id = ?");
        $uStmt->execute([$newStat, $depId]);

        $newBal = null;
        if ($updateBal && ($newStat === 'completed' || $newStat === 'success') && $oldStat !== 'completed' && $oldStat !== 'success') {
            $bStmt = $pdo->prepare("UPDATE auth SET balance = balance + ? WHERE tg_id = ?");
            $bStmt->execute([(float)$dep['amount'], $dep['user_id']]);

            $nbStmt = $pdo->prepare("SELECT balance FROM auth WHERE tg_id = ?");
            $nbStmt->execute([$dep['user_id']]);
            $newBal = (float)($nbStmt->fetch()['balance'] ?? 0);
        }

        echo json_encode(['success' => true, 'old_status' => $oldStat, 'new_status' => $newStat, 'new_balance' => $newBal]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to update deposit status']);
        exit;
    }
}

// ─── Service Custom Pricing & Activity ───────────────────────────────
if ($route === '/admin/services/custom' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT * FROM service_custom");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['service_id'] = (int)$r['service_id'];
            $r['is_enabled'] = (bool)$r['is_enabled'];
            $r['custom_rate'] = $r['custom_rate'] !== null ? (float)$r['custom_rate'] : null;
            $r['profit_margin'] = (float)($r['profit_margin'] ?? 0);
        }
        echo json_encode($rows);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load custom pricing']);
        exit;
    }
}

if ($route === '/admin/services/custom' && $method === 'POST') {
    try {
        $sId = (int)($requestData['service_id'] ?? 0);
        $cRate = isset($requestData['custom_rate']) && $requestData['custom_rate'] !== null ? (float)$requestData['custom_rate'] : null;
        $margin = isset($requestData['profit_margin']) ? (float)$requestData['profit_margin'] : 0;
        $enabled = isset($requestData['is_enabled']) ? ($requestData['is_enabled'] ? 1 : 0) : 1;

        $stmt = $pdo->prepare("INSERT INTO service_custom (service_id, custom_rate, profit_margin, is_enabled) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE custom_rate = ?, profit_margin = ?, is_enabled = ?");
        $stmt->execute([$sId, $cRate, $margin, $enabled, $cRate, $margin, $enabled]);

        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save custom service settings']);
        exit;
    }
}

if ($route === '/admin/services/activity' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT *, created_at as updated_at FROM service_custom ORDER BY id DESC LIMIT 50");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['service_id'] = (int)$r['service_id'];
            $r['is_enabled'] = (bool)$r['is_enabled'];
            $r['custom_rate'] = $r['custom_rate'] !== null ? (float)$r['custom_rate'] : null;
            $r['profit_margin'] = (float)($r['profit_margin'] ?? 0);
        }
        echo json_encode($rows);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load service activity']);
        exit;
    }
}

if ($route === '/admin/services/disabled' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT * FROM service_custom WHERE is_enabled = 0");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['service_id'] = (int)$r['service_id'];
            $r['is_enabled'] = false;
        }
        echo json_encode($rows);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load disabled services']);
        exit;
    }
}

// ─── Withdrawals (User Requests) ──────────────────────────────────────
if ($route === '/admin/withdrawals' && $method === 'GET') {
    try {
        $stmt = $pdo->query("
            SELECT w.*, a.username, a.first_name, a.last_name 
            FROM withdrawals w 
            LEFT JOIN auth a ON w.user_id = a.tg_id 
            ORDER BY w.created_at DESC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['amount'] = (float)$r['amount'];
        }
        echo json_encode(['success' => true, 'withdrawals' => $rows]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load withdrawals']);
        exit;
    }
}

if ($route === '/admin/withdrawals/approve' && $method === 'POST') {
    try {
        $wId = (int)($requestData['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE withdrawals SET status = 'done' WHERE id = ?");
        $stmt->execute([$wId]);
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to approve withdrawal']);
        exit;
    }
}

// ─── Finance Stats ────────────────────────────────────────────────────
if ($route === '/admin/finance-stats' && $method === 'GET') {
    try {
        $revStmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM deposits WHERE status IN ('completed', 'success')");
        $totalRevenue = (float)($revStmt->fetch()['total'] ?? 0);

        $withStmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM withdrawals WHERE status = 'done'");
        $totalWithdrawn = (float)($withStmt->fetch()['total'] ?? 0);

        $pendStmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) as total FROM withdrawals WHERE status = 'pending'");
        $pendingWithdrawn = (float)($pendStmt->fetch()['total'] ?? 0);

        echo json_encode([
            'success' => true,
            'totalRevenue' => $totalRevenue,
            'todayRevenue' => 0,
            'weeklyRevenue' => $totalRevenue,
            'monthlyRevenue' => $totalRevenue,
            'totalWithdrawn' => $totalWithdrawn,
            'todayWithdrawals' => 0,
            'weeklyWithdrawals' => $totalWithdrawn,
            'monthlyWithdrawals' => $totalWithdrawn,
            'withdrawableBalance' => $totalRevenue - $totalWithdrawn,
            'pendingWithdrawals' => $pendingWithdrawn,
            'totalWithdrawals' => $totalWithdrawn,
            'totalWithdrawalsCount' => 0,
            'totalPayingUsers' => 0,
            'providerCosts' => 0,
            'revenueGrowth' => 0
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load finance stats']);
        exit;
    }
}

// ─── Holidays API ─────────────────────────────────────────────────────
if ($route === '/admin/holidays' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT * FROM holidays ORDER BY start_date ASC, id DESC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['discount_percent'] = (int)$r['discount_percent'];
            $r['is_recurring'] = (int)$r['is_recurring'];
        }
        echo json_encode(['success' => true, 'holidays' => $rows]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load holidays: ' . $e->getMessage()]);
        exit;
    }
}

if ($route === '/admin/holidays' && $method === 'POST') {
    try {
        $id = $requestData['id'] ?? null;
        $name = trim($requestData['name'] ?? '');
        if (!$name) {
            http_response_code(400);
            echo json_encode(['error' => 'Holiday name is required']);
            exit;
        }

        $disc = (int)($requestData['discount_percent'] ?? 0);
        $stat = ($requestData['status'] ?? '') === 'active' ? 'active' : 'inactive';
        $cat = $requestData['category'] ?? 'custom';
        $recur = (isset($requestData['is_recurring']) && ($requestData['is_recurring'] === false || $requestData['is_recurring'] === 0)) ? 0 : 1;
        $desc = $requestData['description'] ?? '';
        $startDate = !empty($requestData['start_date']) ? $requestData['start_date'] : null;
        $endDate = !empty($requestData['end_date']) ? $requestData['end_date'] : null;

        if ($id) {
            $stmt = $pdo->prepare("UPDATE holidays SET name=?, discount_percent=?, status=?, start_date=?, end_date=?, category=?, is_recurring=?, description=? WHERE id=?");
            $stmt->execute([$name, $disc, $stat, $startDate, $endDate, $cat, $recur, $desc, $id]);
            $targetId = $id;
        } else {
            $stmt = $pdo->prepare("INSERT INTO holidays (name, discount_percent, status, start_date, end_date, category, is_recurring, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $disc, $stat, $startDate, $endDate, $cat, $recur, $desc]);
            $targetId = $pdo->lastInsertId();
        }

        if ($stat === 'active' && $targetId) {
            $u = $pdo->prepare("UPDATE holidays SET status = 'inactive' WHERE id != ?");
            $u->execute([$targetId]);
        }

        syncActiveHolidayToSettings($pdo);

        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save holiday: ' . $e->getMessage()]);
        exit;
    }
}

if (preg_match('#^/admin/holidays/(\d+)$#', $route, $m) && $method === 'DELETE') {
    try {
        $delId = (int)$m[1];
        $stmt = $pdo->prepare("DELETE FROM holidays WHERE id = ?");
        $stmt->execute([$delId]);
        syncActiveHolidayToSettings($pdo);
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to delete holiday']);
        exit;
    }
}

if (preg_match('#^/admin/holidays/(\d+)/toggle$#', $route, $m) && $method === 'POST') {
    try {
        $tId = (int)$m[1];
        $stmt = $pdo->prepare("SELECT * FROM holidays WHERE id = ?");
        $stmt->execute([$tId]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Holiday not found']);
            exit;
        }

        $newStatus = ($row['status'] === 'active') ? 'inactive' : 'active';
        if ($newStatus === 'active') {
            $u = $pdo->prepare("UPDATE holidays SET status = 'inactive' WHERE id != ?");
            $u->execute([$tId]);
        }

        $u2 = $pdo->prepare("UPDATE holidays SET status = ? WHERE id = ?");
        $u2->execute([$newStatus, $tId]);

        syncActiveHolidayToSettings($pdo);

        echo json_encode(['success' => true, 'status' => $newStatus]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to toggle status: ' . $e->getMessage()]);
        exit;
    }
}

if ($route === '/admin/holidays/seed-presets' && $method === 'POST') {
    try {
        $presets = [
            ['name' => 'Enkutatash (Ethiopian New Year) 🌼', 'discount_percent' => 20, 'category' => 'ethiopian', 'description' => 'Happy Ethiopian New Year! Special discount on all boost services.'],
            ['name' => 'Meskel Celebration ✝️', 'discount_percent' => 15, 'category' => 'ethiopian', 'description' => 'Celebrate Finding of the True Cross with discounted rates.'],
            ['name' => 'Genna (Ethiopian Christmas) 🎄', 'discount_percent' => 25, 'category' => 'ethiopian', 'description' => 'Merry Christmas! Enjoy holiday special pricing.'],
            ['name' => 'Timket (Epiphany) 🕊️', 'discount_percent' => 20, 'category' => 'ethiopian', 'description' => 'Blessed Timket! Boost your social media presence for less.'],
            ['name' => 'Fasika (Ethiopian Easter) 🐣', 'discount_percent' => 20, 'category' => 'ethiopian', 'description' => 'Happy Easter! Special holiday discount unlocked.'],
            ['name' => 'Eid al-Fitr Mubarak 🌙', 'discount_percent' => 20, 'category' => 'international', 'description' => 'Eid Mubarak! Celebrate with exclusive discount rates.'],
            ['name' => 'Eid al-Adha Mubarak 🕌', 'discount_percent' => 20, 'category' => 'international', 'description' => 'Blessed Eid al-Adha! Enjoy discounted promotional packages.'],
            ['name' => 'Black Friday Mega Deal 🛍️', 'discount_percent' => 30, 'category' => 'international', 'description' => 'Biggest discount of the year! Limited time offer.'],
            ['name' => 'New Year Global Sale 🎆', 'discount_percent' => 15, 'category' => 'international', 'description' => 'Welcome the New Year with reduced rates across all services.']
        ];

        $added = 0;
        foreach ($presets as $item) {
            $c = $pdo->prepare("SELECT id FROM holidays WHERE name = ?");
            $c->execute([$item['name']]);
            if (!$c->fetch()) {
                $ins = $pdo->prepare("INSERT INTO holidays (name, discount_percent, status, category, is_recurring, description) VALUES (?, ?, 'inactive', ?, 1, ?)");
                $ins->execute([$item['name'], $item['discount_percent'], $item['category'], $item['description']]);
                $added++;
            }
        }

        echo json_encode(['success' => true, 'added' => $added, 'message' => "Successfully imported $added holiday presets!"]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to seed presets']);
        exit;
    }
}

// Fallback if route match within /admin not found
http_response_code(404);
echo json_encode(['error' => 'Admin endpoint not found', 'route' => $route]);
