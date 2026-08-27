<?php
include '../includes/auth.php';
include '../config/database.php';

if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 1) {
    header("Location: ../login.php");
    exit();
}

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

function nutri_slug($category) {
    return strtolower(str_replace(' ', '-', trim((string)$category)));
}

function nutri_class($category) {
    $known = [
        'normal', 'underweight', 'severely-underweight', 'overweight', 'obese',
        'stunted', 'severely-stunted', 'moderately-wasted', 'severely-wasted'
    ];
    $slug = nutri_slug($category);
    $suffix = in_array($slug, $known, true) ? $slug : 'other';
    return 'nutri-chip nutri-' . $suffix;
}

function nutri_bar_color($category) {
    $map = [
        'normal' => '#16a34a',
        'underweight' => '#d97706',
        'severely-underweight' => '#dc2626',
        'overweight' => '#d97706',
        'obese' => '#dc2626',
        'stunted' => '#d97706',
        'severely-stunted' => '#dc2626',
        'moderately-wasted' => '#ea580c',
        'severely-wasted' => '#dc2626',
    ];
    $slug = nutri_slug($category);
    return $map[$slug] ?? '#64748b';
}

/*
|--------------------------------------------------------------------------
| THIS PAGE IS READ-ONLY.
| CSWD may monitor referral progress and trace every referral back to its
| official intervention_guidance record, but cannot edit status, generate,
| send, or reply here. Those actions remain exclusive to CDW/Guardian.
|--------------------------------------------------------------------------
*/

$view_referral_id = isset($_GET['referral_id']) ? (int) $_GET['referral_id'] : 0;
$filter_cdc_id = isset($_GET['cdc_id']) ? (int) $_GET['cdc_id'] : 0;
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';

/*
|--------------------------------------------------------------------------
| DETAIL VIEW: single referral, full traceability + read-only thread
|--------------------------------------------------------------------------
*/
$detail = null;
$comments = [];

