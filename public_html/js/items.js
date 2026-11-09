// The list where we store all cart items
let cart = [];

/**
 * Adds an item to the cart or increases quantity
 */
function addToCart(id, name, price, maxStock) {
    // Check if the item is already in the list
    let existingItem = cart.find(item => item.id === id);
    
    if (existingItem) {
        // Stop if adding more exceeds our actual stock
        if (existingItem.qty >= maxStock) {
            alert("Cannot add more! Only " + maxStock + " in stock.");
            return; 
        }
        // Increase quantity and update item total
        existingItem.qty += 1;
        existingItem.total = existingItem.qty * existingItem.price;
    } else {
        // Add a brand new item to the list
        cart.push({
            id: id,
            name: name,
            price: price,
            qty: 1,
            total: price,
            max_stock: maxStock,
            type: 'swap' 
        });
    }
    
    // Refresh the table on the screen
    updateCartUI();
}

/**
 * Deletes an item from the cart
 */
function removeFromCart(index) {
    // Remove the specific item from the array
    cart.splice(index, 1);
    updateCartUI(); 
}

/**
 * Updates quantity when typed manually
 */
function changeQty(index, newQty) {
    let item = cart[index]; 
    
    // Ensure quantity is at least 1
    if (newQty < 1) newQty = 1;
    
    // Cap the quantity at the maximum stock available
    if (newQty > item.max_stock) {
        alert("Not enough stock! Reverting to maximum available.");
        newQty = item.max_stock;
    }
    
    // Save the new number and update item total
    item.qty = parseInt(newQty); 
    item.total = item.qty * item.price;
    
    updateCartUI(); 
}

/**
 * Changes item between 'Swap Tank' or 'New Tank'
 */
function changeType(index, newType) {
    cart[index].type = newType; 
    updateCartUI(); 
}

/**
 * Updates the entire HTML table to show current cart data
 */
function updateCartUI() {
    let tbody = document.getElementById('cart-body');
    tbody.innerHTML = ''; // Clear the current table rows
    
    let grandTotal = 0; 

    // Show message if cart is empty
    if (cart.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: #999;">No items in cart</td></tr>';
    } else {
        // Generate a new row for every item in our data
        cart.forEach((item, index) => {
            grandTotal += item.total; 
            
            let row = `<tr>
                <td>
                    <strong>${item.name}</strong><br>
                    <select class="cart-type-select" onchange="changeType(${index}, this.value)">
                        <option value="swap" ${item.type === 'swap' ? 'selected' : ''}> Swap Empty Tank</option>
                        <option value="new" ${item.type === 'new' ? 'selected' : ''}> Buy New Tank</option>
                    </select>
                </td>
                <td><input type="number" class="cart-qty-input" value="${item.qty}" onchange="changeQty(${index}, this.value)"></td>
                <td>₱${item.price.toFixed(2)}</td>
                <td style="font-weight:bold;">₱${item.total.toFixed(2)}</td>
                <td><button type="button" class="btn-remove" onclick="removeFromCart(${index})">X</button></td>
            </tr>`;
            
            tbody.innerHTML += row;
        });
    }

    // Update the final total and change on screen
    document.getElementById('display-total').innerText = '₱' + grandTotal.toFixed(2);
    calculateChange();
    
    // Save data into a hidden input so PHP can process it
    document.getElementById('cart_data_input').value = JSON.stringify(cart);
}

/**
 * Calculates the change to give back to the customer
 */
function calculateChange() {
    let grandTotal = cart.reduce((sum, item) => sum + item.total, 0);
    let cashGiven = parseFloat(document.getElementById('amount_tendered').value) || 0;
    
    let change = cashGiven - grandTotal;
    
    // Display 0 if they haven't paid enough yet
    if (change < 0) change = 0;
    
    document.getElementById('change_due').value = '₱' + change.toFixed(2);
}

/**
 * Validates maximum amount limits for tendered cash input
 */
function limitTenderedAmout(element) {
    if (element.value > 1000000) {
        element.value = 1000000;
    }
}

/**
 * Checks if the sale is valid before sending to the server
 */
function validateCheckout() {
    // Fail if the cart is empty
    if (cart.length === 0) {
        alert("Cart is empty!");
        return false;
    }
    
    // Fail if the customer paid less than the total
    let grandTotal = cart.reduce((sum, item) => sum + item.total, 0);
    let cashGiven = parseFloat(document.getElementById('amount_tendered').value) || 0;
    
    if (cashGiven < grandTotal) {
        alert("Not enough cash tendered!");
        return false;
    }
    
    return true; // Allow the form to submit
}

