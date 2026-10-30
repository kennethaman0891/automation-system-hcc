/**
 * HCC WhatsApp Web Replica — Web Components (Part 1)
 * Authentic WhatsApp Web UI: Chat List, Chat Items, Class Banner, Avatars, Badges
 */

// ============ BASE COMPONENT ============

class WaElement extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.store = window.WhatsAppStore;
    this._boundHandlers = new Map();
  }
  
  connectedCallback() {
    this.store = this.store || window.WhatsAppStore;
    this.render();
    this.bindEvents();
    if (this.store) {
      this.store.addEventListener('store:ready', this.handleStoreReady = () => this.onStoreReady());
      if (this.store.db || (this.store.chats && this.store.chats.size > 0)) {
        this.onStoreReady();
      }
    }
  }
  
  disconnectedCallback() {
    if (this.store && this.handleStoreReady) {
      this.store.removeEventListener('store:ready', this.handleStoreReady);
    }
    this.unbindEvents();
  }
  
  render() {
    if (this.shadowRoot) {
      this.shadowRoot.innerHTML = this.template();
      this.applyStyles();
    }
  }
  
  template() { return ''; }
  applyStyles() {}
  
  onStoreReady() {}
  bindEvents() {}
  unbindEvents() {}
  
  $(selector) {
    return this.shadowRoot.querySelector(selector);
  }
  
  $$(selector) {
    return this.shadowRoot.querySelectorAll(selector);
  }
  
  dispatch(name, detail = {}) {
    this.dispatchEvent(new CustomEvent(name, { detail, bubbles: true, composed: true }));
  }
  
  escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const str = String(text);
    const map = { '&': '&amp;', '<': '&lt;', '(': '&#40;', ')': '&#41;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return str.replace(/[&<>"']/g, m => map[m]);
  }
  
  formatTime(timestamp) {
    if (!timestamp) return '';
    let ts = timestamp;
    if (typeof ts === 'string') {
      const parsed = Date.parse(ts);
      ts = !isNaN(parsed) ? parsed / 1000 : parseInt(ts, 10);
    }
    if (ts > 1e11) ts = Math.floor(ts / 1000); // ms to sec
    
    const date = new Date(ts * 1000);
    if (isNaN(date.getTime())) return '';
    
    const now = new Date();
    const diff = now - date;
    
    if (diff < 60000 && diff >= 0) return 'just now';
    if (diff < 3600000 && diff >= 0) return Math.floor(diff / 60000) + 'm';
    
    const isToday = date.toDateString() === now.toDateString();
    if (isToday) {
      return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: true });
    }
    
    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);
    if (date.toDateString() === yesterday.toDateString()) {
      return 'Yesterday';
    }
    
    if (diff < 7 * 86400000 && diff > 0) {
      return date.toLocaleDateString([], { weekday: 'short' });
    }
    
    return date.toLocaleDateString([], { day: '2-digit', month: '2-digit', year: '2-digit' });
  }
  
  formatDate(timestamp) {
    if (!timestamp) return '';
    let ts = timestamp;
    if (typeof ts === 'string') {
      const parsed = Date.parse(ts);
      ts = !isNaN(parsed) ? parsed / 1000 : parseInt(ts, 10);
    }
    if (ts > 1e11) ts = Math.floor(ts / 1000);
    
    const date = new Date(ts * 1000);
    if (isNaN(date.getTime())) return '';
    
    const now = new Date();
    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);
    
    if (date.toDateString() === now.toDateString()) return 'TODAY';
    if (date.toDateString() === yesterday.toDateString()) return 'YESTERDAY';
    return date.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).toUpperCase();
  }
  
  getInitials(name) {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }
  
  getAvatarColor(name) {
    const colors = [
      '#00a884', '#008069', '#128C7E', '#25D366', '#075E54',
      '#3498db', '#9b59b6', '#e74c3c', '#f39c12', '#1abc9c',
      '#2ecc71', '#34495e', '#7a2238', '#0284c7', '#d97706'
    ];
    let hash = 0;
    const str = name || 'User';
    for (let i = 0; i < str.length; i++) hash = str.charCodeAt(i) + ((hash << 5) - hash);
    return colors[Math.abs(hash) % colors.length];
  }
  
  getDeliveryTickSvg(status) {
    const s = String(status || '').toLowerCase();
    if (s === 'pending') {
      return `<svg class="wa-tick-svg pending" viewBox="0 0 16 16" width="16" height="16" fill="currentColor" title="Pending">
        <path d="M8 1a7 7 0 1 0 7 7A7.008 7.008 0 0 0 8 1zm0 12.6A5.6 5.6 0 1 1 13.6 8 5.606 5.606 0 0 1 8 13.6zm.7-8.4H7.3v4.2l3.6 2.15.7-1.15-2.9-1.75V5.2z"/>
      </svg>`;
    }
    if (s === 'sent') {
      return `<svg class="wa-tick-svg sent" viewBox="0 0 16 15" width="16" height="15" fill="currentColor" title="Sent">
        <path d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L6.466 12.07l-3.52-3.18a.366.366 0 0 0-.505.018l-.423.435a.366.366 0 0 0 .018.505l4.183 3.778c.07.063.16.098.254.098.094 0 .185-.035.255-.098l8.22-9.799a.365.365 0 0 0-.063-.51z"/>
      </svg>`;
    }
    if (s === 'delivered') {
      return `<svg class="wa-tick-svg delivered" viewBox="0 0 16 15" width="16" height="15" fill="currentColor" title="Delivered">
        <path d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L6.466 12.07l-1.92-1.73a.366.366 0 0 0-.505.018l-.423.435a.366.366 0 0 0 .018.505l2.583 2.333c.07.063.16.098.254.098.094 0 .185-.035.255-.098l8.22-9.799a.365.365 0 0 0-.063-.51z"/>
        <path d="M11.51 3.316l-.478-.372a.365.365 0 0 0-.51.063L2.966 12.07l-1.92-1.73a.366.366 0 0 0-.505.018l-.423.435a.366.366 0 0 0 .018.505l2.583 2.333c.07.063.16.098.254.098.094 0 .185-.035.255-.098l8.22-9.799a.365.365 0 0 0-.063-.51z"/>
      </svg>`;
    }
    if (s === 'read') {
      return `<svg class="wa-tick-svg read" viewBox="0 0 16 15" width="16" height="15" fill="#53bdeb" title="Read">
        <path d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L6.466 12.07l-1.92-1.73a.366.366 0 0 0-.505.018l-.423.435a.366.366 0 0 0 .018.505l2.583 2.333c.07.063.16.098.254.098.094 0 .185-.035.255-.098l8.22-9.799a.365.365 0 0 0-.063-.51z"/>
        <path d="M11.51 3.316l-.478-.372a.365.365 0 0 0-.51.063L2.966 12.07l-1.92-1.73a.366.366 0 0 0-.505.018l-.423.435a.366.366 0 0 0 .018.505l2.583 2.333c.07.063.16.098.254.098.094 0 .185-.035.255-.098l8.22-9.799a.365.365 0 0 0-.063-.51z"/>
      </svg>`;
    }
    if (s === 'failed') {
      return `<svg class="wa-tick-svg failed" viewBox="0 0 24 24" width="16" height="16" fill="#e74c3c" title="Failed">
        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
      </svg>`;
    }
    return '';
  }
}

// Expose on window for components2
window.WaElement = WaElement;

// ============ CHAT LIST ITEM ============

class WaChatItem extends WaElement {
  static get observedAttributes() { return ['chat-id', 'is-active']; }
  
  attributeChangedCallback(name, oldVal, newVal) {
    if (oldVal !== newVal) {
      if (name === 'chat-id') {
        this.render();
      } else if (name === 'is-active') {
        this.updateActiveState();
      }
    }
  }
  
  get chat() {
    const chatId = this.getAttribute('chat-id');
    if (!chatId || !this.store) return null;
    return this.store.getChat(parseInt(chatId, 10)) || this.store.chats?.get(parseInt(chatId, 10)) || null;
  }
  
  bindEvents() {
    this.boundChatUpdated = (e) => {
      const updatedChat = e.detail;
      const currentId = parseInt(this.getAttribute('chat-id'), 10);
      if (updatedChat && (updatedChat.id === currentId || updatedChat.chat_id === currentId)) {
        this.render();
      }
    };
    
    this.boundTyping = (e) => {
      const detail = e.detail || {};
      const currentId = parseInt(this.getAttribute('chat-id'), 10);
      if (detail.chatId === currentId || detail.chat_id === currentId) {
        const item = this.$('.wa-chat-item');
        if (item) {
          if (detail.isTyping) {
            item.classList.add('typing');
          } else {
            item.classList.remove('typing');
          }
        }
      }
    };
    
    if (this.store) {
      this.store.addEventListener('chats:updated', this.boundChatUpdated);
      this.store.addEventListener('chat:updated', this.boundChatUpdated);
      this.store.addEventListener('chat:typing', this.boundTyping);
    }
    
    // Click selection with shadowRoot delegation (survives innerHTML re-renders)
    this.shadowRoot.addEventListener('click', (e) => {
      if (e.target.closest('.wa-chat-chevron')) {
        e.stopPropagation();
        this.showContextMenu(e);
        return;
      }
      if (this.chat && this.store) {
        this.store.setActiveChat(this.chat.id);
      }
    });
    
    // Right-click context menu delegation
    this.shadowRoot.addEventListener('contextmenu', (e) => {
      e.preventDefault();
      this.showContextMenu(e);
    });
  }
  
  unbindEvents() {
    if (this.store) {
      if (this.boundChatUpdated) {
        this.store.removeEventListener('chats:updated', this.boundChatUpdated);
        this.store.removeEventListener('chat:updated', this.boundChatUpdated);
      }
      if (this.boundTyping) {
        this.store.removeEventListener('chat:typing', this.boundTyping);
      }
    }
  }
  
  updateActiveState() {
    const item = this.$('.wa-chat-item');
    if (!item) return;
    const isActive = this.getAttribute('is-active') === 'true' || 
                     (this.chat && this.store?.activeChatId === this.chat.id);
    item.classList.toggle('active', !!isActive);
  }
  
