<?php
session_start();
// STRICT NO-CACHE HEADERS
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Check login status: if not logged in, kick to login.php.
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}
// If the user is NOT an Admin, kick them back to the dashboard
if ($_SESSION['role'] !== 'Admin') {
    header("Location: dashboard.php");
    exit();
}

require 'db_connect.php';

$user_role = $_SESSION['role'];
$user_branch_id = $_SESSION['branch_id'];
$current_username = $_SESSION['username'];

// Get current user ID
$user_query = $conn->query("SELECT id FROM users WHERE username = '$current_username'");
$current_user_id = $user_query->fetch_assoc()['id'];

// ==========================================
// FETCH BRANCHES FOR FILTER DROPDOWN
// ==========================================
$branches_res = $conn->query("SELECT id, branch_name FROM branches ORDER BY is_main_branch DESC, branch_name ASC");

// ==========================================
// CAPTURE FILTER INPUTS
// ==========================================
$filter_start = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$filter_end = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$filter_branch = isset($_GET['branch_id']) ? $_GET['branch_id'] : '';

// ==========================================
// BUILD DYNAMIC SQL QUERY
// ==========================================
$sql = "SELECT a.*, u.username, u.role, b.branch_name
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.id
        LEFT JOIN branches b ON a.branch_id = b.id ";

$conditions = [];

// 1. Apply Role Security First
if ($user_role != 'Admin') {
    // Managers only see themselves and their employees
    $conditions[] = "(a.user_id = '$current_user_id' OR (u.role = 'Employee' AND u.branch_id = '$user_branch_id'))";
}

// 2. Apply Branch Location Filter (Includes branch logs + global actions by users assigned to this branch)
if (!empty($filter_branch)) {
    $safe_branch = $conn->real_escape_string($filter_branch);
    $conditions[] = "(a.branch_id = '$safe_branch' OR (a.branch_id IS NULL AND u.branch_id = '$safe_branch'))";
}

// 3. Apply Date Filters
if (!empty($filter_start)) {
    $safe_start = $conn->real_escape_string($filter_start);
    $conditions[] = "DATE(a.timestamp) >= '$safe_start'";
}
if (!empty($filter_end)) {
    $safe_end = $conn->real_escape_string($filter_end);
    $conditions[] = "DATE(a.timestamp) <= '$safe_end'";
}

// Combine all conditions into the query
if (count($conditions) > 0) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

// Add the final sorting and limit
$sql .= " ORDER BY a.timestamp DESC LIMIT 200";

$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Audit Logs</title>
    <link rel="icon" type="image/png" href="img/logo no bg.png">
    <link rel="stylesheet" href="css/inventory.css">
    <link rel="stylesheet" href="css/audit.css">
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
                <a href="issues.php">
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
                    <a href="audit.php" class="active">
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
                <h1 style="margin: 0;">System Audit Logs</h1>
            </div>
                <div class="user-dropdown">
                    <span>User: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                    <?php include 'header-notifications.php'; ?>
                </div>
        </div>

        <div class="table-container">
            
            <form method="GET" action="audit.php" style="display: flex; gap: 15px; margin-bottom: 25px; align-items: flex-end; background-color: #f8fafc; padding: 20px; border-radius: 8px; border: 1px solid #e2e8f0; flex-wrap: wrap;">
                
                <div style="display: flex; flex-direction: column;">
                    <label style="font-size: 12px; font-weight: bold; color: #4a5568; margin-bottom: 5px; text-transform: uppercase;">Branch Location</label>
                    <select name="branch_id" style="padding: 10px; border: 1px solid #cbd5e0; border-radius: 6px; font-family: inherit; color: #2d3748; background-color: #ffffff; min-width: 220px;">
                        <option value="">-- All Branches --</option>
                        <?php 
                        if ($branches_res && $branches_res->num_rows > 0) {
                            while ($b = $branches_res->fetch_assoc()) {
                                $selected = ($filter_branch == $b['id']) ? 'selected' : '';
                                echo "<option value='" . $b['id'] . "' $selected>" . htmlspecialchars($b['branch_name']) . "</option>";
                            }
                        }
                        ?>
                    </select>
                </div>

                <div style="display: flex; flex-direction: column;">
                    <label style="font-size: 12px; font-weight: bold; color: #4a5568; margin-bottom: 5px; text-transform: uppercase;">Start Date</label>
                    <input type="date" name="start_date" min="2025-01-01" max="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($filter_start); ?>" style="padding: 10px; border: 1px solid #cbd5e0; border-radius: 6px; font-family: inherit; color: #2d3748;">
                </div>
                
                <div style="display: flex; flex-direction: column;">
                    <label style="font-size: 12px; font-weight: bold; color: #4a5568; margin-bottom: 5px; text-transform: uppercase;">End Date</label>
                    <input type="date" name="end_date" min="2025-01-01" max="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($filter_end); ?>" style="padding: 10px; border: 1px solid #cbd5e0; border-radius: 6px; font-family: inherit; color: #2d3748;">
                </div>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" style="background-color: #2b6cb0; color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s;">Filter Reports</button>
                    <a href="audit.php" style="background-color: #edf2f7; color: #4a5568; text-decoration: none; padding: 10px 20px; border-radius: 6px; border: 1px solid #cbd5e0; font-weight: bold; display: flex; align-items: center;">Clear</a>
                </div>
            </form>

            <h2 style="margin-bottom: 20px;">Recent Activity</h2>
            <table class="log-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Location</th>
                        <th>Action Type</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result && $result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            
                            // Style the action badge based on what they did
                            $badge_class = "badge-system";
                            if (strpos($row['action_type'], 'SALE') !== false) $badge_class = "badge-sale";
                            if (strpos($row['action_type'], 'UPDATE') !== false || strpos($row['action_type'], 'ADD') !== false) $badge_class = "badge-update";
                            if (strpos($row['action_type'], 'DELETE') !== false || strpos($row['action_type'], 'REMOVE') !== false) $badge_class = "badge-delete";

                            // Format the date nicely
                            $date_formatted = date("M d, Y h:i A", strtotime($row['timestamp']));

                            echo "<tr>";
                            echo "<td class='timestamp-col'>" . $date_formatted . "</td>";
                            echo "<td><strong>" . htmlspecialchars($row['username']) . "</strong></td>";
                            echo "<td>" . htmlspecialchars($row['role']) . "</td>";
                            echo "<td>" . ($row['branch_name'] ? htmlspecialchars($row['branch_name']) : "<em>Global System</em>") . "</td>";
                            echo "<td><span class='" . $badge_class . "'>" . htmlspecialchars($row['action_type']) . "</span></td>";
                            echo "<td>" . htmlspecialchars($row['details']) . "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='6' style='text-align: center; padding: 40px; color: #7f8c8d; background-color: #f8fafc;'>No activity matches your filters.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="js/mobile.js?v=6"></script>

</body>
</html>