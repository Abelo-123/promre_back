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
    
    // Check if bot_id already exists to add the column
    $columns = $pdo->query("SHOW COLUMNS FROM auth")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('bot_id', $columns)) {
        $pdo->exec("ALTER TABLE auth ADD COLUMN bot_id VARCHAR(50) DEFAULT NULL AFTER tg_id");
        $pdo->exec("UPDATE auth SET bot_id = '{$defaultBotId}' WHERE bot_id IS NULL");
        echo "  Added bot_id column to auth table\n";
    } else {
        echo "  bot_id column already exists in auth table\n";
    }

    if (!in_array('photo_url', $columns)) {
        $pdo->exec("ALTER TABLE auth ADD COLUMN photo_url TEXT DEFAULT NULL AFTER username");
        echo "  Added photo_url column to auth table\n";
    }

    // Drop the old singular PRIMARY KEY (tg_id) and make (tg_id, bot_id) the compound PRIMARY KEY
    // Drop unique key tg_id_bot_id if it exists to avoid redundancy (since primary key will cover it)
    try {
        $pdo->exec("ALTER TABLE auth DROP INDEX tg_id_bot_id");
        echo "  Dropped redundant unique index tg_id_bot_id\n";
    } catch (Exception $e) {
        echo "  Note: tg_id_bot_id index already dropped or not found.\n";
    }

    // Drop the foreign key constraints that reference auth(tg_id)
    try {
        $pdo->exec("ALTER TABLE deposits DROP FOREIGN KEY deposits_ibfk_1");
        echo "  Dropped foreign key deposits_ibfk_1\n";
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE orders DROP FOREIGN KEY orders_ibfk_1");
        echo "  Dropped foreign key orders_ibfk_1\n";
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE user_alerts DROP FOREIGN KEY user_alerts_ibfk_1");
        echo "  Dropped foreign key user_alerts_ibfk_1\n";
    } catch (Exception $e) {}

    // Ensure there are no NULL values in bot_id
    try {
        $pdo->exec("UPDATE auth SET bot_id = '{$defaultBotId}' WHERE bot_id IS NULL OR bot_id = ''");
        echo "  Updated NULL bot_ids to default bot ID\n";
    } catch (Exception $e) {
        echo "  Error updating NULL bot_ids: " . $e->getMessage() . "\n";
    }

    // Make bot_id column NOT NULL
    try {
        $pdo->exec("ALTER TABLE auth MODIFY COLUMN bot_id VARCHAR(50) NOT NULL");
        echo "  Set bot_id column to NOT NULL\n";
    } catch (Exception $e) {
        echo "  Error setting bot_id column to NOT NULL: " . $e->getMessage() . "\n";
    }

    // Drop the primary key constraint on tg_id and make (tg_id, bot_id) the primary key
    try {
        $pdo->exec("ALTER TABLE auth DROP PRIMARY KEY, ADD PRIMARY KEY (tg_id, bot_id)");
        echo "  Successfully updated PRIMARY KEY to compound (tg_id, bot_id)\n";
    } catch (Exception $e) {
        echo "  Error updating PRIMARY KEY to compound: " . $e->getMessage() . "\n";
    }

    // 2. Alter settings table
    echo "Altering settings...\n";
    $columns = $pdo->query("SHOW COLUMNS FROM settings")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('bot_id', $columns)) {
        $pdo->exec("ALTER TABLE settings ADD COLUMN bot_id VARCHAR(50) NOT NULL AFTER setting_key");
        $pdo->exec("UPDATE settings SET bot_id = '{$defaultBotId}'");
        try {
            $pdo->exec("ALTER TABLE settings DROP PRIMARY KEY, ADD PRIMARY KEY (setting_key, bot_id)");
            echo "  Added bot_id column and compound primary key to settings table\n";
        } catch (Exception $e) {
            echo "  Error updating primary key: " . $e->getMessage() . "\n";
        }
    } else {
        // bot_id exists, check if primary key is compound
        try {
            $pdo->exec("ALTER TABLE settings DROP PRIMARY KEY, ADD PRIMARY KEY (setting_key, bot_id)");
            echo "  Ensured compound primary key on settings table\n";
        } catch (Exception $e) {
            echo "  Compound primary key already set or settings modified: " . $e->getMessage() . "\n";
        }
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
