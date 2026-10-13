<?php
/**
 * Command-palette search for every role.
 *
 * One endpoint, one auth check, one response contract:
 *   {"groups":[{"label":"...","items":[{"title":"...","hint":"...","url":"..."}]}]}
 *
 * The role is read from the DB, never the session: a stale session must not keep
 * serving results after an account is demoted, and the role is the only thing that
 * decides which scope below runs. The three branches are mutually exclusive, so
 * exactly one set of queries executes per request.
 */
define('CHED_APP', true);
require_once 'config.php';

header('Content-Type: application/json');

$me = $pdo->prepare('SELECT role, department_id FROM users WHERE id = ? LIMIT 1');
$me->execute([(int)($_SESSION['user_id'] ?? 0)]);
$me = $me->fetch() ?: [];

$role = (string)($me['role'] ?? '');
if (!in_array($role, ['admin', 'department', 'unit_head', 'teacher', 'staff'], true)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = (int) $_SESSION['user_id'];

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['groups' => []]);
    exit;
}

$like = '%' . addcslashes($q, '%_\\') . '%';

switch ($role) {

    // ---------------------------------------------------------------- admin --
    // Approved documents (repository vs archive) and program head accounts.
    case 'admin':
        $docs = $pdo->prepare(
            "SELECT d.id, d.title, d.archived, d.department_id, dep.department_name,
                    'document' AS source, COALESCE(dr.folder_id, 0) AS folder_id
               FROM documents d
               JOIN departments dep ON dep.id = d.department_id
               LEFT JOIN teacher_requirements tr ON tr.id = d.teacher_requirement_id
               LEFT JOIN department_requirements dr ON dr.id = tr.requirement_id
              WHERE d.status = 'approved_admin'
                AND (d.title LIKE ? OR d.filename LIKE ? OR d.document_type LIKE ?)
           UNION ALL
           SELECT pd.id, pd.title, pd.archived, pd.department_id, dep.department_name,
                    'ph_document' AS source, 0 AS folder_id
               FROM ph_documents pd
               JOIN departments dep ON dep.id = pd.department_id
              WHERE pd.status = 'approved_admin'
                AND (pd.title LIKE ? OR pd.filename LIKE ?)
            ORDER BY title
            LIMIT 15"
        );
        $docs->execute([$like, $like, $like, $like, $like]);

        $heads = $pdo->prepare(
            "SELECT id, name, email
               FROM users
              WHERE role IN ('department','unit_head')
                AND (name LIKE ? OR email LIKE ?)
            ORDER BY name
            LIMIT 8"
        );
        $heads->execute([$like, $like]);

        // Admin Department Requirements page — folders + requirements across all depts.
        $deptReqs = $pdo->prepare(
            "SELECT dr.id, dr.category, dr.document_type, dr.department_id, dr.folder_id,
                    dep.department_name
               FROM department_requirements dr
               JOIN departments dep ON dep.id = dr.department_id
              WHERE (dr.category LIKE ? OR dr.document_type LIKE ? OR dr.description LIKE ?)
              ORDER BY dep.department_name, dr.category, dr.document_type
              LIMIT 10"
        );
        $deptReqs->execute([$like, $like, $like]);

        $deptReqFolders = $pdo->prepare(
            "SELECT df.id, df.folder_name, df.parent_folder_id, df.department_id,
                    dep.department_name
               FROM department_folders df
               JOIN departments dep ON dep.id = df.department_id
              WHERE df.folder_name LIKE ? AND LOWER(df.folder_name) <> 'root'
              ORDER BY dep.department_name, df.folder_name
              LIMIT 6"
        );
        $deptReqFolders->execute([$like]);

        $docItems = [];
        foreach ($docs->fetchAll() as $d) {
            $archived = (int) $d['archived'] === 1;
            $docItems[] = [
                'title' => (string) $d['title'],
                'hint'  => $archived ? 'Archive' : 'Repository',
                'url'   => sprintf(
                    '%s?dept=%d&folder=%d&highlight=%s:%d',
                    $archived ? 'admin_archive_documents.php' : 'admin_manage_document.php',
                    (int) $d['department_id'],
                    (int) $d['folder_id'],
                    rawurlencode($d['source']),
                    (int) $d['id']
                ),
            ];
        }

        $headItems = [];
        foreach ($heads->fetchAll() as $u) {
            $headItems[] = [
                'title' => (string) $u['name'],
                'hint'  => (string) $u['email'],
                'url'   => 'admin_manage_users.php?edit=' . (int) $u['id'],
            ];
        }

        /* Folder hits link to the PARENT listing (a folder row only exists in its
           parent's view). Requirements link to their own folder_id. */
        $deptReqItems = [];
        foreach ($deptReqFolders->fetchAll() as $f) {
            $parent = (int) ($f['parent_folder_id'] ?? 0);
            $deptReqItems[] = [
                'title' => (string) $f['folder_name'],
                'hint'  => (string) $f['department_name'] . ' · Folder',
                'url'   => 'admin_department_requirements.php?dept=' . (int) $f['department_id']
                        . '&folder=' . $parent . '&highlight=req_folder:' . (int) $f['id'],
            ];
        }
        foreach ($deptReqs->fetchAll() as $r) {
            $deptReqItems[] = [
                'title' => trim($r['category'] . ' — ' . $r['document_type'], ' —'),
                'hint'  => (string) $r['department_name'] . ' · Requirement',
                'url'   => 'admin_department_requirements.php?dept=' . (int) $r['department_id']
                        . '&folder=' . (int) ($r['folder_id'] ?? 0)
                        . '&highlight=requirement:' . (int) $r['id'],
            ];
        }

        $groups = [];
        if ($docItems) $groups[] = ['label' => 'Approved Documents', 'items' => $docItems];
        if ($deptReqItems) $groups[] = ['label' => 'Department Requirements', 'items' => $deptReqItems];
        if ($headItems) $groups[] = ['label' => 'Program Heads', 'items' => $headItems];
        break;

    // ------------------------------------------------- department (program head) --
    // Own repository, department requirements, and department faculty accounts.
    case 'department':
    case 'unit_head':
        $deptId = (int)($me['department_id'] ?? 0);
        if ($deptId <= 0) {
            echo json_encode(['groups' => []]);
            exit;
        }

        $repo = $pdo->prepare(
            "SELECT id, title, filename, folder_id
               FROM ph_repository
              WHERE department_id = ? AND program_head_id = ?
                AND (title LIKE ? OR filename LIKE ? OR description LIKE ?)
            ORDER BY created_at DESC
            LIMIT 12"
        );
        $repo->execute([$deptId, $userId, $like, $like, $like]);

        $repoFolders = $pdo->prepare(
            "SELECT id, folder_name, parent_folder_id
               FROM ph_repo_folders
              WHERE department_id = ? AND program_head_id = ? AND folder_name LIKE ?
            ORDER BY folder_name
            LIMIT 6"
        );
        $repoFolders->execute([$deptId, $userId, $like]);

        $reqs = $pdo->prepare(
            "SELECT id, category, document_type, folder_id
               FROM department_requirements
              WHERE department_id = ?
                AND (category LIKE ? OR document_type LIKE ? OR description LIKE ?)
            ORDER BY category, document_type
            LIMIT 12"
        );
        $reqs->execute([$deptId, $like, $like, $like]);

        $reqFolders = $pdo->prepare(
            "SELECT id, folder_name, parent_folder_id
               FROM department_folders
              WHERE department_id = ? AND folder_name LIKE ? AND LOWER(folder_name) <> 'root'
            ORDER BY folder_name
            LIMIT 6"
        );
        $reqFolders->execute([$deptId, $like]);

        $teachers = $pdo->prepare(
            "SELECT id, name, email
               FROM users
              WHERE role IN ('teacher','staff') AND department_id = ?
                AND (name LIKE ? OR email LIKE ?)
            ORDER BY name
            LIMIT 8"
        );
        $teachers->execute([$deptId, $like, $like]);

        /* A folder row only exists in its PARENT's listing, so folder hits link to the
           parent. Root listings are reached by omitting ?folder= (folder=0 would filter
           on id 0 and miss the NULL-parent rows the page actually shows). */
        $repoItems = [];
        foreach ($repoFolders->fetchAll() as $f) {
            $parent = (int) ($f['parent_folder_id'] ?? 0);
            $repoItems[] = [
                'title' => (string) $f['folder_name'],
                'hint'  => 'Folder',
                'url'   => 'program_head_repository.php'
                        . ($parent > 0 ? '?folder=' . $parent . '&' : '?')
                        . 'highlight=repo_folder:' . (int) $f['id'],
            ];
        }
        foreach ($repo->fetchAll() as $r) {
            $folder = (int) ($r['folder_id'] ?? 0);
            $repoItems[] = [
                'title' => (string) ($r['title'] ?: 'Untitled'),
                'hint'  => (string) ($r['filename'] ?: 'File'),
                'url'   => 'program_head_repository.php'
                        . ($folder > 0 ? '?folder=' . $folder . '&' : '?')
                        . 'highlight=repo_file:' . (int) $r['id'],
            ];
        }

        $reqItems = [];
        foreach ($reqFolders->fetchAll() as $f) {
            $reqItems[] = [
                'title' => (string) $f['folder_name'],
                'hint'  => 'Folder',
                'url'   => 'program_head_requirements.php?folder=' . (int) ($f['parent_folder_id'] ?? 0)
                        . '&highlight=req_folder:' . (int) $f['id'],
            ];
        }
        foreach ($reqs->fetchAll() as $r) {
            $reqItems[] = [
                'title' => trim($r['category'] . ' — ' . $r['document_type'], ' —'),
                'hint'  => 'Requirement',
                'url'   => 'program_head_requirements.php?folder=' . (int) ($r['folder_id'] ?? 0)
                        . '&highlight=requirement:' . (int) $r['id'],
            ];
        }

        $teacherItems = [];
        foreach ($teachers->fetchAll() as $t) {
            $teacherItems[] = [
                'title' => (string) $t['name'],
                'hint'  => (string) $t['email'],
                'url'   => 'program_head_teachers.php?edit=' . (int) $t['id'] . '&highlight=teacher:' . (int) $t['id'],
            ];
        }

        $groups = [];
        if ($repoItems)   $groups[] = ['label' => 'My Repository', 'items' => $repoItems];
        if ($reqItems)    $groups[] = ['label' => 'Admin Requirements', 'items' => $reqItems];
        if ($teacherItems) $groups[] = ['label' => 'Faculty', 'items' => $teacherItems];
        break;

    // ------------------------------------------------------------- teacher --
    // "Review and need fix" (dashboard) and "To do" (tasks page).
    case 'teacher':
    case 'staff':
        // Reuse the same loaders those two pages use, so these buckets can never drift
        // from what the pages actually render (latest-version join, on-disk file
        // filter, and the source/category normalisation all included).
        $requirements  = getTeacherRequirementsOptimized($pdo, $userId);
        $phRequirements = getTeacherPHRequirementsOptimized($pdo, $userId);

        foreach ($phRequirements as &$req) {
            $req['is_ph_requirement'] = true;
            $req['source']            = 'ph_document';
            $req['category']          = 'PH';
            $req['document_type']     = $req['title'] ?? '';
            $req['source_label']      = 'PH';
        }
        unset($req);

        foreach ($requirements as &$req) {
            $req['source']            = 'document';
            $req['is_ph_requirement'] = false;
            $req['source_label']      = (($req['requirement_type'] ?? '') === 'program_head_requirement') ? 'PH' : 'Admin';
        }
        unset($req);

        $fixItems  = [];
        $todoItems = [];

        foreach (array_merge($requirements, $phRequirements) as $req) {
            $label = trim(($req['category'] ?? '') . ' — ' . ($req['document_type'] ?? ''), ' —');
            $hay   = $label . ' ' . ($req['latest_filename'] ?? '');

            if (mb_stripos($hay, $q) === false) continue;

            $status = $req['current_status'] ?? '';

            if (in_array($status, ['rejected_program_head', 'rejected_admin'], true)) {
                // A rejected row always has a latest submission, which is the id the
                // dashboard's .view-btn carries.
                $fixItems[] = [
                    'title' => $label,
                    'hint'  => (string) ($req['latest_filename'] ?? 'Needs fix'),
                    'url'   => 'teacher_dashboard.php?highlight=' . $req['source'] . ':' . (int) $req['submission_id'],
                ];
            } elseif (!in_array($status, ['approved_program_head', 'approved_admin', 'pending_program_head', 'pending_admin'], true)) {
                // "To do" = no submission yet, rendered in teacher_tasks.php. Its row has
                // no doc id, so key off the requirement id (two namespaces: dept vs PH).
                $todoItems[] = [
                    'title' => $label,
                    'hint'  => (string) $req['source_label'] . ' · To do',
                    'url'   => 'teacher_tasks.php?highlight='
                            . (!empty($req['is_ph_requirement']) ? 'ph_task' : 'task') . ':'
                            . (int) (!empty($req['is_ph_requirement']) ? $req['ph_requirement_id'] : $req['id']),
                ];
            }
        }

        $groups = [];
        if ($fixItems)  $groups[] = ['label' => 'Review and need fix', 'items' => array_slice($fixItems, 0, 12)];
        if ($todoItems) $groups[] = ['label' => 'To do', 'items' => array_slice($todoItems, 0, 12)];
        break;
}

echo json_encode(['groups' => $groups]);
