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

if(!isset($_SESSION['active_cdc_id'])){
    die("Please select an active CDC first from the dashboard.");
}

$cdc_id = (int) $_SESSION['active_cdc_id'];
$theme_mode = $_SESSION['theme_mode'];
$error = "";
$success = "";

/*
|--------------------------------------------------------------------------
| HANDLE RESTORE
| Only allowed when the child:
|   - belongs to this CDW's active CDC
|   - is currently soft-deleted
|   - was deleted within the last 30 days
| Restoring un-hides the child AND its related monitoring records,
| mirroring the same tables that delete_child.php soft-deletes.
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_child_id'])) {
    $restore_id = (int) $_POST['restore_child_id'];

    $check_sql = "
        SELECT child_id
        FROM children
        WHERE child_id = ?
          AND cdc_id = ?
          AND is_deleted = 1
          AND deleted_at IS NOT NULL
          AND deleted_at >= (NOW() - INTERVAL 30 DAY)
        LIMIT 1
    ";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("ii", $restore_id, $cdc_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $check_stmt->close();

    if ($check_result && $check_result->num_rows > 0) {
        $conn->begin_transaction();

        try {
            $sql = "UPDATE children SET is_deleted = 0, deleted_at = NULL, deleted_by = NULL WHERE child_id = ? AND cdc_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ii", $restore_id, $cdc_id);
            $stmt->execute();
            $stmt->close();

            $sql = "UPDATE anthropometric_records SET is_deleted = 0 WHERE child_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $restore_id);
            $stmt->execute();
            $stmt->close();

            $sql = "UPDATE feeding_records SET is_deleted = 0 WHERE child_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $restore_id);
            $stmt->execute();
            $stmt->close();

            $sql = "UPDATE milk_feeding_records SET is_deleted = 0 WHERE child_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $restore_id);
            $stmt->execute();
            $stmt->close();

            $sql = "UPDATE deworming_records SET is_deleted = 0 WHERE child_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $restore_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            header("Location: deleted_children.php?restored=1");
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Restore failed. Please try again.";
        }
    } else {
        $error = "This child can no longer be restored (not found, already restored, or past the 30-day window).";
    }
}

if (isset($_GET['restored']) && $_GET['restored'] == '1') {
    $success = "Child record restored successfully.";
}

/*
|--------------------------------------------------------------------------
| LOAD DELETED CHILDREN (within 30-day window only)
| Records older than 30 days simply stop appearing here â€” they remain
| soft-deleted in the database but are no longer restorable from the UI.
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT c.child_id, c.first_name, c.middle_name, c.last_name, c.sex, c.deleted_at,
           u.first_name AS deleter_first_name, u.last_name AS deleter_last_name
    FROM children c
    LEFT JOIN users u ON c.deleted_by = u.user_id
    WHERE c.cdc_id = ?
      AND c.is_deleted = 1
      AND c.deleted_at IS NOT NULL
      AND c.deleted_at >= (NOW() - INTERVAL 30 DAY)
    ORDER BY c.deleted_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $cdc_id);
$stmt->execute();
$result = $stmt->get_result();

$deleted_children = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $middle_name = !empty($row['middle_name']) ? $row['middle_name'] . " " : "";
        $full_name = trim($row['first_name'] . " " . $middle_name . $row['last_name']);

        $deleted_at = new DateTime($row['deleted_at']);
        $expiry = clone $deleted_at;
        $expiry->modify('+30 days');
        $now = new DateTime();
        $days_remaining = (int) $now->diff($expiry)->format('%a');
        if ($now > $expiry) {
            $days_remaining = 0;
        }

        $deleter_name = trim(($row['deleter_first_name'] ?? '') . ' ' . ($row['deleter_last_name'] ?? ''));
        if ($deleter_name === '') {
            $deleter_name = 'Unknown';
        }

        $deleted_children[] = [
            'child_id' => $row['child_id'],
            'full_name' => $full_name,
            'sex' => $row['sex'],
            'deleted_at' => $row['deleted_at'],
            'deleted_by' => $deleter_name,
            'days_remaining' => $days_remaining,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deleted Children | NutriTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
    <link rel="stylesheet" href="../assets/cdw/cdw-topbar-notification.css">
    <style>
        *{
            box-sizing:border-box;
            margin:0;
            padding:0;
        }

        body{
            font-family:'Inter', sans-serif;
            background:#eef0f3;
            color:#333;
        }

        a{
            text-decoration:none;
        }

        .main-content{
            margin-left:260px;
            padding:112px 24px 30px;
            transition:margin-left 0.25s ease;
        }

        .main-content.full{
            margin-left:0;
        }

        .page-header{
            background:#ffffff;
            border:1px solid #dcdcdc;
            border-radius:14px;
            padding:22px 24px;
            margin-bottom:18px;
        }

        .back-link{
            display:inline-flex;
            align-items:center;
            gap:8px;
            margin-bottom:10px;
            font-size:13px;
            font-weight:600;
            color:#2E7D32;
        }

        .page-title{
            font-family:'Poppins', sans-serif;
            font-size:24px;
            font-weight:700;
            color:#2f2f2f;
            margin-bottom:6px;
        }

        .page-subtitle{
            font-size:13px;
            color:#666;
            line-height:1.6;
        }

        .content-card{
            background:#ffffff;
            border:1px solid #dcdcdc;
            border-radius:14px;
            padding:20px;
        }

        .message{
            border-radius:10px;
            padding:14px 16px;
            margin-bottom:16px;
            font-size:13px;
            font-weight:600;
        }

        .message.success{
            background:#e8f5e9;
            color:#2e7d32;
            border:1px solid #c8e6c9;
        }

        .message.error{
            background:#fdeaea;
            color:#c62828;
            border:1px solid #f5c2c7;
        }

        .info-note{
            font-size:13px;
            color:#666;
            margin-bottom:18px;
            line-height:1.6;
        }

        .table-wrapper{
            width:100%;
            overflow-x:auto;
            border:1px solid #ededed;
            border-radius:12px;
        }

        table{
            width:100%;
            border-collapse:collapse;
            min-width:800px;
        }

        th{
            background:#2E7D32;
            color:#fff;
            text-align:left;
            padding:13px 12px;
            font-family:'Poppins', sans-serif;
            font-size:14px;
            white-space:nowrap;
        }

        td{
            padding:13px 12px;
            border-bottom:1px solid #eeeeee;
            font-size:13px;
            vertical-align:middle;
            background:#ffffff;
            color:#333333;
        }

        tbody tr:hover{
            background:#f8fbf8;
        }

        tbody tr:hover td{
            background:#f8fbf8;
        }

        .child-name{
            font-weight:600;
            color:#2f2f2f;
        }

        .days-badge{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-width:80px;
            padding:6px 10px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            background:#fff3e0;
            color:#c27c0e;
            border:1px solid #f3c27a;
        }

        .days-badge.urgent{
            background:#fdeaea;
            color:#c62828;
            border-color:#f1b6b6;
        }

        .restore-form{
            margin:0;
        }

        .btn-restore{
            border:none;
            border-radius:8px;
            padding:9px 14px;
            font-size:13px;
            font-weight:600;
            font-family:'Inter', sans-serif;
            cursor:pointer;
            background:#2E7D32;
            color:#fff;
        }

        .btn-restore:hover{
            background:#256b29;
        }

        .no-data{
            padding:14px 2px 0;
            font-size:13px;
            color:#777;
        }

        body.dark-mode{
            background:#0f172a;
            color:#e5e7eb;
        }

        body.dark-mode .page-header,
        body.dark-mode .content-card{
            background:#111827;
            border-color:#334155;
        }

        body.dark-mode .page-title,
        body.dark-mode .child-name{
            color:#f8fafc;
        }

        body.dark-mode .page-subtitle,
        body.dark-mode .info-note,
        body.dark-mode .no-data{
            color:#cbd5e1;
        }

        body.dark-mode .table-wrapper{
            border-color:#334155;
        }

        body.dark-mode td{
            background:#111827;
            color:#e5e7eb;
            border-bottom:1px solid #334155;
        }

        body.dark-mode tbody tr:hover{
            background:#1e293b;
        }

        body.dark-mode tbody tr:hover td{
            background:#1e293b;
        }

        @media (max-width: 991px){
            .sidebar{
                transform:translateX(-100%);
            }

            .sidebar.open{
                transform:translateX(0);
            }

            .sidebar-overlay.show{
                display:block;
                position:fixed;
                top:88px;
                left:0;
                width:100%;
                height:calc(100vh - 88px);
                background:rgba(0,0,0,0.25);
                z-index:1040;
            }

            .main-content{
                margin-left:0;
                padding:104px 16px 24px;
            }

            .topbar{
                padding:0 12px;
            }

            .topbar-logo{
                height:44px;
            }

            .user-chip{
                display:none;
            }
        }
    </style>
</head>
<body class="<?php echo ($theme_mode === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-header">
        <a href="child_list.php" class="back-link">← Back to Pupil Records</a>
        <h1 class="page-title">Deleted Children</h1>
        <div class="page-subtitle">
            Active CDC: <?php echo htmlspecialchars($_SESSION['active_cdc_name']); ?>
        </div>
    </div>

    <div class="content-card">

        <?php if ($success !== "") { ?>
            <div class="message success"><?php echo htmlspecialchars($success); ?></div>
        <?php } ?>

        <?php if ($error !== "") { ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <p class="info-note">
            Deleted children remain here for 30 days and can be restored at any time within that window,
            along with their Records.
            After 30 days, records automatically stop appearing here and can no longer be restored from this page.
        </p>

        <?php if (!empty($deleted_children)) { ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Full Name</th>
                            <th>Sex</th>
                            <th>Deleted On</th>
                            <th>Deleted By</th>
                            <th>Days Remaining</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($deleted_children as $child) { ?>
                            <tr>
                                <td class="child-name"><?php echo htmlspecialchars($child['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($child['sex']); ?></td>
                                <td><?php echo date("F d, Y", strtotime($child['deleted_at'])); ?></td>
                                <td><?php echo htmlspecialchars($child['deleted_by']); ?></td>
                                <td>
                                    <span class="days-badge <?php echo ($child['days_remaining'] <= 5) ? 'urgent' : ''; ?>">
                                        <?php echo (int) $child['days_remaining']; ?> day(s)
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" class="restore-form" onsubmit="return confirm('Restore <?php echo htmlspecialchars(addslashes($child['full_name'])); ?>? This will also restore their related monitoring records.');">
                                        <input type="hidden" name="restore_child_id" value="<?php echo (int) $child['child_id']; ?>">
                                        <button type="submit" class="btn-restore">Restore</button>
                                    </form>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } else { ?>
            <p class="no-data">No deleted children within the last 30 days.</p>
        <?php } ?>

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