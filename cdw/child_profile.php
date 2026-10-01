<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../includes/auth.php';
include '../config/database.php';

if($_SESSION['role_id'] != 2){
    header("Location: ../login.php");
    exit();
}

if (!isset($_SESSION['theme_mode'])) {
    $_SESSION['theme_mode'] = 'light';
}

if(!isset($_SESSION['active_cdc_id'])){
    die("Please select an active CDC first from the dashboard.");
}

if(!isset($_GET['child_id']) || empty($_GET['child_id'])){
    die("No child selected.");
}

$theme_mode = $_SESSION['theme_mode'];
$active_cdc_id = (int) $_SESSION['active_cdc_id'];
$child_id = (int) $_GET['child_id'];

$sql = "
    SELECT 
        children.*,
        cdc.cdc_name,
        cdc.address AS cdc_address,

        COALESCE(guardians.first_name, guardian_user.first_name) AS guardian_first_name,
        guardians.middle_name AS guardian_middle_name,
        COALESCE(guardians.last_name, guardian_user.last_name) AS guardian_last_name,
        guardians.relationship_to_child,
        COALESCE(guardians.contact_number, guardian_user.contact_number) AS guardian_contact_number,
        COALESCE(guardians.email, guardian_user.email) AS guardian_email,
        COALESCE(guardians.address, guardian_user.address) AS guardian_address,

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

            FROM children
            INNER JOIN cdc 
                ON children.cdc_id = cdc.cdc_id

            LEFT JOIN parent_child_links 
                ON children.child_id = parent_child_links.child_id

            LEFT JOIN users AS guardian_user
                ON parent_child_links.parent_id = guardian_user.user_id

            LEFT JOIN guardians 
                ON guardian_user.user_id = guardians.user_id

            LEFT JOIN child_health_information 
                ON children.child_id = child_health_information.child_id

            WHERE children.child_id = ?
            AND children.cdc_id = ?
            AND children.is_deleted = 0
            LIMIT 1
            ";

$stmt = $conn->prepare($sql);

if(!$stmt){
    die("Prepare error: " . $conn->error);
}

$stmt->bind_param("ii", $child_id, $active_cdc_id);
$stmt->execute();
$result = $stmt->get_result();

if(!$result){
    die("Query error: " . $conn->error);
}

if($result->num_rows == 0){
    die("Child not found or not assigned to the active CDC.");
}

$child = $result->fetch_assoc();
$stmt->close();

$child_full_name = trim(
    $child['first_name'] . ' ' .
    $child['middle_name'] . ' ' .
    $child['last_name']
);

$guardian_full_name = trim(
    ($child['guardian_first_name'] ?? '') . ' ' .
    ($child['guardian_last_name'] ?? '')
);

if(empty($guardian_full_name) && !empty($child['guardian_name'])){
    $guardian_full_name = $child['guardian_name'];
}

if(empty($guardian_full_name)){
    $guardian_full_name = "No guardian linked yet";
}

$age = "N/A";
$age_months = "N/A";

if(!empty($child['birthdate']) && $child['birthdate'] != '0000-00-00'){
    $birthdate = new DateTime($child['birthdate']);
    $today = new DateTime();
    $diff = $today->diff($birthdate);

    $age = $diff->y . " year(s) old";
    $age_months = ($diff->y * 12) + $diff->m;
}

$access_code = !empty($child['access_code']) ? $child['access_code'] : 'N/A';
$sex = !empty($child['sex']) ? $child['sex'] : 'N/A';
$birthdate_display = (!empty($child['birthdate']) && $child['birthdate'] != '0000-00-00')
    ? date("F d, Y", strtotime($child['birthdate']))
    : 'N/A';

$child_address = !empty($child['address']) ? $child['address'] : 'N/A';
$cdc_name = !empty($child['cdc_name']) ? $child['cdc_name'] : 'N/A';
$cdc_address = !empty($child['cdc_address']) ? $child['cdc_address'] : 'N/A';

$relationship_to_child = !empty($child['relationship_to_child']) 
    ? $child['relationship_to_child'] 
    : 'N/A';

$guardian_is_linked = ($guardian_full_name !== "No guardian linked yet");

$guardian_contact_number = $guardian_is_linked
    ? (!empty($child['guardian_contact_number'])
        ? $child['guardian_contact_number']
        : (!empty($child['contact_number']) ? $child['contact_number'] : 'N/A'))
    : 'N/A';

$guardian_email = !empty($child['guardian_email']) 
    ? $child['guardian_email'] 
    : 'N/A';

$guardian_address = $guardian_is_linked
    ? (!empty($child['guardian_address'])
        ? $child['guardian_address']
        : (!empty($child['address']) ? $child['address'] : 'N/A'))
    : 'N/A';

// Detect incomplete guardian profile
$guardian_profile_incomplete = false;

if (
    !empty($child['guardian_email']) &&
    (
        empty($child['relationship_to_child']) ||
        empty($child['guardian_contact_number'])
    )
) {
    $guardian_profile_incomplete = true;
}

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

/* ============================================================
   HELPER: display a value, falling back to N/A when empty
============================================================ */
function displayOrNA($value) {
    $value = trim((string) $value);
    return ($value === '') ? 'N/A' : htmlspecialchars($value);
}

