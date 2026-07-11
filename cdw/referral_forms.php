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
$cdc_name = isset($_SESSION['active_cdc_name']) ? trim($_SESSION['active_cdc_name']) : 'N/A';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function build_child_full_name($first, $middle, $last) {
    $parts = array_filter([trim((string)$first), trim((string)$middle), trim((string)$last)]);
    return implode(' ', $parts);
}

/*
|--------------------------------------------------------------------------
| HANDLE: SEND REFERRAL TO GUARDIAN (Pending -> Sent)
|
| This is the only allowed status transition on the CDW side.
| A referral only becomes visible to the guardian (in guardian/referral.php
| and as a clickable "Possible Further Assessment" link in
| guardian/interventions_reminders.php) once its status is no longer
| 'Pending'.
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_referral'])) {
    $send_referral_id = (int) ($_POST['referral_id'] ?? 0);

    if ($send_referral_id) {
        // Only allow sending a referral that belongs to this CDW's active CDC
        // and is still in 'Pending' status.
        $verify_sql = "
            SELECT r.referral_id
            FROM referrals r
            INNER JOIN children c ON c.child_id = r.child_id
            WHERE r.referral_id = ?
              AND c.cdc_id = ?
              AND r.status = 'Pending'
            LIMIT 1
        ";
        $verify_stmt = $conn->prepare($verify_sql);
        $verify_stmt->bind_param("ii", $send_referral_id, $cdc_id);
        $verify_stmt->execute();
        $verify_row = $verify_stmt->get_result()->fetch_assoc();
        $verify_stmt->close();

        if ($verify_row) {
            $send_update = $conn->prepare("
                UPDATE referrals
                SET status = 'Sent', sent_at = NOW()
                WHERE referral_id = ? AND status = 'Pending'
            ");
            $send_update->bind_param("i", $send_referral_id);
            $send_update->execute();
            $send_update->close();

            header("Location: referral_forms.php?success=sent");
            exit();
        }
    }

    header("Location: referral_forms.php?error=send_failed");
    exit();
}

/*
|--------------------------------------------------------------------------
| VERY IMPORTANT RULE (per official workflow):
| Referral records are sourced ONLY from intervention_guidance.
| We NEVER query submitted_reports or anthropometric_records here.
|
| A record is eligible for referral only when CSWD has already:
|   - reviewed it (sent_to_guardian = 1)
|   - flagged it as needing referral (needs_referral = 1)
|
| Note: needs_referral is only ever set to 1 by the system for
| Endline-based Final Follow-up Reminder cases (see intervention_helper.php),
| so this condition already guarantees assessment_type = Endline.
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
        r.referral_id,
        r.status AS referral_status,
        r.sent_at AS referral_sent_at
    FROM intervention_guidance ig
    INNER JOIN children c ON c.child_id = ig.child_id
    LEFT JOIN referrals r ON r.guidance_id = ig.guidance_id
    WHERE ig.needs_referral = 1
      AND ig.sent_to_guardian = 1
      AND c.cdc_id = ?
    ORDER BY ig.sent_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $cdc_id);
$stmt->execute();
$result = $stmt->get_result();

$referrals_list = [];
$total_records = 0;
$not_generated_count = 0;
$generated_count = 0;

while ($row = $result->fetch_assoc()) {
    $total_records++;

    if (empty($row['referral_id'])) {
        $not_generated_count++;
    } else {
        $generated_count++;
    }

    $referrals_list[] = $row;
}

$stmt->close();

$success_message = "";
$error_message = "";

if (isset($_GET['success']) && $_GET['success'] === 'generated') {
    $success_message = "Referral generated successfully. You may now send it to the guardian.";
}

if (isset($_GET['success']) && $_GET['success'] === 'sent') {
    $success_message = "Referral sent to the guardian successfully. The guardian can now view it.";
}

if (isset($_GET['error']) && $_GET['error'] === 'already_generated') {
    $error_message = "A referral has already been generated for that record.";
}

if (isset($_GET['error']) && $_GET['error'] === 'send_failed') {
    $error_message = "Unable to send that referral. It may have already been sent.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral Forms | NutriTrack</title>
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
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        <h1 class="page-title">Referral Forms</h1>
        <div class="page-subtitle">
            Official Endline Intervention Guidance records marked by CSWD as needing referral.
        </div>
    </div>

    <div class="content-card">

        <?php if (!empty($success_message)) { ?>
            <div class="success-message"><?php echo h($success_message); ?></div>
        <?php } ?>

        <?php if (!empty($error_message)) { ?>
            <div class="error-message"><?php echo h($error_message); ?></div>
        <?php } ?>

        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-label">Total Referral-Eligible Records</div>
                <div class="summary-value"><?php echo (int) $total_records; ?></div>
            </div>

            <div class="summary-card">
                <div class="summary-label">Not Yet Generated</div>
                <div class="summary-value"><?php echo (int) $not_generated_count; ?></div>
            </div>

            <div class="summary-card">
                <div class="summary-label">Generated</div>
                <div class="summary-value"><?php echo (int) $generated_count; ?></div>
            </div>
        </div>

        <?php if (!empty($referrals_list)) { ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Child Name</th>
                            <th>Final Category</th>
                            <th>Guidance Sent Date</th>
                            <th>Referral Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($referrals_list as $row) {
                            $child_name = build_child_full_name($row['first_name'], $row['middle_name'], $row['last_name']);
                            $has_referral = !empty($row['referral_id']);
                            $status_label = $has_referral ? $row['referral_status'] : 'Not Yet Generated';
                            $status_class = $has_referral ? 'status-chip status-' . strtolower(str_replace(' ', '-', $row['referral_status'])) : 'status-chip status-not-generated';
                        ?>
                            <tr>
                                <td><?php echo h($child_name); ?></td>
                                <td><span class="status-chip"><?php echo h($row['intervention_category']); ?></span></td>
                                <td><?php echo h(format_datetime_display($row['sent_at'])); ?></td>
                                <td><span class="<?php echo h($status_class); ?>"><?php echo h($status_label); ?></span></td>
                                <td>
                                    <?php if (!$has_referral) { ?>
                                        <a href="referral_generate.php?guidance_id=<?php echo (int) $row['guidance_id']; ?>" class="btn btn-submit">
                                            Send Referral
                                        </a>
                                    <?php } elseif ($row['referral_status'] === 'Pending') { ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="referral_id" value="<?php echo (int) $row['referral_id']; ?>">
                                            <button type="submit" name="send_referral" class="btn btn-submit">
                                                Send to Guardian
                                            </button>
                                        </form>
                                    <?php } else { ?>
                                        <a href="referral_feedback.php?referral_id=<?php echo (int) $row['referral_id']; ?>" class="btn btn-submit">
                                            View &amp; Reply
                                        </a>
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } else { ?>
            <p class="no-data">No official referral-eligible records found for this CDC yet.</p>
        <?php } ?>

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