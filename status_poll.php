<?php
// shortcut: lightweight status endpoint; upgrade to dedicated presence service if load increases.
define('CHED_APP', true);
require_once 'config.php';
requireSuperAdmin();

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$threshold = ONLINE_INACTIVITY_THRESHOLD ?? 1800;
$cutoff = time() - $threshold;

$stmt = $pdo->prepare("
    SELECT id, name, email, is_active, active_session_token, active_session_last_seen
      FROM users
     WHERE role = 'admin'
     ORDER BY id
");
$stmt->execute();
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

$result = [];
foreach ($admins as $a) {
    $token = $a['active_session_token'] ? trim($a['active_session_token']) : '';
    $lastSeen = (int) ($a['active_session_last_seen'] ?? 0);
    $online = ($token !== '' && $lastSeen > $cutoff);
    $result[] = [
        'id' => (int) $a['id'],
        'online' => $online,
        'is_active' => (int) $a['is_active'],
    ];
}

echo json_encode(['success' => true, 'admins' => $result]);
