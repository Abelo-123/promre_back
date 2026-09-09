<?php
/**
 * Standalone Test Script for JAP Average Time Scraper
 */

require_once __DIR__ . '/average_times_scraper.php';

header('Content-Type: application/json');

$startTime = microtime(true);
$forceRefresh = isset($_GET['refresh']) || (isset($argv[1]) && $argv[1] === 'refresh');

$times = getAverageTimes($forceRefresh);
$duration = round((microtime(true) - $startTime) * 1000, 2);

echo json_encode([
    'success' => true,
    'total_services' => count($times),
    'duration_ms' => $duration,
    'sample_data' => array_slice($times, 0, 10, true),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
