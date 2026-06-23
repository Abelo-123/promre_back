<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');

try {
    $stmt = $pdo->query('SHOW CREATE TABLE auth');
    $row = $stmt->fetch();
    echo "--- TABLE STRUCTURE FOR auth ---\n\n";
    echo $row['Create Table'] . "\n\n";
    
    $stmt = $pdo->query('SHOW INDEX FROM auth');
    $indexes = $stmt->fetchAll();
    echo "--- INDEXES ---\n\n";
    foreach ($indexes as $index) {
        echo "Table: " . $index['Table'] . " | Unique: " . ($index['Non_unique'] ? 'No' : 'Yes') . " | Key Name: " . $index['Key_name'] . " | Column: " . $index['Column_name'] . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
