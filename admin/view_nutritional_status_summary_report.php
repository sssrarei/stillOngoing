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

if ($report['report_type'] !== 'nutritional_status_summary') {
    die('The selected submitted report is not a Nutritional Status Summary report.');
}

$payload = json_decode($report['report_payload'], true);
if (!is_array($payload)) {
    $payload = [];
}

$rows = [];
if (isset($payload['submitted_rows']) && is_array($payload['submitted_rows'])) {
    $rows = $payload['submitted_rows'];
}

$summary_row = !empty($rows) && isset($rows[0]) && is_array($rows[0]) ? $rows[0] : [];

$prepared_by = safe_value($payload['prepared_by'] ?? '', $report['submitted_by_name']);
$reporting_month = safe_value($payload['reporting_month'] ?? '', 'N/A');
$reporting_month_display = safe_value($payload['reporting_month_display'] ?? '', $reporting_month);
$summary_text = safe_value($payload['summary_text'] ?? '', 'N/A');
$highest_label = safe_value($payload['highest_label'] ?? '', 'N/A');
$highest_value = isset($payload['highest_value']) ? $payload['highest_value'] : 'N/A';
$highest_pct = isset($payload['highest_pct']) ? $payload['highest_pct'] : 'N/A';

$total = isset($summary_row['total']) ? $summary_row['total'] : 0;
$normal = isset($summary_row['normal']) ? $summary_row['normal'] : 0;
$normal_pct = isset($summary_row['normal_pct']) ? $summary_row['normal_pct'] : 0;
$underweight = isset($summary_row['underweight']) ? $summary_row['underweight'] : 0;
$underweight_pct = isset($summary_row['underweight_pct']) ? $summary_row['underweight_pct'] : 0;
$severely_underweight = isset($summary_row['severely_underweight']) ? $summary_row['severely_underweight'] : 0;
$severely_underweight_pct = isset($summary_row['severely_underweight_pct']) ? $summary_row['severely_underweight_pct'] : 0;
$overweight = isset($summary_row['overweight']) ? $summary_row['overweight'] : 0;
$overweight_pct = isset($summary_row['overweight_pct']) ? $summary_row['overweight_pct'] : 0;
$obese = isset($summary_row['obese']) ? $summary_row['obese'] : 0;
$obese_pct = isset($summary_row['obese_pct']) ? $summary_row['obese_pct'] : 0;
$stunted = isset($summary_row['stunted']) ? $summary_row['stunted'] : 0;
$stunted_pct = isset($summary_row['stunted_pct']) ? $summary_row['stunted_pct'] : 0;
$severely_stunted = isset($summary_row['severely_stunted']) ? $summary_row['severely_stunted'] : 0;
$severely_stunted_pct = isset($summary_row['severely_stunted_pct']) ? $summary_row['severely_stunted_pct'] : 0;
$moderately_wasted = isset($summary_row['moderately_wasted']) ? $summary_row['moderately_wasted'] : 0;
$moderately_wasted_pct = isset($summary_row['moderately_wasted_pct']) ? $summary_row['moderately_wasted_pct'] : 0;
$severely_wasted = isset($summary_row['severely_wasted']) ? $summary_row['severely_wasted'] : 0;
$severely_wasted_pct = isset($summary_row['severely_wasted_pct']) ? $summary_row['severely_wasted_pct'] : 0;

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
    <title>Nutritional Status Summary | NutriTrack</title>
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
            grid-template-columns: repeat(5, 1fr);
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

        .status-summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(160px, 1fr));
            gap: 14px;
            margin-top: 18px;
        }

        .status-summary-card {
            background: #ffffff;
            border: 1px solid #e5edf6;
            border-radius: 14px;
            padding: 16px;
            text-align: center;
        }

        .status-summary-title {
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .status-summary-value {
            font-size: 22px;
            font-weight: 700;
            color: #163b68;
        }

        .status-normal {
            color: #2E7D32;
            font-weight: 700;
        }

        .status-alert {
            color: #C0392B;
            font-weight: 700;
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
            min-width: 1200px;
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

        .highlight-box {
            margin-top: 18px;
            background: #f8fbff;
            border: 1px solid #dbeafe;
            border-radius: 14px;
            padding: 18px;
        }

        .highlight-box strong {
            color: #163b68;
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

        @media (max-width: 1200px) {
            .summary-grid,
            .status-summary-grid {
                grid-template-columns: repeat(2, minmax(180px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .report-view-wrapper {
                padding: 16px;
            }

            .summary-grid,
            .status-summary-grid {
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
    .report-header p,
    .summary-label,
    .summary-value,
    .status-summary-title,
    .status-summary-value,
    .highlight-box,
    .highlight-box strong {
        color: #000000 !important;
    }

    .summary-card {
        padding: 10px 0 !important;
    }

    .summary-grid {
        grid-template-columns: repeat(5, 1fr) !important;
        gap: 8px !important;
    }

    .summary-item,
    .status-summary-card,
    .highlight-box {
        padding: 8px !important;
        border: 1px solid #cccccc !important;
        background: #ffffff !important;
        border-radius: 0 !important;
    }

    .status-summary-grid {
        grid-template-columns: repeat(5, 1fr) !important;
        gap: 8px !important;
    }

    .status-normal,
    .status-alert {
        color: #000000 !important;
        font-weight: 700 !important;
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
        <h1>Nutritional Status Summary</h1>
    </div>
</div>

        <div class="summary-card">
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-label">CDC</div>
                    <div class="summary-value"><?php echo h(safe_value($payload['cdc_name'] ?? '', $report['cdc_name'])); ?></div>
                </div>

                <div class="summary-item">
                    <div class="summary-label">Reporting Month</div>
                    <div class="summary-value"><?php echo h($reporting_month_display); ?></div>
                </div>

                

                <div class="summary-item">
                    <div class="summary-label">Prepared By</div>
                    <div class="summary-value"><?php echo h($prepared_by); ?></div>
                </div>

                

                

                <div class="summary-item">
                    <div class="summary-label">Highest Label</div>
                    <div class="summary-value"><?php echo h($highest_label); ?></div>
                </div>

                <div class="summary-item">
                    <div class="summary-label">Highest Percentage</div>
                    <div class="summary-value"><?php echo h($highest_value); ?> (<?php echo h($highest_pct); ?>%)</div>
                </div>
            </div>

            <div class="highlight-box">
                <strong>Summary Insight:</strong>
                <?php echo h($summary_text); ?>
            </div>

            <div class="status-summary-grid">
                <div class="status-summary-card">
                    <div class="status-summary-title">Total</div>
                    <div class="status-summary-value"><?php echo h($total); ?></div>
                </div>
                <div class="status-summary-card">
                    <div class="status-summary-title">Normal</div>
                    <div class="status-summary-value status-normal"><?php echo h($normal); ?> (<?php echo h($normal_pct); ?>%)</div>
                </div>
                <div class="status-summary-card">
                    <div class="status-summary-title">Underweight</div>
                    <div class="status-summary-value status-alert"><?php echo h($underweight); ?> (<?php echo h($underweight_pct); ?>%)</div>
                </div>
                <div class="status-summary-card">
                    <div class="status-summary-title">Severely Underweight</div>
                    <div class="status-summary-value status-alert"><?php echo h($severely_underweight); ?> (<?php echo h($severely_underweight_pct); ?>%)</div>
                </div>
                <div class="status-summary-card">
                    <div class="status-summary-title">Overweight</div>
                    <div class="status-summary-value status-alert"><?php echo h($overweight); ?> (<?php echo h($overweight_pct); ?>%)</div>
                </div>
                <div class="status-summary-card">
                    <div class="status-summary-title">Obese</div>
                    <div class="status-summary-value status-alert"><?php echo h($obese); ?> (<?php echo h($obese_pct); ?>%)</div>
                </div>
                <div class="status-summary-card">
                    <div class="status-summary-title">Stunted</div>
                    <div class="status-summary-value status-alert"><?php echo h($stunted); ?> (<?php echo h($stunted_pct); ?>%)</div>
                </div>
                <div class="status-summary-card">
                    <div class="status-summary-title">Severely Stunted</div>
                    <div class="status-summary-value status-alert"><?php echo h($severely_stunted); ?> (<?php echo h($severely_stunted_pct); ?>%)</div>
                </div>
                <div class="status-summary-card">
                    <div class="status-summary-title">Moderately Wasted</div>
                    <div class="status-summary-value status-alert"><?php echo h($moderately_wasted); ?> (<?php echo h($moderately_wasted_pct); ?>%)</div>
                </div>
                <div class="status-summary-card">
                    <div class="status-summary-title">Severely Wasted</div>
                    <div class="status-summary-value status-alert"><?php echo h($severely_wasted); ?> (<?php echo h($severely_wasted_pct); ?>%)</div>
                </div>
            </div>
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