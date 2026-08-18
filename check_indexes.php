<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');

try {
    $stmt = $pdo->query("SELECT tg_id, bot_id FROM auth WHERE bot_id IS NULL OR bot_id = ''");
    $rows = $stmt->fetchAll();
    echo "--- ROWS WITH NULL OR EMPTY bot_id ---\n\n";
    if (empty($rows)) {
        echo "No rows found with NULL or empty bot_id.\n";
    } else {
        foreach ($rows as $row) {
            echo "tg_id: " . $row['tg_id'] . " | bot_id: " . (is_null($row['bot_id']) ? 'NULL' : '"' . $row['bot_id'] . '"') . "\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
