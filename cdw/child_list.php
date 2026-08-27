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

$cdc_id = $_SESSION['active_cdc_id'];
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$theme_mode = $_SESSION['theme_mode'];

// Count for the "View Deleted Children" button badge (30-day restorable window)
$deleted_count = 0;
$deleted_count_stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM children
    WHERE cdc_id = ?
      AND is_deleted = 1
      AND deleted_at IS NOT NULL
      AND deleted_at >= (NOW() - INTERVAL 30 DAY)
");
$deleted_count_stmt->bind_param("i", $cdc_id);
$deleted_count_stmt->execute();
$deleted_count_row = $deleted_count_stmt->get_result()->fetch_assoc();
$deleted_count = (int) ($deleted_count_row['total'] ?? 0);
$deleted_count_stmt->close();

$per_page = 10;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
if($page < 1){
    $page = 1;
}
$offset = ($page - 1) * $per_page;

function compute_age_months_from_birthdate($birthdate){
    if(empty($birthdate) || $birthdate == '0000-00-00'){
        return 'N/A';
    }

    try{
        $birth = new DateTime($birthdate);
        $today = new DateTime();
        $diff = $birth->diff($today);

        return ($diff->y * 12) + $diff->m;
    } catch(Exception $e){
        return 'N/A';
    }
}

if ($search !== "") {
    $count_sql = "SELECT COUNT(*) AS total
              FROM children
              WHERE cdc_id = ?
                AND is_deleted = 0
                AND (
                    first_name LIKE ?
                    OR middle_name LIKE ?
                    OR last_name LIKE ?
                )";
    $count_stmt = $conn->prepare($count_sql);
    $search_param = "%" . $search . "%";
    $count_stmt->bind_param("isss", $cdc_id, $search_param, $search_param, $search_param);
    $count_stmt->execute();
    $total_rows = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();

    $child_sql = "SELECT child_id, first_name, middle_name, last_name, sex, birthdate
              FROM children
              WHERE cdc_id = ?
                AND is_deleted = 0
                AND (
                    first_name LIKE ?
                    OR middle_name LIKE ?
                    OR last_name LIKE ?
                )
              ORDER BY first_name ASC, last_name ASC
              LIMIT ? OFFSET ?";

    $child_stmt = $conn->prepare($child_sql);
    $child_stmt->bind_param("isssii", $cdc_id, $search_param, $search_param, $search_param, $per_page, $offset);
} else {
    $count_sql = "SELECT COUNT(*) AS total
              FROM children
              WHERE cdc_id = ?
                AND is_deleted = 0";
    $count_stmt = $conn->prepare($count_sql);
    $count_stmt->bind_param("i", $cdc_id);
    $count_stmt->execute();
    $total_rows = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();

    $child_sql = "SELECT child_id, first_name, middle_name, last_name, sex, birthdate
              FROM children
              WHERE cdc_id = ?
                AND is_deleted = 0
              ORDER BY first_name ASC, last_name ASC
              LIMIT ? OFFSET ?";

    $child_stmt = $conn->prepare($child_sql);
    $child_stmt->bind_param("iii", $cdc_id, $per_page, $offset);
}

$child_stmt->execute();
$child_result = $child_stmt->get_result();

