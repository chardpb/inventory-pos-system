<?php
session_start();
// STRICT NO-CACHE HEADERS
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require 'db_connect.php';

// LOAD PHPMAILER
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$error_message = "";

// ============================================================
// AUTO-LOGIN VIA "REMEMBER ME" COOKIE
// ============================================================
if (!isset($_SESSION['temp_user_id']) && !isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE remember_token = ? AND status='Active'");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        // Skip OTP and log them straight in if cookie is valid
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['branch_id'] = $user['branch_id'];

        // Redirect based on role or straight to dashboard
        header("Location: index.php"); 
        exit();
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['login'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']);

    $result = $conn->query("SELECT * FROM users WHERE username='$username' AND status='Active'");
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $user_id = $user['id'];
        $attempts = $user['login_attempts'];
        $last_attempt = $user['last_attempt_time'];
        
        $is_locked_out = false;

        // ============================================================
        // 1. PROGRESSIVE LOCKOUT MATH
        // ============================================================
        if ($attempts >= 3 && $last_attempt != null) {
            if ($attempts >= 5) {
                $penalty_minutes = 60; 
            } elseif ($attempts == 4) {
                $penalty_minutes = 15; 
            } else {
                $penalty_minutes = 5;  
            }

            $lockout_time = strtotime($last_attempt) + ($penalty_minutes * 60); 
            $current_time = time();

            if ($current_time < $lockout_time) {
                $is_locked_out = true;
                $minutes_left = ceil(($lockout_time - $current_time) / 60);
                $error_message = "Security Alert: Too many failed attempts. Account locked for $minutes_left more minute(s).";
            }
        }

        // ============================================================
        // 2. CHECK PASSWORD
        // ============================================================
        if (!$is_locked_out) {
            if (password_verify($password, $user['password']) || $password === $user['password']) {
                
                // Reset failures to 0
                $conn->query("UPDATE users SET login_attempts = 0, last_attempt_time = NULL WHERE id = '$user_id'");
                
                // Handle "Remember Me" (1 Week Cookie)
                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    $expire_time = time() + (86400 * 7); // 7 days (1 week)
                    setcookie('remember_token', $token, $expire_time, "/", "", false, true);
                    $conn->query("UPDATE users SET remember_token = '$token' WHERE id = '$user_id'");
                }

                // OTP Email Logic
                if (empty($user['email'])) {
                    $error_message = "Your account has no email registered for OTP. Please contact the Admin.";
                } else {
                    $otp_code = rand(100000, 999999);
                    $conn->query("UPDATE users SET otp_code='$otp_code', otp_expiry=DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE id='$user_id'");
                    
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
                        $mail->Subject = 'Your IJE LPG Login Code';
                        $mail->Body    = "
                            <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ddd; border-radius: 8px; text-align: center; max-width: 400px;'>
                                <h2 style='color: #2c3e50;'>IJE LPG Security</h2>
                                <p>Your One-Time Password (OTP) for login is:</p>
                                <h1 style='color: #1abc9c; font-size: 36px; letter-spacing: 5px; margin: 10px 0;'>$otp_code</h1>
                                <p style='color: #e74c3c; font-size: 12px;'>This code expires in 5 minutes.</p>
                            </div>
                        ";
                        $mail->send();
                        
                        $_SESSION['temp_user_id'] = $user['id'];
                        header("Location: verify_otp.php");
                        exit();
                        
                    } catch (Exception $e) {
                        $error_message = "Failed to send OTP email. Please check your internet connection.";
                    }
                }
            } else {
                $attempts++;
                $ph_time = date('Y-m-d H:i:s');
                $conn->query("UPDATE users SET login_attempts = $attempts, last_attempt_time = '$ph_time' WHERE id = '$user_id'");
                
                if ($attempts >= 3) {
                    $error_message = "Security Alert: Too many failed attempts. Account locked.";
                } else {
                    $attempts_left = 3 - $attempts;
                    $error_message = "Incorrect password. You have $attempts_left warning(s) left.";
                }
            }
        }
    } else {
        $error_message = "Incorrect username or password.";
    }
}
?>

<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - IJE LPG</title>
    <link rel="icon" type="image/png" href="img/logo no bg.png">
    <link rel="stylesheet" href="css/auth.css">
    <link rel="stylesheet" href="css/login.css">
</head>
<body>
    <div class="auth-container">
        <img src="img/logo no bg.png" alt="Logo" class="auth-logo">
        <h2 class="auth-title">IJE LPG SYSTEM</h2>
        <p class="auth-subtitle">Sign in to your account</p>

        <?php if ($error_message != ""): ?>
            <div class="alert-error"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required placeholder="Enter your username">
            </div>
            
            <div class="form-group">
                <label>Password</label>
                <div class="password-wrapper">
                    <input type="password" name="password" id="password" required placeholder="Enter your password">
                    <span id="togglePassword" class="eye-icon">
                        <!-- SVG Eye Icon -->
                        <svg id="eyeOpen" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <svg id="eyeClosed" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                    </span>
                </div>
                
                <div class="login-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember"> Remember me
                    </label>
                    <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
                </div>
            </div>
            <button type="submit" name="login" class="btn-auth">Sign In</button>
        </form>
    </div>

    <!-- JavaScript for Toggle Password Visibility -->
    <script>
        const togglePassword = document.querySelector('#togglePassword');
        const password = document.querySelector('#password');
        const eyeOpen = document.querySelector('#eyeOpen');
        const eyeClosed = document.querySelector('#eyeClosed');

        togglePassword.addEventListener('click', function () {
            // Toggle the type attribute
            const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
            password.setAttribute('type', type);
            
            // Toggle icons
            if (type === 'text') {
                eyeOpen.style.display = 'none';
                eyeClosed.style.display = 'block';
            } else {
                eyeOpen.style.display = 'block';
                eyeClosed.style.display = 'none';
            }
        });
    </script>
</body>
</html>