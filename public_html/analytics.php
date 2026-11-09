<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}
require 'db_connect.php';

$user_role = $_SESSION['role'];
$user_branch_id = $_SESSION['branch_id'];

$branches = [];
if ($user_role === 'Admin') {
    $b_result = $conn->query("SELECT id, branch_name FROM branches ORDER BY branch_name ASC");
    if ($b_result) {
        while ($row = $b_result->fetch_assoc()) {
            $branches[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Data Analytics - IJE LPG</title>
    <link rel="icon" type="image/png" href="img/logo no bg.png">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/analytics.css">
    <link rel="stylesheet" href="css/mobile.css?v=6">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                    <a href="analytics.php" class="active">
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
                <button id="sidebarToggle" type="button" aria-label="Toggle Sidebar" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #2c3e50; display: none; align-items: center; justify-content: center; padding: 0;">
                    &#9776;
                </button>
                <h1 style="margin: 0;"> Data Analytics</h1>
            </div>
                <div class="user-dropdown">
                    <span>User: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                    <?php include 'header-notifications.php'; ?>
                </div>
        </div>

        <!-- GLOBAL CONTROL BAR -->
        <div class="analytics-filter-card">
            <div class="filter-group">
                <label for="branchSelect"><strong>Store Branch:</strong></label>
                <?php if ($user_role === 'Admin'): ?>
                    <select id="branchSelect" onchange="reloadAnalytics()">
                        <option value="all">All Branches (Combined)</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo (int)$b['id']; ?>"><?php echo htmlspecialchars($b['branch_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" value="Assigned Branch" disabled style="padding: 6px 10px; border-radius: 4px; border: 1px solid #ccc; background: #f0f0f0;">
                    <input type="hidden" id="branchSelect" value="<?php echo $user_branch_id; ?>">
                <?php endif; ?>
            </div>

            <div class="filter-group">
                <label for="chartPeriod"><strong>Forecast Horizon:</strong></label>
                <select id="chartPeriod" onchange="reloadAnalytics()">
                    <option value="week">Weekly Sales Only</option>
                    <option value="month">Monthly Sales Only</option>
                    <option value="year">Yearly Sales Only</option>
                </select>
            </div>
        </div>

        <!-- 1. TOP STAT KPI CARDS -->
        <div class="analytics-kpi-grid">
            <div class="kpi-card">
                <div class="kpi-info">
                    <span class="kpi-title">Total Revenue</span>
                    <h2 id="card-revenue">₱0.00</h2>
                    <span class="kpi-badge badge-green" id="badge-revenue">+0.0%</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-info">
                    <span class="kpi-title">Cylinders Sold</span>
                    <h2 id="card-cylinders">0 Units</h2>
                    <span class="kpi-badge badge-blue" id="badge-cylinders">Active</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-info">
                    <span class="kpi-title">Avg Order Value (AOV)</span>
                    <h2 id="card-aov">₱0.00</h2>
                    <span class="kpi-badge badge-purple" id="badge-aov">Per Order</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-info">
                    <span class="kpi-title">Active Outlets</span>
                    <h2 id="card-branches">0 Branches</h2>
                    <span class="kpi-badge badge-orange">Operational</span>
                </div>
            </div>
        </div>

        <!-- 2. MAIN TREND & FORECAST AREA CHART -->
        <div class="chart-card main-chart-box">
            <div class="chart-header">
                <h2>Revenue & Demand Forecasting Trends</h2>
            </div>
            <div style="position: relative; height: 320px; width: 100%;">
                <canvas id="analyticsChart"></canvas>
            </div>
        </div>

        <!-- 3. MIDDLE DUAL GRID (CATEGORY & DISTRIBUTION) -->
        <div class="analytics-two-col">
            <div class="chart-card">
                <div class="chart-header">
                    <h2>Top Selling Brands (By Cylinder Size)</h2>
                </div>
                <div style="position: relative; height: 260px; width: 100%;">
                    <canvas id="topProductsChart"></canvas>
                </div>
            </div>

            <div class="chart-card">
                <div class="chart-header">
                    <h2>LPG Sale Type Ratio</h2>
                </div>
                <div class="doughnut-container">
                    <div style="position: relative; height: 220px; width: 220px;">
                        <canvas id="tankRatioChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. BOTTOM HOURLY OPERATIONAL INSIGHTS -->
        <div class="chart-card">
            <div class="chart-header">
                <h2>Peak Operating Hours Traffic</h2>
            </div>
            <div style="position: relative; height: 240px; width: 100%;">
                <canvas id="peakHoursChart"></canvas>
            </div>
        </div>

    </div>

    <script src="js/analytics.js"></script>
    <script src="js/mobile.js?v=6"></script>
    
</body>
</html>