<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../includes/auth.php';
include '../config/database.php';

if($_SESSION['role_id'] != 3){
    header("Location: ../login.php");
    exit();
}

if (!isset($_SESSION['theme_mode'])) {
    $_SESSION['theme_mode'] = 'light';
}

$theme_mode = $_SESSION['theme_mode'];
$current_page = 'health_info';

$guardian_user_id = (int) $_SESSION['user_id'];
$guardian_name = trim($_SESSION['first_name'] . ' ' . $_SESSION['last_name']);

$message = '';
$message_type = '';
$child = null;
$pending_submission = null;

$sql = "
    SELECT
        children.*,
        cdc.cdc_name,
        cdc.address AS cdc_address,
        child_health_information.born_at,
        child_health_information.learns_at_home_with,
        child_health_information.plays_with_older_siblings,
        child_health_information.plays_with_younger_siblings,
        child_health_information.plays_with_neighbors,
        child_health_information.has_meal_before_school,
        child_health_information.food_normally_eaten,
        child_health_information.has_baon,
        child_health_information.travel_time_to_dcc_minutes,
        child_health_information.travel_mode_to_dcc,
        child_health_information.travel_time_to_ncdc_minutes,
        child_health_information.travel_mode_to_ncdc,
        child_health_information.public_transport_type,
        child_health_information.goes_to_school_with,
        child_health_information.has_eccd_card,
        child_health_information.has_mother_child_book,
        child_health_information.has_other_health_record,
        child_health_information.vaccine_bcg,
        child_health_information.vaccine_dpt,
        child_health_information.vaccine_opv,
        child_health_information.vaccine_hepab,
        child_health_information.vaccine_measles,
        child_health_information.vaccine_others_name,
        child_health_information.vaccine_others_status,
        child_health_information.is_left_handed
    FROM parent_child_links
    INNER JOIN children ON parent_child_links.child_id = children.child_id
    INNER JOIN cdc ON children.cdc_id = cdc.cdc_id
    LEFT JOIN child_health_information ON children.child_id = child_health_information.child_id
    WHERE parent_child_links.parent_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if(!$stmt){
    die("Prepare error: " . $conn->error);
}

$stmt->bind_param("i", $guardian_user_id);
$stmt->execute();
$result = $stmt->get_result();

if($result && $result->num_rows > 0){
    $child = $result->fetch_assoc();
} else {
    die("No linked child found for this guardian.");
}

$stmt->close();

$child_id = (int)$child['child_id'];

$child_full_name = trim(
    ($child['first_name'] ?? '') . ' ' .
    ($child['middle_name'] ?? '') . ' ' .
    ($child['last_name'] ?? '')
);

$birthdate_display = (!empty($child['birthdate']) && $child['birthdate'] != '0000-00-00')
    ? date("F d, Y", strtotime($child['birthdate']))
    : 'N/A';

$age = 'N/A';
$age_months = 'N/A';

if(!empty($child['birthdate']) && $child['birthdate'] != '0000-00-00'){
    $birthdate = new DateTime($child['birthdate']);
    $today = new DateTime();
    $diff = $today->diff($birthdate);

    $age = $diff->y . " year(s) old";
    $age_months = ($diff->y * 12) + $diff->m;
}

$sex = !empty($child['sex']) ? $child['sex'] : 'N/A';
$child_address = !empty($child['address']) ? $child['address'] : 'N/A';
$religion = !empty($child['religion']) ? $child['religion']: 'N/A';
$cdc_name = !empty($child['cdc_name']) ? $child['cdc_name'] : 'N/A';
$cdc_address = !empty($child['cdc_address']) ? $child['cdc_address'] : 'N/A';

$official_birth_order = (isset($child['birth_order']) && $child['birth_order'] !== null && $child['birth_order'] !== '') ? $child['birth_order'] : 'N/A';
$official_siblings_info = !empty($child['siblings_info']) ? $child['siblings_info'] : 'N/A';
$official_eccd_experience_info = !empty($child['eccd_experience_info']) ? $child['eccd_experience_info'] : 'N/A';

