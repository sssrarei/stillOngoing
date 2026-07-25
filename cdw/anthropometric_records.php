<?php
include '../includes/auth.php';
include '../config/database.php';
include '../includes/nnc_growth_standards.php';

if($_SESSION['role_id'] != 2){
    header("Location: ../login.php");
    exit();
}

if(!isset($_SESSION['active_cdc_id'])){
    die("Please select an active CDC first from the dashboard.");
}

$active_cdc_id = (int) $_SESSION['active_cdc_id'];
$user_id = (int) $_SESSION['user_id'];
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$child_id = isset($_GET['child_id']) ? (int) $_GET['child_id'] : 0;

$per_page = 10;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if($page < 1){
    $page = 1;
}
$offset = ($page - 1) * $per_page;
$edit_id = isset($_GET['edit_id']) ? (int) $_GET['edit_id'] : 0;
$view_only = (isset($_GET['view']) && $_GET['view'] == '1') ? true : false;

$success = "";
$error = "";

$form_date_recorded = '';
$form_height = '';
$form_weight = '';
$form_muac = '';

$form_place_of_measurement = '';
$form_assessment_type = 'monthly_followup';

$form_edema_status = '';
$form_edema_grade = '';

/* =========================================================
   HELPERS
========================================================= */
function compute_age_months_exact($birthdate, $date_recorded){
    if(empty($birthdate) || empty($date_recorded)) return null;

    try{
        $birth = new DateTime($birthdate);
        $record = new DateTime($date_recorded);

        if($record < $birth){
            return null;
        }

        $diff = $birth->diff($record);
        return ($diff->y * 12) + $diff->m;
    } catch(Exception $e){
        return null;
    }
}

function compute_age_label($birthdate){
    if(empty($birthdate) || $birthdate == '0000-00-00'){
        return 'N/A';
    }

    try{
        $birth = new DateTime($birthdate);
        $today = new DateTime();
        $diff = $birth->diff($today);
        return $diff->y . " year(s) old";
    } catch(Exception $e){
        return 'N/A';
    }
}

function status_badge($status){
    if(empty($status) || $status == '--'){
        return '<span class="status-badge status-empty">--</span>';
    }

    $normalized = strtolower(trim($status));
    $class = 'status-alert';

    if(
        $normalized === 'normal' ||
        strpos($normalized, 'green zone') !== false
    ){
        $class = 'status-normal';
    }
    elseif(
        strpos($normalized, 'moderate') !== false ||
        strpos($normalized, 'yellow zone') !== false ||
        $normalized === 'underweight' ||
        $normalized === 'wasted' ||
        $normalized === 'stunted' ||
        $normalized === 'tall' ||
        $normalized === 'overweight'
    ){
        $class = 'status-warning';
    }
    elseif(
        strpos($normalized, 'severe') !== false ||
        strpos($normalized, 'red zone') !== false ||
        $normalized === 'obese'
    ){
        $class = 'status-alert';
    }

    return '<span class="status-badge ' . $class . '">' . htmlspecialchars($status) . '</span>';
}

function assessment_type_label($assessment_type){
    $assessment_type = strtolower(trim((string)$assessment_type));

    switch($assessment_type){
        case 'baseline':
            return 'Baseline';
        case 'monthly_followup':
            return 'Monthly Follow-Up';
        case 'midline':
            return 'Midline';
        case 'endline':
            return 'Endline';
        default:
            return 'N/A';
    }
}

function is_valid_assessment_type($assessment_type){
    $allowed = ['baseline', 'monthly_followup', 'midline', 'endline'];
    return in_array($assessment_type, $allowed, true);
}

