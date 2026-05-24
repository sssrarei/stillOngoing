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
$current_page = 'interventions_reminders';

$guardian_user_id = (int) $_SESSION['user_id'];

$child = null;
$latest_record = null;
$official_guidance = null;
$notifications = [];
$error_message = '';

/* --------------------------------------------------------------------------
   GET LINKED CHILD
-------------------------------------------------------------------------- */
$child_sql = "
    SELECT
        children.*,
        cdc.cdc_name,
        cdc.address AS cdc_address
    FROM parent_child_links
    INNER JOIN children ON parent_child_links.child_id = children.child_id
    INNER JOIN cdc ON children.cdc_id = cdc.cdc_id
    WHERE parent_child_links.parent_id = ?
    LIMIT 1
";

$child_stmt = $conn->prepare($child_sql);

if(!$child_stmt){
    die("Prepare error: " . $conn->error);
}

$child_stmt->bind_param("i", $guardian_user_id);
$child_stmt->execute();
$child_result = $child_stmt->get_result();

if($child_result && $child_result->num_rows > 0){
    $child = $child_result->fetch_assoc();
} else {
    $error_message = "No linked child found for this guardian.";
}

$child_stmt->close();

/* --------------------------------------------------------------------------
   GET LATEST ANTHROPOMETRIC RECORD (SUMMARY DISPLAY ONLY)
-------------------------------------------------------------------------- */
if($child){
    $latest_sql = "
        SELECT *
        FROM anthropometric_records
        WHERE child_id = ?
        ORDER BY date_recorded DESC, record_id DESC
        LIMIT 1
    ";

    $latest_stmt = $conn->prepare($latest_sql);

    if($latest_stmt){
        $latest_stmt->bind_param("i", $child['child_id']);
        $latest_stmt->execute();
        $latest_result = $latest_stmt->get_result();
        $latest_record = $latest_result->fetch_assoc();
        $latest_stmt->close();
    }
}

/* --------------------------------------------------------------------------
   GET OFFICIAL INTERVENTION GUIDANCE
-------------------------------------------------------------------------- */
if($child){
    $guidance_sql = "
        SELECT *
        FROM intervention_guidance
        WHERE child_id = ?
          AND is_reviewed = 1
          AND sent_to_guardian = 1
        ORDER BY sent_at DESC, guidance_id DESC
        LIMIT 1
    ";

    $guidance_stmt = $conn->prepare($guidance_sql);

    if($guidance_stmt){
        $child_id = (int)$child['child_id'];
        $guidance_stmt->bind_param("i", $child_id);
        $guidance_stmt->execute();
        $guidance_result = $guidance_stmt->get_result();
        $official_guidance = $guidance_result->fetch_assoc();
        $guidance_stmt->close();
    }
}

/* --------------------------------------------------------------------------
   GET GUARDIAN NOTIFICATIONS / REMINDERS
-------------------------------------------------------------------------- */
$notif_sql = "
    SELECT notification_id, user_id, reminder_id, title, message, deadline, is_read, read_at, created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC, notification_id DESC
";

$notif_stmt = $conn->prepare($notif_sql);

if($notif_stmt){
    $notif_stmt->bind_param("i", $guardian_user_id);
    $notif_stmt->execute();
    $notif_result = $notif_stmt->get_result();

    while($row = $notif_result->fetch_assoc()){
        $notifications[] = $row;
    }

    $notif_stmt->close();
}

/* --------------------------------------------------------------------------
   HELPERS
-------------------------------------------------------------------------- */
function calculateAgeText($birthdate){
    if(empty($birthdate) || $birthdate === '0000-00-00'){
        return 'N/A';
    }

    $birth = new DateTime($birthdate);
    $today = new DateTime();
    $diff = $today->diff($birth);

    return $diff->y . " year(s) old";
}

