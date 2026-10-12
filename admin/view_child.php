<?php
include '../includes/auth.php';
include '../config/database.php';
include '../includes/status_label.php';

if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 1) {
    header("Location: ../login.php");
    exit();
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function table_exists($conn, $table_name)
{
    $table_name = mysqli_real_escape_string($conn, $table_name);
    $sql = "SHOW TABLES LIKE '{$table_name}'";
    $result = mysqli_query($conn, $sql);
    return $result && mysqli_num_rows($result) > 0;
}

function get_table_columns($conn, $table_name)
{
    $columns = [];
    if (!table_exists($conn, $table_name)) {
        return $columns;
    }

    $result = mysqli_query($conn, "SHOW COLUMNS FROM `$table_name`");
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $columns[] = $row['Field'];
        }
    }

    return $columns;
}

function has_column($columns, $column_name)
{
    return in_array($column_name, $columns, true);
}

function first_existing_column($columns, $candidates)
{
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) {
            return $candidate;
        }
    }
    return null;
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

    return $sex !== '' ? ucfirst($sex) : '-';
}

function format_date_value($date)
{
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '-';
    }

    $timestamp = strtotime($date);
    if (!$timestamp) {
        return '-';
    }

    return date('M d, Y', $timestamp);
}

function calculate_age_display($birthdate)
{
    if (empty($birthdate) || $birthdate === '0000-00-00') {
        return '-';
    }

    try {
        $birth = new DateTime($birthdate);
        $today = new DateTime();
        $diff = $today->diff($birth);

        $parts = [];

        if ($diff->y > 0) {
            $parts[] = $diff->y . ' yr' . ($diff->y > 1 ? 's' : '');
        }

        if ($diff->m > 0) {
            $parts[] = $diff->m . ' mo' . ($diff->m > 1 ? 's' : '');
        }

        if (empty($parts)) {
            $parts[] = $diff->d . ' day' . ($diff->d > 1 ? 's' : '');
        }

        return implode(', ', $parts);
    } catch (Exception $e) {
        return '-';
    }
}

function age_in_months($birthdate, $recorded_date = null)
{
    if (empty($birthdate) || $birthdate === '0000-00-00') {
        return '';
    }

    try {
        $birth = new DateTime($birthdate);
        $target = $recorded_date ? new DateTime($recorded_date) : new DateTime();
        $diff = $birth->diff($target);
        return ($diff->y * 12) + $diff->m;
    } catch (Exception $e) {
        return '';
    }
}

function get_full_name_from_row($row)
{
    $parts = [];

    if (!empty($row['first_name'])) {
        $parts[] = trim($row['first_name']);
    }

    if (!empty($row['middle_name'])) {
        $parts[] = trim($row['middle_name']);
    }

    if (!empty($row['last_name'])) {
        $parts[] = trim($row['last_name']);
    }

    $full_name = trim(implode(' ', $parts));
    return $full_name !== '' ? $full_name : 'N/A';
}

function status_class($value)
{
    $value = strtolower(trim((string)$value));
    if ($value === '' || $value === '-') {
        return '';
    }

    return ($value === 'normal') ? 'status-normal' : 'status-alert';
}

