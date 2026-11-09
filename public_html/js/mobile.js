document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.querySelector('.sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const closeSidebarBtn = document.getElementById('closeSidebarBtn');

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('mobile-open');
        });
    }

    if (closeSidebarBtn && sidebar) {
        closeSidebarBtn.addEventListener('click', function() {
            sidebar.classList.remove('mobile-open');
        });
    }

    // ADDED: Close sidebar if clicking anywhere outside of it on mobile or heavy zoom views
    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 1200 && sidebar.classList.contains('mobile-open')) {
            if (!sidebar.contains(e.target) && (!sidebarToggle || !sidebarToggle.contains(e.target))) {
                sidebar.classList.remove('mobile-open');
            }
        }
    });
});