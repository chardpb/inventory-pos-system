<?php
session_start();
require 'db_connect.php'; 

// Tell the browser that this script will output JSON data, not HTML.
header('Content-Type: application/json');

// If the user is not logged in (no role assigned), stop the script.
if (!isset($_SESSION['role'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

// Retrieve the user's role and branch ID from the session memory.
$user_role = $_SESSION['role'];
$user_branch_id = $_SESSION['branch_id'];

$issue_count = 0;
$low_stock_count = 0;

// If the user is an Admin, they see global data (all branches).
if ($user_role == 'Admin') {
    // Count items with 3 or fewer tanks across the entire company.
    $stock_query = $conn->query("SELECT COUNT(*) as count FROM inventory WHERE full_tanks <= 3");
    if ($stock_query) $low_stock_count = $stock_query->fetch_assoc()['count'];
    
    // Count issues that have been specifically sent to the Admin for resolution.
    $issue_query = $conn->query("SELECT COUNT(*) as count FROM issue_reports WHERE status = 'Escalated to Admin' OR status IS NULL OR status = ''");
    if ($issue_query) $issue_count = $issue_query->fetch_assoc()['count'];

} 
//Managers and Employees only see data related to their specific branch.
elseif ($user_role == 'Manager' || $user_role == 'Employee') {
    // Count low stock items only for the branch the user belongs to.
    $stock_query = $conn->query("SELECT COUNT(*) as count FROM inventory WHERE branch_id = '$user_branch_id' AND full_tanks <= 3");
    if ($stock_query) $low_stock_count = $stock_query->fetch_assoc()['count'];
    
    // Count issues waiting for the Manager's attention at their specific branch.
    $issue_query = $conn->query("SELECT COUNT(*) as count FROM issue_reports WHERE branch_id = '$user_branch_id' AND (status = 'Pending Manager' OR status IS NULL OR status = '')");
    if ($issue_query) $issue_count = $issue_query->fetch_assoc()['count'];
}

//Combine the different alerts into one total for the notification bell badge.
$total_notifications = $issue_count + $low_stock_count;

//Send the final numbers back to the Dashboard's JavaScript in JSON format.
echo json_encode([
    'total' => $total_notifications,
    'issues' => $issue_count,
    'low_stock' => $low_stock_count
]);
exit();
?>