$official_born_at = !empty($child['born_at']) ? $child['born_at'] : 'N/A';
$official_learns_at_home_with = !empty($child['learns_at_home_with']) ? $child['learns_at_home_with'] : 'N/A';
$official_plays_with_older_siblings = !empty($child['plays_with_older_siblings']) ? $child['plays_with_older_siblings'] : 'N/A';
$official_plays_with_younger_siblings = !empty($child['plays_with_younger_siblings']) ? $child['plays_with_younger_siblings'] : 'N/A';
$official_plays_with_neighbors = !empty($child['plays_with_neighbors']) ? $child['plays_with_neighbors'] : 'N/A';
$official_has_meal_before_school = !empty($child['has_meal_before_school']) ? $child['has_meal_before_school'] : 'N/A';
$official_food_normally_eaten = !empty($child['food_normally_eaten']) ? $child['food_normally_eaten'] : 'N/A';
$official_has_baon = !empty($child['has_baon']) ? $child['has_baon'] : 'N/A';
$official_travel_time_to_dcc_minutes = (isset($child['travel_time_to_dcc_minutes']) && $child['travel_time_to_dcc_minutes'] !== null && $child['travel_time_to_dcc_minutes'] !== '') ? $child['travel_time_to_dcc_minutes'] . ' minute(s)' : 'N/A';
$official_travel_mode_to_dcc = !empty($child['travel_mode_to_dcc']) ? $child['travel_mode_to_dcc'] : 'N/A';
$official_travel_time_to_ncdc_minutes = (isset($child['travel_time_to_ncdc_minutes']) && $child['travel_time_to_ncdc_minutes'] !== null && $child['travel_time_to_ncdc_minutes'] !== '') ? $child['travel_time_to_ncdc_minutes'] . ' minute(s)' : 'N/A';
$official_travel_mode_to_ncdc = !empty($child['travel_mode_to_ncdc']) ? $child['travel_mode_to_ncdc'] : 'N/A';
$official_public_transport_type = !empty($child['public_transport_type']) ? $child['public_transport_type'] : 'N/A';
$official_goes_to_school_with = !empty($child['goes_to_school_with']) ? $child['goes_to_school_with'] : 'N/A';
$official_has_eccd_card = isset($child['has_eccd_card']) ? ((int)$child['has_eccd_card'] === 1 ? 'Yes' : 'No') : 'N/A';
$official_has_mother_child_book = isset($child['has_mother_child_book']) ? ((int)$child['has_mother_child_book'] === 1 ? 'Yes' : 'No') : 'N/A';
$official_has_other_health_record = !empty($child['has_other_health_record']) ? $child['has_other_health_record'] : 'N/A';
$official_vaccine_bcg = !empty($child['vaccine_bcg']) ? $child['vaccine_bcg'] : 'N/A';
$official_vaccine_dpt = !empty($child['vaccine_dpt']) ? $child['vaccine_dpt'] : 'N/A';
$official_vaccine_opv = !empty($child['vaccine_opv']) ? $child['vaccine_opv'] : 'N/A';
$official_vaccine_hepab = !empty($child['vaccine_hepab']) ? $child['vaccine_hepab'] : 'N/A';
$official_vaccine_measles = !empty($child['vaccine_measles']) ? $child['vaccine_measles'] : 'N/A';
$official_vaccine_others_name = !empty($child['vaccine_others_name']) ? $child['vaccine_others_name'] : 'N/A';
$official_vaccine_others_status = !empty($child['vaccine_others_status']) ? $child['vaccine_others_status'] : 'N/A';
$official_is_left_handed = !empty($child['is_left_handed']) ? $child['is_left_handed'] : 'N/A';

$submission_table_exists = false;
$table_check = $conn->query("SHOW TABLES LIKE 'child_health_information_requests'");
if($table_check && $table_check->num_rows > 0){
    $submission_table_exists = true;
}

if($submission_table_exists){
    $pending_sql = "
        SELECT *
        FROM child_health_information_requests
        WHERE child_id = ?
          AND guardian_id = ?
          AND status = 'Pending'
        ORDER BY submitted_at DESC, request_id DESC
        LIMIT 1
    ";

    $pending_stmt = $conn->prepare($pending_sql);

    if($pending_stmt){
        $pending_stmt->bind_param("ii", $child_id, $guardian_user_id);
        $pending_stmt->execute();
        $pending_result = $pending_stmt->get_result();
        $pending_submission = $pending_result->fetch_assoc();
        $pending_stmt->close();
    }
}

$rejected_submission = null;

if($submission_table_exists && !$pending_submission){
    $rejected_sql = "
        SELECT *
        FROM child_health_information_requests
        WHERE child_id = ?
          AND guardian_id = ?
          AND status = 'Rejected'
        ORDER BY submitted_at DESC, request_id DESC
        LIMIT 1
    ";

    $rejected_stmt = $conn->prepare($rejected_sql);

    if($rejected_stmt){
        $rejected_stmt->bind_param("ii", $child_id, $guardian_user_id);
        $rejected_stmt->execute();
        $rejected_result = $rejected_stmt->get_result();
        $rejected_submission = $rejected_result->fetch_assoc();
        $rejected_stmt->close();
    }
}

/*
|----------------------------------------------------------------
| Hide the "Submit Updated Health Information to CDW" card once
| the latest submission has already been Approved (no pending,
| no need to resubmit). Display-only check, does not touch the
| pending/rejected logic above.
|----------------------------------------------------------------
*/
$latest_submission_approved = false;

