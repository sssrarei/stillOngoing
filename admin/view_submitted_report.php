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

function safe_value($value, $fallback = 'N/A')
{
    return (isset($value) && trim((string)$value) !== '') ? $value : $fallback;
}

function build_full_name($first, $middle, $last)
{
    $parts = [];

    if (!empty($first)) $parts[] = trim($first);
    if (!empty($middle)) $parts[] = trim($middle);
    if (!empty($last)) $parts[] = trim($last);

    return trim(implode(' ', $parts));
}

function calculate_age_text($birthdate)
{
    if (empty($birthdate) || $birthdate === '0000-00-00') {
        return 'N/A';
    }

    try {
        $birth = new DateTime($birthdate);
        $today = new DateTime();
        $diff = $today->diff($birth);
        return $diff->y . ' year(s) old';
    } catch (Exception $e) {
        return 'N/A';
    }
}

function calculate_age_months($birthdate, $record_date = null)
{
    if (empty($birthdate) || $birthdate === '0000-00-00') {
        return 'N/A';
    }

    try {
        $birth = new DateTime($birthdate);
        $end = $record_date ? new DateTime($record_date) : new DateTime();
        $diff = $birth->diff($end);
        return ($diff->y * 12) + $diff->m;
    } catch (Exception $e) {
        return 'N/A';
    }
}


function render_attachment_preview($file_path, $label = 'View Attached File')
{
    if (empty($file_path)) {
        return 'N/A';
    }

    $safe_path = h($file_path);
    $safe_label = h($label);

    return '<a href="' . $safe_path . '" target="_blank" class="file-link">' . $safe_label . '</a>';
}

function status_class($status)
{
    $status = strtolower(trim((string) $status));

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
    if (strpos($status, 'moderate') !== false) return 'status-warning';
    if (strpos($status, 'yellow') !== false) return 'status-warning';

    if (strpos($status, 'severe') !== false) return 'status-alert';
    if (strpos($status, 'red') !== false) return 'status-alert';

    return '';
}

function build_page_url($id, $overrides = [], $anchor = '')
{
    $params = $_GET;
    $params['id'] = $id;

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    $url = 'view_submitted_report.php?' . http_build_query($params);

    if ($anchor !== '') {
        $url .= '#' . $anchor;
    }

    return $url;
}

function get_total_rows($conn, $sql, $child_id)
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return 0;
    }

    mysqli_stmt_bind_param($stmt, "i", $child_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $total = 0;
    if ($result && $row = mysqli_fetch_assoc($result)) {
        $total = (int) $row['total'];
    }

    mysqli_stmt_close($stmt);
    return $total;
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("No submitted report selected.");
}

$submitted_report_id = (int) $_GET['id'];
$save_success = '';

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
mysqli_stmt_bind_param($stmt_report, "i", $submitted_report_id);
mysqli_stmt_execute($stmt_report);
$result_report = mysqli_stmt_get_result($stmt_report);

if (!$result_report || mysqli_num_rows($result_report) === 0) {
    die("Submitted report not found.");
}

$report = mysqli_fetch_assoc($result_report);
mysqli_stmt_close($stmt_report);

$payload = json_decode($report['report_payload'], true);
if (!is_array($payload)) {
    $payload = [];
}

$report_type_options = [
    'masterlist' => 'Masterlist of Beneficiaries',
    'individual_child' => 'Individual Child Report',
    'feeding_attendance' => 'Feeding Attendance Report',
    'wmr' => 'Weight Monitoring Report (WMR)',
    'nutritional_status_summary' => 'Nutritional Status Summary',
    'terminal_report' => 'Terminal Report'
];

$display_report_type = isset($report_type_options[$report['report_type']])
    ? $report_type_options[$report['report_type']]
    : ucwords(str_replace('_', ' ', $report['report_type']));