  template() {
    const chat = this.chat;
    if (!chat) {
      return `
        <style>
          .wa-chat-item.loading { padding: 14px 16px; color: var(--wa-text-tertiary-var, #8696a0); font-size: 13px; text-align: center; }
        </style>
        <div class="wa-chat-item loading">Loading contact...</div>
      `;
    }
    
    const isActive = this.store && (this.store.activeChatId === chat.id || this.store.activeChatId == chat.id);
    const isUnread = (chat.unread_count || 0) > 0;
    const isPinned = !!chat.is_pinned;
    const isMuted = !!chat.is_muted;
    const isTyping = !!(chat.is_typing || chat.typing);
    
    // Extract last message information
    let lastMsg = chat.last_message;
    if (!lastMsg && (chat.last_msg_body || chat.last_msg_id)) {
      lastMsg = {
        id: chat.last_msg_id,
        body: chat.last_msg_body,
        msg_type: chat.last_msg_type || 'text',
        from_me: !!chat.last_msg_from_me,
        timestamp: chat.last_msg_timestamp,
        status: chat.last_msg_status || 'sent'
      };
    }
    
    // Time formatting
    const timeVal = lastMsg?.timestamp || chat.last_activity_at;
    const formattedTime = timeVal ? this.formatTime(timeVal) : '';
    
    // Message preview construction
    let previewText = '';
    let previewIcon = '';
    
    if (lastMsg) {
      const type = lastMsg.msg_type || 'text';
      if (type === 'image') previewIcon = '📷 Photo';
      else if (type === 'video') previewIcon = '🎥 Video';
      else if (type === 'audio') previewIcon = '🎵 Audio';
      else if (type === 'document') previewIcon = '📄 Document';
      else if (type === 'location') previewIcon = '📍 Location';
      else if (type === 'sticker') previewIcon = '💟 Sticker';
      
      const body = lastMsg.body || '';
      if (previewIcon && body) {
        previewText = `${previewIcon} ${body}`;
      } else if (previewIcon) {
        previewText = previewIcon;
      } else {
        previewText = body || 'Message';
      }
    } else if (chat.student_data) {
      previewText = `Roll ${chat.student_data.roll_no || ''} · ${chat.student_data.class_name || ''}`;
    }
    
    const tickSvg = (lastMsg && lastMsg.from_me) ? this.getDeliveryTickSvg(lastMsg.status || 'sent') : '';
    
    return `
      <style>
        :host {
          display: block;
          user-select: none;
        }
        .wa-chat-item {
          display: flex;
          align-items: center;
          padding: 10px 16px;
          cursor: pointer;
          background: var(--wa-bg-panel-var, #ffffff);
          transition: background 0.15s ease;
          position: relative;
          border-bottom: 1px solid var(--wa-border-light, #f0f2f5);
          height: 72px;
          box-sizing: border-box;
        }
        .wa-chat-item:hover {
          background: var(--wa-bg-panel-hover-var, #f5f6f6);
        }
        .wa-chat-item.active {
          background: var(--wa-bg-panel-hover-var, #ebebeb);
        }
        .wa-chat-item.active::before {
          content: '';
          position: absolute;
          left: 0;
          top: 0;
          bottom: 0;
          width: 4px;
          background: var(--wa-green, #00a884);
        }
        .wa-chat-avatar {
          position: relative;
          flex-shrink: 0;
          margin-right: 14px;
        }
        .wa-chat-info {
          flex: 1;
          min-width: 0;
          display: flex;
          flex-direction: column;
          justify-content: center;
          gap: 4px;
        }
        .wa-chat-top {
          display: flex;
          justify-content: space-between;
          align-items: baseline;
          width: 100%;
        }
        .wa-chat-name {
          font-size: 16px;
          font-weight: 500;
          color: var(--wa-text-primary-var, #111b21);
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
          line-height: 21px;
        }
        .wa-chat-item.unread .wa-chat-name {
          font-weight: 600;
        }
        .wa-chat-time {
          font-size: 12px;
          color: var(--wa-text-tertiary-var, #8696a0);
          white-space: nowrap;
          flex-shrink: 0;
          margin-left: 8px;
        }
        .wa-chat-item.unread .wa-chat-time {
          color: var(--wa-green, #00a884);
          font-weight: 600;
        }
        .wa-chat-bottom {
          display: flex;
          justify-content: space-between;
          align-items: center;
          width: 100%;
          min-height: 20px;
        }
        .wa-chat-preview-box {
          display: flex;
          align-items: center;
          gap: 4px;
          font-size: 13.5px;
          color: var(--wa-text-secondary-var, #667781);
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
          flex: 1;
          min-width: 0;
        }
        .wa-chat-item.unread .wa-chat-preview-box {
          color: var(--wa-text-primary-var, #111b21);
          font-weight: 500;
        }
        .wa-tick-container {
          display: inline-flex;
          align-items: center;
          flex-shrink: 0;
          margin-right: 2px;
        }
        .wa-tick-svg {
          width: 16px;
          height: 15px;
        }
        .wa-tick-svg.pending { color: var(--wa-pending, #f39c12); }
        .wa-tick-svg.sent { color: var(--wa-sent, #8696a0); }
        .wa-tick-svg.delivered { color: var(--wa-delivered, #8696a0); }
        .wa-tick-svg.read { color: #53bdeb; }
        
        .wa-preview-text {
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
        }
        
        /* Typing animation */
        .wa-typing-indicator {
          color: var(--wa-green, #00a884);
          font-weight: 500;
          display: inline-flex;
          align-items: center;
          gap: 2px;
          font-size: 13.5px;
        }
        .wa-typing-dots span {
          animation: waDotPulse 1.4s infinite ease-in-out;
          opacity: 0.2;
          font-weight: bold;
        }
        .wa-typing-dots span:nth-child(1) { animation-delay: 0s; }
        .wa-typing-dots span:nth-child(2) { animation-delay: 0.2s; }
        .wa-typing-dots span:nth-child(3) { animation-delay: 0.4s; }
        @keyframes waDotPulse {
          0%, 100% { opacity: 0.2; transform: translateY(0); }
          50% { opacity: 1; transform: translateY(-2px); }
        }
        
        .wa-chat-badges {
          display: flex;
          align-items: center;
          gap: 6px;
          flex-shrink: 0;
          margin-left: 6px;
        }
        .wa-chat-icon-badge {
          color: var(--wa-text-tertiary-var, #8696a0);
          display: flex;
          align-items: center;
        }
        .wa-chat-icon-badge svg {
          width: 15px;
          height: 15px;
        }
        .wa-unread-pill {
          min-width: 20px;
          height: 20px;
          padding: 0 6px;
          font-size: 11.5px;
          font-weight: 700;
          color: #ffffff;
          background: var(--wa-green, #00a884);
          border-radius: 10px;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          box-sizing: border-box;
        }
        
        /* Dropdown Chevron on hover */
        .wa-chat-chevron {
          display: none;
          background: transparent;
          border: none;
          color: var(--wa-text-tertiary-var, #8696a0);
          cursor: pointer;
          padding: 2px;
          border-radius: 50%;
          align-items: center;
          justify-content: center;
          transition: transform 0.15s ease, color 0.15s ease;
        }
        .wa-chat-chevron:hover {
          color: var(--wa-text-primary-var, #111b21);
          transform: scale(1.15);
        }
        .wa-chat-item:hover .wa-chat-chevron {
          display: inline-flex;
        }
        .wa-chat-item:hover .wa-chat-time {
          margin-right: -4px;
        }
      </style>
      
      <div class="wa-chat-item ${isActive ? 'active' : ''} ${isUnread ? 'unread' : ''} ${isPinned ? 'pinned' : ''} ${isMuted ? 'muted' : ''} ${isTyping ? 'typing' : ''}" data-chat-id="${chat.id}">
        <div class="wa-chat-avatar">
          <wa-avatar src="${this.escapeHtml(chat.avatar_url || '')}" name="${this.escapeHtml(chat.name || 'Student')}" size="48"></wa-avatar>
        </div>
        <div class="wa-chat-info">
          <div class="wa-chat-top">
            <span class="wa-chat-name" title="${this.escapeHtml(chat.name || 'Unknown')}">${this.escapeHtml(chat.name || 'Unknown')}</span>
            <span class="wa-chat-time">${formattedTime}</span>
          </div>
          <div class="wa-chat-bottom">
            <div class="wa-chat-preview-box">
              ${isTyping ? `
                <span class="wa-typing-indicator">
                  <span>typing</span>
                  <span class="wa-typing-dots"><span>.</span><span>.</span><span>.</span></span>
                </span>
              ` : `
                ${tickSvg ? `<span class="wa-tick-container">${tickSvg}</span>` : ''}
                <span class="wa-preview-text">${this.escapeHtml(previewText)}</span>
              `}
            </div>
            <div class="wa-chat-badges">
              ${isMuted ? `
                <span class="wa-chat-icon-badge" title="Muted">
                  <svg viewBox="0 0 24 24"><path fill="currentColor" d="M3.63 3.63a.996.996 0 0 0 0 1.41L7.29 8.7 7 9H4c-.55 0-1 .45-1 1v4c0 .55.45 1 1 1h3l3.29 3.29c.63.63 1.71.18 1.71-.71v-4.17l4.18 4.18c-.49.37-1.02.68-1.6.91-.36.14-.58.53-.58.92 0 .68.69 1.18 1.34.93.81-.32 1.55-.76 2.21-1.3l1.83 1.83c.39.39 1.02.39 1.41 0 .39-.39.39-1.02 0-1.41L5.05 3.63c-.39-.39-1.02-.39-1.42 0zM19 12c0 .82-.15 1.61-.41 2.34l1.53 1.53c.56-1.17.88-2.48.88-3.87 0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zm-7-8l-1.88 1.88L12 7.76zm4.5 8A4.5 4.5 0 0 0 14 7.97v1.79l2.48 2.48c.01-.08.02-.16.02-.24z"/></svg>
                </span>
              ` : ''}
              ${isPinned ? `
                <span class="wa-chat-icon-badge" title="Pinned">
                  <svg viewBox="0 0 24 24"><path fill="currentColor" d="M16 9V4l1 0c.55 0 1-.45 1-1s-.45-1-1-1H7c-.55 0-1 .45-1 1s.45 1 1 1l1 0v5c0 1.66-1.34 3-3 3v2h5.97v7l1 1 1-1v-7H19v-2c-1.66 0-3-1.34-3-3z"/></svg>
                </span>
              ` : ''}
              ${isUnread ? `
                <span class="wa-unread-pill">${chat.unread_count > 99 ? '99+' : chat.unread_count}</span>
              ` : ''}
              <button class="wa-chat-chevron" title="Chat menu">
                <svg viewBox="0 0 19 20" width="18" height="18" fill="currentColor">
                  <path d="m3.8 6.7 5.7 5.7 5.7-5.7 1.6 1.6-7.3 7.3-7.3-7.3 1.6-1.6z"/>
                </svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    `;
  }
  
