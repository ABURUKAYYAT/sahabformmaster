/**
 * admin-evaluations-crud.js
 * Enhanced JavaScript for Evaluations CRUD Page
 * No modal dependencies - uses inline sections and smooth transitions
 */

// Document ready equivalent
document.addEventListener('DOMContentLoaded', function() {
    initializeEventListeners();
    initializeFormValidation();
    updateBulkActions();
});

/**
 * Initialize all event listeners
 */
function initializeEventListeners() {
    // Bulk selection
    const selectAllCheckbox = document.getElementById('select-all');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', toggleSelectAll);
    }

    // Individual item selection
    document.addEventListener('change', function(e) {
        if (e.target && e.target.classList.contains('select-item')) {
            updateBulkActions();
            updateSelectAllCheckbox();
        }
    });

    // Form submission handling
    const addForm = document.getElementById('add-evaluation-form');
    if (addForm) {
        addForm.addEventListener('submit', handleFormSubmit);
    }

    // Filter form submission
    const filterForm = document.querySelector('.filter-form');
    if (filterForm) {
        filterForm.addEventListener('submit', handleFilterSubmit);
    }

    // Real-time search
    const searchInput = document.getElementById('search');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(handleSearch, 300));
    }

    // Section toggle buttons
    document.addEventListener('click', function(e) {
        if (e.target && e.target.closest('.toggle-section-btn')) {
            const section = e.target.closest('.panel');
            if (section) {
                toggleSection(section.id);
            }
        }
    });

    // Close view/edit section
    document.addEventListener('click', function(e) {
        if (e.target && e.target.closest('#view-edit-section')) {
            const target = e.target.closest('#view-edit-section');
            if (target === e.target.target) {
                closeViewEdit();
            }
        }
    });
}

/**
 * Initialize form validation
 */
function initializeFormValidation() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!validateForm(form)) {
                e.preventDefault();
            }
        });
    });

    // Real-time validation for required fields
    const requiredFields = document.querySelectorAll('input[required], select[required], textarea[required]');
    requiredFields.forEach(field => {
        field.addEventListener('blur', validateField);
        field.addEventListener('input', clearFieldError);
    });
}

/**
 * Handle form submission with validation and loading states
 */
async function handleFormSubmit(e) {
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    
    if (!validateForm(form)) {
        e.preventDefault();
        return;
    }

    // Add loading state
    setLoadingState(submitBtn, true);

    try {
        // Simulate API call delay for better UX
        await new Promise(resolve => setTimeout(resolve, 1000));
        
        // Form will submit normally, but we could also handle via AJAX here
        // For now, we'll let the server handle the submission
        
    } catch (error) {
        console.error('Form submission error:', error);
        showNotification('Error submitting form. Please try again.', 'error');
    } finally {
        setLoadingState(submitBtn, false);
    }
}

/**
 * Handle filter form submission
 */
function handleFilterSubmit(e) {
    const form = e.target;
    const formData = new FormData(form);
    
    // Build query string
    const params = new URLSearchParams();
    formData.forEach((value, key) => {
        if (value) {
            params.set(key, value);
        }
    });

    // Redirect with filters
    window.location.href = window.location.pathname + '?' + params.toString();
    e.preventDefault();
}

/**
 * Handle real-time search
 */
function handleSearch() {
    const searchTerm = document.getElementById('search').value;
    const currentUrl = new URL(window.location.href);
    
    if (searchTerm) {
        currentUrl.searchParams.set('search', searchTerm);
        currentUrl.searchParams.set('page', '1'); // Reset to first page
    } else {
        currentUrl.searchParams.delete('search');
    }
    
    // Debounced search - only redirect after user stops typing
    clearTimeout(handleSearch.timeout);
    handleSearch.timeout = setTimeout(() => {
        window.location.href = currentUrl.toString();
    }, 500);
}

/**
 * Toggle section visibility
 */
