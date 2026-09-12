<?php
/**
 * Admin Routes — Primora Admin Backend (PHP)
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

// Auth Helper
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

// GET /admin/holidays
if ($route === '/admin/holidays' && $method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT * FROM holidays ORDER BY start_date ASC, id DESC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Cast types for JSON compatibility
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

// POST /admin/holidays (Create / Update)
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

// DELETE /admin/holidays/{id}
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

// POST /admin/holidays/{id}/toggle
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

// POST /admin/holidays/seed-presets
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