  showContextMenu(event) {
    const chat = this.chat;
    if (!chat) return;
    
    // Remove existing open menus
    document.querySelectorAll('.wa-context-menu').forEach(el => el.remove());
    
    const menu = document.createElement('div');
    menu.className = 'wa-context-menu';
    
    const posX = Math.min(event.clientX, window.innerWidth - 220);
    const posY = Math.min(event.clientY, window.innerHeight - 280);
    
    menu.style.cssText = `
      position: fixed; left: ${posX}px; top: ${posY}px; z-index: 9999;
      background: var(--wa-bg-panel-var, #ffffff); border: 1px solid var(--wa-border-medium, #e2e8f0);
      border-radius: 8px; box-shadow: 0 6px 20px rgba(11,20,26,0.18); min-width: 200px; padding: 6px 0;
      animation: waScaleIn 0.15s ease-out; font-family: inherit;
    `;
    
    const actions = [
      { label: chat.is_pinned ? 'Unpin chat' : 'Pin chat', action: 'pin', icon: '📌' },
      { label: chat.is_muted ? 'Unmute notifications' : 'Mute notifications', action: 'mute', icon: '🔕' },
      { label: chat.unread_count > 0 ? 'Mark as read' : 'Mark as unread', action: 'mark_read', icon: '✉️' },
      { label: chat.is_archived ? 'Unarchive chat' : 'Archive chat', action: 'archive', icon: '📁' },
      { label: 'Clear messages', action: 'clear', icon: '🧹', danger: true },
      { label: 'Delete chat', action: 'delete', icon: '🗑️', danger: true }
    ];
    
    menu.innerHTML = `
      <style>
        @keyframes waScaleIn { from { opacity: 0; transform: scale(0.95); } to { opacity: 1; transform: scale(1); } }
        .wa-ctx-btn {
          width: 100%; padding: 9px 16px; text-align: left; background: none; border: none;
          font-size: 14px; color: var(--wa-text-primary-var, #111b21); cursor: pointer;
          display: flex; align-items: center; gap: 12px; transition: background 0.12s;
        }
        .wa-ctx-btn:hover { background: var(--wa-bg-panel-hover-var, #f5f6f6); }
        .wa-ctx-btn.danger { color: #e74c3c; }
        .wa-ctx-btn.danger:hover { background: #fee2e2; }
      </style>
      ${actions.map(a => `
        <button class="wa-ctx-btn ${a.danger ? 'danger' : ''}" data-action="${a.action}">
          <span>${a.icon}</span>
          <span>${a.label}</span>
        </button>
      `).join('')}
    `;
    
    document.body.appendChild(menu);
    
    menu.querySelectorAll('.wa-ctx-btn').forEach(btn => {
      btn.addEventListener('click', async () => {
        const action = btn.dataset.action;
        menu.remove();
        if (!this.store) return;
        
        try {
          if (action === 'pin') await this.store.chatAction(chat.id, 'pin', { pin: !chat.is_pinned });
          if (action === 'archive') await this.store.chatAction(chat.id, 'archive', { archive: !chat.is_archived });
          if (action === 'mute') await this.store.chatAction(chat.id, 'mute', { mute: !chat.is_muted });
          if (action === 'mark_read') {
            if (chat.unread_count > 0) {
              await this.store.chatAction(chat.id, 'mark_read');
            } else {
              chat.unread_count = 1;
              await this.store.saveChat(chat);
            }
          }
          if (action === 'clear') {
            if (confirm(`Clear all messages with ${chat.name || 'this student'}?`)) {
              await this.store.chatAction(chat.id, 'clear');
            }
          }
          if (action === 'delete') {
            if (confirm(`Delete chat with ${chat.name || 'this contact'}?`)) {
              await this.store.chatAction(chat.id, 'delete');
            }
          }
        } catch (err) {
          console.error('Chat context action failed:', err);
        }
      });
    });
    
    const closeListener = (e) => {
      if (!menu.contains(e.target)) {
        menu.remove();
        document.removeEventListener('click', closeListener);
        document.removeEventListener('contextmenu', closeListener);
      }
    };
    setTimeout(() => {
      document.addEventListener('click', closeListener);
      document.addEventListener('contextmenu', closeListener);
    }, 10);
  }
}

customElements.define('wa-chat-item', WaChatItem);

// ============ CHAT LIST ============

class WaChatList extends WaElement {
  constructor() {
    super();
    this.selectedClass = '';
    this.selectedDocType = 'report_card';
    this.activeFilter = 'all';
    this.searchQuery = '';
  }
  
