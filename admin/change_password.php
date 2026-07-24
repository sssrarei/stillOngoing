<?php
include '../includes/auth.php';
include '../config/database.php';
checkRole(1);

$user_id = $_SESSION['user_id'];
$success = "";
$error = "";

if (isset($_POST['change_password'])) {
    $current_password = trim($_POST['current_password']);
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    if ($current_password == "" || $new_password == "" || $confirm_password == "") {
        $error = "Please fill in all required fields.";
    } elseif ($new_password != $confirm_password) {
        $error = "New password and confirm password do not match.";
    } else {
        $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if (!$user) {
            $error = "User not found.";
        } elseif (!password_verify($current_password, $user['password'])) {
            $error = "Current password is incorrect.";
        } else {
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $update->bind_param("si", $new_password_hash, $user_id);

            if ($update->execute()) {
                $success = "Password changed successfully.";
            } else {
                $error = "Failed to change password.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password</title>
    <link rel="stylesheet" href="../assets/admin/admin-style.css">
    <link rel="stylesheet" href="../assets/admin/admin_change_password.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
</head>
<body class="<?php echo (isset($_SESSION['theme_mode']) && $_SESSION['theme_mode'] === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/admin_sidebar.php'; ?>
<?php include '../includes/admin_topbar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-header">
        <h1>Change Password</h1>
        <p>Update your account password securely.</p>
    </div>

    <?php if ($success != "") { ?>
        <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
    <?php } ?>

    <?php if ($error != "") { ?>
        <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
    <?php } ?>

    <div class="password-card">
        <div class="card-header">
            <h2>Password Settings</h2>
            <p>Enter your current password and choose a new one.</p>
        </div>

        <form method="POST">
            <div class="form-grid">
                <div class="form-group full">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required>
                </div>

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" name="change_password" class="btn btn-primary">Save Password</button>
                <a href="settings.php" class="btn btn-light">Back to Profile</a>
            </div>
        </form>
    </div>
</div>


<script src="../assets/admin/sidebar.js"></script>
</body>
</html>