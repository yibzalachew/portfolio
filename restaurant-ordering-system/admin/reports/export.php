<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/admin_helpers.php';
require_once __DIR__ . '/../../vendor/autoload.php';

requireAdmin();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

// --------------------------------------------------
// 1. Validate report dates
// --------------------------------------------------

$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

function validReportDate($date)
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);

    return $parsed && $parsed->format('Y-m-d') === $date;
}

if (!validReportDate($startDate)) {
    $startDate = date('Y-m-01');
}

if (!validReportDate($endDate)) {
    $endDate = date('Y-m-d');
}

if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$startDateTime = $startDate . ' 00:00:00';
$endDateTime = date('Y-m-d', strtotime($endDate . ' +1 day')) . ' 00:00:00';

// --------------------------------------------------
// 2. Retrieve order summary
// --------------------------------------------------

$orderStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_orders,

        COALESCE(SUM(
            CASE WHEN order_status = 'pending'
            THEN 1 ELSE 0 END
        ), 0) AS pending_orders,

        COALESCE(SUM(
            CASE WHEN order_status = 'confirmed'
            THEN 1 ELSE 0 END
        ), 0) AS confirmed_orders,

        COALESCE(SUM(
            CASE WHEN order_status = 'preparing'
            THEN 1 ELSE 0 END
        ), 0) AS preparing_orders,

        COALESCE(SUM(
            CASE WHEN order_status IN ('delivered', 'picked_up')
            THEN 1 ELSE 0 END
        ), 0) AS completed_orders,

        COALESCE(SUM(
            CASE WHEN order_status IN ('cancelled', 'rejected')
            THEN 1 ELSE 0 END
        ), 0) AS cancelled_orders

    FROM orders

    WHERE created_at >= ?
      AND created_at < ?
");

$orderStmt->execute([$startDateTime, $endDateTime]);

$orderSummary = $orderStmt->fetch();

// --------------------------------------------------
// 3. Retrieve verified revenue
// --------------------------------------------------

