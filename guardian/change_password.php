<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../includes/auth.php';
include '../config/database.php';

checkRole(3);

if (!isset($_SESSION['theme_mode'])) {
    $_SESSION['theme_mode'] = 'light';
}

$current_page = 'change_password';
$user_id = (int)$_SESSION['user_id'];

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = trim($_POST['current_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    if ($current_password === '' || $new_password === '' || $confirm_password === '') {
        $message = 'Please fill in all password fields.';
        $message_type = 'error';
    } elseif ($new_password !== $confirm_password) {
        $message = 'New password and confirm password do not match.';
        $message_type = 'error';
    } elseif (strlen($new_password) < 4) {
        $message = 'New password must be at least 4 characters.';
        $message_type = 'error';
    } else {
        $check_sql = "SELECT password FROM users WHERE user_id = ? LIMIT 1";
        $check_stmt = $conn->prepare($check_sql);

        if ($check_stmt) {
            $check_stmt->bind_param("i", $user_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $user = $result->fetch_assoc();
            $check_stmt->close();

            if (!$user) {
                $message = 'User account not found.';
                $message_type = 'error';
            } elseif (!password_verify($current_password, $user['password'])) {
                $message = 'Current password is incorrect.';
                $message_type = 'error';
            } elseif ($current_password === $new_password) {
                $message = 'New password must be different from current password.';
                $message_type = 'error';
            } else {
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_sql = "UPDATE users SET password = ? WHERE user_id = ?";
                $update_stmt = $conn->prepare($update_sql);

                if ($update_stmt) {
                    $update_stmt->bind_param("si", $new_password_hash, $user_id);

                    if ($update_stmt->execute()) {
                        $message = 'Password changed successfully.';
                        $message_type = 'success';
                    } else {
                        $message = 'Failed to update password.';
                        $message_type = 'error';
                    }

                    $update_stmt->close();
                } else {
                    $message = 'Failed to prepare password update.';
                    $message_type = 'error';
                }
            }
        } else {
            $message = 'Failed to check current password.';
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password | NutriTrack</title>
    <link rel="stylesheet" href="../assets/guardian-style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        .password-shell{
            max-width:780px;
            margin:0 auto;
            width:100%;
        }

        .password-card{
            background:#ffffff;
            border:1px solid #d7dde5;
            border-radius:22px;
            overflow:hidden;
            box-shadow:0 10px 30px rgba(15, 23, 42, 0.05);
        }

        .password-card-header{
            background:#f5ede5;
            padding:20px 24px;
            border-bottom:1px solid #e7e7e7;
        }

        .password-card-title{
            font-family:'Poppins', sans-serif;
            font-size:20px;
            font-weight:700;
            color:#c96f00;
        }

        .password-card-subtitle{
            margin-top:6px;
            font-size:13px;
            color:#7b8794;
            font-family:'Inter', sans-serif;
        }

        .password-card-body{
            padding:24px;
        }

        .message-box{
            margin-bottom:18px;
            padding:14px 16px;
            border-radius:12px;
            font-size:14px;
            font-weight:600;
            line-height:1.6;
        }

        .message-box.success{
            background:#e8f5e9;
            color:#2e7d32;
            border:1px solid #c8e6c9;
        }

        .message-box.error{
            background:#fdeaea;
            color:#b42318;
            border:1px solid #efb0b0;
        }

        .password-form{
            display:flex;
            flex-direction:column;
            gap:18px;
        }

        .form-group{
            display:flex;
            flex-direction:column;
            gap:7px;
        }

        .form-label{
            font-size:13px;
            font-weight:600;
            color:#555;
            font-family:'Inter', sans-serif;
        }

        .form-input{
            width:100%;
            border:1px solid #cfcfcf;
            border-radius:12px;
            padding:12px 14px;
            font-family:'Inter', sans-serif;
            font-size:14px;
            color:#243041;
            background:#ffffff;
            outline:none;
        }

        .form-input:focus{
            border-color:#c96f00;
            box-shadow:0 0 0 3px rgba(201,111,0,0.08);
        }

        .form-help{
            font-size:12px;
            color:#6b7280;
            line-height:1.5;
        }

        .form-actions{
            margin-top:4px;
            display:flex;
            justify-content:flex-start;
        }

        .btn-save-password{
            border:none;
            border-radius:10px;
            padding:12px 18px;
            background:#c96f00;
            color:#ffffff;
            font-size:14px;
            font-weight:600;
            font-family:'Inter', sans-serif;
            cursor:pointer;
            transition:0.2s ease;
        }

        .btn-save-password:hover{
            background:#a95d00;
        }

        body.dark-mode .password-card{
            background:#111827;
            border-color:#334155;
        }

        body.dark-mode .password-card-header{
            background:#1e293b;
            border-bottom-color:#334155;
        }

        body.dark-mode .password-card-title{
            color:#f8fafc;
        }

        body.dark-mode .password-card-subtitle,
        body.dark-mode .form-label,
        body.dark-mode .form-help{
            color:#cbd5e1;
        }

        body.dark-mode .form-input{
            background:#0f172a;
            color:#f8fafc;
            border-color:#475569;
        }

        @media (max-width: 768px){
            .password-card-header,
            .password-card-body{
                padding:18px;
            }
        }
    </style>
</head>
<body class="<?php echo (isset($_SESSION['theme_mode']) && $_SESSION['theme_mode'] === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/guardian_topbar.php'; ?>
<?php include '../includes/guardian_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="password-shell">
        <div class="password-card">
            <div class="password-card-header">
                <h1 class="password-card-title">Change Password</h1>
                <div class="password-card-subtitle">Update your account password.</div>
            </div>

            <div class="password-card-body">
                <?php if (!empty($message)) { ?>
                    <div class="message-box <?php echo htmlspecialchars($message_type); ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php } ?>

                <form method="POST" class="password-form">
                    <div class="form-group">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" name="current_password" id="current_password" class="form-input" required>
                    </div>

                    <div class="form-group">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" name="new_password" id="new_password" class="form-input" required>
                        <div class="form-help">Use a password that is easy to remember but different from your current password.</div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-input" required>
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="btn-save-password">Save New Password</button>
                    </div>
                </form>
            </div>
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
    if (window.innerWidth > 991) {
        sidebar.classList.remove('show');
        sidebarOverlay.classList.remove('show');
    }
});
</script>

</body>
</html>