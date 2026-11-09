<?php
session_start();
// STRICT NO-CACHE HEADERS
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['username'])) {
    header("Location: login.php");
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
    $user_id = 0; // Fallback
}

$success_message = "";
$user_role = $_SESSION['role'];
$user_branch_id = $_SESSION['branch_id'];

// ==========================================
// DETERMINE ACTIVE BRANCH
// ==========================================
$active_branch_id = null;
if ($user_role == 'Admin') {
    if (isset($_GET['branch'])) {
        $active_branch_id = $_GET['branch'];
    }
} else {
    // Both Managers and Employees are locked to their assigned branch
    $active_branch_id = $user_branch_id;
}

// ==========================================
// FETCH BRANDS & PRODUCTS FOR DYNAMIC DROPDOWN
// ==========================================
$brand_query = "SELECT DISTINCT brand_name FROM products ORDER BY brand_name ASC";
$brand_result = $conn->query($brand_query);

$all_products_query = "SELECT id, brand_name, tank_size FROM products ORDER BY tank_size ASC";
$all_products_result = $conn->query($all_products_query);
$products_array = [];
while ($row = $all_products_result->fetch_assoc()) {
    $products_array[] = $row;
}

// ===================================================
// ACTION: UPDATE ITEM PRICE
// ===================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_price'])) {
    $target_b_id = $_POST['modal_branch_id'];
    $target_p_id = $_POST['modal_product_id'];
    $new_price   = $_POST['price'];

    // Update price directly in the inventory table
    $update_price_sql = "UPDATE inventory SET price = '$new_price' WHERE branch_id = '$target_b_id' AND product_id = '$target_p_id'";
    
    if ($conn->query($update_price_sql)) {
        $success_message = "Price updated successfully!";
        
        // TRIGGER THE AUDIT LOG
        logAction($conn, $user_id, $target_b_id, "UPDATE PRICE", "Updated branch price of Product ID: $target_p_id to $new_price");
    } else {
        $error_message = "Failed to update price: " . $conn->error;
    }
}

// ==========================================
// ACTION 1: SOFT DELETE ITEM (Admin Only)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_completely'])) {
    if ($user_role == 'Admin') {
        $remove_b_id = $_POST['modal_branch_id'];
        $remove_p_id = $_POST['modal_product_id'];

        // Archive the item instead of permanently deleting it
        $delete_sql = "UPDATE inventory SET status='Archived' WHERE branch_id='$remove_b_id' AND product_id='$remove_p_id'";
        $conn->query($delete_sql);
        $success_message = "Item successfully archived and removed from view!";

        // TRIGGER THE AUDIT LOG
        logAction($conn, $user_id, $remove_b_id, "ARCHIVE ITEM", "Archived Product ID: $remove_p_id to preserve old sales records.");
    } else {
        $success_message = "Security Alert: Only Admins can archive items.";
    }
}

