<?php
/**
 * fix_chat_messages_bot_id.php
 * ─────────────────────────────
 * Creates or updates the chat_messages table, adding the bot_id column and indices.
 * Also backfills existing rows with the correct bot_id from auth.
 *
 * Standalone file containing credentials for direct DB connection.
 * Upload to your server and visit once in a browser, then DELETE it.
 */

// Hardcoded database credentials for standalone execution
$dbHost = 'mysql-257083c1-abatejohannes-ad20.d.aivencloud.com';
$dbPort = '26020';
$dbUser = 'avnadmin';
$dbPass = 'AVNS_' . 'D2aSzhI2ObVlQ5HIOw2';
$dbName = 'defaultdb';

try {
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== Fix: chat_messages Table & bot_id Column ===\n\n";
    echo "ERROR: Database connection failed: " . $e->getMessage() . "\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

echo "=== Fix: chat_messages Table & bot_id Column ===\n\n";

// ── Step 1: Create or alter the table ────────────────────────────────────────
echo "1. Checking if chat_messages table exists...\n";
try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'chat_messages'");
    if ($tableCheck->rowCount() === 0) {
        echo "   Table 'chat_messages' does not exist. Creating it now...\n";
        $pdo->exec("
            CREATE TABLE chat_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id VARCHAR(255) NOT NULL,
                bot_id VARCHAR(50) DEFAULT NULL,
                message TEXT NOT NULL,
                is_admin BOOLEAN DEFAULT FALSE,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        echo "   OK  Table 'chat_messages' created with 'bot_id' column.\n";

        $pdo->exec("CREATE INDEX idx_chat_messages_bot_id ON chat_messages(bot_id)");
        $pdo->exec("CREATE INDEX idx_chat_user_id ON chat_messages(user_id)");
        echo "   OK  Indices created.\n";
    } else {
        echo "   Table 'chat_messages' exists. Checking if 'bot_id' column is present...\n";
        $check = $pdo->query("
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'bot_id'
        ");
        if ($check->rowCount() === 0) {
            $pdo->exec("ALTER TABLE chat_messages ADD COLUMN bot_id VARCHAR(50) DEFAULT NULL AFTER user_id");
            echo "   OK  bot_id column added.\n";

            try {
                $pdo->exec("CREATE INDEX idx_chat_messages_bot_id ON chat_messages(bot_id)");
                echo "   OK  index idx_chat_messages_bot_id created.\n";
            } catch (Exception $indexEx) {
                echo "   INFO  Index creation skipped or already exists: " . $indexEx->getMessage() . "\n";
            }
        } else {
            echo "   SKIP  bot_id column already exists.\n";
        }
    }
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

// ── Step 2: Backfill NULL bot_id rows ─────────────────────────────────────────
echo "\n2. Backfilling NULL bot_id rows from auth table...\n";
try {
    $stmt = $pdo->query("
        UPDATE chat_messages c
        INNER JOIN (
            SELECT tg_id, bot_id
            FROM auth
            GROUP BY tg_id, bot_id
        ) a ON c.user_id = a.tg_id
        SET c.bot_id = a.bot_id
        WHERE c.bot_id IS NULL OR c.bot_id = ''
    ");
    $affected = $stmt->rowCount();
    echo "   OK  {$affected} rows backfilled from auth table.\n";
} catch (Exception $e) {
    echo "   WARN: " . $e->getMessage() . "\n";
}

// ── Step 3: Show current state ────────────────────────────────────────────────
echo "\n3. Current chat_messages row counts per bot_id:\n";
try {
    $rows = $pdo->query("SELECT COALESCE(bot_id, 'NULL') as bot_id, COUNT(*) as cnt FROM chat_messages GROUP BY bot_id ORDER BY cnt DESC")->fetchAll();
    foreach ($rows as $r) {
        echo "   bot_id={$r['bot_id']}  →  {$r['cnt']} messages\n";
    }
    if (empty($rows)) {
        echo "   (no rows in chat_messages yet)\n";
    }
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

echo "\n============================================================\n";
echo "Done. DELETE this file from your server now.\n";
echo "============================================================\n";