  template() {
    const schoolLogo = window.HCC_LOGO_DATA_URL || '/hcc/assets/images/hcclogo.jpg';
    let schoolName = 'Hindustani Covenant Church High School';
    try {
      const hubData = document.getElementById('waHubData');
      if (hubData) {
        const parsed = JSON.parse(hubData.textContent);
        if (parsed.schoolName) schoolName = parsed.schoolName;
      }
    } catch (e) {}
    return `
      <style>
        :host {
          display: flex;
          flex-direction: column;
          height: 100%;
          background: var(--wa-bg-panel-var, #ffffff);
          border-right: 1px solid var(--wa-border-light, #e9edef);
          box-sizing: border-box;
          overflow: hidden;
          position: relative;
        }
        
        /* HEADER BAR */
        .wa-cl-header {
          display: flex;
          align-items: center;
          justify-content: space-between;
          height: 60px;
          min-height: 60px;
          padding: 0 14px;
          background: var(--wa-bg-chat-list-var, #f0f2f5);
          flex-shrink: 0;
          box-sizing: border-box;
          border-bottom: 1px solid var(--wa-border-light, #e9edef);
          gap: 6px;
          position: relative;
        }
        .wa-cl-profile {
          display: flex;
          align-items: center;
          gap: 10px;
          cursor: pointer;
          flex: 1;
          min-width: 0;
          padding: 4px 6px 4px 2px;
          border-radius: 8px;
          transition: background 0.15s ease;
          user-select: none;
        }
        .wa-cl-profile:hover {
          background: rgba(11, 20, 26, 0.04);
        }
        .wa-cl-avatar-wrap {
          position: relative;
          display: inline-flex;
          flex-shrink: 0;
          border-radius: 50%;
        }
        .wa-avatar-online-dot {
          position: absolute;
          bottom: 0px;
          right: 0px;
          width: 10px;
          height: 10px;
          background: #25d366;
          border: 2px solid #f0f2f5;
          border-radius: 50%;
          box-shadow: 0 0 0 0.5px rgba(0,0,0,0.1);
        }
        .wa-cl-profile-title {
          display: flex;
          flex-direction: column;
          min-width: 0;
          justify-content: center;
        }
        .wa-cl-profile-heading {
          display: flex;
          align-items: center;
          gap: 4px;
          min-width: 0;
        }
        .wa-cl-profile-heading b {
          font-size: 14.5px;
          font-weight: 700;
          color: var(--wa-text-primary-var, #111b21);
          line-height: 1.2;
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
          letter-spacing: -0.2px;
        }
        .wa-verified-badge {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          flex-shrink: 0;
          color: #00a884;
        }
        .wa-verified-badge svg {
          width: 13px;
          height: 13px;
          fill: currentColor;
        }
        .wa-cl-profile-subtitle {
          font-size: 11px;
          font-weight: 500;
          color: var(--wa-text-secondary-var, #667781);
          line-height: 1.2;
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
          display: flex;
          align-items: center;
          gap: 5px;
          margin-top: 2px;
        }
        .wa-cl-pulse-dot {
          width: 6px;
          height: 6px;
          border-radius: 50%;
          background: #00a884;
          flex-shrink: 0;
          display: inline-block;
        }
        .wa-cl-header-actions {
          display: flex;
          align-items: center;
          gap: 2px;
          flex-shrink: 0;
        }
        .wa-header-toast {
          position: absolute;
          top: 64px;
          left: 12px;
          right: 12px;
          z-index: 1001;
          background: #111b21;
          color: #ffffff;
          padding: 8px 14px;
          border-radius: 8px;
          font-size: 12.5px;
          box-shadow: 0 4px 14px rgba(0,0,0,0.2);
          opacity: 0;
          transform: translateY(-6px);
          transition: opacity 0.2s ease, transform 0.2s ease;
          pointer-events: none;
        }
        .wa-header-toast.visible {
          opacity: 1;
          transform: translateY(0);
        }
        
        /* SEARCH & FILTER BAR */
        .wa-cl-search-row {
          padding: 8px 12px;
          display: flex;
          align-items: center;
          gap: 8px;
          background: var(--wa-bg-panel-var, #ffffff);
          border-bottom: 1px solid var(--wa-border-light, #f0f2f5);
          box-sizing: border-box;
          flex-shrink: 0;
        }
        .wa-search-wrap {
          flex: 1;
          display: flex;
          align-items: center;
          background: var(--wa-bg-search-var, #f0f2f5);
          border-radius: 8px;
          padding: 0 12px;
          height: 36px;
          box-sizing: border-box;
          position: relative;
          transition: background 0.15s, box-shadow 0.15s;
        }
        .wa-search-wrap:focus-within {
          background: #ffffff;
          box-shadow: 0 0 0 1px var(--wa-green, #00a884);
        }
        .wa-search-icon {
          color: var(--wa-text-tertiary-var, #8696a0);
          display: flex;
          align-items: center;
          margin-right: 10px;
          cursor: pointer;
          flex-shrink: 0;
        }
        .wa-search-icon svg {
          width: 17px;
          height: 17px;
        }
        .wa-search-input {
          flex: 1;
          border: none;
          outline: none;
          background: transparent;
          font-size: 14px;
          color: var(--wa-text-primary-var, #111b21);
          font-family: inherit;
        }
        .wa-search-input::placeholder {
          color: var(--wa-text-tertiary-var, #8696a0);
        }
        .wa-search-clear {
          background: none;
          border: none;
          color: var(--wa-text-tertiary-var, #8696a0);
          cursor: pointer;
          padding: 4px;
          display: none;
          align-items: center;
          justify-content: center;
          border-radius: 50%;
        }
        .wa-search-clear:hover {
          color: var(--wa-text-primary-var, #111b21);
        }
        .wa-filter-unread-btn {
          height: 36px;
          padding: 0 12px;
          border-radius: 18px;
          border: 1px solid var(--wa-border-light, #e9edef);
          background: transparent;
          color: var(--wa-text-secondary-var, #667781);
          font-size: 13px;
          font-weight: 600;
          cursor: pointer;
          display: inline-flex;
          align-items: center;
          gap: 6px;
          transition: all 0.15s ease;
          flex-shrink: 0;
        }
        .wa-filter-unread-btn:hover {
          background: var(--wa-bg-panel-hover-var, #f5f6f6);
          color: var(--wa-text-primary-var, #111b21);
        }
        .wa-filter-unread-btn.active {
          background: #d9fdd3;
          border-color: #00a884;
          color: #008069;
        }
        
        /* CLASS SELECTOR BANNER (HCC Portal Integrated) */
        .wa-class-banner {
          padding: 8px 12px;
          background: #f8fafc;
          border-bottom: 1px solid var(--wa-border-light, #e9edef);
          display: flex;
          flex-direction: column;
          gap: 6px;
          flex-shrink: 0;
        }
        .wa-banner-row {
          display: flex;
          gap: 8px;
        }
        .wa-select-wrap {
          flex: 1;
          display: flex;
          align-items: center;
          background: #ffffff;
          border: 1px solid var(--wa-border-medium, #cbd5e1);
          border-radius: 8px;
          padding: 0 8px;
          height: 32px;
          box-sizing: border-box;
        }
        .wa-select-icon {
          font-size: 14px;
          margin-right: 6px;
        }
        .wa-banner-select {
          flex: 1;
          border: none;
          outline: none;
          background: transparent;
          font-size: 12.5px;
          font-weight: 600;
          color: var(--wa-text-primary-var, #1e293b);
          cursor: pointer;
          font-family: inherit;
        }
        
        /* FILTER TABS */
        .wa-filter-tabs {
          display: flex;
          gap: 6px;
          padding: 8px 12px;
          background: var(--wa-bg-panel-var, #ffffff);
          border-bottom: 1px solid var(--wa-border-light, #f0f2f5);
          overflow-x: auto;
          flex-shrink: 0;
          scrollbar-width: none;
        }
        .wa-filter-tabs::-webkit-scrollbar { display: none; }
        .wa-tab-pill {
          padding: 5px 12px;
          font-size: 13px;
          font-weight: 500;
          color: var(--wa-text-secondary-var, #667781);
          background: var(--wa-bg-chat-list-var, #f0f2f5);
          border: none;
          border-radius: 16px;
          cursor: pointer;
          white-space: nowrap;
          transition: all 0.15s ease;
        }
        .wa-tab-pill:hover {
          color: var(--wa-text-primary-var, #111b21);
          background: #e2e8f0;
        }
        .wa-tab-pill.active {
          color: #008069;
          background: #d9fdd3;
          font-weight: 600;
        }
        
        /* CHAT LIST STREAM */
        .wa-chat-stream {
          flex: 1;
          overflow-y: auto;
          overflow-x: hidden;
          background: var(--wa-bg-panel-var, #ffffff);
          position: relative;
        }
        .wa-chat-stream::-webkit-scrollbar {
          width: 6px;
        }
        .wa-chat-stream::-webkit-scrollbar-thumb {
          background: rgba(11,20,26,0.15);
          border-radius: 3px;
        }
        
        /* EMPTY STATE */
        .wa-empty-state {
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: center;
          height: 100%;
          padding: 32px 24px;
          color: var(--wa-text-tertiary-var, #8696a0);
          text-align: center;
          box-sizing: border-box;
        }
        .wa-empty-icon {
          width: 72px;
          height: 72px;
          margin-bottom: 16px;
          color: #cbd5e1;
        }
        .wa-empty-title {
          font-size: 17px;
          font-weight: 600;
          color: var(--wa-text-primary-var, #111b21);
          margin-bottom: 6px;
        }
        .wa-empty-desc {
          font-size: 13.5px;
          line-height: 1.4;
          max-width: 260px;
        }
        
        /* DROPDOWN MENU */
        .wa-header-dropdown {
          display: none;
          position: absolute;
          top: 54px;
          right: 10px;
          z-index: 1000;
          background: #ffffff;
          border-radius: 10px;
          box-shadow: 0 10px 30px rgba(11,20,26,0.18), 0 2px 8px rgba(11,20,26,0.08);
          border: 1px solid rgba(11,20,26,0.08);
          min-width: 210px;
          padding: 6px 0;
          animation: waDropScale 0.15s cubic-bezier(0.2, 0, 0, 1);
        }
        .wa-header-dropdown.open {
          display: block;
        }
        @keyframes waDropScale {
          from { opacity: 0; transform: scale(0.96) translateY(-4px); }
          to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .wa-menu-item {
          display: flex;
          align-items: center;
          gap: 12px;
          width: 100%;
          padding: 9px 16px;
          font-size: 13.5px;
          font-weight: 500;
          color: var(--wa-text-primary-var, #111b21);
          background: none;
          border: none;
          text-align: left;
          cursor: pointer;
          transition: background 0.12s ease;
          box-sizing: border-box;
          font-family: inherit;
        }
        .wa-menu-item svg {
          width: 18px;
          height: 18px;
          color: #54656f;
          flex-shrink: 0;
        }
        .wa-menu-item:hover {
          background: #f5f6f6;
        }
        .wa-menu-item.wa-menu-item-danger {
          color: #ea4335;
        }
        .wa-menu-item.wa-menu-item-danger svg {
          color: #ea4335;
        }
        .wa-menu-divider {
          height: 1px;
          background: #e9edef;
          margin: 4px 0;
        }

        /* SEARCH RESULTS & QUICK START CHAT */
        .wa-search-section-header {
          padding: 8px 16px 6px;
          font-size: 11.5px;
          font-weight: 700;
          text-transform: uppercase;
          letter-spacing: 0.6px;
          color: var(--wa-green, #008069);
          background: #f8fafc;
          border-top: 1px solid #f1f5f9;
          border-bottom: 1px solid #f1f5f9;
          user-select: none;
        }
        .wa-search-action-card {
          display: flex;
          align-items: center;
          gap: 12px;
          padding: 12px 16px;
          background: #ffffff;
          cursor: pointer;
          border-bottom: 1px solid #f0f2f5;
          transition: background 0.15s ease;
        }
        .wa-search-action-card:hover {
          background: #f0f2f5;
        }
        .wa-action-icon-circle {
          width: 44px;
          height: 44px;
          border-radius: 50%;
          background: #00a884;
          color: #ffffff;
          display: flex;
          align-items: center;
          justify-content: center;
          flex-shrink: 0;
          box-shadow: 0 2px 5px rgba(0, 168, 132, 0.25);
        }
        .wa-action-icon-circle svg {
          width: 22px;
          height: 22px;
          fill: currentColor;
        }
        .wa-action-body {
          flex: 1;
          min-width: 0;
        }
        .wa-action-title {
          font-size: 14px;
          color: #111b21;
          font-weight: 500;
        }
        .wa-action-sub {
          font-size: 12px;
          color: #667781;
          margin-top: 2px;
        }
        .wa-action-go svg {
          width: 20px;
          height: 20px;
          fill: #8696a0;
        }
        .wa-contact-search-row {
          display: flex;
          align-items: center;
          gap: 12px;
          padding: 10px 16px;
          background: #ffffff;
          cursor: pointer;
          border-bottom: 1px solid #f1f5f9;
          transition: background 0.15s ease;
        }
        .wa-contact-search-row:hover {
          background: #f0f2f5;
        }
        .wa-contact-search-info {
          flex: 1;
          min-width: 0;
        }
        .wa-contact-search-name-line {
          display: flex;
          align-items: center;
          gap: 6px;
        }
        .wa-contact-search-name {
          font-weight: 600;
          font-size: 14px;
          color: #111b21;
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
        }
        .wa-badge-roll {
          background: #e0f2fe;
          color: #0369a1;
          font-size: 11px;
          font-weight: 700;
          padding: 1px 6px;
          border-radius: 4px;
        }
        .wa-badge-class {
          background: #f1f5f9;
          color: #475569;
          font-size: 11px;
          padding: 1px 6px;
          border-radius: 4px;
          white-space: nowrap;
        }
        .wa-contact-search-sub-line {
          display: flex;
          align-items: center;
          gap: 8px;
          font-size: 12.5px;
          color: #667781;
          margin-top: 2px;
        }
        .wa-btn-start-chat-inline {
          background: #00a884;
          color: #ffffff;
          border: none;
          border-radius: 6px;
          padding: 6px 12px;
          font-size: 12px;
          font-weight: 600;
          display: inline-flex;
          align-items: center;
          gap: 5px;
          cursor: pointer;
          transition: background 0.15s;
          flex-shrink: 0;
        }
        .wa-btn-start-chat-inline:hover {
          background: #008069;
        }
        .wa-btn-start-chat-inline svg {
          width: 13px;
          height: 13px;
          fill: currentColor;
        }
        .wa-btn-start-chat-fallback {
          margin-top: 12px;
          background: #00a884;
          color: #ffffff;
          border: none;
          border-radius: 8px;
          padding: 10px 18px;
          font-size: 13.5px;
          font-weight: 600;
          cursor: pointer;
          display: inline-flex;
          align-items: center;
          gap: 8px;
          box-shadow: 0 2px 6px rgba(0, 168, 132, 0.3);
        }
        .wa-btn-start-chat-fallback:hover {
          background: #008069;
        }
      </style>
      
      <!-- HEADER BAR -->
      <div class="wa-cl-header">
        <div class="wa-cl-profile" id="profile-trigger" title="${this.escapeHtml(schoolName)} · Official WhatsApp Portal">
          <div class="wa-cl-avatar-wrap">
            <wa-avatar src="${schoolLogo}" name="${this.escapeHtml(schoolName)}" size="40"></wa-avatar>
            <span class="wa-avatar-online-dot" title="Connected & Online"></span>
          </div>
          <div class="wa-cl-profile-title">
            <div class="wa-cl-profile-heading">
              <b>WhatsApp Hub</b>
              <span class="wa-verified-badge" title="Official Verified School Portal">
                <svg viewBox="0 0 18 18"><path d="M9 0a9 9 0 1 0 9 9A9 9 0 0 0 9 0zm4.2 6.6-4.8 4.8a.75.75 0 0 1-1.06 0L5.1 9.16a.75.75 0 1 1 1.06-1.06l1.71 1.7 4.27-4.26a.75.75 0 1 1 1.06 1.06z"/></svg>
              </span>
            </div>
            <div class="wa-cl-profile-subtitle" title="${this.escapeHtml(schoolName)}">
              <span class="wa-cl-pulse-dot"></span>
              <span>HCC High School</span>
            </div>
          </div>
        </div>
        <div class="wa-cl-header-actions">
          <!-- Community Icon -->
          <wa-icon-button id="btn-communities" icon="community" tooltip="Communities"></wa-icon-button>
          <!-- Status Icon -->
          <wa-icon-button id="btn-status" icon="status" tooltip="Status updates"></wa-icon-button>
          <!-- Channels Icon -->
          <wa-icon-button id="btn-channels" icon="channels" tooltip="School Channels"></wa-icon-button>
          <!-- New Chat Icon -->
          <wa-icon-button id="btn-new-chat" icon="new_chat" tooltip="New chat"></wa-icon-button>
          <!-- 3-Dots Menu -->
          <wa-icon-button id="btn-menu" icon="more_vert" tooltip="Menu"></wa-icon-button>
        </div>
      </div>
      
      <!-- MENU DROPDOWN -->
      <div class="wa-header-dropdown" id="header-menu">
        <button class="wa-menu-item" data-action="new-group">
          <svg viewBox="0 0 24 24"><path fill="currentColor" d="M16.5 12c1.38 0 2.49-1.12 2.49-2.5S17.88 7 16.5 7C15.12 7 14 8.12 14 9.5s1.12 2.5 2.5 2.5zM9 11c1.66 0 2.99-1.34 2.99-3S10.66 5 9 5C7.34 5 6 6.34 6 8s1.34 3 3 3zm7.5 3c-1.83 0-5.5.92-5.5 2.75V19h11v-2.25c0-1.83-3.67-2.75-5.5-2.75zM9 13c-2.33 0-7 1.17-7 3.5V19h7v-2.25c0-.85.34-1.65.9-2.28C9.6 13.08 9.27 13 9 13z"/></svg>
          <span>New group</span>
        </button>
        <button class="wa-menu-item" data-action="new-community">
          <svg viewBox="0 0 24 24"><path fill="currentColor" d="M12.071 8.243a3.504 3.504 0 1 0 0-7.008 3.504 3.504 0 0 0 0 7.008zm-5.75 4.316c.307-.84.819-1.579 1.488-2.158C7.159 10.15 6.275 10 5.32 10c-2.454 0-4.32 1.636-4.32 3.636v1.455c0 .5.409.909.909.909h7.405a5.55 5.55 0 0 1-1.028-2.433c-.007-.037-.013-.075-.015-.114v-.893zm11.5 0v.893c-.002.039-.008.077-.015.114a5.55 5.55 0 0 1-1.028 2.433h7.405c.5 0 .909-.409.909-.909v-1.455c0-2-1.866-3.636-4.32-3.636-.955 0-1.839.15-2.489.401.669.579 1.181 1.318 1.488 2.158zm-5.75-2.007c-2.348 0-4.25 1.702-4.25 3.801v1.693c0 .523.427.953.95.953h6.6c.523 0 .95-.43.95-.953v-1.693c0-2.099-1.902-3.801-4.25-3.801z"/></svg>
          <span>New community</span>
        </button>
        <button class="wa-menu-item" data-action="starred">
          <svg viewBox="0 0 24 24"><path fill="currentColor" d="m12 17.27 4.15 2.51c.76.46 1.69-.22 1.49-1.08l-1.1-4.72 3.67-3.18c.67-.58.31-1.68-.57-1.75l-4.83-.41-1.89-4.46c-.34-.81-1.5-.81-1.84 0L9.19 8.63l-4.83.41c-.88.07-1.24 1.17-.57 1.75l3.67 3.18-1.1 4.72c-.2.86.73 1.54 1.49 1.08l4.15-2.5z"/></svg>
          <span>Starred messages</span>
        </button>
        <button class="wa-menu-item" data-action="select-chats">
          <svg viewBox="0 0 24 24"><path fill="currentColor" d="m10 17-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
          <span>Select chats</span>
        </button>
        <div class="wa-menu-divider"></div>
        <button class="wa-menu-item" data-action="fullscreen">
          <svg viewBox="0 0 24 24"><path fill="currentColor" d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>
          <span>Fullscreen WhatsApp</span>
        </button>
        <button class="wa-menu-item" data-action="settings">
          <svg viewBox="0 0 24 24"><path fill="currentColor" d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.488.488 0 0 0-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 0 0-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
          <span>Settings</span>
        </button>
        <div class="wa-menu-divider"></div>
        <button class="wa-menu-item wa-menu-item-danger" data-action="logout">
          <svg viewBox="0 0 24 24"><path fill="currentColor" d="m17 7-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></svg>
          <span>Log out</span>
        </button>
      </div>
      
      <!-- SEARCH ROW -->
      <div class="wa-cl-search-row">
        <div class="wa-search-wrap">
          <span class="wa-search-icon" id="search-icon-btn">
            <svg viewBox="0 0 24 24"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
          </span>
          <input type="text" class="wa-search-input" id="search-input" placeholder="Search or start new chat" aria-label="Search or start new chat">
          <button class="wa-search-clear" id="search-clear-btn" title="Clear search">✕</button>
        </div>
        <button class="wa-filter-unread-btn" id="filter-unread-toggle" title="Filter unread chats">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M3 17h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V5H3z"/></svg>
          <span>Unread</span>
        </button>
      </div>
      
      <!-- CLASS & DOC TYPE BANNER -->
      <div class="wa-class-banner">
        <div class="wa-banner-row">
          <div class="wa-select-wrap">
            <span class="wa-select-icon">🏫</span>
            <select class="wa-banner-select" id="class-select" aria-label="Filter by School Class">
              <option value="">All Classes (School-wide)</option>
              <option value="STD I A">STD I A</option>
              <option value="STD I B">STD I B</option>
              <option value="STD I C">STD I C</option>
              <option value="STD II A">STD II A</option>
              <option value="STD II B">STD II B</option>
              <option value="STD II C">STD II C</option>
              <option value="STD III A">STD III A</option>
              <option value="STD III B">STD III B</option>
              <option value="STD IV A">STD IV A</option>
              <option value="STD IV B">STD IV B</option>
              <option value="STD V A">STD V A</option>
              <option value="STD V B">STD V B</option>
              <option value="STD VI A">STD VI A</option>
              <option value="STD VI B">STD VI B</option>
              <option value="STD VII A">STD VII A</option>
              <option value="STD VII B">STD VII B</option>
              <option value="STD VIII A">STD VIII A</option>
              <option value="STD VIII B">STD VIII B</option>
              <option value="STD IX A">STD IX A</option>
              <option value="STD IX B">STD IX B</option>
              <option value="STD X A">STD X A</option>
              <option value="STD X B">STD X B</option>
            </select>
          </div>
          <div class="wa-select-wrap">
            <span class="wa-select-icon">📄</span>
            <select class="wa-banner-select" id="doc-select" aria-label="Target Document Type">
              <option value="report_card">📋 Semester Report Card</option>
              <option value="unit_test">📝 Unit Test Progress Slip</option>
              <option value="bonafide">📜 Bonafide Certificate</option>
              <option value="notice">📢 School Notice</option>
              <option value="fee">💰 Fee Reminder</option>
            </select>
          </div>
        </div>
      </div>
      
      <!-- FILTER TABS -->
      <div class="wa-filter-tabs" role="tablist">
        <button class="wa-tab-pill active" data-filter="all" role="tab">All</button>
        <button class="wa-tab-pill" data-filter="unread" role="tab">Unread</button>
        <button class="wa-tab-pill" data-filter="groups" role="tab">Groups</button>
        <button class="wa-tab-pill" data-filter="pinned" role="tab">Pinned</button>
      </div>
      
      <!-- CHAT STREAM -->
      <div class="wa-chat-stream" id="chat-stream">
        <div id="chat-list-container"></div>
        <div id="load-more" style="padding: 16px; text-align: center; display: none;">
          <wa-spinner size="24"></wa-spinner>
        </div>
      </div>
    `;
  }
  
