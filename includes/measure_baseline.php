<?php
// Direct-access guard — measurement script, never serve directly.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}
// Minimal baseline instrumentation — does not change behavior.
// shortcut: measurements only; no caching or optimization applied.
if (!defined('MEASURE_LOG')) define('MEASURE_LOG', __DIR__ . '/../measure_baseline_log.jsonl');

function measure_stage(string $correlation, string $stage, float $start, float $end): void {
    $line = json_encode([
        'ts' => date('c'),
        'corr' => $correlation,
        'stage' => $stage,
        'ms' => round(($end - $start) * 1000, 2),
    ], JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents(MEASURE_LOG, $line, FILE_APPEND | LOCK_EX);
}
