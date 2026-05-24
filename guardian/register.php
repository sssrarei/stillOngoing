<?php
include '../config/database.php';

$message = "";
$error = "";

if(isset($_POST['register'])){
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $relationship_to_child = trim($_POST['relationship_to_child']);
    $address = trim($_POST['address']);
    $contact_number = trim($_POST['contact_number']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    $access_code = trim($_POST['access_code']);

    if(
        empty($first_name) || empty($last_name) || empty($relationship_to_child) ||
        empty($address) || empty($contact_number) || empty($email) ||
        empty($password) || empty($confirm_password) || empty($access_code)
    ){
        $error = "Please fill in all required fields.";
    } elseif($password != $confirm_password){
        $error = "Password and Confirm Password do not match.";
    } else {

        // Check if email already exists in users table
        $check_email = $conn->query("SELECT * FROM users WHERE email = '$email'");

        if($check_email && $check_email->num_rows > 0){
            $error = "Email already exists.";
        } else {

            // Check if access code exists
            $child_query = $conn->query("SELECT * FROM children WHERE access_code = '$access_code'");

            if(!$child_query || $child_query->num_rows == 0){
                $error = "Invalid child access code.";
            } else {
                $child = $child_query->fetch_assoc();
                $child_id = $child['child_id'];

                // Check if child is already linked to a guardian
                $check_link = $conn->query("SELECT * FROM parent_child_links WHERE child_id = '$child_id'");

                if($check_link && $check_link->num_rows > 0){
                    $error = "This child is already linked to a guardian.";
                } else {

                    // Start transaction to prevent incomplete guardian registration
$conn->begin_transaction();

try {
    // Insert guardian account into users table
    $user_sql = "INSERT INTO users 
        (role_id, first_name, last_name, email, password, contact_number, address)
        VALUES 
        (3, '$first_name', '$last_name', '$email', '$password', '$contact_number', '$address')";

    if(!$conn->query($user_sql)){
        throw new Exception("User account creation failed: " . $conn->error);
    }

    $parent_id = $conn->insert_id;

    // Insert guardian profile into guardians table
    $guardian_sql = "INSERT INTO guardians 
        (child_id, user_id, first_name, last_name, relationship_to_child, contact_number, email, address)
        VALUES 
        ('$child_id', '$parent_id', '$first_name', '$last_name', '$relationship_to_child', '$contact_number', '$email', '$address')";

    if(!$conn->query($guardian_sql)){
        throw new Exception("Guardian profile creation failed: " . $conn->error);
    }

    // Link guardian user to child
    $link_sql = "INSERT INTO parent_child_links (parent_id, child_id)
                VALUES ('$parent_id', '$child_id')";

    if(!$conn->query($link_sql)){
        throw new Exception("Guardian linking failed: " . $conn->error);
    }

    // If all inserts are successful, save everything
    $conn->commit();

    $message = "Guardian registration successful! You can now login.";

} catch (Exception $e) {
    // If one insert fails, cancel all inserts
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
        }

        .back-link{
            display:inline-flex;
            align-items:center;
            gap:8px;
            margin-bottom:12px;
            text-decoration:none;
            color:#16a34a   ;
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
        }

        .section-title{
            font-family:'Poppins', sans-serif;
            font-size:17px;
            color:#2f2f2f;
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
            margin-top:18px;
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
    </style>
</head>
<body>

    <div class="page-wrapper">

        <div class="page-header">
            <a href="../login.php" class="back-link">← Back to Login</a>
            <h2 class="page-title">Guardian Registration</h2>
            <p class="page-subtitle">
                Create a guardian account and link it to the child using the child access code.
            </p>
        </div>

        <?php if(!empty($error)){ ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <?php if(!empty($message)){ ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php } ?>

        <form method="POST">
            <div class="form-card">
                <h3 class="section-title">Guardian Information</h3>

                <div class="form-grid">
                    <div class="form-row">
                        <label class="form-label">First Name</label>
                        <input type="text" name="first_name" class="form-control" required>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Last Name</label>
                        <input type="text" name="last_name" class="form-control" required>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Relationship to the Child</label>
                        <select name="relationship_to_child" class="form-select" required>
                            <option value="">Select Relationship</option>
                            <option value="Mother">Mother</option>
                            <option value="Father">Father</option>
                            <option value="Grandmother">Grandmother</option>
                            <option value="Grandfather">Grandfather</option>
                            <option value="Guardian">Guardian</option>
                            <option value="Aunt">Aunt</option>
                            <option value="Uncle">Uncle</option>
                            <option value="Sibling">Sibling</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="contact_number" class="form-control" required>
                    </div>

                    <div class="form-row full">
                        <label class="form-label">Address</label>
                        <input type="text" name="address" class="form-control" required>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Child Access Code</label>
                        <input type="text" name="access_code" class="form-control" required>
                        <div class="helper-text">Use the access code provided for the child.</div>
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

</body>
</html>