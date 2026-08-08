<?php
/**
 * Orders Management Routes
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../notify.php';
require_once __DIR__ . '/../wallet.php';
require_once __DIR__ . '/services.php'; // For getCachedData and fetchUpstreamServices

// Enforce authentication helper
function getAuthUserIdFromRequest($requestData) {
    $initData = isset($requestData['initData']) ? $requestData['initData'] : '';
    $tgId = getTelegramUserId($initData);
    if (!$tgId) {
        $tgId = isset($requestData['user_id']) ? $requestData['user_id'] : null;
    }
    return $tgId;
}

// ─── ROUTE: /orders/stream (GET - Server-Sent Events) ─────────────────────
if ($route === '/orders/stream') {
    // Disable time limits for this connection (if permitted by server configuration)
    @set_time_limit(60);
    
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-transform');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    
    // Turn off output buffering
    if (ob_get_level()) ob_end_clean();
    
    $tgId = getAuthUserIdFromRequest($requestData);
    if (!$tgId) {
        http_response_code(401);
        exit;
    }
    
    echo "data: " . json_encode(['type' => 'CONNECTED']) . "\n\n";
    flush();
    
    $lastOrderStates = [];
    
    // Initial fetch of user's active orders to baseline their state
    try {
        $stmt = $pdo->prepare("SELECT id, api_order_id, status, start_count, remains FROM orders WHERE user_id = :user_id AND bot_id = :bot_id");
        $stmt->execute(['user_id' => $tgId, 'bot_id' => getCurrentBotId()]);
        $orders = $stmt->fetchAll();
        foreach ($orders as $o) {
            $lastOrderStates[(int)$o['id']] = [
                'status' => $o['status'],
                'start_count' => (int)$o['start_count'],
                'remains' => (int)$o['remains']
            ];
        }
    } catch (Exception $e) {}
    
    // Run SSE loop for ~45 seconds, then instruct frontend to reconnect
    $endTime = time() + 45;
    while (time() < $endTime) {
        // Sleep 4 seconds to be gentle on database connections
        sleep(4);
        
        // Check if client disconnected
        if (connection_aborted()) {
            break;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT id, api_order_id, status, start_count, remains FROM orders WHERE user_id = :user_id AND bot_id = :bot_id");
            $stmt->execute(['user_id' => $tgId, 'bot_id' => getCurrentBotId()]);
            $currentOrders = $stmt->fetchAll();
            
            foreach ($currentOrders as $co) {
                $dbId = (int)$co['id'];
                $apiOrderId = (int)$co['api_order_id'];
                $status = $co['status'];
                $startCount = (int)$co['start_count'];
                $remains = (int)$co['remains'];
                
                if (!isset($lastOrderStates[$dbId])) {
                    // New order added! (usually caught by place endpoint, but send update anyway)
                    $lastOrderStates[$dbId] = [
                        'status' => $status,
                        'start_count' => $startCount,
                        'remains' => $remains
                    ];
                } elseif ($lastOrderStates[$dbId]['status'] !== $status || 
                          $lastOrderStates[$dbId]['start_count'] !== $startCount || 
                          $lastOrderStates[$dbId]['remains'] !== $remains) {
                    
                    // State changed, send SSE message
                    $lastOrderStates[$dbId] = [
                        'status' => $status,
                        'start_count' => $startCount,
                        'remains' => $remains
                    ];
                    
                    $payload = [
                        'type' => 'ORDER_UPDATED',
                        'order' => [
                            'id' => $dbId,
                            'api_order_id' => $apiOrderId,
                            'status' => $status,
                            'start_count' => $startCount,
                            'remains' => $remains
                        ],
                        'refunded' => in_array($status, ['canceled', 'cancelled', 'refunded', 'failed', 'partial'])
                    ];
                    
                    echo "data: " . json_encode($payload) . "\n\n";
                    flush();
                }
            }
        } catch (Exception $e) {
            // Keep going on DB error
        }
        
        // Keep-alive heartbeat
        echo ": keepalive\n\n";
        flush();
    }
    
    // Request reconnect
    echo "data: " . json_encode(['type' => 'RECONNECT', 'reason' => 'timeout']) . "\n\n";
    flush();
    exit;
}

// ─── ROUTE: /orders/place (POST) ──────────────────────────────────────────
if ($route === '/orders/place') {
    try {
        $tgId = getAuthUserIdFromRequest($requestData);
        if (!$tgId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'User not authenticated']);
            exit;
        }
        
        $serviceId = isset($requestData['service']) ? (int)$requestData['service'] : 0;
        $link = isset($requestData['link']) ? $requestData['link'] : '';
        $quantity = isset($requestData['quantity']) ? (int)$requestData['quantity'] : 0;
        $answerNumber = isset($requestData['answer_number']) ? (int)$requestData['answer_number'] : 0;
        $comments = isset($requestData['comments']) ? $requestData['comments'] : '';
        
        if (empty($gopApiKey)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Provider API key missing']);
            exit;
        }

        if (!$serviceId || empty($link) || !$quantity) {
            echo json_encode(['success' => false, 'error' => 'Service ID, Link, and Quantity are required']);
            exit;
        }

        $pdo->beginTransaction();
        
        // 1. Get combined rate multiplier
        $joadminMultiplier = fetchJoadminMultiplier();
        $primoraMultiplier = 1.0;
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'rate_multiplier' ORDER BY (bot_id = :bot_id) DESC LIMIT 1");
        $stmt->execute(['bot_id' => getCurrentBotId()]);
        $row = $stmt->fetch();
        if ($row) $primoraMultiplier = (float)$row['setting_value'] ?: 1.0;

        if ($primoraMultiplier <= 10.0) {
            $rateMultiplier = $joadminMultiplier * $primoraMultiplier;
        } else {
            $rateMultiplier = $primoraMultiplier * ($joadminMultiplier / 55.0);
        }

        // 2. Lock user auth row to prevent race conditions
        $stmt = $pdo->prepare('SELECT * FROM auth WHERE tg_id = :tg_id AND bot_id = :bot_id FOR UPDATE');
        $stmt->execute(['tg_id' => $tgId, 'bot_id' => getCurrentBotId()]);
        $user = $stmt->fetch();
        if (!$user) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'User not found']);
            exit;
        }

        // 3. Fetch specific service from GodOfPanel
        $rawServices = getCachedData('upstream_services', 3600);
        if (!$rawServices) {
            $rawServices = fetchUpstreamServices();
            setCachedData('upstream_services', $rawServices);
        }

        $serviceData = null;
        foreach ($rawServices as $s) {
            if ((int)$s['service'] === $serviceId) {
                $serviceData = $s;
                break;
            }
        }

        if (!$serviceData) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Service not found or unavailable']);
            exit;
        }

        // 4. Fetch custom pricing configurations
        $stmt = $pdo->prepare('SELECT custom_rate, profit_margin, is_enabled FROM service_custom WHERE service_id = :service_id AND bot_id = :bot_id');
        $stmt->execute(['service_id' => $serviceId, 'bot_id' => getCurrentBotId()]);
        $custom = $stmt->fetch();
        
        if ($custom && (int)$custom['is_enabled'] === 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'This service is currently disabled']);
            exit;
        }

        // Calculate Cost
        $unitRateUsd = (float)$serviceData['rate'];
        $baseRateEtb = $unitRateUsd * $rateMultiplier;
        
        $finalRateEtb = $baseRateEtb;
        if ($custom) {
            if ($custom['custom_rate'] !== null) {
                $finalRateEtb = (float)$custom['custom_rate'];
            } elseif ((float)$custom['profit_margin'] > 0) {
                $finalRateEtb = $baseRateEtb * (1 + (float)$custom['profit_margin'] / 100);
            }
        }

        $totalCostEtb = max(0.01, (float)number_format($finalRateEtb * ($quantity / 1000), 2, '.', ''));

        if ((float)$user['balance'] < $totalCostEtb) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'error' => "Insufficient wallet balance. You have " . number_format((float)$user['balance'], 2) . " ETB, but this order costs " . number_format($totalCostEtb, 2) . " ETB."
            ]);
            exit;
        }

        // Calculate wholesale reseller cost (cost to Primora based on Joadmin multiplier)
        $resellerCostEtb = max(0.01, (float)number_format(($unitRateUsd * $joadminMultiplier) * ($quantity / 1000), 2, '.', ''));

        // Fetch reseller_balance from settings using getCurrentBotId()
        $botId = getCurrentBotId();
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'reseller_balance' AND bot_id = :bot_id LIMIT 1");
        $stmt->execute(['bot_id' => $botId]);
        $resellerRow = $stmt->fetch();
        $resellerBalance = $resellerRow ? (float)$resellerRow['setting_value'] : 0.0;

        // Fetch sum of reseller_cost of all currently pending or processing orders for this specific bot
        $stmt = $pdo->prepare("SELECT SUM(reseller_cost) FROM orders WHERE status IN ('pending', 'processing', 'inprogress', 'in_progress') AND bot_id = :bot_id");
        $stmt->execute(['bot_id' => $botId]);
        $pendingCost = (float)($stmt->fetchColumn() ?: 0.0);

        $effectiveResellerBalance = $resellerBalance - $pendingCost;

        if ($effectiveResellerBalance < $resellerCostEtb) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Insufficient reseller balance on admin panel. Please contact admin.']);
            exit;
        }

        // 5. Place order to GodOfPanel API
        $orderParams = [
            'key'      => $gopApiKey,
            'action'   => 'add',
            'service'  => (string)$serviceId,
            'link'     => $link,
            'quantity' => (string)$quantity
        ];
        if (!empty($comments)) $orderParams['comments'] = $comments;
        if ($answerNumber > 0) $orderParams['answer_number'] = (string)$answerNumber;

        $res = curlRequest('POST', 'https://godofpanel.com/api/v2', [], $orderParams, 30);
        $orderData = json_decode($res['body'], true);

        if (!$orderData || isset($orderData['error'])) {
            $pdo->rollBack();
            $providerErr = isset($orderData['error']) ? $orderData['error'] : 'Upstream panel placing order failed';
            
            // Intercept balance-related upstream errors to clarify that the BOT OWNER needs to top up GodOfPanel
            if (stripos($providerErr, 'funds') !== false || stripos($providerErr, 'balance') !== false) {
                $providerErr = "Provider Error: {$providerErr}. (Admin: Please deposit funds to your GodOfPanel account)";
            }
            
            echo json_encode(['success' => false, 'error' => $providerErr]);
            exit;
        }

        $providerOrderId = $orderData['order'];

        // 6. Verify order successfully received by checking status (up to 3 attempts)
        $orderVerified = false;
        $finalOrderStatus = null;
        for ($v = 0; $v < 3; $v++) {
            usleep(500000); // Wait 0.5s
            
            $chkRes = curlRequest('POST', 'https://godofpanel.com/api/v2', [], [
                'key'    => $gopApiKey,
                'action' => 'status',
                'order'  => (string)$providerOrderId
            ], 15);
            
            $chkData = json_decode($chkRes['body'], true);
            if ($chkData && isset($chkData['status'])) {
                $finalOrderStatus = $chkData['status'];
                if (in_array(strtolower($finalOrderStatus), ['pending', 'processing', 'inprogress', 'completed'])) {
                    $orderVerified = true;
                    break;
                }
            }
        }

        $dbOrderStatus = $finalOrderStatus ? strtolower(str_replace(' ', '_', $finalOrderStatus)) : 'pending';

        // 7. Insert Order into DB (including reseller_cost column)
        $stmt = $pdo->prepare("
            INSERT INTO orders 
            (user_id, bot_id, service_id, service_name, reseller_cost, link, target_link, quantity, api_order_id, charge, status, created_at) 
            VALUES (:user_id, :bot_id, :service_id, :service_name, :reseller_cost, :link, :target_link, :quantity, :api_order_id, :charge, :status, NOW())
        ");
        $stmt->execute([
            'user_id'       => $tgId,
            'bot_id'        => getCurrentBotId(),
            'service_id'    => $serviceId,
            'service_name'  => $serviceData['name'],
            'reseller_cost' => $resellerCostEtb,
            'link'          => $link,
            'target_link'   => $link,
            'quantity'      => $quantity,
            'api_order_id'  => $providerOrderId,
            'charge'        => $totalCostEtb,
            'status'        => $dbOrderStatus
        ]);
        $dbId = $pdo->lastInsertId();

        // 8. Deduct user balance & Log ledger
        $newBalance = processTransaction($tgId, 'order', -$totalCostEtb, "Placed Order #{$dbId}", $pdo, 'order', $dbId);

        // 8.5. Deduct reseller balance ONLY if status is already completed
        if (!in_array($dbOrderStatus, ['pending', 'processing', 'inprogress', 'in_progress'])) {
            $newResellerBalance = $resellerBalance - $resellerCostEtb;
            $stmt = $pdo->prepare("UPDATE settings SET setting_value = :val WHERE setting_key = 'reseller_balance' AND bot_id = :bot_id");
            $stmt->execute(['val' => (string)$newResellerBalance, 'bot_id' => $botId]);
        }

        $pdo->commit();

        // 9. Webhook Notification
        try {
            $displayName = !empty($user['username']) ? $user['username'] : (!empty($user['first_name']) ? $user['first_name'] : 'User');
            notifyNewOrder($tgId, $displayName, $serviceData['name'], (string)$dbId, (string)$totalCostEtb, 'GodOfPanel', (string)$user['balance']);
        } catch (Exception $e) {}

        echo json_encode([
            'success'          => true,
            'order_id'         => (string)$dbId,
            'api_order_id'     => (string)$providerOrderId,
            'new_balance'      => $newBalance,
            'verified'         => $orderVerified,
            'provider_status'  => $finalOrderStatus
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'System error: ' . $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /orders/list (POST) ───────────────────────────────────────────
if ($route === '/orders/list') {
    $tgId = getAuthUserIdFromRequest($requestData);
    if (!$tgId) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE user_id = :user_id AND bot_id = :bot_id ORDER BY created_at DESC LIMIT 100');
        $stmt->execute(['user_id' => $tgId, 'bot_id' => getCurrentBotId()]);
        $rows = $stmt->fetchAll();
        
        // Ensure numeric formats
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['service_id'] = (int)$r['service_id'];
            $r['api_order_id'] = (int)$r['api_order_id'];
            $r['quantity'] = (int)$r['quantity'];
            $r['charge'] = (float)$r['charge'];
            $r['start_count'] = (int)$r['start_count'];
            $r['remains'] = (int)$r['remains'];
        }
        
        echo json_encode(['success' => true, 'orders' => $rows]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'orders' => []]);
    }
    exit;
}

// ─── ROUTE: /orders/status (POST Sync check) ──────────────────────────────
if ($route === '/orders/status') {
    $tgId = getAuthUserIdFromRequest($requestData);
    if (!$tgId) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id, api_order_id, charge, quantity, status, reseller_cost, bot_id 
            FROM orders 
            WHERE user_id = :user_id AND bot_id = :bot_id AND status IN ('pending', 'in_progress', 'processing')
        ");
        $stmt->execute(['user_id' => $tgId, 'bot_id' => getCurrentBotId()]);
        $activeOrders = $stmt->fetchAll();

        if (count($activeOrders) === 0) {
            echo json_encode(['success' => true, 'updated' => []]);
            exit;
        }

        $apiOrderIds = [];
        foreach ($activeOrders as $ao) {
            $apiOrderIds[] = $ao['api_order_id'];
        }
        $reqOrderIds = implode(',', $apiOrderIds);

        // Fetch status map from upstream SMM API
        $res = curlRequest('POST', 'https://godofpanel.com/api/v2', [], [
            'key'    => $gopApiKey,
            'action' => 'status',
            'orders' => $reqOrderIds
        ], 20);
        $statusMap = json_decode($res['body'], true);

        if (!$statusMap || isset($statusMap['error'])) {
            echo json_encode(['success' => false, 'error' => 'Sync with upstream failed']);
            exit;
        }

        $updated = [];
        $terminalStatuses = ['canceled', 'cancelled', 'refunded', 'fail', 'failed'];

        foreach ($activeOrders as $order) {
            $providerOrderId = $order['api_order_id'];
            $info = isset($statusMap[$providerOrderId]) ? $statusMap[$providerOrderId] : null;
            if ($info && isset($info['status'])) {
                $newStatus = strtolower(str_replace(' ', '_', $info['status']));

                if ($order['status'] !== $newStatus) {
                    $refundAmt = 0.0;
                    if (in_array($newStatus, $terminalStatuses)) {
                        $refundAmt = (float)$order['charge'];
                    } elseif ($newStatus === 'partial') {
                        $remains = (int)(isset($info['remains']) ? $info['remains'] : 0);
                        $quantity = (int)$order['quantity'];
                        if ($remains > 0 && $quantity > 0) {
                            $refundAmt = ($remains / $quantity) * (float)$order['charge'];
                        }
                    }

                    if ($refundAmt > 0) {
                        $pdo->beginTransaction();
                        try {
                            $refundDescription = ($newStatus === 'partial') ? "Partial Refund for Order #{$order['id']}" : "Refund for Order #{$order['id']}";
                            processTransaction($tgId, 'refund', $refundAmt, $refundDescription, $pdo, 'order_refund', $order['id']);
                            $pdo->commit();
                        } catch (Exception $txErr) {
                            $pdo->rollBack();
                        }
                    }

                    // Deduct reseller balance on transition to success/completed status
                    if (!in_array($newStatus, ['pending', 'processing', 'in_progress', 'inprogress'])) {
                        if (!in_array($newStatus, $terminalStatuses)) {
                            // Successful or partial completion
                            $deduction = 0.0;
                            if ($newStatus === 'partial') {
                                $remains = (int)(isset($info['remains']) ? $info['remains'] : 0);
                                $qty = (int)$order['quantity'];
                                if ($qty > 0 && $remains < $qty) {
                                    $completedRatio = ($qty - $remains) / $qty;
                                    $deduction = (float)$order['reseller_cost'] * $completedRatio;
                                }
                            } else {
                                $deduction = (float)$order['reseller_cost'];
                            }

                            if ($deduction > 0) {
                                try {
                                    // Fetch current reseller_balance safely
                                    $stmtGet = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'reseller_balance' AND bot_id = :bot_id LIMIT 1");
                                    $stmtGet->execute(['bot_id' => $order['bot_id']]);
                                    $currentResellerBal = (float)($stmtGet->fetchColumn() ?: 0.0);

                                    $newResellerBal = $currentResellerBal - $deduction;

                                    // Update reseller_balance setting
                                    $stmtDeduct = $pdo->prepare("UPDATE settings SET setting_value = :val WHERE setting_key = 'reseller_balance' AND bot_id = :bot_id");
                                    $stmtDeduct->execute(['val' => (string)$newResellerBal, 'bot_id' => $order['bot_id']]);
                                } catch (Exception $e) {
                                    error_log("RESELLER BALANCE DEDUCTION FAILED FOR BOT " . $order['bot_id'] . ": " . $e->getMessage());
                                }
                            }
                        }
                    }
                }

                $stmt = $pdo->prepare('UPDATE orders SET status = :status, start_count = :start, remains = :remains WHERE id = :id AND bot_id = :bot_id');
                $stmt->execute([
                    'status' => $newStatus,
                    'start'  => isset($info['start_count']) ? (int)$info['start_count'] : 0,
                    'remains' => isset($info['remains']) ? (int)$info['remains'] : 0,
                    'id'     => $order['id'],
                    'bot_id' => getCurrentBotId()
                ]);

                $updated[] = [
                    'id'     => (int)$order['id'],
                    'status' => $newStatus
                ];
            }
        }

        echo json_encode(['success' => true, 'updated' => $updated]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /orders/refill (POST) ─────────────────────────────────────────
if ($route === '/orders/refill') {
    $tgId = getAuthUserIdFromRequest($requestData);
    if (!$tgId) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }

    try {
        $orderId = isset($requestData['order_id']) ? (int)$requestData['order_id'] : 0;
        
        $stmt = $pdo->prepare('SELECT api_order_id FROM orders WHERE id = :id AND user_id = :user_id AND bot_id = :bot_id');
        $stmt->execute(['id' => $orderId, 'user_id' => $tgId, 'bot_id' => getCurrentBotId()]);
        $order = $stmt->fetch();
        
        if (!$order) {
            echo json_encode(['success' => false, 'message' => 'Order not found']);
            exit;
        }

        $res = curlRequest('POST', 'https://godofpanel.com/api/v2', [], [
            'key'    => $gopApiKey,
            'action' => 'refill',
            'order'  => (string)$order['api_order_id']
        ], 20);
        $refillData = json_decode($res['body'], true);

        if ($refillData && isset($refillData['error'])) {
            echo json_encode(['success' => false, 'message' => $refillData['error']]);
        } else {
            echo json_encode(['success' => true, 'message' => 'Refill requested']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Failed to request refill']);
    }
    exit;
}
