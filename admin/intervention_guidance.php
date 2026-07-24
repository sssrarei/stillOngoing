<?php
include '../includes/auth.php';
include '../config/database.php';
include '../includes/intervention_helper.php';

if ($_SESSION['role_id'] != 1) {
    header("Location: ../login.php");
    exit();
}

function decodeReportPayload($payload) {
    if (is_array($payload)) {
        return $payload;
    }

    if (is_string($payload) && !empty($payload)) {
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    return [];
}

function getFinalRiskStatus($wfa_status, $hfa_status, $wflh_status) {
    $wfa_status = trim((string)$wfa_status);
    $hfa_status = trim((string)$hfa_status);
    $wflh_status = trim((string)$wflh_status);

    /*
    |--------------------------------------------------------------------------
    | Final Nutritional Status Priority
    |--------------------------------------------------------------------------
    | Wasted, underweight, overweight, and obese statuses are prioritized.
    | Stunted and Severely Stunted are only used if there is no other
    | nutritional risk status from WFA or WFL/H.
    |--------------------------------------------------------------------------
    */

    if ($wflh_status === 'Severely Wasted') {
        return 'Severely Wasted';
    }

    if ($wfa_status === 'Severely Underweight') {
        return 'Severely Underweight';
    }

    if ($wflh_status === 'Moderately Wasted' || $wflh_status === 'Wasted') {
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

function determineFinalInterventionCategory($wfa_status, $hfa_status, $wflh_status) {
    $wfa_status = trim((string)$wfa_status);
    $hfa_status = trim((string)$hfa_status);
    $wflh_status = trim((string)$wflh_status);

    /*
    |--------------------------------------------------------------------------
    | Intervention Guidance Category
    |--------------------------------------------------------------------------
    | Only categories that need intervention guidance are returned.
    | Stunted and Severely Stunted alone are not included here.
    |--------------------------------------------------------------------------
    */

    if ($wflh_status === 'Severely Wasted') {
        return 'Severely Wasted';
    }

    if ($wfa_status === 'Severely Underweight') {
        return 'Severely Wasted';
    }

    if ($wflh_status === 'Moderately Wasted' || $wflh_status === 'Wasted') {
        return 'Moderately Wasted';
    }

    if ($wfa_status === 'Underweight') {
        return 'Moderately Wasted';
    }

    if ($wflh_status === 'Obese') {
        return 'Obese';
    }

    if ($wflh_status === 'Overweight') {
        return 'Overweight';
    }

    return null;
}

/* =========================================================
   REPORT-BASED INTERVENTION GUIDANCE HELPERS
   Purpose:
   - Get recent submitted WMR reports
   - Read at-risk children from selected report
   - Check sent status per child
   - Check warning sequence for Baseline/Midline
========================================================= */

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function getFirstValue($row, $keys, $fallback = '') {
    if (!is_array($row)) {
        return $fallback;
    }

    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return $row[$key];
        }
    }

    return $fallback;
}

function normalizeAssessmentType($assessment_type) {
    $assessment_type = strtolower(trim((string)$assessment_type));

    if ($assessment_type === 'baseline') {
        return 'baseline';
    }

    if ($assessment_type === 'midline') {
        return 'midline';
    }

    if ($assessment_type === 'endline') {
        return 'endline';
    }

    if ($assessment_type === 'monthly_followup' || $assessment_type === 'monthly followup' || $assessment_type === 'monthly_follow-up') {
        return 'monthly_followup';
    }

    return $assessment_type;
}

function getAssessmentLabel($assessment_type) {
    $assessment_type = normalizeAssessmentType($assessment_type);

    if ($assessment_type === 'baseline') {
        return 'Baseline';
    }

    if ($assessment_type === 'midline') {
        return 'Midline';
    }

    if ($assessment_type === 'endline') {
        return 'Endline';
    }

    if ($assessment_type === 'monthly_followup') {
        return 'Monthly Follow-up';
    }

    return $assessment_type !== '' ? ucfirst($assessment_type) : 'N/A';
}

function getOriginalStatusForCategory($row, $intervention_category, $is_terminal_report = false) {
    if ($is_terminal_report) {
        $wfa_status = getFirstValue($row, ['endline_wfa_status', 'endline_wfa', 'wfa_status', 'wfa'], '');
        $hfa_status = getFirstValue($row, ['endline_hfa_status', 'endline_hfa', 'hfa_status', 'hfa'], '');
        $wflh_status = getFirstValue($row, ['endline_wflh_status', 'endline_wflh', 'endline_wfh_status', 'endline_wfh', 'wflh_status', 'wflh', 'wfh_status', 'wfh'], '');
    } else {
        $wfa_status = getFirstValue($row, ['wfa_status', 'wfa'], '');
        $hfa_status = getFirstValue($row, ['hfa_status', 'hfa'], '');
        $wflh_status = getFirstValue($row, ['wflh_status', 'wflh', 'wfh_status', 'wfh'], '');
    }

    if ($intervention_category === 'Moderately Wasted') {
        if ($wflh_status === 'Moderately Wasted' || $wflh_status === 'Wasted') {
            return $wflh_status;
        }

        if ($wfa_status === 'Underweight') {
            return 'Underweight';
        }
    }

    if ($intervention_category === 'Severely Wasted') {
        if ($wflh_status === 'Severely Wasted') {
            return 'Severely Wasted';
        }

        if ($wfa_status === 'Severely Underweight') {
            return 'Severely Underweight';
        }
    }

    if ($intervention_category === 'Overweight') {
        return $wflh_status !== '' ? $wflh_status : 'Overweight';
    }

    if ($intervention_category === 'Obese') {
        return $wflh_status !== '' ? $wflh_status : 'Obese';
    }

    $final_status = getFinalRiskStatus($wfa_status, $hfa_status, $wflh_status);
    return $final_status !== '' ? $final_status : 'N/A';
}

function getChildNameFromReportRow($row) {
    $full_name = getFirstValue($row, [
        'child_name',
        'full_name',
        'name',
        'beneficiary_name'
    ], '');

    if ($full_name !== '') {
        return $full_name;
    }

    $first = getFirstValue($row, ['first_name', 'child_first_name'], '');
    $middle = getFirstValue($row, ['middle_name', 'child_middle_name'], '');
    $last = getFirstValue($row, ['last_name', 'child_last_name'], '');

    $parts = [];

    if ($first !== '') {
        $parts[] = $first;
    }

    if ($middle !== '') {
        $parts[] = $middle;
    }

    if ($last !== '') {
        $parts[] = $last;
    }

    $name = trim(implode(' ', $parts));

    return $name !== '' ? $name : 'N/A';
}

function extractSubmittedReportRows($payload) {
    $payload = decodeReportPayload($payload);

    $candidate_keys = [
        'submitted_rows',
        'rows',
        'report_rows',
        'records',
        'report_data',
        'data',
        'entries',
        'items'
    ];

    foreach ($candidate_keys as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return array_values($payload[$key]);
        }
    }

    $is_list = is_array($payload) && array_keys($payload) === range(0, count($payload) - 1);

    if ($is_list) {
        return $payload;
    }

    return [];
}

function getReportAssessmentType($report, $rows) {
    $payload = decodeReportPayload(isset($report['report_payload']) ? $report['report_payload'] : '');

    $payload_assessment_type = getFirstValue($payload, [
        'assessment_type',
        'assessment',
        'report_assessment_type'
    ], '');

    if ($payload_assessment_type !== '') {
        return normalizeAssessmentType($payload_assessment_type);
    }

    if (!empty($rows)) {
        foreach ($rows as $row) {
            $row_assessment_type = getFirstValue($row, ['assessment_type', 'assessment'], '');

            if ($row_assessment_type !== '') {
                return normalizeAssessmentType($row_assessment_type);
            }
        }
    }

    return '';
}