function build_svg_chart($points, $value_key, $label, $unit, $color)
{
    if (count($points) < 2) {
        return '';
    }

    $filtered = [];
    foreach ($points as $point) {
        if (
            isset($point['x']) && $point['x'] !== '' &&
            isset($point[$value_key]) && $point[$value_key] !== '' &&
            is_numeric($point['x']) &&
            is_numeric($point[$value_key])
        ) {
            $filtered[] = [
                'x' => (float)$point['x'],
                'y' => (float)$point[$value_key],
                'date' => isset($point['date']) ? $point['date'] : '-',
                'display_value' => isset($point[$value_key]) ? $point[$value_key] : '',
                'assessment_type' => isset($point['assessment_type']) ? $point['assessment_type'] : '-'
            ];
        }
    }

    if (count($filtered) < 2) {
        return '';
    }

    $width = 640;
    $height = 300;
    $padding_left = 68;
    $padding_right = 24;
    $padding_top = 24;
    $padding_bottom = 52;

    $plot_width = $width - $padding_left - $padding_right;
    $plot_height = $height - $padding_top - $padding_bottom;

    $x_values = array_column($filtered, 'x');
    $y_values = array_column($filtered, 'y');

    $min_x = min($x_values);
    $max_x = max($x_values);
    $min_y = min($y_values);
    $max_y = max($y_values);

    if ($min_x == $max_x) {
        $min_x -= 1;
        $max_x += 1;
    }

    if ($min_y == $max_y) {
        $min_y -= 1;
        $max_y += 1;
    }

    $y_padding = max(0.5, ($max_y - $min_y) * 0.15);
    $min_y -= $y_padding;
    $max_y += $y_padding;

    $points_attr = [];
    $circle_markup = '';

    foreach ($filtered as $point) {
        $x = $padding_left + (($point['x'] - $min_x) / ($max_x - $min_x)) * $plot_width;
        $y = $padding_top + $plot_height - (($point['y'] - $min_y) / ($max_y - $min_y)) * $plot_height;

        $points_attr[] = round($x, 2) . ',' . round($y, 2);

        $tooltip_text = $label . ': ' . $point['display_value'] . ' ' . $unit . ' | Date: ' . $point['date'] . ' | Age: ' . $point['x'] . ' month(s)';

        $circle_markup .= '
            <g class="chart-point-group" onclick="showGraphPointInfo(\'' . h(addslashes($label)) . '\', \'' . h(addslashes($point["date"])) . '\', \'' . h(addslashes($point["display_value"])) . '\', \'' . h(addslashes($unit)) . '\', \'' . h(addslashes((string)$point["x"])) . '\', \'' . h(addslashes($point["assessment_type"])) . '\')">
                <circle cx="' . round($x, 2) . '" cy="' . round($y, 2) . '" r="7" fill="#ffffff" stroke="' . $color . '" stroke-width="4" style="cursor:pointer;"></circle>
                <title>' . h($tooltip_text) . '</title>
            </g>
        ';
    }

    $polyline = implode(' ', $points_attr);

    $grid_lines = '';
    $y_axis_labels = '';
    for ($i = 0; $i <= 5; $i++) {
        $y = $padding_top + ($plot_height / 5) * $i;
        $grid_lines .= '<line x1="' . $padding_left . '" y1="' . round($y, 2) . '" x2="' . ($width - $padding_right) . '" y2="' . round($y, 2) . '" stroke="#e5e7eb" stroke-width="1"></line>';

        $label_value = $max_y - (($max_y - $min_y) / 5) * $i;
        $y_axis_labels .= '
            <text x="' . ($padding_left - 12) . '" y="' . (round($y, 2) + 4) . '" text-anchor="end" font-size="12" fill="#64748b">
                ' . number_format($label_value, 1) . '
            </text>
        ';
    }

    $x_axis = '<line x1="' . $padding_left . '" y1="' . ($height - $padding_bottom) . '" x2="' . ($width - $padding_right) . '" y2="' . ($height - $padding_bottom) . '" stroke="#94a3b8" stroke-width="1.5"></line>';
    $y_axis = '<line x1="' . $padding_left . '" y1="' . $padding_top . '" x2="' . $padding_left . '" y2="' . ($height - $padding_bottom) . '" stroke="#94a3b8" stroke-width="1.5"></line>';

    $min_x_label = '
        <text x="' . $padding_left . '" y="' . ($height - $padding_bottom + 18) . '" text-anchor="middle" font-size="12" fill="#64748b">
            ' . number_format($min_x, 0) . '
        </text>
    ';

    $max_x_label = '
        <text x="' . ($width - $padding_right) . '" y="' . ($height - $padding_bottom + 18) . '" text-anchor="middle" font-size="12" fill="#64748b">
            ' . number_format($max_x, 0) . '
        </text>
    ';

    $title = h($label . ' Graph');

    return '
        <div class="svg-chart-box">
            <div class="svg-chart-title">' . $title . '</div>
            <svg class="svg-chart" viewBox="0 0 ' . $width . ' ' . $height . '" xmlns="http://www.w3.org/2000/svg">
                ' . $grid_lines . '
                ' . $x_axis . '
                ' . $y_axis . '
                ' . $y_axis_labels . '
                ' . $min_x_label . '
                ' . $max_x_label . '
                <polyline fill="none" stroke="' . $color . '" stroke-width="3" points="' . $polyline . '"></polyline>
                ' . $circle_markup . '
                <text x="' . ($width / 2) . '" y="' . ($height - 8) . '" text-anchor="middle" font-size="12" fill="#475569">Age in Months</text>
                <text x="18" y="' . ($height / 2) . '" text-anchor="middle" font-size="12" fill="#475569" transform="rotate(-90 18 ' . ($height / 2) . ')">' . h($label . ' (' . $unit . ')') . '</text>
            </svg>
        </div>
    ';
}

