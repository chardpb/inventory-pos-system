function openUserModal() { document.getElementById('userModal').style.display = 'flex'; }
function closeUserModal() { document.getElementById('userModal').style.display = 'none'; }

function openEmailModal() { document.getElementById('emailModal').style.display = 'flex'; }
function closeEmailModal() { document.getElementById('emailModal').style.display = 'none'; }

function openPasswordModal() { document.getElementById('passwordModal').style.display = 'flex'; }
function closePasswordModal() { document.getElementById('passwordModal').style.display = 'none'; }

function openSettingsForgotModal() {
    closePasswordModal();
    document.getElementById('settingsForgotModal').style.display = 'flex';
}

function closeSettingsForgotModal() {
    document.getElementById('settingsForgotModal').style.display = 'none';
}

window.onclick = function(event) {
    if (event.target == document.getElementById('userModal')) closeUserModal();
    if (event.target == document.getElementById('emailModal')) closeEmailModal();
    if (event.target == document.getElementById('passwordModal')) closePasswordModal();
    if (event.target == document.getElementById('settingsForgotModal')) closeSettingsForgotModal();
}

// Password Visibility Toggle Helper Function
function setupPasswordToggle(toggleId, inputId, eyeOpenId, eyeClosedId) {
    const toggleBtn = document.getElementById(toggleId);
    const passwordInput = document.getElementById(inputId);
    const eyeOpen = document.getElementById(eyeOpenId);
    const eyeClosed = document.getElementById(eyeClosedId);

    if (toggleBtn && passwordInput) {
        toggleBtn.addEventListener('click', function () {
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                if (eyeOpen) eyeOpen.style.display = 'none';
                if (eyeClosed) eyeClosed.style.display = 'block';
            } else {
                passwordInput.type = 'password';
                if (eyeOpen) eyeOpen.style.display = 'block';
                if (eyeClosed) eyeClosed.style.display = 'none';
            }
        });
    }
}

// Initialize toggles when the DOM loads
document.addEventListener('DOMContentLoaded', function() {
    setupPasswordToggle('toggleCurrentPassword', 'current_password', 'eyeOpenCurrent', 'eyeClosedCurrent');
    setupPasswordToggle('toggleNewPassword', 'new_password', 'eyeOpenNew', 'eyeClosedNew');
    setupPasswordToggle('toggleConfirmPassword', 'confirm_password', 'eyeOpenConfirm', 'eyeClosedConfirm');
    setupPasswordToggle('toggleNewAccountPassword', 'new_account_password', 'eyeOpenNewAccount', 'eyeClosedNewAccount');
});