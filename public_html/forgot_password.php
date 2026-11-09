<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require 'db_connect.php';

// LOAD PHPMAILER
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$error_message = "";
$success_message = "";
$step = $_SESSION['forgot_step'] ?? 1;

// ==========================================
// STEP 1: REQUEST OTP FOR PASSWORD RESET
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['request_reset'])) {
    $username_or_email = trim($conn->real_escape_string($_POST['username_or_email']));

    $stmt = $conn->prepare("SELECT id, email, username FROM users WHERE (username = ? OR email = ?) AND status = 'Active'");
    $stmt->bind_param("ss", $username_or_email, $username_or_email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if (empty($user['email'])) {
            $error_message = "This account does not have an email address registered. Please contact the Admin.";
        } else {
            $otp_code = sprintf("%06d", mt_rand(1, 999999));
            $otp_expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

            $update = $conn->prepare("UPDATE users SET otp_code = ?, otp_expiry = ? WHERE id = ?");
            $update->bind_param("ssi", $otp_code, $otp_expiry, $user['id']);
            
            if ($update->execute()) {
                $_SESSION['reset_user_id'] = $user['id'];
                $_SESSION['forgot_step'] = 2;
                $step = 2;

                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'seren052001@gmail.com';
                    $mail->Password   = 'qnuyhososujisges';
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;
                    $mail->SMTPOptions = array('ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true));
                    
                    $mail->setFrom('seren052001@gmail.com', 'IJE LPG Security');
                    $mail->addAddress($user['email']);
                    
                    $mail->isHTML(true);
                    $mail->Subject = 'IJE LPG - Password Reset Verification Code';
                    $mail->Body    = "
                        <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ddd; border-radius: 8px; text-align: center; max-width: 400px;'>
                            <h2 style='color: #2c3e50;'>IJE LPG Security</h2>
                            <p>Password reset requested for username: <b>{$user['username']}</b></p>
                            <p>Your verification code is:</p>
                            <h1 style='color: #1abc9c; font-size: 36px; letter-spacing: 5px; margin: 10px 0;'>$otp_code</h1>
                            <p style='color: #e74c3c; font-size: 12px;'>This code will expire in 10 minutes.</p>
                        </div>
                    ";
                    $mail->send();
                    $success_message = "A 6-digit verification code has been sent to your email.";
                } catch (Exception $e) {
                    $error_message = "Failed to send email. Mailer Error: {$mail->ErrorInfo}";
                }
            }
        }
    } else {
        $error_message = "No active user found matching that username or email.";
    }
}

// ==========================================
// STEP 2: VERIFY OTP & SAVE NEW PASSWORD
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reset_password'])) {
    $user_id = $_SESSION['reset_user_id'] ?? 0;
    $entered_otp = trim($_POST['otp_code']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (strlen($new_password) < 6 || strlen($new_password) > 25) {
        $error_message = "Error: Password must be between 6 and 25 characters.";
        $step = 2;
    } elseif ($new_password !== $confirm_password) {
        $error_message = "Error: Passwords do not match.";
        $step = 2;
    } else {
        $stmt = $conn->prepare("SELECT otp_code, otp_expiry FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $userData = $stmt->get_result()->fetch_assoc();

        if ($userData && $userData['otp_code'] === $entered_otp && strtotime($userData['otp_expiry']) >= time()) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            $update_pass = $conn->prepare("UPDATE users SET password = ?, otp_code = NULL, otp_expiry = NULL WHERE id = ?");
            $update_pass->bind_param("si", $hashed_password, $user_id);

            if ($update_pass->execute()) {
                unset($_SESSION['reset_user_id']);
                unset($_SESSION['forgot_step']);
                echo "<script>alert('Password successfully reset! You can now log in with your new password.'); window.location.href='index.php';</script>";
                exit();
            }
        } else {
            $error_message = "Error: Invalid or expired verification code.";
            $step = 2;
        }
    }
}

