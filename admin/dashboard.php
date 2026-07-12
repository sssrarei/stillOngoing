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

/*
|--------------------------------------------------------------------------
| PART 11: Visual config for the redesigned dashboard panels
| Additive only — pure presentation lookups. Does not affect any of the
| counting / risk logic above or below.
|--------------------------------------------------------------------------
*/
function getStatusVisual($status_key) {
    $map = array(
        'Normal' => array(
            'icon' => '✅',
            'tone' => 'tone-green',
            'desc' => 'Children with final overall normal status'
        ),
        'Underweight' => array(
            'icon' => '⚠️',
            'tone' => 'tone-amber',
            'desc' => 'Final overall status is underweight'
        ),
        'Severely Underweight' => array(
            'icon' => '⛔',
            'tone' => 'tone-red',
            'desc' => 'Final overall status is severely underweight'
        ),
        'Overweight' => array(
            'icon' => '⚖️',
            'tone' => 'tone-amber',
            'desc' => 'Final overall status is overweight'
        ),
        'Obese' => array(
            'icon' => '🟠',
            'tone' => 'tone-orange',
            'desc' => 'Final overall status is obese'
        ),
        'Stunted' => array(
            'icon' => '📉',
            'tone' => 'tone-amber',
            'desc' => 'Final overall status is stunted'
        ),
        'Severely Stunted' => array(
            'icon' => '📉',
            'tone' => 'tone-red',
            'desc' => 'Final overall status is severely stunted'
        ),
        'Moderately Wasted' => array(
            'icon' => '🔶',
            'tone' => 'tone-orange',
            'desc' => 'Final overall status is moderately wasted'
        ),
        'Severely Wasted' => array(
            'icon' => '🚨',
            'tone' => 'tone-red',
            'desc' => 'Final overall status is severely wasted'
        ),
    );

    return isset($map[$status_key]) ? $map[$status_key] : array(
        'icon' => '❔', 'tone' => 'tone-gray', 'desc' => ''
    );
}

function getReportVisual($report_type_raw) {
    $key = strtolower(trim((string)$report_type_raw));

    $map = array(
        'wmr'                         => array('icon' => '📊', 'label' => 'WMR Report'),
        'masterlist'                  => array('icon' => '📋', 'label' => 'Masterlist Report'),
        'feeding_attendance'          => array('icon' => '🍽️', 'label' => 'Feeding Attendance Report'),
        'nutritional_status_summary'  => array('icon' => '📈', 'label' => 'Nutritional Status Summary'),
        'individual_child'            => array('icon' => '🧒', 'label' => 'Individual Child Report'),
        'terminal_report'             => array('icon' => '🏁', 'label' => 'Terminal Report'),
    );

    if (isset($map[$key])) {
        return $map[$key];
    }

    return array(
        'icon' => '📄',
        'label' => strtoupper($report_type_raw) . ' Report'
    );
}

function getReportStatusPillClass($status_raw) {
    $key = strtolower(trim((string)$status_raw));

    if ($key === 'submitted') {
        return 'pill-green';
    }
    if ($key === 'pending') {
        return 'pill-amber';
    }
    if ($key === 'rejected' || $key === 'declined') {
        return 'pill-red';
    }
    if ($key === 'saved_to_child_profile') {
        return 'pill-blue';
    }

    return 'pill-gray';
}

