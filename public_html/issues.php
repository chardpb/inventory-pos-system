<?php
session_start();
// STRICT NO-CACHE HEADERS
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}
require 'db_connect.php';

$user_role = $_SESSION['role'];
$user_branch_id = $_SESSION['branch_id'];

// Safely get the user's ID directly from the database
$current_username = $_SESSION['username'];
$user_query = $conn->query("SELECT id FROM users WHERE username = '$current_username'");
$user_id = $user_query->fetch_assoc()['id'];
$success_message = "";

// Fetch items list for dropdown selector
$items_result = $conn->query("SELECT id, brand_name, tank_size FROM products ORDER BY brand_name ASC, tank_size ASC");
$items_list = [];
if ($items_result) {
    while ($item = $items_result->fetch_assoc()) {
        $items_list[] = $item;
    }
}

// ==========================================
// ACTION: SUBMIT ISSUE (Employee or Manager)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_issue'])) {
    $issue_type = $conn->real_escape_string($_POST['issue_type']);
    $product_id = !empty($_POST['product_id']) ? (int)$_POST['product_id'] : "NULL";
    $description = $conn->real_escape_string($_POST['description']);
    $current_date = date('Y-m-d');

    // If a Manager reports it, send it straight to the Admin!
    $initial_status = ($user_role == 'Manager') ? 'Escalated to Admin' : 'Pending Manager';

    $sql = "INSERT INTO issue_reports (branch_id, reported_by, product_id, issue_type, description, report_date, status)
            VALUES ('$user_branch_id', '$user_id', " . ($product_id === "NULL" ? "NULL" : "'$product_id'") . ", '$issue_type', '$description', '$current_date', '$initial_status')";
    $conn->query($sql);
    
    if ($user_role == 'Manager') {
        $success_message = "Report successfully submitted directly to the Admin.";
    } else {
        $success_message = "Report successfully submitted to your Manager.";
    }
}

// ==========================================
// ACTION: DELETE RESOLVED REPORT (Admin or Manager)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_report'])) {
    if ($user_role === 'Admin' || $user_role === 'Manager') {
        $delete_id = (int)$_POST['report_id'];
        
        // Ensure only resolved reports can be deleted
        $delete_sql = "DELETE FROM issue_reports WHERE id = $delete_id AND status = 'Resolved'";
        if ($user_role === 'Manager') {
            // Managers can only delete resolved reports for their own branch
            $delete_sql .= " AND branch_id = '$user_branch_id'";
        }
        
        if ($conn->query($delete_sql) && $conn->affected_rows > 0) {
            $success_message = "Resolved issue report has been permanently deleted.";
        } else {
            $success_message = "Unable to delete report. Ensure the issue is resolved.";
        }
    }
}

// ==========================================
// ACTION: MANAGER RESPONDS OR ESCALATES
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['manager_respond'])) {
    $report_id = $_POST['report_id'];
    $manager_resp = $conn->real_escape_string($_POST['manager_response']);
    $new_status = $_POST['action_type'];

    $sql = "UPDATE issue_reports
            SET manager_response = '$manager_resp', status = '$new_status'
            WHERE id = '$report_id'";
    $conn->query($sql);
    $success_message = "Report successfully updated.";
}

// ==========================================
// ACTION: ADMIN RESPONDS
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['admin_respond'])) {
    $report_id = $_POST['report_id'];
    $admin_resp = $conn->real_escape_string($_POST['admin_response']);

    $sql = "UPDATE issue_reports
            SET admin_response = '$admin_resp', status = 'Resolved'
            WHERE id = '$report_id'";
    $conn->query($sql);
    $success_message = "Admin response successfully recorded.";
}

// ==========================================
// FETCH REPORTS BASED ON ROLE
// ==========================================
$where_clause = "";
if ($user_role == 'Employee') {
    $where_clause = "WHERE r.reported_by = '$user_id' ORDER BY r.id DESC";
} elseif ($user_role == 'Manager') {
    $where_clause = "WHERE r.branch_id = '$user_branch_id' ORDER BY CASE WHEN r.status = 'Pending Manager' THEN 1 WHEN r.status = 'Resolved' THEN 3 ELSE 2 END, r.id DESC";
} else {
    $where_clause = "WHERE r.status = 'Escalated to Admin' OR r.admin_response IS NOT NULL OR r.status = 'Resolved' ORDER BY r.id DESC";
}

$sql = "SELECT r.*, u.username, b.branch_name, p.brand_name, p.tank_size
        FROM issue_reports r
        JOIN users u ON r.reported_by = u.id
        JOIN branches b ON r.branch_id = b.id
        LEFT JOIN products p ON r.product_id = p.id
        $where_clause";
