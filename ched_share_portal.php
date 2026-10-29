<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

define('CHED_APP', true);
require_once __DIR__ . '/config.php';

/* ---------- CSP nonce (for inline <script>) ---------- */
$cspNonce  = defined('CSP_NONCE') ? CSP_NONCE : '';
$nonceAttr = $cspNonce !== '' ? ' nonce="' . htmlspecialchars($cspNonce, ENT_QUOTES, 'UTF-8') . '"' : '';

header('Cache-Control: no-store, must-revalidate');
header('Pragma: no-cache');

function deny_share(int $httpCode, string $message, string $title = 'Access Denied'): void
{
    http_response_code($httpCode);
    header('Content-Type: text/html; charset=utf-8');
    $safe      = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $titleSafe = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$titleSafe}</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',system-ui,-apple-system,sans-serif;background:#f6f1e8;min-height:100vh;
display:flex;align-items:center;justify-content:center;color:#0f1720;padding:16px;
background-image:radial-gradient(800px 500px at 100% 0%,rgba(37,99,235,.08),transparent 60%),
radial-gradient(700px 500px at -10% 30%,rgba(245,158,11,.09),transparent 60%)}
.card{max-width:520px;background:#fffefb;border:1px solid #e6ddcc;border-radius:22px;
box-shadow:0 30px 80px -20px rgba(15,23,32,.25),0 8px 20px -8px rgba(15,23,32,.12);padding:40px 40px}
.icon{width:56px;height:56px;border-radius:16px;background:linear-gradient(135deg,#fee2e2,#fecaca);color:#dc2626;
display:inline-flex;align-items:center;justify-content:center;margin-bottom:20px;font-size:26px;
box-shadow:0 10px 24px -8px rgba(220,38,38,.35)}
h1{margin:0 0 12px;font-family:'Fraunces',Georgia,serif;font-size:1.6rem;font-weight:700;letter-spacing:-.02em;color:#0f1720}
p{margin:0;color:#5e6b7a;line-height:1.65;font-size:.95rem}
</style></head><body><div class="card">
<div class="icon">⚠</div><h1>{$titleSafe}</h1><p>{$safe}</p></div></body></html>
HTML;
    exit;
}

function utc_to_local(string $utc, string $fmt = 'M d, Y g:i A'): string
{
    try {
        $d = new DateTime($utc, new DateTimeZone('UTC'));
        $d->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $d->format($fmt);
    } catch (Throwable $e) { return $utc; }
}

function request_is_https(): bool
{
    $xfp = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')[0]));
    return $xfp === 'https'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function assert_link_usable(PDO $pdo, array $link, bool $isReturningVisitor = false): void
{
    if ($link['status'] === 'revoked') {
        deny_share(403,
            'This CHED document-sharing link has been revoked by the administrator. Please contact the system administrator for a new link.',
            'Link Revoked');
    }
    $expUtc = new DateTime($link['expires_at'], new DateTimeZone('UTC'));
    $nowUtc = new DateTime('now', new DateTimeZone('UTC'));
    if ($link['status'] === 'expired' || $expUtc <= $nowUtc) {
        try {
            $pdo->prepare("UPDATE ched_share_links SET status='expired'
                           WHERE status='active' AND expires_at <= UTC_TIMESTAMP()")
                ->execute();
        } catch (Throwable $e) {}
        deny_share(410,
            'This CHED document-sharing link has expired. Please contact the system administrator for a new link.',
            'Link Expired');
    }
    if ($link['status'] === 'full'
        || ($link['max_visitors'] !== null && (int)$link['visitor_count'] >= (int)$link['max_visitors'])) {
        if (!$isReturningVisitor) {
            deny_share(429,
                'This CHED document-sharing link has reached its maximum number of allowed visitors. Please contact the system administrator for a new link.',
                'Link Full');
        }
    }
}

function fetch_all_departments(PDO $pdo): array
{
    return $pdo->query("
        SELECT MIN(id) AS id, department_name, department_code
        FROM departments
        GROUP BY department_name
        ORDER BY department_name
    ")->fetchAll();
}

function fetch_requirement_docs(PDO $pdo, int $deptId, array $req): array
{
    $docs = [];
    $trStmt = $pdo->prepare("SELECT id FROM teacher_requirements WHERE requirement_id = ?");
    $trStmt->execute([$req['id']]);
    $trIds = $trStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($trIds)) {
        $ph = implode(',', array_fill(0, count($trIds), '?'));
        $stmt = $pdo->prepare("
            SELECT id, filename, title, file_path, folder_id, upload_date,
                   'document' AS source
            FROM documents
            WHERE teacher_requirement_id IN ($ph)
              AND status = 'approved_admin'
              AND archived = 0
            ORDER BY upload_date DESC
        ");
        $stmt->execute($trIds);
        $docs = array_merge($docs, $stmt->fetchAll());
    }

    if (!empty($req['category']) && !empty($req['document_type'])) {
        $stmt = $pdo->prepare("
            SELECT id, filename, title, file_path, folder_id, upload_date,
                   'document' AS source
            FROM documents
            WHERE department_id = ?
              AND category = ?
              AND document_type = ?
              AND teacher_requirement_id IS NULL
              AND status = 'approved_admin'
              AND archived = 0
              AND folder_id IS NULL
            ORDER BY upload_date DESC
        ");
        $stmt->execute([$deptId, $req['category'], $req['document_type']]);
        $docs = array_merge($docs, $stmt->fetchAll());
    }
    return $docs;
}

function build_tree_node(PDO $pdo, int $deptId, ?int $folderId, array &$seenDocIds): array
{
    $isRoot = ($folderId === null);
    $folders = [];
    $subSql = "SELECT id, folder_name
               FROM department_folders
               WHERE department_id = ?
                 AND parent_folder_id " . ($isRoot ? "IS NULL" : "= ?") . "
                 AND LOWER(folder_name) <> 'root'
               ORDER BY folder_name";
    $subParams = [$deptId];
    if (!$isRoot) $subParams[] = $folderId;
    $subStmt = $pdo->prepare($subSql);
    $subStmt->execute($subParams);
    foreach ($subStmt->fetchAll() as $sf) {
        $folders[] = [
            'id'       => (int)$sf['id'],
            'name'     => $sf['folder_name'],
            'children' => build_tree_node($pdo, $deptId, (int)$sf['id'], $seenDocIds),
        ];
    }

    if ($isRoot) {
        $reqStmt = $pdo->prepare("
            SELECT * FROM department_requirements
            WHERE department_id = ?
              AND (folder_id IS NULL OR folder_id = 0)
            ORDER BY document_type, category
        ");
        $reqStmt->execute([$deptId]);
    } else {
        $reqStmt = $pdo->prepare("
            SELECT * FROM department_requirements
            WHERE department_id = ? AND folder_id = ?
            ORDER BY document_type, category
        ");
        $reqStmt->execute([$deptId, $folderId]);
    }

    $requirements = [];
    foreach ($reqStmt->fetchAll() as $req) {
        $docs = fetch_requirement_docs($pdo, $deptId, $req);
        $filtered = [];
        foreach ($docs as $d) {
            $key = $d['source'] . '_' . $d['id'];
            if (isset($seenDocIds[$key])) continue;
            $seenDocIds[$key] = true;
            $filtered[] = $d;
        }
        if (empty($filtered)) continue;
        $labelRaw       = trim((string)($req['document_type'] ?? ''));
        $isDirectUpload = ($labelRaw === '' || strcasecmp($labelRaw, 'Direct Upload') === 0);
        $requirements[] = [
            'id'               => (int)$req['id'],
            'label'            => $isDirectUpload ? '' : $labelRaw,
            'is_direct_upload' => $isDirectUpload,
            'documents'        => $filtered,
        ];
    }

    $phGroups     = [];
    $phDirectDocs = [];
    if ($isRoot) {
        $phStmt = $pdo->prepare("
            SELECT pd.id, pd.filename, pd.title, pd.file_path, pd.upload_date,
                   phr.title AS requirement_title,
                   'ph_document' AS source
            FROM ph_documents pd
            JOIN teacher_ph_requirements tpr ON pd.teacher_ph_requirement_id = tpr.id
            JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id
            WHERE pd.department_id = ?
              AND pd.status = 'approved_admin'
              AND pd.archived = 0
            ORDER BY phr.title, pd.upload_date DESC
        ");
        $phStmt->execute([$deptId]);
        foreach ($phStmt->fetchAll() as $doc) {
            $key = 'ph_document_' . $doc['id'];
            if (isset($seenDocIds[$key])) continue;
            $seenDocIds[$key] = true;
            $title = trim((string)($doc['requirement_title'] ?? ''));
            if ($title === '' || strcasecmp($title, 'Direct Upload') === 0) {
                $title = 'PH Documents';
            }
            if (!isset($phGroups[$title])) $phGroups[$title] = [];
            $phGroups[$title][] = $doc;
        }

        $phDirectStmt = $pdo->prepare("
            SELECT id, filename, title, file_path, upload_date, 'document' AS source
            FROM documents
            WHERE department_id = ?
              AND category = 'PH'
              AND document_type = 'Direct Upload'
              AND teacher_requirement_id IS NULL
              AND status = 'approved_admin'
              AND archived = 0
              AND (folder_id IS NULL OR folder_id = 0)
            ORDER BY upload_date DESC
        ");
        $phDirectStmt->execute([$deptId]);
        foreach ($phDirectStmt->fetchAll() as $doc) {
            $key = 'document_' . $doc['id'];
            if (isset($seenDocIds[$key])) continue;
            $seenDocIds[$key] = true;
            $phDirectDocs[] = $doc;
        }
    }

    $pickupDocs     = [];
    $pickupFolderId = $isRoot ? 0 : $folderId;
    $pickupStmt = $pdo->prepare("
        SELECT id, filename, title, file_path, upload_date, 'document' AS source
        FROM documents
        WHERE department_id = ?
          AND teacher_requirement_id IS NULL
          AND status = 'approved_admin'
          AND archived = 0
          AND folder_id = ?
        ORDER BY upload_date DESC
    ");
    $pickupStmt->execute([$deptId, $pickupFolderId]);
    foreach ($pickupStmt->fetchAll() as $doc) {
        $key = 'document_' . $doc['id'];
        if (isset($seenDocIds[$key])) continue;
        $seenDocIds[$key] = true;
        $pickupDocs[] = $doc;
    }

    return [
        'folders'        => $folders,
        'requirements'   => $requirements,
        'pickup_docs'    => $pickupDocs,
        'ph_groups'      => $phGroups,
        'ph_direct_docs' => $phDirectDocs,
    ];
}

function count_node_docs(array $node): int
{
    $n = 0;
    foreach ($node['requirements'] as $r) $n += count($r['documents']);
    foreach ($node['folders'] as $f)      $n += count_node_docs($f['children']);
    $n += count($node['pickup_docs']);
    $n += count($node['ph_direct_docs']);
    foreach ($node['ph_groups'] as $docs) $n += count($docs);
    return $n;
}

function build_breadcrumb(PDO $pdo, int $deptId, int $folderId): array
{
    if ($folderId === 0) return [];
    $parts = []; $seen = []; $id = $folderId;
    while ($id && !isset($seen[$id])) {
        $seen[$id] = true;
        $stmt = $pdo->prepare("SELECT id, folder_name, parent_folder_id FROM department_folders WHERE id = ? AND department_id = ?");
        $stmt->execute([$id, $deptId]);
        $f = $stmt->fetch();
        if (!$f) break;
        array_unshift($parts, ['id' => (int)$f['id'], 'name' => $f['folder_name']]);
        $id = (int)$f['parent_folder_id'];
    }
    return $parts;
}

/* =============================================================================
   Global search
   ============================================================================= */
function search_shared_documents(PDO $pdo, ?int $scopeDeptId, string $query, int $limit = 30): array
{
    $q = trim($query);
    if ($q === '') return [];

    $lower = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $lower) . '%';

    $results = [];
    $seen    = [];

    // ---- documents table ----
    $sql = "SELECT d.id, d.filename, d.title, d.department_id, d.folder_id, 'document' AS source
            FROM documents d
            WHERE d.status = 'approved_admin'
              AND d.archived = 0
              AND (LOWER(d.filename) LIKE ? ESCAPE '\\\\' OR LOWER(d.title) LIKE ? ESCAPE '\\\\')";
    $params = [$needle, $needle];
    if ($scopeDeptId !== null) { $sql .= " AND d.department_id = ?"; $params[] = $scopeDeptId; }
    $sql .= " ORDER BY d.upload_date DESC LIMIT " . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $k = $row['source'] . ':' . $row['id'];
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $results[] = $row;
    }

    // ---- ph_documents table ----
    $sql = "SELECT pd.id, pd.filename, pd.title, pd.department_id, NULL AS folder_id, 'ph_document' AS source
            FROM ph_documents pd
            WHERE pd.status = 'approved_admin'
              AND pd.archived = 0
              AND (LOWER(pd.filename) LIKE ? ESCAPE '\\\\' OR LOWER(pd.title) LIKE ? ESCAPE '\\\\')";
    $params = [$needle, $needle];
    if ($scopeDeptId !== null) { $sql .= " AND pd.department_id = ?"; $params[] = $scopeDeptId; }
    $sql .= " ORDER BY pd.upload_date DESC LIMIT " . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $k = $row['source'] . ':' . $row['id'];
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $results[] = $row;
    }

    // Enrich with department name + folder path
    $deptCache = [];
    $folderCache = [];
    foreach ($results as &$r) {
        $did = (int)$r['department_id'];
        if (!isset($deptCache[$did])) {
            $s = $pdo->prepare("SELECT department_name FROM departments WHERE id = ? LIMIT 1");
            $s->execute([$did]);
            $deptCache[$did] = (string)($s->fetchColumn() ?: '');
        }
        $r['dept_name'] = $deptCache[$did];

        $r['folder_id']   = $r['folder_id'] !== null ? (int)$r['folder_id'] : 0;
        $r['folder_path'] = '';
        if ($r['folder_id'] > 0) {
            $path = [];
            $fid = $r['folder_id'];
            $guard = 0;
            while ($fid && $guard++ < 50) {
                if (isset($folderCache[$fid])) {
                    $cached = $folderCache[$fid];
                    array_unshift($path, $cached['name']);
                    $fid = $cached['parent'];
                    continue;
                }
                $s = $pdo->prepare("SELECT folder_name, parent_folder_id FROM department_folders WHERE id = ? AND department_id = ? LIMIT 1");
                $s->execute([$fid, $did]);
                $f = $s->fetch();
                if (!$f) break;
                $folderCache[$fid] = ['name' => $f['folder_name'], 'parent' => (int)$f['parent_folder_id']];
                array_unshift($path, $f['folder_name']);
                $fid = (int)$f['parent_folder_id'];
            }
            $r['folder_path'] = implode(' / ', $path);
        }

        $displayName = trim((string)($r['title'] ?? ''));
        if ($displayName === '' || strcasecmp($displayName, 'Direct Upload') === 0) {
            $displayName = (string)($r['filename'] ?? '');
        }
        $r['display_name'] = $displayName !== '' ? $displayName : 'Untitled';
    }
    unset($r);

    return $results;
}

/* =============================================================================
   SVG icons
   ============================================================================= */
function icon_folder_v2(int $size = 24): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 48 48" aria-hidden="true">'
         .   '<defs><linearGradient id="fg_' . $size . '" x1="0" y1="0" x2="0" y2="1">'
         .     '<stop offset="0" stop-color="#fbbf24"/><stop offset="1" stop-color="#f59e0b"/>'
         .   '</linearGradient></defs>'
         .   '<path d="M6 12a3 3 0 0 1 3-3h11.6a3 3 0 0 1 2.5 1.4l1.6 2.3a3 3 0 0 0 2.5 1.3H39a3 3 0 0 1 3 3v2H6v-7z" fill="url(#fg_' . $size . ')"/>'
         .   '<path d="M6 17h36v20a3 3 0 0 1-3 3H9a3 3 0 0 1-3-3V17z" fill="#fbbf24"/>'
         .   '<path d="M6 17h36v2H6z" fill="#f59e0b" opacity=".35"/>'
         . '</svg>';
}

function icon_doc_v2(int $size = 22): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         .   '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
         .   '<polyline points="14 2 14 8 20 8"/>'
         .   '<line x1="8" y1="13" x2="16" y2="13"/>'
         .   '<line x1="8" y1="17" x2="13" y2="17"/>'
         . '</svg>';
}

function icon_eye(int $size = 14): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         .   '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>'
         .   '<circle cx="12" cy="12" r="3"/>'
         . '</svg>';
}

function icon_download(int $size = 14): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         .   '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>'
         .   '<polyline points="7 10 12 15 17 10"/>'
         .   '<line x1="12" y1="15" x2="12" y2="3"/>'
         . '</svg>';
}

function icon_zip(int $size = 13): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         .   '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>'
         .   '<polyline points="7 10 12 15 17 10"/>'
         .   '<line x1="12" y1="15" x2="12" y2="3"/>'
         . '</svg>';
}

function icon_home(int $size = 14): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         .   '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'
         .   '<polyline points="9 22 9 12 15 12 15 22"/>'
         . '</svg>';
}

function icon_search(int $size = 16): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         .   '<circle cx="11" cy="11" r="8"/>'
         .   '<line x1="21" y1="21" x2="16.65" y2="16.65"/>'
         . '</svg>';
}

function icon_chevron_right(int $size = 14): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         .   '<polyline points="9 18 15 12 9 6"/>'
         . '</svg>';
}