function toggleSection(sectionId) {
    const section = document.getElementById(sectionId);
    if (!section) return;

    const content = section.querySelector('.panel-body');
    const toggleBtn = section.querySelector('.toggle-section-btn i');
    
    if (content.style.maxHeight) {
        // Close section
        content.style.maxHeight = null;
        content.style.opacity = '0';
        content.style.marginTop = '0';
        toggleBtn.classList.remove('fa-chevron-up');
        toggleBtn.classList.add('fa-chevron-down');
    } else {
        // Open section
        content.style.maxHeight = content.scrollHeight + 'px';
        content.style.opacity = '1';
        content.style.marginTop = '1.5rem';
        toggleBtn.classList.remove('fa-chevron-down');
        toggleBtn.classList.add('fa-chevron-up');
    }
}

/**
 * Bulk selection functionality
 */
function toggleSelectAll() {
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('.select-item');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
    });
    
    updateBulkActions();
}

function updateSelectAllCheckbox() {
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('.select-item');
    const checkedBoxes = document.querySelectorAll('.select-item:checked');
    
    if (checkedBoxes.length === 0) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
    } else if (checkedBoxes.length === checkboxes.length) {
        selectAll.checked = true;
        selectAll.indeterminate = false;
    } else {
        selectAll.checked = false;
        selectAll.indeterminate = true;
    }
}

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.select-item:checked');
    const bulkActions = document.getElementById('bulk-actions');
    const selectedCount = document.getElementById('selected-count');
    
    if (checkboxes.length > 0) {
        bulkActions.style.display = 'flex';
        selectedCount.textContent = checkboxes.length;
    } else {
        bulkActions.style.display = 'none';
        selectedCount.textContent = '0';
    }
}

function clearSelection() {
    const checkboxes = document.querySelectorAll('.select-item');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    document.getElementById('select-all').checked = false;
    updateBulkActions();
}

async function bulkDelete() {
    const checkboxes = document.querySelectorAll('.select-item:checked');
    const ids = Array.from(checkboxes).map(cb => cb.value);
    
    if (ids.length === 0) {
        showNotification('Please select at least one evaluation to delete.', 'warning');
        return;
    }

    if (!confirm(`Are you sure you want to delete ${ids.length} evaluation(s)? This action cannot be undone.`)) {
        return;
    }

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `bulk_delete=1&selected_ids=${encodeURIComponent(JSON.stringify(ids))}`
        });

        if (response.ok) {
            showNotification(`${ids.length} evaluation(s) deleted successfully.`, 'success');
            // Reload page to reflect changes
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            throw new Error('Failed to delete evaluations');
        }
    } catch (error) {
        console.error('Bulk delete error:', error);
        showNotification('Error deleting evaluations. Please try again.', 'error');
    }
}

/**
 * View and Edit functionality
 */
async function viewEvaluation(id) {
    try {
        const response = await fetch(`get_evaluation_details.php?id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            showViewEditSection('view', data.evaluation);
        } else {
            throw new Error(data.message || 'Failed to load evaluation');
        }
    } catch (error) {
        console.error('View evaluation error:', error);
        showNotification('Error loading evaluation details.', 'error');
    }
}

async function editEvaluation(id) {
    try {
        const response = await fetch(`get_evaluation_details.php?id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            showViewEditSection('edit', data.evaluation);
        } else {
            throw new Error(data.message || 'Failed to load evaluation');
        }
    } catch (error) {
        console.error('Edit evaluation error:', error);
        showNotification('Error loading evaluation details.', 'error');
    }
}

function showViewEditSection(mode, evaluation) {
    const section = document.getElementById('view-edit-section');
    const title = document.getElementById('view-edit-title');
    const content = document.getElementById('view-edit-content');
    
    if (mode === 'view') {
        title.innerHTML = '<i class="fas fa-eye"></i> View Evaluation';
        content.innerHTML = generateViewHTML(evaluation);
    } else {
        title.innerHTML = '<i class="fas fa-edit"></i> Edit Evaluation';
        content.innerHTML = generateEditHTML(evaluation);
    }
    
    section.style.display = 'flex';
    
    // Add edit form submission handler if in edit mode
    if (mode === 'edit') {
        const editForm = content.querySelector('form');
        if (editForm) {
            editForm.addEventListener('submit', handleEditSubmit);
        }
    }
}