if ($view_referral_id) {
    $detail_sql = "
        SELECT
            r.referral_id,
            r.guidance_id,
            r.final_category,
            r.reason_for_referral,
            r.recommended_facility,
            r.remarks,
            r.status,
            r.sent_at,
            r.viewed_at,
            r.in_progress_at,
            r.completed_at,
            r.created_at,
            c.first_name,
            c.middle_name,
            c.last_name,
            cdc.cdc_name,
            ig.guidance_text,
            ig.original_status,
            ig.sent_to_guardian,
            ig.sent_at AS guidance_sent_at,
            gen.first_name AS generated_by_first,
            gen.last_name AS generated_by_last
        FROM referrals r
        INNER JOIN children c ON c.child_id = r.child_id
        LEFT JOIN cdc ON cdc.cdc_id = c.cdc_id
        INNER JOIN intervention_guidance ig ON ig.guidance_id = r.guidance_id
        LEFT JOIN users gen ON gen.user_id = r.generated_by
        WHERE r.referral_id = ?
        LIMIT 1
    ";
    $detail_stmt = $conn->prepare($detail_sql);
    $detail_stmt->bind_param("i", $view_referral_id);
    $detail_stmt->execute();
    $detail = $detail_stmt->get_result()->fetch_assoc();
    $detail_stmt->close();

    if ($detail) {
        $comments_stmt = $conn->prepare("
            SELECT rc.sender_role, rc.message, rc.attachment_path, rc.created_at, u.first_name, u.last_name
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
| LIST VIEW: every referral, across all CDCs, with optional filters
|--------------------------------------------------------------------------
*/
$referrals_list = [];
$summary = ['Pending' => 0, 'Sent' => 0, 'Viewed' => 0, 'In Progress' => 0, 'Completed' => 0];
$nutrition_summary = [];
$cdc_options = [];

if (!$view_referral_id) {
    $cdc_result = $conn->query("SELECT cdc_id, cdc_name FROM cdc ORDER BY cdc_name ASC");
    while ($cdc_row = $cdc_result->fetch_assoc()) {
        $cdc_options[] = $cdc_row;
    }

    $where_clauses = [];
    $params = [];
    $param_types = '';

    if ($filter_cdc_id) {
        $where_clauses[] = 'c.cdc_id = ?';
        $params[] = $filter_cdc_id;
        $param_types .= 'i';
    }
    if ($filter_status !== '' && in_array($filter_status, ['Pending', 'Sent', 'Viewed', 'In Progress', 'Completed'], true)) {
        $where_clauses[] = 'r.status = ?';
        $params[] = $filter_status;
        $param_types .= 's';
    }
    $where_sql = !empty($where_clauses) ? ('WHERE ' . implode(' AND ', $where_clauses)) : '';

    $list_sql = "
        SELECT
            r.referral_id,
            r.status,
            r.final_category,
            r.sent_at,
            r.created_at,
            c.first_name,
            c.middle_name,
            c.last_name,
            cdc.cdc_name,
            (SELECT COUNT(*) FROM referral_comments rc WHERE rc.referral_id = r.referral_id) AS comment_count,
            (SELECT MAX(rc2.created_at) FROM referral_comments rc2 WHERE rc2.referral_id = r.referral_id) AS last_comment_at
        FROM referrals r
        INNER JOIN children c ON c.child_id = r.child_id
        LEFT JOIN cdc ON cdc.cdc_id = c.cdc_id
        $where_sql
        ORDER BY r.created_at DESC
    ";
    $list_stmt = $conn->prepare($list_sql);
    if ($param_types !== '') {
        $list_stmt->bind_param($param_types, ...$params);
    }
    $list_stmt->execute();
    $list_result = $list_stmt->get_result();

    while ($row = $list_result->fetch_assoc()) {
        $referrals_list[] = $row;
    }
    $list_stmt->close();

    // Summary counts respect the CDC filter but not the status filter,
    // so CSWD can see the full status breakdown for the selected scope.
    $summary_where = $filter_cdc_id ? 'WHERE c.cdc_id = ?' : '';
    $summary_sql = "
        SELECT r.status, COUNT(*) AS total
        FROM referrals r
        INNER JOIN children c ON c.child_id = r.child_id
        $summary_where
        GROUP BY r.status
    ";
    $summary_stmt = $conn->prepare($summary_sql);
    if ($filter_cdc_id) {
        $summary_stmt->bind_param("i", $filter_cdc_id);
    }
    $summary_stmt->execute();
    $summary_result = $summary_stmt->get_result();
    while ($srow = $summary_result->fetch_assoc()) {
        $summary[$srow['status']] = (int) $srow['total'];
    }
    $summary_stmt->close();

    // Nutritional status breakdown respects the CDC filter but not the status
    // filter, mirroring the status summary above.
    $nutri_sql = "
        SELECT r.final_category, COUNT(*) AS total
        FROM referrals r
        INNER JOIN children c ON c.child_id = r.child_id
        $summary_where
        GROUP BY r.final_category
        ORDER BY total DESC
    ";
    $nutri_stmt = $conn->prepare($nutri_sql);
    if ($filter_cdc_id) {
        $nutri_stmt->bind_param("i", $filter_cdc_id);
    }
    $nutri_stmt->execute();
    $nutri_result = $nutri_stmt->get_result();
    while ($nrow = $nutri_result->fetch_assoc()) {
        $nutrition_summary[] = ['label' => $nrow['final_category'], 'total' => (int) $nrow['total']];
    }
    $nutri_stmt->close();
}

$nutrition_max = 0;
foreach ($nutrition_summary as $nrow) {
    $nutrition_max = max($nutrition_max, $nrow['total']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral Monitoring | NutriTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/admin/admin-style.css">
    <link rel="stylesheet" href="../assets/admin/admin-topbar-notification.css">

    <style>
    .rm-content-card{
        background:#fff;
        border:1px solid #e5e7eb;
        border-radius:20px;
        padding:26px 28px;
        box-shadow:0 10px 26px rgba(15,23,42,0.05);
        margin-bottom:22px;
    }

    .rm-page-header{ margin-bottom:20px; }
    .rm-page-title{ font-family:'Poppins',sans-serif; font-size:26px; font-weight:700; color:#1f2937; margin:6px 0 6px; }
    .rm-page-subtitle{ color:#6b7280; font-size:14px; }
    .rm-back-link{ color:#2C5EAD; font-weight:600; font-size:14px; text-decoration:none; }
    .rm-back-link:hover{ text-decoration:underline; }

    .rm-summary-grid{
        display:grid;
        grid-template-columns:repeat(auto-fit, minmax(140px, 1fr));
        gap:14px;
        margin-bottom:22px;
    }

    .rm-summary-card{
        background:#fffdf9;
        border:1px solid #edf0f4;
        border-radius:16px;
        padding:16px 18px;
        text-align:center;
    }

    .rm-summary-label{ font-size:12px; color:#94a3b8; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:6px; }
    .rm-summary-value{ font-size:24px; font-weight:700; color:#1f2937; font-family:'Poppins',sans-serif; }

    .rm-filters{
        display:flex;
        gap:12px;
        flex-wrap:wrap;
        margin-bottom:20px;
    }

    .rm-filters select{
        padding:10px 14px;
        border-radius:10px;
        border:1px solid #dbe2ea;
        font-family:'Inter',sans-serif;
        font-size:14px;
        color:#334155;
        background:#fff;
    }

    .rm-table-wrapper{ overflow-x:auto; }
    table.rm-table{ width:100%; border-collapse:collapse; }
    table.rm-table th{
        text-align:left;
        font-size:12px;
        text-transform:uppercase;
        letter-spacing:0.4px;
        color:#94a3b8;
        padding:10px 14px;
        border-bottom:2px solid #edf0f4;
    }
    table.rm-table td{
        padding:14px;
        border-bottom:1px solid #f1f5f9;
        font-size:14px;
        color:#334155;
    }

    .status-chip{
        display:inline-block;
        padding:5px 12px;
        border-radius:999px;
        font-size:12px;
        font-weight:700;
    }
    .status-pending{ background:#f1f5f9; color:#64748b; }
    .status-sent{ background:#dbeafe; color:#1d4ed8; }
    .status-viewed{ background:#ede9fe; color:#6d28d9; }
    .status-in-progress{ background:#ffedd5; color:#c2410c; }
    .status-completed{ background:#dcfce7; color:#166534; }

    .nutri-chip{
        display:inline-block;
        padding:5px 12px;
        border-radius:999px;
        font-size:12px;
        font-weight:700;
        white-space:nowrap;
    }
    .nutri-normal{ background:#dcfce7; color:#166534; }
    .nutri-underweight{ background:#fef3c7; color:#92400e; }
    .nutri-severely-underweight{ background:#fee2e2; color:#991b1b; }
    .nutri-overweight{ background:#fef3c7; color:#92400e; }
    .nutri-obese{ background:#fee2e2; color:#991b1b; }
    .nutri-stunted{ background:#fef3c7; color:#92400e; }
    .nutri-severely-stunted{ background:#fee2e2; color:#991b1b; }
    .nutri-moderately-wasted{ background:#ffedd5; color:#c2410c; }
    .nutri-severely-wasted{ background:#fee2e2; color:#991b1b; }
    .nutri-other{ background:#f1f5f9; color:#64748b; }

    .rm-section-label{
        font-size:12px; color:#94a3b8; text-transform:uppercase;
        letter-spacing:0.4px; margin:0 0 16px;
    }

    .rm-bar-chart{ display:flex; flex-direction:column; gap:16px; }
    .rm-bar-row{
        display:grid;
        grid-template-columns:150px 1fr 36px;
        align-items:center;
        gap:14px;
    }
    .rm-bar-label{ font-size:13px; font-weight:600; color:#334155; }
    .rm-bar-track{ background:#f1f5f9; border-radius:999px; height:14px; overflow:hidden; }
    .rm-bar-fill{ height:100%; border-radius:999px; }
    .rm-bar-value{ font-size:14px; font-weight:700; color:#1f2937; text-align:right; font-family:'Poppins',sans-serif; }

    @media (max-width: 640px) {
        .rm-bar-row{ grid-template-columns:1fr; gap:6px; }
        .rm-bar-value{ text-align:left; }
    }

    .rm-no-data{ color:#94a3b8; font-style:italic; padding:20px 0; }

    .rm-meta-grid{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:14px;
        margin-bottom:18px;
    }
    .rm-meta-item{ font-size:14px; color:#334155; }
    .rm-meta-item strong{
        display:block; font-size:12px; color:#94a3b8;
        text-transform:uppercase; letter-spacing:0.4px; margin-bottom:4px;
    }

    .rm-guidance-box{
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

    .rm-trace-box{
        background:#f0f9ff;
        border:1px solid #bae6fd;
        border-radius:16px;
        padding:16px 20px;
        margin-bottom:18px;
        font-size:13px;
        color:#0369a1;
    }
    .rm-trace-box strong{ color:#0c4a6e; }

    .rm-timeline{
        display:flex; gap:14px; flex-wrap:wrap;
        font-size:13px; color:#7b8794; margin-bottom:18px;
        padding-top:14px; border-top:1px solid #edf0f4;
    }

    .comment-list{
        display:flex; flex-direction:column; gap:10px;
        max-height:420px; overflow-y:auto; padding-right:4px;
    }
    .comment-bubble{ max-width:78%; padding:10px 14px; border-radius:14px; font-size:14px; }
    .comment-cdw{ align-self:flex-start; background:#f1f5f9; border:1px solid #e2e8f0; border-bottom-left-radius:4px; }
    .comment-guardian{ align-self:flex-end; background:#fff4e6; border:1px solid #f0dfcd; border-bottom-right-radius:4px; }
    .comment-meta{ display:flex; justify-content:space-between; gap:10px; font-size:11px; font-weight:700; color:#94a3b8; margin-bottom:4px; }
    .comment-message{ color:#1f2937; line-height:1.5; word-break:break-word; }
    .comment-attachment{ display:block; max-width:220px; max-height:220px; border-radius:12px; margin-top:6px; border:1px solid rgba(0,0,0,0.08); object-fit:cover; }
    .rm-no-comments{ font-size:13px; color:#94a3b8; }

    .rm-readonly-note{
        font-size:12px;
        color:#94a3b8;
        font-style:italic;
        margin-top:16px;
    }
    </style>
</head>
<body class="<?php echo themeClass(); ?>">

<?php include '../includes/admin_topbar.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content" id="mainContent">

    <?php if ($view_referral_id) { ?>

        <div class="rm-page-header">
            <a href="view_referral_monitoring.php" class="rm-back-link">← Back to Referral Monitoring</a>
            <h1 class="rm-page-title">Referral Detail (Read-Only)</h1>
            <div class="rm-page-subtitle">Full traceability to the official Intervention Guidance record.</div>
        </div>

        <?php if (!$detail) { ?>
            <div class="rm-content-card">
                <p class="rm-no-data">Referral not found.</p>
            </div>
        <?php } else {
            $child_name = build_child_full_name($detail['first_name'], $detail['middle_name'], $detail['last_name']);
            $generated_by_name = trim(($detail['generated_by_first'] ?? '') . ' ' . ($detail['generated_by_last'] ?? ''));
            $guidance_lines = array_filter(array_map('trim', explode("\n", str_replace("\r\n", "\n", $detail['guidance_text']))));
        ?>
            <div class="rm-content-card">

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
                    <h2 style="font-family:'Poppins',sans-serif; font-size:20px; margin:0;">Referral Form</h2>
                    <span class="<?php echo status_class($detail['status']); ?>"><?php echo h($detail['status']); ?></span>
                </div>

                <div class="rm-trace-box">
                    <strong>Traceability:</strong> This referral (Referral #<?php echo (int) $detail['referral_id']; ?>) was generated directly from
                    Intervention Guidance #<?php echo (int) $detail['guidance_id']; ?>
                    (Endline assessment, Original Status: <?php echo h($detail['original_status']); ?>,
                    Sent to Guardian: <?php echo h(format_datetime_display($detail['guidance_sent_at'])); ?>).
                    No new nutritional assessment was performed to create this referral.
                </div>

                <div class="rm-meta-grid">
                    <div class="rm-meta-item"><strong>Child Name</strong><?php echo h($child_name); ?></div>
                    <div class="rm-meta-item"><strong>CDC</strong><?php echo h($detail['cdc_name'] ?? 'N/A'); ?></div>
                    <div class="rm-meta-item"><strong>Final Category</strong><?php echo h($detail['final_category']); ?></div>
                    <div class="rm-meta-item"><strong>Recommended Facility</strong><?php echo h($detail['recommended_facility']); ?></div>
                    <div class="rm-meta-item"><strong>Generated By (CDW)</strong><?php echo h($generated_by_name !== '' ? $generated_by_name : 'N/A'); ?></div>
                    <div class="rm-meta-item"><strong>Sent</strong><?php echo h(format_datetime_display($detail['sent_at'])); ?></div>
                    <div class="rm-meta-item" style="grid-column:1 / -1;"><strong>Reason for Referral</strong><?php echo h($detail['reason_for_referral']); ?></div>
                    <?php if (!empty($detail['remarks'])) { ?>
                        <div class="rm-meta-item" style="grid-column:1 / -1;"><strong>Remarks</strong><?php echo h($detail['remarks']); ?></div>
                    <?php } ?>
                </div>

                <?php if (!empty($guidance_lines)) { ?>
                    <div class="rm-guidance-box">
                        <strong style="display:block; margin-bottom:8px; color:#94a3b8; font-size:12px; text-transform:uppercase;">Official Guidance (from CSWD)</strong>
                        <?php echo h(implode("\n", $guidance_lines)); ?>
                    </div>
                <?php } ?>

                <div class="rm-timeline">
                    <span>Sent: <?php echo h(format_datetime_display($detail['sent_at'])); ?></span>
                    <?php if (!empty($detail['viewed_at'])) { ?><span>Viewed: <?php echo h(format_datetime_display($detail['viewed_at'])); ?></span><?php } ?>
                    <?php if (!empty($detail['in_progress_at'])) { ?><span>In Progress since: <?php echo h(format_datetime_display($detail['in_progress_at'])); ?></span><?php } ?>
                    <?php if (!empty($detail['completed_at'])) { ?><span>Completed: <?php echo h(format_datetime_display($detail['completed_at'])); ?></span><?php } ?>
                </div>

                <strong style="display:block; margin-bottom:10px; color:#94a3b8; font-size:12px; text-transform:uppercase;">Message History (Guardian ↔ CDW)</strong>

                <?php if (!empty($comments)) { ?>
                    <div class="comment-list">
                        <?php foreach ($comments as $c) {
                            $is_guardian = ($c['sender_role'] === 'Guardian');
                            $sender_full_name = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
                            $sender_label = $c['sender_role'] . ($sender_full_name !== '' ? ' — ' . $sender_full_name : '');
                        ?>
                            <div class="comment-bubble <?php echo $is_guardian ? 'comment-guardian' : 'comment-cdw'; ?>">
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
                    <div class="rm-no-comments">There are no messages on this referral yet.</div>
                <?php } ?>

                <div class="rm-readonly-note">This is a read-only monitoring view. Status updates and replies are handled by the CDW and Guardian.</div>

            </div>
        <?php } ?>

    <?php } else { ?>

        <div class="rm-page-header">
            <a href="dashboard.php" class="rm-back-link">← Back to Dashboard</a>
            <h1 class="rm-page-title">Referral Monitoring</h1>
            <div class="rm-page-subtitle">Read-only view of every referral, traceable to its official Intervention Guidance record.</div>
        </div>

        <div class="rm-summary-grid">
            <div class="rm-summary-card"><div class="rm-summary-label">Pending</div><div class="rm-summary-value"><?php echo (int) $summary['Pending']; ?></div></div>
            <div class="rm-summary-card"><div class="rm-summary-label">Sent</div><div class="rm-summary-value"><?php echo (int) $summary['Sent']; ?></div></div>
            <div class="rm-summary-card"><div class="rm-summary-label">Viewed</div><div class="rm-summary-value"><?php echo (int) $summary['Viewed']; ?></div></div>
            <div class="rm-summary-card"><div class="rm-summary-label">In Progress</div><div class="rm-summary-value"><?php echo (int) $summary['In Progress']; ?></div></div>
            <div class="rm-summary-card"><div class="rm-summary-label">Completed</div><div class="rm-summary-value"><?php echo (int) $summary['Completed']; ?></div></div>
        </div>

        <?php if (!empty($nutrition_summary)) { ?>
            <div class="rm-content-card">
                <p class="rm-section-label">Nutritional Status Summary</p>
                <div class="rm-bar-chart">
                    <?php foreach ($nutrition_summary as $nrow) {
                        $pct = $nutrition_max > 0 ? round(($nrow['total'] / $nutrition_max) * 100) : 0;
                    ?>
                        <div class="rm-bar-row">
                            <div class="rm-bar-label"><?php echo h($nrow['label']); ?></div>
                            <div class="rm-bar-track">
                                <div class="rm-bar-fill" style="width:<?php echo (int) $pct; ?>%; background:<?php echo h(nutri_bar_color($nrow['label'])); ?>;"></div>
                            </div>
                            <div class="rm-bar-value"><?php echo (int) $nrow['total']; ?></div>
                        </div>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>

        <div class="rm-content-card">

            <form method="GET" class="rm-filters">
                <select name="cdc_id" onchange="this.form.submit()">
                    <option value="0">All CDCs</option>
                    <?php foreach ($cdc_options as $opt) { ?>
                        <option value="<?php echo (int) $opt['cdc_id']; ?>" <?php echo ($filter_cdc_id === (int)$opt['cdc_id']) ? 'selected' : ''; ?>>
                            <?php echo h($opt['cdc_name']); ?>
                        </option>
                    <?php } ?>
                </select>

                <select name="status" onchange="this.form.submit()">
                    <option value="">All Statuses</option>
                    <?php foreach (['Pending','Sent','Viewed','In Progress','Completed'] as $st) { ?>
                        <option value="<?php echo h($st); ?>" <?php echo ($filter_status === $st) ? 'selected' : ''; ?>>
                            <?php echo h($st); ?>
                        </option>
                    <?php } ?>
                </select>
            </form>

            <?php if (!empty($referrals_list)) { ?>
                <div class="rm-table-wrapper">
                    <table class="rm-table">
                        <thead>
                            <tr>
                                <th>Child Name</th>
                                <th>CDC</th>
                                <th>Status</th>
                                <th>Sent Date</th>
                                <th>Comments</th>
                                <th>Last Update</th>
                                <th>Nutritional Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($referrals_list as $row) {
                                $child_name = build_child_full_name($row['first_name'], $row['middle_name'], $row['last_name']);
                                $last_update = $row['last_comment_at'] ?? $row['sent_at'];
                            ?>
                                <tr>
                                    <td><?php echo h($child_name); ?></td>
                                    <td><?php echo h($row['cdc_name'] ?? 'N/A'); ?></td>
                                    <td><span class="<?php echo status_class($row['status']); ?>"><?php echo h($row['status']); ?></span></td>
                                    <td><?php echo h(format_datetime_display($row['sent_at'])); ?></td>
                                    <td><?php echo (int) $row['comment_count']; ?></td>
                                    <td><?php echo h(format_datetime_display($last_update)); ?></td>
                                    <td><span class="<?php echo nutri_class($row['final_category']); ?>"><?php echo h($row['final_category']); ?></span></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } else { ?>
                <p class="rm-no-data">No referrals found for the selected filter.</p>
            <?php } ?>

        </div>

    <?php } ?>

</div>

</body>
</html>