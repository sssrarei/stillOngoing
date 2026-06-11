<?php
include '../includes/auth.php';
include '../config/database.php';
include '../includes/intervention_helper.php';

if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 1) {
    header("Location: ../login.php");
    exit();
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function safe_value($value, $fallback = 'N/A')
{
    return (isset($value) && trim((string)$value) !== '') ? $value : $fallback;
}

function format_date_value($date, $fallback = 'N/A')
{
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return $fallback;
    }

    $timestamp = strtotime($date);
    if (!$timestamp) {
        return $fallback;
    }

    return date('F d, Y', $timestamp);
}

function format_datetime_value($date, $fallback = 'N/A')
{
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return $fallback;
    }

    $timestamp = strtotime($date);
    if (!$timestamp) {
        return $fallback;
    }

    return date('F d, Y h:i A', $timestamp);
}

function normalize_sex($sex)
{
    $sex = strtolower(trim((string)$sex));

    if ($sex === 'm' || $sex === 'male') {
        return 'Male';
    }

    if ($sex === 'f' || $sex === 'female') {
        return 'Female';
    }

    return trim((string)$sex) !== '' ? ucfirst(trim((string)$sex)) : 'N/A';
}

function get_first_existing_value($row, $keys, $fallback = 'N/A')
{
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

function get_first_existing_numeric_or_text($row, $keys, $fallback = 'N/A')
{
    if (!is_array($row)) {
        return $fallback;
    }

    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return $row[$key];
        }
    }

    return $fallback;
}

function build_child_name_from_row($row)
{
    if (!is_array($row)) {
        return 'N/A';
    }

    $full_name = get_first_existing_value($row, [
        'child_name',
        'full_name',
        'name',
        'beneficiary_name'
    ], '');

    if ($full_name !== '') {
        return $full_name;
    }

    $parts = [];

    $first = get_first_existing_value($row, ['first_name', 'child_first_name'], '');
    $middle = get_first_existing_value($row, ['middle_name', 'child_middle_name'], '');
    $last = get_first_existing_value($row, ['last_name', 'child_last_name'], '');

    if ($first !== '') $parts[] = trim($first);
    if ($middle !== '') $parts[] = trim($middle);
    if ($last !== '') $parts[] = trim($last);

    $built = trim(implode(' ', $parts));
    return $built !== '' ? $built : 'N/A';
}

function status_class($status)
{
    $status = strtolower(trim((string)$status));

    if ($status === 'normal') return 'status-normal';
    if ($status === 'underweight') return 'status-alert';
    if ($status === 'severely underweight') return 'status-alert';
    if ($status === 'overweight') return 'status-alert';
    if ($status === 'obese') return 'status-alert';
    if ($status === 'stunted') return 'status-alert';
    if ($status === 'severely stunted') return 'status-alert';
    if ($status === 'moderately wasted') return 'status-alert';
    if ($status === 'wasted') return 'status-alert';
    if ($status === 'severely wasted') return 'status-alert';

    return '';
}