function closeViewEdit() {
    const section = document.getElementById('view-edit-section');
    section.style.display = 'none';
}

function generateViewHTML(evaluation) {
    return `
        <div class="view-grid">
            <div class="view-card">
                <h6><i class="fas fa-user"></i> Student Information</h6>
                <div class="view-row">
                    <strong>Name:</strong>
                    <span>${evaluation.full_name}</span>
                </div>
                <div class="view-row">
                    <strong>Class:</strong>
                    <span>${evaluation.class_name || 'N/A'}</span>
                </div>
                <div class="view-row">
                    <strong>Admission No:</strong>
                    <span>${evaluation.admission_no}</span>
                </div>
                <div class="view-row">
                    <strong>Term:</strong>
                    <span>Term ${evaluation.term}</span>
                </div>
                <div class="view-row">
                    <strong>Academic Year:</strong>
                    <span>${evaluation.academic_year}</span>
                </div>
                <div class="view-row">
                    <strong>Evaluated by:</strong>
                    <span>${evaluation.teacher_fname || 'Unknown'}</span>
                </div>
                <div class="view-row">
                    <strong>Date:</strong>
                    <span>${new Date(evaluation.created_at).toLocaleDateString()}</span>
                </div>
            </div>
            
            <div class="view-card">
                <h6><i class="fas fa-star"></i> Performance Ratings</h6>
                <div class="rating-display">
                    <div class="rating-item">
                        <span class="rating-label">Academic:</span>
                        <span class="badge badge-${evaluation.academic.replace('-', '')}">${ucfirst(evaluation.academic)}</span>
                    </div>
                    <div class="rating-item">
                        <span class="rating-label">Non-Academic:</span>
                        <span class="badge badge-${evaluation.non_academic.replace('-', '')}">${ucfirst(evaluation.non_academic)}</span>
                    </div>
                    <div class="rating-item">
                        <span class="rating-label">Cognitive:</span>
                        <span class="badge badge-${evaluation.cognitive.replace('-', '')}">${ucfirst(evaluation.cognitive)}</span>
                    </div>
                    <div class="rating-item">
                        <span class="rating-label">Psychomotor:</span>
                        <span class="badge badge-${evaluation.psychomotor.replace('-', '')}">${ucfirst(evaluation.psychomotor)}</span>
                    </div>
                    <div class="rating-item">
                        <span class="rating-label">Affective:</span>
                        <span class="badge badge-${evaluation.affective.replace('-', '')}">${ucfirst(evaluation.affective)}</span>
                    </div>
                </div>
            </div>
        </div>
        
        ${evaluation.comments ? `
            <div class="view-card">
                <h6><i class="fas fa-comment"></i> Comments & Recommendations</h6>
                <p class="comments-content">${evaluation.comments}</p>
            </div>
        ` : ''}
        
        <div class="view-actions">
            <button class="btn btn-secondary" onclick="closeViewEdit()">
                <i class="fas fa-times"></i> Close
            </button>
            <button class="btn btn-warning" onclick="editEvaluation(${evaluation.id})">
                <i class="fas fa-edit"></i> Edit
            </button>
            <button class="btn btn-success" onclick="printEvaluation(${evaluation.id})">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    `;
}