// ==========================================
// ACTION 2: DEDUCT SPECIFIC STOCK (Admin & Manager)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_deduct_stock']) && $user_role != 'Employee') {
    $b_id = $_POST['modal_branch_id'];
    $p_id = $_POST['modal_product_id'];
    
    $rem_full = !empty($_POST['remove_full_tanks']) ? (int)$_POST['remove_full_tanks'] : 0;
    $rem_empty = !empty($_POST['remove_empty_tanks']) ? (int)$_POST['remove_empty_tanks'] : 0;

    // Update the database first
    $update_sql = "UPDATE inventory
                SET full_tanks = GREATEST(0, full_tanks - $rem_full),
                    empty_tanks = GREATEST(0, empty_tanks - $rem_empty)
                WHERE branch_id='$b_id' AND product_id='$p_id'";
    $conn->query($update_sql);
    
    $success_message = "Stock amounts successfully deducted!";

    // TRIGGER THE AUDIT LOG
    logAction($conn, $user_id, $b_id, "DEDUCT STOCK", "Deducted $rem_full full tanks and $rem_empty empty tanks for Product ID: $p_id");

    // CHECK FOR LOW STOCK & SEND EMAIL ALERT
    $check_stock_sql = "SELECT i.full_tanks, p.brand_name, p.tank_size, b.branch_name
                        FROM inventory i
                        JOIN products p ON i.product_id = p.id
                        JOIN branches b ON i.branch_id = b.id
                        WHERE i.branch_id='$b_id' AND i.product_id='$p_id'";
    $stock_res = $conn->query($check_stock_sql);
    
    if ($stock_res && $stock_res->num_rows > 0) {
        $stock_data = $stock_res->fetch_assoc();
        
        if ($stock_data['full_tanks'] <= 3) {
            $email_sql = "SELECT email, username FROM users
                        WHERE email IS NOT NULL AND email != ''
                        AND (role = 'Admin' OR (role = 'Manager' AND branch_id = '$b_id'))";
            $email_res = $conn->query($email_sql);
            
            if ($email_res && $email_res->num_rows > 0) {
                require 'PHPMailer/src/Exception.php';
                require 'PHPMailer/src/PHPMailer.php';
                require 'PHPMailer/src/SMTP.php';
                
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                
                try {
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'seren052001@gmail.com';
                    $mail->Password   = 'qnuyhososujisges';
                    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = 587;
                    
                    $mail->SMTPOptions = array(
                        'ssl' => array(
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true
                        )
                    );
                    
                    $mail->setFrom('seren052001@gmail.com', 'IJE LPG Alerts');
                    
                    while ($user = $email_res->fetch_assoc()) {
                        $mail->addAddress($user['email'], $user['username']);
                    }
                    
                    $mail->isHTML(true);
                    $mail->Subject = 'URGENT: Low Stock Alert - ' . $stock_data['branch_name'];
                    $mail->Body    = "
                        <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; max-width: 500px;'>
                            <h2 style='color: #e53e3e; margin-top: 0;'>⚠️ Low Stock Warning</h2>
                            <p>Please be advised that inventory has dropped to critical levels.</p>
                            <table style='width: 100%; border-collapse: collapse; margin-top: 15px;'>
                                <tr>
                                    <td style='padding: 8px 0; border-bottom: 1px solid #edf2f7;'><strong>Branch:</strong></td>
                                    <td style='padding: 8px 0; border-bottom: 1px solid #edf2f7;'>{$stock_data['branch_name']}</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; border-bottom: 1px solid #edf2f7;'><strong>LPG Item:</strong></td>
                                    <td style='padding: 8px 0; border-bottom: 1px solid #edf2f7;'>{$stock_data['brand_name']} {$stock_data['tank_size']}</td>
                                </tr>
                                <tr>
                                    <td style='padding: 8px 0; border-bottom: 1px solid #edf2f7;'><strong>Current Full Tanks:</strong></td>
                                    <td style='padding: 8px 0; border-bottom: 1px solid #edf2f7; color: #e53e3e; font-weight: bold; font-size: 18px;'>{$stock_data['full_tanks']}</td>
                                </tr>
                            </table>
                            <p style='margin-top: 20px; font-size: 12px; color: #718096;'>This is an automated message from the IJE LPG System. Please restock immediately.</p>
                        </div>
                    ";
                    
                    $mail->send();
                    $success_message = "Stock deducted! Low stock automated email was sent to management.";
                } catch (Exception $e) {
                    $success_message = "Stock deducted, but email failed to send. Error: {$mail->ErrorInfo}";
                }
            }
        }
    }
}

// ==========================================
// ACTION 3: UPDATE EXISTING STOCK (Admin & Manager)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_stock_update']) && $user_role != 'Employee') {
    $b_id = $_POST['modal_branch_id'];
    $p_id = $_POST['modal_product_id'];
    
    $add_full = !empty($_POST['add_full_tanks']) ? (int)$_POST['add_full_tanks'] : 0;
    $add_empty = !empty($_POST['add_empty_tanks']) ? (int)$_POST['add_empty_tanks'] : 0;

    $update_sql = "UPDATE inventory
                SET full_tanks = full_tanks + $add_full,
                    empty_tanks = empty_tanks + $add_empty
                WHERE branch_id='$b_id' AND product_id='$p_id'";
    $conn->query($update_sql);
    $success_message = "Inventory successfully updated!";

    // TRIGGER THE AUDIT LOG
    logAction($conn, $user_id, $b_id, "UPDATE STOCK", "Added $add_full full tanks and $add_empty empty tanks for Product ID: $p_id");
}