$child_id = isset($_GET['child_id']) ? (int)$_GET['child_id'] : 0;

if ($child_id <= 0) {
    die('Invalid child ID.');
}

$child_sql = "
    SELECT ch.*, c.cdc_name
    FROM children ch
    LEFT JOIN cdc c ON c.cdc_id = ch.cdc_id
    WHERE ch.child_id = ?
    LIMIT 1
";

$child_stmt = mysqli_prepare($conn, $child_sql);
if (!$child_stmt) {
    die('Failed to prepare child query.');
}

mysqli_stmt_bind_param($child_stmt, 'i', $child_id);
mysqli_stmt_execute($child_stmt);
$child_result = mysqli_stmt_get_result($child_stmt);
$child = $child_result ? mysqli_fetch_assoc($child_result) : null;
mysqli_stmt_close($child_stmt);

if (!$child) {
    die('Child record not found.');
}

/*
    Everything below comes from the Individual Child Report that the CDW
    submitted (snapshot saved inside submitted_reports). Nothing here is read
    live from the CDW's records, so the admin only sees data after the CDW
    has submitted the report.
*/
$snapshot = null;
$snapshot_report = null;
$snapshot_notice = '';
$found_old_format = false;

if (table_exists($conn, 'submitted_reports')) {
    $like_child = '%"child_id":' . $child_id . ',%';

    $report_sql = "
        SELECT submitted_report_id, submitted_at, status, report_payload
        FROM submitted_reports
        WHERE LOWER(report_type) = 'individual_child'
          AND status IN ('submitted', 'saved_to_child_profile')
          AND report_payload LIKE ?
        ORDER BY submitted_at DESC, submitted_report_id DESC
    ";

    $report_stmt = mysqli_prepare($conn, $report_sql);

    if ($report_stmt) {
        mysqli_stmt_bind_param($report_stmt, 's', $like_child);
        mysqli_stmt_execute($report_stmt);
        $report_result = mysqli_stmt_get_result($report_stmt);

        if ($report_result) {
            while ($report_row = mysqli_fetch_assoc($report_result)) {
                $decoded = json_decode((string)$report_row['report_payload'], true);

                if (!is_array($decoded) || (int)($decoded['child_id'] ?? 0) !== $child_id) {
                    continue;
                }

                if (isset($decoded['profile_sections']) && is_array($decoded['profile_sections'])) {
                    $snapshot = $decoded;
                    $snapshot_report = $report_row;
                    break;
                }

                $found_old_format = true;
            }
        }

        mysqli_stmt_close($report_stmt);
    }
}

if ($snapshot === null) {
    $snapshot_notice = $found_old_format
        ? 'The Individual Child Report for this child was submitted before the report was updated, so it has no full details. Please ask the CDW to submit the Individual Child Report again.'
        : 'The CDW has not submitted the Individual Child Report for this child yet. The full child data will appear here once it is submitted.';
}

$profile_sections = $snapshot ? $snapshot['profile_sections'] : [];
$anthro_history = ($snapshot && isset($snapshot['anthropometric_rows']) && is_array($snapshot['anthropometric_rows'])) ? $snapshot['anthropometric_rows'] : [];
$feeding_rows = ($snapshot && isset($snapshot['feeding_rows']) && is_array($snapshot['feeding_rows'])) ? $snapshot['feeding_rows'] : [];
$milk_rows = ($snapshot && isset($snapshot['milk_rows']) && is_array($snapshot['milk_rows'])) ? $snapshot['milk_rows'] : [];
$deworming_rows = ($snapshot && isset($snapshot['deworming_rows']) && is_array($snapshot['deworming_rows'])) ? $snapshot['deworming_rows'] : [];
$latest_anthro = !empty($anthro_history) ? $anthro_history[0] : null;

$snapshot_birthdate = $snapshot ? ($snapshot['birthdate'] ?? ($child['birthdate'] ?? '')) : ($child['birthdate'] ?? '');

$growth_points = [];
$anthro_history_asc = array_reverse($anthro_history);

