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
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('reseller_balance', 'total_deposit', 'min_rate_multiplier', 'rate_multiplier')");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        echo json_encode([
            'success' => true,
            'reseller_balance' => (float)($rows['reseller_balance'] ?? 0),
            'total_deposit' => (float)($rows['total_deposit'] ?? 0),
            'min_rate_multiplier' => (float)($rows['min_rate_multiplier'] ?? 200),
            'rate_multiplier' => (float)($rows['rate_multiplier'] ?? 220)
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load reseller status']);
        exit;
    }
}

if ($route === '/admin/reseller/add-balance' && $method === 'POST') {
    try {
        $amount = (float)($requestData['amount'] ?? 0);
        $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'reseller_balance'");
        $current = (float)($stmt->fetch()['setting_value'] ?? 0);
        $newBal = $current + $amount;

        $u = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('reseller_balance', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $u->execute([(string)$newBal, (string)$newBal]);

        echo json_encode(['success' => true, 'new_balance' => $newBal]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to add reseller balance']);
        exit;
    }
}

if ($route === '/admin/reseller/withdraw-deposit' && $method === 'POST') {
    try {
        $amount = (float)($requestData['amount'] ?? 0);
        $bankName = $requestData['bank_name'] ?? 'Bank';
        $accNum = $requestData['account_number'] ?? '';
        $accName = $requestData['account_name'] ?? '';

        $stmt = $pdo->prepare("INSERT INTO admin_withdrawals (amount, bank_name, account_number, account_name, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt->execute([$amount, $bankName, $accNum, $accName]);
        $wId = $pdo->lastInsertId();

        echo json_encode(['success' => true, 'local_id' => (int)$wId, 'status' => 'pending']);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to submit withdrawal request']);
        exit;
    }
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
