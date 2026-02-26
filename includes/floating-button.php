<!-- Enhanced Draggable Floating Buttons Container -->
<div id="floating-buttons-container">
    <!-- AI Assistant Button -->
    <div id="floating-ai-btn" class="floating-ai-btn" aria-label="AI Assistant">
        <i class="fas fa-robot"></i>
        <div class="ai-indicator"></div>
        <div class="ai-pulse-ring"></div>
    </div>

    <!-- Scroll-to-Top Button -->
    <div id="floating-scroll-btn" class="floating-scroll-btn" aria-label="Scroll to top">
        <i class="fas fa-chevron-up"></i>
        <div class="drag-indicator"></div>
    </div>
</div>

<!-- Enhanced AI Assistant Modal -->
<div id="ai-assistant-modal" class="ai-modal">
    <div class="ai-modal-overlay"></div>
    <div class="ai-modal-content">
        <button class="ai-modal-close ai-modal-close-floating" id="ai-modal-close" title="Close">
            <i class="fas fa-times"></i>
        </button>

        <div class="ai-chat-container" id="ai-chat-container">
            <div class="ai-welcome-message">
                <div class="ai-message ai-message-bot">
                <div class="ai-message-avatar">
                    <i class="fas fa-robot"></i>
                </div>
                    <div class="ai-message-content">
                        <div class="ai-message-text">
                            <p>Hi, I am your SahabFormMaster assistant. Ask about workflows, reports, or troubleshooting.</p>
                            <p class="ai-welcome-question">How can I help?</p>
                        </div>
                        <div class="ai-message-time">Just now</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="ai-typing-indicator" id="typing-indicator" style="display: none;">
            <div class="ai-typing-avatar">
                <i class="fas fa-robot"></i>
            </div>
            <div class="ai-typing-content">
                <div class="typing-dots">
                    <div class="typing-dot"></div>
                    <div class="typing-dot"></div>
                    <div class="typing-dot"></div>
                </div>
                <span class="typing-text">AI is thinking...</span>
            </div>
        </div>

        <div class="ai-input-container">
            <div class="ai-input-group">
                <textarea id="ai-input" placeholder="Ask about SahabFormMaster..." rows="1"></textarea>
                <button id="ai-submit" class="ai-submit-btn" disabled>
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
            <div class="ai-input-footer">
                <span class="ai-disclaimer">Powered by AI - responses may not be 100% accurate</span>
            </div>
        </div>
    </div>
</div>



<!-- Load AI Assistant CSS with improved path resolution and fallback styles -->
<?php
// Detect the correct CSS path based on current directory context
$currentPath = $_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? '';
$cssPath = '../assets/css/ai-assistant.css'; // Default for admin/teacher/student directories

// Check if we're in admin, teacher, or student directory (includes path will be ../includes/)
if (strpos($currentPath, '/admin/') !== false ||
    strpos($currentPath, '/teacher/') !== false ||
    strpos($currentPath, '/student/') !== false) {
    $cssPath = '../assets/css/ai-assistant.css';
} elseif (strpos($currentPath, 'index.php') !== false && strpos($currentPath, '/') === 0) {
    // Root index.php
    $cssPath = 'assets/css/ai-assistant.css';
} else {
    // Fallback to relative path from includes directory
    $cssPath = '../assets/css/ai-assistant.css';
}
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars($cssPath); ?>" id="ai-assistant-css">

<!-- Fallback CSS styles in case main CSS fails to load -->
<style id="ai-assistant-fallback-css">
/* Safety: never block the page unless modal is explicitly shown */
.ai-modal {
    display: none !important;
    pointer-events: none !important;
}
.ai-modal.show {
    display: flex !important;
    pointer-events: auto !important;
}
.ai-modal-overlay {
    display: none !important;
    pointer-events: none !important;
}
.ai-modal.show .ai-modal-overlay {
    display: block !important;
    pointer-events: auto !important;
}

/* Fallback styles for AI Assistant - ensures visibility even if main CSS fails */
#ai-assistant-modal.fallback {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    height: 100% !important;
    background: rgba(0, 0, 0, 0.5) !important;
    z-index: 99999 !important;
    display: none !important;
    align-items: center !important;
    justify-content: center !important;
    pointer-events: none !important;
}

