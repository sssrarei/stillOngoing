<?php
include '../includes/auth.php';
include '../config/database.php';
include '../includes/nnc_growth_standards.php';
require_once '../includes/eccd_form2.php';
require_once '../includes/eccd_form1.php';

if ($_SESSION['role_id'] != 2) {
    header("Location: ../login.php");
    exit();
}

if (!isset($_SESSION['active_cdc_id'])) {
    die("Please select an active CDC first from the dashboard.");
}

$active_cdc_id = (int) $_SESSION['active_cdc_id'];
$child_id = isset($_GET['child_id']) ? (int) $_GET['child_id'] : 0;

if ($child_id <= 0) {
    die("Invalid child selected.");
}

$success = "";
$error = "";

/* ============================================================
   HELPERS (same conventions as add_child.php)
============================================================ */
function checkboxListToString($postArray) {
    if (!is_array($postArray) || empty($postArray)) {
        return null;
    }
    $clean = array_map('trim', $postArray);
    $clean = array_filter($clean, function ($v) { return $v !== ''; });
    if (empty($clean)) {
        return null;
    }
    return implode(', ', $clean);
}

function blankToNull($value) {
    $value = trim((string) $value);
    return ($value === '') ? null : $value;
}

/* Matches the exact same helper used in anthropometric_records.php,
   so age-in-months is computed identically everywhere. */
function compute_age_months_exact($birthdate, $date_recorded) {
    if (empty($birthdate) || empty($date_recorded)) return null;
    try {
        $birth = new DateTime($birthdate);
        $record = new DateTime($date_recorded);
        if ($record < $birth) return null;
        $diff = $birth->diff($record);
        return ($diff->y * 12) + $diff->m;
    } catch (Exception $e) {
        return null;
    }
}

// Turn a stored comma-separated string back into an array, for
// checking which checkboxes should be pre-checked on page load.
function stringToCheckedArray($value) {
    if (empty($value)) {
        return [];
    }
    return array_map('trim', explode(',', $value));
}

/* ============================================================
   LOAD CHILD RECORD (must belong to this CDW's active CDC)
============================================================ */
$child_stmt = $conn->prepare("SELECT * FROM children WHERE child_id = ? AND cdc_id = ? LIMIT 1");
$child_stmt->bind_param("ii", $child_id, $active_cdc_id);
$child_stmt->execute();
$child_result = $child_stmt->get_result();

if ($child_result->num_rows === 0) {
    die("Child not found or not assigned to the active CDC.");
}

$child = $child_result->fetch_assoc();
$child_stmt->close();

/* ============================================================
   LOAD CHILD HEALTH INFO (vaccination card / allergies / etc.)
============================================================ */
$health_stmt = $conn->prepare("SELECT * FROM child_health_information WHERE child_id = ? LIMIT 1");
$health_stmt->bind_param("i", $child_id);
$health_stmt->execute();
$health_result = $health_stmt->get_result();
$health = ($health_result->num_rows > 0) ? $health_result->fetch_assoc() : null;
$health_stmt->close();

/* ============================================================
   Siblings and Prior ECCD Experience are now plain text columns
   on `children` (siblings_info, eccd_experience_info) — already
   loaded as part of $child above, no separate query needed.
============================================================ */

/* ============================================================
   RELATIONSHIP OPTIONS (shared with register.php / Emergency Contact)
============================================================ */
$relationship_options = ['Mother','Father','Grandmother','Grandfather','Guardian','Aunt','Uncle','Sibling','Other'];

/* ============================================================
   LANGUAGE OPTIONS (shared with register.php)
============================================================ */
$language_options = ['Tagalog','Bisaya/Cebuano','Ilocano','Bicolano','Hiligaynon/Ilonggo','Waray','Kapampangan','Pangasinan','Chavacano'];

/* ============================================================
   Form 2 health fields that the Child Profile reads from
   child_health_information (kept in sync on save, and used
   to pre-fill the form below)
============================================================ */
$chi_cols = array(
    'born_at', 'learns_at_home_with', 'plays_with_older_siblings',
    'plays_with_younger_siblings', 'plays_with_neighbors',
    'has_meal_before_school', 'food_normally_eaten', 'has_baon',
    'travel_time_to_dcc_minutes', 'travel_mode_to_dcc',
    'travel_time_to_ncdc_minutes', 'travel_mode_to_ncdc',
    'public_transport_type', 'goes_to_school_with',
    'has_eccd_card', 'has_mother_child_book', 'has_other_health_record',
    'vaccine_bcg', 'vaccine_dpt', 'vaccine_opv', 'vaccine_hepab',
    'vaccine_measles', 'vaccine_others_name', 'vaccine_others_status',
    'is_left_handed'
);

