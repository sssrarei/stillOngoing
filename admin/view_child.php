<?php
include '../includes/auth.php';
include '../config/database.php';

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

$guardian = null;

if (table_exists($conn, 'parent_child_links') && table_exists($conn, 'guardians') && table_exists($conn, 'users')) {
    $guardian_sql = "
        SELECT 
            g.*,
            u.first_name AS user_first_name,
            u.last_name AS user_last_name,
            u.email AS user_email
        FROM parent_child_links pcl
        INNER JOIN users u
            ON pcl.parent_id = u.user_id
        LEFT JOIN guardians g
            ON g.user_id = u.user_id
        WHERE pcl.child_id = ?
        LIMIT 1
    ";

    $guardian_stmt = mysqli_prepare($conn, $guardian_sql);

    if ($guardian_stmt) {
        mysqli_stmt_bind_param($guardian_stmt, 'i', $child_id);
        mysqli_stmt_execute($guardian_stmt);
        $guardian_result = mysqli_stmt_get_result($guardian_stmt);

        if ($guardian_result && mysqli_num_rows($guardian_result) > 0) {
            $guardian = mysqli_fetch_assoc($guardian_result);
        }

        mysqli_stmt_close($guardian_stmt);
    }
}

$health_info = null;

if (table_exists($conn, 'child_health_information')) {
    $health_sql = "
        SELECT *
        FROM child_health_information
        WHERE child_id = ?
        LIMIT 1
    ";

    $health_stmt = mysqli_prepare($conn, $health_sql);
    if ($health_stmt) {
        mysqli_stmt_bind_param($health_stmt, 'i', $child_id);
        mysqli_stmt_execute($health_stmt);
        $health_result = mysqli_stmt_get_result($health_stmt);
        if ($health_result) {
            $health_info = mysqli_fetch_assoc($health_result);
        }
        mysqli_stmt_close($health_stmt);
    }
}

/*
    Fallback:
    If official child_health_information is empty or outdated,
    get the latest approved guardian health submission for display.
*/
if (table_exists($conn, 'child_health_information_requests')) {
    $request_sql = "
        SELECT *
        FROM child_health_information_requests
        WHERE child_id = ?
          AND status = 'Approved'
        ORDER BY submitted_at DESC, request_id DESC
        LIMIT 1
    ";

    $request_stmt = mysqli_prepare($conn, $request_sql);

    if ($request_stmt) {
        mysqli_stmt_bind_param($request_stmt, 'i', $child_id);
        mysqli_stmt_execute($request_stmt);
        $request_result = mysqli_stmt_get_result($request_stmt);

        if ($request_result && mysqli_num_rows($request_result) > 0) {
            $latest_request = mysqli_fetch_assoc($request_result);

            if (!is_array($health_info)) {
                $health_info = [];
            }

            if (!empty($latest_request['vaccination_card_file_path'])) {
                $health_info['vaccination_card_file_path'] = $latest_request['vaccination_card_file_path'];
            }

            if (!empty($latest_request['allergies'])) {
                $health_info['allergies'] = $latest_request['allergies'];
            }

            if (!empty($latest_request['comorbidities'])) {
                $health_info['comorbidities'] = $latest_request['comorbidities'];
            }

            if (!empty($latest_request['medical_history_file_path'])) {
                $health_info['medical_history_file_path'] = $latest_request['medical_history_file_path'];
            }
        }

        mysqli_stmt_close($request_stmt);
    }
}

$anthro_columns = get_table_columns($conn, 'anthropometric_records');
$anthro_order_parts = [];

if (has_column($anthro_columns, 'date_recorded')) {
    $anthro_order_parts[] = 'date_recorded DESC';
}
if (has_column($anthro_columns, 'anthropometric_id')) {
    $anthro_order_parts[] = 'anthropometric_id DESC';
}
if (empty($anthro_order_parts)) {
    $anthro_order_parts[] = 'child_id DESC';
}

$anthro_sql = "
    SELECT *
    FROM anthropometric_records
    WHERE child_id = ?
    ORDER BY " . implode(', ', $anthro_order_parts);

$anthro_history = [];
$latest_anthro = null;