  onStoreReady() {
    this.refreshChats();
  }
  
  bindEvents() {
    // Store updates
    if (this.store) {
      this.store.addEventListener('chats:loaded', this.boundChatsLoaded = (e) => this.handleChatsLoaded(e.detail.chats, e.detail.pagination));
      this.store.addEventListener('chats:bulk-updated', this.boundBulkUpdated = () => this.refreshChats());
      this.store.addEventListener('chats:updated', this.boundChatUpdated = () => this.refreshChats());
      this.store.addEventListener('roster:synced', this.boundRosterSynced = () => this.refreshChats());
      this.store.addEventListener('chat:selected', this.boundChatSelected = (e) => this.highlightActive(e.detail.chatId));
      this.store.addEventListener('unread:updated', this.boundUnreadUpdated = () => this.updateFilterBadges());
    }
    
    // Search input
    const searchInput = this.$('#search-input');
    const searchClear = this.$('#search-clear-btn');
    if (searchInput) {
      searchInput.addEventListener('input', (e) => {
        this.searchQuery = e.target.value.trim();
        if (searchClear) searchClear.style.display = this.searchQuery ? 'inline-flex' : 'none';
        this.applyFilterAndSearch();
      });
      searchInput.addEventListener('keydown', async (e) => {
        if (e.key === 'Escape') {
          searchInput.value = '';
          this.searchQuery = '';
          if (searchClear) searchClear.style.display = 'none';
          this.applyFilterAndSearch();
        } else if (e.key === 'Enter' && this.searchQuery) {
          e.preventDefault();
          const phoneCard = this.$('.wa-search-action-card');
          if (phoneCard) {
            phoneCard.click();
            return;
          }
          const firstChat = this.$('wa-chat-item');
          if (firstChat) {
            firstChat.click();
            return;
          }
          const firstContact = this.$('.wa-contact-search-row');
          if (firstContact) {
            firstContact.click();
            return;
          }
          const fallbackBtn = this.$('#btn-fallback-new-chat');
          if (fallbackBtn) {
            fallbackBtn.click();
          } else {
            await this.store?.getOrCreateChat({
              phone: this.searchQuery,
              name: this.searchQuery,
              chat_type: 'individual'
            });
            searchInput.value = '';
            this.searchQuery = '';
            this.applyFilterAndSearch();
          }
        }
      });
    }
    
    if (searchClear) {
      searchClear.addEventListener('click', () => {
        if (searchInput) searchInput.value = '';
        this.searchQuery = '';
        searchClear.style.display = 'none';
        this.applyFilterAndSearch();
        if (searchInput) searchInput.focus();
      });
    }
    
    // Unread toggle pill in search row
    const unreadBtn = this.$('#filter-unread-toggle');
    if (unreadBtn) {
      unreadBtn.addEventListener('click', () => {
        const isUnread = this.activeFilter === 'unread';
        this.setFilter(isUnread ? 'all' : 'unread');
      });
    }
    
    // Class Banner Selects
    const classSelect = this.$('#class-select');
    if (classSelect) {
      classSelect.addEventListener('change', (e) => {
        this.selectedClass = e.target.value;
        this.dispatch('class-selected', { className: this.selectedClass, docType: this.selectedDocType });
        this.applyFilterAndSearch();
      });
    }
    
    const docSelect = this.$('#doc-select');
    if (docSelect) {
      docSelect.addEventListener('change', (e) => {
        this.selectedDocType = e.target.value;
        this.dispatch('doc-type-selected', { className: this.selectedClass, docType: this.selectedDocType });
      });
    }
    
    // Filter tabs
    this.$$('.wa-tab-pill').forEach(btn => {
      btn.addEventListener('click', () => {
        const filter = btn.dataset.filter;
        this.setFilter(filter);
      });
    });
    
    // Profile click
    this.$('#profile-trigger')?.addEventListener('click', () => {
      const st = document.querySelector('wa-settings') || this.getRootNode()?.querySelector('wa-settings');
      if (st && typeof st.open === 'function') st.open();
      else this.dispatch('open-settings');
    });

    // Header Actions
    this.$('#btn-communities')?.addEventListener('click', () => {
      this.showToast('Communities', 'HCC High School Class Communities are active.');
    });
    this.$('#btn-status')?.addEventListener('click', () => {
      this.showToast('Status Updates', 'Academic circulars and dispatch updates are current.');
    });
    this.$('#btn-channels')?.addEventListener('click', () => {
      this.showToast('Official Channels', 'HCC Official Broadcast & Events Channel is connected.');
    });
    this.$('#btn-new-chat')?.addEventListener('click', () => {
      this.dispatch('open-contact-picker');
      const cp = document.querySelector('wa-contact-picker') || this.getRootNode()?.querySelector('wa-contact-picker');
      if (cp && typeof cp.open === 'function') cp.open();
    });
    
    // 3-dots Menu toggle
    const menuBtn = this.$('#btn-menu');
    const headerMenu = this.$('#header-menu');
    if (menuBtn && headerMenu) {
      menuBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = headerMenu.classList.toggle('open');
        const iconBtn = menuBtn.shadowRoot?.querySelector('.wa-icon-btn');
        if (iconBtn) iconBtn.classList.toggle('is-active', isOpen);
      });
      
