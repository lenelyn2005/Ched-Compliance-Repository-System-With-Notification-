<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// shortcut: loopback-only internal endpoint; upgrade to TLS internal tunnel if server is remote.
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddr, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not loopback']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$secret = getenv('WS_INTERNAL_SECRET') ?: 'fallback-secret';
$payloadFields = ['notification_id', 'user_id', 'timestamp', 'nonce'];
$payloadFieldsStr = '';
$payloadStr = '';
foreach ($payloadFields as $f) {
    $payloadFieldsStr .= ($payloadFieldsStr ? '|' : '') . $f;
    $payloadStr .= ($payloadStr ? '|' : '') . ($data[$f] ?? '');
}

$sig = $data['signature'] ?? '';
$expectedSig = hash_hmac('sha256', $secret, $payloadStr);
if (!hash_equals($expectedSig, $sig)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid signature']);
    exit;
}

$notificationId = (int)($data['notification_id'] ?? 0);
$userId = (int)($data['user_id'] ?? 0);
if ($notificationId <= 0 || $userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid IDs']);
    exit;
}

// Replay prevention for publish requests
$replayFile = __DIR__ . '/publish_replay.json';
$used = [];
if (file_exists($replayFile)) {
    $used = json_decode(file_get_contents($replayFile), true) ?: [];
}
$nonce = $data['nonce'] ?? '';
if ($nonce !== '' && isset($used[$nonce])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Replay detected']);
    exit;
}
if ($nonce !== '') {
    $used[$nonce] = time();
    $used = array_filter($used, fn($t) => ($t + 300) > time());
    @file_put_contents($replayFile, json_encode($used), LOCK_EX);
}

// Verify DB record exists
try {
    $stmt = $pdo->prepare('SELECT id FROM in_app_notifications WHERE id = ? AND user_id = ?');
    $stmt->execute([$notificationId, $userId]);
    $row = $stmt->fetch();
} catch (Exception $e) {
    $row = false;
}
if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Notification not found or unauthorized']);
    exit;
}

triggerWebSocketNotification($notificationId, $userId);

echo json_encode(['success' => true, 'notification_id' => $notificationId]);