/* ============================================================
   HELPER: turn a list of ['label' => 0/1] checkbox columns into
   a comma-separated list of the checked labels, or "None"
============================================================ */
function checkedLabelsOrNone(array $flags) {
    $checked = [];
    foreach ($flags as $label => $isChecked) {
        if (!empty($isChecked)) {
            $checked[] = $label;
        }
    }
    return empty($checked) ? 'None' : htmlspecialchars(implode(', ', $checked));
}

/* ============================================================
   Siblings and Prior ECCD Experience (Form 2) are now stored
   directly as text on the `children` row itself
   ($child['siblings_info'], $child['eccd_experience_info']) —
   already included via `children.*` in the main query above.
   No separate query needed here anymore.
============================================================ */

$guardian_submission = null;
$guardian_submission_message = '';
$guardian_submission_message_type = 'success';
$guardian_submission_feature_enabled = false;

$table_check_sql = "SHOW TABLES LIKE 'child_health_information_requests'";
$table_check_result = $conn->query($table_check_sql);

if ($table_check_result && $table_check_result->num_rows > 0) {
    $guardian_submission_feature_enabled = true;
}

if ($guardian_submission_feature_enabled && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardian_submission_action'])) {
    $request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    $action = trim($_POST['guardian_submission_action']);
    $reviewed_by = (int)$_SESSION['user_id'];

    $submission_sql = "
        SELECT *
        FROM child_health_information_requests
        WHERE request_id = ?
          AND child_id = ?
          AND status = 'Pending'
        LIMIT 1
    ";

    $submission_stmt = $conn->prepare($submission_sql);

    if ($submission_stmt) {
        $submission_stmt->bind_param("ii", $request_id, $child_id);
        $submission_stmt->execute();
        $submission_result = $submission_stmt->get_result();
        $submission_row = $submission_result->fetch_assoc();
        $submission_stmt->close();

        if ($submission_row) {
            if ($action === 'approve') {
                $check_health_sql = "SELECT child_id FROM child_health_information WHERE child_id = ? LIMIT 1";
                $check_health_stmt = $conn->prepare($check_health_sql);

                if ($check_health_stmt) {
                    $check_health_stmt->bind_param("i", $child_id);
                    $check_health_stmt->execute();
                    $check_health_result = $check_health_stmt->get_result();
                    $health_exists = $check_health_result->num_rows > 0;
                    $check_health_stmt->close();

                    if ($health_exists) {
                        $update_health_sql = "
                            UPDATE child_health_information
                            SET born_at = ?,
                                learns_at_home_with = ?,
                                plays_with_older_siblings = ?,
                                plays_with_younger_siblings = ?,
                                plays_with_neighbors = ?,
                                has_meal_before_school = ?,
                                food_normally_eaten = ?,
                                has_baon = ?,
                                travel_time_to_dcc_minutes = ?,
                                travel_mode_to_dcc = ?,
                                travel_time_to_ncdc_minutes = ?,
                                travel_mode_to_ncdc = ?,
                                public_transport_type = ?,
                                goes_to_school_with = ?,
                                has_eccd_card = ?,
                                has_mother_child_book = ?,
                                has_other_health_record = ?,
                                vaccine_bcg = ?,
                                vaccine_dpt = ?,
                                vaccine_opv = ?,
                                vaccine_hepab = ?,
                                vaccine_measles = ?,
                                vaccine_others_name = ?,
                                vaccine_others_status = ?,
                                is_left_handed = ?
                            WHERE child_id = ?
                        ";
                        $update_health_stmt = $conn->prepare($update_health_sql);

                        if ($update_health_stmt) {
                            $update_health_stmt->bind_param(
                                "ssssssssisisssiisssssssssi",
                                $submission_row['born_at'],
                                $submission_row['learns_at_home_with'],
                                $submission_row['plays_with_older_siblings'],
                                $submission_row['plays_with_younger_siblings'],
                                $submission_row['plays_with_neighbors'],
                                $submission_row['has_meal_before_school'],
                                $submission_row['food_normally_eaten'],
                                $submission_row['has_baon'],
                                $submission_row['travel_time_to_dcc_minutes'],
                                $submission_row['travel_mode_to_dcc'],
                                $submission_row['travel_time_to_ncdc_minutes'],
                                $submission_row['travel_mode_to_ncdc'],
                                $submission_row['public_transport_type'],
                                $submission_row['goes_to_school_with'],
                                $submission_row['has_eccd_card'],
                                $submission_row['has_mother_child_book'],
                                $submission_row['has_other_health_record'],
                                $submission_row['vaccine_bcg'],
                                $submission_row['vaccine_dpt'],
                                $submission_row['vaccine_opv'],
                                $submission_row['vaccine_hepab'],
                                $submission_row['vaccine_measles'],
                                $submission_row['vaccine_others_name'],
                                $submission_row['vaccine_others_status'],
                                $submission_row['is_left_handed'],
                                $child_id
                            );
                            $update_health_stmt->execute();
                            $update_health_stmt->close();
                        }
                    } else {
                        $insert_health_sql = "
                            INSERT INTO child_health_information (
                                child_id,
                                born_at,
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
                                is_left_handed
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ";
                        $insert_health_stmt = $conn->prepare($insert_health_sql);

                        if ($insert_health_stmt) {
                            $insert_health_stmt->bind_param(
                                "issssssssisisssiisssssssss",
                                $child_id,
                                $submission_row['born_at'],
                                $submission_row['learns_at_home_with'],
                                $submission_row['plays_with_older_siblings'],
                                $submission_row['plays_with_younger_siblings'],
                                $submission_row['plays_with_neighbors'],
                                $submission_row['has_meal_before_school'],
                                $submission_row['food_normally_eaten'],
                                $submission_row['has_baon'],
                                $submission_row['travel_time_to_dcc_minutes'],
                                $submission_row['travel_mode_to_dcc'],
                                $submission_row['travel_time_to_ncdc_minutes'],
                                $submission_row['travel_mode_to_ncdc'],
                                $submission_row['public_transport_type'],
                                $submission_row['goes_to_school_with'],
                                $submission_row['has_eccd_card'],
                                $submission_row['has_mother_child_book'],
                                $submission_row['has_other_health_record'],
                                $submission_row['vaccine_bcg'],
                                $submission_row['vaccine_dpt'],
                                $submission_row['vaccine_opv'],
                                $submission_row['vaccine_hepab'],
                                $submission_row['vaccine_measles'],
                                $submission_row['vaccine_others_name'],
                                $submission_row['vaccine_others_status'],
                                $submission_row['is_left_handed']
                            );
                            $insert_health_stmt->execute();
                            $insert_health_stmt->close();
                        }
                    }

                    // birth_order / siblings_info / eccd_experience_info live on the
                    // `children` row itself (not child_health_information), so they
                    // get a separate, always-an-UPDATE statement — the children row
                    // already exists for this child.
                    $update_children_sql = "
                        UPDATE children
                        SET birth_order = ?,
                            siblings_info = ?,
                            eccd_experience_info = ?
                        WHERE child_id = ?
                    ";
                    $update_children_stmt = $conn->prepare($update_children_sql);

                    if ($update_children_stmt) {
                        $update_children_stmt->bind_param(
                            "issi",
                            $submission_row['birth_order'],
                            $submission_row['siblings_info'],
                            $submission_row['eccd_experience_info'],
                            $child_id
                        );
                        $update_children_stmt->execute();
                        $update_children_stmt->close();
                    }

                    $approve_sql = "
                        UPDATE child_health_information_requests
                        SET status = 'Approved',
                            reviewed_by = ?,
                            reviewed_at = NOW()
                        WHERE request_id = ?
                    ";
                    $approve_stmt = $conn->prepare($approve_sql);

                    if ($approve_stmt) {
                        $approve_stmt->bind_param("ii", $reviewed_by, $request_id);
                        $approve_stmt->execute();
                        $approve_stmt->close();
                    }

                    header("Location: child_profile.php?child_id=" . $child_id . "&guardian_submission=applied");
                    exit();
                }
            }

            if ($action === 'reject') {
                $reject_reason = trim($_POST['reject_reason'] ?? '');

                if ($reject_reason === '') {
                    header("Location: child_profile.php?child_id=" . $child_id . "&guardian_submission=reject_error");
                    exit();
                }

                $reject_sql = "
                    UPDATE child_health_information_requests
                    SET status = 'Rejected',
                        reviewed_by = ?,
                        reviewed_at = NOW(),
                        review_remarks = ?
                    WHERE request_id = ?
                ";
                $reject_stmt = $conn->prepare($reject_sql);

                if ($reject_stmt) {
                    $reject_stmt->bind_param("isi", $reviewed_by, $reject_reason, $request_id);
                    $reject_stmt->execute();
                    $reject_stmt->close();
                }

                header("Location: child_profile.php?child_id=" . $child_id . "&guardian_submission=rejected");
                exit();
            }
        }
    }
}

if ($guardian_submission_feature_enabled && isset($_GET['guardian_submission'])) {
    if ($_GET['guardian_submission'] === 'applied') {
        $guardian_submission_message = "Guardian health information was approved and applied to the child's official health record.";
    } elseif ($_GET['guardian_submission'] === 'rejected') {
        $guardian_submission_message = "Guardian health information submission was rejected.";
    } elseif ($_GET['guardian_submission'] === 'reject_error') {
        $guardian_submission_message = "Rejection was not saved. A reason is required before you can reject a submission.";
        $guardian_submission_message_type = 'error';
    }
}

if ($guardian_submission_feature_enabled) {
    $guardian_submission_sql = "
    SELECT *
    FROM child_health_information_requests
    WHERE child_id = ?
      AND status = 'Pending'
    ORDER BY submitted_at DESC, request_id DESC
    LIMIT 1
";

    $guardian_submission_stmt = $conn->prepare($guardian_submission_sql);

    if ($guardian_submission_stmt) {
        $guardian_submission_stmt->bind_param("i", $child_id);
        $guardian_submission_stmt->execute();
        $guardian_submission_result = $guardian_submission_stmt->get_result();
        $guardian_submission = $guardian_submission_result->fetch_assoc();
        $guardian_submission_stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Child Profile | NutriTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
    <link rel="stylesheet" href="../assets/cdw/child_profile.css">
    <link rel="stylesheet" href="../assets/cdw/cdw-topbar-notification.css">

    <style>
        body.dark-mode{
            background:#0f172a;
            color:#e5e7eb;
        }

        body.dark-mode .back-link{
            color:#86efac;
        }

        body.dark-mode .profile-header,
        body.dark-mode .info-card,
        body.dark-mode .side-action-card{
            background:#111827;
            border-color:#334155;
        }

        body.dark-mode .section-label,
        body.dark-mode .profile-subtext,
        body.dark-mode .info-label,
        body.dark-mode .monitoring-text{
            color:#cbd5e1;
        }

        body.dark-mode .profile-title,
        body.dark-mode .card-title,
        body.dark-mode .sub-section-title,
        body.dark-mode .monitoring-title,
        body.dark-mode .info-value{
            color:#f8fafc;
        }

        body.dark-mode .info-row{
            border-bottom-color:#334155;
        }

        body.dark-mode .access-code-badge{
            background:#1e293b;
            color:#f8fafc;
            border:1px solid #334155;
        }

        body.dark-mode .btn-edit{
            background:#1e293b;
            color:#f8fafc;
            border:1px solid #334155;
        }

        body.dark-mode .btn-monitoring{
            background:#2E7D32;
            color:#ffffff;
        }

         body.dark-mode .btn-delete{
            background:#1e293b;
            color:#f8fafc;
            border:1px solid #334155;
        }
        .btn-delete {
        background: #E74C3C;
        color: #fff;
        padding: 10px 14px;
        border-radius: 6px;
        text-decoration: none;
        margin-left: 10px;
        display: inline-block;
        font-family: 'Inter', sans-serif; 
        font-size: 13px;
        font-weight: 600;
        border: none;
        cursor: pointer;
        }

.btn-delete:hover {
    background: #c0392b;
}


        .details-grid{
            display:grid;
            grid-template-columns:repeat(2, 1fr);
            gap:18px;
            margin-top:18px;
        }

        .card-title-toggle{
            display:flex;
            align-items:center;
            justify-content:space-between;
            cursor:pointer;
            user-select:none;
            margin-bottom:0;
        }

        .card-title-toggle .toggle-icon{
            font-size:14px;
            color:#2E7D32;
            transition:transform 0.2s ease;
            margin-left:10px;
        }

        .card-title-toggle.open .toggle-icon{
            transform:rotate(180deg);
        }

        .info-list.collapsed{
            display:none;
        }

        .details-grid .info-card .info-list:not(.collapsed){
            margin-top:16px;
        }

        @media (max-width: 991px){
            .details-grid{
                grid-template-columns:1fr;
            }
        }
    </style>
</head>
<body class="<?php echo ($theme_mode === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <a href="child_list.php" class="back-link">← Back to Pupil List</a>

    <div class="profile-header">
        <div class="profile-title-wrap">
            <span class="section-label">Child Profile</span>
            <h1 class="profile-title"><?php echo strtoupper(htmlspecialchars($child['first_name'] . " " . $child['last_name'])); ?></h1>
            <div class="profile-subtext">
                Active CDC: <?php echo htmlspecialchars($_SESSION['active_cdc_name']); ?>
            </div>
        </div>

        <div class="access-code-badge">
            Access Code: <?php echo htmlspecialchars($access_code); ?>
        </div>
    </div>

    <div class="content-grid">
        <div class="info-card">
            <h3 class="card-title">Child Information</h3>

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
                        <?php
                        echo !empty($child['religion'])
                            ? htmlspecialchars($child['religion'])
                            : 'N/A';
                        ?>
                    </div>
                </div>

                <div class="info-row">
                    <span class="info-label">Registered (Yes/No)</span>
                    <div class="info-value"><?php echo displayOrNA($child['is_registered'] ?? ''); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">First / Second Language</span>
                    <div class="info-value">
                        <?php echo displayOrNA($child['first_language'] ?? ''); ?>
                        /
                        <?php echo displayOrNA($child['second_language'] ?? ''); ?>
                    </div>
                </div>

                <div class="info-row">
                    <span class="info-label">Birth Order / Born At</span>
                    <div class="info-value">
                        <?php echo displayOrNA($child['birth_order'] ?? ''); ?>
                        —
                        <?php echo displayOrNA($child['born_at'] ?? ''); ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($guardian_submission_message)) { ?>
                <div class="guardian-submission-message <?php echo htmlspecialchars($guardian_submission_message_type); ?>">
                    <?php echo htmlspecialchars($guardian_submission_message); ?>
                </div>
            <?php } ?>

            <?php if ($guardian_submission_feature_enabled && $guardian_submission) { ?>
                <h4 class="sub-section-title guardian-submission-title">Guardian Submitted Health Information</h4>

                <div class="guardian-submission-box">
                    <div class="info-list">
                        <div class="info-row">
                            <span class="info-label">Guardian Name</span>
                            <div class="info-value"><?php echo htmlspecialchars($guardian_full_name); ?></div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Date Submitted</span>
                            <div class="info-value"><?php echo htmlspecialchars(date("F d, Y g:i A", strtotime($guardian_submission['submitted_at']))); ?></div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Status</span>
                            <div class="info-value pending-status"><?php echo htmlspecialchars($guardian_submission['status']); ?></div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Birth Order</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['birth_order']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_birth_order); ?></small>
                            </div>
                        </div>

                        <div class="info-row full">
                            <span class="info-label">Siblings Info</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['siblings_info']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_siblings_info); ?></small>
                            </div>
                        </div>

                        <div class="info-row full">
                            <span class="info-label">Past ECCD Experience</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['eccd_experience_info']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_eccd_experience_info); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Born At</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['born_at']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_born_at); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Learns at Home With</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['learns_at_home_with']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_learns_at_home_with); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Plays with Older Siblings</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['plays_with_older_siblings']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_plays_with_older_siblings); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Plays with Younger Siblings</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['plays_with_younger_siblings']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_plays_with_younger_siblings); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Plays with Neighbors</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['plays_with_neighbors']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_plays_with_neighbors); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Has Meal Before School</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['has_meal_before_school']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_has_meal_before_school); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Food Normally Eaten</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['food_normally_eaten']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_food_normally_eaten); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Has Baon</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['has_baon']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_has_baon); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Travel to DCC</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['travel_time_to_dcc_minutes']); ?> — <?php echo displayOrNA($guardian_submission['travel_mode_to_dcc']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_travel_time_to_dcc_minutes); ?> — <?php echo htmlspecialchars($official_travel_mode_to_dcc); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Travel to NCDC</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['travel_time_to_ncdc_minutes']); ?> — <?php echo displayOrNA($guardian_submission['travel_mode_to_ncdc']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_travel_time_to_ncdc_minutes); ?> — <?php echo htmlspecialchars($official_travel_mode_to_ncdc); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Public Transport Type</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['public_transport_type']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_public_transport_type); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Goes to School With</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['goes_to_school_with']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_goes_to_school_with); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Has ECCD Card</span>
                            <div class="info-value">
                                <?php echo !empty($guardian_submission['has_eccd_card']) ? 'Yes' : 'No'; ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_has_eccd_card); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Has Mother-Child Book</span>
                            <div class="info-value">
                                <?php echo !empty($guardian_submission['has_mother_child_book']) ? 'Yes' : 'No'; ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_has_mother_child_book); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Other Health Record</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['has_other_health_record']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_has_other_health_record); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Vaccine — BCG</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['vaccine_bcg']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_vaccine_bcg); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Vaccine — DPT</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['vaccine_dpt']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_vaccine_dpt); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Vaccine — OPV</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['vaccine_opv']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_vaccine_opv); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Vaccine — Hepatitis B</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['vaccine_hepab']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_vaccine_hepab); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Vaccine — Measles</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['vaccine_measles']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_vaccine_measles); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Vaccine — Others</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['vaccine_others_name']); ?> — <?php echo displayOrNA($guardian_submission['vaccine_others_status']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_vaccine_others_name); ?> — <?php echo htmlspecialchars($official_vaccine_others_status); ?></small>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Left-Handed</span>
                            <div class="info-value">
                                <?php echo displayOrNA($guardian_submission['is_left_handed']); ?>
                                <br><small>Current: <?php echo htmlspecialchars($official_is_left_handed); ?></small>
                            </div>
                        </div>
                    </div>

                    <div class="guardian-submission-actions">
                        <form method="POST" class="guardian-submission-form">
            <input type="hidden" name="request_id" value="<?php echo (int)$guardian_submission['request_id']; ?>">
            <button type="submit" name="guardian_submission_action" value="approve" class="btn-guardian-approve">
                Approve / Apply
            </button>
        </form>

        <form method="POST" class="guardian-submission-form" id="rejectSubmissionForm">
            <input type="hidden" name="request_id" value="<?php echo (int)$guardian_submission['request_id']; ?>">
            <input type="hidden" name="guardian_submission_action" value="reject">
            <input type="hidden" name="reject_reason" id="rejectReasonInput">
            <button type="button" class="btn-guardian-reject" onclick="openRejectModal()">
                Reject
            </button>
        </form>
                    </div>
                </div>

                <div class="reject-modal-overlay" id="rejectModalOverlay">
                    <div class="reject-modal-box">
                        <h4 class="reject-modal-title">Reject Health Information Submission</h4>
                        <p class="reject-modal-text">Please select a reason for rejecting this submission. The guardian will see this reason and can resubmit.</p>

                        <select id="rejectReasonSelect" class="reject-modal-select" onchange="toggleRejectOtherField()">
                            <option value="">Select reason</option>
                            <option value="Incomplete or missing required information">Incomplete or missing required information</option>
                            <option value="Submitted information does not match the child's records">Submitted information does not match the child's records</option>
                            <option value="Others">Others (please specify)</option>
                        </select>

                        <textarea id="rejectReasonOther" class="reject-modal-textarea" rows="3" placeholder="Type specific reason..." style="display:none; margin-top:10px;"></textarea>

                        <div class="reject-modal-error" id="rejectReasonError">Reason is required before you can reject.</div>
                        <div class="reject-modal-actions">
                            <button type="button" class="btn-reject-cancel" onclick="closeRejectModal()">Cancel</button>
                            <button type="button" class="btn-reject-confirm" onclick="confirmRejectSubmission()">Confirm Reject</button>
                        </div>
                    </div>
                </div>
            <?php } ?>

            <div class="card-actions">
                <a href="edit_child.php?child_id=<?php echo $child['child_id']; ?>" class="btn-edit">Edit Child Information</a>
            </div>

            <div class="reject-modal-overlay" id="deleteModalOverlay">
                <div class="reject-modal-box">
                    <h4 class="reject-modal-title">Delete Child Record</h4>
                    <p class="reject-modal-text">
                        Are you sure you want to delete <strong><?php echo htmlspecialchars($child_full_name); ?></strong>?
                        This will also hide all related anthropometric, feeding, milk feeding, and deworming records.
                        You can restore this record within 30 days from the Deleted Children page.
                    </p>

                    <div class="reject-modal-actions">
                        <button type="button" class="btn-reject-cancel" onclick="closeDeleteModal()">Cancel</button>
                        <button type="button" class="btn-reject-confirm" onclick="confirmDeleteChild()">Yes, Delete</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="info-card">
            <h3 class="card-title">Guardian Information</h3>

            <div class="info-list">
                <div class="info-row">
                    <span class="info-label">Parent/Guardian Name</span>
                    <div class="info-value"><?php echo htmlspecialchars($guardian_full_name); ?></div>
                </div>

                <div class="info-row">
    <span class="info-label">Relationship to the Child</span>
    <div class="info-value">
        <?php 
            echo htmlspecialchars($relationship_to_child); 

            if ($guardian_profile_incomplete && $relationship_to_child === 'N/A') {
                echo '<br><small style="color:#b45309;">Guardian profile is incomplete. Please update guardian information.</small>';
            }
        ?>
    </div>
