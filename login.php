<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include 'config/database.php';

if(isset($_POST['login'])){
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    if($email == "" || $password == ""){
        $error = "Please enter your email and password.";
    } else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if($result && $result->num_rows > 0){
            $user = $result->fetch_assoc();

            if(password_verify($password, $user['password'])){

                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['role_id'] = $user['role_id'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                $_SESSION['email'] = $user['email'];

                $update_active = $conn->prepare("UPDATE users SET last_active = NOW() WHERE user_id = ?");
                $update_active->bind_param("i", $user['user_id']);
                $update_active->execute();

                if($user['role_id'] == 1){
                    header("Location: admin/dashboard.php");
                    exit();
                } elseif($user['role_id'] == 2){
                    header("Location: cdw/dashboard.php");
                    exit();
                } elseif($user['role_id'] == 3){
                    header("Location: guardian/dashboard.php");
                    exit();
                } else {
                    $error = "Invalid role.";
                }

            } else {
                $error = "Wrong password.";
            }
        } else {
            $error = "User not found.";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Login - NutriTrack</title>

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">

<style>
body{
    margin:0;
    font-family:'Poppins',sans-serif;
    height:100vh;
    display:flex;
    justify-content:center;
    align-items:center;
    background:#eaf5ea;
}

/* MAIN CONTAINER */
.container{
    width:1000px;
    height:520px;
    display:flex;
    border-radius:20px;
    overflow:hidden;
    box-shadow:0 20px 60px rgba(0,0,0,0.2);
}

/* LEFT PANEL (DARK GREEN) */
.left-panel{
    width:60%;
    background: linear-gradient(135deg, #052e16, #166534);
    color:#fff;
    padding:40px;
    position:relative;
    display:flex;
    flex-direction:column;
    justify-content:center;
}

/* LOGO */
.logo{
    position:absolute;
    top:30px;
    left:40px;
    display:flex;
    align-items:center;
    gap:10px;
}

.logo img{
    width:40px;
}

.logo span{
    font-size:20px;
    font-weight:600;
}

/* TEXT */
.left-panel h1{
    font-size:36px;
    margin:0;
    margin-top:40px;
}

.left-panel h2{
    font-size:28px;
    margin:5px 0 15px;
    font-weight:600;
}

.left-panel p{
    font-size:14px;
    opacity:0.85;
    max-width:400px;
}

/* OPTIONAL IMAGE */
.left-image{
    position:absolute;
    bottom:0;
    left:30px;
    width:260px;
}

/* RIGHT PANEL */
.right-panel{
    width:40%;
    background:#fff;
    display:flex;
    justify-content:center;
    align-items:center;
}

/* CARD */
.login-card{
    width:80%;
}

.login-title{
    font-size:22px;
    font-weight:600;
    margin-bottom:5px;
}

.login-sub{
    font-size:13px;
    color:#777;
    margin-bottom:20px;
}

/* FORM */
.form-group{
    margin-bottom:15px;
}

label{
    font-size:13px;
    font-weight:500;
}

input{
    width:100%;
    padding:10px;
    border:1px solid #ddd;
    border-radius:6px;
    margin-top:5px;
    font-size:14px;
}

input:focus{
    border:1px solid #22c55e;
    outline:none;
}

/* PASSWORD */
.password-group{
    position:relative;
}

.toggle-pass{
    position:absolute;
    right:10px;
    top:35px;
    cursor:pointer;
}

/* OPTIONS */
.options{
    display:flex;
    justify-content:space-between;
    align-items:center;
    font-size:12px;
    margin-bottom:15px;
}

.options a{
    color:#16a34a;
    text-decoration:none;
}

/* BUTTON */
.login-btn{
    width:100%;
    padding:12px;
    border:none;
    border-radius:8px;
    background:#16a34a;
    color:#fff;
    font-weight:600;
    cursor:pointer;
}

/* REGISTER */
.register-link{
    margin-top:15px;
    text-align:center;
    font-size:13px;
}

.register-link a{
    color:#16a34a;
    text-decoration:none;
    font-weight:600;
}

/* ERROR */
.error-message{
    background:#ffe5e5;
    color:#b30000;
    padding:10px;
    margin-bottom:15px;
    border-radius:5px;
    font-size:13px;
}
</style>
</head>

<body>

<div class="container">

    <!-- LEFT -->
    <div class="left-panel">

        <div class="logo">
            <img src="NUTRITRACK-LOGO.png" alt="Logo">
            <span>NutriTrack</span>
        </div>

        <h1>Welcome back!</h1>
        <h2>Log in to your account</h2>
        <p>
            Work together, stay informed, and make a difference.
             NutriTrack connects CDWs, Guardians, and CSWDs to support healthier children and stronger communities.
        </p>
    </div>

    <!-- RIGHT -->
    <div class="right-panel">

        <div class="login-card">

            <div class="login-title">Sign in to your account</div>
            <div class="login-sub">Enter your credentials to continue</div>

            <?php if(isset($error)){ ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php } ?>

            <form method="POST">

                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>

                <div class="form-group password-group">
                    <label>Password</label>
                    <input type="password" name="password" id="password" required>
                    <span class="toggle-pass" onclick="togglePassword()">👁</span>
                </div>

                <button type="submit" name="login" class="login-btn">Login</button>
            </form>

            <div class="register-link">
                Don't have an account? 
                <a href="guardian/register.php">Sign up</a>
            </div>
    </div>

</div>

<script>
function togglePassword(){
    const pass = document.getElementById("password");
    pass.type = pass.type === "password" ? "text" : "password";
}
</script>

</body>
</html>