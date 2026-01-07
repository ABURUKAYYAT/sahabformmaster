<!-- Draggable Floating Buttons Container -->
<div id="floating-buttons-container">
    <!-- AI Assistant Button -->
    <div id="floating-ai-btn" class="floating-ai-btn" aria-label="AI Assistant">
        <i class="fas fa-robot"></i>
        <div class="ai-indicator"></div>
    </div>

    <!-- Scroll-to-Top Button -->
    <div id="floating-scroll-btn" class="floating-scroll-btn" aria-label="Scroll to top">
        <i class="fas fa-chevron-up"></i>
        <div class="drag-indicator"></div>
    </div>
</div>

<!-- AI Assistant Modal -->
<div id="ai-assistant-modal" class="ai-modal">
    <div class="ai-modal-content">
        <div class="ai-modal-header">
            <div class="ai-header-left">
                <div class="ai-avatar">
                    <i class="fas fa-robot"></i>
                </div>
                <div class="ai-info">
                    <h3>SahabFormMaster AI Assistant</h3>
                    <p>Ask me anything about the system</p>
                </div>
            </div>
            <button class="ai-modal-close" id="ai-modal-close">&times;</button>
        </div>

        <div class="ai-chat-container" id="ai-chat-container">
            <div class="ai-welcome-message">
                <div class="ai-message ai-message-bot">
                    <div class="ai-message-avatar">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="ai-message-content">
                        <p>Hello! I'm your AI assistant for SahabFormMaster. I can help you with:</p>
                        <ul>
                            <li><strong>System Guidance:</strong> How to use features, add students, manage results</li>
                            <li><strong>Analytics:</strong> Student performance, attendance trends, fee reports</li>
                            <li><strong>Troubleshooting:</strong> Common issues and solutions</li>
                        </ul>
                        <p>What would you like to know?</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="ai-input-container">
            <div class="ai-quick-actions">
                <button class="ai-quick-btn" data-query="How do I add a new student?">Add Student</button>
                <button class="ai-quick-btn" data-query="How do I compile results?">Compile Results</button>
                <button class="ai-quick-btn" data-query="Show me attendance analytics">Attendance Stats</button>
                <button class="ai-quick-btn" data-query="What's the fee collection status?">Fee Report</button>
            </div>
            <div class="ai-input-group">
                <textarea id="ai-input" placeholder="Ask me anything about SahabFormMaster..." rows="2"></textarea>
                <button id="ai-submit" class="ai-submit-btn">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Floating Scroll Button Styles */
.floating-scroll-btn {
    position: fixed;
    bottom: 30px;
    right: 30px;
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: white;
    border: none;
    border-radius: 50%;
    cursor: grab;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
    transition: all 0.3s ease;
    z-index: 1000;
    opacity: 0;
    transform: translateY(20px) scale(0.8);
    user-select: none;
}

.floating-scroll-btn.visible {
    opacity: 1;
    transform: translateY(0) scale(1);
}

.floating-scroll-btn:hover {
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
    transform: scale(1.1);
}

.floating-scroll-btn:active {
    cursor: grabbing;
}

.floating-scroll-btn.dragging {
    opacity: 0.8;
    transform: scale(1.05);
    box-shadow: 0 8px 25px rgba(99, 102, 241, 0.5);
}

.drag-indicator {
    position: absolute;
    top: 8px;
    left: 50%;
    transform: translateX(-50%);
    width: 20px;
    height: 3px;
    background: rgba(255, 255, 255, 0.6);
    border-radius: 2px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.floating-scroll-btn:hover .drag-indicator {
    opacity: 1;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .floating-scroll-btn {
        width: 50px;
        height: 50px;
        font-size: 1.2rem;
        bottom: 20px;
        right: 20px;
    }
}

@media (max-width: 480px) {
    .floating-scroll-btn {
        width: 45px;
        height: 45px;
        font-size: 1.1rem;
        bottom: 15px;
        right: 15px;
    }
}

/* AI Assistant Button Styles */
.floating-ai-btn {
    position: fixed;
    bottom: 100px;
    right: 30px;
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    border: none;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
    transition: all 0.3s ease;
    z-index: 1000;
    user-select: none;
}

.floating-ai-btn:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.4);
    transform: scale(1.1);
}

.floating-ai-btn.pulsing {
    animation: pulse 2s infinite;
}

