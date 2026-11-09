<?php
// ENABLE PHP ERROR REPORTING FOR DEBUGGING
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
require 'db_connect.php';

$user_role = $_SESSION['role'];
$user_branch_id = $_SESSION['branch_id'];

// ==========================================
// NOTIFICATION SYSTEM LOGIC
// ==========================================
$issue_count = 0;
$low_stock_count = 0;

if ($user_role == 'Admin') {
    $stock_query = $conn->query("SELECT COUNT(*) as count FROM inventory WHERE full_tanks <= 3");
    if ($stock_query) $low_stock_count = $stock_query->fetch_assoc()['count'];
    
    $issue_query = $conn->query("SELECT COUNT(*) as count FROM issue_reports WHERE status = 'Escalated to Admin' OR status IS NULL OR status = ''");
    if ($issue_query) {
        $issue_count = $issue_query->fetch_assoc()['count'];
    }

} elseif ($user_role == 'Manager' || $user_role == 'Employee') {
    $stock_query = $conn->query("SELECT COUNT(*) as count FROM inventory WHERE branch_id = '$user_branch_id' AND full_tanks <= 3");
    if ($stock_query) $low_stock_count = $stock_query->fetch_assoc()['count'];
    
    $issue_query = $conn->query("SELECT COUNT(*) as count FROM issue_reports WHERE branch_id = '$user_branch_id' AND (status = 'Pending Manager' OR status IS NULL OR status = '')");
    if ($issue_query) {
        $issue_count = $issue_query->fetch_assoc()['count'];
    }
}

// ==========================================
// 1. CALCULATE TODAY'S SALES 
// ==========================================
$today_sales = 0;
if ($user_role == 'Admin') {
    $sales_query = "SELECT SUM(total_price) as total FROM daily_sales WHERE DATE(sale_date) = CURDATE()";
} else {
    $sales_query = "SELECT SUM(total_price) as total FROM daily_sales WHERE branch_id = '$user_branch_id' AND DATE(sale_date) = CURDATE()";
}
$sales_result = $conn->query($sales_query);
if ($sales_result && $row = $sales_result->fetch_assoc()) {
    $today_sales = $row['total'] ? $row['total'] : 0;
}

