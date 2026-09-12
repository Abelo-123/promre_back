<?php
/**
 * Services and Categories Route Handler
 */

require_once __DIR__ . '/../config.php';

// Define platforms keywords
$platformKeywords = [
    'instagram' => ['instagram', 'ig '],
    'tiktok'    => ['tiktok', 'tik tok'],
    'youtube'   => ['youtube', 'yt '],
    'facebook'  => ['facebook', 'fb '],
    'twitter'   => ['twitter', 'x.com', 'tweet'],
    'telegram'  => ['telegram', 'tg '],
];

function determinePlatform($category) {
    global $platformKeywords;
    if (empty($category)) return 'other';
    $lower = strtolower($category);
    foreach ($platformKeywords as $platform => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($lower, $kw) !== false) {
                return $platform;
            }
        }
    }
    return 'other';
}

// Local Cache Helpers
$cacheDir = __DIR__ . '/../cache';
if (!file_exists($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

function getCachedData($key, $ttl = 3600) {
    global $cacheDir;
    $cacheFile = "{$cacheDir}/cache_{$key}.json";
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if ($data && isset($data['time']) && (time() - $data['time']) < $ttl) {
            return $data['payload'];
        }
    }
    return null;
}

function setCachedData($key, $payload) {
    global $cacheDir;
    $cacheFile = "{$cacheDir}/cache_{$key}.json";
    $data = [
        'time' => time(),
        'payload' => $payload
    ];
    file_put_contents($cacheFile, json_encode($data));
}

// Fetch joadmin (paxadmin_bot) multiplier via HTTP API since databases are separate
function fetchJoadminMultiplier() {
    return getJoadminMultiplier();
}

// Upstream GodOfPanel fetch helper with 3 retries
function fetchUpstreamServices() {
    global $gopApiKey, $smmProviderUrl;
    if (empty($gopApiKey)) {
        throw new Exception('GODOFPANEL_API_KEY not configured in backend environment');
    }
    
    $lastError = 'Unknown error';
    for ($i = 0; $i < 3; $i++) {
        $res = curlRequest('POST', $smmProviderUrl, [], [
            'key' => $gopApiKey,
            'action' => 'services'
        ], 30);
        
        if ($res['code'] === 200 && !empty($res['body'])) {
            $data = json_decode($res['body'], true);
            if (is_array($data)) {
                return $data;
            } elseif (is_array($data) && isset($data['error'])) {
                $lastError = $data['error'];
            }
        } else {
            $lastError = !empty($res['error']) ? $res['error'] : "HTTP Code {$res['code']}";
        }
        usleep(1500000); // Sleep 1.5s between retries
    }
    
    throw new Exception($lastError);
}

// ─── ROUTE: /average-times (GET / POST) ───────────────────────────────────
if ($route === '/average-times') {
    try {
        require_once __DIR__ . '/../average_times_scraper.php';
        $forceRefresh = (isset($requestData['action']) && $requestData['action'] === 'refresh') ||
                        (isset($requestData['refresh']) && $requestData['refresh'] === '1');
        $times = getAverageTimes($forceRefresh);
        
        $cacheFile = __DIR__ . '/../cache/average_times.json';
        $lastMtime = file_exists($cacheFile) ? filemtime($cacheFile) : time();
        $cacheAge = max(0, time() - $lastMtime);

        $changesFile = __DIR__ . '/../cache/recent_changes.json';
        $recentChanges = [];
        $recentCount = 0;
        if (file_exists($changesFile)) {
            $cData = json_decode(file_get_contents($changesFile), true);
            $recentChanges = isset($cData['changes']) && is_array($cData['changes']) ? $cData['changes'] : [];
            $recentCount = isset($cData['total_changed']) ? (int)$cData['total_changed'] : count($recentChanges);
        }

        echo json_encode([
            'success' => true,
            'mode' => $forceRefresh ? 'SWR_REVALIDATED_SERVE' : 'SWR_INSTANT_SERVE',
            'request_timestamp' => date('c'),
            'last_cache_update' => date('c', $lastMtime),
            'cache_age_seconds' => $cacheAge,
            'service_8651' => isset($times['8651']) ? $times['8651'] : (isset($times[8651]) ? $times[8651] : 'Not Found'),
            'total_services' => count($times),
            'recently_updated_count' => $recentCount,
            'recently_updated_services' => $recentChanges,
            'data' => $times
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch average times: ' . $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /godofpanel-average-times (GET / POST) ───────────────────────────
if ($route === '/godofpanel-average-times' || $route === '/gop-average-times') {
    try {
        require_once __DIR__ . '/../godofpanel_scraper.php';
        $forceRefresh = (isset($requestData['action']) && $requestData['action'] === 'refresh') ||
                        (isset($requestData['refresh']) && $requestData['refresh'] === '1');
        $payload = getGodofpanelAverageTimes($forceRefresh);
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch GodOfPanel average times: ' . $e->getMessage()]);
    }
    exit;
}



// ─── ROUTE: /categories (GET) ──────────────────────────────────────────────
if ($route === '/categories') {
    try {
        $forceRefresh = isset($requestData['refresh']) && $requestData['refresh'] === '1';
        $platform = isset($requestData['platform']) ? $requestData['platform'] : null;
        
        // Check local database for disabled service IDs
        $disabledServiceIds = [];
        try {
            $stmt = $pdo->query('SELECT service_id FROM service_custom WHERE is_enabled = 0');
            $disabledRows = $stmt->fetchAll();
            foreach ($disabledRows as $row) {
                $disabledServiceIds[] = (int)$row['service_id'];
            }
        } catch (Exception $e) {}

        // Fetch from cache or upstream API
        $rawServices = null;
        if (!$forceRefresh) {
            $rawServices = getCachedData('upstream_services', 3600);
        }
        
        if (!$rawServices) {
            try {
                $rawServices = fetchUpstreamServices();
                setCachedData('upstream_services', $rawServices);
            } catch (Exception $e) {
                // cURL blocked/failed - fall back to stale cache with 1 year TTL
                $rawServices = getCachedData('upstream_services', 86400 * 365);
                if (!$rawServices) {
                    throw $e;
                }
            }
        }
        
        // Extract Categories from enabled services
        $categoriesSet = [];
        foreach ($rawServices as $svc) {
            $svcId = (int)$svc['service'];
            if (in_array($svcId, $disabledServiceIds)) {
                continue;
            }
            if (!empty($svc['category'])) {
                $categoriesSet[] = $svc['category'];
            }
        }
        
        $allCategories = array_values(array_unique($categoriesSet));
        
        // Filter by platform keywords if requested
        $result = $allCategories;
        if (!empty($platform) && $platform !== 'top') {
            if ($platform === 'other') {
                // Filter out major platform categories
                $allMajorKeywords = [];
                foreach ($platformKeywords as $kwList) {
                    $allMajorKeywords = array_merge($allMajorKeywords, $kwList);
                }
                
                $result = array_filter($allCategories, function($cat) use ($allMajorKeywords) {
                    $lowerCat = strtolower($cat);
                    foreach ($allMajorKeywords as $kw) {
                        if (strpos($lowerCat, $kw) !== false) {
                            return false;
                        }
                    }
                    return true;
                });
            } else {
                $keywords = isset($platformKeywords[$platform]) ? $platformKeywords[$platform] : null;
                if ($keywords) {
                    $result = array_filter($allCategories, function($cat) use ($keywords) {
                        $lowerCat = strtolower($cat);
                        foreach ($keywords as $kw) {
                            if (strpos($lowerCat, $kw) !== false) {
                                return true;
                            }
                        }
                        return false;
                    });
                }
            }
            $result = array_values($result);
        }
        
        echo json_encode([
            'success' => true,
            'categories' => $result,
            'total' => count($result),
            'cached' => !$forceRefresh
        ]);
        
    } catch (Exception $e) {
        // Fallback to stale cache if available (up to 1 year)
        $staleCache = getCachedData('upstream_services', 86400 * 365);
        if ($staleCache) {
            $categoriesSet = [];
            foreach ($staleCache as $svc) {
                if (!empty($svc['category'])) {
                    $categoriesSet[] = $svc['category'];
                }
            }
            $result = array_values(array_unique($categoriesSet));
            echo json_encode([
                'success' => true,
                'categories' => $result,
                'total' => count($result),
                'cached' => true,
                'stale' => true
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch categories: ' . $e->getMessage()]);
        }
    }
    exit;
}

// ─── ROUTE: /services (GET) ────────────────────────────────────────────────
if ($route === '/services') {
    try {
        $forceRefresh = isset($requestData['refresh']) && $requestData['refresh'] === '1';
        $reqCategory = isset($requestData['category']) ? $requestData['category'] : null;
        
        $reqIds = null;
        if (!empty($requestData['ids'])) {
            $reqIds = array_map('intval', explode(',', $requestData['ids']));
        }
        
        // 1. Get database configs & reseller rate multiplier
        $resellerMultiplier = 200.0;
        try {
            $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'min_rate_multiplier' LIMIT 1");
            $row = $stmt->fetch();
            if ($row && !empty($row['setting_value'])) $resellerMultiplier = (float)$row['setting_value'];
        } catch (Exception $e) {}

        $rateMultiplier = $resellerMultiplier;

        // Custom pricing map
        $customPricingMap = [];
        try {
            $stmt = $pdo->prepare('SELECT service_id, custom_rate, profit_margin, is_enabled FROM service_custom');
            $stmt->execute();
            $customRows = $stmt->fetchAll();
            foreach ($customRows as $row) {
                $customPricingMap[(int)$row['service_id']] = [
                    'custom_rate' => $row['custom_rate'] !== null ? (float)$row['custom_rate'] : null,
                    'profit_margin' => (float)$row['profit_margin'],
                    'is_enabled' => (int)$row['is_enabled']
                ];
            }
        } catch (Exception $e) {}

        // Service delivery duration adjustments map (from GodOfPanel scraper cache)
        $adjustmentsMap = [];
        try {
            require_once __DIR__ . '/../godofpanel_scraper.php';
            $gopRes = getGodofpanelAverageTimes($forceRefresh);
            $adjustmentsMap = isset($gopRes['data']) && is_array($gopRes['data']) ? $gopRes['data'] : $gopRes;
        } catch (Exception $e) {}


        // Fetch Raw Services
        $rawServices = null;
        if (!$forceRefresh) {
            $rawServices = getCachedData('upstream_services', 3600);
        }
        if (!$rawServices) {
            try {
                $rawServices = fetchUpstreamServices();
                setCachedData('upstream_services', $rawServices);
            } catch (Exception $ex) {
                // cURL blocked/failed - fall back to stale cache with 1 year TTL
                $rawServices = getCachedData('upstream_services', 86400 * 365);
                if (!$rawServices) {
                    throw $ex;
                }
            }
        }

        // 2. Centralized formatter
        $processed = [];
        foreach ($rawServices as $svc) {
            $svcId = (int)$svc['service'];
            $custom = isset($customPricingMap[$svcId]) ? $customPricingMap[$svcId] : null;
            
            $isEnabled = $custom ? ($custom['is_enabled'] !== 0) : true;
            if (!$isEnabled) {
                continue;
            }
            
            $numericRate = (float)$svc['rate'];
            $baseRate = $numericRate * $rateMultiplier;
            
            $finalRate = $baseRate;
            if ($custom) {
                if ($custom['custom_rate'] !== null) {
                    $finalRate = $custom['custom_rate'];
                } elseif ($custom['profit_margin'] > 0) {
                    $finalRate = $baseRate * (1 + $custom['profit_margin'] / 100);
                }
            }
            
            $processed[] = [
                'service'       => $svcId,
                'name'          => $svc['name'],
                'type'          => $svc['type'],
                'category'      => $svc['category'],
                'rate'          => number_format($finalRate, 2, '.', ''),
                'original_rate' => $numericRate,
                'min'           => (int)$svc['min'],
                'max'           => (int)$svc['max'],
                'refill'        => ($svc['refill'] === true || $svc['refill'] == 1 || $svc['refill'] === '1'),
                'cancel'        => ($svc['cancel'] === true || $svc['cancel'] == 1 || $svc['cancel'] === '1'),
                'average_time'  => isset($adjustmentsMap[$svcId]) ? $adjustmentsMap[$svcId] : (isset($adjustmentsMap[(string)$svcId]) ? $adjustmentsMap[(string)$svcId] : (isset($svc['average_time']) ? $svc['average_time'] : 'Not specified')),
                'platform_id'   => determinePlatform($svc['category'])
            ];
        }

        // 3. Filter final response
        if ($reqCategory === 'Top Services') {
            $topServicesIdsStr = '';
            try {
                $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'top_services_ids' LIMIT 1");
                $row = $stmt->fetch();
                if ($row) $topServicesIdsStr = $row['setting_value'] ?: '';
            } catch (Exception $e) {}
            
            $recommendedIds = array_filter(array_map('intval', explode(',', $topServicesIdsStr)));
            $processed = array_values(array_filter($processed, function($s) use ($recommendedIds) {
                return in_array($s['service'], $recommendedIds);
            }));
        } elseif (!empty($reqCategory)) {
            $processed = array_values(array_filter($processed, function($s) use ($reqCategory) {
                return $s['category'] === $reqCategory;
            }));
        }

        if ($reqIds) {
            $processed = array_values(array_filter($processed, function($s) use ($reqIds) {
                return in_array($s['service'], $reqIds);
            }));
        }

        echo json_encode($processed);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch services: ' . $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /services/top (GET) ─────────────────────────────────────────────
if ($route === '/services/top') {
    try {
        // Get recommended service IDs
        $stmt = $pdo->query('SELECT service_id FROM recommended_services');
        $recRows = $stmt->fetchAll();
        $recommendedIds = [];
        foreach ($recRows as $r) {
            $recommendedIds[] = (int)$r['service_id'];
        }
        
        if (count($recommendedIds) === 0) {
            echo json_encode(['success' => true, 'services' => [], 'message' => 'No recommended services configured']);
            exit;
        }

        // Fetch raw services
        $rawServices = getCachedData('upstream_services', 3600);
        if (!$rawServices) {
            $rawServices = fetchUpstreamServices();
            setCachedData('upstream_services', $rawServices);
        }

        // Get multiplier
        $resellerMultiplier = 200.0;
        try {
            $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'min_rate_multiplier' LIMIT 1");
            $row = $stmt->fetch();
            if ($row && !empty($row['setting_value'])) $resellerMultiplier = (float)$row['setting_value'];
        } catch (Exception $e) {}

        $rateMultiplier = $resellerMultiplier;

        // Filter and transform
        $topServices = [];
        foreach ($rawServices as $svc) {
            $svcId = (int)$svc['service'];
            if (in_array($svcId, $recommendedIds)) {
                $numericRate = (float)$svc['rate'];
                $topServices[] = [
                    'service'       => $svcId,
                    'name'          => $svc['name'],
                    'type'          => $svc['type'],
                    'category'      => $svc['category'],
                    'rate'          => number_format($numericRate * $rateMultiplier, 2, '.', ''),
                    'original_rate' => $numericRate,
                    'min'           => (int)$svc['min'],
                    'max'           => (int)$svc['max'],
                    'refill'        => ($svc['refill'] === true || $svc['refill'] == 1 || $svc['refill'] === '1'),
                    'cancel'        => ($svc['cancel'] === true || $svc['cancel'] == 1 || $svc['cancel'] === '1'),
                ];
            }
        }

        echo json_encode([
            'success'  => true,
            'services' => $topServices,
            'count'    => count($topServices)
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ─── ROUTE: /services/recommended (GET / POST admin panel configs) ─────────
if ($route === '/services/recommended') {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'POST') {
        try {
            $serviceId = isset($requestData['service_id']) ? (int)$requestData['service_id'] : 0;
            $action = isset($requestData['action']) ? $requestData['action'] : '';
            
            if (!$serviceId) {
                http_response_code(400);
                echo json_encode(['error' => 'service_id required']);
                exit;
            }

            if ($action === 'remove') {
                $stmt = $pdo->prepare('DELETE FROM recommended_services WHERE service_id = :service_id');
                $stmt->execute(['service_id' => $serviceId]);
                echo json_encode(['success' => true, 'message' => "Service {$serviceId} removed from recommended"]);
            } else {
                $stmt = $pdo->prepare('INSERT IGNORE INTO recommended_services (service_id) VALUES (:service_id)');
                $stmt->execute(['service_id' => $serviceId]);
                echo json_encode(['success' => true, 'message' => "Service {$serviceId} added to recommended"]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
    } else { // GET
        try {
            $stmt = $pdo->query('SELECT * FROM recommended_services ORDER BY id DESC');
            $rows = $stmt->fetchAll();
            echo json_encode(['success' => true, 'recommended' => $rows]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
        }
    }
    exit;
}
