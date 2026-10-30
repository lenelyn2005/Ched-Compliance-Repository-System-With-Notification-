<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/performance.php';
require_once __DIR__ . '/avatar_helpers.php';   /* AVATAR */

if (!defined('CHED_APP')) {
    exit;
}

if (defined('HEADER_ALREADY_LOADED')) {
    return;
}
define('HEADER_ALREADY_LOADED', true);

/* ============================================================
   Handle header avatar UPLOAD (any page that includes this header)
   ============================================================ */
if (!defined('HEADER_AVATAR_HANDLER_DONE')) {
    define('HEADER_AVATAR_HANDLER_DONE', true);

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'header_upload_avatar'
        && isset($_SESSION['user_id'])
    ) {
        $csrfOk = true;
        if (function_exists('verifyCsrfToken')) {
            $csrfOk = verifyCsrfToken($_POST['csrf_token'] ?? '');
        } elseif (function_exists('requireCsrfToken')) {
            try { requireCsrfToken(); } catch (Throwable $e) { $csrfOk = false; }
        }

        $uid = (int) $_SESSION['user_id'];

        if ($csrfOk && $uid > 0 && !empty($_FILES['avatar']) && (int)($_FILES['avatar']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
            $oldAvatar = null;
            $uploaderName = '';
            try {
                $oldStmt = $pdo->prepare('SELECT avatar, name FROM users WHERE id = ? LIMIT 1');
                $oldStmt->execute([$uid]);
                $row = $oldStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $oldAvatar = ($row['avatar'] ?? '') ?: null;
                $uploaderName = (string) ($row['name'] ?? '');
            } catch (PDOException $e) {
                error_log('[CHED] header avatar read failed: ' . $e->getMessage());
            }

            $res = avatarUpload($_FILES['avatar'], $uid, $uploaderName);

            if (!empty($res['ok'])) {
                try {
                    $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?')
                        ->execute([$res['path'], $uid]);

                    if ($oldAvatar && $oldAvatar !== $res['path']) {
                        @avatarDelete($oldAvatar);
                    }

                    $_SESSION['avatar'] = $res['path'];
                } catch (PDOException $e) {
                    error_log('[CHED] header avatar update failed: ' . $e->getMessage());
                }
            }

            $back = $_SERVER['REQUEST_URI'] ?? 'index.php';
            header('Location: ' . $back);
            exit;
        }
    }
}

/* ============================================================
   Handle header avatar REMOVE (from the avatar lightbox)
   ============================================================ */
if (!defined('HEADER_AVATAR_REMOVE_DONE')) {
    define('HEADER_AVATAR_REMOVE_DONE', true);

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'header_remove_avatar'
        && isset($_SESSION['user_id'])
    ) {
        $csrfOk = true;
        if (function_exists('verifyCsrfToken')) {
            $csrfOk = verifyCsrfToken($_POST['csrf_token'] ?? '');
        } elseif (function_exists('requireCsrfToken')) {
            try { requireCsrfToken(); } catch (Throwable $e) { $csrfOk = false; }
        }

        $uid = (int) $_SESSION['user_id'];

        if ($csrfOk && $uid > 0) {
            try {
                $stmt = $pdo->prepare('SELECT avatar FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$uid]);
                $currentAvatar = $stmt->fetchColumn();

                if ($currentAvatar) {
                    if (function_exists('avatarDelete')) {
                        @avatarDelete($currentAvatar);
                    }
                    $pdo->prepare('UPDATE users SET avatar = NULL WHERE id = ?')
                        ->execute([$uid]);
                }
                unset($_SESSION['avatar']);
            } catch (PDOException $e) {
                error_log('[CHED] header avatar remove failed: ' . $e->getMessage());
            }
        }

        $back = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: ' . $back);
        exit;
    }
}

if (!function_exists('render_icon')) {
    function render_icon(string $name): string {
        $icons = [
            'bell' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>',
            'bell-off' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13.73 21a2 2 0 0 1-3.46 0"/><path d="M18.63 13A17.89 17.89 0 0 1 18 8"/><path d="M6.26 6.26A5.86 5.86 0 0 0 6 8c0 7-3 9-3 9h14"/><path d="M18 8a6 6 0 0 0-9.33-5"/><path d="m1 1 22 22"/><path d="M17 17H3"/></svg>',
            'check-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
            'x-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
            'info' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>',
            'alert-triangle' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>',
            'menu' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" x2="21" y1="6" y2="6"/><line x1="3" x2="21" y1="12" y2="12"/><line x1="3" x2="21" y1="18" y2="18"/></svg>',
            'folder' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.93a2 2 0 0 1-1.66-.9l-.82-1.2A2 2 0 0 0 7.93 4H4a2 2 0 0 0-2 2v13c0 1.1.9 2 2 2Z"/></svg>',
            'folder-open' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 14 1.45-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.55 6a2 2 0 0 1-1.94 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4a2 2 0 0 1 1.8.1l.9-1.2a2 2 0 0 1 2.1-.4l3.2 1.2a2 2 0 0 1 1.2 2.3V13"/></svg>',
            'file' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><line x1="10" x2="8" y1="9" y2="9"/></svg>',
            'file-text' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/></svg>',
            'download' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>',
            'clipboard' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>',
            'calendar' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>',
            'eye' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>',
            'trash' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>',
            'chevron-down' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>',
            'chevron-up' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>',
            'archive' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M4 6v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6"/><path d="M10 12h4"/><path d="M4 10h16"/></svg>',
            'lock' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
            'unlock' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>',
            'circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg>',
            'circle-check' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
            'search' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>',
            'camera' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9a2 2 0 0 1 2-2h.93a2 2 0 0 0 1.66-.89l.81-1.22A2 2 0 0 1 10.07 4h3.86a2 2 0 0 1 1.66.89l.81 1.22A2 2 0 0 0 18.07 7H19a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9Z"/><circle cx="12" cy="13" r="3"/></svg>',
            'user' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
            'settings' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
            'logout' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>',
            'plus' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>',
            'upload-cloud' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14.9A7 7 0 1 1 15.7 8h1.8a4.5 4.5 0 0 1 2.5 8.2"/><path d="M12 12v9"/><path d="m8 16 4-4 4 4"/></svg>',
            'sparkles' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/></svg>',
            'home' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
            'corner-down-left' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 10 4 15 9 20"/><path d="M20 4v7a4 4 0 0 1-4 4H4"/></svg>',
            'arrow-up-right' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>',
            'command' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 6v12a3 3 0 1 0 3-3H6a3 3 0 1 0 3 3V6a3 3 0 1 0-3 3h12a3 3 0 1 0-3-3"/></svg>',
        ];
        return $icons[$name] ?? '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/></svg>';
    }
}

// OPTIMIZED: Cache notification count in session for 10 seconds
$unreadCount = 0;
if (isset($_SESSION['user_id'])) {
    $notifCacheKey = 'notif_count_' . $_SESSION['user_id'];
    $notifCacheTime = 'notif_time_' . $_SESSION['user_id'];

    if (!isset($_SESSION[$notifCacheKey]) || (time() - ($_SESSION[$notifCacheTime] ?? 0) > 10)) {
        $unreadCount = getUnreadNotificationCount($pdo, (int) $_SESSION['user_id']);
        $_SESSION[$notifCacheKey] = $unreadCount;
        $_SESSION[$notifCacheTime] = time();
    } else {
        $unreadCount = $_SESSION[$notifCacheKey] ?? 0;
    }
}

// User for header
$userName = $_SESSION['user_name'] ?? 'User';

/* AVATAR + mini-profile info */
$headerAvatarPath = null;
$headerAvatarUrl  = null;
$headerUserEmail  = $_SESSION['email'] ?? '';
$headerUserRole   = $_SESSION['role'] ?? ($_SESSION['user_role'] ?? '');

if (isset($_SESSION['user_id'])) {
    try {
        if (usersHasAvatarColumn($pdo)) {
            $avatarStmt = $pdo->prepare('SELECT avatar, email, role FROM users WHERE id = ? LIMIT 1');
            $avatarStmt->execute([(int) $_SESSION['user_id']]);
            $uRow = $avatarStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $headerAvatarPath = $uRow['avatar'] ?? null;
            if (!empty($uRow['email'])) $headerUserEmail = $uRow['email'];
            if (!empty($uRow['role']))  $headerUserRole  = $uRow['role'];
        } else {
            $uStmt = $pdo->prepare('SELECT email, role FROM users WHERE id = ? LIMIT 1');
            $uStmt->execute([(int) $_SESSION['user_id']]);
            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!empty($uRow['email'])) $headerUserEmail = $uRow['email'];
            if (!empty($uRow['role']))  $headerUserRole  = $uRow['role'];
        }
    } catch (PDOException $e) {
        error_log('[CHED] header avatar fetch failed: ' . $e->getMessage());
        $headerAvatarPath = null;
    }
    if ($headerAvatarPath) {
        $headerAvatarUrl = avatarUrl($headerAvatarPath);
        $_SESSION['avatar'] = $headerAvatarPath;
    }
}

/* Human-friendly role label */
$roleLabel = 'User';
$roleLower = strtolower((string) $headerUserRole);
if ($roleLower === 'admin')                 $roleLabel = 'Administrator';
elseif ($roleLower === 'super_admin')         $roleLabel = 'Super Administrator';
elseif ($roleLower === 'department')        $roleLabel = 'Program Head';
elseif ($roleLower !== '')                  $roleLabel = ucfirst(str_replace('_', ' ', $roleLower));

