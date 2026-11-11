<?php
/**
 * Idempotent schema fix: add 'draft' to documents.status / ph_documents.status.
 *
 * The draft flow (teacher_tasks / teacher_dashboard) inserts status='draft'.
 * A database seeded from the pre-draft dump only has the old enum values, so
 * under STRICT_TRANS_TABLES the INSERT fails with
 *   SQLSTATE[01000] 1265 Data truncated for column 'status'
 * and the upload returns HTTP 500. XAMPP/MariaDB silently truncates, which is
 * why local never showed the error.
 *
 * Runs at container boot (docker/entrypoint.sh) and from the CLI. Safe to run
 * repeatedly: it only ALTERs tables whose status enum lacks 'draft'.
 *
 * Manual use against Aiven (values from the Render dashboard):
 *   DB_HOST=... DB_PORT=... DB_USER=... DB_PASS=... DB_NAME=ched_compliance \
 *   DB_SSL_CA=certs/ca.pem php scripts/migrate_draft_status.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$env = static fn(string $key): string => trim((string) (getenv($key) ?: ''));
$host = $env('DB_HOST');
$user = $env('DB_USER');
$pass = (string) (getenv('DB_PASS') ?: '');
if ($host === '' || $user === '' || $pass === '') {
    fwrite(STDERR, "[migrate] DB_HOST / DB_USER / DB_PASS not set — skipping.\n");
    exit(1);
}

$options = [
    PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT          => 10, // never stall container boot on a DB hiccup
];
$ca = $env('DB_SSL_CA') ?: dirname(__DIR__) . '/certs/ca.pem';
if (is_readable($ca)) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
}

$dsn = 'mysql:host=' . $host
    . ';port=' . ($env('DB_PORT') ?: '3306')
    . ';dbname=' . ($env('DB_NAME') ?: 'ched_compliance')
    . ';charset=utf8mb4';

// Column definitions must match the working local schema (draft first, draft default).
$targets = [
    'documents'    => "ENUM('draft','pending_program_head','pending_admin','approved_admin','rejected_program_head','rejected_admin') NOT NULL DEFAULT 'draft'",
    'ph_documents' => "ENUM('draft','pending_program_head','approved_program_head','rejected_program_head','pending_admin','approved_admin','rejected_admin') NOT NULL DEFAULT 'draft'",
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    foreach ($targets as $table => $definition) {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . $table . "` LIKE 'status'");
        $stmt->execute();
        $current = (string) ($stmt->fetch()['Type'] ?? '');
        if (strpos($current, "'draft'") !== false) {
            echo "[migrate] $table.status already supports 'draft'\n";
            continue;
        }
        $pdo->exec("ALTER TABLE `$table` MODIFY `status` $definition");
        echo "[migrate] $table.status updated (added 'draft')\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[migrate] failed: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