$revenueStmt = $pdo->prepare("
    SELECT COALESCE(SUM(p.amount), 0)

    FROM payments p

    INNER JOIN orders o
        ON p.order_id = o.order_id

    WHERE p.payment_status = 'verified'
      AND o.created_at >= ?
      AND o.created_at < ?
");

$revenueStmt->execute([$startDateTime, $endDateTime]);

$totalRevenue = (float) $revenueStmt->fetchColumn();

$totalOrders = (int) $orderSummary['total_orders'];

$averageOrderValue = $totalOrders > 0
    ? $totalRevenue / $totalOrders
    : 0;

// --------------------------------------------------
// 4. Retrieve payment report
// --------------------------------------------------

$paymentStmt = $pdo->prepare("
    SELECT
        p.payment_status,
        COUNT(*) AS payment_count,
        COALESCE(SUM(p.amount), 0) AS payment_total

    FROM payments p

    INNER JOIN orders o
        ON p.order_id = o.order_id

    WHERE o.created_at >= ?
      AND o.created_at < ?

    GROUP BY p.payment_status

    ORDER BY p.payment_status
");

$paymentStmt->execute([$startDateTime, $endDateTime]);

$paymentRows = $paymentStmt->fetchAll();

$paymentSummary = [
    'verified' => ['count' => 0, 'amount' => 0],
    'submitted' => ['count' => 0, 'amount' => 0],
    'pending' => ['count' => 0, 'amount' => 0],
    'rejected' => ['count' => 0, 'amount' => 0],
    'refunded' => ['count' => 0, 'amount' => 0]
];

foreach ($paymentRows as $row) {
    $status = $row['payment_status'];

    if (isset($paymentSummary[$status])) {
        $paymentSummary[$status] = [
            'count' => (int) $row['payment_count'],
            'amount' => (float) $row['payment_total']
        ];
    }
}

// --------------------------------------------------
// 5. Retrieve popular food items
// --------------------------------------------------

$popularStmt = $pdo->prepare("
    SELECT
        oi.food_name,
        SUM(oi.quantity) AS total_quantity,
        SUM(oi.line_total) AS total_sales

    FROM order_items oi

    INNER JOIN orders o
        ON oi.order_id = o.order_id

    WHERE o.created_at >= ?
      AND o.created_at < ?

      AND o.order_status NOT IN ('cancelled', 'rejected')

    GROUP BY oi.food_name

    ORDER BY total_quantity DESC, total_sales DESC

    LIMIT 10
");

$popularStmt->execute([$startDateTime, $endDateTime]);

$popularFoods = $popularStmt->fetchAll();

// --------------------------------------------------
// 6. Retrieve daily performance
// --------------------------------------------------

$dailyStmt = $pdo->prepare("
    SELECT
        DATE(o.created_at) AS report_date,

        COUNT(DISTINCT o.order_id) AS total_orders,

        COALESCE(SUM(
            CASE
                WHEN p.payment_status = 'verified'
                THEN p.amount
                ELSE 0
            END
        ), 0) AS daily_revenue

    FROM orders o

    LEFT JOIN payments p
        ON p.order_id = o.order_id

    WHERE o.created_at >= ?
      AND o.created_at < ?

    GROUP BY DATE(o.created_at)

    ORDER BY report_date ASC
");

$dailyStmt->execute([$startDateTime, $endDateTime]);

$dailyReports = $dailyStmt->fetchAll();

// --------------------------------------------------
// 7. Retrieve individual order details
// --------------------------------------------------

$detailsStmt = $pdo->prepare("
    SELECT
        o.order_number,
        c.full_name AS customer_name,
        c.phone,
        o.order_type,
        o.order_status,
        o.subtotal,
        o.delivery_fee,
        o.total_amount,
        o.created_at,

        COALESCE(pay.verified_amount, 0) AS verified_amount,

        COALESCE(pay.payment_statuses, 'No payment') AS payment_statuses,

        COALESCE(pay.payment_methods, 'Not selected') AS payment_methods

    FROM orders o

    INNER JOIN customers c
        ON o.customer_id = c.customer_id

    LEFT JOIN (
        SELECT
            p.order_id,

            SUM(
                CASE
                    WHEN p.payment_status = 'verified'
                    THEN p.amount
                    ELSE 0
                END
            ) AS verified_amount,

            GROUP_CONCAT(
                DISTINCT p.payment_status
                ORDER BY p.payment_status
                SEPARATOR ', '
            ) AS payment_statuses,

            GROUP_CONCAT(
                DISTINCT pm.method_name
                ORDER BY pm.method_name
                SEPARATOR ', '
            ) AS payment_methods

        FROM payments p

        LEFT JOIN payment_methods pm
            ON p.payment_method_id = pm.payment_method_id

        GROUP BY p.order_id

    ) pay ON pay.order_id = o.order_id

    WHERE o.created_at >= ?
      AND o.created_at < ?

    ORDER BY o.created_at DESC
");

$detailsStmt->execute([$startDateTime, $endDateTime]);

$orderDetails = $detailsStmt->fetchAll();

// --------------------------------------------------
// 8. Create Excel workbook
// --------------------------------------------------

$spreadsheet = new Spreadsheet();

$spreadsheet->getProperties()
    ->setCreator('Zest Restaurant')
    ->setTitle('Zest Restaurant Business Reports')
    ->setSubject('Sales and Business Analytics')
    ->setDescription(
        'Business report from ' . $startDate . ' to ' . $endDate
    );

$spreadsheet->getDefaultStyle()
    ->getFont()
    ->setName('Arial')
    ->setSize(10);

// --------------------------------------------------
// 9. Reusable worksheet formatting
// --------------------------------------------------

function prepareSheet($sheet, $title, $startDate, $endDate, $lastColumn)
{
    $sheet->setCellValue('A1', 'ZEST RESTAURANT');
    $sheet->setCellValue('A2', $title);
    $sheet->setCellValue(
        'A3',
        'Report Period: ' . $startDate . ' to ' . $endDate
    );

    $sheet->mergeCells('A1:' . $lastColumn . '1');
    $sheet->mergeCells('A2:' . $lastColumn . '2');
    $sheet->mergeCells('A3:' . $lastColumn . '3');

    $sheet->getStyle('A1')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 18,
            'color' => ['rgb' => 'FFFFFF']
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => '6B3F24']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER
        ]
    ]);

    $sheet->getStyle('A2')->applyFromArray([
        'font' => [
            'bold' => true,
            'size' => 14,
            'color' => ['rgb' => '6B3F24']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER
        ]
    ]);

    $sheet->getStyle('A3')->applyFromArray([
        'font' => [
            'italic' => true,
            'color' => ['rgb' => '666666']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER
        ]
    ]);

    $sheet->getRowDimension(1)->setRowHeight(30);
    $sheet->getRowDimension(2)->setRowHeight(25);
    $sheet->getRowDimension(3)->setRowHeight(22);
}

function styleTableHeader($sheet, $range)
{
    $sheet->getStyle($range)->applyFromArray([
        'font' => [
            'bold' => true,
            'color' => ['rgb' => 'FFFFFF']
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'A65E2E']
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'borders' => [
            'bottom' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '6B3F24']
            ]
        ]
    ]);

    $sheet->getRowDimension(5)->setRowHeight(25);
}

