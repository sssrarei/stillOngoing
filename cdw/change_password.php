<?php
include '../includes/auth.php';
include '../config/database.php';

if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] != 2) {
    header("Location: ../login.php");
    exit();
}

if (!isset($_SESSION['theme_mode'])) {
    $_SESSION['theme_mode'] = 'light';
}

$user_id = $_SESSION['user_id'];
$success = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = trim($_POST['current_password']);
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    if ($current_password === '' || $new_password === '' || $confirm_password === '') {
        $error = "All password fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New password and confirm password do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters long.";
    } else {
        $sql_check = "SELECT password FROM users WHERE user_id = ? AND role_id = 2 LIMIT 1";
        $stmt_check = $conn->prepare($sql_check);

        if ($stmt_check) {
            $stmt_check->bind_param("i", $user_id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();

            if ($result_check && $result_check->num_rows > 0) {
                $row_check = $result_check->fetch_assoc();

                if (!password_verify($current_password, $row_check['password'])) {
                    $error = "Current password is incorrect.";
                } else {
                    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $sql_update = "UPDATE users SET password = ? WHERE user_id = ? AND role_id = 2";
                    $stmt_update = $conn->prepare($sql_update);

                    if ($stmt_update) {
                        $stmt_update->bind_param("si", $new_password_hash, $user_id);

                        if ($stmt_update->execute()) {
                            $success = "Password changed successfully.";
                        } else {
                            $error = "Failed to change password.";
                        }

                        $stmt_update->close();
                    } else {
                        $error = "Failed to prepare password update.";
                    }
                }
            } else {
                $error = "User not found.";
            }

            $stmt_check->close();
        } else {
            $error = "Failed to prepare password check.";
        }
    }
}

$theme_mode = $_SESSION['theme_mode'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | NutriTrack</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
    <link rel="stylesheet" href="../assets/cdw/cdw-topbar-notification.css">

    <style>
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
            max-width:760px;
        }

        .section-title{
            font-family:'Poppins', sans-serif;
            font-size:18px;
            font-weight:700;
            color:#2f2f2f;
            margin-bottom:6px;
        }

        .section-subtitle{
            font-size:13px;
            color:#666;
            line-height:1.6;
            margin-bottom:18px;
        }

        .password-grid{
            display:grid;
            grid-template-columns:1fr;
            gap:14px;
        }

        .form-group label{
            display:block;
            font-size:12px;
            color:#666;
            margin-bottom:6px;
            font-weight:500;
        }

        .form-control{
            width:100%;
            border:1px solid #cfcfcf;
            border-radius:8px;
            padding:11px 12px;
            font-size:13px;
            font-family:'Inter', sans-serif;
            background:#fff;
            color:#333;
            outline:none;
        }

        .form-control:focus{
            border-color:#2E7D32;
            box-shadow:0 0 0 3px rgba(46,125,50,0.08);
        }

        .button-group{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin-top:18px;
        }

        .btn{
            border:none;
            border-radius:8px;
            padding:11px 16px;
            font-size:13px;
            font-weight:600;
            font-family:'Inter', sans-serif;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .btn-save{
            background:#2E7D32;
            color:#fff;
        }

        .success-message{
            background:#eaf7ee;
            color:#1f7a46;
            border:1px solid #c8e6d0;
            border-radius:10px;
            padding:14px 16px;
            margin-bottom:16px;
            font-size:13px;
            font-weight:600;
            max-width:760px;
        }

        .error-message{
            background:#fdeaea;
            color:#c62828;
            border:1px solid #f5c2c7;
            border-radius:10px;
            padding:14px 16px;
            margin-bottom:16px;
            font-size:13px;
            font-weight:600;
            max-width:760px;
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
        body.dark-mode .section-title{
            color:#f8fafc;
        }

        body.dark-mode .page-subtitle,
        body.dark-mode .section-subtitle,
        body.dark-mode .form-group label{
            color:#cbd5e1;
        }

        body.dark-mode .form-control{
            background:#0f172a;
            color:#f8fafc;
            border-color:#475569;
        }

        @media (max-width: 991px){
            .main-content{
                margin-left:0;
                padding:104px 16px 24px;
            }
        }
    </style>
</head>
<body class="<?php echo ($theme_mode === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-header">
        <a href="settings.php" class="back-link">← Back to Settings</a>
        <h1 class="page-title">Change Password</h1>
        <div class="page-subtitle">Update your account password.</div>
    </div>

    <?php if ($success != '') { ?>
        <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
    <?php } ?>

    <?php if ($error != '') { ?>
        <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
    <?php } ?>

    <div class="content-card">
        <div class="section-title">Password Information</div>
        <div class="section-subtitle">Enter your current password and set a new password.</div>

        <form method="POST">
            <div class="password-grid">
                <div class="form-group">
                    <label>Current Password</label>
                    <input type="password" name="current_password" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="new_password" class="form-control" required>
                </div>

                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" class="form-control" required>
                </div>
            </div>

            <div class="button-group">
                <button type="submit" name="change_password" class="btn btn-save">Change Password</button>
            </div>
        </form>
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