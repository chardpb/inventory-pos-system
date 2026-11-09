<?php
session_start();
// STRICT NO-CACHE HEADERS
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['username'])) {
    header("Location: index.php");
    exit();
}
require 'db_connect.php';

// LOAD PHPMAILER
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$success_message = "";
$error_message = "";
$user_role = $_SESSION['role'];
$user_branch_id = $_SESSION['branch_id'];
$current_username = $_SESSION['username'];

// FETCH CURRENT USER'S DATA
$user_query = $conn->query("SELECT id, email FROM users WHERE username = '$current_username'");
$current_user_data = $user_query->fetch_assoc();
$user_id = $current_user_data['id'];
$current_email = $current_user_data['email'];

// ==========================================
// ACTION 1: REQUEST EMAIL UPDATE (Step 1 - Send OTP)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['request_email_update'])) {
    $updated_email = trim($conn->real_escape_string($_POST['my_email']));
    
    if (empty($updated_email)) {
        $error_message = "Error: Email address is required.";
    } elseif ($updated_email === $current_email) {
        $error_message = "Error: This is already your current email address.";
    } else {
        $check_email = $conn->query("SELECT id FROM users WHERE email = '$updated_email' AND id != '$user_id'");
        if ($check_email && $check_email->num_rows > 0) {
            $error_message = "Error: The email address '$updated_email' is already registered to another account.";
        } else {
            $otp_code = sprintf("%06d", mt_rand(1, 999999));
            $otp_expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

            $update_temp = $conn->prepare("UPDATE users SET otp_code = ?, otp_expiry = ? WHERE id = ?");
            $update_temp->bind_param("ssi", $otp_code, $otp_expiry, $user_id);
            
            if ($update_temp->execute()) {
                $_SESSION['pending_new_email'] = $updated_email;
                $_SESSION['verify_email_modal_open'] = true;

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
                    $mail->addAddress($updated_email);
                    
                    $mail->isHTML(true);
                    $mail->Subject = 'IJE LPG - Verify New Alert Email';
                    $mail->Body    = "
                        <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ddd; border-radius: 8px; text-align: center; max-width: 400px;'>
                            <h2 style='color: #2c3e50;'>IJE LPG Security</h2>
                            <p>You requested to change your low stock alert email address.</p>
                            <p>Your verification code is:</p>
                            <h1 style='color: #1abc9c; font-size: 36px; letter-spacing: 5px; margin: 10px 0;'>$otp_code</h1>
                            <p style='color: #e74c3c; font-size: 12px;'>This code will expire in 10 minutes.</p>
                        </div>
                    ";
                    $mail->send();
                    $success_message = "A verification code has been sent to $updated_email.";
                } catch (Exception $e) {
                    $error_message = "Failed to send verification email. Mailer Error: {$mail->ErrorInfo}";
                }
            }
        }
    }
}

// ==========================================
// ACTION 2: VERIFY EMAIL UPDATE (Step 2 - Confirm OTP)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verify_email_otp'])) {
    $entered_otp = trim($_POST['email_otp_code']);
    $pending_email = $_SESSION['pending_new_email'] ?? '';

    $stmt = $conn->prepare("SELECT otp_code, otp_expiry FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $userData = $stmt->get_result()->fetch_assoc();

    if ($userData && $userData['otp_code'] === $entered_otp && strtotime($userData['otp_expiry']) >= time() && !empty($pending_email)) {
        $update_final = $conn->prepare("UPDATE users SET email = ?, otp_code = NULL, otp_expiry = NULL WHERE id = ?");
        $update_final->bind_param("si", $pending_email, $user_id);
        
        if ($update_final->execute()) {
            $current_email = $pending_email;
            unset($_SESSION['pending_new_email']);
            unset($_SESSION['verify_email_modal_open']);
            
            $success_message = "Your alert email has been successfully verified and updated!";
            logAction($conn, $user_id, NULL, "UPDATE SETTINGS", "Successfully verified and updated personal alert email address.");
        }
    } else {
        $error_message = "Error: Invalid or expired verification code.";
        $_SESSION['verify_email_modal_open'] = true;
    }
}

