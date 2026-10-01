<?php
include '../includes/auth.php';
include '../config/database.php';
checkRole(1);

$success = "";
$error = "";

$barangay_list = [
    "Alima", "Aniban I", "Aniban II", "Aniban III", "Aniban IV", "Aniban V",
    "Banalo", "Bayanan", "Campo Santo", "Daang Bukid", "Digman", "Dulong Bayan",
    "Habay I", "Habay II", "Kaingin", "Ligas I", "Ligas II", "Ligas III",
    "Mabolo I", "Mabolo II", "Mabolo III", "Maliksi I", "Maliksi II", "Maliksi III",
    "Mambog I", "Mambog II", "Mambog III", "Mambog IV", "Mambog V",
    "Molino I", "Molino II", "Molino III", "Molino IV", "Molino V", "Molino VI", "Molino VII",
    "Niog I", "Niog II", "Niog III",
    "P. F. Espiritu I", "P. F. Espiritu II", "P. F. Espiritu III", "P. F. Espiritu IV",
    "P. F. Espiritu V", "P. F. Espiritu VI", "P. F. Espiritu VII", "P. F. Espiritu VIII",
    "Queens Row Central", "Queens Row East", "Queens Row West",
    "Real I", "Real II",
    "Salinas I", "Salinas II", "Salinas III", "Salinas IV",
    "San Nicolas I", "San Nicolas II", "San Nicolas III",
    "Sineguelasan", "Tabing Dagat",
    "Talaba I", "Talaba II", "Talaba III", "Talaba IV", "Talaba V", "Talaba VI", "Talaba VII",
    "Zapote I", "Zapote II", "Zapote III", "Zapote IV", "Zapote V"
];

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

$cdc_id = isset($_GET['cdc_id']) ? (int) $_GET['cdc_id'] : 0;

if ($cdc_id <= 0) {
    die("Invalid CDC selected.");
}

$cdc_stmt = $conn->prepare("SELECT * FROM cdc WHERE cdc_id = ? LIMIT 1");
$cdc_stmt->bind_param("i", $cdc_id);
$cdc_stmt->execute();
$cdc_result = $cdc_stmt->get_result();

if ($cdc_result->num_rows === 0) {
    die("CDC not found.");
}

$cdc = $cdc_result->fetch_assoc();
$cdc_stmt->close();

