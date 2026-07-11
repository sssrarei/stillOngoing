<?php
include '../includes/auth.php';
include '../config/database.php';

if($_SESSION['role_id'] != 2){
    header("Location: ../login.php");
    exit();
}

if (!isset($_SESSION['theme_mode'])) {
    $_SESSION['theme_mode'] = 'light';
}

$theme_mode = $_SESSION['theme_mode'];
$user_id = $_SESSION['user_id'];
$error = "";

function formatCdcDisplayName($cdc_name) {
    $cdc_name = trim((string)$cdc_name);

    $cdc_name = str_replace('Day Care Center', 'Child Development Center', $cdc_name);
    $cdc_name = str_replace('DAY CARE CENTER', 'CHILD DEVELOPMENT CENTER', $cdc_name);
    $cdc_name = str_replace('Daycare Center', 'Child Development Center', $cdc_name);
    $cdc_name = str_replace('DAYCARE CENTER', 'CHILD DEVELOPMENT CENTER', $cdc_name);

    return $cdc_name;
}

function getShortFoodGroupLabel($label) {
    $map = array(
        'Rice, Corn, Root Crops' => 'Rice',
        'Bread and Noodles' => 'Bread',
        'Vegetables' => 'Veggies',
        'Fruits' => 'Fruits',
        'Meat & Poultry' => 'Meat',
        'Fish and Shellfish' => 'Fish',
        'Egg' => 'Egg',
        'Milk and Milk Products' => 'Milk',
        'Dried Beans and Nuts' => 'Beans',
        'Fats and Oils' => 'Fats',
        'Sugar/Sweets' => 'Sugar',
    );

    $label = trim((string)$label);
    return isset($map[$label]) ? $map[$label] : $label;
}

function getShortNutritionLabel($label) {
    $map = array(
        'Normal' => 'Normal',
        'Underweight' => 'UW',
        'Severely Underweight' => 'S.UW',
        'Overweight' => 'OW',
        'Obese' => 'Obese',
        'Stunted' => 'ST',
        'Severely Stunted' => 'S. ST',
        'Moderately Wasted' => 'M. W',
        'Severely Wasted' => 'S. W'
    );

    $label = trim((string)$label);
    return isset($map[$label]) ? $map[$label] : $label;
}