function hasSentGuidanceForReport($conn, $child_id, $submitted_report_id, $intervention_category) {
    $sql = "
        SELECT guidance_id
        FROM intervention_guidance
        WHERE child_id = ?
          AND submitted_report_id = ?
          AND intervention_category = ?
          AND sent_to_guardian = 1
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("iis", $child_id, $submitted_report_id, $intervention_category);
    $stmt->execute();
    $result = $stmt->get_result();
    $has_sent = $result && $result->num_rows > 0;
    $stmt->close();

    return $has_sent;
}

function hasSentGuidanceByAssessment($conn, $child_id, $assessment_type) {
    $assessment_type = strtolower(trim((string)$assessment_type));

    if ($assessment_type === 'baseline') {
        $pattern = '%Baseline%';
    } elseif ($assessment_type === 'midline') {
        $pattern = '%Midline%';
    } elseif ($assessment_type === 'endline') {
        $pattern = '%Endline%';
    } else {
        return false;
    }

    $sql = "
        SELECT guidance_id
        FROM intervention_guidance
        WHERE child_id = ?
          AND sent_to_guardian = 1
          AND is_reviewed = 1
          AND status_note LIKE ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("is", $child_id, $pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $has_sent = $result && $result->num_rows > 0;
    $stmt->close();

    return $has_sent;
}

function getSequenceWarning($conn, $child_id, $assessment_type) {
    $assessment_type = normalizeAssessmentType($assessment_type);

    if ($assessment_type === 'baseline') {
        return '';
    }

    if ($assessment_type === 'midline') {
        $has_baseline = hasSentGuidanceByAssessment($conn, $child_id, 'baseline');

        if (!$has_baseline) {
            return 'Warning: You are generating a Midline Follow-up Reminder without a previously sent Baseline Intervention Guidance for this child. Baseline is normally generated first before Midline. Do you still want to continue?';
        }

        return '';
    }

    if ($assessment_type === 'endline') {
        $has_baseline = hasSentGuidanceByAssessment($conn, $child_id, 'baseline');
        $has_midline = hasSentGuidanceByAssessment($conn, $child_id, 'midline');

        if (!$has_baseline && !$has_midline) {
            return 'Warning: You are generating an Endline Final Follow-up Reminder without previously sent Baseline and Midline guidance. The recommended sequence is Baseline → Midline → Endline. Do you still want to continue?';
        }

        if (!$has_midline) {
            return 'Warning: You are generating a Final Follow-up Reminder without a previously sent Midline Follow-up Reminder for this child. Midline is normally generated before Endline. Do you still want to continue?';
        }

        if (!$has_baseline) {
            return 'Warning: You are generating an Endline Final Follow-up Reminder without a previously sent Baseline Intervention Guidance for this child. Baseline is normally generated before Endline. Do you still want to continue?';
        }

        return '';
    }

    return '';
}

function getAlertTypeFromAssessment($assessment_type) {
    $assessment_type = normalizeAssessmentType($assessment_type);

    if ($assessment_type === 'baseline') {
        return 'nutritional_alert';
    }

    if ($assessment_type === 'midline') {
        return 'follow_up_reminder';
    }

    if ($assessment_type === 'endline') {
        return 'final_follow_up_reminder';
    }

    return 'nutritional_alert';
}

function getRecentSubmittedReportsForGuidance($conn, $limit = 10, $offset = 0, $filter_month = '', $filter_year = '', $filter_assessment = '', $filter_cdc = 0) {
    $reports = [];

    $sql = "
        SELECT
            sr.submitted_report_id,
            sr.report_type,
            sr.cdc_id,
            sr.submitted_by,
            sr.date_from,
            sr.date_to,
            sr.submitted_at,
            sr.status,
            sr.report_payload,
            c.cdc_name
        FROM submitted_reports sr
        LEFT JOIN cdc c ON sr.cdc_id = c.cdc_id
        WHERE LOWER(sr.report_type) IN ('wmr', 'terminal_report')
    ";

    $types = "";
    $params = [];

    if ($filter_month !== '') {
        $sql .= " AND MONTH(sr.submitted_at) = ? ";
        $types .= "i";
        $params[] = (int)$filter_month;
    }

    if ($filter_year !== '') {
        $sql .= " AND YEAR(sr.submitted_at) = ? ";
        $types .= "i";
        $params[] = (int)$filter_year;
    }

    if ($filter_cdc > 0) {
        $sql .= " AND sr.cdc_id = ? ";
        $types .= "i";
        $params[] = (int)$filter_cdc;
    }

    if ($filter_assessment === 'endline') {
        $sql .= " AND LOWER(sr.report_type) = 'terminal_report' ";
    } elseif ($filter_assessment === 'baseline' || $filter_assessment === 'midline') {
        $sql .= " AND LOWER(sr.report_type) = 'wmr' ";
    }

    $sql .= "
    ORDER BY sr.submitted_at DESC, sr.submitted_report_id DESC
    LIMIT ? OFFSET ?
";

$types .= "ii";
$params[] = (int)$limit;
$params[] = (int)$offset;

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return $reports;
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($report = $result->fetch_assoc()) {
        $rows = extractSubmittedReportRows($report['report_payload']);
        $is_terminal_report = strtolower(trim((string)$report['report_type'])) === 'terminal_report';

        $assessment_type = getReportAssessmentType($report, $rows);

        if ($is_terminal_report) {
            $assessment_type = 'endline';
        }

        if (
            ($filter_assessment === 'baseline' || $filter_assessment === 'midline') &&
            $assessment_type !== $filter_assessment
        ) {
            continue;
        }

        $at_risk_count = 0;
        $sent_count = 0;

        foreach ($rows as $row) {
            $child_id = (int)getFirstValue($row, ['child_id'], 0);

            if ($child_id <= 0) {
                continue;
            }

            if ($is_terminal_report) {
                $wfa_status = getFirstValue($row, ['endline_wfa_status', 'endline_wfa', 'wfa_status', 'wfa'], '');
                $hfa_status = getFirstValue($row, ['endline_hfa_status', 'endline_hfa', 'hfa_status', 'hfa'], '');
                $wflh_status = getFirstValue($row, ['endline_wflh_status', 'endline_wflh', 'endline_wfh_status', 'endline_wfh', 'wflh_status', 'wflh', 'wfh_status', 'wfh'], '');
            } else {
                $wfa_status = getFirstValue($row, ['wfa_status', 'wfa'], '');
                $hfa_status = getFirstValue($row, ['hfa_status', 'hfa'], '');
                $wflh_status = getFirstValue($row, ['wflh_status', 'wflh', 'wfh_status', 'wfh'], '');
            }

            $category = determineFinalInterventionCategory(
                $wfa_status,
                $hfa_status,
                $wflh_status
            );

            if ($category === null) {
                continue;
            }

            $at_risk_count++;

            if (hasSentGuidanceForReport($conn, $child_id, (int)$report['submitted_report_id'], $category)) {
                $sent_count++;
            }
        }

        if ($at_risk_count === 0) {
            $guidance_status = 'No At-Risk Found';
        } elseif ($sent_count === 0) {
            $guidance_status = $is_terminal_report ? 'Pending Final Reminder' : 'Pending Guidance';
        } elseif ($sent_count < $at_risk_count) {
            $guidance_status = 'Partially Sent';
        } else {
            $guidance_status = 'Already Generated';
        }

        $reports[] = [
            'submitted_report_id' => (int)$report['submitted_report_id'],
            'report_type' => $report['report_type'],
            'cdc_id' => (int)$report['cdc_id'],
            'cdc_name' => $report['cdc_name'],
            'submitted_at' => $report['submitted_at'],
            'assessment_type' => $assessment_type,
            'assessment_label' => getAssessmentLabel($assessment_type),
            'at_risk_count' => $at_risk_count,
            'sent_count' => $sent_count,
            'guidance_status' => $guidance_status
        ];
    }

    $stmt->close();

    return $reports;
}

function getSubmittedReportById($conn, $submitted_report_id) {
    $sql = "
        SELECT
            sr.submitted_report_id,
            sr.report_type,
            sr.cdc_id,
            sr.submitted_by,
            sr.date_from,
            sr.date_to,
            sr.submitted_at,
            sr.status,
            sr.report_payload,
            c.cdc_name
        FROM submitted_reports sr
        LEFT JOIN cdc c ON sr.cdc_id = c.cdc_id
        WHERE sr.submitted_report_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $submitted_report_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $report = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $report;
}

function getAtRiskChildrenFromSubmittedReport($conn, $submitted_report_id) {
    $report = getSubmittedReportById($conn, $submitted_report_id);

    if (!$report) {
        return [];
    }

    $rows = extractSubmittedReportRows($report['report_payload']);
    $report_assessment_type = getReportAssessmentType($report, $rows);
    $is_terminal_report = strtolower(trim((string)$report['report_type'])) === 'terminal_report';

    if ($is_terminal_report) {
        $report_assessment_type = 'endline';
    }

    $children = [];

    foreach ($rows as $row) {
        $child_id = (int)getFirstValue($row, ['child_id'], 0);

        if ($child_id <= 0) {
            continue;
        }

        if ($is_terminal_report) {
            $wfa_status = getFirstValue($row, ['endline_wfa_status', 'endline_wfa', 'wfa_status', 'wfa'], '');
            $hfa_status = getFirstValue($row, ['endline_hfa_status', 'endline_hfa', 'hfa_status', 'hfa'], '');
            $wflh_status = getFirstValue($row, ['endline_wflh_status', 'endline_wflh', 'endline_wfh_status', 'endline_wfh', 'wflh_status', 'wflh', 'wfh_status', 'wfh'], '');
            $assessment_type = 'endline';
        } else {
            $wfa_status = getFirstValue($row, ['wfa_status', 'wfa'], '');
            $hfa_status = getFirstValue($row, ['hfa_status', 'hfa'], '');
            $wflh_status = getFirstValue($row, ['wflh_status', 'wflh', 'wfh_status', 'wfh'], '');

            $assessment_type = getFirstValue($row, ['assessment_type', 'assessment'], $report_assessment_type);
            $assessment_type = normalizeAssessmentType($assessment_type);
        }

        $intervention_category = determineFinalInterventionCategory(
            $wfa_status,
            $hfa_status,
            $wflh_status
        );

        if ($intervention_category === null) {
            continue;
        }

        $sent_status = hasSentGuidanceForReport(
            $conn,
            $child_id,
            (int)$submitted_report_id,
            $intervention_category
        ) ? 'Already Sent' : 'Not Yet Sent';

        $children[] = [
            'submitted_report_id' => (int)$submitted_report_id,
            'record_id' => (int)getFirstValue($row, ['record_id'], 0),
            'child_id' => $child_id,
            'child_name' => getChildNameFromReportRow($row),
            'cdc_id' => (int)$report['cdc_id'],
            'cdc_name' => $report['cdc_name'],
            'date_recorded' => $is_terminal_report
                ? getFirstValue($row, ['endline_date'], '')
                : getFirstValue($row, ['date_recorded', 'measurement_date'], ''),
            'age_months' => getFirstValue($row, ['age_months', 'age_in_months'], ''),
            'height' => getFirstValue($row, ['height'], ''),
            'weight' => getFirstValue($row, ['weight'], ''),
            'muac' => getFirstValue($row, ['muac'], ''),
            'hfa_status' => $hfa_status,
            'wfa_status' => $wfa_status,
            'wflh_status' => $wflh_status,
            'original_status' => getOriginalStatusForCategory($row, $intervention_category, $is_terminal_report),
            'intervention_category' => $intervention_category,
            'assessment_type' => $assessment_type,
            'assessment_label' => getAssessmentLabel($assessment_type),
            'sent_status' => $sent_status,
            'sequence_warning' => getSequenceWarning($conn, $child_id, $assessment_type)
        ];
    }

    return $children;
}
   

function getLatestSubmittedWMRRowsPerCDC($conn, $selected_cdc = 0) {
    $sql = "SELECT submitted_report_id, cdc_id, submitted_at, report_payload
            FROM submitted_reports
            WHERE LOWER(report_type) = 'wmr'
            ORDER BY cdc_id ASC, submitted_at DESC, submitted_report_id DESC";

    $result = $conn->query($sql);

    $latest_reports = [];
    $rows = [];

    if ($result && $result->num_rows > 0) {
        while ($report = $result->fetch_assoc()) {
            $cdc_id = (int)$report['cdc_id'];

            if ($selected_cdc > 0 && $cdc_id !== $selected_cdc) {
                continue;
            }

            if (isset($latest_reports[$cdc_id])) {
                continue;
            }

            $latest_reports[$cdc_id] = $report;

            $payload = decodeReportPayload($report['report_payload']);
            $submitted_rows = isset($payload['submitted_rows']) && is_array($payload['submitted_rows'])
                ? $payload['submitted_rows']
                : [];

            foreach ($submitted_rows as $row) {
                $row['submitted_report_id'] = $report['submitted_report_id'];
                $row['cdc_id'] = $cdc_id;
                $row['submitted_at'] = $report['submitted_at'];
                $rows[] = $row;
            }
        }
    }

    return $rows;
}

function getChildSubmittedWMRHistory($conn, $child_id, $cdc_id) {
    $sql = "SELECT submitted_report_id, submitted_at, report_payload
            FROM submitted_reports
            WHERE LOWER(report_type) = 'wmr' AND cdc_id = ?
            ORDER BY submitted_at ASC, submitted_report_id ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $cdc_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $history = [];

    while ($report = $result->fetch_assoc()) {
        $payload = decodeReportPayload($report['report_payload']);
        $submitted_rows = isset($payload['submitted_rows']) && is_array($payload['submitted_rows'])
            ? $payload['submitted_rows']
            : [];

        foreach ($submitted_rows as $row) {
            if (!isset($row['child_id']) || (int)$row['child_id'] !== (int)$child_id) {
                continue;
            }

          $final_category = determineFinalInterventionCategory(
                isset($row['wfa_status']) ? $row['wfa_status'] : '',
                isset($row['hfa_status']) ? $row['hfa_status'] : '',
                isset($row['wflh_status']) ? $row['wflh_status'] : ''
            );

            if ($final_category !== null) {
                $history[] = [
                    'date_recorded' => isset($row['date_recorded']) ? $row['date_recorded'] : $report['submitted_at'],
                    'assessment_type' => isset($row['assessment_type']) ? strtolower(trim($row['assessment_type'])) : '',
                    'intervention_category' => $final_category
                ];
            }

            break;
        }
    }

    return $history;
}

$message = '';
$message_type = '';

function getPreviousSubmittedCategory($history, $current_date_recorded) {
    if (!is_array($history) || empty($history)) {
        return null;
    }

    usort($history, function ($a, $b) {
        return strtotime($a['date_recorded']) <=> strtotime($b['date_recorded']);
    });

    $previous_category = null;
    $current_ts = strtotime($current_date_recorded);

    foreach ($history as $item) {
        if (empty($item['date_recorded'])) {
            continue;
        }

        $item_ts = strtotime($item['date_recorded']);

        if ($current_ts && $item_ts < $current_ts) {
            $previous_category = isset($item['intervention_category']) ? $item['intervention_category'] : null;
        }
    }

    return $previous_category;
}

$selected_cdc = isset($_GET['cdc_id']) ? intval($_GET['cdc_id']) : 0;
$selected_category = isset($_GET['category']) ? trim($_GET['category']) : '';
$show_preview = false;
$preview_children = [];
$preview_guidance_rules = [];
$preview_guidance_text = '';
$preview_guidance_rules_by_category = [];
$optional_note = '';
$selected_report_id = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
$recent_reports = [];
$report_children = [];
$selected_report = null;
$filter_month = isset($_REQUEST['month']) ? trim($_REQUEST['month']) : '';
$filter_year = isset($_REQUEST['year']) ? trim($_REQUEST['year']) : '';
$filter_assessment = isset($_REQUEST['assessment_type']) ? trim($_REQUEST['assessment_type']) : '';
$filter_cdc = isset($_REQUEST['filter_cdc_id']) ? (int)$_REQUEST['filter_cdc_id'] : 0;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 5;
$offset = ($page - 1) * $limit;


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

 if (isset($_POST['preview_selected_guidance'])) {
        $selected_report_id = isset($_POST['submitted_report_id']) ? (int)$_POST['submitted_report_id'] : 0;
        $selected_values = isset($_POST['selected_children']) && is_array($_POST['selected_children'])
            ? $_POST['selected_children']
            : [];

        if ($selected_report_id <= 0) {
            $message = 'No submitted report selected.';
            $message_type = 'error';
        } elseif (empty($selected_values)) {
            $message = 'Please select at least one child before generating guidance.';
            $message_type = 'error';
        } else {
            $all_report_children = getAtRiskChildrenFromSubmittedReport($conn, $selected_report_id);

            $selected_lookup = [];
            foreach ($selected_values as $value) {
                $selected_lookup[trim((string)$value)] = true;
            }

            foreach ($all_report_children as $child) {
                $child_key = $child['child_id'] . '|' . $child['intervention_category'];

                if (!isset($selected_lookup[$child_key])) {
                    continue;
                }

                if ($child['sent_status'] === 'Already Sent') {
                    continue;
                }

                $preview_children[] = $child;

                $category = $child['intervention_category'];
                if (!isset($preview_guidance_rules_by_category[$category])) {
                    $preview_guidance_rules_by_category[$category] = getInterventionGuidanceRules($category);
                }
            }

            if (empty($preview_children)) {
                $message = 'No valid pending children were selected. Already sent children cannot be generated again for the same report.';
                $message_type = 'error';
            } else {
                $show_preview = true;
            }
        }
    }

            if (isset($_POST['confirm_selected_guidance'])) {
        $selected_report_id = isset($_POST['submitted_report_id']) ? (int)$_POST['submitted_report_id'] : 0;
        $selected_values = isset($_POST['selected_children']) && is_array($_POST['selected_children'])
            ? $_POST['selected_children']
            : [];

        $optional_notes_by_category = isset($_POST['optional_note_by_category']) && is_array($_POST['optional_note_by_category'])
    ? $_POST['optional_note_by_category']
    : [];
        $reviewed_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

        if ($selected_report_id <= 0) {
            $message = 'No submitted report selected.';
            $message_type = 'error';
        } elseif (empty($selected_values)) {
            $message = 'No selected children found for sending.';
            $message_type = 'error';
        } else {
            $all_report_children = getAtRiskChildrenFromSubmittedReport($conn, $selected_report_id);

            $selected_lookup = [];
            foreach ($selected_values as $value) {
                $selected_lookup[trim((string)$value)] = true;
            }

            $children_to_save = [];

            foreach ($all_report_children as $child) {
                $child_key = $child['child_id'] . '|' . $child['intervention_category'];

                if (!isset($selected_lookup[$child_key])) {
                    continue;
                }

                if ($child['sent_status'] === 'Already Sent') {
                    continue;
                }

                $children_to_save[] = $child;
            }

            if (empty($children_to_save)) {
                $message = 'No valid pending children were selected. Already sent children cannot be generated again for the same report.';
                $message_type = 'error';
            } else {
                $saved_count = 0;

                foreach ($children_to_save as $child) {
                    $child_id = (int)$child['child_id'];
                    $submitted_report_id = (int)$child['submitted_report_id'];
                    $record_id = isset($child['record_id']) ? (int)$child['record_id'] : 0;

                    $intervention_category = trim((string)$child['intervention_category']);
                    $assessment_type = strtolower(trim((string)$child['assessment_type']));
                    $original_status = trim((string)$child['original_status']);

                    $optional_note = isset($optional_notes_by_category[$intervention_category])
                    ? trim((string)$optional_notes_by_category[$intervention_category])
                    : '';

                    $rules = getInterventionGuidanceRules($intervention_category);
                    $guidance_text = buildGuidanceText($rules);

                    $alert_type = getAlertTypeFromAssessment($assessment_type);
                    $flags = getGuidanceFlags($alert_type);

                    $is_at_risk = isset($flags['is_at_risk']) ? (int)$flags['is_at_risk'] : 1;
                    $needs_counseling = isset($flags['needs_counseling']) ? (int)$flags['needs_counseling'] : 0;
                    $needs_referral = isset($flags['needs_referral']) ? (int)$flags['needs_referral'] : 0;

                    if ($assessment_type === 'baseline') {
                        $status_note = 'Nutritional Alert: Child identified as at-risk based on Baseline assessment.';
                    } elseif ($assessment_type === 'midline') {
                        $status_note = 'Follow-up Reminder: Child still needs nutritional attention based on Midline assessment.';
                    } elseif ($assessment_type === 'endline') {
                        $status_note = 'Final Follow-up Reminder: Child still needs nutritional attention based on Endline assessment.';
                    } else {
                        $status_note = 'Nutritional Alert: Child identified as at-risk from submitted report.';
                    }

                    $check_sql = "
                        SELECT guidance_id
                        FROM intervention_guidance
                        WHERE child_id = ?
                          AND submitted_report_id = ?
                          AND intervention_category = ?
                        LIMIT 1
                    ";

                    $check_stmt = $conn->prepare($check_sql);

                    if (!$check_stmt) {
                        continue;
                    }

                    $check_stmt->bind_param("iis", $child_id, $submitted_report_id, $intervention_category);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();

                    if ($existing = $check_result->fetch_assoc()) {
                        $guidance_id = (int)$existing['guidance_id'];

                        $update_sql = "
                            UPDATE intervention_guidance
                            SET record_id = ?,
                                original_status = ?,
                                guidance_text = ?,
                                optional_note = ?,
                                is_at_risk = ?,
                                needs_counseling = ?,
                                needs_referral = ?,
                                reviewed_by = ?,
                                is_reviewed = 1,
                                sent_to_guardian = 1,
                                sent_at = NOW(),
                                resend_count = resend_count + 1,
                                status_note = ?,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE guidance_id = ?
                        ";

                        $update_stmt = $conn->prepare($update_sql);

                        if ($update_stmt) {
                            $update_stmt->bind_param(
                                "isssiiiisi",
                                $record_id,
                                $original_status,
                                $guidance_text,
                                $optional_note,
                                $is_at_risk,
                                $needs_counseling,
                                $needs_referral,
                                $reviewed_by,
                                $status_note,
                                $guidance_id
                            );

                            if ($update_stmt->execute()) {
                                $saved_count++;
                            }

                            $update_stmt->close();
                        }
                    } else {
                        $insert_sql = "
                            INSERT INTO intervention_guidance (
                                child_id,
                                record_id,
                                submitted_report_id,
                                original_status,
                                intervention_category,
                                guidance_text,
                                optional_note,
                                is_at_risk,
                                needs_counseling,
                                needs_referral,
                                reviewed_by,
                                is_reviewed,
                                sent_to_guardian,
                                sent_at,
                                status_note
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW(), ?)
                        ";

                        $insert_stmt = $conn->prepare($insert_sql);

                        if ($insert_stmt) {
                            $insert_stmt->bind_param(
                                "iiissssiiiis",
                                $child_id,
                                $record_id,
                                $submitted_report_id,
                                $original_status,
                                $intervention_category,
                                $guidance_text,
                                $optional_note,
                                $is_at_risk,
                                $needs_counseling,
                                $needs_referral,
                                $reviewed_by,
                                $status_note
                            );

                            if ($insert_stmt->execute()) {
                                $saved_count++;
                            }

                            $insert_stmt->close();
                        }
                    }

                    $check_stmt->close();
                }

                if ($saved_count > 0) {
                    $message = $saved_count . ' intervention guidance/reminder(s) sent to guardian successfully.';
                    $message_type = 'success';
                } else {
                    $message = 'No intervention guidance/reminders were sent.';
                    $message_type = 'error';
                }
            }
        }
    }

    if (isset($_POST['preview_batch'])) {
        $selected_cdc = isset($_POST['cdc_id']) ? intval($_POST['cdc_id']) : 0;
        $selected_category = isset($_POST['category']) ? trim($_POST['category']) : '';

        if (!empty($selected_category)) {
            $show_preview = true;
        }
    }

    if (isset($_POST['confirm_batch'])) {
        $selected_cdc = isset($_POST['cdc_id']) ? intval($_POST['cdc_id']) : 0;
        $selected_category = isset($_POST['category']) ? trim($_POST['category']) : '';
        $optional_note = isset($_POST['optional_note']) ? trim($_POST['optional_note']) : '';
        $reviewed_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

        $latest_wmr_rows = getLatestSubmittedWMRRowsPerCDC($conn, $selected_cdc);
        $children_to_save = [];

        foreach ($latest_wmr_rows as $row) {
            $final_category = determineFinalInterventionCategory(
                isset($row['wfa_status']) ? $row['wfa_status'] : '',
                isset($row['hfa_status']) ? $row['hfa_status'] : '',
                isset($row['wflh_status']) ? $row['wflh_status'] : ''
            );

            if ($final_category !== $selected_category) {
                continue;
            }

            $row['intervention_category'] = $final_category;
            $children_to_save[] = $row;
        }

        if (!empty($children_to_save)) {
            $rules = getInterventionGuidanceRules($selected_category);
            $guidance_text = buildGuidanceText($rules);
            $saved_count = 0;

           foreach ($children_to_save as $child) {
            $child_id = isset($child['child_id']) ? (int)$child['child_id'] : 0;
            $record_id = isset($child['record_id']) ? (int)$child['record_id'] : 0;

            $wmr_record_id = isset($child['submitted_report_id']) 
                ? (int)$child['submitted_report_id'] 
                : $record_id;

            $submitted_report_id = isset($child['submitted_report_id']) ? (int)$child['submitted_report_id'] : null;
            $original_status = '';

                if ($selected_category === 'Moderately Wasted') {
                    if (isset($child['wflh_status']) && $child['wflh_status'] === 'Moderately Wasted') {
                        $original_status = 'Moderately Wasted';
                    } elseif (isset($child['wfa_status']) && $child['wfa_status'] === 'Underweight') {
                        $original_status = 'Underweight';
                    }
                } elseif ($selected_category === 'Severely Wasted') {
                    if (isset($child['wflh_status']) && $child['wflh_status'] === 'Severely Wasted') {
                        $original_status = 'Severely Wasted';
                    } elseif (isset($child['wfa_status']) && $child['wfa_status'] === 'Severely Underweight') {
                        $original_status = 'Severely Underweight';
                    }
                } elseif ($selected_category === 'Overweight') {
                    $original_status = isset($child['wflh_status']) ? $child['wflh_status'] : 'Overweight';
                } elseif ($selected_category === 'Obese') {
                    $original_status = isset($child['wflh_status']) ? $child['wflh_status'] : 'Obese';
                }

                $history = getChildSubmittedWMRHistory($conn, $child_id, (int)$child['cdc_id']);

                    $current_assessment_type = isset($child['assessment_type'])
                        ? strtolower(trim($child['assessment_type']))
                        : '';

                    $current_date_recorded = isset($child['date_recorded'])
                        ? $child['date_recorded']
                        : date('Y-m-d');

                    $current_category = $selected_category;
                    $previous_category = getPreviousSubmittedCategory($history, $current_date_recorded);
                    $progress_status = getInterventionProgressStatus($previous_category, $current_category);

                    $alert_type = getGuidanceAlertType(
                        $current_assessment_type,
                        $progress_status,
                        $current_category
                    );

                    $flags = getGuidanceFlags($alert_type);

                    $is_at_risk = $flags['is_at_risk'];
                    $needs_counseling = $flags['needs_counseling'];
                    $needs_referral = $flags['needs_referral'];
                    $status_note = buildGuidanceStatusNote($alert_type, $current_assessment_type, $progress_status);

                $check_sql = "SELECT guidance_id
                    FROM intervention_guidance
                    WHERE child_id = ?
                    AND intervention_category = ?
                    AND submitted_report_id = ?
                    LIMIT 1";

                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("isi", $child_id, $selected_category, $submitted_report_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();

                if ($check_result->num_rows > 0) {
                    $existing = $check_result->fetch_assoc();
                    $guidance_id = (int)$existing['guidance_id'];

                    $update_sql = "UPDATE intervention_guidance
                    SET submitted_report_id = ?,
                        wmr_record_id = ?,
                        original_status = ?,
                        guidance_text = ?,
                        optional_note = ?,
                        is_at_risk = ?,
                        needs_counseling = ?,
                        needs_referral = ?,
                        reviewed_by = ?,
                        is_reviewed = 1,
                        sent_to_guardian = 1,
                        sent_at = NOW(),
                        resend_count = resend_count + 1,
                        status_note = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE guidance_id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param(
                        "iisssiiiisi",
                        $submitted_report_id,
                        $wmr_record_id,
                        $original_status,
                        $guidance_text,
                        $optional_note,
                        $is_at_risk,
                        $needs_counseling,
                        $needs_referral,
                        $reviewed_by,
                        $status_note,
                        $guidance_id
                    );

                    if ($update_stmt->execute()) {
                        $saved_count++;
                    }
                } else {
                    $insert_sql = "INSERT INTO intervention_guidance (
                                        child_id,
                                        record_id,
                                        submitted_report_id,
                                        original_status,
                                        intervention_category,
                                        guidance_text,
                                        optional_note,
                                        is_at_risk,
                                        needs_counseling,
                                        needs_referral,
                                        reviewed_by,
                                        is_reviewed,
                                        sent_to_guardian,
                                        sent_at,
                                        status_note
                                   ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, NOW(), ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param(
                        "iiissssiiiis",
                        $child_id,
                        $record_id,
                        $submitted_report_id,
                        $original_status,
                        $selected_category,
                        $guidance_text,
                        $optional_note,
                        $is_at_risk,
                        $needs_counseling,
                        $needs_referral,
                        $reviewed_by,
                        $status_note
                    );

                    if ($insert_stmt->execute()) {
                        $saved_count++;
                    }
                }
            }

            if ($saved_count > 0) {
                $message = 'Batch intervention guidance saved and sent to guardian successfully.';
                $message_type = 'success';
            } else {
                $message = 'No intervention guidance records were saved.';
                $message_type = 'error';
            }
        } else {
            $message = 'No children found under the selected category from the latest submitted WMR.';
            $message_type = 'error';
        }
    }
}

$cdc_list = [];
$cdc_sql = "SELECT cdc_id, cdc_name FROM cdc WHERE status = 'Active' ORDER BY cdc_name ASC";
$cdc_result = $conn->query($cdc_sql);
if ($cdc_result && $cdc_result->num_rows > 0) {
    while ($row = $cdc_result->fetch_assoc()) {
        $cdc_list[] = $row;
    }
}

$latest_wmr_rows = getLatestSubmittedWMRRowsPerCDC($conn, $selected_cdc);

$processed_rows = [];
$count_mw = 0;
$count_sw = 0;
$count_ow = 0;
$count_ob = 0;

foreach ($latest_wmr_rows as $row) {
    $final_category = determineFinalInterventionCategory(
        isset($row['wfa_status']) ? $row['wfa_status'] : '',
        isset($row['hfa_status']) ? $row['hfa_status'] : '',
        isset($row['wflh_status']) ? $row['wflh_status'] : ''
    );

    if ($final_category === null) {
        continue;
    }

    $row['intervention_category'] = $final_category;
    $processed_rows[] = $row;

    if ($final_category === 'Moderately Wasted') {
        $count_mw++;
    } elseif ($final_category === 'Severely Wasted') {
        $count_sw++;
    } elseif ($final_category === 'Overweight') {
        $count_ow++;
    } elseif ($final_category === 'Obese') {
        $count_ob++;
    }
}

$children = [];

if (!empty($selected_category)) {
    foreach ($processed_rows as $row) {
        if ($row['intervention_category'] !== $selected_category) {
            continue;
        }

        $children[] = [
            'submitted_report_id' => isset($row['submitted_report_id']) ? $row['submitted_report_id'] : null,
            'record_id' => isset($row['record_id']) ? $row['record_id'] : 0,
            'child_id' => isset($row['child_id']) ? $row['child_id'] : 0,
            'child_name' => isset($row['child_name']) ? $row['child_name'] : '',
            'cdc_id' => isset($row['cdc_id']) ? $row['cdc_id'] : 0,
            'cdc_name' => isset($row['cdc_name']) ? $row['cdc_name'] : '',
            'date_recorded' => isset($row['date_recorded']) ? $row['date_recorded'] : '',
            'age_months' => isset($row['age_in_months']) ? $row['age_in_months'] : '',
            'height' => isset($row['height']) ? $row['height'] : '',
            'weight' => isset($row['weight']) ? $row['weight'] : '',
            'muac' => isset($row['muac']) ? $row['muac'] : '',
            'hfa_status' => isset($row['hfa_status']) ? $row['hfa_status'] : '',
            'wfa_status' => isset($row['wfa_status']) ? $row['wfa_status'] : '',
            'wflh_status' => isset($row['wflh_status']) ? $row['wflh_status'] : '',
            'intervention_category' => $row['intervention_category']
        ];
    }
}

if ($show_preview && !empty($selected_category)) {
    $preview_children = $children;
    $preview_guidance_rules = getInterventionGuidanceRules($selected_category);
    $preview_guidance_text = buildGuidanceText($preview_guidance_rules);
}

$total_records = 0;

$count_sql = "
    SELECT COUNT(*) AS total
    FROM submitted_reports sr
    WHERE LOWER(sr.report_type) IN ('wmr', 'terminal_report')
";

$count_types = "";
$count_params = [];

if ($filter_month !== '') {
    $count_sql .= " AND MONTH(sr.submitted_at) = ? ";
    $count_types .= "i";
    $count_params[] = (int)$filter_month;
}

if ($filter_year !== '') {
    $count_sql .= " AND YEAR(sr.submitted_at) = ? ";
    $count_types .= "i";
    $count_params[] = (int)$filter_year;
}

if ($filter_cdc > 0) {
    $count_sql .= " AND sr.cdc_id = ? ";
    $count_types .= "i";
    $count_params[] = (int)$filter_cdc;
}

if ($filter_assessment === 'endline') {
    $count_sql .= " AND LOWER(sr.report_type) = 'terminal_report' ";
} elseif ($filter_assessment === 'baseline' || $filter_assessment === 'midline') {
    $count_sql .= " AND LOWER(sr.report_type) = 'wmr' ";
}

$count_stmt = $conn->prepare($count_sql);

if ($count_stmt) {
    if (!empty($count_params)) {
        $count_stmt->bind_param($count_types, ...$count_params);
    }

    $count_stmt->execute();
    $count_result = $count_stmt->get_result();

    if ($count_result) {
        $count_row = $count_result->fetch_assoc();
        $total_records = (int)$count_row['total'];
    }

    $count_stmt->close();
}

$total_pages = max(1, (int)ceil($total_records / $limit));

$recent_reports = getRecentSubmittedReportsForGuidance(
    $conn,
    $limit,
    $offset,
    $filter_month,
    $filter_year,
    $filter_assessment,
    $filter_cdc
);

if ($selected_report_id > 0) {
    $selected_report = getSubmittedReportById($conn, $selected_report_id);
    $report_children = getAtRiskChildrenFromSubmittedReport($conn, $selected_report_id);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Intervention Guidance</title>
    <link rel="stylesheet" href="../assets/admin/admin-style.css">
    <link rel="stylesheet" href="../assets/admin/intervention_guidance.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
</head>
<body class="<?php echo (isset($_SESSION['theme_mode']) && $_SESSION['theme_mode'] === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/admin_sidebar.php'; ?>
<?php include '../includes/admin_topbar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-header">
        <h1>Intervention Guidance</h1>
        <p>
            Review submitted Baseline, Midline, and Endline reports, identify At-Risk children,
            and send guidance/reminders to linked guardians.
        </p>
    </div>

    <?php if (!empty($message)) : ?>
        <div class="alert <?php echo h($message_type); ?>">
            <?php echo h($message); ?>
        </div>
    <?php endif; ?>


<!-- =====================================================
     FILTERS
====================================================== -->
<div class="filter-card">
    <form method="GET" class="filter-form">
        <div class="filter-group">
            <label for="month">Month</label>
            <select name="month" id="month">
                <option value="">All Months</option>
                <?php
                    $months = [
                        1 => 'January',
                        2 => 'February',
                        3 => 'March',
                        4 => 'April',
                        5 => 'May',
                        6 => 'June',
                        7 => 'July',
                        8 => 'August',
                        9 => 'September',
                        10 => 'October',
                        11 => 'November',
                        12 => 'December'
                    ];
                ?>

                <?php foreach ($months as $month_number => $month_name) : ?>
                    <option value="<?php echo $month_number; ?>" <?php echo ((string)$filter_month === (string)$month_number) ? 'selected' : ''; ?>>
                        <?php echo h($month_name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label for="year">Year</label>
            <select name="year" id="year">
                <option value="">All Years</option>
                <?php
                    $current_year = (int)date('Y');
                    for ($year = $current_year; $year >= $current_year - 5; $year--) :
                ?>
                    <option value="<?php echo $year; ?>" <?php echo ((string)$filter_year === (string)$year) ? 'selected' : ''; ?>>
                        <?php echo $year; ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="filter-group">
            <label for="assessment_type">Assessment Type</label>
            <select name="assessment_type" id="assessment_type">
                <option value="">All Assessments</option>
                <option value="baseline" <?php echo ($filter_assessment === 'baseline') ? 'selected' : ''; ?>>Baseline</option>
                <option value="midline" <?php echo ($filter_assessment === 'midline') ? 'selected' : ''; ?>>Midline</option>
                <option value="endline" <?php echo ($filter_assessment === 'endline') ? 'selected' : ''; ?>>Endline</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="filter_cdc_id">CDC</label>
            <select name="filter_cdc_id" id="filter_cdc_id">
                <option value="">All CDCs</option>
                <?php foreach ($cdc_list as $cdc) : ?>
                    <option value="<?php echo h($cdc['cdc_id']); ?>" <?php echo ((int)$filter_cdc === (int)$cdc['cdc_id']) ? 'selected' : ''; ?>>
                        <?php echo h($cdc['cdc_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">Apply Filter</button>
            <a href="intervention_guidance.php" class="btn btn-secondary">Reset</a>
        </div>
    </form>
</div>

    <!-- =====================================================
         RECENT SUBMITTED REPORTS
    ====================================================== -->
    <div class="table-card" id="recent-reports-section">
        <div class="table-header">
            <h2>Recent Submitted Reports</h2>
            <p style="margin-top:6px; color:#64748b; font-size:14px;">
                Select a submitted report to view At-Risk children and generate guidance/reminders.
            </p>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Date Submitted</th>
                        <th>Report Type</th>
                        <th>Assessment</th>
                        <th>CDC</th>
                        <th>At-Risk Children</th>
                        <th>Guidance Status</th>
                        <th>Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!empty($recent_reports)) : ?>
                        <?php foreach ($recent_reports as $report_item) : ?>
                            <?php
                                $status_class = 'status-neutral';

                                if (
                                    $report_item['guidance_status'] === 'Pending Guidance' ||
                                    $report_item['guidance_status'] === 'Pending Final Reminder'
                                ) {
                                    $status_class = 'status-alert';
                                } elseif ($report_item['guidance_status'] === 'Partially Sent') {
                                    $status_class = 'status-warning';
                                } elseif ($report_item['guidance_status'] === 'Already Generated') {
                                    $status_class = 'status-normal';
                                }

                                $is_selected_report = ((int)$selected_report_id === (int)$report_item['submitted_report_id']);

                                $display_report_type = strtolower(trim($report_item['report_type'])) === 'terminal_report'
                                    ? 'Terminal Report'
                                    : strtoupper($report_item['report_type']);
                            ?>

                            <tr class="<?php echo $is_selected_report ? 'selected-row' : ''; ?>">
                                <td>
                                    <?php echo !empty($report_item['submitted_at']) ? h(date('M d, Y h:i A', strtotime($report_item['submitted_at']))) : 'N/A'; ?>
                                </td>

                                <td><?php echo h($display_report_type); ?></td>

                                <td>
                                    <strong><?php echo h($report_item['assessment_label']); ?></strong>
                                </td>

                                <td><?php echo h($report_item['cdc_name']); ?></td>

                                <td><?php echo h($report_item['at_risk_count']); ?></td>

                                <td>
                                    <span class="status-pill <?php echo h($status_class); ?>">
                                        <?php echo h($report_item['guidance_status']); ?>
                                    </span>
                                </td>

                                <td>
                                    <a class="btn btn-primary"
                                       href="intervention_guidance.php?report_id=<?php echo urlencode($report_item['submitted_report_id']); ?>&month=<?php echo urlencode($filter_month); ?>&year=<?php echo urlencode($filter_year); ?>&assessment_type=<?php echo urlencode($filter_assessment); ?>&filter_cdc_id=<?php echo urlencode($filter_cdc); ?>#selected-report-section">
                                        View Children
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="7" class="empty-state">
                                No submitted reports found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
               <div class="pagination-controls">
    <?php if ($page > 1) : ?>
        <a class="pagination-btn"
           href="intervention_guidance.php?month=<?php echo urlencode($filter_month); ?>&year=<?php echo urlencode($filter_year); ?>&assessment_type=<?php echo urlencode($filter_assessment); ?>&filter_cdc_id=<?php echo urlencode($filter_cdc); ?>&page=<?php echo h($page - 1); ?>#recent-reports-section">
            Previous
        </a>
    <?php endif; ?>

    <span class="pagination-page">
        Page <?php echo h($page); ?> of <?php echo h($total_pages); ?>
    </span>

    <?php if ($page < $total_pages) : ?>
        <a class="pagination-btn"
           href="intervention_guidance.php?month=<?php echo urlencode($filter_month); ?>&year=<?php echo urlencode($filter_year); ?>&assessment_type=<?php echo urlencode($filter_assessment); ?>&filter_cdc_id=<?php echo urlencode($filter_cdc); ?>&page=<?php echo h($page + 1); ?>#recent-reports-section">
            Next
        </a>
    <?php endif; ?>
</div>
        </div>

    <!-- =====================================================
         SELECTED REPORT AT-RISK CHILDREN
    ====================================================== -->
   <?php if ($selected_report_id > 0) : ?>
    <div class="table-card" id="selected-report-section">
            <div class="table-header">
                <h2>At-Risk Children from Selected Report</h2>

                <?php if ($selected_report) : ?>
                    <?php
                        $selected_report_type = strtolower(trim($selected_report['report_type'])) === 'terminal_report'
                            ? 'Terminal Report'
                            : strtoupper($selected_report['report_type']);
                    ?>

                    <p style="margin-top:6px; color:#64748b; font-size:14px;">
                        Report ID:
                        <strong><?php echo h($selected_report['submitted_report_id']); ?></strong>
                        |
                        Report Type:
                        <strong><?php echo h($selected_report_type); ?></strong>
                        |
                        CDC:
                        <strong><?php echo h($selected_report['cdc_name']); ?></strong>
                        |
                        Submitted:
                        <strong>
                            <?php echo !empty($selected_report['submitted_at']) ? h(date('M d, Y h:i A', strtotime($selected_report['submitted_at']))) : 'N/A'; ?>
                        </strong>
                    </p>
                <?php endif; ?>
            </div>

          <form method="POST" id="selectedChildrenForm">
    <input type="hidden" name="submitted_report_id" value="<?php echo h($selected_report_id); ?>">
    <input type="hidden" name="month" value="<?php echo h($filter_month); ?>">
    <input type="hidden" name="year" value="<?php echo h($filter_year); ?>">
    <input type="hidden" name="assessment_type" value="<?php echo h($filter_assessment); ?>">
    <input type="hidden" name="filter_cdc_id" value="<?php echo h($filter_cdc); ?>">

    <div class="batch-action" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px;">
        <button type="button" class="btn btn-secondary" onclick="selectAllChildren()">
            Select All
        </button>

        <button type="button" class="btn btn-secondary" onclick="selectPendingChildren()">
            Select All Pending
        </button>

        <button type="button" class="btn btn-secondary" onclick="clearSelectedChildren()">
            Clear Selection
        </button>

    </div>

    

                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Select</th>
                                <th>Child Name</th>
                                <th>Assessment</th>
                                <th>Original Status</th>
                                <th>Intervention Category</th>
                                <th>WFA</th>
                                <th>HFA</th>
                                <th>WFL/H</th>
                                <th>Sent Status</th>
                                <th>Warning Note</th>
                            </tr>
                        </thead>

                        <tbody>


                        
                            <?php if (!empty($report_children)) : ?>
                                <?php foreach ($report_children as $child) : ?>
                                    <?php
                                        $is_sent = ($child['sent_status'] === 'Already Sent');
                                        $checkbox_value = $child['child_id'] . '|' . $child['intervention_category'];
                                    ?>

                                    <tr>
                                        <td>
                                            <input
                                                type="checkbox"
                                                name="selected_children[]"
                                                class="child-checkbox <?php echo !$is_sent ? 'pending-checkbox' : ''; ?>"
                                                value="<?php echo h($checkbox_value); ?>"
                                                <?php echo $is_sent ? 'disabled' : ''; ?>
                                            >
                                        </td>

                                        <td><?php echo h($child['child_name']); ?></td>

                                        <td>
                                            <strong><?php echo h($child['assessment_label']); ?></strong>
                                        </td>

                                        <td><?php echo h($child['original_status']); ?></td>

                                        <td><?php echo h($child['intervention_category']); ?></td>

                                        <td><?php echo h($child['wfa_status']); ?></td>

                                        <td><?php echo h($child['hfa_status']); ?></td>

                                        <td><?php echo h($child['wflh_status']); ?></td>

                                        <td>
                                            <?php if ($is_sent) : ?>
                                                <span class="status-pill status-normal">Already Sent</span>
                                            <?php else : ?>
                                                <span class="status-pill status-alert">Not Yet Sent</span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?php if (!empty($child['sequence_warning'])) : ?>
                                                <div class="warning-note">
                                                    <?php echo h($child['sequence_warning']); ?>
                                                </div>
                                            <?php else : ?>
                                                <span style="color:#64748b;">No warning</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="10" class="empty-state">
                                        No At-Risk children found in this submitted report.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($report_children)) : ?>
                    <div class="batch-action" style="margin-top:18px;">
                        <button type="submit" name="preview_selected_guidance" class="btn btn-generate-main">
                            Generate for Selected
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    <?php else : ?>
        <div class="table-card empty-card">
            <div class="empty-state-block">
                Select a submitted report above to view At-Risk children.
            </div>
        </div>
    <?php endif; ?>

        <?php if ($show_preview && !empty($preview_children)) : ?>
        <div class="preview-overlay">
            <div class="preview-modal">
                <div class="preview-header">
                    <h2>Preview Intervention Guidance / Reminder</h2>
                    <p>
                        Review the selected children, warnings, and auto-generated guidance before sending.
                    </p>
                </div>

                                <div class="preview-selected-section">
                    <h3>Selected Children</h3>

                    <div class="preview-child-list">
                        <?php foreach ($preview_children as $child) : ?>
                            <div class="preview-child-card">
                                <div>
                                    <h4><?php echo h($child['child_name']); ?></h4>
                                    <p>
                                        <strong>Assessment:</strong> <?php echo h($child['assessment_label']); ?>
                                        |
                                        <strong>Original Status:</strong> <?php echo h($child['original_status']); ?>
                                        |
                                        <strong>Category:</strong> <?php echo h($child['intervention_category']); ?>
                                    </p>
                                </div>

                                <span class="status-pill status-alert">
                                    <?php echo h($child['sent_status']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php
                    $preview_warnings = [];

                    foreach ($preview_children as $child) {
                        if (!empty($child['sequence_warning'])) {
                            $preview_warnings[] = [
                                'child_name' => $child['child_name'],
                                'warning' => $child['sequence_warning']
                            ];
                        }
                    }
                ?>

                <?php if (!empty($preview_warnings)) : ?>
    <div class="mini-warning-overlay" id="sequenceWarningPopup">
        <div class="mini-warning-modal">
            <div class="mini-warning-header">
                <div class="mini-warning-icon">!</div>
                <h3>Sequence Warning</h3>
            </div>

            <p class="mini-warning-message">
                Some selected children may be missing a previous guidance step.
                This will not block sending, but please review before confirming.
            </p>

            <div class="mini-warning-names">
                <strong>Selected child/children:</strong>
                <ul>
                    <?php foreach ($preview_warnings as $warning_item) : ?>
                        <li><?php echo h($warning_item['child_name']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <button type="button" class="btn btn-primary" onclick="closeSequenceWarningPopup()">
                Okay, I Understand
            </button>
        </div>
    </div>
<?php endif; ?>


                <div class="guidance-section">
                    <h3>Auto-Generated Guidance</h3>

                    <?php foreach ($preview_guidance_rules_by_category as $category => $rules) : ?>
                        <div style="margin-bottom:16px;">
                            <h4 style="margin-bottom:8px;"><?php echo h($category); ?></h4>

                            <?php if (!empty($rules)) : ?>
                                <ul>
                                    <?php foreach ($rules as $rule) : ?>
                                        <li><?php echo h($rule); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                <p>No guidance rules found for this category.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="guidance-disclaimer">
                    <p>
                        This guidance is intended for basic nutrition support and monitoring. Children with specific health conditions should be referred to a registered nutritionist-dietitian or healthcare provider for proper assessment.
                    </p>
                </div>

                <form method="POST" class="preview-form">
                    <input type="hidden" name="submitted_report_id" value="<?php echo h($selected_report_id); ?>">
                    <input type="hidden" name="month" value="<?php echo h($filter_month); ?>">
                    <input type="hidden" name="year" value="<?php echo h($filter_year); ?>">
                    <input type="hidden" name="assessment_type" value="<?php echo h($filter_assessment); ?>">
                    <input type="hidden" name="filter_cdc_id" value="<?php echo h($filter_cdc); ?>">

    <?php foreach ($preview_children as $child) : ?>
                        <input
                            type="hidden"
                            name="selected_children[]"
                            value="<?php echo h($child['child_id'] . '|' . $child['intervention_category']); ?>"
                        >
                    <?php endforeach; ?>

                    <?php foreach ($preview_guidance_rules_by_category as $category => $rules) : ?>
                <div class="note-group">
                    <label>
                        Optional Note / Recommendation for <?php echo h($category); ?>
                    </label>

                    <textarea
                        name="optional_note_by_category[<?php echo h($category); ?>]"
                        rows="4"
                        placeholder="Add optional note or recommendation for <?php echo h($category); ?>..."
                    ></textarea>
                </div>
            <?php endforeach; ?>

                    <div class="preview-actions">
                        <a
                            href="intervention_guidance.php?report_id=<?php echo urlencode($selected_report_id); ?>&month=<?php echo urlencode($filter_month); ?>&year=<?php echo urlencode($filter_year); ?>&assessment_type=<?php echo urlencode($filter_assessment); ?>&filter_cdc_id=<?php echo urlencode($filter_cdc); ?>"
                            class="btn btn-secondary"
                        >
                            Cancel
                        </a>

                        <button type="submit" name="confirm_selected_guidance" class="btn btn-primary">
                            Confirm and Send
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>
<script>

function closeSequenceWarningPopup() {
    const popup = document.getElementById('sequenceWarningPopup');

    if (popup) {
        popup.style.display = 'none';
    }
}

function selectAllChildren() {
    document.querySelectorAll('.child-checkbox:not(:disabled)').forEach(function(checkbox) {
        checkbox.checked = true;
    });
}

function selectPendingChildren() {
    document.querySelectorAll('.pending-checkbox:not(:disabled)').forEach(function(checkbox) {
        checkbox.checked = true;
    });
}

function clearSelectedChildren() {
    document.querySelectorAll('.child-checkbox').forEach(function(checkbox) {
        checkbox.checked = false;
    });
}
</script>

<script src="../assets/admin/sidebar.js"></script>
</body>
</html>