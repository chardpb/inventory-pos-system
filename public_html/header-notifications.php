<?php
// Ensure session and database connection ($conn) are available
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_role = $_SESSION['role'] ?? 'Employee';
$user_branch_id = $_SESSION['branch_id'] ?? 1;

// Only calculate notifications if the role is Admin or Manager
if ($user_role === 'Admin' || $user_role === 'Manager') {
    
    // 1. Fetch Active Issues Count from 'issue_reports' table
    if ($user_role === 'Admin') {
        $issue_query = "SELECT COUNT(*) as count FROM issue_reports WHERE status != 'Resolved'";
    } else {
        // Managers only see issues for their branch
        $issue_query = "SELECT COUNT(*) as count FROM issue_reports WHERE status != 'Resolved' AND branch_id = '$user_branch_id'";
    }
    $issue_result = $conn->query($issue_query);
    $issue_count = ($issue_result && $row = $issue_result->fetch_assoc()) ? (int)$row['count'] : 0;

    // 2. Fetch Low Stock Items Count using 'full_tanks <= 3'
    if ($user_role === 'Admin') {
        $stock_query = "SELECT COUNT(*) as count FROM inventory WHERE full_tanks <= 3";
    } else {
        // Managers only see low stock for their specific branch inventory
        $stock_query = "SELECT COUNT(*) as count FROM inventory WHERE full_tanks <= 3 AND branch_id = '$user_branch_id'";
    }
    $stock_result = $conn->query($stock_query);
    $low_stock_count = ($stock_result && $row = $stock_result->fetch_assoc()) ? (int)$row['count'] : 0;

    // Total Notifications
    $total_notifs = $issue_count + $low_stock_count;
?>

<!-- Notification Bell & Dropdown Component -->
<div class="user-dropdown">
    <div class="bell-icon-wrapper">
        <img src="img/bell.png" alt="Notifications">
        <?php if ($total_notifs > 0): ?>
            <span class="bell-badge-total"><?php echo $total_notifs; ?></span>
        <?php endif; ?>
    </div>
    <span>▼</span>

    <!-- Dropdown Content -->
    <div class="dropdown-content">
        <?php if ($issue_count > 0): ?>
            <a href="issues.php" class="dropdown-item">
                <span><img src="img/issues.png"> Active Issues</span>
                <span class="notif-badge"><?php echo $issue_count; ?></span>
            </a>
        <?php endif; ?>

        <?php if ($low_stock_count > 0): ?>
            <a href="inventory.php" class="dropdown-item">
                <span><img src="img/low stock.png"> Low Stock Items</span>
                <span class="notif-badge"><?php echo $low_stock_count; ?></span>
            </a>
        <?php endif; ?>

        <?php if ($total_notifs === 0): ?>
            <div class="dropdown-item" style="justify-content: center; color: #7f8c8d;">
                Notifications & Alert
            </div>
        <?php endif; ?>
    </div>
</div>

<?php } // End of Admin/Manager role check ?>