function escapeModalList($list) {
    $escaped = array();
    foreach ($list as $item) {
        $escaped[] = array(
            'primary' => htmlspecialchars((string)($item['primary'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'secondary' => htmlspecialchars((string)($item['secondary'] ?? ''), ENT_QUOTES, 'UTF-8'),
            'tag' => isset($item['tag']) && $item['tag'] !== null
                ? htmlspecialchars((string)$item['tag'], ENT_QUOTES, 'UTF-8')
                : null,
            'tagTone' => isset($item['tagTone']) ? (string)$item['tagTone'] : ''
        );
    }
    return $escaped;
}

function getChildAgeFromBirthdate($birthdate) {
    if (empty($birthdate)) {
        return '';
    }
    try {
        $bd = new DateTime($birthdate);
        $now = new DateTime();
        $diff = $bd->diff($now);
        if ($diff->y > 0) {
            return $diff->y . 'y ' . $diff->m . 'm';
        }
        return $diff->m . 'm';
    } catch (Exception $e) {
        return '';
    }
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
| PART 12: Modal list data for the clickable summary boxes
| (Total CDCs / Total CDWs / Total Children / Submitted Reports)
| Additive only — read-only lists for display, does not affect any counts.
|--------------------------------------------------------------------------
*/
$modal_cdcs = array();
$cdc_list_query = "SELECT cdc_id, cdc_name, barangay, status FROM cdc ORDER BY cdc_name ASC";
$cdc_list_result = mysqli_query($conn, $cdc_list_query);
if ($cdc_list_result) {
    while ($row = mysqli_fetch_assoc($cdc_list_result)) {
        $modal_cdcs[] = array(
            'primary' => $row['cdc_name'],
            'secondary' => !empty($row['barangay']) ? $row['barangay'] : '—',
            'tag' => !empty($row['status']) ? $row['status'] : 'Active',
            'tagTone' => (strtolower($row['status']) === 'inactive') ? 'tone-gray' : 'tone-green'
        );
    }
}

$modal_cdws = array();
$cdw_list_query = "
    SELECT 
        u.user_id, u.first_name, u.last_name,
        GROUP_CONCAT(DISTINCT c.cdc_name ORDER BY c.cdc_name SEPARATOR ', ') AS assigned_cdcs
    FROM users u
    LEFT JOIN cdw_assignments ca ON ca.user_id = u.user_id
    LEFT JOIN cdc c ON c.cdc_id = ca.cdc_id
    WHERE u.role_id = 2
    GROUP BY u.user_id, u.first_name, u.last_name
    ORDER BY u.first_name ASC, u.last_name ASC
";
$cdw_list_result = mysqli_query($conn, $cdw_list_query);
if ($cdw_list_result) {
    while ($row = mysqli_fetch_assoc($cdw_list_result)) {
        $modal_cdws[] = array(
            'primary' => trim($row['first_name'] . ' ' . $row['last_name']),
            'secondary' => !empty($row['assigned_cdcs']) ? $row['assigned_cdcs'] : 'No CDC assigned',
            'tag' => null,
            'tagTone' => ''
        );
    }
}

$modal_children = array();
$children_list_query = "
    SELECT ch.child_id, ch.first_name, ch.middle_name, ch.last_name, ch.birthdate, c.cdc_name
    FROM children ch
    LEFT JOIN cdc c ON c.cdc_id = ch.cdc_id
    WHERE ch.is_deleted = 0
    ORDER BY ch.last_name ASC, ch.first_name ASC
";
$children_list_result = mysqli_query($conn, $children_list_query);
if ($children_list_result) {
    while ($row = mysqli_fetch_assoc($children_list_result)) {
        $full_name = trim($row['first_name'] . ' ' . $row['middle_name'] . ' ' . $row['last_name']);
        $age = getChildAgeFromBirthdate($row['birthdate']);
        $modal_children[] = array(
            'primary' => $full_name,
            'secondary' => (!empty($row['cdc_name']) ? $row['cdc_name'] : 'Unknown CDC') . ($age !== '' ? ' — ' . $age : ''),
            'tag' => null,
            'tagTone' => ''
        );
    }
}

$modal_reports = array();
$reports_list_query = "
    SELECT 
        sr.report_type, sr.submitted_at, sr.status,
        c.cdc_name,
        CONCAT(u.first_name, ' ', u.last_name) AS submitted_by_name
    FROM submitted_reports sr
    LEFT JOIN cdc c ON sr.cdc_id = c.cdc_id
    LEFT JOIN users u ON sr.submitted_by = u.user_id
    ORDER BY sr.submitted_at DESC, sr.submitted_report_id DESC
    LIMIT 100
";
$reports_list_result = mysqli_query($conn, $reports_list_query);
if ($reports_list_result) {
    while ($row = mysqli_fetch_assoc($reports_list_result)) {
        $rv = getReportVisual($row['report_type']);
        $status_raw = !empty($row['status']) ? $row['status'] : 'submitted';
        $modal_reports[] = array(
            'primary' => $rv['label'],
            'secondary' => (!empty($row['cdc_name']) ? $row['cdc_name'] : 'Unknown CDC') . ' — ' .
                           (!empty($row['submitted_by_name']) ? trim($row['submitted_by_name']) : 'Unknown User') . ' — ' .
                           (!empty($row['submitted_at']) ? date("M d, Y g:i A", strtotime($row['submitted_at'])) : '—'),
            'tag' => ucwords(str_replace('_', ' ', $status_raw)),
            'tagTone' => str_replace('pill-', 'tone-', getReportStatusPillClass($status_raw))
        );
    }
}

/*
|--------------------------------------------------------------------------
| PART 9: Referral Program Summary (CSWD Dashboard widget)
| Additive only — read-only counts across ALL CDCs.
| Does not modify any of the logic above or below.
|--------------------------------------------------------------------------
*/
$referral_total = 0;
$referral_pending = 0;
$referral_completed = 0;
$referral_with_feedback = 0;
$referral_no_response = 0;

$referral_total_query = "SELECT COUNT(*) AS total FROM referrals";
$referral_total_result = mysqli_query($conn, $referral_total_query);
if ($referral_total_result && mysqli_num_rows($referral_total_result) > 0) {
    $referral_total_row = mysqli_fetch_assoc($referral_total_result);
    $referral_total = (int) $referral_total_row['total'];
}

$referral_status_query = "SELECT status, COUNT(*) AS total FROM referrals GROUP BY status";
$referral_status_result = mysqli_query($conn, $referral_status_query);
if ($referral_status_result) {
    while ($referral_status_row = mysqli_fetch_assoc($referral_status_result)) {
        if ($referral_status_row['status'] === 'Pending') {
            $referral_pending = (int) $referral_status_row['total'];
        }
        if ($referral_status_row['status'] === 'Completed') {
            $referral_completed = (int) $referral_status_row['total'];
        }
    }
}

$referral_feedback_query = "
    SELECT COUNT(DISTINCT r.referral_id) AS total
    FROM referrals r
    INNER JOIN referral_comments rc ON rc.referral_id = r.referral_id
";
$referral_feedback_result = mysqli_query($conn, $referral_feedback_query);
if ($referral_feedback_result && mysqli_num_rows($referral_feedback_result) > 0) {
    $referral_feedback_row = mysqli_fetch_assoc($referral_feedback_result);
    $referral_with_feedback = (int) $referral_feedback_row['total'];
}

$referral_no_response_query = "
    SELECT COUNT(*) AS total
    FROM referrals r
    WHERE r.status != 'Pending'
      AND NOT EXISTS (
          SELECT 1 FROM referral_comments rc WHERE rc.referral_id = r.referral_id
      )
";
$referral_no_response_result = mysqli_query($conn, $referral_no_response_query);
if ($referral_no_response_result && mysqli_num_rows($referral_no_response_result) > 0) {
    $referral_no_response_row = mysqli_fetch_assoc($referral_no_response_result);
    $referral_no_response = (int) $referral_no_response_row['total'];
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
$modal_at_risk = array();
$modal_pending = array();
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
                if (!isset($at_risk_child_keys[$child_key])) {
                    $modal_at_risk[] = array(
                        'primary' => $child_name,
                        'secondary' => $cdc_name,
                        'tag' => $final_status,
                        'tagTone' => getStatusVisual($final_status)['tone']
                    );
                }

                $at_risk_child_keys[$child_key] = true;

                $intervention_category = getInterventionCategoryFromFinalStatus($final_status);

                if ($intervention_category !== null) {
                    $current_result_key = $child_key . '|report_' . $current_report_id . '|category_' . $intervention_category;

                    if (!isset($sent_intervention_result_keys[$current_result_key]) && !isset($pending_review_child_keys[$current_result_key])) {
                        $modal_pending[] = array(
                            'primary' => $child_name,
                            'secondary' => $cdc_name . ' — ' . $intervention_category,
                            'tag' => null,
                            'tagTone' => ''
                        );
                    }

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
        $report_visual = getReportVisual($row['report_type']);
        $cdc_name = !empty($row['cdc_name']) ? $row['cdc_name'] : 'Unknown CDC';
        $submitted_by_name = !empty($row['submitted_by_name']) ? $row['submitted_by_name'] : 'Unknown User';
        $submitted_at = !empty($row['submitted_at']) ? date("F d, Y g:i A", strtotime($row['submitted_at'])) : '—';
        $status_raw = !empty($row['status']) ? $row['status'] : 'submitted';
        $status_label = ucwords(str_replace('_', ' ', $status_raw));

        $recent_reports[] = array(
            'icon' => $report_visual['icon'],
            'title' => $report_visual['label'],
            'description' => $cdc_name,
            'submitted_by' => $submitted_by_name,
            'submitted_at' => $submitted_at,
            'status_label' => $status_label,
            'status_pill_class' => getReportStatusPillClass($status_raw)
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

        .rp-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 12px;
            margin-top: 6px;
        }

        .rp-summary-item {
            background: #fffdf9;
            border: 1px solid #edf0f4;
            border-radius: 14px;
            padding: 14px 16px;
            text-align: center;
        }

        .rp-summary-label {
            font-size: 12px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 6px;
            font-family: 'Inter', sans-serif;
        }

        .rp-summary-value {
            font-size: 22px;
            font-weight: 700;
            color: #1f2937;
            font-family: 'Poppins', sans-serif;
        }

        /*
        |----------------------------------------------------------------
        | PART 11: "Nutritional Status Summary" — quiet, editorial style
        | Hairline dividers instead of boxed cards. Color is used only
        | as a small dot; no icon tiles, no chip backgrounds.
        |----------------------------------------------------------------
        */
        .nss-list {
            display: flex;
            flex-direction: column;
        }

        .nss-row {
            padding: 18px 2px;
            border-bottom: 1px solid #eef1f5;
        }

        .nss-row:last-child {
            border-bottom: none;
        }

        .nss-head {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 16px;
        }

        .nss-title-group {
            display: flex;
            align-items: baseline;
            gap: 9px;
        }

        .nss-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            flex-shrink: 0;
            position: relative;
            top: -1px;
        }

        .tone-green  .nss-dot { background: #34a659; }
        .tone-amber  .nss-dot { background: #d69a35; }
        .tone-orange .nss-dot { background: #d97b3f; }
        .tone-red    .nss-dot { background: #c0483f; }
        .tone-gray   .nss-dot { background: #b3bac4; }

        .nss-title-group h4 {
            font-family: 'Poppins', sans-serif;
            font-size: 14.5px;
            font-weight: 600;
            color: #263143;
            letter-spacing: 0.1px;
        }

        .nss-count {
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            font-weight: 600;
            color: #263143;
            flex-shrink: 0;
        }

        .nss-desc {
            font-size: 12px;
            color: #a3a9b3;
            font-family: 'Inter', sans-serif;
            margin: 3px 0 0 16px;
        }

        .nss-children {
            margin: 12px 0 0 16px;
            display: flex;
            flex-direction: column;
            gap: 7px;
            max-height: 132px;
            overflow-y: auto;
        }

        .nss-child-row {
            display: flex;
            align-items: baseline;
            gap: 8px;
            font-family: 'Inter', sans-serif;
        }

        .nss-child-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            flex-shrink: 0;
            position: relative;
            top: -1px;
        }

        .tone-green  .nss-child-dot { background: #34a659; }
        .tone-amber  .nss-child-dot { background: #d69a35; }
        .tone-orange .nss-child-dot { background: #d97b3f; }
        .tone-red    .nss-child-dot { background: #c0483f; }
        .tone-gray   .nss-child-dot { background: #b3bac4; }

        .nss-child-name {
            font-size: 12.5px;
            color: #47505f;
        }

        .nss-child-cdc {
            font-size: 11.5px;
            color: #b3bac4;
        }

        .nss-empty {
            margin: 10px 0 0 16px;
            font-size: 12px;
            color: #b3bac4;
            font-family: 'Inter', sans-serif;
        }

        /*
        |----------------------------------------------------------------
        | PART 11: "Recent Reports" — quiet, editorial style
        | Hairline dividers, status shown as a small colored word
        | (not a filled pill), no icon tiles.
        |----------------------------------------------------------------
        */
        .rr-list {
            display: flex;
            flex-direction: column;
        }

        .rr-row {
            padding: 16px 2px;
            border-bottom: 1px solid #eef1f5;
        }

        .rr-row:last-child {
            border-bottom: none;
        }

        .rr-top-row {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 14px;
        }

        .rr-title {
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            font-weight: 600;
            color: #263143;
        }

        .rr-cdc {
            font-size: 12px;
            color: #a3a9b3;
            font-family: 'Inter', sans-serif;
            margin-top: 2px;
        }

        .rr-status {
            flex-shrink: 0;
            font-size: 11.5px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            white-space: nowrap;
        }

        .rr-status-dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            margin-right: 5px;
            position: relative;
            top: -1px;
        }

        .pill-green .rr-status-dot { background: #34a659; }
        .pill-amber .rr-status-dot { background: #d69a35; }
        .pill-red   .rr-status-dot { background: #c0483f; }
        .pill-blue  .rr-status-dot { background: #3b6fd6; }
        .pill-gray  .rr-status-dot { background: #b3bac4; }

        .pill-green { color: #2f7a49; }
        .pill-amber { color: #a8701f; }
        .pill-red   { color: #a83c33; }
        .pill-blue  { color: #2f57ab; }
        .pill-gray  { color: #8a92a0; }

        .rr-meta {
            font-size: 11.5px;
            color: #a3a9b3;
            font-family: 'Inter', sans-serif;
            margin-top: 7px;
        }

        /*
        |----------------------------------------------------------------
        | PART 12: Clickable summary boxes + shared list modal
        |----------------------------------------------------------------
        */
        .summary-box-clickable {
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .summary-box-clickable:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.08);
        }

        .adm-modal-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .adm-modal-overlay.active {
            display: flex;
        }
        .adm-modal-box {
            background: #ffffff;
            border-radius: 16px;
            width: 100%;
            max-width: 480px;
            max-height: 78vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 50px rgba(0,0,0,0.2);
        }
        .adm-modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 20px;
            border-bottom: 1px solid #eef1f5;
        }
        .adm-modal-title {
            font-family: 'Poppins', sans-serif;
            font-size: 15.5px;
            font-weight: 600;
            color: #263143;
        }
        .adm-modal-subtitle {
            font-size: 11.5px;
            color: #a3a9b3;
            margin-top: 2px;
            font-family: 'Inter', sans-serif;
        }
        .adm-modal-close {
            background: none;
            border: none;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            font-size: 16px;
            color: #a3a9b3;
            cursor: pointer;
            flex-shrink: 0;
        }
        .adm-modal-close:hover {
            background: #f1f5f9;
            color: #64748b;
        }
        .adm-modal-body {
            padding: 4px 20px;
            overflow-y: auto;
        }
        .adm-modal-row {
            padding: 12px 0;
            border-bottom: 1px solid #f3f5f8;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .adm-modal-row:last-child {
            border-bottom: none;
        }
        .adm-modal-row-primary {
            font-size: 13px;
            font-weight: 600;
            color: #263143;
            font-family: 'Inter', sans-serif;
        }
        .adm-modal-row-secondary {
            font-size: 11.5px;
            color: #a3a9b3;
            font-family: 'Inter', sans-serif;
            margin-top: 2px;
        }
        .adm-modal-row-tag {
            flex-shrink: 0;
            font-size: 11px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            white-space: nowrap;
        }
        .adm-modal-row-tag.tone-green  { color: #2f7a49; }
        .adm-modal-row-tag.tone-amber  { color: #a8701f; }
        .adm-modal-row-tag.tone-orange { color: #b06121; }
        .adm-modal-row-tag.tone-red    { color: #a83c33; }
        .adm-modal-row-tag.tone-blue   { color: #2f57ab; }
        .adm-modal-row-tag.tone-gray   { color: #8a92a0; }
        .adm-modal-empty {
            text-align: center;
            color: #a3a9b3;
            font-size: 12.5px;
            font-family: 'Inter', sans-serif;
            padding: 30px 10px;
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
                <div class="summary-box box-navy summary-box-clickable" onclick="openSummaryModal('cdcs', 'Total CDCs')">
                    <div class="summary-box-header">Total CDCs</div>
                    <div class="summary-box-value"><?php echo $total_cdcs; ?></div>
                </div>

                <div class="summary-box box-navy summary-box-clickable" onclick="openSummaryModal('cdws', 'Total CDWs')">
                    <div class="summary-box-header">Total CDWs</div>
                    <div class="summary-box-value"><?php echo $total_cdws; ?></div>
                </div>

                <div class="summary-box box-navy summary-box-clickable" onclick="openSummaryModal('children', 'Total Children')">
                    <div class="summary-box-header">Total Children</div>
                    <div class="summary-box-value"><?php echo $total_children; ?></div>
                </div>

                <div class="summary-box box-red summary-box-clickable" onclick="openSummaryModal('atrisk', 'At-Risk Children')">
                    <div class="summary-box-header">At-Risk Children</div>
                    <div class="summary-box-value"><?php echo $at_risk_children; ?></div>
                </div>

                <div class="summary-box box-green summary-box-clickable" onclick="openSummaryModal('reports', 'Submitted Reports')">
                    <div class="summary-box-header">Submitted Reports</div>
                    <div class="summary-box-value"><?php echo $submitted_reports; ?></div>
                </div>

                <div class="summary-box box-red summary-box-clickable" onclick="openSummaryModal('pending', 'Pending Reviews')">
                    <div class="summary-box-header">Pending Reviews</div>
                    <div class="summary-box-value"><?php echo $pending_reviews; ?></div>
                </div>
            </div>
        </div>

        <div class="panel-grid">
            <div class="panel-card">
                <h2 class="panel-title">Nutritional Status Summary</h2>

                <div class="nss-list">
                    <?php foreach ($status_summary as $status_key => $status_data):
                        $visual = getStatusVisual($status_key);
                        $children = $status_data['children'];
                    ?>
                        <div class="nss-row <?php echo $visual['tone']; ?>">
                            <div class="nss-head">
                                <div class="nss-title-group">
                                    <span class="nss-dot"></span>
                                    <h4><?php echo htmlspecialchars($status_key); ?></h4>
                                </div>
                                <div class="nss-count"><?php echo (int) $status_data['count']; ?></div>
                            </div>
                            <p class="nss-desc"><?php echo htmlspecialchars($visual['desc']); ?></p>

                            <?php if (!empty($children)): ?>
                                <div class="nss-children">
                                    <?php foreach ($children as $child): ?>
                                        <div class="nss-child-row">
                                            <span class="nss-child-dot"></span>
                                            <span class="nss-child-name"><?php echo htmlspecialchars($child['child_name']); ?></span>
                                            <span class="nss-child-cdc">— <?php echo htmlspecialchars($child['cdc_name']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="nss-empty">No children listed in this category.</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="panel-card">
                <h2 class="panel-title">Recent Reports</h2>

                <?php if (!empty($recent_reports)) { ?>
                    <div class="rr-list">
                        <?php foreach ($recent_reports as $report) { ?>
                            <div class="rr-row">
                                <div class="rr-top-row">
                                    <div>
                                        <div class="rr-title"><?php echo htmlspecialchars($report['title']); ?></div>
                                        <div class="rr-cdc"><?php echo htmlspecialchars($report['description']); ?></div>
                                    </div>
                                    <div class="rr-status <?php echo $report['status_pill_class']; ?>">
                                        <span class="rr-status-dot"></span><?php echo htmlspecialchars($report['status_label']); ?>
                                    </div>
                                </div>
                                <div class="rr-meta">
                                    <?php echo htmlspecialchars($report['submitted_by']); ?> · <?php echo htmlspecialchars($report['submitted_at']); ?>
                                </div>
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
                <h2 class="panel-title">Referral Program Summary</h2>

                <div class="rp-summary-grid">
                    <div class="rp-summary-item">
                        <div class="rp-summary-label">Total</div>
                        <div class="rp-summary-value"><?php echo $referral_total; ?></div>
                    </div>
                    <div class="rp-summary-item">
                        <div class="rp-summary-label">Pending</div>
                        <div class="rp-summary-value"><?php echo $referral_pending; ?></div>
                    </div>
                    <div class="rp-summary-item">
                        <div class="rp-summary-label">Completed</div>
                        <div class="rp-summary-value"><?php echo $referral_completed; ?></div>
                    </div>
                    <div class="rp-summary-item">
                        <div class="rp-summary-label">With Feedback</div>
                        <div class="rp-summary-value"><?php echo $referral_with_feedback; ?></div>
                    </div>
                    <div class="rp-summary-item">
                        <div class="rp-summary-label">No Response</div>
                        <div class="rp-summary-value"><?php echo $referral_no_response; ?></div>
                    </div>
                </div>

                <div class="alert-actions">
                    <a href="view_referral_monitoring.php" class="alert-action-link">View Referral Monitoring →</a>
                </div>
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

<!-- Summary box list modal (Total CDCs / CDWs / Children / At-Risk / Reports / Pending) -->
<div class="adm-modal-overlay" id="admSummaryModalOverlay" onclick="if(event.target === this){ closeSummaryModal(); }">
    <div class="adm-modal-box">
        <div class="adm-modal-header">
            <div>
                <div class="adm-modal-title" id="admSummaryModalTitle">List</div>
                <div class="adm-modal-subtitle" id="admSummaryModalSubtitle">0 item(s)</div>
            </div>
            <button type="button" class="adm-modal-close" onclick="closeSummaryModal()">&times;</button>
        </div>
        <div class="adm-modal-body" id="admSummaryModalBody">
            <!-- populated by JS -->
        </div>
    </div>
</div>

<script>
const summaryModalData = {
    cdcs: <?php echo json_encode(escapeModalList($modal_cdcs), JSON_UNESCAPED_UNICODE); ?>,
    cdws: <?php echo json_encode(escapeModalList($modal_cdws), JSON_UNESCAPED_UNICODE); ?>,
    children: <?php echo json_encode(escapeModalList($modal_children), JSON_UNESCAPED_UNICODE); ?>,
    atrisk: <?php echo json_encode(escapeModalList($modal_at_risk), JSON_UNESCAPED_UNICODE); ?>,
    reports: <?php echo json_encode(escapeModalList($modal_reports), JSON_UNESCAPED_UNICODE); ?>,
    pending: <?php echo json_encode(escapeModalList($modal_pending), JSON_UNESCAPED_UNICODE); ?>
};

function openSummaryModal(key, displayTitle) {
    const list = summaryModalData[key] || [];
    const overlay = document.getElementById('admSummaryModalOverlay');
    const titleEl = document.getElementById('admSummaryModalTitle');
    const subtitleEl = document.getElementById('admSummaryModalSubtitle');
    const bodyEl = document.getElementById('admSummaryModalBody');

    titleEl.textContent = displayTitle;
    subtitleEl.textContent = list.length + (list.length === 1 ? ' item' : ' items');

    if (list.length === 0) {
        bodyEl.innerHTML = '<div class="adm-modal-empty">Wala pang laman ang listahang ito.</div>';
    } else {
        let html = '';
        list.forEach(function (item) {
            html += '<div class="adm-modal-row">' +
                        '<div>' +
                            '<div class="adm-modal-row-primary">' + (item.primary || '—') + '</div>' +
                            (item.secondary ? '<div class="adm-modal-row-secondary">' + item.secondary + '</div>' : '') +
                        '</div>' +
                        (item.tag ? '<div class="adm-modal-row-tag ' + (item.tagTone || '') + '">' + item.tag + '</div>' : '') +
                    '</div>';
        });
        bodyEl.innerHTML = html;
    }

    overlay.classList.add('active');
}

function closeSummaryModal() {
    document.getElementById('admSummaryModalOverlay').classList.remove('active');
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        closeSummaryModal();
    }
});
</script>

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