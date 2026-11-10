<?php
// Fresh WebSocket ticket for reconnects: the page-rendered meta ticket expires
// after WS_TOKEN_TTL (120s), so any reconnect after that must fetch a new one.
define('CHED_APP', true);
require_once 'config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthenticated']);
    exit;
}

echo json_encode(['ticket' => generateWsTicket((int) $_SESSION['user_id'])]);
