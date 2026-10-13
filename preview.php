<?php

define('CHED_APP', true);
require_once 'config.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpWord\IOFactory as WordFactory;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetFactory;
use PhpOffice\PhpPresentation\IOFactory as PresentationFactory;
use PhpOffice\PhpPresentation\Writer\PDF\DomPDF as PresentationPdfWriter;

// Authorization check - user must be logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    http_response_code(403);
    die('Access denied');
}

// Rate limiting: max 120 preview requests per minute per user
$rateKey = 'preview_' . $_SESSION['user_id'];
if (!isset($_SESSION[$rateKey])) $_SESSION[$rateKey] = ['count' => 0, 'window' => time()];
if (time() - $_SESSION[$rateKey]['window'] >= 60) {
    $_SESSION[$rateKey] = ['count' => 0, 'window' => time()];
}
$_SESSION[$rateKey]['count']++;
if ($_SESSION[$rateKey]['count'] > 120) {
    http_response_code(429);
    die('Too many requests');
}

// Increase limits for large files
set_time_limit(300);
ini_set('memory_limit', '512M');

// Helper to determine cache path
function getCachePath($filePath, $type) {
    $cacheDir = getPreviewCacheDir();
    $key = md5($filePath . filemtime($filePath));
    return $cacheDir . "{$key}_{$type}.html";
}

// Fetch document record
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$source = $_GET['source'] ?? '';
$download = isset($_GET['download']) && $_GET['download'] == '1';
if (!$id || !$source) {
    http_response_code(400);
    die('Missing parameters');
}

// SELECT column lists include archived (authorization no longer blocks non-admin on archived; history/notifications list them)
$docCols = 'file_path, filename, uploaded_by, department_id, teacher_requirement_id, archived, status';
$phDocCols = 'file_path, filename, uploaded_by, department_id, teacher_ph_requirement_id, archived, status';
$reqCols = 'file_path, COALESCE(filename, title) as filename, created_by, department_id, 0 as archived';
$repoCols = 'file_path, COALESCE(filename, title) as filename, program_head_id, department_id, ph_document_id, ph_requirement_id, document_id, 0 as archived';

