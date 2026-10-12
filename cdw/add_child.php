<?php
include '../includes/auth.php';
include '../config/database.php';
include '../includes/nnc_growth_standards.php';
require_once '../includes/eccd_form2.php';

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
       FORM 2 items 1-2: CHILD INFORMATION
    ============================ */
    $first_name  = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name   = trim($_POST['last_name'] ?? '');
    $birthdate   = trim($_POST['birthdate'] ?? '');
    $sex         = trim($_POST['sex'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $religion    = trim($_POST['religion'] ?? '');

    /* ============================
       FORM 2 items 8-9: HEIGHT / WEIGHT
       (goes to anthropometric_records, not children)
    ============================ */
    $height_cm = ($_POST['height_cm'] ?? '') !== '' ? (float) $_POST['height_cm'] : null;
    $weight_kg = ($_POST['weight_kg'] ?? '') !== '' ? (float) $_POST['weight_kg'] : null;

    /* ============================
       FORM 2 items 3-7 and 10-16
       (read + validated in includes/eccd_form2.php)
    ============================ */
    $form_errors = array();
    $f2 = eccd_f2_collect($_POST, $form_errors);

    /* ============================
       REQUIRED FIELD VALIDATION
       (same required fields as before — everything from Form 2
       beyond these core fields is optional, since this is new
       data collection and old records won't have it)
    ============================ */
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
            $base = array(
                'first_name'  => array('s', $first_name),
                'middle_name' => array('s', $middle_name),
                'last_name'   => array('s', $last_name),
                'birthdate'   => array('s', $birthdate),
                'sex'         => array('s', $sex),
                'address'     => array('s', $address),
                'religion'    => array('s', $religion),
                'cdc_id'      => array('i', $cdc_id),
                'access_code' => array('s', $access_code),
            );
            list($col_names, $col_types, $col_values) = eccd_f2_build_set($f2, $base);

            $col_sql = implode(', ', array_map(function ($c) { return "`$c`"; }, $col_names));
            $placeholders = implode(', ', array_fill(0, count($col_names), '?'));
            $insert_sql = "INSERT INTO children ($col_sql) VALUES ($placeholders)";

            $insert_stmt = $conn->prepare($insert_sql);

            if (!$insert_stmt) {
                throw new Exception("Failed to prepare child insert: " . $conn->error);
            }

            $insert_stmt->bind_param($col_types, ...$col_values);

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
    <?php echo eccd_f2_css(); ?>
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

            <?php
            /* FORM 2 - Children's Profile (items 1-16).
               On a failed save, show what was typed instead of an empty form. */
            $form_values = isset($f2) && !empty($error)
                ? array_merge($f2, array(
                    'first_name' => $first_name, 'middle_name' => $middle_name, 'last_name' => $last_name,
                    'birthdate' => $birthdate, 'sex' => $sex, 'address' => $address, 'religion' => $religion,
                ))
                : array();
            echo eccd_f2_render($form_values, array('show_growth' => true,
                'growth_note' => 'Optional. Saved as the child\'s baseline height and weight.'));
            ?>

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
<?php echo eccd_f2_js(); ?>

</body>
</html>