function generateEditHTML(evaluation) {
    return `
        <form id="edit-evaluation-form" method="post">
            <input type="hidden" name="update_evaluation" value="1">
            <input type="hidden" name="evaluation_id" value="${evaluation.id}">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Student</label>
                    <input type="text" value="${evaluation.full_name}" disabled>
                </div>
                <div class="form-group">
                    <label>Class</label>
                    <input type="text" value="${evaluation.class_name || 'N/A'}" disabled>
                </div>
                <div class="form-group">
                    <label>Term</label>
                    <input type="text" value="Term ${evaluation.term}" disabled>
                </div>
                <div class="form-group">
                    <label>Academic Year</label>
                    <input type="text" value="${evaluation.academic_year}" disabled>
                </div>
            </div>

            <div class="rating-grid">
                <div class="rating-card">
                    <h6><i class="fas fa-graduation-cap"></i> Academic Performance</h6>
                    <select name="academic" required>
                        <option value="excellent" ${evaluation.academic === 'excellent' ? 'selected' : ''}>Excellent</option>
                        <option value="very-good" ${evaluation.academic === 'very-good' ? 'selected' : ''}>Very Good</option>
                        <option value="good" ${evaluation.academic === 'good' ? 'selected' : ''}>Good</option>
                        <option value="needs-improvement" ${evaluation.academic === 'needs-improvement' ? 'selected' : ''}>Needs Improvement</option>
                    </select>
                </div>
                <div class="rating-card">
                    <h6><i class="fas fa-users"></i> Non-Academic Activities</h6>
                    <select name="non_academic" required>
                        <option value="excellent" ${evaluation.non_academic === 'excellent' ? 'selected' : ''}>Excellent</option>
                        <option value="very-good" ${evaluation.non_academic === 'very-good' ? 'selected' : ''}>Very Good</option>
                        <option value="good" ${evaluation.non_academic === 'good' ? 'selected' : ''}>Good</option>
                        <option value="needs-improvement" ${evaluation.non_academic === 'needs-improvement' ? 'selected' : ''}>Needs Improvement</option>
                    </select>
                </div>
                <div class="rating-card">
                    <h6><i class="fas fa-brain"></i> Cognitive Domain</h6>
                    <select name="cognitive" required>
                        <option value="excellent" ${evaluation.cognitive === 'excellent' ? 'selected' : ''}>Excellent</option>
                        <option value="very-good" ${evaluation.cognitive === 'very-good' ? 'selected' : ''}>Very Good</option>
                        <option value="good" ${evaluation.cognitive === 'good' ? 'selected' : ''}>Good</option>
                        <option value="needs-improvement" ${evaluation.cognitive === 'needs-improvement' ? 'selected' : ''}>Needs Improvement</option>
                    </select>
                </div>
                <div class="rating-card">
                    <h6><i class="fas fa-hand-paper"></i> Psychomotor Domain</h6>
                    <select name="psychomotor" required>
                        <option value="excellent" ${evaluation.psychomotor === 'excellent' ? 'selected' : ''}>Excellent</option>
                        <option value="very-good" ${evaluation.psychomotor === 'very-good' ? 'selected' : ''}>Very Good</option>
                        <option value="good" ${evaluation.psychomotor === 'good' ? 'selected' : ''}>Good</option>
                        <option value="needs-improvement" ${evaluation.psychomotor === 'needs-improvement' ? 'selected' : ''}>Needs Improvement</option>
                    </select>
                </div>
                <div class="rating-card">
                    <h6><i class="fas fa-heart"></i> Affective Domain</h6>
                    <select name="affective" required>
                        <option value="excellent" ${evaluation.affective === 'excellent' ? 'selected' : ''}>Excellent</option>
                        <option value="very-good" ${evaluation.affective === 'very-good' ? 'selected' : ''}>Very Good</option>
                        <option value="good" ${evaluation.affective === 'good' ? 'selected' : ''}>Good</option>
                        <option value="needs-improvement" ${evaluation.affective === 'needs-improvement' ? 'selected' : ''}>Needs Improvement</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="comments">Comments & Recommendations</label>
                <textarea name="comments" rows="4" placeholder="Enter additional comments, strengths, areas for improvement, and recommendations...">${evaluation.comments || ''}</textarea>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeViewEdit()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update Evaluation
                </button>
            </div>
        </form>
    `;
}

