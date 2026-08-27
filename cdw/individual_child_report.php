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

$active_cdc_id = (int) $_SESSION['active_cdc_id'];
$user_id = (int) $_SESSION['user_id'];
$theme_mode = isset($_SESSION['theme_mode']) ? $_SESSION['theme_mode'] : 'light';

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

function status_class($status)
{
    $status = strtolower(trim((string) $status));

    if ($status === '' || $status === '--') {
        return 'status-default';
    }

    if (
        $status === 'normal' ||
        strpos($status, 'green zone') !== false
    ) {
        return 'status-normal';
    }

    if (
        strpos($status, 'moderate') !== false ||
        strpos($status, 'yellow zone') !== false ||
        $status === 'underweight' ||
        $status === 'wasted' ||
        $status === 'stunted' ||
        $status === 'tall' ||
        $status === 'overweight'
    ) {
        return 'status-warning';
    }

    if (
        strpos($status, 'severe') !== false ||
        strpos($status, 'red zone') !== false ||
        $status === 'obese'
    ) {
        return 'status-severe';
    }

    return 'status-default';
}

function build_page_url($overrides = [])
{
    $params = $_GET;

    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return '?' . http_build_query($params);
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

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$child_id = isset($_GET['child_id']) ? (int) $_GET['child_id'] : 0;
$show_details = ($child_id > 0);
$success_message = '';
$error_message = '';

/* =========================================================
   LIST VIEW IF NO CHILD ID
========================================================= */
if (!$show_details) {
    $children = [];

    $list_sql = "
        SELECT
            child_id,
            first_name,
            middle_name,
            last_name,
            birthdate,
            sex
        FROM children
        WHERE cdc_id = ?
          AND is_deleted = 0
    ";

    $types = "i";
    $params = [$active_cdc_id];

    if ($search !== '') {
        $list_sql .= " AND (
            first_name LIKE ?
            OR middle_name LIKE ?
            OR last_name LIKE ?
            OR CONCAT(first_name, ' ', last_name) LIKE ?
        ) ";
        $search_like = '%' . $search . '%';
        $types .= "ssss";
        $params[] = $search_like;
        $params[] = $search_like;
        $params[] = $search_like;
        $params[] = $search_like;
    }

    $list_sql .= " ORDER BY last_name ASC, first_name ASC ";

    $stmt_list = mysqli_prepare($conn, $list_sql);
    if ($stmt_list) {
        mysqli_stmt_bind_param($stmt_list, $types, ...$params);
        mysqli_stmt_execute($stmt_list);
        $result_list = mysqli_stmt_get_result($stmt_list);

        if ($result_list) {
            while ($row = mysqli_fetch_assoc($result_list)) {
                $children[] = $row;
            }
        }

        mysqli_stmt_close($stmt_list);
    }

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Individual Child Report | NutriTrack</title>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
        <link rel="stylesheet" href="../assets/cdw/individual_child_report.css">
    </head>
    <body class="<?php echo ($theme_mode === 'dark') ? 'dark-mode' : ''; ?>">

    <?php include '../includes/cdw_topbar.php'; ?>
    <?php include '../includes/cdw_sidebar.php'; ?>

    <div class="main-content" id="mainContent">
        <div class="report-page">

            <a href="dashboard.php" class="back-link">← Back to Dashboard</a>

            <div class="report-header-card">
                <div class="report-header-copy">
                    <span class="section-label">CDW Report Module</span>
                    <h1>Individual Child Report</h1>
                    <p>
                        Select a child first to open the full individual child report.
                    </p>
                </div>
            </div>

            <div class="selector-card">
                <div class="selector-header">
                    <h2>Child List</h2>
                    <p>Only children under your active CDC are shown here.</p>
                </div>

                <form method="GET" action="" style="margin-bottom: 18px;">
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <input
                            type="text"
                            name="search"
                            value="<?php echo h($search); ?>"
                            placeholder="Search child name"
                            style="flex:1; min-width:220px; padding:12px 14px; border:1px solid #dcdcdc; border-radius:10px; font-size:14px;"
                        >
                        <button type="submit" class="btn-primary">Search</button>
                        <a href="individual_child_report.php" class="btn-secondary">Reset</a>
                    </div>
                </form>

                <div class="selector-table-wrap">
                    <table class="simple-table">
                        <thead>
                            <tr>
                                <th>Child Name</th>
                                <th>Sex</th>
                                <th>Birthdate</th>
                                <th>Age in Months</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($children)): ?>
                                <?php foreach ($children as $row): ?>
                                    <?php
                                        $full_name = build_full_name($row['first_name'], $row['middle_name'], $row['last_name']);
                                        $birthdate_display = (!empty($row['birthdate']) && $row['birthdate'] !== '0000-00-00')
                                            ? date('F d, Y', strtotime($row['birthdate']))
                                            : 'N/A';
                                        $age_months_now = calculate_age_months($row['birthdate']);
                                    ?>
                                    <tr>
                                        <td><?php echo h($full_name); ?></td>
                                        <td><?php echo h(safe_value($row['sex'])); ?></td>
                                        <td><?php echo h($birthdate_display); ?></td>
                                        <td><?php echo h($age_months_now); ?> month(s)</td>
                                        <td>
                                            <a href="individual_child_report.php?child_id=<?php echo (int)$row['child_id']; ?>&view=details" class="btn-secondary">
                                                View Full Report
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5">No child records found for the selected CDC.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
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
    <?php
    exit();
}

