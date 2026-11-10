<?php
/**
 * End-to-end WebSocket flow check. Run while websocket/server.php is up:
 *
 *   php tests/ws_flow_test.php
 *
 * Covers: HTTP upgrade handshake, ticket auth (auth_ok), notification
 * delivery through the file outbox, and outbox drain after delivery.
 */
require __DIR__ . '/../config.php';

function fail(string $msg): void
{
    fwrite(STDERR, "FAIL: $msg\n");
    exit(1);
}

function wsSend($fp, string $text): void
{
    $mask = random_bytes(4);
    $len = strlen($text);
    $frame = chr(0x81);
    if ($len < 126) {
        $frame .= chr(0x80 | $len);
    } elseif ($len < 65536) {
        $frame .= chr(0x80 | 126) . pack('n', $len);
    } else {
        $frame .= chr(0x80 | 127) . pack('J', $len);
    }
    $frame .= $mask;
    for ($i = 0; $i < $len; $i++) {
        $frame .= $text[$i] ^ $mask[$i % 4];
    }
    fwrite($fp, $frame);
}

function wsRead($fp, int $timeoutSec = 2): ?string
{
    stream_set_timeout($fp, $timeoutSec);
    $h = fread($fp, 2);
    if ($h === false || strlen($h) < 2) {
        return null;
    }
    $len = ord($h[1]) & 0x7f;
    if ($len === 126) {
        $len = unpack('n', fread($fp, 2))[1];
    } elseif ($len === 127) {
        $len = unpack('J', fread($fp, 8))[1];
    }
    $payload = '';
    while (strlen($payload) < $len) {
        $chunk = fread($fp, $len - strlen($payload));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $payload .= $chunk;
    }
    return $payload;
}

$host = '127.0.0.1';
$port = (int) (getenv('WS_PORT') ?: 8080);

// Target: the newest notification; we auth as its owner so delivery matches.
$row = false;
try {
    $row = $pdo->query('SELECT id, user_id FROM in_app_notifications ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fail('db: ' . $e->getMessage());
}
$nid = $row ? (int) $row['id'] : 0;
$uid = $row ? (int) $row['user_id'] : 1;

// 1. TCP + HTTP upgrade handshake
$fp = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 3);
if (!$fp) {
    fail("connect $host:$port: $errstr (is websocket/server.php running?)");
}
$key = base64_encode(random_bytes(16));
fwrite($fp, "GET / HTTP/1.1\r\nHost: $host:$port\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\n\r\n");
$resp = '';
while (strpos($resp, "\r\n\r\n") === false) {
    $chunk = fread($fp, 512);
    if ($chunk === false || $chunk === '') {
        fail('handshake read: ' . $resp);
    }
    $resp .= $chunk;
}
if (strpos($resp, ' 101 ') === false) {
    fail('handshake: ' . trim($resp));
}
echo "PASS handshake (101 Switching Protocols)\n";

// 2. Ticket auth
wsSend($fp, json_encode(['action' => 'auth', 'ticket' => generateWsTicket($uid)]));
$msg = wsRead($fp, 5);
$data = json_decode((string) $msg, true);
if (($data['type'] ?? '') !== 'auth_ok') {
    fail('auth: ' . $msg);
}
echo "PASS auth (auth_ok for user $uid)\n";

if ($nid <= 0) {
    echo "SKIP delivery/drain: no rows in in_app_notifications\n";
    exit(0);
}

// 3. Use the real notification path: outbox write + internal poke.
triggerWebSocketNotification($nid, $uid);

// 4. Read frames until our notification arrives (1s fallback timer covers a failed poke).
$got = false;
$deadline = time() + 5;
while (time() < $deadline && !$got) {
    $m = wsRead($fp, 2);
    if ($m === null) {
        continue;
    }
    $d = json_decode($m, true);
    if (($d['type'] ?? '') === 'notification' && (int) ($d['notification']['id'] ?? 0) === $nid) {
        $got = true;
    }
}
if (!$got) {
    fail("notification $nid not delivered within 5s");
}
echo "PASS delivery (notification $nid received over WS)\n";

// 5. Outbox drained after delivery.
$outbox = __DIR__ . '/../websocket/pending.json';
$after = file_exists($outbox) ? (json_decode(file_get_contents($outbox), true) ?: []) : [];
foreach ($after as $it) {
    if ((int) ($it['notification_id'] ?? 0) === $nid && (int) ($it['user_id'] ?? 0) === $uid) {
        fail('outbox did not drain: delivered item still queued');
    }
}
echo "PASS outbox drain (delivered item removed)\n";
echo "ALL PASS\n";
