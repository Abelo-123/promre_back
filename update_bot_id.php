<?php
/**
 * One-time script to migrate existing rows to the actual bot ID '8958935808'
 */

require_once __DIR__ . '/config.php';

echo "--- Starting Bot ID Data Migration ---\n";

$oldBotId = 'default_bot';
$newBotId = '8958935808';

echo "Target Bot ID: {$newBotId}\n";

$tables = [
    'auth',
    'settings',
    'orders',
    'deposits',
    'transactions',
    'chat_messages',
    'alerts',
    'recommended_services',
    'service_custom',
    'withdrawals'
];

foreach ($tables as $table) {
    try {
        // Disable foreign keys check temporarily if needed, but since bot_id doesn't have FK constraints here, we update directly.
        $stmt = $pdo->prepare("UPDATE {$table} SET bot_id = :new_bot_id WHERE bot_id = :old_bot_id OR bot_id IS NULL");
        $stmt->execute(['new_bot_id' => $newBotId, 'old_bot_id' => $oldBotId]);
        $count = $stmt->rowCount();
        echo "Table '{$table}': Updated {$count} rows.\n";
    } catch (Exception $e) {
        echo "Error updating table '{$table}': " . $e->getMessage() . "\n";
    }
}

echo "--- Data Migration Completed Successfully! ---\n";