function getFinalNutritionalStatus($wfa_status, $hfa_status, $wflh_status) {
    $wfa_status = trim((string)$wfa_status);
    $hfa_status = trim((string)$hfa_status);
    $wflh_status = trim((string)$wflh_status);

    if ($wflh_status === 'Severely Wasted') {
        return 'Severely Wasted';
    }

    if ($wfa_status === 'Severely Underweight') {
        return 'Severely Underweight';
    }

    if ($wflh_status === 'Obese') {
        return 'Obese';
    }

    if ($wflh_status === 'Moderately Wasted' || $wflh_status === 'Wasted') {
        return 'Moderately Wasted';
    }

    if ($wfa_status === 'Underweight') {
        return 'Underweight';
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


// Kunin lahat ng assigned CDC ng logged-in CDW
$cdc_result = $conn->query("
    SELECT c.cdc_id, c.cdc_name, c.barangay
    FROM cdw_assignments ca
    JOIN cdc c ON ca.cdc_id = c.cdc_id
    WHERE ca.user_id = '$user_id'
    ORDER BY c.cdc_name ASC
");

// Switch active CDC
if(isset($_POST['switch_cdc'])){
    $selected_cdc_id = $_POST['cdc_id'];

    $check = $conn->query("
        SELECT * FROM cdw_assignments
        WHERE user_id = '$user_id' AND cdc_id = '$selected_cdc_id'
    ");

    if($check && $check->num_rows > 0){
        $_SESSION['active_cdc_id'] = $selected_cdc_id;

        $cdc_info = $conn->query("
            SELECT cdc_name, barangay
            FROM cdc
            WHERE cdc_id = '$selected_cdc_id'
        ");

        if($cdc_info && $cdc_info->num_rows > 0){
            $cdc_row = $cdc_info->fetch_assoc();
            $_SESSION['active_cdc_name'] = $cdc_row['cdc_name'];
            $_SESSION['active_cdc_barangay'] = $cdc_row['barangay'];
        }

        header("Location: dashboard.php");
        exit();
    } else {
        $error = "Invalid CDC selection.";
    }
}

/*
|--------------------------------------------------------------------------
| DASHBOARD COUNTS
|--------------------------------------------------------------------------
*/
$total_children_count = 0;
$normal_count = 0;
$underweight_count = 0;
$severely_underweight_count = 0;
$overweight_count = 0;
$obese_count = 0;
$stunted_count = 0;
$severely_stunted_count = 0;
$moderately_wasted_count = 0;
$severely_wasted_count = 0;

/*
|--------------------------------------------------------------------------
| PART 9: Referral Program Summary (CDW Dashboard widget)
| Additive only — scoped to the active CDC. Defaults defined here so the
| page still renders safely if no active CDC is selected.
|--------------------------------------------------------------------------
*/
$referral_assigned = 0;
$referral_cdw_pending = 0;
$referral_cdw_completed = 0;
$referral_awaiting_response = 0;

/*
|--------------------------------------------------------------------------
| FOOD GROUP GRAPH DATA
|--------------------------------------------------------------------------
*/
$food_group_data = [];

/*
|--------------------------------------------------------------------------
| NUTRITIONAL STATUS GRAPH DATA
|--------------------------------------------------------------------------
*/
$nutritional_graph_data = [];

if(isset($_SESSION['active_cdc_id']) && !empty($_SESSION['active_cdc_id'])){
    $active_cdc_id = (int) $_SESSION['active_cdc_id'];

    // Total children enrolled in active CDC
    $total_sql = "
        SELECT COUNT(*) AS total_children_count
        FROM children
        WHERE cdc_id = '$active_cdc_id'
    ";
    $total_result = mysqli_query($conn, $total_sql);
    if($total_result){
        $total_row = mysqli_fetch_assoc($total_result);
        $total_children_count = $total_row['total_children_count'] ?? 0;
    }

   // Latest nutritional status counts per child under active CDC only
// One child = one final nutritional status only
$summary_sql = "
    SELECT
        ar.child_id,
        ar.wfa_status,
        ar.hfa_status,
        ar.wflh_status
    FROM anthropometric_records ar
    INNER JOIN children c 
        ON ar.child_id = c.child_id

    INNER JOIN (
        SELECT 
            ar2.child_id, 
            MAX(ar2.date_recorded) AS latest_date
        FROM anthropometric_records ar2
        INNER JOIN children c2 
            ON ar2.child_id = c2.child_id
        WHERE c2.cdc_id = '$active_cdc_id'
          AND c2.is_deleted = 0
          AND ar2.is_deleted = 0
        GROUP BY ar2.child_id
    ) latest
        ON ar.child_id = latest.child_id
        AND ar.date_recorded = latest.latest_date

    INNER JOIN (
        SELECT 
            ar3.child_id, 
            ar3.date_recorded, 
            MAX(ar3.record_id) AS latest_record_id
        FROM anthropometric_records ar3
        INNER JOIN children c3 
            ON ar3.child_id = c3.child_id
        WHERE c3.cdc_id = '$active_cdc_id'
          AND c3.is_deleted = 0
          AND ar3.is_deleted = 0
        GROUP BY ar3.child_id, ar3.date_recorded
    ) latest_id
        ON ar.child_id = latest_id.child_id
        AND ar.date_recorded = latest_id.date_recorded
        AND ar.record_id = latest_id.latest_record_id

    WHERE c.cdc_id = '$active_cdc_id'
      AND c.is_deleted = 0
      AND ar.is_deleted = 0
";

$summary_result = mysqli_query($conn, $summary_sql);

if($summary_result){
    while($row = mysqli_fetch_assoc($summary_result)){
        $final_status = getFinalNutritionalStatus(
            $row['wfa_status'] ?? '',
            $row['hfa_status'] ?? '',
            $row['wflh_status'] ?? ''
        );

        if ($final_status === 'Normal') {
            $normal_count++;
        } elseif ($final_status === 'Underweight') {
            $underweight_count++;
        } elseif ($final_status === 'Severely Underweight') {
            $severely_underweight_count++;
        } elseif ($final_status === 'Obese') {
            $obese_count++;
        } elseif ($final_status === 'Moderately Wasted') {
            $moderately_wasted_count++;
        } elseif ($final_status === 'Overweight') {
            $overweight_count++;
        } elseif ($final_status === 'Severely Stunted') {
            $severely_stunted_count++;
        } elseif ($final_status === 'Stunted') {
            $stunted_count++;
        } elseif ($final_status === 'Severely Wasted') {
            $severely_wasted_count++;
        }
    }
}

    // Food group consumption graph data
    $food_sql = "
        SELECT
            fg.food_group_id,
            fg.food_group_name,
            SUM(
                CASE
                    WHEN c.cdc_id = '$active_cdc_id' THEN 1
                    ELSE 0
                END
            ) AS total_count
        FROM food_groups fg
        LEFT JOIN feeding_record_items fri
            ON fg.food_group_id = fri.food_group_id
        LEFT JOIN feeding_records fr
            ON fri.feeding_record_id = fr.feeding_record_id
        LEFT JOIN children c
            ON fr.child_id = c.child_id
        GROUP BY fg.food_group_id, fg.food_group_name
        ORDER BY fg.food_group_id ASC
    ";

    $food_result = mysqli_query($conn, $food_sql);

    if($food_result){
        while($row = mysqli_fetch_assoc($food_result)){
            $food_group_data[] = $row;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PART 9: Referral Program Summary queries (CDW Dashboard widget)
    | Additive only — scoped to the active CDC, same pattern as the counts
    | above. Does not touch any existing query or variable.
    |--------------------------------------------------------------------------
    */
    $referral_assigned_sql = "
        SELECT COUNT(*) AS total
        FROM referrals r
        INNER JOIN children c ON c.child_id = r.child_id
        WHERE c.cdc_id = '$active_cdc_id'
    ";
    $referral_assigned_result = mysqli_query($conn, $referral_assigned_sql);
    if ($referral_assigned_result && mysqli_num_rows($referral_assigned_result) > 0) {
        $referral_assigned_row = mysqli_fetch_assoc($referral_assigned_result);
        $referral_assigned = (int) $referral_assigned_row['total'];
    }

    $referral_cdw_status_sql = "
        SELECT r.status, COUNT(*) AS total
        FROM referrals r
        INNER JOIN children c ON c.child_id = r.child_id
        WHERE c.cdc_id = '$active_cdc_id'
        GROUP BY r.status
    ";
    $referral_cdw_status_result = mysqli_query($conn, $referral_cdw_status_sql);
    if ($referral_cdw_status_result) {
        while ($referral_cdw_status_row = mysqli_fetch_assoc($referral_cdw_status_result)) {
            if ($referral_cdw_status_row['status'] === 'Pending') {
                $referral_cdw_pending = (int) $referral_cdw_status_row['total'];
            }
            if ($referral_cdw_status_row['status'] === 'Completed') {
                $referral_cdw_completed = (int) $referral_cdw_status_row['total'];
            }
        }
    }

    $referral_awaiting_sql = "
        SELECT r.referral_id
        FROM referrals r
        INNER JOIN children c ON c.child_id = r.child_id
        WHERE c.cdc_id = '$active_cdc_id'
          AND r.status != 'Completed'
          AND r.status != 'Pending'
          AND NOT EXISTS (
              SELECT 1 FROM referral_comments rc
              WHERE rc.referral_id = r.referral_id
                AND rc.sender_role = 'Guardian'
          )
    ";
    $referral_awaiting_result = mysqli_query($conn, $referral_awaiting_sql);
    if ($referral_awaiting_result) {
        $referral_awaiting_response = mysqli_num_rows($referral_awaiting_result);
    }
}

/*
|--------------------------------------------------------------------------
| Build Nutritional Status Graph Data
|--------------------------------------------------------------------------
*/
$nutritional_graph_data = [
    ['label' => 'Normal', 'count' => (int)$normal_count],
    ['label' => 'Underweight', 'count' => (int)$underweight_count],
    ['label' => 'Severely Underweight', 'count' => (int)$severely_underweight_count],
    ['label' => 'Overweight', 'count' => (int)$overweight_count],
    ['label' => 'Obese', 'count' => (int)$obese_count],
    ['label' => 'Stunted', 'count' => (int)$stunted_count],
    ['label' => 'Severely Stunted', 'count' => (int)$severely_stunted_count],
    ['label' => 'Moderately Wasted', 'count' => (int)$moderately_wasted_count],
    ['label' => 'Severely Wasted', 'count' => (int)$severely_wasted_count]
];

$food_chart_labels = array();
$food_chart_full_labels = array();
$food_chart_counts = array();

foreach ($food_group_data as $fg) {
    $food_chart_labels[] = getShortFoodGroupLabel($fg['food_group_name']);
    $food_chart_full_labels[] = $fg['food_group_name'];
    $food_chart_counts[] = (int)$fg['total_count'];
}

$nutri_chart_labels = array();
$nutri_chart_full_labels = array();
$nutri_chart_counts = array();

foreach ($nutritional_graph_data as $item) {
    $nutri_chart_labels[] = getShortNutritionLabel($item['label']);
    $nutri_chart_full_labels[] = $item['label'];
    $nutri_chart_counts[] = (int)$item['count'];
}

$today = date('Y-m-d');

$event_sql = "
    SELECT title, event_type, event_date, start_time, end_time, location, status
    FROM events
    WHERE is_deleted = 0
    AND status = 'Upcoming'
    AND event_date >= ?
    ORDER BY event_date ASC, start_time ASC
    LIMIT 5
";

$event_stmt = $conn->prepare($event_sql);
$event_stmt->bind_param("s", $today);
$event_stmt->execute();
$upcoming_events = $event_stmt->get_result();

$is_dark_mode = ($theme_mode === 'dark');
?>
<!DOCTYPE html>
<html>
<head>
    <title>CDW Dashboard | NutriTrack</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
    <link rel="stylesheet" href="../assets/cdw/cdw-dashboard.css">
    <link rel="stylesheet" href="../assets/cdw/cdw-topbar-notification.css">
    <style>
        .rp-section {
            margin-top: 4px;
        }

        .rp-section-header {
            font-family: 'Poppins', sans-serif;
            font-size: 17px;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 4px 0;
        }

        .rp-section-subtitle {
            font-size: 12.5px;
            color: #94a3b8;
            margin: 0 0 16px 0;
        }

        .rp-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 14px;
        }

        .rp-stat {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px 16px;
        }

        .rp-stat-icon {
            width: 40px;
            height: 40px;
            flex-shrink: 0;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .rp-stat-assigned .rp-stat-icon { background: #dbeafe; color: #1d4ed8; }
        .rp-stat-pending .rp-stat-icon { background: #fef3c7; color: #b45309; }
        .rp-stat-completed .rp-stat-icon { background: #dcfce7; color: #15803d; }
        .rp-stat-awaiting .rp-stat-icon { background: #ede9fe; color: #6d28d9; }

        .rp-stat-label {
            font-size: 12.5px;
            color: #64748b;
            font-family: 'Inter', sans-serif;
            margin-bottom: 2px;
        }

        .rp-stat-value {
            font-size: 22px;
            font-weight: 700;
            color: #1e293b;
            font-family: 'Poppins', sans-serif;
            line-height: 1.1;
        }
    </style>
</head>
<body class="<?php echo ($theme_mode === 'dark') ? 'dark-mode' : ''; ?>">
<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">

    <div class="page-card">
        <?php if(!empty($error)){ ?>
            <p class="error-msg"><?php echo $error; ?></p>
        <?php } ?>

        <div class="cdc-switch-area">
            <div class="cdc-title">
                <?php
                if(isset($_SESSION['active_cdc_name']) && !empty($_SESSION['active_cdc_name'])){
                    echo htmlspecialchars(formatCdcDisplayName($_SESSION['active_cdc_name']));
                } else {
                    echo "NO ACTIVE CDC";
                }
                ?>
            </div>

            <form method="POST" class="cdc-form">
                <select name="cdc_id" required>
                    <option value="">Select CDC</option>
                    <?php
                    if($cdc_result && $cdc_result->num_rows > 0){
                        while($cdc = $cdc_result->fetch_assoc()){
                    ?>
                        <option value="<?php echo $cdc['cdc_id']; ?>"
                            <?php
                            if(isset($_SESSION['active_cdc_id']) && $_SESSION['active_cdc_id'] == $cdc['cdc_id']){
                                echo "selected";
                            }
                            ?>>
                            <?php echo htmlspecialchars(formatCdcDisplayName($cdc['cdc_name']) . " - " . $cdc['barangay']); ?>
                        </option>
                    <?php
                        }
                    }
                    ?>
                </select>
                <button type="submit" name="switch_cdc">Switch</button>
            </form>

            <?php if(isset($_SESSION['active_cdc_name'])){ ?>
                <div class="active-cdc-text">
                    Active CDC:
                    <?php echo htmlspecialchars(formatCdcDisplayName($_SESSION['active_cdc_name'])); ?>
                    <?php
                    if(isset($_SESSION['active_cdc_barangay']) && !empty($_SESSION['active_cdc_barangay'])){
                        echo " - " . htmlspecialchars($_SESSION['active_cdc_barangay']);
                    }
                    ?>
                </div>
            <?php } ?>
        </div>
    </div>

    <div class="page-card">
        <div class="cards-grid">
            <div class="card">
                <div class="card-title title-blue">Total Child Enrolled in</div>
                <div class="card-body">
                    <div class="card-count"><?php echo $total_children_count; ?></div>
                </div>
            </div>

            <div class="card">
                <div class="card-title title-normal">Normal</div>
                <div class="card-body">
                    <div class="card-count"><?php echo $normal_count; ?></div>
                </div>
            </div>

            <div class="card">
                <div class="card-title title-alert">Underweight</div>
                <div class="card-body">
                    <div class="card-count"><?php echo $underweight_count; ?></div>
                </div>
            </div>

            <div class="card">
                <div class="card-title title-alert">Severely Underweight</div>
                <div class="card-body">
                    <div class="card-count"><?php echo $severely_underweight_count; ?></div>
                </div>
            </div>

            <div class="card">
                <div class="card-title title-alert">Overweight</div>
                <div class="card-body">
                    <div class="card-count"><?php echo $overweight_count; ?></div>
                </div>
            </div>

            <div class="card">
                <div class="card-title title-alert">Obese</div>
                <div class="card-body">
                    <div class="card-count"><?php echo $obese_count; ?></div>
                </div>
            </div>

            <div class="card">
                <div class="card-title title-alert">Stunted</div>
                <div class="card-body">
                    <div class="card-count"><?php echo $stunted_count; ?></div>
                </div>
            </div>

            <div class="card">
                <div class="card-title title-alert">Severely Stunted</div>
                <div class="card-body">
                    <div class="card-count"><?php echo $severely_stunted_count; ?></div>
                </div>
            </div>

            <div class="card">
                <div class="card-title title-alert">Moderately Wasted</div>
                <div class="card-body">
                    <div class="card-count"><?php echo $moderately_wasted_count; ?></div>
                </div>
            </div>

            <div class="card">
                <div class="card-title title-alert">Severely Wasted</div>
                <div class="card-body">
                    <div class="card-count"><?php echo $severely_wasted_count; ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="page-card rp-section">
        <h2 class="rp-section-header">📋 Referral Program Summary</h2>
        <p class="rp-section-subtitle">Referral cases for children under your active CDC.</p>

        <div class="rp-stat-grid">
            <div class="rp-stat rp-stat-assigned">
                <div class="rp-stat-icon">📁</div>
                <div>
                    <div class="rp-stat-label">Referrals Assigned</div>
                    <div class="rp-stat-value"><?php echo $referral_assigned; ?></div>
                </div>
            </div>

            <div class="rp-stat rp-stat-pending">
                <div class="rp-stat-icon">⏳</div>
                <div>
                    <div class="rp-stat-label">Pending</div>
                    <div class="rp-stat-value"><?php echo $referral_cdw_pending; ?></div>
                </div>
            </div>

            <div class="rp-stat rp-stat-completed">
                <div class="rp-stat-icon">✅</div>
                <div>
                    <div class="rp-stat-label">Completed</div>
                    <div class="rp-stat-value"><?php echo $referral_cdw_completed; ?></div>
                </div>
            </div>

            <div class="rp-stat rp-stat-awaiting">
                <div class="rp-stat-icon">💬</div>
                <div>
                    <div class="rp-stat-label">Awaiting Guardian Response</div>
                    <div class="rp-stat-value"><?php echo $referral_awaiting_response; ?></div>
                </div>
            </div>
        </div>
    </div>

<div class="page-card events-dashboard-card">

    <div class="events-dashboard-header">
        <h2>Upcoming Community Events</h2>
    </div>

    <?php if ($upcoming_events && $upcoming_events->num_rows > 0): ?>
        <div class="cdw-event-list">

            <?php while ($event = $upcoming_events->fetch_assoc()): ?>

                <div class="cdw-event-item">

    <div class="cdw-event-grid">

        <div class="cdw-event-field full">
            <span class="cdw-label">Title</span>
            <div class="cdw-value title">
                <?php echo htmlspecialchars($event['title']); ?>
            </div>
        </div>

        <div class="cdw-event-field">
            <span class="cdw-label">Type</span>
            <div class="cdw-value">
                <?php echo htmlspecialchars($event['event_type']); ?>
            </div>
        </div>

        <div class="cdw-event-field">
            <span class="cdw-label">Date</span>
            <div class="cdw-value">
                <?php echo date("F d, Y", strtotime($event['event_date'])); ?>
            </div>
        </div>

        <div class="cdw-event-field">
            <span class="cdw-label">Time</span>
            <div class="cdw-value">
               <?php
if(!empty($event['start_time']) && !empty($event['end_time'])){
    echo date("h:i A", strtotime($event['start_time'])) .
    " - " .
    date("h:i A", strtotime($event['end_time']));
}
elseif(!empty($event['start_time'])){
    echo date("h:i A", strtotime($event['start_time']));
}
else{
    echo "Not set";
}
?>

            </div>
        </div>

        <div class="cdw-event-field">
            <span class="cdw-label">Location</span>
            <div class="cdw-value">
                <?php echo !empty($event['location']) ? htmlspecialchars($event['location']) : 'Not specified'; ?>
            </div>
        </div>

    </div>

    <span class="cdw-event-status">
        <?php echo htmlspecialchars($event['status']); ?>
    </span>

</div>
            <?php endwhile; ?>

        </div>
    <?php else: ?>

        <div class="empty-box">
            No upcoming community events.
        </div>

    <?php endif; ?>

</div>


    <div class="chart-section">
        <div class="chart-box">
            <div class="chart-title">
                Summary of Food Group Consumption
                <?php echo isset($_SESSION['active_cdc_name']) ? htmlspecialchars(strtoupper(formatCdcDisplayName($_SESSION['active_cdc_name']))) : ''; ?>
            </div>
            <div class="chart-canvas-wrap">
                <canvas id="foodChart"></canvas>
            </div>
        </div>

        <div class="chart-box">
            <div class="chart-title">
                Graphical Representation of the Nutritional Status of Children
                <?php echo isset($_SESSION['active_cdc_name']) ? htmlspecialchars(strtoupper(formatCdcDisplayName($_SESSION['active_cdc_name']))) : ''; ?>
            </div>
            <div class="chart-canvas-wrap">
                <canvas id="nutriChart"></canvas>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const foodChartLabels = <?php echo json_encode($food_chart_labels); ?>;
const foodChartFullLabels = <?php echo json_encode($food_chart_full_labels); ?>;
const foodChartData = <?php echo json_encode($food_chart_counts); ?>;

const nutriChartLabels = <?php echo json_encode($nutri_chart_labels); ?>;
const nutriChartFullLabels = <?php echo json_encode($nutri_chart_full_labels); ?>;
const nutriChartData = <?php echo json_encode($nutri_chart_counts); ?>;

const axisColor = <?php echo json_encode($is_dark_mode ? '#cbd5e1' : '#475569'); ?>;
const gridColor = <?php echo json_encode('rgba(148, 163, 184, 0.18)'); ?>;

new Chart(document.getElementById('foodChart'), {
    type: 'bar',
    data: {
        labels: foodChartLabels,
        datasets: [{
            data: foodChartData,
            backgroundColor: [
                '#8acb99', '#5ea96a', '#5ca767', '#8acb99', '#9ac29f',
                '#5ea96a', '#5ca767', '#a1c7a5', '#84c18c', '#5ca767', '#a8d5ad'
            ],
            borderRadius: 8,
            borderSkipped: false,
            maxBarThickness: 44
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: {
            duration: 700
        },
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: '#1e293b',
                titleColor: '#ffffff',
                bodyColor: '#ffffff',
                displayColors: false,
                callbacks: {
                    title: function(context) {
                        return foodChartFullLabels[context[0].dataIndex] || '';
                    }
                }
            }
        },
        layout: {
            padding: {
                top: 8,
                right: 8,
                bottom: 0,
                left: 8
            }
        },
        scales: {
            x: {
                ticks: {
                    color: axisColor,
                    font: {
                        family: 'Inter',
                        size: 10
                    },
                    autoSkip: false,
                    maxRotation: 0,
                    minRotation: 0
                },
                grid: {
                    display: false,
                    drawBorder: false
                },
                border: {
                    display: false
                }
            },
            y: {
                beginAtZero: true,
                ticks: {
                    precision: 0,
                    stepSize: 1,
                    color: axisColor,
                    font: {
                        family: 'Inter',
                        size: 10
                    }
                },
                grid: {
                    color: gridColor,
                    drawBorder: false
                },
                border: {
                    display: false
                }
            }
        }
    }
});

new Chart(document.getElementById('nutriChart'), {
    type: 'bar',
    data: {
        labels: nutriChartLabels,
        datasets: [{
            data: nutriChartData,
            backgroundColor: [
                '#47b248',
                '#ddbbbb',
                '#ff3d3d',
                '#e5b8b8',
                '#ff3d3d',
                '#dcc0c0',
                '#ff3d3d',
                '#eb5656',
                '#ff3d3d'
            ],
            borderRadius: 8,
            borderSkipped: false,
            maxBarThickness: 44
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: {
            duration: 700
        },
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: '#1e293b',
                titleColor: '#ffffff',
                bodyColor: '#ffffff',
                displayColors: false,
                callbacks: {
                    title: function(context) {
                        return nutriChartFullLabels[context[0].dataIndex] || '';
                    }
                }
            }
        },
        layout: {
            padding: {
                top: 8,
                right: 8,
                bottom: 0,
                left: 8
            }
        },
        scales: {
            x: {
                ticks: {
                    color: axisColor,
                    font: {
                        family: 'Inter',
                        size: 10
                    },
                    autoSkip: false,
                    maxRotation: 0,
                    minRotation: 0
                },
                grid: {
                    display: false,
                    drawBorder: false
                },
                border: {
                    display: false
                }
            },
            y: {
                beginAtZero: true,
                ticks: {
                    precision: 0,
                    stepSize: 1,
                    color: axisColor,
                    font: {
                        family: 'Inter',
                        size: 10
                    }
                },
                grid: {
                    color: gridColor,
                    drawBorder: false
                },
                border: {
                    display: false
                }
            }
        }
    }
});



</script>
<script src="../assets/cdw/sidebar.js"></script>
</body>
</html>