#ai-assistant-modal.fallback.show {
    display: flex !important;
    pointer-events: auto !important;
}

#ai-assistant-modal.fallback .ai-modal-content {
    background: white !important;
    border-radius: 12px !important;
    box-shadow: 0 10px 25px rgba(0,0,0,0.3) !important;
    max-width: 400px !important;
    width: 90% !important;
    max-height: 80vh !important;
    overflow-y: auto !important;
    position: relative !important;
    z-index: 100000 !important;
}

#ai-assistant-modal.fallback #ai-input {
    width: 100% !important;
    padding: 12px !important;
    border: 1px solid #ddd !important;
    border-radius: 8px !important;
    font-size: 14px !important;
    resize: vertical !important;
    min-height: 40px !important;
    max-height: 120px !important;
    box-sizing: border-box !important;
    background: white !important;
    color: black !important;
    pointer-events: auto !important;
    cursor: text !important;
}

#ai-assistant-modal.fallback .ai-submit-btn {
    background: #1d4ed8 !important;
    color: white !important;
    border: none !important;
    padding: 10px 16px !important;
    border-radius: 8px !important;
    cursor: pointer !important;
    margin-top: 8px !important;
}

#ai-assistant-modal.fallback .ai-modal-close {
    position: absolute !important;
    top: 10px !important;
    right: 10px !important;
    background: none !important;
    border: none !important;
    font-size: 20px !important;
    cursor: pointer !important;
    color: #666 !important;
}
</style>

