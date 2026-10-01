<?php
include '../includes/auth.php';
include '../config/database.php';
include '../includes/nnc_growth_standards.php';

if ($_SESSION['role_id'] != 2) {
    header("Location: ../login.php");
    exit();
}

if (!isset($_SESSION['theme_mode'])) {
    $_SESSION['theme_mode'] = 'light';
}

if (!isset($_SESSION['active_cdc_id'])) {
    die("Please select an active CDC first from the dashboard.");
}

$cdc_id = (int) $_SESSION['active_cdc_id'];
$theme_mode = $_SESSION['theme_mode'];
$success = "";
$error = "";

/* ============================================================
   HELPER: convert a checkbox array into a comma-separated string
============================================================ */
function checkboxListToString($postArray) {
    if (!is_array($postArray) || empty($postArray)) {
        return null;
    }
    $clean = array_map('trim', $postArray);
    $clean = array_filter($clean, function ($v) {
        return $v !== '';
    });
    if (empty($clean)) {
        return null;
    }
    return implode(', ', $clean);
}

/* ============================================================
   HELPER: normalize an empty string to NULL for optional ENUM/
   text fields, so we don't insert '' where NULL is expected
============================================================ */
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

if (isset($_POST['save'])) {

    /* ============================
       SECTION 1: CHILD INFORMATION
    ============================ */
    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $birthdate   = trim($_POST['birthdate'] ?? '');
    $sex         = trim($_POST['sex'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $religion    = trim($_POST['religion'] ?? '');

    $birth_order = ($_POST['birth_order'] ?? '') !== '' ? (int) $_POST['birth_order'] : null;
    $born_at     = blankToNull($_POST['born_at'] ?? '');

    /* ============================
       SECTION 2: GROWTH MEASUREMENTS
       (goes to anthropometric_records, not children)
    ============================ */
    $height_cm = ($_POST['height_cm'] ?? '') !== '' ? (float) $_POST['height_cm'] : null;
    $weight_kg = ($_POST['weight_kg'] ?? '') !== '' ? (float) $_POST['weight_kg'] : null;

    /* ============================
       SECTION 3: HEALTH RECORDS
    ============================ */
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

    /* ============================
       SECTION 4: PHYSICAL ATTRIBUTES
    ============================ */
    $deformity_hare_lip         = isset($_POST['deformity_hare_lip']) ? 1 : 0;
    $deformity_cross_eyed       = isset($_POST['deformity_cross_eyed']) ? 1 : 0;
    $deformity_deaf             = isset($_POST['deformity_deaf']) ? 1 : 0;
    $deformity_blind            = isset($_POST['deformity_blind']) ? 1 : 0;
    $deformity_disabled_leg     = isset($_POST['deformity_disabled_leg']) ? 1 : 0;
    $deformity_disabled_arm_hand = isset($_POST['deformity_disabled_arm_hand']) ? 1 : 0;
    $deformity_fingers_toes     = isset($_POST['deformity_fingers_toes']) ? 1 : 0;

    $problem_behavior = isset($_POST['problem_behavior']) ? 1 : 0;
    $problem_speaking = isset($_POST['problem_speaking']) ? 1 : 0;
    $problem_hearing  = isset($_POST['problem_hearing']) ? 1 : 0;
    $problem_vision   = isset($_POST['problem_vision']) ? 1 : 0;

    $is_left_handed = blankToNull($_POST['is_left_handed'] ?? '');

    /* ============================
       SECTION 5: SIBLINGS (free text)
    ============================ */
    $siblings_info = blankToNull($_POST['siblings_info'] ?? '');

    /* ============================
       SECTION 6: PRIOR ECCD EXPERIENCE (free text)
    ============================ */
    $eccd_experience_info = blankToNull($_POST['eccd_experience_info'] ?? '');

    /* ============================
       SECTION 7: OTHER PERFORMANCE INPUTS
    ============================ */
    $learns_at_home_with = checkboxListToString($_POST['learns_at_home_with'] ?? []);
    $plays_with_older_siblings   = blankToNull($_POST['plays_with_older_siblings'] ?? '');
    $plays_with_younger_siblings = blankToNull($_POST['plays_with_younger_siblings'] ?? '');
    $plays_with_neighbors         = blankToNull($_POST['plays_with_neighbors'] ?? '');

    /* ============================
       SECTION 8: LOGISTICS
    ============================ */
    $has_meal_before_school = blankToNull($_POST['has_meal_before_school'] ?? '');
    $food_normally_eaten    = checkboxListToString($_POST['food_normally_eaten'] ?? []);
    $has_baon               = blankToNull($_POST['has_baon'] ?? '');

    $travel_time_to_dcc_minutes = ($_POST['travel_time_to_dcc_minutes'] ?? '') !== '' ? (int) $_POST['travel_time_to_dcc_minutes'] : null;
    $travel_mode_to_dcc          = blankToNull($_POST['travel_mode_to_dcc'] ?? '');
    $travel_time_to_ncdc_minutes = ($_POST['travel_time_to_ncdc_minutes'] ?? '') !== '' ? (int) $_POST['travel_time_to_ncdc_minutes'] : null;
    $travel_mode_to_ncdc          = blankToNull($_POST['travel_mode_to_ncdc'] ?? '');

    $public_transport_type = checkboxListToString($_POST['public_transport_type'] ?? []);
    $goes_to_school_with    = checkboxListToString($_POST['goes_to_school_with'] ?? []);

    /* ============================
       REQUIRED FIELD VALIDATION
       (same required fields as before — everything from Form 2
       beyond these core fields is optional, since this is new
       data collection and old records won't have it)
    ============================ */
    if (empty($first_name) || empty($last_name) || empty($birthdate) || empty($sex)) {
        $error = "Please fill in all required child information fields.";
    } else {

        $conn->begin_transaction();

        try {
            /* ----------------------------------------------
               1. Generate a unique access code
            ---------------------------------------------- */
            $access_code = "CH-" . rand(1000, 9999);

            $check_stmt = $conn->prepare("SELECT child_id FROM children WHERE access_code = ? LIMIT 1");
            $check_stmt->bind_param("s", $access_code);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            while ($check_result->num_rows > 0) {
                $access_code = "CH-" . rand(1000, 9999);
                $check_stmt = $conn->prepare("SELECT child_id FROM children WHERE access_code = ? LIMIT 1");
                $check_stmt->bind_param("s", $access_code);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
            }
            $check_stmt->close();

            /* ----------------------------------------------
               2. Insert the child record (all Form 2 fields)
            ---------------------------------------------- */
            $insert_sql = "
                INSERT INTO children (
                    first_name, middle_name, last_name, birthdate, sex, address, religion,
                    cdc_id, access_code,
                    birth_order, born_at,
                    has_eccd_card, has_mother_child_book, has_other_health_record,
                    vaccine_bcg, vaccine_dpt, vaccine_opv, vaccine_hepab, vaccine_measles,
                    vaccine_others_name, vaccine_others_status,
                    deformity_hare_lip, deformity_cross_eyed, deformity_deaf, deformity_blind,
                    deformity_disabled_leg, deformity_disabled_arm_hand, deformity_fingers_toes,
                    problem_behavior, problem_speaking, problem_hearing, problem_vision,
                    is_left_handed,
                    learns_at_home_with, plays_with_older_siblings, plays_with_younger_siblings, plays_with_neighbors,
                    has_meal_before_school, food_normally_eaten, has_baon,
                    travel_time_to_dcc_minutes, travel_mode_to_dcc,
                    travel_time_to_ncdc_minutes, travel_mode_to_ncdc,
                    public_transport_type, goes_to_school_with,
                    siblings_info, eccd_experience_info
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?, ?, ?,
                    ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?,
                    ?, ?
                )
            ";

            $insert_stmt = $conn->prepare($insert_sql);

            if (!$insert_stmt) {
                throw new Exception("Failed to prepare child insert: " . $conn->error);
            }

            $insert_stmt->bind_param(
                "sssssssisisiisssssssiiiiiiiiiiiisssssssiisisssss",
                $first_name, $middle_name, $last_name, $birthdate, $sex, $address, $religion,
                $cdc_id, $access_code,
                $birth_order, $born_at,
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
                $siblings_info, $eccd_experience_info
            );

            if (!$insert_stmt->execute()) {
                throw new Exception("Failed to save child profile: " . $insert_stmt->error);
            }

            $child_id = $conn->insert_id;
            $insert_stmt->close();

            /* ----------------------------------------------
               3. Insert the initial Height/Weight measurement
                  (Section 2) into anthropometric_records —
                  only if at least one of height/weight was given
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

                if (!$anthro_stmt->execute()) {
                    throw new Exception("Failed to save initial growth measurement: " . $anthro_stmt->error);
                }

                $anthro_stmt->close();
            }

            /* ----------------------------------------------
               All good — commit everything
            ---------------------------------------------- */
            $conn->commit();

            $success = "Child Profile Registration successful! Child Access Code: " . $access_code;

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Child | NutriTrack</title>
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
            background:#eef0f3;
            font-family:'Inter', sans-serif;
            color:#333;
        }

        a{
            text-decoration:none;
        }

        .main-content{
            margin-left:260px;
            padding:112px 24px 30px;
            transition:margin-left 0.25s ease;
            display:flex;
            justify-content:center;
        }

        .page-wrapper{
            width:100%;
            max-width:850px;
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

        .message{
            border-radius:10px;
            padding:14px 16px;
            margin-bottom:16px;
            font-size:13px;
            font-weight:600;
            border:1px solid transparent;
        }

        .message.success{
            background:#e8f5e9;
            color:#2e7d32;
            border-color:#c8e6c9;
        }

        .message.error{
            background:#fdeaea;
            color:#c62828;
            border-color:#f5c2c7;
        }

        .form-grid{
            display:grid;
            grid-template-columns:1fr;
            gap:18px;
        }

        .form-card{
            background:#ffffff;
            border:1px solid #dcdcdc;
            border-radius:14px;
            padding:20px;
        }

        .form-card.full{
            grid-column:1 / -1;
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

        .optional{
            font-family:'Inter', sans-serif;
            font-size:12px;
            color:#777;
            font-weight:500;
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

        .health-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:18px;
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

        .subgroup-title{
            font-size:13px;
            font-weight:700;
            color:#2E7D32;
            margin:16px 0 10px 0;
        }

        .subgroup-title:first-child{
            margin-top:0;
        }

        .form-actions{
            margin-top:18px;
            display:flex;
            justify-content:flex-end;
            gap:10px;
            flex-wrap:wrap;
            width:100%;
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

        .btn-cancel{
            background:#e0e0e0;
            color:#444;
        }

        .btn-save{
            background:#2E7D32;
            color:#fff;
        }

        /* ================= ADD CHILD DARK MODE ================= */
        body.dark-mode{
            background:#0f172a;
            color:#e5e7eb;
        }

        body.dark-mode .page-header,
        body.dark-mode .form-card{
            background:#111827;
            border-color:#334155;
        }

        body.dark-mode .page-title,
        body.dark-mode .section-title{
            color:#f8fafc;
        }

        body.dark-mode .page-subtitle,
        body.dark-mode .form-label,
        body.dark-mode .optional,
        body.dark-mode .section-subtitle{
            color:#cbd5e1;
        }

        body.dark-mode .form-control,
        body.dark-mode .form-select,
        body.dark-mode .form-file{
            background:#0f172a;
            color:#f8fafc;
            border-color:#475569;
        }

        body.dark-mode .radio-group label,
        body.dark-mode .checkbox-item{
            color:#e5e7eb;
        }

        body.dark-mode .btn-cancel{
            background:#1e293b;
            color:#e5e7eb;
            border:1px solid #334155;
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

            .form-grid{
                grid-template-columns:1fr;
            }

            .health-grid,
            .checkbox-grid{
                grid-template-columns:1fr;
            }

            .form-actions{
                justify-content:stretch;
            }

            .form-actions .btn{
                width:100%;
            }
        }
    </style>
</head>
<body class="<?php echo ($theme_mode === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-wrapper">
    <div class="page-header">
        <a href="child_list.php" class="back-link">← Back to Pupil List</a>
        <h1 class="page-title">Child Profile Registration</h1>
        <div class="page-subtitle">
            Active CDC: <?php echo htmlspecialchars($_SESSION['active_cdc_name']); ?><br>
            After saving, give the generated access code to the guardian for account registration.
            This form follows the official ECCD Council "Form 2 - Children's Profile".
        </div>
    </div>

    <?php if (!empty($success)) { ?>
        <div class="message success"><?php echo htmlspecialchars($success); ?></div>
    <?php } ?>

    <?php if (!empty($error)) { ?>
        <div class="message error"><?php echo htmlspecialchars($error); ?></div>
    <?php } ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-grid">

            <!-- ============================
                 CHILD INFORMATION
            ============================= -->
            <div class="form-card">
                <h3 class="section-title">Child Information</h3>

                <div class="form-row">
                    <label class="form-label">First Name</label>
                    <input type="text" name="first_name" class="form-control" required>
                </div>

                <div class="form-row">
                    <label class="form-label">Middle Name <span class="optional">(Optional)</span></label>
                    <input type="text" name="middle_name" class="form-control">
                </div>

                <div class="form-row">
                    <label class="form-label">Last Name</label>
                    <input type="text" name="last_name" class="form-control" required>
                </div>

                <div class="form-row">
                    <label class="form-label">Birthdate</label>
                    <input type="date" name="birthdate" class="form-control" required>
                </div>

                <div class="form-row">
                    <label class="form-label">Sex</label>
                    <div class="radio-group">
                        <label><input type="radio" name="sex" value="Male" required> Male</label>
                        <label><input type="radio" name="sex" value="Female" required> Female</label>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control">
                </div>

                <div class="form-row">
                    <label class="form-label">Religion</label>
                    <select name="religion" class="form-select">
                        <option value="">-- Select Religion --</option>
                        <option value="Roman Catholic">Roman Catholic</option>
                        <option value="Christian">Christian</option>
                        <option value="Islam">Islam (Muslim)</option>
                        <option value="Iglesia ni Cristo">Iglesia ni Cristo</option>
                        <option value="Born Again Christian">Born Again Christian</option>
                        <option value="Seventh-day Adventist">Seventh-day Adventist</option>
                        <option value="Jehovah's Witnesses">Jehovah's Witnesses</option>
                        <option value="Baptist">Baptist</option>
                        <option value="Methodist">Methodist</option>
                    </select>
                </div>

                <div class="health-grid">
                    <div class="form-row">
                        <label class="form-label">Birth Order <span class="optional">(Optional)</span></label>
                        <input type="number" min="1" name="birth_order" class="form-control">
                    </div>

                    <div class="form-row">
                        <label class="form-label">Born at <span class="optional">(Optional)</span></label>
                        <select name="born_at" class="form-select">
                            <option value="">-- Select --</option>
                            <option value="Hospital">Hospital</option>
                            <option value="Health Center">Health Center</option>
                            <option value="Home">Home</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ============================
                 GROWTH MEASUREMENTS
            ============================= -->
            <div class="form-card">
                <h3 class="section-title">Growth Measurements</h3>
                <p class="section-subtitle">Optional at enrollment — saved as the child's baseline record.</p>

                <div class="health-grid">
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
                            <input type="checkbox" id="has_eccd_card" name="has_eccd_card">
                            <label for="has_eccd_card">ECCD Card</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="has_mother_child_book" name="has_mother_child_book">
                            <label for="has_mother_child_book">Mother & Child Book</label>
                        </div>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label">Others, please specify <span class="optional">(Optional)</span></label>
                    <input type="text" name="has_other_health_record" class="form-control">
                </div>

                <p class="subgroup-title">Vaccination and Other Health Data</p>

                <div class="health-grid">
                    <div class="form-row">
                        <label class="form-label">BCG</label>
                        <select name="vaccine_bcg" class="form-select">
                            <option value="">-- Select --</option>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                            <option value="Don't Know">Don't Know</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label class="form-label">DPT</label>
                        <select name="vaccine_dpt" class="form-select">
                            <option value="">-- Select --</option>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                            <option value="Don't Know">Don't Know</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Oral Polio</label>
                        <select name="vaccine_opv" class="form-select">
                            <option value="">-- Select --</option>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                            <option value="Don't Know">Don't Know</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Hepa B</label>
                        <select name="vaccine_hepab" class="form-select">
                            <option value="">-- Select --</option>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                            <option value="Don't Know">Don't Know</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Measles</label>
                        <select name="vaccine_measles" class="form-select">
                            <option value="">-- Select --</option>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                            <option value="Don't Know">Don't Know</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Others (specify vaccine)</label>
                        <input type="text" name="vaccine_others_name" class="form-control" placeholder="Vaccine name">
                    </div>

                    <div class="form-row">
                        <label class="form-label">Others — Status</label>
                        <select name="vaccine_others_status" class="form-select">
                            <option value="">-- Select --</option>
                            <option value="Yes">Yes</option>
                            <option value="No">No</option>
                            <option value="Don't Know">Don't Know</option>
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
                    <div class="checkbox-item">
                        <input type="checkbox" id="deformity_hare_lip" name="deformity_hare_lip">
                        <label for="deformity_hare_lip">Hare Lip</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" id="deformity_cross_eyed" name="deformity_cross_eyed">
                        <label for="deformity_cross_eyed">Cross-Eyed (Duling or Banlag)</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" id="deformity_deaf" name="deformity_deaf">
                        <label for="deformity_deaf">Deaf</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" id="deformity_blind" name="deformity_blind">
                        <label for="deformity_blind">Blind</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" id="deformity_disabled_leg" name="deformity_disabled_leg">
                        <label for="deformity_disabled_leg">Disabled Leg</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" id="deformity_disabled_arm_hand" name="deformity_disabled_arm_hand">
                        <label for="deformity_disabled_arm_hand">Disabled Arm/Hand</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" id="deformity_fingers_toes" name="deformity_fingers_toes">
                        <label for="deformity_fingers_toes">Deformity in Fingers/Toes</label>
                    </div>
                </div>

                <p class="subgroup-title">Problems with</p>
                <div class="checkbox-grid">
                    <div class="checkbox-item">
                        <input type="checkbox" id="problem_behavior" name="problem_behavior">
                        <label for="problem_behavior">Behavior</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" id="problem_speaking" name="problem_speaking">
                        <label for="problem_speaking">Speaking</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" id="problem_hearing" name="problem_hearing">
                        <label for="problem_hearing">Hearing</label>
                    </div>
                    <div class="checkbox-item">
                        <input type="checkbox" id="problem_vision" name="problem_vision">
                        <label for="problem_vision">Vision</label>
                    </div>
                </div>

                <div class="form-row" style="margin-top:16px;">
                    <label class="form-label">Left Handed</label>
                    <div class="radio-group">
                        <label><input type="radio" name="is_left_handed" value="Yes"> Yes</label>
                        <label><input type="radio" name="is_left_handed" value="No"> No</label>
                    </div>
                </div>
            </div>

            <!-- ============================
                 SIBLINGS
            ============================= -->
            <div class="form-card">
                <h3 class="section-title">Siblings</h3>
                <p class="section-subtitle">List each sibling's age, sex, and education status. Leave blank if none.</p>

                <div class="form-row">
                    <textarea name="siblings_info" class="form-control" rows="4" placeholder="e.g. Age 8, Male, In School&#10;Age 5, Female, Out of School"></textarea>
                </div>
            </div>

            <!-- ============================
                 PRIOR ECCD EXPERIENCE
            ============================= -->
            <div class="form-card">
                <h3 class="section-title">Prior Early Childhood Experience</h3>
                <p class="section-subtitle">Describe the child's prior Nursery, Kindergarten, and/or Preparatory experience, if any.</p>

                <div class="form-row">
                    <textarea name="eccd_experience_info" class="form-control" rows="4" placeholder="e.g. Nursery: Private Day Care&#10;Kindergarten: Public Pre-School"></textarea>
                </div>
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
                        ?>
                            <div class="checkbox-item">
                                <input type="checkbox" id="<?php echo $cb_id; ?>" name="learns_at_home_with[]" value="<?php echo htmlspecialchars($opt); ?>">
                                <label for="<?php echo $cb_id; ?>"><?php echo htmlspecialchars($opt); ?></label>
                            </div>
                        <?php } ?>
                    </div>
                </div>

                <div class="health-grid" style="margin-top:6px;">
                    <div class="form-row">
                        <label class="form-label">Play/Interacts with Older Siblings</label>
                        <select name="plays_with_older_siblings" class="form-select">
                            <option value="">-- Select --</option>
                            <option value="Always">Always</option>
                            <option value="Sometimes">Sometimes</option>
                            <option value="Rarely">Rarely</option>
                            <option value="Never">Never</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Play/Interacts with Younger Siblings</label>
                        <select name="plays_with_younger_siblings" class="form-select">
                            <option value="">-- Select --</option>
                            <option value="Always">Always</option>
                            <option value="Sometimes">Sometimes</option>
                            <option value="Rarely">Rarely</option>
                            <option value="Never">Never</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Play/Interacts with Neighbors of Same Age</label>
                        <select name="plays_with_neighbors" class="form-select">
                            <option value="">-- Select --</option>
                            <option value="Always">Always</option>
                            <option value="Sometimes">Sometimes</option>
                            <option value="Rarely">Rarely</option>
                            <option value="Never">Never</option>
                        </select>
                    </div>
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
                        <option value="Always">Always</option>
                        <option value="Most of the time">Most of the time</option>
                        <option value="Sometimes">Sometimes</option>
                        <option value="Rarely">Rarely</option>
                        <option value="Never">Never</option>
                    </select>
                </div>

                <div class="form-row">
                    <label class="form-label">Food Normally Eaten by Child</label>
                    <div class="checkbox-grid">
                        <?php
                        $food_options = ['Vegetable', 'Pork', 'Chicken', 'Beef', 'Fish', 'Rice', 'Noodle Soup', 'Bread', 'Fruits', 'Cereals', 'Fruit Juice', 'Milk'];
                        foreach ($food_options as $opt) {
                            $cb_id = 'food_' . preg_replace('/[^a-z0-9]/i', '', $opt);
                        ?>
                            <div class="checkbox-item">
                                <input type="checkbox" id="<?php echo $cb_id; ?>" name="food_normally_eaten[]" value="<?php echo htmlspecialchars($opt); ?>">
                                <label for="<?php echo $cb_id; ?>"><?php echo htmlspecialchars($opt); ?></label>
                            </div>
                        <?php } ?>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label">Has Baon</label>
                    <select name="has_baon" class="form-select">
                        <option value="">-- Select --</option>
                        <option value="Money">Money</option>
                        <option value="Food">Food</option>
                        <option value="Both">Both</option>
                        <option value="None">None</option>
                        <option value="Don't Know">Don't Know</option>
                    </select>
                </div>

                <p class="subgroup-title">Travel Time from Home to DCC</p>
                <div class="health-grid">
                    <div class="form-row">
                        <label class="form-label">Minutes</label>
                        <input type="number" min="0" name="travel_time_to_dcc_minutes" class="form-control">
                    </div>

                    <div class="form-row">
                        <label class="form-label">Mode</label>
                        <select name="travel_mode_to_dcc" class="form-select">
                            <option value="">-- Select --</option>
                            <option value="Walking">Walking</option>
                            <option value="Private Vehicle">Private Vehicle</option>
                            <option value="Public Transportation">Public Transportation</option>
                        </select>
                    </div>
                </div>

                <p class="subgroup-title">Travel Time from Home to NCDC</p>
                <div class="health-grid">
                    <div class="form-row">
                        <label class="form-label">Minutes</label>
                        <input type="number" min="0" name="travel_time_to_ncdc_minutes" class="form-control">
                    </div>

                    <div class="form-row">
                        <label class="form-label">Mode</label>
                        <select name="travel_mode_to_ncdc" class="form-select">
                            <option value="">-- Select --</option>
                            <option value="Walking">Walking</option>
                            <option value="Private Vehicle">Private Vehicle</option>
                            <option value="Public Transportation">Public Transportation</option>
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
                        ?>
                            <div class="checkbox-item">
                                <input type="checkbox" id="<?php echo $cb_id; ?>" name="public_transport_type[]" value="<?php echo htmlspecialchars($opt); ?>">
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
                        ?>
                            <div class="checkbox-item">
                                <input type="checkbox" id="<?php echo $cb_id; ?>" name="goes_to_school_with[]" value="<?php echo htmlspecialchars($opt); ?>">
                                <label for="<?php echo $cb_id; ?>"><?php echo htmlspecialchars($opt); ?></label>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>

        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-cancel" onclick="window.location.href='child_list.php'">Cancel</button>
            <button type="submit" name="save" class="btn btn-save">Save Pupil</button>
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

</body>
</html>