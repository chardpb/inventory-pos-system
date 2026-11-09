<?php
session_start();
require 'db_connect.php';

// Ensure the user is logged in
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

$user_branch_id = $_SESSION['branch_id'];

// Flip the switch in the database from 0 (Unread) to 1 (Read) for today's sales
$stmt = $conn->prepare("UPDATE daily_sales SET is_read = 1 WHERE branch_id = ? AND DATE(sale_date) = CURDATE()");
$stmt->bind_param("s", $user_branch_id);
$stmt->execute();

// Instantly redirect them to the reports page so they never see this file
header("Location: reports.php");
exit();
?>