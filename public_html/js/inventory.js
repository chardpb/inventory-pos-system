// ==========================================
// 1. Assign New Item Modal
// ==========================================
function openAssignModal() {
    document.getElementById('assignModal').style.display = 'block';
}

function closeAssignModal() {
    document.getElementById('assignModal').style.display = 'none';
    clearImageInput();
}

function clearImageInput() {
    const fileInput = document.getElementById('product_image_input');
    if (fileInput) {
        fileInput.value = ''; 
    }
}

// ==========================================
// 2. Update Stock Modal (+ Stock)
// ==========================================
function openUpdateModal(branchId, productId, branchName, productName) {
    document.getElementById('updateModal').style.display = 'block';
    document.getElementById('form_branch_id').value = branchId;
    document.getElementById('form_product_id').value = productId;
    
    document.getElementById('modal-display-text').innerHTML = 
        "Location: <strong>" + branchName + "</strong><br>" +
        "Item: <strong>" + productName + "</strong>";
}

function closeUpdateModal() {
    document.getElementById('updateModal').style.display = 'none';
}

// ==========================================
// 3. Remove Stock / Delete Modal
// ==========================================
function openRemoveModal(branchId, productId, branchName, productName) {
    document.getElementById('removeModal').style.display = 'block';
    
    document.getElementById('remove_form_branch_id').value = branchId;
    document.getElementById('remove_form_product_id').value = productId;
    
    var deleteBranch = document.getElementById('delete_form_branch_id');
    var deleteProduct = document.getElementById('delete_form_product_id');
    if(deleteBranch && deleteProduct) {
        deleteBranch.value = branchId;
        deleteProduct.value = productId;
    }

    document.getElementById('remove-modal-display-text').innerHTML = 
        "Location: <strong>" + branchName + "</strong><br>" +
        "Item: <strong>" + productName + "</strong>";
}

function closeRemoveModal() {
    document.getElementById('removeModal').style.display = 'none';
}

// ==========================================
// 4. Edit Price Modal
// ==========================================
function openEditPriceModal(branchId, productId, productName, currentPrice) {
    document.getElementById('editPriceModal').style.display = 'block';

    document.getElementById('price_form_branch_id').value = branchId;
    document.getElementById('price_form_product_id').value = productId;

    if (productName) {
        document.getElementById('price-modal-display-text').innerHTML = 
            "Editing price for: <strong>" + productName + "</strong>";
    } else {
        document.getElementById('price-modal-display-text').innerHTML = 
            "Editing price for Product ID: <strong>" + productId + "</strong>";
    }

    document.getElementById('edit_new_price').value = currentPrice;
}

function closeEditPriceModal() {
    document.getElementById('editPriceModal').style.display = 'none';
}

// ==========================================
// 5. Confirmation for Archiving
// ==========================================
function confirmCompleteDelete() {
    return confirm('Are you sure you want to archive this item? It will be hidden from the dashboard, but old sales receipts will stay safe.');
}

// ==========================================
// 6. Global Modal Backdrop Click Handler
// ==========================================
window.onclick = function(event) {
    if (event.target == document.getElementById('updateModal')) {
        closeUpdateModal();
    }
    if (event.target == document.getElementById('assignModal')) {
        closeAssignModal();
    }
    if (event.target == document.getElementById('removeModal')) {
        closeRemoveModal();
    }
    if (event.target == document.getElementById('editPriceModal')) {
        closeEditPriceModal();
    }
}

// ==========================================
// 7. Input Restrictions
// ==========================================
function limitStockInput(input) {
    let value = parseInt(input.value);
    if (value < 0) input.value = 0;
    if (value > 50) input.value = 50;
}

function limitPriceInput(input) {
    let value = parseFloat(input.value);
    if (value < 0) input.value = 0;
    if (value > 10000) input.value = 10000;
}

// ==========================================
// 8. Dynamic Brand & Size Dropdown Handler
// ==========================================
function updateTankSizes() {
    const brandSelect = document.getElementById('select_brand');
    const sizeSelect = document.getElementById('select_product_id');
    const customBrandGroup = document.getElementById('custom_brand_group');
    const customSizeGroup = document.getElementById('custom_size_group');
    
    if (!brandSelect || !sizeSelect) return;

    const selectedBrand = brandSelect.value;
    const allProducts = window.allProducts || [];
    sizeSelect.innerHTML = '';
    
    if (selectedBrand === '__NEW__') {
        if (customBrandGroup) customBrandGroup.style.display = 'block';
        if (customSizeGroup) customSizeGroup.style.display = 'block';
        
        let opt = document.createElement('option');
        opt.value = '__NEW_SIZE__';
        opt.textContent = '+ Create New Variant';
        sizeSelect.appendChild(opt);
        return;
    }

    if (customBrandGroup) customBrandGroup.style.display = 'none';

    const matchingProducts = allProducts.filter(p => p.brand_name === selectedBrand);

    let defaultOpt = document.createElement('option');
    defaultOpt.value = '';
    defaultOpt.disabled = true;
    defaultOpt.selected = true;
    defaultOpt.textContent = '-- Select Tank Size --';
    sizeSelect.appendChild(defaultOpt);

    matchingProducts.forEach(product => {
        let opt = document.createElement('option');
        opt.value = product.id;
        opt.textContent = product.tank_size;
        sizeSelect.appendChild(opt);
    });

    let newSizeOpt = document.createElement('option');
    newSizeOpt.value = '__NEW_SIZE__';
    newSizeOpt.textContent = '+ Add New Size to ' + selectedBrand;
    sizeSelect.appendChild(newSizeOpt);
}

// ==========================================
// 9. Table Brand Filtering Handler
// ==========================================
function filterTableByBrand() {
    const filterSelect = document.getElementById('brandFilterSelect');
    if (!filterSelect) return;

    const selectedBrand = filterSelect.value.trim();
    const rows = document.querySelectorAll('tbody tr[data-brand]');

    rows.forEach(row => {
        const rowBrand = row.getAttribute('data-brand');
        if (selectedBrand === 'ALL' || rowBrand === selectedBrand) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Listener for custom size field toggle
document.addEventListener('DOMContentLoaded', function() {
    const sizeSelect = document.getElementById('select_product_id');
    if (sizeSelect) {
        sizeSelect.addEventListener('change', function() {
            const customSizeGroup = document.getElementById('custom_size_group');
            if (customSizeGroup) {
                customSizeGroup.style.display = (this.value === '__NEW_SIZE__') ? 'block' : 'none';
            }
        });
    }
});