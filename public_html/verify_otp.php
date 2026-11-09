<?php
session_start();
// STRICT NO-CACHE HEADERS
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require 'db_connect.php';

// If they didn't come from the login page, kick them back
if (!isset($_SESSION['temp_user_id'])) {
    header("Location: login.php");
    exit();
}

$error_message = "";
$user_id = $_SESSION['temp_user_id'];

// Get user info to show masked email
$user_res = $conn->query("SELECT email FROM users WHERE id='$user_id'");
$user_email = $user_res->fetch_assoc()['email'];

// Mask email for display (e.g., man****@gmail.com)
$email_parts = explode("@", $user_email);
$masked_email = substr($email_parts[0], 0, 3) . "****@" . $email_parts[1];

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verify_otp'])) {
    $entered_code = $conn->real_escape_string($_POST['otp_code']);
    
    // Check if the code matches and is NOT expired
    $check_sql = "SELECT * FROM users WHERE id='$user_id' AND otp_code='$entered_code' AND otp_expiry > NOW()";
    $result = $conn->query($check_sql);
    
    if ($result->num_rows > 0) {
        // SUCCESS! Log them in properly.
        $user_data = $result->fetch_assoc();
        
        $_SESSION['username'] = $user_data['username'];
        $_SESSION['role'] = $user_data['role'];
        $_SESSION['branch_id'] = $user_data['branch_id'];
        
        // Clear the OTP from the database so it can't be reused
        $conn->query("UPDATE users SET otp_code=NULL, otp_expiry=NULL WHERE id='$user_id'");
        
        // Remove the temporary ID
        unset($_SESSION['temp_user_id']);
        
        // Record the login in the Audit Log
        logAction($conn, $user_id, NULL, "SYSTEM LOGIN", "User successfully logged in via OTP.");
        
        header("Location: dashboard.php");
        exit();
    } else {
        $error_message = "Invalid or expired OTP code. Please try again.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP - IJE LPG</title>
    <link rel="stylesheet" href="css/auth.css">
    <style>
        .otp-input {
            text-align: center;
            font-size: 24px !important;
            letter-spacing: 5px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <h2 class="auth-title" style="color: #1abc9c;">Security Check</h2>
        <p class="auth-subtitle">We sent a 6-digit code to <strong><?php echo $masked_email; ?></strong>.</p>

        <?php if ($error_message != ""): ?>
            <div class="alert-error"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <form action="verify_otp.php" method="POST">
            <div class="form-group">
                <input type="text" name="otp_code" class="otp-input" required maxlength="6" placeholder="000000" autocomplete="off">
            </div>
            <button type="submit" name="verify_otp" class="btn-auth">Verify Code</button>
        </form>
        
        <p style="margin-top: 20px; font-size: 13px;">
            <a href="login.php" style="color: #7f8c8d; text-decoration: underline;">Cancel and go back to Login</a>
        </p>
    </div>
</body>
</html>