// Handle download mode - force download
if ($download) {
    $file = null;
    switch ($source) {
        case 'document':
            $stmt = $pdo->prepare("SELECT $docCols FROM documents WHERE id = ?");
            $stmt->execute([$id]);
            $file = $stmt->fetch();
            break;
        case 'ph_document':
            $stmt = $pdo->prepare("SELECT $phDocCols FROM ph_documents WHERE id = ?");
            $stmt->execute([$id]);
            $file = $stmt->fetch();
            break;
        case 'ph_requirement':
            $stmt = $pdo->prepare("SELECT $reqCols FROM ph_requirements WHERE id = ?");
            $stmt->execute([$id]);
            $file = $stmt->fetch();
            break;
        case 'ph_repository':
            $stmt = $pdo->prepare("SELECT $repoCols FROM ph_repository WHERE id = ?");
            $stmt->execute([$id]);
            $file = $stmt->fetch();
            break;
        default:
            http_response_code(400);
            die('Invalid source');
    }
    
    if (!$file) {
        http_response_code(404);
        die('Document not found');
    }

    // Fallback: if file_path is empty, resolve from linked tables
    if (empty($file['file_path']) && $source === 'ph_repository') {
        if (!empty($file['ph_requirement_id'])) {
            $reqStmt = $pdo->prepare("SELECT file_path, COALESCE(filename, title) as filename, created_by, department_id, 0 as archived FROM ph_requirements WHERE id = ?");
            $reqStmt->execute([$file['ph_requirement_id']]);
            $reqFile = $reqStmt->fetch();
            if ($reqFile && !empty($reqFile['file_path'])) {
                $file = $reqFile;
                $source = 'ph_requirement';
                $id = (int)$file['ph_requirement_id'];
            }
        }
        if (empty($file['file_path']) && !empty($file['ph_document_id'])) {
            $docStmt = $pdo->prepare("SELECT file_path, filename, uploaded_by, department_id, teacher_ph_requirement_id, archived FROM ph_documents WHERE id = ?");
            $docStmt->execute([$file['ph_document_id']]);
            $docFile = $docStmt->fetch();
            if ($docFile && !empty($docFile['file_path'])) {
                $file = $docFile;
                $source = 'ph_document';
                $id = (int)$file['ph_document_id'];
            }
        }
        if (empty($file['file_path']) && !empty($file['document_id'])) {
            $docStmt2 = $pdo->prepare("SELECT file_path, filename, uploaded_by, department_id, teacher_requirement_id, archived FROM documents WHERE id = ?");
            $docStmt2->execute([$file['document_id']]);
            $docFile2 = $docStmt2->fetch();
            if ($docFile2 && !empty($docFile2['file_path'])) {
                $file = $docFile2;
                $source = 'document';
                $id = (int)$file['document_id'];
            }
        }
    }

    if (empty($file['file_path'])) {
        http_response_code(404);
        die('Document not found');
    }
    
    // Authorization check
    $userId = (int)$_SESSION['user_id'];
    $role = $_SESSION['role'];
    $deptId = $_SESSION['department_id'] ?? null;
    $hasAccess = false;

    if ($role === 'admin') {
        $hasAccess = true;
    } elseif (in_array($role, ['department', 'unit_head'], true) && (($deptId && isset($file['department_id']) && (int)$file['department_id'] === (int)$deptId) || (isset($file['uploaded_by']) ? (int)$file['uploaded_by'] : (isset($file['created_by']) ? (int)$file['created_by'] : 0)) === $userId || ($source === 'ph_document' && !empty($file['teacher_ph_requirement_id'])))) {
        if ($source === 'ph_document' && !empty($file['teacher_ph_requirement_id'])) {
            $phDocCheck = $pdo->prepare('SELECT 1 FROM teacher_ph_requirements tpr JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id WHERE tpr.id = ? AND phr.created_by = ?');
            $phDocCheck->execute([(int)$file['teacher_ph_requirement_id'], $userId]);
            $phDocOwn = (bool)$phDocCheck->fetch();
        } else {
            $phDocOwn = true;
        }
        $hasAccess = $phDocOwn;
    } elseif (in_array($role, ['teacher', 'staff'], true)) {
        // For teacher: check uploaded_by, created_by (for templates created by teacher as PH),
        // OR if the document/requirement is assigned to them
        $teacherId = isset($file['uploaded_by']) ? (int)$file['uploaded_by'] : (isset($file['created_by']) ? (int)$file['created_by'] : 0);
        if ($teacherId === $userId) {
            $hasAccess = true;
        } else {
            // Check if this is a ph_requirement template assigned to the teacher
            if ($source === 'ph_requirement') {
                $checkAssigned = $pdo->prepare("SELECT id FROM teacher_ph_requirements WHERE ph_requirement_id = ? AND teacher_id = ?");
                $checkAssigned->execute([$id, $userId]);
                if ($checkAssigned->fetch()) {
                    $hasAccess = true;
                }
            } elseif ($source === 'document' && !empty($file['teacher_requirement_id'])) {
                $checkAssigned = $pdo->prepare("SELECT id FROM teacher_requirements WHERE id = ? AND teacher_id = ?");
                $checkAssigned->execute([$file['teacher_requirement_id'], $userId]);
                if ($checkAssigned->fetch()) {
                    $hasAccess = true;
                }
            } elseif ($source === 'ph_document' && !empty($file['teacher_ph_requirement_id'])) {
                $checkAssigned = $pdo->prepare("SELECT id FROM teacher_ph_requirements WHERE id = ? AND teacher_id = ?");
                $checkAssigned->execute([$file['teacher_ph_requirement_id'], $userId]);
                if ($checkAssigned->fetch()) {
                    $hasAccess = true;
                }
            }
        }
    }
    // Hardening for ph_repository: strictly own repository/department (dynamic per-file check)
    if ($source === 'ph_repository' && $role !== 'admin' && $hasAccess) {
        $phOwner = isset($file['program_head_id']) ? (int)$file['program_head_id'] : 0;
        $deptOwner = isset($file['department_id']) ? (int)$file['department_id'] : 0;
        // Program Head (role department) must own the file; department must also match when available
        if ($phOwner !== $userId) $hasAccess = false;
        if ($deptId && $deptOwner && (int)$deptOwner !== (int)$deptId) $hasAccess = false;
    }

    if (!$hasAccess) {
        http_response_code(403);
        die('Access denied');
    }
    
    $file_path = securePath($file['file_path']);
    if (!$file_path) { http_response_code(404); die('File not found or unreadable'); }
    if (!is_readable($file_path)) { http_response_code(404); die('File not readable'); }
    
    $ext = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
    $mimeTypes = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'docs' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
    ];
    
    $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . rawurlencode($file['filename']) . '"');
    header('Content-Length: ' . filesize($file_path));
    readfile($file_path);
    exit;
}

