<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');

try {
    $stmt = $pdo->query("
        SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE REFERENCED_TABLE_NAME = 'auth'
    ");
    $fks = $stmt->fetchAll();
    echo "--- FOREIGN KEYS REFERENCING auth ---\n\n";
    if (empty($fks)) {
        echo "No foreign keys found referencing 'auth'.\n";
    } else {
        foreach ($fks as $fk) {
            echo "Table: " . $fk['TABLE_NAME'] . " | Column: " . $fk['COLUMN_NAME'] . " | Constraint: " . $fk['CONSTRAINT_NAME'] . " | References: " . $fk['REFERENCED_TABLE_NAME'] . "(" . $fk['REFERENCED_COLUMN_NAME'] . ")\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