// ==========================================
// ACTION 4: ASSIGN BRAND NEW ITEM (WITH IMAGE & BRAND FIX)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['assign_new_stock']) && $user_role != 'Employee') {
    
    if ($user_role == 'Admin') {
        $new_b_id = $_POST['new_branch_id'];
    } else {
        $new_b_id = $user_branch_id;
    }

    $new_full = !empty($_POST['new_full_tanks']) ? (int)$_POST['new_full_tanks'] : 0;
    $new_empty = !empty($_POST['new_empty_tanks']) ? (int)$_POST['new_empty_tanks'] : 0;
    $new_price = !empty($_POST['new_price']) ? (float)$_POST['new_price'] : 0.00;

    // --- Process Image Upload ---
    $uploaded_image_filename = null;
    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
        $file_tmp  = $_FILES['product_image']['tmp_name'];
        $orig_name = basename($_FILES['product_image']['name']);
        $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
        $allowed   = ['jpg', 'jpeg', 'png', 'webp'];

        if (in_array($ext, $allowed)) {
            $uploaded_image_filename = time() . '_' . preg_replace("/[^a-zA-Z0-9_\.-]/", "_", $orig_name);
            $target_dir = "img/products/";

            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }

            move_uploaded_file($file_tmp, $target_dir . $uploaded_image_filename);
        }
    }

    // --- Brand & Product Assignment Logic ---
    $selected_brand_post = isset($_POST['new_brand_name']) ? $_POST['new_brand_name'] : '';
    $selected_prod_post  = isset($_POST['new_product_id']) ? $_POST['new_product_id'] : '';

    if ($selected_brand_post === '__NEW__' || $selected_prod_post === '__NEW_SIZE__') {
        
        // Determine Brand Name (Check for custom text box input)
        if ($selected_brand_post === '__NEW__') {
            $brand = !empty($_POST['custom_brand_input']) ? trim($_POST['custom_brand_input']) : 'New Brand';
        } else {
            $brand = $selected_brand_post;
        }

        // Determine Tank Size
        $size = !empty($_POST['new_tank_size']) ? trim($_POST['new_tank_size']) : 'Standard';

        // Insert new product record
        if ($uploaded_image_filename) {
            $stmt = $conn->prepare("INSERT INTO products (brand_name, tank_size, image) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $brand, $size, $uploaded_image_filename);
            $stmt->execute();
            $new_p_id = $stmt->insert_id;
            $stmt->close();
        } else {
            $stmt = $conn->prepare("INSERT INTO products (brand_name, tank_size) VALUES (?, ?)");
            $stmt->bind_param("ss", $brand, $size);
            $stmt->execute();
            $new_p_id = $stmt->insert_id;
            $stmt->close();
        }
    } else {
        // Existing product selected from dropdown
        $new_p_id = $selected_prod_post;

        // Update image if a file was uploaded for an existing product
        if ($uploaded_image_filename && !empty($new_p_id)) {
            $stmt = $conn->prepare("UPDATE products SET image = ? WHERE id = ?");
            $stmt->bind_param("si", $uploaded_image_filename, $new_p_id);
            $stmt->execute();
            $stmt->close();
        }
    }

    if (!empty($new_p_id)) {
        $check_sql = "SELECT * FROM inventory WHERE branch_id='$new_b_id' AND product_id='$new_p_id'";
        $check_result = $conn->query($check_sql);

        if ($check_result->num_rows == 0) {
            $insert_sql = "INSERT INTO inventory (branch_id, product_id, full_tanks, empty_tanks, price, status)
                        VALUES ('$new_b_id', '$new_p_id', '$new_full', '$new_empty', '$new_price', 'Active')";
            $conn->query($insert_sql);
            
            logAction($conn, $user_id, $new_b_id, "ASSIGN ITEM", "Assigned Product ID: $new_p_id with $new_full full tanks at ₱" . number_format($new_price, 2));
            $success_message = "Item successfully registered and assigned to branch!";
            $active_branch_id = $new_b_id;
        } else {
            $existing_row = $check_result->fetch_assoc();
            if (isset($existing_row['status']) && $existing_row['status'] == 'Archived') {
                $restore_sql = "UPDATE inventory SET status='Active', full_tanks='$new_full', empty_tanks='$new_empty', price='$new_price' WHERE branch_id='$new_b_id' AND product_id='$new_p_id'";
                $conn->query($restore_sql);
                
                logAction($conn, $user_id, $new_b_id, "RESTORE ITEM", "Restored Archived Product ID: $new_p_id");
                $success_message = "Item was previously archived. It has been successfully restored!";
                $active_branch_id = $new_b_id;
            } else {
                $success_message = "Error: This branch already has this item active. Use the Update button instead.";
            }
        }
    } else {
        $success_message = "Error: Please select an existing product or type a new one.";
    }
}