/* =============================================================================
   Rendering
   ============================================================================= */
function render_doc_card(array $doc, string $rawToken, bool $isDirectUploadGroup = false, string $requirementLabel = ''): string
{
    $displayName = trim($requirementLabel);
    if ($displayName === '') {
        $displayName = trim((string)($doc['title'] ?? ''));
        if ($displayName === '' || strcasecmp($displayName, 'Direct Upload') === 0) {
            $displayName = trim((string)($doc['filename'] ?? ''));
        }
    }
    if ($displayName === '' || strcasecmp($displayName, 'Direct Upload') === 0) {
        $displayName = 'untitled';
    }

    $source  = ($doc['source'] ?? 'document') === 'ph_document' ? 'ph_document' : 'document';
    $docId   = (int)$doc['id'];
    $ext     = strtolower(pathinfo((string)($doc['filename'] ?? ''), PATHINFO_EXTENSION));
    $tq      = htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8');
    $nameEsc = htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8');
    $extEsc  = htmlspecialchars($ext, ENT_QUOTES, 'UTF-8');
    $srcEnc  = urlencode($source);

    $size = '';
    if (!empty($doc['file_size'])) {
        $b = (int)$doc['file_size'];
        $size = $b >= 1048576 ? round($b / 1048576, 1) . ' MB'
              : ($b >= 1024 ? round($b / 1024) . ' KB' : $b . ' B');
    }
    $sizeHtml = $size !== '' ? '<span class="doc-size">' . htmlspecialchars($size, ENT_QUOTES, 'UTF-8') . '</span>' : '';

    $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true"><path d="M10 2h20l10 10v34a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" fill="#e0e0e0" stroke="#c8c8c8" stroke-width="1"/><path d="M30 2l10 10h-8a2 2 0 0 1-2-2V2z" fill="#bdbdbd" stroke="#c8c8c8" stroke-width="1"/><line x1="16" y1="22" x2="32" y2="22" stroke="#9e9e9e" stroke-width="2" stroke-linecap="round"/><line x1="16" y1="27" x2="32" y2="27" stroke="#9e9e9e" stroke-width="2" stroke-linecap="round"/><line x1="16" y1="32" x2="26" y2="32" stroke="#9e9e9e" stroke-width="2" stroke-linecap="round"/></svg>';
    if ($ext === 'pdf') {
        $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true"><path d="M10 2h20l10 10v34a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" fill="#e53935"/><path d="M30 2l10 10h-8a2 2 0 0 1-2-2V2z" fill="#b71c1c"/><text x="24" y="37" font-family="Arial, Helvetica, sans-serif" font-size="10" font-weight="700" fill="#ffffff" text-anchor="middle" letter-spacing="0.3">PDF</text></svg>';
    } elseif (in_array($ext, ['doc','docx'], true)) {
        $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true"><path d="M10 2h20l10 10v34a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" fill="#2b579a"/><path d="M30 2l10 10h-8a2 2 0 0 1-2-2V2z" fill="#1e3f70"/><text x="24" y="38" font-family="Arial, Helvetica, sans-serif" font-size="14" font-weight="700" fill="#ffffff" text-anchor="middle">W</text></svg>';
    } elseif (in_array($ext, ['xls','xlsx','csv'], true)) {
        $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true"><path d="M10 2h20l10 10v34a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" fill="#217346"/><path d="M30 2l10 10h-8a2 2 0 0 1-2-2V2z" fill="#185c37"/><text x="24" y="38" font-family="Arial, Helvetica, sans-serif" font-size="14" font-weight="700" fill="#ffffff" text-anchor="middle">X</text></svg>';
    } elseif (in_array($ext, ['ppt','pptx'], true)) {
        $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true"><path d="M10 2h20l10 10v34a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" fill="#d24726"/><path d="M30 2l10 10h-8a2 2 0 0 1-2-2V2z" fill="#a5371e"/><text x="24" y="38" font-family="Arial, Helvetica, sans-serif" font-size="14" font-weight="700" fill="#ffffff" text-anchor="middle">P</text></svg>';
    } elseif (in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg'], true)) {
        $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true"><path d="M10 2h20l10 10v34a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" fill="#ffffff" stroke="#c8c8c8" stroke-width="1"/><path d="M30 2l10 10h-8a2 2 0 0 1-2-2V2z" fill="#e0e0e0" stroke="#c8c8c8" stroke-width="1"/><rect x="13" y="20" width="22" height="16" rx="1" fill="#5eb7e8"/><circle cx="19" cy="25" r="2" fill="#ffffff"/><path d="M13 34l6-6 4 4 4-5 8 7z" fill="#7cc576"/></svg>';
    } elseif (in_array($ext, ['zip','rar','7z'], true)) {
        $iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" aria-hidden="true"><path d="M10 2h20l10 10v34a2 2 0 0 1-2 2H10a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" fill="#ffc107"/><path d="M30 2l10 10h-8a2 2 0 0 1-2-2V2z" fill="#e0a800"/><rect x="20" y="16" width="8" height="2" fill="#5d4037"/><rect x="20" y="20" width="8" height="2" fill="#5d4037"/><rect x="20" y="24" width="8" height="2" fill="#5d4037"/><rect x="20" y="28" width="8" height="2" fill="#5d4037"/><rect x="20" y="32" width="8" height="6" rx="1" fill="#5d4037"/></svg>';
    }

    return '<div class="doc-card" role="group" aria-label="' . $nameEsc . '" data-name="' . htmlspecialchars(strtolower($displayName), ENT_QUOTES, 'UTF-8') . '">'
         .   '<div class="doc-card-icon">' . $iconSvg . '</div>'
         .   '<div class="doc-card-body">'
         .     '<div class="doc-card-name" title="' . $nameEsc . '">' . $nameEsc . '</div>'
         .     '<div class="doc-card-meta">'
         .       ($sizeHtml !== '' ? $sizeHtml : '<span class="doc-size">File</span>')
         .       (($doc['upload_date'] ?? '') !== '' ? '<span class="dot">·</span><span>' . htmlspecialchars(utc_to_local($doc['upload_date'], 'M d'), ENT_QUOTES, 'UTF-8') . '</span>' : '')
         .     '</div>'
         .   '</div>'
         .   '<div class="doc-card-actions">'
         .     '<button type="button" class="dc-btn dc-btn-preview js-view-doc"'
         .       ' data-doc-id="' . $docId . '"'
         .       ' data-source="' . $srcEnc . '"'
         .       ' data-filename="' . $nameEsc . '"'
         .       ' data-ext="' . $extEsc . '"'
         .       ' aria-label="Preview ' . $nameEsc . '">' . icon_eye(12) . '</button>'
         .     '<a class="dc-btn dc-btn-download"'
         .       ' href="?t=' . $tq . '&amp;action=download&amp;type=' . $srcEnc . '&amp;doc=' . $docId . '"'
         .       ' aria-label="Download ' . $nameEsc . '">' . icon_download(12) . '</a>'
         .   '</div>'
         . '</div>';
}