$file = null;
switch ($source) {
    case 'document':
        $stmt = $pdo->prepare("SELECT $docCols FROM documents WHERE id = ?");
        $stmt->execute([$id]);
        $file = $stmt->fetch();
        break;
    case 'ph_document':
        $stmt = $pdo->prepare("SELECT $phDocCols FROM ph_documents WHERE id = ?");
        $stmt->execute([$id]);
        $file = $stmt->fetch();
        break;
    case 'ph_requirement':
        $stmt = $pdo->prepare("SELECT $reqCols FROM ph_requirements WHERE id = ?");
        $stmt->execute([$id]);
        $file = $stmt->fetch();
        break;
    case 'ph_repository':
        $stmt = $pdo->prepare("SELECT $repoCols FROM ph_repository WHERE id = ?");
        $stmt->execute([$id]);
        $file = $stmt->fetch();
        break;
    default:
        http_response_code(400);
        die('Invalid source');
}

if (!$file) {
    http_response_code(404);
    die('Document not found');
}

// Fallback: if file_path is empty, resolve from linked tables (mirrors download handler logic)
if (empty($file['file_path']) && $source === 'ph_repository') {
    if (!empty($file['ph_requirement_id'])) {
        $reqStmt = $pdo->prepare("SELECT file_path, COALESCE(filename, title) as filename, created_by, department_id, 0 as archived FROM ph_requirements WHERE id = ?");
        $reqStmt->execute([$file['ph_requirement_id']]);
        $reqFile = $reqStmt->fetch();
        if ($reqFile && !empty($reqFile['file_path'])) {
            $file = $reqFile;
            $source = 'ph_requirement';
            $id = (int)$file['ph_requirement_id'];
        }
    }
    if (empty($file['file_path']) && !empty($file['ph_document_id'])) {
        $docStmt = $pdo->prepare("SELECT file_path, filename, uploaded_by, department_id, teacher_ph_requirement_id, archived FROM ph_documents WHERE id = ?");
        $docStmt->execute([$file['ph_document_id']]);
        $docFile = $docStmt->fetch();
        if ($docFile && !empty($docFile['file_path'])) {
            $file = $docFile;
            $source = 'ph_document';
            $id = (int)$file['ph_document_id'];
        }
    }
    if (empty($file['file_path']) && !empty($file['document_id'])) {
        $docStmt2 = $pdo->prepare("SELECT file_path, filename, uploaded_by, department_id, teacher_requirement_id, archived FROM documents WHERE id = ?");
        $docStmt2->execute([$file['document_id']]);
        $docFile2 = $docStmt2->fetch();
        if ($docFile2 && !empty($docFile2['file_path'])) {
            $file = $docFile2;
            $source = 'document';
            $id = (int)$file['document_id'];
        }
    }
}

if (empty($file['file_path'])) {
    http_response_code(404);
    die('Document not found');
}

// Authorization check
$userId = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];
$deptId = $_SESSION['department_id'] ?? null;
$hasAccess = false;

