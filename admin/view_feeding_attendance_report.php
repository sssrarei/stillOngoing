<?php
include '../includes/auth.php';
include '../config/database.php';

if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 1) {
    header("Location: ../login.php");
    exit();
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function safe_value($value, $fallback = 'N/A')
{
    return (isset($value) && trim((string)$value) !== '') ? $value : $fallback;
}

function format_date_value($date, $fallback = 'N/A')
{
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return $fallback;
    }

    $timestamp = strtotime($date);
    if (!$timestamp) {
        return $fallback;
    }

    return date('F d, Y', $timestamp);
}

function format_datetime_value($date, $fallback = 'N/A')
{
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return $fallback;
    }

    $timestamp = strtotime($date);
    if (!$timestamp) {
        return $fallback;
    }

    return date('F d, Y h:i A', $timestamp);
}

function get_first_existing_value($row, $keys, $fallback = 'N/A')
{
    if (!is_array($row)) {
        return $fallback;
    }

    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return $row[$key];
        }
    }

    return $fallback;
}

function build_child_name_from_row($row)
{
    if (!is_array($row)) {
        return 'N/A';
    }

    $full_name = get_first_existing_value($row, [
        'child_name',
        'full_name',
        'name',
        'beneficiary_name'
    ], '');

    if ($full_name !== '') {
        return $full_name;
    }

    $parts = [];

    $first = get_first_existing_value($row, ['first_name', 'child_first_name'], '');
    $middle = get_first_existing_value($row, ['middle_name', 'child_middle_name'], '');
    $last = get_first_existing_value($row, ['last_name', 'child_last_name'], '');

    if ($first !== '') $parts[] = trim($first);
    if ($middle !== '') $parts[] = trim($middle);
    if ($last !== '') $parts[] = trim($last);

    $built = trim(implode(' ', $parts));
    return $built !== '' ? $built : 'N/A';
}

function extract_report_rows($payload)
{
    if (!is_array($payload)) {
        return [];
    }

    $candidate_keys = [
        'submitted_rows',
        'rows',
        'report_rows',
        'records',
        'feeding_rows',
        'feeding_attendance_rows',
        'feeding_data',
        'report_data',
        'data',
        'entries',
        'items'
    ];

    foreach ($candidate_keys as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return array_values($payload[$key]);
        }
    }

    $is_list = array_keys($payload) === range(0, count($payload) - 1);
    if ($is_list) {
        return $payload;
    }

    return [];
}

function attendance_badge_class($attendance)
{
    $attendance = strtolower(trim((string)$attendance));

    if ($attendance === 'present') {
        return 'badge-present';
    }

    if ($attendance === 'absent') {
        return 'badge-absent';
    }

    return 'badge-default';
}

function build_food_details($row)
{
    if (!is_array($row)) {
        return 'N/A';
    }

    if (isset($row['food_details']) && is_array($row['food_details'])) {
        $parts = [];
        foreach ($row['food_details'] as $item) {
            if (is_array($item)) {
                $group = get_first_existing_value($item, ['food_group_name', 'food_group', 'group_name'], '');
                $name = get_first_existing_value($item, ['food_item_name', 'food_item', 'item_name', 'food_name'], '');
                $amount = get_first_existing_value($item, ['amount', 'measurement', 'measurement_text', 'serving'], '');

                $text = '';
                if ($group !== '') {
                    $text .= $group . ': ';
                }
                $text .= $name !== '' ? $name : 'N/A';

                if ($amount !== '') {
                    $text .= ' (' . $amount . ')';
                }

                $parts[] = $text;
            } elseif (trim((string)$item) !== '') {
                $parts[] = trim((string)$item);
            }
        }

        return !empty($parts) ? implode('||', $parts) : 'N/A';
    }

    $foodDetails = get_first_existing_value($row, [
        'food_details',
        'food_items',
        'food_item',
        'food_name',
        'meal_details',
        'details'
    ], '');

    if ($foodDetails !== '') {
        return $foodDetails;
    }

    $foodGroup = get_first_existing_value($row, [
        'food_group_name',
        'food_group',
        'group_name'
    ], '');

    $foodItem = get_first_existing_value($row, [
        'food_item_name',
        'food_item',
        'item_name',
        'food_name'
    ], '');

    $amount = get_first_existing_value($row, [
        'amount',
        'measurement',
        'measurement_text',
        'serving'
    ], '');

    $text = '';
    if ($foodGroup !== '') {
        $text .= $foodGroup . ': ';
    }
    if ($foodItem !== '') {
        $text .= $foodItem;
    }
    if ($amount !== '') {
        $text .= ' (' . $amount . ')';
    }

    return trim($text) !== '' ? $text : 'N/A';
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die('No submitted report selected.');
}