// ==========================================
// ACTION 5: UPDATE ITEM PRICE (Branch Specific)
// ==========================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && (isset($_POST['submit_edit_price']) || isset($_POST['update_price']))) {
    $b_id = !empty($_POST['price_form_branch_id']) ? $_POST['price_form_branch_id'] : ($_POST['modal_branch_id'] ?? $active_branch_id);
    $p_id = !empty($_POST['price_form_product_id']) ? $_POST['price_form_product_id'] : ($_POST['modal_product_id'] ?? null);
    
    $new_price = 0.00;
    if (isset($_POST['edit_new_price']) && $_POST['edit_new_price'] !== '') {
        $new_price = (float)$_POST['edit_new_price'];
    } elseif (isset($_POST['price']) && $_POST['price'] !== '') {
        $new_price = (float)$_POST['price'];
    }

    if ($user_role == 'Manager' && $b_id != $user_branch_id) {
        $success_message = "Security Alert: You can only edit prices for your assigned branch.";
    } elseif (!empty($p_id) && !empty($b_id)) {
        $update_price_sql = "UPDATE inventory SET price = '$new_price' WHERE branch_id = '$b_id' AND product_id = '$p_id'";
        
        if ($conn->query($update_price_sql)) {
            $success_message = "Product price successfully updated for this branch!";
            $active_branch_id = $b_id;
            logAction($conn, $user_id, $b_id, "EDIT PRICE", "Updated branch price of Product ID: $p_id to ₱" . number_format($new_price, 2));
        } else {
            $success_message = "Error updating price: " . $conn->error;
        }
    } else {
        $success_message = "Error: Missing product or branch information.";
    }
}

// ==========================================
// FETCH INVENTORY & ALERTS
// ==========================================
$low_stock_items = [];
$stock_items = [];
$branch_name_display = "";

$branches_with_alerts = [];
if ($user_role == 'Admin' && $active_branch_id == null) {
    $alert_sql = "SELECT DISTINCT branch_id FROM inventory WHERE full_tanks <= 3 AND status = 'Active'";
    $alert_result = $conn->query($alert_sql);
    if ($alert_result && $alert_result->num_rows > 0) {
        while($alert_row = $alert_result->fetch_assoc()) {
            $branches_with_alerts[] = $alert_row['branch_id'];
        }
    }
}

if ($active_branch_id != null) {
    $b_name_sql = "SELECT branch_name FROM branches WHERE id = '$active_branch_id'";
    $b_name_res = $conn->query($b_name_sql);
    if ($b_name_res->num_rows > 0) {
        $branch_name_display = $b_name_res->fetch_assoc()['branch_name'];
    }

    $sql = "SELECT b.id AS branch_id, b.branch_name, p.id AS product_id, p.brand_name, p.tank_size, i.price,
                i.full_tanks, i.empty_tanks
            FROM inventory i
            JOIN branches b ON i.branch_id = b.id
            JOIN products p ON i.product_id = p.id
            WHERE b.id = '$active_branch_id' AND i.status = 'Active'
            ORDER BY p.brand_name ASC";
            
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            if ($row['full_tanks'] <= 3) {
                $low_stock_items[] = $row;
            } else {
                $stock_items[] = $row;
            }
        }
    }
}

