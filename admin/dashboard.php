<?php
include '../includes/auth.php';
include '../config/database.php';

if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 1) {
    header("Location: ../login.php");
    exit();
}

function decodeReportPayload($payload) {
    if (is_array($payload)) {
        return $payload;
    }

    if (is_string($payload) && !empty($payload)) {
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : array();
    }

    return array();
}

function getLatestSubmittedWMRPerCDC($conn) {
    $reports = array();

    $sql = "
        SELECT submitted_report_id, cdc_id, submitted_at, report_type, report_payload
        FROM submitted_reports
        WHERE LOWER(report_type) = 'wmr'
        ORDER BY cdc_id ASC, submitted_at DESC, submitted_report_id DESC
    ";
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $seen_cdc = array();

        while ($row = mysqli_fetch_assoc($result)) {
            $cdc_id = (int)$row['cdc_id'];

            if (isset($seen_cdc[$cdc_id])) {
                continue;
            }

            $seen_cdc[$cdc_id] = true;
            $reports[] = $row;
        }
    }

    return $reports;
}

function getFinalRiskStatus($wfa_status, $hfa_status, $wflh_status) {
    $wfa_status = trim((string)$wfa_status);
    $hfa_status = trim((string)$hfa_status);
    $wflh_status = trim((string)$wflh_status);

    if ($wflh_status === 'Severely Wasted') {
        return 'Severely Wasted';
    }

    if ($wfa_status === 'Severely Underweight') {
        return 'Severely Underweight';
    }

    if ($wflh_status === 'Moderately Wasted') {
        return 'Moderately Wasted';
    }

    if ($wfa_status === 'Underweight') {
        return 'Underweight';
    }

    if ($wflh_status === 'Obese') {
        return 'Obese';
    }

    if ($wflh_status === 'Overweight') {
        return 'Overweight';
    }

    if ($hfa_status === 'Severely Stunted') {
        return 'Severely Stunted';
    }

    if ($hfa_status === 'Stunted') {
        return 'Stunted';
    }

    return 'Normal';
}

function isAtRiskFinalStatus($final_status) {
    return (
        $final_status === 'Underweight' ||
        $final_status === 'Severely Underweight' ||
        $final_status === 'Moderately Wasted' ||
        $final_status === 'Severely Wasted' ||
        $final_status === 'Overweight' ||
        $final_status === 'Obese'
    );
}

function getInterventionCategoryFromFinalStatus($final_status) {
    if (
        $final_status === 'Underweight' ||
        $final_status === 'Moderately Wasted'
    ) {
        return 'Moderately Wasted';
    }

    if (
        $final_status === 'Severely Underweight' ||
        $final_status === 'Severely Wasted'
    ) {
        return 'Severely Wasted';
    }

    if ($final_status === 'Overweight') {
        return 'Overweight';
    }

    if ($final_status === 'Obese') {
        return 'Obese';
    }

    return null;
}

$total_cdcs = 0;
$total_cdws = 0;
$total_children = 0;
$at_risk_children = 0;
$submitted_reports = 0;
$pending_reviews = 0;

$status_summary = array(
    'Normal' => array('count' => 0, 'children' => array()),
    'Underweight' => array('count' => 0, 'children' => array()),
    'Severely Underweight' => array('count' => 0, 'children' => array()),
    'Overweight' => array('count' => 0, 'children' => array()),
    'Obese' => array('count' => 0, 'children' => array()),
    'Stunted' => array('count' => 0, 'children' => array()),
    'Severely Stunted' => array('count' => 0, 'children' => array()),
    'Moderately Wasted' => array('count' => 0, 'children' => array()),
    'Severely Wasted' => array('count' => 0, 'children' => array())
);

$recent_reports = array();
$intervention_alerts = array();

$cdc_query = "SELECT COUNT(*) AS total FROM cdc";
$cdc_result = mysqli_query($conn, $cdc_query);
if ($cdc_result && mysqli_num_rows($cdc_result) > 0) {
    $cdc_row = mysqli_fetch_assoc($cdc_result);
    $total_cdcs = (int)$cdc_row['total'];
}

