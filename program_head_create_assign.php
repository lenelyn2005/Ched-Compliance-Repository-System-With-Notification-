<?php
define('CHED_APP', true);
require_once 'config.php';
requireDepartment();


$deptId = (int) $_SESSION['department_id'];
$phId   = (int) $_SESSION['user_id'];
// Orphaned PH (department deleted): store NULL dept on new rows (FK is SET NULL).
$deptBind = $deptId > 0 ? (int)$deptId : null;
$error  = '';
$success = '';

$phSlug = phRootSlug($pdo, $phId) ?: ('ph_' . $phId);
$phUploadDir = UPLOAD_PATH . 'templates' . DIRECTORY_SEPARATOR . $phSlug . DIRECTORY_SEPARATOR;
if (!is_dir($phUploadDir)) {
    mkdir($phUploadDir, 0755, true);
}

// Delete a requirement template by its DB file_path (never by the
// original uploaded name — physical storage names are system-generated).
// Returns false when the file still exists after the unlink attempt, so callers
// can report the orphan instead of failing silently (C-5).
function deleteRequirementFile(?string $relativePath): bool {
    global $phSlug;
    if (!$relativePath) return true;
    $path = (strpos($relativePath, '/') !== false || strpos($relativePath, '\\') !== false)
        ? securePath($relativePath)
        : securePath('templates/' . $phSlug . '/' . $relativePath);
    if ($path && is_file($path)) {
        return unlink($path);
    }
    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCsrfToken();
    $action = $_POST['action'];

    if ($action === 'create_requirement') {
        $reqTitle = trim($_POST['req_title'] ?? '');
        $reqDesc  = trim($_POST['req_description'] ?? '');
        $reqDeadline = !empty($_POST['req_deadline']) ? $_POST['req_deadline'] : null;
        if ($reqDeadline) { $dt = DateTime::createFromFormat('m/d/Y', $reqDeadline); $reqDeadline = $dt ? $dt->format('Y-m-d') : $reqDeadline; }
        
        // Validate deadline - must be in the future if provided
        if ($reqDeadline) {
            $deadlineDate = strtotime($reqDeadline);
            $todayDate = strtotime(date('Y-m-d'));
            if ($deadlineDate < $todayDate) {
                $error = 'Deadline must be a future date.';
            }
        }
        
        if (empty($reqTitle)) { $error = 'Requirement title is required.'; } 
        else {
            $originalFilename = null; $filePath = null;
            if (!empty($_FILES['req_file']['name'])) {
                $file = $_FILES['req_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) { $error = 'File upload failed. Please try again.'; }
                elseif (!in_array($ext, ALLOWED_EXTENSIONS)) { $error = 'Invalid file type.'; } 
                elseif ($file['size'] > maxFileSizeBytesFor($ext)) { $error = 'File too large (max ' . maxFileSizeLabelFor($ext) . ').'; } 
                else {
                    // C-1 fail closed: a false $mime would slip past the allow-list
                    // via the extension fallback map (isDangerousMime(false) === false).
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = $finfo !== false ? finfo_file($finfo, $file['tmp_name']) : false;
                    if ($finfo !== false) finfo_close($finfo);
                    if ($mime === false) {
                        $error = 'Could not read the uploaded file to verify its type. Please try again.';
                    } elseif (!in_array($mime, ALLOWED_MIMES)) {
                        // ponytail: ext allowlist above + dangerous-mime gate only; the
                        // per-ext mime map rejected real docx/pptx (finfo: application/zip).
                        if (isDangerousMime($mime)) { $error = 'Invalid file type.'; }
                    }
                }
                if (empty($error)) {
                    $originalFilename = $file['name']; // Store original filename for display/audit
                    $uniqueFilename = allocateStorageFilename($phUploadDir, $reqTitle, $ext);
                    $filePath = 'templates/' . $phSlug . '/' . $uniqueFilename;
                    if (!move_uploaded_file($file['tmp_name'], $phUploadDir . $uniqueFilename)) {
                        @unlink($phUploadDir . $uniqueFilename);
                        $error = 'Failed to save uploaded file.';
                    }
                }
            }
            if (empty($error)) {
                // The file was already moved; remove it if no row gets written (no
                // committed row can reference it at this point).
                $movedFullPath = $filePath !== null ? $phUploadDir . $uniqueFilename : null;
                try {
                    $stmt = $pdo->prepare("INSERT INTO ph_requirements (department_id, created_by, title, description, filename, file_path, created_at, deadline) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)");
                    $stmt->execute([$deptBind, $phId, $reqTitle, $reqDesc, $originalFilename, $filePath, $reqDeadline]);
                } catch (Throwable $e) {
                    if ($movedFullPath !== null) @unlink($movedFullPath);
                    $error = 'Could not save the requirement. Please try again.';
                }
                if (empty($error)) {
                    $newReqId = (int)$pdo->lastInsertId();
                    if ($filePath !== null) {
                        finalizeStorageFilename($pdo, $newReqId, 'ph_requirements', $reqTitle, $ext);
                    }
                    logAudit($pdo, 'create_ph_requirement', phRoleShort() . " created: $reqTitle" . ($reqDeadline ? " (Deadline: $reqDeadline)" : ""), 'ph_requirement', $newReqId);
                    $success = "Requirement created successfully.";
                }
            }
        }
    }

    if ($action === 'update_requirement') {
        $reqId = (int)$_POST['req_id'];
        $reqTitle = trim($_POST['req_title'] ?? '');
        $reqDesc = trim($_POST['req_description'] ?? '');
        $reqDeadline = isset($_POST['req_deadline']) ? ($_POST['req_deadline'] ?: null) : null;
        if ($reqDeadline) { $dt = DateTime::createFromFormat('m/d/Y', $reqDeadline); $reqDeadline = $dt ? $dt->format('Y-m-d') : $reqDeadline; }
        
        // Validate deadline if provided
        if ($reqDeadline) {
            $deadlineTimestamp = strtotime($reqDeadline);
            if ($deadlineTimestamp <= time()) {
                $error = 'Deadline must be a future date and time.';
            }
        }
        
        $check = $pdo->prepare("SELECT id, filename, file_path FROM ph_requirements WHERE id = ? AND (department_id = ? OR department_id IS NULL) AND created_by = ?");
        $check->execute([$reqId, $deptId, $phId]);
        $old = $check->fetch();
        if ($old && !empty($reqTitle) && empty($error)) {
            // Keep the system-generated physical path; only the original-name
            // metadata column tracks the uploaded file's display name.
            $filename = $old['filename'];
            $filePath = $old['file_path'];
            $replacedOldPath = null; // set only when a replacement file was moved into place
            $newFull = null;
            if (!empty($_FILES['req_file']['name'])) {
                $file = $_FILES['req_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    $error = 'File upload failed. Please try again.';
                } elseif (!in_array($ext, ALLOWED_EXTENSIONS)) {
                    $error = 'Invalid file type.';
                } elseif ($file['size'] > maxFileSizeBytesFor($ext)) {
                    $error = 'File too large (max ' . maxFileSizeLabelFor($ext) . ').';
                } else {
                    // C-1 fail closed: a false $mime would slip past the allow-list
                    // via the extension fallback map (isDangerousMime(false) === false).
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = $finfo !== false ? finfo_file($finfo, $file['tmp_name']) : false;
                    if ($finfo !== false) finfo_close($finfo);
                    if ($mime === false) {
                        $error = 'Could not read the uploaded file to verify its type. Please try again.';
                    } elseif (!in_array($mime, ALLOWED_MIMES)) {
                        // ponytail: ext allowlist above + dangerous-mime gate only; the
                        // per-ext mime map rejected real docx/pptx (finfo: application/zip).
                        if (isDangerousMime($mime)) { $error = 'Invalid file type.'; }
                    }
                }
                if (empty($error)) {
                    $newFilename = allocateStorageFilename($phUploadDir, $reqTitle, $ext);
                    if (move_uploaded_file($file['tmp_name'], $phUploadDir . $newFilename)) {
                        $filename = $file['name'];
                        $filePath = 'templates/' . $phSlug . '/' . $newFilename;
                        $replacedOldPath = $old['file_path'];
                        $newFull = $phUploadDir . $newFilename;
                    } else {
                        @unlink($phUploadDir . $newFilename);
                        $error = 'Failed to save uploaded file.';
                    }
                }
            }
            if (empty($error)) {
                try {
                    $pdo->prepare("UPDATE ph_requirements SET title = ?, description = ?, filename = ?, file_path = ?, deadline = ? WHERE id = ?")
                        ->execute([$reqTitle, $reqDesc, $filename, $filePath, $reqDeadline, $reqId]);
                } catch (Throwable $e) {
                    // Row never moved off the old path, so the new file is unreferenced.
                    if ($newFull !== null) @unlink($newFull);
                    $error = 'Could not save the requirement. Please try again.';
                }
                if (empty($error)) {
                    refreshPhRequirementExpiry($pdo, $reqId);
                    // Delete the old template only now that the row references the new
                    // file; a failed delete leaves a reported orphan, never a dangling path.
                    if ($replacedOldPath !== null && !deleteRequirementFile($replacedOldPath)) {
                        logAudit($pdo, 'upload_failed', "Old requirement template could not be deleted: $replacedOldPath", 'ph_requirement', $reqId);
                    }
                    $success = "Requirement updated.";
                }
            }
        }
    }

    if ($action === 'delete_requirement') {
        $reqId = (int)$_POST['req_id'];
        $check = $pdo->prepare("SELECT id, title, filename, file_path FROM ph_requirements WHERE id = ? AND (department_id = ? OR department_id IS NULL) AND created_by = ?");
        $check->execute([$reqId, $deptId, $phId]);
        $req = $check->fetch();
        if ($req) {
            $wipeStmt = $pdo->prepare("SELECT teacher_id FROM teacher_ph_requirements WHERE ph_requirement_id = ?");
            $wipeStmt->execute([$reqId]);
            $assignedTeachers = $wipeStmt->fetchAll(PDO::FETCH_COLUMN);
            $pdo->beginTransaction();
            try {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                $pdo->prepare("UPDATE ph_documents SET teacher_ph_requirement_id = NULL WHERE teacher_ph_requirement_id IN (SELECT id FROM teacher_ph_requirements WHERE ph_requirement_id = ?)")->execute([$reqId]);
                $pdo->prepare("DELETE FROM teacher_ph_requirements WHERE ph_requirement_id = ?")->execute([$reqId]);
                $pdo->prepare("DELETE FROM ph_requirements WHERE id = ?")->execute([$reqId]);
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                $pdo->commit();
                foreach ($assignedTeachers as $tid) {
                    createNotification($pdo, (int)$tid, 'Requirement Removed', "The requirement assigned to you was deleted by your " . currentRoleName() . ": \"{$req['title']}\".", 'danger');
                }
                deleteRequirementFile($req['file_path']);
                $success = "Requirement deleted. Associated documents remain in the repository.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                error_log('[CHED] delete_requirement failed: ' . $e->getMessage());
                $error = "Failed to delete requirement.";
            }
        }
    }

    if ($action === 'assign_ph_req') {
        $phReqId = (int)$_POST['ph_req_id'];
        $teacherIds = $_POST['teacher_ids'] ?? [];
        
        if ($phReqId && !empty($teacherIds)) {
            $checkReq = $pdo->prepare("SELECT title, assigned FROM ph_requirements WHERE id = ? AND (department_id = ? OR department_id IS NULL) AND created_by = ?");
            $checkReq->execute([$phReqId, $deptId, $phId]);
            $phReq = $checkReq->fetch();
            
            if ($phReq) {
                // Teacher name map (single query instead of one query per teacher)
                $teacherNameMap = [];
                foreach ($pdo->query("SELECT id, name FROM users WHERE role IN ('teacher','staff') AND department_id = " . (int)$deptId)->fetchAll() as $nu) {
                    $teacherNameMap[(int)$nu['id']] = $nu['name'];
                }

                // Check which teachers are already assigned
                $assignedTeachers = $pdo->prepare("SELECT teacher_id FROM teacher_ph_requirements WHERE ph_requirement_id = ?");
                $assignedTeachers->execute([$phReqId]);
                $alreadyAssigned = array_column($assignedTeachers->fetchAll(), 'teacher_id');
                
                $newlyAssigned = 0;
                $skippedAlreadyAssigned = 0;
                $assignedTeacherNames = [];
                $newTeacherIds = [];
                
                // Bulk assignment runs inside a transaction. Notifications are
                // deferred until after commit so emails are never sent while the
                // transaction is open, and are not lost if it rolls back.
                try {
                    $pdo->beginTransaction();
                    foreach ($teacherIds as $tid) {
                        $tid = (int)$tid;
                        
                        // Check if assignment already exists
                        if (in_array($tid, $alreadyAssigned)) {
                            $skippedAlreadyAssigned++;
                            continue;
                        }
                        
                        // Insert new assignment with requirement_type = 'program_head_requirement'
                        $insertStmt = $pdo->prepare("INSERT INTO teacher_ph_requirements (teacher_id, ph_requirement_id, assigned_at, requirement_type) VALUES (?, ?, NOW(), 'program_head_requirement')");
                        $insertStmt->execute([$tid, $phReqId]);
                        $newlyAssigned++;
                        $newTeacherIds[] = $tid;
                        
                        // Defer notification until after commit
                        sendTemplateToTeacher($pdo, $tid, $phReqId, $phReq['title'], true);
                        
                        $assignedTeacherNames[] = $teacherNameMap[$tid] ?? ('User #' . $tid);
                    }
                    
                    if ($newlyAssigned > 0) {
                        // Update requirement assignment status
                        $pdo->prepare("UPDATE ph_requirements SET assigned = 1, assigned_at = NOW() WHERE id = ?")->execute([$phReqId]);
                    }
                    $pdo->commit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $newlyAssigned = 0;
                    $newTeacherIds = [];
                    $assignedTeacherNames = [];
                    $error = "Assignment failed: " . $e->getMessage();
                }
                
                // Notify teachers after the transaction commits
                if (empty($error) && $newlyAssigned > 0) {
                    foreach ($newTeacherIds as $tid) {
                        sendTemplateToTeacher($pdo, $tid, $phReqId, $phReq['title']);
                    }
                }
                
                // Build success message
                if (empty($error)) {
                    if ($newlyAssigned > 0) {
                        $msg = "Requirement '{$phReq['title']}' assigned to $newlyAssigned faculty member(s): " . implode(', ', $assignedTeacherNames);
                        if ($skippedAlreadyAssigned > 0) {
                            $msg .= " ($skippedAlreadyAssigned already assigned - skipped)";
                        }
                        $success = $msg;
                    } else {
                        $success = "All selected faculty are already assigned to this requirement. No new assignments made.";
                    }
                }
            } else {
                $error = "Requirement not found or you don't have permission to assign it.";
            }
        } else {
            $error = "Please select a requirement and at least one faculty member.";
        }
    }

    if ($action === 'unassign_ph_req') {
        $phReqId = (int)$_POST['ph_req_id'];
        $tStmt = $pdo->prepare("SELECT teacher_id FROM teacher_ph_requirements WHERE ph_requirement_id = ?");
        $tStmt->execute([$phReqId]);
        $assignedTeachers = $tStmt->fetchAll(PDO::FETCH_COLUMN);
        $titleStmt = $pdo->prepare("SELECT title FROM ph_requirements WHERE id = ?");
        $titleStmt->execute([$phReqId]);
        $phReqTitle = $titleStmt->fetchColumn() ?: 'requirement';
        $pdo->prepare("DELETE FROM teacher_ph_requirements WHERE ph_requirement_id = ?")->execute([$phReqId]);
        $pdo->prepare("UPDATE ph_requirements SET assigned = 0, assigned_at = NULL WHERE id = ?")->execute([$phReqId]);
        foreach ($assignedTeachers as $tid) {
            createNotification($pdo, (int)$tid, 'Requirement Unassigned', "You were unassigned from: \"$phReqTitle\".", 'warning');
        }
        $success = "Requirement unassigned from all faculty.";
    }

    if ($action === 'direct_upload') {
        $title = trim($_POST['upload_title'] ?? '');
        $description = trim($_POST['upload_description'] ?? '');
        if (!empty($title) && !empty($_FILES['upload_file']['name'])) {
            // Quick Direct Upload always forwards to Admin automatically.
            // The visible "Automatically forward to Admin" checkbox has been removed;
            // forwarding is now part of the successful upload workflow.
            $result = uploadPHDocumentDirect($pdo, $phId, $deptId, $_FILES['upload_file'], $title, true, $description);
            if (is_int($result)) {
                $success = "Document uploaded and forwarded to Admin for review.";
            } else {
                // Forwarding/save failed — do NOT report success.
                $error = is_string($result) ? $result : 'Upload failed. Please try again.';
            }
        } else {
            $error = 'Document title and file are required.';
}
    }

}
$allTeachers = $pdo->prepare("SELECT id, name, email FROM users WHERE role IN ('teacher','staff') AND department_id = ? ORDER BY name");
$allTeachers->execute([$deptId]);
$allTeachers = $allTeachers->fetchAll();

$phRequirements = $pdo->prepare("
    SELECT pr.*, 
           (SELECT COUNT(*) FROM teacher_ph_requirements tpr WHERE tpr.ph_requirement_id = pr.id) as assigned_count,
           (SELECT COUNT(DISTINCT tpr2.teacher_id) 
             FROM teacher_ph_requirements tpr2
             JOIN ph_documents pd ON pd.teacher_ph_requirement_id = tpr2.id AND pd.status IN ('approved_program_head', 'approved_admin')
             WHERE tpr2.ph_requirement_id = pr.id) as completed_count
    FROM ph_requirements pr 
    WHERE (pr.department_id = ? OR pr.department_id IS NULL) AND pr.created_by = ? 
    ORDER BY pr.created_at DESC
");
$phRequirements->execute([$deptId, $phId]);
$phRequirements = $phRequirements->fetchAll();

$assignableRequirements = array_filter($phRequirements, function($r) {
    return empty($r['assigned_count']) || $r['assigned_count'] == 0;
});

// Get already assigned teacher IDs for each assignable requirement
$assignedTeachersMap = [];
foreach ($assignableRequirements as $req) {
    $stmt = $pdo->prepare("
        SELECT teacher_id FROM teacher_ph_requirements 
        WHERE ph_requirement_id = ?
    ");
    $stmt->execute([$req['id']]);
    $assignedTeachersMap[$req['id']] = array_column($stmt->fetchAll(), 'teacher_id');
}

$pageTitle = 'Management Hub';

// SVG icon helper (same set as program_head_dashboard.php — UI only)
if (!function_exists('render_dashboard_icon')) {
    function render_dashboard_icon(string $name): string {
        $icons = [
            'plus' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/></svg>',
            'upload' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>',
            'clipboard' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>',
            'users' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'eye' => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>',
            'pencil' => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>',
            'trash' => '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
            'check' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
            'check-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
            'x-circle' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>',
            'file' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
            'send' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>',
            'clock' => '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
            'pin' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="17" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24Z"/></svg>',
        ];
        return $icons[$name] ?? '';
    }
}
include 'includes/header.php';
?>

<style>
    @import url('offline/inter-font.css');

    :root {
        --primary: #2563eb;
        --primary-dark: #1d4ed8;
        --primary-light: #eff6ff;
        --success: #10b981;
        --success-light: #d1fae5;
        --warning: #f59e0b;
        --warning-light: #fef3c7;
        --danger: #ef4444;
        --danger-light: #fee2e2;
        --info: #3b82f6;
        --info-light: #dbeafe;
        --gray-50: #f9fafb;
        --gray-100: #f3f4f6;
        --gray-200: #e5e7eb;
        --gray-300: #d1d5db;
        --gray-400: #9ca3af;
        --gray-500: #6b7280;
        --gray-600: #4b5563;
        --gray-700: #374151;
        --gray-800: #1f2937;
        --gray-900: #111827;
        --white: #ffffff;
        --border: #e5e7eb;
        --shadow-xs: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        --shadow-sm: 0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1);
        --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        --radius-sm: 0.5rem;
        --radius-md: 0.75rem;
        --radius-lg: 1rem;
        --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .dashboard-container {
        max-width: 1280px;
        margin: 0 auto;
        padding: 0.5rem;
        display: flex;
        flex-direction: column;
        gap: 1.25rem;
        font-family: 'Inter', sans-serif;
        color: var(--gray-800);
        font-size: 0.9375rem;
        line-height: 1.5;
        background: #f8fafc;
    }

    /* Page title — same as dashboard */
    .page-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.75rem;
        padding: 0.25rem 0.25rem 0;
    }

    .page-head h1 {
        font-size: 1.375rem;
        font-weight: 700;
        color: var(--gray-900);
        letter-spacing: -0.02em;
        margin: 0;
        line-height: 1.25;
    }

    .page-head p {
        margin: 0.25rem 0 0;
        font-size: 0.875rem;
        color: var(--gray-500);
        font-weight: 400;
    }

    .page-head .page-meta {
        font-size: 0.8125rem;
        color: var(--gray-500);
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 9999px;
        padding: 0.4rem 0.85rem;
        white-space: nowrap;
    }

    /* Cards — same as dashboard */
    .card {
        background: var(--white);
        border-radius: 12px;
        border: 1px solid var(--border);
        overflow: hidden;
        transition: box-shadow 0.15s ease;
        box-shadow: var(--shadow-xs);
    }

    .card:hover {
        box-shadow: var(--shadow-sm);
    }

    .card-header {
        padding: 0.875rem 1.25rem;
        border-bottom: 1px solid var(--border);
        background: var(--white);
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.625rem 1rem;
    }

    .card-header h3 {
        font-size: 0.95rem;
        font-weight: 650;
        color: var(--gray-900);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.55rem;
        letter-spacing: -0.01em;
    }

    .card-header h3 svg {
        width: 18px;
        height: 18px;
        color: var(--gray-400);
        flex-shrink: 0;
    }

    .card-header .card-sub {
        font-size: 0.8rem;
        color: var(--gray-500);
        font-weight: 400;
    }

    .card-body {
        padding: 1.25rem;
    }

    /* Form elements — same rhythm as dashboard */
    .form-group {
        margin-bottom: 1rem;
    }

    .form-group:last-child {
        margin-bottom: 0;
    }

    .form-label {
        display: block;
        margin-bottom: 0.4rem;
        font-weight: 600;
        font-size: 0.82rem;
        color: var(--gray-700);
    }

    .form-hint {
        display: block;
        margin-top: 0.3rem;
        font-size: 0.75rem;
        color: var(--gray-500);
    }

    .form-input,
    .form-select {
        width: 100%;
        padding: 0.65rem 0.85rem;
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        font-family: inherit;
        font-size: 0.875rem;
        transition: var(--transition);
        background: var(--white);
        color: var(--gray-800);
        box-sizing: border-box;
    }

    .form-input:focus,
    .form-select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px var(--primary-light);
    }

    textarea.form-input {
        resize: vertical;
    }

    input[type="file"].form-input {
        padding: 0.55rem 0.75rem;
        font-size: 0.82rem;
        color: var(--gray-600);
    }

    .check-row {
        display: flex;
        align-items: center;
        gap: 0.55rem;
        padding: 0.6rem 0.75rem;
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        background: var(--gray-50);
        font-size: 0.83rem;
        color: var(--gray-700);
    }

    .check-row input[type="checkbox"] {
        width: 1rem;
        height: 1rem;
        accent-color: var(--primary);
        margin: 0;
    }

    /* Buttons — same as dashboard */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        padding: 0.625rem 1.25rem;
        border-radius: var(--radius-sm);
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: var(--transition);
        border: 1px solid transparent;
        text-decoration: none;
        line-height: 1.5;
        white-space: nowrap;
    }

    .btn svg {
        width: 15px;
        height: 15px;
    }

    .btn-sm {
        padding: 0.4rem 0.7rem;
        font-size: 0.78rem;
    }

    .btn-block {
        width: 100%;
    }

    .btn-primary {
        background: var(--primary);
        color: white;
    }
    .btn-primary:hover {
        background: var(--primary-dark);
    }

    .btn-success {
        background: var(--success);
        color: white;
    }
    .btn-success:hover {
        background: #0e9f6e;
    }

    .btn-warning {
        background: var(--warning);
        color: white;
    }
    .btn-warning:hover {
        background: #d97706;
    }

    .btn-danger {
        background: var(--danger-light);
        color: var(--danger);
    }
    .btn-danger:hover {
        background: #fecaca;
    }

    .btn-outline {
        background: transparent;
        border-color: var(--gray-300);
        color: var(--gray-700);
    }
    .btn-outline:hover {
        border-color: var(--primary);
        color: var(--primary);
        background: var(--primary-light);
    }

    .icon-btn {
        width: 30px;
        height: 30px;
        padding: 0 !important;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 7px;
    }

    .btn:focus-visible,
    .tab-btn:focus-visible,
    .teacher-check-item:focus-visible {
        outline: 2px solid var(--primary);
        outline-offset: 2px;
    }

    /* Alerts — same as dashboard */
    .alert {
        padding: 0.9rem 1.1rem;
        border-radius: var(--radius-md);
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 0.65rem;
        font-size: 0.875rem;
        border: 1px solid var(--border);
        border-left: 3px solid;
        margin-bottom: 0;
    }

    .alert svg {
        flex-shrink: 0;
    }

    .alert-success {
        background: #ecfdf5;
        color: #047857;
        border-left-color: var(--success);
    }
    .alert-error {
        background: #fef2f2;
        color: #b91c1c;
        border-left-color: var(--danger);
    }

    /* Badges — same quiet style as dashboard */
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.2rem 0.6rem;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 600;
        line-height: 1.4;
        white-space: nowrap;
    }

    .badge svg {
        width: 12px;
        height: 12px;
    }

    /* Requirement items — compact rows, same language as dashboard */
    .requirements-list {
        max-height: 560px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        padding-right: 0.15rem;
    }

    .requirement-item {
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 0.7rem 0.85rem;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }

    .requirement-item:hover {
        border-color: var(--gray-300);
        box-shadow: var(--shadow-xs);
    }

    .requirement-item.is-done {
        border-left: 3px solid var(--success);
    }

    .req-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.6rem;
    }

    .req-main {
        min-width: 0;
        flex: 1;
    }

    .req-title {
        font-weight: 600;
        color: var(--gray-900);
        font-size: 0.88rem;
        line-height: 1.35;
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.35rem;
    }

    .req-title svg {
        color: var(--success);
        flex-shrink: 0;
    }

    .req-desc {
        margin-top: 0.3rem;
        color: var(--gray-600);
        font-size: 0.82rem;
        line-height: 1.45;
    }

    .req-meta {
        font-size: 0.75rem;
        color: var(--gray-500);
        margin-top: 0.45rem;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.35rem;
    }

    .req-side {
        display: flex;
        gap: 0.4rem;
        flex-shrink: 0;
    }

    .status-badge {
        font-size: 0.7rem;
        padding: 0.18rem 0.6rem;
        border-radius: 9999px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        white-space: nowrap;
    }

    .status-assigned {
        background: #ecfdf5;
        color: #047857;
    }

    .status-unassigned {
        background: var(--gray-100);
        color: var(--gray-600);
    }

    .status-completed {
        background: #ecfdf5;
        color: #047857;
    }

    .status-in-progress {
        background: #fef3c7;
        color: #92400e;
    }

    .badge-success {
        background: #ecfdf5;
        color: #047857;
    }

    .badge-warning {
        background: #fef3c7;
        color: #92400e;
    }

    .badge-danger {
        background: #fef2f2;
        color: #b91c1c;
    }

    .badge-info {
        background: #eff6ff;
        color: #1d4ed8;
    }

    .badge-neutral {
        background: var(--gray-100);
        color: var(--gray-600);
    }

    .edit-panel {
        display: none;
        margin-top: 0.75rem;
        padding-top: 0.75rem;
        border-top: 1px dashed var(--border);
    }

    .edit-panel.open {
        display: block;
    }

    /* Teacher list — compact, same tokens */
    .teacher-list-box {
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        max-height: 260px;
        overflow-y: auto;
        background: var(--white);
    }

    .teacher-check-item {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        padding: 0.6rem 0.8rem;
        cursor: pointer;
        transition: background 0.15s ease;
        border-bottom: 1px solid var(--gray-100);
        font-size: 0.85rem;
        color: var(--gray-800);
    }

    .teacher-check-item:last-child {
        border-bottom: none;
    }

    .teacher-check-item:hover {
        background: var(--gray-50);
    }

    .teacher-check-item input {
        margin: 0;
        width: 1rem;
        height: 1rem;
        accent-color: var(--primary);
        flex-shrink: 0;
    }

    .teacher-check-item.is-assigned {
        background: #ecfdf5;
        opacity: 0.75;
    }

    .teacher-check-item.is-assigned .already-assigned-tag {
        display: inline;
    }

    .already-assigned-tag {
        display: none;
        font-size: 0.7rem;
        font-weight: 600;
        color: #047857;
        margin-left: auto;
        flex-shrink: 0;
    }

    .assign-status {
        margin-top: 0.6rem;
        font-size: 0.78rem;
        color: var(--gray-500);
    }

    /* Layout grids — same 2-col collapse as dashboard */
    .top-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.25rem;
        align-items: start;
    }

    .bottom-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
        gap: 1.25rem;
        align-items: start;
    }

    /* Tabs — quiet pills like dashboard badges */
    .tabs-container {
        margin-bottom: 0.85rem;
    }

    .tabs-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
        border-bottom: 1px solid var(--border);
        padding-bottom: 0.65rem;
    }

    .tab-btn {
        padding: 0.35rem 0.8rem;
        border: 1px solid var(--border);
        border-radius: 9999px;
        background: var(--white);
        color: var(--gray-600);
        font-size: 0.76rem;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition);
        font-family: inherit;
        line-height: 1.5;
    }
    .tab-btn:hover {
        border-color: var(--gray-300);
        color: var(--gray-800);
    }
    .tab-btn.active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }

    /* Empty state — same as dashboard */
    .empty-state {
        text-align: center;
        padding: 2rem 1.25rem;
        color: var(--gray-500);
    }

    .empty-state-icon {
        width: 48px;
        height: 48px;
        margin: 0 auto 0.65rem;
        border-radius: 14px;
        background: var(--gray-100);
        color: var(--gray-400);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .empty-state-icon svg {
        width: 22px;
        height: 22px;
    }

    .empty-state p {
        margin: 0 0 0.25rem;
        font-weight: 600;
        font-size: 0.88rem;
        color: var(--gray-600);
    }

    .empty-state small {
        display: block;
        font-size: 0.78rem;
        color: var(--gray-500);
    }

    /* File view modal — same overlay language as dashboard */
    .document-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 2000;
        background: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(4px);
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }

    .document-modal.show {
        display: flex;
    }

    .modal-card {
        background: var(--white);
        border-radius: 12px;
        max-width: 90%;
        max-height: 90%;
        width: 800px;
        display: flex;
        flex-direction: column;
        box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
        overflow: hidden;
    }

    .modal-head {
        padding: 0.9rem 1.25rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-head h4 {
        margin: 0;
        font-weight: 650;
        font-size: 0.95rem;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .modal-head h4 svg {
        color: var(--gray-400);
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 1.4rem;
        cursor: pointer;
        color: var(--gray-400);
        line-height: 1;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 9999px;
    }

    .modal-close:hover {
        color: var(--gray-600);
        background: var(--gray-100);
    }

    .modal-preview {
        padding: 1.25rem;
        flex: 1;
        overflow: auto;
        text-align: center;
    }

    /* Small confirm modal (delete) — same overlay, narrow card */
    .modal-card-sm {
        width: 440px;
    }

    .modal-card-sm .modal-head h4 svg {
        color: var(--danger);
    }

    .modal-confirm-body {
        padding: 1.25rem;
        text-align: left;
    }

    .modal-confirm-body p {
        margin: 0 0 0.35rem;
        font-size: 0.88rem;
        color: var(--gray-800);
    }

    .modal-confirm-body .confirm-title {
        font-weight: 650;
        color: var(--gray-900);
        word-break: break-word;
    }

    .modal-confirm-body small {
        display: block;
        font-size: 0.78rem;
        color: var(--gray-500);
    }

    .modal-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
        margin-top: 1rem;
    }

    /* Responsive — same breakpoints as dashboard */
    @media (max-width: 992px) {
        .top-grid,
        .bottom-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .dashboard-container {
            padding: 0.5rem;
            gap: 1rem;
        }
        .page-head h1 {
            font-size: 1.2rem;
        }
        .card-body {
            padding: 1rem;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        *,
        *::before,
        *::after {
            animation-duration: 0.001ms !important;
            transition-duration: 0.001ms !important;
        }
    }
</style>

<div class="dashboard-container">
    <!-- Page header — same as dashboard -->
 

    <?php if ($error): ?>
        <div class="alert alert-error" role="alert">
            <?= render_dashboard_icon('x-circle') ?> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php include 'includes/success_toast.php'; ?>

    <div class="top-grid">
        <!-- Create Requirement Card -->
        <div class="card">
            <div class="card-header">
                <h3><?= render_dashboard_icon('clipboard') ?> <?= currentRoleName() ?> Requirement</h3>
                <span class="card-sub">Create once, assign to faculty</span>
            </div>
            <div class="card-body">
                
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create_requirement">
                    <div class="form-group">
                        <label class="form-label">Title <span style="color: var(--danger);">*</span></label>
                        <input type="text" name="req_title" class="form-input" placeholder="e.g., SY 2024-2025 Syllabus" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="req_description" class="form-input" rows="3" placeholder="Additional details for faculty members..."></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Deadline</label>
                        <div class="deadline-wrap" style="position:relative">
                            <input type="text" name="req_deadline" class="form-input req-deadline" placeholder="MM/DD/YYYY" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}">
                            <input type="date" class="req-deadline-picker" style="position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;cursor:pointer">

                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Template / Guide File</label>
                        <input type="file" name="req_file" class="form-input">
                    </div>
                    <button type="submit" class="btn btn-primary btn-block"><?= render_dashboard_icon('plus') ?> Create <?= phRoleShort() ?> Requirement</button>
                </form>
            </div>
        </div>

        <!-- Quick Direct Upload Card -->
        <div class="card">
            <div class="card-header">
                <h3><?= render_dashboard_icon('upload') ?> Quick Direct Upload</h3>
                <span class="card-sub">Send straight for Admin review</span>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="direct_upload">
                    <div class="form-group">
                        <label class="form-label">Document Title <span style="color: var(--danger);">*</span></label>
                        <input type="text" name="upload_title" class="form-input" placeholder="Enter document name..." required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Select File <span style="color: var(--danger);">*</span></label>
                        <input type="file" name="upload_file" class="form-input" required>
                    </div>
                    <button type="submit" class="btn btn-outline btn-block"><?= render_dashboard_icon('send') ?> Upload for Admin Review</button>
                </form>
            </div>
        </div>
    </div>

    <div class="bottom-grid">
        <!-- Your Requirements Card -->
        <div class="card">
            <div class="card-header">
                <h3><?= render_dashboard_icon('pin') ?> Your Requirements</h3>
                <span class="badge badge-neutral"><?= count($phRequirements) ?> total</span>
            </div>
            <div class="card-body">
                <!-- Tabs -->
                <div class="tabs-container">
                    <div class="tabs-list">
                        <button class="tab-btn active" data-filter="all">All</button>
                        <button class="tab-btn" data-filter="assigned">Assigned</button>
                        <button class="tab-btn" data-filter="unassigned">Unassigned</button>
                        <button class="tab-btn" data-filter="completed">Completed</button>
                        <button class="tab-btn" data-filter="in-progress">In Progress</button>
                    </div>
                </div>

                <div class="requirements-list">
                    <?php if (empty($phRequirements)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon"><?= render_dashboard_icon('clipboard') ?></div>
                            <p>No requirements found.</p>
                            <small>Create your first requirement using the form above.</small>
                        </div>
                    <?php else: ?>
                        <?php foreach ($phRequirements as $req): 
                            // Compute status for filtering
                            $isCompleted = $req['assigned_count'] > 0 && $req['completed_count'] >= $req['assigned_count'];
                            $isAssigned = $req['assigned_count'] > 0;
                            if ($isCompleted) {
                                $status = 'completed';
                            } elseif ($isAssigned) {
                                $status = 'in-progress';
                            } else {
                                $status = 'unassigned';
                            }
                        ?>
                            <div class="requirement-item<?= $isCompleted ? ' is-done' : '' ?>" data-status="<?= $status ?>">
                                <div class="req-header">
                                    <div class="req-main">
                                        <div class="req-title">
                                            <?= htmlspecialchars($req['title']) ?>
                                            <?php if (!empty($req['filename'])): ?>
                                                <span class="badge badge-info"><?= render_dashboard_icon('file') ?> Attachment</span>
                                                <button type="button" class="btn btn-outline btn-sm view-btn" data-id="<?= $req['id'] ?>" data-type="ph_requirement" data-title="<?= htmlspecialchars($req['filename'] ?? $req['title']) ?>" data-filename="<?= htmlspecialchars($req['filename']) ?>"><?= render_dashboard_icon('eye') ?> View</button>
                                            <?php endif; ?>
                                            <?php if ($isCompleted): ?>
                                                <?= render_dashboard_icon('check') ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($req['description'])): ?>
                                            <div class="req-desc">
                                                <?= htmlspecialchars($req['description']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="req-meta">
                                            Created: <?php
                                                $createdAt = $req['created_at'] ?? null;
                                                if (!empty($createdAt) && strtotime($createdAt)) {
                                                    $date = strtotime($createdAt);
                                                    $now = time();
                                                    $diff = $now - $date;
                                                    if ($diff < 60) {
                                                        echo 'Just now';
                                                    } elseif ($diff < 3600) {
                                                        echo floor($diff / 60) . ' min ago';
                                                    } elseif ($diff < 86400 && date('Y-m-d', $date) === date('Y-m-d', $now)) {
                                                        echo 'Today at ' . date('g:i a', $date);
                                                    } else {
                                                        echo date('M d, Y', $date);
                                                    }
                                                } else {
                                                    echo date('M d, Y');
                                                }
                                            ?>
                                            <?php if (!empty($req['deadline'])): ?>
                                                <?php
                                                    $deadlineTs = strtotime($req['deadline']);
                                                    $nowTs = time();
                                                    $isOverdue = $deadlineTs < $nowTs && !$isCompleted;
                                                ?>
                                                <span class="status-badge <?= $isOverdue ? 'badge-danger' : 'badge-warning' ?>"><?= render_dashboard_icon('clock') ?> Due <?= date('m/d/Y', $deadlineTs) ?></span>
                                            <?php endif; ?>
                                            <?php if ($isCompleted): ?>
                                                <span class="status-badge status-completed">
                                                    Completed (<?= $req['completed_count'] ?>/<?= $req['assigned_count'] ?>)
                                                </span>
                                            <?php elseif ($isAssigned): ?>
                                                <span class="status-badge status-in-progress">
                                                    In Progress (<?= $req['completed_count'] ?>/<?= $req['assigned_count'] ?>)
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge status-unassigned">Not Assigned</span>
                                            <?php endif; ?>
                                            <span class="status-badge badge-info"><?= phRoleShort() ?> Created</span>
                                        </div>
                                    </div>
                                    <div class="req-side">
                                        <?php if (!$isCompleted): ?>
                                            <button data-csp-action="toggleEdit" data-csp-args="[<?= (int)$req['id'] ?>]" class="btn btn-outline btn-sm icon-btn" title="Edit" aria-label="Edit <?= htmlspecialchars($req['title']) ?>"><?= render_dashboard_icon('pencil') ?></button>
                                        <?php endif; ?>
                                        <form method="POST" class="delete-req-form" data-req-title="<?= htmlspecialchars($req['title']) ?>" style="display: inline;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_requirement">
                                            <input type="hidden" name="req_id" value="<?= $req['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm icon-btn" title="Delete" aria-label="Delete <?= htmlspecialchars($req['title']) ?>"><?= render_dashboard_icon('trash') ?></button>
                                        </form>
                                    </div>
                                </div>

                                <?php if (!$isCompleted): ?>
                                <div id="edit_<?= $req['id'] ?>" class="edit-panel">
                                    <form method="POST" enctype="multipart/form-data">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="update_requirement">
                                        <input type="hidden" name="req_id" value="<?= $req['id'] ?>">
                                        <div class="form-group">
                                            <label class="form-label">Title</label>
                                            <input type="text" name="req_title" class="form-input" value="<?= htmlspecialchars($req['title']) ?>" required>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Description</label>
                                            <textarea name="req_description" class="form-input" rows="2"><?= htmlspecialchars($req['description']) ?></textarea>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Deadline</label>
                                            <div class="deadline-wrap" style="position:relative">
                                                <input type="text" name="req_deadline" class="form-input req-deadline" value="<?= $req['deadline'] ? date('m/d/Y', strtotime($req['deadline'])) : '' ?>" pattern="[0-9]{2}/[0-9]{2}/[0-9]{4}" placeholder="MM/DD/YYYY">
                                                <input type="date" class="req-deadline-picker" style="position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;cursor:pointer">
                                                <span class="deadline-display form-hint"><?= $req['deadline'] ? date('m/d/Y', strtotime($req['deadline'])) : 'No deadline' ?></span>
                                            </div>
                                            <small class="form-hint">Leave empty to remove deadline</small>
                                        </div>
                                        <div class="form-group">
                                            <label class="form-label">Replace file</label>
                                            <input type="file" name="req_file" class="form-input">
                                        </div>
                                        <div style="display: flex; gap: 0.5rem;">
                                            <button type="submit" class="btn btn-primary btn-sm">Update</button>
                                            <button type="button" data-csp-action="toggleEdit" data-csp-args="[<?= (int)$req['id'] ?>]" class="btn btn-outline btn-sm">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Assign to Teachers Card -->
        <div class="card">
            <div class="card-header">
                <h3><?= render_dashboard_icon('users') ?> Assign <?= currentRoleName() ?> Requirements</h3>
                <span class="card-sub">Distribute to faculty</span>
            </div>
            <div class="card-body">

                <?php if (empty($assignableRequirements)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon"><?= render_dashboard_icon('check-circle') ?></div>
                        <p>All requirements have been assigned.</p>
                        <small>New requirements will appear here for distribution.</small>
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="assign_ph_req">
                        <div class="form-group">
                            <label class="form-label">Step 1: Select <?= phRoleShort() ?> Requirement <span style="color: var(--danger);">*</span></label>
                            <select name="ph_req_id" class="form-select" required>
                                <option value="">-- Choose Requirement --</option>
                                <?php foreach ($assignableRequirements as $r): ?>
                                    <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Step 2: Choose Faculty <span style="color: var(--danger);">*</span></label>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                <span class="form-hint" style="margin: 0;">Select faculty members</span>
                                <label style="font-size: 0.78rem; font-weight: 600; color: var(--primary); cursor: pointer; display: inline-flex; align-items: center; gap: 0.35rem;">
                                    <input type="checkbox" id="selectAll" style="accent-color: var(--primary);"> Select All
                                </label>
                            </div>
                            <div class="teacher-list-box" id="teacherListBox">
                                <?php foreach ($allTeachers as $t): ?>
                                    <label class="teacher-check-item teacher-item" data-teacher-id="<?= $t['id'] ?>">
                                        <input type="checkbox" name="teacher_ids[]" value="<?= $t['id'] ?>" class="teacher-cb">
                                        <span><?= htmlspecialchars($t['name']) ?></span>
                                        <span class="already-assigned-tag">Already assigned</span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                            <div id="assignmentStatus" class="assign-status">
                                Select a requirement to see assignment status
                            </div>
                        </div>

                        <button type="submit" class="btn btn-success btn-block"><?= render_dashboard_icon('send') ?> Distribute Requirement</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirm Modal (replaces native confirm dialog) -->
<div id="deleteConfirmModal" class="document-modal" role="dialog" aria-modal="true" aria-labelledby="deleteConfirmTitle">
    <div class="modal-card modal-card-sm" data-csp-stop>
        <div class="modal-head">
            <h4 id="deleteConfirmTitle"><?= render_dashboard_icon('trash') ?> Delete Requirement</h4>
            <button type="button" data-action="closeDeleteModal" class="modal-close" aria-label="Close">&times;</button>
        </div>
        <div class="modal-confirm-body">
            <p>Delete <span class="confirm-title" id="deleteConfirmName"></span>?</p>
            <small>Assigned faculty members will be unlinked. Documents already submitted remain in the repository.</small>
            <div class="modal-actions">
                <button type="button" data-action="closeDeleteModal" class="btn btn-outline btn-sm">Cancel</button>
                <button type="button" data-csp-action="confirmDeleteSubmit" class="btn btn-danger btn-sm"><?= render_dashboard_icon('trash') ?> Delete</button>
            </div>
        </div>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
    // File size validation for all upload forms
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            const fileInput = this.querySelector('input[type="file"]');
            const f = fileInput && fileInput.files[0];
            if (f) {
                const isImg = /\.(jpe?g|png|gif|webp)$/i.test(f.name);
                if (f.size > (isImg ? 10 : 100) * 1024 * 1024) {
                    e.preventDefault();
                    alert('File too large. Maximum size is ' + (isImg ? '10MB' : '100MB') + '.');
                    return false;
                }
            }
        });
    });

    // Assigned teachers data - maps requirement ID to array of teacher IDs
    const assignedTeachersMap = <?= json_encode($assignedTeachersMap) ?>;
    const uploadPath = 'uploads/';
    
    // Delete confirmation modal (replaces native confirm())
    let pendingDeleteForm = null;

    function openDeleteModal(form) {
        pendingDeleteForm = form;
        const name = form?.dataset?.reqTitle || 'this requirement';
        document.getElementById('deleteConfirmName').textContent = `“${name}”`;
        const modal = document.getElementById('deleteConfirmModal');
        modal.classList.add('show');
        const cancelBtn = modal.querySelector('.modal-actions .btn-outline');
        if (cancelBtn) cancelBtn.focus();
    }

    function closeDeleteModal() {
        document.getElementById('deleteConfirmModal').classList.remove('show');
        pendingDeleteForm = null;
    }

    function confirmDeleteSubmit() {
        if (pendingDeleteForm) pendingDeleteForm.submit();
        closeDeleteModal();
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Show deadline as MM/DD/YYYY next to the text input
        document.querySelectorAll('.deadline-wrap').forEach(wrap => {
            const input = wrap.querySelector('.req-deadline');
            const picker = wrap.querySelector('.req-deadline-picker');
            const display = wrap.querySelector('.deadline-display');
            if (!input) return;
            const fmt = v => v || 'No deadline';
            if (picker) {
                // Click the overlay → open native date picker
                picker.addEventListener('click', () => {
                    const toDb = v => { const p = v.split('/'); return p.length === 3 ? p[2] + '-' + p[0] + '-' + p[1] : ''; };
                    picker.value = input.value && input.value.includes('/') ? toDb(input.value) : '';
                    picker.showPicker();
                });
                // Date selected → write MM/DD/YYYY into the visible text input
                picker.addEventListener('change', () => {
                    const p = picker.value.split('-');
                    input.value = p.length === 3 ? p[1] + '/' + p[2] + '/' + p[0] : picker.value;
                    if (display) display.textContent = fmt(input.value);
                });
            }
            if (display) display.textContent = fmt(input.value);
        });

        // Intercept delete forms and confirm via modal instead of native confirm()
        document.querySelectorAll('.delete-req-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                openDeleteModal(this);
            });
        });

        // Backdrop click + Escape close for the delete modal
        const delModal = document.getElementById('deleteConfirmModal');
        if (delModal) {
            delModal.addEventListener('click', function(e) {
                if (e.target === this) closeDeleteModal();
            });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDeleteModal();
        });
    });

    function toggleEdit(id) {
        const form = document.getElementById('edit_' + id);
        if (form) form.classList.toggle('open');
    }
    
    function updateTeacherList(reqId) {
        const teacherItems = document.querySelectorAll('.teacher-item');
        const statusDiv = document.getElementById('assignmentStatus');
        const selectAllCheckbox = document.getElementById('selectAll');
        let assignedCount = 0;
        
        teacherItems.forEach(item => {
            const teacherId = parseInt(item.dataset.teacherId);
            const checkbox = item.querySelector('.teacher-cb');
            const tag = item.querySelector('.already-assigned-tag');
            
            // Check if teacher is already assigned to this requirement
            if (reqId && assignedTeachersMap[reqId] && assignedTeachersMap[reqId].includes(teacherId)) {
                item.classList.add('is-assigned');
                checkbox.disabled = true;
                checkbox.checked = false;
                assignedCount++;
            } else {
                item.classList.remove('is-assigned');
                checkbox.disabled = false;
            }
        });
        
        // Update status message
        if (reqId) {
            const totalTeachers = teacherItems.length;
            const availableTeachers = totalTeachers - assignedCount;
            if (assignedCount > 0) {
                    statusDiv.textContent = `${assignedCount} faculty member(s) already assigned - ${availableTeachers} available for selection`;
            } else {
                statusDiv.textContent = `All ${totalTeachers} faculty members are available for assignment`;
            }
        } else {
            statusDiv.textContent = 'Select a requirement to see assignment status';
        }
        
        // Update select all to only select available checkboxes
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    // Only check enabled checkboxes
                    document.querySelectorAll('.teacher-cb:not(:disabled)').forEach(cb => cb.checked = true);
                } else {
                    document.querySelectorAll('.teacher-cb').forEach(cb => cb.checked = false);
                }
            });
        }
    }
    
    // Listen for requirement selection changes (guarded: form only renders when assignable items exist)
    const phReqSelect = document.querySelector('select[name="ph_req_id"]');
    if (phReqSelect) {
        phReqSelect.addEventListener('change', function() {
            updateTeacherList(this.value);
        });
    }
    
    // Initialize on page load if a requirement is pre-selected
    document.addEventListener('DOMContentLoaded', function() {
        const reqSelect = document.querySelector('select[name="ph_req_id"]');
        if (reqSelect && reqSelect.value) {
            updateTeacherList(reqSelect.value);
        }
    });

    // Tab filtering for requirements
    document.addEventListener('DOMContentLoaded', function() {
        const tabs = document.querySelectorAll('.tab-btn');
        const items = document.querySelectorAll('.requirement-item');

        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                // Update active tab
                tabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');

                const filter = this.dataset.filter;
                let visibleCount = 0;

                items.forEach(item => {
                    const status = item.dataset.status;
                    const show = filter === 'all'
                        || status === filter
                        || (filter === 'assigned' && (status === 'completed' || status === 'in-progress'));
                    if (show) {
                        item.style.display = 'block';
                        visibleCount++;
                    } else {
                        item.style.display = 'none';
                    }
                });

                // Show/hide the "no results" message
                const noResults = document.querySelector('.no-results-message');
                if (noResults) {
                    noResults.style.display = visibleCount === 0 ? 'block' : 'none';
                }
            });
        });

        // Add a "no results" message if none exist
        const list = document.querySelector('.requirements-list');
        if (list && !document.querySelector('.no-results-message')) {
            const noMsg = document.createElement('p');
            noMsg.className = 'no-results-message';
            noMsg.style.cssText = 'text-align: center; color: var(--gray-500); padding: 2rem; display: none;';
            noMsg.textContent = 'No requirements match this filter.';
            list.appendChild(noMsg);
        }
    });
</script>

<?php include 'includes/footer.php'; ?>



