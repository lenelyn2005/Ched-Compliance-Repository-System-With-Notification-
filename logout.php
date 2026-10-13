<?php
define('CHED_APP', true);
require_once 'config.php';

// Logout requires POST + CSRF (CSRF audit spec #9).
// GET renders a minimal auto-submitting form so existing <a href="logout.php"> links keep working.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign out</title>
</head>
<body>
    <form method="post" action="logout.php">
        <?= csrfField() ?>
        <button type="submit">Sign out</button>
    </form>
    <script nonce="<?= csp_nonce() ?>">document.forms[0].submit();</script>
</body>
</html><?php
    exit;
}

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Invalid security token. Please refresh the page and try again.');
}

if (session_status() === PHP_SESSION_ACTIVE) {
    // Release the single-active-session claim before destroying the session.
    // Scoped by token so a session that was already displaced cannot orphan the
    // token of the browser that took over the account.
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['session_token'])) {
        try {
            $pdo->prepare('UPDATE users SET active_session_token = NULL, active_session_last_seen = NULL WHERE id = ? AND active_session_token = ?')
                ->execute([$_SESSION['user_id'], $_SESSION['session_token']]);
        } catch (Throwable $e) {
            error_log('[CHED Compliance] Logout session-token clear failed: ' . $e->getMessage());
        }
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO audit_trail (user_id, action, description) VALUES (?, ?, ?)');
        $stmt->execute([$_SESSION['user_id'], 'logout', 'User logged out: ' . ($_SESSION['user_name'] ?? '')]);
    } catch (Exception $e) {}
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Strict',
        ]);
    }
    session_destroy();
}
header('Location: login.php');
exit;
