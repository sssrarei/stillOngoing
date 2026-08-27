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

        child_health_information.vaccination_card_file_path,
        child_health_information.allergies,
        child_health_information.comorbidities,
        child_health_information.medical_history_file_path

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

$guardian_contact_number = !empty($child['guardian_contact_number']) 
    ? $child['guardian_contact_number'] 
    : (!empty($child['contact_number']) ? $child['contact_number'] : 'N/A');

$guardian_email = !empty($child['guardian_email']) 
    ? $child['guardian_email'] 
    : 'N/A';

$guardian_address = !empty($child['guardian_address']) 
    ? $child['guardian_address'] 
    : (!empty($child['address']) ? $child['address'] : 'N/A');

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

$vaccination_records = !empty($child['vaccination_card_file_path']) ? $child['vaccination_card_file_path'] : 'N/A';
$allergies = !empty($child['allergies']) ? $child['allergies'] : 'N/A';
$comorbidities = !empty($child['comorbidities']) ? $child['comorbidities'] : 'N/A';
$medical_history = !empty($child['medical_history_file_path']) ? $child['medical_history_file_path'] : 'N/A';

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

        $html = '';

        if (!empty($text_only)) {
            $html .= '<div>' . nl2br(htmlspecialchars($text_only)) . '</div>';
        }

        $link_text = 'View Attached File';

if (stripos($value, 'vacc') !== false) {
    $link_text = 'View Vaccination Card';
} elseif (stripos($value, 'med') !== false) {
    $link_text = 'View Medical History File';
}

$html .= '<a href="' . $safe_file_path . '" target="_blank" class="file-link">' . $link_text . '</a>';

        return $html;
    }

    return nl2br($safe_value);
}

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
                            SET vaccination_card_file_path = ?,
                                allergies = ?,
                                comorbidities = ?,
                                medical_history_file_path = ?
                            WHERE child_id = ?
                        ";
                        $update_health_stmt = $conn->prepare($update_health_sql);

                        if ($update_health_stmt) {
                            $update_health_stmt->bind_param(
                                "ssssi",
                                $submission_row['vaccination_card_file_path'],
                                $submission_row['allergies'],
                                $submission_row['comorbidities'],
                                $submission_row['medical_history_file_path'],
                                $child_id
                            );
                            $update_health_stmt->execute();
                            $update_health_stmt->close();
                        }
                    } else {
                        $insert_health_sql = "
                            INSERT INTO child_health_information (
                                child_id,
                                vaccination_card_file_path,
                                allergies,
                                comorbidities,
                                medical_history_file_path
                            ) VALUES (?, ?, ?, ?, ?)
                        ";
                        $insert_health_stmt = $conn->prepare($insert_health_sql);

                        if ($insert_health_stmt) {
                            $insert_health_stmt->bind_param(
                                "issss",
                                $child_id,
                                $submission_row['vaccination_card_file_path'],
                                $submission_row['allergies'],
                                $submission_row['comorbidities'],
                                $submission_row['medical_history_file_path']
                            );
                            $insert_health_stmt->execute();
                            $insert_health_stmt->close();
                        }
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
            </div>

            <h4 class="sub-section-title">Child Health Information</h4>

            <div class="info-list">
                <div class="info-row">
                    <span class="info-label">Vaccination Records</span>
                    <div class="info-value"><?php echo renderFileOrText($vaccination_records); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Allergies</span>
                    <div class="info-value"><?php echo htmlspecialchars($allergies); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Comorbidities</span>
                    <div class="info-value"><?php echo htmlspecialchars($comorbidities); ?></div>
                </div>

                <div class="info-row">
                    <span class="info-label">Medical History</span>
                    <div class="info-value"><?php echo renderFileOrText($medical_history); ?></div>
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
                            <span class="info-label">Vaccination Records</span>
                            <div class="info-value">
                                <?php echo renderFileOrText(!empty($guardian_submission['vaccination_card_file_path']) ? $guardian_submission['vaccination_card_file_path'] : 'N/A'); ?>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Allergies</span>
                            <div class="info-value">
                                <?php echo !empty($guardian_submission['allergies']) ? htmlspecialchars($guardian_submission['allergies']) : 'N/A'; ?>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Comorbidities</span>
                            <div class="info-value">
                                <?php echo !empty($guardian_submission['comorbidities']) ? htmlspecialchars($guardian_submission['comorbidities']) : 'N/A'; ?>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Medical History</span>
                            <div class="info-value">
                                <?php echo renderFileOrText(!empty($guardian_submission['medical_history_file_path']) ? $guardian_submission['medical_history_file_path'] : 'N/A'); ?>
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
                            <option value="Vaccination card image is unclear or unreadable">Vaccination card image is unclear or unreadable</option>
                            <option value="Medical history document is unclear or unreadable">Medical history document is unclear or unreadable</option>
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