$submitted_report_id = (int) $_GET['id'];

$report_sql = "
    SELECT
        sr.submitted_report_id,
        sr.report_type,
        sr.cdc_id,
        sr.submitted_by,
        sr.date_from,
        sr.date_to,
        sr.submitted_at,
        sr.status,
        sr.report_payload,
        c.cdc_name,
        c.barangay,
        c.address AS cdc_address,
        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN u.first_name IS NOT NULL AND u.first_name != '' AND u.last_name IS NOT NULL AND u.last_name != '' THEN ' '
                ELSE ''
            END,
            COALESCE(u.last_name, '')
        ) AS submitted_by_name
    FROM submitted_reports sr
    INNER JOIN cdc c ON sr.cdc_id = c.cdc_id
    INNER JOIN users u ON sr.submitted_by = u.user_id
    WHERE sr.submitted_report_id = ?
    LIMIT 1
";

$stmt_report = mysqli_prepare($conn, $report_sql);
if (!$stmt_report) {
    die('Failed to prepare submitted report query.');
}

mysqli_stmt_bind_param($stmt_report, "i", $submitted_report_id);
mysqli_stmt_execute($stmt_report);
$result_report = mysqli_stmt_get_result($stmt_report);

if (!$result_report || mysqli_num_rows($result_report) === 0) {
    die('Submitted report not found.');
}

$report = mysqli_fetch_assoc($result_report);
mysqli_stmt_close($stmt_report);

if ($report['report_type'] !== 'feeding_attendance') {
    die('The selected submitted report is not a Feeding Attendance Report.');
}

$payload = json_decode($report['report_payload'], true);
if (!is_array($payload)) {
    $payload = [];
}

$rows = extract_report_rows($payload);

$prepared_by = get_first_existing_value($payload, [
    'prepared_by',
    'preparedBy',
    'submitted_by_name',
    'teacher_name',
    'cdw_name'
], $report['submitted_by_name']);

$coverage_text = 'All Dates';
if (!empty($report['date_from']) && !empty($report['date_to'])) {
    $coverage_text = format_date_value($report['date_from']) . ' - ' . format_date_value($report['date_to']);
} elseif (!empty($report['date_from'])) {
    $coverage_text = 'From ' . format_date_value($report['date_from']);
} elseif (!empty($report['date_to'])) {
    $coverage_text = 'Up to ' . format_date_value($report['date_to']);
}

$status = strtolower(trim((string)$report['status']));
$status_class = 'status-default';

