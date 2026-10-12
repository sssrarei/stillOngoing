<?php
include '../config/database.php';
require_once '../includes/eccd_form1.php';

$message = "";
$error = "";

/* Form 1 answers (1.A, 1.B, 1.C). Empty on first load. */
$f1_errors = array();
$f1 = eccd_f1_empty();

if (isset($_POST['register'])) {

    /* ============================
       GUARDIAN ACCOUNT FIELDS (system fields, not part of Form 1)
    ============================ */
    $first_name             = trim($_POST['first_name'] ?? '');
    $last_name               = trim($_POST['last_name'] ?? '');
    $relationship_to_child   = trim($_POST['relationship_to_child'] ?? '');
    $address                 = trim($_POST['address'] ?? '');
    $contact_number          = trim($_POST['contact_number'] ?? '');
    $email                   = trim($_POST['email'] ?? '');
    $password                = trim($_POST['password'] ?? '');
    $confirm_password        = trim($_POST['confirm_password'] ?? '');
    $access_code             = trim($_POST['access_code'] ?? '');

    /* ============================
       FORM 1 - HOME PROFILE
       (everything on Form 1 is optional)
    ============================ */
    $f1 = eccd_f1_collect($_POST, $f1_errors);
    // make sure the home_profile column exists BEFORE the transaction starts (ALTER commits implicitly)
    eccd_f1_storage_ready($conn);

    /* ============================
       REQUIRED FIELD VALIDATION
       (only the guardian account fields are required)
    ============================ */
    if (
        $first_name === '' || $last_name === '' || $relationship_to_child === '' ||
        $address === '' || $contact_number === '' || $email === '' ||
        $password === '' || $confirm_password === '' || $access_code === ''
    ) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($password !== $confirm_password) {
        $error = "Password and Confirm Password do not match.";
    } elseif (!empty($f1_errors)) {
        $error = implode(' ', $f1_errors);
    } else {

        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // Check if email already exists in users table
        $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
        $check_email->bind_param("s", $email);
        $check_email->execute();
        $check_email_result = $check_email->get_result();

        if ($check_email_result->num_rows > 0) {
            $error = "Email already exists.";
            $check_email->close();
        } else {
            $check_email->close();

            // Check if access code exists
            $child_query = $conn->prepare("SELECT child_id FROM children WHERE access_code = ? LIMIT 1");
            $child_query->bind_param("s", $access_code);
            $child_query->execute();
            $child_result = $child_query->get_result();

            if ($child_result->num_rows === 0) {
                $error = "Invalid child access code.";
                $child_query->close();
            } else {
                $child = $child_result->fetch_assoc();
                $child_id = (int) $child['child_id'];
                $child_query->close();

                // Check if child is already linked to a guardian
                $check_link = $conn->prepare("SELECT link_id FROM parent_child_links WHERE child_id = ? LIMIT 1");
                $check_link->bind_param("i", $child_id);
                $check_link->execute();
                $check_link_result = $check_link->get_result();

                if ($check_link_result->num_rows > 0) {
                    $error = "This child is already linked to a guardian.";
                    $check_link->close();
                } else {
                    $check_link->close();

                    // Start transaction to prevent incomplete guardian registration
                    $conn->begin_transaction();

                    try {
                        // 1. Insert guardian account into users table
                        $user_stmt = $conn->prepare("
                            INSERT INTO users (role_id, first_name, last_name, email, password, contact_number, address)
                            VALUES (3, ?, ?, ?, ?, ?, ?)
                        ");
                        $user_stmt->bind_param(
                            "ssssss",
                            $first_name,
                            $last_name,
                            $email,
                            $password_hash,
                            $contact_number,
                            $address
                        );

                        if (!$user_stmt->execute()) {
                            throw new Exception("User account creation failed: " . $user_stmt->error);
                        }

                        $parent_id = $conn->insert_id;
                        $user_stmt->close();

                        // 2. Insert guardian profile into guardians table
                        $guardian_stmt = $conn->prepare("
                            INSERT INTO guardians (child_id, user_id, first_name, last_name, relationship_to_child, contact_number, email, address, linked_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $guardian_stmt->bind_param(
                            "iissssss",
                            $child_id,
                            $parent_id,
                            $first_name,
                            $last_name,
                            $relationship_to_child,
                            $contact_number,
                            $email,
                            $address
                        );

                        if (!$guardian_stmt->execute()) {
                            throw new Exception("Guardian profile creation failed: " . $guardian_stmt->error);
                        }

                        $guardian_stmt->close();

                        // 3. Link guardian user to child
                        $link_stmt = $conn->prepare("
                            INSERT INTO parent_child_links (parent_id, child_id)
                            VALUES (?, ?)
                        ");
                        $link_stmt->bind_param("ii", $parent_id, $child_id);

                        if (!$link_stmt->execute()) {
                            throw new Exception("Guardian linking failed: " . $link_stmt->error);
                        }

                        $link_stmt->close();

                        // 4. Save Form 1 (Home Profile) for this child.
                        //    Skipped only when the guardian left Form 1 completely blank.
                        if (eccd_f1_has_any($f1)) {
                            $f1_save_error = '';
                            if (!eccd_f1_save($conn, $child_id, $f1, $f1_save_error)) {
                                throw new Exception($f1_save_error);
                            }

                            // keep children.mother_name / father_name etc. in step
                            eccd_f1_sync_children($conn, $child_id, $f1);
                        }

                        // All good - commit everything
                        $conn->commit();

                        $message = "Guardian registration successful! You can now login.";
                        $f1 = eccd_f1_empty();
                        $_POST = array();

                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = "Registration failed: " . $e->getMessage();
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Guardian Registration</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
        *{
            box-sizing:border-box;
        }

        body{
            margin:0;
            min-height:100vh;
            background:#eef0f3;
            font-family:'Inter', sans-serif;
            color:#333;
            padding:24px;
        }

        .page-wrapper{
            max-width:1100px;
            margin:0 auto;
        }

        .page-header{
    background:#ffffff;
    border:1px solid #dcdcdc;
    border-radius:16px;
    padding:22px 24px;
    margin-bottom:18px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    flex-wrap:wrap;
}

.page-header-text{
    flex:1;
    min-width:260px;
}

        .back-link{
            display:inline-flex;
            align-items:center;
            gap:8px;
            margin-bottom:12px;
            text-decoration:none;
            color:#16a34a;
            font-size:13px;
            font-weight:600;
        }

        .page-title{
            font-family:'Poppins', sans-serif;
            font-size:22px;
            line-height:1.3;
            color:#2f2f2f;
            margin:0 0 8px 0;
        }

        .page-subtitle{
            font-size:13px;
            color:#666;
            margin:0;
        }

        .message{
            border-radius:10px;
            padding:14px 16px;
            margin-bottom:16px;
            font-size:13px;
            font-weight:600;
        }

        .message.error{
            background:#fdeaea;
            color:#b30000;
            border:1px solid #efb0b0;
        }

        .message.success{
            background:#e8f5e9;
            color:#2e7d32;
            border:1px solid #c8e6c9;
        }

        .form-card{
            background:#ffffff;
            border:1px solid #dcdcdc;
            border-radius:16px;
            padding:22px;
            margin-bottom:18px;
        }

        .section-title{
            font-family:'Poppins', sans-serif;
            font-size:17px;
            color:#2f2f2f;
            margin:0 0 6px 0;
        }

        .section-subtitle{
            font-size:12px;
            color:#888;
            margin:0 0 18px 0;
        }

        .form-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:18px;
        }

        .form-row{
            margin-bottom:14px;
        }

        .form-row.full{
            grid-column:1 / -1;
        }

        .form-label{
            display:block;
            margin-bottom:6px;
            font-size:12px;
            font-weight:600;
            color:#666;
        }

        .form-control,
        .form-select{
            width:100%;
            border:1px solid #cfcfcf;
            border-radius:10px;
            padding:12px 13px;
            font-size:13px;
            font-family:'Inter', sans-serif;
            color:#333;
            background:#fff;
            outline:none;
            transition:border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-control:focus,
        .form-select:focus{
            border-color:#2E7D32;
            box-shadow:0 0 0 3px rgba(46,125,50,0.08);
        }

        .helper-text{
            font-size:12px;
            color:#777;
            margin-top:4px;
        }

        .form-actions{
            margin-top:6px;
            display:flex;
            justify-content:flex-end;
            gap:10px;
            flex-wrap:wrap;
        }

        .btn{
            border:none;
            border-radius:10px;
            padding:12px 16px;
            font-size:13px;
            font-weight:600;
            font-family:'Inter', sans-serif;
            cursor:pointer;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .btn-secondary{
            background:#e0e0e0;
            color:#444;
        }

        .btn-primary{
            background:#16a34a;
            color:#fff;
        }

        .btn-primary:hover{
            background:#4ADE80;
        }

        .system-field-card{
            border:1px dashed #cfcfcf;
            background:#fafafa;
        }

        .system-field-tag{
            display:inline-block;
            font-size:11px;
            font-weight:700;
            color:#888;
            background:#eee;
            padding:3px 8px;
            border-radius:6px;
            margin-bottom:10px;
        }

        .other-field-group{
            display:none;
            margin-top:10px;
        }

        .other-field-group.show{
            display:block;
        }

        /* ============================
           PRINT / SAVE BUTTON
           Fixed to the side margin of the page.
        ============================= */
        .print-form1-btn{
            border:none;
            border-radius:10px;
            background:#16a34a;
            color:#fff;
            padding:14px 18px;
            font-size:13px;
            font-weight:700;
            font-family:'Inter', sans-serif;
            cursor:pointer;
            display:inline-flex;
            align-items:center;
            gap:8px;
            box-shadow:-4px 4px 14px rgba(22,163,74,0.28);
            flex-shrink:0;
        }

        .print-form1-btn:hover{
            background:#4ADE80;
        }

        @media (max-width: 1200px){
            .print-form1-btn{
                position:static;
                margin:0 0 18px auto;
                display:flex;
                width:fit-content;
            }
        }

        @media (max-width: 900px){
            body{
                padding:16px;
            }

            .form-grid{
                grid-template-columns:1fr;
            }

            .form-row.full{
                grid-column:auto;
            }

            .page-header,
            .form-card{
                padding:18px;
            }
        }

        /* ============================
           PRINT RULES
           Only the Form 1 sections (1.A Father's Profile, 1.B Mother's
           Profile, 1.C Family Profile) are printed. Page header, the
           guardian account fields and the print button are hidden.
        ============================= */
        @media print{
            body{
                background:#ffffff !important;
                padding:0 !important;
            }

            .page-header,
            .print-form1-btn,
            .system-field-card,
            .no-print{
                display:none !important;
            }

            .page-wrapper{
                max-width:100% !important;
                margin:0 !important;
            }

            .form-card{
                box-shadow:none !important;
                border:1px solid #999 !important;
                break-inside:avoid;
            }
        }
    </style>

    <?php echo eccd_form_css(); ?>
    <?php echo eccd_f1_css(); ?>
</head>
<body>

    <div class="page-wrapper">

        <div class="page-header">
            <div class="page-header-text">
                <a href="../login.php" class="back-link">&larr; Back to Login</a>
                <h2 class="page-title">Guardian Registration</h2>
                <p class="page-subtitle">
                    Create a guardian account and link it to the child using the child access code.
                    The form below is a full copy of the official ECCD Council
                    &quot;Form 1 &ndash; Home Profile&quot; (1.A Father's Profile, 1.B Mother's Profile, 1.C Family Profile).
                </p>
            </div>

            <button type="button" class="print-form1-btn no-print" onclick="window.print()">
                &#128424;&#65039; Print / Save Form 1
            </button>
        </div>

        <?php if (!empty($error)) { ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <?php if (!empty($message)) { ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php } ?>

        <div class="eccd-print-only" style="text-align:center; margin-bottom:14px;">
            <div style="font-size:14px; font-weight:700;">Early Childhood Care and Development Council</div>
            <div style="font-size:12px;">FORM 1 &ndash; HOME PROFILE</div>
        </div>

        <form method="POST">

            <!-- ============================
                 GUARDIAN ACCOUNT DETAILS
                 (system fields - not part of Form 1)
            ============================= -->
            <div class="form-card system-field-card">
                <span class="system-field-tag">SYSTEM FIELD &mdash; not part of Form 1</span>
                <h3 class="section-title">Guardian Information</h3>
                <p class="section-subtitle">This will also be your login account.</p>

                <div class="form-grid">
                    <div class="form-row">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" required>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" required>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Relationship to the Child</label>
                        <select name="relationship_to_child" class="form-select" required>
                            <option value="">Select Relationship</option>
                            <?php
                            $relationship_options = ['Mother','Father','Grandmother','Grandfather','Guardian','Aunt','Uncle','Sibling','Other'];
                            $selected_relationship = $_POST['relationship_to_child'] ?? '';
                            foreach ($relationship_options as $option) {
                                $sel = ($selected_relationship === $option) ? 'selected' : '';
                                echo "<option value=\"" . htmlspecialchars($option) . "\" $sel>" . htmlspecialchars($option) . "</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="contact_number" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ''); ?>" required>
                    </div>

                    <div class="form-row full">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>" required>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                    </div>
                </div>
            </div>

            <!-- ============================
                 FORM 1.A / 1.B / 1.C  (official Home Profile)
            ============================= -->
            <?php echo eccd_f1_render_all($f1); ?>

            <!-- ============================
                 SYSTEM-SPECIFIC FIELDS
                 (not part of the paper Form 1 - needed only
                 for linking the account to the child record
                 and for login access)
            ============================= -->
            <div class="form-card system-field-card">
                <span class="system-field-tag">SYSTEM FIELD &mdash; not part of Form 1</span>
                <h3 class="section-title">Account Access</h3>

                <div class="form-grid">
                    <div class="form-row full">
                        <label class="form-label">Child Access Code</label>
                        <input type="text" name="access_code" class="form-control"
                               value="<?php echo htmlspecialchars($_POST['access_code'] ?? ''); ?>" required>
                        <div class="helper-text">Use the access code provided by the Child Development Worker for the child.</div>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Password</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="../login.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" name="register" class="btn btn-primary">Register</button>
                </div>
            </div>

        </form>

    </div>

    <?php echo eccd_form_js(); ?>
    <?php echo eccd_f1_js(); ?>

</body>
</html>