// ==========================================
// 2. FETCH RECENT TRANSACTIONS
// ==========================================
if ($user_role == 'Admin') {
    $recent_query = "SELECT ds.*, p.brand_name, p.tank_size, u.username, b.branch_name
                    FROM daily_sales ds
                    JOIN products p ON ds.product_id = p.id
                    JOIN users u ON ds.user_id = u.id
                    JOIN branches b ON ds.branch_id = b.id
                    WHERE DATE(ds.sale_date) = CURDATE()
                    ORDER BY ds.sale_date DESC LIMIT 10";
} else {
    $recent_query = "SELECT ds.*, p.brand_name, p.tank_size, u.username, b.branch_name
                    FROM daily_sales ds
                    JOIN products p ON ds.product_id = p.id
                    JOIN users u ON ds.user_id = u.id
                    JOIN branches b ON ds.branch_id = b.id
                    WHERE ds.branch_id = '$user_branch_id' AND DATE(ds.sale_date) = CURDATE()
                    ORDER BY ds.sale_date DESC LIMIT 10";
}
$recent_result = $conn->query($recent_query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <link rel="icon" type="image/png" href="img/logo no bg.png">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/mobile.css?v=7">
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
                <a href="dashboard.php" class="active">
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
                <!-- ADDED: Hamburger toggle button for high zoom / mobile screens -->
                <button id="sidebarToggle" type="button" aria-label="Toggle Sidebar" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #2c3e50; display: none; align-items: center; justify-content: center; padding: 0;">
                    &#9776;
                </button>
                <h1 style="margin: 0;">Dashboard</h1>
            </div>
            
            <div class="user-dropdown">
                <span>User: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                <?php include 'header-notifications.php'; ?>
            </div>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card card-green">
                <div class="kpi-title">Today's Sales Revenue</div>
                <div class="kpi-value">₱<?php echo number_format($today_sales, 2); ?></div>
            </div>

            <div class="kpi-card card-red">
                <div class="kpi-title">Items Low on Stock </div>
                <div class="kpi-value"><?php echo $low_stock_count; ?> Items</div>
            </div>

            <div class="kpi-card card-blue">
                <div class="kpi-title">System Status</div>
                <div class="kpi-value">Online</div>
            </div>
        </div>

        <div class="table-container">
            <h2 style="margin-bottom: 20px; color: #2c3e50;">Today's Recent Transactions</h2>
            
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Location</th>
                        <th>Cashier</th>
                        <th>Item Sold</th>
                        <th>Amount</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($recent_result && $recent_result->num_rows > 0) {
                        while($row = $recent_result->fetch_assoc()) {
                            $formatted_time = date("h:i A", strtotime($row['sale_date']));
                            
                            $brand = htmlspecialchars($row['brand_name'], ENT_QUOTES);
                            $size = htmlspecialchars($row['tank_size'], ENT_QUOTES);
                            $item_name = $brand . " " . $size;
                            
                            $raw_type = isset($row['sale_type']) ? $row['sale_type'] : '';
                            
                            if ($raw_type === 'new' || $raw_type === 'New Tank' || $raw_type === 'Buy New Tank') {
                                $tank_type = 'New Tank';
                            } else {
                                $tank_type = 'Swap Tank';
                            }

                            $branch = htmlspecialchars($row['branch_name'], ENT_QUOTES);
                            $cashier = htmlspecialchars($row['username'], ENT_QUOTES);
                            $qty = $row['quantity'];
                            $total = number_format($row['total_price'], 2);
                            $date_full = date("n/j/Y, g:i:s A", strtotime($row['sale_date']));
                            
                            echo "<tr>";
                            echo "<td style='color: #7f8c8d;'>" . $formatted_time . "</td>";
                            echo "<td><strong>" . $branch . "</strong></td>";
                            echo "<td>" . $cashier . "</td>";
                            echo "<td>" . $qty . "x " . $item_name . "</td>";
                            echo "<td style='color: #27ae60; font-weight: bold;'>₱" . $total . "</td>";
                            echo "<td><button class='btn-view' onclick=\"openReceiptModal('{$branch}', '{$cashier}', '{$item_name}', '{$tank_type}', '{$qty}', '{$total}', '{$date_full}')\">View Receipt</button></td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='6' style='text-align: center; padding: 30px; color: #7f8c8d;'>No sales recorded yet today.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

    </div>

    <div id="receiptModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeReceiptModal()">&times;</span>
            <div style="text-align: center; margin-bottom: 20px;">
                <h2 style="color: #2c3e50; margin-bottom: 5px;">Transaction Details</h2>
                <p style="color: #7f8c8d; font-size: 13px;" id="modal-date">Date & Time</p>
            </div>
            
            <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; border: 1px solid #eee;">
                <div class="receipt-row" style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span>Store Location:</span>
                    <strong id="modal-branch">Branch Name</strong>
                </div>
                <div class="receipt-row" style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span>Assisted By (Cashier):</span>
                    <strong id="modal-cashier">Cashier Name</strong>
                </div>
                <div class="receipt-row" style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span>Item Purchased:</span>
                    <strong id="modal-item">LPG Name</strong>
                </div>
                <div class="receipt-row" style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span>Purchase Type:</span>
                    <strong id="modal-type" style="color:#2078ab;">Swap Tank</strong>
                </div>
                <div class="receipt-row" style="display:flex; justify-content:space-between; margin-bottom:8px;">
                    <span>Quantity:</span>
                    <strong id="modal-qty">1</strong>
                </div>
                
                <div style="margin-top: 15px; padding-top: 15px; border-top: 2px solid #2c3e50; display: flex; justify-content: space-between; font-size: 18px;">
                    <span style="font-weight: bold; color: #2c3e50;">Total Paid:</span>
                    <strong style="color: #27ae60;" id="modal-total">₱0.00</strong>
                </div>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button onclick="printDashboardReceipt()" style="flex: 1; padding: 12px; background-color: #2078ab; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">Print Receipt</button>
                <button onclick="closeReceiptModal()" style="flex: 1; padding: 12px; background-color: #2c3e50; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">Close Details</button>
            </div>
        </div>
    </div>
    
    <script src="js/dashboard.js"></script>
    <script src="js/mobile.js?v=7"></script>
    
</body>
</html>