<?php
define('CHED_APP', true);
require_once 'config.php';
requireAdmin();
maybeAutoSyncExpiration($pdo);

// -------------------------------------------------------------------
// Define secure output escaping function (was missing!)
// -------------------------------------------------------------------
if (!function_exists('sanitize')) {
    /**
     * Safely escape HTML output to prevent XSS.
     * @param string|null $string
     * @return string
     */
    function sanitize(?string $string): string {
        return htmlspecialchars($string ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}

// -------------------------------------------------------------------
// Latest-version window: each document chain (grouped by teacher_requirement_id
// or direct upload id) – we take the latest version per group.
// -------------------------------------------------------------------
function latestDocsWindow(): string {
    return "(SELECT d.* FROM documents d INNER JOIN ("
        . "SELECT teacher_requirement_id AS grp, MAX(version) AS mv "
        . "FROM documents WHERE teacher_requirement_id IS NOT NULL GROUP BY teacher_requirement_id "
        . "UNION ALL "
        . "SELECT id AS grp, MAX(version) AS mv "
        . "FROM documents WHERE teacher_requirement_id IS NULL GROUP BY id"
        . ") lv ON (d.teacher_requirement_id IS NOT NULL AND d.teacher_requirement_id = lv.grp AND d.version = lv.mv) "
        . "OR (d.teacher_requirement_id IS NULL AND d.id = lv.grp AND d.version = lv.mv))";
}

// Archived rows = all archived approved docs (documents + ph_documents), same set
// admin_archive_documents.php lists. One query shared by export + on-page archive sections.
$archivedRowsSql = function ($lw) {
    return "
        SELECT title, document_type, category, department_name, upload_date, expiry_date FROM (
            SELECT d.title, d.document_type, d.category, dep.department_name, d.upload_date, d.expiry_date
            FROM $lw d
            LEFT JOIN departments dep ON d.department_id = dep.id
            WHERE " . archivedDocCondition() . "
            UNION ALL
            SELECT pd.title, 'PH Document' AS document_type, 'PH' AS category, dep.department_name, pd.upload_date, pd.expiry_date
            FROM ph_documents pd
            LEFT JOIN departments dep ON pd.department_id = dep.id
            WHERE pd.archived = 1 AND pd.status = 'approved_admin'
        ) a ORDER BY upload_date DESC
    ";
};

// -------------------------------------------------------------------
// PHPWord Export Handling (MUST be before any output)
// -------------------------------------------------------------------
if (isset($_GET['export_report'])) {
    require_once 'vendor/autoload.php';
    if (!class_exists('PhpOffice\PhpWord\PhpWord')) {
        die('PHPWord library not found. Please run "composer require phpoffice/phpword".');
    }

    $reportType = $_GET['export_report'];
    $allowedReports = ['all_documents', 'by_requirements', 'by_department', 'by_validity', 'archived', 'expiring_expired', 'all_reports'];
    if (!in_array($reportType, $allowedReports)) {
        die('Invalid report type.');
    }

    /* ============================================================
     * 1. Report metadata
     * ============================================================ */
    $reportLabels = [
        'all_documents'    => 'All Approved Documents',
        'by_requirements'  => 'Requirements by Category',
        'by_department'    => 'Documents by Department',
        'by_validity'      => 'Requirements by Validity',
        'archived'         => 'Archived Documents',
        'expiring_expired' => 'Expiring & Expired Documents',
        'all_reports'      => 'Comprehensive Compliance Report',
    ];
    $reportTitle = $reportLabels[$reportType] ?? ucfirst(str_replace('_', ' ', $reportType));

    /* ============================================================
     * 2. Bootstrap PHPWord
     * ============================================================ */
    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $phpWord->setDefaultFontName('Arial');
    $phpWord->setDefaultFontSize(10);
    $phpWord->getSettings()->setUpdateFields(true); // refresh PAGE / NUMPAGES on open

    /* ============================================================
     * 3. Named styles (Arial)
     * ============================================================ */
    $phpWord->addTitleStyle(1, ['name' => 'Arial', 'size' => 26, 'bold' => true, 'color' => '1E3A8A'], [
        'alignment' => 'center', 'spaceAfter' => 100,
    ]);
    $phpWord->addTitleStyle(2, ['name' => 'Arial', 'size' => 15, 'bold' => true, 'color' => '0F766E'], [
        'spaceBefore' => 300, 'spaceAfter' => 120,
        'borderBottomSize' => 12, 'borderBottomColor' => '0F766E', 'borderBottomSpace' => 4,
    ]);
    $phpWord->addTitleStyle(3, ['name' => 'Arial', 'size' => 12, 'bold' => true, 'color' => '1F2937'], [
        'spaceBefore' => 220, 'spaceAfter' => 80,
    ]);
    // Department heading — used to separate each department page
    $phpWord->addTitleStyle(4, ['name' => 'Arial', 'size' => 18, 'bold' => true, 'color' => '1E3A8A'], [
        'alignment' => 'center', 'spaceBefore' => 0, 'spaceAfter' => 200,
        'borderBottomSize' => 16, 'borderBottomColor' => '1E3A8A', 'borderBottomSpace' => 6,
    ]);

    $phpWord->addFontStyle('coverKicker', ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => '6B7280', 'allCaps' => true, 'spacing' => 60]);
    $phpWord->addFontStyle('coverTitle',  ['name' => 'Arial', 'size' => 24, 'bold' => true, 'color' => '1F2937']);
    $phpWord->addFontStyle('coverMeta',   ['name' => 'Arial', 'size' => 10, 'color' => '6B7280', 'italic' => true]);
    $phpWord->addFontStyle('emptyText',   ['name' => 'Arial', 'size' => 10, 'color' => '9CA3AF', 'italic' => true]);
    $phpWord->addFontStyle('catSepText',  ['name' => 'Arial', 'size' => 12, 'bold' => true, 'color' => '0F766E']);
    $phpWord->addFontStyle('deptKicker',  ['name' => 'Arial', 'size' => 9,  'bold' => true, 'color' => '6B7280', 'allCaps' => true, 'spacing' => 60]);

    $phpWord->addParagraphStyle('center',      ['alignment' => 'center']);
    $phpWord->addParagraphStyle('centerTight', ['alignment' => 'center', 'spaceAfter' => 0]);

    $phpWord->addTableStyle('reportTable', [
        'borderSize'  => 4,
        'borderColor' => 'D1D5DB',
        'cellMargin'  => 100,
        'layout'      => \PhpOffice\PhpWord\Style\Table::LAYOUT_FIXED,
    ]);

    /* ============================================================
     * 4. Section + header/footer
     * ============================================================ */
    $section = $phpWord->addSection([
        'marginTop'    => 1440,
        'marginBottom' => 1440,
        'marginLeft'   => 1080,
        'marginRight'  => 1080,
    ]);

    $headerSection = $section->addHeader();
    if (file_exists('headers.png')) {
        $headerSection->addImage('headers.png', [
            'width' => 420, 'height' => 75, 'alignment' => 'center',
        ]);
    }

    $footer = $section->addFooter();
    $footer->addPreserveText(
        $reportTitle . '  |  Page {PAGE} of {NUMPAGES}',
        ['name' => 'Arial', 'size' => 8, 'color' => '9CA3AF'],
        ['alignment' => 'center']
    );

    /* ============================================================
     * 5. Cover block (no stats strip)
     * ============================================================ */
    $section->addTextBreak(1);
    $section->addText('Compliance Report', 'coverKicker', 'centerTight');
    $section->addText($reportTitle, 'coverTitle', 'center');
    $section->addText('Generated on ' . date('F j, Y \a\t g:i a'), 'coverMeta', 'center');
    $section->addTextBreak(1);

    /* ============================================================
     * 6a. Column header palette — navy → blue → teal → green
     *     Each column gets its own color, sampled evenly from this list.
     * ============================================================ */
    $masterPalette = [
        '1E3A5F', // 0 · dark navy
        '2E5077', // 1 · navy blue
        '3D5A80', // 2 · dark steel
        '4A6FA5', // 3 · steel blue
        '5B87B8', // 4 · lighter steel
        '00ACC1', // 5 · teal
        '26A69A', // 6 · teal green
        '4CAF50', // 7 · green
    ];

    /**
     * Pick N evenly-spaced colors from the palette so the first header
     * column is navy and the last is green. Middle columns blend between.
     */
    $pickHeaderColors = function (int $count) use ($masterPalette): array {
        if ($count <= 1) return [$masterPalette[0]];
        $last = count($masterPalette) - 1;
        $out  = [];
        for ($i = 0; $i < $count; $i++) {
            $idx   = (int) round($i * $last / ($count - 1));
            $out[] = $masterPalette[$idx];
        }
        return $out;
    };

    /* ============================================================
     * 6. Reusable table renderer
     * ============================================================ */
    $addTable = function ($headers, $rows, $section, $colWidths = null) use ($pickHeaderColors) {
        if (empty($rows)) {
            $section->addText('No data available for this section.', 'emptyText',
                ['spaceBefore' => 60, 'spaceAfter' => 120]);
            return;
        }

        $colCount = count($headers);
        if (!$colWidths) {
            $w = (int) floor(9300 / $colCount);
            $colWidths = array_fill(0, $colCount, $w);
        }

        /* Per-column header colors */
        $headerColors = $pickHeaderColors($colCount);

        $table = $section->addTable('reportTable');

        // Header row — repeats on page breaks, one color per column
        $table->addRow(400, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ($headers as $i => $header) {
            $color = $headerColors[$i] ?? end($headerColors);
            $table->addCell($colWidths[$i] ?? null, [
                'shading'     => ['fill' => $color],
                'valign'      => 'center',
                'borderColor' => $color,       // solid color block
                'borderSize'  => 6,
            ])->addText(strtoupper((string)$header), [
                'name' => 'Arial', 'size' => 9, 'bold' => true,
                'color' => 'FFFFFF', 'allCaps' => true,
            ], ['spaceBefore' => 60, 'spaceAfter' => 60]);
        }

        // Body — zebra striping
        $rowIndex = 0;
        foreach ($rows as $row) {
            $bg = ($rowIndex % 2 === 0) ? 'F9FAFB' : 'FFFFFF';
            $table->addRow(null, ['cantSplit' => true]);
            $i = 0;
            foreach ($row as $cell) {
                $table->addCell($colWidths[$i] ?? null, [
                    'shading'     => ['fill' => $bg],
                    'borderColor' => 'E5E7EB',
                    'borderSize'  => 4,
                    'valign'      => 'center',
                ])->addText((string)$cell,
                    ['name' => 'Arial', 'size' => 10, 'color' => '374151'],
                    ['spaceBefore' => 40, 'spaceAfter' => 40]);
                $i++;
            }
            $rowIndex++;
        }
        $section->addTextBreak(0);
    };

    /* ============================================================
     * 7. Grouped-by-category single-table renderer
     *    One table. Category separator rows (full-width, gridSpan)
     *    visually divide documents by category inside the table.
     * ============================================================ */
    $addCategoryTable = function ($headers, $colWidths, $groupedByCategory, $section) use ($pickHeaderColors) {
        if (empty($groupedByCategory)) {
            $section->addText('No data available for this section.', 'emptyText',
                ['spaceBefore' => 60, 'spaceAfter' => 120]);
            return;
        }

        $colCount     = count($headers);
        $totalWidth   = array_sum($colWidths);
        $headerColors = $pickHeaderColors($colCount);

        $table = $section->addTable('reportTable');

        // Header row (repeats on page breaks) — per-column colors
        $table->addRow(400, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ($headers as $i => $header) {
            $color = $headerColors[$i] ?? end($headerColors);
            $table->addCell($colWidths[$i], [
                'shading'     => ['fill' => $color],
                'valign'      => 'center',
                'borderColor' => $color,
                'borderSize'  => 6,
            ])->addText(strtoupper((string)$header), [
                'name' => 'Arial', 'size' => 10, 'bold' => true,
                'color' => 'FFFFFF', 'allCaps' => true,
            ], ['spaceBefore' => 60, 'spaceAfter' => 60]);
        }

        foreach ($groupedByCategory as $catName => $rows) {
            // Full-width category separator row
            $table->addRow(420, ['cantSplit' => true]);
            $table->addCell($totalWidth, [
                'gridSpan'    => $colCount,
                'shading'     => ['fill' => 'DFF2EE'],
                'borderColor' => 'D1D5DB',
                'borderSize'  => 4,
                'valign'      => 'center',
            ])->addText((string)$catName, 'catSepText',
                ['spaceBefore' => 80, 'spaceAfter' => 80]);

            // Document rows
            $idx = 0;
            foreach ($rows as $row) {
                $bg = ($idx % 2 === 0) ? 'F9FAFB' : 'FFFFFF';
                $table->addRow(null, ['cantSplit' => true]);
                $i = 0;
                foreach ($row as $cell) {
                    $table->addCell($colWidths[$i] ?? null, [
                        'shading'     => ['fill' => $bg],
                        'borderColor' => 'E5E7EB',
                        'borderSize'  => 4,
                        'valign'      => 'center',
                    ])->addText((string)$cell,
                        ['name' => 'Arial', 'size' => 10, 'color' => '374151'],
                        ['spaceBefore' => 40, 'spaceAfter' => 40]);
                    $i++;
                }
                $idx++;
            }
        }
        $section->addTextBreak(0);
    };

    /* ============================================================
     * 8. Data helpers
     * ============================================================ */
    $lwExp = latestDocsWindow();

    $approvedByDept = function () use ($pdo, $lwExp) {
        $rows = $pdo->query("
            SELECT COALESCE(dep.department_name, 'None') AS dept, d.title, d.document_type, d.category,
                   DATE(d.upload_date) AS upload_date, d.expiry_date, d.archived
            FROM $lwExp d
            LEFT JOIN departments dep ON d.department_id = dep.id
            WHERE d.status = 'approved_admin'
            ORDER BY dep.department_name, d.title
        ")->fetchAll();
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['dept']][] = [
                $r['title'], $r['document_type'], $r['category'] ?: 'Uncategorized',
                $r['upload_date'], $r['expiry_date'] ?: 'N/A',
                $r['archived'] ? 'Archived' : 'Active',
            ];
        }
        return $groups;
    };

    $reqValidityBuckets = function () use ($pdo) {
        $reqRows = $pdo->query("
            SELECT dr.category, dr.document_type, COALESCE(d.department_name, 'None') AS department_name, dr.expiration_date
            FROM department_requirements dr
            LEFT JOIN departments d ON dr.department_id = d.id
        ")->fetchAll();
        $b = ['active' => [], 'expiring' => [], 'expired' => []];
        foreach ($reqRows as $row) {
            $s = classifyExpiryStatus($row['expiration_date']);
            $b[$s][] = [
                $row['category']      ?: 'Uncategorized',
                $row['document_type'] ?: 'N/A',
                $row['department_name'],
                $row['expiration_date'] ?: 'N/A',
            ];
        }
        return $b;
    };

    // Grouped by DEPARTMENT first, then CATEGORY inside each department.
    // Returns: [departmentName => [categoryName => [ [Category, Title, Department], ... ]]]
    $docsGroupedByDeptThenCategory = function () use ($pdo, $lwExp) {
        $stmt = $pdo->query("
            SELECT COALESCE(dep.department_name, 'None') AS department_name,
                   COALESCE(NULLIF(d.category, ''), 'Uncategorized') AS category,
                   d.document_type AS title
            FROM $lwExp d
            LEFT JOIN departments dep ON d.department_id = dep.id
            WHERE d.status = 'approved_admin'
            ORDER BY department_name ASC, category ASC, d.document_type ASC
        ");
        $grouped = [];
        while ($r = $stmt->fetch()) {
            $grouped[$r['department_name']][$r['category']][] =
                [$r['category'], $r['title'], $r['department_name']];
        }
        return $grouped;
    };

    /* ============================================================
     * 9. Report bodies
     * ============================================================ */
    switch ($reportType) {

    /* ============================================================
     * ALL DOCUMENTS — one department per page
     * Title column = document_type
     * ============================================================ */
    case 'all_documents':
        $section->addTitle('Approved Documents by Department', 2);
        $firstDept = true;
        foreach ($approvedByDept() as $deptName => $rows) {
            if (!$firstDept) $section->addPageBreak();
            $firstDept = false;

            $section->addText('Department', 'deptKicker', 'centerTight');
            $section->addTitle($deptName, 4);

            // Drop the old redundant "Title" column; keep document_type as "Title"
            $trimmed = array_map(function ($r) {
                // $r = [title, document_type, category, upload_date, expiry_date, status]
                return [
                    $r[1],  // document_type → Title
                    $r[2],  // category
                    $r[3],  // upload date
                    $r[4],  // expiry
                    $r[5],  // status
                ];
            }, $rows);

            $addTable(
                ['Title', 'Category', 'Upload Date', 'Expiry Date', 'Status'],
                $trimmed, $section,
                [2700, 1800, 1600, 1500, 1500]
            );
        }
        break;

    /* ============================================================
     * BY REQUIREMENTS — already one department per page
     * ============================================================ */
    case 'by_requirements':
        $section->addTitle('Requirements by Category', 2);
        $deptGroups = $docsGroupedByDeptThenCategory();
        if (empty($deptGroups)) {
            $section->addText('No data available for this section.', 'emptyText',
                ['spaceBefore' => 60, 'spaceAfter' => 120]);
            break;
        }
        $firstDept = true;
        foreach ($deptGroups as $deptName => $categories) {
            if (!$firstDept) $section->addPageBreak();
            $firstDept = false;

            $section->addText('Department', 'deptKicker', 'centerTight');
            $section->addTitle($deptName, 4);

            $addCategoryTable(
                ['Category', 'Title', 'Department'],
                [3000, 2800, 3500],
                $categories,
                $section
            );
        }
        break;

    /* ============================================================
     * BY DEPARTMENT — summary + one department per page
     * ============================================================ */
    case 'by_department':
        $section->addTitle('Summary by Department', 2);
        $stmt = $pdo->query("
            SELECT d.id, d.department_name, COUNT(doc.id) as count
            FROM departments d
            LEFT JOIN $lwExp doc ON d.id = doc.department_id AND doc.status = 'approved_admin'
            GROUP BY d.id
            ORDER BY count DESC, d.department_name
        ");
        $deptSummary = [];
        while ($row = $stmt->fetch()) {
            $deptSummary[] = [$row['department_name'], $row['count']];
        }
        $addTable(['Department', 'Document Count'], $deptSummary, $section, [6900, 2400]);

        // Collect docs grouped by department
        $stmtDeptList = $pdo->query("
            SELECT d.department_name, doc.title, doc.document_type, doc.category, doc.expiry_date
            FROM departments d
            LEFT JOIN $lwExp doc ON d.id = doc.department_id AND doc.status = 'approved_admin'
            ORDER BY d.department_name, doc.title
        ");
        $deptDocs = [];
        while ($row = $stmtDeptList->fetch()) {
            if (!empty($row['title'])) {
                $deptDocs[$row['department_name']][] = [
                    $row['document_type'],                 // Title = document_type
                    $row['category'] ?: 'Uncategorized',
                    $row['expiry_date'] ?: 'N/A',
                ];
            }
        }

        // Documents per Department — one page per department
        $section->addPageBreak();
        $section->addTitle('Documents per Department', 2);

        $firstDept = true;
        foreach ($deptDocs as $deptName => $rows) {
            if (!$firstDept) $section->addPageBreak();
            $firstDept = false;

            $section->addText('Department', 'deptKicker', 'centerTight');
            $section->addTitle($deptName, 4);

            $addTable(['Title', 'Category', 'Expiry Date'], $rows, $section, [4200, 2800, 2300]);
        }
        break;

    /* ============================================================
     * BY VALIDITY — one department per page in each validity bucket
     * ============================================================ */
    case 'by_validity':
        $v = $reqValidityBuckets();
        $validitySections = [
            'Active Requirements'                              => $v['active'],
            'Expiring Within ' . EXPIRY_WARNING_DAYS . ' Days' => $v['expiring'],
            'Expired Requirements'                             => $v['expired'],
        ];

        $firstValidity = true;
        foreach ($validitySections as $label => $rows) {
            if (!$firstValidity) $section->addPageBreak();
            $firstValidity = false;

            $section->addTitle($label, 2);

            if (empty($rows)) {
                $section->addText('No data available for this section.', 'emptyText');
                continue;
            }

            // Group by department (index 2 = department_name)
            $byDept = [];
            foreach ($rows as $r) {
                $byDept[$r[2] ?? 'None'][] = $r;
            }

            $firstDept = true;
            foreach ($byDept as $deptName => $deptRows) {
                if (!$firstDept) $section->addPageBreak();
                $firstDept = false;

                $section->addText('Department', 'deptKicker', 'centerTight');
                $section->addTitle($deptName, 4);

                $addTable(
                    ['Category', 'Title', 'Department', 'Expiry Date'],
                    $deptRows, $section,
                    [2500, 2200, 2200, 2400]
                );
            }
        }
        break;

    /* ============================================================
     * ARCHIVED — one department per page
     * ============================================================ */
    case 'archived':
        $section->addTitle('Archived Documents', 2);
        $stmt = $pdo->query($archivedRowsSql($lwExp));
        $byDept = [];
        while ($row = $stmt->fetch()) {
            $byDept[$row['department_name'] ?: 'None'][] = [
                $row['document_type'],                     // Title = document_type
                $row['category'] ?: 'Uncategorized',
                $row['department_name'] ?: 'None',
                date('Y-m-d', strtotime($row['upload_date'])),
                $row['expiry_date'] ?: 'N/A',
            ];
        }

        if (empty($byDept)) {
            $section->addText('No archived documents found.', 'emptyText');
            break;
        }

        $firstDept = true;
        foreach ($byDept as $deptName => $rows) {
            if (!$firstDept) $section->addPageBreak();
            $firstDept = false;

            $section->addText('Department', 'deptKicker', 'centerTight');
            $section->addTitle($deptName, 4);

            $addTable(
                ['Title', 'Category', 'Department', 'Upload Date', 'Expiry Date'],
                $rows, $section,
                [2400, 1700, 1800, 1500, 1500]
            );
        }
        break;

    /* ============================================================
     * EXPIRING & EXPIRED — one department per page
     * ============================================================ */
    case 'expiring_expired':
        $section->addTitle('Expiring & Expired Documents', 2);
        $expDocs = $pdo->query("
            SELECT d.title, d.document_type, d.category, dep.department_name, d.expiry_date,
                   dr.expiration_date AS req_deadline
            FROM $lwExp d
            LEFT JOIN departments dep ON d.department_id = dep.id
            LEFT JOIN teacher_requirements tr ON d.teacher_requirement_id = tr.id
            LEFT JOIN department_requirements dr ON tr.requirement_id = dr.id
            WHERE d.status = 'approved_admin'
        ")->fetchAll();

        $byDept = [];
        foreach ($expDocs as $row) {
            $deadline = $row['req_deadline'] ?: $row['expiry_date'];
            $s = classifyExpiryStatus($deadline);
            if ($s === 'active') continue;
            $byDept[$row['department_name'] ?: 'None'][] = [
                $row['document_type'],                     // Title = document_type
                $row['category'] ?: 'Uncategorized',
                $row['department_name'] ?: 'None',
                $deadline ?: 'N/A',
                $s === 'expired' ? 'Expired' : 'Expiring',
            ];
        }

        if (empty($byDept)) {
            $section->addText('No expiring or expired documents found.', 'emptyText');
            break;
        }

        $firstDept = true;
        foreach ($byDept as $deptName => $rows) {
            if (!$firstDept) $section->addPageBreak();
            $firstDept = false;

            $section->addText('Department', 'deptKicker', 'centerTight');
            $section->addTitle($deptName, 4);

            $addTable(
                ['Title', 'Category', 'Department', 'Expiry Date', 'Status'],
                $rows, $section,
                [2400, 1700, 1800, 1500, 1500]
            );
        }
        break;

    /* ============================================================
     * ALL REPORTS — every section separates departments onto pages
     * ============================================================ */
    case 'all_reports':

        /* ---------- Section 1 — Approved Documents by Department ---------- */
        $section->addTitle('Section 1 — Approved Documents by Department', 2);
        $firstDept = true;
        foreach ($approvedByDept() as $deptName => $rows1) {
            if (!$firstDept) $section->addPageBreak();
            $firstDept = false;

            $section->addText('Department', 'deptKicker', 'centerTight');
            $section->addTitle($deptName, 4);

            $trimmed = array_map(function ($r) {
                return [
                    $r[1],  // document_type → Title
                    $r[2],  // category
                    $r[3],  // upload date
                    $r[4],  // expiry
                    $r[5],  // status
                ];
            }, $rows1);

            $addTable(
                ['Title', 'Category', 'Upload Date', 'Expiry Date', 'Status'],
                $trimmed, $section,
                [2700, 1800, 1600, 1500, 1500]
            );
        }

        /* ---------- Section 2 — Requirements by Category ---------- */
        $section->addPageBreak();
        $section->addTitle('Section 2 — Requirements by Category', 2);
        $deptGroups2 = $docsGroupedByDeptThenCategory();
        if (empty($deptGroups2)) {
            $section->addText('No data available for this section.', 'emptyText');
        } else {
            $firstDept2 = true;
            foreach ($deptGroups2 as $deptName => $categories) {
                if (!$firstDept2) $section->addPageBreak();
                $firstDept2 = false;

                $section->addText('Department', 'deptKicker', 'centerTight');
                $section->addTitle($deptName, 4);

                $addCategoryTable(
                    ['Category', 'Title', 'Department'],
                    [3000, 2800, 3500],
                    $categories,
                    $section
                );
            }
        }

        /* ---------- Section 3 — Documents by Department ---------- */
        $section->addPageBreak();
        $section->addTitle('Section 3 — Documents by Department', 2);
        $stmt = $pdo->query("
            SELECT d.id, d.department_name, COUNT(doc.id) as count
            FROM departments d
            LEFT JOIN $lwExp doc ON d.id = doc.department_id AND doc.status = 'approved_admin'
            GROUP BY d.id
            ORDER BY count DESC, d.department_name
        ");
        $deptSummary = [];
        while ($row = $stmt->fetch()) {
            $deptSummary[] = [$row['department_name'], $row['count']];
        }
        $addTable(['Department', 'Document Count'], $deptSummary, $section, [6900, 2400]);

        $stmtDeptList = $pdo->query("
            SELECT d.department_name, doc.title, doc.document_type, doc.category, doc.expiry_date
            FROM departments d
            LEFT JOIN $lwExp doc ON d.id = doc.department_id AND doc.status = 'approved_admin'
            ORDER BY d.department_name, doc.title
        ");
        $deptDocs = [];
        while ($row = $stmtDeptList->fetch()) {
            if (!empty($row['title'])) {
                $deptDocs[$row['department_name']][] = [
                    $row['document_type'],                 // Title = document_type
                    $row['category'] ?: 'Uncategorized',
                    $row['expiry_date'] ?: 'N/A',
                ];
            }
        }

        foreach ($deptDocs as $deptName => $rows) {
            $section->addPageBreak();

            $section->addText('Department', 'deptKicker', 'centerTight');
            $section->addTitle($deptName, 4);

            $addTable(['Title', 'Category', 'Expiry Date'], $rows, $section, [4200, 2800, 2300]);
        }

        /* ---------- Section 4 — Requirements by Validity ---------- */
        $section->addPageBreak();
        $section->addTitle('Section 4 — Requirements by Validity Period', 2);
        $v4 = $reqValidityBuckets();
        $validitySections4 = [
            'Active Requirements'                              => $v4['active'],
            'Expiring Within ' . EXPIRY_WARNING_DAYS . ' Days' => $v4['expiring'],
            'Expired Requirements'                             => $v4['expired'],
        ];

        $firstValidity4 = true;
        foreach ($validitySections4 as $label => $rows) {
            if (!$firstValidity4) $section->addPageBreak();
            $firstValidity4 = false;

            $section->addTitle($label, 3);

            if (empty($rows)) {
                $section->addText('No data available for this section.', 'emptyText');
                continue;
            }

            $byDept4 = [];
            foreach ($rows as $r) {
                $byDept4[$r[2] ?? 'None'][] = $r;
            }

            $firstDept4 = true;
            foreach ($byDept4 as $deptName => $deptRows) {
                if (!$firstDept4) $section->addPageBreak();
                $firstDept4 = false;

                $section->addText('Department', 'deptKicker', 'centerTight');
                $section->addTitle($deptName, 4);

                $addTable(
                    ['Category', 'Title', 'Department', 'Expiry Date'],
                    $deptRows, $section,
                    [2500, 2200, 2200, 2400]
                );
            }
        }

        /* ---------- Section 5 — Archived Documents ---------- */
        $section->addPageBreak();
        $section->addTitle('Section 5 — All Archived Documents', 2);
        $stmt = $pdo->query($archivedRowsSql($lwExp));
        $archByDept = [];
        while ($row = $stmt->fetch()) {
            $archByDept[$row['department_name'] ?: 'None'][] = [
                $row['document_type'],                     // Title = document_type
                $row['category'] ?: 'Uncategorized',
                $row['department_name'] ?: 'None',
                date('Y-m-d', strtotime($row['upload_date'])),
                $row['expiry_date'] ?: 'N/A',
            ];
        }

        if (empty($archByDept)) {
            $section->addText('No archived documents found.', 'emptyText');
        } else {
            $firstDept5 = true;
            foreach ($archByDept as $deptName => $rows) {
                if (!$firstDept5) $section->addPageBreak();
                $firstDept5 = false;

                $section->addText('Department', 'deptKicker', 'centerTight');
                $section->addTitle($deptName, 4);

                $addTable(
                    ['Title', 'Category', 'Department', 'Upload Date', 'Expiry Date'],
                    $rows, $section,
                    [2400, 1700, 1800, 1500, 1500]
                );
            }
        }
        break;
}
    $filename = 'compliance_report_' . $reportType . '_' . date('Ymd_His') . '.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    $objWriter = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
    $objWriter->save('php://output');
    exit;
}

$pageTitle = 'Compliance Reports';
include 'includes/header.php';

// -------------------------------------------------------------------
// Helper Functions & Stats (using the same classification as above)
// -------------------------------------------------------------------
function getReportStats($pdo) {
    $stats = [];

    $stats['active_count'] = 0;
    $stats['expiring_count'] = 0;
    $stats['expired_count'] = 0;
    $lw = latestDocsWindow();
    // Total = admin-approved documents (latest version per chain).
    $stats['total_documents'] = (int)$pdo->query("SELECT COUNT(*) FROM $lw d WHERE d.status = 'approved_admin'")->fetchColumn();
    // Active/Expiring/Expired = ALL department requirements across every department,
    // same rule as admin_department_requirements.php (classifyExpiryStatus on expiration_date).
    foreach ($pdo->query("SELECT expiration_date FROM department_requirements") as $row) {
        $s = classifyExpiryStatus($row['expiration_date']);
        if ($s === 'expired') $stats['expired_count']++;
        elseif ($s === 'expiring') $stats['expiring_count']++;
        else $stats['active_count']++;
    }

    $stats['archived_count'] = (int)$pdo->query("SELECT COUNT(*) FROM documents d WHERE " . archivedDocCondition())->fetchColumn()
        + (int)$pdo->query("SELECT COUNT(*) FROM ph_documents pd WHERE pd.archived = 1 AND pd.status = 'approved_admin'")->fetchColumn();

    return $stats;
}

// Get enhanced data for overview dashboard — sourced from department_requirements
// (same table admin_department_requirements.php reads)
$__lw = latestDocsWindow();
$categoryStats = $pdo->query("
    SELECT COALESCE(NULLIF(dr.category, ''), 'Uncategorized') as category, COUNT(*) as count
    FROM department_requirements dr
    GROUP BY COALESCE(NULLIF(dr.category, ''), 'Uncategorized')
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);

$deptStats = $pdo->query("
    SELECT d.id, COALESCE(NULLIF(d.department_code, ''), d.department_name) as label, COUNT(dr.id) as count
    FROM departments d
    LEFT JOIN department_requirements dr ON d.id = dr.department_id
    GROUP BY d.id
    ORDER BY count DESC, d.department_name
")->fetchAll(PDO::FETCH_ASSOC);

// Expiring requirements — same 2-day window as the stat cards (admin_department_requirements.php rule)
$recentExpiring = $pdo->query("
    SELECT dr.category, dr.document_type, COALESCE(d.department_name, 'Unassigned') as department_name, dr.expiration_date
    FROM department_requirements dr
    LEFT JOIN departments d ON dr.department_id = d.id
    WHERE dr.expiration_date IS NOT NULL
      AND dr.expiration_date > CURDATE()
      AND dr.expiration_date <= DATE_ADD(CURDATE(), INTERVAL " . EXPIRY_WARNING_DAYS . " DAY)
    ORDER BY dr.expiration_date ASC
    LIMIT 10
")->fetchAll();

$recentUploads = $pdo->query("
    SELECT d.title, d.document_type, dep.department_name, d.upload_date, d.id
    FROM $__lw d
    LEFT JOIN departments dep ON d.department_id = dep.id
    WHERE d.status = 'approved_admin'
    ORDER BY d.upload_date DESC
    LIMIT 8
")->fetchAll();

$stats = getReportStats($pdo);

// Start output buffering for the page content
ob_start();
?>

<!-- ============================================================ -->
<!-- MODERN UI – Matches admin_analytics.php design                -->
<!-- ============================================================ -->
<style>
    @import url('offline/inter-font.css');

    :root {
        --primary: #4f46e5;
        --primary-dark: #4338ca;
        --primary-light: #eef2ff;
        --primary-soft: #6366f1;
        --secondary: #64748b;
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
        --shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
        --radius-sm: 0.5rem;
        --radius-md: 0.75rem;
        --radius-lg: 12px;
        --radius-xl: 16px;
        --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        --app-bg: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    }

    [data-theme="dark"] {
        --gray-50: #0f172a;
        --gray-100: #1e293b;
        --gray-200: #334155;
        --gray-600: #94a3b8;
        --gray-700: #cbd5e1;
        --gray-900: #f8fafc;
        --white: #1e293b;
        --border: #334155;
        --app-bg: #0f172a;
    }

    [data-theme="dark"] .report-card .card-header,
    [data-theme="dark"] .data-table th {
        background: var(--gray-100);
    }

    * { box-sizing: border-box; }
    body { background: var(--app-bg); font-family: 'Inter', sans-serif; min-height: 100vh; -webkit-font-smoothing: antialiased; }

    .content-area {
        flex: 1;
        display: flex;
        flex-direction: column;
        padding: 1.25rem 1.5rem !important;
        gap: 0;
    }

    .report-container {
        flex: 1;
        width: 100%;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    /* Page Header — premium card */
    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.75rem;
        background: var(--white);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 1rem 1.25rem;
        box-shadow: var(--shadow);
    }
    .page-header h1 {
        font-size: 1.15rem;
        font-weight: 800;
        color: var(--gray-900);
        margin: 0;
        display: flex;
        align-items: center;
        gap: 0.6rem;
        letter-spacing: -0.02em;
    }
    .page-header .header-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, var(--primary) 0%, #6366f1 100%);
        color: #fff;
        box-shadow: 0 2px 8px rgba(79,70,229,0.25);
        border: 1px solid rgba(255,255,255,0.2);
    }
    .page-header .header-icon svg { width: 18px; height: 18px; }
    .page-header .subtitle { font-size: 0.78rem; color: var(--gray-500); margin-top: 0.2rem; font-weight: 500; }

    /* Stats Grid — parity with admin_dashboard */
    .stats-grid {
        display: grid;
        /* ponytail: same shrink-proofing as .charts-row — stops the row forcing a
           page-wide horizontal scrollbar when the sidebar expands. */
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 0.75rem;
    }
    .stat-card {
        background: var(--white);
        border-radius: 12px;
        padding: 1rem 0.9rem;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        transition: var(--transition);
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    .stat-card::before {
        content: '';
        position: absolute;
        left: 0; right: 0; bottom: 0;
        height: 3px;
        background: var(--kpi-accent, var(--primary));
        opacity: 1;
        top: auto;
    }
    .stat-card::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, transparent 60%, rgba(0,0,0,0.01) 100%);
        pointer-events: none;
    }
    .stat-card[data-accent="primary"] { --kpi-accent: var(--primary); }
    .stat-card[data-accent="success"] { --kpi-accent: var(--success); }
    .stat-card[data-accent="warning"] { --kpi-accent: var(--warning); }
    .stat-card[data-accent="danger"] { --kpi-accent: var(--danger); }
    .stat-card[data-accent="secondary"] { --kpi-accent: var(--secondary); }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
        border-color: var(--gray-200);
    }
    .stat-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        border: 1px solid rgba(0,0,0,0.04);
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .stat-icon svg { width: 18px; height: 18px; }
    .stat-info { flex: 1; min-width: 0; }
    .stat-info h3 {
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--gray-500);
        margin-bottom: 0.2rem;
    }
    .stat-number {
        font-size: 1.45rem;
        font-weight: 800;
        color: var(--gray-900);
        line-height: 1.1;
        letter-spacing: -0.02em;
    }

    /* Export Toolbar — premium boxed */
    .export-toolbar {
        background: var(--white);
        border-radius: 12px;
        border: 1px solid var(--border);
        padding: 0.85rem 1rem;
        box-shadow: var(--shadow);
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.75rem;
    }
    .export-group {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    .export-group svg { color: var(--primary); width: 18px; height: 18px; }
    .export-select {
        padding: 0.6rem 2rem 0.6rem 0.85rem;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: var(--white);
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--gray-700);
        cursor: pointer;
        transition: var(--transition);
        min-width: 220px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.04);
    }
    .export-select:hover { border-color: var(--gray-300); }
    .export-select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px var(--primary-light), 0 1px 2px rgba(0,0,0,0.04);
        background: white;
    }
    .btn-export {
        background: var(--primary);
        color: white;
        border: 1px solid var(--primary);
        padding: 0.6rem 1.15rem;
        border-radius: 10px;
        font-weight: 600;
        font-size: 0.82rem;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        cursor: pointer;
        transition: var(--transition);
        box-shadow: 0 1px 2px rgba(79,70,229,0.2);
        letter-spacing: 0.01em;
    }
    .btn-export:hover {
        background: var(--primary-dark);
        border-color: var(--primary-dark);
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);
    }
    .btn-export:active { transform: translateY(0); }
    .btn-export svg { width: 16px; height: 16px; }
    .export-hint { font-size: 0.72rem; color: var(--gray-500); font-weight: 500; background: var(--gray-50); border: 1px solid var(--border); padding: 0.3rem 0.6rem; border-radius: 9999px; }

    /* Tab Navigation — pill segmented parity */
    .tab-nav {
        display: flex;
        gap: 0.35rem;
        padding: 0.35rem;
        background: white;
        border-radius: 9999px;
        flex-wrap: wrap;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        width: fit-content;
        max-width: 100%;
        overflow-x: auto;
    }
    .tab-btn {
        padding: 0.45rem 0.9rem;
        border: 1px solid transparent;
        background: transparent;
        border-radius: 9999px;
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--gray-600);
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        gap: 0.35rem;
        white-space: nowrap;
        letter-spacing: 0.01em;
    }
    .tab-btn:hover { color: var(--gray-800); background: var(--gray-50); border-color: var(--gray-200); }
    .tab-btn.active {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
        box-shadow: 0 2px 8px rgba(79,70,229,0.3);
    }
    .tab-btn svg { width: 14px; height: 14px; }

    /* Cards — premium */
    .report-card {
        background: var(--white);
        border-radius: 12px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
        overflow: hidden;
        margin-bottom: 1rem;
    }
    .card-header {
        padding: 0.9rem 1.1rem;
        background: linear-gradient(180deg, #ffffff 0%, #f9fafb 100%);
        border-bottom: 1px solid var(--border);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .card-header h3 {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin: 0;
        letter-spacing: -0.01em;
    }
    .card-header h3::before {
        content: '';
        width: 3px;
        height: 16px;
        background: var(--primary);
        border-radius: 9999px;
        display: inline-block;
    }
    .card-header h3 svg { width: 16px; height: 16px; color: var(--primary); }
    .search-input {
        padding: 0.5rem 0.85rem 0.5rem 2.1rem;
        border: 1px solid var(--border);
        border-radius: 9999px;
        font-size: 0.78rem;
        width: 220px;
        transition: var(--transition);
        background: var(--gray-50) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.3-4.3'/%3E%3C/svg%3E") no-repeat 0.7rem center;
        color: var(--gray-800);
        box-shadow: 0 1px 2px rgba(0,0,0,0.04);
    }
    .search-input::placeholder { color: var(--gray-400); }
    .search-input:focus {
        outline: none;
        border-color: var(--primary);
        background-color: white;
        box-shadow: 0 0 0 3px var(--primary-light), 0 1px 2px rgba(0,0,0,0.04);
    }

    /* Data Tables — premium sticky header parity */
    .data-table-wrapper { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .data-table-wrapper::-webkit-scrollbar { height: 6px; }
    .data-table-wrapper::-webkit-scrollbar-thumb { background: var(--gray-200); border-radius: 3px; }
    .data-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 0.82rem;
    }
    .data-table th {
        background: var(--gray-50);
        padding: 0.75rem 0.9rem;
        text-align: left;
        font-weight: 700;
        color: var(--gray-500);
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        cursor: default;
        position: sticky;
        top: 0;
        z-index: 1;
    }
    .data-table th[data-sortable] {
        cursor: pointer;
        user-select: none;
    }
    .data-table th[data-sortable]:hover { background: var(--gray-100); color: var(--gray-700); }
    .data-table td {
        padding: 0.7rem 0.9rem;
        border-bottom: 1px solid var(--gray-100);
        color: var(--gray-700);
    }
    .data-table tbody tr:last-child td { border-bottom: none; }
    .data-table tbody tr:hover td { background: #f8fafc; }

    /* Badges — premium pill with border */
    .badge {
        display: inline-flex;
        align-items: center;
        padding: 0.22rem 0.55rem;
        border-radius: 9999px;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        border: 1px solid transparent;
        line-height: 1.25;
    }
    .badge-active { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
    .badge-expiring { background: #fef3c7; color: #92400e; border-color: #fde68a; }
    .badge-expired { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
    .badge-archived { background: var(--gray-100); color: var(--gray-600); border-color: var(--gray-200); }

    /* Group sections inside cards — premium */
    .group-section {
        padding: 1.1rem 1.25rem;
        border-bottom: 1px solid var(--gray-100);
    }
    .group-section:last-child { border-bottom: none; }
    .group-header {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--gray-900);
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.7rem;
        letter-spacing: -0.01em;
    }
    .group-header svg { width: 16px; height: 16px; color: var(--primary); }
    .group-count {
        background: var(--gray-50);
        color: var(--gray-600);
        padding: 0.2rem 0.55rem;
        border-radius: 9999px;
        font-size: 0.68rem;
        font-weight: 700;
        border: 1px solid var(--border);
        letter-spacing: 0.02em;
    }

    /* Charts Row — premium cards */
    .charts-row {
        display: grid;
        /* ponytail: 1fr alone can't shrink below Chart.js's inline canvas px width →
           sidebar toggle leaves a stuck chart + page-wide h-scrollbar. minmax(0,1fr)
           lets the card shrink so Chart.js's ResizeObserver fires. Drop it only if
           charts are ever replaced by non-px-sized elements. */
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1rem;
    }
    .chart-card {
        background: var(--white);
        border-radius: 12px;
        padding: 1.1rem;
        border: 1px solid var(--border);
        box-shadow: var(--shadow);
    }
    .chart-card h4 {
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--gray-900);
        margin-bottom: 0.85rem;
        letter-spacing: -0.01em;
        display: flex;
        align-items: center;
        gap: 0.4rem;
    }
    .chart-card h4::before {
        content: '';
        width: 3px;
        height: 14px;
        background: var(--primary);
        border-radius: 9999px;
        display: inline-block;
    }
    .chart-card canvas { max-height: 180px; width: 100%; }

    /* Recent list */
    .recent-list {
        list-style: none;
        max-height: 220px;
        overflow-y: auto;
        margin: 0;
        padding: 0;
    }
    .recent-list li {
        padding: 0.5rem 0;
        border-bottom: 1px solid var(--gray-100);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.5rem;
    }
    .recent-list li:last-child { border-bottom: none; }
    .recent-list .doc-title {
        font-weight: 500;
        color: var(--gray-800);
        font-size: 0.8125rem;
    }
    .recent-list .doc-meta {
        font-size: 0.6875rem;
        color: var(--gray-600);
        margin-top: 0.1rem;
    }

    /* Empty State */
    .empty-state {
        padding: 1.5rem;
        text-align: center;
        color: var(--gray-600);
        font-size: 0.875rem;
    }

    /* Tab Content */
    .tab-content { display: none; }
    .tab-content.active { display: block; }

    /* Responsive */
    @media (max-width: 1200px) {
        .stats-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .charts-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 768px) {
        .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .charts-row { grid-template-columns: minmax(0, 1fr); }
        .export-toolbar { flex-direction: column; align-items: stretch; }
        .tab-nav { flex-wrap: nowrap; overflow-x: auto; }
        .report-container { padding: 0.625rem; }
    }
    @media (max-width: 480px) {
        .stats-grid { grid-template-columns: minmax(0, 1fr); }
    }

    /* Print */
    @media print {
        .no-print, .export-toolbar, .tab-nav, .stats-grid .stat-card:not(:first-child) { display: none !important; }
        body { background: #fff; }
        .report-card { break-inside: avoid; }
    }
</style>

<div class="report-container">

    <!-- Stats Grid -->
    <div class="stats-grid no-print">
        <div class="stat-card" data-accent="primary">
            <div class="stat-icon" style="background:var(--primary-light);color:var(--primary);">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            </div>
            <div class="stat-info">
                <h3>Approved Documents</h3>
                <div class="stat-number"><?= number_format($stats['total_documents']) ?></div>
            </div>
        </div>
        <div class="stat-card" data-accent="success">
            <div class="stat-icon" style="background:var(--success-light);color:var(--success);">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="stat-info">
                <h3>Active Compliant</h3>
                <div class="stat-number"><?= number_format($stats['active_count']) ?></div>
            </div>
        </div>
        <div class="stat-card" data-accent="warning">
            <div class="stat-icon" style="background:var(--warning-light);color:var(--warning);">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="stat-info">
                <h3>Expiring (<?= EXPIRY_WARNING_DAYS ?> days)</h3>
                <div class="stat-number"><?= number_format($stats['expiring_count']) ?></div>
            </div>
        </div>
        <div class="stat-card" data-accent="danger">
            <div class="stat-icon" style="background:var(--danger-light);color:var(--danger);">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </div>
            <div class="stat-info">
                <h3>Expired</h3>
                <div class="stat-number"><?= number_format($stats['expired_count']) ?></div>
            </div>
        </div>
        <div class="stat-card" data-accent="secondary">
            <div class="stat-icon" style="background:var(--gray-200);color:var(--gray-600);">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
            </div>
            <div class="stat-info">
                <h3>Archived</h3>
                <div class="stat-number"><?= number_format($stats['archived_count']) ?></div>
            </div>
        </div>
    </div>

    <!-- Export Toolbar -->
    <div class="export-toolbar no-print">
        <div class="export-group">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <form method="GET" id="exportForm" style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
                <select name="export_report" class="export-select">
                    <option value="">Select Report Type</option>
                    <option value="all_documents">All Documents</option>
                    <option value="by_requirements">Requirements by Category</option>
                    <option value="by_department">By Department</option>
                    <option value="by_validity">By Validity</option>
                    <option value="archived">Archived Documents</option>
                    <option value="all_reports">All Reports</option>
                </select>
                <button type="submit" class="btn-export">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Download DOCX
                </button>
            </form>
        </div>
        <span class="export-hint">Microsoft Word format</span>
    </div>

    <!-- Tab Navigation -->
    <div class="tab-nav no-print" id="tabNav">
        <button type="button" class="tab-btn active" id="tabOverview" data-tab="overview">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/></svg>
            Overview
        </button>
        <button type="button" class="tab-btn" id="tabDocuments" data-tab="documents">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            All Documents
        </button>
        <button type="button" class="tab-btn" id="tabCategories" data-tab="categories">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
            By Category
        </button>
        <button type="button" class="tab-btn" id="tabDepartments" data-tab="departments">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 1h6v6H9V1z"/></svg>
            By Department
        </button>
        <button type="button" class="tab-btn" id="tabValidity" data-tab="validity">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            By Validity
        </button>
        <button type="button" class="tab-btn" id="tabArchived" data-tab="archived">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
            Archived
        </button>
    </div>

    <!-- ==================== TAB CONTENT ==================== -->
    <div id="overview" class="tab-content active">
        <div class="charts-row">
            <div class="chart-card">
                <h4>Requirements by Category</h4>
                <canvas id="categoryChart"></canvas>
            </div>
            <div class="chart-card">
                <h4>Requirements by Department</h4>
                <canvas id="deptChart"></canvas>
            </div>
            <div class="chart-card">
                <h4>Expiring Within <?= EXPIRY_WARNING_DAYS ?> Days</h4>
                <?php if (count($recentExpiring) > 0): ?>
                <ul class="recent-list">
                    <?php foreach ($recentExpiring as $exp): ?>
                    <li>
                        <div>
                            <div class="doc-title"><?= sanitize($exp['category'] . ' - ' . $exp['document_type']) ?></div>
                            <div class="doc-meta"><?= sanitize($exp['department_name']) ?></div>
                        </div>
                        <span class="badge badge-expiring"><?= date('M d', strtotime($exp['expiration_date'])) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <p class="empty-state">No requirements expiring soon</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="report-card">
            <div class="card-header">
                <h3>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Recently Uploaded
                </h3>
            </div>
            <div class="data-table-wrapper">
                <table class="data-table">
                    <thead><tr><th>Title</th><th>Type</th><th>Department</th><th>Upload Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentUploads as $up): ?>
                        <tr>
                            <td><?= sanitize($up['title']) ?></td>
                            <td><?= sanitize($up['document_type']) ?></td>
                            <td><?= sanitize($up['department_name'] ?? 'None') ?></td>
                            <td><?= date('Y-m-d', strtotime($up['upload_date'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="documents" class="tab-content">
        <div class="report-card">
            <div class="card-header">
                <h3>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    All Compliance Documents
                </h3>
                <input type="text" class="search-input" data-table="documents" placeholder="Search documents...">
            </div>
            <?php
            // Only admin-approved documents, grouped by department
            $__lwDocs = latestDocsWindow();
            $docsByDept = [];
            $docsStmt = $pdo->query("SELECT dep.department_name, d.title, d.document_type, d.category, d.upload_date, d.expiry_date, d.archived FROM $__lwDocs d LEFT JOIN departments dep ON d.department_id = dep.id WHERE d.status = 'approved_admin' ORDER BY dep.department_name, d.title");
            while ($doc = $docsStmt->fetch()) {
                $docsByDept[$doc['department_name'] ?: 'None'][] = $doc;
            }
            if (empty($docsByDept)):
            ?>
            <p class="empty-state">No approved documents.</p>
            <?php else: foreach ($docsByDept as $deptName => $docs): ?>
            <div class="group-section">
                <div class="group-header">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                    <?= sanitize($deptName) ?>
                    <span class="group-count"><?= count($docs) ?></span>
                </div>
                <table class="data-table">
                    <thead><tr><th data-sortable>Title</th><th data-sortable>Type</th><th data-sortable>Category</th><th data-sortable>Upload Date</th><th data-sortable>Expiry</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($docs as $doc):
                        $statusClass = $doc['archived'] ? 'badge-archived' : 'badge-active';
                        $statusText = $doc['archived'] ? 'Archived' : 'Active';
                    ?>
                        <tr>
                            <td><?= sanitize($doc['title']) ?></td>
                            <td><?= sanitize($doc['document_type']) ?></td>
                            <td><?= sanitize($doc['category'] ?: 'Uncategorized') ?></td>
                            <td><?= date('Y-m-d', strtotime($doc['upload_date'])) ?></td>
                            <td><?= sanitize($doc['expiry_date']) ?: 'N/A' ?></td>
                            <td><span class="badge <?= $statusClass ?>"><?= $statusText ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div id="categories" class="tab-content">
        <div class="report-card">
            <div class="card-header">
                <h3>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    Documents by Category
                </h3>
            </div>
            <?php
            $__lwCats = latestDocsWindow();
            $cats = $pdo->query("SELECT COALESCE(NULLIF(d.category, ''), 'Uncategorized') as category, COUNT(*) as cnt FROM $__lwCats d WHERE d.status = 'approved_admin' GROUP BY COALESCE(NULLIF(d.category, ''), 'Uncategorized') ORDER BY cnt DESC")->fetchAll();
            foreach ($cats as $cat):
                $catName = $cat['category'];
            ?>
            <div class="group-section">
                <div class="group-header">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                    <?= sanitize($catName) ?>
                    <span class="group-count"><?= $cat['cnt'] ?></span>
                </div>
                <table class="data-table">
                    <thead><tr><th>Title</th><th>Type</th><th>Department</th></tr></thead>
                    <tbody>
                    <?php
                    $__lwCatDet = latestDocsWindow();
                    if ($catName === 'Uncategorized') {
                        $stmtCat = $pdo->prepare("SELECT d.title, d.document_type, dep.department_name FROM $__lwCatDet d LEFT JOIN departments dep ON d.department_id = dep.id WHERE d.status = 'approved_admin' AND (d.category IS NULL OR d.category = '') ORDER BY d.title");
                        $stmtCat->execute();
                    } else {
                        $stmtCat = $pdo->prepare("SELECT d.title, d.document_type, dep.department_name FROM $__lwCatDet d LEFT JOIN departments dep ON d.department_id = dep.id WHERE d.status = 'approved_admin' AND d.category = :cat ORDER BY d.title");
                        $stmtCat->execute([':cat' => $catName]);
                    }
                    while ($doc = $stmtCat->fetch()):
                    ?>
                        <tr><td><?= sanitize($doc['title']) ?></td><td><?= sanitize($doc['document_type']) ?></td><td><?= sanitize($doc['department_name'] ?: 'None') ?></td></tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="departments" class="tab-content">
        <div class="report-card">
            <div class="card-header">
                <h3>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 1h6v6H9V1z"/></svg>
                    Documents by Department
                </h3>
            </div>
            <?php
            $__lwDepts = latestDocsWindow();
            $depts = $pdo->query("SELECT d.id, d.department_name, COUNT(doc.id) as cnt FROM departments d LEFT JOIN $__lwDepts doc ON d.id = doc.department_id AND doc.status = 'approved_admin' GROUP BY d.id ORDER BY d.department_name")->fetchAll();
            foreach ($depts as $dept):
            ?>
            <div class="group-section">
                <div class="group-header">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 1h6v6H9V1z"/></svg>
                    <?= sanitize($dept['department_name']) ?>
                    <span class="group-count"><?= $dept['cnt'] ?></span>
                </div>
                <table class="data-table">
                    <thead><tr><th>Title</th><th>Type</th><th>Category</th><th>Expiry</th></tr></thead>
                    <tbody>
                    <?php
                    $__lwDeptDet = latestDocsWindow();
                    $stmtDept = $pdo->prepare("SELECT d.title, d.document_type, d.category, d.expiry_date FROM $__lwDeptDet d WHERE d.status = 'approved_admin' AND d.department_id = ? ORDER BY d.title");
                    $stmtDept->execute([$dept['id']]);
                    while ($doc = $stmtDept->fetch()):
                    ?>
                        <tr><td><?= sanitize($doc['title']) ?></td><td><?= sanitize($doc['document_type']) ?></td><td><?= sanitize($doc['category'] ?: 'Uncategorized') ?></td><td><?= $doc['expiry_date'] ?: 'N/A' ?></td></tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="validity" class="tab-content">
        <?php
        // Validity sourced from department_requirements (admin_department_requirements.php rule)
        $reqRows = $pdo->query("
            SELECT dr.category, dr.document_type, COALESCE(d.department_name, 'None') AS department_name, dr.expiration_date
            FROM department_requirements dr
            LEFT JOIN departments d ON dr.department_id = d.id
        ")->fetchAll();
        $vActive = []; $vExpiring = []; $vExpired = [];
        foreach ($reqRows as $row) {
            $s = classifyExpiryStatus($row['expiration_date']);
            $entry = [
                'category'        => $row['category'] ?: 'Uncategorized',
                'type'            => $row['document_type'] ?: 'N/A',
                'department_name' => $row['department_name'],
                'expiry_date'     => $row['expiration_date'] ?: 'N/A',
            ];
            if ($s === 'expired') $vExpired[] = $entry;
            elseif ($s === 'expiring') $vExpiring[] = $entry;
            else $vActive[] = $entry;
        }
        ?>
        <div class="report-card">
            <div class="card-header">
                <h3>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                    Active Requirements
                </h3>
            </div>
            <div class="data-table-wrapper">
                <table class="data-table">
                    <thead><tr><th>Category</th><th>Title</th><th>Department</th><th>Expiry</th></tr></thead>
                    <tbody>
                    <?php foreach ($vActive as $r): ?>
                        <tr><td><?= sanitize($r['category']) ?></td><td><?= sanitize($r['type']) ?></td><td><?= sanitize($r['department_name']) ?></td><td><?= sanitize($r['expiry_date']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($vActive)): ?><tr><td colspan="4" style="text-align:center;color:#94A3B8;">No active requirements</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="report-card">
            <div class="card-header">
                <h3>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Expiring Within <?= EXPIRY_WARNING_DAYS ?> Days
                </h3>
            </div>
            <div class="data-table-wrapper">
                <table class="data-table">
                    <thead><tr><th>Category</th><th>Title</th><th>Department</th><th>Expiry</th></tr></thead>
                    <tbody>
                    <?php foreach ($vExpiring as $r): ?>
                        <tr><td><?= sanitize($r['category']) ?></td><td><?= sanitize($r['type']) ?></td><td><?= sanitize($r['department_name']) ?></td><td><?= sanitize($r['expiry_date']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($vExpiring)): ?><tr><td colspan="4" style="text-align:center;color:#94A3B8;">No requirements expiring soon</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="report-card">
            <div class="card-header">
                <h3>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    Expired Requirements
                </h3>
            </div>
            <div class="data-table-wrapper">
                <table class="data-table">
                    <thead><tr><th>Category</th><th>Title</th><th>Department</th><th>Expiry Date</th></tr></thead>
                    <tbody>
                    <?php foreach ($vExpired as $r): ?>
                        <tr><td><?= sanitize($r['category']) ?></td><td><?= sanitize($r['type']) ?></td><td><?= sanitize($r['department_name']) ?></td><td><?= sanitize($r['expiry_date']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($vExpired)): ?><tr><td colspan="4" style="text-align:center;color:#94A3B8;">No expired requirements</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="archived" class="tab-content">
        <div class="report-card">
            <div class="card-header">
                <h3>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                    Archived Documents
                </h3>
            </div>
            <div class="data-table-wrapper">
                <table class="data-table">
                    <thead><tr><th>Title</th><th>Type</th><th>Category</th><th>Department</th><th>Upload Date</th><th>Expiry</th></tr></thead>
                    <tbody>
                    <?php
                    $__lwArch = latestDocsWindow();
                    $archStmt = $pdo->query($archivedRowsSql($__lwArch));
                    while ($r = $archStmt->fetch()):
                    ?>
                        <tr>
                            <td><?= sanitize($r['title']) ?></td>
                            <td><?= sanitize($r['document_type']) ?></td>
                            <td><?= sanitize($r['category'] ?: 'Uncategorized') ?></td>
                            <td><?= sanitize($r['department_name'] ?: 'None') ?></td>
                            <td><?= date('Y-m-d', strtotime($r['upload_date'])) ?></td>
                            <td><?= sanitize($r['expiry_date']) ?: 'N/A' ?></td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script nonce="<?= csp_nonce() ?>">
    document.addEventListener('DOMContentLoaded', function() {
        // Tab Navigation
        const tabNav = document.getElementById('tabNav');
        if (tabNav) {
            tabNav.addEventListener('click', function(e) {
                const btn = e.target.closest('.tab-btn');
                if (!btn) return;
                const tabId = btn.getAttribute('data-tab');
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                const content = document.getElementById(tabId);
                if (content) content.classList.add('active');
            });
        }

        // Table search filter
        document.querySelectorAll('.search-input').forEach(input => {
            input.addEventListener('keyup', function() {
                const tableId = this.getAttribute('data-table');
                const table = document.getElementById(tableId);
                if (!table) return;
                const filter = this.value.toLowerCase();
                const rows = table.querySelectorAll('tbody tr');
                rows.forEach(row => {
                    const text = row.innerText.toLowerCase();
                    row.style.display = text.includes(filter) ? '' : 'none';
                });
            });
        });

        // Table sorting
        document.querySelectorAll('.data-table th[data-sortable]').forEach(th => {
            th.addEventListener('click', function() {
                const table = this.closest('.data-table');
                const tbody = table.querySelector('tbody');
                const rows = Array.from(tbody.querySelectorAll('tr'));
                const index = this.cellIndex;
                const dir = this.getAttribute('data-sort-dir') === 'asc' ? 'desc' : 'asc';
                rows.sort((a, b) => {
                    let aVal = a.cells[index] ? a.cells[index].innerText.trim() : '';
                    let bVal = b.cells[index] ? b.cells[index].innerText.trim() : '';
                    const aNum = parseFloat(aVal), bNum = parseFloat(bVal);
                    if (!isNaN(aNum) && !isNaN(bNum)) { aVal = aNum; bVal = bNum; }
                    if (aVal < bVal) return dir === 'asc' ? -1 : 1;
                    if (aVal > bVal) return dir === 'asc' ? 1 : -1;
                    return 0;
                });
                rows.forEach(row => tbody.appendChild(row));
                th.setAttribute('data-sort-dir', dir);
            });
        });

        // Charts
        try {
            const categoryLabels = <?= json_encode(array_column($categoryStats, 'category')) ?>;
            const categoryCounts = <?= json_encode(array_column($categoryStats, 'count')) ?>;
            const deptLabels = <?= json_encode(array_column($deptStats, 'label')) ?>;
            const deptCounts = <?= json_encode(array_column($deptStats, 'count')) ?>;
            const chartColors = ['#4F46E5', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC489A', '#14B8A6', '#F97316'];

            if (document.getElementById('categoryChart') && typeof Chart !== 'undefined') {
                new Chart(document.getElementById('categoryChart'), {
                    type: 'bar',
                    data: {
                        labels: categoryLabels,
                        datasets: [{ label: 'Documents', data: categoryCounts, backgroundColor: '#4F46E5', borderRadius: 6, maxBarThickness: 50 }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
                    }
                });
            }
            if (document.getElementById('deptChart') && typeof Chart !== 'undefined') {
                new Chart(document.getElementById('deptChart'), {
                    type: 'doughnut',
                    data: {
                        labels: deptLabels,
                        datasets: [{ data: deptCounts, backgroundColor: chartColors, borderWidth: 0 }]
                    },
                    options: {
                        responsive: true,
                        plugins: { legend: { position: 'right', labels: { boxWidth: 12, padding: 12 } } }
                    }
                });
            }
        } catch (e) {
            console.log('Chart error:', e);
        }
    });
</script>

<?php
$content = ob_get_clean();
echo $content;
include 'includes/footer.php';
?>