function calculateAgeMonths($birthdate){
    if(empty($birthdate) || $birthdate === '0000-00-00'){
        return 'N/A';
    }

    $birth = new DateTime($birthdate);
    $today = new DateTime();
    $diff = $today->diff($birth);

    return ($diff->y * 12) + $diff->m;
}

function getDashboardPriorityStatus($record){
    if(!$record){
        return 'No record yet';
    }

    $wflh = isset($record['wflh_status']) ? trim($record['wflh_status']) : '';
    $hfa  = isset($record['hfa_status']) ? trim($record['hfa_status']) : '';
    $wfa  = isset($record['wfa_status']) ? trim($record['wfa_status']) : '';

    $priority_wflh = [
        'Severely Wasted',
        'Moderately Wasted',
        'Wasted',
        'Overweight',
        'Obese'
    ];

    $priority_hfa = [
        'Severely Stunted',
        'Stunted'
    ];

    $priority_wfa = [
        'Severely Underweight',
        'Underweight'
    ];

    if (in_array($wflh, $priority_wflh, true)) {
        return $wflh;
    }

    if (in_array($hfa, $priority_hfa, true)) {
        return $hfa;
    }

    if (in_array($wfa, $priority_wfa, true)) {
        return $wfa;
    }

    if (!empty($wflh) && strtolower($wflh) === 'normal') {
        return 'Normal';
    }

    if (!empty($hfa) && strtolower($hfa) === 'normal') {
        return 'Normal';
    }

    if (!empty($wfa) && strtolower($wfa) === 'normal') {
        return 'Normal';
    }

    if (!empty($wflh)) {
        return $wflh;
    }

    if (!empty($hfa)) {
        return $hfa;
    }

    if (!empty($wfa)) {
        return $wfa;
    }

    return 'No record yet';
}

function getStatusClass($status){
    $status = strtolower(trim($status));

    if($status === 'normal'){
        return 'status-normal';
    }

    if($status === 'no record yet'){
        return 'status-neutral';
    }

    return 'status-alert';
}

function formatDateValue($value){
    if(empty($value) || $value === '0000-00-00'){
        return 'N/A';
    }

    return date("F d, Y", strtotime($value));
}

function formatDateTimeValue($value){
    if(empty($value) || $value === '0000-00-00 00:00:00'){
        return 'N/A';
    }

    return date("F d, Y g:i A", strtotime($value));
}

function formatGuidanceTextAsList($text){
    if(empty($text)){
        return [];
    }

    $text = str_replace("\r\n", "\n", $text);
    $lines = explode("\n", $text);
    $items = [];

    foreach($lines as $line){
        $clean = trim($line);
        $clean = preg_replace('/^[\-\•\●\*\d\.\)\(]+\s*/u', '', $clean);

        if($clean !== ''){
            $items[] = $clean;
        }
    }

    return $items;
}

$child_full_name = $child
    ? trim(($child['first_name'] ?? '') . ' ' . ($child['middle_name'] ?? '') . ' ' . ($child['last_name'] ?? ''))
    : 'N/A';

$birthdate_display = ($child && !empty($child['birthdate']) && $child['birthdate'] !== '0000-00-00')
    ? date("F d, Y", strtotime($child['birthdate']))
    : 'N/A';

$age_text = $child ? calculateAgeText($child['birthdate']) : 'N/A';
$age_months = $child ? calculateAgeMonths($child['birthdate']) : 'N/A';
$sex = ($child && !empty($child['sex'])) ? ucfirst($child['sex']) : 'N/A';
$cdc_display = $child ? trim(($child['cdc_name'] ?? 'N/A') . ' - ' . ($child['cdc_address'] ?? 'N/A')) : 'N/A';
$latest_status = getDashboardPriorityStatus($latest_record);
$latest_status_class = getStatusClass($latest_status);
$latest_assessment_date = ($latest_record && !empty($latest_record['date_recorded']))
    ? date("F d, Y", strtotime($latest_record['date_recorded']))
    : 'N/A';

