<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}
/**
 * Avatar / photo upload helpers.
 * Shared by includes/header.php and admin_settings.php
 */

if (!defined('CHED_APP')) {
    exit;
}

if (!defined('AVATAR_UPLOAD_DIR')) {
    define('AVATAR_UPLOAD_DIR', dirname(__DIR__) . '/uploads/avatars');
}
if (!defined('AVATAR_UPLOAD_URL')) {
    define('AVATAR_UPLOAD_URL', 'uploads/avatars');
}
if (!defined('AVATAR_MAX_BYTES')) {
    define('AVATAR_MAX_BYTES', 2 * 1024 * 1024); // 2 MB
}
if (!defined('AVATAR_ALLOWED_MIME')) {
    define('AVATAR_ALLOWED_MIME', [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ]);
}

/**
 * Detect (once) whether users.avatar exists. Returns false instead of throwing,
 * so the app keeps working before the migration is applied.
 */
if (!function_exists('usersHasAvatarColumn')) {
    function usersHasAvatarColumn(PDO $pdo): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        $has = false;
        try {
            $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar'");
                $has = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
            } elseif ($driver === 'sqlite') {
                $stmt = $pdo->query("PRAGMA table_info(users)");
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
                    if (isset($col['name']) && strtolower((string) $col['name']) === 'avatar') {
                        $has = true;
                        break;
                    }
                }
            } else {
                // Postgres / MSSQL / other: probe with a zero-row select.
                $pdo->query('SELECT avatar FROM users LIMIT 0');
                $has = true;
            }
        } catch (PDOException $e) {
            $has = false;
        }
        return $has;
    }
}

/**
 * SQL fragment to safely select the avatar column whether or not it exists.
 * Usage: "SELECT id, name, " . userAvatarColumnExpr($pdo) . " AS avatar FROM users"
 */
if (!function_exists('userAvatarColumnExpr')) {
    function userAvatarColumnExpr(PDO $pdo): string
    {
        return usersHasAvatarColumn($pdo) ? 'avatar' : 'NULL';
    }
}

/**
 * Create the upload directory (and harden it for Apache) if needed.
 */
if (!function_exists('avatarEnsureDir')) {
    function avatarEnsureDir(): bool
    {
        $dir = AVATAR_UPLOAD_DIR;

        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return false;
            }
        }

        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            $rules = "Options -Indexes -ExecCGI\n"
                   . "php_flag engine off\n"
                   . "<FilesMatch \"\\.(php|phtml|phar|php[0-9]|pl|py|cgi|asp|aspx|jsp|sh|htaccess)$\">\n"
                   . "    Require all denied\n"
                   . "</FilesMatch>\n";
            @file_put_contents($htaccess, $rules);
        }

        return is_dir($dir) && is_writable($dir);
    }
}

/**
 * Public URL for a stored avatar path, with a cache-busting query string.
 * Returns null when the file is missing or the path is outside the uploads dir.
 */
if (!function_exists('avatarUrl')) {
    function avatarUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (strpos($path, AVATAR_UPLOAD_URL . '/') !== 0) {
            return null;
        }
        if (strpos($path, '..') !== false) {
            return null;
        }
        $full = dirname(__DIR__) . '/' . $path;
        if (!is_file($full)) {
            return null;
        }
        $ver = @filemtime($full) ?: time();
        return $path . '?v=' . $ver;
    }
}

/**
 * Delete a stored avatar file (never leaves the avatars directory).
 */
if (!function_exists('avatarDelete')) {
    function avatarDelete(?string $path): void
    {
        if (!$path) {
            return;
        }
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (strpos($path, AVATAR_UPLOAD_URL . '/') !== 0 || strpos($path, '..') !== false) {
            return;
        }
        $base = realpath(AVATAR_UPLOAD_DIR);
        $full = realpath(dirname(__DIR__) . '/' . $path);
        if ($base && $full && strpos($full, $base) === 0 && is_file($full)) {
            @unlink($full);
        }
    }
}

/**
 * Validate + store an uploaded image.
 *
 * @return array{ok:bool, path:?string, error:?string}
 */
if (!function_exists('avatarUpload')) {
    function avatarUpload(array $file, int $uid = 0, string $name = ''): array
    {
        $fail = static function (string $msg): array {
            return ['ok' => false, 'path' => null, 'error' => $msg];
        };

        if (!isset($file['error']) || is_array($file['error'])) {
            return $fail('Invalid upload.');
        }

        switch ((int) $file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                return $fail('Please choose a photo to upload.');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return $fail('The image is too large. Maximum size is 2 MB.');
            case UPLOAD_ERR_PARTIAL:
                return $fail('The upload was interrupted. Please try again.');
            default:
                return $fail('Upload failed. Please try again.');
        }

        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            return $fail('Invalid upload.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > AVATAR_MAX_BYTES) {
            return $fail('The image must be 2 MB or smaller.');
        }

        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return $fail('That file is not a valid image.');
        }

        $mime = isset($info['mime']) ? strtolower((string) $info['mime']) : '';
        $allowed = AVATAR_ALLOWED_MIME;
        if (!isset($allowed[$mime])) {
            return $fail('Only JPG, PNG, WEBP or GIF images are allowed.');
        }

        $width  = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 16 || $height < 16) {
            return $fail('The image is too small (minimum 16×16 pixels).');
        }
        if ($width > 8000 || $height > 8000) {
            return $fail('The image dimensions are too large (maximum 8000×8000).');
        }

        if (!avatarEnsureDir()) {
            return $fail('The upload folder is not writable. Please contact your administrator.');
        }

        $ext = $allowed[$mime];

        // File is named after the uploader: "<Name>_<uid>.<ext>".
        // The uid keeps two users with the same name from sharing one file.
        // ponytail: deterministic name = same user re-upload overwrites in place;
        // callers MUST skip deleting the old avatar when old path === new path,
        // or the fresh upload gets unlinked. Random-name scheme was dropped for
        // readability; restore it if overwrite-in-place ever causes stale-CDN issues.
        $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $name);
        if (!$safeName) {
            $safeName = 'avatar';
        }
        $filename = substr($safeName, 0, 60) . '_' . max(1, (int) $uid) . '.' . $ext;
        $dest     = rtrim(AVATAR_UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (!@move_uploaded_file($file['tmp_name'], $dest)) {
            return $fail('Could not save the uploaded image.');
        }
        @chmod($dest, 0644);

        return [
            'ok'    => true,
            'path'  => AVATAR_UPLOAD_URL . '/' . $filename,
            'error' => null,
        ];
    }
}