function writeText($sheet, $cell, $value)
{
    $sheet->setCellValueExplicit(
        $cell,
        (string) ($value ?? ''),
        DataType::TYPE_STRING
    );
}

function finishSheet($sheet, $lastColumn, $lastRow)
{
    $sheet->freezePane('A6');

    if ($lastRow >= 5) {
        $sheet->setAutoFilter('A5:' . $lastColumn . $lastRow);
    }

    $sheet->getStyle(
        'A5:' . $lastColumn . max(5, $lastRow)
    )->getBorders()->getAllBorders()->setBorderStyle(
        Border::BORDER_THIN
    );

    $sheet->getPageSetup()
        ->setOrientation(
            \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE
        );

    $sheet->getPageSetup()
        ->setFitToWidth(1)
        ->setFitToHeight(0);

    $sheet->getPageSetup()->setFitToPage(true);
}

// --------------------------------------------------
// SHEET 1: Sales Summary
// --------------------------------------------------

$sheet = $spreadsheet->getActiveSheet();

$sheet->setTitle('Sales Summary');

prepareSheet(
    $sheet,
    'Sales Summary',
    $startDate,
    $endDate,
    'B'
);

$sheet->setCellValue('A5', 'Metric');
$sheet->setCellValue('B5', 'Value');

styleTableHeader($sheet, 'A5:B5');

$summaryData = [
    ['Total Orders', $totalOrders],
    ['Verified Revenue (ETB)', $totalRevenue],
    ['Average Revenue per Order (ETB)', $averageOrderValue],
    ['Completed Orders', (int) $orderSummary['completed_orders']]
];

$rowNumber = 6;

foreach ($summaryData as $item) {
    writeText($sheet, 'A' . $rowNumber, $item[0]);

    $sheet->setCellValue('B' . $rowNumber, $item[1]);

    $rowNumber++;
}

$sheet->getStyle('B7:B8')
    ->getNumberFormat()
    ->setFormatCode('"ETB" #,##0.00');

$sheet->getColumnDimension('A')->setWidth(38);
$sheet->getColumnDimension('B')->setWidth(25);

finishSheet($sheet, 'B', $rowNumber - 1);

// --------------------------------------------------
// SHEET 2: Order Statistics
// --------------------------------------------------

$sheet = $spreadsheet->createSheet();

$sheet->setTitle('Order Statistics');

prepareSheet(
    $sheet,
    'Order Statistics',
    $startDate,
    $endDate,
    'B'
);

$sheet->setCellValue('A5', 'Order Status');
$sheet->setCellValue('B5', 'Number of Orders');

styleTableHeader($sheet, 'A5:B5');