$headerInitial = strtoupper(mb_substr(trim($userName), 0, 1));
$hasAvatar     = !empty($headerAvatarUrl);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <?php if (isset($_SESSION['user_id'])): ?>
    <meta name="ws-ticket" content="<?= htmlspecialchars(generateWsTicket((int)$_SESSION['user_id']), ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <script nonce="<?= csp_nonce() ?>">
        window.WS_HOST = <?= json_encode(getenv('WS_HOST') ?: 'localhost', JSON_UNESCAPED_SLASHES) ?>;
        window.WS_PORT = <?= json_encode((string)(getenv('WS_PORT') ?: '8080'), JSON_UNESCAPED_SLASHES) ?>;
        window.WS_PATH = <?= json_encode((string)(getenv('WS_PUBLIC_PATH') ?: ''), JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <title><?= $pageTitle ?? 'CHED Compliance Repository' ?></title>
    <meta name="description"
        content="CHED Compliance Repository System - Digital document management for educational standards">

    <style>
        /* ============================================================
           DESIGN TOKENS
           ============================================================ */
        :root {
            --primary: #2563eb;
            --primary-600: #1d4ed8;
            --primary-700: #1e40af;
            --primary-50: #eff6ff;
            --primary-100: #dbeafe;
            --violet: #7c3aed;
            --violet-50: #f5f3ff;

            --danger: #ef4444;
            --danger-50: #fef2f2;
            --success: #22c55e;
            --warning: #f59e0b;

            --white: #ffffff;
            --bg: #f6f8fc;
            --text: #0f172a;
            --text-2: #475569;
            --text-3: #94a3b8;

            --line: #e6ebf3;
            --line-2: #eef2f7;
            --surface-2: #f4f6fb;

            --sidebar-width: 280px;
            --sidebar-collapsed-width: 80px;
            --topbar-height: 72px;

            --r-sm: 10px;
            --r-md: 14px;
            --r-lg: 20px;
            --r-full: 999px;

            --ring: 0 0 0 3px rgba(37, 99, 235, .16);
            --sh-1: 0 1px 2px rgba(15, 23, 42, .05);
            --sh-2: 0 4px 14px -6px rgba(15, 23, 42, .22);
            --sh-3: 0 28px 64px -26px rgba(15, 23, 42, .45),
                    0 6px 18px -10px rgba(15, 23, 42, .14);

            --ease: cubic-bezier(.16, .84, .44, 1);
            --transition: 0.25s cubic-bezier(0.4, 0, 0.2, 1);

            --grad-brand: linear-gradient(135deg, #2563eb 0%, #7c3aed 100%);
            --grad-brand-soft: linear-gradient(135deg, #eff6ff 0%, #f5f3ff 100%);
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            font-size: 14px; line-height: 1.6; color: var(--text);
            background: var(--bg); -webkit-font-smoothing: antialiased; min-height: 100vh;
        }
        button, input { font-family: inherit; }

        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            transition: margin-left var(--transition);
            display: flex; flex-direction: column;
        }
        body.sidebar-collapsed .main-content { margin-left: var(--sidebar-collapsed-width); }
        .content-area { padding: 1.25rem; flex: 1; }

        /* ============================================================
           APP BAR SHELL
           ============================================================ */
        .appbar {
            position: sticky; top: 0; z-index: 200;
            height: var(--topbar-height);
            background: rgba(255, 255, 255, .80);
            -webkit-backdrop-filter: saturate(180%) blur(18px);
            backdrop-filter: saturate(180%) blur(18px);
            border-bottom: 1px solid var(--line);
            transition: background .28s ease, box-shadow .28s ease, border-color .28s ease;
        }
        .appbar.is-scrolled {
            background: rgba(255, 255, 255, .95);
            border-bottom-color: #dde5f0;
            box-shadow: 0 12px 30px -24px rgba(15, 23, 42, .6);
        }

        .appbar-inner {
            position: relative;
            height: 100%;
            display: flex; align-items: center; gap: 1rem;
            padding: 0 1.25rem;
        }

        /* ---- LEFT: toggle + page heading ---- */
        .appbar-left {
            display: flex; align-items: center; gap: .85rem;
            min-width: 0; flex-shrink: 1;
        }
        .page-heading { min-width: 0; }
        .crumbs {
            display: flex; align-items: center; gap: .35rem;
            font-size: .68rem; font-weight: 700; letter-spacing: .04em;
            text-transform: uppercase; color: var(--text-3);
            line-height: 1.2; margin-bottom: .1rem;
        }
        .crumbs .sep { opacity: .5; }
        .crumbs .cur { color: var(--primary); }
        .page-title {
            font-size: 1.08rem; font-weight: 800; letter-spacing: -.024em;
            color: var(--text); line-height: 1.25;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        /* ---- CENTER: search trigger ---- */
        .search-trigger {
            flex: 1 1 auto; max-width: 440px; margin: 0 auto;
            display: flex; align-items: center; gap: .65rem;
            height: 42px; padding: 0 .55rem 0 .9rem;
            background: var(--surface-2);
            border: 1px solid transparent;
            border-radius: var(--r-full);
            color: var(--text-3); font-size: .84rem; font-weight: 500;
            cursor: pointer; text-align: left;
            transition: background .2s ease, border-color .2s ease,
                        box-shadow .2s ease, color .2s ease;
        }
        .search-trigger:hover {
            background: var(--white); border-color: var(--line);
            color: var(--text-2); box-shadow: var(--sh-1);
        }
        .search-trigger:focus-visible { outline: none; box-shadow: var(--ring); border-color: var(--primary); }
        .search-trigger svg { flex-shrink: 0; width: 17px; height: 17px; }
        .search-trigger .st-label {
            flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .search-trigger kbd {
            flex-shrink: 0;
            display: inline-flex; align-items: center; gap: 2px;
            padding: .26rem .45rem; border-radius: 7px;
            background: var(--white); border: 1px solid var(--line);
            font: 700 .66rem/1 'Plus Jakarta Sans', sans-serif;
            color: var(--text-3); box-shadow: var(--sh-1);
        }

        /* ---- RIGHT cluster ---- */
        .appbar-right {
            display: flex; align-items: center; gap: .55rem;
            flex-shrink: 0;
        }

        .icon-btn {
            position: relative;
            width: 40px; height: 40px; flex-shrink: 0;
            display: grid; place-items: center;
            background: var(--white); color: var(--text-2);
            border: 1px solid var(--line); border-radius: 12px;
            cursor: pointer; padding: 0; box-shadow: var(--sh-1);
            transition: color .2s ease, background .2s ease, border-color .2s ease,
                        transform .2s ease, box-shadow .2s ease;
        }
        .icon-btn:hover {
            color: var(--primary); background: var(--primary-50);
            border-color: var(--primary-100); transform: translateY(-1px);
            box-shadow: var(--sh-2);
        }
        .icon-btn:active { transform: translateY(0) scale(.95); }
        .icon-btn:focus-visible { outline: none; box-shadow: var(--ring); border-color: var(--primary); }
        .icon-btn svg { display: block; width: 19px; height: 19px; }

        /* Tooltip for icon buttons */
        .icon-btn[data-tip]::after {
            content: attr(data-tip);
            position: absolute; top: calc(100% + 9px); left: 50%;
            transform: translateX(-50%) translateY(-4px);
            padding: .3rem .55rem; border-radius: 7px;
            background: #0f172a; color: #fff;
            font-size: .68rem; font-weight: 700; letter-spacing: .01em;
            white-space: nowrap; pointer-events: none;
            opacity: 0; transition: opacity .16s ease, transform .16s var(--ease);
            z-index: 10;
        }
        .icon-btn[data-tip]:hover::after {
            opacity: 1; transform: translateX(-50%) translateY(0);
        }

        /* Quick "New" button */
        .quick-btn {
            display: inline-flex; align-items: center; gap: .45rem;
            height: 40px; padding: 0 1rem 0 .85rem;
            border: none; border-radius: 12px; cursor: pointer;
            background: var(--grad-brand); color: #fff;
            font-size: .82rem; font-weight: 700; letter-spacing: -.01em;
            text-decoration: none; white-space: nowrap;
            box-shadow: 0 6px 16px -8px rgba(37, 99, 235, .9);
            transition: transform .2s var(--ease), box-shadow .2s ease, filter .2s ease;
        }
        .quick-btn:hover {
            transform: translateY(-1px); filter: brightness(1.05);
            box-shadow: 0 12px 24px -10px rgba(37, 99, 235, 1);
        }
        .quick-btn:active { transform: translateY(0) scale(.98); }
        .quick-btn svg { width: 16px; height: 16px; }

        /* Notification badge */
        .notif-count {
            position: absolute; top: -6px; right: -6px;
            min-width: 19px; height: 19px; padding: 0 5px;
            border-radius: var(--r-full);
            background: linear-gradient(135deg, #f87171 0%, #dc2626 100%);
            color: #fff; font-size: .63rem; font-weight: 800; line-height: 1;
            display: grid; place-items: center;
            border: 2px solid var(--white);
            animation: badgePulse 2.4s ease-in-out infinite;
        }
        @keyframes badgePulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, .5); }
            60%      { box-shadow: 0 0 0 7px rgba(239, 68, 68, 0); }
        }

        /* ---- USER CHIP ---- */
              .user-chip {
            display: flex; align-items: center; gap: .6rem;
            height: 44px; padding: .25rem .8rem .25rem .25rem;
            background:
                linear-gradient(var(--white), var(--white)) padding-box,
                linear-gradient(135deg, #22d3ee 0%, #2563eb 50%, #7c3aed 100%) border-box;
            border: 2px solid transparent;
            border-radius: var(--r-full); cursor: pointer;
            box-shadow: 0 0 0 3px rgba(34, 211, 238, .12), var(--sh-1);
            user-select: none;
            transition: box-shadow .2s ease, transform .2s ease;
        }
        .user-chip:hover {
            transform: translateY(-1px);
            box-shadow: 0 0 0 4px rgba(34, 211, 238, .22),
                        0 8px 24px -10px rgba(37, 99, 235, .75);
        }
        .user-chip.is-open {
            box-shadow: 0 0 0 4px rgba(34, 211, 238, .28);
        }
        .user-chip:focus-visible {
            outline: none;
            box-shadow: 0 0 0 4px rgba(34, 211, 238, .35);
        }

        .user-chip .uc-avatar {
            position: relative; width: 34px; height: 34px; flex-shrink: 0;
            border-radius: 50%; padding: 2px;
            background: var(--grad-brand-soft); border: 1px solid var(--line);
            box-shadow: 0 3px 10px -4px rgba(37, 99, 235, .8);
        }
        .user-chip .uc-avatar-inner {
            width: 100%; height: 100%; border-radius: 50%;
            background: var(--grad-brand);
            color: #fff; display: grid; place-items: center;
            font-size: .78rem; font-weight: 800; line-height: 1;
            border: 2px solid var(--white); overflow: hidden;
        }
        .user-chip .uc-avatar-inner img {
            width: 100%; height: 100%; object-fit: cover; display: block;
        }
        .user-chip .uc-avatar::after {
            content: ""; position: absolute; right: 0; bottom: 0;
            width: 10px; height: 10px; border-radius: 50%;
            background: var(--success); border: 2px solid var(--white);
            box-shadow: 0 0 0 1px rgba(34, 197, 94, .25);
        }
        
        .uc-meta { display: flex; flex-direction: column; align-items: flex-start; min-width: 0; }
        .uc-name {
            font-size: .82rem; font-weight: 700; letter-spacing: -.012em;
            color: var(--text); line-height: 1.2;
            max-width: 128px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .uc-role {
            font-size: .66rem; font-weight: 600; color: var(--text-3);
            letter-spacing: .02em; line-height: 1.2;
            max-width: 128px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .uc-chev {
            width: 14px; height: 14px; flex-shrink: 0; color: var(--text-3);
            transition: transform .24s var(--ease), color .2s ease;
        }
        .user-chip:hover .uc-chev { color: var(--text-2); }
        .user-chip.is-open .uc-chev { transform: rotate(180deg); color: var(--primary); }

        /* ============================================================
           DROPDOWN / PANEL BASE
           ============================================================ */
        .panel {
            position: absolute; top: calc(100% - 8px); right: 1.25rem;
            z-index: 1000;
            background: var(--white); border: 1px solid var(--line);
            border-radius: var(--r-lg); box-shadow: var(--sh-3);
            overflow: hidden; transform-origin: top right;
            opacity: 0; visibility: hidden;
            transform: translateY(-10px) scale(.97);
            transition: opacity .18s var(--ease), transform .18s var(--ease),
                        visibility 0s linear .18s;
        }
        .panel.show {
            opacity: 1; visibility: visible; transform: translateY(0) scale(1);
            transition: opacity .2s var(--ease), transform .2s var(--ease), visibility 0s;
        }

        /* ============================================================
           PROFILE PANEL
           ============================================================ */
        .profile-panel { width: 336px; max-width: calc(100vw - 2rem); }

        .pp-cover {
            position: relative; height: 96px;
            background: var(--grad-brand);
        }
        .pp-cover::after {
            content: ""; position: absolute; inset: 0;
            background-image:
                radial-gradient(circle at 18% 20%, rgba(255,255,255,.30) 0, transparent 42%),
                radial-gradient(circle at 82% 80%, rgba(255,255,255,.18) 0, transparent 46%);
        }
        .pp-cover-grid {
            position: absolute; inset: 0; opacity: .18;
            background-image:
                linear-gradient(rgba(255,255,255,.6) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,.6) 1px, transparent 1px);
            background-size: 22px 22px;
            -webkit-mask-image: radial-gradient(ellipse at 50% 100%, #000 20%, transparent 75%);
            mask-image: radial-gradient(ellipse at 50% 100%, #000 20%, transparent 75%);
        }

        .pp-body {
            position: relative;
            padding: 0 1.25rem 1.15rem;
            text-align: center;
        }

        .pp-avatar-wrap {
            position: relative;
            width: 96px; height: 96px;
            margin: -48px auto .7rem;
        }
        .pp-avatar {
            position: relative;
            width: 100%; height: 100%; padding: 4px; border: none;
            border-radius: 50%; background: var(--white);
            cursor: zoom-in; display: block;
            box-shadow: 0 10px 28px -14px rgba(15, 23, 42, .6);
            transition: transform .24s var(--ease);
        }
        .pp-avatar:hover { transform: translateY(-2px) scale(1.02); }
        .pp-avatar:active { transform: translateY(0) scale(.98); }
        .pp-avatar:focus-visible { outline: none; box-shadow: var(--ring); }

        .pp-avatar-media {
            width: 100%; height: 100%; border-radius: 50%; overflow: hidden;
            background: var(--grad-brand); color: #fff;
            display: grid; place-items: center;
            font-size: 2.1rem; font-weight: 800; letter-spacing: -.03em; line-height: 1;
        }
        .pp-avatar-media img { width: 100%; height: 100%; object-fit: cover; display: block; }

        .pp-avatar-overlay {
            position: absolute; inset: 4px; border-radius: 50%;
            background: radial-gradient(circle at center, rgba(15,23,42,.20), rgba(15,23,42,.72));
            color: #fff; display: grid; place-items: center;
            opacity: 0; pointer-events: none; transition: opacity .18s ease;
        }
        .pp-avatar:hover .pp-avatar-overlay,
        .pp-avatar:focus-visible .pp-avatar-overlay { opacity: 1; }
        .pp-avatar-overlay svg { width: 26px; height: 26px; }

        .pp-camera {
            position: absolute; right: -2px; bottom: 2px;
            width: 30px; height: 30px; border-radius: 50%;
            background: var(--primary); color: #fff;
            border: 3px solid var(--white);
            display: grid; place-items: center; cursor: pointer;
            box-shadow: 0 3px 10px rgba(15, 23, 42, .28);
            transition: background .18s ease, transform .18s var(--ease), box-shadow .2s ease;
        }
        .pp-avatar-wrap:hover .pp-camera {
            background: var(--primary-600); transform: scale(1.1);
            box-shadow: 0 5px 16px rgba(37, 99, 235, .6);
        }
        .pp-camera:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(37, 99, 235, .35); }
        .pp-camera svg { width: 13px; height: 13px; }

        .pp-name {
            font-size: 1.04rem; font-weight: 800; letter-spacing: -.02em;
            color: var(--text); line-height: 1.25; word-break: break-word;
        }
        .pp-email {
            font-size: .78rem; color: var(--text-2); margin-top: .18rem;
            word-break: break-all;
        }
        .pp-role {
            display: inline-flex; align-items: center; gap: .38rem;
            margin-top: .7rem; padding: .26rem .78rem;
            background: var(--grad-brand-soft); color: var(--primary-700);
            border: 1px solid var(--primary-100); border-radius: var(--r-full);
            font-size: .65rem; font-weight: 800;
            letter-spacing: .08em; text-transform: uppercase;
        }
        .pp-role::before {
            content: ""; width: 5px; height: 5px; border-radius: 50%;
            background: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .15);
        }

.pp-actions { padding: .55rem; border-top: 1px solid var(--line-2); }
        .pp-action {
            display: flex; align-items: center; gap: .7rem;
            width: 100%; padding: .62rem .85rem;
            border: none; background: none; border-radius: var(--r-sm);
            font-size: .85rem; font-weight: 600; color: var(--text);
            text-align: left; text-decoration: none; cursor: pointer;
            transition: background .15s ease, color .15s ease, transform .15s ease;
        }
        .pp-action svg {
            width: 16px; height: 16px; flex-shrink: 0; color: var(--text-2);
            transition: color .15s ease, transform .18s var(--ease);
        }
        .pp-action:hover { background: var(--surface-2); transform: translateX(2px); }
        .pp-action:hover svg { color: var(--primary); transform: scale(1.08); }
        .pp-action.danger { color: var(--danger); }
        .pp-action.danger svg { color: var(--danger); }
        .pp-action.danger:hover { background: var(--danger-50); }
        .pp-divider { height: 1px; background: var(--line-2); margin: .3rem .6rem; }

        /* ============================================================
           NOTIFICATION PANEL
           ============================================================ */
        .notif-panel {
            width: 400px; max-width: calc(100vw - 2rem);
            max-height: min(600px, calc(100vh - 100px));
            display: flex; flex-direction: column;
        }

        .np-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: .75rem; padding: 1rem 1.15rem .75rem;
            border-bottom: 1px solid var(--line-2);
        }
        .np-title {
            display: flex; align-items: center; gap: .5rem;
            font-size: .92rem; font-weight: 800; letter-spacing: -.015em;
            color: var(--text);
        }
        .np-title .np-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--danger);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, .18);
        }
        .np-mark {
            border: none; background: none; cursor: pointer;
            font-size: .72rem; font-weight: 700; color: var(--primary);
            padding: .28rem .6rem; border-radius: var(--r-full);
            transition: background .15s ease, color .15s ease;
        }
        .np-mark:hover { background: var(--primary-50); color: var(--primary-700); }

        .np-tabs {
            display: flex; gap: .3rem; padding: .6rem 1.15rem .7rem;
            border-bottom: 1px solid var(--line-2);
        }
        .np-tab {
            border: none; background: none; cursor: pointer;
            padding: .34rem .78rem; border-radius: var(--r-full);
            font-size: .76rem; font-weight: 700; color: var(--text-2);
            display: inline-flex; align-items: center; gap: .38rem;
            transition: background .16s ease, color .16s ease;
        }
        .np-tab:hover { background: var(--surface-2); color: var(--text); }
        .np-tab.active { background: var(--text); color: #fff; }
        .np-tab .np-tab-count {
            font-size: .64rem; font-weight: 800;
            padding: .05rem .38rem; border-radius: var(--r-full);
            background: rgba(37, 99, 235, .12); color: var(--primary);
        }
        .np-tab.active .np-tab-count {
            background: rgba(255, 255, 255, .22); color: #fff;
        }

        .np-list {
            flex: 1; overflow-y: auto; overscroll-behavior: contain;
            scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent;
        }
        .np-list::-webkit-scrollbar { width: 8px; }
        .np-list::-webkit-scrollbar-track { background: transparent; }
        .np-list::-webkit-scrollbar-thumb {
            background: #cbd5e1; border-radius: var(--r-full);
            border: 2px solid var(--white);
        }
        .np-list::-webkit-scrollbar-thumb:hover { background: var(--text-3); }

        .np-item {
            position: relative; display: flex; gap: .8rem;
            padding: .85rem 1.15rem;
            border-bottom: 1px solid var(--line-2);
            text-decoration: none; color: inherit;
            transition: background .15s ease;
        }
        .np-item:last-child { border-bottom: none; }
        .np-item:hover { background: #fafbfe; }
        .np-item.unread {
            background: linear-gradient(90deg, rgba(37, 99, 235, .07) 0%, rgba(37, 99, 235, .012) 100%);
        }
        .np-item.unread:hover {
            background: linear-gradient(90deg, rgba(37, 99, 235, .11) 0%, rgba(37, 99, 235, .035) 100%);
        }
        .np-item.unread::before {
            content: ""; position: absolute; left: 0; top: 14px; bottom: 14px;
            width: 3px; border-radius: 0 3px 3px 0; background: var(--primary);
        }

        .np-icon {
            flex-shrink: 0; width: 38px; height: 38px; border-radius: 12px;
            display: grid; place-items: center;
            background: var(--surface-2); color: var(--text-2);
            transition: transform .18s var(--ease);
        }
        .np-item:hover .np-icon { transform: scale(1.06); }
        .np-icon svg { width: 18px; height: 18px; display: block; }
        .np-icon.success { background: rgba(34, 197, 94, .12);  color: #16a34a; }
        .np-icon.danger  { background: rgba(239, 68, 68, .12);  color: #dc2626; }
        .np-icon.warning { background: rgba(245, 158, 11, .14); color: #d97706; }
        .np-icon.info    { background: rgba(59, 130, 246, .12); color: #2563eb; }

        .np-content { flex: 1; min-width: 0; }
        .np-item-title {
            font-weight: 700; font-size: .81rem; color: var(--text);
            margin-bottom: .16rem; line-height: 1.35;
        }
        .np-item-msg {
            font-size: .75rem; color: var(--text-2); line-height: 1.45;
            margin-bottom: .32rem;
            overflow: hidden; text-overflow: ellipsis;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
        }
        .np-item-time {
            font-size: .68rem; font-weight: 600; color: var(--text-3);
            display: inline-flex; align-items: center; gap: .3rem;
        }
        .np-item-time::before {
            content: ""; width: 4px; height: 4px; border-radius: 50%;
            background: currentColor; opacity: .55;
        }

        .np-empty { padding: 3rem 1.5rem; text-align: center; }
        .np-empty-icon {
            width: 52px; height: 52px; margin: 0 auto .85rem;
            border-radius: 50%; display: grid; place-items: center;
            background: var(--surface-2); color: var(--text-3);
        }
        .np-empty-icon svg { width: 22px; height: 22px; }
        .np-empty p { font-size: .82rem; color: var(--text-2); font-weight: 600; margin: 0; }
        .np-empty small { font-size: .72rem; color: var(--text-3); }

        .np-foot {
            padding: .7rem 1.15rem; text-align: center;
            border-top: 1px solid var(--line-2); background: #fafbfe;
        }
        .np-foot a {
            font-size: .75rem; font-weight: 700; color: var(--primary);
            text-decoration: none; transition: color .15s ease;
            display: inline-flex; align-items: center; gap: .32rem;
        }
        .np-foot a:hover { color: var(--primary-700); }
        .np-foot a svg { width: 13px; height: 13px; }

        /* ============================================================
           COMMAND PALETTE
           ============================================================ */
        .cmdk-overlay {
            position: fixed; inset: 0; z-index: 2500;
            display: none; align-items: flex-start; justify-content: center;
            padding: 12vh 1.25rem 1.25rem;
            background: rgba(15, 23, 42, .55);
            -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px);
            opacity: 0; transition: opacity .2s var(--ease);
        }
        .cmdk-overlay.show { display: flex; opacity: 1; }

        .cmdk {
            width: 100%; max-width: 560px;
            background: var(--white); border-radius: var(--r-lg);
            box-shadow: var(--sh-3); overflow: hidden;
            transform: translateY(-12px) scale(.97); opacity: 0;
            transition: transform .26s var(--ease), opacity .2s var(--ease);
        }
        .cmdk-overlay.show .cmdk { transform: translateY(0) scale(1); opacity: 1; }

        .cmdk-input-wrap {
            display: flex; align-items: center; gap: .75rem;
            padding: .9rem 1.15rem; border-bottom: 1px solid var(--line-2);
        }
        .cmdk-input-wrap svg { width: 18px; height: 18px; color: var(--text-3); flex-shrink: 0; }
        .cmdk-input {
            flex: 1; border: none; outline: none; background: none;
            font-size: .94rem; font-weight: 600; color: var(--text);
            letter-spacing: -.01em;
        }
        .cmdk-input::placeholder { color: var(--text-3); font-weight: 500; }
        .cmdk-esc {
            flex-shrink: 0; padding: .22rem .45rem; border-radius: 6px;
            background: var(--surface-2); border: 1px solid var(--line);
            font: 700 .64rem/1 'Plus Jakarta Sans', sans-serif; color: var(--text-3);
        }

        .cmdk-list {
            max-height: 340px; overflow-y: auto; padding: .45rem;
            overscroll-behavior: contain;
        }
        .cmdk-group-label {
            padding: .6rem .75rem .3rem;
            font-size: .64rem; font-weight: 800; letter-spacing: .09em;
            text-transform: uppercase; color: var(--text-3);
        }
        .cmdk-item {
            display: flex; align-items: center; gap: .75rem;
            padding: .62rem .75rem; border-radius: var(--r-sm);
            text-decoration: none; color: var(--text); cursor: pointer;
            font-size: .85rem; font-weight: 600;
            transition: background .14s ease, color .14s ease;
        }
        .cmdk-item:hover, .cmdk-item.is-active {
            background: var(--primary-50); color: var(--primary-700);
        }
        .cmdk-item svg { width: 16px; height: 16px; color: var(--text-2); flex-shrink: 0; }
        .cmdk-item:hover svg, .cmdk-item.is-active svg { color: var(--primary); }
        .cmdk-item .cmdk-hint {
            margin-left: auto; font-size: .68rem; font-weight: 700;
            color: var(--text-3);
        }
        .cmdk-empty {
            padding: 2.5rem 1.5rem; text-align: center;
            font-size: .82rem; color: var(--text-3); font-weight: 600;
        }

        /* ============================================================
           AVATAR LIGHTBOX
           ============================================================ */
        .avatar-lightbox {
            position: fixed; inset: 0; z-index: 3000; display: none;
            align-items: center; justify-content: center; padding: 1.5rem;
            background: rgba(15, 23, 42, .72);
            -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px);
            opacity: 0; transition: opacity .24s var(--ease);
        }
        .avatar-lightbox.show { display: flex; opacity: 1; }

        .avatar-lightbox-card {
            position: relative; width: 100%; max-width: 420px;
            padding: 2rem 1.75rem 1.5rem; text-align: center;
            background: var(--white); border-radius: 24px;
            box-shadow: 0 40px 90px -30px rgba(15, 23, 42, .8),
                        0 8px 20px -8px rgba(15, 23, 42, .35);
            transform: scale(.92) translateY(10px); opacity: 0;
            transition: transform .3s cubic-bezier(.16, 1, .3, 1), opacity .24s var(--ease);
        }
        .avatar-lightbox.show .avatar-lightbox-card {
            transform: scale(1) translateY(0); opacity: 1;
        }

        .avatar-lightbox-close {
            position: absolute; top: .85rem; right: .85rem;
            width: 36px; height: 36px; border-radius: 50%;
            background: var(--surface-2); border: none; color: var(--text-2);
            cursor: pointer; display: grid; place-items: center;
            transition: background .18s ease, color .18s ease, transform .22s var(--ease);
        }
        .avatar-lightbox-close:hover {
            background: var(--danger-50); color: var(--danger); transform: rotate(90deg);
        }
        .avatar-lightbox-close:focus-visible { outline: none; box-shadow: var(--ring); }
        .avatar-lightbox-close svg { width: 16px; height: 16px; }

        .avatar-lightbox-media {
            width: 220px; height: 220px; margin: 0 auto 1.15rem;
            border-radius: 50%; overflow: hidden;
            background: var(--grad-brand);
            color: #fff; font-size: 4.75rem; font-weight: 800;
            letter-spacing: -.04em; line-height: 1; display: grid; place-items: center;
            box-shadow: 0 0 0 4px #fff,
                        0 0 0 5px rgba(37, 99, 235, .18),
                        0 30px 60px -20px rgba(37, 99, 235, .55);
        }
        .avatar-lightbox-media img {
            width: 100%; height: 100%; object-fit: cover; display: block;
        }

        .avatar-lightbox-name {
            font-size: 1.15rem; font-weight: 800; color: var(--text);
            letter-spacing: -.018em; line-height: 1.3; word-break: break-word;
        }
        .avatar-lightbox-email {
            font-size: .82rem; color: var(--text-2);
            margin-top: .22rem; word-break: break-all;
        }
        .avatar-lightbox-role {
            display: inline-flex; align-items: center; gap: .35rem;
            margin-top: .75rem; padding: .28rem .78rem;
            background: var(--grad-brand-soft); color: var(--primary-700);
            border: 1px solid var(--primary-100); border-radius: var(--r-full);
            font-size: .68rem; font-weight: 800;
            letter-spacing: .07em; text-transform: uppercase;
        }
        .avatar-lightbox-role::before {
            content: ""; width: 5px; height: 5px; border-radius: 50%; background: var(--primary);
        }

        .avatar-lightbox-actions {
            margin-top: 1.5rem; display: flex; gap: .6rem;
            justify-content: center; flex-wrap: wrap;
        }

        .avatar-lightbox-btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: .42rem; padding: .68rem 1.15rem; border-radius: 11px;
            font-family: inherit; font-size: .84rem; font-weight: 700;
            cursor: pointer; border: 1px solid transparent;
            text-decoration: none; white-space: nowrap;
            transition: background .18s ease, color .18s ease,
                        border-color .18s ease, box-shadow .18s ease,
                        transform .18s ease;
        }
        .avatar-lightbox-btn svg { width: 15px; height: 15px; flex-shrink: 0; }
        .avatar-lightbox-btn:focus-visible { outline: none; box-shadow: var(--ring); }

        .avatar-lightbox-btn.primary {
            background: var(--grad-brand); color: #fff;
            box-shadow: 0 4px 14px -6px rgba(37, 99, 235, .8);
        }
        .avatar-lightbox-btn.primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 22px -8px rgba(37, 99, 235, .95);
        }

        .avatar-lightbox-btn.danger {
            background: var(--white); color: var(--danger);
            border-color: #fecaca;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
        }
        .avatar-lightbox-btn.danger:hover {
            background: var(--danger); color: #fff;
            border-color: var(--danger);
            transform: translateY(-1px);
            box-shadow: 0 8px 22px -8px rgba(239, 68, 68, .85);
        }
        .avatar-lightbox-btn.danger:disabled {
            opacity: .6; cursor: wait; transform: none;
        }

        /* ============================================================
           SIDEBAR TOGGLE (fixed, beside sidebar)
           ============================================================ */
        .sidebar-toggle-btn--header {
            width: 32px; height: 32px; background: transparent; border: none;
            border-radius: 8px; color: #000; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; padding: 0;
            transition: color .2s ease, background .2s ease;
        }
        .sidebar-toggle-btn--header:hover { background: var(--surface-2); }
        .sidebar-toggle-btn--header:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

        .sidebar-toggle-btn--beside {
            position: fixed;
            left: calc(var(--sidebar-width) + 0.2rem);
            top: calc(var(--topbar-height) / 2 - 7px);
            z-index: 1001;
            width: 14px !important; height: 14px !important;
            min-width: 0 !important; min-height: 0 !important;
            padding: 0 !important; line-height: 0 !important;
            background: transparent !important; background-color: transparent !important;
            border: 0 !important; outline: 0 !important;
            box-shadow: none !important; border-radius: 0 !important;
            color: #000 !important; appearance: none; -webkit-appearance: none;
            transition: left var(--transition), color .2s ease;
        }
        .sidebar-toggle-btn--beside:hover,
        .sidebar-toggle-btn--beside:active,
        .sidebar-toggle-btn--beside:focus,
        .sidebar-toggle-btn--beside:focus-visible {
            background: transparent !important; border: 0 !important;
            outline: 0 !important; box-shadow: none !important; color: #000 !important;
        }
        body.sidebar-collapsed .sidebar-toggle-btn--beside {
            left: calc(var(--sidebar-collapsed-width) + 0.2rem);
        }

        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 1180px) {
            .search-trigger { max-width: 300px; }
            .uc-meta { display: none; }
            .user-chip { padding: .25rem; gap: 0; }
        }

        @media (max-width: 992px) {
            .search-trigger .st-label { display: none; }
            .search-trigger { max-width: 42px; padding: 0; justify-content: center; }
            .search-trigger kbd { display: none; }
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0 !important; }
            .appbar-inner { padding: 0 1rem; gap: .6rem; }
            .panel { right: .75rem; }
            .quick-btn span { display: none; }
            .quick-btn { width: 40px; padding: 0; justify-content: center; }
            .sidebar-toggle-btn--beside,
            body.sidebar-collapsed .sidebar-toggle-btn--beside { left: .2rem !important; }
            .crumbs { display: none; }
            .page-title { font-size: 1rem; }
        }

        @media (max-width: 480px) {
            .panel { width: calc(100vw - 1.5rem); right: .75rem; }
            .notif-panel { max-height: calc(100vh - 90px); }
            .avatar-lightbox { padding: 1rem; }
            .avatar-lightbox-card { padding: 1.65rem 1.25rem 1.25rem; border-radius: 20px; }
            .avatar-lightbox-media { width: 170px; height: 170px; font-size: 3.75rem; }
            .avatar-lightbox-actions .avatar-lightbox-btn { flex: 1 1 auto; }
        }

        @media (prefers-reduced-motion: reduce) {
            .panel, .user-chip, .user-chip .uc-chev, .icon-btn, .pp-avatar,
            .pp-camera, .pp-action, .np-icon, .notif-count, .avatar-lightbox,
            .avatar-lightbox-card, .avatar-lightbox-close, .avatar-lightbox-btn,
            .cmdk-overlay, .cmdk, .quick-btn, .search-trigger {
                animation: none !important; transition: none !important;
            }
        }
    </style>

    <link rel="stylesheet" href="includes/sidebar.css">

    <?php if (isset($extraStyles)) echo $extraStyles; ?>

    <script src="includes/poller.js" defer></script>
    <?php /* pdf.js removed from <head>: it blocked first paint on every page and
           hit the cdnjs CDN per request. footer.php lazy-loads the self-hosted
           copy (offline/pdf.min.js) only when the preview modal opens. */ ?>
    <script nonce="<?= csp_nonce() ?>">
    function timeAgoFromTimestamp(timestamp) {
        const now = Math.floor(Date.now() / 1000);
        const diff = now - timestamp;
        if (diff < 60) return 'Just now';
        if (diff < 120) return '1 minute ago';
        if (diff < 3600) return Math.floor(diff / 60) + ' minutes ago';
        if (diff < 7200) return '1 hour ago';
        if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
        if (diff < 172800) return '1 day ago';
        if (diff < 2592000) return Math.floor(diff / 86400) + ' days ago';
        if (diff < 5184000) return '1 month ago';
        if (diff < 31536000) return Math.floor(diff / 2592000) + ' months ago';
        if (diff < 63072000) return '1 year ago';
        return Math.floor(diff / 31536000) + ' years ago';
    }
    function updateRelativeTimes() {
        document.querySelectorAll('.relative-time').forEach(el => {
            const ts = parseInt(el.dataset.timestamp, 10);
            if (ts > 0) el.textContent = timeAgoFromTimestamp(ts);
        });
    }
    document.addEventListener('DOMContentLoaded', function() {
        updateRelativeTimes();
        setInterval(updateRelativeTimes, 10000);
    });
    </script>
    <script nonce="<?= csp_nonce() ?>">
    // bfcache guard: a page restored from back/forward cache must revalidate
    // with the server (config.php kills locked/expired sessions and redirects).
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) location.reload();
    });
    </script>
