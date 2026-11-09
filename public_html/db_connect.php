<?php

// Tell PHP to use Philippine Time for all date() functions
date_default_timezone_set('Asia/Manila');

// 1. Database Credentials
$servername = "localhost";
$username = "u347073285_ijelpg_System";
$password = "Ijelpg123.";
$dbname = "u347073285_lpg_inventory";


// 2. Build the bridge to the database
$conn = new mysqli($servername, $username, $password, $dbname);


// 3. Check connection
if ($conn->connect_error) {
    die("Oops! Database connection failed: " . $conn->connect_error);
}

// =========================================================================
// NEW: Tell the DATABASE to also use Philippine Time (+8 hours from UTC)
// =========================================================================
$conn->query("SET time_zone = '+08:00'");


function logAction($conn, $user_id, $branch_id, $action_type, $details) {
    // 1. Generate the CURRENT Philippine time right now in PHP
    $ph_time = date('Y-m-d H:i:s'); 

    $safe_action = $conn->real_escape_string($action_type);
    $safe_details = $conn->real_escape_string($details);
    
    $b_id_val = empty($branch_id) ? "NULL" : "'$branch_id'";

    // 2. Explicitly INSERT the $ph_time into your timestamp column
    // (Ensure your column name is 'timestamp' or 'created_at')
    $sql = "INSERT INTO audit_logs (user_id, branch_id, action_type, details, timestamp)
            VALUES ('$user_id', $b_id_val, '$safe_action', '$safe_details', '$ph_time')";
    
    $conn->query($sql);
}
?>