$cdw_query = "SELECT COUNT(*) AS total FROM users WHERE role_id = 2";
$cdw_result = mysqli_query($conn, $cdw_query);
if ($cdw_result && mysqli_num_rows($cdw_result) > 0) {
    $cdw_row = mysqli_fetch_assoc($cdw_result);
    $total_cdws = (int)$cdw_row['total'];
}

$children_query = "SELECT COUNT(*) AS total FROM children";
$children_result = mysqli_query($conn, $children_query);
if ($children_result && mysqli_num_rows($children_result) > 0) {
    $children_row = mysqli_fetch_assoc($children_result);
    $total_children = (int)$children_row['total'];
}

$submitted_reports_query = "SELECT COUNT(*) AS total FROM submitted_reports";
$submitted_reports_result = mysqli_query($conn, $submitted_reports_query);
if ($submitted_reports_result && mysqli_num_rows($submitted_reports_result) > 0) {
    $submitted_reports_row = mysqli_fetch_assoc($submitted_reports_result);
    $submitted_reports = (int)$submitted_reports_row['total'];
}

/*
|--------------------------------------------------------------------------
| At-Risk Children
| Source: latest submitted WMR per CDC only
| Do NOT depend on intervention_guidance sending status
|--------------------------------------------------------------------------
*/
$at_risk_child_keys = array();
$pending_review_child_keys = array();
$reviewed_or_sent_child_keys = array();

/*
|--------------------------------------------------------------------------
| Sent Intervention Guidance Records
| Specific to submitted WMR report and intervention category
|--------------------------------------------------------------------------
| A child is no longer pending only if the intervention guidance
| was sent for the same child, same submitted report, and same category.
|--------------------------------------------------------------------------
*/
$sent_intervention_result_keys = array();

$sent_intervention_query = "
    SELECT DISTINCT child_id, submitted_report_id, intervention_category
    FROM intervention_guidance
    WHERE is_at_risk = 1
      AND sent_to_guardian = 1
      AND submitted_report_id IS NOT NULL
";
$sent_intervention_result = mysqli_query($conn, $sent_intervention_query);

