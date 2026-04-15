<?php
#!/usr/bin/env php
<?php

/**
 * Migration runner for HomeCare.
 * 
 * Runs pending .sql migrations from migrations/ directory.
 * Records applied in hc_migrations table.
 * 
 * Usage: php bin/migrate [--dry-run]
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../includes/init.php'; // Sets up $c = mysqli

use mysqli;

global $c;

$dryRun = in_array('--dry-run', $argv, true);

try {
    // Create tracking table if missing
    $c->query("
        CREATE TABLE IF NOT EXISTS hc_migrations (
            name VARCHAR(64) PRIMARY KEY,
            applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");

    // Get applied
    $result = $c->query("SELECT name FROM hc_migrations ORDER BY name");
    $applied = [];
    while ($row = $result->fetch_assoc()) {
        $applied[] = $row['name'];
    }

    // Get migration files sorted
    $files = glob(__DIR__ . '/../migrations/*.sql');
    sort($files, SORT_NATURAL);

    $pending = [];
    foreach ($files as $file) {
        $name = basename($file);
        if (!in_array($name, $applied, true)) {
            $pending[] = $name;
        }
    }

    if (empty($pending)) {
        echo "No pending migrations.\n";
        exit(0);
    }

    if ($dryRun) {
        echo "Pending migrations (" . count($pending) . "):\n";
        foreach ($pending as $name) {
            echo "  - $name\n";
        }
        exit(0);
    }

    // Apply
    $appliedCount = 0;
    foreach ($pending as $name) {
        $file = __DIR__ . '/../migrations/' . $name;
        $sql = file_get_contents($file);
        
        // Execute
        if (!$c->multi_query($sql)) {
            throw new RuntimeException("Failed to apply $name: " . $c->error);
        }
        
        // Wait for results if multi
        do {
            $c->next_result();
        } while ($c->more_results());
        
        // Record
        $stmt = $c->prepare("INSERT INTO hc_migrations (name) VALUES (?)");
        $stmt->bind_param("s", $name);
        if (!$stmt->execute()) {
            throw new RuntimeException("Failed to record $name: " . $c->error);
        }
        $stmt->close();
        
        echo "Applied migration: $name\n";
        $appliedCount++;
    }
    
    echo "Successfully applied $appliedCount migrations.\n";
    exit(0);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
