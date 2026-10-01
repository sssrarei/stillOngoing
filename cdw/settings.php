<?php
include '../includes/auth.php';
include '../config/database.php';

if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 2) {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$success = "";
$error = "";

/* =========================
   HELPERS
========================= */
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

function stringToCheckedArray($value) {
    if (empty($value)) {
        return [];
    }
    return array_map('trim', explode(',', $value));
}

/* =========================
   SAVE PROFILE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $contact_number = trim($_POST['contact_number']);
    $address = trim($_POST['address']);

    // Personal Information (Form 6, Section I)
    $sex = blankToNull($_POST['sex'] ?? '');
    $birthday = blankToNull($_POST['birthday'] ?? '');
    $religion = blankToNull($_POST['religion'] ?? '');
    $ethnicity = blankToNull($_POST['ethnicity'] ?? '');
    $civil_status = blankToNull($_POST['civil_status'] ?? '');
    $no_of_children = ($_POST['no_of_children'] ?? '') !== '' ? (int) $_POST['no_of_children'] : null;
    $home_number = blankToNull($_POST['home_number'] ?? '');
    $office_number = blankToNull($_POST['office_number'] ?? '');
    $fax_number = blankToNull($_POST['fax_number'] ?? '');

    $educational_background = blankToNull($_POST['educational_background'] ?? '');
    $degree = blankToNull($_POST['degree'] ?? '');
    $eligibility = checkboxListToString($_POST['eligibility'] ?? []);
    $eligibility_other = blankToNull($_POST['eligibility_other'] ?? '');

    // Work-Related Information (Section II)
    $years_as_cdw = ($_POST['years_as_cdw'] ?? '') !== '' ? (float) $_POST['years_as_cdw'] : null;
    $compensation_type = checkboxListToString($_POST['compensation_type'] ?? []);
    $terms_of_employment = blankToNull($_POST['terms_of_employment'] ?? '');

    $compensation_barangay_amount = ($_POST['compensation_barangay_amount'] ?? '') !== '' ? (float) $_POST['compensation_barangay_amount'] : null;
    $compensation_city_amount = ($_POST['compensation_city_amount'] ?? '') !== '' ? (float) $_POST['compensation_city_amount'] : null;
    $compensation_ngo_amount = ($_POST['compensation_ngo_amount'] ?? '') !== '' ? (float) $_POST['compensation_ngo_amount'] : null;
    $compensation_parents_amount = ($_POST['compensation_parents_amount'] ?? '') !== '' ? (float) $_POST['compensation_parents_amount'] : null;
    $compensation_source_other = blankToNull($_POST['compensation_source_other'] ?? '');
    $compensation_source_other_amount = ($_POST['compensation_source_other_amount'] ?? '') !== '' ? (float) $_POST['compensation_source_other_amount'] : null;

    $cdw_status = blankToNull($_POST['cdw_status'] ?? '');
    $cdw_date_accredited = blankToNull($_POST['cdw_date_accredited'] ?? '');
    $cdw_accreditation_no = blankToNull($_POST['cdw_accreditation_no'] ?? '');
    $cdw_accreditation_level = blankToNull($_POST['cdw_accreditation_level'] ?? '');

    $trainings_attended = blankToNull($_POST['trainings_attended'] ?? '');
    $courses_attended = blankToNull($_POST['courses_attended'] ?? '');

    // Working Conditions (Section III)
    $total_children_served = ($_POST['total_children_served'] ?? '') !== '' ? (int) $_POST['total_children_served'] : null;
    $sessions_per_day = blankToNull($_POST['sessions_per_day'] ?? '');
    $hours_per_session = blankToNull($_POST['hours_per_session'] ?? '');
    $age_of_children_handled = checkboxListToString($_POST['age_of_children_handled'] ?? []);
    $hours_staying_in_center = blankToNull($_POST['hours_staying_in_center'] ?? '');
    $sessions_conducted_with = blankToNull($_POST['sessions_conducted_with'] ?? '');

    if ($first_name === '' || $last_name === '') {
        $error = "First name and last name are required.";
    } else {
        $sql_update = "
            UPDATE users SET
                first_name = ?, last_name = ?, contact_number = ?, address = ?,
                sex = ?, birthday = ?, religion = ?, ethnicity = ?, civil_status = ?, no_of_children = ?,
                home_number = ?, office_number = ?, fax_number = ?,
                educational_background = ?, degree = ?, eligibility = ?, eligibility_other = ?,
                years_as_cdw = ?, compensation_type = ?, terms_of_employment = ?,
                compensation_barangay_amount = ?, compensation_city_amount = ?, compensation_ngo_amount = ?, compensation_parents_amount = ?,
                compensation_source_other = ?, compensation_source_other_amount = ?,
                cdw_status = ?, cdw_date_accredited = ?, cdw_accreditation_no = ?, cdw_accreditation_level = ?,
                trainings_attended = ?, courses_attended = ?,
                total_children_served = ?, sessions_per_day = ?, hours_per_session = ?,
                age_of_children_handled = ?, hours_staying_in_center = ?, sessions_conducted_with = ?
            WHERE user_id = ? AND role_id = 2
        ";
        $stmt_update = $conn->prepare($sql_update);

        if ($stmt_update) {
            $stmt_update->bind_param(
                "sssssssssisssssssdssddddsdssssssisssssi",
                $first_name, $last_name, $contact_number, $address,
                $sex, $birthday, $religion, $ethnicity, $civil_status, $no_of_children,
                $home_number, $office_number, $fax_number,
                $educational_background, $degree, $eligibility, $eligibility_other,
                $years_as_cdw, $compensation_type, $terms_of_employment,
                $compensation_barangay_amount, $compensation_city_amount, $compensation_ngo_amount, $compensation_parents_amount,
                $compensation_source_other, $compensation_source_other_amount,
                $cdw_status, $cdw_date_accredited, $cdw_accreditation_no, $cdw_accreditation_level,
                $trainings_attended, $courses_attended,
                $total_children_served, $sessions_per_day, $hours_per_session,
                $age_of_children_handled, $hours_staying_in_center, $sessions_conducted_with,
                $user_id
            );

            if ($stmt_update->execute()) {
                $_SESSION['first_name'] = $first_name;
                $_SESSION['last_name'] = $last_name;
                $success = "Profile information updated successfully.";
            } else {
                $error = "Failed to update profile information: " . $stmt_update->error;
            }

            $stmt_update->close();
        } else {
            $error = "Failed to prepare profile update: " . $conn->error;
        }
    }
}

/* =========================
   FETCH USER INFORMATION
========================= */
$user = [
    'first_name' => '', 'last_name' => '', 'email' => '', 'contact_number' => '', 'address' => '',
    'sex' => '', 'birthday' => '', 'religion' => '', 'ethnicity' => '', 'civil_status' => '', 'no_of_children' => '',
    'home_number' => '', 'office_number' => '', 'fax_number' => '',
    'educational_background' => '', 'degree' => '', 'eligibility' => '', 'eligibility_other' => '',
    'years_as_cdw' => '', 'compensation_type' => '', 'terms_of_employment' => '',
    'compensation_barangay_amount' => '', 'compensation_city_amount' => '', 'compensation_ngo_amount' => '', 'compensation_parents_amount' => '',
    'compensation_source_other' => '', 'compensation_source_other_amount' => '',
    'cdw_status' => '', 'cdw_date_accredited' => '', 'cdw_accreditation_no' => '', 'cdw_accreditation_level' => '',
    'trainings_attended' => '', 'courses_attended' => '',
    'total_children_served' => '', 'sessions_per_day' => '', 'hours_per_session' => '',
    'age_of_children_handled' => '', 'hours_staying_in_center' => '', 'sessions_conducted_with' => '',
];

$sql_user = "SELECT * FROM users WHERE user_id = ? AND role_id = 2 LIMIT 1";
$stmt_user = $conn->prepare($sql_user);

if ($stmt_user) {
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();

    if ($result_user && $result_user->num_rows > 0) {
        $row_user = $result_user->fetch_assoc();
        foreach ($user as $key => $default) {
            $user[$key] = $row_user[$key] ?? $default;
        }
    }

    $stmt_user->close();
}

// Compute age from birthday (not stored, always derived)
$computed_age = '';
if (!empty($user['birthday'])) {
    try {
        $birth = new DateTime($user['birthday']);
        $today = new DateTime();
        $computed_age = $today->diff($birth)->y . ' years old';
    } catch (Exception $e) {
        $computed_age = '';
    }
}

$eligibility_checked = stringToCheckedArray($user['eligibility']);
$compensation_type_checked = stringToCheckedArray($user['compensation_type']);
$age_of_children_checked = stringToCheckedArray($user['age_of_children_handled']);

/* =========================
   FETCH ASSIGNED CDCs
========================= */
$assigned_cdcs = [];

$sql_cdc = "SELECT c.cdc_name
            FROM cdw_assignments ca
            INNER JOIN cdc c ON ca.cdc_id = c.cdc_id
            WHERE ca.user_id = ?
            ORDER BY c.cdc_name ASC";
$stmt_cdc = $conn->prepare($sql_cdc);

if ($stmt_cdc) {
    $stmt_cdc->bind_param("i", $user_id);
    $stmt_cdc->execute();
    $result_cdc = $stmt_cdc->get_result();

    while ($row_cdc = $result_cdc->fetch_assoc()) {
        $assigned_cdcs[] = $row_cdc['cdc_name'];
    }

    $stmt_cdc->close();
}

$cdc_count = count($assigned_cdcs);
$cdc_names = ($cdc_count > 0) ? implode(', ', $assigned_cdcs) : 'No assigned CDC';
$assigned_cdc_display = $cdc_count . ' Assigned CDC' . ($cdc_count > 1 ? 's' : '') . ' (' . $cdc_names . ')';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | NutriTrack</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
    <link rel="stylesheet" href="../assets/cdw/cdw-topbar-notification.css">

    <style>
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

        .settings-grid{
            display:flex;
            flex-direction:column;
            gap:18px;
        }

        .content-card{
            background:#ffffff;
            border:1px solid #dcdcdc;
            border-radius:14px;
            padding:20px;
        }

        .section-title{
            font-family:'Poppins', sans-serif;
            font-size:18px;
            font-weight:700;
            color:#2f2f2f;
            margin-bottom:6px;
        }

        .section-subtitle{
            font-size:13px;
            color:#666;
            line-height:1.6;
            margin-bottom:18px;
        }

        .settings-form-grid{
            display:grid;
            grid-template-columns:repeat(2, 1fr);
            gap:14px;
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

        .form-control[readonly]{
            background:#f5f5f5;
            cursor:not-allowed;
        }

        .full-width{
            grid-column:1 / -1;
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

        .subgroup-title{
            font-size:13px;
            font-weight:700;
            color:#2E7D32;
            margin:16px 0 10px 0;
        }

        .subgroup-title:first-child{
            margin-top:0;
        }

        textarea.form-control{
            min-height:105px;
            resize:vertical;
        }

        .button-group{
            display:flex;
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

        .btn-save{
            background:#2E7D32;
            color:#fff;
        }

        .success-message{
            background:#eaf7ee;
            color:#1f7a46;
            border:1px solid #c8e6d0;
            border-radius:10px;
            padding:14px 16px;
            margin-bottom:16px;
            font-size:13px;
            font-weight:600;
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

        .preference-table{
            width:100%;
            border-collapse:collapse;
            margin-top:16px;
        }

        .preference-table th,
        .preference-table td{
            border:1px solid #ebebeb;
            padding:12px 14px;
            text-align:left;
            font-size:13px;
            color:#2f2f2f;
        }

        .preference-table th{
            width:34%;
            background:#f7f7f7;
            font-weight:600;
        }

        @media (max-width: 991px){
            .main-content{
                margin-left:0;
                padding:104px 16px 24px;
            }

            .settings-grid{
                grid-template-columns:1fr;
            }

            .settings-form-grid{
                grid-template-columns:1fr;
            }
        }
    </style>
</head>
<body>

<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-header">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        <h1 class="page-title">Settings</h1>
        <div class="page-subtitle">Manage your profile information.</div>
    </div>

    <?php if ($success != '') { ?>
        <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
    <?php } ?>

    <?php if ($error != '') { ?>
        <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
    <?php } ?>

    <div class="settings-grid">
        <div class="content-card">
            <div class="section-title">Profile Information</div>
            <div class="section-subtitle">
                The information entered by CSWD is shown below. You may also edit your profile information here.
                This form follows the official ECCD Council "Form 6 - Child Development Worker Profile".
            </div>

            <form method="POST">
                <p class="subgroup-title">Basic Account Info</p>
                <div class="settings-form-grid">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>Position</label>
                        <input type="text" class="form-control" value="CDW" readonly>
                    </div>

                    <div class="form-group">
                        <label>Mobile Number</label>
                        <input type="text" name="contact_number" class="form-control" value="<?php echo htmlspecialchars($user['contact_number']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Assigned CDC</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($assigned_cdc_display); ?>" readonly>
                    </div>

                    <div class="form-group full-width">
                        <label>Address</label>
                        <textarea name="address" class="form-control"><?php echo htmlspecialchars($user['address']); ?></textarea>
                    </div>
                </div>

                <p class="subgroup-title">Personal Information</p>
                <div class="settings-form-grid">
                    <div class="form-group">
                        <label>Sex</label>
                        <select name="sex" class="form-control">
                            <option value="">-- Select --</option>
                            <option value="Male" <?php echo ($user['sex'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($user['sex'] === 'Female') ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Birthday <?php echo $computed_age ? "($computed_age)" : ''; ?></label>
                        <input type="date" name="birthday" class="form-control" value="<?php echo htmlspecialchars($user['birthday']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Religion</label>
                        <select name="religion" class="form-control">
                            <option value="">-- Select Religion --</option>
                            <?php
                            $religion_options = ['Roman Catholic','Christian','Islam','Iglesia ni Cristo','Born Again Christian','Seventh-day Adventist',"Jehovah's Witnesses",'Baptist','Methodist'];
                            // Keep any previously saved value that isn't in the list, so it isn't lost on save
                            if ($user['religion'] !== '' && !in_array($user['religion'], $religion_options, true)) {
                                $religion_options[] = $user['religion'];
                            }
                            foreach ($religion_options as $opt) {
                                $sel = ($user['religion'] === $opt) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Ethnicity</label>
                        <input type="text" name="ethnicity" class="form-control" value="<?php echo htmlspecialchars($user['ethnicity']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Civil Status</label>
                        <select name="civil_status" class="form-control">
                            <option value="">-- Select --</option>
                            <?php foreach (['Single','Married','Separated','Widow/Widower','Live-in'] as $opt) {
                                $sel = ($user['civil_status'] === $opt) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                            } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>No. of Children</label>
                        <input type="number" min="0" name="no_of_children" class="form-control" value="<?php echo htmlspecialchars($user['no_of_children']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Home Number</label>
                        <input type="text" name="home_number" class="form-control" value="<?php echo htmlspecialchars($user['home_number']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Office Number</label>
                        <input type="text" name="office_number" class="form-control" value="<?php echo htmlspecialchars($user['office_number']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Fax Number</label>
                        <input type="text" name="fax_number" class="form-control" value="<?php echo htmlspecialchars($user['fax_number']); ?>">
                    </div>
                </div>

                <p class="subgroup-title">Educational Background</p>
                <div class="settings-form-grid">
                    <div class="form-group">
                        <label>Educational Attainment</label>
                        <select name="educational_background" class="form-control">
                            <option value="">-- Select --</option>
                            <?php
                            $edu_options = ['Elementary Undergraduate','Elementary Graduate','High School Undergraduate','High School Graduate','College Undergraduate','College Graduate','With Masteral Units','Post Graduate','Vocational'];
                            foreach ($edu_options as $opt) {
                                $sel = ($user['educational_background'] === $opt) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Degree (if applicable)</label>
                        <input type="text" name="degree" class="form-control" value="<?php echo htmlspecialchars($user['degree']); ?>">
                    </div>

                    <div class="form-group full-width">
                        <label>Eligibility</label>
                        <div class="checkbox-grid">
                            <?php
                            $eligibility_options = ['Civil Service Sub-Professional','Civil Service Professional','Licensure Examination for Teachers','None'];
                            foreach ($eligibility_options as $opt) {
                                $cb_id = 'elig_' . preg_replace('/[^a-z0-9]/i', '', $opt);
                                $checked = in_array($opt, $eligibility_checked, true) ? 'checked' : '';
                            ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="<?php echo $cb_id; ?>" name="eligibility[]" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $checked; ?>>
                                    <label for="<?php echo $cb_id; ?>"><?php echo htmlspecialchars($opt); ?></label>
                                </div>
                            <?php } ?>
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label>Others, please specify</label>
                        <input type="text" name="eligibility_other" class="form-control" value="<?php echo htmlspecialchars($user['eligibility_other']); ?>">
                    </div>
                </div>

                <p class="subgroup-title">Work-Related Information</p>
                <div class="settings-form-grid">
                    <div class="form-group">
                        <label>No. of Years as CDW</label>
                        <input type="number" step="0.5" min="0" name="years_as_cdw" class="form-control" value="<?php echo htmlspecialchars($user['years_as_cdw']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Terms of Employment</label>
                        <select name="terms_of_employment" class="form-control">
                            <option value="">-- Select --</option>
                            <?php foreach (['Plantilla','Contract of Service','Casual','Co-Terminus w/ Hiring Authority','Voluntary'] as $opt) {
                                $sel = ($user['terms_of_employment'] === $opt) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                            } ?>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label>Monthly Compensation Type</label>
                        <div class="checkbox-grid">
                            <?php
                            $comp_options = ['Salary','Honoraria','Allowance',"Parents' Monthly Contribution/Pledges"];
                            foreach ($comp_options as $opt) {
                                $cb_id = 'comp_' . preg_replace('/[^a-z0-9]/i', '', $opt);
                                $checked = in_array($opt, $compensation_type_checked, true) ? 'checked' : '';
                            ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="<?php echo $cb_id; ?>" name="compensation_type[]" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $checked; ?>>
                                    <label for="<?php echo $cb_id; ?>"><?php echo htmlspecialchars($opt); ?></label>
                                </div>
                            <?php } ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Source: Barangay (Amount)</label>
                        <input type="number" step="0.01" min="0" name="compensation_barangay_amount" class="form-control" value="<?php echo htmlspecialchars($user['compensation_barangay_amount']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Source: City/Municipal (Amount)</label>
                        <input type="number" step="0.01" min="0" name="compensation_city_amount" class="form-control" value="<?php echo htmlspecialchars($user['compensation_city_amount']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Source: NGOs/NGAs (Amount)</label>
                        <input type="number" step="0.01" min="0" name="compensation_ngo_amount" class="form-control" value="<?php echo htmlspecialchars($user['compensation_ngo_amount']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Source: Parents (Amount)</label>
                        <input type="number" step="0.01" min="0" name="compensation_parents_amount" class="form-control" value="<?php echo htmlspecialchars($user['compensation_parents_amount']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Other Source, specify</label>
                        <input type="text" name="compensation_source_other" class="form-control" value="<?php echo htmlspecialchars($user['compensation_source_other']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Other Source Amount</label>
                        <input type="number" step="0.01" min="0" name="compensation_source_other_amount" class="form-control" value="<?php echo htmlspecialchars($user['compensation_source_other_amount']); ?>">
                    </div>
                </div>

                <p class="subgroup-title">Status as a Child Development Worker</p>
                <div class="settings-form-grid">
                    <div class="form-group">
                        <label>Accreditation Status</label>
                        <select name="cdw_status" class="form-control">
                            <option value="">-- Select --</option>
                            <?php foreach (['Accredited','Not Accredited','Accredited but Expired'] as $opt) {
                                $sel = ($user['cdw_status'] === $opt) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                            } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Date Accredited</label>
                        <input type="date" name="cdw_date_accredited" class="form-control" value="<?php echo htmlspecialchars($user['cdw_date_accredited']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Accreditation No.</label>
                        <input type="text" name="cdw_accreditation_no" class="form-control" value="<?php echo htmlspecialchars($user['cdw_accreditation_no']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Level</label>
                        <select name="cdw_accreditation_level" class="form-control">
                            <option value="">-- Select --</option>
                            <?php foreach (['1','2','3'] as $opt) {
                                $sel = ($user['cdw_accreditation_level'] === $opt) ? 'selected' : '';
                                echo "<option value=\"$opt\" $sel>Level $opt</option>";
                            } ?>
                        </select>
                    </div>
                </div>

                <p class="subgroup-title">ECCD-Related Trainings &amp; Other Courses Attended</p>
                <div class="settings-form-grid">
                    <div class="form-group full-width">
                        <label>Trainings Attended (title, dates, hours, sponsor — one per line)</label>
                        <textarea name="trainings_attended" class="form-control" placeholder="e.g. Rights of the Child — Jan 2024, 8 hrs, sponsored by DSWD"><?php echo htmlspecialchars($user['trainings_attended']); ?></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label>Other Courses Attended (course, year completed — one per line)</label>
                        <textarea name="courses_attended" class="form-control" placeholder="e.g. Basic Life Support — 2023"><?php echo htmlspecialchars($user['courses_attended']); ?></textarea>
                    </div>
                </div>

                <p class="subgroup-title">Working Conditions</p>
                <div class="settings-form-grid">
                    <div class="form-group">
                        <label>Total No. of Children Being Served</label>
                        <input type="number" min="0" name="total_children_served" class="form-control" value="<?php echo htmlspecialchars($user['total_children_served']); ?>">
                    </div>

                    <div class="form-group">
                        <label>No. of Sessions per Day</label>
                        <select name="sessions_per_day" class="form-control">
                            <option value="">-- Select --</option>
                            <?php foreach (['1','2','3','4'] as $opt) {
                                $sel = ($user['sessions_per_day'] === $opt) ? 'selected' : '';
                                echo "<option value=\"$opt\" $sel>$opt</option>";
                            } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>No. of Hours per Session</label>
                        <select name="hours_per_session" class="form-control">
                            <option value="">-- Select --</option>
                            <?php foreach (['1','2','2.5','3'] as $opt) {
                                $sel = ($user['hours_per_session'] === $opt) ? 'selected' : '';
                                echo "<option value=\"$opt\" $sel>$opt hr(s)</option>";
                            } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>No. of Hours Staying in Center</label>
                        <select name="hours_staying_in_center" class="form-control">
                            <option value="">-- Select --</option>
                            <?php foreach (['1-2 hrs','2.5-3 hrs','4-5 hrs','6-7 hrs','8 hours'] as $opt) {
                                $sel = ($user['hours_staying_in_center'] === $opt) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                            } ?>
                        </select>
                    </div>

                    <div class="form-group full-width">
                        <label>Age of Children Being Handled</label>
                        <div class="checkbox-grid">
                            <?php
                            $age_options = ['Below 1 y/o','1 year old','2 years old','3 years old','4 years old'];
                            foreach ($age_options as $opt) {
                                $cb_id = 'age_' . preg_replace('/[^a-z0-9]/i', '', $opt);
                                $checked = in_array($opt, $age_of_children_checked, true) ? 'checked' : '';
                            ?>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="<?php echo $cb_id; ?>" name="age_of_children_handled[]" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $checked; ?>>
                                    <label for="<?php echo $cb_id; ?>"><?php echo htmlspecialchars($opt); ?></label>
                                </div>
                            <?php } ?>
                        </div>
                    </div>

                    <div class="form-group full-width">
                        <label>How Sessions are Conducted</label>
                        <select name="sessions_conducted_with" class="form-control">
                            <option value="">-- Select --</option>
                            <?php foreach (['With reference materials','Without reference materials'] as $opt) {
                                $sel = ($user['sessions_conducted_with'] === $opt) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                            } ?>
                        </select>
                    </div>
                </div>

                <div class="button-group">
                    <button type="submit" name="save_profile" class="btn btn-save">Save Changes</button>
                </div>
            </form>
        </div>
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
<script src="../assets/cdw/sidebar.js"></script>
</body>
</html>