async function handleEditSubmit(e) {
    e.preventDefault();
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    
    if (!validateForm(form)) {
        return;
    }

    setLoadingState(submitBtn, true);

    try {
        const formData = new FormData(form);
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });

        if (response.ok) {
            showNotification('Evaluation updated successfully!', 'success');
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            throw new Error('Failed to update evaluation');
        }
    } catch (error) {
        console.error('Edit submission error:', error);
        showNotification('Error updating evaluation. Please try again.', 'error');
    } finally {
        setLoadingState(submitBtn, false);
    }
}

/**
 * Export functionality
 */
function exportEvaluations(format) {
    const search = document.getElementById('search').value;
    const termFilter = document.getElementById('term_filter').value;
    const ratingFilter = document.getElementById('rating_filter').value;
    const classFilter = document.getElementById('class_filter').value;
    
    let url = `export-evaluations.php?format=${format}`;
    
    if (search) url += `&search=${encodeURIComponent(search)}`;
    if (termFilter) url += `&term=${termFilter}`;
    if (ratingFilter) url += `&rating=${ratingFilter}`;
    if (classFilter) url += `&class_id=${classFilter}`;
    
    window.open(url, '_blank');
}

function printEvaluation(id) {
    window.open(`print-evaluation.php?id=${id}`, '_blank');
}

/**
 * Utility functions
 */
function validateForm(form) {
    let isValid = true;
    const requiredFields = form.querySelectorAll('input[required], select[required], textarea[required]');
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            markFieldError(field, 'This field is required');
            isValid = false;
        } else {
            clearFieldError(field);
        }
    });

    return isValid;
}

function validateField(e) {
    const field = e.target;
    if (!field.value.trim()) {
        markFieldError(field, 'This field is required');
    } else {
        clearFieldError(field);
    }
}

function clearFieldError(field) {
    field.classList.remove('error');
    const errorElement = field.nextElementSibling;
    if (errorElement && errorElement.classList.contains('field-error')) {
        errorElement.remove();
    }
}

function markFieldError(field, message) {
    field.classList.add('error');
    field.classList.remove('success');
    
    // Remove existing error message
    const existingError = field.nextElementSibling;
    if (existingError && existingError.classList.contains('field-error')) {
        existingError.remove();
    }
    
    // Add new error message
    const errorElement = document.createElement('div');
    errorElement.className = 'field-error';
    errorElement.style.color = '#ef4444';
    errorElement.style.fontSize = '0.75rem';
    errorElement.style.marginTop = '0.25rem';
    errorElement.textContent = message;
    field.parentNode.appendChild(errorElement);
}

function setLoadingState(button, isLoading) {
    if (isLoading) {
        button.classList.add('loading');
        button.disabled = true;
        const originalText = button.innerHTML;
        button.dataset.originalText = originalText;
    } else {
        button.classList.remove('loading');
        button.disabled = false;
        if (button.dataset.originalText) {
            button.innerHTML = button.dataset.originalText;
        }
    }
}

function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <i class="fas fa-${getNotificationIcon(type)}"></i>
        <span>${message}</span>
        <button class="notification-close" onclick="this.parentElement.remove()">&times;</button>
    `;
    
    // Add to page
    document.body.appendChild(notification);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 5000);
}

function getNotificationIcon(type) {
    switch (type) {
        case 'success': return 'check-circle';
        case 'error': return 'exclamation-circle';
        case 'warning': return 'exclamation-triangle';
        default: return 'info-circle';
    }
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function ucfirst(str) {
    return str.charAt(0).toUpperCase() + str.slice(1).replace(/-/g, ' ');
}

// Make functions available globally
window.toggleSection = toggleSection;
window.toggleSelectAll = toggleSelectAll;
window.updateBulkActions = updateBulkActions;
window.clearSelection = clearSelection;
window.bulkDelete = bulkDelete;
window.viewEvaluation = viewEvaluation;
window.editEvaluation = editEvaluation;
window.closeViewEdit = closeViewEdit;
window.exportEvaluations = exportEvaluations;
window.printEvaluation = printEvaluation;
window.showNotification = showNotification;