if ($sent_intervention_result) {
    while ($sent_row = mysqli_fetch_assoc($sent_intervention_result)) {
        $sent_child_id = (int)$sent_row['child_id'];
        $sent_report_id = (int)$sent_row['submitted_report_id'];
        $sent_category = trim((string)$sent_row['intervention_category']);

        if ($sent_child_id > 0 && $sent_report_id > 0 && $sent_category !== '') {
            $sent_key = 'child_' . $sent_child_id . '|report_' . $sent_report_id . '|category_' . $sent_category;
            $sent_intervention_result_keys[$sent_key] = true;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Nutritional Status Summary
| Source: latest submitted WMR per CDC only
| One final pinaka-risk status per child
|--------------------------------------------------------------------------
*/
$latest_wmr_reports = getLatestSubmittedWMRPerCDC($conn);
$processed_children = array();

foreach ($latest_wmr_reports as $wmr_report) {
    $payload = decodeReportPayload($wmr_report['report_payload']);
    $submitted_rows = isset($payload['submitted_rows']) && is_array($payload['submitted_rows'])
        ? $payload['submitted_rows']
        : array();

    foreach ($submitted_rows as $row) {
        $child_id = isset($row['child_id']) ? (int)$row['child_id'] : 0;
        $child_name = isset($row['child_name']) ? trim($row['child_name']) : '';
        $cdc_name = isset($row['cdc_name']) ? trim($row['cdc_name']) : '';

        $wfa_status = isset($row['wfa_status']) ? trim($row['wfa_status']) : '';
        $hfa_status = isset($row['hfa_status']) ? trim($row['hfa_status']) : '';
        $wflh_status = isset($row['wflh_status']) ? trim($row['wflh_status']) : '';

        if ($child_name === '') {
            $child_name = 'Unknown Child';
        }

        if ($cdc_name === '') {
            $cdc_name = 'Unknown CDC';
        }

        $child_key = $child_id > 0 ? 'child_' . $child_id : md5($child_name . '|' . $cdc_name . '|' . $wmr_report['submitted_report_id']);

        if (isset($processed_children[$child_key])) {
            continue;
        }
        $processed_children[$child_key] = true;

        $final_status = getFinalRiskStatus($wfa_status, $hfa_status, $wflh_status);

                $current_report_id = (int)$wmr_report['submitted_report_id'];

            if (isAtRiskFinalStatus($final_status)) {
                $at_risk_child_keys[$child_key] = true;

                $intervention_category = getInterventionCategoryFromFinalStatus($final_status);

                if ($intervention_category !== null) {
                    $current_result_key = $child_key . '|report_' . $current_report_id . '|category_' . $intervention_category;

                    if (!isset($sent_intervention_result_keys[$current_result_key])) {
                        $pending_review_child_keys[$current_result_key] = true;
                    }
                }
}

            if (!isset($status_summary[$final_status])) {
                continue;
            }

        $status_summary[$final_status]['count']++;
        $status_summary[$final_status]['children'][] = array(
            'child_name' => $child_name,
            'cdc_name' => $cdc_name
        );
    }
}

$at_risk_children = count($at_risk_child_keys);
$pending_reviews = count($pending_review_child_keys);




/*
|--------------------------------------------------------------------------
| Recent Reports
|--------------------------------------------------------------------------
*/
$recent_reports_query = "
    SELECT 
        sr.submitted_report_id,
        sr.report_type,
        sr.cdc_id,
        sr.submitted_at,
        sr.status,
        c.cdc_name,
        CONCAT(u.first_name, ' ', u.last_name) AS submitted_by_name
    FROM submitted_reports sr
    LEFT JOIN cdc c ON sr.cdc_id = c.cdc_id
    LEFT JOIN users u ON sr.submitted_by = u.user_id
    ORDER BY sr.submitted_at DESC, sr.submitted_report_id DESC
    LIMIT 5
";
$recent_reports_result = mysqli_query($conn, $recent_reports_query);

if ($recent_reports_result) {
    while ($row = mysqli_fetch_assoc($recent_reports_result)) {
        $report_type = strtoupper($row['report_type']);
        $cdc_name = !empty($row['cdc_name']) ? $row['cdc_name'] : 'Unknown CDC';
        $submitted_by_name = !empty($row['submitted_by_name']) ? $row['submitted_by_name'] : 'Unknown User';
        $submitted_at = !empty($row['submitted_at']) ? date("F d, Y g:i A", strtotime($row['submitted_at'])) : '—';
        $status = !empty($row['status']) ? ucfirst($row['status']) : 'Submitted';

        $recent_reports[] = array(
            'title' => $report_type . ' Report',
            'description' => $cdc_name,
            'meta' => 'Submitted by ' . $submitted_by_name . ' • ' . $submitted_at . ' • ' . $status
        );
    }
}

/*
|--------------------------------------------------------------------------
| Intervention Alerts
| Official intervention_guidance data only
|--------------------------------------------------------------------------
*/
$intervention_alerts_query = "
    SELECT 
        ig.guidance_id,
        ig.child_id,
        ig.original_status,
        ig.intervention_category,
        ig.is_at_risk,
        ig.needs_counseling,
        ig.needs_referral,
        ig.status_note,
        ig.updated_at,
        ig.created_at,
        ig.sent_to_guardian,
        c.cdc_name,
        ch.first_name,
        ch.last_name
    FROM intervention_guidance ig
    LEFT JOIN children ch ON ig.child_id = ch.child_id
    LEFT JOIN cdc c ON ch.cdc_id = c.cdc_id
    WHERE ig.is_at_risk = 1
      AND (ig.sent_to_guardian = 0 OR ig.sent_to_guardian IS NULL)
    ORDER BY ig.updated_at DESC, ig.guidance_id DESC
    LIMIT 5
";
$intervention_alerts_result = mysqli_query($conn, $intervention_alerts_query);

if ($intervention_alerts_result) {
    while ($row = mysqli_fetch_assoc($intervention_alerts_result)) {
        $child_name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if ($child_name === '') {
            $child_name = 'Unknown Child';
        }

        $nutritional_status = !empty($row['original_status'])
            ? $row['original_status']
            : (!empty($row['intervention_category']) ? $row['intervention_category'] : 'At Risk');

        $description = !empty($row['status_note'])
            ? $row['status_note']
            : 'Official intervention guidance record available for review.';

        $meta_time = !empty($row['updated_at']) ? $row['updated_at'] : $row['created_at'];

        $intervention_alerts[] = array(
            'child_id' => (int)$row['child_id'],
            'child_name' => $child_name,
            'nutritional_status' => $nutritional_status,
            'description' => $description,
            'meta' => (!empty($row['cdc_name']) ? $row['cdc_name'] : 'Unknown CDC') . ' • ' .
                      (!empty($meta_time) ? date("F d, Y g:i A", strtotime($meta_time)) : '—')
        );
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSWD Dashboard | NutriTrack</title>
    <link rel="stylesheet" href="../assets/admin/admin-style.css">
    <link rel="stylesheet" href="../assets/admin/admin-topbar-notification.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    <style>
        .status-children-list {
            margin-top: 10px;
            padding-left: 18px;
        }

        .status-children-list li {
            margin-bottom: 6px;
            font-size: 13px;
            color: #334155;
            font-family: 'Inter', sans-serif;
        }

        .status-children-list li span {
            color: #64748b;
            font-size: 12px;
        }

        .status-empty-note {
            margin-top: 8px;
            font-size: 12px;
            color: #94a3b8;
            font-family: 'Inter', sans-serif;
        }

        .alert-actions {
            margin-top: 10px;
        }

        .alert-action-link {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 10px;
            background: #1e3a8a;
            color: #ffffff;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
        }

        .alert-action-link:hover {
            opacity: 0.92;
        }
    </style>
</head>
<body>

<?php include '../includes/admin_topbar.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="dashboard-wrapper">

        <div class="page-header-card">
            <div class="page-header">
                
            </div>

            <div class="summary-grid">
                <div class="summary-box box-navy">
                    <div class="summary-box-header">Total CDCs</div>
                    <div class="summary-box-value"><?php echo $total_cdcs; ?></div>
                </div>

                <div class="summary-box box-navy">
                    <div class="summary-box-header">Total CDWs</div>
                    <div class="summary-box-value"><?php echo $total_cdws; ?></div>
                </div>

                <div class="summary-box box-navy">
                    <div class="summary-box-header">Total Children</div>
                    <div class="summary-box-value"><?php echo $total_children; ?></div>
                </div>

                <div class="summary-box box-red">
                    <div class="summary-box-header">At-Risk Children</div>
                    <div class="summary-box-value"><?php echo $at_risk_children; ?></div>
                </div>

                <div class="summary-box box-green">
                    <div class="summary-box-header">Submitted Reports</div>
                    <div class="summary-box-value"><?php echo $submitted_reports; ?></div>
                </div>

                <div class="summary-box box-red">
                    <div class="summary-box-header">Pending Reviews</div>
                    <div class="summary-box-value"><?php echo $pending_reviews; ?></div>
                </div>
            </div>
        </div>

        <div class="panel-grid">
            <div class="panel-card">
                <h2 class="panel-title">Nutritional Status Summary</h2>

                <div class="status-list">
                    <div class="status-item">
                        <div class="status-item-left">
                            <h4>Normal</h4>
                            <p>Children with final overall normal status</p>
                            <?php if (!empty($status_summary['Normal']['children'])) { ?>
                                <ul class="status-children-list">
                                    <?php foreach ($status_summary['Normal']['children'] as $child) { ?>
                                        <li>
                                            <?php echo htmlspecialchars($child['child_name']); ?>
                                            <span>• <?php echo htmlspecialchars($child['cdc_name']); ?></span>
                                        </li>
                                    <?php } ?>
                                </ul>
                            <?php } else { ?>
                                <div class="status-empty-note">No children listed in this category.</div>
                            <?php } ?>
                        </div>
                        <div class="status-badge"><?php echo $status_summary['Normal']['count']; ?></div>
                    </div>

                    <div class="status-item">
                        <div class="status-item-left">
                            <h4>Underweight</h4>
                            <p>Children whose final overall status is underweight</p>
                            <?php if (!empty($status_summary['Underweight']['children'])) { ?>
                                <ul class="status-children-list">
                                    <?php foreach ($status_summary['Underweight']['children'] as $child) { ?>
                                        <li>
                                            <?php echo htmlspecialchars($child['child_name']); ?>
                                            <span>• <?php echo htmlspecialchars($child['cdc_name']); ?></span>
                                        </li>
                                    <?php } ?>
                                </ul>
                            <?php } else { ?>
                                <div class="status-empty-note">No children listed in this category.</div>
                            <?php } ?>
                        </div>
                        <div class="status-badge"><?php echo $status_summary['Underweight']['count']; ?></div>
                    </div>

                    <div class="status-item">
                        <div class="status-item-left">
                            <h4>Severely Underweight</h4>
                            <p>Children whose final overall status is severely underweight</p>
                            <?php if (!empty($status_summary['Severely Underweight']['children'])) { ?>
                                <ul class="status-children-list">
                                    <?php foreach ($status_summary['Severely Underweight']['children'] as $child) { ?>
                                        <li>
                                            <?php echo htmlspecialchars($child['child_name']); ?>
                                            <span>• <?php echo htmlspecialchars($child['cdc_name']); ?></span>
                                        </li>
                                    <?php } ?>
                                </ul>
                            <?php } else { ?>
                                <div class="status-empty-note">No children listed in this category.</div>
                            <?php } ?>
                        </div>
                        <div class="status-badge"><?php echo $status_summary['Severely Underweight']['count']; ?></div>
                    </div>

                    <div class="status-item">
                        <div class="status-item-left">
                            <h4>Overweight</h4>
                            <p>Children whose final overall status is overweight</p>
                            <?php if (!empty($status_summary['Overweight']['children'])) { ?>
                                <ul class="status-children-list">
                                    <?php foreach ($status_summary['Overweight']['children'] as $child) { ?>
                                        <li>
                                            <?php echo htmlspecialchars($child['child_name']); ?>
                                            <span>• <?php echo htmlspecialchars($child['cdc_name']); ?></span>
                                        </li>
                                    <?php } ?>
                                </ul>
                            <?php } else { ?>
                                <div class="status-empty-note">No children listed in this category.</div>
                            <?php } ?>
                        </div>
                        <div class="status-badge"><?php echo $status_summary['Overweight']['count']; ?></div>
                    </div>

                    <div class="status-item">
                        <div class="status-item-left">
                            <h4>Obese</h4>
                            <p>Children whose final overall status is obese</p>
                            <?php if (!empty($status_summary['Obese']['children'])) { ?>
                                <ul class="status-children-list">
                                    <?php foreach ($status_summary['Obese']['children'] as $child) { ?>
                                        <li>
                                            <?php echo htmlspecialchars($child['child_name']); ?>
                                            <span>• <?php echo htmlspecialchars($child['cdc_name']); ?></span>
                                        </li>
                                    <?php } ?>
                                </ul>
                            <?php } else { ?>
                                <div class="status-empty-note">No children listed in this category.</div>
                            <?php } ?>
                        </div>
                        <div class="status-badge"><?php echo $status_summary['Obese']['count']; ?></div>
                    </div>

                    <div class="status-item">
                        <div class="status-item-left">
                            <h4>Stunted</h4>
                            <p>Children whose final overall status is stunted</p>
                            <?php if (!empty($status_summary['Stunted']['children'])) { ?>
                                <ul class="status-children-list">
                                    <?php foreach ($status_summary['Stunted']['children'] as $child) { ?>
                                        <li>
                                            <?php echo htmlspecialchars($child['child_name']); ?>
                                            <span>• <?php echo htmlspecialchars($child['cdc_name']); ?></span>
                                        </li>
                                    <?php } ?>
                                </ul>
                            <?php } else { ?>
                                <div class="status-empty-note">No children listed in this category.</div>
                            <?php } ?>
                        </div>
                        <div class="status-badge"><?php echo $status_summary['Stunted']['count']; ?></div>
                    </div>

                    <div class="status-item">
                        <div class="status-item-left">
                            <h4>Severely Stunted</h4>
                            <p>Children whose final overall status is severely stunted</p>
                            <?php if (!empty($status_summary['Severely Stunted']['children'])) { ?>
                                <ul class="status-children-list">
                                    <?php foreach ($status_summary['Severely Stunted']['children'] as $child) { ?>
                                        <li>
                                            <?php echo htmlspecialchars($child['child_name']); ?>
                                            <span>• <?php echo htmlspecialchars($child['cdc_name']); ?></span>
                                        </li>
                                    <?php } ?>
                                </ul>
                            <?php } else { ?>
                                <div class="status-empty-note">No children listed in this category.</div>
                            <?php } ?>
                        </div>
                        <div class="status-badge"><?php echo $status_summary['Severely Stunted']['count']; ?></div>
                    </div>

                    <div class="status-item">
                        <div class="status-item-left">
                            <h4>Moderately Wasted</h4>
                            <p>Children whose final overall status is moderately wasted</p>
                            <?php if (!empty($status_summary['Moderately Wasted']['children'])) { ?>
                                <ul class="status-children-list">
                                    <?php foreach ($status_summary['Moderately Wasted']['children'] as $child) { ?>
                                        <li>
                                            <?php echo htmlspecialchars($child['child_name']); ?>
                                            <span>• <?php echo htmlspecialchars($child['cdc_name']); ?></span>
                                        </li>
                                    <?php } ?>
                                </ul>
                            <?php } else { ?>
                                <div class="status-empty-note">No children listed in this category.</div>
                            <?php } ?>
                        </div>
                        <div class="status-badge"><?php echo $status_summary['Moderately Wasted']['count']; ?></div>
                    </div>

                    <div class="status-item">
                        <div class="status-item-left">
                            <h4>Severely Wasted</h4>
                            <p>Children whose final overall status is severely wasted</p>
                            <?php if (!empty($status_summary['Severely Wasted']['children'])) { ?>
                                <ul class="status-children-list">
                                    <?php foreach ($status_summary['Severely Wasted']['children'] as $child) { ?>
                                        <li>
                                            <?php echo htmlspecialchars($child['child_name']); ?>
                                            <span>• <?php echo htmlspecialchars($child['cdc_name']); ?></span>
                                        </li>
                                    <?php } ?>
                                </ul>
                            <?php } else { ?>
                                <div class="status-empty-note">No children listed in this category.</div>
                            <?php } ?>
                        </div>
                        <div class="status-badge"><?php echo $status_summary['Severely Wasted']['count']; ?></div>
                    </div>
                </div>
            </div>

            <div class="panel-card">
                <h2 class="panel-title">Recent Reports</h2>

                <?php if (!empty($recent_reports)) { ?>
                    <div class="report-list">
                        <?php foreach ($recent_reports as $report) { ?>
                            <div class="report-item">
                                <h4><?php echo htmlspecialchars($report['title']); ?></h4>
                                <p><?php echo htmlspecialchars($report['description']); ?></p>
                                <div class="item-meta"><?php echo htmlspecialchars($report['meta']); ?></div>
                            </div>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <div class="empty-box">
                        No recent report data is connected yet.
                        <br><br>
                        This panel is ready for:
                        Masterlist, WMR, Feeding Attendance, Nutritional Status Summary, and Terminal Reports.
                    </div>
                <?php } ?>
            </div>

         

            <div class="panel-card">
                <h2 class="panel-title">Admin Quick Access</h2>

                <div class="quick-actions">
                    <a href="child_records.php" class="quick-btn navy">Child Records</a>
                    <a href="monitoring_reports.php" class="quick-btn navy">Monitoring Reports</a>
                    <a href="intervention_guidance.php" class="quick-btn navy">Intervention Guidance</a>
                    <a href="add_cdc.php" class="quick-btn navy">CDC Management</a>
                    <a href="add_user.php" class="quick-btn navy">User Management</a>
                </div>
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