<?php
include '../includes/auth.php';
include '../config/database.php';
include '../includes/nnc_growth_standards.php';

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
   HANDLE FORM SUBMISSION
============================================================ */
if (isset($_POST['update'])) {

    /* ---- Child Information ---- */
    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $birthdate   = trim($_POST['birthdate'] ?? '');
    $sex         = trim($_POST['sex'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $religion    = trim($_POST['religion'] ?? '');

    $birth_order = ($_POST['birth_order'] ?? '') !== '' ? (int) $_POST['birth_order'] : null;
    $born_at     = blankToNull($_POST['born_at'] ?? '');

    /* ---- Family & Registration Details (Form 1) ---- */
    $is_registered = blankToNull($_POST['is_registered'] ?? '');

    $first_language_raw   = trim($_POST['first_language'] ?? '');
    $first_language_other = trim($_POST['first_language_other'] ?? '');
    $first_language = ($first_language_raw === 'Others' && $first_language_other !== '')
        ? $first_language_other : blankToNull($first_language_raw);

    $second_language_raw   = trim($_POST['second_language'] ?? '');
    $second_language_other = trim($_POST['second_language_other'] ?? '');
    $second_language = ($second_language_raw === 'Others' && $second_language_other !== '')
        ? $second_language_other : blankToNull($second_language_raw);

    $mother_name         = blankToNull($_POST['mother_name'] ?? '');
    $mother_occupation   = blankToNull($_POST['mother_occupation'] ?? '');
    $mother_address      = blankToNull($_POST['mother_address'] ?? '');
    $mother_contact_home = blankToNull($_POST['mother_contact_home'] ?? '');
    $mother_contact_work = blankToNull($_POST['mother_contact_work'] ?? '');

    $father_name         = blankToNull($_POST['father_name'] ?? '');
    $father_occupation   = blankToNull($_POST['father_occupation'] ?? '');
    $father_address      = blankToNull($_POST['father_address'] ?? '');
    $father_contact_home = blankToNull($_POST['father_contact_home'] ?? '');
    $father_contact_work = blankToNull($_POST['father_contact_work'] ?? '');

    $emergency_contact_name         = blankToNull($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_relationship = blankToNull($_POST['emergency_contact_relationship'] ?? '');
    $emergency_contact_home         = blankToNull($_POST['emergency_contact_home'] ?? '');
    $emergency_contact_work         = blankToNull($_POST['emergency_contact_work'] ?? '');

    /* ---- Growth Measurements (not stored on children; see below) ---- */
    $height_cm = ($_POST['height_cm'] ?? '') !== '' ? (float) $_POST['height_cm'] : null;
    $weight_kg = ($_POST['weight_kg'] ?? '') !== '' ? (float) $_POST['weight_kg'] : null;

    /* ---- Health Records (Form 2) ---- */
    $has_eccd_card          = isset($_POST['has_eccd_card']) ? 1 : 0;
    $has_mother_child_book  = isset($_POST['has_mother_child_book']) ? 1 : 0;
    $has_other_health_record = blankToNull($_POST['has_other_health_record'] ?? '');

    $vaccine_bcg      = blankToNull($_POST['vaccine_bcg'] ?? '');
    $vaccine_dpt      = blankToNull($_POST['vaccine_dpt'] ?? '');
    $vaccine_opv      = blankToNull($_POST['vaccine_opv'] ?? '');
    $vaccine_hepab    = blankToNull($_POST['vaccine_hepab'] ?? '');
    $vaccine_measles  = blankToNull($_POST['vaccine_measles'] ?? '');
    $vaccine_others_name   = blankToNull($_POST['vaccine_others_name'] ?? '');
    $vaccine_others_status = blankToNull($_POST['vaccine_others_status'] ?? '');

    /* ---- Physical Attributes ---- */
    $deformity_hare_lip          = isset($_POST['deformity_hare_lip']) ? 1 : 0;
    $deformity_cross_eyed        = isset($_POST['deformity_cross_eyed']) ? 1 : 0;
    $deformity_deaf              = isset($_POST['deformity_deaf']) ? 1 : 0;
    $deformity_blind             = isset($_POST['deformity_blind']) ? 1 : 0;
    $deformity_disabled_leg      = isset($_POST['deformity_disabled_leg']) ? 1 : 0;
    $deformity_disabled_arm_hand = isset($_POST['deformity_disabled_arm_hand']) ? 1 : 0;
    $deformity_fingers_toes      = isset($_POST['deformity_fingers_toes']) ? 1 : 0;

    $problem_behavior = isset($_POST['problem_behavior']) ? 1 : 0;
    $problem_speaking = isset($_POST['problem_speaking']) ? 1 : 0;
    $problem_hearing  = isset($_POST['problem_hearing']) ? 1 : 0;
    $problem_vision   = isset($_POST['problem_vision']) ? 1 : 0;

    $is_left_handed = blankToNull($_POST['is_left_handed'] ?? '');

    /* ---- Siblings & Prior ECCD Experience (free text) ---- */
    $siblings_info = blankToNull($_POST['siblings_info'] ?? '');
    $eccd_experience_info = blankToNull($_POST['eccd_experience_info'] ?? '');

    /* ---- Other Performance Related Inputs ---- */
    $learns_at_home_with = checkboxListToString($_POST['learns_at_home_with'] ?? []);
    $plays_with_older_siblings   = blankToNull($_POST['plays_with_older_siblings'] ?? '');
    $plays_with_younger_siblings = blankToNull($_POST['plays_with_younger_siblings'] ?? '');
    $plays_with_neighbors         = blankToNull($_POST['plays_with_neighbors'] ?? '');

    /* ---- Logistics ---- */
    $has_meal_before_school = blankToNull($_POST['has_meal_before_school'] ?? '');
    $food_normally_eaten    = checkboxListToString($_POST['food_normally_eaten'] ?? []);
    $has_baon               = blankToNull($_POST['has_baon'] ?? '');

    $travel_time_to_dcc_minutes = ($_POST['travel_time_to_dcc_minutes'] ?? '') !== '' ? (int) $_POST['travel_time_to_dcc_minutes'] : null;
    $travel_mode_to_dcc          = blankToNull($_POST['travel_mode_to_dcc'] ?? '');
    $travel_time_to_ncdc_minutes = ($_POST['travel_time_to_ncdc_minutes'] ?? '') !== '' ? (int) $_POST['travel_time_to_ncdc_minutes'] : null;
    $travel_mode_to_ncdc          = blankToNull($_POST['travel_mode_to_ncdc'] ?? '');

    $public_transport_type = checkboxListToString($_POST['public_transport_type'] ?? []);
    $goes_to_school_with    = checkboxListToString($_POST['goes_to_school_with'] ?? []);

    /* ---- Health info (existing feature, unchanged) ---- */
    $allergies      = trim($_POST['allergies'] ?? '');
    $comorbidities  = trim($_POST['comorbidities'] ?? '');
    $vaccination_card_path = $health['vaccination_card_file_path'] ?? "";
    $medical_history_path  = $health['medical_history_file_path'] ?? "";

    if (empty($first_name) || empty($last_name) || empty($birthdate) || empty($sex)) {
        $error = "Please fill in all required child information fields.";
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
            $update_sql = "
                UPDATE children SET
                    first_name = ?, middle_name = ?, last_name = ?, birthdate = ?, sex = ?, address = ?, religion = ?,
                    birth_order = ?, born_at = ?,
                    is_registered = ?, first_language = ?, second_language = ?,
                    mother_name = ?, mother_occupation = ?, mother_address = ?, mother_contact_home = ?, mother_contact_work = ?,
                    father_name = ?, father_occupation = ?, father_address = ?, father_contact_home = ?, father_contact_work = ?,
                    emergency_contact_name = ?, emergency_contact_relationship = ?, emergency_contact_home = ?, emergency_contact_work = ?,
                    has_eccd_card = ?, has_mother_child_book = ?, has_other_health_record = ?,
                    vaccine_bcg = ?, vaccine_dpt = ?, vaccine_opv = ?, vaccine_hepab = ?, vaccine_measles = ?,
                    vaccine_others_name = ?, vaccine_others_status = ?,
                    deformity_hare_lip = ?, deformity_cross_eyed = ?, deformity_deaf = ?, deformity_blind = ?,
                    deformity_disabled_leg = ?, deformity_disabled_arm_hand = ?, deformity_fingers_toes = ?,
                    problem_behavior = ?, problem_speaking = ?, problem_hearing = ?, problem_vision = ?,
                    is_left_handed = ?,
                    learns_at_home_with = ?, plays_with_older_siblings = ?, plays_with_younger_siblings = ?, plays_with_neighbors = ?,
                    has_meal_before_school = ?, food_normally_eaten = ?, has_baon = ?,
                    travel_time_to_dcc_minutes = ?, travel_mode_to_dcc = ?,
                    travel_time_to_ncdc_minutes = ?, travel_mode_to_ncdc = ?,
                    public_transport_type = ?, goes_to_school_with = ?,
                    siblings_info = ?, eccd_experience_info = ?
                WHERE child_id = ? AND cdc_id = ?
            ";

            $update_stmt = $conn->prepare($update_sql);
            if (!$update_stmt) {
                throw new Exception("Failed to prepare child update: " . $conn->error);
            }

            $update_stmt->bind_param(
                "sssssssissssssssssssssssssiissssssssiiiiiiiiiiissssssssisisssssii",
                $first_name, $middle_name, $last_name, $birthdate, $sex, $address, $religion,
                $birth_order, $born_at,
                $is_registered, $first_language, $second_language,
                $mother_name, $mother_occupation, $mother_address, $mother_contact_home, $mother_contact_work,
                $father_name, $father_occupation, $father_address, $father_contact_home, $father_contact_work,
                $emergency_contact_name, $emergency_contact_relationship, $emergency_contact_home, $emergency_contact_work,
                $has_eccd_card, $has_mother_child_book, $has_other_health_record,
                $vaccine_bcg, $vaccine_dpt, $vaccine_opv, $vaccine_hepab, $vaccine_measles,
                $vaccine_others_name, $vaccine_others_status,
                $deformity_hare_lip, $deformity_cross_eyed, $deformity_deaf, $deformity_blind,
                $deformity_disabled_leg, $deformity_disabled_arm_hand, $deformity_fingers_toes,
                $problem_behavior, $problem_speaking, $problem_hearing, $problem_vision,
                $is_left_handed,
                $learns_at_home_with, $plays_with_older_siblings, $plays_with_younger_siblings, $plays_with_neighbors,
                $has_meal_before_school, $food_normally_eaten, $has_baon,
                $travel_time_to_dcc_minutes, $travel_mode_to_dcc,
                $travel_time_to_ncdc_minutes, $travel_mode_to_ncdc,
                $public_transport_type, $goes_to_school_with,
                $siblings_info, $eccd_experience_info,
                $child_id, $active_cdc_id
            );

            if (!$update_stmt->execute()) {
                throw new Exception("Failed to update child profile: " . $update_stmt->error);
            }
            $update_stmt->close();

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
   Determine current dropdown state for First/Second Language
   (so we can pre-select "Others" + fill the specify box when
   the saved value isn't one of the standard options)
============================================================ */
$current_first_language = $child['first_language'] ?? '';
$first_language_is_other = ($current_first_language !== '' && !in_array($current_first_language, $language_options, true));
$first_language_select_value = $first_language_is_other ? 'Others' : $current_first_language;

$current_second_language = $child['second_language'] ?? '';
$second_language_is_other = ($current_second_language !== '' && !in_array($current_second_language, $language_options, true));
$second_language_select_value = $second_language_is_other ? 'Others' : $current_second_language;

$learns_checked = stringToCheckedArray($child['learns_at_home_with'] ?? '');
$food_checked = stringToCheckedArray($child['food_normally_eaten'] ?? '');
$transport_checked = stringToCheckedArray($child['public_transport_type'] ?? '');
$goeswith_checked = stringToCheckedArray($child['goes_to_school_with'] ?? '');
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

            <!-- ============================
                 CHILD INFORMATION
            ============================= -->
            <div class="form-card">
                <h3 class="section-title">Child Information</h3>

                <div class="form-grid">
                    <div class="form-row">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($child['first_name']); ?>" required>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control" value="<?php echo htmlspecialchars($child['middle_name'] ?? ''); ?>">
                    </div>

                    <div class="form-row">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($child['last_name']); ?>" required>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Birthdate</label>
                        <input type="date" name="birthdate" class="form-control" value="<?php echo htmlspecialchars($child['birthdate']); ?>" required>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Sex</label>
                        <select name="sex" class="form-select" required>
                            <option value="Male" <?php if ($child['sex'] == 'Male') echo 'selected'; ?>>Male</option>
                            <option value="Female" <?php if ($child['sex'] == 'Female') echo 'selected'; ?>>Female</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($child['address'] ?? ''); ?>">
                    </div>

                    <div class="form-row">
                        <label class="form-label">Religion</label>
                        <select name="religion" class="form-select">
                            <option value="">-- Select Religion --</option>
                            <?php
                            $religion_options = ['Roman Catholic','Christian','Islam','Iglesia ni Cristo','Seventh-day Adventist',"Jehovah's Witnesses",'Baptist','Methodist','Born Again Christian','Others'];
                            foreach ($religion_options as $opt) {
                                $sel = ($child['religion'] === $opt) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Birth Order</label>
                        <input type="number" min="1" name="birth_order" class="form-control" value="<?php echo htmlspecialchars($child['birth_order'] ?? ''); ?>">
                    </div>

                    <div class="form-row">
                        <label class="form-label">Born at</label>
                        <select name="born_at" class="form-select">
                            <option value="">-- Select --</option>
                            <?php foreach (['Hospital','Health Center','Home'] as $opt) {
                                $sel = (($child['born_at'] ?? '') === $opt) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                            } ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ============================
                 FAMILY & REGISTRATION DETAILS (FORM 1)
            ============================= -->
            <div class="form-card">
                <h3 class="section-title">Family &amp; Registration Details (Form 1)</h3>
                <p class="section-subtitle">Originally submitted by the guardian — correct here if needed.</p>

                <div class="form-grid">
                    <div class="form-row">
                        <label class="form-label">Registered (Yes/No)</label>
                        <select name="is_registered" class="form-select">
                            <option value="">-- Select --</option>
                            <option value="Yes" <?php echo (($child['is_registered'] ?? '') === 'Yes') ? 'selected' : ''; ?>>Yes</option>
                            <option value="No" <?php echo (($child['is_registered'] ?? '') === 'No') ? 'selected' : ''; ?>>No</option>
                        </select>
                    </div>

                    <div class="form-row"></div>

                    <div class="form-row">
                        <label class="form-label">First Language</label>
                        <select name="first_language" id="firstLanguage" class="form-select">
                            <option value="">Select Language</option>
                            <?php foreach ($language_options as $lang) {
                                $sel = ($first_language_select_value === $lang) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($lang) . "\" $sel>" . htmlspecialchars($lang) . "</option>";
                            } ?>
                            <option value="Others" <?php echo ($first_language_select_value === 'Others') ? 'selected' : ''; ?>>Others</option>
                        </select>
                        <div class="other-field-group <?php echo $first_language_is_other ? 'show' : ''; ?>" id="firstLanguageOtherGroup">
                            <input type="text" name="first_language_other" class="form-control" placeholder="Please specify"
                                   value="<?php echo $first_language_is_other ? htmlspecialchars($current_first_language) : ''; ?>">
                        </div>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Second Language</label>
                        <select name="second_language" id="secondLanguage" class="form-select">
                            <option value="">Select Language</option>
                            <?php foreach ($language_options as $lang) {
                                $sel = ($second_language_select_value === $lang) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($lang) . "\" $sel>" . htmlspecialchars($lang) . "</option>";
                            } ?>
                            <option value="Others" <?php echo ($second_language_select_value === 'Others') ? 'selected' : ''; ?>>Others</option>
                        </select>
                        <div class="other-field-group <?php echo $second_language_is_other ? 'show' : ''; ?>" id="secondLanguageOtherGroup">
                            <input type="text" name="second_language_other" class="form-control" placeholder="Please specify"
                                   value="<?php echo $second_language_is_other ? htmlspecialchars($current_second_language) : ''; ?>">
                        </div>
                    </div>
                </div>

                <p class="subgroup-title">Mother's Information</p>
                <div class="form-grid">
                    <div class="form-row">
                        <label class="form-label">Name</label>
                        <input type="text" name="mother_name" class="form-control" value="<?php echo htmlspecialchars($child['mother_name'] ?? ''); ?>">
                    </div>
                    <div class="form-row">
                        <label class="form-label">Occupation</label>
                        <input type="text" name="mother_occupation" class="form-control" value="<?php echo htmlspecialchars($child['mother_occupation'] ?? ''); ?>">
                    </div>
                    <div class="form-row" style="grid-column:1 / -1;">
                        <label class="form-label">Address</label>
                        <input type="text" name="mother_address" class="form-control" value="<?php echo htmlspecialchars($child['mother_address'] ?? ''); ?>">
                    </div>
                    <div class="form-row">
                        <label class="form-label">Contact Number - Home</label>
                        <input type="text" name="mother_contact_home" class="form-control" value="<?php echo htmlspecialchars($child['mother_contact_home'] ?? ''); ?>">
                    </div>
                    <div class="form-row">
                        <label class="form-label">Contact Number - Work</label>
                        <input type="text" name="mother_contact_work" class="form-control" value="<?php echo htmlspecialchars($child['mother_contact_work'] ?? ''); ?>">
                    </div>
                </div>

                <p class="subgroup-title">Father's Information</p>
                <div class="form-grid">
                    <div class="form-row">
                        <label class="form-label">Name</label>
                        <input type="text" name="father_name" class="form-control" value="<?php echo htmlspecialchars($child['father_name'] ?? ''); ?>">
                    </div>
                    <div class="form-row">
                        <label class="form-label">Occupation</label>
                        <input type="text" name="father_occupation" class="form-control" value="<?php echo htmlspecialchars($child['father_occupation'] ?? ''); ?>">
                    </div>
                    <div class="form-row" style="grid-column:1 / -1;">
                        <label class="form-label">Address</label>
                        <input type="text" name="father_address" class="form-control" value="<?php echo htmlspecialchars($child['father_address'] ?? ''); ?>">
                    </div>
                    <div class="form-row">
                        <label class="form-label">Contact Number - Home</label>
                        <input type="text" name="father_contact_home" class="form-control" value="<?php echo htmlspecialchars($child['father_contact_home'] ?? ''); ?>">
                    </div>
                    <div class="form-row">
                        <label class="form-label">Contact Number - Work</label>
                        <input type="text" name="father_contact_work" class="form-control" value="<?php echo htmlspecialchars($child['father_contact_work'] ?? ''); ?>">
                    </div>
                </div>

                <p class="subgroup-title">In Case of Emergency, Please Contact</p>
                <div class="form-grid">
                    <div class="form-row">
                        <label class="form-label">Name</label>
                        <input type="text" name="emergency_contact_name" class="form-control" value="<?php echo htmlspecialchars($child['emergency_contact_name'] ?? ''); ?>">
                    </div>
                    <div class="form-row">
                        <label class="form-label">Relationship</label>
                        <select name="emergency_contact_relationship" class="form-select">
                            <option value="">Select Relationship</option>
                            <?php foreach ($relationship_options as $opt) {
                                $sel = (($child['emergency_contact_relationship'] ?? '') === $opt) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                            } ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <label class="form-label">Contact Number - Home</label>
                        <input type="text" name="emergency_contact_home" class="form-control" value="<?php echo htmlspecialchars($child['emergency_contact_home'] ?? ''); ?>">
                    </div>
                    <div class="form-row">
                        <label class="form-label">Contact Number - Work</label>
                        <input type="text" name="emergency_contact_work" class="form-control" value="<?php echo htmlspecialchars($child['emergency_contact_work'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- ============================
                 GROWTH MEASUREMENTS
            ============================= -->
            <div class="form-card">
                <h3 class="section-title">Add New Growth Measurement</h3>
                <p class="section-subtitle">Optional. Leave blank to skip — this will NOT overwrite existing growth history, it adds a new follow-up record.</p>

                <div class="form-grid">
                    <div class="form-row">
                        <label class="form-label">Height (cm)</label>
                        <input type="number" step="0.01" min="0" name="height_cm" class="form-control">
                    </div>
                    <div class="form-row">
                        <label class="form-label">Weight (kg)</label>
                        <input type="number" step="0.01" min="0" name="weight_kg" class="form-control">
                    </div>
                </div>
            </div>

            <!-- ============================
                 HEALTH RECORDS
            ============================= -->
            <div class="form-card">
                <h3 class="section-title">Health Records</h3>

                <div class="form-row">
                    <label class="form-label">Does the Child have:</label>
                    <div class="checkbox-grid">
                        <div class="checkbox-item">
                            <input type="checkbox" id="has_eccd_card" name="has_eccd_card" <?php echo !empty($child['has_eccd_card']) ? 'checked' : ''; ?>>
                            <label for="has_eccd_card">ECCD Card</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="has_mother_child_book" name="has_mother_child_book" <?php echo !empty($child['has_mother_child_book']) ? 'checked' : ''; ?>>
                            <label for="has_mother_child_book">Mother & Child Book</label>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label">Others, please specify</label>
                    <input type="text" name="has_other_health_record" class="form-control" value="<?php echo htmlspecialchars($child['has_other_health_record'] ?? ''); ?>">
                </div>

                <p class="subgroup-title">Vaccination and Other Health Data</p>
                <div class="form-grid">
                    <?php
                    $vaccine_fields = [
                        'vaccine_bcg' => 'BCG',
                        'vaccine_dpt' => 'DPT',
                        'vaccine_opv' => 'Oral Polio',
                        'vaccine_hepab' => 'Hepa B',
                        'vaccine_measles' => 'Measles',
                    ];
                    foreach ($vaccine_fields as $field => $label) {
                    ?>
                        <div class="form-row">
                            <label class="form-label"><?php echo htmlspecialchars($label); ?></label>
                            <select name="<?php echo $field; ?>" class="form-select">
                                <option value="">-- Select --</option>
                                <?php foreach (['Yes','No',"Don't Know"] as $opt) {
                                    $sel = (($child[$field] ?? '') === $opt) ? 'selected' : '';
                                    echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                                } ?>
                            </select>
                        </div>
                    <?php } ?>

                    <div class="form-row">
                        <label class="form-label">Others (specify vaccine)</label>
                        <input type="text" name="vaccine_others_name" class="form-control" value="<?php echo htmlspecialchars($child['vaccine_others_name'] ?? ''); ?>">
                    </div>

                    <div class="form-row">
                        <label class="form-label">Others — Status</label>
                        <select name="vaccine_others_status" class="form-select">
                            <option value="">-- Select --</option>
                            <?php foreach (['Yes','No',"Don't Know"] as $opt) {
                                $sel = (($child['vaccine_others_status'] ?? '') === $opt) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                            } ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ============================
                 PHYSICAL ATTRIBUTES
            ============================= -->
            <div class="form-card">
                <h3 class="section-title">Physical Attributes</h3>

                <p class="subgroup-title">Physical Deformity</p>
                <div class="checkbox-grid">
                    <?php
                    $deformity_fields = [
                        'deformity_hare_lip' => 'Hare Lip',
                        'deformity_cross_eyed' => 'Cross-Eyed (Duling or Banlag)',
                        'deformity_deaf' => 'Deaf',
                        'deformity_blind' => 'Blind',
                        'deformity_disabled_leg' => 'Disabled Leg',
                        'deformity_disabled_arm_hand' => 'Disabled Arm/Hand',
                        'deformity_fingers_toes' => 'Deformity in Fingers/Toes',
                    ];
                    foreach ($deformity_fields as $field => $label) {
                        $checked = !empty($child[$field]) ? 'checked' : '';
                    ?>
                        <div class="checkbox-item">
                            <input type="checkbox" id="<?php echo $field; ?>" name="<?php echo $field; ?>" <?php echo $checked; ?>>
                            <label for="<?php echo $field; ?>"><?php echo htmlspecialchars($label); ?></label>
                        </div>
                    <?php } ?>
                </div>

                <p class="subgroup-title">Problems with</p>
                <div class="checkbox-grid">
                    <?php
                    $problem_fields = [
                        'problem_behavior' => 'Behavior',
                        'problem_speaking' => 'Speaking',
                        'problem_hearing' => 'Hearing',
                        'problem_vision' => 'Vision',
                    ];
                    foreach ($problem_fields as $field => $label) {
                        $checked = !empty($child[$field]) ? 'checked' : '';
                    ?>
                        <div class="checkbox-item">
                            <input type="checkbox" id="<?php echo $field; ?>" name="<?php echo $field; ?>" <?php echo $checked; ?>>
                            <label for="<?php echo $field; ?>"><?php echo htmlspecialchars($label); ?></label>
                        </div>
                    <?php } ?>
                </div>

                <div class="form-row" style="margin-top:16px;">
                    <label class="form-label">Left Handed</label>
                    <div class="radio-group">
                        <label><input type="radio" name="is_left_handed" value="Yes" <?php echo (($child['is_left_handed'] ?? '') === 'Yes') ? 'checked' : ''; ?>> Yes</label>
                        <label><input type="radio" name="is_left_handed" value="No" <?php echo (($child['is_left_handed'] ?? '') === 'No') ? 'checked' : ''; ?>> No</label>
                    </div>
                </div>
            </div>

            <!-- ============================
                 SIBLINGS
            ============================= -->
            <div class="form-card">
                <h3 class="section-title">Siblings</h3>
                <p class="section-subtitle">List each sibling's age, sex, and education status. Leave blank if none.</p>
                <textarea name="siblings_info" class="form-control" rows="4" placeholder="e.g. Age 8, Male, In School&#10;Age 5, Female, Out of School"><?php echo htmlspecialchars($child['siblings_info'] ?? ''); ?></textarea>
            </div>

            <!-- ============================
                 PRIOR ECCD EXPERIENCE
            ============================= -->
            <div class="form-card">
                <h3 class="section-title">Prior Early Childhood Experience</h3>
                <p class="section-subtitle">List what the child attended per level (Nursery/Kindergarten/Preparatory). Leave blank if none.</p>
                <textarea name="eccd_experience_info" class="form-control" rows="4" placeholder="e.g. Nursery: Private Day Care&#10;Kindergarten: Public Pre-School"><?php echo htmlspecialchars($child['eccd_experience_info'] ?? ''); ?></textarea>
            </div>

            <!-- ============================
                 OTHER PERFORMANCE RELATED INPUTS
            ============================= -->
            <div class="form-card">
                <h3 class="section-title">Other Performance Related Inputs</h3>

                <div class="form-row">
                    <label class="form-label">Learns at Home with</label>
                    <div class="checkbox-grid">
                        <?php
                        $learns_options = ['Nobody', 'Siblings', 'Househelp/Maid', 'Tutor', 'Mother/Father/Both', 'Relatives', 'Others'];
                        foreach ($learns_options as $opt) {
                            $cb_id = 'learns_' . preg_replace('/[^a-z0-9]/i', '', $opt);
                            $checked = in_array($opt, $learns_checked, true) ? 'checked' : '';
                        ?>
                            <div class="checkbox-item">
                                <input type="checkbox" id="<?php echo $cb_id; ?>" name="learns_at_home_with[]" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $checked; ?>>
                                <label for="<?php echo $cb_id; ?>"><?php echo htmlspecialchars($opt); ?></label>
                            </div>
                        <?php } ?>
                    </div>
                </div>

                <div class="form-grid" style="margin-top:6px;">
                    <?php
                    $plays_fields = [
                        'plays_with_older_siblings' => 'Play/Interacts with Older Siblings',
                        'plays_with_younger_siblings' => 'Play/Interacts with Younger Siblings',
                        'plays_with_neighbors' => 'Play/Interacts with Neighbors of Same Age',
                    ];
                    foreach ($plays_fields as $field => $label) {
                    ?>
                        <div class="form-row">
                            <label class="form-label"><?php echo htmlspecialchars($label); ?></label>
                            <select name="<?php echo $field; ?>" class="form-select">
                                <option value="">-- Select --</option>
                                <?php foreach (['Always','Sometimes','Rarely','Never'] as $opt) {
                                    $sel = (($child[$field] ?? '') === $opt) ? 'selected' : '';
                                    echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                                } ?>
                            </select>
                        </div>
                    <?php } ?>
                </div>
            </div>

            <!-- ============================
                 LOGISTICS
            ============================= -->
            <div class="form-card">
                <h3 class="section-title">Logistics</h3>

                <div class="form-row">
                    <label class="form-label">Has Meal Before Going to School</label>
                    <select name="has_meal_before_school" class="form-select">
                        <option value="">-- Select --</option>
                        <?php foreach (['Always','Most of the time','Sometimes','Rarely','Never'] as $opt) {
                            $sel = (($child['has_meal_before_school'] ?? '') === $opt) ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                        } ?>
                    </select>
                </div>

                <div class="form-row">
                    <label class="form-label">Food Normally Eaten by Child</label>
                    <div class="checkbox-grid">
                        <?php
                        $food_options = ['Vegetable', 'Pork', 'Chicken', 'Beef', 'Fish', 'Rice', 'Noodle Soup', 'Bread', 'Fruits', 'Cereals', 'Fruit Juice', 'Milk'];
                        foreach ($food_options as $opt) {
                            $cb_id = 'food_' . preg_replace('/[^a-z0-9]/i', '', $opt);
                            $checked = in_array($opt, $food_checked, true) ? 'checked' : '';
                        ?>
                            <div class="checkbox-item">
                                <input type="checkbox" id="<?php echo $cb_id; ?>" name="food_normally_eaten[]" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $checked; ?>>
                                <label for="<?php echo $cb_id; ?>"><?php echo htmlspecialchars($opt); ?></label>
                            </div>
                        <?php } ?>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label">Has Baon</label>
                    <select name="has_baon" class="form-select">
                        <option value="">-- Select --</option>
                        <?php foreach (['Money','Food','Both','None',"Don't Know"] as $opt) {
                            $sel = (($child['has_baon'] ?? '') === $opt) ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                        } ?>
                    </select>
                </div>

                <p class="subgroup-title">Travel Time from Home to DCC</p>
                <div class="form-grid">
                    <div class="form-row">
                        <label class="form-label">Minutes</label>
                        <input type="number" min="0" name="travel_time_to_dcc_minutes" class="form-control" value="<?php echo htmlspecialchars($child['travel_time_to_dcc_minutes'] ?? ''); ?>">
                    </div>
                    <div class="form-row">
                        <label class="form-label">Mode</label>
                        <select name="travel_mode_to_dcc" class="form-select">
                            <option value="">-- Select --</option>
                            <?php foreach (['Walking','Private Vehicle','Public Transportation'] as $opt) {
                                $sel = (($child['travel_mode_to_dcc'] ?? '') === $opt) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                            } ?>
                        </select>
                    </div>
                </div>

                <p class="subgroup-title">Travel Time from Home to NCDC</p>
                <div class="form-grid">
                    <div class="form-row">
                        <label class="form-label">Minutes</label>
                        <input type="number" min="0" name="travel_time_to_ncdc_minutes" class="form-control" value="<?php echo htmlspecialchars($child['travel_time_to_ncdc_minutes'] ?? ''); ?>">
                    </div>
                    <div class="form-row">
                        <label class="form-label">Mode</label>
                        <select name="travel_mode_to_ncdc" class="form-select">
                            <option value="">-- Select --</option>
                            <?php foreach (['Walking','Private Vehicle','Public Transportation'] as $opt) {
                                $sel = (($child['travel_mode_to_ncdc'] ?? '') === $opt) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                            } ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label">Public Transportation (if applicable)</label>
                    <div class="checkbox-grid">
                        <?php
                        $transport_options = ['School bus', 'Jeep', 'Bus', 'Banca', 'Calesa', 'Tricycle', 'Pedicab', 'Habal-Habal', 'Others'];
                        foreach ($transport_options as $opt) {
                            $cb_id = 'transport_' . preg_replace('/[^a-z0-9]/i', '', $opt);
                            $checked = in_array($opt, $transport_checked, true) ? 'checked' : '';
                        ?>
                            <div class="checkbox-item">
                                <input type="checkbox" id="<?php echo $cb_id; ?>" name="public_transport_type[]" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $checked; ?>>
                                <label for="<?php echo $cb_id; ?>"><?php echo htmlspecialchars($opt); ?></label>
                            </div>
                        <?php } ?>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label">Goes to School with</label>
                    <div class="checkbox-grid">
                        <?php
                        $school_with_options = ['Mother', 'Father', 'Both Parents', 'Siblings', 'Relatives', 'Grandparents', 'Maid', 'None'];
                        foreach ($school_with_options as $opt) {
                            $cb_id = 'goeswith_' . preg_replace('/[^a-z0-9]/i', '', $opt);
                            $checked = in_array($opt, $goeswith_checked, true) ? 'checked' : '';
                        ?>
                            <div class="checkbox-item">
                                <input type="checkbox" id="<?php echo $cb_id; ?>" name="goes_to_school_with[]" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $checked; ?>>
                                <label for="<?php echo $cb_id; ?>"><?php echo htmlspecialchars($opt); ?></label>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <!-- ============================
                 CHILD HEALTH INFORMATION (existing feature)
            ============================= -->
            <div class="form-card">
                <h4 class="sub-section-title" style="margin-top:0; border-top:none; padding-top:0;">Child Health Information</h4>

                <div class="form-grid">
                    <div class="form-row">
                        <label class="form-label">Vaccination Card</label>
                        <input type="file" name="vaccination_card" class="form-file">
                        <?php if (!empty($health['vaccination_card_file_path'])) { ?>
                            <div class="section-subtitle">Current file: <?php echo htmlspecialchars($health['vaccination_card_file_path']); ?></div>
                        <?php } ?>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Medical History</label>
                        <input type="file" name="medical_history_file" class="form-file">
                        <?php if (!empty($health['medical_history_file_path'])) { ?>
                            <div class="section-subtitle">Current file: <?php echo htmlspecialchars($health['medical_history_file_path']); ?></div>
                        <?php } ?>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Allergies</label>
                        <input type="text" name="allergies" class="form-control" value="<?php echo htmlspecialchars($health['allergies'] ?? ''); ?>">
                    </div>

                    <div class="form-row">
                        <label class="form-label">Comorbidities</label>
                        <input type="text" name="comorbidities" class="form-control" value="<?php echo htmlspecialchars($health['comorbidities'] ?? ''); ?>">
                    </div>
                </div>
            </div>

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

function setupLanguageOtherToggle(selectId, groupId) {
    var select = document.getElementById(selectId);
    var group = document.getElementById(groupId);
    if (!select || !group) return;

    function update() {
        if (select.value === 'Others') {
            group.classList.add('show');
        } else {
            group.classList.remove('show');
        }
    }

    select.addEventListener('change', update);
}

setupLanguageOtherToggle('firstLanguage', 'firstLanguageOtherGroup');
setupLanguageOtherToggle('secondLanguage', 'secondLanguageOtherGroup');
</script>

</body>
</html>