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
$cdc_name = isset($_SESSION['active_cdc_name']) ? $_SESSION['active_cdc_name'] : 'N/A';
$user_id = (int) $_SESSION['user_id'];
$prepared_by = trim($_SESSION['first_name'] . ' ' . $_SESSION['last_name']);

$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$error = "";
$success = "";
$rows = [];
$grouped_rows = [];

$total_records = 0;
$present_count = 0;
$absent_count = 0;
$attendance_rate = 0;

function safe_display($value){
    return (!empty($value) && trim($value) !== '') ? $value : 'N/A';
}

function get_date_range_display($date_from, $date_to){
    if($date_from !== '' && $date_to !== ''){
        return date("F d, Y", strtotime($date_from)) . " to " . date("F d, Y", strtotime($date_to));
    } elseif($date_from !== ''){
        return "From " . date("F d, Y", strtotime($date_from));
    } elseif($date_to !== ''){
        return "Up to " . date("F d, Y", strtotime($date_to));
    }

    return "All available feeding records";
}

function fetch_feeding_attendance_rows($conn, $cdc_id, $date_from, $date_to){
    $rows = [];
    $grouped_rows = [];
    $total_records = 0;
    $present_count = 0;
    $absent_count = 0;
    $attendance_rate = 0;
    $error = "";

    $sql = "SELECT
                fr.feeding_record_id,
                fr.feeding_date,
                CONCAT(c.first_name, ' ', c.last_name) AS child_name,
                fr.attendance,
                COALESCE(
                    GROUP_CONCAT(
                        DISTINCT CONCAT(
                            fg.food_group_name,
                            ' — ',
                            fi.food_item_name,
                            CASE
                                WHEN fri.measurement_text IS NOT NULL AND TRIM(fri.measurement_text) != ''
                                    THEN CONCAT(' (', fri.measurement_text, ')')
                                ELSE ''
                            END
                        )
                        ORDER BY fri.feeding_item_id
                        SEPARATOR '||'
                    ),
                    '-'
                ) AS food_details,
                COALESCE(fr.remarks, '-') AS remarks
            FROM feeding_records fr
            INNER JOIN children c ON fr.child_id = c.child_id
            LEFT JOIN feeding_record_items fri ON fr.feeding_record_id = fri.feeding_record_id
            LEFT JOIN food_groups fg ON fri.food_group_id = fg.food_group_id
            LEFT JOIN food_items fi ON fri.food_item_id = fi.food_item_id
            WHERE c.cdc_id = ?";

    $types = "i";
    $params = [$cdc_id];

    if($date_from !== ''){
        $sql .= " AND DATE(fr.feeding_date) >= ?";
        $types .= "s";
        $params[] = $date_from;
    }

    if($date_to !== ''){
        $sql .= " AND DATE(fr.feeding_date) <= ?";
        $types .= "s";
        $params[] = $date_to;
    }

    $sql .= " GROUP BY
                fr.feeding_record_id,
                fr.feeding_date,
                c.first_name,
                c.last_name,
                fr.attendance,
                fr.remarks
              ORDER BY fr.feeding_date DESC, c.last_name ASC, c.first_name ASC";

    $stmt = $conn->prepare($sql);

    if($stmt){
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        while($row = $result->fetch_assoc()){
            $rows[] = $row;

            $feeding_date_key = date('Y-m-d', strtotime($row['feeding_date']));
            $grouped_rows[$feeding_date_key][] = $row;

            $total_records++;

            if(strtolower(trim($row['attendance'])) === 'present'){
                $present_count++;
            } else {
                $absent_count++;
            }
        }

        $stmt->close();
    } else {
        $error = "Failed to prepare Feeding Attendance query: " . $conn->error;
    }

    if($total_records > 0){
        $attendance_rate = round(($present_count / $total_records) * 100, 1);
    }

    return [
        'error' => $error,
        'rows' => $rows,
        'grouped_rows' => $grouped_rows,
        'total_records' => $total_records,
        'present_count' => $present_count,
        'absent_count' => $absent_count,
        'attendance_rate' => $attendance_rate
    ];
}

