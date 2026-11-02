<?php
require __DIR__ . '/../vendor/autoload.php';
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

require_once __DIR__ . '/../config.php';

class NotificationServer implements \Ratchet\MessageComponentInterface
{
    protected $clients = [];
    protected $users = []; // resourceId => userId (validated)

    public function onOpen(\Ratchet\ConnectionInterface $conn)
    {
        $this->clients[$conn->resourceId] = $conn;
    }

    public function onMessage(\Ratchet\ConnectionInterface $from, $msg)
    {
        try {
            $data = json_decode($msg, true);
            if (!is_array($data)) return;
            $action = $data['action'] ?? '';
            if ($action === 'auth') {
                $ticket = $data['ticket'] ?? '';
                $userId = verifyWsTicket($ticket);
                if ($userId !== null) {
                    $this->users[$from->resourceId] = $userId;
                    $from->send(json_encode(['type' => 'auth_ok', 'user_id' => $userId]));
                } else {
                    $from->send(json_encode(['type' => 'auth_fail', 'reason' => 'invalid_or_expired']));
                    $from->close();
                }
            }
        } catch (Exception $e) {
            // silent
        }
    }

    public function onClose(\Ratchet\ConnectionInterface $conn)
    {
        unset($this->clients[$conn->resourceId]);
        unset($this->users[$conn->resourceId]);
    }

    public function onError(\Ratchet\ConnectionInterface $conn, \Exception $e)
    {
        $conn->close();
        unset($this->clients[$conn->resourceId]);
        unset($this->users[$conn->resourceId]);
    }

    public function deliverPending()
    {
        global $pdo;
        $file = __DIR__ . '/pending.json';
        if (!file_exists($file)) return;
        $items = json_decode(file_get_contents($file), true) ?: [];
        if (empty($items)) return;

        $newItems = [];
        foreach ($items as $item) {
            $notificationId = (int)($item['notification_id'] ?? 0);
            $userId = (int)($item['user_id'] ?? 0);
            if ($notificationId <= 0 || $userId <= 0) continue;

            // Verify notification exists and belongs to user
            try {
                $stmt = $pdo->prepare('SELECT id, title, message, type, related_document_id, related_doc_type FROM in_app_notifications WHERE id = ? AND user_id = ?');
                $stmt->execute([$notificationId, $userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $row = false;
            }

            if ($row) {
                $payload = json_encode([
                    'type' => 'notification',
                    'notification' => [
                        'id' => $row['id'],
                        'title' => $row['title'],
                        'message' => $row['message'],
                        'type' => $row['type'],
                        'related_document_id' => $row['related_document_id'],
                        'related_doc_type' => $row['related_doc_type'],
                    ]
                ]);
                $delivered = false;
                foreach ($this->clients as $resourceId => $client) {
                    if (isset($this->users[$resourceId]) && $this->users[$resourceId] === $userId) {
                        $client->send($payload);
                        $delivered = true;
                    }
                }
            }
            // If not delivered or user not connected, keep for retry
            // shortcut: outbox event kept until delivered or manual cleanup; upgrade to DB outbox with retry count and expiry.
            $newItems[] = $item;
        }
        @file_put_contents($file, json_encode($newItems), LOCK_EX);
    }
}

$server = new NotificationServer();
$loop = \React\EventLoop\Loop::get();
// 1s periodic timer = safety-net fallback only; normal delivery is triggered instantly by the internal poke.
$loop->addPeriodicTimer(1, function () use ($server) {
    $server->deliverPending();
});

// Internal poke listener (loopback only): PHP pokes this right after writing the outbox, for instant delivery.
$internalPort = (int)(getenv('WS_INTERNAL_PORT') ?: 8090);
$internalSocket = new \React\Socket\Server('127.0.0.1:' . $internalPort, $loop);
$internalSocket->on('connection', function ($conn) use ($server) {
    $conn->on('data', function () use ($conn, $server) {
        $server->deliverPending();
        $conn->end('ok');
    });
});

$socket = new \React\Socket\Server('127.0.0.1:' . (getenv('WS_PORT') ?: 8080), $loop);
$wsServer = new \Ratchet\WebSocket\WsServer($server);
$httpServer = new HttpServer($wsServer);
$io = new IoServer($httpServer, $socket, $loop);

echo "WebSocket server started on ws://127.0.0.1:" . (getenv('WS_PORT') ?: 8080) . "\n";
$io->run();