$guidance_items = $official_guidance ? formatGuidanceTextAsList($official_guidance['guidance_text']) : [];
$guidance_sent_at = $official_guidance ? formatDateTimeValue($official_guidance['sent_at']) : 'N/A';
$guidance_note = $official_guidance && !empty($official_guidance['optional_note'])
    ? $official_guidance['optional_note']
    : '';
$official_original_status = $official_guidance && !empty($official_guidance['original_status'])
    ? $official_guidance['original_status']
    : 'N/A';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interventions Guidance | NutriTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/guardian-style.css">

    <style>
        .ir-shell{
            display:flex;
            flex-direction:column;
            gap:24px;
            max-width:1080px;
            margin:0 auto;
            width:100%;
        }

        .ir-card{
            background:#ffffff;
            border:1px solid #d7dde5;
            border-radius:22px;
            overflow:hidden;
            box-shadow:0 10px 30px rgba(15, 23, 42, 0.05);
        }

        .ir-card-header{
            background:#f5ede5;
            padding:20px 24px;
            border-bottom:1px solid #e7e7e7;
        }

        .ir-card-title{
            font-family:'Poppins', sans-serif;
            font-size:20px;
            font-weight:700;
            color:#c96f00;
        }

        .ir-card-body{
            padding:24px;
        }

        .info-list{
            display:flex;
            flex-direction:column;
        }

        .info-row{
            padding:14px 0;
            border-bottom:1px solid #e5e7eb;
        }

        .info-row:last-child{
            border-bottom:none;
        }

        .info-label{
            display:block;
            font-size:13px;
            color:#7b8794;
            margin-bottom:6px;
        }

        .info-value{
            font-size:15px;
            font-weight:600;
            color:#1f2937;
            line-height:1.6;
            word-break:break-word;
        }

        .status-pill{
            display:inline-flex;
            align-items:center;
            padding:8px 14px;
            border-radius:999px;
            font-size:14px;
            font-weight:700;
            border:1px solid transparent;
        }

        .status-pill.status-normal{
            background:#e8f5e9;
            color:#2e7d32;
            border-color:#c8e6c9;
        }

        .status-pill.status-alert{
            background:#fdeaea;
            color:#c62828;
            border-color:#efb0b0;
        }

        .status-pill.status-neutral{
            background:#f3f4f6;
            color:#666;
            border-color:#d1d5db;
        }

        .guidance-meta{
            display:flex;
            flex-wrap:wrap;
            gap:12px;
            margin-bottom:14px;
        }

        .guidance-badge{
            display:inline-flex;
            align-items:center;
            padding:7px 12px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
        }

        .guidance-badge.category{
            background:#fff7ed;
            color:#c2410c;
            border:1px solid #fdba74;
        }

        .guidance-badge.date{
            background:#eff6ff;
            color:#1d4ed8;
            border:1px solid #93c5fd;
        }

        .guidance-badge.source-status{
            background:#fef2f2;
            color:#b91c1c;
            border:1px solid #fca5a5;
        }

        .guidance-list{
            margin:0;
            padding-left:20px;
            display:flex;
            flex-direction:column;
            gap:10px;
        }

        .guidance-list li{
            color:#334155;
            line-height:1.7;
            font-size:14px;
        }

        .guidance-note{
            margin-top:16px;
            padding:14px 16px;
            border-radius:12px;
            background:#f8fafc;
            border:1px solid #dbe4ee;
            color:#475569;
            font-size:14px;
            line-height:1.7;
            white-space:pre-line;
        }

        .empty-box{
            padding:16px;
            border:1px dashed #d1d5db;
            border-radius:12px;
            background:#f8fafc;
            color:#6b7280;
            font-size:14px;
            line-height:1.6;
        }

        .notif-list{
            display:flex;
            flex-direction:column;
            gap:16px;
        }

        .notif-item{
            border:1px solid #e5e7eb;
            border-radius:14px;
            padding:16px;
            background:#ffffff;
        }

        .notif-top{
            display:flex;
            justify-content:space-between;
            gap:16px;
            align-items:flex-start;
            margin-bottom:8px;
            flex-wrap:wrap;
        }

        .notif-title{
            font-family:'Poppins', sans-serif;
            font-size:16px;
            font-weight:700;
            color:#243041;
        }

        .notif-date{
            font-size:12px;
            color:#6b7280;
            font-weight:600;
        }

        .notif-message{
            font-size:14px;
            line-height:1.7;
            color:#475569;
            margin-bottom:10px;
            white-space:pre-line;
        }

        .notif-meta{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
        }

        .notif-badge{
            display:inline-flex;
            align-items:center;
            padding:6px 10px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
        }

        .notif-badge.deadline{
            background:#fff7ed;
            color:#c2410c;
            border:1px solid #fdba74;
        }

        .notif-badge.read{
            background:#ecfdf5;
            color:#166534;
            border:1px solid #86efac;
        }

        .notif-badge.unread{
            background:#eff6ff;
            color:#1d4ed8;
            border:1px solid #93c5fd;
        }

        body.dark-mode .ir-card{
            background:#111827;
            border-color:#334155;
        }

        body.dark-mode .ir-card-header{
            background:#1e293b;
            border-bottom-color:#334155;
        }

        body.dark-mode .ir-card-title,
        body.dark-mode .info-value,
        body.dark-mode .notif-title{
            color:#f8fafc;
        }

        body.dark-mode .info-label,
        body.dark-mode .notif-date{
            color:#cbd5e1;
        }

        body.dark-mode .info-row{
            border-bottom-color:#334155;
        }

        body.dark-mode .guidance-list li,
        body.dark-mode .notif-message,
        body.dark-mode .guidance-note{
            color:#e2e8f0;
        }

        body.dark-mode .empty-box{
            background:#0f172a;
            border-color:#334155;
            color:#cbd5e1;
        }

        body.dark-mode .notif-item{
            background:#0f172a;
            border-color:#334155;
        }

        body.dark-mode .guidance-note{
            background:#0f172a;
            border-color:#334155;
        }

        @media (max-width: 768px){
            .ir-card-header,
            .ir-card-body{
                padding:18px;
            }
        }
    </style>