<script>
// Enhanced CSS loading and fallback mechanism
(function() {
    let cssLoaded = false;
    let fallbackApplied = false;

    function checkCSSLoaded() {
        const testBtn = document.getElementById('floating-ai-btn');
        if (!testBtn) return false;

        const styles = window.getComputedStyle(testBtn);
        // Check if our custom CSS variables are applied
        return styles.getPropertyValue('--primary-color') !== '';
    }

    function applyFallbackStyles() {
        if (fallbackApplied) return;
        fallbackApplied = true;

        console.warn('[AI Assistant] Applying fallback styles due to CSS loading failure');

        const modal = document.getElementById('ai-assistant-modal');
        if (modal) {
            modal.classList.add('fallback');
        }

        // Force show modal if it should be visible
        const aiBtn = document.getElementById('floating-ai-btn');
        if (aiBtn && aiBtn.classList.contains('active')) {
            if (modal) {
                modal.style.display = 'flex !important';
                modal.style.opacity = '1 !important';
                modal.style.pointerEvents = 'auto !important';
            }
        }

        // Ensure textarea is always interactive
        const textarea = document.getElementById('ai-input');
        if (textarea) {
            textarea.style.pointerEvents = 'auto';
            textarea.style.cursor = 'text';
            textarea.disabled = false;
            textarea.readOnly = false;
            textarea.removeAttribute('readonly');
            textarea.removeAttribute('disabled');
        }
    }

    function loadCSSFallback() {
        const cssLink = document.getElementById('ai-assistant-css');
        if (!cssLink) return;

        // Check if CSS loaded after a short delay
        setTimeout(function() {
            cssLoaded = checkCSSLoaded();
            if (!cssLoaded) {
                console.warn('[AI Assistant] CSS may not have loaded properly, trying fallback paths...');
                // Try alternative paths
                const fallbackPaths = [
                    'assets/css/ai-assistant.css',
                    '/assets/css/ai-assistant.css',
                    '../assets/css/ai-assistant.css',
                    '../../assets/css/ai-assistant.css',
                    './assets/css/ai-assistant.css'
                ];

                let loadedCount = 0;
                fallbackPaths.forEach(function(path, index) {
                    const link = document.createElement('link');
                    link.rel = 'stylesheet';
                    link.href = path;
                    link.id = 'ai-assistant-css-fallback-' + index;
                    document.head.appendChild(link);

                    link.onload = function() {
                        loadedCount++;
                        if (checkCSSLoaded()) {
                            cssLoaded = true;
                            console.log('[AI Assistant] CSS loaded successfully from:', path);
                            return;
                        }
                    };
                });

                // If still not loaded after all attempts, apply fallback
                setTimeout(function() {
                    if (!cssLoaded) {
                        applyFallbackStyles();
                    }
                }, 1000);
            } else {
                console.log('[AI Assistant] CSS loaded successfully');
            }
        }, 500);

        // Handle direct CSS load error
        cssLink.addEventListener('error', function() {
            console.error('[AI Assistant] CSS file failed to load from:', cssLink.href);
            applyFallbackStyles();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadCSSFallback);
    } else {
        loadCSSFallback();
    }
})();
</script>

<!-- AI Assistant Enhancement Notice -->
<!-- Enhanced floating button with modern design, improved UX, and better functionality -->
<!-- Features: Glassmorphism modal, auto-resize textarea, minimize function, keyboard shortcuts -->

<script>
// Enhanced Draggable Floating Scroll Button Functionality
document.addEventListener('DOMContentLoaded', function() {
    const scrollBtn = document.getElementById('floating-scroll-btn');
    if (!scrollBtn) return;

    let isDragging = false;
    let startX, startY, initialX, initialY;
    let currentX = 0;
    let currentY = 0;

    // Load saved position from localStorage
    const savedPosition = localStorage.getItem('floatingBtnPosition');
    if (savedPosition) {
        const pos = JSON.parse(savedPosition);
        currentX = pos.x;
        currentY = pos.y;
        updateButtonPosition();
    }

    // Show/hide button based on scroll position
    function toggleButtonVisibility() {
        if (window.scrollY > 200) {
            scrollBtn.classList.add('visible');
        } else {
            scrollBtn.classList.remove('visible');
        }
    }

    // Update button position
    function updateButtonPosition() {
        const container = document.getElementById('floating-buttons-container');
        if (container) {
            container.style.transform = `translate(${currentX}px, ${currentY}px)`;
        }
    }

    // Save position to localStorage
    function savePosition() {
        localStorage.setItem('floatingBtnPosition', JSON.stringify({ x: currentX, y: currentY }));
    }

    // Get mouse/touch position
    function getEventPosition(e) {
        if (e.type.includes('touch')) {
            return {
                x: e.touches[0].clientX,
                y: e.touches[0].clientY
            };
        } else {
            return {
                x: e.clientX,
                y: e.clientY
            };
        }
    }

    // Mouse/Touch event handlers
    function startDrag(e) {
        if (e.target !== scrollBtn && !scrollBtn.contains(e.target)) return;

        e.preventDefault();
        isDragging = true;
        scrollBtn.classList.add('dragging');

        const pos = getEventPosition(e);
        startX = pos.x;
        startY = pos.y;

        const rect = scrollBtn.getBoundingClientRect();
        initialX = rect.left;
        initialY = rect.top;
    }

    function drag(e) {
        if (!isDragging) return;

        e.preventDefault();
        const pos = getEventPosition(e);

        const deltaX = pos.x - startX;
        const deltaY = pos.y - startY;

        currentX += deltaX;
        currentY += deltaY;

        startX = pos.x;
        startY = pos.y;

        // Boundary constraints
        const btnRect = scrollBtn.getBoundingClientRect();
        const maxX = window.innerWidth - btnRect.width - 10;
        const maxY = window.innerHeight - btnRect.height - 10;

        currentX = Math.max(-10, Math.min(currentX, maxX));
        currentY = Math.max(-10, Math.min(currentY, maxY));

        updateButtonPosition();
    }

    function endDrag(e) {
        if (!isDragging) return;

        isDragging = false;
        scrollBtn.classList.remove('dragging');
        savePosition();
    }

    // Scroll to top function
    function scrollToTop() {
        if (isDragging) return; // Don't scroll if dragging

        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }

    // Event listeners
    // Scroll visibility
    window.addEventListener('scroll', toggleButtonVisibility);

    // Drag events - Mouse
    scrollBtn.addEventListener('mousedown', startDrag);
    document.addEventListener('mousemove', drag);
    document.addEventListener('mouseup', endDrag);

    // Drag events - Touch
    scrollBtn.addEventListener('touchstart', startDrag, { passive: false });
    document.addEventListener('touchmove', drag, { passive: false });
    document.addEventListener('touchend', endDrag);

    // Click to scroll (only if not dragging)
    scrollBtn.addEventListener('click', function(e) {
        if (!isDragging) {
            scrollToTop();
        }
    });

    // Prevent context menu on right click during drag
    scrollBtn.addEventListener('contextmenu', function(e) {
        e.preventDefault();
    });

    // Initial visibility check
    toggleButtonVisibility();

    // Handle window resize
    window.addEventListener('resize', function() {
        // Ensure button stays within bounds on resize
        const btnRect = scrollBtn.getBoundingClientRect();
        const maxX = window.innerWidth - btnRect.width - 10;
        const maxY = window.innerHeight - btnRect.height - 10;

        currentX = Math.max(-10, Math.min(currentX, maxX));
        currentY = Math.max(-10, Math.min(currentY, maxY));

        updateButtonPosition();
        savePosition();
    });
});

// Enhanced AI Assistant Modal Functionality
document.addEventListener('DOMContentLoaded', function() {
    // Get all DOM elements with null checks
    const aiBtn = document.getElementById('floating-ai-btn');
    const aiModal = document.getElementById('ai-assistant-modal');
    const aiModalClose = document.getElementById('ai-modal-close');
    const aiMinimizeBtn = document.getElementById('ai-minimize-btn');
    const aiInput = document.getElementById('ai-input');
    const aiSubmit = document.getElementById('ai-submit');
    const aiChatContainer = document.getElementById('ai-chat-container');
    const quickBtns = document.querySelectorAll('.ai-quick-btn');

    // Critical null checks - exit early if essential elements don't exist
    if (!aiBtn) {
        console.error('[AI Assistant] Critical: floating-ai-btn element not found in DOM');
        return; // Exit early if button doesn't exist
    }

    if (!aiModal) {
        console.error('[AI Assistant] Critical: ai-assistant-modal element not found in DOM');
        return; // Exit early if modal doesn't exist
    }

    // Log successful initialization
    console.log('[AI Assistant] Initializing...', {
        button: !!aiBtn,
        modal: !!aiModal,
        input: !!aiInput,
        submit: !!aiSubmit,
        timestamp: new Date().toISOString()
    });

    // Debug: Force modal visibility for testing
    console.log('[AI Assistant] Debug - Modal classes before:', aiModal.className);
    console.log('[AI Assistant] Debug - Button classes before:', aiBtn.className);

    // Verify button is visible and clickable
    try {
        const btnStyles = window.getComputedStyle(aiBtn);
        const btnRect = aiBtn.getBoundingClientRect();
        console.log('[AI Assistant] Button visibility check:', {
            display: btnStyles.display,
            visibility: btnStyles.visibility,
            opacity: btnStyles.opacity,
            pointerEvents: btnStyles.pointerEvents,
            zIndex: btnStyles.zIndex,
            cursor: btnStyles.cursor,
            width: btnRect.width,
            height: btnRect.height,
            isVisible: btnRect.width > 0 && btnRect.height > 0
        });

        // Ensure button is clickable - fix any blocking styles
        if (btnStyles.pointerEvents === 'none') {
            console.warn('[AI Assistant] Button has pointer-events: none, fixing...');
            aiBtn.style.pointerEvents = 'auto';
        }
        
        // Ensure button has proper cursor
        if (btnStyles.cursor === 'default' || btnStyles.cursor === 'auto') {
            aiBtn.style.cursor = 'pointer';
        }
        
        // Ensure button has minimum z-index
        const zIndex = parseInt(btnStyles.zIndex) || 0;
        if (zIndex < 9999) {
            aiBtn.style.zIndex = '9999';
            console.log('[AI Assistant] Adjusted button z-index to 9999');
        }
        
        // Ensure button container is also properly positioned
        const container = document.getElementById('floating-buttons-container');
        if (container) {
            const containerStyles = window.getComputedStyle(container);
            if (containerStyles.position !== 'fixed' && containerStyles.position !== 'absolute') {
                container.style.position = 'fixed';
                container.style.bottom = '24px';
                container.style.right = '24px';
                container.style.zIndex = '9999';
                console.log('[AI Assistant] Fixed button container positioning');
            }
        }
    } catch (error) {
        console.error('[AI Assistant] Error checking button visibility:', error);
    }

    let isMinimized = false;

    // Blur effect management - default to disabled for better clarity

    // Open modal with error handling and enhanced debugging
    try {
        aiBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('[AI Assistant] Button clicked, isMinimized:', isMinimized);
            console.log('[AI Assistant] Event target:', e.target);
            console.log('[AI Assistant] Modal element:', aiModal);
            console.log('[AI Assistant] Modal classes before click:', aiModal.className);

            try {
                if (isMinimized) {
                    // Restore from minimized state
                    aiModal.classList.add('show');
                    aiModal.classList.remove('minimized');
                    aiBtn.classList.add('active');
                    isMinimized = false;
                    console.log('[AI Assistant] Modal restored from minimized state');
                    console.log('[AI Assistant] Modal classes after restore:', aiModal.className);
                    // Ensure textarea is enabled and focusable
                    if (aiInput) {
                        aiInput.disabled = false;
                        aiInput.readOnly = false;
                        aiInput.removeAttribute('readonly');
                        setTimeout(() => {
                            if (aiInput) aiInput.focus();
                        }, 100);
                    }
                } else {
                    // Open normally
                    console.log('[AI Assistant] Opening modal normally...');
                    aiModal.classList.add('show');
                    aiBtn.classList.add('active');
                    console.log('[AI Assistant] Modal opened, classes after:', aiModal.className);
                    console.log('[AI Assistant] Button classes after:', aiBtn.className);
                    // Ensure textarea is enabled and focusable
                    if (aiInput) {
                        aiInput.disabled = false;
                        aiInput.readOnly = false;
                        aiInput.removeAttribute('readonly');
                        setTimeout(() => {
                            if (aiInput) aiInput.focus();
                            console.log('[AI Assistant] Textarea focused');
                        }, 100);
                    }
                }

                // Force visibility check after a short delay
                setTimeout(() => {
                    const modalStyles = window.getComputedStyle(aiModal);
                    console.log('[AI Assistant] Modal computed styles:', {
                        display: modalStyles.display,
                        opacity: modalStyles.opacity,
                        visibility: modalStyles.visibility,
                        pointerEvents: modalStyles.pointerEvents
                    });
                }, 100);

            } catch (error) {
                console.error('[AI Assistant] Error opening modal:', error);
            }
        }, { passive: false });

        console.log('[AI Assistant] Click event listener attached successfully');
    } catch (error) {
        console.error('[AI Assistant] Error attaching click event listener:', error);
        // Fallback: Use event delegation
        document.addEventListener('click', function(e) {
            if (e.target && (e.target.closest('#floating-ai-btn') || e.target.id === 'floating-ai-btn')) {
                e.preventDefault();
                e.stopPropagation();
                console.log('[AI Assistant] Fallback click handler triggered');
                aiModal.classList.add('show');
                aiBtn.classList.add('active');
                console.log('[AI Assistant] Modal opened via fallback');
            }
        });
    }

    // Close modal with null check
    if (aiModalClose) {
        try {
            aiModalClose.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                aiModal.classList.remove('show');
                aiBtn.classList.remove('active');
                isMinimized = false;
                aiModal.classList.remove('minimized');
                console.log('[AI Assistant] Modal closed');
            });
        } catch (error) {
            console.error('[AI Assistant] Error attaching close button listener:', error);
        }
    } else {
        console.warn('[AI Assistant] Close button not found');
    }

    // Minimize modal with null check
    if (aiMinimizeBtn) {
        try {
            aiMinimizeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                aiModal.classList.remove('show');
                aiModal.classList.add('minimized');
                aiBtn.classList.add('active');
                isMinimized = true;
                console.log('[AI Assistant] Modal minimized');
            });
        } catch (error) {
            console.error('[AI Assistant] Error attaching minimize button listener:', error);
        }
    }

    // Close modal when clicking outside (on overlay only)
    const aiModalOverlay = aiModal.querySelector('.ai-modal-overlay');
    if (aiModalOverlay) {
        aiModalOverlay.addEventListener('click', function(e) {
            aiModal.classList.remove('show');
            aiBtn.classList.remove('active');
            isMinimized = false;
            aiModal.classList.remove('minimized');
        });
    }
    
    // Prevent clicks inside modal content from closing the modal
    const aiModalContent = aiModal.querySelector('.ai-modal-content');
    if (aiModalContent) {
        aiModalContent.addEventListener('click', function(e) {
            e.stopPropagation();
        });
    }

    // Handle quick action buttons with null checks
    if (quickBtns && quickBtns.length > 0) {
        try {
            quickBtns.forEach(btn => {
                if (btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        const query = this.getAttribute('data-query');
                        if (aiInput && query) {
                            aiInput.value = query;
                            sendMessage();
                        }
                    });
                }
            });
        } catch (error) {
            console.error('[AI Assistant] Error attaching quick action button listeners:', error);
        }
    }

    // Handle enter key in textarea with null check
    if (aiInput) {
        try {
            aiInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
        } catch (error) {
            console.error('[AI Assistant] Error attaching textarea keydown listener:', error);
        }
    } else {
        console.warn('[AI Assistant] Textarea not found');
    }

    // Ensure textarea is always enabled and interactive
    function ensureTextareaInteractivity() {
        if (!aiInput) return;

        // Force remove any disabled/readonly states
        aiInput.disabled = false;
        aiInput.readOnly = false;
        aiInput.removeAttribute('disabled');
        aiInput.removeAttribute('readonly');

        // Ensure proper styling
        aiInput.style.pointerEvents = 'auto';
        aiInput.style.cursor = 'text';
        aiInput.style.opacity = '1';
        aiInput.style.backgroundColor = 'white';
        aiInput.style.color = 'black';

        // Set tabindex for accessibility
        aiInput.setAttribute('tabindex', '0');

        console.log('[AI Assistant] Textarea interactivity ensured');
    }

    // Call this immediately and periodically
    ensureTextareaInteractivity();
    setInterval(ensureTextareaInteractivity, 1000); // Check every second

    // Handle input changes to enable/disable submit button
    if (aiInput) {
        aiInput.addEventListener('input', function() {
            const hasText = this.value.trim().length > 0;
            if (aiSubmit) {
                aiSubmit.disabled = !hasText;
            }

            // Auto-resize textarea
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';

            // Re-ensure interactivity on input
            ensureTextareaInteractivity();
        });

        // Also handle on focus to ensure it's interactive
        aiInput.addEventListener('focus', function() {
            ensureTextareaInteractivity();
            console.log('[AI Assistant] Textarea focused and verified interactive');
        });

        // Handle click to ensure it's interactive
        aiInput.addEventListener('click', function() {
            ensureTextareaInteractivity();
        });
    }


    // Handle submit button with null check
    if (aiSubmit) {
        try {
            aiSubmit.addEventListener('click', function(e) {
                e.preventDefault();
                sendMessage();
            });
        } catch (error) {
            console.error('[AI Assistant] Error attaching submit button listener:', error);
        }
    } else {
        console.warn('[AI Assistant] Submit button not found');
    }

    // Send message function with null checks
    function sendMessage() {
        try {
            if (!aiInput) {
                console.error('[AI Assistant] Cannot send message: textarea not found');
                return;
            }

            const message = aiInput.value.trim();
            if (!message) {
                console.log('[AI Assistant] Message is empty, not sending');
                return;
            }

            console.log('[AI Assistant] Sending message:', message.substring(0, 50) + '...');

            // Add user message to chat
            if (aiChatContainer) {
                addMessage(message, 'user');
            }

            // Clear input
            aiInput.value = '';
            aiInput.style.height = 'auto';
            if (aiSubmit) {
                aiSubmit.disabled = true;
            }

            // Show typing indicator
            showTypingIndicator();

            // Send to AI API
            fetchAIResponse(message);
        } catch (error) {
            console.error('[AI Assistant] Error in sendMessage:', error);
        }
    }

    // Add message to chat
    function addMessage(content, type) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `ai-message ai-message-${type}`;

        const avatarDiv = document.createElement('div');
        avatarDiv.className = 'ai-message-avatar';
        avatarDiv.innerHTML = `${type === 'bot' ? '<i class="fas fa-robot"></i>' : '<i class="fas fa-user"></i>'}`;

        const contentDiv = document.createElement('div');
        contentDiv.className = 'ai-message-content';

        const textDiv = document.createElement('div');
        textDiv.className = 'ai-message-text';
        textDiv.innerHTML = content;

        const timeDiv = document.createElement('div');
        timeDiv.className = 'ai-message-time';
        timeDiv.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        contentDiv.appendChild(textDiv);
        contentDiv.appendChild(timeDiv);

        messageDiv.appendChild(avatarDiv);
        messageDiv.appendChild(contentDiv);

        // Remove welcome message if it exists
        const welcomeMessage = aiChatContainer.querySelector('.ai-welcome-message');
        if (welcomeMessage) {
            welcomeMessage.remove();
        }

        aiChatContainer.appendChild(messageDiv);
        scrollToBottom();
    }

    // Show typing indicator
    function showTypingIndicator() {
        const typingDiv = document.getElementById('typing-indicator');
        if (typingDiv) {
            typingDiv.style.display = 'flex';
            scrollToBottom();
        }
    }

    // Hide typing indicator
    function hideTypingIndicator() {
        const typingDiv = document.getElementById('typing-indicator');
        if (typingDiv) {
            typingDiv.style.display = 'none';
        }
    }

    // Fetch AI response
    function fetchAIResponse(message) {
        fetch('../ajax/ai_assistant.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                message: message,
                user_role: window.userRole || 'unknown',
                current_page: window.location.pathname
            })
        })
        .then(response => response.json())
        .then(data => {
            hideTypingIndicator();

            if (data.success) {
                addMessage(data.response, 'bot');
            } else {
                addMessage('<div class="ai-message-error">Sorry, I encountered an error. Please try again.</div>', 'bot');
            }

            // Re-enable submit button
            aiSubmit.disabled = false;
        })
        .catch(error => {
            console.error('AI API Error:', error);
            hideTypingIndicator();
            addMessage('<div class="ai-message-error">Sorry, I\'m unable to connect right now. Please try again later.</div>', 'bot');
            aiSubmit.disabled = false;
        });
    }

    // Scroll chat to bottom
    function scrollToBottom() {
        setTimeout(() => {
            if (aiChatContainer) {
                aiChatContainer.scrollTop = aiChatContainer.scrollHeight;
            }
        }, 100);
    }

    // Set user role (this will be set by PHP)
    window.userRole = '<?php echo isset($_SESSION['role']) ? $_SESSION['role'] : 'unknown'; ?>';

    // Add keyboard shortcuts with safety checks
    document.addEventListener('keydown', function(e) {
        try {
            // Ctrl/Cmd + / to focus AI input
            if ((e.ctrlKey || e.metaKey) && e.key === '/') {
                e.preventDefault();
                if (!aiModal.classList.contains('show')) {
                    if (aiBtn) aiBtn.click();
                } else {
                    if (aiInput) aiInput.focus();
                }
            }

            // Escape to close modal
            if (e.key === 'Escape' && aiModal.classList.contains('show')) {
                if (aiModalClose) aiModalClose.click();
            }
        } catch (error) {
            console.error('[AI Assistant] Error in keyboard shortcut handler:', error);
        }
    });

    // Add minimize functionality styles
    const style = document.createElement('style');
    style.textContent = `
        .ai-modal.minimized {
            display: none !important;
        }

        .ai-modal.minimized + .ai-modal-overlay {
            display: none !important;
        }

        /* Emergency fallback - force modal visibility */
        .ai-modal.emergency-show {
            opacity: 1 !important;
            pointer-events: auto !important;
            z-index: 10001 !important;
        }

        .ai-modal.emergency-show .ai-modal-overlay {
            opacity: 1 !important;
        }

        .ai-modal.emergency-show .ai-modal-content {
            transform: translateY(0) scale(1) !important;
        }
    `;
    document.head.appendChild(style);

    // Remove emergency fallback - modal should only open on explicit user click

    // Manual debug function - accessible via console
    window.debugAIModal = function() {
        console.log('[AI Assistant] Manual Debug Triggered');
        const aiBtn = document.getElementById('floating-ai-btn');
        const aiModal = document.getElementById('ai-assistant-modal');

        console.log('Button element:', aiBtn);
        console.log('Modal element:', aiModal);
        console.log('Button classes:', aiBtn ? aiBtn.className : 'N/A');
        console.log('Modal classes:', aiModal ? aiModal.className : 'N/A');

        if (aiBtn) {
            console.log('Button computed style:', window.getComputedStyle(aiBtn));
        }

        if (aiModal) {
            console.log('Modal computed style:', window.getComputedStyle(aiModal));
            console.log('Forcing modal open...');
            aiModal.classList.add('show');
            aiModal.style.display = 'flex';
            aiModal.style.opacity = '1';
            aiModal.style.pointerEvents = 'auto';
            console.log('Modal classes after force:', aiModal.className);
        }
    };

    console.log('[AI Assistant] Debug function available: window.debugAIModal()');
});
</script>

