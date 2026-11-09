// ==========================================
// 1. RECEIPT MODAL & PRINT FUNCTIONS
// ==========================================
let currentReceiptData = {};

function openReceiptModal(branch, cashier, item, tankType, qty, total, dateFull) {
    currentReceiptData = { branch, cashier, item, tankType, qty, total, dateFull };

    document.getElementById('modal-branch').innerText = branch;
    document.getElementById('modal-cashier').innerText = cashier;
    document.getElementById('modal-item').innerText = item;
    document.getElementById('modal-type').innerText = tankType;
    document.getElementById('modal-qty').innerText = qty;
    document.getElementById('modal-total').innerText = '₱' + total;
    document.getElementById('modal-date').innerText = dateFull;
    
    document.getElementById('receiptModal').style.display = 'flex';
}

function closeReceiptModal() { 
    document.getElementById('receiptModal').style.display = 'none'; 
}

function printDashboardReceipt() {
    const d = currentReceiptData;

    const receiptHtml = `
        <html>
        <head>
            <title>Print Receipt</title>
            <style>
                body { font-family: 'Courier New', Courier, monospace; width: 280px; margin: 0 auto; padding: 10px; font-size: 13px; }
                .text-center { text-align: center; }
                .line { border-top: 1px dashed #000; margin: 8px 0; }
                .row { display: flex; justify-content: space-between; margin-bottom: 4px; }
            </style>
        </head>
        <body>
            <div class="text-center">
                <h2 style="margin: 0;">IJE LPG</h2>
                <p style="margin: 3px 0;">Branch: ${d.branch}</p>
                <p style="margin: 3px 0;">Date: ${d.dateFull}</p>
                <p style="margin: 3px 0;">Cashier: ${d.cashier}</p>
                <p style="margin: 3px 0;">Customer: Guest</p>
            </div>
            <div class="line"></div>
            <div class="row" style="font-weight: bold;">
                <span>Qty Description</span>
                <span>Price Total</span>
            </div>
            <div class="line"></div>
            <div class="row">
                <span>${d.qty}  ${d.item} (${d.tankType})</span>
                <span>₱${d.total}</span>
            </div>
            <div class="line"></div>
            <div class="row" style="font-size: 14px; font-weight: bold;">
                <span>Total Amount:</span>
                <span>₱${d.total}</span>
            </div>
            <div class="line"></div>
            <p class="text-center" style="margin-top: 15px;">Thank you for your purchase!</p>
        </body>
        </html>
    `;

    const printWin = window.open('', '', 'width=400,height=600');
    printWin.document.write(receiptHtml);
    printWin.document.close();
    printWin.focus();
    printWin.print();
    printWin.close();
}

// ==========================================
// 2. NOTIFICATION RADAR
// ==========================================
function updateNotifications() {
    fetch('fetch_notifications.php')
        .then(res => res.json())
        .then(data => {
            if (data.error) return;
            
            let bellCounter = document.getElementById('main-bell-counter');
            if (bellCounter) bellCounter.innerText = data.total;
            
            let liveSales = document.getElementById('live-sales-revenue');
            if (liveSales) liveSales.innerText = '₱' + data.sales_revenue;
            
            let liveStock = document.getElementById('live-low-stock');
            if (liveStock) liveStock.innerText = data.low_stock + ' Items';
        });
}

setInterval(updateNotifications, 3000);