/**
 * MLD Real-time Client
 *
 * WebSocket client for real-time collaboration features
 * Version: 1.0.0
 */

(function () {
  'use strict';

  class MLDRealtimeClient {
    constructor(options = {}) {
      this.options = {
        serverUrl: options.serverUrl || 'ws://localhost:8080',
        reconnectInterval: 5000,
        maxReconnectAttempts: 10,
        heartbeatInterval: 30000,
        enableDebug: options.debug || false,
        ...options,
      };

      this.ws = null;
      this.connectionId = null;
      this.isConnected = false;
      this.reconnectAttempts = 0;
      this.heartbeatTimer = null;
      this.eventHandlers = new Map();
      this.currentProperty = null;
      this.collaborationId = null;
      this.userCursors = new Map();

      // Bind methods
      this.connect = this.connect.bind(this);
      this.disconnect = this.disconnect.bind(this);
      this.reconnect = this.reconnect.bind(this);
      this.handleMessage = this.handleMessage.bind(this);
    }

    /**
     * Connect to WebSocket server
     * @param token
     */
    connect(token = null) {
      if (this.ws && this.ws.readyState === WebSocket.OPEN) {
        this.log('Already connected');
        return Promise.resolve();
      }

      return new Promise((resolve, reject) => {
        try {
          // Build connection URL with authentication
          let url = this.options.serverUrl;
          if (token) {
            url += `?token=${encodeURIComponent(token)}`;
          }

          this.ws = new WebSocket(url);

          // Connection opened
          this.ws.onopen = () => {
            this.log('Connected to real-time server');
            this.isConnected = true;
            this.reconnectAttempts = 0;

            // Start heartbeat
            this.startHeartbeat();

            // Trigger connected event
            this.emit('connected');

            resolve();
          };

          // Message received
          this.ws.onmessage = (event) => {
            this.handleMessage(event.data);
          };

          // Connection closed
          this.ws.onclose = (event) => {
            this.log('Disconnected from server', event.code, event.reason);
            this.isConnected = false;
            this.stopHeartbeat();

            // Trigger disconnected event
            this.emit('disconnected');

            // Attempt reconnection if not intentional disconnect
            if (event.code !== 1000) {
              this.reconnect();
            }
          };

          // Error occurred
          this.ws.onerror = (error) => {
            this.log('WebSocket error', error);
            this.emit('error', error);
            reject(error);
          };
        } catch (error) {
          this.log('Failed to create WebSocket', error);
          reject(error);
        }
      });
    }

    /**
     * Disconnect from server
     */
    disconnect() {
      if (this.ws) {
        this.ws.close(1000, 'Client disconnect');
        this.ws = null;
      }
      this.isConnected = false;
      this.stopHeartbeat();
    }

    /**
     * Reconnect to server
     */
    reconnect() {
      if (this.reconnectAttempts >= this.options.maxReconnectAttempts) {
        this.log('Max reconnection attempts reached');
        this.emit('reconnect_failed');
        return;
      }

      this.reconnectAttempts++;
      this.log(`Reconnecting... (attempt ${this.reconnectAttempts})`);

      setTimeout(() => {
        this.connect().catch(() => {
          // Reconnection failed, will try again
        });
      }, this.options.reconnectInterval);
    }

    /**
     * Send message to server
     * @param type
     * @param data
     */
    send(type, data = {}) {
      if (!this.isConnected || !this.ws) {
        this.log('Not connected, queuing message');
        // Could implement message queue here
        return false;
      }

      const message = JSON.stringify({ type, data });
      this.ws.send(message);
      return true;
    }

    /**
     * Handle incoming message
     * @param data
     */
    handleMessage(data) {
      try {
        const message = JSON.parse(data);

        if (!message.type) {
          return;
        }

        this.log('Message received:', message.type);

        // Handle system messages
        switch (message.type) {
          case 'welcome':
            this.handleWelcome(message.data);
            break;

          case 'pong':
            // Heartbeat response
            break;

          case 'property_viewers_update':
            this.handlePropertyViewersUpdate(message.data);
            break;

          case 'favorite_update':
            this.handleFavoriteUpdate(message.data);
            break;

          case 'collaboration_invite':
            this.handleCollaborationInvite(message.data);
            break;

          case 'collaboration_started':
            this.handleCollaborationStarted(message.data);
            break;

          case 'collaboration_message':
            this.handleCollaborationMessage(message.data);
            break;

          case 'cursor_update':
            this.handleCursorUpdate(message.data);
            break;

          case 'property_note_added':
            this.handlePropertyNoteAdded(message.data);
            break;

          case 'search_shared':
            this.handleSearchShared(message.data);
            break;

          case 'typing_indicator':
            this.handleTypingIndicator(message.data);
            break;

          case 'user_status':
            this.handleUserStatus(message.data);
            break;

          case 'price_alert_set':
            this.handlePriceAlertSet(message.data);
            break;

          default:
            // Emit custom event
            this.emit(message.type, message.data);
        }
      } catch (error) {
        this.log('Failed to parse message', error);
      }
    }

    /**
     * Handle welcome message
     * @param data
     */
    handleWelcome(data) {
      this.connectionId = data.connection_id;
      this.emit('welcome', data);
    }

    /**
     * Handle property viewers update
     * @param data
     */
    handlePropertyViewersUpdate(data) {
      // Update UI to show current viewers
      const viewersElement = document.querySelector(
        `[data-property-id="${data.property_id}"] .viewers-count`
      );
      if (viewersElement) {
        viewersElement.textContent = data.count;

        // Show viewer avatars if available
        if (data.viewers) {
          this.updateViewerAvatars(data.property_id, data.viewers);
        }
      }

      this.emit('viewers_update', data);
    }

    /**
     * Handle favorite update
     * @param data
     */
    handleFavoriteUpdate(data) {
      // Show notification
      this.showNotification(
        `${data.user_name} ${data.is_favorited ? 'favorited' : 'unfavorited'} a property`
      );

      // Update UI if viewing same property
      if (this.currentProperty === data.property_id) {
        this.updateFavoriteIndicator(data);
      }

      this.emit('favorite_update', data);
    }

    /**
     * Handle collaboration invite
     * @param data
     */
    handleCollaborationInvite(data) {
      // Show invitation modal
      this.showCollaborationInvite(data);
      this.emit('collaboration_invite', data);
    }

    /**
     * Handle collaboration started
     * @param data
     */
    handleCollaborationStarted(data) {
      this.collaborationId = data.collaboration_id;
      this.emit('collaboration_started', data);
    }

    /**
     * Handle collaboration message
     * @param data
     */
    handleCollaborationMessage(data) {
      // Add message to chat
      this.addChatMessage(data);
      this.emit('collaboration_message', data);
    }

    /**
     * Handle cursor update
     * @param data
     */
    handleCursorUpdate(data) {
      // Update or create cursor for user
      this.updateUserCursor(data);
      this.emit('cursor_update', data);
    }

    /**
     * Handle property note added
     * @param data
     */
    handlePropertyNoteAdded(data) {
      // Add note to UI
      this.addPropertyNote(data);
      this.emit('property_note_added', data);
    }

    /**
     * Handle search shared
     * @param data
     */
    handleSearchShared(data) {
      // Show shared search notification
      this.showNotification(`${data.user_name} shared a search with you`);
      this.emit('search_shared', data);
    }

    /**
     * Handle typing indicator
     * @param data
     */
    handleTypingIndicator(data) {
      // Show/hide typing indicator
      this.updateTypingIndicator(data);
      this.emit('typing_indicator', data);
    }

    /**
     * Handle user status
     * @param data
     */
    handleUserStatus(data) {
      // Update user status indicator
      this.updateUserStatus(data);
      this.emit('user_status', data);
    }

    /**
     * Handle price alert set
     * @param data
     */
    handlePriceAlertSet(data) {
      // Show confirmation
      this.showNotification(data.message);
      this.emit('price_alert_set', data);
    }

    /**
     * View property
     * @param propertyId
     */
    viewProperty(propertyId) {
      this.currentProperty = propertyId;
      this.send('view_property', { property_id: propertyId });
    }

    /**
     * Leave property
     * @param propertyId
     */
    leaveProperty(propertyId) {
      if (this.currentProperty === propertyId) {
        this.currentProperty = null;
      }
      this.send('leave_property', { property_id: propertyId });
    }

    /**
     * Toggle favorite
     * @param propertyId
     * @param isFavorited
     */
    toggleFavorite(propertyId, isFavorited) {
      this.send('favorite_toggle', {
        property_id: propertyId,
        is_favorited: isFavorited,
      });
    }

    /**
     * Start collaboration
     * @param propertyId
     * @param invitedUsers
     */
    startCollaboration(propertyId, invitedUsers = []) {
      this.send('start_collaboration', {
        property_id: propertyId,
        invited_users: invitedUsers,
      });
    }

    /**
     * Send collaboration message
     * @param message
     */
    sendCollaborationMessage(message) {
      if (!this.collaborationId) return;

      this.send('collaboration_message', {
        collaboration_id: this.collaborationId,
        message,
      });
    }

    /**
     * Send cursor position
     * @param x
     * @param y
     */
    sendCursorPosition(x, y) {
      if (!this.currentProperty) return;

      this.send('cursor_move', {
        property_id: this.currentProperty,
        x,
        y,
      });
    }

    /**
     * Add property note
     * @param propertyId
     * @param note
     * @param isPrivate
     */
    addNote(propertyId, note, isPrivate = false) {
      this.send('property_note', {
        property_id: propertyId,
        note,
        is_private: isPrivate,
      });
    }

    /**
     * Share search
     * @param filters
     */
    shareSearch(filters) {
      this.send('search_update', {
        filters,
        collaboration_id: this.collaborationId,
      });
    }

    /**
     * Send typing indicator
     * @param isTyping
     * @param context
     */
    sendTypingIndicator(isTyping, context = 'chat') {
      this.send('typing_indicator', {
        is_typing: isTyping,
        context,
        property_id: this.currentProperty,
      });
    }

    /**
     * Set price alert
     * @param propertyId
     * @param alertType
     * @param threshold
     */
    setPriceAlert(propertyId, alertType, threshold = null) {
      this.send('price_alert', {
        property_id: propertyId,
        alert_type: alertType,
        threshold,
      });
    }

    /**
     * Start heartbeat
     */
    startHeartbeat() {
      this.stopHeartbeat();
      this.heartbeatTimer = setInterval(() => {
        if (this.isConnected) {
          this.send('ping');
        }
      }, this.options.heartbeatInterval);
    }

    /**
     * Stop heartbeat
     */
    stopHeartbeat() {
      if (this.heartbeatTimer) {
        clearInterval(this.heartbeatTimer);
        this.heartbeatTimer = null;
      }
    }

    /**
     * Update viewer avatars
     * @param propertyId
     * @param viewers
     */
    updateViewerAvatars(propertyId, viewers) {
      const container = document.querySelector(
        `[data-property-id="${propertyId}"] .viewer-avatars`
      );
      if (!container) return;

      container.innerHTML = '';
      viewers.slice(0, 5).forEach((viewer) => {
        const avatar = document.createElement('div');
        avatar.className = 'viewer-avatar';
        avatar.title = viewer.user_name;
        avatar.textContent = viewer.user_name.charAt(0).toUpperCase();
        container.appendChild(avatar);
      });

      if (viewers.length > 5) {
        const more = document.createElement('div');
        more.className = 'viewer-avatar more';
        more.textContent = `+${viewers.length - 5}`;
        container.appendChild(more);
      }
    }

    /**
     * Update favorite indicator
     * @param data
     */
    updateFavoriteIndicator(data) {
      // Implementation depends on UI structure
    }

    /**
     * Show collaboration invite
     * @param data
     */
    showCollaborationInvite(data) {
      // Create and show modal
      const modal = document.createElement('div');
      modal.className = 'mld-collaboration-invite-modal';
      modal.innerHTML = `
                <div class="modal-content">
                    <h3>Collaboration Invitation</h3>
                    <p>${data.message}</p>
                    <div class="modal-actions">
                        <button class="btn-accept">Accept</button>
                        <button class="btn-decline">Decline</button>
                    </div>
                </div>
            `;

      document.body.appendChild(modal);

      // Handle accept/decline
      modal.querySelector('.btn-accept').addEventListener('click', () => {
        this.acceptCollaborationInvite(data.collaboration_id);
        modal.remove();
      });

      modal.querySelector('.btn-decline').addEventListener('click', () => {
        modal.remove();
      });
    }

    /**
     * Accept collaboration invite
     * @param collaborationId
     */
    acceptCollaborationInvite(collaborationId) {
      this.collaborationId = collaborationId;
      // Join collaboration
      this.send('join_collaboration', { collaboration_id: collaborationId });
    }

    /**
     * Add chat message
     * @param data
     */
    addChatMessage(data) {
      const chatContainer = document.querySelector('.mld-collaboration-chat');
      if (!chatContainer) return;

      const message = document.createElement('div');
      message.className = 'chat-message';
      message.innerHTML = `
                <span class="user-name">${data.user_name}:</span>
                <span class="message-text">${data.message}</span>
                <span class="message-time">${new Date(data.timestamp * 1000).toLocaleTimeString()}</span>
            `;

      chatContainer.appendChild(message);
      chatContainer.scrollTop = chatContainer.scrollHeight;
    }

    /**
     * Update user cursor
     * @param data
     */
    updateUserCursor(data) {
      let cursor = this.userCursors.get(data.user_id);

      if (!cursor) {
        cursor = document.createElement('div');
        cursor.className = 'mld-user-cursor';
        cursor.innerHTML = `
                    <svg width="20" height="20" viewBox="0 0 20 20">
                        <path d="M0,0 L0,15 L5,12 L8,18 L10,17 L7,11 L12,11 Z" fill="${data.color}"/>
                    </svg>
                    <span class="cursor-label">${data.user_name}</span>
                `;
        document.body.appendChild(cursor);
        this.userCursors.set(data.user_id, cursor);
      }

      cursor.style.left = `${data.x}px`;
      cursor.style.top = `${data.y}px`;

      // Hide cursor after inactivity
      clearTimeout(cursor.hideTimeout);
      cursor.hideTimeout = setTimeout(() => {
        cursor.style.display = 'none';
      }, 5000);
      cursor.style.display = 'block';
    }

    /**
     * Add property note to UI
     * @param data
     */
    addPropertyNote(data) {
      const notesContainer = document.querySelector(
        `[data-property-id="${data.property_id}"] .property-notes`
      );
      if (!notesContainer) return;

      const note = document.createElement('div');
      note.className = 'property-note';
      note.innerHTML = `
                <div class="note-header">
                    <span class="note-author">${data.user_name}</span>
                    <span class="note-time">${new Date(data.timestamp * 1000).toLocaleString()}</span>
                </div>
                <div class="note-content">${data.note}</div>
            `;

      notesContainer.appendChild(note);
    }

    /**
     * Update typing indicator
     * @param data
     */
    updateTypingIndicator(data) {
      const indicator = document.querySelector('.typing-indicator');
      if (!indicator) return;

      if (data.is_typing) {
        indicator.textContent = `${data.user_name} is typing...`;
        indicator.style.display = 'block';
      } else {
        indicator.style.display = 'none';
      }
    }

    /**
     * Update user status
     * @param data
     */
    updateUserStatus(data) {
      const statusElement = document.querySelector(
        `[data-user-id="${data.user_id}"] .status-indicator`
      );
      if (statusElement) {
        statusElement.className = `status-indicator ${data.status}`;
      }
    }

    /**
     * Show notification
     * @param message
     * @param type
     */
    showNotification(message, type = 'info') {
      const notification = document.createElement('div');
      notification.className = `mld-notification ${type}`;
      notification.textContent = message;

      document.body.appendChild(notification);

      // Animate in
      setTimeout(() => notification.classList.add('show'), 10);

      // Auto remove after 5 seconds
      setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => notification.remove(), 300);
      }, 5000);
    }

    /**
     * Register event handler
     * @param event
     * @param handler
     */
    on(event, handler) {
      if (!this.eventHandlers.has(event)) {
        this.eventHandlers.set(event, []);
      }
      this.eventHandlers.get(event).push(handler);
    }

    /**
     * Remove event handler
     * @param event
     * @param handler
     */
    off(event, handler) {
      if (!this.eventHandlers.has(event)) return;

      const handlers = this.eventHandlers.get(event);
      const index = handlers.indexOf(handler);
      if (index > -1) {
        handlers.splice(index, 1);
      }
    }

    /**
     * Emit event
     * @param event
     * @param data
     */
    emit(event, data = null) {
      if (!this.eventHandlers.has(event)) return;

      this.eventHandlers.get(event).forEach((handler) => {
        try {
          handler(data);
        } catch (error) {
          MLDLogger.error(`Error in event handler for ${event}:`, error);
        }
      });
    }

    /**
     * Log message
     * @param {...any} args
     */
    log(...args) {
      if (this.options.enableDebug) {
        MLDLogger.debug('[MLDRealtimeClient]', ...args);
      }
    }
  }

  // Export for use
  window.MLDRealtimeClient = MLDRealtimeClient;
})();