$anthro_stmt = mysqli_prepare($conn, $anthro_sql);
if ($anthro_stmt) {
    mysqli_stmt_bind_param($anthro_stmt, 'i', $child_id);
    mysqli_stmt_execute($anthro_stmt);
    $anthro_result = mysqli_stmt_get_result($anthro_stmt);

    if ($anthro_result) {
        while ($row = mysqli_fetch_assoc($anthro_result)) {
            $anthro_history[] = $row;
        }
    }

    mysqli_stmt_close($anthro_stmt);
}

if (!empty($anthro_history)) {
    $latest_anthro = $anthro_history[0];
}

$feeding_rows = [];

if (table_exists($conn, 'feeding_records') && table_exists($conn, 'feeding_record_items')) {
    $feeding_sql = "
        SELECT
            fr.feeding_record_id,
            fr.feeding_date,
            fr.attendance,
            fr.remarks,
            GROUP_CONCAT(
                CASE
                    WHEN fi.food_item_name IS NOT NULL AND fi.food_item_name != '' THEN
                        CONCAT(
                            COALESCE(fg.food_group_name, 'Uncategorized'),
                            ': ',
                            fi.food_item_name,
                            CASE
                                WHEN fri.measurement_text IS NOT NULL AND fri.measurement_text != '' 
                                    THEN CONCAT(' (', fri.measurement_text, ')')
                                WHEN fri.quantity IS NOT NULL 
                                    THEN CONCAT(' (', CAST(fri.quantity AS CHAR), ')')
                                ELSE ''
                            END
                        )
                    ELSE NULL
                END
                ORDER BY fri.feeding_item_id ASC
                SEPARATOR '||'
            ) AS food_details
        FROM feeding_records fr
        LEFT JOIN feeding_record_items fri 
            ON fr.feeding_record_id = fri.feeding_record_id
        LEFT JOIN food_groups fg 
            ON fri.food_group_id = fg.food_group_id
        LEFT JOIN food_items fi 
            ON fri.food_item_id = fi.food_item_id
        WHERE fr.child_id = ?
        GROUP BY 
            fr.feeding_record_id,
            fr.feeding_date,
            fr.attendance,
            fr.remarks
        ORDER BY fr.feeding_date DESC, fr.feeding_record_id DESC
    ";

    $feeding_stmt = mysqli_prepare($conn, $feeding_sql);

    if ($feeding_stmt) {
        mysqli_stmt_bind_param($feeding_stmt, 'i', $child_id);
        mysqli_stmt_execute($feeding_stmt);
        $feeding_result = mysqli_stmt_get_result($feeding_stmt);

        if ($feeding_result) {
            while ($row = mysqli_fetch_assoc($feeding_result)) {
                $feeding_rows[] = $row;
            }
        }

        mysqli_stmt_close($feeding_stmt);
    }
}

$milk_rows = [];
if (table_exists($conn, 'milk_feeding_records')) {
    $milk_cols = get_table_columns($conn, 'milk_feeding_records');
    $milk_date_col = first_existing_column($milk_cols, ['feeding_date', 'date_recorded', 'record_date', 'date']);
    $milk_order = $milk_date_col ? "`$milk_date_col` DESC" : "child_id DESC";

    $milk_sql = "SELECT * FROM milk_feeding_records WHERE child_id = ? ORDER BY $milk_order";
    $milk_stmt = mysqli_prepare($conn, $milk_sql);

    if ($milk_stmt) {
        mysqli_stmt_bind_param($milk_stmt, 'i', $child_id);
        mysqli_stmt_execute($milk_stmt);
        $milk_result = mysqli_stmt_get_result($milk_stmt);

        if ($milk_result) {
            while ($row = mysqli_fetch_assoc($milk_result)) {
                $milk_rows[] = $row;
            }
        }

        mysqli_stmt_close($milk_stmt);
    }
}