if ($role === 'admin') {
    $hasAccess = true;
} elseif (in_array($role, ['department', 'unit_head'], true) && (($deptId && isset($file['department_id']) && (int)$file['department_id'] === (int)$deptId) || (isset($file['uploaded_by']) ? (int)$file['uploaded_by'] : (isset($file['created_by']) ? (int)$file['created_by'] : 0)) === $userId || ($source === 'ph_document' && !empty($file['teacher_ph_requirement_id'])))) {
    if ($source === 'ph_document' && !empty($file['teacher_ph_requirement_id'])) {
        $phDocCheck = $pdo->prepare('SELECT 1 FROM teacher_ph_requirements tpr JOIN ph_requirements phr ON tpr.ph_requirement_id = phr.id WHERE tpr.id = ? AND phr.created_by = ?');
        $phDocCheck->execute([(int)$file['teacher_ph_requirement_id'], $userId]);
        $phDocOwn = (bool)$phDocCheck->fetch();
        $hasAccess = $phDocOwn;
    } else {
        $hasAccess = true;
    }
} elseif (in_array($role, ['teacher', 'staff'], true)) {
        // For teacher: check uploaded_by, created_by (for templates created by teacher as PH),
        // OR if the document/requirement is assigned to them
        $teacherId = isset($file['uploaded_by']) ? (int)$file['uploaded_by'] : (isset($file['created_by']) ? (int)$file['created_by'] : 0);
        if ($teacherId === $userId) {
            $hasAccess = true;
        } else {
            // Check if this is a ph_requirement template assigned to the teacher
            if ($source === 'ph_requirement') {
                $checkAssigned = $pdo->prepare("SELECT id FROM teacher_ph_requirements WHERE ph_requirement_id = ? AND teacher_id = ?");
                $checkAssigned->execute([$id, $userId]);
                if ($checkAssigned->fetch()) {
                    $hasAccess = true;
                }
            } elseif ($source === 'document' && !empty($file['teacher_requirement_id'])) {
                $checkAssigned = $pdo->prepare("SELECT id FROM teacher_requirements WHERE id = ? AND teacher_id = ?");
                $checkAssigned->execute([$file['teacher_requirement_id'], $userId]);
                if ($checkAssigned->fetch()) {
                    $hasAccess = true;
                }
            } elseif ($source === 'ph_document' && !empty($file['teacher_ph_requirement_id'])) {
                $checkAssigned = $pdo->prepare("SELECT id FROM teacher_ph_requirements WHERE id = ? AND teacher_id = ?");
                $checkAssigned->execute([$file['teacher_ph_requirement_id'], $userId]);
                if ($checkAssigned->fetch()) {
                    $hasAccess = true;
                }
            }
        }
}
// Hardening for ph_repository: strictly own repository/department (dynamic per-file check)
if ($source === 'ph_repository' && $role !== 'admin' && $hasAccess) {
    $phOwner = isset($file['program_head_id']) ? (int)$file['program_head_id'] : 0;
    $deptOwner = isset($file['department_id']) ? (int)$file['department_id'] : 0;
    if ($phOwner !== $userId) $hasAccess = false;
    if ($deptId && $deptOwner && (int)$deptOwner !== (int)$deptId) $hasAccess = false;
}

if (!$hasAccess) {
    http_response_code(403);
    die('Access denied');
}

$file_path = securePath($file['file_path']);
if (!$file_path || !is_readable($file_path)) {
    http_response_code(404);
    die('File not found or unreadable');
}

$ext = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
if (empty($ext) && !empty($file['file_path'])) {
    $ext = strtolower(pathinfo($file['file_path'], PATHINFO_EXTENSION));
}

// ---------- PDF and Images: Stream directly ----------
if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'])) {
    $mime = match ($ext) {
        'pdf'   => 'application/pdf',
        'jpg', 'jpeg' => 'image/jpeg',
        'png'   => 'image/png',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        default => 'application/octet-stream'
    };
    // Clear output buffers to prevent gzip corruption of binary data
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . rawurlencode($file['filename']) . '"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($file_path);
    exit;
}

