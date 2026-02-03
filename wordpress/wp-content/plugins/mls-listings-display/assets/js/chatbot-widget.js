/**
 * MLS Chatbot Widget
 *
 * Interactive AI-powered chat widget for property inquiries
 *
 * @package MLS_Listings_Display
 * @since 6.6.0
 */

// CSS is loaded via PHP wp_enqueue_style
// import './chatbot-widget.css';

class MLDChatbotWidget {
    constructor() {
        this.isOpen = false;
        this.sessionId = this.getOrCreateSessionId(); // v6.27.8: Persist session across pages
        this.heartbeatInterval = null;
        this.lastActivityTime = Date.now();
        this.isTyping = false;
        this.messageQueue = [];

        // Lead capture gate state (v6.27.0)
        this.isLeadCaptured = false;
        this.leadData = {
            name: '',
            email: '',
            phone: ''
        };
        this.leadGateKey = 'mld_chatbot_lead_captured';

        // Chat state persistence keys (v6.27.8)
        this.sessionKey = 'mld_chatbot_session_id';
        this.chatOpenKey = 'mld_chatbot_is_open';
        this.lastPageKey = 'mld_chatbot_last_page';

        // Configuration from WordPress
        this.config = window.mldChatbot || {};
        this.ajaxUrl = this.config.ajaxUrl || this.config.ajax_url || '/wp-admin/admin-ajax.php';
        this.nonce = this.config.nonce || '';

        this.init();
    }

    init() {
        this.injectHTML();
        this.attachEventListeners();
        this.startHeartbeat();
        this.handlePageUnload();

        // Check for existing lead capture (v6.27.0)
        this.checkLeadCaptureState();

        // Restore chat state if it was open on previous page (v6.27.8)
        this.restoreChatState();
    }

    /**
     * Get existing session ID or create a new one (v6.27.8)
     * Persists session across page navigations for continuity
     */
    getOrCreateSessionId() {
        try {
            const stored = localStorage.getItem('mld_chatbot_session_id');
            const storedTime = localStorage.getItem('mld_chatbot_session_time');

            // Session expires after 30 minutes of inactivity
            const SESSION_TIMEOUT = 30 * 60 * 1000; // 30 minutes

            if (stored && storedTime) {
                const elapsed = Date.now() - parseInt(storedTime, 10);
                if (elapsed < SESSION_TIMEOUT) {
                    // Update activity time
                    localStorage.setItem('mld_chatbot_session_time', Date.now().toString());
                    return stored;
                }
            }

            // Create new session
            const newSession = this.generateSessionId();
            localStorage.setItem('mld_chatbot_session_id', newSession);
            localStorage.setItem('mld_chatbot_session_time', Date.now().toString());
            return newSession;
        } catch (e) {
            // localStorage not available, generate new session
            return this.generateSessionId();
        }
    }

    generateSessionId() {
        return 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    }

    /**
     * Restore chat state from previous page (v6.27.8)
     * If chat was open on the previous page, reopen it and load history
     */
    restoreChatState() {
        try {
            const wasOpen = localStorage.getItem(this.chatOpenKey) === 'true';
            const lastPage = localStorage.getItem(this.lastPageKey);
            const currentPage = window.location.href;

            // Update last page
            localStorage.setItem(this.lastPageKey, currentPage);

            if (wasOpen && lastPage && lastPage !== currentPage) {
                // Chat was open on a different page - user navigated
                // Small delay to ensure DOM is ready
                setTimeout(() => {
                    // Check if lead is captured first
                    if (this.isLeadCaptured) {
                        this.openChatWindow();
                        this.loadChatHistory();

                        // Notify user that page context has changed
                        this.showPageChangeNotice();
                    }
                }, 100);
            }
        } catch (e) {
            // Silent fallback - could not restore chat state
        }
    }

    /**
     * Show a subtle notice that the page context has changed (v6.27.8)
     */
    showPageChangeNotice() {
        const pageContext = this.getPageContext();
        let contextMessage = '';

        switch (pageContext.page_type) {
            case 'property_detail':
                contextMessage = "📍 I see you're now viewing a property. Ask me anything about this listing!";
                break;
            case 'calculator':
                contextMessage = "🧮 You're on our calculators page. Need help with any calculations?";
                break;
            case 'search_results':
                contextMessage = "🔍 I see you're browsing listings. Want me to help narrow down your search?";
                break;
            default:
                // No notice for other pages
                return;
        }

        if (contextMessage) {
            // Add as a system message
            this.addMessage(contextMessage, 'bot', { isSystemNotice: true });
        }
    }