if ($status === 'submitted') {
    $status_class = 'status-submitted';
} elseif ($status === 'reviewed') {
    $status_class = 'status-reviewed';
} elseif ($status === 'approved') {
    $status_class = 'status-approved';
} elseif ($status === 'returned') {
    $status_class = 'status-returned';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feeding Attendance Report | NutriTrack</title>
    <link rel="stylesheet" href="../assets/admin/admin-style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">

    <style>
        .report-view-wrapper {
            padding: 24px;
        }

        .report-header-card,
        .summary-card,
        .table-card {
            background: #ffffff;
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
            margin-bottom: 24px;
        }

        .report-header-card {
            padding: 24px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: #163b68;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 14px;
        }

        .report-header h1 {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            font-size: 28px;
            font-weight: 700;
            color: #163b68;
        }

        .report-header p {
            margin: 8px 0 0;
            font-size: 14px;
            color: #64748b;
        }

        .summary-card {
            padding: 22px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }

        .summary-item {
            background: #ffffff;
            border: 1px solid #cccccc;
            border-radius: 10px;
            padding: 14px;
        }

        .summary-label {
            font-size: 12px;
            font-weight: 700;
            color: #000000;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 8px;
        }

        .summary-value {
                font-size: 15px;
                font-weight: 700;
                color: #000000;
                line-height: 1.5;
            }

        .table-header {
            padding: 20px 22px;
            border-bottom: 1px solid #eef2f7;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .table-header h2 {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            font-weight: 700;
            color: #163b68;
        }

        .table-header p {
            margin: 6px 0 0;
            font-size: 13px;
            color: #64748b;
        }

        .record-count,
        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .record-count {
            background: #edf4ff;
            color: #163b68;
        }

        .status-badge {
            text-transform: capitalize;
        }

        .status-submitted {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-reviewed {
            background: #dcfce7;
            color: #166534;
        }

        .status-approved {
            background: #ede9fe;
            color: #6d28d9;
        }

        .status-returned {
            background: #fee2e2;
            color: #b91c1c;
        }

        .status-default {
            background: #e5e7eb;
            color: #374151;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            min-width: 1000px;
            border-collapse: collapse;
        }

        .report-table thead th {
            background: #f8fafc;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            text-align: left;
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        .report-table tbody td {
            padding: 14px 16px;
            font-size: 14px;
            color: #1e293b;
            border-bottom: 1px solid #eef2f7;
            vertical-align: top;
        }

        .report-table tbody tr:hover {
            background: #fafcff;
        }

        .attendance-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
            text-transform: capitalize;
        }

        .badge-present {
            background: #dcfce7;
            color: #166534;
        }

        .badge-absent {
            background: #fee2e2;
            color: #b91c1c;
        }

        .badge-default {
            background: #e5e7eb;
            color: #374151;
        }

        .food-details-list {
            margin: 0;
            padding-left: 18px;
        }

        .food-details-list li {
            margin-bottom: 4px;
            line-height: 1.5;
        }

        .food-details-list li:last-child {
            margin-bottom: 0;
        }

        .empty-state {
            padding: 56px 24px;
            text-align: center;
            color: #64748b;
            font-size: 14px;
        }

        .empty-state h3 {
            margin: 0 0 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            color: #163b68;
        }

        .payload-preview {
            padding: 20px 22px 24px;
            border-top: 1px solid #eef2f7;
        }

        .payload-preview pre {
            margin: 0;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            overflow: auto;
            font-size: 12px;
            color: #334155;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
        }

        @media (max-width: 1200px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(180px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .report-view-wrapper {
                padding: 16px;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }
        }

        .report-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}

.print-btn {
    background: #163b68;
    color: #ffffff;
    border: none;
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Inter', sans-serif;
}

.print-btn:hover {
    background: #102f54;
}

@media print {
    @page {
        size: landscape;
        margin: 12mm;
    }

    body {
        background: #ffffff !important;
        color: #000000 !important;
    }

    .no-print,
    .sidebar,
    #sidebar,
    .sidebar-overlay,
    #sidebarOverlay,
    .topbar,
    .admin-topbar,
    header,
    nav,
    button,
    .back-link {
        display: none !important;
    }

    .main-content,
    #mainContent {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    .report-view-wrapper {
        padding: 0 !important;
    }

    .report-header-card,
    .summary-card,
    .table-card {
        box-shadow: none !important;
        border-radius: 0 !important;
        margin-bottom: 14px !important;
        border: none !important;
    }

    .report-header-card {
        padding: 0 0 10px 0 !important;
    }

    .report-header {
        text-align: center !important;
    }

    .report-header h1,
    .report-header p {
        color: #000000 !important;
        text-align: center !important;
    }

    .summary-card {
        padding: 10px 0 !important;
    }

    .summary-grid {
        grid-template-columns: repeat(4, 1fr) !important;
        gap: 8px !important;
    }

    .summary-item {
        padding: 8px !important;
        border: 1px solid #cccccc !important;
        background: #ffffff !important;
        min-height: 48px !important;
    }

    .summary-label,
    .summary-value,
    .table-header h2,
    .table-header p,
    .record-count {
        color: #000000 !important;
    }

    .table-header {
        padding: 8px 0 !important;
        border-bottom: 1px solid #cccccc !important;
    }

    .table-wrap {
        overflow: visible !important;
    }

    .report-table {
        width: 100% !important;
        min-width: 0 !important;
        font-size: 10px !important;
    }

    .report-table thead th,
    .report-table tbody td {
        padding: 6px 5px !important;
        font-size: 9px !important;
        color: #000000 !important;
        border: 1px solid #cccccc !important;
        white-space: normal !important;
    }

    .report-table thead th {
        background: #f2f2f2 !important;
    }

    .report-table tbody tr:hover {
        background: transparent !important;
    }

    .attendance-badge {
        background: transparent !important;
        color: #000000 !important;
        padding: 0 !important;
        border-radius: 0 !important;
    }
}
    </style>
</head>
<body>

<?php include '../includes/admin_topbar.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="report-view-wrapper">

        <div class="report-header-card">
    <div class="report-actions no-print">
        <a href="monitoring_reports.php" class="back-link">← Back to Monitoring Reports</a>

        <button type="button" class="print-btn" onclick="window.print()">
            Print / Save as PDF
        </button>
    </div>

    <div class="report-header">
        <h1>Feeding Attendance Report</h1>
    </div>
</div>

        <div class="summary-card">
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-label">CDC</div>
                    <div class="summary-value"><?php echo h(safe_value($report['cdc_name'])); ?></div>
                </div>


                

                <div class="summary-item">
                    <div class="summary-label">Prepared By</div>
                    <div class="summary-value"><?php echo h(safe_value($prepared_by)); ?></div>
                </div>

                <div class="summary-item">
                    <div class="summary-label">Submitted At</div>
                    <div class="summary-value"><?php echo h(format_datetime_value($report['submitted_at'])); ?></div>
                </div>

                

                <div class="summary-item">
                    <div class="summary-label">Barangay</div>
                    <div class="summary-value"><?php echo h(safe_value($report['barangay'])); ?></div>
                </div>

                
        </div>

        <div class="table-card">
            <div class="table-header">
                <div>
                    <h2>Feeding Attendance Table</h2>
                    <p>Submitted feeding attendance rows saved in this report snapshot.</p>
                </div>
                <span class="record-count"><?php echo count($rows); ?> record(s)</span>
            </div>

            <?php if (!empty($rows)): ?>
                <div class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Feeding Date</th>
                                <th>Child Name</th>
                                <th>Attendance</th>
                                <th>Food Details</th>
                                
                                <th>Remarks</th>
                                
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $index => $row): ?>
                                <?php
                                    $feeding_date = get_first_existing_value($row, [
                                        'feeding_date',
                                        'date',
                                        'record_date'
                                    ], 'N/A');

                                    $child_name = build_child_name_from_row($row);

                                    $attendance = get_first_existing_value($row, [
                                        'attendance',
                                        'status'
                                    ], 'N/A');

                                    $food_details = build_food_details($row);

                                    $amount = get_first_existing_value($row, [
                                        'amount',
                                        'measurement',
                                        'measurement_text',
                                        'serving'
                                    ], '');

                                    $remarks = get_first_existing_value($row, [
                                        'remarks',
                                        'remark'
                                    ], 'N/A');

                                    $cdc_name = get_first_existing_value($row, [
                                        'cdc_name',
                                        'center_name'
                                    ], $report['cdc_name']);

                                    $food_parts = [];
                                    if ($food_details !== 'N/A') {
                                        if (strpos($food_details, '||') !== false) {
                                            $food_parts = array_filter(array_map('trim', explode('||', $food_details)));
                                        } else {
                                            $food_parts = [$food_details];
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><?php echo (int)($index + 1); ?></td>
                                    <td><?php echo h($feeding_date !== 'N/A' ? format_date_value($feeding_date, 'N/A') : 'N/A'); ?></td>
                                    <td><?php echo h($child_name); ?></td>
                                    <td>
                                        <span class="attendance-badge <?php echo h(attendance_badge_class($attendance)); ?>">
                                            <?php echo h($attendance); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (strtolower(trim((string)$attendance)) === 'absent'): ?>
                                            Absent
                                        <?php elseif (!empty($food_parts)): ?>
                                            <ul class="food-details-list">
                                                <?php foreach ($food_parts as $food): ?>
                                                    <li><?php echo h($food); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                        <td><?php echo h($remarks); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No feeding attendance rows found</h3>
                    <p>This submitted report does not contain readable feeding attendance row data in the payload.</p>
                </div>

                <?php if (!empty($payload)): ?>
                    <div class="payload-preview">
                        <pre><?php echo h(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');
const mainContent = document.getElementById('mainContent');

function handleDesktopToggle() {
    if (!sidebar || !mainContent) return;
    sidebar.classList.toggle('hidden');
    mainContent.classList.toggle('full');
}

function handleMobileToggle() {
    if (!sidebar || !sidebarOverlay) return;
    sidebar.classList.toggle('show');
    sidebarOverlay.classList.toggle('show');
}

if (menuToggle && sidebar) {
    menuToggle.addEventListener('click', function () {
        if (window.innerWidth <= 991) {
            handleMobileToggle();
        } else {
            handleDesktopToggle();
        }
    });
}

if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', function () {
        sidebar.classList.remove('show');
        sidebarOverlay.classList.remove('show');
    });
}

window.addEventListener('resize', function () {
    if (window.innerWidth > 991 && sidebar && sidebarOverlay) {
        sidebar.classList.remove('show');
        sidebarOverlay.classList.remove('show');
    }
});
</script>

</body>
</html>