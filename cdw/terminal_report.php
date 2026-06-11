<?php
include '../includes/auth.php';
include '../config/database.php';

if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 2) {
    header("Location: ../login.php");
    exit();
}

if (!isset($_SESSION['active_cdc_id'])) {
    die("Please select an active CDC first from the dashboard.");
}

$cdc_id = (int) $_SESSION['active_cdc_id'];
$cdc_name = isset($_SESSION['active_cdc_name']) ? trim($_SESSION['active_cdc_name']) : 'N/A';
$prepared_by = trim($_SESSION['first_name'] . ' ' . $_SESSION['last_name']);
$submitted_by = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

$error = "";
$success = "";
$rows = [];

$program_title = "Nutritional Monitoring Terminal Report";
$program_description = "The report is designed to provide a clear comparison of the children's nutritional status at the start, middle, and end of the program, based on the specific assessment types recorded in the system. Only records that are explicitly marked as Baseline, Midline, or Endline will be included in this report to ensure consistency and accuracy in tracking the progress of each child throughout the program duration.";
$program_duration = "180 Days (6 Months)";

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function format_date_display($date) {
    if (empty($date) || $date === '0000-00-00') {
        return 'N/A';
    }

    $timestamp = strtotime($date);
    if (!$timestamp) {
        return 'N/A';
    }

    return date("F d, Y", $timestamp);
}

function normalize_status($value) {
    $value = trim((string)$value);
    return $value !== '' ? $value : 'No Data';
}

function find_first_record_of_type($records, $type) {
    $type = strtolower(trim($type));

    foreach ($records as $record) {
        $record_type = strtolower(trim((string)$record['assessment_type']));
        if ($record_type === $type) {
            return $record;
        }
    }

    return null;
}

