<?php
/**
 * install.php — one-time installer.
 * Creates the schema, loads seed data and (optionally) resets the admin password.
 *
 *   php ingest/install.php                 # schema + seed
 *   php ingest/install.php --no-seed       # schema only
 *   php ingest/install.php --admin-pass=SECRET
 *
 * Reads DB settings from config/config.php.
 */
use App\Core\Config;
use App\Core\Database;

require dirname(__DIR__) . '/app/bootstrap.php';

$opts = getopt('', ['no-seed', 'admin-pass::']);
$db   = Database::app();
$dir  = dirname(__DIR__) . '/database';

echo "→ Applying schema...\n";
run_sql_file($db, "{$dir}/schema.sql");

if (!isset($opts['no-seed'])) {
    echo "→ Loading seed data...\n";
    run_sql_file($db, "{$dir}/seed.sql");
}

if (!empty($opts['admin-pass'])) {
    $hash = password_hash($opts['admin-pass'], PASSWORD_DEFAULT);
    $db->update('users', ['password_hash' => $hash], "username = 'admin'");
    echo "→ Admin password updated.\n";
}

echo "✔ Done. Log in at your base URL with admin / admin123 (unless you changed it).\n";

/** Split a .sql file on semicolons at line ends and execute each statement. */
function run_sql_file(Database $db, string $file): void
{
    if (!is_file($file)) {
        fwrite(STDERR, "Missing SQL file: {$file}\n");
        exit(1);
    }
    $sql = file_get_contents($file);
    // Strip full-line comments to simplify splitting.
    $lines = array_filter(explode("\n", $sql), fn($l) => !preg_match('/^\s*--/', $l));
    $sql = implode("\n", $lines);
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
        $stmt = rtrim($stmt, ";\n\r\t ");
        if ($stmt === '') continue;
        try {
            $db->pdo()->exec($stmt);
        } catch (\Throwable $e) {
            fwrite(STDERR, "SQL error: " . $e->getMessage() . "\n  in: " . substr($stmt, 0, 80) . "...\n");
        }
    }
}