$statusData = [
    ['Pending', (int) $orderSummary['pending_orders']],
    ['Confirmed', (int) $orderSummary['confirmed_orders']],
    ['Preparing', (int) $orderSummary['preparing_orders']],
    ['Completed', (int) $orderSummary['completed_orders']],
    ['Cancelled / Rejected', (int) $orderSummary['cancelled_orders']],
    ['Total Orders', $totalOrders]
];

$rowNumber = 6;

foreach ($statusData as $item) {
    writeText($sheet, 'A' . $rowNumber, $item[0]);

    $sheet->setCellValue('B' . $rowNumber, $item[1]);

    $rowNumber++;
}

$sheet->getColumnDimension('A')->setWidth(30);
$sheet->getColumnDimension('B')->setWidth(25);

finishSheet($sheet, 'B', $rowNumber - 1);

// --------------------------------------------------
// SHEET 3: Payment Report
// --------------------------------------------------

$sheet = $spreadsheet->createSheet();

$sheet->setTitle('Payment Report');

prepareSheet(
    $sheet,
    'Payment Report',
    $startDate,
    $endDate,
    'C'
);

$sheet->fromArray(
    ['Payment Status', 'Number of Payments', 'Total Amount (ETB)'],
    null,
    'A5'
);

styleTableHeader($sheet, 'A5:C5');

$rowNumber = 6;

foreach ($paymentSummary as $status => $data) {
    writeText($sheet, 'A' . $rowNumber, ucfirst($status));

    $sheet->setCellValue('B' . $rowNumber, $data['count']);

    $sheet->setCellValue('C' . $rowNumber, $data['amount']);

    $rowNumber++;
}

$sheet->getStyle('C6:C' . ($rowNumber - 1))
    ->getNumberFormat()
    ->setFormatCode('"ETB" #,##0.00');

$sheet->getColumnDimension('A')->setWidth(22);
$sheet->getColumnDimension('B')->setWidth(25);
$sheet->getColumnDimension('C')->setWidth(25);

finishSheet($sheet, 'C', $rowNumber - 1);

// --------------------------------------------------
// SHEET 4: Popular Foods
// --------------------------------------------------

$sheet = $spreadsheet->createSheet();

$sheet->setTitle('Popular Foods');

prepareSheet(
    $sheet,
    'Popular Food Items',
    $startDate,
    $endDate,
    'C'
);

$sheet->fromArray(
    ['Food Name', 'Quantity Sold', 'Total Sales (ETB)'],
    null,
    'A5'
);

styleTableHeader($sheet, 'A5:C5');

$rowNumber = 6;

foreach ($popularFoods as $food) {
    writeText($sheet, 'A' . $rowNumber, $food['food_name']);

    $sheet->setCellValue(
        'B' . $rowNumber,
        (float) $food['total_quantity']
    );

    $sheet->setCellValue(
        'C' . $rowNumber,
        (float) $food['total_sales']
    );

    $rowNumber++;
}

if (!$popularFoods) {
    writeText($sheet, 'A6', 'No food sales found for this period.');
}

if ($rowNumber > 6) {
    $sheet->getStyle('C6:C' . ($rowNumber - 1))
        ->getNumberFormat()
        ->setFormatCode('"ETB" #,##0.00');
}

$sheet->getColumnDimension('A')->setWidth(35);
$sheet->getColumnDimension('B')->setWidth(20);
$sheet->getColumnDimension('C')->setWidth(25);

finishSheet($sheet, 'C', max(6, $rowNumber - 1));

// --------------------------------------------------
// SHEET 5: Daily Performance
// --------------------------------------------------

$sheet = $spreadsheet->createSheet();

$sheet->setTitle('Daily Performance');

prepareSheet(
    $sheet,
    'Daily Performance',
    $startDate,
    $endDate,
    'C'
);

$sheet->fromArray(
    ['Date', 'Total Orders', 'Verified Revenue (ETB)'],
    null,
    'A5'
);

styleTableHeader($sheet, 'A5:C5');

$rowNumber = 6;

foreach ($dailyReports as $day) {
    $sheet->setCellValue(
        'A' . $rowNumber,
        $day['report_date']
    );

    $sheet->setCellValue(
        'B' . $rowNumber,
        (int) $day['total_orders']
    );

    $sheet->setCellValue(
        'C' . $rowNumber,
        (float) $day['daily_revenue']
    );

    $rowNumber++;
}