/* =========================================================
   DETAIL VIEW IF CHILD ID EXISTS
========================================================= */

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
    WHERE c.child_id = ? AND c.cdc_id = ? AND c.is_deleted = 0
    LIMIT 1
";

$stmt_child = mysqli_prepare($conn, $child_sql);
mysqli_stmt_bind_param($stmt_child, "ii", $child_id, $active_cdc_id);
mysqli_stmt_execute($stmt_child);
$result_child = mysqli_stmt_get_result($stmt_child);

if (!$result_child || mysqli_num_rows($result_child) === 0) {
    die("Child not found or not assigned to the active CDC.");
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    $payload = [
        'report_type'   => 'individual_child',
        'child_id'      => (int) $child['child_id'],
        'child_name'    => $child_full_name,
        'sex'           => safe_value($child['sex']),
        'cdc_id'        => (int) $child['cdc_id'],
        'cdc_name'      => safe_value($child['cdc_name']),
        'submitted_by'  => $user_id,
        'submitted_at'  => date('Y-m-d H:i:s')
    ];

    $payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $insert_sql = "
        INSERT INTO submitted_reports (
            report_type,
            cdc_id,
            submitted_by,
            date_from,
            date_to,
            status,
            report_payload
        ) VALUES (?, ?, ?, NULL, NULL, ?, ?)
    ";

    $report_type = 'individual_child';
    $status = 'submitted';

    $stmt_insert = mysqli_prepare($conn, $insert_sql);

    if ($stmt_insert) {
        mysqli_stmt_bind_param(
            $stmt_insert,
            "siiss",
            $report_type,
            $child['cdc_id'],
            $user_id,
            $status,
            $payload_json
        );

        if (mysqli_stmt_execute($stmt_insert)) {
            header("Location: individual_child_report.php?child_id=" . (int)$child_id . "&view=details&submitted=1");
            exit();
        } else {
            $error_message = "Failed to submit the individual child report.";
        }

        mysqli_stmt_close($stmt_insert);
    } else {
        $error_message = "Failed to prepare report submission.";
    }
}

if (isset($_GET['submitted']) && $_GET['submitted'] == '1') {
    $success_message = "Individual Child Report submitted successfully to CSWD.";
}

/* -----------------------------
   LATEST NUTRITIONAL STATUS
----------------------------- */
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

/* -----------------------------
   GRAPH DATA
----------------------------- */
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

/* -----------------------------
   PAGINATION SETUP
----------------------------- */
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

/* -----------------------------
   ANTHROPOMETRIC HISTORY
----------------------------- */
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

/* -----------------------------
   FEEDING HISTORY
----------------------------- */
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

/* -----------------------------
   MILK FEEDING HISTORY
----------------------------- */
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