function render_level(array $tree, string $rawToken, int $deptId): string
{
    $html = '';
    $tq   = htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8');

    if (!empty($tree['folders'])) {
        $html .= '<div class="folders-grid">';
        foreach ($tree['folders'] as $folder) {
            $count   = count_node_docs($folder['children']);
            $nameEsc = htmlspecialchars($folder['name'], ENT_QUOTES, 'UTF-8');
            $href    = '?t=' . $tq . '&amp;dept=' . (int)$deptId . '&amp;folder=' . (int)$folder['id'];
            $zipHref = '?t=' . $tq . '&amp;action=zip&amp;dept=' . (int)$deptId . '&amp;folder=' . (int)$folder['id'];
            $empty   = $count === 0;

            $html .= '<div class="folder-tile' . ($empty ? ' is-empty' : '') . '" data-name="' . htmlspecialchars(strtolower($folder['name']), ENT_QUOTES, 'UTF-8') . '">'
                   .   '<a class="folder-tile-main" href="' . ($empty ? '#' : $href) . '"'
                   .     ($empty ? ' aria-disabled="true" tabindex="-1"' : '') . '>'
                   .     '<span class="folder-tile-ico">' . icon_folder_v2(56) . '</span>'
                   .     '<div class="folder-tile-info">'
                   .       '<span class="folder-tile-name">' . $nameEsc . '</span>'
                   .       '<span class="folder-tile-count">' . (int)$count . ' file' . ($count === 1 ? '' : 's') . '</span>'
                   .     '</div>'
                   .     '<span class="folder-tile-arrow" aria-hidden="true">' . icon_chevron_right(14) . '</span>'
                   .   '</a>'
                   .   ($empty
                        ? '<span class="folder-tile-zip is-disabled" aria-hidden="true">' . icon_zip(13) . '</span>'
                        : '<a class="folder-tile-zip js-zip" href="' . $zipHref . '" title="Download folder as ZIP">' . icon_zip(13) . '</a>')
                   . '</div>';
        }
        $html .= '</div>';
    }

    foreach ($tree['requirements'] as $req) {
        $isDirect = !empty($req['is_direct_upload']);
        $html .= '<section class="req-section">'
               .   '<div class="docs-grid">';
        foreach ($req['documents'] as $doc) {
            $html .= render_doc_card($doc, $rawToken, $isDirect, $isDirect ? '' : (string)$req['label']);
        }
        $html .= '</div></section>';
    }

    foreach ($tree['ph_groups'] as $title => $docs) {
        $html .= '<section class="req-section"><div class="docs-grid">';
        foreach ($docs as $doc) {
            $html .= render_doc_card($doc, $rawToken, false, (string)$title);
        }
        $html .= '</div></section>';
    }

    if (!empty($tree['ph_direct_docs'])) {
        $html .= '<section class="req-section"><div class="docs-grid">';
        foreach ($tree['ph_direct_docs'] as $doc) $html .= render_doc_card($doc, $rawToken, true, '');
        $html .= '</div></section>';
    }
    if (!empty($tree['pickup_docs'])) {
        $html .= '<section class="req-section"><div class="docs-grid">';
        foreach ($tree['pickup_docs'] as $doc) $html .= render_doc_card($doc, $rawToken, true, '');
        $html .= '</div></section>';
    }

    return $html;
}

/* =============================================================================
   File helpers
   ============================================================================= */