$deworming_rows = [];
if (table_exists($conn, 'deworming_records')) {
    $deworm_cols = get_table_columns($conn, 'deworming_records');
    $deworm_date_col = first_existing_column($deworm_cols, ['deworming_date', 'date_recorded', 'record_date', 'date']);
    $deworm_order = $deworm_date_col ? "`$deworm_date_col` DESC" : "child_id DESC";

    $deworm_sql = "SELECT * FROM deworming_records WHERE child_id = ? ORDER BY $deworm_order";
    $deworm_stmt = mysqli_prepare($conn, $deworm_sql);

    if ($deworm_stmt) {
        mysqli_stmt_bind_param($deworm_stmt, 'i', $child_id);
        mysqli_stmt_execute($deworm_stmt);
        $deworm_result = mysqli_stmt_get_result($deworm_stmt);

        if ($deworm_result) {
            while ($row = mysqli_fetch_assoc($deworm_result)) {
                $deworming_rows[] = $row;
            }
        }

        mysqli_stmt_close($deworm_stmt);
    }
}

$growth_points = [];
$anthro_history_asc = array_reverse($anthro_history);

foreach ($anthro_history_asc as $row) {
    $record_date_raw = !empty($row['date_recorded']) ? $row['date_recorded'] : '';
    $growth_points[] = [
        'x' => age_in_months($child['birthdate'], $record_date_raw),
        'height' => isset($row['height']) && is_numeric($row['height']) ? $row['height'] : '',
        'weight' => isset($row['weight']) && is_numeric($row['weight']) ? $row['weight'] : '',
        'date' => format_date_value($record_date_raw),
        'assessment_type' => isset($row['assessment_type']) ? $row['assessment_type'] : '-'
    ];
}

$weight_chart = build_svg_chart($growth_points, 'weight', 'Weight', 'kg', '#3b82f6');
$height_chart = build_svg_chart($growth_points, 'height', 'Height', 'cm', '#22c55e');

