<?php
// 1. Security Check
session_start();
// STRICT NO-CACHE HEADERS
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");


if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// SECURITY BOUNCER: KICK OUT EMPLOYEES
if ($_SESSION['role'] == 'Employee') {
    header("Location: items.php"); 
    exit();
}

require 'db_connect.php';

// ==========================================
// GET CURRENT USER ID FOR THE AUDIT LOG
// ==========================================
$current_username = $_SESSION['username'];
$user_query = $conn->query("SELECT id FROM users WHERE username = '$current_username'");
if ($user_query && $user_query->num_rows > 0) {
    $user_id = $user_query->fetch_assoc()['id'];
} else {
    $user_id = 0;
}

$success_message = "";
$user_role = $_SESSION['role'];
$user_branch_id = $_SESSION['branch_id'];

// ==========================================
// ACTION 1: ADD NEW BRANCH (Admin Only)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_branch'])) {
    if ($user_role == 'Admin') {
        $branch_name = $conn->real_escape_string($_POST['new_branch_name']);
        $is_main = (int)$_POST['is_main_branch'];
        
        $insert_sql = "INSERT INTO branches (branch_name, is_main_branch, status) VALUES ('$branch_name', '$is_main', 'Active')";
        if ($conn->query($insert_sql) === TRUE) {
            $success_message = "New branch successfully added!";

            // TRIGGER THE AUDIT LOG
            logAction($conn, $user_id, NULL, "ADD BRANCH", "Created new branch location: $branch_name");
        }
    }
}

// ==========================================
// ACTION 2: EDIT EXISTING BRANCH (Admin & Manager)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_branch'])) {
    $edit_id = $_POST['edit_branch_id'];
    $edit_name = $conn->real_escape_string($_POST['edit_branch_name']);
    $edit_is_main = (int)$_POST['edit_is_main_branch'];
    
    // Security check: Managers can only edit their OWN branch ID
    if ($user_role == 'Admin' || ($user_role == 'Manager' && $edit_id == $user_branch_id)) {
        $update_sql = "UPDATE branches SET branch_name='$edit_name', is_main_branch='$edit_is_main' WHERE id='$edit_id'";
        if ($conn->query($update_sql) === TRUE) {
            $success_message = "Branch information successfully updated!";

            // TRIGGER THE AUDIT LOG
            logAction($conn, $user_id, NULL, "EDIT BRANCH", "Updated details for branch ID: $edit_id");
        }
    }
}

// ==========================================
// ACTION 3: TOGGLE BRANCH STATUS (Admin Only)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['toggle_branch'])) {
    if ($user_role == 'Admin') {
        $target_id = $_POST['target_branch_id'];
        $new_status = $_POST['new_status'];
        $target_name = $conn->real_escape_string($_POST['target_branch_name']);

        $update_sql = "UPDATE branches SET status='$new_status' WHERE id='$target_id'";
        if ($conn->query($update_sql) === TRUE) {
            $action_word = ($new_status == 'Inactive') ? "Deactivated" : "Reactivated";
            $success_message = "Branch successfully " . strtolower($action_word) . "!";

            // TRIGGER THE AUDIT LOG
            logAction($conn, $user_id, NULL, strtoupper($action_word) . " BRANCH", "$action_word branch: $target_name");
        }
    }
}