$total_pages = ($total_rows > 0) ? ceil($total_rows / $per_page) : 1;
if($page > $total_pages){
    $page = $total_pages;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pupil Records | NutriTrack</title>
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

        .top-actions{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:14px;
            flex-wrap:wrap;
            margin-bottom:18px;
        }

        .search-form{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            flex:1;
        }

        .search-input{
            min-width:260px;
            border:1px solid #cfcfcf;
            border-radius:10px;
            padding:11px 13px;
            font-size:13px;
            font-family:'Inter', sans-serif;
            color:#333;
            outline:none;
            background:#ffffff;
        }

        .search-input:focus{
            border-color:#2E7D32;
            box-shadow:0 0 0 3px rgba(46,125,50,0.08);
        }

        .btn{
            border:none;
            border-radius:10px;
            padding:11px 16px;
            font-size:13px;
            font-weight:600;
            font-family:'Inter', sans-serif;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .btn-search{
            background:#2E7D32;
            color:#fff;
        }

        .btn-reset{
            background:#f5f5f5;
            color:#555;
            border:1px solid #d6d6d6;
        }

        .btn-add{
            background:#2E7D32;
            color:#fff;
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
            min-width:950px;
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

        .btn-view{
            background:#2E7D32;
            color:#fff;
            padding:9px 14px;
            font-size:13px;
            border-radius:8px;
            font-weight:600;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .no-data{
            padding:14px 2px 0;
            font-size:13px;
            color:#777;
        }

        .pagination{
            display:flex;
            justify-content:center;
            align-items:center;
            gap:6px;
            flex-wrap:wrap;
            margin-top:18px;
        }

        .page-link{
            min-width:36px;
            height:36px;
            padding:0 10px;
            border-radius:8px;
            border:1px solid #d6d6d6;
            background:#ffffff;
            color:#333;
            font-size:13px;
            font-weight:600;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .page-link:hover{
            border-color:#2E7D32;
            color:#2E7D32;
        }

        .page-link.active{
            background:#2E7D32;
            border-color:#2E7D32;
            color:#fff;
        }

        .page-link.disabled{
            opacity:0.45;
            pointer-events:none;
        }

        body.dark-mode .page-link{
            background:#111827;
            border-color:#334155;
            color:#e5e7eb;
        }

        body.dark-mode .page-link.active{
            background:#2E7D32;
            border-color:#2E7D32;
            color:#fff;
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
        body.dark-mode .no-data{
            color:#cbd5e1;
        }

        body.dark-mode .search-input{
            background:#0f172a;
            color:#f8fafc;
            border-color:#475569;
        }

        body.dark-mode .btn-reset{
            background:#1e293b;
            color:#e5e7eb;
            border-color:#334155;
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

            .top-actions{
                flex-direction:column;
                align-items:stretch;
            }

            .search-form{
                width:100%;
            }

            .search-input{
                width:100%;
                min-width:unset;
            }
        }
    </style>
</head>
<body class="<?php echo ($theme_mode === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-header">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        <h1 class="page-title">Pupil Records</h1>
        <div class="page-subtitle">
            Active CDC: <?php echo htmlspecialchars($_SESSION['active_cdc_name']); ?>
        </div>
    </div>

    <div class="content-card">
        <div class="top-actions">
            <form method="GET" class="search-form">
                <input
                    type="text"
                    name="search"
                    class="search-input"
                    placeholder="Search Pupils"
                    value="<?php echo htmlspecialchars($search); ?>"
                >
                <button type="submit" class="btn btn-search">Search</button>

                <?php if($search !== ""){ ?>
                    <a href="child_list.php" class="btn btn-reset">Reset</a>
                <?php } ?>
            </form>

            <a href="add_child.php" class="btn btn-add">+ Add Child</a>
            <a href="deleted_children.php" class="btn btn-reset">🗑 View Deleted Children<?php echo $deleted_count > 0 ? " ({$deleted_count})" : ""; ?></a>
        </div>

        <?php if($child_result && $child_result->num_rows > 0){ ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Full Name</th>
                            <th>Sex</th>
                            <th>Birthdate</th>
                            <th>Age in Months</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $child_result->fetch_assoc()){
                            $middle_name = !empty($row['middle_name']) ? $row['middle_name'] . " " : "";
                            $full_name = trim($row['first_name'] . " " . $middle_name . $row['last_name']);
                            $age_months = compute_age_months_from_birthdate($row['birthdate']);
                        ?>
                        <tr>
                            <td class="child-name"><?php echo htmlspecialchars($full_name); ?></td>
                            <td><?php echo htmlspecialchars($row['sex']); ?></td>
                            <td><?php echo date("F d, Y", strtotime($row['birthdate'])); ?></td>
                            <td><?php echo htmlspecialchars($age_months); ?> month(s)</td>
                            <td>
                                <a href="child_profile.php?child_id=<?php echo $row['child_id']; ?>" class="btn-view">View</a>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <?php if($total_pages > 1){ ?>
                <div class="pagination">
                    <?php
                        $query_params = $search !== "" ? "&search=" . urlencode($search) : "";

                        $prev_page = max(1, $page - 1);
                        $next_page = min($total_pages, $page + 1);

                        $prev_disabled = ($page <= 1) ? "disabled" : "";
                        $next_disabled = ($page >= $total_pages) ? "disabled" : "";
                    ?>
                    <a href="?page=<?php echo $prev_page . $query_params; ?>" class="page-link <?php echo $prev_disabled; ?>">‹</a>

                    <?php for($i = 1; $i <= $total_pages; $i++){
                        $active_class = ($i == $page) ? "active" : "";
                    ?>
                        <a href="?page=<?php echo $i . $query_params; ?>" class="page-link <?php echo $active_class; ?>"><?php echo $i; ?></a>
                    <?php } ?>

                    <a href="?page=<?php echo $next_page . $query_params; ?>" class="page-link <?php echo $next_disabled; ?>">›</a>
                </div>
            <?php } ?>
        <?php } else { ?>
            <p class="no-data">No pupils found.</p>
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