<?php
include '../includes/auth.php';
include '../config/database.php';

if ($_SESSION['role_id'] != 2) {
    header("Location: ../login.php");
    exit();
}

if (!isset($_GET['child_id']) || empty($_GET['child_id'])) {
    header("Location: child_list.php");
    exit();
}

$child_id = (int) $_GET['child_id'];
$user_id  = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

function table_exists($conn, $table_name) {
    $table_name = mysqli_real_escape_string($conn, $table_name);
    $sql = "SHOW TABLES LIKE '$table_name'";
    $result = mysqli_query($conn, $sql);
    return ($result && mysqli_num_rows($result) > 0);
}

function compute_age_in_months($birthdate, $record_date) {
    try {
        $birth = new DateTime($birthdate);
        $record = new DateTime($record_date);
        $diff = $birth->diff($record);
        return ($diff->y * 12) + $diff->m;
    } catch (Exception $e) {
        return 0;
    }
}

/* =========================
   GET CHILD INFORMATION
========================= */
$child = null;

$child_sql = "
    SELECT c.child_id, c.first_name, c.last_name, c.birthdate, c.sex, c.access_code, c.cdc_id,
           d.cdc_name
    FROM children c
    LEFT JOIN cdc d ON c.cdc_id = d.cdc_id
    WHERE c.child_id = ?
      AND c.is_deleted = 0
    LIMIT 1
";
$stmt_child = mysqli_prepare($conn, $child_sql);
mysqli_stmt_bind_param($stmt_child, "i", $child_id);
mysqli_stmt_execute($stmt_child);
$result_child = mysqli_stmt_get_result($stmt_child);

if ($result_child && mysqli_num_rows($result_child) > 0) {
    $child = mysqli_fetch_assoc($result_child);
} else {
    header("Location: child_list.php");
    exit();
}

/* =========================
   LATEST ANTHROPOMETRIC
========================= */
$latest_record = null;

$latest_sql = "
    SELECT record_id, child_id, height, weight, muac, edema_status, edema_grade, muac_status, wfa_status, hfa_status, wflh_status, date_recorded
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

/* =========================
   ANTHROPOMETRIC HISTORY
   FOR MINI TABLE + GRAPH
========================= */
$anthropometric_history = [];
$graph_labels = [];
$graph_weights = [];
$graph_heights = [];

$history_sql = "
    SELECT record_id, child_id, height, weight, muac, edema_status, edema_grade, muac_status, wfa_status, hfa_status, wflh_status, date_recorded
    FROM anthropometric_records
    WHERE child_id = ?
    ORDER BY date_recorded ASC, record_id ASC
";
$stmt_history = mysqli_prepare($conn, $history_sql);
mysqli_stmt_bind_param($stmt_history, "i", $child_id);
mysqli_stmt_execute($stmt_history);
$result_history = mysqli_stmt_get_result($stmt_history);

if ($result_history) {
    while ($row = mysqli_fetch_assoc($result_history)) {
        $anthropometric_history[] = $row;

        $age_months = compute_age_in_months($child['birthdate'], $row['date_recorded']);
        $graph_labels[] = $age_months;
        $graph_weights[] = (float) $row['weight'];
        $graph_heights[] = (float) $row['height'];
    }
}

/* =========================
   MILK FEEDING HISTORY
========================= */
$milk_history = [];

if (table_exists($conn, 'milk_feeding_records')) {
    $milk_sql = "
        SELECT milk_record_id, child_id, feeding_date, attendance, milk_type, amount, remarks, recorded_by
        FROM milk_feeding_records
        WHERE child_id = ?
        ORDER BY feeding_date DESC, milk_record_id DESC
        LIMIT 10
    ";
    $stmt_milk = mysqli_prepare($conn, $milk_sql);
    mysqli_stmt_bind_param($stmt_milk, "i", $child_id);
    mysqli_stmt_execute($stmt_milk);
    $result_milk = mysqli_stmt_get_result($stmt_milk);

    if ($result_milk) {
        while ($row = mysqli_fetch_assoc($result_milk)) {
            $milk_history[] = $row;
        }
    }
}

/* =========================
   DEWORMING HISTORY
========================= */
$deworming_history = [];

