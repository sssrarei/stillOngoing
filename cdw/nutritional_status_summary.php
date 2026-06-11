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

$selected_month = isset($_GET['month']) ? trim($_GET['month']) : date('Y-m');
$error = "";
$success = "";

$total = 0;
$normal = 0;
$underweight = 0;
$severely_underweight = 0;
$overweight = 0;
$obese = 0;
$stunted = 0;
$severely_stunted = 0;
$moderately_wasted = 0;
$severely_wasted = 0;

function getFinalNutritionalStatus($wfa_status, $hfa_status, $wflh_status) {
    $wfa_status = trim((string)$wfa_status);
    $hfa_status = trim((string)$hfa_status);
    $wflh_status = trim((string)$wflh_status);

    if ($wflh_status === 'Severely Wasted') {
        return 'Severely Wasted';
    }

    if ($wfa_status === 'Severely Underweight') {
        return 'Severely Underweight';
    }

    if ($wflh_status === 'Obese') {
        return 'Obese';
    }

    if ($wflh_status === 'Moderately Wasted' || $wflh_status === 'Wasted') {
        return 'Moderately Wasted';
    }

    if ($wfa_status === 'Underweight') {
        return 'Underweight';
    }

    if ($wflh_status === 'Overweight') {
        return 'Overweight';
    }

    if ($hfa_status === 'Severely Stunted') {
        return 'Severely Stunted';
    }

    if ($hfa_status === 'Stunted') {
        return 'Stunted';
    }

    return 'Normal';
}

function percent($value, $total){
    if($total <= 0){
        return 0;
    }
    return round(($value / $total) * 100, 1);
}

