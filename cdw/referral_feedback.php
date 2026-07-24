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
    return $timestamp ? date("F d, Y g:i A", $timestamp) : 'N/A';
}

function status_class($status) {
    $status = strtolower(trim((string)$status));
    return 'status-chip status-' . str_replace(' ', '-', $status);
}

$view_referral_id = isset($_GET['referral_id']) ? (int) $_GET['referral_id'] : 0;

/*
|--------------------------------------------------------------------------
| HANDLE: CDW REPLY (comment, optionally with image attachment)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_reply'])) {
    $reply_referral_id = (int) ($_POST['referral_id'] ?? 0);
    $reply_message = trim($_POST['message'] ?? '');
    $reply_error = '';
    $attachment_db_path = null;

    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['attachment'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $reply_error = 'Nagka-problema sa pag-upload ng image. Subukan ulit.';
        } else {
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $max_size = 5 * 1024 * 1024;
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

            if (!in_array($ext, $allowed_ext, true) || !in_array($mime, $allowed_mimes, true)) {
                $reply_error = 'Image files lang ang pwede (JPG, PNG, GIF, WEBP).';
            } elseif ($file['size'] > $max_size) {
                $reply_error = 'Masyadong malaki ang image (max 5MB).';
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
                    $reply_error = 'Hindi na-save ang image. Subukan ulit.';
                }
            }
        }
    }

    if ($reply_error === '' && $reply_referral_id && ($reply_message !== '' || $attachment_db_path !== null)) {
        // Verify this referral belongs to a child under this CDW's active CDC
        $verify = $conn->prepare("
            SELECT r.referral_id
            FROM referrals r
            INNER JOIN children c ON c.child_id = r.child_id
            WHERE r.referral_id = ? AND c.cdc_id = ?
            LIMIT 1
        ");
        $verify->bind_param("ii", $reply_referral_id, $cdc_id);
        $verify->execute();
        $valid_referral = $verify->get_result()->fetch_assoc();
        $verify->close();

        if ($valid_referral) {
            $insert_comment = $conn->prepare("
                INSERT INTO referral_comments (referral_id, sender_user_id, sender_role, message, attachment_path)
                VALUES (?, ?, 'CDW', ?, ?)
            ");
            $insert_comment->bind_param("iiss", $reply_referral_id, $user_id, $reply_message, $attachment_db_path);
            $insert_comment->execute();
            $insert_comment->close();
        }
    }

    $redirect_url = 'referral_feedback.php?referral_id=' . $reply_referral_id;
    if ($reply_error !== '') {
        $redirect_url .= '&reply_error=' . urlencode($reply_error);
    }
    header("Location: $redirect_url");
    exit();
}

/*
|--------------------------------------------------------------------------
| DETAIL VIEW: a single referral + its full comment thread
|--------------------------------------------------------------------------
*/
$detail = null;
$comments = [];