// ==========================================
// FETCH BRANCHES FOR THE TABLE
// ==========================================
if ($user_role == 'Admin') {
    // Admins see all locations
    $sql = "SELECT * FROM branches ORDER BY status ASC, is_main_branch DESC, branch_name ASC";
} else {
    // Managers ONLY see their assigned location
    $sql = "SELECT * FROM branches WHERE id = '$user_branch_id'";
}
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Branch Management</title>
    <link rel="icon" type="image/png" href="img/logo no bg.png">
    <link rel="stylesheet" href="css/branch.css">
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
                    <a href="branch.php" class="active">
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
                <h1 style="margin: 0;">Branch Management</h1>
            </div>
                <div class="user-dropdown">
                    <span>User: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                    <?php include 'header-notifications.php'; ?>
                </div>
        </div>

        <?php if ($success_message != ""): ?>
            <div class="alert-success" style="padding: 10px; margin-bottom: 20px; background-color: #d4edda; color: #155724; border-radius: 4px; font-weight: bold;"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <div class="table-container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2>Location Details</h2>
                
                <?php if ($user_role == 'Admin'): ?>
                    <button onclick="openAddModal()" class="btn-manage">+ Add New Branch</button>
                <?php endif; ?>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Branch ID</th>
                        <th>Location Name</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Settings</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ($result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            
                            // Visual fade for inactive branches
                            $row_style = (isset($row['status']) && $row['status'] == 'Inactive') ? "opacity: 0.6; background-color: #f9f9f9;" : "";
                            
                            echo "<tr style='$row_style'>";
                            echo "<td style='color: #7f8c8d;'>#" . $row['id'] . "</td>";
                            echo "<td><strong>" . htmlspecialchars($row['branch_name']) . "</strong></td>";
                            
                            if ($row['is_main_branch'] == 1) {
                                echo "<td><span class='badge-main' style='background-color: #9b59b6; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold;'>★ Main Branch</span></td>";
                            } else {
                                echo "<td><span class='badge-regular' style='background-color: #ecf0f1; color: #7f8c8d; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold;'>Regular Branch</span></td>";
                            }
                            
                            // STATUS COLUMN
                            echo "<td>";
                            if (!isset($row['status']) || $row['status'] == 'Active') {
                                echo "<span style='color: #27ae60; font-weight: bold;'>Active</span>";
                            } else {
                                echo "<span style='color: #e74c3c; font-weight: bold;'>Inactive</span>";
                            }
                            echo "</td>";
                            
                            echo "<td>";
                            echo "<div style='display: flex; gap: 8px;'>";
                            
                            // BOTH Admin and Manager can Edit
                            echo "<button onclick=\"openEditModal({$row['id']}, '" . addslashes($row['branch_name']) . "', {$row['is_main_branch']})\" class='btn-edit' style='background-color: #3498db; color: white; border: none; padding: 6px 12px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 12px;'>Edit Info</button>";
                            
                            // ONLY Admin can Deactivate/Reactivate
                            if ($user_role == 'Admin') {
                                $is_active = (!isset($row['status']) || $row['status'] == 'Active');
                                $btn_color = $is_active ? "#e74c3c" : "#27ae60";
                                $btn_text = $is_active ? "Deactivate" : "Reactivate";
                                $new_status = $is_active ? "Inactive" : "Active";
                                $confirm_msg = $is_active ? "Are you sure you want to deactivate this branch? It will be hidden from the system." : "Reactivate this branch?";

                                ?>
                                <form action="branch.php" method="POST" style="margin: 0;" onsubmit="return confirmBranchDeactivate('<?php echo htmlspecialchars($row['branch_name']); ?>');">
                                    <input type="hidden" name="target_branch_id" value="<?php echo $row['id']; ?>">
                                    <input type="hidden" name="target_branch_name" value="<?php echo htmlspecialchars($row['branch_name']); ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $new_status; ?>">
                                    <button type="submit" name="toggle_branch" style="background-color: <?php echo $btn_color; ?>; color: white; border: none; padding: 6px 12px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 12px;"><?php echo $btn_text; ?></button>
                                </form>
                                <?php
                            }
                            
                            echo "</div>";
                            echo "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='5' style='text-align: center; padding: 30px;'>No branch assigned.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($user_role == 'Admin'): ?>
    <div id="addBranchModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000;">
        <div class="modal-content" style="background-color: #ffffff; padding: 30px; border-radius: 8px; width: 400px; position: relative; border-top: 5px solid #2c3e50;">
            <span class="close-btn" onclick="closeAddModal()" style="position: absolute; top: 15px; right: 20px; font-size: 24px; cursor: pointer; color: #7f8c8d;">&times;</span>
            <h2 style="color: #2c3e50; margin-bottom: 20px;">Add New Branch</h2>
            <form action="branch.php" method="POST">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #2c3e50; font-size: 14px;">Branch Location Name:</label>
                    <input type="text" name="new_branch_name" maxlength="50" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #2c3e50; font-size: 14px;">Branch Type:</label>
                    <select name="is_main_branch" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                        <option value="0">Regular Branch</option>
                        <option value="1">Main Headquarters</option>
                    </select>
                </div>
                <button type="submit" name="add_branch" class="btn-manage" style="background-color: #2c3e50; color: #ffffff; padding: 12px; border-radius: 4px; border: none; cursor: pointer; font-weight: bold; width: 100%;">Save New Branch</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div id="editBranchModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000;">
        <div class="modal-content" style="background-color: #ffffff; padding: 30px; border-radius: 8px; width: 400px; position: relative; border-top: 5px solid #3498db;">
            <span class="close-btn" onclick="closeEditModal()" style="position: absolute; top: 15px; right: 20px; font-size: 24px; cursor: pointer; color: #7f8c8d;">&times;</span>
            <h2 style="color: #2c3e50; margin-bottom: 20px;">Edit Branch Info</h2>
            <form action="branch.php" method="POST">
                <input type="hidden" name="edit_branch_id" id="edit_form_branch_id">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #2c3e50; font-size: 14px;">Branch Location Name:</label>
                    <input type="text" name="edit_branch_name" id="edit_form_branch_name" maxlength="50" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 5px; font-weight: bold; color: #2c3e50; font-size: 14px;">Branch Type:</label>
                    <select name="edit_is_main_branch" id="edit_form_is_main" required <?php if($user_role == 'Manager') echo 'style="pointer-events: none; background-color: #f1f5f9; width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;"'; else echo 'style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px;"'; ?>>
                        <option value="0">Regular Branch</option>
                        <option value="1">Main Headquarters</option>
                    </select>
                    <?php if($user_role == 'Manager') echo '<small style="color: #e74c3c; margin-top: 5px; display: block;">Managers cannot change branch types.</small>'; ?>
                </div>
                <button type="submit" name="edit_branch" class="btn-edit" style="background-color: #3498db; color: #ffffff; padding: 12px; border-radius: 4px; border: none; cursor: pointer; font-weight: bold; width: 100%;">Save Changes</button>
            </form>
        </div>
    </div>

    <script src="js/branch.js"></script>
    <script src="js/mobile.js?v=6"></script>
    <?php include 'chatbot_widget.php'; ?>

</body>
</html>