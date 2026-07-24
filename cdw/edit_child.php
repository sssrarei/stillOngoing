<?php
include '../includes/auth.php';
include '../config/database.php';

if($_SESSION['role_id'] != 2){
    header("Location: ../login.php");
    exit();
}

if(!isset($_SESSION['active_cdc_id'])){
    die("Please select an active CDC first from the dashboard.");
}

$active_cdc_id = (int) $_SESSION['active_cdc_id'];
$child_id = isset($_GET['child_id']) ? (int) $_GET['child_id'] : 0;

if($child_id <= 0){
    die("Invalid child selected.");
}

$success = "";
$error = "";

// Check if child belongs to active CDC
$child_sql = "SELECT * FROM children 
              WHERE child_id = '$child_id' 
              AND cdc_id = '$active_cdc_id'
              LIMIT 1";
$child_result = $conn->query($child_sql);

if(!$child_result){
    die("Query error: " . $conn->error);
}

if($child_result->num_rows == 0){
    die("Child not found or not assigned to the active CDC.");
}

$child = $child_result->fetch_assoc();

// Load child health info
$health_sql = "SELECT * FROM child_health_information WHERE child_id = '$child_id' LIMIT 1";
$health_result = $conn->query($health_sql);
$health = ($health_result && $health_result->num_rows > 0) ? $health_result->fetch_assoc() : null;

