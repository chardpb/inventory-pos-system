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

// LOAD PHPMAILER FOR THE POS SYSTEM
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

$success_message = "";
$user_role = $_SESSION['role'];
$user_branch_id = $_SESSION['branch_id'];

// 1. CHECK PAYMENT SUBMISSION
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['cart_data'])) {
    $current_username = $_SESSION['username'];
    $user_sql = "SELECT id, branch_id FROM users WHERE username = '$current_username'";
    $user_result = $conn->query($user_sql);
    $user_data = $user_result->fetch_assoc();
    
    $user_id = $user_data['id'];
    $sale_branch_id = $_POST['selected_branch_id'];

    $cart_items = json_decode($_POST['cart_data'], true);

    if (!empty($cart_items)) {
        foreach ($cart_items as $item) {
            $product_id = $item['id'];
            $qty = $item['qty'];
            $total_price = $item['total'];
            
            // Grab the transaction type from the cart (Swap vs New)
            $type = isset($item['type']) ? $item['type'] : 'swap';
            
            // Save to reports including sale_type
            $insert_sql = "INSERT INTO daily_sales (branch_id, user_id, product_id, quantity, total_price, sale_type)
                        VALUES ('$sale_branch_id', '$user_id', '$product_id', '$qty', '$total_price', '$type')";
            $conn->query($insert_sql);
            // TRIGGER THE AUDIT LOG!
            logAction($conn, $user_id, $sale_branch_id, "SALE", "Sold $qty units of Product ID $product_id for ₱$total_price");

            // ==========================================
            // SMART INVENTORY MATH
            // ==========================================
            if ($type == 'swap') {
                // SWAP REFILL: Store loses a full tank, but gains an empty tank from the customer
                $update_inv = "UPDATE inventory
                            SET full_tanks = GREATEST(0, full_tanks - $qty),
                                empty_tanks = empty_tanks + $qty
                            WHERE branch_id = '$sale_branch_id' AND product_id = '$product_id'";
            } else {
                // BUY NEW TANK: Store just loses a full tank. No empty tank is returned.
                $update_inv = "UPDATE inventory
                            SET full_tanks = GREATEST(0, full_tanks - $qty)
                            WHERE branch_id = '$sale_branch_id' AND product_id = '$product_id'";
            }
            $conn->query($update_inv);
            
            // ==========================================
            // POS EMAIL TRIGGER: CHECK FOR LOW STOCK
            // ==========================================
            $check_stock_sql = "SELECT i.full_tanks, p.brand_name, p.tank_size, b.branch_name
                                FROM inventory i
                                JOIN products p ON i.product_id = p.id
                                JOIN branches b ON i.branch_id = b.id
                                WHERE i.branch_id='$sale_branch_id' AND i.product_id='$product_id'";
            $stock_res = $conn->query($check_stock_sql);
            
            if ($stock_res && $stock_res->num_rows > 0) {
                $stock_data = $stock_res->fetch_assoc();
                
                // If stock drops to 3 or below during this sale, trigger the email!
                if ($stock_data['full_tanks'] <= 3) {
                    
                    // Find Admin and Manager emails
                    $email_sql = "SELECT email, username FROM users
                                WHERE email IS NOT NULL AND email != ''
                                AND (role = 'Admin' OR (role = 'Manager' AND branch_id = '$sale_branch_id'))";
                    $email_res = $conn->query($email_sql);
                    
                    if ($email_res && $email_res->num_rows > 0) {
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
                            $mail->Subject = 'URGENT: Low Stock Alert (From POS) - ' . $stock_data['branch_name'];
                            $mail->Body    = "
                                <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px; max-width: 500px;'>
                                    <h2 style='color: #e53e3e; margin-top: 0;'>⚠️ Low Stock Warning</h2>
                                    <p>A recent sale has caused inventory to drop to critical levels.</p>
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
                                </div>
                            ";
                            $mail->send();
                        } catch (Exception $e) {
                            // Fails silently if internet is down, so it doesn't break the POS
                        }
                    }
                }
            }
        }
        $success_message = "Payment successful!.";
    }
}

// 2. DETERMINE WHICH BRANCH TO SHOW
$active_branch_id = null;

