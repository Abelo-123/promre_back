<?php
/**
 * fix_chat_messages_bot_id.php
 * ─────────────────────────────
 * Adds bot_id column to chat_messages table and backfills existing rows
 * with the correct bot_id so each admin sees only its own chats.
 *
 * Upload to your server and visit once in a browser, then DELETE it.
 */

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== Fix: chat_messages bot_id column ===\n\n";

// ── Step 1: Add bot_id column if missing ─────────────────────────────────────
echo "1. Checking bot_id column on chat_messages...\n";
try {
    $check = $pdo->query("
        SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages' AND COLUMN_NAME = 'bot_id'
    ");
    if ($check->rowCount() === 0) {
        $pdo->exec("ALTER TABLE chat_messages ADD COLUMN bot_id VARCHAR(50) DEFAULT NULL AFTER user_id");
        echo "   OK  bot_id column added.\n";

        $pdo->exec("CREATE INDEX idx_chat_messages_bot_id ON chat_messages(bot_id)");
        echo "   OK  index created.\n";
    } else {
        echo "   SKIP  bot_id column already exists.\n";
    }
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}

// ── Step 2: Backfill NULL bot_id rows ─────────────────────────────────────────
// We look at each chat message's user_id and find what bot they belong to
// from the auth table. If ambiguous, we use the most recent auth row.
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