function find_shared_document(PDO $pdo, ?int $scopeDeptId, string $type, int $docId): ?array
{
    if ($type === 'ph_document') {
        $sql = "SELECT id, filename, title, file_path, department_id, 'ph_document' AS source
                FROM ph_documents
                WHERE id = ? AND status='approved_admin' AND archived=0";
    } else {
        $sql = "SELECT id, filename, title, file_path, department_id, 'document' AS source
                FROM documents
                WHERE id = ? AND status='approved_admin' AND archived=0";
    }
    $params = [$docId];
    if ($scopeDeptId !== null) { $sql .= " AND department_id = ?"; $params[] = $scopeDeptId; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function build_safe_filename(array $doc): string
{
    $orig = (string)($doc['filename'] ?? '');
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

    $base = trim((string)($doc['title'] ?? ''));
    if ($base === '' || strcasecmp($base, 'Direct Upload') === 0) {
        $base = pathinfo($orig, PATHINFO_FILENAME);
    }
    if ($ext !== '' && strcasecmp(substr($base, -strlen('.' . $ext)), '.' . $ext) === 0) {
        $base = substr($base, 0, -strlen('.' . $ext));
    }
    $base = preg_replace('/[\/\\\\\r\n\t"<>:|?*\x00-\x1F]+/', '_', $base);
    $base = trim($base, " ._");
    if ($base === '') $base = 'document';

    return $ext !== '' ? $base . '.' . $ext : $base;
}

function add_doc_flat_to_zip(ZipArchive $zip, array &$staged, array &$missing, array $doc, string $basePath): void
{
    try {
        $src = storageStageLocalCopy((string)$doc['file_path']);
    } catch (Throwable $e) {
        error_log('[CHED share] zip staging failed for document #' . (int)($doc['id'] ?? 0) . ': ' . $e->getMessage());
        $src = null;
    }
    if ($src === null) {
        $missing[] = rtrim($basePath, '/') . '/' . build_safe_filename($doc);
        return;
    }
    $staged[] = $src;

    $name = build_safe_filename($doc);
    $entry = rtrim($basePath, '/') . '/' . $name;

    $i = 1; $origEntry = $entry;
    while ($zip->locateName($entry) !== false) {
        $pathinfo = pathinfo($origEntry);
        $entry = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . ' (' . $i . ')';
        if (isset($pathinfo['extension'])) $entry .= '.' . $pathinfo['extension'];
        $i++;
    }
    $zip->addFile($src, $entry);
}

function add_tree_to_zip(ZipArchive $zip, array &$staged, array &$missing, array $tree, string $basePath): void
{
    foreach ($tree['requirements'] as $req) {
        foreach ($req['documents'] as $doc) add_doc_flat_to_zip($zip, $staged, $missing, $doc, $basePath);
    }
    foreach ($tree['pickup_docs'] as $doc)    add_doc_flat_to_zip($zip, $staged, $missing, $doc, $basePath);
    foreach ($tree['ph_groups'] as $docs) {
        foreach ($docs as $doc) add_doc_flat_to_zip($zip, $staged, $missing, $doc, $basePath);
    }
    foreach ($tree['ph_direct_docs'] as $doc) add_doc_flat_to_zip($zip, $staged, $missing, $doc, $basePath);

    foreach ($tree['folders'] as $folder) {
        $safe = preg_replace('#[/\\\\\0]+#', '_', $folder['name']);
        $sub  = rtrim($basePath, '/') . '/' . $safe;
        $zip->addEmptyDir($sub);
        add_tree_to_zip($zip, $staged, $missing, $folder['children'], $sub);
    }
}

/* =============================================================================
   1. Token
   ============================================================================= */
$rawToken = $_GET['t'] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/', (string)$rawToken)) {
    deny_share(404, 'This link is not valid. Please contact the system administrator.', 'Invalid Link');
}
$tokenHash = hash('sha256', $rawToken);

/* =============================================================================
   2. Load link
   ============================================================================= */
$stmt = $pdo->prepare("SELECT * FROM ched_share_links WHERE token_hash = ? LIMIT 1");
$stmt->execute([$tokenHash]);
$link = $stmt->fetch();
if (!$link) {
    deny_share(404, 'This link is not valid. Please contact the system administrator.', 'Invalid Link');
}

/* =============================================================================
   3. Session
   ============================================================================= */
$cookieName   = 'ched_share_' . substr($tokenHash, 0, 16);
$existingHash = null;
$rawCookie    = $_COOKIE[$cookieName] ?? '';
$hasVisitorCookie = is_string($rawCookie) && (bool)preg_match('/^[a-f0-9]{64}$/', $rawCookie);

if ($hasVisitorCookie) {
    $tryHash = hash('sha256', $rawCookie);
    $s = $pdo->prepare("SELECT id FROM ched_share_sessions WHERE share_link_id=? AND session_hash=? LIMIT 1");
    $s->execute([$link['id'], $tryHash]);
    if ($s->fetch()) {
        $existingHash = $tryHash;
        $pdo->prepare("UPDATE ched_share_sessions SET last_seen_at=UTC_TIMESTAMP()
                       WHERE share_link_id=? AND session_hash=?")
            ->execute([$link['id'], $tryHash]);
    }
}

assert_link_usable($pdo, $link, $existingHash !== null);

$scopeDeptId = $link['department_id'] !== null ? (int)$link['department_id'] : null;

if ($existingHash === null) {
    $atCapacity = $link['status'] === 'full'
        || ($link['max_visitors'] !== null && (int)$link['visitor_count'] >= (int)$link['max_visitors']);

    if (!$atCapacity) {
        setcookie($cookieName, bin2hex(random_bytes(32)), [
            'expires'  => time() + 60 * 60 * 24 * 7,
            'path'     => '/',
            'secure'   => request_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

if ($existingHash === null && $hasVisitorCookie) {
    $registerHash = hash('sha256', $rawCookie);
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("
            INSERT IGNORE INTO ched_share_sessions (share_link_id, session_hash, created_at, last_seen_at)
            VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ");
        $ins->execute([$link['id'], $registerHash]);

        if ($ins->rowCount() === 0) {
            $pdo->rollBack();
            $existingHash = $registerHash;
        } else {
            $upd = $pdo->prepare("
                UPDATE ched_share_links
                   SET visitor_count = visitor_count + 1
                 WHERE id = ?
                   AND status = 'active'
                   AND expires_at > UTC_TIMESTAMP()
                   AND (max_visitors IS NULL OR visitor_count < max_visitors)
            ");
            $upd->execute([$link['id']]);

            if ($upd->rowCount() !== 1) {
                $pdo->rollBack();
                deny_share(429,
                    'This CHED document-sharing link has reached its maximum number of allowed visitors. Please contact the system administrator for a new link.',
                    'Link Full');
            }

            $pdo->commit();
            $existingHash = $registerHash;
        }

        setcookie($cookieName, $rawCookie, [
            'expires'  => time() + 60 * 60 * 24 * 7,
            'path'     => '/',
            'secure'   => request_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $stmt->execute([$tokenHash]);
        $link = $stmt->fetch();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[CHED share] reserve failed: ' . $e->getMessage());
        deny_share(500, 'The portal could not process your request. Please try again.', 'Server Error');
    }
}

/* =============================================================================
   4. Dispatch
   ============================================================================= */
$action = $_GET['action'] ?? 'list';

/* ---------- 4a. Inline JSON search endpoint ---------- */
if ($action === 'search') {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    assert_link_usable($pdo, $link, $existingHash !== null);

    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    if ($q === '' || (function_exists('mb_strlen') ? mb_strlen($q, 'UTF-8') : strlen($q)) > 200) {
        echo json_encode(['results' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {
        $rows = search_shared_documents($pdo, $scopeDeptId, $q, 30);
    } catch (Throwable $e) {
        error_log('[CHED share] search failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Search failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'          => (int)$r['id'],
            'source'      => $r['source'],
            'filename'    => (string)($r['display_name'] ?? ''),
            'dept_id'     => (int)$r['department_id'],
            'dept_name'   => (string)$r['dept_name'],
            'folder_id'   => (int)$r['folder_id'],
            'folder_path' => (string)$r['folder_path'],
        ];
    }
    echo json_encode(['results' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ---------- 4b. Preview / download ---------- */
if ($action === 'preview' || $action === 'download') {
    $docId = (int)($_GET['doc'] ?? 0);
    $type  = ($_GET['type'] ?? 'document') === 'ph_document' ? 'ph_document' : 'document';

    $doc = find_shared_document($pdo, $scopeDeptId, $type, $docId);
    if (!$doc) {
        deny_share(404, 'The requested document is no longer available.', 'Document Not Available');
    }
    assert_link_usable($pdo, $link, $existingHash !== null);

    try {
        $staged = storageStageLocalCopy((string)$doc['file_path']);
    } catch (Throwable $e) {
        error_log('[CHED share] staging failed for ' . $type . ' #' . $docId . ': ' . $e->getMessage());
        deny_share(502, 'The requested document could not be retrieved from storage.', 'Storage Error');
    }
    if ($staged === null) {
        deny_share(404, 'The requested document file is missing.', 'File Not Found');
    }

    $ext = strtolower(pathinfo($doc['filename'], PATHINFO_EXTENSION));
    $mimes = [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',  'gif'  => 'image/gif',
        'webp' => 'image/webp', 'bmp'  => 'image/bmp', 'svg' => 'image/svg+xml',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip'  => 'application/zip',
    ];
    $mime = $mimes[$ext] ?? 'application/octet-stream';

    $safe = build_safe_filename($doc);
    $ascii = preg_replace('/[^\x20-\x7E]/', '_', $safe);

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($staged));
    header(
        'Content-Disposition: ' . ($action === 'preview' ? 'inline' : 'attachment')
        . '; filename="' . addcslashes($ascii, '"\\') . '"'
        . "; filename*=UTF-8''" . rawurlencode($safe)
    );
    header('X-Content-Type-Options: nosniff');
    readfile($staged);
    @unlink($staged);
    exit;
}

/* ---------- 4c. ZIP ---------- */
if ($action === 'zip') {
    assert_link_usable($pdo, $link, $existingHash !== null);

    if (!class_exists('ZipArchive')) {
        deny_share(500, 'ZIP downloads are not available on this server.', 'ZIP Unavailable');
    }

    $zipDeptId   = $scopeDeptId;
    $zipFolderId = 0;

    if ($zipDeptId === null && isset($_GET['dept']) && $_GET['dept'] !== '') {
        $zipDeptId = (int)$_GET['dept'];
    }

    if ($zipDeptId !== null && isset($_GET['folder']) && (int)$_GET['folder'] > 0) {
        $stmt = $pdo->prepare("SELECT id FROM department_folders WHERE id = ? AND department_id = ?");
        $stmt->execute([(int)$_GET['folder'], $zipDeptId]);
        if ($stmt->fetch()) {
            $zipFolderId = (int)$_GET['folder'];
        }
    }

    $zipPath = tempnam(sys_get_temp_dir(), 'chedshare');
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($zipPath);
        deny_share(500, 'Could not create ZIP.', 'ZIP Unavailable');
    }

    $staged = [];
    $missing = [];
    $zipNameBase = 'CHED_Documents';

    if ($zipDeptId !== null) {
        $stmt = $pdo->prepare("SELECT MIN(id) AS id, department_name FROM departments WHERE id = ? GROUP BY department_name");
        $stmt->execute([$zipDeptId]);
        $dept = $stmt->fetch();
        if (!$dept) {
            $zip->close(); @unlink($zipPath);
            deny_share(404, 'Department not found.', 'Not Found');
        }
        $safeDept = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$dept['department_name']) ?: 'Department';

        if ($zipFolderId > 0) {
            $stmt = $pdo->prepare("SELECT folder_name FROM department_folders WHERE id = ?");
            $stmt->execute([$zipFolderId]);
            $folderName = $stmt->fetchColumn() ?: 'Folder';
            $safeFolder = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$folderName) ?: 'Folder';

            $rootPath = $safeDept . '/' . $safeFolder;
            $zip->addEmptyDir($rootPath);

            $seen = [];
            $tree = build_tree_node($pdo, $zipDeptId, $zipFolderId, $seen);
            add_tree_to_zip($zip, $staged, $missing, $tree, $rootPath);

            $zipNameBase = $safeFolder;
        } else {
            $zip->addEmptyDir($safeDept);
            $seen = [];
            $tree = build_tree_node($pdo, $zipDeptId, null, $seen);
            add_tree_to_zip($zip, $staged, $missing, $tree, $safeDept);
            $zipNameBase = $safeDept;
        }
    } else {
        foreach (fetch_all_departments($pdo) as $d) {
            $safeDept = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$d['department_name']) ?: 'Department';
            $zip->addEmptyDir($safeDept);
            $seen = [];
            $tree = build_tree_node($pdo, (int)$d['id'], null, $seen);
            add_tree_to_zip($zip, $staged, $missing, $tree, $safeDept);
        }
    }

    if ($missing) {
        $zip->addFromString(
            'MISSING_FILES.txt',
            "The following documents could not be retrieved from storage and are not included in this archive:\r\n"
            . implode("\r\n", array_map(static fn(string $m): string => '- ' . $m, $missing))
        );
    }
    $zip->close();
    foreach ($staged as $t) @unlink($t);

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipNameBase . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    header('X-Content-Type-Options: nosniff');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

/* =============================================================================
   5. Determine view
   ============================================================================= */
$reqDept   = isset($_GET['dept']) ? (int)$_GET['dept'] : 0;
$reqFolder = isset($_GET['folder']) ? (int)$_GET['folder'] : 0;

$viewDept   = null;
$viewFolder = 0;

if ($scopeDeptId !== null) {
    $viewDept = $scopeDeptId;
    if ($reqFolder > 0) {
        $stmt = $pdo->prepare("SELECT id FROM department_folders WHERE id = ? AND department_id = ?");
        $stmt->execute([$reqFolder, $viewDept]);
        if ($stmt->fetch()) $viewFolder = $reqFolder;
    }
} elseif ($reqDept > 0) {
    $stmt = $pdo->prepare("SELECT MIN(id) AS id FROM departments WHERE id = ? GROUP BY department_name");
    $stmt->execute([$reqDept]);
    $d = $stmt->fetch();
    if ($d) {
        $viewDept = (int)$d['id'];
        if ($reqFolder > 0) {
            $stmt = $pdo->prepare("SELECT id FROM department_folders WHERE id = ? AND department_id = ?");
            $stmt->execute([$reqFolder, $viewDept]);
            if ($stmt->fetch()) $viewFolder = $reqFolder;
        }
    }
}

$departments = $scopeDeptId !== null
    ? (function () use ($pdo, $scopeDeptId) {
        $stmt = $pdo->prepare("SELECT MIN(id) AS id, department_name, department_code FROM departments WHERE id = ? GROUP BY department_name");
        $stmt->execute([$scopeDeptId]);
        $row = $stmt->fetch();
        return $row ? [$row] : [];
    })()
    : fetch_all_departments($pdo);

$viewDeptName = '';
$viewTree     = null;
$viewCount    = 0;
$breadcrumb   = [];

if ($viewDept !== null) {
    foreach ($departments as $d) {
        if ((int)$d['id'] === $viewDept) {
            $viewDeptName = $d['department_name'];
            break;
        }
    }
    $breadcrumb = build_breadcrumb($pdo, $viewDept, $viewFolder);
    $seen       = [];
    $viewTree   = build_tree_node($pdo, $viewDept, $viewFolder > 0 ? $viewFolder : null, $seen);
    $viewCount  = count_node_docs($viewTree);
}

$deptCards    = [];
$totalInScope = 0;
if ($viewDept === null) {
    foreach ($departments as $d) {
        $seen  = [];
        $tree  = build_tree_node($pdo, (int)$d['id'], null, $seen);
        $count = count_node_docs($tree);
        if ($count === 0) continue;
        $deptCards[]   = ['dept' => $d, 'count' => $count];
        $totalInScope += $count;
    }
}

$slotsRaw   = $link['max_visitors'] === null
    ? null
    : max(0, (int)$link['max_visitors'] - (int)$link['visitor_count']);
$slotsLabel = $slotsRaw === null ? '∞' : (string)$slotsRaw;
$expiresLocal = utc_to_local($link['expires_at'], 'M d, Y');
$tq           = htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8');

$totalDocs = $viewDept === null ? $totalInScope : $viewCount;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $viewDept === null ? 'CHED Shared Documents' : htmlspecialchars($viewDeptName, ENT_QUOTES, 'UTF-8') . ' · CHED' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700;9..144,900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --cream:#f6f1e8;
  --cream-2:#efe9de;
  --paper:#fffefb;
  --paper-2:#faf7f0;
  --ink:#0f1720;
  --ink-2:#2c3742;
  --ink-3:#5e6b7a;
  --ink-4:#93a0b0;
  --navy:#0b1b2b;
  --navy-2:#132a40;
  --blue:#2563eb;
  --blue-2:#1d4ed8;
  --blue-glow:rgba(37,99,235,.22);
  --accent:#f59e0b;
  --accent-2:#ea580c;
  --line:#e6ddcc;
  --line-2:#d8cdb6;
  --success:#059669;
  --danger:#dc2626;
  --radius:12px;
  --radius-lg:22px;
  --shadow-sm:0 1px 2px rgba(15,23,32,.04), 0 1px 3px rgba(15,23,32,.06);
  --shadow:0 8px 24px -10px rgba(15,23,32,.18), 0 2px 6px -2px rgba(15,23,32,.08);
  --shadow-lg:0 24px 60px -20px rgba(15,23,32,.35), 0 8px 20px -8px rgba(15,23,32,.15);
  --shadow-glow:0 0 0 1px rgba(37,99,235,.08), 0 20px 60px -25px rgba(37,99,235,.4);
  --ease:cubic-bezier(.22,1,.36,1);
  --ease-spring:cubic-bezier(.34,1.56,.64,1);
}
html,body{height:100%}
body{
  font-family:'Inter',system-ui,-apple-system,sans-serif;
  background:var(--cream);
  color:var(--ink);
  font-size:14.5px;
  line-height:1.55;
  -webkit-font-smoothing:antialiased;
  -moz-osx-font-smoothing:grayscale;
  min-height:100vh;
  overflow-x:hidden;
}
body::before{
  content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background:
    radial-gradient(900px 600px at 100% 0%, rgba(37,99,235,.08), transparent 60%),
    radial-gradient(700px 500px at -10% 30%, rgba(245,158,11,.09), transparent 60%),
    radial-gradient(800px 600px at 50% 120%, rgba(15,23,32,.05), transparent 60%);
}
body::after{
  content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='3'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.035'/%3E%3C/svg%3E");
}
.app{min-height:100vh;position:relative;z-index:1}
.main{display:flex;flex-direction:column}

/* TOPBAR */
.topbar{
  position:sticky;top:0;z-index:60;
  background:linear-gradient(180deg,rgba(246,241,232,.92),rgba(246,241,232,.78));
  backdrop-filter:blur(20px) saturate(180%);
  -webkit-backdrop-filter:blur(20px) saturate(180%);
  border-bottom:1px solid rgba(15,23,32,.06);
  padding:16px 40px;
  display:flex;align-items:center;justify-content:space-between;gap:24px;
  flex-wrap:wrap;
}
.topbar-brand{display:flex;align-items:center;gap:14px;text-decoration:none;color:inherit}
.topbar-brand-img{
  width:44px;height:44px;border-radius:12px;object-fit:cover;
  flex-shrink:0;
  border:1px solid rgba(255,255,255,.9);
  box-shadow:0 4px 12px rgba(15,23,32,.12), inset 0 1px 0 rgba(255,255,255,.6);
  background:var(--paper);
}
.topbar-brand-text{display:flex;flex-direction:column;justify-content:center;line-height:1.15}
.topbar-brand-text h2{
  font-family:'Fraunces',Georgia,serif;
  font-size:1.1rem;font-weight:700;letter-spacing:-.025em;color:var(--ink);margin:0;
}
.topbar-brand-text .school-sub{
  font-size:.68rem;font-weight:600;color:var(--ink-4);
  letter-spacing:.06em;text-transform:uppercase;margin-top:3px;
}
.topbar-badge{
  display:inline-flex;align-items:center;gap:7px;
  padding:8px 14px;border-radius:999px;
  background:rgba(37,99,235,.08);
  color:var(--blue);
  font-size:.72rem;font-weight:700;
  letter-spacing:.02em;text-transform:uppercase;
  border:1px solid rgba(37,99,235,.15);
}
.topbar-badge::before{
  content:'';width:7px;height:7px;border-radius:50%;
  background:var(--blue);
  box-shadow:0 0 0 3px rgba(37,99,235,.18);
  animation:pulse 2s ease-in-out infinite;
}
@keyframes pulse{
  0%,100%{opacity:1;transform:scale(1)}
  50%{opacity:.6;transform:scale(.85)}
}

/* SUBHEADER */
.subheader{
  display:flex;align-items:center;justify-content:space-between;gap:20px;
  padding:12px 40px;
  background:var(--paper);
  border-bottom:1px solid var(--line);
  position:sticky;top:77px;z-index:55;
  box-shadow:0 1px 0 rgba(15,23,32,.02);
}
.crumbs{
  display:flex;align-items:center;gap:4px;
  font-size:.82rem;flex-wrap:wrap;flex:1;min-width:0;
}
.crumbs a,.crumbs span{
  display:inline-flex;align-items:center;gap:6px;
  color:var(--ink-3);text-decoration:none;font-weight:600;
  padding:6px 10px;border-radius:8px;
  transition:all .15s var(--ease);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:240px;
}
.crumbs a:hover{background:rgba(37,99,235,.08);color:var(--blue)}
.crumbs .current{
  color:var(--ink);font-weight:700;
  background:var(--paper-2);
  box-shadow:inset 0 0 0 1px rgba(15,23,32,.05);
}
.crumbs .sep{color:var(--ink-4);padding:0 2px;font-size:.7rem}
.subheader-actions{display:flex;align-items:center;gap:10px;flex-shrink:0;position:relative}
.search-box{
  position:relative;
  display:flex;align-items:center;gap:9px;
  background:var(--paper-2);border:1px solid var(--line);
  padding:8px 14px;border-radius:11px;
  font-size:.82rem;color:var(--ink-3);
  transition:all .18s var(--ease);
  min-width:230px;
}
.search-box:focus-within{
  border-color:var(--blue);
  background:#fff;
  box-shadow:0 0 0 4px var(--blue-glow);
}
.search-box input{
  border:0;background:transparent;outline:0;
  font-family:inherit;font-size:.82rem;color:var(--ink);
  width:100%;min-width:0;font-weight:500;
}
.search-box input::placeholder{color:var(--ink-4);font-weight:500}
.search-box svg{color:var(--ink-4);flex-shrink:0;transition:color .15s}
.search-box:focus-within svg{color:var(--blue)}
.search-dropdown{position:absolute;top:calc(100% + 8px);right:0;left:auto;min-width:320px;max-width:460px;background:#fffefb;border:1px solid #e6ddcc;border-radius:12px;box-shadow:0 12px 30px rgba(0,0,0,.12);max-height:320px;overflow-y:auto;z-index:80;font-size:.9rem}
.search-dropdown[hidden]{display:none}
.search-dropdown a{display:block;padding:.7rem .9rem;text-decoration:none;color:#0f1720;border-bottom:1px solid #eee;transition:background .12s}
.search-dropdown a:last-child{border-bottom:0}
.search-dropdown a:hover,.search-dropdown a.is-active{background:#f6f1e8}
.search-dropdown .sr-title{font-weight:600;line-height:1.25;color:#0f1720}
.search-dropdown .sr-hint{font-size:.78rem;color:#5c5c5c;margin-top:2px}
.search-dropdown .sr-empty{padding:.9rem;color:#666;font-style:italic}
.zip-btn{
  display:inline-flex;align-items:center;gap:8px;
  padding:9px 16px;border-radius:11px;
  background:var(--navy);color:#fff;
  font-size:.8rem;font-weight:700;text-decoration:none;
  border:1px solid var(--navy);cursor:pointer;
  transition:all .18s var(--ease);
  white-space:nowrap;position:relative;overflow:hidden;
  box-shadow:0 4px 12px -2px rgba(15,23,32,.25);
}
.zip-btn::before{
  content:'';position:absolute;inset:0;
  background:linear-gradient(120deg,transparent 30%,rgba(255,255,255,.18),transparent 70%);
  transform:translateX(-100%);transition:transform .5s var(--ease);
}
.zip-btn:hover::before{transform:translateX(100%)}
.zip-btn:hover{
  background:var(--blue);border-color:var(--blue);
  transform:translateY(-1px);
  box-shadow:0 8px 20px -4px var(--blue-glow);
}
.zip-btn.is-loading{pointer-events:none;opacity:.75}
.zip-btn.is-loading::after{
  content:'';width:12px;height:12px;
  border:2px solid currentColor;border-top-color:transparent;border-radius:50%;
  animation:spin .7s linear infinite;margin-left:2px;
}
@keyframes spin{to{transform:rotate(360deg)}}

/* SHARE STRIP */
.share-strip{
  display:flex;align-items:center;gap:20px;flex-wrap:wrap;
  padding:14px 40px;
  background:linear-gradient(90deg, rgba(245,158,11,.05), rgba(37,99,235,.04));
  border-bottom:1px solid var(--line);
  font-size:.78rem;
}
.share-strip-title{
  flex-shrink:0;
  font-size:.66rem;font-weight:800;letter-spacing:.1em;
  text-transform:uppercase;color:var(--accent-2);
  padding:5px 10px;border-radius:7px;
  background:rgba(245,158,11,.12);
}
.share-stat{display:inline-flex;align-items:center;gap:7px;position:relative}
.share-stat .k{color:var(--ink-4);font-weight:600}
.share-stat .v{color:var(--ink);font-weight:800;font-feature-settings:"tnum";font-size:.8rem}
.share-stat + .share-stat{padding-left:20px}
.share-stat + .share-stat::before{
  content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);
  width:1px;height:14px;background:var(--line-2);
}

/* HERO */
.hero{
  padding:48px 40px 24px;
  display:flex;align-items:flex-end;justify-content:space-between;
  gap:40px;flex-wrap:wrap;
  max-width:1440px;margin:0 auto;width:100%;
}
.hero-left{min-width:0;flex:1;max-width:720px}
.hero-eyebrow{
  display:inline-flex;align-items:center;gap:8px;
  font-size:.72rem;font-weight:700;
  color:var(--blue);letter-spacing:.08em;text-transform:uppercase;
  margin-bottom:18px;
  padding:7px 12px;border-radius:8px;
  background:rgba(37,99,235,.08);
  border:1px solid rgba(37,99,235,.14);
}
.hero-eyebrow .dot{
  width:6px;height:6px;border-radius:50%;background:var(--blue);display:inline-block;
  box-shadow:0 0 0 3px rgba(37,99,235,.2);
  animation:pulse 2s ease-in-out infinite;
}
.hero h1{
  font-family:'Fraunces',Georgia,serif;
  font-size:clamp(2.2rem,4.6vw,3.6rem);
  font-weight:700;letter-spacing:-.025em;line-height:1.05;
  color:var(--ink);margin:0 0 14px;
}
.hero h1 em{
  font-style:italic;font-weight:500;
  background:linear-gradient(135deg,var(--blue),var(--accent-2));
  -webkit-background-clip:text;background-clip:text;
  -webkit-text-fill-color:transparent;
}
.hero-desc{
  font-size:1rem;color:var(--ink-3);
  max-width:580px;line-height:1.65;font-weight:500;
}
.hero-stats{display:flex;gap:12px;flex-wrap:wrap}
.hero-stat{
  background:var(--paper);border:1px solid var(--line);
  padding:18px 22px;border-radius:var(--radius-lg);
  min-width:120px;
  box-shadow:var(--shadow-sm);
  display:flex;flex-direction:column;justify-content:center;
  transition:all .2s var(--ease);
  position:relative;overflow:hidden;
}
.hero-stat::before{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:linear-gradient(90deg,var(--blue),var(--accent));
  opacity:0;transition:opacity .2s;
}
.hero-stat:hover{transform:translateY(-2px);box-shadow:var(--shadow)}
.hero-stat:hover::before{opacity:1}
.hero-stat-num{
  font-family:'Fraunces',Georgia,serif;
  font-size:2.1rem;font-weight:700;
  color:var(--ink);line-height:1;margin-bottom:8px;
  font-feature-settings:"tnum";letter-spacing:-.02em;
}
.hero-stat-label{
  font-size:.68rem;font-weight:700;
  color:var(--ink-4);letter-spacing:.1em;
  text-transform:uppercase;
}

/* CONTENT */
.content{
  padding:32px 40px 80px;
  flex:1;max-width:1440px;margin:0 auto;width:100%;
}

/* DEPT CARDS */
.dept-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(340px,1fr));
  gap:20px;
}
.dept-card{
  background:var(--paper);border:1px solid var(--line);
  border-radius:var(--radius-lg);padding:26px;
  position:relative;overflow:hidden;text-decoration:none;color:inherit;
  display:flex;flex-direction:column;gap:18px;
  box-shadow:var(--shadow-sm);
  transition:all .3s var(--ease);
  isolation:isolate;
}
.dept-card::before{
  content:'';position:absolute;top:-60px;right:-60px;width:200px;height:200px;border-radius:50%;
  background:radial-gradient(circle, rgba(37,99,235,.12), transparent 70%);
  transition:transform .5s var(--ease),opacity .4s;opacity:.6;z-index:-1;
}
.dept-card::after{
  content:'';position:absolute;inset:0;border-radius:var(--radius-lg);
  background:linear-gradient(135deg,transparent 60%,rgba(37,99,235,.03));
  opacity:0;transition:opacity .3s;z-index:-1;pointer-events:none;
}
.dept-card:hover{
  transform:translateY(-4px);
  box-shadow:var(--shadow-glow);
  border-color:rgba(37,99,235,.25);
}
.dept-card:hover::before{transform:scale(1.4);opacity:1}
.dept-card:hover::after{opacity:1}
.dept-card-head{display:flex;align-items:center;gap:18px}
.dept-icon-wrap{
  width:64px;height:64px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,#fff7e6,#fef3c7);
  border-radius:18px;
  border:1px solid rgba(245,158,11,.2);
  transition:transform .35s var(--ease-spring);
  box-shadow:0 6px 14px -4px rgba(245,158,11,.3), inset 0 1px 0 rgba(255,255,255,.7);
}
.dept-card:hover .dept-icon-wrap{transform:scale(1.06) rotate(-5deg)}
.dept-icon-wrap svg{filter:drop-shadow(0 2px 3px rgba(180,83,9,.2))}
.dept-title-wrap{flex:1;min-width:0}
.dept-title{
  font-family:'Fraunces',Georgia,serif;
  font-size:1.25rem;font-weight:600;
  letter-spacing:-.015em;line-height:1.2;
  color:var(--ink);margin:0 0 6px;
  overflow-wrap:break-word;word-break:break-word;
}
.dept-subtitle{
  font-size:.78rem;color:var(--ink-4);font-weight:600;
  display:inline-flex;align-items:center;gap:5px;
  padding:3px 9px;border-radius:6px;
  background:var(--paper-2);
  border:1px solid var(--line);
}
.dept-subtitle strong{color:var(--blue);font-weight:800}

/* FOLDER TILES */
.folders-grid{
  display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));
  gap:14px;margin-bottom:36px;
}
.folder-tile{
  background:var(--paper);border:1px solid var(--line);
  border-radius:var(--radius);padding:16px;
  display:flex;align-items:center;gap:12px;
  transition:all .22s var(--ease);
  box-shadow:var(--shadow-sm);position:relative;
}
.folder-tile:hover{
  transform:translateY(-3px);
  border-color:rgba(37,99,235,.25);
  box-shadow:var(--shadow);
}
.folder-tile.is-empty{opacity:.55;background:var(--paper-2)}
.folder-tile.is-empty:hover{transform:none;box-shadow:var(--shadow-sm)}
.folder-tile-main{display:flex;align-items:center;gap:14px;flex:1;min-width:0;text-decoration:none;color:inherit}
.folder-tile.is-empty .folder-tile-main{pointer-events:none}
.folder-tile-ico{
  width:48px;height:48px;flex-shrink:0;display:flex;align-items:center;justify-content:center;
  transition:transform .3s var(--ease-spring);
}
.folder-tile:hover:not(.is-empty) .folder-tile-ico{transform:scale(1.1) rotate(-4deg)}
.folder-tile-info{flex:1;min-width:0}
.folder-tile-name{
  display:block;font-weight:700;font-size:.92rem;color:var(--ink);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  line-height:1.3;margin-bottom:3px;
  transition:color .15s;
}
.folder-tile:hover:not(.is-empty) .folder-tile-name{color:var(--blue)}
.folder-tile-count{display:inline-block;font-size:.72rem;color:var(--ink-4);font-weight:600}
.folder-tile-arrow{color:var(--ink-4);flex-shrink:0;transition:all .18s var(--ease)}
.folder-tile:hover:not(.is-empty) .folder-tile-arrow{color:var(--blue);transform:translateX(3px)}
.folder-tile-zip{
  flex-shrink:0;width:34px;height:34px;border-radius:9px;
  display:flex;align-items:center;justify-content:center;
  background:var(--paper-2);border:1px solid var(--line);
  color:var(--ink-3);text-decoration:none;transition:all .15s var(--ease);
}
.folder-tile-zip:hover{
  background:var(--navy);border-color:var(--navy);color:#fff;
  transform:translateY(-1px) scale(1.05);
}
.folder-tile-zip.is-disabled{background:transparent;border:1px dashed var(--line-2);color:var(--ink-4);pointer-events:none;opacity:.5}

/* ========== DOC CARDS — COMPACT ========== */
.req-section{margin-bottom:32px}
.req-section:last-child{margin-bottom:0}
.docs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}
.doc-card{
  background:var(--paper);border:1px solid var(--line);
  border-radius:10px;padding:9px 10px;
  display:flex;align-items:center;gap:10px;
  position:relative;overflow:hidden;
  transition:all .2s var(--ease);
  box-shadow:var(--shadow-sm);
}
.doc-card::before{
  content:'';position:absolute;left:0;top:0;bottom:0;width:3px;
  background:linear-gradient(180deg,var(--blue),var(--accent));
  transform:scaleY(0);transform-origin:top;
  transition:transform .25s var(--ease);
}
.doc-card:hover{
  transform:translateY(-2px);
  border-color:rgba(37,99,235,.2);
  box-shadow:var(--shadow);
}
.doc-card:hover::before{transform:scaleY(1)}
.doc-card-icon{
  width:30px;height:36px;flex-shrink:0;
  position:relative;display:flex;align-items:center;justify-content:center;
  transition:transform .25s var(--ease-spring);
}
.doc-card:hover .doc-card-icon{transform:scale(1.06) rotate(-3deg)}
.doc-card-body{flex:1;min-width:0;padding-right:2px}
.doc-card-name{
  font-size:.78rem;font-weight:700;color:var(--ink);line-height:1.3;
  overflow:hidden;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;
  word-break:break-word;margin-bottom:3px;
}
.doc-card-meta{display:flex;align-items:center;gap:4px;font-size:.66rem;color:var(--ink-4);font-weight:600}
.doc-card-meta .dot{opacity:.7}
.doc-card-actions{display:flex;flex-direction:column;gap:4px;flex-shrink:0}
.dc-btn{
  width:26px;height:26px;border-radius:7px;
  display:flex;align-items:center;justify-content:center;
  border:1px solid var(--line);background:var(--paper);
  color:var(--ink-3);cursor:pointer;text-decoration:none;
  transition:all .15s var(--ease);
}
.dc-btn:hover{
  background:rgba(37,99,235,.08);
  border-color:rgba(37,99,235,.25);
  color:var(--blue);
  transform:scale(1.08);
}
.dc-btn-download{background:var(--navy);border-color:var(--navy);color:#fff}
.dc-btn-download:hover{
  background:var(--blue);border-color:var(--blue);color:#fff;
  box-shadow:0 4px 12px -2px var(--blue-glow);
}

/* EMPTY STATE */
.empty-state{
  text-align:center;padding:100px 24px;
  background:var(--paper);border:1px dashed var(--line-2);
  border-radius:var(--radius-lg);
}
.empty-ico{
  width:88px;height:88px;margin:0 auto 22px;
  background:linear-gradient(135deg,#fff7e6,#fef3c7);
  border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  box-shadow:0 12px 28px -8px rgba(245,158,11,.3);
}
.empty-state h3{
  font-family:'Fraunces',Georgia,serif;
  font-size:1.4rem;font-weight:700;color:var(--ink);margin:0 0 10px;
}
.empty-state p{color:var(--ink-3);font-size:.92rem;max-width:420px;margin:0 auto;line-height:1.6}

/* MODAL */
.modal{
  position:fixed;inset:0;z-index:9999;
  background:rgba(11,27,43,.82);
  backdrop-filter:blur(12px) saturate(140%);
  -webkit-backdrop-filter:blur(12px) saturate(140%);
  display:flex;align-items:center;justify-content:center;padding:24px;
}
.modal[hidden]{display:none}
.modal:not([hidden]){animation:modalFade .25s var(--ease)}
@keyframes modalFade{from{opacity:0}to{opacity:1}}
.modal-box{
  background:var(--paper);
  border-radius:var(--radius-lg);
  max-width:1080px;width:100%;max-height:92vh;
  display:flex;flex-direction:column;
  box-shadow:0 40px 100px -20px rgba(0,0,0,.6), 0 0 0 1px rgba(255,255,255,.05);
  overflow:hidden;
  animation:modalPop .35s var(--ease-spring);
}
@keyframes modalPop{
  from{transform:translateY(20px) scale(.96);opacity:0}
  to{transform:translateY(0) scale(1);opacity:1}
}
.modal-head{
  padding:18px 24px;border-bottom:1px solid var(--line);
  display:flex;align-items:center;justify-content:space-between;gap:16px;
  background:var(--paper-2);
}
.modal-head-left{display:flex;align-items:center;gap:14px;min-width:0}
.modal-ico{
  width:40px;height:40px;border-radius:11px;flex-shrink:0;
  background:linear-gradient(135deg,var(--blue),var(--blue-2));color:#fff;
  display:flex;align-items:center;justify-content:center;
  box-shadow:0 6px 14px -4px var(--blue-glow);
}
.modal-title{
  font-family:'Inter',sans-serif;
  font-size:.95rem;font-weight:700;color:var(--ink);
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.modal-close{
  border:0;background:var(--paper);width:38px;height:38px;border-radius:10px;
  cursor:pointer;color:var(--ink-3);
  display:flex;align-items:center;justify-content:center;font-size:16px;
  flex-shrink:0;transition:all .15s var(--ease);
  box-shadow:inset 0 0 0 1px var(--line);
}
.modal-close:hover{
  background:rgba(220,38,38,.08);
  color:var(--danger);
  box-shadow:inset 0 0 0 1px rgba(220,38,38,.25);
  transform:rotate(90deg);
}
.modal-body{
  flex:1;overflow:auto;background:var(--cream-2);
  display:flex;align-items:center;justify-content:center;
  min-height:400px;padding:20px;
}
.modal-body iframe{
  width:100%;height:74vh;border:none;border-radius:12px;background:#fff;
  box-shadow:0 8px 32px -8px rgba(0,0,0,.15);
}
.modal-body img{
  max-width:100%;max-height:74vh;border-radius:12px;
  box-shadow:0 8px 32px -8px rgba(0,0,0,.15);
}
.modal-fallback{text-align:center;padding:48px 28px;max-width:440px}
.modal-fallback h4{
  font-family:'Fraunces',Georgia,serif;
  font-size:1.3rem;font-weight:700;margin:0 0 10px;color:var(--ink);
}
.modal-fallback p{color:var(--ink-3);margin:0 0 22px;font-size:.9rem;line-height:1.6}
.modal-fallback .btn-download{
  display:inline-flex;align-items:center;gap:8px;
  background:var(--blue);color:#fff;
  padding:11px 20px;border-radius:11px;text-decoration:none;
  font-weight:700;font-size:.85rem;transition:all .15s var(--ease);
  box-shadow:0 6px 16px -4px var(--blue-glow);
}
.modal-fallback .btn-download:hover{background:var(--blue-2);transform:translateY(-1px)}
.modal-foot{
  padding:14px 24px;border-top:1px solid var(--line);
  display:flex;align-items:center;justify-content:flex-end;gap:8px;
  background:var(--paper-2);
}
.mf-btn{
  display:inline-flex;align-items:center;gap:8px;
  padding:10px 18px;border-radius:11px;
  font-size:.82rem;font-weight:700;cursor:pointer;text-decoration:none;
  border:1px solid transparent;font-family:inherit;line-height:1;
  transition:all .15s var(--ease);
}
.mf-btn-primary{
  background:var(--navy);border-color:var(--navy);color:#fff;
  box-shadow:0 4px 12px -2px rgba(15,23,32,.25);
}
.mf-btn-primary:hover{
  background:var(--blue);border-color:var(--blue);
  transform:translateY(-1px);box-shadow:0 8px 20px -4px var(--blue-glow);
}
.mf-btn-secondary{background:var(--paper);border-color:var(--line-2);color:var(--ink-2)}
.mf-btn-secondary:hover{background:var(--paper-2);color:var(--ink)}

/* STAGGER REVEAL */
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.dept-card,.folder-tile,.doc-card{animation:fadeUp .5s var(--ease) backwards}
.dept-card:nth-child(1),.folder-tile:nth-child(1),.doc-card:nth-child(1){animation-delay:.02s}
.dept-card:nth-child(2),.folder-tile:nth-child(2),.doc-card:nth-child(2){animation-delay:.04s}
.dept-card:nth-child(3),.folder-tile:nth-child(3),.doc-card:nth-child(3){animation-delay:.06s}
.dept-card:nth-child(4),.folder-tile:nth-child(4),.doc-card:nth-child(4){animation-delay:.08s}
.dept-card:nth-child(5),.folder-tile:nth-child(5),.doc-card:nth-child(5){animation-delay:.10s}
.dept-card:nth-child(6),.folder-tile:nth-child(6),.doc-card:nth-child(6){animation-delay:.12s}
.dept-card:nth-child(n+7),.folder-tile:nth-child(n+7),.doc-card:nth-child(n+7){animation-delay:.14s}

/* SEARCH FILTER STATE */
.is-hidden{display:none !important}
.no-results{
  grid-column:1/-1;text-align:center;padding:40px;
  color:var(--ink-3);font-size:.9rem;font-weight:500;
}

/* DOC HIGHLIGHT (from search navigation) */
.doc-card.is-highlighted{
  background:#eff6ff !important;
  border-color:rgba(37,99,235,.55) !important;
  box-shadow:0 0 0 3px rgba(37,99,235,.28), 0 12px 30px -8px rgba(37,99,235,.35) !important;
  animation:highlightPulse 1.2s ease-in-out 2;
}
@keyframes highlightPulse{
  0%,100%{box-shadow:0 0 0 3px rgba(37,99,235,.28), 0 12px 30px -8px rgba(37,99,235,.35)}
  50%{box-shadow:0 0 0 6px rgba(37,99,235,.18), 0 12px 30px -8px rgba(37,99,235,.45)}
}

/* RESPONSIVE */
@media (max-width:960px){
  .topbar{padding:14px 20px;gap:16px}
  .topbar-brand-img{width:40px;height:40px}
  .subheader{padding:10px 20px;top:69px}
  .share-strip{padding:12px 20px;gap:16px}
  .hero{padding:36px 20px 16px;gap:28px}
  .content{padding:24px 20px 60px}
  .search-box{display:none}
  .dept-grid{grid-template-columns:repeat(auto-fill,minmax(280px,1fr))}
  .docs-grid{grid-template-columns:repeat(auto-fill,minmax(200px,1fr))}
}
@media (max-width:560px){
  .topbar{padding:12px 16px}
  .topbar-badge{display:none}
  .subheader{padding:8px 16px;top:65px;flex-wrap:wrap}
  .subheader-actions{width:100%;justify-content:space-between}
  .zip-btn{padding:10px 14px;flex:1;justify-content:center}
  .share-strip{padding:10px 16px;gap:14px;justify-content:space-between}
  .share-strip-title{display:none}
  .share-stat + .share-stat{padding-left:0}
  .share-stat + .share-stat::before{display:none}
  .hero{padding:28px 16px 12px}
  .hero-stats{width:100%;gap:10px}
  .hero-stat{flex:1;min-width:0;padding:14px;text-align:center}
  .hero-stat-num{font-size:1.7rem}
  .content{padding:16px 16px 48px}
  .dept-grid{grid-template-columns:1fr}
  .folders-grid{grid-template-columns:1fr}
  .docs-grid{grid-template-columns:1fr}
  .modal{padding:0}
  .modal-box{max-height:100vh;border-radius:0}
  .modal-body iframe{height:60vh}
  .modal-body img{max-height:60vh}
  .modal-foot{padding:12px 16px}
  .mf-btn{padding:9px 14px;font-size:.78rem}
}
@media (prefers-reduced-motion:reduce){
  *,*::before,*::after{animation-duration:.001ms !important;transition-duration:.001ms !important;scroll-behavior:auto !important}
  .doc-card.is-highlighted{animation:none}
}
</style>
</head>
<body>

<div class="app">
  <main class="main">

    <!-- TOPBAR -->
    <header class="topbar">
      <div class="topbar-brand">
        <img class="topbar-brand-img" src="picture/love.jpg" alt="USAT College logo" width="44" height="44">
        <div class="topbar-brand-text">
          <h2>USAT College</h2>
          <span class="school-sub">Sagay City Inc.</span>
        </div>
      </div>
      <span class="topbar-badge">Welcome to the USAT, Compliance Portal.</span>
    </header>

    <!-- SUBHEADER -->
    <div class="subheader">
      <nav class="crumbs" aria-label="Breadcrumb">
        <?php if ($viewDept === null): ?>
          <span class="current"><?= icon_home(13) ?> All Departments</span>
        <?php else: ?>
          <?php if ($scopeDeptId === null): ?>
            <a href="?t=<?= $tq ?>"><?= icon_home(13) ?> All Departments</a>
          <?php else: ?>
            <span><?= icon_home(13) ?> All Departments</span>
          <?php endif; ?>
          <span class="sep">/</span>
          <?php if ($viewFolder === 0): ?>
            <span class="current"><?= htmlspecialchars($viewDeptName, ENT_QUOTES, 'UTF-8') ?></span>
          <?php else: ?>
            <a href="?t=<?= $tq ?>&amp;dept=<?= (int)$viewDept ?>"><?= htmlspecialchars($viewDeptName, ENT_QUOTES, 'UTF-8') ?></a>
            <?php $bcLast = count($breadcrumb) - 1; foreach ($breadcrumb as $i => $crumb): ?>
              <span class="sep">/</span>
              <?php if ($i === $bcLast): ?>
                <span class="current"><?= htmlspecialchars($crumb['name'], ENT_QUOTES, 'UTF-8') ?></span>
              <?php else: ?>
                <a href="?t=<?= $tq ?>&amp;dept=<?= (int)$viewDept ?>&amp;folder=<?= (int)$crumb['id'] ?>"><?= htmlspecialchars($crumb['name'], ENT_QUOTES, 'UTF-8') ?></a>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endif; ?>
      </nav>

      <div class="subheader-actions">
        <label class="search-box" for="docSearch">
          <?= icon_search(14) ?>
          <input type="search" id="docSearch" placeholder="Search all documents…" aria-label="Search all documents" autocomplete="off" aria-autocomplete="list" aria-controls="searchResults">
        </label>
        <div id="searchResults" class="search-dropdown" role="listbox" aria-live="polite" hidden></div>
        <?php if ($viewDept !== null): ?>
          <a class="zip-btn js-zip" href="?t=<?= $tq ?>&amp;action=zip&amp;dept=<?= (int)$viewDept ?><?= $viewFolder > 0 ? '&amp;folder=' . (int)$viewFolder : '' ?>">
            <?= icon_zip(13) ?><span>Download ZIP</span>
          </a>
        <?php else: ?>
          <a class="zip-btn js-zip" href="?t=<?= $tq ?>&amp;action=zip">
            <?= icon_zip(13) ?><span>Download All</span>
          </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- SHARE INFO STRIP -->
    <div class="share-strip" aria-label="Share info">
      <span class="share-strip-title">Share Info</span>
      <span class="share-stat"><span class="k">Expires</span> <span class="v"><?= htmlspecialchars($expiresLocal, ENT_QUOTES, 'UTF-8') ?></span></span>
      <span class="share-stat"><span class="k">Slots left</span> <span class="v"><?= htmlspecialchars($slotsLabel, ENT_QUOTES, 'UTF-8') ?></span></span>
      <span class="share-stat"><span class="k">Total files</span> <span class="v"><?= (int)$totalDocs ?></span></span>
    </div>

    <?php if ($viewDept === null): ?>
      <!-- ALL DEPARTMENTS VIEW -->
      <section class="hero">
        <div class="hero-left">
          <span class="hero-eyebrow"><span class="dot"></span> Shared with you</span>
          <h1>USAT <em>Compliance, Documents.</em></h1>
          <p class="hero-desc">Explore compliance documents from all departments of USAT College Sagay City, Inc.</p>
        </div>
        <div class="hero-stats">
          <div class="hero-stat">
            <div class="hero-stat-num"><?= count($deptCards) ?></div>
            <div class="hero-stat-label">Departments</div>
          </div>
          <div class="hero-stat">
            <div class="hero-stat-num"><?= (int)$totalInScope ?></div>
            <div class="hero-stat-label">Documents</div>
          </div>
          <div class="hero-stat">
            <div class="hero-stat-num"><?= htmlspecialchars($slotsLabel, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="hero-stat-label">Slots left</div>
          </div>
        </div>
      </section>

      <div class="content">
        <?php if (empty($deptCards)): ?>
          <div class="empty-state">
            <div class="empty-ico"><?= icon_folder_v2(44) ?></div>
            <h3>No documents yet</h3>
            <p>There are no approved documents available to share right now.</p>
          </div>
        <?php else: ?>
          <div class="dept-grid">
            <?php foreach ($deptCards as $card):
              $d = $card['dept'];
              $deptName = $d['department_name'];
              $count = (int)$card['count'];
            ?>
              <a class="dept-card" href="?t=<?= $tq ?>&amp;dept=<?= (int)$d['id'] ?>" data-name="<?= htmlspecialchars(strtolower($deptName), ENT_QUOTES, 'UTF-8') ?>">
                <div class="dept-card-head">
                  <div class="dept-icon-wrap"><?= icon_folder_v2(48) ?></div>
                  <div class="dept-title-wrap">
                    <h3 class="dept-title"><?= htmlspecialchars($deptName, ENT_QUOTES, 'UTF-8') ?></h3>
                    <div class="dept-subtitle"><strong><?= $count ?></strong> document<?= $count === 1 ? '' : 's' ?></div>
                  </div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    <?php else: ?>
      <!-- DEPARTMENT VIEW -->
      <section class="hero">
        <div class="hero-left">
          <h1><?= htmlspecialchars($viewFolder === 0 ? $viewDeptName : (end($breadcrumb)['name'] ?? $viewDeptName), ENT_QUOTES, 'UTF-8') ?></h1>
        </div>
        <div class="hero-stats">
          <div class="hero-stat">
            <div class="hero-stat-num"><?= (int)$viewCount ?></div>
            <div class="hero-stat-label">Documents</div>
          </div>
          <div class="hero-stat">
            <div class="hero-stat-num"><?= count($viewTree['folders'] ?? []) ?></div>
            <div class="hero-stat-label">Subfolders</div>
          </div>
        </div>
      </section>

      <div class="content">
        <?php
        $rendered = render_level($viewTree, $rawToken, $viewDept);
        if ($rendered === ''):
        ?>
          <div class="empty-state">
            <div class="empty-ico"><?= icon_folder_v2(44) ?></div>
            <h3>This <?= $viewFolder > 0 ? 'folder' : 'department' ?> is empty</h3>
            <p>No approved documents have been uploaded here yet.</p>
          </div>
        <?php else: ?>
          <?= $rendered ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </main>
</div>

<!-- MODAL -->
<div class="modal" id="viewModal" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-head">
      <div class="modal-head-left">
        <div class="modal-ico"><?= icon_doc_v2(18) ?></div>
        <div class="modal-title" id="modalTitle">Document</div>
      </div>
      <button type="button" class="modal-close" aria-label="Close">✕</button>
    </div>
    <div class="modal-body" id="modalBody"></div>
    <div class="modal-foot">
      <a id="modalDownload" class="mf-btn mf-btn-primary" href="#">
        <?= icon_download(14) ?> Download
      </a>
      <button type="button" class="mf-btn mf-btn-secondary js-close">Close</button>
    </div>
  </div>
</div>

<script<?= $nonceAttr ?>>
(function () {
    var TOKEN = <?= json_encode((string)$rawToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    var previewable = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
    var modal      = document.getElementById('viewModal');
    var titleEl    = document.getElementById('modalTitle');
    var bodyEl     = document.getElementById('modalBody');
    var downloadEl = document.getElementById('modalDownload');
    var lastFocused = null;

    /* ---------- Preview modal ---------- */
    function buildUrl(action, source, docId) {
        return '?t=' + encodeURIComponent(TOKEN)
             + '&action=' + action
             + '&type=' + encodeURIComponent(source)
             + '&doc=' + encodeURIComponent(docId);
    }

    function openModal(opts) {
        var ext = (opts.ext || '').toLowerCase();
        var previewUrl  = buildUrl('preview', opts.source, opts.docId);
        var downloadUrl = buildUrl('download', opts.source, opts.docId);

        lastFocused = document.activeElement;
        titleEl.textContent = opts.filename || 'Document';
        downloadEl.setAttribute('href', downloadUrl);
        bodyEl.innerHTML = '';

        if (previewable.indexOf(ext) !== -1) {
            if (ext === 'pdf') {
                var iframe = document.createElement('iframe');
                iframe.src = previewUrl;
                iframe.title = 'Document preview';
                bodyEl.appendChild(iframe);
            } else {
                var img = document.createElement('img');
                img.src = previewUrl;
                img.alt = opts.filename || 'Preview';
                bodyEl.appendChild(img);
            }
        } else {
            var fb = document.createElement('div');
            fb.className = 'modal-fallback';
            fb.innerHTML =
                '<h4>Preview not available</h4>' +
                '<p>This file type cannot be previewed in the browser. Download it to open on your device.</p>' +
                '<a class="btn-download" href="' + downloadUrl + '">Download file</a>';
            bodyEl.appendChild(fb);
        }

        modal.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
        var closeBtn = modal.querySelector('.modal-close');
        if (closeBtn) closeBtn.focus();
    }

    function closeModal() {
        modal.setAttribute('hidden', '');
        bodyEl.innerHTML = '';
        document.body.style.overflow = '';
        if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
        lastFocused = null;
    }

    function trapFocus(container, e) {
        var focusable = container.querySelectorAll('a[href],button:not([disabled]),input,select,textarea,[tabindex]:not([tabindex="-1"])');
        if (!focusable.length) return;
        var first = focusable[0], last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.js-view-doc');
        if (btn) {
            e.preventDefault();
            openModal({
                docId:    btn.getAttribute('data-doc-id') || '',
                source:   btn.getAttribute('data-source') || 'document',
                filename: btn.getAttribute('data-filename') || 'Document',
                ext:      btn.getAttribute('data-ext') || ''
            });
            return;
        }
        if (e.target === modal) { closeModal(); return; }
        if (e.target.closest && e.target.closest('.js-close, .modal-close')) closeModal();
    });

    document.addEventListener('keydown', function (e) {
        if (modal.hasAttribute('hidden')) return;
        if (e.key === 'Escape') closeModal();
        else if (e.key === 'Tab') trapFocus(modal, e);
    });

    /* ---------- ZIP loading spinner ---------- */
    document.addEventListener('click', function (e) {
        var zip = e.target.closest && e.target.closest('.js-zip');
        if (!zip || zip.classList.contains('is-loading')) return;
        zip.classList.add('is-loading');
        setTimeout(function () { zip.classList.remove('is-loading'); }, 8000);
    });

    /* ---------- Global search ---------- */
    (function () {
        var input    = document.getElementById('docSearch');
        var dropdown = document.getElementById('searchResults');
        if (!input || !dropdown) return;

        var timer = null;
        var seq   = 0;
        var data  = [];
        var activeIndex = -1;

        function clearDropdown() {
            dropdown.hidden = true;
            dropdown.innerHTML = '';
            activeIndex = -1;
        }

        function showDropdown() {
            dropdown.hidden = false;
        }

        function setActive(idx) {
            var links = dropdown.querySelectorAll('a[data-i]');
            for (var i = 0; i < links.length; i++) links[i].classList.remove('is-active');
            activeIndex = idx;
            if (idx >= 0 && links[idx]) {
                links[idx].classList.add('is-active');
                links[idx].scrollIntoView({ block: 'nearest' });
            }
        }

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
            });
        }

        function render(results) {
            data = results || [];
            if (!data.length) {
                dropdown.innerHTML = '<div class="sr-empty">No documents match your search.</div>';
                showDropdown();
                activeIndex = -1;
                return;
            }
            var html = '';
            for (var i = 0; i < data.length; i++) {
                var r = data[i];
                var name = escapeHtml(r.filename || 'Untitled');
                var dept = escapeHtml(r.dept_name || '');
                var path = escapeHtml(r.folder_path || '');
                html += '<a href="#" role="option" data-i="' + i + '">' +
                    '<div class="sr-title">' + name + '</div>' +
                    '<div class="sr-hint">' + dept + (path ? ' · ' + path : '') + '</div></a>';
            }
            dropdown.innerHTML = html;
            showDropdown();
            activeIndex = -1;
        }

        function navigate(r) {
            if (!r) return;
            var params = new URLSearchParams();
            params.set('t', TOKEN);
            params.set('dept', r.dept_id);
            if (r.folder_id) params.set('folder', r.folder_id);
            params.set('highlight', r.source + ':' + r.id);
            window.location.href = window.location.pathname + '?' + params.toString();
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            var val = input.value.trim();
            if (val.length < 1) { clearDropdown(); return; }

            timer = setTimeout(function () {
                var s = ++seq;
                var url = '?t=' + encodeURIComponent(TOKEN)
                        + '&action=search&q=' + encodeURIComponent(val);
                fetch(url, { headers: { 'Accept': 'application/json' } })
                    .then(function (res) { return res.json(); })
                    .then(function (payload) {
                        if (s !== seq) return;
                        render(payload && payload.results ? payload.results : []);
                    })
                    .catch(function () {
                        if (s !== seq) return;
                        dropdown.innerHTML = '<div class="sr-empty">Search failed. Please try again.</div>';
                        showDropdown();
                    });
            }, 180);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                clearDropdown();
                input.blur();
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (dropdown.hidden) return;
                var links = dropdown.querySelectorAll('a[data-i]');
                if (!links.length) return;
                setActive(activeIndex + 1 >= links.length ? 0 : activeIndex + 1);
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (dropdown.hidden) return;
                var linksU = dropdown.querySelectorAll('a[data-i]');
                if (!linksU.length) return;
                setActive(activeIndex <= 0 ? linksU.length - 1 : activeIndex - 1);
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                if (!data.length) return;
                navigate(data[activeIndex >= 0 ? activeIndex : 0]);
            }
        });

        dropdown.addEventListener('click', function (e) {
            var link = e.target.closest('a[data-i]');
            if (!link) return;
            e.preventDefault();
            navigate(data[parseInt(link.getAttribute('data-i'), 10)]);
        });

        dropdown.addEventListener('mousemove', function (e) {
            var link = e.target.closest('a[data-i]');
            if (!link) return;
            var idx = parseInt(link.getAttribute('data-i'), 10);
            if (idx !== activeIndex) setActive(idx);
        });

        document.addEventListener('click', function (e) {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) clearDropdown();
        });
    })();

    /* ---------- Highlight after search navigation ---------- */
    (function () {
        var h = new URLSearchParams(window.location.search).get('highlight');
        if (!h || !/^[a-z_]+:\d+$/.test(h)) return;

        var parts  = h.split(':');
        var source = parts[0];
        var id     = parseInt(parts[1], 10);
        if (!id) return;

        var btn = document.querySelector(
            '.js-view-doc[data-doc-id="' + id + '"][data-source="' + source + '"]'
        );
        if (!btn) return;
        var card = btn.closest('.doc-card');
        if (!card) return;

        card.classList.add('is-highlighted');
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });

        setTimeout(function () { card.classList.remove('is-highlighted'); }, 3800);
    })();
})();
</script>
</body>
</html>