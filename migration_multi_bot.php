<?php
/**
 * Database Migration Script: Multi-Bot Partitioning
 * Run this script to add `bot_id` column to all tables and set up constraints.
 */

require_once __DIR__ . '/config.php';

echo "--- Starting Database Migration for Multi-Bot Support ---\n";

// Determine default bot ID
$defaultBotId = 'default_bot';
$singleToken = getEnvVar('BOT_TOKEN');
if ($singleToken && strpos($singleToken, ':') !== false) {
    $parts = explode(':', $singleToken);
    $defaultBotId = $parts[0];
}
echo "Default Bot ID set to: {$defaultBotId}\n";

try {
    // 1. Alter auth table
    echo "Altering auth table...\n";
    // Check if bot_id already exists
    $columns = $pdo->query("SHOW COLUMNS FROM auth")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('bot_id', $columns)) {
        // Drop unique key on tg_id first if it exists
        try {
            $pdo->exec("ALTER TABLE auth DROP INDEX tg_id");
            echo "  Dropped unique key tg_id\n";
        } catch (Exception $e) {
            echo "  No separate tg_id index found or error dropping: " . $e->getMessage() . "\n";
        }
        
        $pdo->exec("ALTER TABLE auth ADD COLUMN bot_id VARCHAR(50) DEFAULT NULL AFTER tg_id");
        $pdo->exec("UPDATE auth SET bot_id = '{$defaultBotId}' WHERE bot_id IS NULL");
        $pdo->exec("ALTER TABLE auth ADD UNIQUE KEY tg_id_bot_id (tg_id, bot_id)");
        echo "  Added bot_id column and unique key (tg_id, bot_id) to auth table\n";
    } else {
        echo "  bot_id column already exists in auth table\n";
    }

    // 2. Alter settings table
    echo "Altering settings...\n";
    $columns = $pdo->query("SHOW COLUMNS FROM settings")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('bot_id', $columns)) {
        try {
            $pdo->exec("ALTER TABLE settings DROP PRIMARY KEY");
            echo "  Dropped settings primary key\n";
        } catch (Exception $e) {
            echo "  Error dropping settings primary key: " . $e->getMessage() . "\n";
        }
        
        $pdo->exec("ALTER TABLE settings ADD COLUMN bot_id VARCHAR(50) NOT NULL AFTER setting_key");
        $pdo->exec("UPDATE settings SET bot_id = '{$defaultBotId}'");
        $pdo->exec("ALTER TABLE settings ADD PRIMARY KEY (setting_key, bot_id)");
        echo "  Added bot_id column and compound primary key to settings table\n";
    } else {
        echo "  bot_id column already exists in settings table\n";
    }

    // 3. Alter other tables
    $tables = [
        'orders' => 'user_id',
        'deposits' => 'user_id',
        'transactions' => 'user_id',
        'chat_messages' => 'user_id',
        'alerts' => 'user_id',
        'recommended_services' => 'service_id',
        'service_custom' => 'service_id',
        'withdrawals' => 'user_id'
    ];

    foreach ($tables as $table => $afterCol) {
        echo "Altering table {$table}...\n";
        // Check if table exists
        try {
            $columns = $pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('bot_id', $columns)) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN bot_id VARCHAR(50) DEFAULT NULL AFTER {$afterCol}");
                $pdo->exec("UPDATE {$table} SET bot_id = '{$defaultBotId}' WHERE bot_id IS NULL");
                echo "  Added bot_id column to {$table}\n";
            } else {
                echo "  bot_id column already exists in {$table}\n";
            }
        } catch (Exception $e) {
            echo "  Table {$table} does not exist or error altering: " . $e->getMessage() . "\n";
        }
    }

    echo "--- Migration Completed Successfully! ---\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