/* -----------------------------
   DEWORMING HISTORY
----------------------------- */
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
    <title>Individual Child Report | NutriTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
    <link rel="stylesheet" href="../assets/cdw/individual_child_report.css">    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="<?php echo ($theme_mode === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="report-page">

        <a href="individual_child_report.php" class="back-link">← Back to Individual Child Report List</a>

        <div class="report-header-card">
            <div class="report-header-copy">
                <h1>Individual Child Report</h1>
                <p>
                    This report combines the child profile, health information, guardian details,
                    nutritional monitoring records, and growth monitoring data of the selected child.
                </p>
            </div>

            <form method="POST" class="submit-form no-print" style="display:flex; justify-content:space-between; align-items:center; width:100%;">
            <button type="submit" name="submit_report" class="btn-primary">Submit Report to CSWD</button>

            <button type="button" class="btn-print" onclick="window.print()">
                Print / Save as PDF
            </button>
        </form>
        </div>

        <?php if ($success_message !== ''): ?>
            <div class="alert success"><?php echo h($success_message); ?></div>
        <?php endif; ?>

        <?php if ($error_message !== ''): ?>
            <div class="alert error"><?php echo h($error_message); ?></div>
        <?php endif; ?>

        <div class="summary-grid">
            <div class="summary-card">
                <span class="summary-label">Child Name</span>
                <span class="summary-value"><?php echo h($child_full_name); ?></span>
            </div>

            <div class="summary-card">
                <span class="summary-label">Sex</span>
                <span class="summary-value"><?php echo h(safe_value($child['sex'])); ?></span>
            </div>

            <div class="summary-card">
                <span class="summary-label">Age</span>
                <span class="summary-value"><?php echo h($age_text); ?></span>
            </div>

            <div class="summary-card">
                <span class="summary-label">CDC</span>
                <span class="summary-value"><?php echo h(safe_value($child['cdc_name'])); ?></span>
            </div>
        </div>

        <div class="content-section">
            <div class="section-title-wrap">
                <h2>Child Profile</h2>
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

        <div class="content-section">
            <div class="section-title-wrap">
                <h2>Guardian Information</h2>
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

      <div class="content-section">
    <div class="section-title-wrap">
        <h2>Child Health Information</h2>
    </div>

    <div class="health-info-panel">
        <div class="health-info-group">
            <div class="health-info-group-title">Documents</div>

            <div class="health-info-row">
                <div class="health-info-label">Vaccination Card File</div>
                <div class="health-info-value">
                    <?php if (!empty($child['vaccination_card_file_path'])): ?>
                        <a href="<?php echo h($child['vaccination_card_file_path']); ?>" target="_blank" class="file-link">
                            View Vaccination Card
                        </a>
                    <?php else: ?>
                        N/A
                    <?php endif; ?>
                </div>
            </div>

            <div class="health-info-row">
                <div class="health-info-label">Medical History File</div>
                <div class="health-info-value">
                    <?php if (!empty($child['medical_history_file_path'])): ?>
                        <a href="<?php echo h($child['medical_history_file_path']); ?>" target="_blank" class="file-link">
                            View Medical History File
                        </a>
                    <?php else: ?>
                        N/A
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="health-info-group">
            <div class="health-info-group-title">Health Details</div>

            <div class="health-info-row">
                <div class="health-info-label">Allergies / Allergen</div>
                <div class="health-info-value"><?php echo h(safe_value($child['allergies'])); ?></div>
            </div>

            <div class="health-info-row">
                <div class="health-info-label">Comorbidities</div>
                <div class="health-info-value"><?php echo h(safe_value($child['comorbidities'])); ?></div>
            </div>
        </div>
    </div>