.ai-indicator {
    position: absolute;
    top: 8px;
    right: 8px;
    width: 8px;
    height: 8px;
    background: #ef4444;
    border-radius: 50%;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.floating-ai-btn.active .ai-indicator {
    opacity: 1;
}

@keyframes pulse {
    0% { box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
    50% { box-shadow: 0 4px 20px rgba(16, 185, 129, 0.6); }
    100% { box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
}

/* AI Modal Styles */
.ai-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 10000;
    animation: fadeIn 0.3s ease;
}

.ai-modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
}

.ai-modal-content {
    background: white;
    border-radius: 12px;
    width: 90%;
    max-width: 500px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
    animation: slideUp 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes slideUp {
    from { transform: translateY(30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

/* Modal Header */
.ai-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px;
    border-bottom: 1px solid #e5e7eb;
}

.ai-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.ai-avatar {
    width: 40px;
    height: 40px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.2rem;
}

.ai-info h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: #111827;
}

.ai-info p {
    margin: 4px 0 0 0;
    font-size: 0.9rem;
    color: #6b7280;
}

.ai-modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: #6b7280;
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.ai-modal-close:hover {
    background: #f3f4f6;
    color: #374151;
}

/* Chat Container */
.ai-chat-container {
    flex: 1;
    padding: 20px;
    overflow-y: auto;
    max-height: 400px;
}

.ai-welcome-message {
    margin-bottom: 20px;
}

.ai-message {
    display: flex;
    gap: 12px;
    margin-bottom: 16px;
}

.ai-message-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.9rem;
    flex-shrink: 0;
}

.ai-message.ai-message-bot .ai-message-avatar {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
}

.ai-message.ai-message-user .ai-message-avatar {
    background: #e5e7eb;
    color: #6b7280;
}

.ai-message.ai-message-user {
    flex-direction: row-reverse;
}

.ai-message.ai-message-user .ai-message-content {
    background: #10b981;
    color: white;
}

.ai-message-content {
    background: #f3f4f6;
    padding: 12px 16px;
    border-radius: 16px;
    max-width: 80%;
    line-height: 1.5;
}

.ai-message-content p {
    margin: 0 0 8px 0;
}

.ai-message-content ul {
    margin: 8px 0;
    padding-left: 20px;
}

.ai-message-content li {
    margin-bottom: 4px;
}

.ai-message-content strong {
    color: #059669;
}

/* Typing Indicator */
.ai-typing {
    display: flex;
    gap: 12px;
    margin-bottom: 16px;
}

.ai-typing-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.9rem;
}

.ai-typing-indicator {
    background: #f3f4f6;
    padding: 12px 16px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.typing-dots {
    display: flex;
    gap: 2px;
}

.typing-dot {
    width: 4px;
    height: 4px;
    background: #6b7280;
    border-radius: 50%;
    animation: typing 1.4s infinite;
}

.typing-dot:nth-child(2) { animation-delay: 0.2s; }
.typing-dot:nth-child(3) { animation-delay: 0.4s; }

@keyframes typing {
    0%, 60%, 100% { transform: translateY(0); }
    30% { transform: translateY(-8px); }
}

/* Input Container */
.ai-input-container {
    padding: 20px;
    border-top: 1px solid #e5e7eb;
}

.ai-quick-actions {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}

.ai-quick-btn {
    background: #f3f4f6;
    border: 1px solid #d1d5db;
    color: #374151;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.ai-quick-btn:hover {
    background: #10b981;
    border-color: #10b981;
    color: white;
}

.ai-input-group {
    display: flex;
    gap: 8px;
    align-items: flex-end;
}

.ai-input-group textarea {
    flex: 1;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    padding: 12px;
    font-size: 0.95rem;
    resize: none;
    outline: none;
    transition: border-color 0.2s ease;
}

.ai-input-group textarea:focus {
    border-color: #10b981;
}

.ai-submit-btn {
    background: #10b981;
    color: white;
    border: none;
    width: 44px;
    height: 44px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s ease;
}

.ai-submit-btn:hover {
    background: #059669;
}

.ai-submit-btn:disabled {
    background: #d1d5db;
    cursor: not-allowed;
}

/* Responsive */
@media (max-width: 768px) {
    .floating-ai-btn {
        width: 50px;
        height: 50px;
        font-size: 1.1rem;
        bottom: 80px;
        right: 20px;
    }

    .ai-modal-content {
        width: 95%;
        max-height: 90vh;
    }

    .ai-quick-actions {
        gap: 6px;
    }

    .ai-quick-btn {
        padding: 4px 8px;
        font-size: 0.8rem;
    }
}
</style>

<script>
// Draggable Floating Scroll Button Functionality
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
        scrollBtn.style.transform = `translate(${currentX}px, ${currentY}px)`;
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

// AI Assistant Modal Functionality
document.addEventListener('DOMContentLoaded', function() {
    const aiBtn = document.getElementById('floating-ai-btn');
    const aiModal = document.getElementById('ai-assistant-modal');
    const aiModalClose = document.getElementById('ai-modal-close');
    const aiInput = document.getElementById('ai-input');
    const aiSubmit = document.getElementById('ai-submit');
    const aiChatContainer = document.getElementById('ai-chat-container');
    const quickBtns = document.querySelectorAll('.ai-quick-btn');

    // Open modal
    aiBtn.addEventListener('click', function() {
        aiModal.classList.add('show');
        aiBtn.classList.add('active');
        aiInput.focus();
    });

    // Close modal
    aiModalClose.addEventListener('click', function() {
        aiModal.classList.remove('show');
        aiBtn.classList.remove('active');
    });

    // Close modal when clicking outside
    aiModal.addEventListener('click', function(e) {
        if (e.target === aiModal) {
            aiModal.classList.remove('show');
            aiBtn.classList.remove('active');
        }
    });

    // Handle quick action buttons
    quickBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const query = this.getAttribute('data-query');
            aiInput.value = query;
            sendMessage();
        });
    });

    // Handle enter key in textarea
    aiInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Handle submit button
    aiSubmit.addEventListener('click', sendMessage);

    // Send message function
    function sendMessage() {
        const message = aiInput.value.trim();
        if (!message) return;

        // Add user message to chat
        addMessage(message, 'user');

        // Clear input
        aiInput.value = '';

        // Disable submit button
        aiSubmit.disabled = true;

        // Show typing indicator
        showTypingIndicator();

        // Send to AI API
        fetchAIResponse(message);
    }

    // Add message to chat
    function addMessage(content, type) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `ai-message ai-message-${type}`;

        const avatarDiv = document.createElement('div');
        avatarDiv.className = 'ai-message-avatar';
        avatarDiv.innerHTML = type === 'bot' ? '<i class="fas fa-robot"></i>' : '<i class="fas fa-user"></i>';

        const contentDiv = document.createElement('div');
        contentDiv.className = 'ai-message-content';
        contentDiv.innerHTML = content;

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
        const typingDiv = document.createElement('div');
        typingDiv.className = 'ai-typing';
        typingDiv.id = 'typing-indicator';

        const avatarDiv = document.createElement('div');
        avatarDiv.className = 'ai-typing-avatar';
        avatarDiv.innerHTML = '<i class="fas fa-robot"></i>';

        const indicatorDiv = document.createElement('div');
        indicatorDiv.className = 'ai-typing-indicator';
        indicatorDiv.innerHTML = `
            <div class="typing-dots">
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
                <div class="typing-dot"></div>
            </div>
        `;

        typingDiv.appendChild(avatarDiv);
        typingDiv.appendChild(indicatorDiv);

        aiChatContainer.appendChild(typingDiv);
        scrollToBottom();
    }

    // Hide typing indicator
    function hideTypingIndicator() {
        const typingIndicator = document.getElementById('typing-indicator');
        if (typingIndicator) {
            typingIndicator.remove();
        }
    }

    // Fetch AI response
    function fetchAIResponse(message) {
        fetch('ajax/ai_assistant.php', {
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
                addMessage('Sorry, I encountered an error. Please try again.', 'bot');
            }

            // Re-enable submit button
            aiSubmit.disabled = false;
        })
        .catch(error => {
            console.error('AI API Error:', error);
            hideTypingIndicator();
            addMessage('Sorry, I\'m unable to connect right now. Please try again later.', 'bot');
            aiSubmit.disabled = false;
        });
    }

    // Scroll chat to bottom
    function scrollToBottom() {
        setTimeout(() => {
            aiChatContainer.scrollTop = aiChatContainer.scrollHeight;
        }, 100);
    }

    // Auto-resize textarea
    aiInput.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 100) + 'px';
    });

    // Set user role (this will be set by PHP)
    window.userRole = '<?php echo isset($_SESSION['role']) ? $_SESSION['role'] : 'unknown'; ?>';
});
</script>