if ($report['report_type'] !== 'individual_child') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>View Submitted Report | NutriTrack</title>
        <link rel="stylesheet" href="../assets/admin/admin-style.css">
        <link rel="stylesheet" href="../assets/admin/view_submitted_report.css">
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    </head>
    <body>

    <?php include '../includes/admin_topbar.php'; ?>
    <?php include '../includes/admin_sidebar.php'; ?>

    <div class="main-content" id="mainContent">
        <div class="report-view-wrapper">
            <div class="report-header-card">
                <a href="monitoring_reports.php" class="back-link">← Back to Monitoring Reports</a>
                <div class="report-header">
                    <h1><?php echo h($display_report_type); ?></h1>
                    <p>This page currently supports the Individual Child Report detailed viewer only.</p>
                </div>
            </div>

            <div class="not-supported">
                The selected submitted report is not an Individual Child Report.
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit();
}

$child_id = isset($payload['child_id']) ? (int) $payload['child_id'] : 0;

if ($child_id <= 0) {
    die("Invalid child reference in submitted report payload.");
}

/* SAVE TO CHILD PROFILE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_to_child_profile'])) {
    if ($report['status'] !== 'saved_to_child_profile') {
        $new_status = 'saved_to_child_profile';
        $update_sql = "UPDATE submitted_reports SET status = ? WHERE submitted_report_id = ? LIMIT 1";
        $stmt_update = mysqli_prepare($conn, $update_sql);

        if ($stmt_update) {
            mysqli_stmt_bind_param($stmt_update, "si", $new_status, $submitted_report_id);
            mysqli_stmt_execute($stmt_update);
            mysqli_stmt_close($stmt_update);
        }
    }

    header("Location: child_records.php?saved=1");
    exit();
}

$child_sql = "
    SELECT
        c.child_id,
        c.first_name,
        c.middle_name,
        c.last_name,
        c.birthdate,
        c.sex,
        c.address,
        c.religion,
        c.guardian_name,
        c.contact_number,
        c.access_code,
        c.cdc_id,
        d.cdc_name,
        d.barangay,
        d.address AS cdc_address,

        g.guardian_id,
        g.first_name AS guardian_first_name,
        g.middle_name AS guardian_middle_name,
        g.last_name AS guardian_last_name,
        g.relationship_to_child,
        g.contact_number AS guardian_contact_number,
        g.email AS guardian_email,
        g.address AS guardian_address,

        chi.health_info_id,
        chi.vaccination_card_file_path,
        chi.allergies,
        chi.comorbidities,
        chi.medical_history_file_path

    FROM children c
    INNER JOIN cdc d ON c.cdc_id = d.cdc_id
    LEFT JOIN guardians g ON c.child_id = g.child_id
    LEFT JOIN child_health_information chi ON c.child_id = chi.child_id
    WHERE c.child_id = ?
    LIMIT 1
";

$stmt_child = mysqli_prepare($conn, $child_sql);
mysqli_stmt_bind_param($stmt_child, "i", $child_id);
mysqli_stmt_execute($stmt_child);
$result_child = mysqli_stmt_get_result($stmt_child);

if (!$result_child || mysqli_num_rows($result_child) === 0) {
    die("Child record not found.");
}

$child = mysqli_fetch_assoc($result_child);
mysqli_stmt_close($stmt_child);

$child_full_name = build_full_name($child['first_name'], $child['middle_name'], $child['last_name']);
$guardian_full_name = build_full_name(
    $child['guardian_first_name'],
    $child['guardian_middle_name'],
    $child['guardian_last_name']
);

if ($guardian_full_name === '') {
    $guardian_full_name = safe_value($child['guardian_name'], 'No guardian linked yet');
}

$birthdate_display = (!empty($child['birthdate']) && $child['birthdate'] !== '0000-00-00')
    ? date('F d, Y', strtotime($child['birthdate']))
    : 'N/A';

$age_text = calculate_age_text($child['birthdate']);
$age_months_now = calculate_age_months($child['birthdate']);

$latest_record = null;

$latest_sql = "
    SELECT
        record_id,
        child_id,
        height,
        weight,
        muac,
        edema_status,
        edema_grade,
        muac_status,
        date_recorded,
        age_months,
        assessment_type,
        wfa_status,
        hfa_status,
        wflh_status
    FROM anthropometric_records
    WHERE child_id = ?
    ORDER BY date_recorded DESC, record_id DESC
    LIMIT 1
";

$stmt_latest = mysqli_prepare($conn, $latest_sql);
mysqli_stmt_bind_param($stmt_latest, "i", $child_id);
mysqli_stmt_execute($stmt_latest);
$result_latest = mysqli_stmt_get_result($stmt_latest);

if ($result_latest && mysqli_num_rows($result_latest) > 0) {
    $latest_record = mysqli_fetch_assoc($result_latest);
}
mysqli_stmt_close($stmt_latest);

$graph_labels = [];
$graph_weights = [];
$graph_heights = [];

$graph_sql = "
    SELECT
        record_id,
        date_recorded,
        age_months,
        height,
        weight
    FROM anthropometric_records
    WHERE child_id = ?
    ORDER BY date_recorded ASC, record_id ASC
";

$stmt_graph = mysqli_prepare($conn, $graph_sql);
mysqli_stmt_bind_param($stmt_graph, "i", $child_id);
mysqli_stmt_execute($stmt_graph);
$result_graph = mysqli_stmt_get_result($stmt_graph);

if ($result_graph) {
    while ($row = mysqli_fetch_assoc($result_graph)) {
        $age_label = (!empty($row['age_months']) || $row['age_months'] === '0')
            ? (int) $row['age_months']
            : calculate_age_months($child['birthdate'], $row['date_recorded']);

        $graph_labels[] = $age_label;
        $graph_weights[] = (float) $row['weight'];
        $graph_heights[] = (float) $row['height'];
    }
}
mysqli_stmt_close($stmt_graph);

$per_page = 5;

$anthro_page = isset($_GET['anthro_page']) ? max(1, (int) $_GET['anthro_page']) : 1;
$feeding_page = isset($_GET['feeding_page']) ? max(1, (int) $_GET['feeding_page']) : 1;
$milk_page = isset($_GET['milk_page']) ? max(1, (int) $_GET['milk_page']) : 1;
$deworm_page = isset($_GET['deworm_page']) ? max(1, (int) $_GET['deworm_page']) : 1;

$anthro_total = get_total_rows($conn, "SELECT COUNT(*) AS total FROM anthropometric_records WHERE child_id = ?", $child_id);
$feeding_total = get_total_rows($conn, "SELECT COUNT(*) AS total FROM feeding_records WHERE child_id = ?", $child_id);
$milk_total = get_total_rows($conn, "SELECT COUNT(*) AS total FROM milk_feeding_records WHERE child_id = ?", $child_id);
$deworm_total = get_total_rows($conn, "SELECT COUNT(*) AS total FROM deworming_records WHERE child_id = ?", $child_id);

$anthro_total_pages = max(1, (int) ceil($anthro_total / $per_page));
$feeding_total_pages = max(1, (int) ceil($feeding_total / $per_page));
$milk_total_pages = max(1, (int) ceil($milk_total / $per_page));
$deworm_total_pages = max(1, (int) ceil($deworm_total / $per_page));

if ($anthro_page > $anthro_total_pages) $anthro_page = $anthro_total_pages;
if ($feeding_page > $feeding_total_pages) $feeding_page = $feeding_total_pages;
if ($milk_page > $milk_total_pages) $milk_page = $milk_total_pages;
if ($deworm_page > $deworm_total_pages) $deworm_page = $deworm_total_pages;

$anthro_offset = ($anthro_page - 1) * $per_page;
$feeding_offset = ($feeding_page - 1) * $per_page;
$milk_offset = ($milk_page - 1) * $per_page;
$deworm_offset = ($deworm_page - 1) * $per_page;

$anthropometric_rows = [];

$anthro_sql = "
    SELECT
        record_id,
        date_recorded,
        age_months,
        height,
        weight,
        muac,
        edema_status,
        edema_grade,
        muac_status,
        assessment_type,
        wfa_status,
        hfa_status,
        wflh_status
    FROM anthropometric_records
    WHERE child_id = ?
    ORDER BY date_recorded DESC, record_id DESC
    LIMIT ?, ?
";

$stmt_anthro = mysqli_prepare($conn, $anthro_sql);
mysqli_stmt_bind_param($stmt_anthro, "iii", $child_id, $anthro_offset, $per_page);
mysqli_stmt_execute($stmt_anthro);
$result_anthro = mysqli_stmt_get_result($stmt_anthro);

if ($result_anthro) {
    while ($row = mysqli_fetch_assoc($result_anthro)) {
        $anthropometric_rows[] = $row;
    }
}
mysqli_stmt_close($stmt_anthro);

$feeding_rows = [];

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
                        ' - ',
                        fi.food_item_name,
                        CASE
                            WHEN fri.measurement_text IS NOT NULL AND fri.measurement_text != '' THEN CONCAT(' (', fri.measurement_text, ')')
                            WHEN fri.quantity IS NOT NULL THEN CONCAT(' (', CAST(fri.quantity AS CHAR), ')')
                            ELSE ''
                        END
                    )
                ELSE NULL
            END
            ORDER BY fri.feeding_item_id ASC
            SEPARATOR '||'
        ) AS food_details
    FROM feeding_records fr
    LEFT JOIN feeding_record_items fri ON fr.feeding_record_id = fri.feeding_record_id
    LEFT JOIN food_groups fg ON fri.food_group_id = fg.food_group_id
    LEFT JOIN food_items fi ON fri.food_item_id = fi.food_item_id
    WHERE fr.child_id = ?
    GROUP BY fr.feeding_record_id, fr.feeding_date, fr.attendance, fr.remarks
    ORDER BY fr.feeding_date DESC, fr.feeding_record_id DESC
    LIMIT ?, ?
";

$stmt_feeding = mysqli_prepare($conn, $feeding_sql);
mysqli_stmt_bind_param($stmt_feeding, "iii", $child_id, $feeding_offset, $per_page);
mysqli_stmt_execute($stmt_feeding);
$result_feeding = mysqli_stmt_get_result($stmt_feeding);

if ($result_feeding) {
    while ($row = mysqli_fetch_assoc($result_feeding)) {
        $feeding_rows[] = $row;
    }
}
mysqli_stmt_close($stmt_feeding);

$milk_rows = [];

$milk_sql = "
    SELECT
        milk_record_id,
        feeding_date,
        attendance,
        milk_type,
        amount,
        remarks
    FROM milk_feeding_records
    WHERE child_id = ?
    ORDER BY feeding_date DESC, milk_record_id DESC
    LIMIT ?, ?
";

$stmt_milk = mysqli_prepare($conn, $milk_sql);
mysqli_stmt_bind_param($stmt_milk, "iii", $child_id, $milk_offset, $per_page);
mysqli_stmt_execute($stmt_milk);
$result_milk = mysqli_stmt_get_result($stmt_milk);

if ($result_milk) {
    while ($row = mysqli_fetch_assoc($result_milk)) {
        $milk_rows[] = $row;
    }
}
mysqli_stmt_close($stmt_milk);

$deworm_rows = [];

$deworm_sql = "
    SELECT
        deworm_id,
        deworming_date,
        attendance,
        medicine,
        dosage,
        remarks
    FROM deworming_records
    WHERE child_id = ?
    ORDER BY deworming_date DESC, deworm_id DESC
    LIMIT ?, ?
";

$stmt_deworm = mysqli_prepare($conn, $deworm_sql);
mysqli_stmt_bind_param($stmt_deworm, "iii", $child_id, $deworm_offset, $per_page);
mysqli_stmt_execute($stmt_deworm);
$result_deworm = mysqli_stmt_get_result($stmt_deworm);

if ($result_deworm) {
    while ($row = mysqli_fetch_assoc($result_deworm)) {
        $deworm_rows[] = $row;
    }
}
mysqli_stmt_close($stmt_deworm);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Submitted Report | NutriTrack</title>
    <link rel="stylesheet" href="/nutritrack/assets/admin/admin-style.css">
    <link rel="stylesheet" href="/nutritrack/assets/admin/view_submitted_report.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<?php include '../includes/admin_topbar.php'; ?>
<?php include '../includes/admin_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="report-view-wrapper">

        <div class="report-header-card">
    <div class="report-actions no-print">
        <a href="monitoring_reports.php" class="back-link">← Back to Monitoring Reports</a>

        <div class="report-action-buttons">
            <?php if ($report['status'] !== 'saved_to_child_profile'): ?>
                <form method="POST" style="margin:0;">
                    <button type="submit" name="save_to_child_profile" class="blue-save-btn">
                        Save to Child Profile
                    </button>
                </form>
            <?php else: ?>
                <span class="saved-badge">Already Saved to Child Profile</span>
                <a href="child_records.php" class="view-btn">Go to Child Profiles</a>
            <?php endif; ?>

            <button type="button" class="print-btn" onclick="window.print()">
                Print / Save as PDF
            </button>
        </div>
    </div>

    <div class="report-header">
        <h1>Individual Child Report</h1>
        
    </div>
</div>

        

        <div class="table-card">
            <div class="table-header">
                <div>
                    <h2>Child Profile</h2>
                    <p>Basic child information from the current live child record.</p>
                </div>
            </div>

            <div class="table-wrap">
                <table class="report-table">
                    <tbody>
                        <tr>
                            <th>Child Name</th>
                            <td><?php echo h($child_full_name); ?></td>
                            <th>Access Code</th>
                            <td><?php echo h(safe_value($child['access_code'])); ?></td>
                        </tr>
                        <tr>
                            <th>Birthdate</th>
                            <td><?php echo h($birthdate_display); ?></td>
                            <th>Age in Months</th>
                            <td><?php echo h($age_months_now); ?></td>
                        </tr>
                        <tr>
                            <th>Sex</th>
                            <td><?php echo h(safe_value($child['sex'])); ?></td>
                            <th>Religion</th>
                            <td><?php echo h(safe_value($child['religion'])); ?></td>
                        </tr>
                        <tr>
                            <th>Address</th>
                            <td colspan="3"><?php echo h(safe_value($child['address'])); ?></td>
                        </tr>
                        <tr>
                            <th>Child Development Center</th>
                            <td><?php echo h(safe_value($child['cdc_name'])); ?></td>
                            <th>CDC Address</th>
                            <td><?php echo h(safe_value($child['cdc_address'])); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-card">
            <div class="table-header">
                <div>
                    <h2>Guardian Information</h2>
                    <p>Parent or guardian information linked to the child.</p>
                </div>
            </div>

            <div class="table-wrap">
                <table class="report-table">
                    <tbody>
                        <tr>
                            <th>Parent / Guardian Name</th>
                            <td><?php echo h($guardian_full_name); ?></td>
                            <th>Relationship to Child</th>
                            <td><?php echo h(safe_value($child['relationship_to_child'])); ?></td>
                        </tr>
                        <tr>
                            <th>Contact Number</th>
                            <td><?php echo h(safe_value($child['guardian_contact_number'], safe_value($child['contact_number']))); ?></td>
                            <th>Email</th>
                            <td><?php echo h(safe_value($child['guardian_email'])); ?></td>
                        </tr>
                        <tr>
                            <th>Address</th>
                            <td colspan="3"><?php echo h(safe_value($child['guardian_address'], safe_value($child['address']))); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="table-card">
            <div class="table-header">
                <div>
                    <h2>Child Health Information</h2>
                    <p>Health-related information submitted for the selected child.</p>
                </div>
            </div>

            <div class="table-wrap">
                <table class="report-table">
                    <tbody>
                      <tr>
    <th>Vaccination Card File</th>
    <td>
        <?php echo render_attachment_preview($child['vaccination_card_file_path'] ?? '', 'View Vaccination Card'); ?>
    </td>

    <th>Medical History File</th>
    <td>
        <?php echo render_attachment_preview($child['medical_history_file_path'] ?? '', 'View Medical History File'); ?>
    </td>
</tr>

<tr>
    <th>Allergies / Allergen</th>
    <td><?php echo h(safe_value($child['allergies'])); ?></td>

    <th>Comorbidities</th>
    <td><?php echo h(safe_value($child['comorbidities'])); ?></td>
</tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="summary-card">
            <div class="table-header">
                <div>
                    <h2>Latest Nutritional Status</h2>
                    <p>Current latest nutritional monitoring result of the child.</p>
                </div>
            </div>

            <?php if ($latest_record): ?>
                <div class="summary-grid">
                    <div class="summary-item">
                        <div class="summary-label">Latest Date Recorded</div>
                        <div class="summary-value"><?php echo h(date('F d, Y', strtotime($latest_record['date_recorded']))); ?></div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Height</div>
                        <div class="summary-value"><?php echo h(safe_value($latest_record['height'])); ?> cm</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">Weight</div>
                        <div class="summary-value"><?php echo h(safe_value($latest_record['weight'])); ?> kg</div>
                    </div>
                    <div class="summary-item">
                        <div class="summary-label">MUAC</div>
                        <div class="summary-value"><?php echo h(safe_value($latest_record['muac'])); ?> cm</div>
                    </div>
                </div>

                <div class="table-wrap section-spacing">
                    <table class="report-table">
                        <tbody>
                            <tr>
                                <th>Age in Months</th>
                                <td><?php echo h(safe_value($latest_record['age_months'], calculate_age_months($child['birthdate'], $latest_record['date_recorded']))); ?></td>
                                <th>Assessment Type</th>
                                <td><?php echo h(safe_value($latest_record['assessment_type'])); ?></td>
                            </tr>
                            <tr>
                                <th>Edema</th>
                                <td><?php echo h(safe_value($latest_record['edema_status'])); ?></td>

                                <th>Grade</th>
                                <td><?php echo h(safe_value($latest_record['edema_grade'])); ?></td>
                            </tr>

                            <tr>
                                <th>MUAC Status</th>
                                <td><?php echo h(safe_value($latest_record['muac_status'])); ?></td>

                                <th>Latest Monitoring Date</th>
                                <td><?php echo h(date('F d, Y', strtotime($latest_record['date_recorded']))); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="terminal-summary-grid">
    <div class="terminal-summary-card">
        <div class="summary-label">WFA</div>
        <div class="terminal-summary-value <?php echo h(status_class($latest_record['wfa_status'])); ?>">
            <?php echo h(safe_value($latest_record['wfa_status'])); ?>
        </div>
    </div>

    <div class="terminal-summary-card">
        <div class="summary-label">HFA</div>
        <div class="terminal-summary-value <?php echo h(status_class($latest_record['hfa_status'])); ?>">
            <?php echo h(safe_value($latest_record['hfa_status'])); ?>
        </div>
    </div>

    <div class="terminal-summary-card">
        <div class="summary-label">WFL/H</div>
        <div class="terminal-summary-value <?php echo h(status_class($latest_record['wflh_status'])); ?>">
            <?php echo h(safe_value($latest_record['wflh_status'])); ?>
        </div>
    </div>

    <div class="terminal-summary-card">
        <div class="summary-label">MUAC Status</div>
        <div class="terminal-summary-value <?php echo h(status_class($latest_record['muac_status'])); ?>">
            <?php echo h(safe_value($latest_record['muac_status'])); ?>
        </div>
    </div>
</div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No latest nutritional status found</h3>
                    <p>No anthropometric record is currently available for this child.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="table-card section-anchor" id="anthropometric-history">
            <div class="table-header">
                <div>
                    <h2>Anthropometric History</h2>
                    <p>Historical anthropometric records of the selected child.</p>
                </div>
                <span class="record-count"><?php echo (int) $anthro_total; ?> record(s)</span>
            </div>

            <?php if (!empty($anthropometric_rows)): ?>
                <div class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Date Recorded</th>
                                <th>Age in Months</th>
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
                            <?php foreach ($anthropometric_rows as $row): ?>
                                <tr>
                                    <td><?php echo h(date('F d, Y', strtotime($row['date_recorded']))); ?></td>
                                    <td><?php echo h(safe_value($row['age_months'], calculate_age_months($child['birthdate'], $row['date_recorded']))); ?></td>
                                    <td><?php echo h(safe_value($row['height'])); ?> cm</td>
                                    <td><?php echo h(safe_value($row['weight'])); ?> kg</td>
                                    <td><?php echo h(safe_value($row['muac'])); ?> cm</td>
                                    <td><?php echo h(safe_value($row['edema_status'])); ?></td>
                                    <td><?php echo h(safe_value($row['edema_grade'])); ?></td>
                                    <td><?php echo h(safe_value($row['muac_status'])); ?></td>
                                    <td><?php echo h(safe_value($row['wfa_status'])); ?></td>
                                    <td><?php echo h(safe_value($row['hfa_status'])); ?></td>
                                    <td><?php echo h(safe_value($row['wflh_status'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-bar">
                    <div class="pagination-info">Page <?php echo (int) $anthro_page; ?> of <?php echo (int) $anthro_total_pages; ?></div>
                    <div class="filter-actions compact">
                        <?php if ($anthro_page > 1): ?>
                            <a class="filter-btn secondary" href="<?php echo h(build_page_url($submitted_report_id, ['anthro_page' => $anthro_page - 1], 'anthropometric-history')); ?>">Previous</a>
                        <?php endif; ?>

                        <?php if ($anthro_page < $anthro_total_pages): ?>
                            <a class="filter-btn secondary" href="<?php echo h(build_page_url($submitted_report_id, ['anthro_page' => $anthro_page + 1], 'anthropometric-history')); ?>">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No anthropometric history found</h3>
                    <p>No anthropometric records are available for this child.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="table-card section-anchor" id="feeding-history">
            <div class="table-header">
                <div>
                    <h2>Feeding History</h2>
                    <p>Current feeding records of the selected child.</p>
                </div>
                <span class="record-count"><?php echo (int) $feeding_total; ?> record(s)</span>
            </div>

            <?php if (!empty($feeding_rows)): ?>
                <div class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Attendance</th>
                                <th>Food Details</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($feeding_rows as $row): ?>
                                <tr>
                                    <td><?php echo h(date('F d, Y', strtotime($row['feeding_date']))); ?></td>
                                    <td>
                                        <?php
                                        $attendance_value = strtolower((string)$row['attendance']);
                                        $badge_class = ($attendance_value === 'present') ? 'badge-present' : 'badge-absent';
                                        ?>
                                        <span class="attendance-badge <?php echo h($badge_class); ?>">
                                            <?php echo h(safe_value($row['attendance'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        if (strtolower((string)$row['attendance']) === 'absent') {
                                            echo 'Absent';
                                        } elseif (!empty($row['food_details'])) {
                                            $foods = explode('||', $row['food_details']);
                                            echo '<ul class="food-details-list">';
                                            foreach ($foods as $food) {
                                                echo '<li>' . h($food) . '</li>';
                                            }
                                            echo '</ul>';
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo h(safe_value($row['remarks'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-bar">
                    <div class="pagination-info">Page <?php echo (int) $feeding_page; ?> of <?php echo (int) $feeding_total_pages; ?></div>
                    <div class="filter-actions compact">
                        <?php if ($feeding_page > 1): ?>
                            <a class="filter-btn secondary" href="<?php echo h(build_page_url($submitted_report_id, ['feeding_page' => $feeding_page - 1], 'feeding-history')); ?>">Previous</a>
                        <?php endif; ?>

                        <?php if ($feeding_page < $feeding_total_pages): ?>
                            <a class="filter-btn secondary" href="<?php echo h(build_page_url($submitted_report_id, ['feeding_page' => $feeding_page + 1], 'feeding-history')); ?>">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No feeding history found</h3>
                    <p>No feeding records are available for this child.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="table-card section-anchor" id="milk-feeding-history">
            <div class="table-header">
                <div>
                    <h2>Milk Feeding History</h2>
                    <p>Current milk feeding records of the selected child.</p>
                </div>
                <span class="record-count"><?php echo (int) $milk_total; ?> record(s)</span>
            </div>

            <?php if (!empty($milk_rows)): ?>
                <div class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Attendance</th>
                                <th>Milk Type</th>
                                <th>Amount</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($milk_rows as $row): ?>
                                <tr>
                                    <td><?php echo h(date('F d, Y', strtotime($row['feeding_date']))); ?></td>
                                    <td><?php echo h(safe_value($row['attendance'])); ?></td>
                                    <td><?php echo h(safe_value($row['milk_type'])); ?></td>
                                    <td><?php echo h(safe_value($row['amount'])); ?></td>
                                    <td><?php echo h(safe_value($row['remarks'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-bar">
                    <div class="pagination-info">Page <?php echo (int) $milk_page; ?> of <?php echo (int) $milk_total_pages; ?></div>
                    <div class="filter-actions compact">
                        <?php if ($milk_page > 1): ?>
                            <a class="filter-btn secondary" href="<?php echo h(build_page_url($submitted_report_id, ['milk_page' => $milk_page - 1], 'milk-feeding-history')); ?>">Previous</a>
                        <?php endif; ?>

                        <?php if ($milk_page < $milk_total_pages): ?>
                            <a class="filter-btn secondary" href="<?php echo h(build_page_url($submitted_report_id, ['milk_page' => $milk_page + 1], 'milk-feeding-history')); ?>">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No milk feeding history found</h3>
                    <p>No milk feeding records are available for this child.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="table-card section-anchor" id="deworming-history">
            <div class="table-header">
                <div>
                    <h2>Deworming History</h2>
                    <p>Current deworming records of the selected child.</p>
                </div>
                <span class="record-count"><?php echo (int) $deworm_total; ?> record(s)</span>
            </div>

            <?php if (!empty($deworm_rows)): ?>
                <div class="table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Attendance</th>
                                <th>Medicine</th>
                                <th>Dosage</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deworm_rows as $row): ?>
                                <tr>
                                    <td><?php echo h(date('F d, Y', strtotime($row['deworming_date']))); ?></td>
                                    <td><?php echo h(safe_value($row['attendance'])); ?></td>
                                    <td><?php echo h(safe_value($row['medicine'])); ?></td>
                                    <td><?php echo h(safe_value($row['dosage'])); ?></td>
                                    <td><?php echo h(safe_value($row['remarks'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-bar">
                    <div class="pagination-info">Page <?php echo (int) $deworm_page; ?> of <?php echo (int) $deworm_total_pages; ?></div>
                    <div class="filter-actions compact">
                        <?php if ($deworm_page > 1): ?>
                            <a class="filter-btn secondary" href="<?php echo h(build_page_url($submitted_report_id, ['deworm_page' => $deworm_page - 1], 'deworming-history')); ?>">Previous</a>
                        <?php endif; ?>

                        <?php if ($deworm_page < $deworm_total_pages): ?>
                            <a class="filter-btn secondary" href="<?php echo h(build_page_url($submitted_report_id, ['deworm_page' => $deworm_page + 1], 'deworming-history')); ?>">Next</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No deworming history found</h3>
                    <p>No deworming records are available for this child.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="table-card">
            <div class="table-header">
                <div>
                    <h2>Growth Monitoring Graph</h2>
                    <p>Current graph of the child's recorded height and weight.</p>
                </div>
            </div>

            <?php if (!empty($graph_labels)): ?>
                <div class="graph-section">
                    <div class="chart-grid">
                        <div class="summary-box">
                            <div class="summary-title">Weight Graph</div>
                            <div class="chart-holder">
                                <canvas id="weightChart"></canvas>
                            </div>
                        </div>

                        <div class="summary-box">
                            <div class="summary-title">Height Graph</div>
                            <div class="chart-holder">
                                <canvas id="heightChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <h3>No graph data found</h3>
                    <p>No anthropometric records are available for graph generation.</p>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php if (!empty($graph_labels)): ?>
<script>
window.viewSubmittedReportChartData = {
    graphLabels: <?php echo json_encode($graph_labels); ?>,
    graphWeights: <?php echo json_encode($graph_weights); ?>,
    graphHeights: <?php echo json_encode($graph_heights); ?>
};
</script>
<?php endif; ?>

<script src="/nutritrack/assets/admin/view_submitted_report.js"></script>


</body>
</html>