</div>

                <div class="info-row">
                    <span class="info-label">Guardian Address</span>
                    <div class="info-value"><?php echo htmlspecialchars($guardian_address); ?></div>
                </div>

                <div class="info-row">
    <span class="info-label">Contact Number</span>
    <div class="info-value">
        <?php 
            echo htmlspecialchars($guardian_contact_number); 

            if ($guardian_profile_incomplete && $guardian_contact_number === 'N/A') {
                echo '<br><small style="color:#b45309;">Contact number is missing from the guardian profile.</small>';
            }
        ?>
    </div>
</div>

                <div class="info-row">
                    <span class="info-label">Email</span>
                    <div class="info-value"><?php echo htmlspecialchars($guardian_email); ?></div>
                </div>
            </div>

            <div class="card-actions">
                <a href="edit_guardian.php?child_id=<?php echo $child['child_id']; ?>" class="btn-edit">Edit Guardian Information</a>
            </div>
        </div>

        <div class="side-action-card">
            <div>
                <h3 class="monitoring-title">Nutritional Monitoring</h3>
                <p class="monitoring-text">
                    Open this child’s nutritional monitoring page to view nutritional status, feeding, milk feeding, deworming, and growth monitoring records.
                </p>
            </div>

            <a href="nutritional_monitoring.php?child_id=<?php echo $child['child_id']; ?>" class="btn-monitoring">
                Open Nutritional Monitoring
            </a>
        </div>
    </div>

    <div class="details-grid">
        <div class="info-card">
            <h3 class="card-title card-title-toggle" onclick="toggleDetailCard(this)">
                Mother's Information
                <span class="toggle-icon">&#9662;</span>
            </h3>

            <div class="info-list collapsed">
                <div class="info-row">
                    <span class="info-label">Mother's Name</span>
                    <div class="info-value"><?php echo displayOrNA($child['mother_name'] ?? ''); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Mother's Occupation / Address</span>
                    <div class="info-value">
                        <?php echo displayOrNA($child['mother_occupation'] ?? ''); ?>
                        —
                        <?php echo displayOrNA($child['mother_address'] ?? ''); ?>
                    </div>
                </div>

                <div class="info-row">
                    <span class="info-label">Mother's Contact (Home / Work)</span>
                    <div class="info-value">
                        <?php echo displayOrNA($child['mother_contact_home'] ?? ''); ?>
                        /
                        <?php echo displayOrNA($child['mother_contact_work'] ?? ''); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="info-card">
            <h3 class="card-title card-title-toggle" onclick="toggleDetailCard(this)">
                Father's Information
                <span class="toggle-icon">&#9662;</span>
            </h3>

            <div class="info-list collapsed">
                <div class="info-row">
                    <span class="info-label">Father's Name</span>
                    <div class="info-value"><?php echo displayOrNA($child['father_name'] ?? ''); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Father's Occupation / Address</span>
                    <div class="info-value">
                        <?php echo displayOrNA($child['father_occupation'] ?? ''); ?>
                        —
                        <?php echo displayOrNA($child['father_address'] ?? ''); ?>
                    </div>
                </div>

                <div class="info-row">
                    <span class="info-label">Father's Contact (Home / Work)</span>
                    <div class="info-value">
                        <?php echo displayOrNA($child['father_contact_home'] ?? ''); ?>
                        /
                        <?php echo displayOrNA($child['father_contact_work'] ?? ''); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="info-card">
            <h3 class="card-title card-title-toggle" onclick="toggleDetailCard(this)">
                Emergency Contact
                <span class="toggle-icon">&#9662;</span>
            </h3>

            <div class="info-list collapsed">
                <div class="info-row">
                    <span class="info-label">Emergency Contact</span>
                    <div class="info-value">
                        <?php echo displayOrNA($child['emergency_contact_name'] ?? ''); ?>
                        (<?php echo displayOrNA($child['emergency_contact_relationship'] ?? ''); ?>)
                    </div>
                </div>

                <div class="info-row">
                    <span class="info-label">Emergency Contact Number (Home / Work)</span>
                    <div class="info-value">
                        <?php echo displayOrNA($child['emergency_contact_home'] ?? ''); ?>
                        /
                        <?php echo displayOrNA($child['emergency_contact_work'] ?? ''); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="info-card">
            <h3 class="card-title card-title-toggle" onclick="toggleDetailCard(this)">
                Siblings &amp; Prior ECCD Experience
                <span class="toggle-icon">&#9662;</span>
            </h3>

            <div class="info-list collapsed">
                <div class="info-row">
                    <span class="info-label">Siblings</span>
                    <div class="info-value">
                        <?php echo nl2br(displayOrNA($child['siblings_info'] ?? '')); ?>
                    </div>
                </div>

                <div class="info-row">
                    <span class="info-label">Prior ECCD Experience</span>
                    <div class="info-value">
                        <?php echo nl2br(displayOrNA($child['eccd_experience_info'] ?? '')); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="info-card">
            <h3 class="card-title card-title-toggle" onclick="toggleDetailCard(this)">
                Health Records
                <span class="toggle-icon">&#9662;</span>
            </h3>

            <div class="info-list collapsed">
                <div class="info-row">
                    <span class="info-label">Has ECCD Card / Mother &amp; Child Book</span>
                    <div class="info-value">
                        <?php echo !empty($child['has_eccd_card']) ? 'Yes' : 'No'; ?>
                        /
                        <?php echo !empty($child['has_mother_child_book']) ? 'Yes' : 'No'; ?>
                        <?php if (!empty($child['has_other_health_record'])) { ?>
                            <br><small>Others: <?php echo htmlspecialchars($child['has_other_health_record']); ?></small>
                        <?php } ?>
                    </div>
                </div>

                <div class="info-row">
                    <span class="info-label">Vaccination (BCG / DPT / OPV / Hepa B / Measles)</span>
                    <div class="info-value">
                        <?php echo displayOrNA($child['vaccine_bcg'] ?? ''); ?> /
                        <?php echo displayOrNA($child['vaccine_dpt'] ?? ''); ?> /
                        <?php echo displayOrNA($child['vaccine_opv'] ?? ''); ?> /
                        <?php echo displayOrNA($child['vaccine_hepab'] ?? ''); ?> /
                        <?php echo displayOrNA($child['vaccine_measles'] ?? ''); ?>
                        <?php if (!empty($child['vaccine_others_name'])) { ?>
                            <br><small>Others (<?php echo htmlspecialchars($child['vaccine_others_name']); ?>): <?php echo displayOrNA($child['vaccine_others_status'] ?? ''); ?></small>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="info-card">
            <h3 class="card-title card-title-toggle" onclick="toggleDetailCard(this)">
                Physical Attributes
                <span class="toggle-icon">&#9662;</span>
            </h3>

            <div class="info-list collapsed">
                <div class="info-row">
                    <span class="info-label">Physical Deformity</span>
                    <div class="info-value">
                        <?php echo checkedLabelsOrNone([
                            'Hare Lip' => $child['deformity_hare_lip'] ?? 0,
                            'Cross-Eyed' => $child['deformity_cross_eyed'] ?? 0,
                            'Deaf' => $child['deformity_deaf'] ?? 0,
                            'Blind' => $child['deformity_blind'] ?? 0,
                            'Disabled Leg' => $child['deformity_disabled_leg'] ?? 0,
                            'Disabled Arm/Hand' => $child['deformity_disabled_arm_hand'] ?? 0,
                            'Deformity in Fingers/Toes' => $child['deformity_fingers_toes'] ?? 0,
                        ]); ?>
                    </div>
                </div>

                <div class="info-row">
                    <span class="info-label">Problems With</span>
                    <div class="info-value">
                        <?php echo checkedLabelsOrNone([
                            'Behavior' => $child['problem_behavior'] ?? 0,
                            'Speaking' => $child['problem_speaking'] ?? 0,
                            'Hearing' => $child['problem_hearing'] ?? 0,
                            'Vision' => $child['problem_vision'] ?? 0,
                        ]); ?>
                    </div>
                </div>

                <div class="info-row">
                    <span class="info-label">Left Handed</span>
                    <div class="info-value"><?php echo displayOrNA($child['is_left_handed'] ?? ''); ?></div>
                </div>
            </div>
        </div>

        <div class="info-card">
            <h3 class="card-title card-title-toggle" onclick="toggleDetailCard(this)">
                Home &amp; Learning
                <span class="toggle-icon">&#9662;</span>
            </h3>

            <div class="info-list collapsed">
                <div class="info-row">
                    <span class="info-label">Learns at Home With</span>
                    <div class="info-value"><?php echo displayOrNA($child['learns_at_home_with'] ?? ''); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Plays With (Older / Younger / Neighbors)</span>
                    <div class="info-value">
                        <?php echo displayOrNA($child['plays_with_older_siblings'] ?? ''); ?> /
                        <?php echo displayOrNA($child['plays_with_younger_siblings'] ?? ''); ?> /
                        <?php echo displayOrNA($child['plays_with_neighbors'] ?? ''); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="info-card">
            <h3 class="card-title card-title-toggle" onclick="toggleDetailCard(this)">
                Logistics
                <span class="toggle-icon">&#9662;</span>
            </h3>

            <div class="info-list collapsed">
                <div class="info-row">
                    <span class="info-label">Has Meal Before School / Food Eaten</span>
                    <div class="info-value">
                        <?php echo displayOrNA($child['has_meal_before_school'] ?? ''); ?>
                        <?php if (!empty($child['food_normally_eaten'])) { ?>
                            <br><small><?php echo htmlspecialchars($child['food_normally_eaten']); ?></small>
                        <?php } ?>
                    </div>
                </div>

                <div class="info-row">
                    <span class="info-label">Has Baon</span>
                    <div class="info-value"><?php echo displayOrNA($child['has_baon'] ?? ''); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Travel to DCC (Time / Mode)</span>
                    <div class="info-value">
                        <?php echo displayOrNA($child['travel_time_to_dcc_minutes'] ?? ''); ?> min
                        /
                        <?php echo displayOrNA($child['travel_mode_to_dcc'] ?? ''); ?>
                    </div>
                </div>

                <div class="info-row">
                    <span class="info-label">Travel to NCDC (Time / Mode)</span>
                    <div class="info-value">
                        <?php echo displayOrNA($child['travel_time_to_ncdc_minutes'] ?? ''); ?> min
                        /
                        <?php echo displayOrNA($child['travel_mode_to_ncdc'] ?? ''); ?>
                    </div>
                </div>

                <div class="info-row">
                    <span class="info-label">Public Transportation Used</span>
                    <div class="info-value"><?php echo displayOrNA($child['public_transport_type'] ?? ''); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Goes to School With</span>
                    <div class="info-value"><?php echo displayOrNA($child['goes_to_school_with'] ?? ''); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div style="margin-top:18px; text-align:right;">
        <button type="button" class="btn-delete" onclick="openDeleteModal()">
            Delete Child
        </button>
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

function toggleDetailCard(headerEl) {
    var list = headerEl.nextElementSibling;
    if (!list) return;

    list.classList.toggle('collapsed');
    headerEl.classList.toggle('open');
}

function openRejectModal() {
    var select = document.getElementById('rejectReasonSelect');
    var otherBox = document.getElementById('rejectReasonOther');
    var errorText = document.getElementById('rejectReasonError');
    var overlay = document.getElementById('rejectModalOverlay');

    if (!select || !otherBox || !errorText || !overlay) {
        return;
    }

    select.value = '';
    otherBox.value = '';
    otherBox.style.display = 'none';
    errorText.textContent = 'Reason is required before you can reject.';
    errorText.style.display = 'none';
    overlay.classList.add('show');
}

function toggleRejectOtherField() {
    var select = document.getElementById('rejectReasonSelect');
    var otherBox = document.getElementById('rejectReasonOther');

    if (!select || !otherBox) {
        return;
    }

    otherBox.style.display = (select.value === 'Others') ? 'block' : 'none';
}

function closeRejectModal() {
    var overlay = document.getElementById('rejectModalOverlay');

    if (overlay) {
        overlay.classList.remove('show');
    }
}

function confirmRejectSubmission() {
    var select = document.getElementById('rejectReasonSelect');
    var otherBox = document.getElementById('rejectReasonOther');
    var errorText = document.getElementById('rejectReasonError');
    var reasonInput = document.getElementById('rejectReasonInput');
    var form = document.getElementById('rejectSubmissionForm');

    if (!select || !otherBox || !errorText || !reasonInput || !form) {
        return;
    }

    var selectedReason = select.value;
    var finalReason = '';

    if (selectedReason === '') {
        errorText.style.display = 'block';
        return;
    }

    if (selectedReason === 'Others') {
        var otherText = otherBox.value.trim();

        if (otherText === '') {
            errorText.textContent = 'Please specify the reason.';
            errorText.style.display = 'block';
            return;
        }

        finalReason = otherText;
    } else {
        finalReason = selectedReason;
    }

    reasonInput.value = finalReason;
    form.submit();
}

function openDeleteModal() {
    var overlay = document.getElementById('deleteModalOverlay');

    if (overlay) {
        overlay.classList.add('show');
    }
}

function closeDeleteModal() {
    var overlay = document.getElementById('deleteModalOverlay');

    if (overlay) {
        overlay.classList.remove('show');
    }
}

function confirmDeleteChild() {
    window.location.href = 'delete_child.php?child_id=<?php echo (int) $child['child_id']; ?>';
}

</script>

<script src="../assets/cdw/sidebar.js"></script>

</body>
</html>