$branches_dropdown = $conn->query("SELECT id, branch_name FROM branches ORDER BY is_main_branch DESC, branch_name ASC");
$products_dropdown = $conn->query("SELECT id, brand_name, tank_size FROM products ORDER BY brand_name ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Inventory</title>
    <link rel="icon" type="image/png" href="img/logo no bg.png">
    <link rel="stylesheet" href="css/inventory.css">
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
                <a href="inventory.php" class="active">
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
                <button id="sidebarToggle" type="button" aria-label="Toggle Sidebar" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #2c3e50; display: none; align-items: center; justify-content: center; padding: 0;">
                    &#9776;
                </button>
                <h1 style="margin: 0;">Current Stock Levels</h1>
            </div>
                <div class="user-dropdown">
                    <span>User: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                    <?php include 'header-notifications.php'; ?>
                </div>
        </div>

        <?php if ($success_message != ""): ?>
            <div class="alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <?php if ($user_role == 'Admin' && $active_branch_id == null): ?>
            <h2>Please Select a Branch to View Inventory:</h2>
            
            <div style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 20px;">
                <?php
                $branches_dropdown->data_seek(0);
                while($b = $branches_dropdown->fetch_assoc()):
                    $has_alert = in_array($b['id'], $branches_with_alerts);
                    $border_color = $has_alert ? "#e74c3c" : "#3498db";
                ?>
                    <a href="inventory.php?branch=<?php echo $b['id']; ?>" style="position: relative; display: inline-block; padding: 20px 30px; background-color: #ffffff; color: #2c3e50; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-left: 6px solid <?php echo $border_color; ?>; min-width: 200px; text-align: center;">
                        <?php echo $b['branch_name']; ?>
                        
                        <?php if ($has_alert): ?>
                            <span style="position: absolute; top: -8px; right: -8px; height: 18px; width: 18px; background-color: #e74c3c; border-radius: 50%; border: 3px solid #f0f2f5; box-shadow: 0 2px 4px rgba(0,0,0,0.2);" title="Low Stock Alert!"></span>
                        <?php endif; ?>
                    </a>
                <?php endwhile; ?>
            </div>

            <div style="margin-top: 40px; border-top: 1px solid #ddd; padding-top: 20px;">
                <button onclick="openAssignModal()" class="btn-update" style="background-color: #2c3e50; padding: 12px 20px; font-size: 15px; font-weight: bold;">
                    + Assign New Item to a Branch
                </button>
            </div>

        <?php else: ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
                <h2 style="color: #1abc9c;">Inventory: <?php echo $branch_name_display; ?>
                    <?php if($user_role == 'Admin') echo "<a href='inventory.php' style='font-size: 14px; margin-left: 15px; color: #e74c3c; text-decoration: none;'>[← Back to All Branches]</a>"; ?>
                </h2>
                
                <?php if ($user_role != 'Employee'): ?>
                    <button onclick="openAssignModal()" class="btn-update" style="background-color: #2c3e50; padding: 12px 20px; font-size: 15px; font-weight: bold;">
                        + Assign New Item
                    </button>
                <?php endif; ?>
            </div>

            <?php if (count($low_stock_items) > 0): ?>
                <div class="table-container" style="border-top: 5px solid #e74c3c; margin-bottom: 30px;">
                    <h2 style="margin-bottom: 20px; color: #e74c3c;"><img src="img/issues.png"> Action Required: Low Stock Alerts</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>LPG Brand & Size</th>
                                <th>Selling Price</th>
                                <th>Full Tanks</th>
                                <th>Empty Tanks</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($low_stock_items as $item): ?>
                                <tr data-brand="<?php echo htmlspecialchars($item['brand_name']); ?>">
                                    <td><strong><?php echo $item['brand_name'] . " " . $item['tank_size']; ?></strong></td>
                                    <td style="color: #27ae60; font-weight: bold;">₱<?php echo number_format($item['price'], 2); ?></td>
                                    <td><span class='badge-danger'><img src="img/issues.png"> <?php echo $item['full_tanks']; ?> Tanks</span></td>
                                    <td><span class='badge-empty'><?php echo $item['empty_tanks']; ?> Tanks</span></td>
                                    <td>
                                        <?php if ($user_role != 'Employee'): ?>
                                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                                <button onclick="openUpdateModal(<?php echo $item['branch_id'] . ", " . $item['product_id'] . ", '" . addslashes($item['branch_name']) . "', '" . addslashes($item['brand_name'] . " " . $item['tank_size']) . "'"; ?>)" class='btn-update'>+ Stock</button>
                                                <button onclick="openEditPriceModal('<?php echo $item['branch_id']; ?>', '<?php echo $item['product_id']; ?>', '<?php echo addslashes($item['brand_name'] . ' ' . $item['tank_size']); ?>', '<?php echo $item['price']; ?>')" class="btn-edit-price">Edit Price</button>
                                                <button onclick="openRemoveModal(<?php echo $item['branch_id'] . ", " . $item['product_id'] . ", '" . addslashes($item['branch_name']) . "', '" . addslashes($item['brand_name'] . " " . $item['tank_size']) . "'"; ?>)" class='btn-remove-stock'>Remove</button>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #7f8c8d; font-size: 12px; font-style: italic;">View Only</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="table-container">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0;">Stock Overview</h2>
        
                    <!-- BRAND FILTER DROPDOWN -->
                    <div>
                        <label style="font-weight: bold; margin-right: 8px; font-size: 14px; color: #2c3e50;">Filter by Brand:</label>
                        <select id="brandFilterSelect" onchange="filterTableByBrand()" style="padding: 6px 12px; border-radius: 4px; border: 1px solid #ccc; background-color: #fff; font-size: 14px;">
                            <option value="ALL">-- All Brands --</option>
                            <?php 
                            $filter_brands = $conn->query("SELECT DISTINCT brand_name FROM products ORDER BY brand_name ASC");
                            while ($fb = $filter_brands->fetch_assoc()) {
                                echo "<option value='" . htmlspecialchars($fb['brand_name']) . "'>" . htmlspecialchars($fb['brand_name']) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>LPG Brand & Size</th>
                            <th>Selling Price</th>
                            <th>Full Tanks</th>
                            <th>Empty Tanks</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($stock_items) > 0): ?>
                            <?php foreach ($stock_items as $item): ?>
                                <tr data-brand="<?php echo htmlspecialchars($item['brand_name']); ?>">
                                    <td><strong><?php echo $item['brand_name'] . " " . $item['tank_size']; ?></strong></td>
                                    <td style="color: #27ae60; font-weight: bold;">₱<?php echo number_format($item['price'], 2); ?></td>
                                    <td><span class='badge-full'><?php echo $item['full_tanks']; ?> Tanks</span></td>
                                    <td><span class='badge-empty'><?php echo $item['empty_tanks']; ?> Tanks</span></td>
                                    <td>
                                        <?php if ($user_role != 'Employee'): ?>
                                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                                <button onclick="openUpdateModal(<?php echo $item['branch_id'] . ", " . $item['product_id'] . ", '" . addslashes($item['branch_name']) . "', '" . addslashes($item['brand_name'] . " " . $item['tank_size']) . "'"; ?>)" class='btn-update'>Update Stock</button>
                                                <button onclick="openEditPriceModal('<?php echo $item['branch_id']; ?>', '<?php echo $item['product_id']; ?>', '<?php echo addslashes($item['brand_name'] . ' ' . $item['tank_size']); ?>', '<?php echo $item['price']; ?>')" class="btn-edit-price">Edit Price</button>
                                                <button onclick="openRemoveModal(<?php echo $item['branch_id'] . ", " . $item['product_id'] . ", '" . addslashes($item['branch_name']) . "', '" . addslashes($item['brand_name'] . " " . $item['tank_size']) . "'"; ?>)" class='btn-remove-stock'>Remove</button>
                                            </div>
                                        <?php else: ?>
                                            <span style="color: #7f8c8d; font-size: 12px; font-style: italic;">View Only</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan='5' style='text-align: center; padding: 20px;'>No stock registered for this branch yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div id="updateModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeUpdateModal()">&times;</span>
            <h2 style="color: #2c3e50; margin-bottom: 10px;">Update Inventory</h2>
            <p id="modal-display-text" style="color: #666; margin-bottom: 20px; font-size: 14px; border-bottom: 1px solid #eee; padding-bottom: 10px;"></p>
            
            <form action="inventory.php<?php echo ($active_branch_id) ? '?branch='.$active_branch_id : ''; ?>" method="POST">
                <input type="hidden" name="modal_branch_id" id="form_branch_id">
                <input type="hidden" name="modal_product_id" id="form_product_id">
                <div class="form-group">
                    <label>Add Full Tanks (From Supplier):</label>
                    <input type="number" name="add_full_tanks" min="1" max="50" placeholder="Leave blank if 0" oninput="limitStockInput(this)">
                </div>
                <div class="form-group">
                    <label>Add Empty Tanks (Donated / Extra):</label>
                    <input type="number" name="add_empty_tanks" min="1" max="50" placeholder="Leave blank if 0" oninput="limitStockInput(this)">
                </div>
                <button type="submit" name="submit_stock_update" class="btn-save">Save Updated Stock</button>
            </form>
        </div>
    </div>

    <!-- ASSIGN ITEM MODAL -->
    <div id="assignModal" class="modal">
        <div class="modal-content" style="border-top-color: #2c3e50; width: 450px;">
            <span class="close-btn" onclick="closeAssignModal()">&times;</span>
            <h2 style="color: #2c3e50; margin-bottom: 20px;">Assign Item to Branch</h2>
            
            <form action="inventory.php<?php echo ($active_branch_id) ? '?branch='.$active_branch_id : ''; ?>" method="POST" enctype="multipart/form-data">
                
                <div class="form-group">
                    <label>Select Branch Location:</label>
                    <?php if ($user_role == 'Admin'): ?>
                        <select name="new_branch_id" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ccc;" required>
                            <?php
                            $branches_dropdown->data_seek(0);
                            while($b = $branches_dropdown->fetch_assoc()) {
                                $selected = ($b['id'] == $active_branch_id) ? "selected" : "";
                                echo "<option value='".$b['id']."' $selected>".$b['branch_name']."</option>";
                            } ?>
                        </select>
                    <?php else: ?>
                        <div style="padding: 10px; background-color: #f1f5f9; border: 1px solid #ccc; border-radius: 4px; color: #7f8c8d; font-weight: bold;">
                            🔒 <?php echo $branch_name_display; ?>
                        </div>
                    <?php endif; ?>
                </div>

            <!-- BRAND DROPDOWN -->
            <div class="form-group">
                <label>LPG Brand Name:</label>
                <select id="select_brand" name="new_brand_name" onchange="updateTankSizes()" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ccc;" required>
                    <option value="" disabled selected>-- Select Brand --</option>
                    <?php 
                    $brand_result->data_seek(0);
                    while ($b_row = $brand_result->fetch_assoc()): 
                    ?>
                        <option value="<?php echo htmlspecialchars($b_row['brand_name']); ?>">
                            <?php echo htmlspecialchars($b_row['brand_name']); ?>
                        </option>
                    <?php endwhile; ?>
                    <option value="__NEW__">+ Add New Brand</option>
                </select>
            </div>

            <!-- CUSTOM BRAND INPUT (Hidden by default) -->
            <div class="form-group" id="custom_brand_group" style="display: none;">
                <label style="color: #1abc9c;">Enter New Brand Name:</label>
                <input type="text" id="custom_brand_name" name="custom_brand_input" placeholder="e.g. Petron Gasul">
            </div>

            <!-- TANK SIZE DROPDOWN (Dynamically Populated) -->
            <div class="form-group">
                <label>Tank Size / Variant:</label>
                <select id="select_product_id" name="new_product_id" style="width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #ccc;" required>
                    <option value="" disabled selected>-- Select Brand First --</option>
                </select>
            </div>

            <!-- CUSTOM SIZE INPUT (Hidden by default) -->
            <div class="form-group" id="custom_size_group" style="display: none;">
                <label style="color: #1abc9c;">Enter New Tank Size:</label>
                <input type="text" name="new_tank_size" placeholder="e.g. 20kg">
            </div>

            <!-- PRODUCT IMAGE UPLOAD -->
            <div class="form-group">
                <label style="color: #1abc9c;">Product Image (Optional):</label>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <input type="file" id="product_image_input" name="product_image" accept="image/png, image/jpeg, image/jpg, image/webp" style="flex: 1; padding: 8px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; background-color: #fff;">
                    <button type="button" onclick="clearImageInput()" style="background-color: #e74c3c; color: white; border: none; padding: 8px 12px; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: bold;">Remove</button>
                </div>
            </div>

            <!-- SELLING PRICE -->
            <div class="form-group">
                <label style="color: #1abc9c;">Selling Price (₱):</label>
                <input type="number" name="new_price" step="0.01" min="1" max="10000" placeholder="0.00" oninput="limitPriceInput(this)" required>
            </div>
                <hr style="border: 0; border-top: 1px solid #eee; margin: 15px 0;">
                <div style="display: flex; gap: 10px;">
                    <div class="form-group" style="flex: 1;">
                        <label>Starting Full Tanks:</label>
                        <input type="number" name="new_full_tanks" min="1" max="50" placeholder="0">
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>Starting Empty Tanks:</label>
                        <input type="number" name="new_empty_tanks" min="1" max="50" placeholder="0">
                    </div>
                </div>
                <button type="submit" name="assign_new_stock" class="btn-save" style="background-color: #2c3e50;">Assign Stock</button>
            </form>
        </div>
    </div>

    <div id="removeModal" class="modal">
        <div class="modal-content" style="border-top-color: #e74c3c;">
            <span class="close-btn" onclick="closeRemoveModal()">&times;</span>
            <h2 style="color: #e74c3c; margin-bottom: 10px;">Deduct Stock</h2>
            <p id="remove-modal-display-text" style="color: #666; margin-bottom: 20px; font-size: 14px; border-bottom: 1px solid #eee; padding-bottom: 10px;"></p>
            
            <form action="inventory.php<?php echo ($active_branch_id) ? '?branch='.$active_branch_id : ''; ?>" method="POST">
                <input type="hidden" name="modal_branch_id" id="remove_form_branch_id">
                <input type="hidden" name="modal_product_id" id="remove_form_product_id">
                <div class="form-group">
                    <label>Remove Full Tanks (Missing or Damaged):</label>
                    <input type="number" name="remove_full_tanks" min="1" max="50" placeholder="Leave blank if 0" oninput="limitStockInput(this)">
                </div>
                <div class="form-group">
                    <label>Remove Empty Tanks (Supplier truck took them):</label>
                    <input type="number" name="remove_empty_tanks" min="1" max="50" placeholder="Leave blank if 0" oninput="limitStockInput(this)">
                </div>
                <button type="submit" name="submit_deduct_stock" class="btn-save" style="background-color: #e74c3c; margin-bottom: 15px;">Deduct Stock Quantities</button>
            </form>

            <?php if ($user_role == 'Admin'): ?>
                <form action="inventory.php<?php echo ($active_branch_id) ? '?branch='.$active_branch_id : ''; ?>" method="POST" style="text-align: center;" onsubmit="return confirmCompleteDelete();">
                    <input type="hidden" name="modal_branch_id" id="delete_form_branch_id">
                    <input type="hidden" name="modal_product_id" id="delete_form_product_id">
                    
                    <button type="submit" name="delete_completely" style="background: none; border: none; color: #e74c3c; text-decoration: underline; cursor: pointer; font-size: 13px; font-weight: bold; margin-top: 10px;">
                        Archive this item
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div id="editPriceModal" class="modal">
        <div class="modal-content" style="border-top-color: #f39c12; width: 400px;">
            <span class="close-btn" onclick="closeEditPriceModal()">&times;</span>
            <h2 style="color: #f39c12; margin-bottom: 10px;">Edit Selling Price</h2>
            <p id="price-modal-display-text" style="color: #666; margin-bottom: 20px; font-size: 14px; border-bottom: 1px solid #eee; padding-bottom: 10px;"></p>
            
            <form action="inventory.php<?php echo ($active_branch_id) ? '?branch='.$active_branch_id : ''; ?>" method="POST">

            <input type="hidden" name="price_form_branch_id" id="price_form_branch_id">
            <input type="hidden" name="price_form_product_id" id="price_form_product_id">
            
            <div class="form-group">
                <label>New Selling Price (₱):</label>
                <input type="number" step="0.01" name="edit_new_price" id="edit_new_price" min="1" max="10000" oninput="limitPriceInput(this)" required>
            </div>
                
                <button type="submit" name="submit_edit_price" class="btn-save" style="background-color: #f39c12; margin-bottom: 15px;">Save New Price</button>
            </form>
        </div>
    </div>

    <!-- Pass database products array into global window JS variable -->
    <script>
        window.allProducts = <?php echo json_encode($products_array); ?>;
    </script>
    <script src="js/inventory.js?v=3"></script>
    <script src="js/mobile.js?v=6"></script>
    
</body>
</html>