      document.addEventListener('click', (e) => {
        if (!headerMenu.contains(e.target)) {
          headerMenu.classList.remove('open');
          const iconBtn = menuBtn.shadowRoot?.querySelector('.wa-icon-btn');
          if (iconBtn) iconBtn.classList.remove('is-active');
        }
      });
    }
    
    // Menu items
    this.$$('.wa-menu-item').forEach(item => {
      item.addEventListener('click', (e) => {
        const action = item.dataset.action;
        headerMenu?.classList.remove('open');
        
        if (action === 'new-group') {
          const gc = document.querySelector('wa-group-create') || this.getRootNode()?.querySelector('wa-group-create');
          if (gc && typeof gc.open === 'function') gc.open();
          else this.dispatch('open-group-create');
        } else if (action === 'settings') {
          const st = document.querySelector('wa-settings') || this.getRootNode()?.querySelector('wa-settings');
          if (st && typeof st.open === 'function') st.open();
          else this.dispatch('open-settings');
        } else if (action === 'fullscreen') {
          this.dispatch('toggle-fullscreen');
        } else if (action === 'starred') {
          this.setFilter('starred');
        } else if (action === 'logout') {
          if (confirm('Log out from WhatsApp Web Replica?')) {
            this.store?.logout();
          }
        } else {
          alert(`${item.textContent.trim()} action selected.`);
        }
      });
    });
    
    // Infinite scrolling
    const stream = this.$('#chat-stream');
    if (stream) {
      stream.addEventListener('scroll', () => {
        if (stream.scrollTop + stream.clientHeight >= stream.scrollHeight - 80) {
          if (this.store && !this.store.loadingChats && this.store.hasMoreChats) {
            this.store.loadMoreChats();
          }
        }
      });
    }

    // Initial render of existing chats if store already populated
    this.refreshChats();
  }
  
  refreshChats() {
    if (!this.store) {
      this.store = window.WhatsAppStore;
    }
    if (!this.store) return;
    const chats = this.store.getFilteredChats ? this.store.getFilteredChats() : Array.from(this.store.chats?.values() || []);
    this.handleChatsLoaded(chats, { has_more: !!this.store.hasMoreChats });
  }

  showToast(title, body) {
    if (window.WhatsAppAudio && typeof window.WhatsAppAudio.notify === 'function') {
      window.WhatsAppAudio.notify({ title, body, icon: '/hcc/assets/images/hcclogo.jpg' });
    } else {
      const existing = this.shadowRoot?.querySelector('.wa-header-toast');
      if (existing) existing.remove();
      const toast = document.createElement('div');
      toast.className = 'wa-header-toast';
      toast.innerHTML = `<strong>${this.escapeHtml(title)}:</strong> ${this.escapeHtml(body)}`;
      this.shadowRoot?.appendChild(toast);
      setTimeout(() => toast.classList.add('visible'), 10);
      setTimeout(() => {
        toast.classList.remove('visible');
        setTimeout(() => toast.remove(), 250);
      }, 3000);
    }
  }
  
  applyFilterAndSearch() {
    if (!this.store) {
      this.store = window.WhatsAppStore;
    }
    if (!this.store) return;
    
    const container = this.$('#chat-list-container');
    if (!container) return;
    
    let allChats = Array.from(this.store.chats?.values() || []);
    
    // Apply Class Filter
    if (this.selectedClass) {
      const clsUpper = this.selectedClass.toUpperCase();
      allChats = allChats.filter(c => {
        const name = (c.name || '').toUpperCase();
        const studentClass = c.student_data?.class_name ? c.student_data.class_name.toUpperCase() : '';
        const metadataClass = (c.metadata?.class_label || c.metadata?.class_name || c.metadata?.class_key || c.linked_class_key || '').toUpperCase();
        return name.includes(clsUpper) || studentClass.includes(clsUpper) || metadataClass.includes(clsUpper);
      });
    }
    
    // Apply Tab Filter
    if (this.activeFilter === 'unread') {
      allChats = allChats.filter(c => (c.unread_count || 0) > 0);
    } else if (this.activeFilter === 'groups') {
      allChats = allChats.filter(c => c.chat_type === 'group' || c.chat_type === 'broadcast');
    } else if (this.activeFilter === 'pinned') {
      allChats = allChats.filter(c => !!c.is_pinned);
    }
    
    // If NO search query, render standard chats list
    if (!this.searchQuery) {
      this.renderChatItems(allChats);
      return;
    }
    
    // We HAVE a search query
    const q = this.searchQuery.toLowerCase();
    const cleanDigits = this.searchQuery.replace(/\D/g, '');
    
    // 1. Filter existing chats
    const matchingChats = allChats.filter(c => {
      const name = (c.name || '').toLowerCase();
      const phone = (c.wa_chat_id || '').toLowerCase();
      const roll = c.student_data?.roll_no ? String(c.student_data.roll_no) : (c.linked_student_roll ? String(c.linked_student_roll) : '');
      const preview = (c.last_message?.body || c.last_msg_body || '').toLowerCase();
      return name.includes(q) || phone.includes(q) || roll.includes(q) || preview.includes(q);
    });
    
    // 2. Direct phone action if query has digits
    let phoneAction = null;
    if (cleanDigits.length >= 3) {
      const formatted = cleanDigits.length === 10 ? `+91 ${cleanDigits}` : `+${cleanDigits}`;
      phoneAction = {
        phone: cleanDigits,
        displayPhone: formatted
      };
    }
    
    // 3. Search contacts/students from store and roster
    const existingChatWaIds = new Set(matchingChats.map(c => c.wa_chat_id));
    const contactsMap = new Map();
    
    // Contacts in store.contacts
    if (this.store.contacts) {
      for (const contact of this.store.contacts.values()) {
        const cName = (contact.name || '').toLowerCase();
        const cPhone = (contact.phone_e164 || contact.wa_id || '').toLowerCase();
        const cRoll = String(contact.linked_student_roll || '').toLowerCase();
        if (cName.includes(q) || cPhone.includes(q) || cRoll.includes(q)) {
          if (!existingChatWaIds.has(contact.wa_id)) {
            contactsMap.set(contact.wa_id, contact);
          }
        }
      }
    }
    
    // Also check #waHubData students
    try {
      const hubDataEl = document.getElementById('waHubData');
      if (hubDataEl) {
        const hubCfg = JSON.parse(hubDataEl.textContent || '{}');
        const stuList = hubCfg.students || [];
        for (const stu of stuList) {
          const sName = (stu.student_name || '').toLowerCase();
          const sRoll = String(stu.roll_no || '').toLowerCase();
          const sPhone = String(stu.parent_phone || '').toLowerCase();
          if (sName.includes(q) || sRoll.includes(q) || sPhone.includes(q)) {
            const cleanPhone = (stu.parent_phone || '').replace(/\D/g, '') || ('98765' + String(stu.roll_no).padStart(5, '0'));
            const sWaId = (cleanPhone.startsWith('91') ? cleanPhone : ('91' + cleanPhone)) + '@c.us';
            if (!existingChatWaIds.has(sWaId) && !contactsMap.has(sWaId)) {
              contactsMap.set(sWaId, {
                wa_id: sWaId,
                name: stu.student_name,
                phone_e164: '+' + (cleanPhone.startsWith('91') ? cleanPhone : ('91' + cleanPhone)),
                linked_student_roll: stu.roll_no,
                linked_class_key: hubCfg.classKey || stu.std || ''
              });
            }
          }
        }
      }
    } catch (e) {}

    const matchingContacts = Array.from(contactsMap.values());
    this.renderSearchResults(matchingChats, matchingContacts, phoneAction);

    // Also trigger server contact search asynchronously
    if (this.searchQuery && this.searchQuery.length >= 1 && matchingContacts.length < 10) {
      this.store.searchContacts(this.searchQuery).then(serverContacts => {
        if (!serverContacts || this.searchQuery.toLowerCase() !== q) return;
        let added = false;
        for (const sc of serverContacts) {
          if (!existingChatWaIds.has(sc.wa_id) && !contactsMap.has(sc.wa_id)) {
            contactsMap.set(sc.wa_id, sc);
            added = true;
          }
        }
        if (added && this.searchQuery.toLowerCase() === q) {
          this.renderSearchResults(matchingChats, Array.from(contactsMap.values()), phoneAction);
        }
      }).catch(() => {});
    }
  }

  renderSearchResults(chats, contacts, phoneAction) {
    const container = this.$('#chat-list-container');
    if (!container) return;
    
    container.innerHTML = '';
    
    const hasChats = chats && chats.length > 0;
    const hasContacts = contacts && contacts.length > 0;
    
    if (!hasChats && !hasContacts && !phoneAction) {
      container.innerHTML = `
        <div class="wa-empty-state">
          <svg class="wa-empty-icon" viewBox="0 0 24 24" fill="currentColor">
            <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/>
          </svg>
          <div class="wa-empty-title">No chats found for "${this.escapeHtml(this.searchQuery)}"</div>
          <div class="wa-empty-desc">Would you like to start a new chat with this name or number?</div>
          <button type="button" class="wa-btn-start-chat-fallback" id="btn-fallback-new-chat">
            <svg style="width:16px;height:16px;fill:currentColor;" viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
            Start new chat with "${this.escapeHtml(this.searchQuery)}"
          </button>
        </div>
      `;
      container.querySelector('#btn-fallback-new-chat')?.addEventListener('click', async () => {
        await this.store?.getOrCreateChat({
          phone: this.searchQuery,
          name: this.searchQuery,
          chat_type: 'individual'
        });
        const inp = this.$('#search-input');
        if (inp) inp.value = '';
        this.searchQuery = '';
        this.applyFilterAndSearch();
      });
      return;
    }
    
    // 1. Direct Phone Number Action Card
    if (phoneAction) {
      const phoneCard = document.createElement('div');
      phoneCard.className = 'wa-search-action-card';
      phoneCard.innerHTML = `
        <div class="wa-action-icon-circle">
          <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 10h-4v4h-2v-4H8v-2h4V6h2v4h4v2z"/></svg>
        </div>
        <div class="wa-action-body">
          <div class="wa-action-title">Start new chat with <strong>${this.escapeHtml(phoneAction.displayPhone)}</strong></div>
          <div class="wa-action-sub">Click to initiate direct WhatsApp conversation</div>
        </div>
        <div class="wa-action-go">
          <svg viewBox="0 0 24 24"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/></svg>
        </div>
      `;
      phoneCard.addEventListener('click', async () => {
        await this.store?.getOrCreateChat({
          phone: phoneAction.phone,
          name: phoneAction.displayPhone,
          chat_type: 'individual'
        });
        const inp = this.$('#search-input');
        if (inp) inp.value = '';
        this.searchQuery = '';
        this.applyFilterAndSearch();
      });
      container.appendChild(phoneCard);
    }
    
    // 2. Existing Chats Section
    if (hasChats) {
      const chatHeader = document.createElement('div');
      chatHeader.className = 'wa-search-section-header';
      chatHeader.textContent = `CHATS (${chats.length})`;
      container.appendChild(chatHeader);
      
      chats.forEach(chat => {
        const item = document.createElement('wa-chat-item');
        item.setAttribute('chat-id', chat.id);
        if (this.store && this.store.activeChatId === chat.id) {
          item.setAttribute('is-active', 'true');
        }
        container.appendChild(item);
      });
    }
    
    // 3. New Contact / Student Chats Section
    if (hasContacts) {
      const contactHeader = document.createElement('div');
      contactHeader.className = 'wa-search-section-header';
      contactHeader.textContent = `STUDENTS & CONTACTS (START NEW CHAT)`;
      container.appendChild(contactHeader);
      
      contacts.forEach(contact => {
        const row = document.createElement('div');
        row.className = 'wa-contact-search-row';
        row.innerHTML = `
          <wa-avatar name="${this.escapeHtml(contact.name || 'Student')}" size="44"></wa-avatar>
          <div class="wa-contact-search-info">
            <div class="wa-contact-search-name-line">
              <span class="wa-contact-search-name">${this.escapeHtml(contact.name || 'Student')}</span>
              ${contact.linked_student_roll ? `<span class="wa-badge-roll">Roll ${this.escapeHtml(contact.linked_student_roll)}</span>` : ''}
            </div>
            <div class="wa-contact-search-sub-line">
              <span class="wa-contact-search-phone">${this.escapeHtml(contact.phone_e164 || contact.wa_id || '')}</span>
              ${contact.linked_class_key ? `<span class="wa-badge-class">${this.escapeHtml(contact.linked_class_key)}</span>` : ''}
            </div>
          </div>
          <button type="button" class="wa-btn-start-chat-inline" title="Start Chat">
            <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
            Chat
          </button>
        `;
        row.addEventListener('click', async () => {
          await this.store?.getOrCreateChat({
            phone: contact.phone_e164 || contact.wa_id,
            name: contact.name,
            student_roll: contact.linked_student_roll,
            class_key: contact.linked_class_key,
            chat_type: 'individual'
          });
          const inp = this.$('#search-input');
          if (inp) inp.value = '';
          this.searchQuery = '';
          this.applyFilterAndSearch();
        });
        container.appendChild(row);
      });
    }
  }
  
  setFilter(filter) {
    this.activeFilter = filter;
    
    this.$$('.wa-tab-pill').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.filter === filter);
    });
    
    const unreadToggle = this.$('#filter-unread-toggle');
    if (unreadToggle) {
      unreadToggle.classList.toggle('active', filter === 'unread');
    }
    
    this.applyFilterAndSearch();
  }
  
  handleChatsLoaded(chats, pagination) {
    this.applyFilterAndSearch();
    const loadMore = this.$('#load-more');
    if (loadMore) {
      loadMore.style.display = pagination?.has_more ? 'block' : 'none';
    }
    // Auto-select first chat if none selected
    if (this.store && !this.store.activeChatId && chats && chats.length > 0) {
      const first = chats[0];
      if (first && first.id) {
        this.store.setActiveChat(first.id);
      }
    }
  }
  
  renderChatItems(chats) {
    const container = this.$('#chat-list-container');
    if (!container) return;
    
    container.innerHTML = '';
    
    if (!chats || chats.length === 0) {
      container.innerHTML = `
        <div class="wa-empty-state">
          <svg class="wa-empty-icon" viewBox="0 0 24 24" fill="currentColor">
            <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/>
          </svg>
          <div class="wa-empty-title">${this.searchQuery || this.selectedClass ? 'No chats found' : 'No chats yet'}</div>
          <div class="wa-empty-desc">
            ${this.searchQuery || this.selectedClass 
              ? 'Try selecting a different class or searching by student name or roll number.' 
              : 'Start messaging students and parents or select a class from the banner above.'}
          </div>
        </div>
      `;
      return;
    }
    
    // Sort: pinned first, then last_activity_at desc
    const sorted = [...chats].sort((a, b) => {
      if (a.is_pinned && !b.is_pinned) return -1;
      if (!a.is_pinned && b.is_pinned) return 1;
      const tA = new Date(a.last_activity_at || 0).getTime();
      const tB = new Date(b.last_activity_at || 0).getTime();
      return tB - tA;
    });
    
    sorted.forEach(chat => {
      const item = document.createElement('wa-chat-item');
      item.setAttribute('chat-id', chat.id);
      if (this.store && this.store.activeChatId === chat.id) {
        item.setAttribute('is-active', 'true');
      }
      container.appendChild(item);
    });
  }
  
  highlightActive(chatId) {
    this.$$('#chat-list-container wa-chat-item').forEach(item => {
      const isMatch = item.getAttribute('chat-id') == chatId;
      item.setAttribute('is-active', isMatch ? 'true' : 'false');
    });
  }
  
  updateFilterBadges() {
    // Unread counts update reactively
  }
}