// ==========================================
// ACTION 2.5: CANCEL PERSONAL EMAIL VERIFICATION
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['cancel_verify_my_email'])) {
    unset($_SESSION['pending_new_email']);
    unset($_SESSION['verify_email_modal_open']);
    
    $clear_otp = $conn->prepare("UPDATE users SET otp_code = NULL, otp_expiry = NULL WHERE id = ?");
    $clear_otp->bind_param("i", $user_id);
    $clear_otp->execute();
    
    $error_message = "Email verification cancelled. You can enter a different email address.";
}

// ==========================================
// ACTION 3: CREATE NEW USER ACCOUNT (Step 1)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_user']) && $user_role != 'Employee') {
    $new_username = trim($conn->real_escape_string($_POST['new_username']));
    $new_email = trim($conn->real_escape_string($_POST['new_email']));
    $new_password = $_POST['new_password'];
    
    if ($user_role == 'Admin') {
        $new_role = $_POST['new_role'];
        $new_branch = $_POST['new_branch_id'];
    } else {
        $new_role = 'Employee';
        $new_branch = $user_branch_id;
    }

    if (empty($new_username)) {
        $error_message = "Error: Username is required.";
    } elseif (preg_match('/\s/', $new_username)) {
        $error_message = "Error: Username cannot contain spaces (e.g., 'Richard Pelobello' is not allowed).";
    } elseif (strlen($new_password) < 6 || strlen($new_password) > 25) {
        $error_message = "Error: Password must be between 6 and 25 characters.";
    } elseif (empty($new_email)) {
        $error_message = "Error: Email address is required.";
    } else {
        $check_existing = $conn->query("SELECT username, email FROM users WHERE username = '$new_username' OR email = '$new_email'");

        if ($check_existing && $check_existing->num_rows > 0) {
            $existing_user = $check_existing->fetch_assoc();
            if (strcasecmp($existing_user['username'], $new_username) === 0) {
                $error_message = "Error: Username '$new_username' is already taken.";
            } elseif (strcasecmp($existing_user['email'], $new_email) === 0) {
                $error_message = "Error: Email address '$new_email' is already registered.";
            }
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $otp_code = sprintf("%06d", mt_rand(1, 999999));
            $otp_expiry = date("Y-m-d H:i:s", strtotime("+10 minutes"));

            $insert_sql = "INSERT INTO users (username, email, password, role, branch_id, status, otp_code, otp_expiry)
                        VALUES ('$new_username', '$new_email', '$hashed_password', '$new_role', '$new_branch', 'Unverified', '$otp_code', '$otp_expiry')";
            
            if ($conn->query($insert_sql)) {
                $new_user_id = $conn->insert_id;

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
                    $mail->addAddress($new_email);
                    
                    $mail->isHTML(true);
                    $mail->Subject = 'IJE LPG - Account Verification Code';
                    $mail->Body    = "
                        <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ddd; border-radius: 8px; text-align: center; max-width: 400px;'>
                            <h2 style='color: #2c3e50;'>IJE LPG Security</h2>
                            <p>Hello <b>$new_username</b>,</p>
                            <p>Your verification code to complete your account creation is:</p>
                            <h1 style='color: #1abc9c; font-size: 36px; letter-spacing: 5px; margin: 10px 0;'>$otp_code</h1>
                            <p style='color: #e74c3c; font-size: 12px;'>This code will expire in 10 minutes.</p>
                        </div>
                    ";
                    
                    if($mail->send()) {
                        $_SESSION['verify_user_id'] = $new_user_id;
                        $success_message = "Account created! A verification code has been sent to $new_email.";
                    }
                } catch (Exception $e) {
                    $error_message = "Mailer Error: " . $mail->ErrorInfo;
                }
            }
        }
    }
}

// ==========================================
// ACTION 4: VERIFY NEW ACCOUNT OTP (Step 2)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['verify_account_otp'])) {
    $verify_user_id = (int)$_POST['verify_user_id'];
    $entered_otp = trim($_POST['otp_code']);

    $stmt = $conn->prepare("SELECT otp_code, otp_expiry FROM users WHERE id = ?");
    $stmt->bind_param("i", $verify_user_id);
    $stmt->execute();
    $userData = $stmt->get_result()->fetch_assoc();

    if ($userData && $userData['otp_code'] === $entered_otp && strtotime($userData['otp_expiry']) >= time()) {
        $activate = $conn->prepare("UPDATE users SET status = 'Active', otp_code = NULL, otp_expiry = NULL WHERE id = ?");
        $activate->bind_param("i", $verify_user_id);
        if ($activate->execute()) {
            unset($_SESSION['verify_user_id']);
            $success_message = "Account successfully verified and activated!";
            logAction($conn, $user_id, NULL, "VERIFY USER", "Verified and activated user ID: $verify_user_id");
        }
    } else {
        $error_message = "Error: Invalid or expired verification code.";
        $_SESSION['verify_user_id'] = $verify_user_id;
    }
}