/* =========================================================
   SAVE / UPDATE RECORD
========================================================= */
if(!$view_only && isset($_POST['save_record'])){
    $child_id_post = isset($_POST['child_id']) ? (int) $_POST['child_id'] : 0;
    $edit_record_id = isset($_POST['edit_record_id']) ? (int) $_POST['edit_record_id'] : 0;

    $date_recorded = trim($_POST['date_recorded']);
    $height = trim($_POST['height']);
    $weight = trim($_POST['weight']);
    $muac = trim($_POST['muac']);
    $edema_status = trim($_POST['edema_status']);
    $edema_grade = $_POST['edema_grade'] ?? null;
    $edema_grade = ($edema_status === 'Present') ? $edema_grade : null;
    $assessment_type = isset($_POST['assessment_type']) ? trim($_POST['assessment_type']) : '';

    $form_date_recorded = $date_recorded;
    $form_height = $height;
    $form_weight = $weight;
    $form_muac = $muac;
    $form_edema_status = $edema_status;
    $form_edema_grade = $edema_grade;
    $form_assessment_type = $assessment_type;

    $child_stmt = $conn->prepare("
        SELECT child_id, birthdate, sex, cdc_id
        FROM children
        WHERE child_id = ?
        AND cdc_id = ?
        AND is_deleted = 0
        LIMIT 1
    ");
    $child_stmt->bind_param("ii", $child_id_post, $active_cdc_id);
    $child_stmt->execute();
    $child_result = $child_stmt->get_result();

    if($child_result->num_rows == 0){
        $error = "Invalid child selected.";
    }
    elseif(
        empty($date_recorded) ||
        $height === '' ||
        $weight === '' ||
        $muac === '' ||
        empty($edema_status) ||
        empty($assessment_type)
    ){
        $error = "Please complete all required fields.";
    }
    elseif($edema_status === 'Present' && empty($edema_grade)){
        $error = "Please select edema grade.";
    }
    elseif(!is_valid_assessment_type($assessment_type)){
        $error = "Invalid assessment type selected.";
    }
    elseif(!is_numeric($height) || !is_numeric($weight) || !is_numeric($muac)){
        $error = "Height, weight, and MUAC must be numeric values.";
    }
    elseif((float)$height <= 0 || (float)$weight <= 0 || (float)$muac <= 0){
        $error = "Height, weight, and MUAC must be greater than zero.";
    }
    else {

        $child = $child_result->fetch_assoc();
        $sex = trim($child['sex']);
        $birthdate = $child['birthdate'];

        $age_months = compute_age_months_exact($birthdate, $date_recorded);

        if($age_months === null){
            $error = "Unable to compute age in months.";
        }
        else {

            $height_val = (float)$height;
            $weight_val = (float)$weight;
            $muac_val = (float)$muac;

            if($muac_val < 11.5){
                $muac_status = 'Severe Acute Malnutrition';
            }
            elseif($muac_val >= 11.5 && $muac_val < 12.5){
                $muac_status = 'Moderate Acute Malnutrition';
            }
            else{
                $muac_status = 'Normal';
            }

            /* =========================================================
               ASSESSMENT TYPE VALIDATION RULES
            ========================================================= */
            $check_existing_stmt = $conn->prepare("
                SELECT record_id, assessment_type, date_recorded
                FROM anthropometric_records
                WHERE child_id = ? 
            ");
            $check_existing_stmt->bind_param("i", $child_id_post);
            $check_existing_stmt->execute();
            $existing_records = $check_existing_stmt->get_result();

            $has_baseline = false;

            while($existing_row = $existing_records->fetch_assoc()){
                $existing_record_id = (int)$existing_row['record_id'];
                $existing_type = trim((string)$existing_row['assessment_type']);
                $existing_date = trim((string)$existing_row['date_recorded']);

                if($edit_record_id > 0 && $existing_record_id === $edit_record_id){
                    continue;
                }

                if($existing_type === 'baseline'){
                    $has_baseline = true;
                }

                if($existing_type === $assessment_type && $existing_date === $date_recorded){
                    $error = "Duplicate record: same assessment type and date already exists.";
                    break;
                }
            }

            if(empty($error)){
                if($assessment_type === 'baseline' && $has_baseline){
                    $error = "Only one baseline record is allowed per child.";
                } elseif($assessment_type === 'midline' && !$has_baseline){
                    $error = "Midline cannot be recorded without a baseline.";
                } elseif($assessment_type === 'endline' && !$has_baseline){
                    $error = "Endline cannot be recorded without a baseline.";
                }
            }

            if(empty($error)){
                $wfa_status = nnc_get_wfa_status($conn, $sex, $age_months, $weight_val);
                $hfa_status = nnc_get_hfa_status($conn, $sex, $age_months, $height_val);
                $wflh_status = nnc_get_wflh_status($conn, $sex, $age_months, $height_val, $weight_val);

                if($edit_record_id > 0){
                    $check_edit_stmt = $conn->prepare("
                        SELECT ar.record_id
                        FROM anthropometric_records ar
                        INNER JOIN children c ON ar.child_id = c.child_id
                        WHERE ar.record_id = ? AND ar.child_id = ? AND c.cdc_id = ?
                        LIMIT 1
                    ");
                    $check_edit_stmt->bind_param("iii", $edit_record_id, $child_id_post, $active_cdc_id);
                    $check_edit_stmt->execute();
                    $check_edit_result = $check_edit_stmt->get_result();

                    if($check_edit_result->num_rows == 0){
                        $error = "Invalid record selected for editing.";
                    } else {
                       $update_stmt = $conn->prepare("
    UPDATE anthropometric_records
    SET 
        height = ?, 
        weight = ?, 
        muac = ?, 
        edema_status = ?, 
        edema_grade = ?,
        muac_status = ?, 
        date_recorded = ?, 
        age_months = ?, 
        wfa_status = ?, 
        hfa_status = ?, 
        wflh_status = ?, 
        assessment_type = ?, 
        recorded_by = ?
    WHERE record_id = ? AND child_id = ?
");

                      $update_stmt->bind_param(
    "dddssssissssiii",
    $height_val,
    $weight_val,
    $muac_val,
    $edema_status,
    $edema_grade,
    $muac_status,
    $date_recorded,
    $age_months,
    $wfa_status,
    $hfa_status,
    $wflh_status,
    $assessment_type,
    $user_id,
    $edit_record_id,
    $child_id_post
);
                        if($update_stmt->execute()){
                            $success = "Anthropometric record updated successfully.";
                            $child_id = $child_id_post;
                            $edit_id = 0;
                            $form_date_recorded = '';
                            $form_height = '';
                            $form_weight = '';
                            $form_muac = '';
                            $form_edema_status = '';
                            $form_edema_grade = '';
                            $form_muac_status = '';
                            $form_assessment_type = 'monthly_followup';
                        } else {
                            $error = "Error updating record: " . $conn->error;
                            $child_id = $child_id_post;
                            $edit_id = $edit_record_id;
                        }
                    }
                } else {
                    $insert_stmt = $conn->prepare("
                        INSERT INTO anthropometric_records
                        (child_id, height, weight, muac, edema_status, edema_grade, muac_status, date_recorded, age_months, assessment_type, wfa_status, hfa_status, wflh_status, recorded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $insert_stmt->bind_param(
                        "idddssssissssi",
                        $child_id_post,
                        $height_val,
                        $weight_val,
                        $muac_val,
                        $edema_status,
                        $edema_grade,
                        $muac_status,
                        $date_recorded,
                        $age_months,
                        $assessment_type,
                        $wfa_status,
                        $hfa_status,
                        $wflh_status,
                        $user_id
                    );

                    if($insert_stmt->execute()){
                        $success = "Anthropometric record saved successfully.";
                        $child_id = $child_id_post;
                        $form_date_recorded = '';
                        $form_height = '';
                        $form_weight = '';
                        $form_muac = '';
                        $form_edema_status = '';
                        $form_edema_grade = '';
                        $form_muac_status = '';
                        $form_assessment_type = 'monthly_followup';
                    } else {
                        $error = "Error saving record: " . $conn->error;
                        $child_id = $child_id_post;
                    }
                }
            }
        }
    }
}

/* =========================================================
   SEARCH MODE
========================================================= */
$search_results = null;
$total_pupils = 0;
$total_pupil_pages = 1;

if($child_id <= 0){
    $count_sql = "SELECT COUNT(*) AS total
            FROM children
            WHERE cdc_id = ?";

    if($search !== ""){
        $count_sql .= " AND (
            first_name LIKE ? OR
            middle_name LIKE ? OR
            last_name LIKE ?
        )";
    }

    $count_stmt = $conn->prepare($count_sql);

    if($search !== ""){
        $search_param = "%" . $search . "%";
        $count_stmt->bind_param("isss", $active_cdc_id, $search_param, $search_param, $search_param);
    } else {
        $count_stmt->bind_param("i", $active_cdc_id);
    }

    $count_stmt->execute();
    $total_pupils = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();

    $total_pupil_pages = ($total_pupils > 0) ? ceil($total_pupils / $per_page) : 1;
    if($page > $total_pupil_pages){
        $page = $total_pupil_pages;
    }
    $offset = ($page - 1) * $per_page;

    $sql = "SELECT child_id, first_name, middle_name, last_name
            FROM children
            WHERE cdc_id = ?";

    if($search !== ""){
        $sql .= " AND (
            first_name LIKE ? OR
            middle_name LIKE ? OR
            last_name LIKE ?
        )";
    }

    $sql .= " ORDER BY first_name ASC, last_name ASC LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);

    if($search !== ""){
        $stmt->bind_param("isssii", $active_cdc_id, $search_param, $search_param, $search_param, $per_page, $offset);
    } else {
        $stmt->bind_param("iii", $active_cdc_id, $per_page, $offset);
    }

    $stmt->execute();
    $search_results = $stmt->get_result();
}

/* =========================================================
   CHILD DETAIL MODE
========================================================= */
$child_data = null;
$records = null;

if($child_id > 0){
    $child_stmt = $conn->prepare("
        SELECT children.*, cdc.cdc_name
        FROM children
        INNER JOIN cdc ON children.cdc_id = cdc.cdc_id
        WHERE children.child_id = ? AND children.cdc_id = ?
        LIMIT 1
    ");
    $child_stmt->bind_param("ii", $child_id, $active_cdc_id);
    $child_stmt->execute();
    $child_result = $child_stmt->get_result();

    if($child_result->num_rows == 0){
        die("Child not found or not assigned to the active CDC.");
    }

    $child_data = $child_result->fetch_assoc();

    if(!$view_only && $edit_id > 0){
        $edit_stmt = $conn->prepare("
            SELECT ar.*
            FROM anthropometric_records ar
            WHERE ar.record_id = ? AND ar.child_id = ?
            LIMIT 1
        ");
        $edit_stmt->bind_param("ii", $edit_id, $child_id);
        $edit_stmt->execute();
        $edit_result = $edit_stmt->get_result();

        if($edit_result && $edit_result->num_rows > 0){
            $edit_data = $edit_result->fetch_assoc();
            $form_date_recorded = $edit_data['date_recorded'];
            $form_height = $edit_data['height'];
            $form_weight = $edit_data['weight'];
            $form_muac = $edit_data['muac'];
            $form_edema_status = $edit_data['edema_status'];
            $form_edema_grade = $edit_data['edema_grade'];
            $form_muac_status = $edit_data['muac_status'];
            $form_assessment_type = !empty($edit_data['assessment_type']) ? $edit_data['assessment_type'] : 'monthly_followup';
        } else {
            $edit_id = 0;
        }
    } else {
        $edit_id = 0;
    }

    $records_stmt = $conn->prepare("
        SELECT ar.*, u.first_name AS recorded_first_name, u.last_name AS recorded_last_name
        FROM anthropometric_records ar
        LEFT JOIN users u ON ar.recorded_by = u.user_id
        WHERE ar.child_id = ?
        ORDER BY ar.date_recorded DESC, ar.record_id DESC
    ");
    $records_stmt->bind_param("i", $child_id);
    $records_stmt->execute();
    $records = $records_stmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $view_only ? 'Anthropometric Record History' : 'Input Height, Weight and MUAC'; ?> | NutriTrack</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
    <link rel="stylesheet" href="../assets/cdw/cdw-topbar-notification.css">
    <link rel="stylesheet" href="../assets/cdw/anthropometric.css">
    

    <style>
        .field-tooltip-wrap {
            position: relative;
        }

        .field-tooltip-wrap .field-description {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            margin-top: 3px;
            white-space: nowrap;
            background: #fff;
            border: 1px solid #e5e7eb;
            padding: 3px 6px;
            border-radius: 3px;
            z-index: 10;
        }

        .field-tooltip-wrap:hover .field-description {
            display: block;
        }

        .field-description {
            font-size: 8px;
            font-weight: 400;
            color: #999999;
            line-height: 1.3;
        }

        .measurements-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .measurements-table th,
        .measurements-table td {
            padding: 16px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #e5e7eb;
            font-size: 15px;
        }

        .measurements-table th {
            font-weight: 700;
            color: #222;
            background: #fff;
            text-align: left;
        }

        .measurements-table .col-date {
            width: 110px;
        }

        .measurements-table .col-type {
            width: 150px;
            text-align: center;
        }

        .measurements-table .col-age {
            width: 105px;
            text-align: center;
        }

        .measurements-table .col-height,
        .measurements-table .col-weight,
        .measurements-table .col-muac {
            width: 105px;
            text-align: center;
        }

        .measurements-table .col-wfa,
        .measurements-table .col-hfa,
        .measurements-table .col-wflh {
            width: 145px;
            text-align: center;
        }



        .measurements-table .col-action {
            width: 120px;
            text-align: center;
        }

        .measurements-table td.center-cell,
        .measurements-table th.center-cell {
            text-align: center;
        }

        .measurements-table td.date-cell {
            line-height: 1.2;
        }

        .measurements-table td.unit-cell {
            line-height: 1.25;
        }

       
        .measurements-table td.action-cell {
            text-align: center;
        }

        .edit-btn-table {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 92px;
            padding: 10px 18px;
            border-radius: 10px;
            background: #2e7d32;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: 0.2s ease;
        }

        .edit-btn-table:hover {
            background: #256628;
        }

        .status-cell {
            text-align: center;
        }

        .status-cell .status-badge {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            min-width: 120px;
            padding: 10px 14px;
            text-align: center;
            border-radius: 8px;
        }

        .assessment-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 125px;
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
        }

        .badge-baseline {
            background: #e3f2fd;
            color: #1565c0;
        }

        .badge-midline {
            background: #fff3e0;
            color: #ef6c00;
        }

        .badge-endline {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .badge-followup {
            background: #f3f4f6;
            color: #374151;
        }

        @media (max-width: 1200px) {
            .measurements-table {
                min-width: 1260px;
            }
        }

        .pupil-pagination{
            display:flex;
            justify-content:center;
            align-items:center;
            gap:6px;
            flex-wrap:wrap;
            margin-top:16px;
        }

        .pupil-page-link{
            min-width:36px;
            height:36px;
            padding:0 10px;
            border-radius:8px;
            border:1px solid #d6d6d6;
            background:#ffffff;
            color:#333;
            font-size:13px;
            font-weight:600;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            text-decoration:none;
        }

        .pupil-page-link:hover{
            border-color:#2E7D32;
            color:#2E7D32;
        }

        .pupil-page-link.active{
            background:#2E7D32;
            border-color:#2E7D32;
            color:#fff;
        }

        .pupil-page-link.disabled{
            opacity:0.45;
            pointer-events:none;
        }
    </style>
</head>
<?php include __DIR__ . '/../includes/auth.php'; ?>
<body class="<?php echo themeClass(); ?>">
    
<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-wrapper">

        <?php if($child_id <= 0){ ?>

            <div class="page-header">
                <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
                <h1 class="page-title">Input Height, Weight and MUAC</h1>
                <div class="page-subtitle">
                    Search pupils under your active CDC first before recording anthropometric measurements.
                </div>
            </div>

            <?php if(!empty($success)){ ?>
                <div class="message success"><?php echo htmlspecialchars($success); ?></div>
            <?php } ?>

            <?php if(!empty($error)){ ?>
                <div class="message error"><?php echo htmlspecialchars($error); ?></div>
            <?php } ?>

            <div class="search-card">
                <div class="search-card-title">Search Pupils</div>

                <form method="GET" class="search-form">
                    <input
                        type="text"
                        name="search"
                        class="search-input"
                        placeholder="Search Pupils"
                        value="<?php echo htmlspecialchars($search); ?>"
                    >
                    <button type="submit" class="search-btn">Search</button>
                </form>

                <div class="list-label">Pupils List</div>

                <div class="pupil-list">
                    <?php if($search_results && $search_results->num_rows > 0){ ?>
                        <?php while($row = $search_results->fetch_assoc()){
                            $full_name = trim(
                                $row['first_name'] . ' ' .
                                (!empty($row['middle_name']) ? $row['middle_name'] . ' ' : '') .
                                $row['last_name']
                            );
                        ?>
                            <div class="pupil-item">
                                <div class="pupil-name"><?php echo htmlspecialchars($full_name); ?></div>
                                <a href="anthropometric_records.php?child_id=<?php echo $row['child_id']; ?>" class="view-arrow-btn" title="Open Input Height, Weight and MUAC">
                                    <span class="arrow-icon">➜</span>
                                </a>
                            </div>
                        <?php } ?>
                    <?php } else { ?>
                        <div class="empty-state">No pupils found under the selected CDC.</div>
                    <?php } ?>
                </div>

                <?php if($total_pupil_pages > 1){ ?>
                    <div class="pupil-pagination">
                        <?php
                            $pupil_query_params = $search !== "" ? "&search=" . urlencode($search) : "";

                            $prev_pupil_page = max(1, $page - 1);
                            $next_pupil_page = min($total_pupil_pages, $page + 1);

                            $prev_pupil_disabled = ($page <= 1) ? "disabled" : "";
                            $next_pupil_disabled = ($page >= $total_pupil_pages) ? "disabled" : "";
                        ?>
                        <a href="?page=<?php echo $prev_pupil_page . $pupil_query_params; ?>" class="pupil-page-link <?php echo $prev_pupil_disabled; ?>">‹</a>

                        <?php for($i = 1; $i <= $total_pupil_pages; $i++){
                            $pupil_active_class = ($i == $page) ? "active" : "";
                        ?>
                            <a href="?page=<?php echo $i . $pupil_query_params; ?>" class="pupil-page-link <?php echo $pupil_active_class; ?>"><?php echo $i; ?></a>
                        <?php } ?>

                        <a href="?page=<?php echo $next_pupil_page . $pupil_query_params; ?>" class="pupil-page-link <?php echo $next_pupil_disabled; ?>">›</a>
                    </div>
                <?php } ?>
            </div>

        <?php } else { ?>

            <?php
                $child_full_name = trim(
                    $child_data['first_name'] . ' ' .
                    (!empty($child_data['middle_name']) ? $child_data['middle_name'] . ' ' : '') .
                    $child_data['last_name']
                );
                $age_now = compute_age_label($child_data['birthdate']);$age_months_now = compute_age_months_exact($child_data['birthdate'], date('Y-m-d'));
            ?>

            <div class="page-header">
                <a href="anthropometric_records.php" class="back-link">← Back to Search Pupils</a>
                <h1 class="page-title"><?php echo $view_only ? 'Anthropometric Record History' : 'Input Height, Weight and MUAC'; ?></h1>
                <div class="page-subtitle">
                    <?php echo $view_only
                        ? 'View previous anthropometric records and measurement history.'
                        : 'Record anthropometric measurements and view previous measurement history.'; ?>
                </div>
            </div>

            <?php if(!empty($success)){ ?>
                <div class="message success"><?php echo htmlspecialchars($success); ?></div>
            <?php } ?>

            <?php if(!empty($error)){ ?>
                <div class="message error"><?php echo htmlspecialchars($error); ?></div>
            <?php } ?>

            <div class="child-summary">
                <div class="summary-item"><strong>Child Name: </strong> <?php echo htmlspecialchars(strtoupper($child_full_name)); ?></div>
                <div class="summary-item"><strong>Birthdate: </strong> <?php echo date("F d, Y", strtotime($child_data['birthdate'])); ?></div>
                <div class="summary-item"><strong>Age in Months: </strong><?php echo ($age_months_now !== null) ? htmlspecialchars($age_months_now . ' month(s) old') : 'N/A'; ?></div>
                <div class="summary-item"><strong>CDC: </strong> <?php echo htmlspecialchars($child_data['cdc_name']); ?></div>
            </div>

            <?php if(!$view_only){ ?>
            <div class="content-card">
                <div class="card-header">
                    <?php echo $edit_id > 0 ? 'Edit Height, Weight and MUAC' : 'Input Height, Weight and MUAC'; ?>
                </div>
                <div class="card-body">
                    <form method="POST" class="measurement-form">
                        <input type="hidden" name="child_id" value="<?php echo $child_id; ?>">
                        <input type="hidden" name="edit_record_id" value="<?php echo $edit_id; ?>">

                        <div class="form-grid">
                            <label class="form-label">Date Measured:</label>
                            <input type="date" name="date_recorded" class="form-control" value="<?php echo htmlspecialchars($form_date_recorded); ?>" required>

                            <label class="form-label">Height (cm):</label>
                            <input type="number" step="0.01" name="height" class="form-control" value="<?php echo htmlspecialchars($form_height); ?>" required>

                            <label class="form-label">Weight (kg):</label>
                            <input type="number" step="0.01" name="weight" class="form-control" value="<?php echo htmlspecialchars($form_weight); ?>" required>

                            <label class="form-label">MUAC (cm):</label>
                            <div class="field-tooltip-wrap">
                                <input type="number" step="0.01" name="muac" class="form-control" value="<?php echo htmlspecialchars($form_muac); ?>" required>
                                <small class="field-description">Measurement of the child's upper arm circumference.</small>
                            </div>

                            <label class="form-label">Presence of Edema:</label>
                        <div class="field-tooltip-wrap">
                        <select name="edema_status" id="edema_status" class="form-control" required>
                            <option value="">Select</option>
                            <option value="Absent" <?php echo ($form_edema_status === 'Absent') ? 'selected' : ''; ?>>Absent</option>
                            <option value="Present" <?php echo ($form_edema_status === 'Present') ? 'selected' : ''; ?>>Present</option>
                        </select>
                        <small class="field-description">Swelling of the body, a sign of malnutrition.</small>
                        </div>

                        <label class="form-label" id="edema_grade_label" style="display:none;">
                            Edema Grade:
                        </label>

                        <select name="edema_grade" id="edema_grade" class="form-control" style="display:none;">
                            <option value="">Select Grade</option>
                            <option value="+1">+1</option>
                            <option value="+2">+2</option>
                            <option value="+3">+3</option>
                        </select>


                            <label class="form-label">Assessment Type:</label>
                            <select name="assessment_type" class="form-control" required>
                                <option value="">Select Assessment Type</option>
                                <option value="baseline" <?php echo ($form_assessment_type === 'baseline') ? 'selected' : ''; ?>>Baseline</option>
                                <option value="monthly_followup" <?php echo ($form_assessment_type === 'monthly_followup') ? 'selected' : ''; ?>>Monthly Follow-Up</option>
                                <option value="midline" <?php echo ($form_assessment_type === 'midline') ? 'selected' : ''; ?>>Midline</option>
                                <option value="endline" <?php echo ($form_assessment_type === 'endline') ? 'selected' : ''; ?>>Endline</option>
                            </select>

                            <label class="form-label">Recorded By:</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?>" readonly>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="save_record" class="btn-save">
                                <?php echo $edit_id > 0 ? 'Update' : 'Save'; ?>
                            </button>
                            <a href="anthropometric_records.php?child_id=<?php echo $child_id; ?>" class="btn-cancel">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
            <?php } ?>

            <div class="content-card">
                <div class="card-header">Previous Measurements</div>
                <div class="card-body">
                    <?php if($records && $records->num_rows > 0){ ?>
                        <div class="table-wrapper">
                            <table class="measurements-table">
                                <thead>
                                    <tr>
                                        <th class="col-date">Date</th>
                                        <th class="col-type center-cell">Assessment Type</th>
                                        <th class="col-age center-cell">Age<br>(Months)</th>
                                        <th class="col-height center-cell">Height<br>(cm)</th>
                                        <th class="col-weight center-cell">Weight<br>(kg)</th>
                                        <th class="col-muac center-cell">MUAC<br>(cm)</th>
                                        <th class="col-edema center-cell">Edema</th>
                                        <th class="col-edema-grade center-cell">Grade</th>
                                        <th class="col-muac-status center-cell">MUAC Status</th>
                                        <th class="col-wfa center-cell">WFA</th>
                                        <th class="col-hfa center-cell">HFA</th>
                                        <th class="col-wflh center-cell">WFL/H</th>
                                       
                                        <?php if(!$view_only){ ?>
                                            <th class="col-action center-cell">Action</th>
                                        <?php } ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while($record = $records->fetch_assoc()){
                                        $recorded_by_name = trim(($record['recorded_first_name'] ?? '') . ' ' . ($record['recorded_last_name'] ?? ''));
                                        if($recorded_by_name == ''){
                                            $recorded_by_name = 'N/A';
                                        }

                                        $type = $record['assessment_type'] ?? '';
                                        $badge_class = 'badge-followup';

                                        if($type === 'baseline'){
                                            $badge_class = 'badge-baseline';
                                        } elseif($type === 'midline'){
                                            $badge_class = 'badge-midline';
                                        } elseif($type === 'endline'){
                                            $badge_class = 'badge-endline';
                                        }
                                    ?>
                                        <tr>
                                            <td class="date-cell"><?php echo date("M d, Y", strtotime($record['date_recorded'])); ?></td>
                                            <td class="center-cell">
                                                <span class="assessment-badge <?php echo $badge_class; ?>">
                                                    <?php echo htmlspecialchars(assessment_type_label($type)); ?>
                                                </span>
                                            </td>
                                            <td class="center-cell"><?php echo htmlspecialchars($record['age_months'] ?? '--'); ?></td>
                                            <td class="center-cell unit-cell"><?php echo htmlspecialchars(number_format((float)$record['height'], 2)); ?><br>cm</td>
                                            <td class="center-cell unit-cell"><?php echo htmlspecialchars(number_format((float)$record['weight'], 2)); ?><br>kg</td>
                                            <td class="center-cell unit-cell"><?php echo htmlspecialchars(number_format((float)$record['muac'], 2)); ?><br>cm</td>
                                            <td class="center-cell"><?php echo htmlspecialchars($record['edema_status'] ?? '--'); ?></td>
                                            <td class="center-cell"><?php echo htmlspecialchars($record['edema_grade'] ?? '--'); ?></td>                                           <td class="status-cell"><?php echo status_badge($record['muac_status'] ?? '--'); ?></td>
                                            <td class="status-cell"><?php echo status_badge($record['wfa_status'] ?? '--'); ?></td>
                                            <td class="status-cell"><?php echo status_badge($record['hfa_status'] ?? '--'); ?></td>
                                            <td class="status-cell"><?php echo status_badge($record['wflh_status'] ?? '--'); ?></td>
                                           
                                            <?php if(!$view_only){ ?>
                                            <td class="action-cell">
                                                <a href="anthropometric_records.php?child_id=<?php echo $child_id; ?>&edit_id=<?php echo $record['record_id']; ?>" class="edit-btn-table">
                                                    Edit
                                                </a>
                                            </td>
                                            <?php } ?>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    <?php } else { ?>
                        <div class="no-records">No anthropometric records found for this child.</div>
                    <?php } ?>
                </div>
            </div>

        <?php } ?>

    </div>
</div>

<script>


document.addEventListener('DOMContentLoaded', function(){

    const edemaStatus = document.getElementById('edema_status');
    const edemaGrade = document.getElementById('edema_grade');
    const edemaGradeLabel = document.getElementById('edema_grade_label');

    function toggleEdema(){

        if(edemaStatus.value === 'Present'){
            edemaGrade.style.display = 'block';
            edemaGradeLabel.style.display = 'block';
        } else {
            edemaGrade.style.display = 'none';
            edemaGradeLabel.style.display = 'none';
            edemaGrade.value = '';
        }
    }

    edemaStatus.addEventListener('change', toggleEdema);
    toggleEdema();
});

</script>
<script src="../assets/cdw/sidebar.js"></script>
</body>
</html>