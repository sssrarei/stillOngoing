<?php
include '../includes/auth.php';
include '../config/database.php';
checkRole(1);

function displayOrNA($value) {
    $value = trim((string) $value);
    return ($value === '') ? 'N/A' : htmlspecialchars($value);
}

$user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;

if ($user_id <= 0) {
    die("Invalid CDW selected.");
}

$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ? AND role_id = 2 LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("CDW account not found.");
}

$cdw = $result->fetch_assoc();
$stmt->close();

// Compute age from birthday (not stored, always derived)
$computed_age = 'N/A';
if (!empty($cdw['birthday'])) {
    try {
        $birth = new DateTime($cdw['birthday']);
        $today = new DateTime();
        $computed_age = $today->diff($birth)->y . ' years old';
    } catch (Exception $e) {
        $computed_age = 'N/A';
    }
}

// Assigned CDC(s), same logic used elsewhere in the admin module
$assigned_cdcs = [];
$cdc_stmt = $conn->prepare("
    SELECT c.cdc_name
    FROM cdw_assignments ca
    INNER JOIN cdc c ON ca.cdc_id = c.cdc_id
    WHERE ca.user_id = ?
    ORDER BY c.cdc_name ASC
");
$cdc_stmt->bind_param("i", $user_id);
$cdc_stmt->execute();
$cdc_result = $cdc_stmt->get_result();
while ($row = $cdc_result->fetch_assoc()) {
    $assigned_cdcs[] = $row['cdc_name'];
}
$cdc_stmt->close();
$assigned_cdc_display = !empty($assigned_cdcs) ? implode(', ', $assigned_cdcs) : 'No assigned CDC';

$full_name = trim($cdw['first_name'] . ' ' . $cdw['last_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CDW Profile | NutriTrack</title>
    <link rel="stylesheet" href="../assets/admin/admin-style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        .page-wrapper{
            max-width:1100px;
            margin:0 auto;
        }

        .back-link{
            display:inline-flex;
            align-items:center;
            gap:8px;
            margin-bottom:12px;
            color:#2C5EAD;
            font-size:13px;
            font-weight:600;
            text-decoration:none;
        }

        .profile-card{
            background:#ffffff;
            border:1px solid #dcdcdc;
            border-radius:14px;
            padding:24px;
            margin-bottom:18px;
        }

        .sub-section-title{
            font-family:'Poppins', sans-serif;
            font-size:16px;
            font-weight:700;
            color:#2C5EAD;
            margin:22px 0 14px 0;
            padding-top:14px;
            border-top:1px solid #eef1f5;
        }

        .sub-section-title:first-child{
            margin-top:0;
            padding-top:0;
            border-top:none;
        }

        .info-list{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:16px 24px;
        }

        .info-list.full-col{
            grid-template-columns:1fr;
        }

        .info-row{
            display:flex;
            flex-direction:column;
            gap:4px;
        }

        .info-row.full-width{
            grid-column:1 / -1;
        }

        .info-label{
            font-size:12px;
            font-weight:600;
            color:#6b7280;
        }

        .info-value{
            font-size:14px;
            color:#1f2937;
            line-height:1.5;
            white-space:pre-line;
        }

        body.dark-mode .profile-card{
            background:#111827;
            border-color:#334155;
        }

        body.dark-mode .info-label{
            color:#9ca3af;
        }

        body.dark-mode .info-value{
            color:#f3f4f6;
        }

        body.dark-mode .sub-section-title{
            color:#93c5fd;
            border-top-color:#334155;
        }

        @media (max-width: 768px){
            .info-list{
                grid-template-columns:1fr;
            }
        }
    </style>
</head>
<body class="<?php echo (isset($_SESSION['theme_mode']) && $_SESSION['theme_mode'] === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/admin_sidebar.php'; ?>
<?php include '../includes/admin_topbar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-wrapper">

        <a href="add_user.php" class="back-link">← Back to User Management</a>

        <div class="profile-card">
            <div class="page-header" style="margin-bottom:20px;">
                <h1><?php echo htmlspecialchars($full_name); ?></h1>
                <p>CDW Profile — read-only view. To make corrections, ask the CDW to update it themselves via their account settings.</p>
            </div>

            <!-- ============================
                 BASIC ACCOUNT INFO
            ============================= -->
            <p class="sub-section-title">Basic Account Info</p>
            <div class="info-list">
                <div class="info-row">
                    <span class="info-label">Email</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['email']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Mobile Number</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['contact_number']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Assigned CDC</span>
                    <div class="info-value"><?php echo htmlspecialchars($assigned_cdc_display); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Account Created</span>
                    <div class="info-value">
                        <?php echo (!empty($cdw['created_at']) && $cdw['created_at'] !== '0000-00-00 00:00:00')
                            ? date("F d, Y", strtotime($cdw['created_at']))
                            : 'N/A'; ?>
                    </div>
                </div>

                <div class="info-row full-width">
                    <span class="info-label">Address</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['address']); ?></div>
                </div>
            </div>

            <!-- ============================
                 PERSONAL INFORMATION
            ============================= -->
            <p class="sub-section-title">Personal Information</p>
            <div class="info-list">
                <div class="info-row">
                    <span class="info-label">Sex</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['sex']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Birthday</span>
                    <div class="info-value">
                        <?php
                        echo !empty($cdw['birthday'])
                            ? date("F d, Y", strtotime($cdw['birthday'])) . " ($computed_age)"
                            : 'N/A';
                        ?>
                    </div>
                </div>

                <div class="info-row">
                    <span class="info-label">Religion</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['religion']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Ethnicity</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['ethnicity']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Civil Status</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['civil_status']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">No. of Children</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['no_of_children']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Home Number</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['home_number']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Office Number</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['office_number']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Fax Number</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['fax_number']); ?></div>
                </div>
            </div>

            <!-- ============================
                 EDUCATIONAL BACKGROUND
            ============================= -->
            <p class="sub-section-title">Educational Background</p>
            <div class="info-list">
                <div class="info-row">
                    <span class="info-label">Educational Attainment</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['educational_background']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Degree</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['degree']); ?></div>
                </div>

                <div class="info-row full-width">
                    <span class="info-label">Eligibility</span>
                    <div class="info-value">
                        <?php echo displayOrNA($cdw['eligibility']); ?>
                        <?php if (!empty($cdw['eligibility_other'])) { ?>
                            <br><small>Others: <?php echo htmlspecialchars($cdw['eligibility_other']); ?></small>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <!-- ============================
                 WORK-RELATED INFORMATION
            ============================= -->
            <p class="sub-section-title">Work-Related Information</p>
            <div class="info-list">
                <div class="info-row">
                    <span class="info-label">No. of Years as CDW</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['years_as_cdw']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Terms of Employment</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['terms_of_employment']); ?></div>
                </div>

                <div class="info-row full-width">
                    <span class="info-label">Monthly Compensation Type</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['compensation_type']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Source: Barangay (Amount)</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['compensation_barangay_amount']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Source: City/Municipal (Amount)</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['compensation_city_amount']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Source: NGOs/NGAs (Amount)</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['compensation_ngo_amount']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Source: Parents (Amount)</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['compensation_parents_amount']); ?></div>
                </div>

                <div class="info-row full-width">
                    <span class="info-label">Other Source</span>
                    <div class="info-value">
                        <?php echo displayOrNA($cdw['compensation_source_other']); ?>
                        <?php if (!empty($cdw['compensation_source_other_amount'])) { ?>
                            — <?php echo htmlspecialchars($cdw['compensation_source_other_amount']); ?>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <!-- ============================
                 STATUS AS A CDW
            ============================= -->
            <p class="sub-section-title">Status as a Child Development Worker</p>
            <div class="info-list">
                <div class="info-row">
                    <span class="info-label">Accreditation Status</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['cdw_status']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Date Accredited</span>
                    <div class="info-value">
                        <?php echo !empty($cdw['cdw_date_accredited'])
                            ? date("F d, Y", strtotime($cdw['cdw_date_accredited']))
                            : 'N/A'; ?>
                    </div>
                </div>

                <div class="info-row">
                    <span class="info-label">Accreditation No.</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['cdw_accreditation_no']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Level</span>
                    <div class="info-value">
                        <?php echo !empty($cdw['cdw_accreditation_level']) ? 'Level ' . htmlspecialchars($cdw['cdw_accreditation_level']) : 'N/A'; ?>
                    </div>
                </div>
            </div>

            <!-- ============================
                 TRAININGS & COURSES
            ============================= -->
            <p class="sub-section-title">ECCD-Related Trainings &amp; Other Courses Attended</p>
            <div class="info-list full-col">
                <div class="info-row">
                    <span class="info-label">Trainings Attended</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['trainings_attended']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Other Courses Attended</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['courses_attended']); ?></div>
                </div>
            </div>

            <!-- ============================
                 WORKING CONDITIONS
            ============================= -->
            <p class="sub-section-title">Working Conditions</p>
            <div class="info-list">
                <div class="info-row">
                    <span class="info-label">Total No. of Children Being Served</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['total_children_served']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">No. of Sessions per Day</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['sessions_per_day']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">No. of Hours per Session</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['hours_per_session']); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">No. of Hours Staying in Center</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['hours_staying_in_center']); ?></div>
                </div>

                <div class="info-row full-width">
                    <span class="info-label">Age of Children Being Handled</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['age_of_children_handled']); ?></div>
                </div>

                <div class="info-row full-width">
                    <span class="info-label">How Sessions are Conducted</span>
                    <div class="info-value"><?php echo displayOrNA($cdw['sessions_conducted_with']); ?></div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="../assets/admin/sidebar.js"></script>
</body>
</html>