/**
 * Intercepts the Pay Now click to validate & open Confirmation Modal
 */
function handlePayNowClick(e) {
    if (e && e.preventDefault) {
        e.preventDefault(); // Stop instant PHP submission
    }

    // Validate cart and payment
    if (!validateCheckout()) {
        return false;
    }

    // Fill Confirmation Modal Items
    let itemsContainer = document.getElementById('confirm-items-list');
    itemsContainer.innerHTML = '';

    cart.forEach(item => {
        let typeLabel = item.type === 'swap' ? 'Swap Tank' : 'New Tank';
        itemsContainer.innerHTML += `
            <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #f0f0f0; padding: 6px 0; font-size: 14px;">
                <div><strong>${item.name}</strong> (${typeLabel}) x ${item.qty}</div>
                <div>₱${item.total.toFixed(2)}</div>
            </div>
        `;
    });

    let grandTotal = cart.reduce((sum, item) => sum + item.total, 0);
    let cashGiven = parseFloat(document.getElementById('amount_tendered').value) || 0;
    let change = cashGiven - grandTotal;

    document.getElementById('confirm-total').innerText = '₱' + grandTotal.toFixed(2);
    document.getElementById('confirm-cash').innerText = '₱' + cashGiven.toFixed(2);
    document.getElementById('confirm-change').innerText = '₱' + (change > 0 ? change.toFixed(2) : '0.00');

    // Show confirmation modal
    document.getElementById('confirmationModal').style.display = 'block';
    return false;
}

function closeConfirmationModal() {
    document.getElementById('confirmationModal').style.display = 'none';
}

/**
 * Moves from Confirmation to Receipt View (Includes 10% VAT Breakdown Calculation)
 */
function proceedToReceipt() {
    closeConfirmationModal();

    let customerName = document.getElementById('customer_name_input').value.trim();
    let grandTotal = cart.reduce((sum, item) => sum + item.total, 0);
    let cashGiven = parseFloat(document.getElementById('amount_tendered').value) || 0;
    let change = cashGiven - grandTotal;

    // Calculate VAT breakdown (10% VAT included in price, e.g., ₱1,500 = ₱1,350 base + ₱150 VAT)[cite: 10]
    let vatSales = grandTotal / 1.10;   
    let vatAmount = grandTotal - vatSales; 

    // Fill Receipt Modal Data
    document.getElementById('receipt-date').innerText = new Date().toLocaleString();
    document.getElementById('receipt-customer').innerText = customerName !== "" ? customerName : 'Guest';

    let tbody = document.getElementById('receipt-body');
    tbody.innerHTML = '';

    cart.forEach(item => {
        let typeLabel = item.type === 'swap' ? 'Swap Tank' : 'New Tank';
        
        let itemBase = item.total / 1.10;
        let itemVat = item.total - itemBase;

        tbody.innerHTML += `
            <tr>
                <td style="padding: 4px 0;">${item.qty}</td>
                <td style="padding: 4px 0;">
                    ${item.name} (${typeLabel})<br>
                    <span style="font-size: 10px; color: #555;">(Subtotal: ₱${itemBase.toFixed(2)} + VAT: ₱${itemVat.toFixed(2)})</span>
                </td>
                <td style="padding: 4px 0; text-align: right;">₱${item.price.toFixed(2)}</td>
                <td style="padding: 4px 0; text-align: right;">₱${item.total.toFixed(2)}</td>
            </tr>
        `;
    });

    document.getElementById('receipt-vat-sales').innerText = '₱' + vatSales.toFixed(2);
    document.getElementById('receipt-vat-amount').innerText = '₱' + vatAmount.toFixed(2);
    document.getElementById('receipt-total').innerText = '₱' + grandTotal.toFixed(2);
    document.getElementById('receipt-cash').innerText = '₱' + cashGiven.toFixed(2);
    document.getElementById('receipt-change').innerText = '₱' + (change > 0 ? change.toFixed(2) : '0.00');

    // Show Receipt Modal
    document.getElementById('receiptModal').style.display = 'block';
}

/**
 * Completes the transaction without printing
 */
function finalizeSaleWithoutPrint() {
    document.getElementById('checkout-form').submit();
}

/**
 * Triggers browser print window for the receipt section only, then submits form
 */
function printReceiptAndSubmit() {
    window.print();
    
    // Submit form after printing
    setTimeout(() => {
        document.getElementById('checkout-form').submit();
    }, 300);
}