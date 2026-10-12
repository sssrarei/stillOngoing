<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../includes/auth.php';
include '../config/database.php';
require_once '../includes/eccd_form1.php';

checkRole(3);

if (!isset($_SESSION['theme_mode'])) {
    $_SESSION['theme_mode'] = 'light';
}

$current_page = 'profile_information';
$user_id = (int)$_SESSION['user_id'];

$message = '';
$message_type = '';

$relationship_options = ['Mother','Father','Grandmother','Grandfather','Guardian','Aunt','Uncle','Sibling','Other'];

/*
|--------------------------------------------------------------------------
| LOAD GUARDIAN ACCOUNT
| users table first, guardians table as fallback for blank fields
|--------------------------------------------------------------------------
*/
$user = null;

$load_stmt = $conn->prepare("
    SELECT first_name, last_name, email, contact_number, address
    FROM users
    WHERE user_id = ?
    LIMIT 1
");

if ($load_stmt) {
    $load_stmt->bind_param("i", $user_id);
    $load_stmt->execute();
    $user = $load_stmt->get_result()->fetch_assoc();
    $load_stmt->close();
}

if (!$user) {
    die("Guardian account not found.");
}

/* the child this guardian is linked to (Form 1 belongs to the child) */
$child_id = 0;
$link_stmt = $conn->prepare("SELECT child_id FROM parent_child_links WHERE parent_id = ? ORDER BY link_id ASC LIMIT 1");
if ($link_stmt) {
    $link_stmt->bind_param("i", $user_id);
    $link_stmt->execute();
    $link_row = $link_stmt->get_result()->fetch_assoc();
    $link_stmt->close();
    if ($link_row) {
        $child_id = (int)$link_row['child_id'];
    }
}

/* guardians row: relationship to the child (and fallback contact/address) */
$guardian_row = null;
$g_stmt = $conn->prepare("
    SELECT guardian_id, relationship_to_child, contact_number, address
    FROM guardians
    WHERE user_id = ?
    ORDER BY guardian_id ASC
    LIMIT 1
");
if ($g_stmt) {
    $g_stmt->bind_param("i", $user_id);
    $g_stmt->execute();
    $guardian_row = $g_stmt->get_result()->fetch_assoc();
    $g_stmt->close();
}

if ($guardian_row) {
    if (trim((string)$user['contact_number']) === '') {
        $user['contact_number'] = $guardian_row['contact_number'];
    }
    if (trim((string)$user['address']) === '') {
        $user['address'] = $guardian_row['address'];
    }
}

$user['relationship_to_child'] = $guardian_row['relationship_to_child'] ?? '';

/* Form 1 answers saved for the child (empty if nothing saved yet) */
$f1 = eccd_f1_empty();
if ($child_id > 0) {
    $f1 = eccd_f1_load($conn, $child_id);
}

/*
|--------------------------------------------------------------------------
| SAVE
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $relationship_to_child = trim($_POST['relationship_to_child'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $address = trim($_POST['address'] ?? '');

    // what was typed stays on screen if something fails
    $user['first_name'] = $first_name;
    $user['last_name'] = $last_name;
    $user['relationship_to_child'] = $relationship_to_child;
    $user['email'] = $email;
    $user['contact_number'] = $contact_number;
    $user['address'] = $address;

    $f1_errors = array();
    if ($child_id > 0) {
        $f1 = eccd_f1_collect($_POST, $f1_errors);
        // the home_profile column must exist BEFORE the transaction (ALTER commits implicitly)
        eccd_f1_storage_ready($conn);
    }

    if ($first_name === '' || $last_name === '' || $email === '') {
        $message = 'First name, last name, and email are required.';
        $message_type = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
        $message_type = 'error';
    } elseif ($relationship_to_child !== '' && !in_array($relationship_to_child, $relationship_options, true)) {
        $message = 'Please choose a valid relationship to the child.';
        $message_type = 'error';
    } elseif (!empty($f1_errors)) {
        $message = implode(' ', $f1_errors);
        $message_type = 'error';
    } else {
        $check_email_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1");

        if (!$check_email_stmt) {
            $message = 'Failed to validate email address.';
            $message_type = 'error';
        } else {
            $check_email_stmt->bind_param("si", $email, $user_id);
            $check_email_stmt->execute();
            $email_exists = $check_email_stmt->get_result()->num_rows > 0;
            $check_email_stmt->close();

            if ($email_exists) {
                $message = 'That email address is already being used by another account.';
                $message_type = 'error';
            } else {
                $conn->begin_transaction();

                try {
                    /* 1. Guardian account (users) */
                    $update_stmt = $conn->prepare("
                        UPDATE users
                        SET first_name = ?, last_name = ?, email = ?, contact_number = ?, address = ?
                        WHERE user_id = ?
                    ");
                    if (!$update_stmt) {
                        throw new Exception('Failed to prepare profile update.');
                    }
                    $update_stmt->bind_param("sssssi", $first_name, $last_name, $email, $contact_number, $address, $user_id);
                    if (!$update_stmt->execute()) {
                        throw new Exception('Failed to update profile information.');
                    }
                    $update_stmt->close();

                    /* 2. Guardian profile (guardians) - keeps the CDW side in step */
                    if ($guardian_row) {
                        $g_update = $conn->prepare("
                            UPDATE guardians
                            SET first_name = ?, last_name = ?, relationship_to_child = ?,
                                contact_number = ?, email = ?, address = ?
                            WHERE user_id = ?
                        ");
                        if (!$g_update) {
                            throw new Exception('Failed to prepare guardian update.');
                        }
                        $g_update->bind_param("ssssssi", $first_name, $last_name, $relationship_to_child, $contact_number, $email, $address, $user_id);
                        if (!$g_update->execute()) {
                            throw new Exception('Failed to update guardian information.');
                        }
                        $g_update->close();
                    }

                    /* 3. Form 1 - Father's, Mother's and Family Profile */
                    if ($child_id > 0) {
                        $f1_error = '';
                        if (!eccd_f1_save($conn, $child_id, $f1, $f1_error)) {
                            throw new Exception($f1_error);
                        }
                        if (eccd_f1_has_any($f1)) {
                            eccd_f1_sync_children($conn, $child_id, $f1);
                        }
                    }

                    $conn->commit();

                    $_SESSION['first_name'] = $first_name;
                    $_SESSION['last_name'] = $last_name;
                    $_SESSION['email'] = $email;

                    // show exactly what is saved now
                    if ($child_id > 0) {
                        $f1 = eccd_f1_load($conn, $child_id);
                    }

                    $message = 'Profile information updated successfully.';
                    $message_type = 'success';

                } catch (Exception $e) {
                    $conn->rollback();
                    $message = $e->getMessage();
                    $message_type = 'error';
                }
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
    <title>Profile Information | NutriTrack</title>
    <link rel="stylesheet" href="../assets/guardian-style.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        .profile-shell{
            max-width:900px;
            margin:0 auto;
            width:100%;
        }

        .profile-card{
            background:#ffffff;
            border:1px solid #d7dde5;
            border-radius:22px;
            overflow:hidden;
            box-shadow:0 10px 30px rgba(15, 23, 42, 0.05);
            margin-bottom:22px;
        }

        .profile-card-header{
            background:#f5ede5;
            padding:20px 24px;
            border-bottom:1px solid #e7e7e7;
        }

        .profile-card-title{
            font-family:'Poppins', sans-serif;
            font-size:20px;
            font-weight:700;
            color:#c96f00;
        }

        .profile-card-subtitle{
            margin-top:6px;
            font-size:13px;
            color:#7b8794;
            font-family:'Inter', sans-serif;
        }

        .profile-card-body{
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

        .profile-form{
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:18px 20px;
        }

        .form-group{
            display:flex;
            flex-direction:column;
            gap:7px;
        }

        .form-group.full{
            grid-column:1 / -1;
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

        textarea.form-input{
            min-height:110px;
            resize:vertical;
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
            grid-column:1 / -1;
            margin-top:4px;
            display:flex;
            justify-content:flex-start;
        }

        .btn-save-profile{
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

        .btn-save-profile:hover{
            background:#a95d00;
        }

        .save-bar{
            display:flex;
            justify-content:flex-start;
            margin:4px 0 30px;
        }

        .no-child-note{
            padding:16px 18px;
            border:1px dashed #d7dde5;
            border-radius:14px;
            font-size:13px;
            color:#6b7280;
            line-height:1.6;
            background:#fff;
            margin-bottom:22px;
        }

        /* ---- Form 1 cards (1.A, 1.B, 1.C) made to match this page ---- */
        .form-card{
            background:#ffffff;
            border:1px solid #d7dde5;
            border-radius:22px;
            padding:24px;
            margin-bottom:22px;
            box-shadow:0 10px 30px rgba(15, 23, 42, 0.05);
        }

        .form-card .section-title{
            font-family:'Poppins', sans-serif;
            font-size:18px;
            font-weight:700;
            color:#c96f00;
            margin:0 0 4px;
        }

        .form-card .section-subtitle{
            font-size:13px;
            color:#7b8794;
            margin:0 0 14px;
            font-family:'Inter', sans-serif;
        }

        /* guardian pages use orange instead of the CDW green */
        .eccd-opt input{accent-color:#c96f00;}
        .eccd-text:focus{border-color:#c96f00;box-shadow:0 0 0 3px rgba(201,111,0,0.08);}
        .eccd-ece-title{color:#c96f00;}

        body.dark-mode .profile-card,
        body.dark-mode .form-card,
        body.dark-mode .no-child-note{
            background:#111827;
            border-color:#334155;
        }

        body.dark-mode .profile-card-header{
            background:#1e293b;
            border-bottom-color:#334155;
        }

        body.dark-mode .profile-card-title,
        body.dark-mode .form-card .section-title{
            color:#f8fafc;
        }

        body.dark-mode .profile-card-subtitle,
        body.dark-mode .form-card .section-subtitle,
        body.dark-mode .form-label,
        body.dark-mode .form-help,
        body.dark-mode .no-child-note{
            color:#cbd5e1;
        }

        body.dark-mode .form-input{
            background:#0f172a;
            color:#f8fafc;
            border-color:#475569;
        }

        @media (max-width: 768px){
            .profile-card-header,
            .profile-card-body{
                padding:18px;
            }

            .form-card{
                padding:18px;
            }

            .profile-form{
                grid-template-columns:1fr;
            }
        }
    </style>
    <?php echo eccd_form_css(); ?>
    <?php echo eccd_f1_css(); ?>
</head>
<body class="<?php echo (isset($_SESSION['theme_mode']) && $_SESSION['theme_mode'] === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/guardian_topbar.php'; ?>
<?php include '../includes/guardian_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="profile-shell">

        <?php if (!empty($message)) { ?>
            <div class="message-box <?php echo htmlspecialchars($message_type); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php } ?>

        <form method="POST">

            <!-- ============================
                 GUARDIAN INFORMATION
            ============================= -->
            <div class="profile-card">
                <div class="profile-card-header">
                    <h1 class="profile-card-title">Profile Information</h1>
                    <div class="profile-card-subtitle">Update your guardian account details and the Home Profile (Form 1) of your child.</div>
                </div>

                <div class="profile-card-body">
                    <div class="profile-form">
                        <div class="form-group">
                            <label for="first_name" class="form-label">First Name</label>
                            <input
                                type="text"
                                name="first_name"
                                id="first_name"
                                class="form-input"
                                value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input
                                type="text"
                                name="last_name"
                                id="last_name"
                                class="form-input"
                                value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="relationship_to_child" class="form-label">Relationship to the Child</label>
                            <select name="relationship_to_child" id="relationship_to_child" class="form-input">
                                <option value="">Select Relationship</option>
                                <?php foreach ($relationship_options as $option) { ?>
                                    <option value="<?php echo htmlspecialchars($option); ?>" <?php echo (($user['relationship_to_child'] ?? '') === $option) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($option); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="contact_number" class="form-label">Contact Number</label>
                            <input
                                type="text"
                                name="contact_number"
                                id="contact_number"
                                class="form-input"
                                value="<?php echo htmlspecialchars($user['contact_number'] ?? ''); ?>"
                            >
                        </div>

                        <div class="form-group full">
                            <label for="email" class="form-label">Email</label>
                            <input
                                type="email"
                                name="email"
                                id="email"
                                class="form-input"
                                value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"
                                required
                            >
                        </div>

                        <div class="form-group full">
                            <label for="address" class="form-label">Address</label>
                            <textarea
                                name="address"
                                id="address"
                                class="form-input"
                            ><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============================
                 FORM 1.A / 1.B / 1.C
            ============================= -->
            <?php if ($child_id > 0) { ?>
                <?php echo eccd_f1_render_all($f1); ?>
            <?php } else { ?>
                <div class="no-child-note">
                    Your account is not linked to a child yet, so the Father's Profile, Mother's Profile and Family Profile
                    are not available. You can still update your guardian information above.
                </div>
            <?php } ?>

            <div class="save-bar">
                <button type="submit" class="btn-save-profile">Save Changes</button>
            </div>

        </form>

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
<?php echo eccd_form_js(); ?>
<?php echo eccd_f1_js(); ?>

</body>
</html>