if (table_exists($conn, 'deworming_records')) {
    $deworm_sql = "
        SELECT deworm_id, child_id, deworming_date, attendance, medicine, dosage, remarks, recorded_by
        FROM deworming_records
        WHERE child_id = ?
        ORDER BY deworming_date DESC, deworm_id DESC
        LIMIT 10
    ";
    $stmt_deworm = mysqli_prepare($conn, $deworm_sql);
    mysqli_stmt_bind_param($stmt_deworm, "i", $child_id);
    mysqli_stmt_execute($stmt_deworm);
    $result_deworm = mysqli_stmt_get_result($stmt_deworm);

    if ($result_deworm) {
        while ($row = mysqli_fetch_assoc($result_deworm)) {
            $deworming_history[] = $row;
        }
    }
}

/* =========================
   FEEDING HISTORY
   GROUPED BY DATE / RECORD
========================= */
$feeding_history = [];

if (
    table_exists($conn, 'feeding_records') &&
    table_exists($conn, 'feeding_record_items') &&
    table_exists($conn, 'food_groups') &&
    table_exists($conn, 'food_items')
) {
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
                                WHEN fri.measurement_text IS NOT NULL AND fri.measurement_text != ''
                                    THEN CONCAT(' (', fri.measurement_text, ')')
                                WHEN fri.quantity IS NOT NULL
                                    THEN CONCAT(' (', TRIM(TRAILING '.00' FROM fri.quantity), ')')
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
        GROUP BY fr.feeding_record_id, fr.feeding_date, fr.attendance, fr.remarks
        ORDER BY fr.feeding_date DESC, fr.feeding_record_id DESC
        LIMIT 10
    ";

    $stmt_feeding = mysqli_prepare($conn, $feeding_sql);
    if ($stmt_feeding) {
        mysqli_stmt_bind_param($stmt_feeding, "i", $child_id);
        mysqli_stmt_execute($stmt_feeding);
        $result_feeding = mysqli_stmt_get_result($stmt_feeding);

        if ($result_feeding) {
            while ($row = mysqli_fetch_assoc($result_feeding)) {
                $feeding_history[] = $row;
            }
        }
    }
}

/* =========================
   STATUS CLASS HELPERS
========================= */
function safe_value($value) {
    return isset($value) && $value !== '' ? htmlspecialchars($value) : '-';
}

