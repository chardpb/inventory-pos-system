<?php
session_start();
require 'db_connect.php';

if (!isset($_SESSION['username'])) { exit(); }

// 1. CAPTURE THE SAME FILTERS
$filter_start  = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$filter_end    = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$filter_branch = isset($_GET['branch_id']) ? $_GET['branch_id'] : '';

// 2. BUILD THE WHERE CLAUSE (Same as reports.php)
$where_clause = "WHERE 1=1 ";
if ($_SESSION['role'] != 'Admin') {
    $where_clause .= " AND ds.branch_id = '{$_SESSION['branch_id']}'";
} elseif ($filter_branch != '') {
    $where_clause .= " AND ds.branch_id = '$filter_branch'";
}

if ($filter_start != '') { $where_clause .= " AND DATE(ds.sale_date) >= '$filter_start'"; }
if ($filter_end != '')   { $where_clause .= " AND DATE(ds.sale_date) <= '$filter_end'"; }

// 3. SET EXCEL HEADERS
$filename = "Sales_Report_" . date('Y-m-d') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

// 4. GENERATE DATA
$output = fopen('php://output', 'w');

// Add Column Headers to Excel
fputcsv($output, array('Date & Time', 'Branch', 'Item', 'Qty', 'Total Price', 'Cashier'));

$query = "SELECT ds.*, p.brand_name, p.tank_size, u.username, b.branch_name
          FROM daily_sales ds
          JOIN products p ON ds.product_id = p.id
          JOIN users u ON ds.user_id = u.id
          JOIN branches b ON ds.branch_id = b.id
          $where_clause
          ORDER BY ds.sale_date DESC";

$result = $conn->query($query);

while ($row = $result->fetch_assoc()) {
    fputcsv($output, array(
        $row['sale_date'],
        $row['branch_name'],
        $row['brand_name'] . " " . $row['tank_size'],
        $row['quantity'],
        "P" . number_format($row['total_price'], 2),
        $row['username']
    ));
}

fclose($output);
exit();
?>