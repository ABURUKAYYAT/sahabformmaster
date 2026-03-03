// Lightweight student UI enhancements: mobile menu toggle and sidebar controls
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('mobileMenuToggle');
    var sidebarClose = document.getElementById('sidebarClose');
    var body = document.body;

    if (toggle) {
        toggle.addEventListener('click', function () {
            body.classList.toggle('sidebar-open');
        });
    }

    if (sidebarClose) {
        sidebarClose.addEventListener('click', function () {
            body.classList.remove('sidebar-open');
        });
    }

    // Close offcanvas when clicking outside on mobile
    document.addEventListener('click', function (e) {
        if (!e.target.closest('#sidebar') && !e.target.closest('#mobileMenuToggle')) {
            body.classList.remove('sidebar-open');
        }
    });
});