customElements.define('wa-chat-list', WaChatList);

// ============ AVATAR COMPONENT ============

class WaAvatar extends WaElement {
  static get observedAttributes() { return ['src', 'name', 'size']; }
  
  attributeChangedCallback() { this.render(); }
  
  template() {
    const src = this.getAttribute('src');
    const name = this.getAttribute('name') || '?';
    const size = parseInt(this.getAttribute('size'), 10) || 40;
    const isGroup = name.startsWith('STD ') || name.includes('Group') || name.includes('Class');
    
    return `
      <style>
        :host {
          display: inline-block;
          vertical-align: middle;
        }
        .wa-avatar {
          width: ${size}px;
          height: ${size}px;
          border-radius: 50%;
          background: ${src && !src.startsWith('data:,') ? '#ffffff' : this.getAvatarColor(name)};
          color: #ffffff;
          font-size: ${Math.round(size * 0.38)}px;
          font-weight: 700;
          display: flex;
          align-items: center;
          justify-content: center;
          overflow: hidden;
          box-shadow: 0 1px 3px rgba(0,0,0,0.1);
          border: 1px solid rgba(0,0,0,0.06);
          position: relative;
          box-sizing: border-box;
        }
        .wa-avatar img {
          width: 100%;
          height: 100%;
          object-fit: cover;
          display: block;
        }
        .wa-avatar svg {
          width: ${Math.round(size * 0.55)}px;
          height: ${Math.round(size * 0.55)}px;
          fill: #ffffff;
        }
      </style>
      <div class="wa-avatar" title="${this.escapeHtml(name)}">
        ${src && !src.startsWith('data:,') ? `
          <img src="${this.escapeHtml(src)}" alt="${this.escapeHtml(name)}" loading="lazy" onerror="this.style.display='none';this.parentElement.style.background='${this.getAvatarColor(name)}';this.nextElementSibling.style.display='flex'">
          <span style="display:none;width:100%;height:100%;align-items:center;justify-content:center;">${this.getInitials(name)}</span>
        ` : (isGroup ? `
          <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
        ` : this.getInitials(name))}
      </div>
    `;
  }
}