// ==========================================
// ACTION 5: CANCEL/CHANGE INCORRECT EMAIL DURING VERIFICATION
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['cancel_verify_email'])) {
    $verify_user_id = (int)$_POST['verify_user_id'];
    $conn->query("DELETE FROM users WHERE id = '$verify_user_id' AND status = 'Unverified'");
    
    unset($_SESSION['verify_user_id']);
    $error_message = "Verification cancelled. You can now re-create the account with the correct email.";
}

// ==========================================
// ACTION 6: TOGGLE ACCOUNT STATUS
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_status']) && $user_role != 'Employee') {
    $target_id = $_POST['target_user_id'];
    $new_status = $_POST['new_status'];
    
    $check_target_sql = "SELECT username, role, branch_id FROM users WHERE id='$target_id'";
    $target_user_result = $conn->query($check_target_sql);
    
    if ($target_user_result && $target_user_result->num_rows > 0) {
        $target_user = $target_user_result->fetch_assoc();
        $can_toggle = false;
        
        if ($user_role == 'Admin') {
            if ($target_user['username'] !== $current_username) { $can_toggle = true; }
            else { $error_message = "Error: You cannot deactivate your own account."; }
        } elseif ($user_role == 'Manager') {
            if ($target_user['role'] == 'Employee' && $target_user['branch_id'] == $user_branch_id) { $can_toggle = true; }
            else { $error_message = "Security Alert: Unauthorized account modification blocked."; }
        }
        
        if ($can_toggle) {
            $toggle_sql = "UPDATE users SET status='$new_status' WHERE id='$target_id'";
            if ($conn->query($toggle_sql)) {
                $action_word = ($new_status == 'Inactive') ? "deactivated" : "reactivated";
                $success_message = "User account successfully $action_word!";
                logAction($conn, $user_id, NULL, "UPDATE USER", ucfirst($action_word) . " user account ID: $target_id");
            }
        }
    }
}

// ==========================================
// ACTION 7: CHANGE PASSWORD
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_change_password'])) {
    $target_user_id = (int)$_POST['target_password_user_id'];
    $current_password_input = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if ($user_role == 'Employee' && $target_user_id != $user_id) {
        die("Security Alert: Unauthorized action.");
    }

    if (strlen($new_password) < 6 || strlen($new_password) > 25) {
        $error_message = "Error: New password must be between 6 and 25 characters.";
    } elseif (strlen($current_password_input) > 25) {
        $error_message = "Error: Current password exceeds the 25-character limit.";
    } else {
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $target_user_id);
        $stmt->execute();
        $result_pass = $stmt->get_result()->fetch_assoc();

        if (!$result_pass || !password_verify($current_password_input, $result_pass['password'])) {
            $error_message = "Error: Current password is incorrect.";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "Error: Passwords do not match. Please try again.";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update_stmt->bind_param("si", $hashed_password, $target_user_id);

            if ($update_stmt->execute()) {
                $success_message = "Password successfully updated!";
                logAction($conn, $user_id, NULL, "UPDATE PASSWORD", "Changed password for user ID: $target_user_id");
            } else {
                $error_message = "Database error. Could not update password.";
            }
        }
    }
}

// FETCH USERS & BRANCHES
$users_to_edit = [];
if ($user_role == 'Admin') {
    $sql_users = "SELECT id, username, role FROM users ORDER BY role ASC, username ASC";
    $result_users = $conn->query($sql_users);
    while($row = $result_users->fetch_assoc()) { $users_to_edit[] = $row; }
} elseif ($user_role == 'Manager') {
    $sql_users = "SELECT id, username, role FROM users WHERE username = '$current_username' OR (role = 'Employee' AND branch_id = '$user_branch_id') ORDER BY role DESC, username ASC";
    $result_users = $conn->query($sql_users);
    while($row = $result_users->fetch_assoc()) { $users_to_edit[] = $row; }
} else {
    $sql_users = "SELECT id, username, role FROM users WHERE id = '$user_id'";
    $result_users = $conn->query($sql_users);
    while($row = $result_users->fetch_assoc()) { $users_to_edit[] = $row; }
}

