<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['manager', 'engineer']);

require_once __DIR__ . '/includes/db.php';

/* =========================================
   1. CSV SAFETY
========================================= */

function csvSafe($value): string
{
    $value = (string)$value;

    if (preg_match('/^[\s]*[=+\-@]/u', $value)) {
        return "'" . $value;
    }

    return $value;
}

/* =========================================
   2. VALIDATE FILTERS
========================================= */

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$projectId = filter_var(
    $_GET['project_id'] ?? '0',
    FILTER_VALIDATE_INT
);

if ($projectId === false || $projectId < 0) {
    http_response_code(400);
    exit('Invalid project selection. Return to Reports.');
}

$startObject = is_string($startDate)
    ? DateTime::createFromFormat('!Y-m-d', $startDate)
    : false;

$endObject = is_string($endDate)
    ? DateTime::createFromFormat('!Y-m-d', $endDate)
    : false;

if (
    !$startObject ||
    $startObject->format('Y-m-d') !== $startDate ||
    !$endObject ||
    $endObject->format('Y-m-d') !== $endDate ||
    $startDate > $endDate
) {
    http_response_code(400);
    exit('Invalid date range. Return to Reports and select valid dates.');
}

/* =========================================
   3. GET PROJECT NAME
========================================= */

$projectName = 'All Projects';

if ($projectId > 0) {
    $projectStmt = $pdo->prepare("
        SELECT project_name
        FROM projects
        WHERE project_id = ?
    ");

    $projectStmt->execute([$projectId]);

    $projectName = $projectStmt->fetchColumn();

    if ($projectName === false) {
        http_response_code(400);
        exit('Selected project does not exist.');
    }
}

/* =========================================
   4. GET MATERIAL REPORT DATA
========================================= */

$sql = "
    SELECT
        m.material_name,
        m.unit,
        m.minimum_stock,

        COALESCE(r.received_qty, 0) AS received_qty,
        COALESCE(r.material_cost, 0) AS material_cost,

        COALESCE(a.allocated_qty, 0) AS allocated_qty,
        COALESCE(u.used_qty, 0) AS used_qty,
        COALESCE(w.wasted_qty, 0) AS wasted_qty,

        (
            COALESCE(ar.received_all_time, 0)
            - COALESCE(aa.allocated_all_time, 0)
        ) AS store_balance

    FROM materials m

    LEFT JOIN (
        SELECT
            material_id,
            SUM(quantity) AS received_qty,
            SUM(quantity * unit_cost) AS material_cost
        FROM material_receipts
        WHERE received_date BETWEEN ? AND ?
          AND (? = 0 OR project_id = ?)
        GROUP BY material_id
    ) r ON r.material_id = m.material_id

    LEFT JOIN (
        SELECT
            material_id,
            SUM(quantity) AS allocated_qty
        FROM allocations
        WHERE allocation_date BETWEEN ? AND ?
          AND (? = 0 OR project_id = ?)
        GROUP BY material_id
    ) a ON a.material_id = m.material_id

    LEFT JOIN (
        SELECT
            al.material_id,
            SUM(mu.quantity_used) AS used_qty
        FROM material_usage mu
        INNER JOIN allocations al
            ON al.allocation_id = mu.allocation_id
        WHERE mu.usage_date BETWEEN ? AND ?
          AND (? = 0 OR al.project_id = ?)
        GROUP BY al.material_id
    ) u ON u.material_id = m.material_id

    LEFT JOIN (
        SELECT
            al.material_id,
            SUM(wr.quantity_wasted) AS wasted_qty
        FROM wastage_records wr
        INNER JOIN allocations al
            ON al.allocation_id = wr.allocation_id
        WHERE wr.approval_status = 'approved'
          AND wr.recorded_date BETWEEN ? AND ?
          AND (? = 0 OR al.project_id = ?)
        GROUP BY al.material_id
    ) w ON w.material_id = m.material_id

    LEFT JOIN (
        SELECT
            material_id,
            SUM(quantity) AS received_all_time
        FROM material_receipts
        WHERE (? = 0 OR project_id = ?)
        GROUP BY material_id
    ) ar ON ar.material_id = m.material_id

    LEFT JOIN (
        SELECT
            material_id,
            SUM(quantity) AS allocated_all_time
        FROM allocations
        WHERE (? = 0 OR project_id = ?)
        GROUP BY material_id
    ) aa ON aa.material_id = m.material_id

    ORDER BY m.material_name ASC
";

$stmt = $pdo->prepare($sql);

$stmt->execute([
    $startDate, $endDate, $projectId, $projectId,
    $startDate, $endDate, $projectId, $projectId,
    $startDate, $endDate, $projectId, $projectId,
    $startDate, $endDate, $projectId, $projectId,
    $projectId, $projectId,
    $projectId, $projectId
]);

$materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================================
   5. CALCULATE TOTAL MATERIAL COST
========================================= */

$totalCost = 0;

foreach ($materials as $material) {
    $totalCost += (float)$material['material_cost'];
}

/* =========================================
   6. PREPARE CSV DOWNLOAD
========================================= */

$filename = 'construction_material_report_' .
    $startDate . '_to_' . $endDate . '.csv';

header('Content-Type: text/csv; charset=UTF-8');

header(
    'Content-Disposition: attachment; filename="' .
    $filename . '"'
);

header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

/* UTF-8 BOM for Excel */

fwrite($output, "\xEF\xBB\xBF");

/* =========================================
   7. CSV REPORT INFORMATION
========================================= */

fputcsv($output, [
    'Construction Site Material Allocation and Wastage Tracking System'
]);

fputcsv($output, [
    'Project',
    csvSafe($projectName)
]);

fputcsv($output, [
    'Start Date',
    $startDate
]);

fputcsv($output, [
    'End Date',
    $endDate
]);

fputcsv($output, [
    'Generated At',
    date('Y-m-d H:i:s')
]);

fputcsv($output, []);

/* =========================================
   8. CSV COLUMN HEADINGS
========================================= */

fputcsv($output, [
    'Material',
    'Unit',
    'Received During Period',
    'Material Cost (ETB)',
    'Allocated During Period',
    'Used During Period',
    'Approved Wastage During Period',
    'Current Store Stock',
    'Minimum Stock',
    'Stock Status'
]);

/* =========================================
   9. WRITE MATERIAL DATA
========================================= */

foreach ($materials as $material) {

    $storeBalance = (float)$material['store_balance'];
    $minimumStock = (float)$material['minimum_stock'];
    $materialCost = (float)$material['material_cost'];

    $stockStatus = $storeBalance < $minimumStock
        ? 'LOW STOCK'
        : 'OK';

    fputcsv($output, [
        csvSafe($material['material_name']),
        csvSafe($material['unit']),
        (float)$material['received_qty'],
        number_format($materialCost, 2, '.', ''),
        (float)$material['allocated_qty'],
        (float)$material['used_qty'],
        (float)$material['wasted_qty'],
        $storeBalance,
        $minimumStock,
        $stockStatus
    ]);
}

/* =========================================
   10. TOTAL MATERIAL COST
========================================= */

fputcsv($output, []);

fputcsv($output, [
    'TOTAL MATERIAL COST',
    '',
    '',
    number_format($totalCost, 2, '.', ''),
    '',
    '',
    '',
    '',
    '',
    ''
]);

fclose($output);
exit;
