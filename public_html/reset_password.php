<?php
session_start();
require 'db_connect.php';

$message = "";
$message_type = "";
$valid_token = false;
$token = $_GET['token'] ?? '';

if (!empty($token)) {
    // Validate token exists and hasn't expired
    $stmt = $conn->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $res->num_rows > 0) {
        $valid_token = true;
        $reset_data = $res->fetch_assoc();
        $user_email = $reset_data['email'];
    } else {
        $message = "This password reset link is invalid or has expired.";
        $message_type = "error";
    }
} else {
    $message = "No reset token provided.";
    $message_type = "error";
}

// Handle Password Submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_new_password']) && $valid_token) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (strlen($new_password) < 4) {
        $message = "Password must be at least 4 characters long.";
        $message_type = "error";
    } elseif ($new_password !== $confirm_password) {
        $message = "Passwords do not match.";
        $message_type = "error";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        // 1. Update user password
        $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
        $update_stmt->bind_param("ss", $hashed_password, $user_email);

        if ($update_stmt->execute()) {
            // 2. Delete used token
            $del_stmt = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
            $del_stmt->bind_param("s", $user_email);
            $del_stmt->execute();

            $message = "Password successfully reset! You can now log in with your new password.";
            $message_type = "success";
            $valid_token = false; // Hide form after success
        } else {
            $message = "Database error. Could not reset password.";
            $message_type = "error";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password - IJE LPG</title>
    <style>
        body { display: flex; justify-content: center; align-items: center; min-height: 100vh; background-color: #f4f6f9; font-family: sans-serif; }
        .reset-card { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 100%; max-width: 400px; }
        .alert-error { background: #fed7d7; color: #9b2c2c; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; }
        .alert-success { background: #c6f6d5; color: #22543d; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 14px; }
    </style>
</head>
<body>

<div class="reset-card">
    <h2 style="margin-top: 0; color: #2c3e50;">Set New Password</h2>

    <?php if ($message != ""): ?>
        <div class="<?php echo $message_type == 'success' ? 'alert-success' : 'alert-error'; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <?php if ($valid_token): ?>
        <form action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" method="POST">
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; font-size: 14px;">New Password:</label>
                <input type="password" name="new_password" required minlength="4" style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px; box-sizing: border-box;">
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold; font-size: 14px;">Confirm New Password:</label>
                <input type="password" name="confirm_password" required minlength="4" style="width: 100%; padding: 10px; border: 1px solid #cbd5e0; border-radius: 4px; box-sizing: border-box;">
            </div>

            <button type="submit" name="submit_new_password" style="width: 100%; background-color: #ed8936; color: white; padding: 10px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer;">
                Update Password
            </button>
        </form>
    <?php else: ?>
        <div style="text-align: center; margin-top: 15px;">
            <a href="login.php" style="color: #2b6cb0; font-weight: bold; text-decoration: none;">Go to Login Page</a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>