if ($user_role == 'Admin') {
    if (isset($_GET['branch'])) {
        $active_branch_id = $_GET['branch'];
    }
} else {
    $active_branch_id = $user_branch_id;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Items</title>
    <link rel="icon" type="image/png" href="img/logo no bg.png">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/items.css">
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
                <a href="items.php" class="active">
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
                <button id="sidebarToggle" type="button" aria-label="Toggle Sidebar" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #2c3e50; display: none; align-items: center; justify-content: center; padding: 0;">
                    &#9776;
                </button>
                <h1 style="margin: 0;">Point of Sale</h1>
            </div>
                <div class="user-dropdown">
                    <span>User: <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong> (<?php echo htmlspecialchars($_SESSION['role']); ?>)</span>
                    <?php include 'header-notifications.php'; ?>
                </div>
        </div>

        <?php if ($success_message != ""): ?>
            <div class="alert-success" style="padding: 15px; margin-bottom: 20px; background-color: #d4edda; color: #155724; border-radius: 4px; font-weight: bold; text-align: center;"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <?php if ($active_branch_id == null):
            $branches_sql = "SELECT * FROM branches ORDER BY is_main_branch DESC, branch_name ASC";
            $branches_result = $conn->query($branches_sql);
        ?>
            <h2>Please Select a Branch to Open:</h2>
            <div class="branch-select-grid">
                <?php while($b = $branches_result->fetch_assoc()): ?>
                    <a href="items.php?branch=<?php echo $b['id']; ?>" class="branch-btn">
                         <?php echo $b['branch_name']; ?>
                    </a>
                <?php endwhile; ?>
            </div>

        <?php else:
            $b_name_sql = "SELECT branch_name FROM branches WHERE id = '$active_branch_id'";
            $b_name_res = $conn->query($b_name_sql);
            $branch_name = $b_name_res->fetch_assoc()['branch_name'];

            $product_sql = "SELECT p.id, p.brand_name, p.tank_size, p.image, i.price, i.full_tanks
                            FROM inventory i
                            JOIN products p ON i.product_id = p.id
                            WHERE i.branch_id = '$active_branch_id' AND i.status = 'Active'
                            ORDER BY p.brand_name ASC";
            $product_result = $conn->query($product_sql);
        ?>
            <h3 style="margin-bottom: 15px; color: #1abc9c;">Active POS: <?php echo $branch_name; ?>
                <?php if($user_role == 'Admin') echo "<a href='items.php' style='font-size: 14px; margin-left: 15px; color: #e74c3c;'>[Change Branch]</a>"; ?>
            </h3>

            <div class="pos-container">
                <div class="product-section">
                    <div class="product-grid">
                        <?php
                        if ($product_result->num_rows > 0) {
                            while($row = $product_result->fetch_assoc()) {
                                $stock = $row['full_tanks'] !== null ? $row['full_tanks'] : 0;
                                $img_path = (!empty($row['image'])) ? 'img/products/' . $row['image'] : 'img/logo no bg.png';
                                $full_item_name = $row['brand_name'] . " " . $row['tank_size'];
                                
                                echo "<div class='product-card'>";
                                echo "<div class='product-image-container'>";
                                echo "<img src='" . htmlspecialchars($img_path) . "' alt='" . htmlspecialchars($full_item_name) . "'>";
                                echo "</div>";
                                echo "<div>";
                                echo "<div class='product-title'>" . htmlspecialchars($full_item_name) . "</div>";
                                echo "<div class='product-price'>₱" . number_format($row['price'], 2) . "</div>";
                                echo "<div class='stock-text'>Stock: <strong>" . $stock . "</strong></div>";
                                echo "</div>";
                                
                                if ($stock > 0) {
                                    echo "<button class='btn-add' onclick='addToCart(" . $row['id'] . ", \"" . addslashes($full_item_name) . "\", " . $row['price'] . ", " . $stock . ")'>Add</button>";
                                } else {
                                    echo "<button class='btn-add' style='background-color: #eee; border-color: #ccc; color: #999; cursor: not-allowed;' disabled>Out of Stock</button>";
                                }
                                echo "</div>";
                            }
                        }
                        ?>
                    </div>
                </div>

                <div class="cart-section">
                    <table class="cart-table">
                        <thead>
                            <tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th><th></th></tr>
                        </thead>
                        <tbody id="cart-body">
                            <tr><td colspan="5" style="text-align: center; color: #999;">No items in cart</td></tr>
                        </tbody>
                    </table>

                    <div class="checkout-area">
                        <div class="total-row"><span>Total:</span><span id="display-total">₱0.00</span></div>
                        
                        <form action="items.php?branch=<?php echo $active_branch_id; ?>" method="POST" id="checkout-form" onsubmit="return handlePayNowClick(event)">
                            <input type="hidden" name="cart_data" id="cart_data_input">
                            <input type="hidden" name="selected_branch_id" value="<?php echo $active_branch_id; ?>">

                            <div class="payment-group">
                                <label>Amount Tendered (Cash):</label>
                                <input type="number" id="amount_tendered" step="0.01" min="0" max="1000000" placeholder="₱0.00" oninput="limitTenderedAmout(this)" onkeyup="calculateChange()">
                            </div>
                            <div class="payment-group">
                                <label>Change:</label>
                                <input type="text" id="change_due" readonly placeholder="₱0.00" style="background-color: #f8f9fa;">
                            </div>
                            <button type="submit" class="btn-pay">PAY NOW</button>
                        </form>
                    </div>
                </div>
            </div>

        <?php endif; ?>
    </div>
    
    <!-- 1. ORDER CONFIRMATION MODAL -->
    <div id="confirmationModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="background: #fff; width: 450px; margin: 5% auto; padding: 25px; border-radius: 8px; font-family: Arial, sans-serif;">
            <h2 style="margin-top: 0; color: #2c3e50; border-bottom: 2px solid #1abc9c; padding-bottom: 10px;">Confirm Order</h2>
            
            <p style="margin-bottom: 15px; color: #555;">Please verify the items and prices before finalizing:</p>
            
            <div id="confirm-items-list" style="max-height: 200px; overflow-y: auto; border: 1px solid #eee; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
                <!-- Injected via JavaScript -->
            </div>
    
            <div style="background: #f8f9fa; padding: 10px; border-radius: 5px; margin-bottom: 15px;">
                <p style="margin: 5px 0;"><strong>Total Amount:</strong> <span id="confirm-total">₱0.00</span></p>
                <p style="margin: 5px 0;"><strong>Cash Tendered:</strong> <span id="confirm-cash">₱0.00</span></p>
                <p style="margin: 5px 0;"><strong>Change:</strong> <span id="confirm-change">₱0.00</span></p>
            </div>
    
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-weight: bold; margin-bottom: 5px;">Customer Name (Optional):</label>
                <input type="text" id="customer_name_input" placeholder="e.g. John Doe" style="width: 100%; padding: 8px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px;">
            </div>
    
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeConfirmationModal()" style="padding: 10px 15px; background: #95a5a6; color: white; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
                <button type="button" onclick="proceedToReceipt()" style="padding: 10px 20px; background: #27ae60; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Confirm & Proceed</button>
            </div>
        </div>
    </div>
    
    <!-- 2. PRINTABLE RECEIPT MODAL -->
    <div id="receiptModal" class="modal" style="display: none; position: fixed; z-index: 1001; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);">
        <div style="background: #fff; width: 380px; margin: 3% auto; padding: 20px; border-radius: 8px; font-family: 'Courier New', Courier, monospace;">
            
            <!-- Printable Area Starts Here -->
            <div id="printable-receipt-area">
                <div style="text-align: center; border-bottom: 1px dashed #000; padding-bottom: 10px; margin-bottom: 10px;">
                    <h2 style="margin: 0;">IJE LPG</h2>
                    <p style="margin: 3px 0; font-size: 13px;">Branch: <?php echo isset($branch_name) ? $branch_name : 'Main'; ?></p>
                    <p style="margin: 3px 0; font-size: 12px;">Date: <span id="receipt-date"></span></p>
                    <p style="margin: 3px 0; font-size: 12px;">Cashier: <?php echo $_SESSION['username']; ?></p>
                    <p style="margin: 3px 0; font-size: 12px;">Customer: <span id="receipt-customer">Guest</span></p>
                </div>
    
                <table style="width: 100%; font-size: 12px; text-align: left; border-collapse: collapse; margin-bottom: 10px;">
                    <thead>
                        <tr style="border-bottom: 1px solid #000;">
                            <th style="padding-bottom: 5px;">Qty</th>
                            <th style="padding-bottom: 5px;">Description</th>
                            <th style="padding-bottom: 5px; text-align: right;">Price</th>
                            <th style="padding-bottom: 5px; text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody id="receipt-body">
                        <!-- Injected via JavaScript -->
                    </tbody>
                </table>
    
                <div style="border-top: 1px dashed #000; padding-top: 8px; font-size: 12px;">
                    <div style="display: flex; justify-content: space-between;">
                        <span>VATable Sales (90%):</span>
                        <span id="receipt-vat-sales">₱0.00</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>10% VAT:</span>
                        <span id="receipt-vat-amount">₱0.00</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; font-weight: bold; font-size: 13px; margin-top: 4px; border-top: 1px solid #eee; padding-top: 4px;">
                        <span>Total Amount:</span>
                        <span id="receipt-total">₱0.00</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>Cash Tendered:</span>
                        <span id="receipt-cash">₱0.00</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>Change:</span>
                        <span id="receipt-change">₱0.00</span>
                    </div>
                </div>
    
                <div style="text-align: center; margin-top: 15px; border-top: 1px dashed #000; padding-top: 10px; font-size: 11px;">
                    <p style="margin: 0;">Thank you for your purchase!</p>
                </div>
            </div>
            <!-- Printable Area Ends Here -->
    
            <div style="display: flex; gap: 10px; margin-top: 20px; justify-content: space-between;">
                <button type="button" onclick="finalizeSaleWithoutPrint()" style="padding: 10px 15px; background: #e74c3c; color: white; border: none; border-radius: 4px; cursor: pointer;">Done (No Print)</button>
                <button type="button" onclick="printReceiptAndSubmit()" style="padding: 10px 15px; background: #2980b9; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">🖨️ Print Receipt</button>
            </div>
        </div>
    </div>
    
    <script src="js/items.js?v=2"></script>
    <script src="js/mobile.js?v=6"></script>

</body>
</html>