$reports = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Issue</title>
    <link rel="icon" type="image/png" href="img/logo no bg.png">
    <link rel="stylesheet" href="css/inventory.css">
    <link rel="stylesheet" href="css/issues.css">
    <link rel="stylesheet" href="css/dashboard.css">
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
            <li>
                <a href="dashboard.php">
                    <span class="nav-icon"><img src="img/dashboard.png" alt="Dashboard"></span>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="items.php">
                    <span class="nav-icon"><img src="img/items.png" alt="Items"></span>
                    <span>Items</span>
                </a>
            </li>
            <li>
                <a href="inventory.php">
                    <span class="nav-icon"><img src="img/inventory.png" alt="Inventory"></span>
                    <span>Inventory</span>
                </a>
            </li>
            
            <?php if ($_SESSION['role'] === 'Admin'): ?>
                <li>
                    <a href="analytics.php">
                        <span class="nav-icon"><img src="img/data analytics.png" alt="Data Analytics"></span>
                        <span>Data Analytics</span>
                    </a>
                </li>
            <?php endif; ?>   
            
            <li>
                <a href="reports.php">
                    <span class="nav-icon"><img src="img/reports.png" alt="Reports"></span>
                    <span>Sales Reports</span>
                </a>
            </li>
            <li>
                <a href="issues.php" class="active">
                    <span class="nav-icon"><img src="img/issues.png" alt="Issues"></span>
                    <span>Issues</span>
                </a>
            </li>
        
            <?php if ($_SESSION['role'] != 'Employee'): ?>
                <li>
                    <a href="branch.php">
                        <span class="nav-icon"><img src="img/branch.png" alt="Branch"></span>
                        <span>Branch</span>
                    </a>
                </li>
            <?php endif; ?>
            
            <?php if ($_SESSION['role'] === 'Admin'): ?>
                <li>
                    <a href="audit.php">
                        <span class="nav-icon"><img src="img/logs.png" alt="Logs"></span>
                        <span>Audit Logs</span>
                    </a>
                </li>
            <?php endif; ?>
            
            <li>
                <a href="settings.php">
                    <span class="nav-icon"><img src="img/settings.png" alt="Settings"></span>
                    <span>Settings</span>
                </a>
            </li>
            
            <br>
            <li>
                <a href="logout.php" class="text-red" onclick="return confirm('Are you sure you want to log out?');">
                    <span class="nav-icon"><img src="img/logout.png" alt="Logout"></span>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </div>

    <div class="main-content">
        <div class="page-header">
            <div style="display: flex; align-items: center; gap: 15px;">
                <button id="sidebarToggle" type="button" aria-label="Toggle Sidebar" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #2c3e50; display: none; align-items: center; justify-content: center; padding: 0;">
                    &#9776;
                </button>
                <h1 style="margin: 0;">Issue Management System</h1>
            </div>
                <div class="user-dropdown">
                    <span>User: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                    <?php include 'header-notifications.php'; ?>
                </div>
        </div>

        <?php if ($success_message != ""): ?>
            <div class="alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="issue-dashboard">
            
            <?php if ($user_role == 'Employee' || $user_role == 'Manager'): ?>
                <div class="issue-card" style="border-top: 4px solid #2b6cb0;">
                    <h2 class="issue-title" style="margin-bottom: 15px;">Report an Issue to <?php echo ($user_role == 'Manager') ? 'Admin' : 'Manager'; ?></h2>
                    <form action="issues.php" method="POST">
                        <div class="form-group">
                            <label>Type of Issue:</label>
                            <select name="issue_type" class="form-textarea" style="min-height: auto;" required>
                                <option value="Damaged Goods">Damaged / Leaking Tank</option>
                                <option value="Incorrect Variant">Incorrect Variant Delivered</option>
                                <option value="Missing Stock">Missing Inventory</option>
                                <option value="Other">Other Operational Issue</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Related Product / Item (Optional):</label>
                            <select name="product_id" class="form-textarea" style="min-height: auto;">
                                <option value="">-- Select Affected Item (Optional) --</option>
                                <?php foreach ($items_list as $item): ?>
                                    <option value="<?php echo $item['id']; ?>">
                                        <?php echo htmlspecialchars($item['brand_name'] . ' ' . $item['tank_size']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Describe the issue in detail:</label>
                            <textarea name="description" class="form-textarea" placeholder="E.g., We received 5 Fiesta 11kg tanks, but 2 of them have damaged valves..." required></textarea>
                        </div>
                        <button type="submit" name="submit_issue" class="btn-save" style="background-color: #2b6cb0;">Submit Report</button>
                    </form>
                </div>
            <?php endif; ?>

            <h2 style="color: #2c3e50;">Active Reports</h2>
            
            <?php if ($reports && $reports->num_rows > 0): ?>
                <?php while($row = $reports->fetch_assoc()): ?>
                    
                    <?php
                        $badge_class = "status-pending";
                        if ($row['status'] == 'Resolved') $badge_class = "status-resolved";
                        if ($row['status'] == 'Escalated to Admin') $badge_class = "status-escalated";
                    ?>

                    <div class="issue-card">
                        <div class="issue-header">
                            <span class="issue-title">
                                <?php echo htmlspecialchars($row['issue_type']); ?>
                                <?php if (!empty($row['brand_name'])): ?>
                                    <span style="font-size: 14px; color: #2b6cb0; font-weight: 600;">
                                        📦 Item: <?php echo htmlspecialchars($row['brand_name'] . ' ' . $row['tank_size']); ?>
                                    </span>
                                <?php endif; ?>
                                <span style="font-size: 13px; color: #718096; font-weight: normal;">
                                    Reported on <?php echo date("M d, Y", strtotime($row['report_date'])); ?> by <?php echo htmlspecialchars($row['username']); ?> (<?php echo htmlspecialchars($row['branch_name']); ?>)
                                </span>
                            </span>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span class="badge-status <?php echo $badge_class; ?>"><?php echo htmlspecialchars($row['status']); ?></span>
                                
                                <?php if ($row['status'] == 'Resolved' && ($user_role == 'Admin' || $user_role == 'Manager')): ?>
                                    <form action="issues.php" method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to permanently delete this resolved issue report?');">
                                        <input type="hidden" name="report_id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" name="delete_report" class="btn-save" style="background-color: #e53e3e; padding: 6px 12px; font-size: 12px; border-radius: 4px; border: none; cursor: pointer;">
                                            🗑️ Delete
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="issue-body">
                            <strong>Description:</strong><br>
                            <?php echo nl2br(htmlspecialchars($row['description'])); ?>
                        </div>

                        <?php if (!empty($row['manager_response'])): ?>
                            <div class="response-box">
                                <div class="response-label">Manager's Action / Response:</div>
                                <?php echo nl2br(htmlspecialchars($row['manager_response'])); ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($row['admin_response'])): ?>
                            <div class="response-box" style="border-color: #805ad5; background-color: #faf5ff;">
                                <div class="response-label" style="color: #805ad5;">Admin's Final Decision:</div>
                                <?php echo nl2br(htmlspecialchars($row['admin_response'])); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($user_role == 'Manager' && $row['status'] == 'Pending Manager'): ?>
                            <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;">
                            <form action="issues.php" method="POST">
                                <input type="hidden" name="report_id" value="<?php echo $row['id']; ?>">
                                <div class="form-group">
                                    <label>Your Action Plan / Response:</label>
                                    <textarea name="manager_response" class="form-textarea" placeholder="Detail the steps taken to resolve this, or explain why you are escalating it to the Admin..." required></textarea>
                                </div>
                                <div style="display: flex; gap: 10px;">
                                    <button type="submit" name="manager_respond" value="Resolved" class="btn-save" style="background-color: #38a169; width: auto; padding: 10px 20px;">Mark as Resolved</button>
                                    
                                    <input type="hidden" id="action_type_<?php echo $row['id']; ?>" name="action_type" value="Resolved">
                                    <button type="button" onclick="document.getElementById('action_type_<?php echo $row['id']; ?>').value='Escalated to Admin'; this.form.submit();" class="btn-save" style="background-color: #e53e3e; width: auto; padding: 10px 20px;">Escalate to Admin</button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <?php if ($user_role == 'Admin' && $row['status'] == 'Escalated to Admin'): ?>
                            <hr style="border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;">
                            <form action="issues.php" method="POST">
                                <input type="hidden" name="report_id" value="<?php echo $row['id']; ?>">
                                <div class="form-group">
                                    <label>Admin Response & Directive:</label>
                                    <textarea name="admin_response" class="form-textarea" placeholder="Provide instructions to the Manager on how to handle this..." required></textarea>
                                </div>
                                <button type="submit" name="admin_respond" class="btn-save" style="background-color: #805ad5; width: auto; padding: 10px 20px;">Finalize & Resolve Issue</button>
                            </form>
                        <?php endif; ?>

                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="issue-card" style="text-align: center; color: #718096; padding: 40px;">
                    No pending issues found for your role. Everything is running smoothly!
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script src="js/mobile.js?v=6"></script>
</body>
</html>