// Update Child + Child Health
if(isset($_POST['update'])){
    $first_name = trim($_POST['first_name']);
    $middle_name = trim($_POST['middle_name']);
    $last_name = trim($_POST['last_name']);
    $birthdate = trim($_POST['birthdate']);
    $sex = trim($_POST['sex']);
    $address = trim($_POST['address']);
    $religion = trim($_POST['religion']);
    $allergies = trim($_POST['allergies']);
    $comorbidities = trim($_POST['comorbidities']);

    $vaccination_card_path = $health['vaccination_card_file_path'] ?? "";
    $medical_history_path = $health['medical_history_file_path'] ?? "";

    if(empty($first_name) || empty($last_name) || empty($birthdate) || empty($sex)){
        $error = "Please fill in all required child information fields.";
    } else {
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

        $update_child_sql = "UPDATE children SET
            first_name = '$first_name',
            middle_name = '$middle_name',
            last_name = '$last_name',
            birthdate = '$birthdate',
            sex = '$sex',
            address = '$address',
            religion = '$religion'
            WHERE child_id = '$child_id'
            AND cdc_id = '$active_cdc_id'";

        if($conn->query($update_child_sql)){
            if($health){
                $update_health_sql = "UPDATE child_health_information SET
                    vaccination_card_file_path = '$vaccination_card_path',
                    allergies = '$allergies',
                    comorbidities = '$comorbidities',
                    medical_history_file_path = '$medical_history_path'
                    WHERE child_id = '$child_id'";

                if($conn->query($update_health_sql)){
                    $success = "Child information updated successfully!";
                } else {
                    $error = "Child info updated, but health information failed: " . $conn->error;
                }
            } else {
                $insert_health_sql = "INSERT INTO child_health_information
                    (child_id, vaccination_card_file_path, allergies, comorbidities, medical_history_file_path)
                    VALUES
                    ('$child_id', '$vaccination_card_path', '$allergies', '$comorbidities', '$medical_history_path')";

                if($conn->query($insert_health_sql)){
                    $success = "Child information updated successfully!";
                } else {
                    $error = "Child info updated, but health information failed: " . $conn->error;
                }
            }

            $child_result = $conn->query($child_sql);
            $child = $child_result->fetch_assoc();

            $health_result = $conn->query($health_sql);
            $health = ($health_result && $health_result->num_rows > 0) ? $health_result->fetch_assoc() : null;

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
    <title>Edit Child Information | NutriTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/cdw/cdw-style.css">
    <link rel="stylesheet" href="../assets/cdw/cdw-topbar-notification.css">

    <style>
        .page-wrapper{
            max-width:1100px;
            margin:0 auto;
        }

        .page-header{
            background:#ffffff;
            border:1px solid #dcdcdc;
            border-radius:12px;
            padding:22px 24px;
            margin-bottom:18px;
        }

        .back-link{
            display:inline-flex;
            align-items:center;
            gap:8px;
            margin-bottom:12px;
            color:#2E7D32;
            font-size:13px;
            font-weight:600;
        }

        .page-title{
            font-family:'Poppins', sans-serif;
            font-size:22px;
            font-weight:700;
            color:#2f2f2f;
            margin:0 0 8px 0;
        }

        .small-label{
            font-size:12px;
            color:#666;
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

        .form-card{
            background:#ffffff;
            border:1px solid #dcdcdc;
            border-radius:12px;
            padding:20px;
        }

        .section-title{
            font-family:'Poppins', sans-serif;
            font-size:17px;
            color:#2f2f2f;
            margin:0 0 16px 0;
        }

        .sub-section-title{
            font-family:'Poppins', sans-serif;
            font-size:16px;
            color:#2f2f2f;
            margin:18px 0 16px 0;
            padding-top:8px;
            border-top:1px solid #e9e9e9;
        }

        .form-grid{
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:18px;
        }

        .form-row{
            margin-bottom:14px;
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

        .form-actions{
            margin-top:18px;
            display:flex;
            justify-content:flex-end;
            gap:10px;
            flex-wrap:wrap;
        }

        .btn{
            border:none;
            border-radius:8px;
            padding:11px 16px;
            font-size:13px;
            font-weight:600;
            font-family:'Inter', sans-serif;
            cursor:pointer;
        }

        .btn-cancel{
            background:#e0e0e0;
            color:#444;
        }

        .btn-save{
            background:#2E7D32;
            color:#fff;
        }

        @media (max-width: 900px){
            .page-wrapper{
                max-width:100%;
            }

            .form-grid{
                grid-template-columns:1fr;
            }
        }
    </style>
</head>
<body>

<?php include '../includes/cdw_topbar.php'; ?>
<?php include '../includes/cdw_sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="page-wrapper">
        <div class="page-header">
            <a href="child_profile.php?child_id=<?php echo $child_id; ?>" class="back-link">← Back to Child Profile</a>
            <h2 class="page-title">Edit Child Information</h2>
            <div class="small-label">Active CDC: <?php echo htmlspecialchars($_SESSION['active_cdc_name']); ?></div>
        </div>

        <?php if(!empty($success)){ ?>
            <div class="message success"><?php echo htmlspecialchars($success); ?></div>
        <?php } ?>

        <?php if(!empty($error)){ ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="form-card">
                <h3 class="section-title">Child Information</h3>

                <div class="form-grid">
                    <div class="form-row">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($child['first_name']); ?>" required>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control" value="<?php echo htmlspecialchars($child['middle_name']); ?>">
                    </div>

                    <div class="form-row">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($child['last_name']); ?>" required>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Birthdate</label>
                        <input type="date" name="birthdate" class="form-control" value="<?php echo htmlspecialchars($child['birthdate']); ?>" required>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Sex</label>
                        <select name="sex" class="form-select" required>
                            <option value="Male" <?php if($child['sex'] == 'Male') echo 'selected'; ?>>Male</option>
                            <option value="Female" <?php if($child['sex'] == 'Female') echo 'selected'; ?>>Female</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($child['address']); ?>">
                    </div>

                    <div class="form-row">
                        <label class="form-label">Religion</label>
                        <select name="religion" class="form-select">
                            <option value="">-- Select Religion --</option>

                            <option value="Roman Catholic" <?= ($child['religion'] == 'Roman Catholic') ? 'selected' : ''; ?>>
                                Roman Catholic
                            </option>

                            <option value="Christian" <?= ($child['religion'] == 'Christian') ? 'selected' : ''; ?>>
                                Christian
                            </option>

                            <option value="Islam" <?= ($child['religion'] == 'Islam') ? 'selected' : ''; ?>>
                                Islam (Muslim)
                            </option>

                            <option value="Iglesia ni Cristo" <?= ($child['religion'] == 'Iglesia ni Cristo') ? 'selected' : ''; ?>>
                                Iglesia ni Cristo
                            </option>

                            <option value="Seventh-day Adventist" <?= ($child['religion'] == 'Seventh-day Adventist') ? 'selected' : ''; ?>>
                                Seventh-day Adventist
                            </option>

                            <option value="Jehovah's Witnesses" <?= ($child['religion'] == "Jehovah's Witnesses") ? 'selected' : ''; ?>>
                                Jehovah's Witnesses
                            </option>

                            <option value="Baptist" <?= ($child['religion'] == 'Baptist') ? 'selected' : ''; ?>>
                                Baptist
                            </option>

                            <option value="Methodist" <?= ($child['religion'] == 'Methodist') ? 'selected' : ''; ?>>
                                Methodist
                            </option>

                            <option value="Born Again Christian" <?= ($child['religion'] == 'Born Again Christian') ? 'selected' : ''; ?>>
                                Born Again Christian
                            </option>

                            <option value="Others" <?= ($child['religion'] == 'Others') ? 'selected' : ''; ?>>
                                Others
                            </option>
                        </select>
                    </div>

                </div>

                <h4 class="sub-section-title">Child Health Information</h4>

                <div class="form-grid">
                    <div class="form-row">
                        <label class="form-label">Vaccination Card</label>
                        <input type="file" name="vaccination_card" class="form-file">
                    </div>

                    <div class="form-row">
                        <label class="form-label">Medical History</label>
                        <input type="file" name="medical_history_file" class="form-file">
                    </div>

                    <div class="form-row">
                        <label class="form-label">Allergies</label>
                        <input type="text" name="allergies" class="form-control" value="<?php echo htmlspecialchars($health['allergies'] ?? ''); ?>">
                    </div>

                    <div class="form-row">
                        <label class="form-label">Comorbidities</label>
                        <input type="text" name="comorbidities" class="form-control" value="<?php echo htmlspecialchars($health['comorbidities'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-cancel" onclick="window.location.href='child_profile.php?child_id=<?php echo $child_id; ?>'">Cancel</button>
                <button type="submit" name="update" class="btn btn-save">Update Child Information</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleSidebar() {
    var sidebar = document.getElementById('sidebar');
    var mainContent = document.getElementById('mainContent');

    if (window.innerWidth <= 991) {
        sidebar.classList.toggle('open');
    } else {
        sidebar.classList.toggle('closed');
        mainContent.classList.toggle('full');
    }
}
</script>

</body>
</html>