function extract_report_rows($payload)
{
    if (!is_array($payload)) {
        return [];
    }

    $candidate_keys = [
        'submitted_rows',
        'rows',
        'report_rows',
        'records',
        'terminal_rows',
        'terminal_report_rows',
        'terminal_data',
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

    $is_list = array_keys($payload) === range(0, count($payload) - 1);

    if ($is_list) {
        return $payload;
    }

    return [];
}

function terminal_get_final_risk_status($wfa_status, $hfa_status, $wflh_status)
{
    $statuses = [
        trim((string)$wfa_status),
        trim((string)$hfa_status),
        trim((string)$wflh_status)
    ];

    $priority_order = [
        'Severely Wasted',
        'Severely Underweight',
        'Severely Stunted',
        'Moderately Wasted',
        'Wasted',
        'Underweight',
        'Obese',
        'Overweight',
        'Stunted',
        'Normal'
    ];

    foreach ($priority_order as $priority_status) {
        if (in_array($priority_status, $statuses, true)) {
            return $priority_status;
        }
    }

    return 'Normal';
}

function terminal_determine_intervention_category($wfa_status, $hfa_status, $wflh_status)
{
    $final_status = terminal_get_final_risk_status($wfa_status, $hfa_status, $wflh_status);

    if (
        $final_status === 'Underweight' ||
        $final_status === 'Moderately Wasted' ||
        $final_status === 'Wasted'
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

function terminal_get_endline_record_id($conn, $child_id)
{
    $record_id = 0;

    $sql = "
        SELECT record_id
        FROM anthropometric_records
        WHERE child_id = ?
          AND LOWER(TRIM(assessment_type)) = 'endline'
        ORDER BY date_recorded DESC, record_id DESC
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $child_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($row = mysqli_fetch_assoc($result)) {
            $record_id = (int)$row['record_id'];
        }

        mysqli_stmt_close($stmt);
    }

    return $record_id;
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die('No submitted report selected.');
}

$submitted_report_id = (int) $_GET['id'];

$report_sql = "
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
        c.cdc_name,
        c.barangay,
        c.address AS cdc_address,
        CONCAT(
            COALESCE(u.first_name, ''),
            CASE
                WHEN u.first_name IS NOT NULL AND u.first_name != '' AND u.last_name IS NOT NULL AND u.last_name != '' THEN ' '
                ELSE ''
            END,
            COALESCE(u.last_name, '')
        ) AS submitted_by_name
    FROM submitted_reports sr
    INNER JOIN cdc c ON sr.cdc_id = c.cdc_id
    INNER JOIN users u ON sr.submitted_by = u.user_id
    WHERE sr.submitted_report_id = ?
    LIMIT 1
";

$stmt_report = mysqli_prepare($conn, $report_sql);
if (!$stmt_report) {
    die('Failed to prepare submitted report query.');
}

mysqli_stmt_bind_param($stmt_report, "i", $submitted_report_id);
mysqli_stmt_execute($stmt_report);
$result_report = mysqli_stmt_get_result($stmt_report);

if (!$result_report || mysqli_num_rows($result_report) === 0) {
    die('Submitted report not found.');
}

$report = mysqli_fetch_assoc($result_report);
mysqli_stmt_close($stmt_report);

if ($report['report_type'] !== 'terminal_report') {
    die('The selected submitted report is not a Terminal Report.');
}

$payload = json_decode($report['report_payload'], true);
if (!is_array($payload)) {
    $payload = [];
}

$rows = extract_report_rows($payload);

/* Fallback: get missing child sex from children table */
if (!empty($rows)) {
    $sex_lookup_sql = "
        SELECT sex
        FROM children
        WHERE child_id = ?
        LIMIT 1
    ";

    $sex_lookup_stmt = mysqli_prepare($conn, $sex_lookup_sql);

    if ($sex_lookup_stmt) {
        foreach ($rows as $index => $row) {
            $current_sex = trim((string)($row['sex'] ?? ''));

            if (($current_sex === '' || strtoupper($current_sex) === 'N/A') && !empty($row['child_id'])) {
                $lookup_child_id = (int)$row['child_id'];

                mysqli_stmt_bind_param($sex_lookup_stmt, "i", $lookup_child_id);
                mysqli_stmt_execute($sex_lookup_stmt);
                $sex_result = mysqli_stmt_get_result($sex_lookup_stmt);

                if ($sex_result && mysqli_num_rows($sex_result) > 0) {
                    $sex_row = mysqli_fetch_assoc($sex_result);
                    $linked_sex = trim((string)($sex_row['sex'] ?? ''));

                    if ($linked_sex !== '') {
                        $rows[$index]['sex'] = $linked_sex;
                    }
                }
            }
        }

        mysqli_stmt_close($sex_lookup_stmt);
    }
}

$message = '';
$message_type = '';

/*
|--------------------------------------------------------------------------
| FINAL FOLLOW-UP PREVIEW VARIABLES
|--------------------------------------------------------------------------
| ADDED:
| These variables are used so the Admin can review the final follow-up
| reminder first before sending it to guardians.
*/
$show_final_preview = false;
$final_preview_children = [];
$final_preview_rules_by_category = [];
$final_optional_note = '';

$prepared_by = get_first_existing_value($payload, [
    'prepared_by',
    'preparedBy',
    'submitted_by_name',
    'teacher_name',
    'cdw_name'
], $report['submitted_by_name']);

$coverage_text = 'All Dates';

if (!empty($report['date_from']) && !empty($report['date_to'])) {
    $coverage_text = format_date_value($report['date_from']) . ' - ' . format_date_value($report['date_to']);
} elseif (!empty($report['date_from'])) {
    $coverage_text = 'From ' . format_date_value($report['date_from']);
} elseif (!empty($report['date_to'])) {
    $coverage_text = 'Up to ' . format_date_value($report['date_to']);
}

/*
|--------------------------------------------------------------------------
| PREVIEW FINAL FOLLOW-UP REMINDER
|--------------------------------------------------------------------------
| ADDED:
| This only prepares the preview list.
| It does NOT save/send anything yet.
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview_final_followup'])) {
    $show_final_preview = true;

    foreach ($rows as $row) {
        $child_id = isset($row['child_id']) ? (int)$row['child_id'] : 0;

        if ($child_id <= 0) {
            continue;
        }

        $endline_wfa = get_first_existing_value($row, ['endline_wfa_status', 'endline_wfa'], '');
        $endline_hfa = get_first_existing_value($row, ['endline_hfa_status', 'endline_hfa'], '');
        $endline_wflh = get_first_existing_value($row, ['endline_wflh_status', 'endline_wflh', 'endline_wfh_status', 'endline_wfh'], '');

        $intervention_category = terminal_determine_intervention_category(
            $endline_wfa,
            $endline_hfa,
            $endline_wflh
        );

        if ($intervention_category === null) {
            continue;
        }

        $original_status = terminal_get_final_risk_status(
            $endline_wfa,
            $endline_hfa,
            $endline_wflh
        );

        $final_preview_children[] = [
            'child_id' => $child_id,
            'child_name' => build_child_name_from_row($row),
            'original_status' => $original_status,
            'intervention_category' => $intervention_category,
            'endline_wfa' => $endline_wfa,
            'endline_hfa' => $endline_hfa,
            'endline_wflh' => $endline_wflh
        ];

        if (!isset($final_preview_rules_by_category[$intervention_category])) {
            $final_preview_rules_by_category[$intervention_category] = getInterventionGuidanceRules($intervention_category);
        }
    }

    if (empty($final_preview_children)) {
        $show_final_preview = false;
        $message = 'No Endline at-risk children found for final follow-up reminder.';
        $message_type = 'error';
    }
}

/*
|--------------------------------------------------------------------------
| CONFIRM AND SEND FINAL FOLLOW-UP REMINDER
|--------------------------------------------------------------------------
| FIXED:
| Before, the save/send logic was running immediately.
| Now, it only runs when Admin clicks "Confirm and Send".
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_final_followup'])) {
    $final_optional_note = isset($_POST['final_optional_note']) ? trim($_POST['final_optional_note']) : '';
    $reviewed_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $saved_count = 0;

    foreach ($rows as $row) {
        $child_id = isset($row['child_id']) ? (int)$row['child_id'] : 0;

        if ($child_id <= 0) {
            continue;
        }

        $endline_wfa = get_first_existing_value($row, ['endline_wfa_status', 'endline_wfa'], '');
        $endline_hfa = get_first_existing_value($row, ['endline_hfa_status', 'endline_hfa'], '');
        $endline_wflh = get_first_existing_value($row, ['endline_wflh_status', 'endline_wflh', 'endline_wfh_status', 'endline_wfh'], '');

        $intervention_category = terminal_determine_intervention_category(
            $endline_wfa,
            $endline_hfa,
            $endline_wflh
        );

        if ($intervention_category === null) {
            continue;
        }

        $original_status = terminal_get_final_risk_status(
            $endline_wfa,
            $endline_hfa,
            $endline_wflh
        );

        $record_id = terminal_get_endline_record_id($conn, $child_id);

        if ($record_id <= 0) {
            continue;
        }

        $rules = getInterventionGuidanceRules($intervention_category);
        $guidance_text = buildGuidanceText($rules);

        /*
        |--------------------------------------------------------------------------
        | OPTIONAL NOTE
        |--------------------------------------------------------------------------
        | FIXED:
        | If CSWD Admin enters a recommendation/note in the preview form,
        | it will be saved into optional_note.
        */
        $optional_note = $final_optional_note !== ''
            ? $final_optional_note
            : 'Generated from Terminal Report Endline assessment.';

        $is_at_risk = 1;
        $needs_counseling = 1;
        $needs_referral = 1;
        $status_note = 'Final Follow-up Reminder: Child still needs nutritional attention based on Endline assessment.';

        /*
        |--------------------------------------------------------------------------
        | DUPLICATE CHECK
        |--------------------------------------------------------------------------
        | This prevents duplicate final reminders for the same child,
        | same category, and same submitted terminal report.
        */
        $check_sql = "
            SELECT guidance_id
            FROM intervention_guidance
            WHERE child_id = ?
              AND intervention_category = ?
              AND submitted_report_id = ?
            LIMIT 1
        ";

        $check_stmt = mysqli_prepare($conn, $check_sql);

        if (!$check_stmt) {
            continue;
        }

        mysqli_stmt_bind_param($check_stmt, "isi", $child_id, $intervention_category, $submitted_report_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if ($existing = mysqli_fetch_assoc($check_result)) {
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

            $update_stmt = mysqli_prepare($conn, $update_sql);

            if ($update_stmt) {
                mysqli_stmt_bind_param(
                    $update_stmt,
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

                if (mysqli_stmt_execute($update_stmt)) {
                    $saved_count++;
                }

                mysqli_stmt_close($update_stmt);
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

            $insert_stmt = mysqli_prepare($conn, $insert_sql);

            if ($insert_stmt) {
                mysqli_stmt_bind_param(
                    $insert_stmt,
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

                if (mysqli_stmt_execute($insert_stmt)) {
                    $saved_count++;
                }

                mysqli_stmt_close($insert_stmt);
            }
        }

        mysqli_stmt_close($check_stmt);
    }

    if ($saved_count > 0) {
        $message = $saved_count . ' final follow-up reminder(s) generated and sent to guardian successfully.';
        $message_type = 'success';
    } else {
        $message = 'No final follow-up reminders were generated. No Endline at-risk children were found or reminders already exist.';
        $message_type = 'error';
    }
}


$status = strtolower(trim((string)$report['status']));
$status_class = 'status-default';

if ($status === 'submitted') {
    $status_class = 'status-submitted';
} elseif ($status === 'reviewed') {
    $status_class = 'status-reviewed';
} elseif ($status === 'approved') {
    $status_class = 'status-approved';
} elseif ($status === 'returned') {
    $status_class = 'status-returned';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terminal Report | NutriTrack</title>
    <link rel="stylesheet" href="../assets/admin/admin-style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">

    <style>
        .report-view-wrapper {
            padding: 24px;
        }

        .report-header-card,
        .summary-card,
        .table-card {
            background: #ffffff;
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
            margin-bottom: 24px;
        }

        .report-header-card {
            padding: 24px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            color: #163b68;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 14px;
        }

        .report-header h1 {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            font-size: 28px;
            font-weight: 700;
            color: #163b68;
        }

        .report-header p {
            margin: 8px 0 0;
            font-size: 14px;
            color: #64748b;
        }

        .summary-card {
            padding: 22px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }

        .summary-item {
            background: #ffffff;
            border: 1px solid #cccccc;
            border-radius: 10px;
            padding: 14px;
        }

        .summary-label {
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 8px;
        }

        .summary-value {
            font-size: 15px;
            font-weight: 700;
            color: #163b68;
            line-height: 1.5;
        }

        .table-header {
            padding: 20px 22px;
            border-bottom: 1px solid #eef2f7;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .table-header h2 {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            font-weight: 700;
            color: #163b68;
        }

        .table-header p {
            margin: 6px 0 0;
            font-size: 13px;
            color: #64748b;
        }

        .record-count,
        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .record-count {
            background: #edf4ff;
            color: #163b68;
        }

        .status-badge {
            text-transform: capitalize;
        }

        .status-submitted {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-reviewed {
            background: #dcfce7;
            color: #166534;
        }

        .status-approved {
            background: #ede9fe;
            color: #163b68;
        }

        .status-returned {
            background: #fee2e2;
            color: #b91c1c;
        }

        .status-default {
            background: #e5e7eb;
            color: #374151;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            min-width: 1500px;
            border-collapse: collapse;
        }

        .report-table thead th {
            background: #f8fafc;
            font-size: 13px;
            font-weight: 700;
            color: #334155;
            text-align: left;
            padding: 14px 16px;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }

        .report-table tbody td {
            padding: 14px 16px;
            font-size: 14px;
            color: #1e293b;
            border-bottom: 1px solid #eef2f7;
            vertical-align: top;
        }

        .report-table tbody tr:hover {
            background: #fafcff;
        }

        .status-normal {
            color: #2E7D32;
            font-weight: 700;
        }

        .status-alert {
            color: #C0392B;
            font-weight: 700;
        }

        .empty-state {
            padding: 56px 24px;
            text-align: center;
            color: #64748b;
            font-size: 14px;
        }

        .empty-state h3 {
            margin: 0 0 8px;
            font-family: 'Poppins', sans-serif;
            font-size: 20px;
            color: #163b68;
        }

        .payload-preview {
            padding: 20px 22px 24px;
            border-top: 1px solid #eef2f7;
        }

        .payload-preview pre {
            margin: 0;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            overflow: auto;
            font-size: 12px;
            color: #334155;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
        }

        @media (max-width: 1200px) {
            .summary-grid {
                grid-template-columns: repeat(2, minmax(180px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .report-view-wrapper {
                padding: 16px;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
        


        .report-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 14px;
}

.print-btn {
    background: #163b68;
    color: #ffffff;
    border: none;
    padding: 10px 16px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    font-family: 'Inter', sans-serif;
}

.print-btn:hover {
    background: #102f54;
}

@media print {
    @page {
        size: landscape;
        margin: 12mm;
    }

    body {
        background: #ffffff !important;
        color: #000000 !important;
    }

    .no-print,
    .sidebar,
    #sidebar,
    .sidebar-overlay,
    #sidebarOverlay,
    .topbar,
    .admin-topbar,
    header,
    nav,
    button,
    .back-link,
    .summary-card form,
    .summary-card textarea {
        display: none !important;
    }

    .main-content,
    #mainContent {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    .report-view-wrapper {
        padding: 0 !important;
    }

    .report-header-card,
    .summary-card,
    .table-card {
        box-shadow: none !important;
        border-radius: 0 !important;
        margin-bottom: 14px !important;
        border: none !important;
    }

    .report-header-card {
        padding: 0 0 10px 0 !important;
    }

    .report-header {
        text-align: center !important;
    }

    .report-header h1,
    .report-header p,
    .summary-label,
    .summary-value,
    .table-header h2,
    .table-header p,
    .record-count {
        color: #000000 !important;
    }

    .summary-card {
        padding: 10px 0 !important;
    }

    .summary-grid {
        grid-template-columns: repeat(4, 1fr) !important;
        gap: 8px !important;
    }

    .summary-item {
        padding: 8px !important;
        border: 1px solid #cccccc !important;
        background: #ffffff !important;
        min-height: 48px !important;
    }

    .table-header {
        padding: 8px 0 !important;
        border-bottom: 1px solid #cccccc !important;
    }

    .table-wrap {
        overflow: visible !important;
    }

    .report-table {
        width: 100% !important;
        min-width: 0 !important;
        font-size: 9px !important;
    }

    .report-table thead th,
    .report-table tbody td {
        padding: 5px 4px !important;
        font-size: 8px !important;
        color: #000000 !important;
        border: 1px solid #cccccc !important;
        white-space: normal !important;
    }

    .report-table thead th {
        background: #f2f2f2 !important;
    }

    .status-normal,
    .status-alert {
        color: #000000 !important;
        font-weight: 700 !important;
    }
}
    </style>
</head>
<body>

<?php include '../includes/admin_topbar.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="report-view-wrapper">

        <div class="report-header-card">
    <div class="report-actions no-print">
        <a href="monitoring_reports.php" class="back-link">← Back to Monitoring Reports</a>

        <button type="button" class="print-btn" onclick="window.print()">
            Print / Save as PDF
        </button>
    </div>

    <div class="report-header">
        <h1>Terminal Report</h1>
        <p>Submitted report snapshot for CSWD review.</p>
    </div>
</div>
        <?php if (!empty($message)) { ?>
    <div class="summary-card">
        <div class="<?php echo ($message_type === 'success') ? 'status-approved' : 'status-returned'; ?>" style="padding:14px 18px; border-radius:12px; font-weight:700;">
            <?php echo h($message); ?>
        </div>
    </div>
<?php } ?>

<div class="summary-card">
    <!--
        FIXED:
        This button now previews the final follow-up reminder first.
        It does NOT immediately save/send the reminder.
    -->
    <form method="POST">
        <button type="submit" name="preview_final_followup" class="status-badge status-approved" style="border:none; cursor:pointer; padding:12px 18px;">
            Generate Final Follow-up Reminder
        </button>
    </form>

    <p style="margin-top:10px; color:#64748b; font-size:14px;">
        This will preview final follow-up reminders for children who are still At-Risk based on the Endline assessment.
    </p>
</div>

<?php if ($show_final_preview && !empty($final_preview_children)) { ?>
    <!--
        ADDED:
        Preview section before sending final follow-up reminders.
        CSWD can review the children included and add notes/recommendations.
    -->
    <div class="summary-card">
        <h2 style="font-family:'Poppins', sans-serif; font-size:22px; margin-bottom:8px; color:#163b68;">
            Generate Final Follow-up Reminder
        </h2>

        <p style="color:#64748b; margin-bottom:18px;">
            Review the children included and add final recommendations before sending to guardians.
        </p>

        <div class="table-wrap" style="margin-bottom:20px;">
            <table class="report-table" style="min-width:900px;">
                <thead>
                    <tr>
                        <th>Child Name</th>
                        <th>Endline WFA</th>
                        <th>Endline HFA</th>
                        <th>Endline WFL/H</th>
                        <th>Final Category</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($final_preview_children as $child_preview) { ?>
                        <tr>
                            <td><?php echo h($child_preview['child_name']); ?></td>
                            <td><?php echo h($child_preview['endline_wfa']); ?></td>
                            <td><?php echo h($child_preview['endline_hfa']); ?></td>
                            <td><?php echo h($child_preview['endline_wflh']); ?></td>
                            <td><?php echo h($child_preview['intervention_category']); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <h3 style="font-family:'Poppins', sans-serif; font-size:18px; margin-bottom:10px;">
            Auto-Generated Guidance
        </h3>

        <?php foreach ($final_preview_rules_by_category as $category => $rules) { ?>
            <div style="margin-bottom:18px;">
                <strong><?php echo h($category); ?></strong>
                <ul style="margin-top:8px; padding-left:22px; color:#334155; line-height:1.8;">
                    <?php foreach ($rules as $rule) { ?>
                        <li><?php echo h($rule); ?></li>
                    <?php } ?>
                </ul>
            </div>
        <?php } ?>

        <div style="padding:14px 16px; border-radius:12px; background:#fff7ed; border-left:5px solid #f59e0b; color:#92400e; margin-bottom:18px;">
           This is intended for healthy Filipino children aged 0–71 months. Children with specific health conditions should be brought to a registered nutritionist-dietitian or any healthcare provider for consultation regarding their energy and nutrient needs.
        </div>

        <form method="POST">
            <label for="final_optional_note" style="display:block; font-weight:700; margin-bottom:8px; color:#334155;">
                Optional Final Recommendation / Note
            </label>

            <textarea
                name="final_optional_note"
                id="final_optional_note"
                rows="5"
                style="width:100%; padding:14px; border:1px solid #cbd5e1; border-radius:12px; resize:vertical; font-family:'Inter', sans-serif;"
                placeholder="Add CSWD final recommendation or note..."
            ></textarea>

            <div style="display:flex; gap:12px; margin-top:18px; flex-wrap:wrap;">
                <!--
                    ADDED:
                    This is the only button that saves/sends the final reminder.
                -->
                <button type="submit" name="confirm_final_followup" class="status-badge status-approved" style="border:none; cursor:pointer; padding:12px 18px;">
                    Confirm and Send Final Follow-up Reminder
                </button>

                <a href="view_terminal_report.php?id=<?php echo (int)$submitted_report_id; ?>" class="status-badge status-default" style="text-decoration:none; padding:12px 18px;">
                    Cancel
                </a>
            </div>
        </form>
    </div>
<?php } ?>

        <div class="summary-card">
            <div class="summary-grid">
                <div class="summary-item">
                    <div class="summary-label">CDC</div>
                    <div class="summary-value"><?php echo h(safe_value($report['cdc_name'])); ?></div>
                </div>

            

               

                <div class="summary-item">
                    <div class="summary-label">Prepared By</div>
                    <div class="summary-value"><?php echo h(safe_value($prepared_by)); ?></div>
                </div>

                <div class="summary-item">
                    <div class="summary-label">Submitted At</div>
                    <div class="summary-value"><?php echo h(format_datetime_value($report['submitted_at'])); ?></div>
                </div>

                

                <div class="summary-item">
                    <div class="summary-label">Barangay</div>
                    <div class="summary-value"><?php echo h(safe_value($report['barangay'])); ?></div>
                </div>

                
        </div>

        <div class="table-card">
            <div class="table-header">
                <div>
                    <h2>Terminal Report Table</h2>
                    <p>Submitted terminal report rows saved in this report snapshot.</p>
                </div>
                <span class="record-count"><?php echo count($rows); ?> record(s)</span>
            </div>

            <?php if (!empty($rows)): ?>
                <div class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Child Name</th>
                                <th>Sex</th>
                                <th>Baseline Date</th>
                                <th>Baseline WFA</th>
                                <th>Baseline HFA</th>
                                <th>Baseline WFL/H</th>
                                <th>Midline Date</th>
                                <th>Midline WFA</th>
                                <th>Midline HFA</th>
                                <th>Midline WFL/H</th>
                                <th>Endline Date</th>
                                <th>Endline WFA</th>
                                <th>Endline HFA</th>
                                <th>Endline WFL/H</th>
                                <th>Improvement</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $index => $row): ?>
                                <?php
                                    $child_name = build_child_name_from_row($row);
                                    $sex = normalize_sex(get_first_existing_value($row, ['sex', 'gender'], 'N/A'));

                                    $baseline_date = get_first_existing_value($row, ['baseline_date', 'baseline_recorded_date'], 'N/A');
                                    $baseline_wfa = get_first_existing_value($row, ['baseline_wfa_status', 'baseline_wfa'], 'N/A');
                                    $baseline_hfa = get_first_existing_value($row, ['baseline_hfa_status', 'baseline_hfa'], 'N/A');
                                    $baseline_wflh = get_first_existing_value($row, ['baseline_wflh_status', 'baseline_wflh', 'baseline_wfh_status', 'baseline_wfh'], 'N/A');

                                    $midline_date = get_first_existing_value($row, ['midline_date', 'midline_recorded_date'], 'N/A');
                                    $midline_wfa = get_first_existing_value($row, ['midline_wfa_status', 'midline_wfa'], 'N/A');
                                    $midline_hfa = get_first_existing_value($row, ['midline_hfa_status', 'midline_hfa'], 'N/A');
                                    $midline_wflh = get_first_existing_value($row, ['midline_wflh_status', 'midline_wflh', 'midline_wfh_status', 'midline_wfh'], 'N/A');

                                    $endline_date = get_first_existing_value($row, ['endline_date', 'endline_recorded_date'], 'N/A');
                                    $endline_wfa = get_first_existing_value($row, ['endline_wfa_status', 'endline_wfa'], 'N/A');
                                    $endline_hfa = get_first_existing_value($row, ['endline_hfa_status', 'endline_hfa'], 'N/A');
                                    $endline_wflh = get_first_existing_value($row, ['endline_wflh_status', 'endline_wflh', 'endline_wfh_status', 'endline_wfh'], 'N/A');

                                   $normal_statuses = ['Normal', 'Tall'];

                                    $problem_statuses = [
                                        'Underweight',
                                        'Wasted',
                                        'Overweight',
                                        'Stunted',
                                        'Moderately Wasted'
                                    ];

                                    $severe_statuses = [
                                        'Severely Underweight',
                                        'Severely Wasted',
                                        'Severely Stunted',
                                        'Obese'
                                    ];

                                    $all_problem_statuses = array_merge($problem_statuses, $severe_statuses);

                                    $baseline_normal =
                                        in_array($baseline_wfa, $normal_statuses) &&
                                        in_array($baseline_hfa, $normal_statuses) &&
                                        in_array($baseline_wflh, $normal_statuses);

                                    $endline_normal =
                                        in_array($endline_wfa, $normal_statuses) &&
                                        in_array($endline_hfa, $normal_statuses) &&
                                        in_array($endline_wflh, $normal_statuses);

                                    $baseline_has_problem =
                                        in_array($baseline_wfa, $all_problem_statuses) ||
                                        in_array($baseline_hfa, $all_problem_statuses) ||
                                        in_array($baseline_wflh, $all_problem_statuses);

                                    $endline_has_problem =
                                        in_array($endline_wfa, $all_problem_statuses) ||
                                        in_array($endline_hfa, $all_problem_statuses) ||
                                        in_array($endline_wflh, $all_problem_statuses);

                                    if ($baseline_has_problem && $endline_normal) {
                                        $improvement = 'Improved';
                                    }
                                    elseif ($baseline_normal && $endline_has_problem) {
                                        $improvement = 'Worsened';
                                    }
                                    else {
                                        $improvement = 'No Change';
                                    }
                                ?>
                                <tr>
                                    <td><?php echo (int)($index + 1); ?></td>
                                    <td><?php echo h($child_name); ?></td>
                                    <td><?php echo h($sex); ?></td>

                                    <td><?php echo h($baseline_date !== 'N/A' ? format_date_value($baseline_date, 'N/A') : 'N/A'); ?></td>
                                    <td class="<?php echo h(status_class($baseline_wfa)); ?>"><?php echo h($baseline_wfa); ?></td>
                                    <td class="<?php echo h(status_class($baseline_hfa)); ?>"><?php echo h($baseline_hfa); ?></td>
                                    <td class="<?php echo h(status_class($baseline_wflh)); ?>"><?php echo h($baseline_wflh); ?></td>

                                    <td><?php echo h($midline_date !== 'N/A' ? format_date_value($midline_date, 'N/A') : 'N/A'); ?></td>
                                    <td class="<?php echo h(status_class($midline_wfa)); ?>"><?php echo h($midline_wfa); ?></td>
                                    <td class="<?php echo h(status_class($midline_hfa)); ?>"><?php echo h($midline_hfa); ?></td>
                                    <td class="<?php echo h(status_class($midline_wflh)); ?>"><?php echo h($midline_wflh); ?></td>

                                    <td><?php echo h($endline_date !== 'N/A' ? format_date_value($endline_date, 'N/A') : 'N/A'); ?></td>
                                    <td class="<?php echo h(status_class($endline_wfa)); ?>"><?php echo h($endline_wfa); ?></td>
                                    <td class="<?php echo h(status_class($endline_hfa)); ?>"><?php echo h($endline_hfa); ?></td>
                                    <td class="<?php echo h(status_class($endline_wflh)); ?>"><?php echo h($endline_wflh); ?></td>

                                    <td><?php echo h($improvement); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No terminal report rows found</h3>
                    <p>This submitted report does not contain readable terminal report row data in the payload.</p>
                </div>

                <?php if (!empty($payload)): ?>
                    <div class="payload-preview">
                        <pre><?php echo h(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');
const mainContent = document.getElementById('mainContent');

function handleDesktopToggle() {
    if (!sidebar || !mainContent) return;
    sidebar.classList.toggle('hidden');
    mainContent.classList.toggle('full');
}

function handleMobileToggle() {
    if (!sidebar || !sidebarOverlay) return;
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
    if (window.innerWidth > 991 && sidebar && sidebarOverlay) {
        sidebar.classList.remove('show');
        sidebarOverlay.classList.remove('show');
    }
});
</script>

</body>
</html>