function build_nutritional_summary_data($conn, $cdc_id, $selected_month){
    $error = "";

    $total = 0;
    $normal = 0;
    $underweight = 0;
    $severely_underweight = 0;
    $overweight = 0;
    $obese = 0;
    $stunted = 0;
    $severely_stunted = 0;
    $moderately_wasted = 0;
    $severely_wasted = 0;

    $month_start = $selected_month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $reporting_month_display = date('F Y', strtotime($month_start));

    $total_sql = "SELECT COUNT(*) AS total_children
                  FROM children
                  WHERE cdc_id = ?";

    $total_stmt = $conn->prepare($total_sql);
    if($total_stmt){
        $total_stmt->bind_param("i", $cdc_id);
        $total_stmt->execute();
        $total_result = $total_stmt->get_result();
        if($total_row = $total_result->fetch_assoc()){
            $total = (int)$total_row['total_children'];
        }
        $total_stmt->close();
    } else {
        $error = "Failed to prepare total count query.";
    }
//
    if(empty($error)){
    $summary_sql = "
        SELECT
            ar.child_id,
            ar.wfa_status,
            ar.hfa_status,
            ar.wflh_status
        FROM anthropometric_records ar
        INNER JOIN children c 
            ON ar.child_id = c.child_id

        INNER JOIN (
            SELECT 
                ar2.child_id, 
                MAX(ar2.date_recorded) AS latest_date
            FROM anthropometric_records ar2
            INNER JOIN children c2 
                ON ar2.child_id = c2.child_id
            WHERE c2.cdc_id = ?
              AND c2.is_deleted = 0
              AND ar2.is_deleted = 0
            GROUP BY ar2.child_id
        ) latest
            ON ar.child_id = latest.child_id
            AND ar.date_recorded = latest.latest_date

        INNER JOIN (
            SELECT 
                ar3.child_id, 
                ar3.date_recorded, 
                MAX(ar3.record_id) AS latest_record_id
            FROM anthropometric_records ar3
            INNER JOIN children c3 
                ON ar3.child_id = c3.child_id
            WHERE c3.cdc_id = ?
              AND c3.is_deleted = 0
              AND ar3.is_deleted = 0
            GROUP BY ar3.child_id, ar3.date_recorded
        ) latest_id
            ON ar.child_id = latest_id.child_id
            AND ar.date_recorded = latest_id.date_recorded
            AND ar.record_id = latest_id.latest_record_id

        WHERE c.cdc_id = ?
          AND c.is_deleted = 0
          AND ar.is_deleted = 0
    ";

    $summary_stmt = $conn->prepare($summary_sql);

    if($summary_stmt){
        $summary_stmt->bind_param(
            "iii",
            $cdc_id,
            $cdc_id,
            $cdc_id
        );

        $summary_stmt->execute();
        $summary_result = $summary_stmt->get_result();

        while($row = $summary_result->fetch_assoc()){
            $final_status = getFinalNutritionalStatus(
                $row['wfa_status'] ?? '',
                $row['hfa_status'] ?? '',
                $row['wflh_status'] ?? ''
            );

            if ($final_status === 'Normal') {
                $normal++;
            } elseif ($final_status === 'Underweight') {
                $underweight++;
            } elseif ($final_status === 'Severely Underweight') {
                $severely_underweight++;
            } elseif ($final_status === 'Overweight') {
                $overweight++;
            } elseif ($final_status === 'Obese') {
                $obese++;
            } elseif ($final_status === 'Stunted') {
                $stunted++;
            } elseif ($final_status === 'Severely Stunted') {
                $severely_stunted++;
            } elseif ($final_status === 'Moderately Wasted') {
                $moderately_wasted++;
            } elseif ($final_status === 'Severely Wasted') {
                $severely_wasted++;
            }
        }

        $summary_stmt->close();
    } else {
        $error = "Failed to prepare nutritional summary query.";
    }
}

//

    $normal_pct = percent($normal, $total);
    $underweight_pct = percent($underweight, $total);
    $severely_underweight_pct = percent($severely_underweight, $total);
    $overweight_pct = percent($overweight, $total);
    $obese_pct = percent($obese, $total);
    $stunted_pct = percent($stunted, $total);
    $severely_stunted_pct = percent($severely_stunted, $total);
    $moderately_wasted_pct = percent($moderately_wasted, $total);
    $severely_wasted_pct = percent($severely_wasted, $total);

    $uw_combined = $underweight + $severely_underweight;
    $uw_combined_pct = percent($uw_combined, $total);

    $summary_text = "No nutritional records found up to the selected month.";

    if(($normal + $underweight + $severely_underweight + $overweight + $obese + $stunted + $severely_stunted + $moderately_wasted + $severely_wasted) > 0){
        $summary_text = "Most children are in Normal status (" . $normal_pct . "%).";
        if($uw_combined > 0){
            $summary_text .= " " . $uw_combined_pct . "% are underweight or severely underweight.";
        }
    }

    $concern_statuses = [
        "Underweight" => $underweight,
        "Severely Underweight" => $severely_underweight,
        "Stunted" => $stunted,
        "Severely Stunted" => $severely_stunted,
        "Moderately Wasted" => $moderately_wasted,
        "Severely Wasted" => $severely_wasted,
        "Overweight" => $overweight,
        "Obese" => $obese
    ];

    $highest_label = "None";
    $highest_value = 0;

    foreach($concern_statuses as $label => $value){
        if($value > $highest_value){
            $highest_value = $value;
            $highest_label = $label;
        }
    }

    $highest_pct = percent($highest_value, $total);

    return [
        'error' => $error,
        'month_start' => $month_start,
        'month_end' => $month_end,
        'reporting_month_display' => $reporting_month_display,
        'total' => $total,
        'normal' => $normal,
        'underweight' => $underweight,
        'severely_underweight' => $severely_underweight,
        'overweight' => $overweight,
        'obese' => $obese,
        'stunted' => $stunted,
        'severely_stunted' => $severely_stunted,
        'moderately_wasted' => $moderately_wasted,
        'severely_wasted' => $severely_wasted,
        'normal_pct' => $normal_pct,
        'underweight_pct' => $underweight_pct,
        'severely_underweight_pct' => $severely_underweight_pct,
        'overweight_pct' => $overweight_pct,
        'obese_pct' => $obese_pct,
        'stunted_pct' => $stunted_pct,
        'severely_stunted_pct' => $severely_stunted_pct,
        'moderately_wasted_pct' => $moderately_wasted_pct,
        'severely_wasted_pct' => $severely_wasted_pct,
        'summary_text' => $summary_text,
        'highest_label' => $highest_label,
        'highest_value' => $highest_value,
        'highest_pct' => $highest_pct
    ];
}