if($submission_table_exists && !$pending_submission){
    $latest_sql = "
        SELECT status
        FROM child_health_information_requests
        WHERE child_id = ?
          AND guardian_id = ?
        ORDER BY submitted_at DESC, request_id DESC
        LIMIT 1
    ";

    $latest_stmt = $conn->prepare($latest_sql);

    if($latest_stmt){
        $latest_stmt->bind_param("ii", $child_id, $guardian_user_id);
        $latest_stmt->execute();
        $latest_result = $latest_stmt->get_result();
        $latest_row = $latest_result->fetch_assoc();
        $latest_stmt->close();

        if($latest_row && $latest_row['status'] === 'Approved'){
            $latest_submission_approved = true;
        }
    }
}

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_health_info'])){
    if(!$submission_table_exists){
        $message = "Submission table not found. Please create child_health_information_requests first.";
        $message_type = 'error';
    } elseif($pending_submission){
        $message = "You already have a pending health information submission waiting for CDW review.";
        $message_type = 'error';
    } else {
        $birth_order_input = trim($_POST['birth_order'] ?? '');
$siblings_info_input = trim($_POST['siblings_info'] ?? '');
$eccd_experience_info_input = trim($_POST['eccd_experience_info'] ?? '');

$born_at_input = trim($_POST['born_at'] ?? '');
$learns_at_home_with_input = trim($_POST['learns_at_home_with'] ?? '');
$plays_with_older_siblings_input = trim($_POST['plays_with_older_siblings'] ?? '');
$plays_with_younger_siblings_input = trim($_POST['plays_with_younger_siblings'] ?? '');
$plays_with_neighbors_input = trim($_POST['plays_with_neighbors'] ?? '');
$has_meal_before_school_input = trim($_POST['has_meal_before_school'] ?? '');
$food_normally_eaten_input = trim($_POST['food_normally_eaten'] ?? '');
$has_baon_input = trim($_POST['has_baon'] ?? '');
$travel_time_to_dcc_minutes_input = trim($_POST['travel_time_to_dcc_minutes'] ?? '');
$travel_mode_to_dcc_input = trim($_POST['travel_mode_to_dcc'] ?? '');
$travel_time_to_ncdc_minutes_input = trim($_POST['travel_time_to_ncdc_minutes'] ?? '');
$travel_mode_to_ncdc_input = trim($_POST['travel_mode_to_ncdc'] ?? '');
$public_transport_type_input = trim($_POST['public_transport_type'] ?? '');
$goes_to_school_with_input = trim($_POST['goes_to_school_with'] ?? '');
$has_eccd_card_input = isset($_POST['has_eccd_card']) ? 1 : 0;
$has_mother_child_book_input = isset($_POST['has_mother_child_book']) ? 1 : 0;
$has_other_health_record_input = trim($_POST['has_other_health_record'] ?? '');
$vaccine_bcg_input = trim($_POST['vaccine_bcg'] ?? '');
$vaccine_dpt_input = trim($_POST['vaccine_dpt'] ?? '');
$vaccine_opv_input = trim($_POST['vaccine_opv'] ?? '');
$vaccine_hepab_input = trim($_POST['vaccine_hepab'] ?? '');
$vaccine_measles_input = trim($_POST['vaccine_measles'] ?? '');
$vaccine_others_name_input = trim($_POST['vaccine_others_name'] ?? '');
$vaccine_others_status_input = trim($_POST['vaccine_others_status'] ?? '');
$is_left_handed_input = trim($_POST['is_left_handed'] ?? '');

if ($travel_mode_to_dcc_input !== 'Public Transportation' && $travel_mode_to_ncdc_input !== 'Public Transportation') {
    $public_transport_type_input = '';
}

$birth_order_value = ($birth_order_input !== '') ? (int)$birth_order_input : null;
$travel_time_to_dcc_value = ($travel_time_to_dcc_minutes_input !== '') ? (int)$travel_time_to_dcc_minutes_input : null;
$travel_time_to_ncdc_value = ($travel_time_to_ncdc_minutes_input !== '') ? (int)$travel_time_to_ncdc_minutes_input : null;

      if(
            $birth_order_input === '' && $siblings_info_input === '' && $eccd_experience_info_input === ''
            && $born_at_input === '' && $learns_at_home_with_input === ''
            && $plays_with_older_siblings_input === '' && $plays_with_younger_siblings_input === '' && $plays_with_neighbors_input === ''
            && $has_meal_before_school_input === '' && $food_normally_eaten_input === '' && $has_baon_input === ''
            && $travel_time_to_dcc_minutes_input === '' && $travel_mode_to_dcc_input === ''
            && $travel_time_to_ncdc_minutes_input === '' && $travel_mode_to_ncdc_input === ''
            && $goes_to_school_with_input === '' && !$has_eccd_card_input && !$has_mother_child_book_input
            && $has_other_health_record_input === ''
            && $vaccine_bcg_input === '' && $vaccine_dpt_input === '' && $vaccine_opv_input === ''
            && $vaccine_hepab_input === '' && $vaccine_measles_input === ''
            && $vaccine_others_name_input === '' && $vaccine_others_status_input === ''
            && $is_left_handed_input === ''
      ){
            $message = "Please provide at least one health information entry before submitting.";
            $message_type = 'error';
        

        } else {
           

            $insert_sql = "
                INSERT INTO child_health_information_requests (
                child_id,
                guardian_id,
                birth_order,
                born_at,
                siblings_info,
                eccd_experience_info,
                learns_at_home_with,
                plays_with_older_siblings,
                plays_with_younger_siblings,
                plays_with_neighbors,
                has_meal_before_school,
                food_normally_eaten,
                has_baon,
                travel_time_to_dcc_minutes,
                travel_mode_to_dcc,
                travel_time_to_ncdc_minutes,
                travel_mode_to_ncdc,
                public_transport_type,
                goes_to_school_with,
                has_eccd_card,
                has_mother_child_book,
                has_other_health_record,
                vaccine_bcg,
                vaccine_dpt,
                vaccine_opv,
                vaccine_hepab,
                vaccine_measles,
                vaccine_others_name,
                vaccine_others_status,
                is_left_handed,
                status,
                submitted_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
            ";

            $insert_stmt = $conn->prepare($insert_sql);

            if(!$insert_stmt){
                $message = "Prepare error: " . $conn->error;
                $message_type = 'error';
            } else {
               $insert_stmt->bind_param(
                "iiissssssssssisisssiisssssssss",
                $child_id,
                $guardian_user_id,
                $birth_order_value,
                $born_at_input,
                $siblings_info_input,
                $eccd_experience_info_input,
                $learns_at_home_with_input,
                $plays_with_older_siblings_input,
                $plays_with_younger_siblings_input,
                $plays_with_neighbors_input,
                $has_meal_before_school_input,
                $food_normally_eaten_input,
                $has_baon_input,
                $travel_time_to_dcc_value,
                $travel_mode_to_dcc_input,
                $travel_time_to_ncdc_value,
                $travel_mode_to_ncdc_input,
                $public_transport_type_input,
                $goes_to_school_with_input,
                $has_eccd_card_input,
                $has_mother_child_book_input,
                $has_other_health_record_input,
                $vaccine_bcg_input,
                $vaccine_dpt_input,
                $vaccine_opv_input,
                $vaccine_hepab_input,
                $vaccine_measles_input,
                $vaccine_others_name_input,
                $vaccine_others_status_input,
                $is_left_handed_input
            );

                if($insert_stmt->execute()){
                    $message = "Health information successfully submitted to CDW.";
                    $message_type = 'success';

                    $refresh_sql = "
                        SELECT *
                        FROM child_health_information_requests
                        WHERE child_id = ?
                        AND guardian_id = ?
                        AND status = 'Pending'
                        ORDER BY submitted_at DESC, request_id DESC
                        LIMIT 1
                    ";
                    $refresh_stmt = $conn->prepare($refresh_sql);
                    if($refresh_stmt){
                        $refresh_stmt->bind_param("ii", $child_id, $guardian_user_id);
                        $refresh_stmt->execute();
                        $refresh_result = $refresh_stmt->get_result();
                        $pending_submission = $refresh_result->fetch_assoc();
                        $refresh_stmt->close();
                    }
                } else {
                    $message = "Error submitting health information: " . $insert_stmt->error;
                    $message_type = 'error';
                }

                $insert_stmt->close();
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
    <title>Health Information | NutriTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/guardian-style.css">

    <style>
     
    .health-shell{
        display:flex;
        flex-direction:column;
        gap:22px;
        max-width:920px;
        margin:0 auto;
        width:100%;
    }

    .health-card{
        background:rgba(255,255,255,0.98);
        border:1px solid #e5e7eb;
        border-radius:24px;
        overflow:hidden;
        box-shadow:0 14px 34px rgba(15, 23, 42, 0.08);
    }

    .health-card-header{
        background:linear-gradient(135deg, #fff4e6 0%, #f8eadb 100%);
        padding:22px 28px;
        border-bottom:1px solid #f0dfcd;
    }

    .health-card-title{
        font-family:'Poppins', sans-serif;
        font-size:22px;
        font-weight:800;
        color:#c96f00;
        line-height:1.25;
    }

    .health-card-body{
        padding:26px 28px;
    }

    .info-list{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:14px 18px;
    }

    .info-row{
        padding:16px 18px;
        border:1px solid #edf0f4;
        border-radius:16px;
        background:#fffdf9;
        min-height:86px;
    }

    .info-row.full{
        grid-column:1 / -1;
    }

    .info-label{
        display:block;
        font-size:13px;
        color:#7b8794;
        margin-bottom:7px;
        font-weight:600;
    }

    .info-value{
        font-size:15px;
        font-weight:800;
        color:#1f2937;
        line-height:1.5;
        white-space:pre-line;
        word-break:break-word;
    }

    .attached-text{
        margin-bottom:8px;
    }

    .file-link{
        display:inline-flex;
        align-items:center;
        gap:6px;
        color:#c96f00;
        font-weight:800;
        text-decoration:underline;
        word-break:break-word;
    }

    .file-link:hover{
        color:#a95d00;
    }

    .sub-section-title{
        font-family:'Poppins', sans-serif;
        font-size:18px;
        font-weight:800;
        color:#243041;
        margin:24px 0 14px;
    }

    .message-box{
        padding:14px 16px;
        border-radius:14px;
        font-size:14px;
        font-weight:700;
        box-shadow:0 8px 20px rgba(15, 23, 42, 0.05);
    }

    .message-box.success{
        background:#e8f5e9;
        color:#2e7d32;
        border:1px solid #c8e6c9;
    }

    .message-box.error{
        background:#fdeaea;
        color:#b42318;
        border:1px solid #efb0b0;
    }

    .pending-box{
        padding:18px;
        border:1px solid #f3d9a6;
        background:linear-gradient(135deg, #fff8e8 0%, #fffaf0 100%);
        border-radius:18px;
    }

    .pending-title{
        font-family:'Poppins', sans-serif;
        font-size:17px;
        font-weight:800;
        color:#a16207;
        margin-bottom:8px;
    }

    .pending-text{
        font-size:14px;
        color:#7c5a10;
        line-height:1.6;
    }

    .update-info-prompt{
        padding:18px;
        border:1px solid #c8e6c9;
        background:linear-gradient(135deg, #eafaf1 0%, #f5fffa 100%);
        border-radius:18px;
        margin-bottom:18px;
    }

    .update-info-text{
        font-size:14px;
        color:#1e5631;
        line-height:1.6;
        margin-bottom:12px;
    }

    .rejected-box{
        padding:18px;
        border:1px solid #f2b8b5;
        background:linear-gradient(135deg, #fdeaea 0%, #fff5f5 100%);
        border-radius:18px;
        margin-bottom:18px;
    }

    .rejected-title{
        font-family:'Poppins', sans-serif;
        font-size:17px;
        font-weight:800;
        color:#b42318;
        margin-bottom:8px;
    }

    .rejected-text{
        font-size:14px;
        color:#8a2e26;
        line-height:1.6;
    }

    .health-form{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:18px 20px;
    }

    .form-group{
        display:flex;
        flex-direction:column;
        gap:8px;
    }

    .form-group.full{
        grid-column:1 / -1;
    }

    .form-label{
        font-size:14px;
        font-weight:800;
        color:#374151;
    }

    .form-control{
        width:100%;
        border:1px solid #d7dce3;
        border-radius:16px;
        padding:13px 15px;
        font-family:'Inter', sans-serif;
        font-size:14px;
        color:#243041;
        background:#ffffff;
        resize:vertical;
        min-height:82px;
        max-height:150px;
        outline:none;
        transition:0.2s ease;
    }

    input.form-input-file{
        width:100%;
        border:1px dashed #d8b98f;
        border-radius:16px;
        padding:12px 14px;
        font-family:'Inter', sans-serif;
        font-size:14px;
        color:#243041;
        background:#fffaf3;
        outline:none;
        transition:0.2s ease;
    }

    .form-control:focus,
    .form-input-file:focus{
        border-color:#c96f00;
        box-shadow:0 0 0 4px rgba(201,111,0,0.10);
        background:#ffffff;
    }

    .form-help{
        font-size:12px;
        color:#6b7280;
        line-height:1.45;
    }

    .form-actions{
        grid-column:1 / -1;
        margin-top:4px;
        display:flex;
        justify-content:flex-start;
    }

    .btn-submit-health{
        border:none;
        border-radius:14px;
        padding:13px 22px;
        background:linear-gradient(135deg, #d97706 0%, #c96f00 100%);
        color:#ffffff;
        font-size:14px;
        font-weight:800;
        font-family:'Inter', sans-serif;
        cursor:pointer;
        box-shadow:0 10px 20px rgba(201, 111, 0, 0.18);
        transition:0.2s ease;
    }

    .btn-submit-health:hover{
        background:linear-gradient(135deg, #c96f00 0%, #a95d00 100%);
        transform:translateY(-1px);
        box-shadow:0 14px 24px rgba(201, 111, 0, 0.22);
    }

    body.dark-mode .health-card{
        background:#111827;
        border-color:#334155;
        box-shadow:none;
    }

    body.dark-mode .health-card-header{
        background:#1e293b;
        border-bottom-color:#334155;
    }

    body.dark-mode .health-card-title,
    body.dark-mode .sub-section-title,
    body.dark-mode .info-value{
        color:#f8fafc;
    }

    body.dark-mode .info-row{
        background:#0f172a;
        border-color:#334155;
    }

    body.dark-mode .info-label,
    body.dark-mode .form-label,
    body.dark-mode .form-help{
        color:#cbd5e1;
    }

    body.dark-mode .form-control,
    body.dark-mode .form-input-file{
        background:#0f172a;
        color:#f8fafc;
        border-color:#475569;
    }

    body.dark-mode .pending-box{
        background:#3b2f11;
        border-color:#7c5a10;
    }

    body.dark-mode .pending-title,
    body.dark-mode .pending-text{
        color:#fde68a;
    }

    body.dark-mode .rejected-box{
        background:#3b1a1a;
        border-color:#7f1d1d;
    }

    body.dark-mode .rejected-title,
    body.dark-mode .rejected-text{
        color:#fca5a5;
    }

    body.dark-mode .file-link{
        color:#fbbf24;
    }

    body.dark-mode .file-link:hover{
        color:#fde68a;
    }

    @media (max-width: 900px){
        .health-shell{
            max-width:100%;
        }

        .health-form,
        .info-list{
            grid-template-columns:1fr;
        }

        .health-card-body,
        .health-card-header{
            padding:20px;
        }
    }

    @media (max-width: 520px){
        .health-card{
            border-radius:20px;
        }

        .health-card-title{
            font-size:19px;
        }

        .form-control{
            min-height:78px;
        }

        .btn-submit-health{
            width:100%;
        }
    }
</style>
    </style>
</head>
<body class="<?php echo ($theme_mode === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/guardian_topbar.php'; ?>
<?php include '../includes/guardian_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="health-shell">

        <?php if(!empty($message)) { ?>
            <div class="message-box <?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php } ?>

        <div class="health-card">
            <div class="health-card-header">
                <h2 class="health-card-title">Child Information</h2>
            </div>
            <div class="health-card-body">
                <div class="info-list">
                    <div class="info-row">
                        <span class="info-label">Child Name</span>
                        <div class="info-value"><?php echo htmlspecialchars($child_full_name); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Child Development Center</span>
                        <div class="info-value"><?php echo htmlspecialchars($cdc_name); ?> - <?php echo htmlspecialchars($cdc_address); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Sex</span>
                        <div class="info-value"><?php echo htmlspecialchars($sex); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Birthdate</span>
                        <div class="info-value"><?php echo htmlspecialchars($birthdate_display); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Age</span>
                        <div class="info-value"><?php echo htmlspecialchars($age); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Age in Months</span>
                        <div class="info-value">
                            <?php echo ($age_months === 'N/A') ? 'N/A' : htmlspecialchars($age_months) . ' month(s)'; ?>
                        </div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Address</span>
                        <div class="info-value"><?php echo htmlspecialchars($child_address); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Religion</span>
                        <div class="info-value">
                            <?php echo htmlspecialchars($religion); ?>
                        </div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Birth Order</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_birth_order); ?></div>
                    </div>

                    <div class="info-row full">
                        <span class="info-label">Siblings Info</span>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($official_siblings_info)); ?></div>
                    </div>

                    <div class="info-row full">
                        <span class="info-label">Past ECCD Experience</span>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($official_eccd_experience_info)); ?></div>
                    </div>

                </div>

                <h3 class="sub-section-title">Current Health Information</h3>

                <div class="info-list">
                    <div class="info-row">
                        <span class="info-label">Born At</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_born_at); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Learns at Home With</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_learns_at_home_with); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Plays with Older Siblings</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_plays_with_older_siblings); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Plays with Younger Siblings</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_plays_with_younger_siblings); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Plays with Neighbors</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_plays_with_neighbors); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Has Meal Before School</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_has_meal_before_school); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Food Normally Eaten</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_food_normally_eaten); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Has Baon</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_has_baon); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Travel to DCC</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_travel_time_to_dcc_minutes); ?> — <?php echo htmlspecialchars($official_travel_mode_to_dcc); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Travel to NCDC</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_travel_time_to_ncdc_minutes); ?> — <?php echo htmlspecialchars($official_travel_mode_to_ncdc); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Public Transport Type</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_public_transport_type); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Goes to School With</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_goes_to_school_with); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Has ECCD Card</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_has_eccd_card); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Has Mother-Child Book</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_has_mother_child_book); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Other Health Record</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_has_other_health_record); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Vaccine — BCG</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_vaccine_bcg); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Vaccine — DPT</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_vaccine_dpt); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Vaccine — OPV</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_vaccine_opv); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Vaccine — Hepatitis B</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_vaccine_hepab); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Vaccine — Measles</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_vaccine_measles); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Vaccine — Others</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_vaccine_others_name); ?> — <?php echo htmlspecialchars($official_vaccine_others_status); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Left-Handed</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_is_left_handed); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="health-card">
            <div class="health-card-header">
                <h2 class="health-card-title">Submit Updated Health Information to CDW</h2>
            </div>
            <div class="health-card-body">

                <?php if($pending_submission) { ?>
                    <div class="pending-box">
                        <div class="pending-title">Pending Submission</div>
                        <div class="pending-text">
                            You already have a pending health information submission waiting for CDW review.
                            <br><br>
                            <strong>Submitted on:</strong>
                            <?php echo htmlspecialchars(date("F d, Y g:i A", strtotime($pending_submission['submitted_at']))); ?>
                        </div>
                    </div>
                <?php } else { ?>
                    <?php if($rejected_submission) { ?>
                        <div class="rejected-box">
                            <div class="rejected-title">Submission Rejected</div>
                            <div class="rejected-text">
                                Your previous health information submission was rejected by the Child Development Worker.
                                <br><br>
                                <strong>Reason:</strong>
                                <?php echo nl2br(htmlspecialchars(!empty($rejected_submission['review_remarks']) ? $rejected_submission['review_remarks'] : 'No reason provided.')); ?>
                                <br><br>
                                <strong>Reviewed on:</strong>
                                <?php echo htmlspecialchars(!empty($rejected_submission['reviewed_at']) ? date("F d, Y g:i A", strtotime($rejected_submission['reviewed_at'])) : 'N/A'); ?>
                                <br><br>
                                You may update and resubmit the form below.
                            </div>
                        </div>
                    <?php } ?>

                    <?php if($latest_submission_approved) { ?>
                        <div class="update-info-prompt" id="updateInfoPrompt">
                            <p class="update-info-text">
                                Your latest submission has been approved and applied to the child's official record.
                                You may submit updated information anytime.
                            </p>
                            <button type="button" class="btn-submit-health" onclick="toggleHealthUpdateForm()">
                                Update Information
                            </button>
                        </div>
                    <?php } ?>

                    <form method="POST" enctype="multipart/form-data" class="health-form" id="healthUpdateForm"<?php echo $latest_submission_approved ? ' style="display:none;"' : ''; ?>>

                        <div class="form-group">
                            <label class="form-label">Birth Order</label>
                            <input type="number" min="1" name="birth_order" class="form-text-input">
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Siblings Info</label>
                            <textarea name="siblings_info" class="form-control" placeholder="Names/ages of siblings"></textarea>
                        </div>

                        <div class="form-group full">
                            <label class="form-label">Past ECCD Experience</label>
                            <textarea name="eccd_experience_info" class="form-control" placeholder="e.g. Service type, service, dates"></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Born At</label>
                            <select name="born_at" class="form-control form-select">
                                <option value="">Select answer</option>
                                <option value="Hospital">Hospital</option>
                                <option value="Health Center">Health Center</option>
                                <option value="Home">Home</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Learns at Home With</label>
                            <input type="text" name="learns_at_home_with" class="form-text-input">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Plays with Older Siblings</label>
                            <select name="plays_with_older_siblings" class="form-control form-select">
                                <option value="">Select answer</option>
                                <option value="Always">Always</option>
                                <option value="Sometimes">Sometimes</option>
                                <option value="Rarely">Rarely</option>
                                <option value="Never">Never</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Plays with Younger Siblings</label>
                            <select name="plays_with_younger_siblings" class="form-control form-select">
                                <option value="">Select answer</option>
                                <option value="Always">Always</option>
                                <option value="Sometimes">Sometimes</option>
                                <option value="Rarely">Rarely</option>
                                <option value="Never">Never</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Plays with Neighbors</label>
                            <select name="plays_with_neighbors" class="form-control form-select">
                                <option value="">Select answer</option>
                                <option value="Always">Always</option>
                                <option value="Sometimes">Sometimes</option>
                                <option value="Rarely">Rarely</option>
                                <option value="Never">Never</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Has Meal Before School</label>
                            <select name="has_meal_before_school" class="form-control form-select">
                                <option value="">Select answer</option>
                                <option value="Always">Always</option>
                                <option value="Most of the time">Most of the time</option>
                                <option value="Sometimes">Sometimes</option>
                                <option value="Rarely">Rarely</option>
                                <option value="Never">Never</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Food Normally Eaten</label>
                            <input type="text" name="food_normally_eaten" class="form-text-input">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Has Baon</label>
                            <select name="has_baon" class="form-control form-select">
                                <option value="">Select answer</option>
                                <option value="Money">Money</option>
                                <option value="Food">Food</option>
                                <option value="Both">Both</option>
                                <option value="None">None</option>
                                <option value="Don't Know">Don't Know</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Travel Time to DCC (minutes)</label>
                            <input type="number" min="0" name="travel_time_to_dcc_minutes" class="form-text-input">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Travel Mode to DCC</label>
                            <select name="travel_mode_to_dcc" id="travelModeToDcc" class="form-control form-select">
                                <option value="">Select answer</option>
                                <option value="Walking">Walking</option>
                                <option value="Private Vehicle">Private Vehicle</option>
                                <option value="Public Transportation">Public Transportation</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Travel Time to NCDC (minutes)</label>
                            <input type="number" min="0" name="travel_time_to_ncdc_minutes" class="form-text-input">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Travel Mode to NCDC</label>
                            <select name="travel_mode_to_ncdc" id="travelModeToNcdc" class="form-control form-select">
                                <option value="">Select answer</option>
                                <option value="Walking">Walking</option>
                                <option value="Private Vehicle">Private Vehicle</option>
                                <option value="Public Transportation">Public Transportation</option>
                            </select>
                        </div>

                        <div class="form-group" id="publicTransportTypeGroup" style="display:none;">
                            <label class="form-label">Public Transport Type</label>
                            <input type="text" name="public_transport_type" id="publicTransportType" class="form-text-input" placeholder="e.g. Tricycle, Jeepney">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Goes to School With</label>
                            <input type="text" name="goes_to_school_with" class="form-text-input">
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <input type="checkbox" name="has_eccd_card" value="1"> Has ECCD Card
                            </label>
                        </div>

                        <div class="form-group">
                            <label class="form-label">
                                <input type="checkbox" name="has_mother_child_book" value="1"> Has Mother-Child Book
                            </label>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Other Health Record</label>
                            <input type="text" name="has_other_health_record" class="form-text-input">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Vaccine — BCG</label>
                            <select name="vaccine_bcg" class="form-control form-select">
                                <option value="">Select answer</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                                <option value="Don't Know">Don't Know</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Vaccine — DPT</label>
                            <select name="vaccine_dpt" class="form-control form-select">
                                <option value="">Select answer</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                                <option value="Don't Know">Don't Know</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Vaccine — OPV</label>
                            <select name="vaccine_opv" class="form-control form-select">
                                <option value="">Select answer</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                                <option value="Don't Know">Don't Know</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Vaccine — Hepatitis B</label>
                            <select name="vaccine_hepab" class="form-control form-select">
                                <option value="">Select answer</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                                <option value="Don't Know">Don't Know</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Vaccine — Measles</label>
                            <select name="vaccine_measles" class="form-control form-select">
                                <option value="">Select answer</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                                <option value="Don't Know">Don't Know</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Vaccine — Others (Name)</label>
                            <input type="text" name="vaccine_others_name" class="form-text-input" placeholder="e.g. Rotavirus">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Vaccine — Others (Status)</label>
                            <select name="vaccine_others_status" class="form-control form-select">
                                <option value="">Select answer</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                                <option value="Don't Know">Don't Know</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Left-Handed</label>
                            <select name="is_left_handed" class="form-control form-select">
                                <option value="">Select answer</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                            </select>
                        </div>

                        <div class="form-actions">
                            <button type="submit" name="submit_health_info" class="btn-submit-health">
                                Submit to CDW
                            </button>
                        </div>

                    </form>
                <?php } ?>

            </div>
        </div>

    </div>