/* ============================================================
   HANDLE FORM SUBMISSION
============================================================ */
if (isset($_POST['update'])) {

    /* ---- Child Information (Form 2 items 1-2) ---- */
    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $birthdate   = trim($_POST['birthdate'] ?? '');
    $sex         = trim($_POST['sex'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $religion    = trim($_POST['religion'] ?? '');

    /* ---- Growth Measurements (not stored on children; see below) ---- */
    $height_cm = ($_POST['height_cm'] ?? '') !== '' ? (float) $_POST['height_cm'] : null;
    $weight_kg = ($_POST['weight_kg'] ?? '') !== '' ? (float) $_POST['weight_kg'] : null;

    /* ---- Form 2 items 3-16 (read + validated in includes/eccd_form2.php) ---- */
    $form_errors = array();
    $f2 = eccd_f2_collect($_POST, $form_errors);

    /* ---- Form 1 - Home Profile (1.A, 1.B, 1.C) ---- */
    $f1 = eccd_f1_collect($_POST, $form_errors);
    // make sure the home_profile column exists BEFORE the transaction starts (ALTER commits implicitly)
    $f1_ready = eccd_f1_storage_ready($conn);

    /* ---- Health info (existing feature, unchanged) ---- */
    $allergies      = trim($_POST['allergies'] ?? '');
    $comorbidities  = trim($_POST['comorbidities'] ?? '');
    $vaccination_card_path = $health['vaccination_card_file_path'] ?? "";
    $medical_history_path  = $health['medical_history_file_path'] ?? "";

    if (empty($first_name) || empty($last_name) || empty($birthdate) || empty($sex)) {
        $error = "Please fill in all required child information fields.";
    } elseif (!in_array($sex, array('Male', 'Female'), true)) {
        $error = "Please choose Male or Female for Sex.";
    } elseif (!empty($form_errors)) {
        $error = implode(' ', $form_errors);
    } else {

        $conn->begin_transaction();

        try {
            /* ----------------------------------------------
               1. Handle file uploads (unchanged behavior)
            ---------------------------------------------- */
            if (isset($_FILES['vaccination_card']) && $_FILES['vaccination_card']['error'] == 0) {
                $vacc_name = time() . "_vacc_" . basename($_FILES['vaccination_card']['name']);
                $vacc_target = "../uploads/vaccination_cards/" . $vacc_name;
                if (move_uploaded_file($_FILES['vaccination_card']['tmp_name'], $vacc_target)) {
                    $vaccination_card_path = "uploads/vaccination_cards/" . $vacc_name;
                }
            }

            if (isset($_FILES['medical_history_file']) && $_FILES['medical_history_file']['error'] == 0) {
                $med_name = time() . "_med_" . basename($_FILES['medical_history_file']['name']);
                $med_target = "../uploads/medical_history/" . $med_name;
                if (move_uploaded_file($_FILES['medical_history_file']['tmp_name'], $med_target)) {
                    $medical_history_path = "uploads/medical_history/" . $med_name;
                }
            }

            /* ----------------------------------------------
               2. Update the children record (all Form 1 + Form 2 fields)
            ---------------------------------------------- */
            $base = array(
                'first_name'  => array('s', $first_name),
                'middle_name' => array('s', $middle_name),
                'last_name'   => array('s', $last_name),
                'birthdate'   => array('s', $birthdate),
                'sex'         => array('s', $sex),
                'address'     => array('s', $address),
                'religion'    => array('s', $religion),
            );
            list($col_names, $col_types, $col_values) = eccd_f2_build_set($f2, $base);

            $set_sql = implode(', ', array_map(function ($c) { return "`$c` = ?"; }, $col_names));
            $update_sql = "UPDATE children SET $set_sql WHERE child_id = ? AND cdc_id = ?";

            $update_stmt = $conn->prepare($update_sql);
            if (!$update_stmt) {
                throw new Exception("Failed to prepare child update: " . $conn->error);
            }

            $col_types .= 'ii';
            $col_values[] = $child_id;
            $col_values[] = $active_cdc_id;
            $update_stmt->bind_param($col_types, ...$col_values);

            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update child profile: " . $update_stmt->error);
            }
            $update_stmt->close();

            /* ----------------------------------------------
               2b. Save Form 1 - Home Profile (children.home_profile)
            ---------------------------------------------- */
            $f1_error = '';
            if (!eccd_f1_save($conn, $child_id, $f1, $f1_error)) {
                throw new Exception($f1_error);
            }
            if (eccd_f1_has_any($f1)) {
                eccd_f1_sync_children($conn, $child_id, $f1);
            }

            /* ----------------------------------------------
               Siblings and Prior ECCD Experience are now saved
               directly as plain text columns in the UPDATE above
               (siblings_info, eccd_experience_info) — no separate
               delete/insert step needed.
            ---------------------------------------------- */

            /* ----------------------------------------------
               3. Optional growth measurement update. If a 'baseline'
                  record already exists for this child, UPDATE it
                  (editing here is correcting the child's profile,
                  not logging a new monthly check-up — that belongs
                  to anthropometric_records.php). Only insert a new
                  baseline row if none exists yet.
            ---------------------------------------------- */
            if ($height_cm !== null || $weight_kg !== null) {
                $recorded_by = (int) $_SESSION['user_id'];
                $today = date('Y-m-d');
                $height_val = $height_cm ?? 0;
                $weight_val = $weight_kg ?? 0;

                $age_months_val = compute_age_months_exact($birthdate, $today);
                $wfa_status_val = nnc_get_wfa_status($conn, $sex, $age_months_val, $weight_val);
                $hfa_status_val = nnc_get_hfa_status($conn, $sex, $age_months_val, $height_val);
                $wflh_status_val = nnc_get_wflh_status($conn, $sex, $age_months_val, $height_val, $weight_val);

                $baseline_check = $conn->prepare("SELECT record_id FROM anthropometric_records WHERE child_id = ? AND assessment_type = 'baseline' LIMIT 1");
                $baseline_check->bind_param("i", $child_id);
                $baseline_check->execute();
                $baseline_row = $baseline_check->get_result()->fetch_assoc();
                $baseline_check->close();

                if ($baseline_row) {
                    $anthro_stmt = $conn->prepare("
                        UPDATE anthropometric_records
                        SET height = ?, weight = ?, date_recorded = ?, age_months = ?,
                            wfa_status = ?, hfa_status = ?, wflh_status = ?, recorded_by = ?
                        WHERE record_id = ?
                    ");
                    $anthro_stmt->bind_param(
                        "ddsisssii",
                        $height_val, $weight_val, $today, $age_months_val,
                        $wfa_status_val, $hfa_status_val, $wflh_status_val, $recorded_by,
                        $baseline_row['record_id']
                    );
                } else {
                    $anthro_stmt = $conn->prepare("
                        INSERT INTO anthropometric_records
                            (child_id, height, weight, muac, date_recorded, age_months, assessment_type, wfa_status, hfa_status, wflh_status, recorded_by)
                        VALUES (?, ?, ?, 0, ?, ?, 'baseline', ?, ?, ?, ?)
                    ");
                    $anthro_stmt->bind_param(
                        "iddsisssi",
                        $child_id, $height_val, $weight_val, $today, $age_months_val,
                        $wfa_status_val, $hfa_status_val, $wflh_status_val, $recorded_by
                    );
                }

                if (!$anthro_stmt->execute()) {
                    throw new Exception("Failed to save growth measurement: " . $anthro_stmt->error);
                }
                $anthro_stmt->close();
            }

            /* ----------------------------------------------
               6. Update or insert child_health_information
                  (existing feature, unchanged logic, now with
                  prepared statements)
            ---------------------------------------------- */
            if ($health) {
                $health_update_stmt = $conn->prepare("
                    UPDATE child_health_information SET
                        vaccination_card_file_path = ?,
                        allergies = ?,
                        comorbidities = ?,
                        medical_history_file_path = ?
                    WHERE child_id = ?
                ");
                $health_update_stmt->bind_param(
                    "ssssi",
                    $vaccination_card_path, $allergies, $comorbidities, $medical_history_path, $child_id
                );
                if (!$health_update_stmt->execute()) {
                    throw new Exception("Failed to update health information: " . $health_update_stmt->error);
                }
                $health_update_stmt->close();
            } else {
                $health_insert_stmt = $conn->prepare("
                    INSERT INTO child_health_information
                        (child_id, vaccination_card_file_path, allergies, comorbidities, medical_history_file_path)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $health_insert_stmt->bind_param(
                    "issss",
                    $child_id, $vaccination_card_path, $allergies, $comorbidities, $medical_history_path
                );
                if (!$health_insert_stmt->execute()) {
                    throw new Exception("Failed to save health information: " . $health_insert_stmt->error);
                }
                $health_insert_stmt->close();
            }

            /* ----------------------------------------------
               6b. Sync Form 2 health fields into
                   child_health_information (the table the
                   Child Profile reads from)
            ---------------------------------------------- */
            $chi_types_map = eccd_f2_columns();
            $chi_sets = array();
            $chi_types = '';
            $chi_vals = array();
            foreach ($chi_cols as $col) {
                $chi_sets[] = "`$col` = ?";
                $chi_types .= $chi_types_map[$col];
                $chi_vals[] = array_key_exists($col, $f2) ? $f2[$col] : null;
            }
            $chi_sync_stmt = $conn->prepare("UPDATE child_health_information SET " . implode(', ', $chi_sets) . " WHERE child_id = ?");
            if (!$chi_sync_stmt) {
                throw new Exception("Failed to prepare health record sync: " . $conn->error);
            }
            $chi_types .= 'i';
            $chi_vals[] = $child_id;
            $chi_sync_stmt->bind_param($chi_types, ...$chi_vals);
            if (!$chi_sync_stmt->execute()) {
                throw new Exception("Failed to sync health records: " . $chi_sync_stmt->error);
            }
            $chi_sync_stmt->close();

            $conn->commit();
            $success = "Child information updated successfully!";

            /* ----------------------------------------------
               Refresh all local variables so the form below
               reflects the just-saved data
            ---------------------------------------------- */
            $child_stmt = $conn->prepare("SELECT * FROM children WHERE child_id = ? AND cdc_id = ? LIMIT 1");
            $child_stmt->bind_param("ii", $child_id, $active_cdc_id);
            $child_stmt->execute();
            $child = $child_stmt->get_result()->fetch_assoc();
            $child_stmt->close();

            $health_stmt = $conn->prepare("SELECT * FROM child_health_information WHERE child_id = ? LIMIT 1");
            $health_stmt->bind_param("i", $child_id);
            $health_stmt->execute();
            $health_result2 = $health_stmt->get_result();
            $health = ($health_result2->num_rows > 0) ? $health_result2->fetch_assoc() : null;
            $health_stmt->close();

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error: " . $e->getMessage();
        }
    }
}

/* ============================================================
   Values shown in the form (Form 1 comes from children.home_profile).
   After a failed save we show what the user typed, not the old row.
============================================================ */
if (!isset($f1)) {
    $f1 = eccd_f1_load($conn, $child_id);
}
if (!empty($success)) {
    $f1 = eccd_f1_load($conn, $child_id);
}

$form_values = $child;
if ($health) {
    foreach ($chi_cols as $col) {
        if (isset($health[$col])) {
            $form_values[$col] = $health[$col];
        }
    }
}
if (isset($f2) && !empty($error)) {
    foreach ($f2 as $k => $v) {
        $form_values[$k] = $v;
    }
    $form_values['first_name']  = $first_name;
    $form_values['middle_name'] = $middle_name;
    $form_values['last_name']   = $last_name;
    $form_values['birthdate']   = $birthdate;
    $form_values['sex']         = $sex;
    $form_values['address']     = $address;
    $form_values['religion']    = $religion;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Child Information | NutriTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
    <link rel="stylesheet" href="../assets/cdw/cdw-topbar-notification.css">

    <style>
        .page-wrapper{
            max-width:1100px;
            margin:0 auto;
        }

        .page-header{
            background:#ffffff;
            border:1px solid #dcdcdc;
            border-radius:12px;
            padding:22px 24px;
            margin-bottom:18px;
        }

        .back-link{
            display:inline-flex;
            align-items:center;
            gap:8px;
            margin-bottom:12px;
            color:#2E7D32;
            font-size:13px;
            font-weight:600;
        }

        .page-title{
            font-family:'Poppins', sans-serif;
            font-size:22px;
            font-weight:700;
            color:#2f2f2f;
            margin:0 0 8px 0;
        }

        .small-label{
            font-size:12px;
            color:#666;
        }

        .message{
            border-radius:10px;
            padding:14px 16px;
            margin-bottom:16px;
            font-size:13px;
            font-weight:600;
        }

        .message.success{
            background:#e8f5e9;
            color:#2e7d32;
            border:1px solid #c8e6c9;
        }

        .message.error{
            background:#fdeaea;
            color:#c62828;
            border:1px solid #f5c2c7;
        }

        .form-card{
            background:#ffffff;
            border:1px solid #dcdcdc;
            border-radius:12px;
            padding:20px;
            margin-bottom:18px;
        }

        .section-title{
            font-family:'Poppins', sans-serif;
            font-size:17px;
            color:#2f2f2f;
            margin:0 0 6px 0;
        }

        .section-subtitle{
            font-size:12px;
            color:#888;
            margin:0 0 16px 0;
        }

        .sub-section-title{
            font-family:'Poppins', sans-serif;
            font-size:16px;
            color:#2f2f2f;
            margin:18px 0 16px 0;
            padding-top:8px;
            border-top:1px solid #e9e9e9;
        }

        .subgroup-title{
            font-size:13px;
            font-weight:700;
            color:#2E7D32;
            margin:16px 0 10px 0;
        }

        .subgroup-title:first-child{
            margin-top:0;
        }

        .form-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:18px;
        }

        .form-grid.full-row{
            grid-template-columns:1fr;
        }

        .form-row{
            margin-bottom:14px;
        }

        .form-row:last-child{
            margin-bottom:0;
        }

        .form-label{
            display:block;
            font-size:12px;
            color:#666;
            margin-bottom:6px;
            font-weight:500;
        }

        .form-control,
        .form-select,
        .form-file{
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

        .form-control:focus,
        .form-select:focus,
        .form-file:focus{
            border-color:#2E7D32;
            box-shadow:0 0 0 3px rgba(46,125,50,0.08);
        }

        .checkbox-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:10px;
        }

        .checkbox-item{
            display:flex;
            align-items:center;
            gap:8px;
            font-size:13px;
            color:#333;
        }

        .checkbox-item input{
            width:16px;
            height:16px;
            cursor:pointer;
        }

        .checkbox-item label{
            cursor:pointer;
        }

        .radio-group{
            display:flex;
            gap:20px;
            align-items:center;
            flex-wrap:wrap;
            padding-top:4px;
        }

        .radio-group label{
            font-size:13px;
            color:#333;
            display:flex;
            align-items:center;
            gap:6px;
        }

        .other-field-group{
            display:none;
            margin-top:10px;
        }

        .other-field-group.show{
            display:block;
        }

        .form-actions{
            margin-top:18px;
            display:flex;
            justify-content:flex-end;
            gap:10px;
            flex-wrap:wrap;
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

        .btn-cancel{
            background:#e0e0e0;
            color:#444;
        }

        .btn-save{
            background:#2E7D32;
            color:#fff;
        }

        @media (max-width: 900px){
            .page-wrapper{
                max-width:100%;
            }

            .form-grid,
            .checkbox-grid{
                grid-template-columns:1fr;
            }
        }
    </style>
    <?php echo eccd_f2_css(); ?>
    <?php echo eccd_f1_css(); ?>
</head>
<body>

<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-wrapper">
        <div class="page-header">
            <a href="child_profile.php?child_id=<?php echo $child_id; ?>" class="back-link">← Back to Child Profile</a>
            <h2 class="page-title">Edit Child Information</h2>
            <div class="small-label">Active CDC: <?php echo htmlspecialchars($_SESSION['active_cdc_name']); ?></div>
        </div>

        <?php if (!empty($success)) { ?>
            <div class="message success"><?php echo htmlspecialchars($success); ?></div>
        <?php } ?>

        <?php if (!empty($error)) { ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <form method="POST" enctype="multipart/form-data">

            <?php
            /* FORM 2 - Children's Profile (items 1-16) */
            echo eccd_f2_render($form_values, array('show_growth' => true,
                'growth_note' => 'Optional. Updates the child\'s baseline height/weight. Leave blank to skip. Follow-up check-ups are logged in Anthropometric Data.'));

            /* FORM 1 - Home Profile (1.A Father, 1.B Mother, 1.C Family) */
            echo eccd_f1_render_all($f1);
            ?>

           

            <div class="form-actions">
                <button type="button" class="btn btn-cancel" onclick="window.location.href='child_profile.php?child_id=<?php echo $child_id; ?>'">Cancel</button>
                <button type="submit" name="update" class="btn btn-save">Update Child Information</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSidebar() {
    var sidebar = document.getElementById('sidebar');
    var mainContent = document.getElementById('mainContent');

    if (window.innerWidth <= 991) {
        sidebar.classList.toggle('open');
    } else {
        sidebar.classList.toggle('closed');
        mainContent.classList.toggle('full');
    }
}

</script>
<?php echo eccd_f2_js(); ?>
<?php echo eccd_f1_js(); ?>

</body>
</html>