if (!$dailyReports) {
    writeText($sheet, 'A6', 'No orders found for this period.');
}

if ($rowNumber > 6) {
    $sheet->getStyle('C6:C' . ($rowNumber - 1))
        ->getNumberFormat()
        ->setFormatCode('"ETB" #,##0.00');
}

$sheet->getColumnDimension('A')->setWidth(20);
$sheet->getColumnDimension('B')->setWidth(20);
$sheet->getColumnDimension('C')->setWidth(30);

finishSheet($sheet, 'C', max(6, $rowNumber - 1));

// --------------------------------------------------
// SHEET 6: Order Details
// --------------------------------------------------

$sheet = $spreadsheet->createSheet();

$sheet->setTitle('Order Details');

prepareSheet(
    $sheet,
    'Individual Order Details',
    $startDate,
    $endDate,
    'M'
);

$headers = [
    'Order Number',
    'Customer Name',
    'Phone',
    'Order Type',
    'Order Status',
    'Subtotal (ETB)',
    'Delivery Fee (ETB)',
    'Total Amount (ETB)',
    'Verified Amount (ETB)',
    'Payment Status',
    'Payment Method',
    'Order Date',
    'Order Time'
];

$sheet->fromArray($headers, null, 'A5');

styleTableHeader($sheet, 'A5:M5');

$rowNumber = 6;

foreach ($orderDetails as $order) {

    writeText($sheet, 'A' . $rowNumber, $order['order_number']);
    writeText($sheet, 'B' . $rowNumber, $order['customer_name']);
    writeText($sheet, 'C' . $rowNumber, $order['phone']);
    writeText($sheet, 'D' . $rowNumber, ucfirst($order['order_type']));
    writeText($sheet, 'E' . $rowNumber, ucfirst($order['order_status']));

    $sheet->setCellValue('F' . $rowNumber, (float) $order['subtotal']);
    $sheet->setCellValue('G' . $rowNumber, (float) $order['delivery_fee']);
    $sheet->setCellValue('H' . $rowNumber, (float) $order['total_amount']);
    $sheet->setCellValue('I' . $rowNumber, (float) $order['verified_amount']);

    writeText($sheet, 'J' . $rowNumber, $order['payment_statuses']);
    writeText($sheet, 'K' . $rowNumber, $order['payment_methods']);

    $orderDate = strtotime($order['created_at']);

    $sheet->setCellValue(
        'L' . $rowNumber,
        date('Y-m-d', $orderDate)
    );

    $sheet->setCellValue(
        'M' . $rowNumber,
        date('H:i:s', $orderDate)
    );

    $rowNumber++;
}

if (!$orderDetails) {
    writeText($sheet, 'A6', 'No orders found for this period.');
}

if ($rowNumber > 6) {
    $sheet->getStyle('F6:I' . ($rowNumber - 1))
        ->getNumberFormat()
        ->setFormatCode('"ETB" #,##0.00');
}

$columnWidths = [
    'A' => 25,
    'B' => 25,
    'C' => 18,
    'D' => 15,
    'E' => 18,
    'F' => 18,
    'G' => 20,
    'H' => 20,
    'I' => 20,
    'J' => 25,
    'K' => 25,
    'L' => 16,
    'M' => 15
];

foreach ($columnWidths as $column => $width) {
    $sheet->getColumnDimension($column)->setWidth($width);
}

finishSheet($sheet, 'M', max(6, $rowNumber - 1));

// --------------------------------------------------
// 10. Final workbook settings
// --------------------------------------------------

$spreadsheet->setActiveSheetIndex(0);

$filename = 'Zest_Restaurant_Report_' .
    $startDate . '_to_' . $endDate . '.xlsx';

// Prevent accidental output before the Excel download.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

header(
    'Content-Disposition: attachment; filename="' . $filename . '"'
);

header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);

$writer->save('php://output');

$spreadsheet->disconnectWorksheets();

exit;
