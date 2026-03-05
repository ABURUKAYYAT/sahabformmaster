(() => {
    const body = document.body;
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const sidebar = document.querySelector('[data-sidebar]');
    const overlay = document.querySelector('[data-sidebar-overlay]');
    const requestModal = document.getElementById('requestModal');
    const detailsModal = document.getElementById('detailsModal');
    const detailsContent = document.getElementById('detailsContent');
    const statusClassMap = {
        pending: 'status-pending',
        approved: 'status-approved',
        rejected: 'status-rejected',
        cancelled: 'status-cancelled'
    };

    const openSidebar = () => {
        if (!sidebar || !overlay) return;
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('opacity-0', 'pointer-events-none');
        overlay.classList.add('opacity-100');
        body.classList.add('nav-open');
    };

    const closeSidebarShell = () => {
        if (!sidebar || !overlay) return;
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('opacity-0', 'pointer-events-none');
        overlay.classList.remove('opacity-100');
        body.classList.remove('nav-open');
    };

    const openModal = (modal) => {
        if (!modal) return;
        modal.classList.add('is-open');
    };

    const closeModal = (modal) => {
        if (!modal) return;
        modal.classList.remove('is-open');
    };

    const escapeHtml = (value) =>
        String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

    const safeAttachmentUrl = (value) => {
        const url = String(value || '').trim();
        if (!url) return '';
        if (
            url.startsWith('../teacher/uploads/permissions/')
            || url.startsWith('/')
            || url.startsWith('http://')
            || url.startsWith('https://')
        ) {
            return encodeURI(url);
        }
        return '';
    };

    const formatDateTime = (value) => {
        if (!value) return '';
        const parsed = new Date(value);
        if (Number.isNaN(parsed.getTime())) {
            return escapeHtml(value);
        }
        return escapeHtml(parsed.toLocaleString());
    };

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            if (sidebar && sidebar.classList.contains('-translate-x-full')) {
                openSidebar();
            } else {
                closeSidebarShell();
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebarShell);
    }

    if (sidebar) {
        sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeSidebarShell));
    }

    const filterByStatus = (status) => {
        const rows = document.querySelectorAll('.request-row');
        const buttons = document.querySelectorAll('.permission-filter-btn');
        const counter = document.getElementById('visibleRequestCount');
        let visibleCount = 0;

        rows.forEach((row) => {
            const rowStatus = (row.dataset.statusKey || '').toLowerCase();
            if (status === 'all' || rowStatus === status) {
                row.style.display = '';
                visibleCount += 1;
            } else {
                row.style.display = 'none';
            }
        });

        if (counter) {
            counter.textContent = String(visibleCount);
        }

        buttons.forEach((button) => {
            const active = (button.dataset.filter || 'all') === status;
            button.classList.toggle('is-active', active);
        });
    };

    window.openRequestModal = () => openModal(requestModal);
    window.closeRequestModal = () => closeModal(requestModal);
    window.closeDetailsModal = () => closeModal(detailsModal);

    window.viewRequestDetails = (requestId) => {
        const row = document.querySelector(`.request-row[data-id="${requestId}"]`);
        if (!row || !detailsContent) return;

        const requestData = {
            title: row.dataset.title || 'Untitled Request',
            type: row.dataset.type || 'N/A',
            date: row.dataset.date || 'N/A',
            duration: row.dataset.duration || 'N/A',
            priority: row.dataset.priority || 'N/A',
            status: row.dataset.status || 'N/A',
            statusKey: row.dataset.statusKey || 'pending',
            approvedBy: row.dataset.approvedBy || 'Not approved yet',
            description: row.dataset.description || '',
            rejectionReason: row.dataset.rejectionReason || '',
            approvedAt: row.dataset.approvedAt || '',
            attachmentPath: row.dataset.attachmentPath || ''
        };

        const safeStatusClass = statusClassMap[requestData.statusKey] || 'status-pending';
        const safeAttachmentHref = safeAttachmentUrl(requestData.attachmentPath);

        detailsContent.innerHTML = `
            <div class="permission-details-header">
                <h4 class="permission-details-title">${escapeHtml(requestData.title)}</h4>
                <span class="permission-inline-badge ${safeStatusClass}">${escapeHtml(requestData.status)}</span>
            </div>
            <div class="permission-details-grid">
                <div class="permission-info-card"><p>Type</p><strong>${escapeHtml(requestData.type)}</strong></div>
                <div class="permission-info-card"><p>Date</p><strong>${escapeHtml(requestData.date)}</strong></div>
                <div class="permission-info-card"><p>Duration</p><strong>${escapeHtml(requestData.duration)}</strong></div>
                <div class="permission-info-card"><p>Priority</p><strong>${escapeHtml(requestData.priority)}</strong></div>
                <div class="permission-info-card"><p>Approved By</p><strong>${escapeHtml(requestData.approvedBy)}</strong></div>
                ${requestData.approvedAt ? `<div class="permission-info-card"><p>Approved At</p><strong>${formatDateTime(requestData.approvedAt)}</strong></div>` : ''}
            </div>
            <div class="permission-info-block">
                <p class="permission-info-heading">Description</p>
                <div class="permission-info-body">${escapeHtml(requestData.description || 'No description provided.')}</div>
            </div>
            ${requestData.rejectionReason ? `<div class="permission-info-block"><p class="permission-info-heading">Rejection Reason</p><div class="permission-info-body rejection">${escapeHtml(requestData.rejectionReason)}</div></div>` : ''}
            ${safeAttachmentHref ? `<div class="permission-info-block"><a href="${safeAttachmentHref}" target="_blank" rel="noopener noreferrer" class="btn btn-outline"><i class="fas fa-paperclip"></i><span>View Attachment</span></a></div>` : ''}
        `;

        openModal(detailsModal);
    };

    window.cancelRequest = (requestId) => {
        if (confirm('Are you sure you want to cancel this request?')) {
            window.location.href = `cancel_permission.php?id=${requestId}`;
        }
    };

    window.exportRequests = () => {
        window.location.href = 'permissions.php?export=permissions_pdf';
    };

    window.filterByStatus = filterByStatus;

    document.addEventListener('DOMContentLoaded', () => {
        const fileInput = document.querySelector('input[name="attachment"]');
        const fileName = document.getElementById('permissionFileName');
        const filterButtons = document.querySelectorAll('.permission-filter-btn');

        if (fileInput && fileName) {
            fileInput.addEventListener('change', function() {
                if (this.files.length > 0) {
                    fileName.textContent = `Selected: ${this.files[0].name}`;
                } else {
                    fileName.textContent = '';
                }
            });
        }

        filterButtons.forEach((button) => {
            button.addEventListener('click', () => filterByStatus((button.dataset.filter || 'all').toLowerCase()));
        });

        filterByStatus('all');
    });

    [requestModal, detailsModal].forEach((modal) => {
        if (!modal) return;
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal(modal);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        if (requestModal && requestModal.classList.contains('is-open')) closeModal(requestModal);
        if (detailsModal && detailsModal.classList.contains('is-open')) closeModal(detailsModal);
    });
})();
