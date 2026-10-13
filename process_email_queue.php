<?php


// Only allow CLI (direct web access denied). spawnEmailWorker() invokes this
// via proc_open with the php binary, which is CLI SAPI — safe to hard-deny here.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('CHED_APP', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/send_email.php';

const EMAIL_QUEUE_BATCH = 20;
const EMAIL_MAX_ATTEMPTS = 5;

global $pdo;
if (!isset($pdo) || !$pdo) {
    $pdo = function_exists('perf_db') ? perf_db() : null;
}
if (!$pdo) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] No database connection available. Aborting.\n");
    exit(1);
}

$result = processEmailQueueBatch(EMAIL_QUEUE_BATCH, EMAIL_MAX_ATTEMPTS);
$sent = $result['sent'];
$retried = $result['retried'];
$failed = $result['failed'];

echo '[' . date('Y-m-d H:i:s') . "] Done: $sent sent, $retried retried, $failed failed permanently.\n";
