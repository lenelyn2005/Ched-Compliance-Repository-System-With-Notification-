<?php
/**
 * CHED Compliance - one-time Super Admin bootstrap (CLI).
 *
 * The Super Admin account is never created over the web: no registration page,
 * no hardcoded credentials, no default password. This script must be run by
 * someone with shell access to the server:
 *
 *   php scripts/create_super_admin.php
 *
 * It refuses to run twice - after the first Super Admin exists, further Super
 * Admin accounts can only be made this way (the dashboard can only ever write
 * role='admin').
 *
 * Run: php scripts/create_super_admin.php
 * Exit: 0 = created, 1 = refused/failed.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}
define('CHED_APP', true);
require __DIR__ . '/../config.php';

const MIN_PASSWORD = 12; // highest-privilege account: stricter than the app-wide 8

// ponytail: password echoes on Windows consoles (no stty); upgrade to a
// raw-terminal hidden read (sapi_windows_vt100_support) if shoulder-surfing matters.
function readSecret(string $label): string
{
    fwrite(STDOUT, $label);
    $hidden = false;
    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')) {
        @shell_exec('stty -echo');
        $hidden = true;
    }
    $line = fgets(STDIN);
    if ($hidden) {
        @shell_exec('stty echo');
    }
    fwrite(STDOUT, PHP_EOL);
    return rtrim((string) $line, "\r\n");
}

function promptLine(string $label): string
{
    fwrite(STDOUT, $label);
    return rtrim((string) fgets(STDIN), "\r\n");
}

function fail(string $msg): never
{
    fwrite(STDERR, "[FAIL] {$msg}\n");
    exit(1);
}

try {
    $existing = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn();
} catch (Throwable $e) {
    // Most likely the role enum has not been widened yet.
    error_log('[CHED Compliance] super_admin bootstrap probe failed: ' . $e->getMessage());
    fail('users.role has no super_admin value yet - apply migrations/super_admin.sql first.');
}
if ($existing > 0) {
    fail('A Super Admin already exists. Additional Super Admin accounts are only ever created from this script.');
}

$name  = trim(promptLine('Full name: '));
$email = trim(promptLine('Email address: '));
if ($name === '') {
    fail('Name is required.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('Invalid email address.');
}
$dup = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$dup->execute([$email]);
if ($dup->fetch()) {
    fail('That email address is already in use.');
}

$pass = readSecret("Password (min " . MIN_PASSWORD . " characters, not echoed to any file): ");
if (strlen($pass) < MIN_PASSWORD) {
    fail("Password must be at least " . MIN_PASSWORD . " characters.");
}
$confirm = readSecret('Confirm password: ');
if (!hash_equals($pass, $confirm)) {
    fail('Passwords do not match.');
}

try {
    // 'admin'-style literals only: role is never taken from a prompt or argument.
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, is_active) VALUES (?, ?, ?, 'super_admin', 1)");
    $stmt->execute([$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
    $newId = (int) $pdo->lastInsertId();
} catch (PDOException $e) {
    error_log('[CHED Compliance] super_admin bootstrap insert failed: ' . $e->getMessage());
    fail('Database error - no account was created.');
}

logAudit($pdo, 'create', "Super Admin account created via CLI: {$email}", 'user', $newId);
fwrite(STDOUT, "[PASS] Super Admin created (id {$newId}) for {$email}. Sign in at login.php.\n");
exit(0);
