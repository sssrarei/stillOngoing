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
        child_health_information.vaccination_card_file_path,
        child_health_information.allergies,
        child_health_information.comorbidities,
        child_health_information.medical_history_file_path
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
$cdc_name = !empty($child['cdc_name']) ? $child['cdc_name'] : 'N/A';
$cdc_address = !empty($child['cdc_address']) ? $child['cdc_address'] : 'N/A';

$official_vaccination = !empty($child['vaccination_card_file_path']) ? $child['vaccination_card_file_path'] : 'N/A';
$official_allergies = !empty($child['allergies']) ? $child['allergies'] : 'N/A';
$official_comorbidities = !empty($child['comorbidities']) ? $child['comorbidities'] : 'N/A';
$official_medical_history = !empty($child['medical_history_file_path']) ? $child['medical_history_file_path'] : 'N/A';

function renderFileOrText($value){
    if (empty($value) || $value === 'N/A') {
        return 'N/A';
    }

    $value = trim($value);
    $safe_value = htmlspecialchars($value);

    if (preg_match('/(\.\.\/uploads\/[^\s]+|uploads\/[^\s]+)/i', $value, $matches)) {
        $file_path = trim($matches[1]);
        $safe_file_path = htmlspecialchars($file_path);

        $text_only = trim(str_replace($file_path, '', $value));
        $text_only = trim(str_replace('Medical Attached File:', '', $text_only));
        $text_only = trim(str_replace('Vaccination Attached File:', '', $text_only));
        $text_only = trim(str_replace('Attached File:', '', $text_only));

        $html = '';

        if (!empty($text_only)) {
            $html .= '<div class="attached-text">' . nl2br(htmlspecialchars($text_only)) . '</div>';
        }

        $html .= '<a href="' . $safe_file_path . '" target="_blank" class="file-link">View Attached File</a>';

        return $html;
    }

    return nl2br($safe_value);
}

$submission_table_exists = false;
$table_check = $conn->query("SHOW TABLES LIKE 'child_health_information_requests'");
if($table_check && $table_check->num_rows > 0){
    $submission_table_exists = true;
}

if($submission_table_exists){
    $pending_sql = "
        SELECT *
        FROM guardian_health_submissions
        WHERE child_id = ?
          AND guardian_user_id = ?
          AND status = 'Pending'
        ORDER BY submitted_at DESC, submission_id DESC
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

if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_health_info'])){
    if(!$submission_table_exists){
        $message = "Submission table not found. Please create child_health_information_requests first.";
        $message_type = 'error';
    } elseif($pending_submission){
        $message = "You already have a pending health information submission waiting for CDW review.";
        $message_type = 'error';
    } else {
        $allergies = trim($_POST['allergies'] ?? '');
        $comorbidities = trim($_POST['comorbidities'] ?? '');
        $medical_history_text = trim($_POST['medical_history_text'] ?? '');

        $upload_dir = "../uploads/";
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $vaccination_path = '';
        $medical_file_path = '';

        if(isset($_FILES['vaccination_file']) && $_FILES['vaccination_file']['error'] === 0){
            $vaccination_name = time() . "_vacc_" . preg_replace('/[^A-Za-z0-9_\.-]/', '_', basename($_FILES['vaccination_file']['name']));
            $vaccination_target = $upload_dir . $vaccination_name;

            if(move_uploaded_file($_FILES['vaccination_file']['tmp_name'], $vaccination_target)){
                $vaccination_path = $vaccination_target;
            }
        }

        if(isset($_FILES['medical_file']) && $_FILES['medical_file']['error'] === 0){
            $medical_name = time() . "_med_" . preg_replace('/[^A-Za-z0-9_\.-]/', '_', basename($_FILES['medical_file']['name']));
            $medical_target = $upload_dir . $medical_name;

            if(move_uploaded_file($_FILES['medical_file']['tmp_name'], $medical_target)){
                $medical_file_path = $medical_target;
            }
        }

        if(empty($vaccination_path) && empty($allergies) && empty($comorbidities) && empty($medical_history_text) && empty($medical_file_path)){
            $message = "Please provide at least one health information entry before submitting.";
            $message_type = 'error';
        } else {
            if(!empty($medical_history_text) && !empty($medical_file_path)){
                $medical_history_value = $medical_history_text . "\n\nAttached File: " . $medical_file_path;
            } elseif(!empty($medical_history_text)) {
                $medical_history_value = $medical_history_text;
            } elseif(!empty($medical_file_path)) {
                $medical_history_value = $medical_file_path;
            } else {
                $medical_history_value = '';
            }

            $insert_sql = "
                INSERT INTO child_health_information_requests (
                child_id,
                guardian_id,
                vaccination_card_file_path,
                allergies,
                comorbidities,
                medical_history,
                medical_history_file_path,
                status,
                submitted_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
            ";

            $insert_stmt = $conn->prepare($insert_sql);

            if(!$insert_stmt){
                $message = "Prepare error: " . $conn->error;
                $message_type = 'error';
            } else {
               $insert_stmt->bind_param(
                "iisssss",
                $child_id,
                $guardian_user_id,
                $vaccination_path,
                $allergies,
                $comorbidities,
                $medical_history_text,
                $medical_file_path
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
                </div>

                <h3 class="sub-section-title">Current Health Information</h3>

                <div class="info-list">
                    <div class="info-row">
                        <span class="info-label">Vaccination Records</span>
                        <div class="info-value"><?php echo renderFileOrText($official_vaccination); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Allergies</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_allergies); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Comorbidities</span>
                        <div class="info-value"><?php echo htmlspecialchars($official_comorbidities); ?></div>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Medical History</span>
                        <div class="info-value"><?php echo renderFileOrText($official_medical_history); ?></div>
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
                    <form method="POST" enctype="multipart/form-data" class="health-form">

                        <div class="form-group">
                            <label class="form-label">Vaccination Records (Upload Image)</label>
                            <input type="file" name="vaccination_file" class="form-input-file" accept=".jpg,.jpeg,.png,.webp">
                            <div class="form-help">Upload the vaccination card image if available.</div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Allergies</label>
                            <textarea name="allergies" class="form-control" placeholder="Enter allergies"><?php echo ($official_allergies !== 'N/A') ? htmlspecialchars($official_allergies) : ''; ?></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Comorbidities</label>
                            <textarea name="comorbidities" class="form-control" placeholder="Enter comorbidities"><?php echo ($official_comorbidities !== 'N/A') ? htmlspecialchars($official_comorbidities) : ''; ?></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Medical History (Text)</label>
                            <textarea name="medical_history_text" class="form-control" placeholder="Enter medical history"><?php echo ($official_medical_history !== 'N/A') ? htmlspecialchars($official_medical_history) : ''; ?></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Medical History (Upload Image)</label>
                            <input type="file" name="medical_file" class="form-input-file" accept=".jpg,.jpeg,.png,.webp">
                            <div class="form-help">You may upload a medical document image if needed.</div>
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