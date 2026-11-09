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

// ==========================================
// 1. CAPTURE FILTERS & SET PAGINATION
// ==========================================
$filter_start = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$filter_end = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$filter_branch = isset($_GET['branch_id']) ? $_GET['branch_id'] : '';

$limit = 50; // Show exactly 50 reports per page
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $limit;

// ==========================================
// 2. BUILD THE DYNAMIC "WHERE" CLAUSE
// ==========================================
$where_clause = "WHERE 1=1 ";

// Security Rule: Manager can only see their branch. Admin can search all or filter.
if ($user_role != 'Admin') {
    $where_clause .= " AND ds.branch_id = '$user_branch_id'";
} elseif ($filter_branch != '') {
    $where_clause .= " AND ds.branch_id = '$filter_branch'";
}

// DATE FILTERS (Using DATE() to ignore hours/minutes)
if ($filter_start != '') {
    $where_clause .= " AND DATE(ds.sale_date) >= '$filter_start'";
}
if ($filter_end != '') {
    $where_clause .= " AND DATE(ds.sale_date) <= '$filter_end'";
}

// ==========================================
// 3. COUNT TOTAL RECORDS FOR PAGINATION
// ==========================================
$count_sql = "SELECT COUNT(ds.id) AS total_rows
            FROM daily_sales ds
            JOIN products p ON ds.product_id = p.id
            JOIN users u ON ds.user_id = u.id
            JOIN branches b ON ds.branch_id = b.id
            $where_clause";
$count_result = $conn->query($count_sql);
$total_rows = $count_result->fetch_assoc()['total_rows'];
$total_pages = ceil($total_rows / $limit);

// ==========================================
// 4. FETCH THE 50 FILTERED TRANSACTIONS
// ==========================================
$log_sql = "SELECT ds.*, p.brand_name, p.tank_size, u.username, b.branch_name
            FROM daily_sales ds
            JOIN products p ON ds.product_id = p.id
            JOIN users u ON ds.user_id = u.id
            JOIN branches b ON ds.branch_id = b.id
            $where_clause
            ORDER BY ds.sale_date DESC
            LIMIT $limit OFFSET $offset";
$log_result = $conn->query($log_sql);

// Fetch branches for Admin dropdown
$branches = [];
if ($user_role == 'Admin') {
    $branch_query = $conn->query("SELECT id, branch_name FROM branches ORDER BY branch_name ASC");
    while ($b = $branch_query->fetch_assoc()) {
        $branches[] = $b;
    }
}

// Helper function to keep filter active when clicking "Next Page"
function buildPaginationUrl($page_num) {
    $params = $_GET;
    $params['page'] = $page_num;
    return 'reports.php?' . http_build_query($params);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Reports</title>
    <link rel="icon" type="image/png" href="img/logo no bg.png">
    <link rel="stylesheet" href="css/inventory.css">
    <link rel="stylesheet" href="css/reports.css">
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
                <a href="reports.php" class="active">
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
                <button id="sidebarToggle" type="button" aria-label="Toggle Sidebar" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #2c3e50; display: none; align-items: center; justify-content: center; padding: 0;">
                    &#9776;
                </button>
                <h1 style="margin: 0;">Transaction History</h1>
            </div>
                <div class="user-dropdown">
                    <span>User: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                    <?php include 'header-notifications.php'; ?>
                </div>
        </div>

        <form method="GET" action="reports.php" class="filter-section">
            <div class="filter-group">
                <label>Start Date</label>
                <input type="date" name="start_date" min="2025-01-01" max="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($filter_start); ?>">
            </div>
            
            <div class="filter-group">
                <label>End Date</label>
                <input type="date" name="end_date" min="2025-01-01" max="<?php echo date('Y-m-d'); ?>" value="<?php echo htmlspecialchars($filter_end); ?>">
            </div>
            <?php if ($user_role == 'Admin'): ?>
            <div class="filter-group">
                <label>Branch Location</label>
                <select name="branch_id">
                    <option value="">-- All Branches --</option>
                    <?php foreach($branches as $b): ?>
                        <option value="<?php echo $b['id']; ?>" <?php if($filter_branch == $b['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($b['branch_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="filter-group" style="flex-direction: row; gap: 12px;">
                <button type="submit" class="btn-search">Filter Reports</button>
                
                <a href="export_excel.php?<?php echo http_build_query($_GET); ?>" 
                    class="btn-search" 
                    style="background-color: #27ae60; text-decoration: none; text-align: center;">
                    Download Excel
                </a>
                
                <a href="reports.php" class="btn-clear">Clear</a>
            </div>
        </form>

        <div class="table-container">
            <h2 style="margin-bottom: 20px; color: #2c3e50;">Recent Transactions</h2>
            <table>
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <?php if ($user_role == 'Admin') echo "<th>Branch Location</th>"; ?>
                        <th>LPG Item</th>
                        <th>Type</th>
                        <th>Qty</th>
                        <th>Total Paid</th>
                        <th>Cashier</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($log_result && $log_result->num_rows > 0) {
                        while($row = $log_result->fetch_assoc()) {
                            // We leave the time visible in the table so they still know when the sale happened!
                            $formatted_date = date("M d, Y - h:i A", strtotime($row['sale_date']));
                            
                            echo "<tr>";
                            echo "<td style='color: #7f8c8d;'>" . $formatted_date . "</td>";
                            
                            if ($user_role == 'Admin') {
                                echo "<td><strong>" . htmlspecialchars($row['branch_name']) . "</strong></td>";
                            }
                            
                            echo "<td>" . htmlspecialchars($row['brand_name']) . " " . htmlspecialchars($row['tank_size']) . "</td>";
                            echo "<td><span style='background-color: #e2e3e5; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; color: #4a5568;'>Sale</span></td>";
                            echo "<td>" . htmlspecialchars($row['quantity']) . "</td>";
                            echo "<td style='color: #27ae60; font-weight: bold;'>₱" . number_format($row['total_price'], 2) . "</td>";
                            echo "<td>" . htmlspecialchars($row['username']) . "</td>";
                            echo "</tr>";
                        }
                    } else {
                        $col_span = ($user_role == 'Admin') ? '7' : '6';
                        echo "<tr><td colspan='$col_span' style='text-align: center; padding: 40px; color: #7f8c8d;'>No transaction data found for this period.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($current_page > 1): ?>
                    <a href="<?php echo buildPaginationUrl($current_page - 1); ?>" class="page-btn">← Previous</a>
                <?php endif; ?>

                <span class="page-info">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></span>

                <?php if ($current_page < $total_pages): ?>
                    <a href="<?php echo buildPaginationUrl($current_page + 1); ?>" class="page-btn">Next →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

    <script src="js/mobile.js?v=6"></script>

</body>
</html>