/**
 * Chatbot Session Manager
 * Handles session persistence across page navigation
 *
 * @package MLS_Listings_Display
 * @since 6.6.0
 * @version 6.11.43 - Full screen mobile chatbot with keyboard handling
 */

(function() {
    'use strict';

    const SESSION_KEY = 'mld_chatbot_session';
    const SESSION_EXPIRY = 30 * 60 * 1000; // 30 minutes in milliseconds
    const MESSAGES_KEY = 'mld_chatbot_messages';
    const MAX_STORED_MESSAGES = 50; // Limit localStorage size

    /**
     * Session Manager Class
     */
    class ChatbotSessionManager {
        constructor() {
            this.session = null;
            this.messageHistory = [];
            this.scrollTriggeredOpen = false;
            this.init();
        }

        /**
         * Initialize session manager
         */
        init() {
            // Load existing session or create new one
            this.loadSession();

            // Hook into widget initialization
            this.hookIntoWidget();

            // Initialize scroll-triggered open/close for property pages
            this.initScrollTrigger();

            // Initialize mobile keyboard handling
            this.initMobileKeyboardHandler();

            // Listen for page unload to save state
            window.addEventListener('beforeunload', () => this.saveState());
        }

        /**
         * Load session from storage
         */
        loadSession() {
            try {
                const stored = localStorage.getItem(SESSION_KEY);

                if (stored) {
                    const sessionData = JSON.parse(stored);
                    const now = Date.now();

                    // Check if session is still valid
                    if (sessionData.expiresAt > now) {
                        this.session = sessionData;
                        this.loadMessageHistory();
                        return;
                    } else {
                        this.clearSession();
                    }
                }
            } catch (e) {
                console.error('[MLD Chatbot] Error loading session:', e);
            }

            // Create new session if none exists or expired
            this.createNewSession();
        }

        /**
         * Create a new session
         */
        createNewSession() {
            this.session = {
                id: this.generateSessionId(),
                createdAt: Date.now(),
                expiresAt: Date.now() + SESSION_EXPIRY,
                isOpen: false
            };

            this.saveSession();
        }

        /**
         * Generate unique session ID
         */
        generateSessionId() {
            return 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        }

        /**
         * Save session to storage
         */
        saveSession() {
            try {
                localStorage.setItem(SESSION_KEY, JSON.stringify(this.session));
            } catch (e) {
                console.error('[MLD Chatbot] Error saving session:', e);
            }
        }

        /**
         * Clear session
         */
        clearSession() {
            localStorage.removeItem(SESSION_KEY);
            localStorage.removeItem(MESSAGES_KEY);
            this.session = null;
            this.messageHistory = [];
        }

        /**
         * Update session expiry (call on user activity)
         */
        updateExpiry() {
            if (this.session) {
                this.session.expiresAt = Date.now() + SESSION_EXPIRY;
                this.saveSession();
            }
        }

        /**
         * Load message history from storage
         */
        loadMessageHistory() {
            try {
                const stored = localStorage.getItem(MESSAGES_KEY);
                if (stored) {
                    const data = JSON.parse(stored);

                    // Verify session ID matches
                    if (data.sessionId === this.session.id) {
                        this.messageHistory = data.messages || [];
                    }
                }
            } catch (e) {
                console.error('[MLD Chatbot] Error loading message history:', e);
            }
        }

        /**
         * Save message history to storage
         */
        saveMessageHistory() {
            try {
                const data = {
                    sessionId: this.session.id,
                    messages: this.messageHistory.slice(-MAX_STORED_MESSAGES) // Keep only recent messages
                };
                localStorage.setItem(MESSAGES_KEY, JSON.stringify(data));
            } catch (e) {
                console.error('[MLD Chatbot] Error saving message history:', e);
            }
        }

        /**
         * Add message to history
         */
        addMessage(message, role = 'user') {
            this.messageHistory.push({
                role: role,
                content: message,
                timestamp: Date.now()
            });
            this.saveMessageHistory();
            this.updateExpiry();
        }

        /**
         * Save current state
         */
        saveState() {
            if (this.session) {
                this.saveSession();
                this.saveMessageHistory();
            }
        }

        /**
         * Hook into the chatbot widget to inject session ID and restore messages
         */
        hookIntoWidget() {
            const self = this;

            // Wait for widget to be ready
            const checkWidget = setInterval(() => {
                if (window.mldChatbotWidget) {
                    clearInterval(checkWidget);

                    // Override session ID
                    if (self.session) {
                        window.mldChatbotWidget.sessionId = self.session.id;

                        // Restore chat state if it was open
                        if (self.session.isOpen) {
                            setTimeout(() => {
                                self.restoreMessages();
                            }, 500);
                        } else {
                            // Just restore messages without opening
                            self.restoreMessages();
                        }
                    }

                    // Intercept the original sendMessage to track messages
                    const originalSendMessage = window.mldChatbotWidget.sendMessage.bind(window.mldChatbotWidget);
                    window.mldChatbotWidget.sendMessage = async function() {
                        const input = document.getElementById('mld-chat-input');
                        if (input && input.value.trim()) {
                            self.addMessage(input.value.trim(), 'user');
                        }
                        return originalSendMessage();
                    };

                    // Intercept addMessage to track bot responses
                    const originalAddMessage = window.mldChatbotWidget.addMessage.bind(window.mldChatbotWidget);
                    window.mldChatbotWidget.addMessage = function(message, role = 'bot', options = {}) {
                        if (role === 'bot') {
                            self.addMessage(message, 'bot');
                        }
                        return originalAddMessage(message, role, options);
                    };

                    // Track chat open/close state
                    const originalOpenChat = window.mldChatbotWidget.openChat.bind(window.mldChatbotWidget);
                    window.mldChatbotWidget.openChat = function() {
                        self.session.isOpen = true;
                        self.saveSession();
                        return originalOpenChat();
                    };

                    const originalCloseChat = window.mldChatbotWidget.closeChat.bind(window.mldChatbotWidget);
                    window.mldChatbotWidget.closeChat = function() {
                        self.session.isOpen = false;
                        self.saveSession();
                        return originalCloseChat();
                    };
                }
            }, 100);

            // Stop checking after 10 seconds
            setTimeout(() => clearInterval(checkWidget), 10000);
        }

        /**
         * Initialize scroll-triggered chatbot open/close
         * Only on property detail pages with gallery
         * @since 6.11.15
         * @updated 6.11.16 - Added close trigger at Comparable Properties section
         */
        initScrollTrigger() {
            const self = this;
            const gallery = document.querySelector('.mld-v3-hero-gallery');

            // Only activate on pages with the V3 gallery (property details)
            if (!gallery) return;

            const galleryHeight = gallery.offsetHeight;
            const triggerPoint = galleryHeight / 2; // Gallery top at mid-screen

            window.addEventListener('scroll', function() {
                const currentScrollY = window.scrollY;
                const chatbot = window.mldChatbotWidget;

                if (!chatbot) return;

                // Open when scrolled past trigger point (gallery top at mid-screen)
                if (currentScrollY >= triggerPoint && !chatbot.isOpen && !self.scrollTriggeredOpen) {
                    chatbot.openChat();
                    self.scrollTriggeredOpen = true;
                }

                // Close when scrolled back to top
                if (currentScrollY === 0 && chatbot.isOpen && self.scrollTriggeredOpen) {
                    chatbot.closeChat();
                    self.scrollTriggeredOpen = false;
                }

                // Close when bottom of viewport reaches Comparable Properties section
                const similarHomes = document.getElementById('similar-homes');
                if (similarHomes && chatbot.isOpen && self.scrollTriggeredOpen) {
                    const viewportBottom = currentScrollY + window.innerHeight;
                    const sectionTop = similarHomes.offsetTop;

                    if (viewportBottom >= sectionTop) {
                        chatbot.closeChat();
                        self.scrollTriggeredOpen = false;
                    }
                }
            }, { passive: true });
        }

        /**
         * Initialize mobile keyboard handling
         * Full screen chatbot that shrinks when keyboard opens
         * Tap conversation to dismiss keyboard
         * @since 6.11.20
         * @updated 6.11.42 - Full screen mode, dynamic height, tap to dismiss
         */
        initMobileKeyboardHandler() {
            // Only on mobile devices
            if (window.innerWidth > 480) return;

            // Get references lazily since chatbot is dynamically created
            const getChatWindow = () => document.getElementById('mld-chat-window');
            const getChatInput = () => document.getElementById('mld-chat-input');
            const getMessagesContainer = () => document.getElementById('mld-chat-messages');

            // Store initial full screen height
            let fullScreenHeight = window.innerHeight;

            // Setup tap-to-dismiss on messages container
            const setupTapToDismiss = () => {
                const messagesContainer = getMessagesContainer();
                if (!messagesContainer) {
                    setTimeout(setupTapToDismiss, 500);
                    return;
                }

                messagesContainer.addEventListener('click', (e) => {
                    // Don't dismiss if clicking on a link or button inside messages
                    if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON') return;

                    const chatInput = getChatInput();
                    if (chatInput && document.activeElement === chatInput) {
                        // Blur input to dismiss keyboard
                        chatInput.blur();
                    }
                });
            };

            setupTapToDismiss();

            // Use visualViewport API if available (modern browsers)
            if (window.visualViewport) {
                let keyboardOpen = false;

                const handleViewportResize = () => {
                    const chatWindow = getChatWindow();
                    if (!chatWindow) return;

                    const currentHeight = window.visualViewport.height;
                    const heightDiff = fullScreenHeight - currentHeight;

                    // Keyboard is open if viewport shrunk significantly (> 100px)
                    const isKeyboardNowOpen = heightDiff > 100;

                    if (isKeyboardNowOpen && !keyboardOpen) {
                        // Keyboard just opened
                        keyboardOpen = true;
                        chatWindow.classList.add('mld-keyboard-open');

                        // Set height to match visual viewport (above keyboard)
                        chatWindow.style.height = currentHeight + 'px';

                        // Scroll messages to bottom after a brief delay
                        setTimeout(() => {
                            const messagesContainer = getMessagesContainer();
                            if (messagesContainer) {
                                messagesContainer.scrollTop = messagesContainer.scrollHeight;
                            }
                        }, 100);
                    } else if (isKeyboardNowOpen && keyboardOpen) {
                        // Keyboard still open but height changed (e.g., suggestions bar)
                        chatWindow.style.height = currentHeight + 'px';
                    } else if (!isKeyboardNowOpen && keyboardOpen) {
                        // Keyboard just closed - return to full screen
                        keyboardOpen = false;
                        chatWindow.classList.remove('mld-keyboard-open');
                        chatWindow.style.height = ''; // Remove inline style, CSS takes over
                    }
                };

                window.visualViewport.addEventListener('resize', handleViewportResize);

                // Update full screen height on orientation change
                window.addEventListener('orientationchange', () => {
                    setTimeout(() => {
                        fullScreenHeight = window.innerHeight;
                        const chatWindow = getChatWindow();
                        if (chatWindow && !keyboardOpen) {
                            chatWindow.style.height = '';
                        }
                    }, 300);
                });
            } else {
                // Fallback for older browsers - use focus/blur events
                const setupFallback = () => {
                    const chatInput = getChatInput();
                    if (!chatInput) {
                        setTimeout(setupFallback, 500);
                        return;
                    }

                    chatInput.addEventListener('focus', () => {
                        const chatWindow = getChatWindow();
                        if (chatWindow) {
                            chatWindow.classList.add('mld-keyboard-open');
                            // Estimate keyboard height (~40% of screen)
                            const estimatedHeight = window.innerHeight * 0.6;
                            chatWindow.style.height = estimatedHeight + 'px';

                            setTimeout(() => {
                                const messagesContainer = getMessagesContainer();
                                if (messagesContainer) {
                                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                                }
                            }, 300);
                        }
                    });

                    chatInput.addEventListener('blur', () => {
                        const chatWindow = getChatWindow();
                        if (chatWindow) {
                            chatWindow.classList.remove('mld-keyboard-open');
                            chatWindow.style.height = ''; // Return to full screen
                        }
                    });
                };

                setupFallback();
            }
        }

        /**
         * Restore messages from history into the widget
         */
        restoreMessages() {
            if (!window.mldChatbotWidget || this.messageHistory.length === 0) {
                return;
            }

            const messagesContainer = document.getElementById('mld-chat-messages');
            if (!messagesContainer) {
                return;
            }

            // Clear existing messages (except greeting)
            const messages = messagesContainer.querySelectorAll('.mld-chat-message:not(:first-child)');
            messages.forEach(msg => msg.remove());

            // Add messages from history
            this.messageHistory.forEach(msg => {
                const role = msg.role === 'user' ? 'user' : 'bot';
                const messageDiv = document.createElement('div');
                messageDiv.className = `mld-chat-message ${role === 'user' ? 'mld-user-message' : 'mld-bot-message'}`;

                const time = new Date(msg.timestamp).toLocaleTimeString('en-US', {
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true
                });

                messageDiv.innerHTML = `
                    <div class="mld-message-content">
                        <p>${this.escapeHtml(msg.content)}</p>
                    </div>
                    <div class="mld-message-time">${time}</div>
                `;

                messagesContainer.appendChild(messageDiv);
            });

            // Scroll to bottom
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        /**
         * Escape HTML to prevent XSS
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        /**
         * Get current session ID
         */
        getSessionId() {
            return this.session ? this.session.id : null;
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            window.mldChatbotSessionManager = new ChatbotSessionManager();
        });
    } else {
        window.mldChatbotSessionManager = new ChatbotSessionManager();
    }

})();