// ---------- Word Documents ----------
if (in_array($ext, ['doc', 'docx', 'docs'])) {
    // Check if ZipArchive extension is available
    if (!class_exists('ZipArchive')) {
        // Show user-friendly message with download option
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Preview Unavailable</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 40px; background: #f5f5f5; }
                    .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); text-align: center; }
                    h2 { color: #d97706; }
                    p { color: #666; }
                    .btn { display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; margin-top: 15px; }
                    .btn:hover { background: #1d4ed8; }
                </style>
            </head>
            <body>
                <div class="container">
                    <h2>Preview Not Available</h2>
                    <p>Word document preview requires the ZIP extension to be enabled on the server.</p>
                    <p>Please contact your system administrator to enable the php_zip extension.</p>
                    <a href="preview.php?id=' . $id . '&source=' . $source . '&download=1" class="btn">Download Document</a>
                </div>
            </body>
            </html>';
        exit;
    }
    
    $cachePath = getCachePath($file_path, 'word');
    if (file_exists($cachePath)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($cachePath);
        exit;
    }

    try {
        $phpWord = WordFactory::load($file_path);
        $htmlWriter = new \PhpOffice\PhpWord\Writer\HTML($phpWord);
        ob_start();
        $htmlWriter->save('php://output');
        $html = ob_get_clean();

        // Wrap in responsive HTML document
        $finalHtml = '<!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>' . htmlspecialchars($file['filename']) . '</title>
                <style>
                    body {
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                        margin: 0;
                        padding: 20px;
                        line-height: 1.5;
                        background: #f5f5f5;
                        color: #333;
                    }
                    .document-container {
                        max-width: 100%;
                        background: white;
                        padding: 20px;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                        border-radius: 8px;
                    }
                    table {
                        border-collapse: collapse;
                        width: 100%;
                        margin: 1em 0;
                    }
                    td, th {
                        border: 1px solid #ddd;
                        padding: 8px;
                        vertical-align: top;
                    }
                    img {
                        max-width: 100%;
                        height: auto;
                    }
                    @media (max-width: 768px) {
                        body { padding: 10px; }
                        td, th { padding: 4px; }
                    }
                </style>
            </head>
            <body>
                <div class="document-container">' . $html . '</div>
            </body>
            </html>';

        // Cache the result
        file_put_contents($cachePath, $finalHtml);

        header('Content-Type: text/html; charset=utf-8');
        echo $finalHtml;
        exit;
    } catch (Exception $e) {
        error_log("Word conversion error: " . $e->getMessage());
        header('Content-Type: text/html; charset=utf-8');
        $downloadUrl = "preview.php?id={$id}&source={$source}&download=1";
        echo '<!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Preview Error</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 40px; background: #f5f5f5; }
                    .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); text-align: center; }
                    h2 { color: #d97706; }
                    p { color: #666; }
                    .btn { display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; margin-top: 15px; }
                    .btn:hover { background: #1d4ed8; }
                </style>
            </head>
            <body>
                <div class="container">
                    <h2>Preview Not Available</h2>
                    <p>Unable to preview this Word document. Please download to view.</p>
                    <a href="' . htmlspecialchars($downloadUrl) . '" class="btn" download>Download Document</a>
                </div>
            </body>
            </html>';
        exit;
    }
}

// ---------- Excel Documents ----------
if (in_array($ext, ['xls', 'xlsx'])) {
    // Check if ZipArchive extension is available
    if (!class_exists('ZipArchive')) {
        // Show user-friendly message with download option
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Preview Unavailable</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 40px; background: #f5f5f5; }
                    .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); text-align: center; }
                    h2 { color: #d97706; }
                    p { color: #666; }
                    .btn { display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; margin-top: 15px; }
                    .btn:hover { background: #1d4ed8; }
                </style>
            </head>
            <body>
                <div class="container">
                    <h2>Preview Not Available</h2>
                    <p>Excel document preview requires the ZIP extension to be enabled on the server.</p>
                    <p>Please contact your system administrator to enable the php_zip extension.</p>
                    <a href="preview.php?id=' . $id . '&source=' . $source . '&download=1" class="btn">Download Document</a>
                </div>
            </body>
            </html>';
        exit;
    }
    
    $cachePath = getCachePath($file_path, 'excel');
    if (file_exists($cachePath)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($cachePath);
        exit;
    }

    try {
        $spreadsheet = SpreadsheetFactory::load($file_path);
        $htmlWriter = new \PhpOffice\PhpSpreadsheet\Writer\Html($spreadsheet);
        ob_start();
        $htmlWriter->save('php://output');
        $html = ob_get_clean();

        // Wrap with responsive CSS
        $finalHtml = '<!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>' . htmlspecialchars($file['filename']) . '</title>
                <style>
                    body {
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                        margin: 0;
                        padding: 20px;
                        background: #f5f5f5;
                    }
                    .excel-container {
                        max-width: 100%;
                        overflow-x: auto;
                        background: white;
                        padding: 20px;
                        border-radius: 8pZx;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                    }
                    table {
                        border-collapse: collapse;
                        min-width: 100%;
                    }
                    td, th {
                        border: 1px solid #ddd;
                        padding: 8px;
                        text-align: left;
                        white-space: nowrap;
                    }
                    @media (max-width: 768px) {
                        td, th { padding: 4px; }
                    }
                </style>
            </head>
            <body>
                <div class="excel-container">' . $html . '</div>
            </body>
            </html>';

        file_put_contents($cachePath, $finalHtml);

        header('Content-Type: text/html; charset=utf-8');
        echo $finalHtml;
        exit;
    } catch (Exception $e) {
        error_log("Excel conversion error: " . $e->getMessage());
        header('Content-Type: text/html; charset=utf-8');
        $downloadUrl = "preview.php?id={$id}&source={$source}&download=1";
        echo '<!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Preview Error</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 40px; background: #f5f5f5; }
                    .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); text-align: center; }
                    h2 { color: #d97706; }
                    p { color: #666; }
                    .btn { display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; margin-top: 15px; }
                    .btn:hover { background: #1d4ed8; }
                </style>
            </head>
            <body>
                <div class="container">
                    <h2>Preview Not Available</h2>
                    <p>Unable to preview this Excel file. Please download to view.</p>
                    <a href="' . htmlspecialchars($downloadUrl) . '" class="btn" download>Download Document</a>
                </div>
            </body>
            </html>';
        exit;
    }
}

// ---------- PowerPoint Presentations ----------
if (in_array($ext, ['ppt', 'pptx'])) {
    // Check if ZipArchive extension is available
    if (!class_exists('ZipArchive')) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Preview Unavailable</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 40px; background: #f5f5f5; }
                    .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); text-align: center; }
                    h2 { color: #d97706; }
                    p { color: #666; }
                    .btn { display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; margin-top: 15px; }
                    .btn:hover { background: #1d4ed8; }
                </style>
            </head>
            <body>
                <div class="container">
                    <h2>Preview Not Available</h2>
                    <p>PowerPoint preview requires the ZIP extension to be enabled on the server.</p>
                    <p>Please contact your system administrator to enable the php_zip extension.</p>
                    <a href="preview.php?id=' . $id . '&source=' . $source . '&download=1" class="btn">Download Document</a>
                </div>
            </body>
            </html>';
        exit;
    }

    // Use .pdf extension for PowerPoint cache
    $cacheDir = getPreviewCacheDir();
    $cacheKey = md5($file_path . filemtime($file_path));
    $cachePath = $cacheDir . "{$cacheKey}_ppt.pdf";
    
    if (file_exists($cachePath)) {
        // Clear output buffers to prevent gzip corruption of binary PDF
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($cachePath));
        readfile($cachePath);
        exit;
    }

    try {
        $presentation = PresentationFactory::load($file_path);
        
        // Use PDF adapter for better rendering
        $pdfWriter = new PresentationPdfWriter($presentation);
        
        // Save to cache file
        $pdfWriter->save($cachePath);

        // Clear output buffers to prevent gzip corruption of binary PDF
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($cachePath));
        readfile($cachePath);
        exit;
    } catch (Exception $e) {
        error_log("PowerPoint conversion error: " . $e->getMessage());
        header('Content-Type: text/html; charset=utf-8');
        $downloadUrl = "preview.php?id={$id}&source={$source}&download=1";
        echo '<!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Preview Error</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 40px; background: #f5f5f5; }
                    .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); text-align: center; }
                    h2 { color: #d97706; }
                    p { color: #666; }
                    .btn { display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; margin-top: 15px; }
                    .btn:hover { background: #1d4ed8; }
                </style>
            </head>
            <body>
                <div class="container">
                    <h2>Preview Not Available</h2>
                    <p>Unable to preview this PowerPoint file. Please download to view.</p>
                    <a href="' . htmlspecialchars($downloadUrl) . '" class="btn" download>Download Document</a>
                </div>
            </body>
            </html>';
        exit;
    }
}

// ---------- Unsupported Format ----------
header('Content-Type: text/html; charset=utf-8');
http_response_code(415);
$downloadUrl = "preview.php?id={$id}&source={$source}&download=1";
echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Unsupported Format</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 40px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); text-align: center; }
        h2 { color: #d97706; }
        p { color: #666; }
        .btn { display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; margin-top: 15px; }
        .btn:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Preview Not Available</h2>
        <p>The file type <strong>' . htmlspecialchars($ext) . '</strong> cannot be previewed directly.</p>
        <a href="' . htmlspecialchars($downloadUrl) . '" class="btn" download>Download File</a>
    </div>
</body>
</html>';
exit;