</div>

        <div class="content-section">
            <div class="section-title-wrap">
                <h2>Latest Nutritional Status</h2>
            </div>

            <?php if ($latest_record): ?>
                <div class="status-grid">
                    <div class="status-box">
                        <span class="status-box-label">Latest Date Recorded</span>
                        <span class="status-box-value"><?php echo h(date('F d, Y', strtotime($latest_record['date_recorded']))); ?></span>
                    </div>
                    <div class="status-box">
                        <span class="status-box-label">Height</span>
                        <span class="status-box-value"><?php echo h(safe_value($latest_record['height'])); ?> cm</span>
                    </div>
                    <div class="status-box">
                        <span class="status-box-label">Weight</span>
                        <span class="status-box-value"><?php echo h(safe_value($latest_record['weight'])); ?> kg</span>
                    </div>
                    <div class="status-box">
                        <span class="status-box-label">MUAC</span>
                        <span class="status-box-value"><?php echo h(safe_value($latest_record['muac'])); ?> cm</span>
                    </div>
                </div>

                <div class="table-wrap">
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
                                <td><?php echo h(safe_value($latest_record['edema_grade'], '--')); ?></td>
                            </tr>
                            <tr>
                                <th>MUAC Status</th>
                                <td><?php echo h(safe_value($latest_record['muac_status'], '--')); ?></td>
                                <th>Latest Monitoring Date</th>
                                <td><?php echo h(date('F d, Y', strtotime($latest_record['date_recorded']))); ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="status-pill-grid">
                    <div class="status-pill <?php echo h(status_class($latest_record['wfa_status'])); ?>">
                        <span class="pill-label">WFA</span>
                        <span class="pill-value"><?php echo h(safe_value($latest_record['wfa_status'])); ?></span>
                    </div>
                    <div class="status-pill <?php echo h(status_class($latest_record['hfa_status'])); ?>">
                        <span class="pill-label">HFA</span>
                        <span class="pill-value"><?php echo h(safe_value($latest_record['hfa_status'])); ?></span>
                    </div>
                    <div class="status-pill <?php echo h(status_class($latest_record['wflh_status'])); ?>">
                        <span class="pill-label">WFL/H</span>
                        <span class="pill-value"><?php echo h(safe_value($latest_record['wflh_status'])); ?></span>
                    </div>
                    <div class="status-pill <?php echo h(status_class($latest_record['muac_status'])); ?>">
                        <span class="pill-label">MUAC Status</span>
                        <span class="pill-value"><?php echo h(safe_value($latest_record['muac_status'], '--')); ?></span>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">No latest nutritional status found for this child.</div>
            <?php endif; ?>
        </div>

        <div class="content-section">
            <div class="section-title-wrap">
                <h2>Anthropometric History</h2>
                <span class="record-badge"><?php echo (int) $anthro_total; ?> record(s)</span>
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
                                    <td><?php echo h(safe_value($row['edema_status'], '--')); ?></td>
                                    <td><?php echo h(safe_value($row['edema_grade'], '--')); ?></td>
                                    <td><?php echo h(safe_value($row['muac_status'], '--')); ?></td>
                                    <td><?php echo h(safe_value($row['wfa_status'])); ?></td>
                                    <td><?php echo h(safe_value($row['hfa_status'])); ?></td>
                                    <td><?php echo h(safe_value($row['wflh_status'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-wrap">
                    <?php if ($anthro_page > 1): ?>
                        <a class="page-btn" href="<?php echo h(build_page_url(['child_id' => $child_id, 'anthro_page' => $anthro_page - 1])); ?>">Previous</a>
                    <?php endif; ?>

                    <span class="page-text">Page <?php echo (int) $anthro_page; ?> of <?php echo (int) $anthro_total_pages; ?></span>

                    <?php if ($anthro_page < $anthro_total_pages): ?>
                        <a class="page-btn" href="<?php echo h(build_page_url(['child_id' => $child_id, 'anthro_page' => $anthro_page + 1])); ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">No anthropometric history found for this child.</div>
            <?php endif; ?>
        </div>

        <div class="content-section">
            <div class="section-title-wrap">
                <h2>Feeding History</h2>
                <span class="record-badge"><?php echo (int) $feeding_total; ?> record(s)</span>
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
                                    <td><?php echo h(safe_value($row['attendance'])); ?></td>
                                    <td>
                                        <?php
                                        if (strtolower((string)$row['attendance']) === 'absent') {
                                            echo 'Absent';
                                        } elseif (!empty($row['food_details'])) {
                                            $foods = explode('||', $row['food_details']);
                                            echo '<ul class="food-list">';
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

                <div class="pagination-wrap">
                    <?php if ($feeding_page > 1): ?>
                        <a class="page-btn" href="<?php echo h(build_page_url(['child_id' => $child_id, 'feeding_page' => $feeding_page - 1])); ?>">Previous</a>
                    <?php endif; ?>

                    <span class="page-text">Page <?php echo (int) $feeding_page; ?> of <?php echo (int) $feeding_total_pages; ?></span>

                    <?php if ($feeding_page < $feeding_total_pages): ?>
                        <a class="page-btn" href="<?php echo h(build_page_url(['child_id' => $child_id, 'feeding_page' => $feeding_page + 1])); ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">No feeding history found for this child.</div>
            <?php endif; ?>
        </div>

        <div class="content-section">
            <div class="section-title-wrap">
                <h2>Milk Feeding History</h2>
                <span class="record-badge"><?php echo (int) $milk_total; ?> record(s)</span>
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

                <div class="pagination-wrap">
                    <?php if ($milk_page > 1): ?>
                        <a class="page-btn" href="<?php echo h(build_page_url(['child_id' => $child_id, 'milk_page' => $milk_page - 1])); ?>">Previous</a>
                    <?php endif; ?>

                    <span class="page-text">Page <?php echo (int) $milk_page; ?> of <?php echo (int) $milk_total_pages; ?></span>

                    <?php if ($milk_page < $milk_total_pages): ?>
                        <a class="page-btn" href="<?php echo h(build_page_url(['child_id' => $child_id, 'milk_page' => $milk_page + 1])); ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">No milk feeding history found for this child.</div>
            <?php endif; ?>
        </div>

        <div class="content-section">
            <div class="section-title-wrap">
                <h2>Deworming History</h2>
                <span class="record-badge"><?php echo (int) $deworm_total; ?> record(s)</span>
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

                <div class="pagination-wrap">
                    <?php if ($deworm_page > 1): ?>
                        <a class="page-btn" href="<?php echo h(build_page_url(['child_id' => $child_id, 'deworm_page' => $deworm_page - 1])); ?>">Previous</a>
                    <?php endif; ?>

                    <span class="page-text">Page <?php echo (int) $deworm_page; ?> of <?php echo (int) $deworm_total_pages; ?></span>

                    <?php if ($deworm_page < $deworm_total_pages): ?>
                        <a class="page-btn" href="<?php echo h(build_page_url(['child_id' => $child_id, 'deworm_page' => $deworm_page + 1])); ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">No deworming history found for this child.</div>
            <?php endif; ?>
        </div>

        <div class="content-section">
            <div class="section-title-wrap">
                <h2>Growth Monitoring Graph</h2>
            </div>

            <?php if (!empty($graph_labels)): ?>
                <div class="graph-grid">
                    <div class="graph-card">
                        <h3>Weight Graph</h3>
                        <div class="chart-box">
                            <canvas id="weightChart"></canvas>
                        </div>
                    </div>

                    <div class="graph-card">
                        <h3>Height Graph</h3>
                        <div class="chart-box">
                            <canvas id="heightChart"></canvas>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="empty-state">No anthropometric data available for graph.</div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php if (!empty($graph_labels)): ?>
<script>
const graphLabels = <?php echo json_encode($graph_labels); ?>;
const graphWeights = <?php echo json_encode($graph_weights); ?>;
const graphHeights = <?php echo json_encode($graph_heights); ?>;

const weightCanvas = document.getElementById('weightChart');
if (weightCanvas) {
    new Chart(weightCanvas, {
        type: 'line',
        data: {
            labels: graphLabels,
            datasets: [{
                label: 'Weight (kg)',
                data: graphWeights,
                borderColor: '#2E7D32',
                backgroundColor: 'rgba(46, 125, 50, 0.12)',
                borderWidth: 3,
                fill: true,
                tension: 0.25,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: true }
            },
            scales: {
                x: {
                    title: {
                        display: true,
                        text: 'Age in Months'
                    }
                },
                y: {
                    title: {
                        display: true,
                        text: 'Weight (kg)'
                    },
                    beginAtZero: false
                }
            }
        }
    });
}

const heightCanvas = document.getElementById('heightChart');
if (heightCanvas) {
    new Chart(heightCanvas, {
        type: 'line',
        data: {
            labels: graphLabels,
            datasets: [{
                label: 'Height (cm)',
                data: graphHeights,
                borderColor: '#1B5E20',
                backgroundColor: 'rgba(27, 94, 32, 0.10)',
                borderWidth: 3,
                fill: true,
                tension: 0.25,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: true }
            },
            scales: {
                x: {
                    title: {
                        display: true,
                        text: 'Age in Months'
                    }
                },
                y: {
                    title: {
                        display: true,
                        text: 'Height (cm)'
                    },
                    beginAtZero: false
                }
            }
        }
    });
}
</script>
<?php endif; ?>

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

</body>
</html>