/* =========================================================
   SUBMIT REPORT
========================================================= */
if(isset($_POST['submit_report'])){
    $selected_month_post = isset($_POST['month']) ? trim($_POST['month']) : date('Y-m');

    $report_data = build_nutritional_summary_data($conn, $cdc_id, $selected_month_post);

    if($report_data['error'] !== ''){
        $error = $report_data['error'];
    } else {
        mysqli_begin_transaction($conn);

        try{
            $payload_rows = [
                [
                    'total' => $report_data['total'],
                    'normal' => $report_data['normal'],
                    'normal_pct' => $report_data['normal_pct'],
                    'underweight' => $report_data['underweight'],
                    'underweight_pct' => $report_data['underweight_pct'],
                    'severely_underweight' => $report_data['severely_underweight'],
                    'severely_underweight_pct' => $report_data['severely_underweight_pct'],
                    'overweight' => $report_data['overweight'],
                    'overweight_pct' => $report_data['overweight_pct'],
                    'obese' => $report_data['obese'],
                    'obese_pct' => $report_data['obese_pct'],
                    'stunted' => $report_data['stunted'],
                    'stunted_pct' => $report_data['stunted_pct'],
                    'severely_stunted' => $report_data['severely_stunted'],
                    'severely_stunted_pct' => $report_data['severely_stunted_pct'],
                    'moderately_wasted' => $report_data['moderately_wasted'],
                    'moderately_wasted_pct' => $report_data['moderately_wasted_pct'],
                    'severely_wasted' => $report_data['severely_wasted'],
                    'severely_wasted_pct' => $report_data['severely_wasted_pct']
                ]
            ];

            $report_payload = json_encode([
                'report_type' => 'nutritional_status_summary',
                'cdc_id' => $cdc_id,
                'cdc_name' => $cdc_name,
                'prepared_by' => $prepared_by,
                'reporting_month' => $selected_month_post,
                'reporting_month_display' => $report_data['reporting_month_display'],
                'summary_text' => $report_data['summary_text'],
                'highest_label' => $report_data['highest_label'],
                'highest_value' => $report_data['highest_value'],
                'highest_pct' => $report_data['highest_pct'],
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

            $report_type = 'nutritional_status_summary';
            $status = 'submitted';
            $date_from_insert = $report_data['month_start'];
            $date_to_insert = $report_data['month_end'];

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
            $success = "Nutritional Status Summary submitted successfully and is now visible to CSWD.";
        } catch(Exception $e){
            mysqli_rollback($conn);
            $error = $e->getMessage();
        }
    }

    $selected_month = $selected_month_post;
}

/* =========================================================
   FETCH DATA
========================================================= */
$data = build_nutritional_summary_data($conn, $cdc_id, $selected_month);

if($data['error'] !== ''){
    $error = $data['error'];
} else {
    $total = $data['total'];
    $normal = $data['normal'];
    $underweight = $data['underweight'];
    $severely_underweight = $data['severely_underweight'];
    $overweight = $data['overweight'];
    $obese = $data['obese'];
    $stunted = $data['stunted'];
    $severely_stunted = $data['severely_stunted'];
    $moderately_wasted = $data['moderately_wasted'];
    $severely_wasted = $data['severely_wasted'];

    $normal_pct = $data['normal_pct'];
    $underweight_pct = $data['underweight_pct'];
    $severely_underweight_pct = $data['severely_underweight_pct'];
    $overweight_pct = $data['overweight_pct'];
    $obese_pct = $data['obese_pct'];
    $stunted_pct = $data['stunted_pct'];
    $severely_stunted_pct = $data['severely_stunted_pct'];
    $moderately_wasted_pct = $data['moderately_wasted_pct'];
    $severely_wasted_pct = $data['severely_wasted_pct'];

    $summary_text = $data['summary_text'];
    $highest_label = $data['highest_label'];
    $highest_value = $data['highest_value'];
    $highest_pct = $data['highest_pct'];

    $month_start = $data['month_start'];
    $month_end = $data['month_end'];
    $reporting_month_display = $data['reporting_month_display'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nutritional Status Summary | NutriTrack</title>
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
    background:var(--page-bg);
    color:var(--text-color);
}

/* LINKS */
a{
    text-decoration:none;
}

/* MAIN LAYOUT */
.main-content{
    margin-left:260px;
    padding:112px 24px 30px;
    transition:margin-left 0.25s ease;
}

.main-content.full{
    margin-left:0;
}

/* PAGE HEADER */
.page-header{
    background:var(--card-bg);
    color:var(--text-color);
    border:1px solid var(--border-color);
    padding:22px 24px;
    margin-bottom:18px;
}

/* BACK LINK */
.back-link{
    display:inline-flex;
    align-items:center;
    gap:8px;
    margin-bottom:10px;
    font-size:13px;
    font-weight:600;
    color:var(--accent);
}

/* TITLES */
.page-title{
    font-family:'Poppins', sans-serif;
    font-size:24px;
    font-weight:700;
    color:var(--text-color);
    margin-bottom:6px;
}

.page-subtitle{
    font-size:13px;
    color:var(--muted-text);
    line-height:1.8;
}

/* CARD */
.content-card{
    background:var(--card-bg);
    border:1px solid var(--border-color);
    border-radius:14px;
    padding:20px;
}

/* FILTER */
.filter-row{
    display:flex;
    gap:12px;
    align-items:end;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.form-group{
    min-width:220px;
}

.form-group label{
    display:block;
    font-size:12px;
    color:var(--muted-text);
    margin-bottom:6px;
    font-weight:500;
}

/* INPUT */
.form-control{
    width:100%;
    border:1px solid var(--border-color);
    border-radius:8px;
    padding:11px 12px;
    font-size:13px;
    font-family:'Inter', sans-serif;
    background:var(--card-bg);
    color:var(--text-color);
    outline:none;
}

.form-control:focus{
    border-color:#2E7D32;
    box-shadow:0 0 0 3px rgba(46,125,50,0.08);
}

/* BUTTONS */
.button-group{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
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
}

.btn-generate{
    background:#2E7D32;
    color:#fff;
}

.btn-submit{
    background:var(--btn-primary);
    color:#fff;
}

.btn-print{
    background:#16a34a;
    color:#ffffff;
}

.btn-print:hover{
    background:#15803d;
}

.footer-actions{
    display:flex;
    gap:12px;
    align-items:center;
    flex-wrap:wrap;
}

/* MESSAGES */
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

/* META BOX */
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

/* SUMMARY / CONCERN */
.summary-box,
.concern-box{
    margin-bottom:16px;
    padding:14px 16px;
    border-radius:10px;
    border:1px solid var(--border-color);
    background:var(--card-bg);
}

.summary-title,
.concern-title{
    font-family:'Poppins', sans-serif;
    font-size:16px;
    font-weight:700;
    color:var(--text-color);
    margin-bottom:6px;
}

.summary-text,
.concern-text{
    font-size:14px;
    line-height:1.6;
    color:var(--text-color);
}

/* TABLE */
.table-wrapper{
    width:100%;
    overflow-x:auto;
    border:1px solid var(--border-color);
    border-radius:12px;
    margin-bottom:18px;
}

table{
    width:100%;
    border-collapse:collapse;
    min-width:1200px;
}

/* HEADER */
th{
    background:#2E7D32;
    color:#fff;
    text-align:center;
    padding:13px 12px;
    font-family:'Poppins', sans-serif;
    font-size:14px;
}

/* CELLS */
td{
    padding:13px 12px;
    border-bottom:1px solid var(--border-color);
    border-right:1px solid var(--border-color);
    font-size:13px;
    text-align:center;
    vertical-align:middle;
    background:var(--card-bg);
    color:var(--text-color);
}

td:last-child,
th:last-child{
    border-right:none;
}

/* STATUS */
.status-value{
    display:block;
    font-weight:700;
    color:var(--text-color);
    margin-bottom:4px;
}

.status-pct{
    display:block;
    font-size:12px;
    color:var(--muted-text);
}

/* FOOTER */
.footer-row{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    flex-wrap:wrap;
}

.date-generated{
    font-size:13px;
    color:var(--muted-text);
}

/* RESPONSIVE */
@media (max-width: 991px){

    .main-content{
        margin-left:0;
        padding:104px 16px 24px;
    }

    .meta-grid{
        grid-template-columns:1fr;
    }
}

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
        border-bottom:1px solid #000000 !important;
        padding:0 0 12px 0 !important;
        margin:0 0 12px 0 !important;
        background:#ffffff !important;
        text-align:center !important;
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

    .summary-box,
    .concern-box{
        border:1px solid #000000 !important;
        background:#ffffff !important;
        border-radius:0 !important;
        padding:8px 10px !important;
        margin-bottom:8px !important;
    }

    .summary-title,
    .concern-title{
        color:#000000 !important;
        font-size:11px !important;
        margin-bottom:3px !important;
    }

    .summary-text,
    .concern-text{
        color:#000000 !important;
        font-size:10px !important;
        line-height:1.35 !important;
    }

    .table-wrapper{
        overflow:visible !important;
        width:100% !important;
        border:none !important;
        border-radius:0 !important;
        margin-bottom:0 !important;
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
        padding:5px 6px !important;
        color:#000000 !important;
        background:#ffffff !important;
        font-size:8px !important;
        line-height:1.2 !important;
        text-align:center !important;
        vertical-align:middle !important;
        word-break:break-word !important;
        white-space:normal !important;
    }

    th{
        font-weight:bold !important;
    }

    .status-value,
    .status-pct{
        color:#000000 !important;
        font-size:8px !important;
        line-height:1.2 !important;
    }

    .status-value{
        font-weight:bold !important;
    }

    .empty-state{
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
        <h1 class="page-title">Nutritional Status Summary</h1>
        <div class="page-subtitle">
            View the nutritional status summary of children under the active CDC using the latest record up to the selected month.
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
            <div class="filter-row">
                <div class="form-group">
                    <label>Reporting Month</label>
                    <input type="month" name="month" class="form-control" value="<?php echo htmlspecialchars($selected_month); ?>">
                </div>
            </div>

            <div class="button-group">
                <button type="submit" class="btn btn-generate">Generate Report</button>
            </div>
        </form>

        <div class="meta-box">
            <div class="meta-grid">
                <div class="meta-item"><strong>CDC:</strong> <?php echo htmlspecialchars($cdc_name); ?></div>
                <div class="meta-item"><strong>Reporting Month:</strong> <?php echo htmlspecialchars($reporting_month_display); ?></div>
                <div class="meta-item"><strong>Prepared by:</strong> <?php echo htmlspecialchars($prepared_by); ?></div>
                <div class="meta-item"><strong>Date Generated:</strong> <?php echo date("F d, Y"); ?></div>
            </div>
        </div>

        <div class="summary-box">
            <div class="summary-title">Summary</div>
            <div class="summary-text"><?php echo htmlspecialchars($summary_text); ?></div>
        </div>

        <div class="concern-box">
            <div class="concern-title">Highest Concern</div>
            <div class="concern-text">
                <?php
                if($highest_value > 0){
                    echo htmlspecialchars($highest_label) . " (" . $highest_pct . "%)";
                } else {
                    echo "No major concern identified up to the selected month.";
                }
                ?>
            </div>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Total</th>
                        <th>Normal</th>
                        <th>Underweight</th>
                        <th>Severely Underweight</th>
                        <th>Overweight</th>
                        <th>Obese</th>
                        <th>Stunted</th>
                        <th>Severely Stunted</th>
                        <th>Moderately Wasted</th>
                        <th>Severely Wasted</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <span class="status-value"><?php echo $total; ?></span>
                            <span class="status-pct">100%</span>
                        </td>
                        <td>
                            <span class="status-value"><?php echo $normal; ?></span>
                            <span class="status-pct"><?php echo $normal_pct; ?>%</span>
                        </td>
                        <td>
                            <span class="status-value"><?php echo $underweight; ?></span>
                            <span class="status-pct"><?php echo $underweight_pct; ?>%</span>
                        </td>
                        <td>
                            <span class="status-value"><?php echo $severely_underweight; ?></span>
                            <span class="status-pct"><?php echo $severely_underweight_pct; ?>%</span>
                        </td>
                        <td>
                            <span class="status-value"><?php echo $overweight; ?></span>
                            <span class="status-pct"><?php echo $overweight_pct; ?>%</span>
                        </td>
                        <td>
                            <span class="status-value"><?php echo $obese; ?></span>
                            <span class="status-pct"><?php echo $obese_pct; ?>%</span>
                        </td>
                        <td>
                            <span class="status-value"><?php echo $stunted; ?></span>
                            <span class="status-pct"><?php echo $stunted_pct; ?>%</span>
                        </td>
                        <td>
                            <span class="status-value"><?php echo $severely_stunted; ?></span>
                            <span class="status-pct"><?php echo $severely_stunted_pct; ?>%</span>
                        </td>
                        <td>
                            <span class="status-value"><?php echo $moderately_wasted; ?></span>
                            <span class="status-pct"><?php echo $moderately_wasted_pct; ?>%</span>
                        </td>
                        <td>
                            <span class="status-value"><?php echo $severely_wasted; ?></span>
                            <span class="status-pct"><?php echo $severely_wasted_pct; ?>%</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <form method="POST">
            <input type="hidden" name="month" value="<?php echo htmlspecialchars($selected_month); ?>">

            <div class="footer-row no-print">
    <div class="date-generated">Date Generated: <?php echo date("F d, Y"); ?></div>

    <div class="footer-actions">
        <button type="submit" name="submit_report" class="btn btn-submit">Submit Report</button>

        <button type="button" class="btn btn-print" onclick="window.print()">
            Print / Save as PDF
        </button>
    </div>
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