    /**
     * Save chat open state (v6.27.8)
     */
    saveChatOpenState(isOpen) {
        try {
            localStorage.setItem(this.chatOpenKey, isOpen ? 'true' : 'false');
            localStorage.setItem(this.lastPageKey, window.location.href);
            // Update session activity time
            localStorage.setItem('mld_chatbot_session_time', Date.now().toString());
        } catch (e) {
            // Ignore localStorage errors
        }
    }

    /**
     * Load chat history from server (v6.27.8)
     */
    async loadChatHistory() {
        try {
            const formData = new FormData();
            formData.append('action', 'mld_chat_get_history');
            formData.append('nonce', this.nonce);
            formData.append('session_id', this.sessionId);

            const response = await fetch(this.ajaxUrl, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success && result.data.messages && result.data.messages.length > 0) {
                // Clear current messages
                const messagesContainer = document.getElementById('mld-chat-messages');
                if (messagesContainer) {
                    messagesContainer.innerHTML = '';
                }

                // Add history messages
                result.data.messages.forEach(msg => {
                    const sender = msg.sender_type === 'user' ? 'user' : 'bot';
                    this.addMessage(msg.message_text, sender, {
                        isFallback: msg.is_fallback === '1',
                        skipAnimation: true
                    });
                });
            }
        } catch (e) {
            // Silent fallback - could not load chat history
        }
    }