// Handle Cancel / Restart
if (isset($_GET['cancel'])) {
    unset($_SESSION['reset_user_id']);
    unset($_SESSION['forgot_step']);
    header("Location: forgot_password.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Recovery - IJE LPG</title>
    <link rel="stylesheet" href="css/auth.css">
    <link rel="stylesheet" href="css/login.css">
    <style>
        body { display: flex; justify-content: center; align-items: center; min-height: 100vh; background-color: #f4f6f9; font-family: sans-serif; }
        .reset-card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        .alert-error { background: #fed7d7; color: #9b2c2c; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; text-align: center; font-weight: bold; }
        .alert-success { background: #c6f6d5; color: #22543d; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; text-align: center; font-weight: bold; }
        
        /* Eye icon positioning wrapper */
        .password-input-container {
            position: relative;
            width: 100%;
        }
        .password-input-container input {
            width: 100%;
            padding: 10px 40px 10px 10px;
            border: 1px solid #cbd5e0;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .toggle-eye-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #718096;
            display: flex;
            align-items: center;
            user-select: none;
        }
    </style>
</head>
<body>

<div class="reset-card">
    <img src="img/logo no bg.png" alt="Logo" class="auth-logo" style="display:block; margin: 0 auto 15px auto; width: 60px;">
    <h2 style="margin-top: 0; color: #2c3e50; text-align: center;">Password Recovery</h2>
    <p style="text-align: center; color: #718096; font-size: 13px; margin-bottom: 20px;">
        <?php echo ($step == 1) ? "Enter your username or email to receive an OTP." : "Enter the 6-digit code sent to your email."; ?>
    </p>

    <?php if (!empty($error_message)): ?>
        <div class="alert-error"><?php echo $error_message; ?></div>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
        <div class="alert-success"><?php echo $success_message; ?></div>
    <?php endif; ?>

    <?php if ($step == 1): ?>
        <form action="forgot_password.php" method="POST">
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; font-size: 14px; color: #2c3e50;">Username or Email:</label>
                <input type="text" name="username_or_email" required placeholder="Enter username or email" style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px; box-sizing: border-box;">
            </div>

            <button type="submit" name="request_reset" style="width: 100%; background-color: #ed8936; color: white; padding: 12px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 15px;">
                Send Verification Code
            </button>

            <div style="text-align: center; margin-top: 15px;">
                <a href="index.php" style="color: #2b6cb0; font-weight: bold; text-decoration: none; font-size: 14px;">« Back to Login</a>
            </div>
        </form>
    <?php else: ?>
        <form action="forgot_password.php" method="POST">
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; font-size: 14px; color: #2c3e50;">6-Digit OTP Code:</label>
                <input type="text" name="otp_code" required maxlength="6" placeholder="Enter 6-digit code" style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px; box-sizing: border-box; text-align: center; font-size: 18px; letter-spacing: 3px;">
            </div>

            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; font-size: 14px; color: #2c3e50;">New Password (6-25 chars):</label>
                <div class="password-input-container">
                    <input type="password" id="new_password" name="new_password" required minlength="6" maxlength="25" placeholder="Enter new password">
                    <span id="toggleNewPassword" class="toggle-eye-btn">
                        <svg id="eyeOpenNew" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <svg id="eyeClosedNew" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </span>
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; font-size: 14px; color: #2c3e50;">Confirm New Password:</label>
                <div class="password-input-container">
                    <input type="password" id="confirm_password" name="confirm_password" required minlength="6" maxlength="25" placeholder="Confirm new password">
                    <span id="toggleConfirmPassword" class="toggle-eye-btn">
                        <svg id="eyeOpenConfirm" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <svg id="eyeClosedConfirm" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </span>
                </div>
            </div>

            <button type="submit" name="reset_password" style="width: 100%; background-color: #27ae60; color: white; padding: 12px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 15px; margin-bottom: 10px;">
                Reset Password
            </button>

            <div style="text-align: center;">
                <a href="forgot_password.php?cancel=1" style="color: #e74c3c; font-weight: bold; text-decoration: none; font-size: 13px;">Cancel / Try Different Account</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
function bindPasswordToggle(toggleId, inputId, eyeOpenId, eyeClosedId) {
    const toggleBtn = document.querySelector(toggleId);
    const passwordInput = document.querySelector(inputId);
    const eyeOpen = document.querySelector(eyeOpenId);
    const eyeClosed = document.querySelector(eyeClosedId);

    if (toggleBtn && passwordInput) {
        toggleBtn.addEventListener('click', function () {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            eyeOpen.style.display = isPassword ? 'none' : 'block';
            eyeClosed.style.display = isPassword ? 'block' : 'none';
        });
    }
}

document.addEventListener("DOMContentLoaded", function() {
    bindPasswordToggle('#toggleNewPassword', '#new_password', '#eyeOpenNew', '#eyeClosedNew');
    bindPasswordToggle('#toggleConfirmPassword', '#confirm_password', '#eyeOpenConfirm', '#eyeClosedConfirm');
});
</script>

</body>
</html>