</head>
<body class="<?php echo ($theme_mode === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/guardian_topbar.php'; ?>
<?php include '../includes/guardian_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="ir-shell">

        <?php if(!empty($error_message)) { ?>
            <div class="empty-box"><?php echo htmlspecialchars($error_message); ?></div>
        <?php } else { ?>

            <div class="ir-card">
                <div class="ir-card-header">
                    <h2 class="ir-card-title">Child Summary</h2>
                </div>
                <div class="ir-card-body">
                    <div class="info-list">
                        <div class="info-row">
                            <span class="info-label">Child Name</span>
                            <div class="info-value"><?php echo htmlspecialchars($child_full_name); ?></div>
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
                            <div class="info-value"><?php echo htmlspecialchars($age_text); ?></div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Age in Months</span>
                            <div class="info-value">
                                <?php echo ($age_months === 'N/A') ? 'N/A' : htmlspecialchars($age_months) . ' month(s)'; ?>
                            </div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Child Development Center</span>
                            <div class="info-value"><?php echo htmlspecialchars($cdc_display); ?></div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Latest Assessment Date</span>
                            <div class="info-value"><?php echo htmlspecialchars($latest_assessment_date); ?></div>
                        </div>

                        <div class="info-row">
                            <span class="info-label">Latest Nutritional Status</span>
                            <div class="info-value">
                                <span class="status-pill <?php echo $latest_status_class; ?>">
                                    <?php echo htmlspecialchars($latest_status); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="ir-card">
                <div class="ir-card-header">
                    <h2 class="ir-card-title">Intervention Guidance</h2>
                </div>
                <div class="ir-card-body">
                    <?php if($official_guidance) { ?>
                        <div class="guidance-meta">
                            <span class="guidance-badge source-status">
                                Official Status: <?php echo htmlspecialchars($official_original_status); ?>
                            </span>

                            <span class="guidance-badge category">
                                Category: <?php echo htmlspecialchars($official_guidance['intervention_category']); ?>
                            </span>

                            <span class="guidance-badge date">
                                Sent: <?php echo htmlspecialchars($guidance_sent_at); ?>
                            </span>
                        </div>

                        <?php if(!empty($guidance_items)) { ?>
                            <ul class="guidance-list">
                                <?php foreach($guidance_items as $item) { ?>
                                    <li><?php echo htmlspecialchars($item); ?></li>
                                <?php } ?>
                            </ul>
                        <?php } else { ?>
                            <div class="empty-box">
                                No guidance text available.
                            </div>
                        <?php } ?>

                        <?php if(!empty($guidance_note)) { ?>
                            <div class="guidance-note">
                                <?php echo htmlspecialchars($guidance_note); ?>
                            </div>
                        <?php } ?>
                    <?php } else { ?>
                        <div class="empty-box">
                            No intervention guidance has been sent yet.
                        </div>
                    <?php } ?>
                </div>
            </div>

            <div class="ir-card">
    <div class="ir-card-header">
        <h2 class="ir-card-title">Follow-up Reminders</h2>
    </div>
    <div class="ir-card-body">

        <?php
            $has_followup_reminder = false;
            $followup_title = '';
            $followup_message = '';

            if($official_guidance){
                $needs_counseling = isset($official_guidance['needs_counseling']) ? (int)$official_guidance['needs_counseling'] : 0;
                $needs_referral = isset($official_guidance['needs_referral']) ? (int)$official_guidance['needs_referral'] : 0;
                $status_note_lower = strtolower(trim((string)($official_guidance['status_note'] ?? '')));

                if($needs_referral === 1 || strpos($status_note_lower, 'final follow-up reminder') !== false){
                    $has_followup_reminder = true;
                    $followup_title = 'Final Follow-up Reminder';
                    $followup_message = 'Based on the Endline assessment, your child still needs nutritional attention. Please coordinate with the CDW or health personnel for further assessment if needed.';
                }
                elseif($needs_counseling === 1 || strpos($status_note_lower, 'follow-up reminder') !== false){
                    $has_followup_reminder = true;
                    $followup_title = 'Follow-up Reminder';
                    $followup_message = 'Based on the latest monitoring assessment, your child still needs nutritional attention. Please coordinate with the CDW for counseling, nutrition education, and further monitoring.';
                }
            }
        ?>

        <?php if($has_followup_reminder) { ?>
            <div class="notif-list">
                <div class="notif-item">
                    <div class="notif-top">
                        <div class="notif-title">
                            <?php echo htmlspecialchars($followup_title); ?>
                        </div>
                        <div class="notif-date">
                            <?php echo htmlspecialchars($guidance_sent_at); ?>
                        </div>
                    </div>

                    <div class="notif-message">
                        <?php echo htmlspecialchars($followup_message); ?>
                    </div>

                    <div class="notif-meta">
                        <span class="notif-badge deadline">
                            Action Needed: Coordinate with CDW
                        </span>

                        <?php if(isset($official_guidance['needs_referral']) && (int)$official_guidance['needs_referral'] === 1) { ?>
                            <span class="notif-badge unread">
                                Possible Further Assessment
                            </span>
                        <?php } else { ?>
                            <span class="notif-badge unread">
                                Counseling / Nutrition Education
                            </span>
                        <?php } ?>
                    </div>

                    <?php if(!empty($official_guidance['status_note'])) { ?>
                        <div class="guidance-note">
                            <?php echo htmlspecialchars($official_guidance['status_note']); ?>
                        </div>
                    <?php } ?>
                </div>
            </div>
        <?php } else { ?>
            <div class="empty-box">
                No follow-up reminder available at this time.
            </div>
        <?php } ?>

    </div>
</div>

        <?php } ?>
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