</div>

<script>

function toggleHealthUpdateForm() {
    var form = document.getElementById('healthUpdateForm');
    var prompt = document.getElementById('updateInfoPrompt');

    if (form) {
        form.style.display = 'block';
    }

    if (prompt) {
        prompt.style.display = 'none';
    }
}

function setupTravelConditionalFields() {
    const travelModeToDcc = document.getElementById('travelModeToDcc');
    const travelModeToNcdc = document.getElementById('travelModeToNcdc');
    const publicTransportTypeGroup = document.getElementById('publicTransportTypeGroup');

    function updatePublicTransportType() {
        if (!publicTransportTypeGroup) return;

        const dccValue = travelModeToDcc ? travelModeToDcc.value : '';
        const ncdcValue = travelModeToNcdc ? travelModeToNcdc.value : '';

        const show = (dccValue === 'Public Transportation') || (ncdcValue === 'Public Transportation');
        publicTransportTypeGroup.style.display = show ? 'flex' : 'none';
    }

    if (travelModeToDcc) {
        travelModeToDcc.addEventListener('change', updatePublicTransportType);
    }

    if (travelModeToNcdc) {
        travelModeToNcdc.addEventListener('change', updatePublicTransportType);
    }

    updatePublicTransportType();
}

setupTravelConditionalFields();

const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');
const mainContent = document.getElementById('mainContent');

function handleDesktopToggle() {
    sidebar.classList.toggle('hidden');
    mainContent.classList.toggle('full');
}

function handleMobileToggle() {
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
    if (window.innerWidth > 991) {
        sidebar.classList.remove('show');
        sidebarOverlay.classList.remove('show');
    }
});
</script>

</body>
</html>