    injectHTML() {
        const html = `
            <div id="mld-chatbot-widget" class="mld-chatbot-widget" data-session="${this.sessionId}">
                <!-- Chat Bubble -->
                <div class="mld-chat-bubble" id="mld-chat-bubble">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/>
                        <circle cx="12" cy="10" r="1.5"/>
                        <circle cx="8" cy="10" r="1.5"/>
                        <circle cx="16" cy="10" r="1.5"/>
                    </svg>
                    <span class="mld-chat-badge" id="mld-chat-badge" style="display: none;">1</span>
                </div>

                <!-- Lead Capture Gate Form (v6.27.0) -->
                <div class="mld-chat-lead-gate" id="mld-chat-lead-gate" style="display: none;">
                    <div class="mld-lead-gate-content">
                        <div class="mld-lead-gate-header">
                            <div class="mld-lead-gate-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/>
                                </svg>
                            </div>
                            <h3>Let's get acquainted!</h3>
                            <p>Please share your contact info so we can better assist you.</p>
                        </div>
                        <form id="mld-lead-gate-form" class="mld-lead-gate-form">
                            <div class="mld-form-group">
                                <label for="mld-lead-name">Name <span class="mld-required">*</span></label>
                                <input type="text" id="mld-lead-name" name="name" required
                                       placeholder="Your full name" autocomplete="name">
                                <span class="mld-field-error" id="mld-name-error"></span>
                            </div>
                            <div class="mld-form-group">
                                <label for="mld-lead-email">Email</label>
                                <input type="email" id="mld-lead-email" name="email"
                                       placeholder="your@email.com" autocomplete="email">
                                <span class="mld-field-error" id="mld-email-error"></span>
                            </div>
                            <div class="mld-form-group">
                                <label for="mld-lead-phone">Phone</label>
                                <input type="tel" id="mld-lead-phone" name="phone"
                                       placeholder="(555) 123-4567" autocomplete="tel">
                                <span class="mld-field-error" id="mld-phone-error"></span>
                            </div>
                            <div class="mld-contact-hint" id="mld-contact-hint">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16">
                                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
                                </svg>
                                <span>Please provide at least an email or phone number</span>
                            </div>
                            <button type="submit" class="mld-lead-submit" id="mld-lead-submit">
                                <span>Start Chatting</span>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/>
                                </svg>
                            </button>
                        </form>
                        <button class="mld-lead-gate-close" id="mld-lead-gate-close" aria-label="Close">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Chat Window -->
                <div class="mld-chat-window" id="mld-chat-window" style="display: none;">
                    <!-- Header -->
                    <div class="mld-chat-header">
                        <div class="mld-chat-header-info">
                            <div class="mld-chat-avatar">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/>
                                </svg>
                            </div>
                            <div class="mld-chat-header-text">
                                <h3>Property Assistant</h3>
                                <span class="mld-chat-status">Online</span>
                            </div>
                        </div>
                        <button class="mld-chat-close" id="mld-chat-close" aria-label="Close chat">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Messages Container -->
                    <div class="mld-chat-messages" id="mld-chat-messages">
                        <div class="mld-chat-message mld-bot-message">
                            <div class="mld-message-content">
                                <p>${this.config.settings?.greeting || 'Hello! 👋 I\'m your AI property assistant. How can I help you today?'}</p>
                            </div>
                            <div class="mld-message-time">${this.getCurrentTime()}</div>
                        </div>
                    </div>

                    <!-- Typing Indicator -->
                    <div class="mld-typing-indicator" id="mld-typing-indicator" style="display: none;">
                        <span></span>
                        <span></span>
                        <span></span>
                    </div>

                    <!-- Input Area -->
                    <div class="mld-chat-input-container">
                        <textarea
                            id="mld-chat-input"
                            class="mld-chat-input"
                            placeholder="Type your message..."
                            rows="1"
                            maxlength="1000"
                        ></textarea>
                        <button id="mld-chat-send" class="mld-chat-send" aria-label="Send message">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Powered By -->
                    <div class="mld-chat-footer">
                        <span>Powered by AI</span>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', html);
    }

    attachEventListeners() {
        // Chat bubble toggle
        const bubble = document.getElementById('mld-chat-bubble');
        bubble?.addEventListener('click', () => this.toggleChat());

        // Close button
        const closeBtn = document.getElementById('mld-chat-close');
        closeBtn?.addEventListener('click', () => this.closeChat());

        // Send button
        const sendBtn = document.getElementById('mld-chat-send');
        sendBtn?.addEventListener('click', () => this.sendMessage());

        // Input field
        const input = document.getElementById('mld-chat-input');
        input?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.sendMessage();
            }
        });

        // Auto-resize textarea
        input?.addEventListener('input', (e) => {
            e.target.style.height = 'auto';
            e.target.style.height = Math.min(e.target.scrollHeight, 120) + 'px';
        });

        // Lead gate form (v6.27.0)
        const leadForm = document.getElementById('mld-lead-gate-form');
        leadForm?.addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleLeadSubmit();
        });

        // Lead gate close button
        const leadCloseBtn = document.getElementById('mld-lead-gate-close');
        leadCloseBtn?.addEventListener('click', () => this.closeChat());

        // Real-time validation hints for contact info
        const emailInput = document.getElementById('mld-lead-email');
        const phoneInput = document.getElementById('mld-lead-phone');
        emailInput?.addEventListener('input', () => this.updateContactHint());
        phoneInput?.addEventListener('input', () => this.updateContactHint());
    }

    toggleChat() {
        if (this.isOpen) {
            this.closeChat();
        } else {
            this.openChat();
        }
    }

    openChat() {
        const chatWindow = document.getElementById('mld-chat-window');
        const leadGate = document.getElementById('mld-chat-lead-gate');
        const bubble = document.getElementById('mld-chat-bubble');
        const badge = document.getElementById('mld-chat-badge');

        // Hide bubble
        bubble.classList.add('mld-chat-bubble-hidden');
        badge.style.display = 'none';
        this.isOpen = true;

        // Save chat open state for persistence across pages (v6.27.8)
        this.saveChatOpenState(true);

        // Check if lead gate is enabled and lead not yet captured
        const leadGateEnabled = this.config.lead_gate?.enabled !== false;

        if (leadGateEnabled && !this.isLeadCaptured) {
            // Show lead gate form
            leadGate.style.display = 'flex';
            chatWindow.style.display = 'none';

            // Pre-fill form with any WP user data
            this.prefillLeadForm();

            // Focus first empty field
            setTimeout(() => {
                const nameInput = document.getElementById('mld-lead-name');
                if (nameInput && !nameInput.value) {
                    nameInput.focus();
                } else {
                    const emailInput = document.getElementById('mld-lead-email');
                    if (emailInput && !emailInput.value) {
                        emailInput.focus();
                    }
                }
            }, 300);
        } else {
            // Show chat window directly
            this.openChatWindow();
        }
    }

    /**
     * Open the chat window (after lead gate or directly) (v6.27.8)
     */
    openChatWindow() {
        const chatWindow = document.getElementById('mld-chat-window');
        const leadGate = document.getElementById('mld-chat-lead-gate');
        const bubble = document.getElementById('mld-chat-bubble');

        chatWindow.style.display = 'flex';
        leadGate.style.display = 'none';
        bubble.classList.add('mld-chat-bubble-hidden');
        this.isOpen = true;

        this.scrollToBottom();

        // Focus input
        setTimeout(() => {
            document.getElementById('mld-chat-input')?.focus();
        }, 300);
    }

    closeChat() {
        const chatWindow = document.getElementById('mld-chat-window');
        const leadGate = document.getElementById('mld-chat-lead-gate');
        const bubble = document.getElementById('mld-chat-bubble');

        chatWindow.style.display = 'none';
        leadGate.style.display = 'none';
        bubble.classList.remove('mld-chat-bubble-hidden');

        this.isOpen = false;

        // Save chat closed state (v6.27.8)
        // Note: We don't end the session here - user might navigate and continue chat
        this.saveChatOpenState(false);
    }

    /**
     * Explicitly end the chat session (v6.27.8)
     * Called when user explicitly ends chat or after extended inactivity
     */
    endChatSession() {
        this.endSession();

        // Clear session from localStorage
        try {
            localStorage.removeItem('mld_chatbot_session_id');
            localStorage.removeItem('mld_chatbot_session_time');
            localStorage.removeItem(this.chatOpenKey);
        } catch (e) {
            // Ignore
        }
    }

    async sendMessage() {
        const input = document.getElementById('mld-chat-input');
        const message = input?.value.trim();

        if (!message) return;

        // Clear input
        input.value = '';
        input.style.height = 'auto';

        // Add user message to UI
        this.addMessage(message, 'user');

        // Show typing indicator
        this.showTyping();

        // Update activity time
        this.lastActivityTime = Date.now();

        try {
            // Send to backend
            const response = await this.sendToBackend(message);

            // Hide typing indicator
            this.hideTyping();

            if (response.success) {
                // Add bot response
                this.addMessage(response.data.message, 'bot', {
                    isFallback: response.data.is_fallback || false,
                    metadata: response.data.metadata || null
                });
            } else {
                // Show error
                this.addMessage(
                    'Sorry, I encountered an error. Please try again or contact us directly.',
                    'bot',
                    { isError: true }
                );
            }
        } catch (error) {
            console.error('[MLD Chatbot] Error sending message:', error);
            this.hideTyping();
            this.addMessage(
                'Connection error. Please check your internet connection and try again.',
                'bot',
                { isError: true }
            );
        }

        this.scrollToBottom();
    }

    addMessage(text, sender = 'bot', options = {}) {
        const messagesContainer = document.getElementById('mld-chat-messages');
        const messageClass = sender === 'user' ? 'mld-user-message' : 'mld-bot-message';

        const messageDiv = document.createElement('div');
        messageDiv.className = `mld-chat-message ${messageClass}`;

        if (options.isError) {
            messageDiv.classList.add('mld-message-error');
        }

        if (options.isFallback) {
            messageDiv.classList.add('mld-message-fallback');
        }

        // v6.27.5: Use formatMessageText for bot messages to enable clickable links
        // User messages still use plain escapeHtml for safety
        const formattedText = sender === 'bot'
            ? this.formatMessageText(text)
            : this.escapeHtml(text);

        messageDiv.innerHTML = `
            <div class="mld-message-content">
                <p>${formattedText}</p>
                ${options.isFallback ? '<span class="mld-fallback-badge">FAQ</span>' : ''}
            </div>
            <div class="mld-message-time">${this.getCurrentTime()}</div>
        `;

        messagesContainer.appendChild(messageDiv);
        this.scrollToBottom();

        // Show badge if chat is closed
        if (!this.isOpen && sender === 'bot') {
            this.showNotificationBadge();
        }
    }

    showTyping() {
        const indicator = document.getElementById('mld-typing-indicator');
        if (indicator) {
            indicator.style.display = 'flex';
            this.scrollToBottom();
        }
    }

    hideTyping() {
        const indicator = document.getElementById('mld-typing-indicator');
        if (indicator) {
            indicator.style.display = 'none';
        }
    }

    showNotificationBadge() {
        const badge = document.getElementById('mld-chat-badge');
        if (badge && !this.isOpen) {
            badge.style.display = 'flex';

            // Animate bubble
            const bubble = document.getElementById('mld-chat-bubble');
            bubble?.classList.add('mld-chat-bubble-bounce');
            setTimeout(() => {
                bubble?.classList.remove('mld-chat-bubble-bounce');
            }, 1000);
        }
    }

    async sendToBackend(message) {
        const formData = new FormData();
        formData.append('action', 'mld_chat_send_message');
        formData.append('nonce', this.nonce);
        formData.append('message', message);
        formData.append('session_id', this.sessionId);
        formData.append('user_data', JSON.stringify(this.getUserData()));

        const response = await fetch(this.ajaxUrl, {
            method: 'POST',
            body: formData
        });

        return await response.json();
    }

    getUserData() {
        return {
            page_url: window.location.href,
            referrer_url: document.referrer,
            device_type: this.getDeviceType(),
            browser: this.getBrowser(),
            // Include lead data (v6.27.0)
            name: this.leadData.name,
            email: this.leadData.email,
            phone: this.leadData.phone,
            // Include page context (v6.27.7)
            page_context: this.getPageContext()
        };
    }

    /**
     * Get current page context for AI awareness (v6.27.7)
     * Detects page type and extracts relevant information
     */
    getPageContext() {
        const url = window.location.href;
        const pathname = window.location.pathname;
        const context = {
            page_type: 'unknown',
            page_title: document.title,
            page_url: url
        };

        // Detect page type based on URL patterns and available data
        if (this.isPropertyPage()) {
            context.page_type = 'property_detail';
            context.property_data = this.extractPropertyData();
        } else if (pathname.includes('/calculator') || pathname.includes('/calculators')) {
            context.page_type = 'calculator';
            context.calculator_info = this.extractCalculatorInfo();
        } else if (pathname.includes('/cma') || pathname.includes('/market-analysis')) {
            context.page_type = 'cma';
            context.cma_info = this.extractCMAInfo();
        } else if (pathname.includes('/search') || pathname.includes('/listings')) {
            context.page_type = 'search_results';
            context.search_info = this.extractSearchInfo();
        } else if (pathname.includes('/saved-searches') || pathname.includes('/my-searches')) {
            context.page_type = 'saved_searches';
        } else if (pathname.includes('/book') || pathname.includes('/appointment') || pathname.includes('/schedule')) {
            context.page_type = 'booking';
        } else if (pathname === '/' || pathname === '') {
            context.page_type = 'homepage';
            context.homepage_info = this.extractHomepageInfo();
        } else {
            context.page_type = 'content_page';
            context.page_content = this.extractPageContent();
        }

        return context;
    }

    /**
     * Check if current page is a property detail page
     */
    isPropertyPage() {
        // Check for property data objects
        if (window.mldPropertyData || window.mldPropertyDataV3) {
            return true;
        }
        // Check URL pattern
        if (window.location.pathname.includes('/property/')) {
            return true;
        }
        // Check for property-specific elements
        if (document.querySelector('.mld-property-detail, .property-detail, [data-listing-id]')) {
            return true;
        }
        return false;
    }

    /**
     * Extract property data from the current page
     */
    extractPropertyData() {
        const data = {};

        // Get from window.mldPropertyData (primary source)
        if (window.mldPropertyData) {
            data.listing_id = window.mldPropertyData.listingId || window.mldPropertyData.mls_number || null;
            data.address = window.mldPropertyData.address || null;
            data.price = window.mldPropertyData.price || null;
            data.coordinates = window.mldPropertyData.coordinates || null;
        }

        // Get from window.mldPropertyDataV3 (additional data)
        if (window.mldPropertyDataV3) {
            data.property_tax = window.mldPropertyDataV3.propertyTax || null;
            data.hoa_fees = window.mldPropertyDataV3.hoaFees || null;
            data.bedrooms = window.mldPropertyDataV3.bedrooms || null;
            data.bathrooms = window.mldPropertyDataV3.bathrooms || null;
            data.sqft = window.mldPropertyDataV3.sqft || null;
            data.year_built = window.mldPropertyDataV3.yearBuilt || null;
            data.property_type = window.mldPropertyDataV3.propertyType || null;
        }

        // Extract listing ID from URL if not found
        if (!data.listing_id) {
            const urlMatch = window.location.pathname.match(/\/property\/([^\/]+)/);
            if (urlMatch) {
                data.listing_id = urlMatch[1];
            }
        }

        // Extract from data attributes if still missing
        const propertyElement = document.querySelector('[data-listing-id], [data-mls]');
        if (propertyElement) {
            data.listing_id = data.listing_id || propertyElement.dataset.listingId || propertyElement.dataset.mls;
        }

        // Extract visible property details from page content
        data.visible_details = this.extractVisiblePropertyDetails();

        return data;
    }

    /**
     * Extract visible property details from page elements
     */
    extractVisiblePropertyDetails() {
        const details = {};

        // Common selectors for property information
        const selectors = {
            mls_number: '.mls-number, .mld-mls-number, [data-field="mls"], .property-mls',
            price: '.property-price, .mld-price, .list-price, [data-field="price"]',
            address: '.property-address, .mld-address, [data-field="address"]',
            bedrooms: '.bedrooms, .beds, [data-field="bedrooms"]',
            bathrooms: '.bathrooms, .baths, [data-field="bathrooms"]',
            sqft: '.sqft, .square-feet, [data-field="sqft"]',
            year_built: '.year-built, [data-field="year_built"]',
            lot_size: '.lot-size, [data-field="lot_size"]',
            property_type: '.property-type, [data-field="property_type"]',
            status: '.property-status, .listing-status, [data-field="status"]',
            days_on_market: '.days-on-market, .dom, [data-field="dom"]',
            description: '.property-description, .mld-description, [data-field="description"]'
        };

        for (const [key, selector] of Object.entries(selectors)) {
            const element = document.querySelector(selector);
            if (element) {
                let text = element.textContent.trim();
                // Clean up the text (remove labels, extra whitespace)
                text = text.replace(/^[^:]+:\s*/, '').trim();
                if (text && text.length < 2000) { // Limit length for descriptions
                    details[key] = text;
                }
            }
        }

        // Try to extract MLS number from any element containing "MLS" text
        if (!details.mls_number) {
            const allText = document.body.innerText;
            const mlsMatch = allText.match(/MLS[#:\s]*([A-Z0-9]+)/i);
            if (mlsMatch) {
                details.mls_number = mlsMatch[1];
            }
        }

        return details;
    }

    /**
     * Extract calculator information from the page
     */
    extractCalculatorInfo() {
        const info = {
            calculator_types: []
        };

        // Detect calculator types by looking for common elements
        const calcKeywords = ['mortgage', 'closing cost', 'rent vs buy', 'investment', 'affordability'];
        const pageText = document.body.innerText.toLowerCase();

        calcKeywords.forEach(keyword => {
            if (pageText.includes(keyword)) {
                info.calculator_types.push(keyword);
            }
        });

        // Look for calculator forms
        const calcForms = document.querySelectorAll('form[class*="calc"], .calculator-form, .mld-calculator');
        info.has_calculator_form = calcForms.length > 0;

        return info;
    }

    /**
     * Extract CMA information from the page
     */
    extractCMAInfo() {
        const info = {};

        // Check for subject property
        if (window.mldCMAData) {
            info.subject_property = window.mldCMAData.subject || null;
            info.comparables_count = window.mldCMAData.comparables?.length || 0;
        }

        return info;
    }

    /**
     * Extract search results information
     */
    extractSearchInfo() {
        const info = {};

        // Count visible property cards
        const propertyCards = document.querySelectorAll('.property-card, .listing-card, .mld-property-card');
        info.results_count = propertyCards.length;

        // Try to get search criteria from URL params
        const urlParams = new URLSearchParams(window.location.search);
        info.search_params = {};
        ['city', 'beds', 'baths', 'price_min', 'price_max', 'property_type'].forEach(param => {
            if (urlParams.has(param)) {
                info.search_params[param] = urlParams.get(param);
            }
        });

        return info;
    }

    /**
     * Extract homepage information
     */
    extractHomepageInfo() {
        const info = {};

        // Check for featured listings
        const featuredListings = document.querySelectorAll('.featured-listing, .featured-property, .mld-featured');
        info.featured_count = featuredListings.length;

        // Check for search form
        info.has_search_form = !!document.querySelector('.property-search, .mld-search-form, [class*="search-form"]');

        return info;
    }

    /**
     * Extract content from regular WordPress pages
     */
    extractPageContent() {
        const content = {};

        // Get page title
        const h1 = document.querySelector('h1, .entry-title, .page-title');
        if (h1) {
            content.heading = h1.textContent.trim();
        }

        // Get main content summary (first 500 chars)
        const mainContent = document.querySelector('main, .entry-content, .page-content, article, .content');
        if (mainContent) {
            let text = mainContent.innerText.replace(/\s+/g, ' ').trim();
            if (text.length > 500) {
                text = text.substring(0, 500) + '...';
            }
            content.summary = text;
        }

        // Check for specific content features
        content.has_forms = !!document.querySelector('form:not([class*="search"])');
        content.has_contact_info = !!(
            document.body.innerText.match(/\(\d{3}\)\s*\d{3}-\d{4}/) || // Phone
            document.body.innerText.match(/[\w.-]+@[\w.-]+\.\w+/) // Email
        );

        return content;
    }

    startHeartbeat() {
        // Send heartbeat every 2 minutes to keep session alive
        this.heartbeatInterval = setInterval(() => {
            this.sendHeartbeat();
        }, 120000); // 2 minutes
    }

    async sendHeartbeat() {
        const formData = new FormData();
        formData.append('action', 'mld_session_heartbeat');
        formData.append('nonce', this.nonce);
        formData.append('session_id', this.sessionId);

        try {
            await fetch(this.ajaxUrl, {
                method: 'POST',
                body: formData
            });
        } catch (error) {
            console.error('[MLD Chatbot] Heartbeat failed:', error);
        }
    }

    async endSession() {
        const formData = new FormData();
        formData.append('action', 'mld_session_close');
        formData.append('nonce', this.nonce);
        formData.append('session_id', this.sessionId);

        try {
            await fetch(this.ajaxUrl, {
                method: 'POST',
                body: formData
            });
        } catch (error) {
            console.error('[MLD Chatbot] End session failed:', error);
        }
    }

    handlePageUnload() {
        window.addEventListener('beforeunload', () => {
            // Use sendBeacon for reliable delivery on page unload
            if (this.isOpen) {
                const formData = new FormData();
                formData.append('action', 'mld_session_close');
                formData.append('nonce', this.nonce);
                formData.append('session_id', this.sessionId);

                const blob = new Blob([new URLSearchParams(formData).toString()], {
                    type: 'application/x-www-form-urlencoded'
                });

                navigator.sendBeacon(this.ajaxUrl, blob);
            }

            // Cleanup heartbeat
            if (this.heartbeatInterval) {
                clearInterval(this.heartbeatInterval);
            }
        });
    }

    scrollToBottom() {
        const messagesContainer = document.getElementById('mld-chat-messages');
        if (messagesContainer) {
            setTimeout(() => {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }, 100);
        }
    }

    getCurrentTime() {
        const now = new Date();
        return now.toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    }

    getDeviceType() {
        const ua = navigator.userAgent;
        if (/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i.test(ua)) {
            return 'tablet';
        }
        if (/Mobile|Android|iP(hone|od)|IEMobile|BlackBerry|Kindle|Silk-Accelerated|(hpw|web)OS|Opera M(obi|ini)/.test(ua)) {
            return 'mobile';
        }
        return 'desktop';
    }

    getBrowser() {
        const ua = navigator.userAgent;
        let browser = 'Unknown';

        if (ua.includes('Firefox/')) browser = 'Firefox';
        else if (ua.includes('Edg/')) browser = 'Edge';
        else if (ua.includes('Chrome/')) browser = 'Chrome';
        else if (ua.includes('Safari/')) browser = 'Safari';

        return browser;
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Convert URLs in text to clickable links (v6.27.5)
     * Call this AFTER escapeHtml to safely make URLs clickable
     */
    linkifyUrls(text) {
        // Pattern to match URLs (http, https, or www)
        const urlPattern = /(\b(https?:\/\/|www\.)[^\s<]+[^\s<.,;:!?)\]'"])/gi;

        return text.replace(urlPattern, (match) => {
            let url = match;
            // Add https:// if URL starts with www.
            if (url.startsWith('www.')) {
                url = 'https://' + url;
            }
            // Create clickable link that opens in new tab
            return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="mld-chat-link">${match}</a>`;
        });
    }

    /**
     * Format message text for display (v6.27.5)
     * Handles line breaks, URLs, and basic formatting
     */
    formatMessageText(text) {
        // First escape HTML for security
        let formatted = this.escapeHtml(text);

        // Convert URLs to clickable links
        formatted = this.linkifyUrls(formatted);

        // Convert line breaks to <br> tags
        formatted = formatted.replace(/\n/g, '<br>');

        // Convert **bold** to <strong>
        formatted = formatted.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');

        // Convert numbered lists (1. 2. 3.)
        formatted = formatted.replace(/^(\d+)\.\s+(.+)$/gm, '<span class="mld-list-item">$1. $2</span>');

        return formatted;
    }

    // ==========================================
    // Lead Capture Gate Methods (v6.27.0)
    // ==========================================

    /**
     * Check if lead has already been captured (from localStorage or WP user)
     */
    checkLeadCaptureState() {
        // Check if logged-in user has complete profile
        if (this.config.user && this.config.user.id > 0) {
            const hasName = this.config.user.name && this.config.user.name.trim() !== '';
            const hasEmail = this.config.user.email && this.config.user.email.trim() !== '';

            if (hasName && hasEmail) {
                // Auto-fill lead data and skip gate
                this.leadData = {
                    name: this.config.user.name,
                    email: this.config.user.email,
                    phone: this.config.user.phone || ''
                };
                this.isLeadCaptured = true;
                return;
            }
        }

        // Check localStorage for previously captured lead
        try {
            const stored = localStorage.getItem(this.leadGateKey);
            if (stored) {
                const data = JSON.parse(stored);
                // Validate stored data (must have name and email OR phone)
                if (data.name && (data.email || data.phone)) {
                    this.leadData = data;
                    this.isLeadCaptured = true;
                    return;
                }
            }
        } catch (e) {
            console.error('[MLD Chatbot] Error reading lead data:', e);
        }
    }

    /**
     * Pre-fill lead form with any available WordPress user data
     */
    prefillLeadForm() {
        if (!this.config.user || this.config.user.id === 0) {
            return;
        }

        const nameInput = document.getElementById('mld-lead-name');
        const emailInput = document.getElementById('mld-lead-email');
        const phoneInput = document.getElementById('mld-lead-phone');

        if (nameInput && this.config.user.name) {
            nameInput.value = this.config.user.name;
        }
        if (emailInput && this.config.user.email) {
            emailInput.value = this.config.user.email;
        }
        if (phoneInput && this.config.user.phone) {
            phoneInput.value = this.config.user.phone;
        }

        // Update hint based on pre-filled values
        this.updateContactHint();
    }

    /**
     * Handle lead form submission
     */
    async handleLeadSubmit() {
        const nameInput = document.getElementById('mld-lead-name');
        const emailInput = document.getElementById('mld-lead-email');
        const phoneInput = document.getElementById('mld-lead-phone');
        const submitBtn = document.getElementById('mld-lead-submit');

        // Clear previous errors
        this.clearLeadErrors();

        // Get values
        const name = nameInput?.value.trim() || '';
        const email = emailInput?.value.trim() || '';
        const phone = phoneInput?.value.trim() || '';

        // Validate
        let hasErrors = false;

        if (!name) {
            this.showLeadError('mld-name-error', 'Name is required');
            hasErrors = true;
        }

        if (!email && !phone) {
            this.showLeadError('mld-email-error', 'Please provide email or phone');
            this.showLeadError('mld-phone-error', 'Please provide email or phone');
            hasErrors = true;
        } else {
            if (email && !this.isValidEmail(email)) {
                this.showLeadError('mld-email-error', 'Please enter a valid email');
                hasErrors = true;
            }
            if (phone && !this.isValidPhone(phone)) {
                this.showLeadError('mld-phone-error', 'Please enter a valid phone number');
                hasErrors = true;
            }
        }

        if (hasErrors) {
            return;
        }

        // Show loading state
        submitBtn.disabled = true;
        const originalHTML = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span>Saving...</span>';

        // Save lead data
        this.leadData = { name, email, phone };

        try {
            // Send to backend
            await this.saveLeadToBackend();

            // Save to localStorage
            localStorage.setItem(this.leadGateKey, JSON.stringify(this.leadData));

            // Mark as captured
            this.isLeadCaptured = true;

            // Transition to chat
            this.transitionToChat();

        } catch (error) {
            console.error('[MLD Chatbot] Error saving lead:', error);
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalHTML;
            this.showLeadError('mld-name-error', 'An error occurred. Please try again.');
        }
    }

    /**
     * Save lead data to backend via AJAX
     */
    async saveLeadToBackend() {
        const formData = new FormData();
        formData.append('action', 'mld_chat_update_user_info');
        formData.append('nonce', this.nonce);
        formData.append('session_id', this.sessionId);
        formData.append('user_data', JSON.stringify({
            name: this.leadData.name,
            email: this.leadData.email,
            phone: this.leadData.phone
        }));

        const response = await fetch(this.ajaxUrl, {
            method: 'POST',
            body: formData
        });

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.data?.message || 'Failed to save user info');
        }

        return result;
    }

    /**
     * Transition from lead gate to chat window with personalized greeting
     */
    transitionToChat() {
        const leadGate = document.getElementById('mld-chat-lead-gate');
        const chatWindow = document.getElementById('mld-chat-window');

        // Fade out lead gate
        leadGate.style.opacity = '0';
        leadGate.style.transition = 'opacity 0.3s';

        setTimeout(() => {
            leadGate.style.display = 'none';
            leadGate.style.opacity = '1';

            // Show chat window
            chatWindow.style.display = 'flex';
            this.scrollToBottom();

            // Add personalized greeting if name provided
            if (this.leadData.name) {
                const firstName = this.leadData.name.split(' ')[0];
                this.addMessage(
                    `Great to meet you, ${firstName}! How can I help you find your perfect property today?`,
                    'bot'
                );
            }

            // Focus input
            setTimeout(() => {
                document.getElementById('mld-chat-input')?.focus();
            }, 300);
        }, 300);
    }

    /**
     * Validate email format
     */
    isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    /**
     * Validate phone format (at least 10 digits)
     */
    isValidPhone(phone) {
        const digits = phone.replace(/\D/g, '');
        return digits.length >= 10 && digits.length <= 15;
    }

    /**
     * Show error message for a form field
     */
    showLeadError(elementId, message) {
        const errorEl = document.getElementById(elementId);
        if (errorEl) {
            errorEl.textContent = message;
            errorEl.style.display = 'block';
        }
    }

    /**
     * Clear all lead form errors
     */
    clearLeadErrors() {
        const errors = document.querySelectorAll('.mld-field-error');
        errors.forEach(el => {
            el.textContent = '';
            el.style.display = 'none';
        });
    }

    /**
     * Update contact hint based on current email/phone values
     */
    updateContactHint() {
        const email = document.getElementById('mld-lead-email')?.value.trim();
        const phone = document.getElementById('mld-lead-phone')?.value.trim();
        const hint = document.getElementById('mld-contact-hint');

        if (hint) {
            if (email || phone) {
                hint.classList.add('mld-hint-satisfied');
            } else {
                hint.classList.remove('mld-hint-satisfied');
            }
        }
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        window.mldChatbotWidget = new MLDChatbotWidget();
    });
} else {
    window.mldChatbotWidget = new MLDChatbotWidget();
}

// export default MLDChatbotWidget; // Commented out - not using ES modules