</head>

<body class="<?= $bodyClass ?? '' ?>">
    <script nonce="<?= csp_nonce() ?>">
        (function () {
            try {
                if (localStorage.getItem('sidebarCollapsed') === '1') {
                    document.body.classList.add('sidebar-collapsed');
                }
            } catch (e) {}
        })();
    </script>
    <?php include_once 'includes/sidebar.php'; ?>
    <button id="sidebarToggleBtn" class="sidebar-toggle-btn sidebar-toggle-btn--header sidebar-toggle-btn--beside" aria-label="Collapse sidebar" aria-expanded="true" title="Toggle sidebar">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
    </button>

    <main class="main-content">
        <!-- ============================================================
             APP BAR
             ============================================================ -->
        <header class="appbar" id="appbar">
            <div class="appbar-inner">

                <!-- LEFT: page heading + breadcrumbs -->
                <div class="appbar-left">
                    <div class="page-heading">
                     
                        <h1 class="page-title"><?= $pageTitle ?? 'Dashboard' ?></h1>
                    </div>
                </div>

                <!-- CENTER: search / command palette trigger -->
                <button type="button" class="search-trigger" id="searchTrigger"
                        aria-label="Open search (Ctrl+K)">
                    <?= render_icon('search') ?>
                    <span class="st-label"><?= (strtolower((string)($headerUserRole ?? '')) === 'staff') ? 'Search task' : 'Search documents, users, pages…' ?></span>
                    <kbd><span id="cmdkHintMod">Ctrl</span>K</kbd>
                </button>

                <!-- RIGHT: actions -->
                <div class="appbar-right">

                    <a href="<?= (in_array(strtolower((string)$headerUserRole ?? ''), ['department','unit_head'], true)) ? 'program_head_create_assign.php' : (strtolower((string)$headerUserRole) === 'teacher' || strtolower((string)$headerUserRole ?? '') === 'staff' ? 'teacher_tasks.php' : 'admin_department_requirements.php') ?>" class="quick-btn" title="<?= (strtolower((string)$headerUserRole ?? '') === 'teacher' || strtolower((string)$headerUserRole ?? '') === 'staff') ? 'Task' : 'New' ?>">
                        <?= render_icon('plus') ?>
                        <span><?= (strtolower((string)$headerUserRole ?? '') === 'teacher' || strtolower((string)$headerUserRole ?? '') === 'staff') ? 'Task' : 'New' ?></span>
                    </a>

                    <button class="icon-btn" id="notifBtn" data-tip="Notifications"
                            aria-label="Notifications" aria-haspopup="true" aria-expanded="false"
                            aria-controls="notifPanel">
                        <?= render_icon('bell') ?>
                        <?php if ($unreadCount > 0): ?>
                            <span class="notif-count" id="notifCountBadge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                        <?php endif; ?>
                    </button>

                    <script src="includes/websocket_client.js" nonce="<?= csp_nonce() ?>"></script>

                    <button type="button" class="user-chip" id="userMenuBtn"
                            aria-haspopup="true" aria-expanded="false" aria-controls="profileDropdown">
                        <span class="uc-avatar">
                            <span class="uc-avatar-inner">
                                <?php if (!empty($headerAvatarUrl)): ?>
                                    <img src="<?= htmlspecialchars($headerAvatarUrl, ENT_QUOTES, 'UTF-8') ?>"
                                         alt="<?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?> profile photo">
                                <?php else: ?>
                                    <?= htmlspecialchars($headerInitial, ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                            </span>
                        </span>
                        <span class="uc-meta">
                            <span class="uc-name"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="uc-role"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <svg class="uc-chev" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                             fill="none" stroke="currentColor" stroke-width="2.4"
                             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <polyline points="6 9 12 15 18 9"/>
                        </svg>
                    </button>
                </div>

                <!-- ============================================================
                     PROFILE PANEL
                     ============================================================ -->
                <div id="profileDropdown" class="panel profile-panel" role="menu" aria-label="User menu">
                    <div class="pp-cover">
                        <span class="pp-cover-grid" aria-hidden="true"></span>
                    </div>

                    <div class="pp-body">
                        <div class="pp-avatar-wrap">
                            <button type="button" class="pp-avatar" id="headerAvatarBtn"
                                    title="Click to view photo" aria-label="View profile photo">
                                <span class="pp-avatar-media">
                                    <?php if (!empty($headerAvatarUrl)): ?>
                                        <img src="<?= htmlspecialchars($headerAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <?php else: ?>
                                        <?= htmlspecialchars($headerInitial, ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </span>
                                <span class="pp-avatar-overlay" aria-hidden="true">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="11" cy="11" r="7"/>
                                        <path d="m21 21-4.3-4.3"/>
                                        <path d="M11 8v6"/>
                                        <path d="M8 11h6"/>
                                    </svg>
                                </span>
                            </button>
                            <span class="pp-camera" id="headerCameraBadge"
                                  role="button" tabindex="0"
                                  title="Upload new photo"
                                  aria-label="Upload new profile photo">
                                <?= render_icon('camera') ?>
                            </span>
                        </div>

                        <div class="pp-name"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if (!empty($headerUserEmail)): ?>
                            <div class="pp-email"><?= htmlspecialchars($headerUserEmail, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <span class="pp-role"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>


                    </div>

                    <div class="pp-actions">
                        <?php if (strtolower((string)$headerUserRole) === 'admin' || strtolower((string)$headerUserRole) === 'super_admin'): ?>
                            <a href="<?= strtolower((string)$headerUserRole) === 'super_admin' ? 'super_admin_settings.php' : 'admin_settings.php' ?>" class="pp-action" role="menuitem">
                                <?= render_icon('settings') ?> Account settings
                            </a>
                            <div class="pp-divider"></div>
                        <?php endif; ?>
                        <a href="logout.php" class="pp-action danger" role="menuitem">
                            <?= render_icon('logout') ?> Sign out
                        </a>
                    </div>

                    <!-- Hidden UPLOAD form -->
                    <form method="POST" enctype="multipart/form-data" id="headerAvatarForm"
                          style="display:none;" aria-hidden="true">
                        <?= function_exists('csrfField') ? csrfField() : '' ?>
                        <input type="hidden" name="action" value="header_upload_avatar">
                        <input type="file" id="headerAvatarInput" name="avatar"
                               accept="image/jpeg,image/png,image/webp,image/gif">
                    </form>

                    <!-- Hidden REMOVE form -->
                    <form method="POST" id="headerAvatarRemoveForm"
                          style="display:none;" aria-hidden="true">
                        <?= function_exists('csrfField') ? csrfField() : '' ?>
                        <input type="hidden" name="action" value="header_remove_avatar">
                    </form>
                </div>

                <!-- ============================================================
                     NOTIFICATION PANEL
                     ============================================================ -->
                <div id="notifPanel" class="panel notif-panel" role="dialog" aria-label="Notifications">
                    <div class="np-head">
                        <div class="np-title">
                            <?php if ($unreadCount > 0): ?><span class="np-dot" aria-hidden="true"></span><?php endif; ?>
                            Notifications
                        </div>
                        <?php if ($unreadCount > 0): ?>
                            <button type="button" class="np-mark" id="markAllRead">Mark all read</button>
                        <?php endif; ?>
                    </div>

                    <div class="np-tabs" role="tablist">
                        <button type="button" class="np-tab active" data-filter="all" role="tab">
                            All
                            <span class="np-tab-count" id="tabCountAll">0</span>
                        </button>
                        <button type="button" class="np-tab" data-filter="unread" role="tab">
                            Unread
                            <span class="np-tab-count" id="tabCountUnread"><?= (int)$unreadCount ?></span>
                        </button>
                    </div>

                    <div class="np-list" id="notifList">
                        <?php
                        if (isset($_SESSION['user_id'])) {
                            $recentNotifs = fetchRecentValidNotifications($pdo, $_SESSION['user_id'], 6);
                            $notifs = $recentNotifs;

                            if (empty($notifs)) {
                                echo '<div class="np-empty">
                                        <div class="np-empty-icon">' . render_icon('bell') . '</div>
                                        <p>You\'re all caught up</p>
                                        <small>No new updates right now</small>
                                      </div>';
                            } else {
                                foreach ($notifs as $n) {
                                    $iconName = $n['type'] === 'success' ? 'check-circle' : ($n['type'] === 'danger' ? 'x-circle' : 'info');
                                    if ($n['type'] === 'warning') $iconName = 'alert-triangle';

                                    $unreadClass = $n['is_read'] ? '' : 'unread';
                                    $isUnreadData = $n['is_read'] ? '0' : '1';
                                    $iconClass = $n['type'] === 'success' ? 'success' : ($n['type'] === 'danger' ? 'danger' : ($n['type'] === 'warning' ? 'warning' : 'info'));
                                    $title = sanitize($n['title']);
                                    $msg = sanitize(substr($n['message'], 0, 70)) . (strlen($n['message']) > 70 ? '...' : '');
                                    $timeUnix = $n['created_at'] && $n['created_at'] !== '0000-00-00 00:00:00' ? strtotime($n['created_at']) : 0;
                                    $time = $timeUnix > 0 ? "<span class=\"relative-time\" data-timestamp=\"$timeUnix\">" . timeAgo($n['created_at']) . "</span>" : 'Just now';

                                    echo "<a href='notifications.php' class='np-item $unreadClass' data-unread='$isUnreadData'>
                                            <span class='np-icon $iconClass'>" . render_icon($iconName) . "</span>
                                            <div class='np-content'>
                                                <div class='np-item-title'>$title</div>
                                                <div class='np-item-msg'>$msg</div>
                                                <div class='np-item-time'>$time</div>
                                            </div>
                                          </a>";
                                }
                            }
                        } else {
                            echo '<div class="np-empty"><p>Session expired</p><small>Please sign in again</small></div>';
                        }
                        ?>
                    </div>

                    <div class="np-foot">
                        <a href="notifications.php">
                            See all notifications
                            <?= render_icon('arrow-up-right') ?>
                        </a>
                    </div>
                </div>

            </div><!-- /.appbar-inner -->
        </header>

        <!-- ============================================================
             COMMAND PALETTE
             ============================================================ -->
        <?php
        $cmdkRole = strtolower((string)$headerUserRole);
        // One endpoint for every role; global_search.php does its own DB role check
        // and returns empty groups for roles it does not serve.
        $cmdkSearchUrl = 'global_search.php';
        ?>
        <div id="cmdkOverlay" class="cmdk-overlay" role="dialog" aria-modal="true" aria-label="Command palette">
            <div class="cmdk">
                <div class="cmdk-input-wrap">
                    <?= render_icon('search') ?>
                    <input type="text" class="cmdk-input" id="cmdkInput"
                           placeholder="<?= (in_array($cmdkRole, ['admin','department','teacher','unit_head'], true)) ? 'Search documents, requirements, accounts…' : ($cmdkRole === 'staff' ? 'Search task' : 'Type a command or search…') ?>" // shortcut: only staff gets 'Search task'; upgrade: add 'faculty'
                           autocomplete="off" spellcheck="false">
                    <span class="cmdk-esc">ESC</span>
                </div>
                <div class="cmdk-list" id="cmdkList">
                    <?php
                    $cmdkItems = [];
                    if ($cmdkRole === 'admin') {
                        $cmdkItems = [
                            ['Dashboard', 'admin_dashboard.php', 'home', 'Home'],
                            ['Department Requirements', 'admin_department_requirements.php', 'upload-cloud', ''],
                            ['Submissions', 'admin_submissions.php', 'folder', ''],
                            ['Manage Users', 'admin_manage_users.php', 'user', ''],
                            ['Analytics', 'admin_analytics.php', 'info', ''],
                            ['Audit Trail', 'admin_audit_trail.php', 'info', ''],
                            ['Notifications', 'notifications.php', 'bell', ''],
                        ];
                    } elseif ($cmdkRole === 'department' || $cmdkRole === 'unit_head') { // shortcut: unit_head = program head
                        $cmdkItems = [
                            ['Dashboard', 'program_head_dashboard.php', 'home', 'Home'],
                            ['Create & Assign', 'program_head_create_assign.php', 'upload-cloud', 'New'],
                            ['Pending', 'program_head_pending.php', 'folder', ''],
                            ['Review', 'program_head_review.php', 'info', ''],
                            ['Teachers', 'program_head_teachers.php', 'user', ''],
                            ['Repository', 'program_head_repository.php', 'folder', ''],
                            ['Notifications', 'notifications.php', 'bell', ''],
                        ];
                    } elseif ($cmdkRole === 'teacher') {
                        $cmdkItems = [
                            ['Dashboard', 'teacher_dashboard.php', 'home', 'Home'],
                            ['My Tasks', 'teacher_tasks.php', 'upload-cloud', 'Task'],
                            ['My Submissions', 'teacher_submissions.php', 'folder', ''],
                            ['Notifications', 'notifications.php', 'bell', ''],
                        ];
                    }
                    if ($cmdkRole === 'admin') $cmdkItems[] = ['Account Settings', 'admin_settings.php', 'settings', ''];
                    foreach ($cmdkItems as $item):
                    ?>
                    <a href="<?= htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8') ?>" class="cmdk-item">
                        <?= render_icon($item[2]) ?> <?= htmlspecialchars($item[0], ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($item[3]): ?><span class="cmdk-hint"><?= htmlspecialchars($item[3], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <div class="cmdk-list" id="cmdkResults" style="display:none;"
                     data-search-url="<?= $cmdkSearchUrl ?>"></div>
                <div class="cmdk-empty" id="cmdkEmpty" style="display:none;">
                    No results found
                </div>
            </div>
        </div>

        <!-- ============================================================
             AVATAR LIGHTBOX
             ============================================================ -->
        <div id="avatarLightbox" class="avatar-lightbox" role="dialog" aria-modal="true"
             aria-labelledby="avatarLightboxName" aria-hidden="true">
            <div class="avatar-lightbox-card">
                <button type="button" class="avatar-lightbox-close" id="avatarLightboxClose"
                        aria-label="Close preview">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 6 6 18"/><path d="m6 6 12 12"/>
                    </svg>
                </button>

                <div class="avatar-lightbox-media">
                    <?php if (!empty($headerAvatarUrl)): ?>
                        <img src="<?= htmlspecialchars($headerAvatarUrl, ENT_QUOTES, 'UTF-8') ?>"
                             alt="<?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?> profile photo">
                    <?php else: ?>
                        <?= htmlspecialchars($headerInitial, ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </div>

                <div class="avatar-lightbox-name" id="avatarLightboxName">
                    <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php if (!empty($headerUserEmail)): ?>
                    <div class="avatar-lightbox-email"><?= htmlspecialchars($headerUserEmail, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <span class="avatar-lightbox-role"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>

                <div class="avatar-lightbox-actions">
                    <button type="button" class="avatar-lightbox-btn primary" id="avatarLightboxChange">
                        <?= render_icon('camera') ?>
                        <?= !empty($headerAvatarUrl) ? 'Change Photo' : 'Upload Photo' ?>
                    </button>

                    <?php if ($hasAvatar): ?>
                        <button type="button" class="avatar-lightbox-btn danger" id="avatarLightboxRemove">
                            Remove
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="content-area">
            <script nonce="<?= csp_nonce() ?>">
(function () {
    'use strict';

    /* ---------- Element refs ---------- */
    var appbar        = document.getElementById('appbar');
    var userBtn       = document.getElementById('userMenuBtn');
    var profilePanel  = document.getElementById('profileDropdown');
    var notifBtn      = document.getElementById('notifBtn');
    var notifPanel    = document.getElementById('notifPanel');
    var avatarBtn     = document.getElementById('headerAvatarBtn');
    var cameraBadge   = document.getElementById('headerCameraBadge');
    var avatarInput   = document.getElementById('headerAvatarInput');
    var avatarForm    = document.getElementById('headerAvatarForm');
    var removeForm    = document.getElementById('headerAvatarRemoveForm');

    /* Lightbox */
    var lightbox      = document.getElementById('avatarLightbox');
    var lightboxClose = document.getElementById('avatarLightboxClose');
    var lightboxChange= document.getElementById('avatarLightboxChange');
    var lightboxRemove= document.getElementById('avatarLightboxRemove');
    var lastFocusedEl = null;

    /* Command palette */
    var searchTrigger = document.getElementById('searchTrigger');
    var cmdkOverlay   = document.getElementById('cmdkOverlay');
    var cmdkInput     = document.getElementById('cmdkInput');
    var cmdkList      = document.getElementById('cmdkList');
    var cmdkResults   = document.getElementById('cmdkResults');
    var cmdkEmpty     = document.getElementById('cmdkEmpty');

    /* ---------- Panel helpers ---------- */
    function closeProfile() {
        if (!profilePanel) return;
        profilePanel.classList.remove('show');
        if (userBtn) {
            userBtn.classList.remove('is-open');
            userBtn.setAttribute('aria-expanded', 'false');
        }
    }
    function closeNotif() {
        if (!notifPanel) return;
        notifPanel.classList.remove('show');
        if (notifBtn) notifBtn.setAttribute('aria-expanded', 'false');
    }
    function closeAllPanels() { closeProfile(); closeNotif(); }

    /* ---------- Profile toggle ---------- */
    if (userBtn && profilePanel) {
        userBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            closeNotif();
            var open = profilePanel.classList.toggle('show');
            userBtn.classList.toggle('is-open', open);
            userBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    /* ---------- Notifications toggle ---------- */
    if (notifBtn && notifPanel) {
        notifBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            closeProfile();
            var open = notifPanel.classList.toggle('show');
            notifBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    /* ---------- Notification tabs ---------- */
    var notifList = document.getElementById('notifList');
    var tabCountAll = document.getElementById('tabCountAll');
    var tabCountUnread = document.getElementById('tabCountUnread');

    function refreshNotifCounts() {
        if (!notifList) return;
        var items  = notifList.querySelectorAll('.np-item');
        var unread = notifList.querySelectorAll('.np-item[data-unread="1"]');
        if (tabCountAll)    tabCountAll.textContent = items.length;
        if (tabCountUnread) tabCountUnread.textContent = unread.length;
    }
    refreshNotifCounts();

    document.querySelectorAll('.np-tab').forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            e.stopPropagation();
            document.querySelectorAll('.np-tab').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');

            var filter = tab.dataset.filter;
            if (!notifList) return;
            notifList.querySelectorAll('.np-item').forEach(function (item) {
                var isUnread = item.dataset.unread === '1';
                item.style.display = (filter === 'unread' && !isUnread) ? 'none' : '';
            });
        });
    });

    /* ---------- Mark all read (optimistic + server sync) ---------- */
    var markAllBtn = document.getElementById('markAllRead');
    if (markAllBtn && notifList) {
        markAllBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            var csrfMeta = document.querySelector('meta[name="csrf-token"]');
            var csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
            fetch('notifications.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_read&csrf_token=' + encodeURIComponent(csrfToken)
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                // shortcut: assume server cleared DB; refresh count from response or default 0
                var count = (data && data.unread_count !== undefined) ? data.unread_count : 0;
                notifList.querySelectorAll('.np-item.unread').forEach(function (item) {
                    item.classList.remove('unread');
                    item.dataset.unread = '0';
                });
                var badge = document.getElementById('notifCountBadge');
                if (badge) badge.remove();
                var dot = document.querySelector('.np-dot');
                if (dot) dot.remove();
                markAllBtn.remove();
                refreshNotifCounts();
                // Sync header session cache by forcing a fresh count fetch
                var tabUnread = document.getElementById('tabCountUnread');
                if (tabUnread) tabUnread.textContent = count;
            })
            .catch(function (err) { console.error('notif sync failed, retrying:', err); setTimeout(function () { fetch('notifications.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'action=mark_all_read&csrf_token=' + encodeURIComponent((document.querySelector('meta[name="csrf-token"]') || {getAttribute:function(){return'';}}).getAttribute('content')) }); }, 500); var badge=document.getElementById('notifCountBadge'); if(badge) badge.remove(); var dot=document.querySelector('.np-dot'); if(dot) dot.remove(); refreshNotifCounts(); var tabUnread=document.getElementById('tabCountUnread'); if(tabUnread) tabUnread.textContent='0'; /* shortcut: one retry; ceiling: brief server failure leaves session stale */ });
        });
    }

    /* ---------- AVATAR LIGHTBOX ---------- */
    function openLightbox() {
        if (!lightbox) return;
        lastFocusedEl = document.activeElement;
        closeAllPanels();
        lightbox.classList.add('show');
        lightbox.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (lightboxClose) setTimeout(function () { lightboxClose.focus(); }, 30);
    }
    function closeLightbox() {
        if (!lightbox) return;
        lightbox.classList.remove('show');
        lightbox.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        if (lastFocusedEl && typeof lastFocusedEl.focus === 'function') {
            setTimeout(function () { lastFocusedEl.focus(); }, 30);
        }
    }

    if (avatarBtn) {
        avatarBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            openLightbox();
        });
    }

    if (cameraBadge && avatarInput) {
        cameraBadge.addEventListener('click', function (e) {
            e.stopPropagation(); e.preventDefault();
            avatarInput.click();
        });
        cameraBadge.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault(); e.stopPropagation();
                avatarInput.click();
            }
        });
    }

    if (lightboxChange && avatarInput) {
        lightboxChange.addEventListener('click', function (e) {
            e.stopPropagation();
            avatarInput.click();
        });
    }

    if (lightboxRemove && removeForm) {
        lightboxRemove.addEventListener('click', function (e) {
            e.stopPropagation();
            if (!confirm('Remove your profile photo?')) return;
            lightboxRemove.disabled = true;
            lightboxRemove.textContent = 'Removing…';
            removeForm.submit();
        });
    }

    if (lightboxClose) lightboxClose.addEventListener('click', closeLightbox);
    if (lightbox) {
        lightbox.addEventListener('click', function (e) {
            if (e.target === lightbox) closeLightbox();
        });
    }

    /* Auto-submit upload once a file is picked */
    if (avatarInput && avatarForm) {
        avatarInput.addEventListener('change', function () {
            if (avatarInput.files && avatarInput.files.length > 0) avatarForm.submit();
        });
    }

    /* ---------- COMMAND PALETTE ---------- */
    var cmdkItems = [];
    function collectCmdkItems() {
        cmdkItems = Array.prototype.slice.call(document.querySelectorAll('#cmdkList .cmdk-item, #cmdkResults .cmdk-item'));
    }
    collectCmdkItems();
    var cmdkNav = function () {
        return cmdkItems.filter(function (i) { return !cmdkResults || !cmdkResults.contains(i); });
    };

    function openCmdk() {
        if (!cmdkOverlay) return;
        closeAllPanels();
        cmdkOverlay.classList.add('show');
        document.body.style.overflow = 'hidden';
        setTimeout(function () { if (cmdkInput) cmdkInput.focus(); }, 30);
    }
    function closeCmdk() {
        if (!cmdkOverlay) return;
        cmdkOverlay.classList.remove('show');
        document.body.style.overflow = '';
        if (cmdkInput) cmdkInput.value = '';
        clearCmdkResults();
        filterCmdk('');
        highlightIndex = -1;
    }

    var highlightIndex = -1;
    function filterCmdk(q) {
        q = (q || '').trim().toLowerCase();
        var visible = 0;
        cmdkNav().forEach(function (item) {
            var text = item.textContent.toLowerCase();
            var show = !q || text.indexOf(q) !== -1;
            item.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        var hasResults = cmdkResults && cmdkResults.style.display !== 'none';
        if (cmdkEmpty) cmdkEmpty.style.display = (visible || hasResults) ? 'none' : 'block';
        highlightIndex = -1;
    }

    function highlightCmdk(dir) {
        var visible = cmdkItems.filter(function (i) { return i.offsetParent !== null; });
        if (!visible.length) return;
        visible.forEach(function (i) { i.classList.remove('is-active'); });
        highlightIndex += dir;
        if (highlightIndex < 0) highlightIndex = visible.length - 1;
        if (highlightIndex >= visible.length) highlightIndex = 0;
        visible[highlightIndex].classList.add('is-active');
        visible[highlightIndex].scrollIntoView({ block: 'nearest' });
    }

    /* ---------- Server search: approved documents (repository + archive) + program heads ---------- */
    var cmdkUrl   = cmdkResults ? cmdkResults.getAttribute('data-search-url') : null;
    var cmdkTimer = null;
    var cmdkSeq   = 0;

    function escCmdk(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
    function clearCmdkResults() {
        if (!cmdkResults) return;
        cmdkResults.innerHTML = '';
        cmdkResults.style.display = 'none';
        cmdkList.style.display = '';
        collectCmdkItems();
    }
    function renderCmdkResults(data) {
        if (!cmdkResults) return;
        var html = '', n = 0;
        (data.groups || []).forEach(function (g) {
            if (!g.items || !g.items.length) return;
            html += '<div class="cmdk-group-label">' + escCmdk(g.label) + '</div>';
            g.items.forEach(function (it) {
                n++;
                html += '<a class="cmdk-item" href="' + escCmdk(it.url) + '">' +
                        escCmdk(it.title) +
                        (it.hint ? '<span class="cmdk-hint">' + escCmdk(it.hint) + '</span>' : '') +
                        '</a>';
            });
        });
        cmdkResults.innerHTML = html;
        if (n) {
            cmdkResults.style.display = '';
            cmdkList.style.display = 'none';
            if (cmdkEmpty) cmdkEmpty.style.display = 'none';
        } else {
            clearCmdkResults();
            filterCmdk(cmdkInput ? cmdkInput.value : '');
        }
        collectCmdkItems();
        highlightIndex = -1;
    }
    function runCmdkSearch(q) {
        if (!cmdkUrl) return;
        q = (q || '').trim();
        clearTimeout(cmdkTimer);
        if (q.length < 2) { clearCmdkResults(); return; }
        var seq = ++cmdkSeq;
        cmdkTimer = setTimeout(function () {
            fetch(cmdkUrl + '?q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) { if (seq === cmdkSeq) renderCmdkResults(data); })
                .catch(function () { });
        }, 200);
    }

    if (searchTrigger) {
        searchTrigger.addEventListener('click', function (e) {
            e.stopPropagation();
            openCmdk();
        });
    }
    if (cmdkInput) {
        cmdkInput.addEventListener('input', function () {
            filterCmdk(cmdkInput.value);
            runCmdkSearch(cmdkInput.value);
        });
        cmdkInput.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); highlightCmdk(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); highlightCmdk(-1); }
            else if (e.key === 'Enter') {
                var active = document.querySelector('#cmdkResults .cmdk-item.is-active, #cmdkList .cmdk-item.is-active') ||
                             document.querySelector('#cmdkResults .cmdk-item:not([style*="display: none"])') ||
                             document.querySelector('#cmdkList .cmdk-item:not([style*="display: none"])');
                if (active && active.href) { window.location.href = active.href; }
            }
        });
    }
    if (cmdkOverlay) {
        cmdkOverlay.addEventListener('click', function (e) {
            if (e.target === cmdkOverlay) closeCmdk();
        });
    }

    /* ---------- Global key handling ---------- */
    document.addEventListener('keydown', function (e) {
        /* Ctrl/Cmd + K → command palette */
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            if (cmdkOverlay && cmdkOverlay.classList.contains('show')) closeCmdk();
            else openCmdk();
            return;
        }
        if (e.key === 'Escape') {
            if (cmdkOverlay && cmdkOverlay.classList.contains('show')) { closeCmdk(); return; }
            if (lightbox && lightbox.classList.contains('show')) { closeLightbox(); return; }
            closeAllPanels();
        }
    });

    /* Show correct modifier hint on Mac */
    if (navigator.platform && /Mac|iPhone|iPad/.test(navigator.platform)) {
        var modHint = document.getElementById('cmdkHintMod');
        if (modHint) modHint.textContent = '⌘';
    }

    /* ---------- Outside click closes panels ---------- */
    document.addEventListener('click', function (e) {
        if (profilePanel && !profilePanel.contains(e.target) && userBtn && !userBtn.contains(e.target)) {
            closeProfile();
        }
        if (notifPanel && !notifPanel.contains(e.target) && notifBtn && !notifBtn.contains(e.target)) {
            closeNotif();
        }
    });

    /* ---------- Scroll shadow ---------- */
    function syncAppbar() {
        if (!appbar) return;
        appbar.classList.toggle('is-scrolled', window.scrollY > 4);
    }
    window.addEventListener('scroll', syncAppbar, { passive: true });
    syncAppbar();
})();
            </script>