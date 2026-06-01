<?php
require_once 'Database.php';
$pdo = Database::connect();

try {
    $pdo->exec("ALTER TABLE media ADD COLUMN status VARCHAR(20) DEFAULT 'Plan to Watch'");
    echo "Column 'status' added successfully.\n";
} catch (Exception $e) {
    echo "Error or column already exists: " . $e->getMessage() . "\n";
}
