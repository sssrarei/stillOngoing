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
$theme_mode = $_SESSION['theme_mode'];
$success = "";
$error = "";

if(isset($_POST['save'])){
    // CHILD INFORMATION
    $child_name = trim($_POST['child_name']);
    $birthdate = $_POST['birthdate'];
    $sex = isset($_POST['sex']) ? trim($_POST['sex']) : '';
    $address = trim($_POST['address']);
    $religion = trim($_POST['religion']);

    // HEALTH INFORMATION
    $allergies = trim($_POST['allergies']);
    $comorbidities = trim($_POST['comorbidities']);

  
    if(empty($child_name) || empty($birthdate) || empty($sex)){
        $error = "Please fill in all required child information fields.";
    } else {
        // Split child full name
        $child_parts = preg_split('/\s+/', $child_name);

        $first_name = "";
        $middle_name = "";
        $last_name = "";

        if(count($child_parts) == 1){
            $first_name = $child_parts[0];
        } elseif(count($child_parts) == 2){
            $first_name = $child_parts[0];
            $last_name = $child_parts[1];
        } else {
            $first_name = array_shift($child_parts);
            $last_name = array_pop($child_parts);
            $middle_name = implode(" ", $child_parts);
        }

        // Generate unique access code
        $access_code = "CH-" . rand(1000, 9999);
        $check_code = $conn->query("SELECT child_id FROM children WHERE access_code = '$access_code'");

        while($check_code && $check_code->num_rows > 0){
            $access_code = "CH-" . rand(1000, 9999);
            $check_code = $conn->query("SELECT child_id FROM children WHERE access_code = '$access_code'");
        }

        // FILE UPLOADS
        $vaccination_card_path = "";
        $medical_history_path = "";

        if(isset($_FILES['vaccination_card']) && $_FILES['vaccination_card']['error'] == 0){
            $vacc_name = time() . "_vacc_" . basename($_FILES['vaccination_card']['name']);
            $vacc_target = "../uploads/vaccination_cards/" . $vacc_name;

            if(move_uploaded_file($_FILES['vaccination_card']['tmp_name'], $vacc_target)){
                $vaccination_card_path = "uploads/vaccination_cards/" . $vacc_name;
            }
        }

        if(isset($_FILES['medical_history_file']) && $_FILES['medical_history_file']['error'] == 0){
            $med_name = time() . "_med_" . basename($_FILES['medical_history_file']['name']);
            $med_target = "../uploads/medical_history/" . $med_name;

            if(move_uploaded_file($_FILES['medical_history_file']['tmp_name'], $med_target)){
                $medical_history_path = "uploads/medical_history/" . $med_name;
            }
        }

        // SAVE CHILD
    $child_sql = "INSERT INTO children
    (first_name, middle_name, last_name, birthdate, sex, address, religion, cdc_id, access_code)
    VALUES
    ('$first_name', '$middle_name', '$last_name', '$birthdate', '$sex', '$address', '$religion', '$cdc_id', '$access_code')";

        if($conn->query($child_sql)){
            $child_id = $conn->insert_id;

            

            // SAVE HEALTH INFORMATION ONLY IF MAY INPUT
                if(empty($error)){
                    $has_health_info = !empty($vaccination_card_path) || 
                                    !empty($allergies) || 
                                    !empty($comorbidities) || 
                                    !empty($medical_history_path);

                    if($has_health_info){
                        $health_sql = "INSERT INTO child_health_information
                            (child_id, vaccination_card_file_path, allergies, comorbidities, medical_history_file_path)
                            VALUES
                            ('$child_id', '$vaccination_card_path', '$allergies', '$comorbidities', '$medical_history_path')";

                        $health_saved = $conn->query($health_sql);

                        if(!$health_saved){
                            $error = "Child saved, but health information failed: " . $conn->error;
                        } else {
                            $success = "Child Profile Registration successful! Child Access Code: " . $access_code;
                        }
                    } else {
                        $success = "Child Profile Registration successful! Child Access Code: " . $access_code;
                    }
                }
        } else {
            $error = "Error: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Child | NutriTrack</title>
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
            background:#eef0f3;
            font-family:'Inter', sans-serif;
            color:#333;
        }

        a{
            text-decoration:none;
        }


       .main-content{
            margin-left:260px;
            padding:112px 24px 30px;
            transition:margin-left 0.25s ease;
            display:flex;
            justify-content:center;
        }

        .page-wrapper{
            width:100%;
            max-width:850px;
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

        .message{
            border-radius:10px;
            padding:14px 16px;
            margin-bottom:16px;
            font-size:13px;
            font-weight:600;
            border:1px solid transparent;
        }

        .message.success{
            background:#e8f5e9;
            color:#2e7d32;
            border-color:#c8e6c9;
        }

        .message.error{
            background:#fdeaea;
            color:#c62828;
            border-color:#f5c2c7;
        }

        .form-grid{
            display:grid;
            grid-template-columns:1fr;
            gap:18px;
        }

        .form-card{
            background:#ffffff;
            border:1px solid #dcdcdc;
            border-radius:14px;
            padding:20px;
        }

        .form-card.full{
            grid-column:1 / -1;
        }

        .section-title{
            font-family:'Poppins', sans-serif;
            font-size:17px;
            color:#2f2f2f;
            margin:0 0 16px 0;
        }

        .optional{
            font-family:'Inter', sans-serif;
            font-size:12px;
            color:#777;
            font-weight:500;
        }

        .form-row{
            margin-bottom:14px;
        }

        .form-row:last-child{
            margin-bottom:0;
        }

        .form-label{
            display:block;
            font-size:12px;
            color:#666;
            margin-bottom:6px;
            font-weight:500;
        }

        .form-control,
        .form-select,
        .form-file{
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

        .form-control:focus,
        .form-select:focus,
        .form-file:focus{
            border-color:#2E7D32;
            box-shadow:0 0 0 3px rgba(46,125,50,0.08);
        }

        .radio-group{
            display:flex;
            gap:20px;
            align-items:center;
            flex-wrap:wrap;
            padding-top:4px;
        }

        .radio-group label{
            font-size:13px;
            color:#333;
            display:flex;
            align-items:center;
            gap:6px;
        }

        .health-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:18px;
        }

        .form-actions{
            margin-top:18px;
            display:flex;
            justify-content:flex-end;
            gap:10px;
            flex-wrap:wrap;
            width:100%;
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

        .btn-cancel{
            background:#e0e0e0;
            color:#444;
        }

        .btn-save{
            background:#2E7D32;
            color:#fff;
        }

        /* ================= ADD CHILD DARK MODE ================= */
        body.dark-mode{
            background:#0f172a;
            color:#e5e7eb;
        }

        body.dark-mode .page-header,
        body.dark-mode .form-card{
            background:#111827;
            border-color:#334155;
        }

        body.dark-mode .page-title,
        body.dark-mode .section-title{
            color:#f8fafc;
        }

        body.dark-mode .page-subtitle,
        body.dark-mode .form-label,
        body.dark-mode .optional{
            color:#cbd5e1;
        }

        body.dark-mode .form-control,
        body.dark-mode .form-select,
        body.dark-mode .form-file{
            background:#0f172a;
            color:#f8fafc;
            border-color:#475569;
        }

        body.dark-mode .radio-group label{
            color:#e5e7eb;
        }

        body.dark-mode .btn-cancel{
            background:#1e293b;
            color:#e5e7eb;
            border:1px solid #334155;
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

            .form-grid{
                grid-template-columns:1fr;
            }

            .health-grid{
                grid-template-columns:1fr;
            }

            .form-actions{
                justify-content:stretch;
            }

            .form-actions .btn{
                width:100%;
            }
        }
    </style>
</head>
<body class="<?php echo ($theme_mode === 'dark') ? 'dark-mode' : ''; ?>">

<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-wrapper">
    <div class="page-header">
        <a href="child_list.php" class="back-link">← Back to Pupil List</a>
        <h1 class="page-title">Child Profile Registration</h1>
        <div class="page-subtitle">
            Active CDC: <?php echo htmlspecialchars($_SESSION['active_cdc_name']); ?><br>
            After saving, give the generated access code to the guardian for account registration.
        </div>
    </div>

    <?php if(!empty($success)){ ?>
        <div class="message success"><?php echo htmlspecialchars($success); ?></div>
    <?php } ?>

    <?php if(!empty($error)){ ?>
        <div class="message error"><?php echo htmlspecialchars($error); ?></div>
    <?php } ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-grid">
            <div class="form-card">
                <h3 class="section-title">Child Information</h3>

                <div class="form-row">
                    <label class="form-label">Child Name</label>
                    <input type="text" name="child_name" class="form-control" placeholder="First name Middle name Last name" required>
                </div>

                <div class="form-row">
                    <label class="form-label">Birthdate</label>
                    <input type="date" name="birthdate" class="form-control" required>
                </div>

                <div class="form-row">
                    <label class="form-label">Sex</label>
                    <div class="radio-group">
                        <label><input type="radio" name="sex" value="Male" required> Male</label>
                        <label><input type="radio" name="sex" value="Female" required> Female</label>
                    </div>
                </div>

                <div class="form-row">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control">
                </div>

                <div class="form-row">
                    <label class="form-label">Religion</label>
                    <select name="religion" class="form-select">
                        <option value="">-- Select Religion --</option>
                        <option value="Roman Catholic">Roman Catholic</option>
                        <option value="Christian">Christian</option>
                        <option value="Islam">Islam (Muslim)</option>
                        <option value="Iglesia ni Cristo">Iglesia ni Cristo</option>
                        <option value="Born Again Christian">Born Again Christian</option>
                        <option value="Seventh-day Adventist">Seventh-day Adventist</option>
                        <option value="Jehovah's Witnesses">Jehovah's Witnesses</option>
                        <option value="Baptist">Baptist</option>
                        <option value="Methodist">Methodist</option>
                    </select>
                </div>
                

            <div class="form-card full">
                <h3 class="section-title">Child Health Information <span class="optional">(Optional)</span></h3>

                <div class="health-grid">
                    <div>
                        <div class="form-row">
                            <label class="form-label">Vaccination Card</label>
                            <input type="file" name="vaccination_card" class="form-file">
                        </div>

                        <div class="form-row">
                            <label class="form-label">Allergies</label>
                            <input type="text" name="allergies" class="form-control">
                        </div>
                    </div>

                    <div>
                        <div class="form-row">
                            <label class="form-label">Medical History</label>
                            <input type="file" name="medical_history_file" class="form-file">
                        </div>

                        <div class="form-row">
                            <label class="form-label">Comorbidities</label>
                            <input type="text" name="comorbidities" class="form-control">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-cancel" onclick="window.location.href='child_list.php'">Cancel</button>
            <button type="submit" name="save" class="btn btn-save">Save Pupil</button>
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

</body>
</html>