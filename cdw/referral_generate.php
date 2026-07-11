<?php
include '../includes/auth.php';
include '../config/database.php';

if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 2) {
    header("Location: ../login.php");
    exit();
}

if (!isset($_SESSION['active_cdc_id'])) {
    die("Please select an active CDC first from the dashboard.");
}

$cdc_id = (int) $_SESSION['active_cdc_id'];
$user_id = (int) $_SESSION['user_id'];

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function build_child_full_name($first, $middle, $last) {
    $parts = array_filter([trim((string)$first), trim((string)$middle), trim((string)$last)]);
    return implode(' ', $parts);
}

function format_datetime_display($datetime) {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return 'N/A';
    }

    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return 'N/A';
    }

    return date("F d, Y g:i A", $timestamp);
}

$guidance_id = 0;
if (isset($_GET['guidance_id'])) {
    $guidance_id = (int) $_GET['guidance_id'];
} elseif (isset($_POST['guidance_id'])) {
    $guidance_id = (int) $_POST['guidance_id'];
}

if (!$guidance_id) {
    die("Invalid request. No Intervention Guidance record specified.");
}

/*
|--------------------------------------------------------------------------
| VERY IMPORTANT RULE:
| A referral may ONLY be generated from an official, CSWD-approved
| intervention_guidance record where:
|   - sent_to_guardian = 1  (CSWD already sent the guidance to the guardian)
|   - needs_referral   = 1  (CSWD flagged this case for referral)
|
| We never read from submitted_reports or anthropometric_records here.
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        ig.guidance_id,
        ig.child_id,
        ig.intervention_category,
        ig.guidance_text,
        ig.status_note,
        ig.sent_at,
        c.first_name,
        c.middle_name,
        c.last_name,
        c.cdc_id,
        g.first_name AS guardian_first_name,
        g.last_name AS guardian_last_name
    FROM intervention_guidance ig
    INNER JOIN children c ON c.child_id = ig.child_id
    LEFT JOIN guardians g ON g.child_id = c.child_id
    WHERE ig.guidance_id = ?
      AND ig.needs_referral = 1
      AND ig.sent_to_guardian = 1
    LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $guidance_id);
$stmt->execute();
$guidance = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$guidance) {
    die("This record is not a valid, official referral-eligible Intervention Guidance record.");
}

if ((int) $guidance['cdc_id'] !== $cdc_id) {
    die("You are not authorized to generate a referral for this record.");
}

// Enforce: one referral per guidance record (matches the UNIQUE key on referrals.guidance_id)
$check = $conn->prepare("SELECT referral_id FROM referrals WHERE guidance_id = ? LIMIT 1");
$check->bind_param("i", $guidance_id);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

if ($existing) {
    header("Location: referral_forms.php?error=already_generated");
    exit();
}

$child_name = build_child_full_name($guidance['first_name'], $guidance['middle_name'], $guidance['last_name']);
$guardian_name = trim(($guidance['guardian_first_name'] ?? '') . ' ' . ($guidance['guardian_last_name'] ?? ''));
$guardian_name = $guardian_name !== '' ? $guardian_name : 'No guardian linked yet';

$error = "";
$default_reason = trim((string) $guidance['status_note']);
$facility_options = [
    'City Health Office',
    'City Social Welfare Office',
    'City Health Services',
    'Office of Social Welfare Development',
];
$recommended_facility_choice = "";
$recommended_facility_other = "";
$remarks_val = "";
$date_to_send_val = date("Y-m-d");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_referral'])) {
    $reason_for_referral = trim($_POST['reason_for_referral'] ?? '');
    $recommended_facility_choice = trim($_POST['recommended_facility'] ?? '');
    $recommended_facility_other = trim($_POST['recommended_facility_other'] ?? '');
    $remarks_val = trim($_POST['remarks'] ?? '');
    $date_to_send_val = trim($_POST['date_to_send'] ?? '');

    $final_facility = ($recommended_facility_choice === 'Others')
        ? $recommended_facility_other
        : $recommended_facility_choice;

    if ($reason_for_referral === '') {
        $error = "Reason for Referral is required.";
    } elseif ($recommended_facility_choice === '') {
        $error = "Please select a Recommended Facility.";
    } elseif ($recommended_facility_choice === 'Others' && $recommended_facility_other === '') {
        $error = "Please specify the facility name.";
    } elseif ($date_to_send_val === '' || !strtotime($date_to_send_val)) {
        $error = "Please choose a valid Date to Send.";
    } else {
        // Re-check right before insert to avoid a race condition (double-submit, two tabs, etc.)
        $check2 = $conn->prepare("SELECT referral_id FROM referrals WHERE guidance_id = ? LIMIT 1");
        $check2->bind_param("i", $guidance_id);
        $check2->execute();
        $dup = $check2->get_result()->fetch_assoc();
        $check2->close();

        if ($dup) {
            header("Location: referral_forms.php?error=already_generated");
            exit();
        }

        // Generating this form IS the act of sending it to the guardian —
        // no separate "Send to Guardian" step. Status goes straight to
        // 'Sent' with sent_at stamped at the moment of submission.
        $insert = $conn->prepare("
            INSERT INTO referrals
                (guidance_id, child_id, generated_by, final_category, reason_for_referral, recommended_facility, remarks, date_to_send, status, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Sent', NOW())
        ");
        $insert->bind_param(
            "iiisssss",
            $guidance_id,
            $guidance['child_id'],
            $user_id,
            $guidance['intervention_category'],
            $reason_for_referral,
            $final_facility,
            $remarks_val,
            $date_to_send_val
        );
        $insert->execute();
        $insert->close();

        header("Location: referral_forms.php?success=sent");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Referral | NutriTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
    <link rel="stylesheet" href="../assets/cdw/referral_forms.css">
    <link rel="stylesheet" href="../assets/cdw/cdw-topbar-notification.css">
</head>
<body class="<?php echo themeClass(); ?>">

<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-header">
        <a href="referral_forms.php" class="back-link">← Back to Referral Forms</a>
        <h1 class="page-title">Generate Referral Form</h1>
        <div class="page-subtitle">
            This form is generated directly from the official Endline Intervention Guidance issued by CSWD.
            No new nutritional assessment is performed here.
        </div>
    </div>

    <div class="content-card">

        <?php if (!empty($error)) { ?>
            <div class="error-message"><?php echo h($error); ?></div>
        <?php } ?>

        <div class="meta-box">
            <div class="meta-grid">
                <div class="meta-item"><strong>Child Name:</strong> <?php echo h($child_name); ?></div>
                <div class="meta-item"><strong>Guardian:</strong> <?php echo h($guardian_name); ?></div>
                <div class="meta-item"><strong>Assessment:</strong> Endline</div>
                <div class="meta-item"><strong>Final Category:</strong> <?php echo h($guidance['intervention_category']); ?></div>
                <div class="meta-item"><strong>Guidance Sent to Guardian:</strong> <?php echo h(format_datetime_display($guidance['sent_at'])); ?></div>
            </div>
        </div>

        <div class="program-box">
            <div class="program-title">Official Intervention Guidance (from CSWD)</div>
            <div class="program-text"><?php echo nl2br(h($guidance['guidance_text'])); ?></div>
        </div>

        <form method="POST">
            <input type="hidden" name="guidance_id" value="<?php echo (int) $guidance_id; ?>">

            <div class="form-group">
                <label for="reason_for_referral">Reason for Referral <span class="required">*</span></label>
                <textarea
                    id="reason_for_referral"
                    name="reason_for_referral"
                    rows="3"
                    required
                ><?php echo h($_POST['reason_for_referral'] ?? $default_reason); ?></textarea>
            </div>

            <div class="form-group">
                <label for="recommended_facility">Recommended Facility <span class="required">*</span></label>
                <select
                    id="recommended_facility"
                    name="recommended_facility"
                    onchange="toggleOtherFacility(this.value)"
                    required
                >
                    <option value="">— Select Facility —</option>
                    <?php foreach ($facility_options as $option) { ?>
                        <option value="<?php echo h($option); ?>" <?php echo ($recommended_facility_choice === $option) ? 'selected' : ''; ?>>
                            <?php echo h($option); ?>
                        </option>
                    <?php } ?>
                    <option value="Others" <?php echo ($recommended_facility_choice === 'Others') ? 'selected' : ''; ?>>
                        Others
                    </option>
                </select>
            </div>

            <div class="form-group" id="otherFacilityGroup" style="<?php echo ($recommended_facility_choice === 'Others') ? '' : 'display:none;'; ?>">
                <label for="recommended_facility_other">Please specify <span class="required">*</span></label>
                <input
                    type="text"
                    id="recommended_facility_other"
                    name="recommended_facility_other"
                    placeholder="Enter facility name"
                    value="<?php echo h($recommended_facility_other); ?>"
                >
            </div>

            <div class="form-group">
                <label for="date_to_send">Date to Send Referral <span class="required">*</span></label>
                <input
                    type="date"
                    id="date_to_send"
                    name="date_to_send"
                    value="<?php echo h($date_to_send_val); ?>"
                    required
                >
                <div class="field-hint">This is the date the CDW plans to officially send this referral to the guardian.</div>
            </div>

            <div class="form-group">
                <label for="remarks">Remarks (optional)</label>
                <textarea
                    id="remarks"
                    name="remarks"
                    rows="3"
                    placeholder="Additional notes"
                ><?php echo h($remarks_val); ?></textarea>
            </div>

            <div class="top-actions" style="justify-content: flex-start; margin-top: 18px;">
                <button type="submit" name="generate_referral" class="btn btn-submit">
                    Generate &amp; Send Referral to Guardian
                </button>
                <a href="referral_forms.php" class="btn btn-cancel">Cancel</a>
            </div>
        </form>

    </div>
</div>

<script>
function toggleOtherFacility(value) {
    var group = document.getElementById('otherFacilityGroup');
    group.style.display = (value === 'Others') ? 'block' : 'none';
}
</script>
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