function status_class($status) {
    $status = strtolower(trim((string)$status));

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nutritional Monitoring | NutriTrack</title>
    <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
    <link rel="stylesheet" href="../assets/cdw/nutritional_monitoring.css">
    <link rel="stylesheet" href="../assets/cdw/cdw-topbar-notification.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
include __DIR__ . '/../includes/auth.php';
<body class="<?php echo themeClass(); ?>">

<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content">
    <?php include '../includes/cdw_topbar.php'; ?>

    <div class="page-content">

        <div class="page-header">
            <div>
                <h1>Nutritional Monitoring</h1>
                <p>Per child nutritional monitoring and history</p>
            </div>
            <div class="header-actions">
                <a href="child_profile.php?child_id=<?php echo $child_id; ?>" class="btn-secondary">Back to Child Profile</a>
            </div>
        </div>

        <div class="child-info-card">
            <div class="child-info-grid">
                <div class="info-box">
                    <span class="label">Child Name</span>
                    <span class="value"><?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?></span>
                </div>
                <div class="info-box">
                    <span class="label">Birthdate</span>
                    <span class="value"><?php echo date('F d, Y', strtotime($child['birthdate'])); ?></span>
                </div>
                <div class="info-box">
                    <span class="label">Sex</span>
                    <span class="value"><?php echo htmlspecialchars(ucfirst($child['sex'])); ?></span>
                </div>
                <div class="info-box">
                    <span class="label">CDC</span>
                    <span class="value"><?php echo safe_value($child['cdc_name']); ?></span>
                </div>
                <div class="info-box">
                    <span class="label">Access Code</span>
                    <span class="value"><?php echo safe_value($child['access_code']); ?></span>
                </div>
                <div class="info-box">
                    <span class="label">Child ID</span>
                    <span class="value"><?php echo $child_id; ?></span>
                </div>
            </div>
        </div>

        <div class="section-card">
            <div class="section-header">
                <h2>Latest Nutritional Status</h2>
            </div>

            <?php if ($latest_record) { ?>
                <div class="latest-summary-grid">
                    <div class="metric-card">
                        <span class="metric-label">Latest Date Recorded</span>
                        <span class="metric-value"><?php echo date('F d, Y', strtotime($latest_record['date_recorded'])); ?></span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">Height</span>
                        <span class="metric-value"><?php echo safe_value($latest_record['height']); ?> cm</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">Weight</span>
                        <span class="metric-value"><?php echo safe_value($latest_record['weight']); ?> kg</span>
                    </div>
                    <div class="metric-card">
                        <span class="metric-label">MUAC</span>
                        <span class="metric-value"><?php echo safe_value($latest_record['muac']); ?> cm</span>
                    </div>
                </div>

                <div class="status-grid">
                    <div class="status-card <?php echo status_class($latest_record['wfa_status']); ?>">
                        <span class="status-title">WFA</span>
                        <span class="status-value"><?php echo safe_value($latest_record['wfa_status']); ?></span>
                    </div>

                    <div class="status-card <?php echo status_class($latest_record['hfa_status']); ?>">
                        <span class="status-title">HFA</span>
                        <span class="status-value"><?php echo safe_value($latest_record['hfa_status']); ?></span>
                    </div>

                    <div class="status-card <?php echo status_class($latest_record['wflh_status']); ?>">
                        <span class="status-title">WFL/H</span>
                        <span class="status-value"><?php echo safe_value($latest_record['wflh_status']); ?></span>
                    </div>
                                        <div class="status-card <?php echo status_class($latest_record['muac_status']); ?>">
                        <span class="status-title">MUAC Status</span>
                        <span class="status-value"><?php echo safe_value($latest_record['muac_status']); ?></span>
                    </div>
                </div>
            <?php } else { ?>
                <div class="empty-state">No anthropometric record found for this child.</div>
            <?php } ?>
        </div>

        <div class="section-card">
            <div class="section-header">
                <h2>Anthropometric History</h2>
            </div>

            <?php if (!empty($anthropometric_history)) { ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date Recorded</th>
                                <th>Age in Months</th>
                                <th>Height (cm)</th>
                                <th>Weight (kg)</th>
                                <th>MUAC (cm)</th>
                                <th>Edema</th>
                                <th>Grade</th>
                                <th>MUAC Status</th>      
                                <th>WFA</th>
                                <th>HFA</th>
                                <th>WFL/H</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_reverse($anthropometric_history) as $row) { ?>
                                <tr>
                                    <td><?php echo date('F d, Y', strtotime($row['date_recorded'])); ?></td>
                                    <td><?php echo compute_age_in_months($child['birthdate'], $row['date_recorded']); ?></td>
                                    <td><?php echo safe_value($row['height']); ?></td>
                                    <td><?php echo safe_value($row['weight']); ?></td>
                                    <td><?php echo safe_value($row['muac']); ?></td>
                                    <td><?php echo safe_value($row['edema_status']); ?></td>
                                    <td><?php echo safe_value($row['edema_grade']); ?></td>
                                    <td><?php echo safe_value($row['muac_status']); ?></td>
                                    <td><?php echo safe_value($row['wfa_status']); ?></td>
                                    <td><?php echo safe_value($row['hfa_status']); ?></td>
                                    <td><?php echo safe_value($row['wflh_status']); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } else { ?>
                <div class="empty-state">No anthropometric history found.</div>
            <?php } ?>
        </div>

        <div class="section-card">
            <div class="section-header">
                <h2>Milk Feeding History</h2>
            </div>

            <?php if (!empty($milk_history)) { ?>
                <div class="table-wrapper">
                    <table class="data-table">
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
                            <?php foreach ($milk_history as $row) { ?>
                                <tr>
                                    <td><?php echo date('F d, Y', strtotime($row['feeding_date'])); ?></td>
                                    <td><?php echo safe_value($row['attendance']); ?></td>
                                    <td><?php echo safe_value($row['milk_type']); ?></td>
                                    <td><?php echo safe_value($row['amount']); ?></td>
                                    <td><?php echo safe_value($row['remarks']); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } else { ?>
                <div class="empty-state">No milk feeding history found for this child.</div>
            <?php } ?>
        </div>

        <div class="section-card">
            <div class="section-header">
                <h2>Feeding History</h2>
            </div>

            <?php if (!empty($feeding_history)) { ?>
                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Attendance</th>
                                <th>Food Details</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($feeding_history as $row) { ?>
                                <tr>
                                    <td>
                                        <?php
                                        if (!empty($row['feeding_date'])) {
                                            echo date('F d, Y', strtotime($row['feeding_date']));
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo safe_value($row['attendance']); ?></td>
                                    <td>
                                        <?php
                                        if (strtolower((string)$row['attendance']) === 'absent') {
                                            echo 'Absent';
                                        } elseif (!empty($row['food_details'])) {
                                            $foods = explode('||', $row['food_details']);
                                            echo '<ul style="margin:0; padding-left:18px;">';
                                            foreach ($foods as $food) {
                                                echo '<li style="margin-bottom:4px;">' . htmlspecialchars($food) . '</li>';
                                            }
                                            echo '</ul>';
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo safe_value($row['remarks']); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } else { ?>
                <div class="empty-state">No feeding history found for this child.</div>
            <?php } ?>
        </div>

        <div class="section-card">
            <div class="section-header">
                <h2>Deworming History</h2>
            </div>

            <?php if (!empty($deworming_history)) { ?>
                <div class="table-wrapper">
                    <table class="data-table">
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
                            <?php foreach ($deworming_history as $row) { ?>
                                <tr>
                                    <td><?php echo date('F d, Y', strtotime($row['deworming_date'])); ?></td>
                                    <td><?php echo safe_value($row['attendance']); ?></td>
                                    <td><?php echo safe_value($row['medicine']); ?></td>
                                    <td><?php echo safe_value($row['dosage']); ?></td>
                                    <td><?php echo safe_value($row['remarks']); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            <?php } else { ?>
                <div class="empty-state">No deworming history found for this child.</div>
            <?php } ?>
        </div>

        <div class="section-card">
            <div class="section-header">
                <h2>Growth Monitoring Graph</h2>
            </div>

            <?php if (!empty($anthropometric_history)) { ?>
                <div class="graph-grid">
                    <div class="graph-card">
                        <h3>Weight Graph</h3>
                        <div class="chart-container">
                            <canvas id="weightChart"></canvas>
                        </div>
                    </div>

                    <div class="graph-card">
                        <h3>Height Graph</h3>
                        <div class="chart-container">
                            <canvas id="heightChart"></canvas>
                        </div>
                    </div>
                </div>
            <?php } else { ?>
                <div class="empty-state">No anthropometric data available for graph.</div>
            <?php } ?>
        </div>

    </div>
</div>

<?php if (!empty($anthropometric_history)) { ?>
<script>
const graphLabels = <?php echo json_encode($graph_labels); ?>;
const graphWeights = <?php echo json_encode($graph_weights); ?>;
const graphHeights = <?php echo json_encode($graph_heights); ?>;

const weightCtx = document.getElementById('weightChart');
if (weightCtx) {
    new Chart(weightCtx, {
        type: 'line',
        data: {
            labels: graphLabels,
            datasets: [{
                label: 'Weight (kg)',
                data: graphWeights,
                borderColor: '#2f80ed',
                backgroundColor: 'rgba(47, 128, 237, 0.10)',
                borderWidth: 3,
                fill: true,
                tension: 0.3,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true
                }
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

const heightCtx = document.getElementById('heightChart');
if (heightCtx) {
    new Chart(heightCtx, {
        type: 'line',
        data: {
            labels: graphLabels,
            datasets: [{
                label: 'Height (cm)',
                data: graphHeights,
                borderColor: '#27ae60',
                backgroundColor: 'rgba(39, 174, 96, 0.10)',
                borderWidth: 3,
                fill: true,
                tension: 0.3,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true
                }
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
<?php } ?>

</body>
</html>