$child_name = get_full_name_from_row($child);
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

        <div class="view-card">
            <div class="view-card-header">Child Information</div>
            <div class="view-card-body">
                <div class="profile-grid">
                    <div class="mini-card">
                        <div class="mini-card-header">Child Profile</div>
                        <div class="mini-card-body">
                            <div class="detail-list">
                                <div class="detail-item"><strong>Child Name:</strong> <?php echo h($child_name); ?></div>
                                <div class="detail-item"><strong>Sex:</strong> <?php echo h(normalize_sex($child['sex'] ?? '')); ?></div>
                                <div class="detail-item"><strong>Birthdate:</strong> <?php echo h(format_date_value($child['birthdate'] ?? '')); ?></div>
                                <div class="detail-item"><strong>Age:</strong> <?php echo h(calculate_age_display($child['birthdate'] ?? '')); ?></div>
                                <div class="detail-item"><strong>CDC:</strong> <?php echo h($child['cdc_name'] ?? '-'); ?></div>
                                <div class="detail-item"><strong>Address:</strong> <?php echo h($child['address'] ?? '-'); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="mini-card">
                        <div class="mini-card-header">Guardian Information</div>
                        <div class="mini-card-body">
                            <div class="detail-list">
                                <?php if ($guardian) { ?>
                                    <?php
                                        $guardian_name_display = get_full_name_from_row($guardian);

                                        if ($guardian_name_display === 'N/A' || $guardian_name_display === '-') {
                                            $guardian_name_display = trim(($guardian['user_first_name'] ?? '') . ' ' . ($guardian['user_last_name'] ?? ''));
                                        }

                                        if ($guardian_name_display === '') {
                                            $guardian_name_display = '-';
                                        }

                                        $guardian_email_display = !empty($guardian['email'])
                                            ? $guardian['email']
                                            : ($guardian['user_email'] ?? '-');

                                        $guardian_contact_display = !empty($guardian['contact_number'])
                                            ? $guardian['contact_number']
                                            : ($child['contact_number'] ?? '-');

                                        $guardian_address_display = !empty($guardian['address'])
                                            ? $guardian['address']
                                            : ($child['address'] ?? '-');
                                    ?>

                                    <div class="detail-item"><strong>Guardian Name:</strong> <?php echo h($guardian_name_display); ?></div>
                                    <div class="detail-item"><strong>Email:</strong> <?php echo h($guardian_email_display); ?></div>
                                    <div class="detail-item"><strong>Contact Number:</strong> <?php echo h($guardian_contact_display); ?></div>
                                    <div class="detail-item"><strong>Address:</strong> <?php echo h($guardian_address_display); ?></div>
                                <?php } else { ?>
                                    <div class="detail-item"><strong>Guardian Name:</strong> <?php echo h($child['guardian_name'] ?? '-'); ?></div>
                                    <div class="detail-item"><strong>Contact Number:</strong> <?php echo h($child['guardian_contact'] ?? ($child['contact_number'] ?? '-')); ?></div>
                                    <div class="detail-item"><strong>Address:</strong> <?php echo h($child['address'] ?? '-'); ?></div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>

                <div class="mini-card">
    <div class="mini-card-header">Health Information</div>
    <div class="mini-card-body">
        <div class="detail-list">

            <div class="detail-item">
                <strong>Vaccination Card File:</strong>
                <?php if (!empty($health_info['vaccination_card_file_path'])) { ?>
                    <a href="<?php echo h($health_info['vaccination_card_file_path']); ?>" target="_blank">
                        View Attached File
                    </a>
                <?php } else { ?>
                    -
                <?php } ?>
            </div>

            <div class="detail-item">
                <strong>Allergies / Allergen:</strong> <?php echo h($health_info['allergies'] ?? '-'); ?>
            </div>

            <div class="detail-item">
                <strong>Comorbidities:</strong> <?php echo h($health_info['comorbidities'] ?? '-'); ?>
            </div>

            <div class="detail-item">
                <strong>Medical History File:</strong>
                <?php if (!empty($health_info['medical_history_file_path'])) { ?>
                    <a href="<?php echo h($health_info['medical_history_file_path']); ?>" target="_blank">
                        View Attached File
                    </a>
                <?php } else { ?>
                    -
                <?php } ?>
            </div>

        </div>
    </div>
</div>

                    <div class="mini-card">
                        <div class="mini-card-header">Latest Nutritional Status</div>
                        <div class="mini-card-body">
                            <div class="detail-list">
                                <div class="detail-item">
                                    <strong>Latest Record Date:</strong>
                                    <?php echo h(format_date_value($latest_anthro['date_recorded'] ?? '')); ?>
                                </div>
                                <div class="detail-item">
                                    <strong>WFA:</strong>
                                    <span class="<?php echo h(status_class($latest_wfa)); ?>"><?php echo h($latest_wfa); ?></span>
                                </div>
                                <div class="detail-item">
                                    <strong>HFA:</strong>
                                    <span class="<?php echo h(status_class($latest_hfa)); ?>"><?php echo h($latest_hfa); ?></span>
                                </div>
                                <div class="detail-item">
                                    <strong>WFL/H:</strong>
                                    <span class="<?php echo h(status_class($latest_wflh)); ?>"><?php echo h($latest_wflh); ?></span>
                                </div>
                                <div class="detail-item">
                                    <strong>Height:</strong> <?php echo h($latest_anthro['height'] ?? '-'); ?>
                                </div>
                                <div class="detail-item">
                                    <strong>Weight:</strong> <?php echo h($latest_anthro['weight'] ?? '-'); ?>
                                </div>
                                <div class="detail-item">
                                    <strong>MUAC:</strong> <?php echo h($latest_anthro['muac'] ?? '-'); ?>
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
                                        <td><?php echo h($row['assessment_type'] ?? '-'); ?></td>
                                        <td><?php echo h($row['height'] ?? '-'); ?></td>
                                        <td><?php echo h($row['weight'] ?? '-'); ?></td>
                                        <td><?php echo h($row['muac'] ?? '-'); ?></td>
                                        <td><?php echo h($row['edema_status'] ?? '-'); ?></td>
                                        <td><?php echo h($row['edema_grade'] ?? '-'); ?></td>
                                        <td><?php echo h($row['muac_status'] ?? '-'); ?></td>
                                        <td class="<?php echo h(status_class($row['wfa_status'] ?? '')); ?>"><?php echo h($row['wfa_status'] ?? '-'); ?></td>
                                        <td class="<?php echo h(status_class($row['hfa_status'] ?? '')); ?>"><?php echo h($row['hfa_status'] ?? '-'); ?></td>
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
                                                if (!empty($row['food_details'])) {
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