if (isset($_POST['update_cdc'])) {
    $cdc_name = trim($_POST['cdc_name'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');
    $address  = trim($_POST['address'] ?? '');

    $year_established = ($_POST['year_established'] ?? '') !== '' ? (int) $_POST['year_established'] : null;
    $telephone   = blankToNull($_POST['telephone'] ?? '');
    $fax_number  = blankToNull($_POST['fax_number'] ?? '');
    $cdc_email   = blankToNull($_POST['cdc_email'] ?? '');

    $accreditation_status = blankToNull($_POST['accreditation_status'] ?? '');
    $date_accredited       = blankToNull($_POST['date_accredited'] ?? '');
    $accreditation_no       = blankToNull($_POST['accreditation_no'] ?? '');
    $accreditation_level    = blankToNull($_POST['accreditation_level'] ?? '');

    $services_offered = checkboxListToString($_POST['services_offered'] ?? []);
    $services_offered_other = blankToNull($_POST['services_offered_other'] ?? '');

    $facilities_available = checkboxListToString($_POST['facilities_available'] ?? []);
    $facilities_other = blankToNull($_POST['facilities_other'] ?? '');

    $utilities_available = checkboxListToString($_POST['utilities_available'] ?? []);
    $utilities_other = blankToNull($_POST['utilities_other'] ?? '');

    $equipment_materials = checkboxListToString($_POST['equipment_materials'] ?? []);
    $equipment_other = blankToNull($_POST['equipment_other'] ?? '');

    if ($cdc_name === '') {
        $error = "CDC Name is required.";
    } else {
        $check_stmt = $conn->prepare("SELECT cdc_id FROM cdc WHERE cdc_name = ? AND cdc_id != ?");
        $check_stmt->bind_param("si", $cdc_name, $cdc_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $error = "Another CDC already uses that name.";
        } else {
            $update_stmt = $conn->prepare("
                UPDATE cdc SET
                    cdc_name = ?, barangay = ?, address = ?,
                    year_established = ?, telephone = ?, fax_number = ?, email = ?,
                    accreditation_status = ?, date_accredited = ?, accreditation_no = ?, accreditation_level = ?,
                    services_offered = ?, services_offered_other = ?,
                    facilities_available = ?, facilities_other = ?,
                    utilities_available = ?, utilities_other = ?,
                    equipment_materials = ?, equipment_other = ?
                WHERE cdc_id = ?
            ");
            $update_stmt->bind_param(
                "sssisssssssssssssssi",
                $cdc_name, $barangay, $address,
                $year_established, $telephone, $fax_number, $cdc_email,
                $accreditation_status, $date_accredited, $accreditation_no, $accreditation_level,
                $services_offered, $services_offered_other,
                $facilities_available, $facilities_other,
                $utilities_available, $utilities_other,
                $equipment_materials, $equipment_other,
                $cdc_id
            );

            if ($update_stmt->execute()) {
                $success = "CDC information updated successfully.";

                // Refresh local data so the form reflects the saved values
                $cdc_stmt = $conn->prepare("SELECT * FROM cdc WHERE cdc_id = ? LIMIT 1");
                $cdc_stmt->bind_param("i", $cdc_id);
                $cdc_stmt->execute();
                $cdc = $cdc_stmt->get_result()->fetch_assoc();
                $cdc_stmt->close();
            } else {
                $error = "Error updating CDC: " . $update_stmt->error;
            }
        }
    }
}

// No. of CDW currently assigned (computed, matches add_cdc.php's logic)
$cdw_count = 0;
$cdw_count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM cdw_assignments WHERE cdc_id = ?");
$cdw_count_stmt->bind_param("i", $cdc_id);
$cdw_count_stmt->execute();
$cdw_count_row = $cdw_count_stmt->get_result()->fetch_assoc();
$cdw_count = (int) ($cdw_count_row['total'] ?? 0);
$cdw_count_stmt->close();

$services_checked   = stringToCheckedArray($cdc['services_offered'] ?? '');
$facilities_checked = stringToCheckedArray($cdc['facilities_available'] ?? '');
$utilities_checked  = stringToCheckedArray($cdc['utilities_available'] ?? '');
$equipment_checked  = stringToCheckedArray($cdc['equipment_materials'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit CDC | NutriTrack</title>
    <link rel="stylesheet" href="../assets/admin/admin-style.css">
    <link rel="stylesheet" href="../assets/admin/add_cdc.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        .section-heading{
            margin-top:20px;
            font-size:15px;
            font-weight:700;
            color:#2C5EAD;
        }
        .section-heading:first-child{
            margin-top:0;
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
        }
        .checkbox-item input{
            width:16px;
            height:16px;
            cursor:pointer;
        }
        .readonly-stat{
            background:#f8fafc;
            border:1px solid #e5e7eb;
            border-radius:10px;
            padding:12px 14px;
            font-size:13px;
            color:#374151;
        }
    </style>
</head>
<body class="<?php echo (isset($_SESSION['theme_mode']) && $_SESSION['theme_mode'] === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/admin_sidebar.php'; ?>
<?php include '../includes/admin_topbar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-header">
        <a href="add_cdc.php" style="font-size:13px; color:#2C5EAD; font-weight:600;">← Back to CDC Management</a>
        <h1 style="margin-top:10px;">Edit CDC — <?php echo htmlspecialchars($cdc['cdc_name']); ?></h1>
        <p>Update this Child Development Center's Form 7 profile details.</p>
    </div>

    <?php if ($success != "") { ?>
        <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
    <?php } ?>

    <?php if ($error != "") { ?>
        <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
    <?php } ?>

    <div class="form-card show">
        <div class="card-header">
            <h2>CDC Information</h2>
            <p>Number of CDW currently assigned: <strong><?php echo $cdw_count; ?></strong> (managed from CDC Management &rarr; Manage CDW)</p>
        </div>

        <form method="POST">
            <h3 class="section-heading">Basic Information</h3>
            <div class="form-grid">
                <div class="form-group full">
                    <label for="cdc_name">CDC Name</label>
                    <input type="text" id="cdc_name" name="cdc_name" required
                           value="<?php echo htmlspecialchars($cdc['cdc_name']); ?>">
                </div>

                <div class="form-group">
                    <label for="barangay">Barangay</label>
                    <select id="barangay" name="barangay">
                        <option value="">Select Barangay</option>
                        <?php foreach ($barangay_list as $b) {
                            $sel = ($cdc['barangay'] === $b) ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($b) . "\" $sel>" . htmlspecialchars($b) . "</option>";
                        } ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="address">Address</label>
                    <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($cdc['address'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label for="year_established">Year Established</label>
                    <input type="number" id="year_established" name="year_established" min="1900" max="<?php echo date('Y'); ?>"
                           value="<?php echo htmlspecialchars($cdc['year_established'] ?? ''); ?>">
                </div>
            </div>

            <h3 class="section-heading">Contact Details</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="telephone">Telephone No.</label>
                    <input type="text" id="telephone" name="telephone" value="<?php echo htmlspecialchars($cdc['telephone'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="fax_number">Fax No.</label>
                    <input type="text" id="fax_number" name="fax_number" value="<?php echo htmlspecialchars($cdc['fax_number'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="cdc_email">Email Address</label>
                    <input type="email" id="cdc_email" name="cdc_email" value="<?php echo htmlspecialchars($cdc['email'] ?? ''); ?>">
                </div>
            </div>

            <h3 class="section-heading">Status of the Center</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="accreditation_status">Accreditation Status</label>
                    <select id="accreditation_status" name="accreditation_status">
                        <option value="">-- Select --</option>
                        <?php foreach (['Accredited','Not Accredited','Accredited but Expired'] as $opt) {
                            $sel = (($cdc['accreditation_status'] ?? '') === $opt) ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars($opt) . "</option>";
                        } ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="date_accredited">Date Accredited</label>
                    <input type="date" id="date_accredited" name="date_accredited" value="<?php echo htmlspecialchars($cdc['date_accredited'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="accreditation_no">Accreditation No.</label>
                    <input type="text" id="accreditation_no" name="accreditation_no" value="<?php echo htmlspecialchars($cdc['accreditation_no'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="accreditation_level">Level</label>
                    <select id="accreditation_level" name="accreditation_level">
                        <option value="">-- Select --</option>
                        <?php foreach (['1','2','3'] as $opt) {
                            $sel = (($cdc['accreditation_level'] ?? '') === $opt) ? 'selected' : '';
                            echo "<option value=\"$opt\" $sel>Level $opt</option>";
                        } ?>
                    </select>
                </div>
            </div>

            <h3 class="section-heading">Services Offered</h3>
            <div class="checkbox-grid">
                <?php
                $services_options = ['Supplemental Parental Care','Nutritional Care','Early Learning',"Guiding Children's Behavior",'Supplemental Feeding','Play & Socialization','Health Related Activities','Inculcating Character & Values','Child Safety & Protection'];
                foreach ($services_options as $opt) {
                    $cb_id = 'svc_' . preg_replace('/[^a-z0-9]/i', '', $opt);
                    $checked = in_array($opt, $services_checked, true) ? 'checked' : '';
                ?>
                    <div class="checkbox-item">
                        <input type="checkbox" id="<?php echo $cb_id; ?>" name="services_offered[]" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $checked; ?>>
                        <label for="<?php echo $cb_id; ?>"><?php echo htmlspecialchars($opt); ?></label>
                    </div>
                <?php } ?>
            </div>
            <div class="form-group full" style="margin-top:10px;">
                <label for="services_offered_other">Others, please specify</label>
                <input type="text" id="services_offered_other" name="services_offered_other" value="<?php echo htmlspecialchars($cdc['services_offered_other'] ?? ''); ?>">
            </div>

            <h3 class="section-heading">Available Facilities</h3>
            <div class="checkbox-grid">
                <?php
                $facilities_options = ['CDW Table','Toilet','Play Area','Nap Area','Classroom'];
                foreach ($facilities_options as $opt) {
                    $cb_id = 'fac_' . preg_replace('/[^a-z0-9]/i', '', $opt);
                    $checked = in_array($opt, $facilities_checked, true) ? 'checked' : '';
                ?>
                    <div class="checkbox-item">
                        <input type="checkbox" id="<?php echo $cb_id; ?>" name="facilities_available[]" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $checked; ?>>
                        <label for="<?php echo $cb_id; ?>"><?php echo htmlspecialchars($opt); ?></label>
                    </div>
                <?php } ?>
            </div>
            <div class="form-group full" style="margin-top:10px;">
                <label for="facilities_other">Others, please specify</label>
                <input type="text" id="facilities_other" name="facilities_other" value="<?php echo htmlspecialchars($cdc['facilities_other'] ?? ''); ?>">
            </div>

            <h3 class="section-heading">Utilities/Services Offered</h3>
            <div class="checkbox-grid">
                <?php
                $utilities_options = ['Electricity','Feeding Facilities & Utensils','First Aid Kit','Running Water','Playground with Equipment','Structure with Accessibility - PWD','Potable Water','Secured Doors & Windows','Computer',"Facilities & Eqpt. To Measure Child's Growth"];
                foreach ($utilities_options as $opt) {
                    $cb_id = 'util_' . preg_replace('/[^a-z0-9]/i', '', $opt);
                    $checked = in_array($opt, $utilities_checked, true) ? 'checked' : '';
                ?>
                    <div class="checkbox-item">
                        <input type="checkbox" id="<?php echo $cb_id; ?>" name="utilities_available[]" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $checked; ?>>
                        <label for="<?php echo $cb_id; ?>"><?php echo htmlspecialchars($opt); ?></label>
                    </div>
                <?php } ?>
            </div>
            <div class="form-group full" style="margin-top:10px;">
                <label for="utilities_other">Others, please specify</label>
                <input type="text" id="utilities_other" name="utilities_other" value="<?php echo htmlspecialchars($cdc['utilities_other'] ?? ''); ?>">
            </div>

            <h3 class="section-heading">Available Equipment &amp; Learning Materials</h3>
            <div class="checkbox-grid">
                <?php
                $equipment_options = ['Audio/Video Materials','Manipulative Toys','Reading Materials','Musical Instrument',"Children's Books",'Coloring Books'];
                foreach ($equipment_options as $opt) {
                    $cb_id = 'eqp_' . preg_replace('/[^a-z0-9]/i', '', $opt);
                    $checked = in_array($opt, $equipment_checked, true) ? 'checked' : '';
                ?>
                    <div class="checkbox-item">
                        <input type="checkbox" id="<?php echo $cb_id; ?>" name="equipment_materials[]" value="<?php echo htmlspecialchars($opt); ?>" <?php echo $checked; ?>>
                        <label for="<?php echo $cb_id; ?>"><?php echo htmlspecialchars($opt); ?></label>
                    </div>
                <?php } ?>
            </div>
            <div class="form-group full" style="margin-top:10px;">
                <label for="equipment_other">Others, please specify</label>
                <input type="text" id="equipment_other" name="equipment_other" value="<?php echo htmlspecialchars($cdc['equipment_other'] ?? ''); ?>">
            </div>

            <div class="form-actions" style="margin-top:20px;">
                <button type="submit" name="update_cdc" class="btn btn-primary">Save Changes</button>
                <a href="add_cdc.php" class="btn btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script src="../assets/admin/sidebar.js"></script>
</body>
</html>