foreach ($anthro_history_asc as $row) {
    $record_date_raw = !empty($row['date_recorded']) ? $row['date_recorded'] : '';
    $age_x = (isset($row['age_months']) && $row['age_months'] !== '' && $row['age_months'] !== null)
        ? (int)$row['age_months']
        : age_in_months($snapshot_birthdate, $record_date_raw);

    $growth_points[] = [
        'x' => $age_x,
        'height' => isset($row['height']) && is_numeric($row['height']) ? $row['height'] : '',
        'weight' => isset($row['weight']) && is_numeric($row['weight']) ? $row['weight'] : '',
        'date' => format_date_value($record_date_raw),
        'assessment_type' => isset($row['assessment_type']) ? $row['assessment_type'] : '-'
    ];
}

$weight_chart = build_svg_chart($growth_points, 'weight', 'Weight', 'kg', '#3b82f6');
$height_chart = build_svg_chart($growth_points, 'height', 'Height', 'cm', '#22c55e');

$child_name = ($snapshot && !empty($snapshot['child_name'])) ? $snapshot['child_name'] : get_full_name_from_row($child);
$latest_wfa = !empty($latest_anthro['wfa_status']) ? $latest_anthro['wfa_status'] : '-';
$latest_hfa = !empty($latest_anthro['hfa_status']) ? $latest_anthro['hfa_status'] : '-';
$latest_wflh = !empty($latest_anthro['wflh_status']) ? $latest_anthro['wflh_status'] : '-';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Child | NutriTrack</title>
    <link rel="stylesheet" href="../assets/admin/admin-style.css?v=1">
    <link rel="stylesheet" href="../assets/admin/admin-view_child.css?v=1">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
</head>
<body>