function find_first_record_of_type_after_baseline($records, $type, $baseline_date) {
    $type = strtolower(trim($type));

    if (empty($baseline_date) || $baseline_date === '0000-00-00') {
        return null;
    }

    $baseline_ts = strtotime($baseline_date);

    foreach ($records as $record) {
        $record_type = strtolower(trim((string)$record['assessment_type']));
        $record_date = isset($record['date_recorded']) ? $record['date_recorded'] : '';

        if (
            $record_type === $type &&
            !empty($record_date) &&
            $record_date !== '0000-00-00' &&
            strtotime($record_date) > $baseline_ts
        ) {
            return $record;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| GET CHILDREN UNDER ACTIVE CDC
|--------------------------------------------------------------------------
*/
$children_sql = "SELECT child_id, first_name, middle_name, last_name, sex
                 FROM children
                 WHERE cdc_id = ?
                 ORDER BY last_name ASC, first_name ASC";

$children_stmt = mysqli_prepare($conn, $children_sql);

if (!$children_stmt) {
    $error = "Failed to prepare children query.";
} else {
    mysqli_stmt_bind_param($children_stmt, "i", $cdc_id);
    mysqli_stmt_execute($children_stmt);
    $children_result = mysqli_stmt_get_result($children_stmt);

    while ($child = mysqli_fetch_assoc($children_result)) {
        $child_id = (int) $child['child_id'];

        $records_sql = "SELECT
                            record_id,
                            child_id,
                            date_recorded,
                            assessment_type,
                            wfa_status,
                            hfa_status,
                            wflh_status
                        FROM anthropometric_records
                        WHERE child_id = ?
                          AND LOWER(TRIM(assessment_type)) IN ('baseline', 'midline', 'endline')
                        ORDER BY date_recorded ASC, record_id ASC";

        $records_stmt = mysqli_prepare($conn, $records_sql);

        if (!$records_stmt) {
            $error = "Failed to prepare anthropometric records query.";
            break;
        }

        mysqli_stmt_bind_param($records_stmt, "i", $child_id);
        mysqli_stmt_execute($records_stmt);
        $records_result = mysqli_stmt_get_result($records_stmt);

        $records = [];
        while ($record = mysqli_fetch_assoc($records_result)) {
            $records[] = $record;
        }

        mysqli_stmt_close($records_stmt);

        $baseline = find_first_record_of_type($records, 'baseline');
        $midline = null;
        $endline = null;

        if ($baseline) {
            $baseline_date = $baseline['date_recorded'];
            $midline = find_first_record_of_type_after_baseline($records, 'midline', $baseline_date);
            $endline = find_first_record_of_type_after_baseline($records, 'endline', $baseline_date);
        }

        $middle_name = !empty($child['middle_name']) ? ' ' . $child['middle_name'] : '';
        $full_name = trim($child['first_name'] . $middle_name . ' ' . $child['last_name']);

        $rows[] = [
    'child_id' => $child_id,
    'child_name' => $full_name,
    'sex' => !empty($child['sex']) ? $child['sex'] : 'N/A',

    'baseline_date' => $baseline ? $baseline['date_recorded'] : '',
            'baseline_date_display' => $baseline ? format_date_display($baseline['date_recorded']) : 'N/A',
            'baseline_wfa' => $baseline ? normalize_status($baseline['wfa_status']) : 'No Data',
            'baseline_hfa' => $baseline ? normalize_status($baseline['hfa_status']) : 'No Data',
            'baseline_wflh' => $baseline ? normalize_status($baseline['wflh_status']) : 'No Data',

            'midline_date' => $midline ? $midline['date_recorded'] : '',
            'midline_date_display' => $midline ? format_date_display($midline['date_recorded']) : 'N/A',
            'midline_wfa' => $midline ? normalize_status($midline['wfa_status']) : 'No Data',
            'midline_hfa' => $midline ? normalize_status($midline['hfa_status']) : 'No Data',
            'midline_wflh' => $midline ? normalize_status($midline['wflh_status']) : 'No Data',

            'endline_date' => $endline ? $endline['date_recorded'] : '',
            'endline_date_display' => $endline ? format_date_display($endline['date_recorded']) : 'N/A',
            'endline_wfa' => $endline ? normalize_status($endline['wfa_status']) : 'No Data',
            'endline_hfa' => $endline ? normalize_status($endline['hfa_status']) : 'No Data',
            'endline_wflh' => $endline ? normalize_status($endline['wflh_status']) : 'No Data'
        ];
    }

    mysqli_stmt_close($children_stmt);
}

$total_children = count($rows);
$with_baseline_count = 0;
$with_midline_count = 0;
$with_endline_count = 0;

$min_baseline_date = null;
$max_endline_date = null;

foreach ($rows as $row) {
    if (!empty($row['baseline_date']) && $row['baseline_date'] !== '0000-00-00') {
        $with_baseline_count++;

        if ($min_baseline_date === null || strtotime($row['baseline_date']) < strtotime($min_baseline_date)) {
            $min_baseline_date = $row['baseline_date'];
        }
    }

    if (!empty($row['midline_date']) && $row['midline_date'] !== '0000-00-00') {
        $with_midline_count++;
    }

    if (!empty($row['endline_date']) && $row['endline_date'] !== '0000-00-00') {
        $with_endline_count++;

        if ($max_endline_date === null || strtotime($row['endline_date']) > strtotime($max_endline_date)) {
            $max_endline_date = $row['endline_date'];
        }
    }
}

/*
|--------------------------------------------------------------------------
| SUBMISSION GUARD
|--------------------------------------------------------------------------
*/
$can_submit = true;
$incomplete_children = [];

foreach ($rows as $row) {
    $missing_parts = [];

    if (empty($row['baseline_date']) || $row['baseline_date'] === '0000-00-00') {
        $missing_parts[] = 'Baseline';
    }

    if (empty($row['midline_date']) || $row['midline_date'] === '0000-00-00') {
        $missing_parts[] = 'Midline';
    }

    if (empty($row['endline_date']) || $row['endline_date'] === '0000-00-00') {
        $missing_parts[] = 'Endline';
    }

    if (!empty($missing_parts)) {
        $can_submit = false;
        $incomplete_children[] = [
            'child_name' => $row['child_name'],
            'missing' => implode(', ', $missing_parts)
        ];
    }
}

/*
|--------------------------------------------------------------------------
| SUBMIT REPORT
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    if (!empty($error)) {
        $error = $error;
    } elseif (empty($rows)) {
        $error = "No terminal report data found to submit.";
    } elseif (!$can_submit) {
        $error = "Terminal Report cannot be submitted yet because some children do not have complete Baseline, Midline, and Endline records.";
    } else {
        $report_type = 'terminal_report';
        $status = 'submitted';
        $date_from = $min_baseline_date;
        $date_to = $max_endline_date;

        $payload = [
            'program_title' => $program_title,
            'program_description' => $program_description,
            'program_duration' => $program_duration,
            'cdc_name' => $cdc_name,
            'prepared_by' => $prepared_by,
            'generated_at' => date('Y-m-d H:i:s'),
            'generated_at_display' => date('F d, Y h:i A'),
            'total_children' => $total_children,
            'with_baseline_count' => $with_baseline_count,
            'with_midline_count' => $with_midline_count,
            'with_endline_count' => $with_endline_count,
            'submitted_rows' => $rows
        ];

        $report_payload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if ($report_payload === false) {
            $error = "Failed to encode report payload.";
        } else {
            $insert_sql = "INSERT INTO submitted_reports
                           (report_type, cdc_id, submitted_by, date_from, date_to, submitted_at, status, report_payload)
                           VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)";

            $insert_stmt = mysqli_prepare($conn, $insert_sql);

            if (!$insert_stmt) {
                $error = "Failed to prepare terminal report submission.";
            } else {
                mysqli_stmt_bind_param(
                    $insert_stmt,
                    "siissss",
                    $report_type,
                    $cdc_id,
                    $submitted_by,
                    $date_from,
                    $date_to,
                    $status,
                    $report_payload
                );

                if (mysqli_stmt_execute($insert_stmt)) {
                    $success = "Terminal Report submitted successfully.";
                } else {
                    $error = "Failed to submit Terminal Report.";
                }

                mysqli_stmt_close($insert_stmt);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terminal Report | NutriTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
    <link rel="stylesheet" href="../assets/cdw/terminal_report.css">
    <link rel="stylesheet" href="../assets/cdw/cdw-topbar-notification.css">
</head>
<?php include __DIR__ . '/../includes/auth.php'; ?>
<body class="<?php echo themeClass(); ?>">

<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-header">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        <h1 class="page-title">Terminal Report</h1>
        <div class="page-subtitle">
            Final comparison report using Baseline, Midline, and Endline anthropometric records only.
        </div>
    </div>

    <div class="content-card">
        <form method="POST" class="top-actions no-print">
    <button
        type="submit"
        name="submit_report"
        class="btn btn-submit <?php echo !$can_submit ? 'btn-disabled' : ''; ?>"
        <?php echo !$can_submit ? 'disabled' : ''; ?>
    >
        Submit Report
    </button>

    <button type="button" class="btn btn-print" onclick="window.print()">
        Print / Save as PDF
    </button>
</form>

        <?php if (!empty($error)) { ?>
            <div class="error-message"><?php echo h($error); ?></div>
        <?php } ?>

        <?php if (!empty($success)) { ?>
            <div class="success-message"><?php echo h($success); ?></div>
        <?php } ?>

        <?php if (!$can_submit && !empty($rows)) { ?>
            <div class="warning-message">
                Terminal Report cannot be submitted yet. All children must have complete Baseline, Midline, and Endline records first.
            </div>

            <div class="incomplete-box">
                <div class="incomplete-title">Incomplete Child Records</div>
                <ul class="incomplete-list">
                    <?php foreach ($incomplete_children as $item) { ?>
                        <li>
                            <strong><?php echo h($item['child_name']); ?></strong> — Missing: <?php echo h($item['missing']); ?>
                        </li>
                    <?php } ?>
                </ul>
            </div>
        <?php } ?>

        <div class="meta-box">
            <div class="meta-grid">
                <div class="meta-item"><strong>CDC:</strong> <?php echo h($cdc_name); ?></div>
                <div class="meta-item"><strong>Prepared by:</strong> <?php echo h($prepared_by); ?></div>
                <div class="meta-item"><strong>Program Duration:</strong> <?php echo h($program_duration); ?></div>
                <div class="meta-item"><strong>Date Generated:</strong> <?php echo date("F d, Y"); ?></div>
            </div>
        </div>

        <div class="program-box">
            <div class="program-title"><?php echo h($program_title); ?></div>
            <div class="program-text"><?php echo h($program_description); ?></div>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-label">Total Children</div>
                <div class="summary-value"><?php echo (int) $total_children; ?></div>
            </div>

            <div class="summary-card">
                <div class="summary-label">With Baseline</div>
                <div class="summary-value"><?php echo (int) $with_baseline_count; ?></div>
            </div>

            <div class="summary-card">
                <div class="summary-label">With Midline</div>
                <div class="summary-value"><?php echo (int) $with_midline_count; ?></div>
            </div>

            <div class="summary-card">
                <div class="summary-label">With Endline</div>
                <div class="summary-value"><?php echo (int) $with_endline_count; ?></div>
            </div>
        </div>

        <?php if (!empty($rows)) { ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Child Name</th>
                            <th>Baseline Date</th>
                            <th>Baseline WFA</th>
                            <th>Baseline HFA</th>
                            <th>Baseline WFL/H</th>
                            <th>Midline Date</th>
                            <th>Midline WFA</th>
                            <th>Midline HFA</th>
                            <th>Midline WFL/H</th>
                            <th>Endline Date</th>
                            <th>Endline WFA</th>
                            <th>Endline HFA</th>
                            <th>Endline WFL/H</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row) { ?>
                            <tr>
                                <td><?php echo h($row['child_name']); ?></td>
                                <td><?php echo h($row['baseline_date_display']); ?></td>
                                <td><span class="status-chip"><?php echo h($row['baseline_wfa']); ?></span></td>
                                <td><span class="status-chip"><?php echo h($row['baseline_hfa']); ?></span></td>
                                <td><span class="status-chip"><?php echo h($row['baseline_wflh']); ?></span></td>
                                <td><?php echo h($row['midline_date_display']); ?></td>
                                <td><span class="status-chip"><?php echo h($row['midline_wfa']); ?></span></td>
                                <td><span class="status-chip"><?php echo h($row['midline_hfa']); ?></span></td>
                                <td><span class="status-chip"><?php echo h($row['midline_wflh']); ?></span></td>
                                <td><?php echo h($row['endline_date_display']); ?></td>
                                <td><span class="status-chip"><?php echo h($row['endline_wfa']); ?></span></td>
                                <td><span class="status-chip"><?php echo h($row['endline_hfa']); ?></span></td>
                                <td><span class="status-chip"><?php echo h($row['endline_wflh']); ?></span></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } else { ?>
            <p class="no-data">No terminal report data found.</p>
        <?php } ?>
    </div>
</div>

<script>
function toggleSidebar() {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var mainContent = document.getElementById('mainContent');

    if (window.innerWidth <= 991) {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('show');
    } else {
        sidebar.classList.toggle('closed');
        mainContent.classList.toggle('full');
    }
}

function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('show');
}
</script>
<script src="../assets/cdw/sidebar.js"></script>
</body>
</html>