/* =========================================================
   SUBMIT REPORT
========================================================= */
if(isset($_POST['submit_report'])){
    $date_from_post = isset($_POST['date_from']) ? trim($_POST['date_from']) : '';
    $date_to_post = isset($_POST['date_to']) ? trim($_POST['date_to']) : '';

    $report_data = fetch_feeding_attendance_rows($conn, $cdc_id, $date_from_post, $date_to_post);

    if($report_data['error'] !== ''){
        $error = $report_data['error'];
    } elseif(empty($report_data['rows'])){
        $error = "No feeding attendance records found to submit.";
    } else {
        mysqli_begin_transaction($conn);

        try{
            $payload_rows = [];

            foreach($report_data['rows'] as $row){
                $payload_rows[] = [
                    'feeding_record_id' => (int)$row['feeding_record_id'],
                    'feeding_date' => $row['feeding_date'],
                    'child_name' => $row['child_name'],
                    'attendance' => $row['attendance'],
                    'food_details' => $row['food_details'],
                    'remarks' => $row['remarks']
                ];
            }

            $report_payload = json_encode([
                'report_type' => 'feeding_attendance',
                'cdc_id' => $cdc_id,
                'cdc_name' => $cdc_name,
                'prepared_by' => $prepared_by,
                'date_from' => $date_from_post !== '' ? $date_from_post : null,
                'date_to' => $date_to_post !== '' ? $date_to_post : null,
                'date_range_display' => get_date_range_display($date_from_post, $date_to_post),
                'total_records' => $report_data['total_records'],
                'present_count' => $report_data['present_count'],
                'absent_count' => $report_data['absent_count'],
                'attendance_rate' => $report_data['attendance_rate'],
                'submitted_rows' => $payload_rows
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

            $report_type = 'feeding_attendance';
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
            $success = "Feeding Attendance Report submitted successfully and is now visible to CSWD.";
        } catch(Exception $e){
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }

    $date_from = $date_from_post;
    $date_to = $date_to_post;
}

/* =========================================================
   FETCH DATA
========================================================= */
$data = fetch_feeding_attendance_rows($conn, $cdc_id, $date_from, $date_to);

if($data['error'] !== ''){
    $error = $data['error'];
} else {
    $rows = $data['rows'];
    $grouped_rows = $data['grouped_rows'];
    $total_records = $data['total_records'];
    $present_count = $data['present_count'];
    $absent_count = $data['absent_count'];
    $attendance_rate = $data['attendance_rate'];
}

$date_range_display = get_date_range_display($date_from, $date_to);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feeding Attendance Report | NutriTrack</title>
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
            line-height:1.8;
        }

        .content-card{
            background:#ffffff;
            border:1px solid #dcdcdc;
            border-radius:14px;
            padding:20px;
        }

        .filter-grid{
            display:grid;
            grid-template-columns:repeat(2, 1fr);
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

        .meta-box{
            margin-bottom:18px;
            padding:14px 16px;
            background:var(--card-subtle);
            border:1px solid var(--border-color);
            border-radius:10px;
}

        .meta-grid{
            display:grid;
            grid-template-columns:repeat(2, 1fr);
            gap:10px 16px;
        }

        .meta-item{
            font-size:13px;
            color:var(--text-color);
            line-height:1.6;
        }

        .summary-grid{
            display:grid;
            grid-template-columns:repeat(4, 1fr);
            gap:14px;
            margin-bottom:18px;
        }

        .summary-card{
            background:var(--card-bg);
            border:1px solid var(--border-color);
            border-radius:12px;
            padding:16px;
}

        .summary-value{
            font-family:'Poppins', sans-serif;
            font-size:24px;
            font-weight:700;
            color:var(--text-color);
}

        .summary-label{
            font-size:12px;
            color:#777;
            margin-bottom:6px;
            font-weight:500;
        }

        .report-list{
            display:flex;
            flex-direction:column;
            gap:18px;
        }

        .date-group-card{
            border:1px solid #e3e3e3;
            border-radius:14px;
            background:#fafafa;
            overflow:hidden;
        }

        .date-group-header{
            background:#2E7D32;
            color:#fff;
            padding:14px 18px;
        }

        .date-group-title{
            font-family:'Poppins', sans-serif;
            font-size:18px;
            font-weight:700;
        }

        .table-wrapper{
            width:100%;
            overflow-x:auto;
        }

        table{
            width:100%;
            border-collapse:collapse;
            min-width:1000px;
        }

        th{
            background:#f4f6f8;
            color:#2f2f2f;
            text-align:left;
            padding:13px 12px;
            font-family:'Poppins', sans-serif;
            font-size:14px;
            border-bottom:1px solid #dddddd;
            white-space:nowrap;
        }

        td{
            padding:13px 12px;
            border-bottom:1px solid #eeeeee;
            font-size:13px;
            vertical-align:top;
            background:#fff;
        }

       
            table th:nth-child(2),
            table td:nth-child(2),
            table th:nth-child(4),
            table td:nth-child(4){
                text-align:center;
                vertical-align:middle;
            }

        tbody tr:hover{
            background:#f8fbf8;
        }

        .badge{
            display:inline-block;
            padding:4px 10px;
            border-radius:20px;
            font-size:12px;
            font-weight:600;
        }

        .badge-present{
            background:#e8f5e9;
            color:#2e7d32;
        }

        .badge-absent{
            background:#fdecea;
            color:#c62828;
        }

        .food-details-list{
            margin:0;
            padding-left:18px;
        }

        .food-details-list li{
            margin-bottom:4px;
            line-height:1.5;
        }

        .food-details-list li:last-child{
            margin-bottom:0;
        }

        .no-data{
            padding:14px 2px 0;
            font-size:13px;
            color:#777;
        }

        .footer-row{
            display:flex;
            justify-content:flex-end;
            align-items:center;
            gap:12px;
            margin-top:18px;
            flex-wrap:wrap;
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

            .meta-grid{
                grid-template-columns:1fr;
            }

            .summary-grid{
                grid-template-columns:1fr 1fr;
            }
        }

        @media (max-width: 768px){
            .filter-grid{
                grid-template-columns:1fr;
            }

            .summary-grid{
                grid-template-columns:1fr;
            }
        }

        /* =========================
   PRINT / SAVE AS PDF
========================= */
/* =========================
   PRINT / SAVE AS PDF
========================= */
@media print {
    @page {
        size: A4 landscape;
        margin: 10mm;
    }

    body{
        background:#ffffff !important;
        color:#000000 !important;
        font-family:Arial, sans-serif !important;
        margin:0 !important;
        padding:0 !important;
    }

    .topbar,
    .sidebar,
    .sidebar-overlay,
    .back-link,
    .button-group,
    .footer-row,
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
        padding:0 0 12px 0 !important;
        margin:0 0 12px 0 !important;
        background:#ffffff !important;
        text-align:center !important;
        border-bottom:1px solid #000000 !important;
    }

    .page-title{
        color:#000000 !important;
        font-size:20px !important;
        text-align:center !important;
        margin-bottom:6px !important;
    }

    .page-subtitle{
        color:#000000 !important;
        text-align:center !important;
        font-size:11px !important;
        line-height:1.4 !important;
    }

    .content-card{
        border:none !important;
        padding:0 !important;
        background:#ffffff !important;
    }

    /* REPORT DETAILS */
    .meta-box{
        width:100% !important;
        border:none !important;
        border-bottom:1px solid #000000 !important;
        background:#ffffff !important;
        border-radius:0 !important;
        padding:8px 0 10px 0 !important;
        margin:0 0 10px 0 !important;
    }

    .meta-grid{
        display:grid !important;
        grid-template-columns:1fr 1fr !important;
        column-gap:40px !important;
        row-gap:6px !important;
        width:90% !important;
        margin:0 auto !important;
        align-items:start !important;
    }

    .meta-item{
        color:#000000 !important;
        font-size:10px !important;
        line-height:1.35 !important;
        text-align:left !important;
        white-space:normal !important;
        word-break:normal !important;
    }

    .meta-item strong{
        font-weight:bold !important;
    }

    /* SUMMARY CARDS */
    .summary-grid{
        display:grid !important;
        grid-template-columns:repeat(4, 1fr) !important;
        gap:6px !important;
        margin-bottom:8px !important;
    }

    .summary-card{
        border:1px solid #000000 !important;
        background:#ffffff !important;
        border-radius:0 !important;
        padding:6px 7px !important;
    }

    .summary-label{
        color:#000000 !important;
        font-size:9px !important;
        line-height:1.2 !important;
    }

    .summary-value{
        color:#000000 !important;
        font-size:13px !important;
        line-height:1.2 !important;
        font-weight:bold !important;
    }

    .report-list{
        gap:8px !important;
    }

    .date-group-card{
        border:1px solid #000000 !important;
        border-radius:0 !important;
        background:#ffffff !important;
        overflow:visible !important;
        page-break-inside:auto !important;
    }

    .date-group-header{
        background:#ffffff !important;
        color:#000000 !important;
        border-bottom:1px solid #000000 !important;
        padding:5px 7px !important;
    }

    .date-group-title{
        color:#000000 !important;
        font-size:11px !important;
    }

    .table-wrapper{
        overflow:visible !important;
        width:100% !important;
    }

    table{
        width:100% !important;
        min-width:0 !important;
        border-collapse:collapse !important;
        table-layout:fixed !important;
    }

    th,
    td{
        border:1px solid #000000 !important;
        padding:4px 5px !important;
        color:#000000 !important;
        background:#ffffff !important;
        font-size:8px !important;
        line-height:1.2 !important;
        vertical-align:top !important;
        word-break:break-word !important;
        white-space:normal !important;
    }

    th{
        font-weight:bold !important;
        text-align:center !important;
    }

    table th:nth-child(2),
    table td:nth-child(2),
    table th:nth-child(4),
    table td:nth-child(4){
        text-align:center !important;
        vertical-align:middle !important;
    }

    tbody tr:hover{
        background:#ffffff !important;
    }

    .badge{
        background:#ffffff !important;
        color:#000000 !important;
        border-radius:0 !important;
        padding:0 !important;
        font-size:8px !important;
        font-weight:normal !important;
    }

    .food-details-list{
        margin:0 !important;
        padding-left:12px !important;
    }

    .food-details-list li{
        margin-bottom:2px !important;
        line-height:1.2 !important;
    }

    .no-data{
        color:#000000 !important;
        font-size:11px !important;
    }
}

    </style>
</head>
<?php include __DIR__ . '/../includes/auth.php'; ?>
<body class="<?php echo themeClass(); ?>">

<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-header">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        <h1 class="page-title">Feeding Attendance Report</h1>
        <div class="page-subtitle">
            View feeding attendance records of children under the active CDC.
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
                </div>

                <button type="button" class="btn btn-print" onclick="window.print()">
                    Print / Save as PDF
                </button>
            </div>
        </form>

        <div class="meta-box">
            <div class="meta-grid">
                <div class="meta-item"><strong>CDC:</strong> <?php echo htmlspecialchars($cdc_name); ?></div>
                <div class="meta-item"><strong>Date Range:</strong> <?php echo htmlspecialchars($date_range_display); ?></div>
                <div class="meta-item"><strong>Prepared by:</strong> <?php echo htmlspecialchars($prepared_by); ?></div>
                <div class="meta-item"><strong>Date Generated:</strong> <?php echo date("F d, Y"); ?></div>
            </div>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-label">Total Records</div>
                <div class="summary-value"><?php echo $total_records; ?></div>
            </div>

            <div class="summary-card">
                <div class="summary-label">Present</div>
                <div class="summary-value"><?php echo $present_count; ?></div>
            </div>

            <div class="summary-card">
                <div class="summary-label">Absent</div>
                <div class="summary-value"><?php echo $absent_count; ?></div>
            </div>

            <div class="summary-card">
                <div class="summary-label">Attendance Rate</div>
                <div class="summary-value"><?php echo $attendance_rate; ?>%</div>
            </div>
        </div>

        <?php if(!empty($grouped_rows)){ ?>
            <div class="report-list">
                <?php foreach($grouped_rows as $feeding_date => $items){ ?>
                    <div class="date-group-card">
                        <div class="date-group-header">
                            <div class="date-group-title">
                                Feeding Date: <?php echo date("F d, Y", strtotime($feeding_date)); ?>
                            </div>
                        </div>

                        <div class="table-wrapper">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Child Name</th>
                                        <th>Attendance</th>
                                        <th>Food Details</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($items as $row){ ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['child_name']); ?></td>
                                            <td>
                                                <?php if(strtolower(trim($row['attendance'])) === 'present'){ ?>
                                                    <span class="badge badge-present">Present</span>
                                                <?php } else { ?>
                                                    <span class="badge badge-absent">Absent</span>
                                                <?php } ?>
                                            </td>
                                            <td>
                                                <?php
                                                $food_details = safe_display($row['food_details']);
                                                if($food_details !== 'N/A' && $food_details !== '-'){
                                                    $detail_lines = explode('||', $food_details);
                                                ?>
                                                    <ul class="food-details-list">
                                                        <?php foreach($detail_lines as $line){ ?>
                                                            <li><?php echo htmlspecialchars(trim($line)); ?></li>
                                                        <?php } ?>
                                                    </ul>
                                                <?php } else { ?>
                                                    <?php echo htmlspecialchars($food_details); ?>
                                                <?php } ?>
                                            </td>
                                            <td><?php echo htmlspecialchars(safe_display($row['remarks'])); ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php } ?>
            </div>

            <form method="POST">
                <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">

                <div class="footer-row no-print">
                <button type="submit" name="submit_report" class="btn btn-submit">Submit Report</button>
            </div>
            </form>
        <?php } else { ?>
            <p class="no-data">No feeding attendance records found.</p>
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