<?php include '../includes/admin_topbar.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="view-shell">

        <div class="view-breadcrumb">
            <a href="child_records.php">Child Records</a> /
            <strong>View Child Profile</strong>
        </div>

        <div class="view-title">Child Profile</div>

        <div class="view-tabs">
            <div class="view-tab active">Full Child Data</div>
        </div>

        <?php if ($snapshot) { ?>
        <div class="view-card" style="margin-bottom:18px;">
            <div class="view-card-body">
                <strong>Submitted by CDW:</strong>
                <?php echo h(!empty($snapshot_report['submitted_at']) ? date('M d, Y g:i A', strtotime($snapshot_report['submitted_at'])) : '-'); ?>
                &nbsp;|&nbsp; This is the Individual Child Report exactly as the CDW submitted it.
            </div>
        </div>

        <div class="view-card">
            <div class="view-card-header">Child Information</div>
            <div class="view-card-body">
                <div class="profile-grid">
                    <?php foreach ($profile_sections as $section) { ?>
                        <div class="mini-card">
                            <div class="mini-card-header"><?php echo h($section['title'] ?? ''); ?></div>
                            <div class="mini-card-body">
                                <div class="detail-list">
                                    <?php foreach (($section['rows'] ?? []) as $r) { ?>
                                        <div class="detail-item"><strong><?php echo h($r['label'] ?? ''); ?>:</strong> <?php echo nl2br(h($r['value'] ?? '')); ?></div>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                    <?php } ?>

                    <div class="mini-card">
                        <div class="mini-card-header">Latest Nutritional Status</div>
                        <div class="mini-card-body">
                            <div class="detail-list">
                                <div class="detail-item">
                                    <strong>Latest Record Date:</strong>
                                    <?php echo h(format_date_value($latest_anthro['date_recorded'] ?? '')); ?>
                                </div>
                                <div class="detail-item">
                                    <strong>Assessment Type:</strong> <?php echo h($latest_anthro['assessment_type'] ?? '-'); ?>
                                </div>
                                <div class="detail-item">
                                    <strong>Age in Months:</strong> <?php echo h($latest_anthro['age_months'] ?? '-'); ?>
                                </div>
                                <div class="detail-item">
                                    <strong>WFA:</strong>
                                    <span class="<?php echo h(status_class($latest_wfa)); ?>"><?php echo h($latest_wfa); ?></span>
                                </div>
                                <div class="detail-item">
                                    <strong>HFA:</strong>
                                    <span class="<?php echo h(status_class($latest_hfa)); ?>"><?php echo h(statusLabel($latest_hfa)); ?></span>
                                </div>
                                <div class="detail-item">
                                    <strong>WFL/H:</strong>
                                    <span class="<?php echo h(status_class($latest_wflh)); ?>"><?php echo h($latest_wflh); ?></span>
                                </div>
                                <div class="detail-item">
                                    <strong>Height:</strong> <?php echo h($latest_anthro['height'] ?? '-'); ?> cm
                                </div>
                                <div class="detail-item">
                                    <strong>Weight:</strong> <?php echo h($latest_anthro['weight'] ?? '-'); ?> kg
                                </div>
                                <div class="detail-item">
                                    <strong>MUAC:</strong> <?php echo h($latest_anthro['muac'] ?? '-'); ?> cm
                                </div>
                                <div class="detail-item">
                                    <strong>Edema:</strong> <?php echo h($latest_anthro['edema_status'] ?? '-'); ?>
                                </div>
                                <div class="detail-item">
                                    <strong>Grade:</strong> <?php echo h($latest_anthro['edema_grade'] ?? '-'); ?>
                                </div>
                                <div class="detail-item">
                                    <strong>MUAC Status:</strong> <?php echo h($latest_anthro['muac_status'] ?? '-'); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="view-card" style="margin-top:18px;">
            <div class="view-card-header">Anthropometric History</div>
            <div class="view-card-body">
                <?php if (!empty($anthro_history)) { ?>
                    <div class="view-table-wrap">
                        <table class="view-table">
                            <thead>
                                <tr>
                                    <th>Date Recorded</th>
                                    <th>Age in Months</th>
                                    <th>Assessment Type</th>
                                    <th>Height</th>
                                    <th>Weight</th>
                                    <th>MUAC</th>
                                    <th>Edema</th>
                                    <th>Grade</th>
                                    <th>MUAC Status</th>
                                    <th>WFA</th>
                                    <th>HFA</th>
                                    <th>WFL/H</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($anthro_history as $row) { ?>
                                    <tr>
                                        <td><?php echo h(format_date_value($row['date_recorded'] ?? '')); ?></td>
                                        <td><?php echo h(($row['age_months'] ?? '') !== '' ? $row['age_months'] : age_in_months($snapshot_birthdate, $row['date_recorded'] ?? '')); ?></td>
                                        <td><?php echo h($row['assessment_type'] ?? '-'); ?></td>
                                        <td><?php echo h($row['height'] ?? '-'); ?></td>
                                        <td><?php echo h($row['weight'] ?? '-'); ?></td>
                                        <td><?php echo h($row['muac'] ?? '-'); ?></td>
                                        <td><?php echo h($row['edema_status'] ?? '-'); ?></td>
                                        <td><?php echo h($row['edema_grade'] ?? '-'); ?></td>
                                        <td><?php echo h($row['muac_status'] ?? '-'); ?></td>
                                        <td class="<?php echo h(status_class($row['wfa_status'] ?? '')); ?>"><?php echo h($row['wfa_status'] ?? '-'); ?></td>
                                        <td class="<?php echo h(status_class($row['hfa_status'] ?? '')); ?>"><?php echo h(statusLabel($row['hfa_status'] ?? '-')); ?></td>
                                        <td class="<?php echo h(status_class($row['wflh_status'] ?? '')); ?>"><?php echo h($row['wflh_status'] ?? '-'); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } else { ?>
                    <div class="empty-section">No anthropometric records found.</div>
                <?php } ?>
            </div>
        </div>

        <div class="view-card" style="margin-top:18px;">
            <div class="view-card-header">Feeding History</div>
            <div class="view-card-body">
                <?php if (!empty($feeding_rows)) { ?>
                    <div class="view-table-wrap">
                        <table class="view-table">
                            <thead>
                                <tr>
                                    <th>Feeding Date</th>
                                    <th>Attendance</th>
                                    <th>Food Details</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($feeding_rows as $row) { ?>
                                   <tr>
                                        <td><?php echo h(format_date_value($row['feeding_date'] ?? '')); ?></td>
                                        <td><?php echo h($row['attendance'] ?? '-'); ?></td>
                                        <td class="food-details-cell">
                                            <?php
                                                if (strtolower((string)($row['attendance'] ?? '')) === 'absent') {
                                                    echo 'Absent';
                                                } elseif (!empty($row['food_details'])) {
                                                    $foods = explode('||', $row['food_details']);

                                                    foreach ($foods as $food) {
                                                        echo '<div class="food-detail-line">' . h($food) . '</div>';
                                                    }
                                                } else {
                                                    echo 'N/A';
                                                }
                                            ?>
                                        </td>
                                        <td><?php echo h($row['remarks'] ?? '-'); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } else { ?>
                    <div class="empty-section">No feeding history found.</div>
                <?php } ?>
            </div>
        </div>

        <div class="view-card" style="margin-top:18px;">
            <div class="view-card-header">Milk Feeding</div>
            <div class="view-card-body">
                <?php if (!empty($milk_rows)) { ?>
                    <div class="view-table-wrap">
                        <table class="view-table">
                            <thead>
                                <tr>
                                    <th>Feeding Date</th>
                                    <th>Attendance</th>
                                    <th>Milk Type</th>
                                    <th>Amount</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($milk_rows as $row) { ?>
                                    <tr>
                                        <td><?php echo h(format_date_value($row['feeding_date'] ?? ($row['date_recorded'] ?? ''))); ?></td>
                                        <td><?php echo h($row['attendance'] ?? '-'); ?></td>
                                        <td><?php echo h($row['milk_type'] ?? '-'); ?></td>
                                        <td><?php echo h($row['amount'] ?? '-'); ?></td>
                                        <td><?php echo h($row['remarks'] ?? '-'); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } else { ?>
                    <div class="empty-section">No milk feeding records found.</div>
                <?php } ?>
            </div>
        </div>

        <div class="view-card" style="margin-top:18px;">
            <div class="view-card-header">Deworming</div>
            <div class="view-card-body">
                <?php if (!empty($deworming_rows)) { ?>
                    <div class="view-table-wrap">
                        <table class="view-table">
                            <thead>
                                <tr>
                                    <th>Deworming Date</th>
                                    <th>Attendance / Status</th>
                                    <th>Medicine</th>
                                    <th>Dosage</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deworming_rows as $row) { ?>
                                    <tr>
                                        <td><?php echo h(format_date_value($row['deworming_date'] ?? ($row['date_recorded'] ?? ''))); ?></td>
                                        <td><?php echo h($row['attendance'] ?? ($row['status'] ?? '-')); ?></td>
                                        <td><?php echo h($row['medicine'] ?? '-'); ?></td>
                                        <td><?php echo h($row['dosage'] ?? '-'); ?></td>
                                        <td><?php echo h($row['remarks'] ?? '-'); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } else { ?>
                    <div class="empty-section">No deworming records found.</div>
                <?php } ?>
            </div>
        </div>

        <div class="view-card" style="margin-top:18px;">
            <div class="view-card-header">Growth Graph</div>
            <div class="view-card-body">
                <div class="growth-layout">
                    <div class="graph-info" id="graphPointInfo">
                        <strong>Child:</strong> <?php echo h($child_name); ?><br>
                        <strong>Birthdate:</strong> <?php echo h(format_date_value($child['birthdate'] ?? '')); ?><br>
                        <strong>Total Anthropometric Records:</strong> <?php echo (int)count($anthro_history); ?><br>
                        <strong>Instruction:</strong> Click a graph point to view the date and measurement details.
                    </div>

                    <?php if ($weight_chart !== '' || $height_chart !== '') { ?>
                        <div class="growth-charts">
                            <?php echo $weight_chart; ?>
                            <?php echo $height_chart; ?>
                        </div>
                    <?php } else { ?>
                        <div class="graph-empty">Not enough anthropometric records to display growth charts. At least 2 records are needed.</div>
                    <?php } ?>
                </div>
            </div>
        </div>

        <?php } else { ?>
        <div class="view-card">
            <div class="view-card-header">Child Information</div>
            <div class="view-card-body">
                <div class="empty-section"><?php echo h($snapshot_notice); ?></div>
            </div>
        </div>
        <?php } ?>

        <div class="back-wrap">
            <a href="child_records.php" class="back-link">Back to Child Records</a>
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

function showGraphPointInfo(type, date, value, unit, ageMonths, assessmentType) {
    const graphInfo = document.getElementById('graphPointInfo');
    if (!graphInfo) return;

    graphInfo.innerHTML = `
        <strong>Selected Graph Point</strong><br>
        <strong>Measurement Type:</strong> ${type}<br>
        <strong>Date Recorded:</strong> ${date}<br>
        <strong>Value:</strong> ${value} ${unit}<br>
        <strong>Age in Months:</strong> ${ageMonths}<br>
        <strong>Assessment Type:</strong> ${assessmentType}
    `;
}
</script>

</body>
</html>