<?php
include '../includes/auth.php';
include '../config/database.php';

if ($_SESSION['role_id'] != 3) {
    header("Location: ../login.php");
    exit();
}

if (!isset($_SESSION['theme_mode'])) {
    $_SESSION['theme_mode'] = 'light';
}

$theme_mode = $_SESSION['theme_mode'];
$current_page = 'referral';

$guardian_user_id = (int) $_SESSION['user_id'];

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function build_child_full_name($first, $middle, $last) {
    $parts = array_filter([trim((string)$first), trim((string)$middle), trim((string)$last)]);
    return implode(' ', $parts);
}

function format_date_display($date) {
    if (empty($date) || $date === '0000-00-00') {
        return 'N/A';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date("F d, Y", $timestamp) : 'N/A';
}

function format_datetime_display($datetime) {
    if (empty($datetime) || $datetime === '0000-00-00 00:00:00') {
        return 'N/A';
    }
    $timestamp = strtotime($datetime);
    return $timestamp ? date("F d, Y g:i A", $timestamp) : 'N/A';
}

function status_class($status) {
    $status = strtolower(trim((string)$status));
    return 'status-' . str_replace(' ', '-', $status);
}

$error_message = '';
$child = null;

/* --------------------------------------------------------------------------
   GET LINKED CHILD (same pattern used in interventions_reminders.php)
-------------------------------------------------------------------------- */
$child_sql = "
    SELECT children.*
    FROM parent_child_links
    INNER JOIN children ON parent_child_links.child_id = children.child_id
    WHERE parent_child_links.parent_id = ?
    LIMIT 1
";
$child_stmt = $conn->prepare($child_sql);
$child_stmt->bind_param("i", $guardian_user_id);
$child_stmt->execute();
$child_result = $child_stmt->get_result();

if ($child_result && $child_result->num_rows > 0) {
    $child = $child_result->fetch_assoc();
} else {
    $error_message = "No linked child found for this guardian.";
}
$child_stmt->close();

$child_id = $child ? (int) $child['child_id'] : 0;
$child_full_name = $child ? build_child_full_name($child['first_name'], $child['middle_name'], $child['last_name']) : 'N/A';

/* --------------------------------------------------------------------------
   HANDLE NEW COMMENT (Guardian -> CDW), optionally with an image attachment
-------------------------------------------------------------------------- */
if ($child_id && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    $comment_referral_id = (int) ($_POST['referral_id'] ?? 0);
    $comment_message = trim($_POST['message'] ?? '');
    $comment_error = '';
    $attachment_db_path = null;

    // --- Handle optional image attachment ---
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['attachment'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $comment_error = 'Nagka-problema sa pag-upload ng image. Subukan ulit.';
        } else {
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $max_size = 5 * 1024 * 1024; // 5MB
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if (!in_array($ext, $allowed_ext, true) || !in_array($mime, $allowed_mimes, true)) {
                $comment_error = 'Image files lang ang pwede (JPG, PNG, GIF, WEBP).';
            } elseif ($file['size'] > $max_size) {
                $comment_error = 'Masyadong malaki ang image (max 5MB).';
            } else {
                $upload_dir = '../uploads/referral_comments/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $unique_name = 'refcom_' . uniqid() . '_' . time() . '.' . $ext;
                $destination = $upload_dir . $unique_name;

                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    $attachment_db_path = 'uploads/referral_comments/' . $unique_name;
                } else {
                    $comment_error = 'Hindi na-save ang image. Subukan ulit.';
                }
            }
        }
    }

    // Require at least a message OR an attachment (not necessarily both)
    if ($comment_error === '' && $comment_referral_id && ($comment_message !== '' || $attachment_db_path !== null)) {
        // Verify this referral actually belongs to this guardian's linked child
        $verify_comment = $conn->prepare("SELECT referral_id FROM referrals WHERE referral_id = ? AND child_id = ? LIMIT 1");
        $verify_comment->bind_param("ii", $comment_referral_id, $child_id);
        $verify_comment->execute();
        $valid_referral = $verify_comment->get_result()->fetch_assoc();
        $verify_comment->close();

        if ($valid_referral) {
            $insert_comment = $conn->prepare("
                INSERT INTO referral_comments (referral_id, sender_user_id, sender_role, message, attachment_path)
                VALUES (?, ?, 'Guardian', ?, ?)
            ");
            $insert_comment->bind_param("iiss", $comment_referral_id, $guardian_user_id, $comment_message, $attachment_db_path);
            $insert_comment->execute();
            $insert_comment->close();
        }
    }

    $redirect_url = 'referral.php';
    if ($comment_error !== '') {
        $redirect_url .= '?comment_error=' . urlencode($comment_error);
    }
    header("Location: $redirect_url");
    exit();
}