if ($user_role == 'Admin') {
    $sql = "SELECT u.id, u.username, u.email, u.role, u.status, b.branch_name FROM users u LEFT JOIN branches b ON u.branch_id = b.id ORDER BY u.status ASC, u.role ASC, u.username ASC";
} elseif ($user_role == 'Manager') {
    $sql = "SELECT u.id, u.username, u.email, u.role, u.status, b.branch_name FROM users u LEFT JOIN branches b ON u.branch_id = b.id WHERE u.username = '$current_username' OR (u.role = 'Employee' AND u.branch_id = '$user_branch_id') ORDER BY u.role DESC, u.username ASC";
} else {
    $sql = "SELECT u.id, u.username, u.email, u.role, u.status, b.branch_name FROM users u LEFT JOIN branches b ON u.branch_id = b.id WHERE u.id = '$user_id'";
}
$result = $conn->query($sql);
$branches_dropdown = $conn->query("SELECT id, branch_name FROM branches ORDER BY branch_name ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Settings</title>
    <link rel="icon" type="image/png" href="img/logo no bg.png">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/inventory.css">
    <link rel="stylesheet" href="css/settings.css">
    <link rel="stylesheet" href="css/mobile.css?v=6">
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-header">
            <a href="dashboard.php">
                <img src="img/logo no bg.png" alt="IJE LPG Logo" class="sidebar-logo-img">
                <span class="sidebar-brand-text">IJE LPG</span>
            </a>
        </div>
        <ul class="sidebar-menu">
            <li><a href="dashboard.php"><span class="nav-icon"><img src="img/dashboard.png" alt="Dashboard"></span><span>Dashboard</span></a></li>
            <li><a href="items.php"><span class="nav-icon"><img src="img/items.png" alt="Items"></span><span>Items</span></a></li>
            <li><a href="inventory.php"><span class="nav-icon"><img src="img/inventory.png" alt="Inventory"></span><span>Inventory</span></a></li>
            <?php if ($_SESSION['role'] === 'Admin'): ?>
                <li><a href="analytics.php"><span class="nav-icon"><img src="img/data analytics.png" alt="Data Analytics"></span><span>Data Analytics</span></a></li>
            <?php endif; ?>   
            <li><a href="reports.php"><span class="nav-icon"><img src="img/reports.png" alt="Reports"></span><span>Sales Reports</span></a></li>
            <li><a href="issues.php"><span class="nav-icon"><img src="img/issues.png" alt="Issues"></span><span>Issues</span></a></li>
            <?php if ($_SESSION['role'] != 'Employee'): ?>
                <li><a href="branch.php"><span class="nav-icon"><img src="img/branch.png" alt="Branch"></span><span>Branch</span></a></li>
            <?php endif; ?>
            <?php if ($_SESSION['role'] === 'Admin'): ?>
                <li><a href="audit.php"><span class="nav-icon"><img src="img/logs.png" alt="Logs"></span><span>Audit Logs</span></a></li>
            <?php endif; ?>
            <li><a href="settings.php" class="active"><span class="nav-icon"><img src="img/settings.png" alt="Settings"></span><span>Settings</span></a></li>
            <br>
            <li><a href="logout.php" class="text-red" onclick="return confirm('Are you sure you want to log out?');"><span class="nav-icon"><img src="img/logout.png" alt="Logout"></span><span>Logout</span></a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="page-header">
            <div style="display: flex; align-items: center; gap: 15px;">
                <button id="sidebarToggle" type="button" aria-label="Toggle Sidebar" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #2c3e50; display: none; align-items: center; justify-content: center; padding: 0;">
                    &#9776;
                </button>
                <h1 style="margin: 0;">System Settings & Users</h1>
            </div>
            <div class="user-dropdown">
                <span>User: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                <?php include 'header-notifications.php'; ?>
            </div>
        </div>

        <?php if (!empty($success_message)): ?>
            <div style="padding: 10px; margin-bottom: 20px; background-color: #d4edda; color: #155724; border-radius: 4px; font-weight: bold; text-align: center;"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
            <div style="padding: 10px; margin-bottom: 20px; background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 4px; font-weight: bold; text-align: center;"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <div class="table-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;">System Users</h2>
                <div style="display: flex; gap: 12px;">
                    <button onclick="openPasswordModal()" class="btn-save" style="background-color: #ed8936; padding: 10px 20px; border: none; border-radius: 6px; color: white; cursor: pointer; font-weight: bold;">Change Password</button>
                    <button onclick="openEmailModal()" class="btn-save" style="background-color: #2b6cb0; padding: 10px 20px; border: none; border-radius: 6px; color: white; cursor: pointer; font-weight: bold;">Setup Email</button>
                    <?php if ($user_role != 'Employee'): ?>
                        <button onclick="openUserModal()" class="btn-update">+ Create New Account</button>
                    <?php endif; ?>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email Address</th>
                        <th>Assigned Branch</th>
                        <th>Account Role</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            $row_style = ($row['status'] == 'Inactive' || $row['status'] == 'Unverified') ? "opacity: 0.6; background-color: #f9f9f9;" : "";
                            echo "<tr style='$row_style'>";
                            echo "<td><strong>" . htmlspecialchars($row['username']) . "</strong></td>";
                            echo "<td>" . (!empty($row['email']) ? htmlspecialchars($row['email']) : "<em style='color:#a0aec0;'>No email set</em>") . "</td>";
                            echo "<td>" . ($row['branch_name'] ? htmlspecialchars($row['branch_name']) : "<em>All Branches</em>") . "</td>";
                            echo "<td>";
                            if ($row['role'] == 'Admin') echo "<span class='badge-admin'>System Admin</span>";
                            else if ($row['role'] == 'Manager') echo "<span class='badge-manager'>Store Manager</span>";
                            else echo "<span class='badge-employee'>Cashier / Employee</span>";
                            echo "</td>";
                            echo "<td>";
                            if ($row['status'] == 'Active') echo "<span style='color: #27ae60; font-weight: bold;'>Active</span>";
                            elseif ($row['status'] == 'Unverified') echo "<span style='color: #d69e2e; font-weight: bold;'>Unverified</span>";
                            else echo "<span style='color: #e74c3c; font-weight: bold;'>Deactivated</span>";
                            echo "</td>";
                            echo "<td>";
                            if ($row['username'] === $current_username) {
                                echo "<span style='color: #95a5a6; font-size: 12px; font-weight: bold;'>You</span>";
                            } elseif ($user_role == 'Admin' || ($user_role == 'Manager' && $row['role'] == 'Employee')) {
                                $is_active = ($row['status'] == 'Active');
                                $btn_color = $is_active ? "#e74c3c" : "#27ae60";
                                $btn_text = $is_active ? "Deactivate" : "Reactivate";
                                $new_status = $is_active ? "Inactive" : "Active";
                                ?>
                                <form action="settings.php" method="POST" style="margin: 0;" onsubmit="return confirm('Change status for this user?');">
                                    <input type="hidden" name="target_user_id" value="<?php echo $row['id']; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $new_status; ?>">
                                    <button type="submit" name="toggle_status" style="background-color: <?php echo $btn_color; ?>; color: white; border: none; padding: 6px 12px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 12px;"><?php echo $btn_text; ?></button>
                                </form>
                                <?php
                            } else { echo "-"; }
                            echo "</td>";
                            echo "</tr>";
                        }
                    } else { echo "<tr><td colspan='6' style='text-align:center;'>No users found.</td></tr>"; }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Setup Email Modal -->
    <div id="emailModal" class="modal">
        <div class="modal-content" style="width: 400px; border-top-color: #2b6cb0;">
            <span class="close-btn" onclick="closeEmailModal()">&times;</span>
            <h2 style="color: #2c3e50; margin-bottom: 20px;">My Alert Preferences</h2>
            <form action="settings.php" method="POST">
                <div class="form-group">
                    <label>Email Address for Low Stock Alerts:</label>
                    <input type="email" name="my_email" value="<?php echo htmlspecialchars($current_email); ?>" required>
                </div>
                <p style="font-size: 12px; color: #718096; margin-bottom: 15px;">A verification code will be sent to confirm your new email address.</p>
                <button type="submit" name="request_email_update" class="btn-save" style="width: 100%;">Send Verification Code</button>
            </form>
        </div>
    </div>

    <!-- Email OTP Verification Modal -->
    <div id="verifyEmailOtpModal" class="modal" style="<?php echo isset($_SESSION['verify_email_modal_open']) ? 'display: flex;' : 'display: none;'; ?> position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000;">
        <div class="modal-content" style="width: 400px; background: #fff; padding: 30px; border-radius: 8px; border-top: 5px solid #2b6cb0;">
            <h2 style="color: #2c3e50; margin-bottom: 15px;">Verify New Email Address</h2>
            <p style="font-size: 13px; color: #718096; margin-bottom: 20px;">Enter the 6-digit verification code sent to your new email address.</p>
            
            <form action="settings.php" method="POST">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="font-weight: bold; color: #2c3e50; font-size: 14px;">Verification Code:</label>
                    <input type="text" name="email_otp_code" required maxlength="6" placeholder="Enter 6-digit code" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 16px; letter-spacing: 2px; text-align: center;">
                </div>
                <button type="submit" name="verify_email_otp" style="background-color: #2b6cb0; color: white; padding: 12px; border: none; border-radius: 4px; width: 100%; font-weight: bold; cursor: pointer; margin-bottom: 10px;">Verify & Save Email</button>
            </form>

            <form action="settings.php" method="POST">
                <button type="submit" name="cancel_verify_my_email" style="background-color: #e74c3c; color: white; padding: 10px; border: none; border-radius: 4px; width: 100%; font-weight: bold; cursor: pointer; font-size: 13px;">Wrong Email? Cancel / Change</button>
            </form>
        </div>
    </div>

    <!-- Create Account Modal -->
    <?php if ($user_role != 'Employee'): ?>
    <div id="userModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeUserModal()">&times;</span>
            <h2 style="color: #2c3e50; margin-bottom: 20px;">Create Account</h2>
            <form action="settings.php" method="POST">
                <div class="form-group">
                    <label>Username:</label>
                    <input type="text" name="new_username" required minlength="3" maxlength="25" pattern="\S+" title="Username cannot contain spaces" placeholder="Enter username (no spaces, 3-25 chars)">
                </div>
                <div class="form-group">
                    <label>Email Address:</label>
                    <input type="email" name="new_email" placeholder="user@ijelpg.com" required>
                </div>
                <div class="form-group">
                    <label>Password:</label>
                    <div style="position: relative;">
                        <input type="password" id="new_account_password" name="new_password" required minlength="6" maxlength="25" placeholder="Enter password (6-25 chars)" style="width: 100%; padding-right: 40px;">
                        <span id="toggleNewAccountPassword" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #718096; display: flex; align-items: center;">
                            <svg id="eyeOpenNewAccount" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            <svg id="eyeClosedNewAccount" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                        </span>
                    </div>
                </div>
                <?php if ($user_role == 'Admin'): ?>
                    <div class="form-group">
                        <label>Account Role:</label>
                        <select name="new_role" required>
                            <option value="Manager">Store Manager</option>
                            <option value="Employee">Cashier / Employee</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Assign to Branch:</label>
                        <select name="new_branch_id" required>
                            <?php 
                            $branches_dropdown->data_seek(0);
                            while($b = $branches_dropdown->fetch_assoc()):
                            ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo $b['branch_name']; ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <div style="background-color: #f1f5f9; padding: 15px; border-radius: 4px; margin-bottom: 15px; font-size: 13px; color: #7f8c8d;">
                        <strong>Security Lock:</strong> Created as an <strong>Employee</strong> assigned to your current branch.
                    </div>
                <?php endif; ?>
                <button type="submit" name="create_user" class="btn-update" style="width: 100%;">Create Account & Send Code</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- New Account OTP Verification Modal -->
    <div id="verifyOtpModal" class="modal" style="<?php echo isset($_SESSION['verify_user_id']) ? 'display: flex;' : 'display: none;'; ?> position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000;">
        <div class="modal-content" style="width: 400px; background: #fff; padding: 30px; border-radius: 8px; border-top: 5px solid #27ae60;">
            <h2 style="color: #2c3e50; margin-bottom: 15px;">Verify New Account Email</h2>
            <p style="font-size: 13px; color: #718096; margin-bottom: 20px;">Enter the 6-digit verification code sent to the new account's email address to activate it.</p>
            
            <form action="settings.php" method="POST">
                <input type="hidden" name="verify_user_id" value="<?php echo $_SESSION['verify_user_id'] ?? ''; ?>">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="font-weight: bold; color: #2c3e50; font-size: 14px;">Verification Code:</label>
                    <input type="text" name="otp_code" required maxlength="6" placeholder="Enter 6-digit code" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 16px; letter-spacing: 2px; text-align: center;">
                </div>
                <button type="submit" name="verify_account_otp" style="background-color: #27ae60; color: white; padding: 12px; border: none; border-radius: 4px; width: 100%; font-weight: bold; cursor: pointer; margin-bottom: 10px;">Confirm & Activate Account</button>
            </form>

            <form action="settings.php" method="POST">
                <input type="hidden" name="verify_user_id" value="<?php echo $_SESSION['verify_user_id'] ?? ''; ?>">
                <button type="submit" name="cancel_verify_email" style="background-color: #e74c3c; color: white; padding: 10px; border: none; border-radius: 4px; width: 100%; font-weight: bold; cursor: pointer; font-size: 13px;">Wrong Email? Cancel / Change</button>
            </form>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div id="passwordModal" class="modal">
        <div class="modal-content" style="width: 400px; border-top-color: #ed8936;">
            <span class="close-btn" onclick="closePasswordModal()">&times;</span>
            <h2 style="color: #2c3e50; margin-bottom: 20px;">Change Password</h2>
            <form action="settings.php" method="POST">
                <div class="form-group">
                    <label>Select Account to Update:</label>
                    <select name="target_password_user_id" required>
                        <?php foreach ($users_to_edit as $u): ?>
                            <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['username']); ?> (<?php echo htmlspecialchars($u['role']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Current Password:</label>
                    <div style="position: relative;">
                        <input type="password" id="current_password" name="current_password" required minlength="6" maxlength="25" placeholder="Enter current password (max 25 chars)" style="width: 100%; padding-right: 40px;">
                        <span id="toggleCurrentPassword" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #718096; display: flex; align-items: center;">
                            <svg id="eyeOpenCurrent" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            <svg id="eyeClosedCurrent" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                        </span>
                    </div>
                </div>

                <div class="form-group">
                    <label>New Password:</label>
                    <div style="position: relative;">
                        <input type="password" id="new_password" name="new_password" required minlength="6" maxlength="25" placeholder="Enter new password (6-25 chars)" style="width: 100%; padding-right: 40px;">
                        <span id="toggleNewPassword" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #718096; display: flex; align-items: center;">
                            <svg id="eyeOpenNew" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            <svg id="eyeClosedNew" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                        </span>
                    </div>
                </div>

                <div class="form-group">
                    <label>Confirm New Password:</label>
                    <div style="position: relative;">
                        <input type="password" id="confirm_password" name="confirm_password" required minlength="6" maxlength="25" placeholder="Confirm new password (6-25 chars)" style="width: 100%; padding-right: 40px;">
                        <span id="toggleConfirmPassword" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #718096; display: flex; align-items: center;">
                            <svg id="eyeOpenConfirm" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            <svg id="eyeClosedConfirm" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>
                        </span>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; margin-bottom: 15px;">
                    <a href="javascript:void(0);" onclick="openSettingsForgotModal()" style="color: #e74c3c; font-size: 13px; text-decoration: none; font-weight: bold;">Forgot Password?</a>
                </div>

                <button type="submit" name="submit_change_password" class="btn-save" style="background-color: #ed8936; width: 100%;">Update Password</button>
            </form>
        </div>
    </div>

    <!-- Forgot Password Modal inside Settings -->
    <div id="settingsForgotModal" class="modal">
        <div class="modal-content" style="width: 400px; border-top-color: #ed8936;">
            <span class="close-btn" onclick="closeSettingsForgotModal()">&times;</span>
            <h2 style="color: #2c3e50; margin-bottom: 15px;">Password Recovery</h2>
            <p style="font-size: 13px; color: #718096; margin-bottom: 20px;">Need to reset your password? Enter your username or email to receive a verification code.</p>
            
            <form action="forgot_password.php" method="POST">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="font-weight: bold; color: #2c3e50; font-size: 14px;">Username or Email:</label>
                    <input type="text" name="username_or_email" required placeholder="Enter username or email" style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box;">
                </div>
                <button type="submit" name="request_reset" class="btn-save" style="background-color: #ed8936; width: 100%;">Send Verification Code</button>
            </form>
        </div>
    </div>

    <script src="js/settings.js"></script>
    <script src="js/mobile.js?v=6"></script>

</body>
</html>