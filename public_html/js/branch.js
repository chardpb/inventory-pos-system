// --- ADD MODAL ---
function openAddModal() { 
    document.getElementById('addBranchModal').style.display = 'flex';
}
function closeAddModal() { 
    document.getElementById('addBranchModal').style.display = 'none';
}

// --- EDIT MODAL ---
function openEditModal(id, name, isMain) {
    document.getElementById('edit_form_branch_id').value = id;
    document.getElementById('edit_form_branch_name').value = name;
    document.getElementById('edit_form_is_main').value = isMain;
    
    document.getElementById('editBranchModal').style.display = 'flex';
}
function closeEditModal() { 
    document.getElementById('editBranchModal').style.display = 'none';
}

// --- SECURITY: HARD CONFIRMATION TYPING TEST ---
function confirmBranchDeactivate(expectedName) {
    // Trigger the typing prompt
    const userInput = prompt("SECURITY WARNING: This will deactivate the branch location and hide it from the active system.\n\nTo confirm, please type the branch name exactly:\n" + expectedName);
    
    // Verify the input
    if (userInput === expectedName) {
        return true; // Match! Proceed with deactivation.
    } else if (userInput !== null) {
        alert("Action cancelled: The branch name did not match exactly.");
        return false; // Did not match, block action.
    }
    
    return false; // Cancelled the prompt.
}

// --- GLOBAL WINDOW CLICK ---
window.onclick = function(event) {
    const addModal = document.getElementById('addBranchModal');
    const editModal = document.getElementById('editBranchModal');
    
    if (event.target == addModal) addModal.style.display = "none";
    if (event.target == editModal) editModal.style.display = "none";
}