if ($view_referral_id) {
    $detail_sql = "
        SELECT
            r.referral_id,
            r.final_category,
            r.reason_for_referral,
            r.recommended_facility,
            r.remarks,
            r.status,
            r.sent_at,
            r.viewed_at,
            r.in_progress_at,
            r.completed_at,
            c.first_name,
            c.middle_name,
            c.last_name,
            ig.guidance_text
        FROM referrals r
        INNER JOIN children c ON c.child_id = r.child_id
        INNER JOIN intervention_guidance ig ON ig.guidance_id = r.guidance_id
        WHERE r.referral_id = ? AND c.cdc_id = ?
        LIMIT 1
    ";
    $detail_stmt = $conn->prepare($detail_sql);
    $detail_stmt->bind_param("ii", $view_referral_id, $cdc_id);
    $detail_stmt->execute();
    $detail = $detail_stmt->get_result()->fetch_assoc();
    $detail_stmt->close();

    if ($detail) {
        $comments_stmt = $conn->prepare("
            SELECT rc.comment_id, rc.sender_role, rc.message, rc.attachment_path, rc.created_at, u.first_name, u.last_name
            FROM referral_comments rc
            LEFT JOIN users u ON u.user_id = rc.sender_user_id
            WHERE rc.referral_id = ?
            ORDER BY rc.created_at ASC
        ");
        $comments_stmt->bind_param("i", $view_referral_id);
        $comments_stmt->execute();
        $comments = $comments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $comments_stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| LIST VIEW: every sent referral for this CDC, with last-message preview
|--------------------------------------------------------------------------
*/
$referrals_list = [];

if (!$view_referral_id) {
    $list_sql = "
        SELECT
            r.referral_id,
            r.status,
            r.sent_at,
            c.first_name,
            c.middle_name,
            c.last_name,
            (SELECT COUNT(*) FROM referral_comments rc WHERE rc.referral_id = r.referral_id) AS comment_count,
            (SELECT rc2.message FROM referral_comments rc2 WHERE rc2.referral_id = r.referral_id ORDER BY rc2.created_at DESC LIMIT 1) AS last_message,
            (SELECT rc3.sender_role FROM referral_comments rc3 WHERE rc3.referral_id = r.referral_id ORDER BY rc3.created_at DESC LIMIT 1) AS last_sender,
            (SELECT rc4.created_at FROM referral_comments rc4 WHERE rc4.referral_id = r.referral_id ORDER BY rc4.created_at DESC LIMIT 1) AS last_message_at
        FROM referrals r
        INNER JOIN children c ON c.child_id = r.child_id
        WHERE r.status != 'Pending'
          AND c.cdc_id = ?
        ORDER BY COALESCE(
            (SELECT rc5.created_at FROM referral_comments rc5 WHERE rc5.referral_id = r.referral_id ORDER BY rc5.created_at DESC LIMIT 1),
            r.sent_at
        ) DESC
    ";
    $list_stmt = $conn->prepare($list_sql);
    $list_stmt->bind_param("i", $cdc_id);
    $list_stmt->execute();
    $list_result = $list_stmt->get_result();

    while ($row = $list_result->fetch_assoc()) {
        $referrals_list[] = $row;
    }
    $list_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral Feedback | NutriTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
    <link rel="stylesheet" href="../assets/cdw/referral_forms.css">
    <link rel="stylesheet" href="../assets/cdw/cdw-topbar-notification.css">

    <style>
    .fb-table-preview{
        font-size:13px;
        color:#6b7280;
        max-width:280px;
        white-space:nowrap;
        overflow:hidden;
        text-overflow:ellipsis;
    }

    .fb-empty-msg{
        color:#b0b7c0;
        font-style:italic;
    }

    .fb-detail-shell{
        max-width:820px;
        margin:0 auto;
        display:flex;
        flex-direction:column;
        gap:20px;
    }

    .fb-meta-grid{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:14px;
        margin-bottom:18px;
    }

    .fb-meta-item{
        font-size:14px;
        color:#334155;
    }

    .fb-meta-item strong{
        display:block;
        font-size:12px;
        color:#94a3b8;
        text-transform:uppercase;
        letter-spacing:0.4px;
        margin-bottom:4px;
    }

    .fb-guidance-box{
        background:#fffdf9;
        border:1px solid #edf0f4;
        border-radius:16px;
        padding:16px 20px;
        margin-bottom:18px;
        white-space:pre-line;
        font-size:14px;
        color:#334155;
        line-height:1.7;
    }

    .comment-list{
        display:flex;
        flex-direction:column;
        gap:10px;
        margin-bottom:16px;
        max-height:420px;
        overflow-y:auto;
        padding-right:4px;
    }

    .comment-bubble{
        max-width:78%;
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

    .comment-attachment{
        display:block;
        max-width:220px;
        max-height:220px;
        border-radius:12px;
        margin-top:6px;
        border:1px solid rgba(0,0,0,0.08);
        object-fit:cover;
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

    .btn-attach:hover{ background:#f1f5f9; }
    </style>
</head>
<body class="<?php echo themeClass(); ?>">

<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">

    <?php if ($view_referral_id) { ?>

        <div class="page-header">
            <a href="referral_feedback.php" class="back-link">← Back to Referral Feedback List</a>
            <h1 class="page-title">Referral Feedback</h1>
            <div class="page-subtitle">View the full message thread and reply to the guardian.</div>
        </div>

        <?php if (!$detail) { ?>
            <div class="content-card">
                <p class="no-data">Referral not found, or it does not belong to your active CDC.</p>
            </div>
        <?php } else {
            $child_name = build_child_full_name($detail['first_name'], $detail['middle_name'], $detail['last_name']);
            $guidance_lines = array_filter(array_map('trim', explode("\n", str_replace("\r\n", "\n", $detail['guidance_text']))));
        ?>
            <div class="fb-detail-shell">
                <div class="content-card">

                    <?php if (!empty($_GET['reply_error'])) { ?>
                        <div class="error-message"><?php echo h($_GET['reply_error']); ?></div>
                    <?php } ?>

                    <div class="page-header" style="padding:0; margin-bottom:14px; border:none;">
                        <h2 class="page-title" style="font-size:20px;">Referral Form</h2>
                        <span class="<?php echo status_class($detail['status']); ?>"><?php echo h($detail['status']); ?></span>
                    </div>

                    <div class="fb-meta-grid">
                        <div class="fb-meta-item"><strong>Child Name</strong><?php echo h($child_name); ?></div>
                        <div class="fb-meta-item"><strong>Final Category</strong><?php echo h($detail['final_category']); ?></div>
                        <div class="fb-meta-item"><strong>Recommended Facility</strong><?php echo h($detail['recommended_facility']); ?></div>
                        <div class="fb-meta-item"><strong>Sent</strong><?php echo h(format_datetime_display($detail['sent_at'])); ?></div>
                        <div class="fb-meta-item" style="grid-column:1 / -1;"><strong>Reason for Referral</strong><?php echo h($detail['reason_for_referral']); ?></div>
                        <?php if (!empty($detail['remarks'])) { ?>
                            <div class="fb-meta-item" style="grid-column:1 / -1;"><strong>Remarks</strong><?php echo h($detail['remarks']); ?></div>
                        <?php } ?>
                    </div>

                    <?php if (!empty($guidance_lines)) { ?>
                        <div class="fb-guidance-box">
                            <strong style="display:block; margin-bottom:8px; color:#94a3b8; font-size:12px; text-transform:uppercase;">Official Guidance (from CSWD)</strong>
                            <?php echo h(implode("\n", $guidance_lines)); ?>
                        </div>
                    <?php } ?>

                    <div class="referral-timeline" style="display:flex; gap:14px; flex-wrap:wrap; font-size:13px; color:#7b8794; margin-bottom:18px;">
                        <?php if (!empty($detail['viewed_at'])) { ?><span>Viewed: <?php echo h(format_datetime_display($detail['viewed_at'])); ?></span><?php } ?>
                        <?php if (!empty($detail['in_progress_at'])) { ?><span>In Progress since: <?php echo h(format_datetime_display($detail['in_progress_at'])); ?></span><?php } ?>
                        <?php if (!empty($detail['completed_at'])) { ?><span>Completed: <?php echo h(format_datetime_display($detail['completed_at'])); ?></span><?php } ?>
                    </div>

                    <strong style="display:block; margin-bottom:10px; color:#94a3b8; font-size:12px; text-transform:uppercase;">Messages with Guardian</strong>

                    <?php if (!empty($comments)) { ?>
                        <div class="comment-list">
                            <?php foreach ($comments as $c) {
                                $is_mine = ($c['sender_role'] === 'CDW');
                                $sender_full_name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                                $sender_label = $is_mine ? 'You' : ('Guardian' . ($sender_full_name !== '' ? ' — ' . $sender_full_name : ''));
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
                        <div class="comment-empty">There are no messages on this referral yet.</div>
                    <?php } ?>

                    <form method="POST" class="comment-form" enctype="multipart/form-data">
                        <input type="hidden" name="referral_id" value="<?php echo (int) $detail['referral_id']; ?>">
                        <textarea name="message" rows="2" placeholder="Sumagot sa guardian..."></textarea>
                        <label class="btn-attach" title="Mag-attach ng image">
                            <span class="attach-icon">📷</span>
                            <input type="file" name="attachment" accept="image/*" style="display:none;" onchange="this.previousElementSibling.textContent = this.files.length ? '✅' : '📷';">
                        </label>
                        <button type="submit" name="add_reply" class="btn btn-submit">Reply</button>
                    </form>

                </div>
            </div>
        <?php } ?>

    <?php } else { ?>

        <div class="page-header">
            <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
            <h1 class="page-title">Referral Feedback</h1>
            <div class="page-subtitle">
                Monitor guardian feedback and reply to sent referrals for your assigned CDC.
            </div>
        </div>

        <div class="content-card">
            <?php if (!empty($referrals_list)) { ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Child Name</th>
                                <th>Status</th>
                                <th>Last Message</th>
                                <th>Last Update</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($referrals_list as $row) {
                                $child_name = build_child_full_name($row['first_name'], $row['middle_name'], $row['last_name']);
                                $last_time = $row['last_message_at'] ?? $row['sent_at'];
                            ?>
                                <tr>
                                    <td><?php echo h($child_name); ?></td>
                                    <td><span class="<?php echo status_class($row['status']); ?>"><?php echo h($row['status']); ?></span></td>
                                    <td class="fb-table-preview">
                                        <?php if (!empty($row['last_message'])) { ?>
                                            <?php echo ($row['last_sender'] === 'CDW' ? 'You: ' : 'Guardian: ') . h($row['last_message']); ?>
                                        <?php } else { ?>
                                            <span class="fb-empty-msg">No messages yet</span>
                                        <?php } ?>
                                        <?php echo (int)$row['comment_count'] > 0 ? ' (' . (int)$row['comment_count'] . ')' : ''; ?>
                                    </td>
                                    <td><?php echo h(format_datetime_display($last_time)); ?></td>
                                    <td>
                                        <a href="referral_feedback.php?referral_id=<?php echo (int) $row['referral_id']; ?>" class="btn btn-submit">
                                            View &amp; Reply
                                        </a>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } else { ?>
                <p class="no-data">No sent referrals yet for this CDC.</p>
            <?php } ?>
        </div>

    <?php } ?>

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