/* --------------------------------------------------------------------------
   HANDLE STATUS UPDATE (Guardian actions only: In Progress, Completed)
   Guardian may only move a referral FORWARD in its lifecycle, never back.
-------------------------------------------------------------------------- */
if ($child_id && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $referral_id = (int) ($_POST['referral_id'] ?? 0);
    $new_status = $_POST['update_status'];

    $allowed_transitions = [
        'Viewed' => 'In Progress',
        'In Progress' => 'Completed',
    ];

    if ($referral_id && in_array($new_status, ['In Progress', 'Completed'], true)) {
        // Verify this referral belongs to this guardian's linked child, and the transition is valid
        $verify = $conn->prepare("SELECT status FROM referrals WHERE referral_id = ? AND child_id = ? LIMIT 1");
        $verify->bind_param("ii", $referral_id, $child_id);
        $verify->execute();
        $current = $verify->get_result()->fetch_assoc();
        $verify->close();

        if ($current && isset($allowed_transitions[$current['status']]) && $allowed_transitions[$current['status']] === $new_status) {
            $timestamp_column = ($new_status === 'In Progress') ? 'in_progress_at' : 'completed_at';

            $update = $conn->prepare("
                UPDATE referrals
                SET status = ?, $timestamp_column = NOW()
                WHERE referral_id = ? AND child_id = ?
            ");
            $update->bind_param("sii", $new_status, $referral_id, $child_id);
            $update->execute();
            $update->close();
        }
    }

    header("Location: referral.php");
    exit();
}

/* --------------------------------------------------------------------------
   FETCH REFERRALS FOR THIS CHILD
   Only referrals that have already been officially SENT are visible here
   (status != 'Pending'). A Pending referral is still internal to CDW/CSWD.
-------------------------------------------------------------------------- */
$referrals = [];

if ($child_id) {
    $ref_sql = "
        SELECT
            r.referral_id,
            r.final_category,
            r.reason_for_referral,
            r.recommended_facility,
            r.remarks,
            r.date_to_send,
            r.status,
            r.sent_at,
            r.viewed_at,
            r.in_progress_at,
            r.completed_at,
            r.created_at,
            ig.guidance_text
        FROM referrals r
        INNER JOIN intervention_guidance ig ON ig.guidance_id = r.guidance_id
        WHERE r.child_id = ?
          AND r.status != 'Pending'
        ORDER BY r.created_at DESC
    ";
    $ref_stmt = $conn->prepare($ref_sql);
    $ref_stmt->bind_param("i", $child_id);
    $ref_stmt->execute();
    $ref_result = $ref_stmt->get_result();

    while ($row = $ref_result->fetch_assoc()) {
        $referrals[] = $row;
    }
    $ref_stmt->close();

    /*
    | Mark the most recently sent referral as "Viewed" the first time the
    | guardian opens this page (Pending -> Sent -> Viewed -> In Progress -> Completed)
    */
    foreach ($referrals as &$ref) {
        if ($ref['status'] === 'Sent') {
            $mark_viewed = $conn->prepare("
                UPDATE referrals SET status = 'Viewed', viewed_at = NOW()
                WHERE referral_id = ? AND status = 'Sent'
            ");
            $mark_viewed->bind_param("i", $ref['referral_id']);
            $mark_viewed->execute();
            $mark_viewed->close();

            $ref['status'] = 'Viewed';
            $ref['viewed_at'] = date('Y-m-d H:i:s');
        }
    }
    unset($ref);

    /*
    | Load the comment thread for each referral (Guardian <-> CDW messages)
    */
    foreach ($referrals as &$ref) {
        $comments_stmt = $conn->prepare("
            SELECT rc.comment_id, rc.sender_role, rc.message, rc.attachment_path, rc.created_at, u.first_name, u.last_name
            FROM referral_comments rc
            LEFT JOIN users u ON u.user_id = rc.sender_user_id
            WHERE rc.referral_id = ?
            ORDER BY rc.created_at ASC
        ");
        $comments_stmt->bind_param("i", $ref['referral_id']);
        $comments_stmt->execute();
        $ref['comments'] = $comments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $comments_stmt->close();
    }
    unset($ref);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral Form | NutriTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/guardian-style.css">

    <style>
    .ir-shell{
        display:flex;
        flex-direction:column;
        gap:22px;
        max-width:920px;
        margin:0 auto;
        width:100%;
    }

    .ir-card{
        background:rgba(255,255,255,0.98);
        border:1px solid #e5e7eb;
        border-radius:24px;
        overflow:hidden;
        box-shadow:0 14px 34px rgba(15, 23, 42, 0.08);
    }

    .ir-card-header{
        background:linear-gradient(135deg, #fff4e6 0%, #f8eadb 100%);
        padding:22px 28px;
        border-bottom:1px solid #f0dfcd;
        display:flex;
        justify-content:space-between;
        align-items:center;
        flex-wrap:wrap;
        gap:12px;
    }

    .ir-card-title{
        font-family:'Poppins', sans-serif;
        font-size:22px;
        font-weight:800;
        color:#c96f00;
        line-height:1.25;
    }

    .ir-card-body{
        padding:26px 28px;
    }

    .empty-box{
        padding:24px;
        border:1px dashed #d1d5db;
        border-radius:18px;
        background:#f8fafc;
        color:#6b7280;
        font-size:15px;
        line-height:1.6;
    }

    .status-pill{
        display:inline-flex;
        align-items:center;
        justify-content:center;
        padding:9px 16px;
        border-radius:999px;
        font-size:13px;
        font-weight:800;
        border:1px solid transparent;
        white-space:nowrap;
    }

    .status-sent{ background:#eff6ff; color:#1d4ed8; border-color:#93c5fd; }
    .status-viewed{ background:#f3e8ff; color:#7e22ce; border-color:#d8b4fe; }
    .status-in-progress{ background:#fff7ed; color:#c2410c; border-color:#fdba74; }
    .status-completed{ background:#ecfdf5; color:#166534; border-color:#86efac; }

    .info-list{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:14px 18px;
        margin-bottom:20px;
    }

    .info-row{
        padding:16px 18px;
        border:1px solid #edf0f4;
        border-radius:16px;
        background:#fffdf9;
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
        word-break:break-word;
        white-space:pre-line;
    }

    .guidance-list{
        margin:0 0 20px;
        padding:20px 24px 20px 42px;
        display:flex;
        flex-direction:column;
        gap:12px;
        background:#fffdf9;
        border:1px solid #edf0f4;
        border-radius:18px;
    }

    .guidance-list li{
        color:#334155;
        line-height:1.7;
        font-size:15px;
    }

    .referral-actions{
        display:flex;
        gap:12px;
        flex-wrap:wrap;
        margin-top:10px;
    }

    .btn-action{
        border:none;
        border-radius:10px;
        padding:12px 18px;
        font-size:14px;
        font-weight:700;
        cursor:pointer;
        font-family:'Inter', sans-serif;
    }

    .btn-inprogress{
        background:#c2410c;
        color:#fff;
    }

    .btn-completed{
        background:#166534;
        color:#fff;
    }

    .referral-timeline{
        margin-top:18px;
        padding-top:16px;
        border-top:1px solid #edf0f4;
        font-size:13px;
        color:#7b8794;
        display:flex;
        flex-wrap:wrap;
        gap:14px;
    }

    .comment-thread{
        margin-top:18px;
        padding-top:18px;
        border-top:1px solid #edf0f4;
    }

    .comment-list{
        display:flex;
        flex-direction:column;
        gap:10px;
        margin-bottom:14px;
        max-height:320px;
        overflow-y:auto;
    }

    .comment-bubble{
        max-width:80%;
        padding:10px 14px;
        border-radius:14px;
        font-size:14px;
    }

    .comment-other{
        align-self:flex-start;
        background:#f1f5f9;
        border:1px solid #e2e8f0;
        border-bottom-left-radius:4px;
    }

    .comment-mine{
        align-self:flex-end;
        background:#fff4e6;
        border:1px solid #f0dfcd;
        border-bottom-right-radius:4px;
    }

    .comment-meta{
        display:flex;
        justify-content:space-between;
        gap:10px;
        font-size:11px;
        font-weight:700;
        color:#94a3b8;
        margin-bottom:4px;
    }

    .comment-message{
        color:#1f2937;
        line-height:1.5;
        word-break:break-word;
    }

    .comment-empty{
        font-size:13px;
        color:#94a3b8;
        margin-bottom:14px;
    }

    .comment-form{
        display:flex;
        gap:10px;
        align-items:flex-end;
        flex-wrap:wrap;
    }

    .comment-form textarea{
        flex:1;
        min-width:200px;
        resize:vertical;
        border:1px solid #dbe2ea;
        border-radius:12px;
        padding:10px 14px;
        font-family:'Inter', sans-serif;
        font-size:14px;
        color:#1f2937;
    }

    .comment-attachment{
        display:block;
        max-width:220px;
        max-height:220px;
        border-radius:12px;
        margin-top:6px;
        border:1px solid rgba(0,0,0,0.08);
        object-fit:cover;
    }

    .btn-attach{
        display:flex;
        align-items:center;
        justify-content:center;
        width:44px;
        height:44px;
        border-radius:12px;
        border:1px solid #dbe2ea;
        background:#f8fafc;
        cursor:pointer;
        font-size:18px;
        flex-shrink:0;
    }

    .btn-attach:hover{
        background:#f1f5f9;
    }

    body.dark-mode .btn-attach{ background:#0f172a; border-color:#334155; }
    body.dark-mode .btn-attach:hover{ background:#1e293b; }

    .btn-comment{
        background:#1d4ed8;
        color:#fff;
    }

    body.dark-mode .ir-card{ background:#111827; border-color:#334155; box-shadow:none; }
    body.dark-mode .ir-card-header{ background:#1e293b; border-bottom-color:#334155; }
    body.dark-mode .ir-card-title,
    body.dark-mode .info-value{ color:#f8fafc; }
    body.dark-mode .info-row,
    body.dark-mode .guidance-list{ background:#0f172a; border-color:#334155; }
    body.dark-mode .info-label,
    body.dark-mode .referral-timeline{ color:#cbd5e1; }
    body.dark-mode .guidance-list li{ color:#e2e8f0; }
    body.dark-mode .empty-box{ background:#0f172a; border-color:#334155; color:#cbd5e1; }
    body.dark-mode .referral-timeline{ border-top-color:#334155; }
    body.dark-mode .comment-thread{ border-top-color:#334155; }
    body.dark-mode .comment-other{ background:#0f172a; border-color:#334155; }
    body.dark-mode .comment-mine{ background:#1e293b; border-color:#334155; }
    body.dark-mode .comment-message{ color:#f1f5f9; }
    body.dark-mode .comment-form textarea{ background:#0f172a; border-color:#334155; color:#f1f5f9; }

    @media (max-width: 900px){
        .ir-shell{ max-width:100%; }
        .info-list{ grid-template-columns:1fr; }
        .ir-card-header, .ir-card-body{ padding:20px; }
    }
    </style>
</head>
<body class="<?php echo ($theme_mode === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/guardian_topbar.php'; ?>
<?php include '../includes/guardian_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="ir-shell">

        <?php if (!empty($_GET['comment_error'])) { ?>
            <div class="ir-card">
                <div class="ir-card-body">
                    <div class="empty-box"><?php echo h($_GET['comment_error']); ?></div>
                </div>
            </div>
        <?php } ?>

        <?php if (!empty($error_message)) { ?>
            <div class="ir-card">
                <div class="ir-card-body">
                    <div class="empty-box"><?php echo h($error_message); ?></div>
                </div>
            </div>
        <?php } elseif (empty($referrals)) { ?>
            <div class="ir-card">
                <div class="ir-card-header">
                    <h2 class="ir-card-title">Referral Form</h2>
                </div>
                <div class="ir-card-body">
                    <div class="empty-box">
                        No official referral has been sent for <?php echo h($child_full_name); ?> yet.
                    </div>
                </div>
            </div>
        <?php } else { ?>

            <?php foreach ($referrals as $ref) {
                $guidance_lines = array_filter(array_map('trim', explode("\n", str_replace("\r\n", "\n", $ref['guidance_text']))));
            ?>
                <div class="ir-card">
                    <div class="ir-card-header">
                        <h2 class="ir-card-title">Referral Form</h2>
                        <span class="status-pill <?php echo status_class($ref['status']); ?>">
                            <?php echo h($ref['status']); ?>
                        </span>
                    </div>
                    <div class="ir-card-body">

                        <div class="info-list">
                            <div class="info-row">
                                <span class="info-label">Child Name</span>
                                <div class="info-value"><?php echo h($child_full_name); ?></div>
                            </div>

                            <div class="info-row">
                                <span class="info-label">Assessment</span>
                                <div class="info-value">Endline</div>
                            </div>

                            <div class="info-row">
                                <span class="info-label">Final Category</span>
                                <div class="info-value"><?php echo h($ref['final_category']); ?></div>
                            </div>

                            <div class="info-row">
                                <span class="info-label">Recommended Facility</span>
                                <div class="info-value"><?php echo h($ref['recommended_facility']); ?></div>
                            </div>

                            <div class="info-row full">
                                <span class="info-label">Reason for Referral</span>
                                <div class="info-value"><?php echo h($ref['reason_for_referral']); ?></div>
                            </div>

                            <?php if (!empty($ref['remarks'])) { ?>
                                <div class="info-row full">
                                    <span class="info-label">Remarks</span>
                                    <div class="info-value"><?php echo h($ref['remarks']); ?></div>
                                </div>
                            <?php } ?>
                        </div>

                        <?php if (!empty($guidance_lines)) { ?>
                            <span class="info-label" style="margin-bottom:10px; display:block;">Official Guidance (from CSWD)</span>
                            <ul class="guidance-list">
                                <?php foreach ($guidance_lines as $line) { ?>
                                    <li><?php echo h($line); ?></li>
                                <?php } ?>
                            </ul>
                        <?php } ?>

                        <?php if ($ref['status'] === 'Viewed' || $ref['status'] === 'In Progress') { ?>
                            <form method="POST" class="referral-actions">
                                <input type="hidden" name="referral_id" value="<?php echo (int) $ref['referral_id']; ?>">

                                <?php if ($ref['status'] === 'Viewed') { ?>
                                    <button type="submit" name="update_status" value="In Progress" class="btn-action btn-inprogress">
                                        Mark as In Progress
                                    </button>
                                <?php } elseif ($ref['status'] === 'In Progress') { ?>
                                    <button type="submit" name="update_status" value="Completed" class="btn-action btn-completed">
                                        Mark as Completed
                                    </button>
                                <?php } ?>
                            </form>
                        <?php } ?>

                        <div class="referral-timeline">
                            <span>Sent: <?php echo h(format_datetime_display($ref['sent_at'])); ?></span>
                            <?php if (!empty($ref['viewed_at'])) { ?>
                                <span>Viewed: <?php echo h(format_datetime_display($ref['viewed_at'])); ?></span>
                            <?php } ?>
                            <?php if (!empty($ref['in_progress_at'])) { ?>
                                <span>In Progress since: <?php echo h(format_datetime_display($ref['in_progress_at'])); ?></span>
                            <?php } ?>
                            <?php if (!empty($ref['completed_at'])) { ?>
                                <span>Completed: <?php echo h(format_datetime_display($ref['completed_at'])); ?></span>
                            <?php } ?>
                        </div>

                        <div class="comment-thread">
                            <span class="info-label" style="display:block; margin-bottom:10px;">Messages with CDW</span>

                            <?php if (!empty($ref['comments'])) { ?>
                                <div class="comment-list">
                                    <?php foreach ($ref['comments'] as $c) {
                                        $is_mine = ($c['sender_role'] === 'Guardian');
                                        $sender_full_name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                                        $sender_label = $is_mine ? 'You' : ('CDW' . ($sender_full_name !== '' ? ' — ' . $sender_full_name : ''));
                                    ?>
                                        <div class="comment-bubble <?php echo $is_mine ? 'comment-mine' : 'comment-other'; ?>">
                                            <div class="comment-meta">
                                                <span><?php echo h($sender_label); ?></span>
                                                <span><?php echo h(format_datetime_display($c['created_at'])); ?></span>
                                            </div>
                                            <?php if (!empty($c['message'])) { ?>
                                                <div class="comment-message"><?php echo nl2br(h($c['message'])); ?></div>
                                            <?php } ?>
                                            <?php if (!empty($c['attachment_path'])) { ?>
                                                <a href="../<?php echo h($c['attachment_path']); ?>" target="_blank" rel="noopener">
                                                    <img src="../<?php echo h($c['attachment_path']); ?>" alt="Attached image" class="comment-attachment">
                                                </a>
                                            <?php } ?>
                                        </div>
                                    <?php } ?>
                                </div>
                            <?php } else { ?>
                                <div class="comment-empty">There are no messages yet. You can ask CDW about this referral here.</div>
                            <?php } ?>

                            <form method="POST" class="comment-form" enctype="multipart/form-data">
                                <input type="hidden" name="referral_id" value="<?php echo (int) $ref['referral_id']; ?>">
                                <textarea name="message" rows="2" placeholder="Magsulat ng mensahe para sa CDW..."></textarea>
                                <label class="btn-attach" title="Mag-attach ng image">
                                    <span class="attach-icon">📷</span>
                                    <input type="file" name="attachment" accept="image/*" style="display:none;" onchange="this.previousElementSibling.textContent = this.files.length ? '✅' : '📷';">
                                </label>
                                <button type="submit" name="add_comment" class="btn-action btn-comment">Send</button>
                            </form>
                        </div>

                    </div>
                </div>
            <?php } ?>

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