customElements.define('wa-avatar', WaAvatar);

// ============ ICON BUTTON ============

class WaIconButton extends WaElement {
  static get observedAttributes() { return ['icon', 'disabled', 'tooltip']; }
  
  attributeChangedCallback() { this.render(); }
  
  template() {
    const icon = this.getAttribute('icon') || '';
    const disabled = this.hasAttribute('disabled');
    const tooltip = this.getAttribute('tooltip') || '';
    
    const icons = {
      search: '<svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>',
      more_vert: '<svg viewBox="0 0 24 24"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>',
      community: '<svg viewBox="0 0 24 24"><path d="M12.071 8.243a3.504 3.504 0 1 0 0-7.008 3.504 3.504 0 0 0 0 7.008zm-5.75 4.316c.307-.84.819-1.579 1.488-2.158C7.159 10.15 6.275 10 5.32 10c-2.454 0-4.32 1.636-4.32 3.636v1.455c0 .5.409.909.909.909h7.405a5.55 5.55 0 0 1-1.028-2.433c-.007-.037-.013-.075-.015-.114v-.893zm11.5 0v.893c-.002.039-.008.077-.015.114a5.55 5.55 0 0 1-1.028 2.433h7.405c.5 0 .909-.409.909-.909v-1.455c0-2-1.866-3.636-4.32-3.636-.955 0-1.839.15-2.489.401.669.579 1.181 1.318 1.488 2.158zm-5.75-2.007c-2.348 0-4.25 1.702-4.25 3.801v1.693c0 .523.427.953.95.953h6.6c.523 0 .95-.43.95-.953v-1.693c0-2.099-1.902-3.801-4.25-3.801z"/></svg>',
      status: '<svg viewBox="0 0 24 24"><path d="M12 20.5a8.5 8.5 0 0 1-7.36-4.25 1 1 0 0 0-1.73 1A10.5 10.5 0 0 0 12 22.5a10.5 10.5 0 0 0 9.09-5.25 1 1 0 0 0-1.73-1A8.5 8.5 0 0 1 12 20.5zM3.41 11.25a8.5 8.5 0 0 1 2.23-5.5 1 1 0 1 0-1.5-1.32A10.5 10.5 0 0 0 1.4 11.25a1 1 0 0 0 2.01 0zm17.18 0a1 1 0 0 0 2.01 0 10.5 10.5 0 0 0-2.74-6.82 1 1 0 1 0-1.5 1.32 8.5 8.5 0 0 1 2.23 5.5zm-8.59-7.75a8.47 8.47 0 0 1 5.95 2.47 1 1 0 0 0 1.41-1.41A10.47 10.47 0 0 0 12 1.5a10.47 10.47 0 0 0-7.36 3.06 1 1 0 0 0 1.41 1.41A8.47 8.47 0 0 1 12 3.5z"/></svg>',
      channels: '<svg viewBox="0 0 24 24"><path d="M12 2.5c-5.24 0-9.5 4.04-9.5 9.03 0 1.83.59 3.53 1.62 4.95L3 21.5l5.24-1.12c1.16.59 2.45.92 3.76.92 5.24 0 9.5-4.04 9.5-9.03S17.24 2.5 12 2.5zm0 16.26c-1.19 0-2.36-.31-3.39-.9l-.36-.21-3.11.66.68-2.97-.24-.36a7.27 7.27 0 0 1-1.28-4.05c0-4.01 3.45-7.23 7.7-7.23s7.7 3.22 7.7 7.23c0 4.02-3.45 7.23-7.7 7.23zm3.25-9.61a1.2 1.2 0 0 0-1.2 1.2v2.4a1.2 1.2 0 1 0 2.4 0v-2.4a1.2 1.2 0 0 0-1.2-1.2zm-6.5 0a1.2 1.2 0 0 0-1.2 1.2v2.4a1.2 1.2 0 1 0 2.4 0v-2.4a1.2 1.2 0 0 0-1.2-1.2zm3.25-1.5a1.2 1.2 0 0 0-1.2 1.2v5.4a1.2 1.2 0 1 0 2.4 0v-5.4a1.2 1.2 0 0 0-1.2-1.2z"/></svg>',
      new_chat: '<svg viewBox="0 0 24 24"><path d="M19.005 3.103a2.44 2.44 0 0 0-2.44 2.44v1.077h-9.13A2.44 2.44 0 0 0 5 9.06v7.387l-2.05 2.05A.75.75 0 0 0 3.48 19.78l2.96-1.22h10.125a2.44 2.44 0 0 0 2.44-2.44V5.543a2.44 2.44 0 0 0-2.44-2.44h2.44zm.94 13.117a.94.94 0 0 1-.94.94H6.18a.75.75 0 0 0-.28.055L4.5 17.854V9.06a.94.94 0 0 1 .94-.94h9.13V5.543a.94.94 0 0 1 .94-.94h2.44a.94.94 0 0 1 .94.94v10.677h.005z"/><path d="M12.005 10.103h-4a.75.75 0 1 0 0 1.5h4a.75.75 0 1 0 0-1.5zm-4 4.5h2.5a.75.75 0 1 0 0-1.5h-2.5a.75.75 0 1 0 0 1.5z"/></svg>',
      call: '<svg viewBox="0 0 24 24"><path d="M20.01 15.38c-1.23 0-2.42-.2-3.53-.56a.977.977 0 0 0-1.01.24l-1.57 1.97c-2.83-1.35-5.48-3.9-6.89-6.83l1.95-1.66c.27-.28.35-.67.24-1.02-.37-1.11-.56-2.3-.56-3.53 0-.54-.45-.99-.99-.99H4.19C3.65 3 3 3.24 3 3.99 3 13.28 10.73 21 20.01 21c.71 0 .99-.63.99-1.18v-3.45c0-.54-.45-.99-.99-.99z"/></svg>',
      videocam: '<svg viewBox="0 0 24 24"><path d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"/></svg>',
      info: '<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>',
      send: '<svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>',
      attach: '<svg viewBox="0 0 24 24"><path d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5a2.5 2.5 0 0 1 5 0v10.5c0 .83-.67 1.5-1.5 1.5s-1.5-.67-1.5-1.5V6H9v9.5a3 3 0 0 0 6 0V5c0-2.21-1.79-4-4-4S7 2.79 7 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-1.5z"/></svg>',
      mic: '<svg viewBox="0 0 24 24"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.5V21h-2v-4.5c-2.76-.4-5-2.5-5-5.5H3c0 4.41 3.59 8 8 8s8-3.59 8-8h-1.7z"/></svg>',
      emoji: '<svg viewBox="0 0 24 24"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm0 18.2A8.2 8.2 0 1 1 12 3.8a8.2 8.2 0 0 1 0 16.4zm-3.5-9.2a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm7 0a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3zm-3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z"/></svg>',
      arrow_back: '<svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>',
      close: '<svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>'
    };
    
    return `
      <style>
        :host {
          display: inline-flex;
          vertical-align: middle;
          flex-shrink: 0;
        }
        .wa-icon-btn {
          display: inline-flex;
          align-items: center;
          justify-content: center;
          width: 36px;
          height: 36px;
          border-radius: 50%;
          background: transparent;
          border: none;
          color: var(--wa-text-secondary-var, #54656f);
          cursor: pointer;
          transition: background 0.15s ease, color 0.15s ease, transform 0.1s ease;
          padding: 0;
          box-sizing: border-box;
          position: relative;
        }
        .wa-icon-btn:hover:not(:disabled) {
          background: rgba(11,20,26,0.08);
          color: var(--wa-text-primary-var, #111b21);
        }
        .wa-icon-btn:active:not(:disabled) {
          background: rgba(11,20,26,0.14);
          transform: scale(0.95);
        }
        .wa-icon-btn.is-active {
          background: rgba(11,20,26,0.12);
          color: var(--wa-text-primary-var, #111b21);
        }
        .wa-icon-btn:disabled {
          opacity: 0.35;
          cursor: not-allowed;
        }
        .wa-icon-btn svg {
          width: 20px;
          height: 20px;
          fill: currentColor;
          display: block;
        }
      </style>
      <button class="wa-icon-btn ${disabled ? 'disabled' : ''}" ${disabled ? 'disabled' : ''} title="${this.escapeHtml(tooltip)}">
        ${icons[icon] || icon}
      </button>
    `;
  }
  
  bindEvents() {
    this.$('.wa-icon-btn')?.addEventListener('click', (e) => {
      if (!this.hasAttribute('disabled')) {
        this.dispatch('click', { originalEvent: e });
      }
    });
  }
}

customElements.define('wa-icon-button', WaIconButton);

// ============ SPINNER ============

class WaSpinner extends WaElement {
  static get observedAttributes() { return ['size']; }
  
  attributeChangedCallback() { this.render(); }
  
  template() {
    const size = parseInt(this.getAttribute('size'), 10) || 24;
    return `
      <style>
        :host {
          display: inline-flex;
          align-items: center;
          justify-content: center;
        }
        .wa-spinner {
          width: ${size}px;
          height: ${size}px;
          border: 2.5px solid rgba(0, 168, 132, 0.2);
          border-top-color: var(--wa-green, #00a884);
          border-radius: 50%;
          animation: waSpin 0.75s linear infinite;
        }
        @keyframes waSpin {
          from { transform: rotate(0deg); }
          to { transform: rotate(360deg); }
        }
      </style>
      <div class="wa-spinner" role="status" aria-label="Loading"></div>
    `;
  }
}

customElements.define('wa-spinner', WaSpinner);
