<?php
/**
 * Standalone Test Script for JAP Average Time Scraper & Authentication
 */

require_once __DIR__ . '/average_times_scraper.php';

header('Content-Type: application/json');

$startTime = microtime(true);
$forceRefresh = isset($_GET['refresh']) || (isset($argv[1]) && $argv[1] === 'refresh');

$times = getAverageTimes($forceRefresh);
$duration = round((microtime(true) - $startTime) * 1000, 2);

$changesFile = __DIR__ . '/cache/recent_changes.json';
$recentChanges = [];
if (file_exists($changesFile)) {
    $cData = json_decode(file_get_contents($changesFile), true);
    if (isset($cData['changes']) && is_array($cData['changes'])) {
        $recentChanges = $cData['changes'];
    }
}

echo json_encode([
    'success' => true,
    'total_services' => count($times),
    'duration_ms' => $duration,
    'service_8651' => isset($times['8651']) ? $times['8651'] : 'Not found in scraped map',
    'recently_updated_count' => count($recentChanges),
    'recently_updated_services' => $recentChanges,
    'sample_data' => array_slice($times, 0, 10, true),
    'data' => $times
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

