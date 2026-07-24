<?php
include '../includes/auth.php';
include '../config/database.php';

if($_SESSION['role_id'] != 2){
    header("Location: ../login.php");
    exit();
}

if(!isset($_SESSION['active_cdc_id'])){
    die("Please select an active CDC first from the dashboard.");
}

$cdc_id = (int) $_SESSION['active_cdc_id'];
$user_id = (int) $_SESSION['user_id'];
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$assessment_type = isset($_GET['assessment_type']) ? trim($_GET['assessment_type']) : 'baseline';

if($assessment_type !== 'baseline' && $assessment_type !== 'midline'){
    $assessment_type = 'baseline';
}
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$error = "";
$success = "";
$rows = [];
$prepared_by = trim($_SESSION['first_name'] . ' ' . $_SESSION['last_name']);

function assessment_type_label($assessment_type){
    $assessment_type = strtolower(trim((string)$assessment_type));

   switch($assessment_type){
    case 'baseline':
        return 'Baseline';
    case 'midline':
        return 'Midline';
    default:
        return 'N/A';
}
}

function fetch_wmr_rows($conn, $cdc_id, $date_from, $date_to, $assessment_type){
    $rows = [];

    $sql = "
        SELECT
            ar.record_id,
            c.child_id,
            CONCAT(c.first_name, ' ', c.last_name) AS child_name,
            cd.cdc_name,
            ar.date_recorded,
            ar.assessment_type,
            ar.is_submitted,
            ar.submitted_at,
            c.birthdate,
            ar.height,
            ar.weight,
            ar.muac,
            ar.edema_grade,
            ar.muac_status,
            ar.edema_status,
            ar.wfa_status,
            ar.hfa_status,
            ar.wflh_status,
            CONCAT(u.first_name, ' ', u.last_name) AS recorded_by_name
        FROM anthropometric_records ar
            INNER JOIN children c ON ar.child_id = c.child_id
            INNER JOIN cdc cd ON c.cdc_id = cd.cdc_id
            LEFT JOIN users u ON ar.recorded_by = u.user_id
            INNER JOIN (
            SELECT
                ar2.child_id,
                MIN(ar2.record_id) AS baseline_record_id
            FROM anthropometric_records ar2
            INNER JOIN children c2 ON ar2.child_id = c2.child_id
            WHERE c2.cdc_id = ? AND c2.is_deleted = 0
              AND ar2.assessment_type = ?
    ";

    $types = "is";
    $params = [$cdc_id, $assessment_type];

    if($date_from !== ''){
        $sql .= " AND DATE(ar2.date_recorded) >= ?";
        $types .= "s";
        $params[] = $date_from;
    }

    if($date_to !== ''){
        $sql .= " AND DATE(ar2.date_recorded) <= ?";
        $types .= "s";
        $params[] = $date_to;
    }

    $sql .= "
            GROUP BY ar2.child_id
        ) baseline_per_child ON ar.record_id = baseline_per_child.baseline_record_id
        WHERE c.cdc_id = ?
        ORDER BY c.last_name ASC, c.first_name ASC
    ";

    $types .= "i";
    $params[] = $cdc_id;

    $stmt = $conn->prepare($sql);

    if(!$stmt){
        return ['error' => 'Failed to prepare baseline WMR query.', 'rows' => []];
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    while($row = $result->fetch_assoc()){
        $birth = new DateTime($row['birthdate']);
        $measured = new DateTime(date('Y-m-d', strtotime($row['date_recorded'])));
        $diff = $birth->diff($measured);
        $age_in_months = ($diff->y * 12) + $diff->m;

        $row['age_in_months'] = $age_in_months;
        $rows[] = $row;
    }

    $stmt->close();

    return ['error' => '', 'rows' => $rows];
}

/* =========================================================
   SUBMIT BASELINE REPORT
========================================================= */
if(isset($_POST['submit_report'])){
    $date_from_post = isset($_POST['date_from']) ? trim($_POST['date_from']) : '';
    $date_to_post = isset($_POST['date_to']) ? trim($_POST['date_to']) : '';
    $assessment_type = isset($_POST['assessment_type']) ? trim($_POST['assessment_type']) : 'baseline';

if($assessment_type !== 'baseline' && $assessment_type !== 'midline'){
    $assessment_type = 'baseline';
}

    $baseline_data = fetch_wmr_rows($conn, $cdc_id, $date_from_post, $date_to_post, $assessment_type);

    if($baseline_data['error'] !== ''){
        $error = $baseline_data['error'];
    } elseif(empty($baseline_data['rows'])){
        $error = "No baseline records found to submit.";
    } else {
        mysqli_begin_transaction($conn);

        try{
            $submit_sql = "
                UPDATE anthropometric_records ar
                INNER JOIN children c ON ar.child_id = c.child_id
                INNER JOIN (
                    SELECT
                        ar2.child_id,
                        MIN(ar2.record_id) AS baseline_record_id
                    FROM anthropometric_records ar2
                    INNER JOIN children c2 ON ar2.child_id = c2.child_id
                    WHERE c2.cdc_id = ?
                      AND ar2.assessment_type = ?
            ";

            $submit_types = "is";
            $submit_params = [$cdc_id, $assessment_type];

            if($date_from_post !== ''){
                $submit_sql .= " AND DATE(ar2.date_recorded) >= ?";
                $submit_types .= "s";
                $submit_params[] = $date_from_post;
            }

            if($date_to_post !== ''){
                $submit_sql .= " AND DATE(ar2.date_recorded) <= ?";
                $submit_types .= "s";
                $submit_params[] = $date_to_post;
            }

            $submit_sql .= "
                    GROUP BY ar2.child_id
                ) baseline_per_child ON ar.record_id = baseline_per_child.baseline_record_id
                SET ar.is_submitted = 1,
                    ar.submitted_at = NOW()
                WHERE c.cdc_id = ?
            ";

            $submit_types .= "i";
            $submit_params[] = $cdc_id;

            $submit_stmt = $conn->prepare($submit_sql);

            if(!$submit_stmt){
                throw new Exception("Failed to prepare baseline WMR submission query.");
            }

            $submit_stmt->bind_param($submit_types, ...$submit_params);

            if(!$submit_stmt->execute()){
                throw new Exception("Failed to submit baseline WMR.");
            }

            $submit_stmt->close();

            $payload_rows = [];
            foreach($baseline_data['rows'] as $row){
                $payload_rows[] = [
                    'record_id' => (int)$row['record_id'],
                    'child_id' => (int)$row['child_id'],
                    'child_name' => $row['child_name'],
                    'cdc_name' => $row['cdc_name'],
                    'date_recorded' => $row['date_recorded'],
                    'assessment_type' => $row['assessment_type'],
                    'age_in_months' => $row['age_in_months'],
                    'height' => $row['height'],
                    'weight' => $row['weight'],
                    'muac' => $row['muac'],
                    'edema_status' => $row['edema_status'],
                    'edema_grade' => $row['edema_grade'],
                    'muac_status' => $row['muac_status'],
                    'wfa_status' => $row['wfa_status'],
                    'hfa_status' => $row['hfa_status'],
                    'wflh_status' => $row['wflh_status'],
                    'recorded_by_name' => $row['recorded_by_name']
                ];
            }

            $report_payload = json_encode([
                'report_type' => 'wmr',
                'assessment_scope' => $assessment_type . '_only',
                'cdc_id' => $cdc_id,
                'cdc_name' => $_SESSION['active_cdc_name'],
                'prepared_by' => $prepared_by,
                'date_from' => $date_from_post !== '' ? $date_from_post : null,
                'date_to' => $date_to_post !== '' ? $date_to_post : null,
                'submitted_rows' => $payload_rows,
                'total_records' => count($payload_rows)
            ], JSON_UNESCAPED_UNICODE);

            if($report_payload === false){
                throw new Exception("Failed to encode report payload.");
            }

            $insert_stmt = $conn->prepare("
                INSERT INTO submitted_reports
                (report_type, cdc_id, submitted_by, date_from, date_to, submitted_at, status, report_payload)
                VALUES (?, ?, ?, ?, ?, NOW(), ?, ?)
            ");

            if(!$insert_stmt){
                throw new Exception("Failed to prepare submitted report insert query.");
            }

            $report_type = 'wmr';
            $status = 'submitted';
            $date_from_insert = ($date_from_post !== '') ? $date_from_post : null;
            $date_to_insert = ($date_to_post !== '') ? $date_to_post : null;

            $insert_stmt->bind_param(
                "siissss",
                $report_type,
                $cdc_id,
                $user_id,
                $date_from_insert,
                $date_to_insert,
                $status,
                $report_payload
            );

            if(!$insert_stmt->execute()){
                throw new Exception("Failed to save submitted report.");
            }

            $insert_stmt->close();

            mysqli_commit($conn);
            $success = ucfirst($assessment_type) . " WMR submitted successfully. Selected records are now official and visible to CSWD.";
        } catch(Exception $e){
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }

    $date_from = $date_from_post;
    $date_to = $date_to_post;
}

/* =========================================================
   FETCH BASELINE WMR DATA
========================================================= */
$data = fetch_wmr_rows($conn, $cdc_id, $date_from, $date_to, $assessment_type);

if($data['error'] !== ''){
    $error = $data['error'];
} else {
    $rows = $data['rows'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weight Monitoring Report | NutriTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
    <link rel="stylesheet" href="../assets/cdw/cdw-topbar-notification.css">
    <style>
        *{
            box-sizing:border-box;
            margin:0;
            padding:0;
        }

        body{
            font-family:'Inter', sans-serif;
            background:#eef0f3;
            color:#333;
        }

        a{
            text-decoration:none;
        }

        .main-content{
            margin-left:260px;
            padding:112px 24px 30px;
            transition:margin-left 0.25s ease;
        }

        .main-content.full{
            margin-left:0;
        }

        .page-header{
            background:#ffffff;
            border:1px solid #dcdcdc;
            border-radius:14px;
            padding:22px 24px;
            margin-bottom:18px;
        }

        .back-link{
            display:inline-flex;
            align-items:center;
            gap:8px;
            margin-bottom:10px;
            font-size:13px;
            font-weight:600;
            color:#2E7D32;
        }

        .page-title{
            font-family:'Poppins', sans-serif;
            font-size:24px;
            font-weight:700;
            color:#2f2f2f;
            margin-bottom:6px;
        }

        .page-subtitle{
            font-size:13px;
            color:#666;
            line-height:1.6;
        }

        .content-card{
            background:#ffffff;
            border:1px solid #dcdcdc;
            border-radius:14px;
            padding:20px;
        }

        .filter-grid{
            display:grid;
            grid-template-columns:repeat(3, 1fr);
            gap:14px;
            margin-bottom:18px;
        }

        .form-group label{
            display:block;
            font-size:12px;
            color:#666;
            margin-bottom:6px;
            font-weight:500;
        }

        .form-control{
            width:100%;
            border:1px solid #cfcfcf;
            border-radius:8px;
            padding:11px 12px;
            font-size:13px;
            font-family:'Inter', sans-serif;
            background:#fff;
            color:#333;
            outline:none;
        }

        .form-control:focus{
            border-color:#2E7D32;
            box-shadow:0 0 0 3px rgba(46,125,50,0.08);
        }

        .button-group{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin-bottom:18px;
        }

        .button-group-split{
            display:flex;
            justify-content:space-between;
            align-items:center;
            flex-wrap:wrap;
            gap:10px;
            margin-bottom:18px;
        }

        .button-group-end{
            display:flex;
            justify-content:flex-end;
            gap:10px;
            flex-wrap:wrap;
            margin-top:18px;
        }

        .btn{
            border:none;
            border-radius:8px;
            padding:11px 16px;
            font-size:13px;
            font-weight:600;
            font-family:'Inter', sans-serif;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .btn-generate{
            background:#2E7D32;
            color:#fff;
        }

        .btn-submit{
            background:#3498db;
            color:#fff;
        }

        .btn-reset{
            background:#f5f5f5;
            color:#555;
            border:1px solid #d6d6d6;
        }

        .btn-print{
            background:#16a34a;
            color:#ffffff;
        }

        .btn-print:hover{
            background:#15803d;
        }

        .error-message{
            background:#fdeaea;
            color:#c62828;
            border:1px solid #f5c2c7;
            border-radius:10px;
            padding:14px 16px;
            margin-bottom:16px;
            font-size:13px;
            font-weight:600;
        }

        .success-message{
            background:#eaf7ec;
            color:#2e7d32;
            border:1px solid #cfe8d3;
            border-radius:10px;
            padding:14px 16px;
            margin-bottom:16px;
            font-size:13px;
            font-weight:600;
        }

        .report-meta{
            display:grid;
            grid-template-columns:repeat(4, 1fr);
            gap:14px;
            margin-bottom:18px;
        }

        .meta-card{
            background:#f8fbf8;
            border:1px solid #dfe9df;
            border-radius:10px;
            padding:12px 14px;
        }

        .meta-label{
            font-size:12px;
            color:#777;
            margin-bottom:4px;
            font-weight:500;
        }

        .meta-value{
            font-size:14px;
            color:#2f2f2f;
            font-weight:700;
            line-height:1.4;
        }

        .table-wrapper{
            width:100%;
            overflow-x:auto;
        }

        .wmr-table{
            width:100%;
            min-width:1500px;
            border-collapse:collapse;
        }

        .wmr-table th,
        .wmr-table td{
            border-bottom:1px solid #e5e7eb;
            padding:14px 12px;
            text-align:center;
            vertical-align:middle;
            font-size:14px;
        }

        .wmr-table th{
            background:#f8fafc;
            color:#222;
            font-weight:700;
        }

        .wmr-table td.left-cell,
        .wmr-table th.left-cell{
            text-align:left;
        }

        .assessment-badge,
        .submit-badge{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-width:120px;
            padding:8px 12px;
            border-radius:8px;
            font-weight:600;
            font-size:13px;
        }

        .badge-baseline{
            background:#e3f2fd;
            color:#1565c0;
        }

        .badge-submitted{
            background:#e8f5e9;
            color:#2e7d32;
        }

        .badge-draft{
            background:#fff3e0;
            color:#ef6c00;
        }

        .status-text{
            font-weight:600;
            line-height:1.4;
        }

        .no-data{
            padding:16px 4px;
            font-size:13px;
            color:#777;
        }

        @media (max-width: 991px){
            .sidebar{
                transform:translateX(-100%);
            }

            .sidebar.open{
                transform:translateX(0);
            }

            .sidebar-overlay.show{
                display:block;
                position:fixed;
                top:88px;
                left:0;
                width:100%;
                height:calc(100vh - 88px);
                background:rgba(0,0,0,0.25);
                z-index:1040;
            }

            .main-content{
                margin-left:0;
                padding:104px 16px 24px;
            }

            .topbar{
                padding:0 12px;
            }

            .topbar-logo{
                height:44px;
            }

            .user-chip{
                display:none;
            }

            .filter-grid,
            .report-meta{
                grid-template-columns:1fr 1fr;
            }
        }

        @media (max-width: 768px){
            .filter-grid,
            .report-meta{
                grid-template-columns:1fr;
            }
        }

        /* =========================
   PRINT / SAVE AS PDF
========================= */
@media print {
    @page {
        size: A4 landscape;
        margin: 8mm;
    }

    body{
        background:#ffffff !important;
        color:#000000 !important;
        font-family:Arial, sans-serif !important;
    }

    .topbar,
    .sidebar,
    .sidebar-overlay,
    .back-link,
    .button-group,
    .no-print,
    .success-message,
    .error-message{
        display:none !important;
    }

    .main-content{
        margin-left:0 !important;
        padding:0 !important;
        width:100% !important;
    }

    .page-header{
        border:none !important;
        padding:0 0 10px 0 !important;
        margin-bottom:10px !important;
        background:#ffffff !important;
    }

    .page-title{
        color:#000000 !important;
        font-size:18px !important;
        text-align:center !important;
        margin-bottom:5px !important;
    }

    .page-subtitle{
        color:#000000 !important;
        text-align:center !important;
        font-size:11px !important;
    }

    .content-card{
        border:none !important;
        padding:0 !important;
        background:#ffffff !important;
    }

    .report-meta{
        display:grid !important;
        grid-template-columns:repeat(4, 1fr) !important;
        gap:6px !important;
        margin-bottom:8px !important;
    }

    .meta-card{
        border:1px solid #000000 !important;
        background:#ffffff !important;
        border-radius:0 !important;
        padding:5px 6px !important;
    }

    .meta-label,
    .meta-value{
        color:#000000 !important;
        font-size:9px !important;
        line-height:1.2 !important;
    }

    .meta-value{
        font-weight:bold !important;
    }

    .table-wrapper{
        overflow:visible !important;
        width:100% !important;
    }

    .wmr-table{
        width:100% !important;
        min-width:0 !important;
        border-collapse:collapse !important;
        table-layout:fixed !important;
    }

    .wmr-table th,
    .wmr-table td{
        border:1px solid #000000 !important;
        padding:3px 4px !important;
        color:#000000 !important;
        background:#ffffff !important;
        font-size:7.5px !important;
        line-height:1.15 !important;
        vertical-align:top !important;
        word-break:break-word !important;
        white-space:normal !important;
    }

    .wmr-table th{
        font-weight:bold !important;
        text-align:center !important;
    }

    .wmr-table tbody tr:hover{
        background:#ffffff !important;
    }

    .assessment-badge,
    .submit-badge{
        min-width:0 !important;
        padding:0 !important;
        border-radius:0 !important;
        background:#ffffff !important;
        color:#000000 !important;
        font-size:7.5px !important;
        font-weight:normal !important;
    }

    .status-text{
        color:#000000 !important;
        font-size:7.5px !important;
        font-weight:normal !important;
    }

    .no-data{
        color:#000000 !important;
        font-size:11px !important;
    }
}
    </style>
</head>
<body class="<?php echo (isset($_SESSION['theme_mode']) && $_SESSION['theme_mode'] === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-header">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        <h1 class="page-title">Weight Monitoring Report</h1>
        <div class="page-subtitle">
            <?php echo ucfirst($assessment_type); ?> Records Only
        </div>
    </div>

    <div class="content-card">
        <?php if(!empty($error)){ ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <?php if(!empty($success)){ ?>
            <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
        <?php } ?>

        <form method="GET" class="no-print">
            <div class="filter-grid">
                <div class="form-group">
                    <label>Assessment Type</label>
                    <select name="assessment_type" class="form-control">
                        <option value="baseline" <?php echo $assessment_type === 'baseline' ? 'selected' : ''; ?>>Baseline</option>
                        <option value="midline" <?php echo $assessment_type === 'midline' ? 'selected' : ''; ?>>Midline</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>

                <div class="form-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
            </div>

            <div class="button-group-split no-print">
                <div class="button-group" style="margin-bottom:0;">
                    <button type="submit" class="btn btn-generate">Generate Report</button>
                    <a href="wmr_report.php" class="btn btn-reset">Reset</a>
                </div>

                <button type="button" class="btn btn-print" onclick="window.print()">
                    Print / Save as PDF
                </button>
            </div>
        </form>

        <div class="report-meta">
            <div class="meta-card">
                <div class="meta-label">Active CDC</div>
                <div class="meta-value"><?php echo htmlspecialchars($_SESSION['active_cdc_name']); ?></div>
            </div>

            <div class="meta-card">
                <div class="meta-label">Prepared By</div>
                <div class="meta-value"><?php echo htmlspecialchars($prepared_by); ?></div>
            </div>

            <div class="meta-card">
                <div class="meta-label">Date From</div>
                <div class="meta-value"><?php echo $date_from !== '' ? htmlspecialchars(date("F d, Y", strtotime($date_from))) : 'All'; ?></div>
            </div>

            <div class="meta-card">
                <div class="meta-label">Date To</div>
                <div class="meta-value"><?php echo $date_to !== '' ? htmlspecialchars(date("F d, Y", strtotime($date_to))) : 'All'; ?></div>
            </div>
        </div>

        <?php if(!empty($rows)){ ?>
            <div class="table-wrapper">
                <table class="wmr-table">
                    <thead>
                        <tr>
                            <th class="left-cell">Child Name</th>
                            <th>Date Measured</th>
                            <th>Assessment Type</th>
                            <th>Submission Status</th>
                            <th>Age (Months)</th>
                            <th>Height (cm)</th>
                            <th>Weight (kg)</th>
                            <th>MUAC (cm)</th>
                            <th>Edema</th>
                            <th>Grade</th>
                            <th>MUAC Status</th>
                            <th>WFA</th>
                            <th>HFA</th>
                            <th>WFL/H</th>

                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($rows as $row){ ?>
                            <?php
                                $submit_badge_class = ((int)$row['is_submitted'] === 1) ? 'badge-submitted' : 'badge-draft';
                                $submit_label = ((int)$row['is_submitted'] === 1) ? 'Submitted' : 'Draft';
                            ?>
                            <tr>
                                <td class="left-cell"><?php echo htmlspecialchars($row['child_name']); ?></td>
                                <td><?php echo date("M d, Y", strtotime($row['date_recorded'])); ?></td>
                                <td>
                                    <span class="assessment-badge badge-baseline">
                                        <?php echo htmlspecialchars(assessment_type_label($row['assessment_type'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="submit-badge <?php echo $submit_badge_class; ?>">
                                        <?php echo htmlspecialchars($submit_label); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($row['age_in_months']); ?></td>
                                <td><?php echo htmlspecialchars(number_format((float)$row['height'], 2)); ?></td>
                                <td><?php echo htmlspecialchars(number_format((float)$row['weight'], 2)); ?></td>
                                <td><?php echo htmlspecialchars(number_format((float)$row['muac'], 2)); ?></td>
                                <td><?php echo htmlspecialchars($row['edema_status'] ?: '--'); ?></td>
                                <td><?php echo htmlspecialchars($row['edema_grade'] ?: '--'); ?></td>
                                <td><span class="status-text"><?php echo htmlspecialchars($row['muac_status'] ?: '--'); ?></span></td>
                                <td><span class="status-text"><?php echo htmlspecialchars($row['wfa_status']); ?></span></td>
                                <td><span class="status-text"><?php echo htmlspecialchars($row['hfa_status']); ?></span></td>
                                <td><span class="status-text"><?php echo htmlspecialchars($row['wflh_status']); ?></span></td>                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } else { ?>
            <p class="no-data">No <?php echo htmlspecialchars(ucfirst($assessment_type)); ?> records found for this CDC.</p>
        <?php } ?>

        <form method="POST">
            <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            <input type="hidden" name="assessment_type" value="<?php echo htmlspecialchars($assessment_type); ?>">

            <div class="button-group-end no-print">
                <button type="submit" name="submit_report" class="btn btn-submit">Submit Report</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSidebar() {
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var mainContent = document.getElementById('mainContent');

    if (window.innerWidth <= 991) {
        sidebar.classList.toggle('open');
        if (overlay) {
            overlay.classList.toggle('show');
        }
    } else {
        sidebar.classList.toggle('closed');
        mainContent.classList.toggle('full');
    }
}

function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    var overlay = document.getElementById('sidebarOverlay');
    if (overlay) {
        overlay.classList.remove('show');
    }
}
</script>
<script src="../assets/cdw/sidebar.js"></script>
</body>
</html>