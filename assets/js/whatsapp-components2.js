/**
 * HCC WhatsApp Web Replica — Additional Web Components (Part 2)
 * Pixel-Perfect WhatsApp Web UI:
 * Conversation Header, Message Bubbles, Composer, Emoji Picker, Attach Menu,
 * Call Simulation Modal, Student Contact Drawer, PDF Preview Lightbox, App Shell
 */

// ============ CONVERSATION HEADER ============

class WaConversationHeader extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.store = window.WhatsAppStore;
    this.inChatSearchOpen = false;
  }

  connectedCallback() {
    this.boundChatSelected = () => this.render();
    this.boundChatUpdated = () => this.render();
    this.boundTyping = (e) => this.handleTyping(e.detail);

    if (this.store) {
      this.store.addEventListener('chat:selected', this.boundChatSelected);
      this.store.addEventListener('chats:updated', this.boundChatUpdated);
      this.store.addEventListener('chat:typing', this.boundTyping);
    }
    this.render();
  }

  disconnectedCallback() {
    if (this.store) {
      this.store.removeEventListener('chat:selected', this.boundChatSelected);
      this.store.removeEventListener('chats:updated', this.boundChatUpdated);
      this.store.removeEventListener('chat:typing', this.boundTyping);
    }
  }

  handleTyping(detail = {}) {
    const chat = this.store?.activeChat;
    if (chat && (chat.id === detail.chatId || chat.id === detail.chat_id)) {
      const statusEl = this.shadowRoot.querySelector('#header-status-line');
      if (statusEl) {
        if (detail.isTyping) {
          statusEl.innerHTML = `<span class="wa-header-typing">typing...</span>`;
        } else {
          statusEl.innerHTML = this.getStatusHtml(chat);
        }
      }
    }
  }

  getStatusHtml(chat) {
    if (!chat) return '';
    if (chat.is_typing || chat.typing) {
      return `<span class="wa-header-typing">typing...</span>`;
    }
    const isOnline = chat.is_online || (chat.online_until && new Date() < new Date(chat.online_until));
    if (isOnline) {
      return `<span class="wa-header-online"><span class="wa-online-dot"></span>online</span>`;
    }
    if (chat.chat_type === 'group' || chat.chat_type === 'broadcast') {
      const count = chat.participants?.length || 32;
      return `<span class="wa-header-offline">${count} participants · Tap here for group info</span>`;
    }
    if (chat.last_seen) {
      const date = new Date(chat.last_seen);
      const timeStr = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: true });
      return `<span class="wa-header-offline">last seen today at ${timeStr}</span>`;
    }
    return `<span class="wa-header-offline">offline</span>`;
  }

  render() {
    let chat = this.store?.activeChat;
    if (!chat && this.store?.activeChatId) {
      chat = this.store.chats?.get(this.store.activeChatId)
        || this.store.chats?.get(parseInt(this.store.activeChatId, 10))
        || this.store.chats?.get(String(this.store.activeChatId))
        || Array.from(this.store.chats?.values() || []).find(c => c.id == this.store.activeChatId);
      if (chat && this.store) this.store.activeChat = chat;
    }

    if (!chat) {
      this.shadowRoot.innerHTML = `
        <style>
          :host {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 60px;
            padding: 0 16px;
            background: var(--wa-bg-chat-list-var, #f0f2f5);
            border-bottom: 1px solid var(--wa-border-light, #e9edef);
            color: var(--wa-text-tertiary-var, #8696a0);
            font-size: 14px;
          }
        </style>
        <div>Select a conversation to start messaging</div>
      `;
      return;
    }

    const studentData = chat.student_data || chat.metadata?.student_data || {};
    const parentPhone = chat.phone_e164 || chat.wa_chat_id || studentData.parent_phone || '';
    const displayName = chat.name || studentData.name || 'Student';
    const subTitle = parentPhone ? `${parentPhone}` : '';

    this.shadowRoot.innerHTML = `
      <style>
        :host {
          display: flex;
          flex-direction: column;
          background: var(--wa-bg-chat-list-var, #f0f2f5);
          border-bottom: 1px solid var(--wa-border-light, #e9edef);
          flex-shrink: 0;
          box-sizing: border-box;
          position: relative;
          z-index: 50;
        }
        .wa-header-main {
          display: flex;
          align-items: center;
          gap: 12px;
          height: 60px;
          padding: 10px 16px;
          box-sizing: border-box;
        }
        .wa-back-btn {
          display: none;
          background: none;
          border: none;
          color: var(--wa-text-secondary-var, #54656f);
          cursor: pointer;
          padding: 6px;
          border-radius: 50%;
        }
        @media (max-width: 768px) {
          .wa-back-btn { display: inline-flex; }
        }
        .wa-header-avatar {
          cursor: pointer;
          flex-shrink: 0;
          position: relative;
        }
        .wa-header-info {
          flex: 1;
          min-width: 0;
          cursor: pointer;
          display: flex;
          flex-direction: column;
          justify-content: center;
        }
        .wa-header-name {
          font-size: 16px;
          font-weight: 600;
          color: var(--wa-text-primary-var, #111b21);
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
          line-height: 20px;
        }
        .wa-header-name span.phone {
          font-size: 12.5px;
          font-weight: normal;
          color: var(--wa-text-secondary-var, #667781);
          margin-left: 6px;
        }
        .wa-header-status {
          font-size: 12px;
          color: var(--wa-text-secondary-var, #667781);
          line-height: 16px;
          display: flex;
          align-items: center;
          gap: 4px;
        }
        .wa-header-typing {
          color: var(--wa-green, #00a884);
          font-weight: 600;
        }
        .wa-header-online {
          color: var(--wa-green, #00a884);
          font-weight: 500;
          display: flex;
          align-items: center;
          gap: 4px;
        }
        .wa-online-dot {
          width: 7px;
          height: 7px;
          border-radius: 50%;
          background: var(--wa-green, #00a884);
          display: inline-block;
        }
        .wa-header-actions {
          display: flex;
          align-items: center;
          gap: 4px;
        }
        
        /* IN-CHAT SEARCH BAR */
        .wa-inchat-search {
          display: ${this.inChatSearchOpen ? 'flex' : 'none'};
          align-items: center;
          gap: 10px;
          padding: 8px 16px;
          background: #ffffff;
          border-top: 1px solid var(--wa-border-light, #e9edef);
          box-shadow: 0 2px 4px rgba(0,0,0,0.04);
        }
        .wa-inchat-search input {
          flex: 1;
          border: 1px solid var(--wa-border-medium, #cbd5e1);
          border-radius: 6px;
          padding: 6px 12px;
          font-size: 13.5px;
          outline: none;
          color: #111b21;
          font-family: inherit;
        }
        .wa-inchat-search input:focus {
          border-color: var(--wa-green, #00a884);
        }
        .wa-inchat-search-btn {
          background: none;
          border: none;
          color: #667781;
          cursor: pointer;
          font-size: 14px;
          padding: 4px 8px;
          border-radius: 4px;
        }
        .wa-inchat-search-btn:hover {
          background: #f1f5f9;
          color: #111b21;
        }
        
        /* DROPDOWN MENU */
        .wa-convo-menu {
          display: none;
          position: absolute;
          top: 56px;
          right: 12px;
          z-index: 1000;
          background: #ffffff;
          border-radius: 8px;
          box-shadow: 0 4px 16px rgba(11,20,26,0.18);
          border: 1px solid #e2e8f0;
          min-width: 200px;
          padding: 6px 0;
          animation: waDropScale 0.15s ease-out;
        }
        .wa-convo-menu.open { display: block; }
        .wa-convo-item {
          display: flex;
          align-items: center;
          gap: 12px;
          width: 100%;
          padding: 9px 16px;
          font-size: 14px;
          color: #111b21;
          background: none;
          border: none;
          text-align: left;
          cursor: pointer;
        }
        .wa-convo-item:hover { background: #f5f6f6; }
        .wa-convo-item.danger { color: #e74c3c; }
        .wa-convo-divider { height: 1px; background: #e2e8f0; margin: 4px 0; }
      </style>
      
      <div class="wa-header-main">
        <button class="wa-back-btn" id="btn-back" title="Back to chats">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
        </button>
        
        <div class="wa-header-avatar" id="avatar-trigger" title="View contact info">
          <wa-avatar src="${this.escapeHtml(chat.avatar_url || '')}" name="${this.escapeHtml(displayName)}" size="42"></wa-avatar>
        </div>
        
        <div class="wa-header-info" id="info-trigger" title="Click for student profile & details">
          <div class="wa-header-name">
            ${this.escapeHtml(displayName)}
            ${subTitle ? `<span class="phone">(${this.escapeHtml(subTitle)})</span>` : ''}
          </div>
          <div class="wa-header-status" id="header-status-line">
            ${this.getStatusHtml(chat)}
          </div>
        </div>
        
        <div class="wa-header-actions">
          <!-- Audio Call button -->
          <wa-icon-button id="btn-audio-call" icon="call" tooltip="Voice call"></wa-icon-button>
          <!-- Video Call button -->
          <wa-icon-button id="btn-video-call" icon="videocam" tooltip="Video call"></wa-icon-button>
          <!-- Search in chat button -->
          <wa-icon-button id="btn-search-chat" icon="search" tooltip="Search in chat"></wa-icon-button>
          <!-- Contact Info button -->
          <wa-icon-button id="btn-contact-info" icon="info" tooltip="Contact info"></wa-icon-button>
          <!-- 3-Dots Menu -->
          <wa-icon-button id="btn-convo-menu" icon="more_vert" tooltip="Menu"></wa-icon-button>
        </div>
      </div>
      
      <!-- IN-CHAT SEARCH BAR -->
      <div class="wa-inchat-search" id="inchat-search-bar">
        <input type="text" id="inchat-search-input" placeholder="Search in chat...">
        <span style="font-size:12px;color:#8696a0;" id="inchat-match-count"></span>
        <button class="wa-inchat-search-btn" id="inchat-prev-btn" title="Previous match">▲</button>
        <button class="wa-inchat-search-btn" id="inchat-next-btn" title="Next match">▼</button>
        <button class="wa-inchat-search-btn" id="inchat-close-btn" title="Close search">✕</button>
      </div>
      
      <!-- CONVERSATION DROPDOWN MENU -->
      <div class="wa-convo-menu" id="convo-dropdown">
        <button class="wa-convo-item" data-action="contact-info">ℹ️ Contact info</button>
        <button class="wa-convo-item" data-action="select-messages">☑️ Select messages</button>
        <button class="wa-convo-item" data-action="mute-chat">🔕 ${chat.is_muted ? 'Unmute notifications' : 'Mute notifications'}</button>
        <div class="wa-convo-divider"></div>
        <button class="wa-convo-item" data-action="export-chat">📥 Export chat</button>
        <button class="wa-convo-item danger" data-action="clear-chat">🧹 Clear chat</button>
      </div>
    `;

    this.bindHeaderEvents();
  }

  bindHeaderEvents() {
    const chat = this.store?.activeChat;
    if (!chat) return;

    // Back button for mobile
    this.shadowRoot.querySelector('#btn-back')?.addEventListener('click', () => {
      this.dispatchEvent(new CustomEvent('back-to-list', { bubbles: true, composed: true }));
    });

    // Contact info triggers
    const openContactInfo = () => {
      this.dispatchEvent(new CustomEvent('open-contact-drawer', { detail: { chat }, bubbles: true, composed: true }));
    };
    this.shadowRoot.querySelector('#avatar-trigger')?.addEventListener('click', openContactInfo);
    this.shadowRoot.querySelector('#info-trigger')?.addEventListener('click', openContactInfo);
    this.shadowRoot.querySelector('#btn-contact-info')?.addEventListener('click', openContactInfo);

    // Call buttons
    this.shadowRoot.querySelector('#btn-audio-call')?.addEventListener('click', () => {
      this.dispatchEvent(new CustomEvent('open-call-modal', { detail: { chat, callType: 'audio' }, bubbles: true, composed: true }));
    });
    this.shadowRoot.querySelector('#btn-video-call')?.addEventListener('click', () => {
      this.dispatchEvent(new CustomEvent('open-call-modal', { detail: { chat, callType: 'video' }, bubbles: true, composed: true }));
    });

    // In-chat search toggle
    const searchBar = this.shadowRoot.querySelector('#inchat-search-bar');
    const searchInput = this.shadowRoot.querySelector('#inchat-search-input');
    this.shadowRoot.querySelector('#btn-search-chat')?.addEventListener('click', () => {
      this.inChatSearchOpen = !this.inChatSearchOpen;
      if (searchBar) searchBar.style.display = this.inChatSearchOpen ? 'flex' : 'none';
      if (this.inChatSearchOpen && searchInput) searchInput.focus();
    });

    this.shadowRoot.querySelector('#inchat-close-btn')?.addEventListener('click', () => {
      this.inChatSearchOpen = false;
      if (searchBar) searchBar.style.display = 'none';
      if (searchInput) searchInput.value = '';
      this.dispatchEvent(new CustomEvent('inchat-search', { detail: { query: '' }, bubbles: true, composed: true }));
    });

    searchInput?.addEventListener('input', (e) => {
      this.dispatchEvent(new CustomEvent('inchat-search', { detail: { query: e.target.value }, bubbles: true, composed: true }));
    });

    // 3-dots Menu dropdown
    const menuBtn = this.shadowRoot.querySelector('#btn-convo-menu');
    const dropdown = this.shadowRoot.querySelector('#convo-dropdown');
    menuBtn?.addEventListener('click', (e) => {
      e.stopPropagation();
      dropdown?.classList.toggle('open');
    });

    document.addEventListener('click', (e) => {
      if (dropdown && !dropdown.contains(e.target)) {
        dropdown.classList.remove('open');
      }
    });

    // Menu actions
    this.shadowRoot.querySelectorAll('.wa-convo-item').forEach(item => {
      item.addEventListener('click', async () => {
        const action = item.dataset.action;
        dropdown?.classList.remove('open');

        if (action === 'contact-info') {
          openContactInfo();
        } else if (action === 'select-messages') {
          this.dispatchEvent(new CustomEvent('toggle-select-messages', { bubbles: true, composed: true }));
        } else if (action === 'mute-chat') {
          await this.store.chatAction(chat.id, 'mute', { mute: !chat.is_muted });
          this.render();
        } else if (action === 'clear-chat') {
          const confirmFn = window.hccConfirm || ((opts) => Promise.resolve(confirm(typeof opts === 'string' ? opts : opts.message)));
          const confirmed = await confirmFn({
            title: 'Clear Chat Messages',
            message: `Clear all messages with ${chat.name}?`,
            confirmText: 'Clear Messages',
            type: 'danger'
          });
          if (confirmed) {
            await this.store.chatAction(chat.id, 'clear');
            this.dispatchEvent(new CustomEvent('chat-cleared', { detail: { chatId: chat.id }, bubbles: true, composed: true }));
          }
        } else if (action === 'export-chat') {
          this.exportChatHistory(chat);
        }
      });
    });
  }

  exportChatHistory(chat) {
    const messagesMap = this.store.messages?.get(chat.id);
    const messages = messagesMap ? Array.from(messagesMap.values()) : [];
    let text = `WhatsApp Chat with ${chat.name} (${chat.wa_chat_id})\n`;
    text += `Exported on ${new Date().toLocaleString()}\n`;
    text += `====================================================\n\n`;

    messages.forEach(m => {
      const time = new Date((m.timestamp || 0) * 1000).toLocaleString();
      const sender = m.from_me ? 'Hindustani Covenant Church High School' : chat.name;
      text += `[${time}] ${sender}: ${m.body || m.caption || '[' + (m.msg_type || 'Media') + ']'}\n`;
    });

    const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `WhatsApp_Chat_${(chat.name || 'Student').replace(/\s+/g, '_')}.txt`;
    a.click();
    URL.revokeObjectURL(url);
  }

  escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }
}
customElements.define('wa-conversation-header', WaConversationHeader);

// ============ MESSAGE BUBBLE ============

class WaMessageBubble extends HTMLElement {
  static get observedAttributes() { return ['message']; }

  attributeChangedCallback(name, oldValue, newValue) {
    if (name === 'message' && oldValue !== newValue) {
      this.render();
    }
  }

  connectedCallback() {
    this.attachShadow({ mode: 'open' });
    this.store = window.WhatsAppStore;
    this.render();
  }

  get msg() {
    try {
      return JSON.parse(this.getAttribute('message') || '{}');
    } catch {
      return {};
    }
  }

  formatWhatsAppText(text) {
    if (!text) return '';
    let escaped = this.escapeHtml(text);

    // Code blocks ```code```
    escaped = escaped.replace(/```([\s\S]*?)```/g, '<pre class="wa-code-block"><code>$1</code></pre>');
    // Inline code `code`
    escaped = escaped.replace(/`([^`]+)`/g, '<code class="wa-inline-code">$1</code>');
    // Bold *bold*
    escaped = escaped.replace(/\*([^*\n]+)\*/g, '<strong>$1</strong>');
    // Italic _italic_
    escaped = escaped.replace(/_([^_\n]+)_/g, '<em>$1</em>');
    // Strikethrough ~strike~
    escaped = escaped.replace(/~([^~\n]+)~/g, '<del>$1</del>');
    // URL links
    escaped = escaped.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer" class="wa-msg-link">$1</a>');
    // Line breaks
    escaped = escaped.replace(/\n/g, '<br>');

    return escaped;
  }

  render() {
    const msg = this.msg;
    if (!msg.id) {
      this.shadowRoot.innerHTML = '<div style="padding:8px">Loading...</div>';
      return;
    }

    const isOwn = !!msg.from_me;
    const isGroup = this.store?.activeChat?.chat_type === 'group';
    const sender = msg.sender_name || (isGroup && !isOwn ? (this.store?.getContact(msg.sender_id || msg.sender_wa_id)?.name || 'Parent') : '');
    const showSender = isGroup && !isOwn && sender;

    const time = msg.timestamp 
      ? new Date(msg.timestamp * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: true })
      : '';

    // Status Tick SVG
    let statusTickHtml = '';
    if (isOwn) {
      const status = (msg.status || 'sent').toLowerCase();
      if (status === 'pending') {
        statusTickHtml = `<span class="wa-tick pending" title="Pending">🕐</span>`;
      } else if (status === 'sent') {
        statusTickHtml = `<svg class="wa-tick sent" viewBox="0 0 16 15" width="16" height="15" fill="#8696a0" title="Sent"><path d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L6.466 12.07l-3.52-3.18a.366.366 0 0 0-.505.018l-.423.435a.366.366 0 0 0 .018.505l4.183 3.778c.07.063.16.098.254.098.094 0 .185-.035.255-.098l8.22-9.799a.365.365 0 0 0-.063-.51z"/></svg>`;
      } else if (status === 'delivered') {
        statusTickHtml = `<svg class="wa-tick delivered" viewBox="0 0 16 15" width="16" height="15" fill="#8696a0" title="Delivered"><path d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L6.466 12.07l-1.92-1.73a.366.366 0 0 0-.505.018l-.423.435a.366.366 0 0 0 .018.505l2.583 2.333c.07.063.16.098.254.098.094 0 .185-.035.255-.098l8.22-9.799a.365.365 0 0 0-.063-.51z"/><path d="M11.51 3.316l-.478-.372a.365.365 0 0 0-.51.063L2.966 12.07l-1.92-1.73a.366.366 0 0 0-.505.018l-.423.435a.366.366 0 0 0 .018.505l2.583 2.333c.07.063.16.098.254.098.094 0 .185-.035.255-.098l8.22-9.799a.365.365 0 0 0-.063-.51z"/></svg>`;
      } else if (status === 'read') {
        statusTickHtml = `<svg class="wa-tick read" viewBox="0 0 16 15" width="16" height="15" fill="#53bdeb" title="Read"><path d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L6.466 12.07l-1.92-1.73a.366.366 0 0 0-.505.018l-.423.435a.366.366 0 0 0 .018.505l2.583 2.333c.07.063.16.098.254.098.094 0 .185-.035.255-.098l8.22-9.799a.365.365 0 0 0-.063-.51z"/><path d="M11.51 3.316l-.478-.372a.365.365 0 0 0-.51.063L2.966 12.07l-1.92-1.73a.366.366 0 0 0-.505.018l-.423.435a.366.366 0 0 0 .018.505l2.583 2.333c.07.063.16.098.254.098.094 0 .185-.035.255-.098l8.22-9.799a.365.365 0 0 0-.063-.51z"/></svg>`;
      }
    }

    // PDF / Document Detection
    const isDoc = msg.msg_type === 'document' || (msg.media_path && msg.media_path.endsWith('.pdf')) || (msg.body && msg.body.endsWith('.pdf'));
    const docName = msg.file_name || (msg.media_path ? msg.media_path.split('/').pop() : 'Report_Card.pdf');
    const docSize = msg.file_size || (msg.media_size ? this.formatSize(msg.media_size) : '245 KB');
    const docUrl = msg.media_url || msg.media_path || '';

    // Construct Content
    let bodyContent = '';
    if (isDoc) {
      bodyContent = `
        <div class="wa-pdf-card">
          <div class="wa-pdf-main">
            <div class="wa-pdf-icon-badge">
              <svg viewBox="0 0 24 24" width="28" height="28" fill="#e74c3c"><path d="M20 2H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-8.5 7.5c0 .83-.67 1.5-1.5 1.5H9v2H7.5V7H10c.83 0 1.5.67 1.5 1.5v1zm5 2c0 .83-.67 1.5-1.5 1.5h-2.5V7H15c.83 0 1.5.67 1.5 1.5v3zm4-3H19v1h1.5V11H19v2h-1.5V7h3v1.5zM9 9.5h1v-1H9v1zM4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm10 5.5h1v-3h-1v3z"/></svg>
            </div>
            <div class="wa-pdf-details">
              <span class="wa-pdf-title" title="${this.escapeHtml(docName)}">${this.escapeHtml(docName)}</span>
              <span class="wa-pdf-meta">${docSize} · PDF</span>
            </div>
          </div>
          <div class="wa-pdf-actions">
            <button class="wa-pdf-btn-preview" id="btn-preview-pdf">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
              <span>Preview PDF</span>
            </button>
            <button class="wa-pdf-btn-dl" id="btn-download-pdf" title="Download Document">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
            </button>
          </div>
          ${msg.caption ? `<div class="wa-pdf-caption">${this.formatWhatsAppText(msg.caption)}</div>` : ''}
        </div>
      `;
    } else if (msg.msg_type === 'image') {
      bodyContent = `
        <div class="wa-media-box">
          <img src="${this.escapeHtml(msg.media_url || '')}" alt="${this.escapeHtml(msg.caption || 'Photo')}" loading="lazy" class="wa-media-img">
          ${msg.caption ? `<div class="wa-media-caption">${this.formatWhatsAppText(msg.caption)}</div>` : ''}
        </div>
      `;
    } else if (msg.msg_type === 'video') {
      bodyContent = `
        <div class="wa-media-box">
          <video src="${this.escapeHtml(msg.media_url || '')}" controls class="wa-media-video"></video>
          ${msg.caption ? `<div class="wa-media-caption">${this.formatWhatsAppText(msg.caption)}</div>` : ''}
        </div>
      `;
    } else if (msg.msg_type === 'audio') {
      const audioUrl = msg.media_url || msg.media_path || '';
      const dur = msg.duration ? `${Math.floor(msg.duration / 60)}:${(msg.duration % 60).toString().padStart(2, '0')}` : '0:05';
      bodyContent = `
        <div class="wa-audio-bubble">
          <button type="button" class="wa-audio-play-btn" id="btn-play-audio" title="Play Voice Message">
            <svg class="icon-play" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
            <svg class="icon-pause" style="display:none;" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
          </button>
          <div class="wa-audio-track-wrap">
            <div class="wa-audio-bars">
              <span class="wa-ab"></span><span class="wa-ab"></span><span class="wa-ab"></span>
              <span class="wa-ab"></span><span class="wa-ab"></span><span class="wa-ab"></span>
              <span class="wa-ab"></span><span class="wa-ab"></span><span class="wa-ab"></span>
              <span class="wa-ab"></span><span class="wa-ab"></span><span class="wa-ab"></span>
              <span class="wa-ab"></span><span class="wa-ab"></span><span class="wa-ab"></span>
            </div>
            <div class="wa-audio-info-row">
              <span class="wa-audio-time" id="audio-time-label">${dur}</span>
              <span class="wa-audio-tag">Voice note</span>
            </div>
          </div>
          ${audioUrl ? `<audio id="bubble-audio-el" src="${this.escapeHtml(audioUrl)}" preload="none"></audio>` : ''}
        </div>
      `;
    } else {
      bodyContent = `<div class="wa-bubble-text">${this.formatWhatsAppText(msg.body || '')}</div>`;
    }

    // Quoted reply message
    let quotedHtml = '';
    if (msg.reply_to || msg.quoted_message) {
      const q = msg.reply_to || msg.quoted_message;
      quotedHtml = `
        <div class="wa-quoted-container">
          <div class="wa-quoted-sender">${this.escapeHtml(q.sender_name || (q.from_me ? 'You' : 'Student'))}</div>
          <div class="wa-quoted-text">${this.escapeHtml(q.body || q.caption || 'Message')}</div>
        </div>
      `;
    }

    // Reactions
    const reactions = msg.reactions || [];
    const reactionHtml = reactions.length > 0 ? `
      <div class="wa-reaction-badge-pill">
        ${reactions.map(r => `<span>${r.emoji} ${r.count > 1 ? r.count : ''}</span>`).join('')}
      </div>
    ` : '';

    this.shadowRoot.innerHTML = `
      <style>
        :host {
          display: flex;
          flex-direction: column;
          width: 100%;
          margin-bottom: 3px;
          position: relative;
        }
        
        .wa-bubble-wrapper {
          display: flex;
          position: relative;
          max-width: 72%;
          align-self: ${isOwn ? 'flex-end' : 'flex-start'};
        }
        
        /* AUTHENTIC WHATSAPP BUBBLE STYLING WITH POINTY TAILS */
        .wa-bubble {
          position: relative;
          padding: 6px 9px 8px 10px;
          font-size: 14.2px;
          line-height: 19px;
          color: var(--wa-text-primary-var, #111b21);
          box-shadow: 0 1px 0.5px rgba(11,20,26,0.13);
          border-radius: 7.5px;
          word-break: break-word;
          min-width: 90px;
        }
        
        .wa-bubble-out {
          background: #d9fdd3;
          border-top-right-radius: 0;
          color: #111b21;
        }
        
        .wa-bubble-in {
          background: #ffffff;
          border-top-left-radius: 0;
          color: #111b21;
        }
        
        /* POINTY TAILS */
        .wa-bubble-out::after {
          content: "";
          position: absolute;
          top: 0;
          right: -8px;
          width: 0;
          height: 0;
          border: 8px solid transparent;
          border-top-color: #d9fdd3;
          border-right: 0;
          border-bottom: 0;
        }
        
        .wa-bubble-in::after {
          content: "";
          position: absolute;
          top: 0;
          left: -8px;
          width: 0;
          height: 0;
          border: 8px solid transparent;
          border-top-color: #ffffff;
          border-left: 0;
          border-bottom: 0;
        }
        
        .wa-bubble-sender {
          font-size: 12.5px;
          font-weight: 600;
          color: #008069;
          margin-bottom: 3px;
        }
        
        .wa-bubble-text {
          white-space: pre-wrap;
          word-break: break-word;
        }
        
        .wa-inline-code {
          background: rgba(0,0,0,0.06);
          padding: 2px 4px;
          border-radius: 4px;
          font-family: monospace;
          font-size: 13px;
        }
        .wa-code-block {
          background: rgba(0,0,0,0.06);
          padding: 6px 8px;
          border-radius: 4px;
          font-family: monospace;
          font-size: 12.5px;
          overflow-x: auto;
          margin: 4px 0;
        }
        .wa-msg-link {
          color: #027eb5;
          text-decoration: underline;
        }
        
        /* PDF ATTACHMENT CARD */
        .wa-pdf-card {
          display: flex;
          flex-direction: column;
          gap: 6px;
          background: rgba(0,0,0,0.04);
          border-radius: 6px;
          padding: 8px 10px;
          margin-bottom: 4px;
          border: 1px solid rgba(0,0,0,0.06);
        }
        .wa-pdf-main {
          display: flex;
          align-items: center;
          gap: 10px;
        }
        .wa-pdf-icon-badge {
          width: 40px;
          height: 40px;
          border-radius: 8px;
          background: #fee2e2;
          display: flex;
          align-items: center;
          justify-content: center;
          flex-shrink: 0;
        }
        .wa-pdf-details {
          flex: 1;
          min-width: 0;
        }
        .wa-pdf-title {
          display: block;
          font-size: 13.5px;
          font-weight: 600;
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
        }
        .wa-pdf-meta {
          font-size: 11.5px;
          color: #667781;
        }
        .wa-pdf-actions {
          display: flex;
          gap: 6px;
          margin-top: 4px;
        }
        .wa-pdf-btn-preview {
          flex: 1;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          gap: 6px;
          background: var(--wa-green, #00a884);
          color: #ffffff;
          border: none;
          border-radius: 6px;
          padding: 6px 12px;
          font-size: 12.5px;
          font-weight: 600;
          cursor: pointer;
        }
        .wa-pdf-btn-preview:hover {
          background: #008069;
        }
        .wa-pdf-btn-dl {
          width: 32px;
          height: 32px;
          border-radius: 6px;
          background: #ffffff;
          border: 1px solid #cbd5e1;
          color: #334155;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
        }
        .wa-pdf-btn-dl:hover {
          background: #f1f5f9;
        }

        /* AUDIO VOICE NOTE BUBBLE */
        .wa-audio-bubble {
          display: flex;
          align-items: center;
          gap: 12px;
          padding: 6px 4px;
          min-width: 230px;
        }
        .wa-audio-play-btn {
          width: 38px;
          height: 38px;
          border-radius: 50%;
          border: none;
          background: #00a884;
          color: #ffffff;
          display: flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
          flex-shrink: 0;
          transition: transform 0.15s, background 0.15s;
        }
        .wa-audio-play-btn:hover {
          background: #008069;
          transform: scale(1.05);
        }
        .wa-audio-play-btn svg {
          width: 20px;
          height: 20px;
          fill: currentColor;
        }
        .wa-audio-track-wrap {
          flex: 1;
          display: flex;
          flex-direction: column;
          gap: 4px;
        }
        .wa-audio-bars {
          display: flex;
          align-items: center;
          gap: 2px;
          height: 18px;
        }
        .wa-ab {
          flex: 1;
          height: 8px;
          background: #8696a0;
          border-radius: 2px;
        }
        .wa-ab:nth-child(2n) { height: 14px; }
        .wa-ab:nth-child(3n) { height: 18px; }
        .wa-ab:nth-child(4n) { height: 10px; }
        .wa-audio-info-row {
          display: flex;
          justify-content: space-between;
          font-size: 11px;
          color: #667781;
        }
        
        /* QUOTED BOX */
        .wa-quoted-container {
          background: rgba(0,0,0,0.05);
          border-left: 4px solid var(--wa-green, #00a884);
          padding: 4px 8px;
          border-radius: 4px;
          margin-bottom: 6px;
          font-size: 12.5px;
        }
        .wa-quoted-sender {
          font-weight: 600;
          color: var(--wa-green, #00a884);
        }
        .wa-quoted-text {
          color: #54656f;
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
        }
        
        /* METADATA ROW */
        .wa-bubble-meta {
          display: inline-flex;
          align-items: center;
          gap: 3px;
          font-size: 11px;
          color: #667781;
          float: right;
          margin: 3px 0 -2px 10px;
          user-select: none;
        }
        .wa-tick {
          display: inline-flex;
          align-items: center;
        }
        
        /* REACTION PILL */
        .wa-reaction-badge-pill {
          position: absolute;
          bottom: -9px;
          right: 12px;
          background: #ffffff;
          border-radius: 12px;
          padding: 2px 6px;
          box-shadow: 0 1px 3px rgba(0,0,0,0.18);
          font-size: 12px;
          display: flex;
          align-items: center;
          gap: 2px;
          cursor: pointer;
        }
        
        /* FLOATING REACTION BAR ON HOVER */
        .wa-reaction-bar {
          position: absolute;
          top: -36px;
          ${isOwn ? 'right: 8px;' : 'left: 8px;'}
          background: #ffffff;
          border-radius: 20px;
          box-shadow: 0 2px 8px rgba(11,20,26,0.18);
          padding: 3px 8px;
          display: none;
          align-items: center;
          gap: 6px;
          z-index: 20;
          animation: waPopIn 0.12s ease-out;
        }
        .wa-bubble-wrapper:hover .wa-reaction-bar {
          display: flex;
        }
        @keyframes waPopIn {
          from { opacity: 0; transform: translateY(4px); }
          to { opacity: 1; transform: translateY(0); }
        }
        .wa-reaction-btn {
          background: none;
          border: none;
          font-size: 16px;
          cursor: pointer;
          padding: 2px 4px;
          border-radius: 50%;
          transition: transform 0.12s;
        }
        .wa-reaction-btn:hover {
          transform: scale(1.3);
        }
        
        /* HOVER CHEVRON MENU */
        .wa-bubble-chevron {
          position: absolute;
          top: 4px;
          right: 4px;
          display: none;
          background: transparent;
          border: none;
          color: #667781;
          cursor: pointer;
          padding: 2px;
          border-radius: 50%;
        }
        .wa-bubble-wrapper:hover .wa-bubble-chevron {
          display: inline-flex;
        }
        
        /* DROPDOWN MENU */
        .wa-bubble-dropdown {
          display: none;
          position: absolute;
          top: 24px;
          right: 4px;
          z-index: 100;
          background: #ffffff;
          border-radius: 8px;
          box-shadow: 0 4px 16px rgba(11,20,26,0.2);
          border: 1px solid #e2e8f0;
          min-width: 150px;
          padding: 4px 0;
        }
        .wa-bubble-dropdown.open { display: block; }
        .wa-bubble-menu-btn {
          display: flex;
          align-items: center;
          gap: 8px;
          width: 100%;
          padding: 8px 14px;
          font-size: 13.5px;
          color: #111b21;
          background: none;
          border: none;
          cursor: pointer;
          text-align: left;
        }
        .wa-bubble-menu-btn:hover { background: #f5f6f6; }
        .wa-bubble-menu-btn.danger { color: #e74c3c; }
      </style>
      
      <div class="wa-bubble-wrapper">
        <!-- FLOATING REACTION BAR -->
        <div class="wa-reaction-bar" id="reaction-bar">
          <button class="wa-reaction-btn" data-emoji="👍" title="Thumbs Up">👍</button>
          <button class="wa-reaction-btn" data-emoji="❤️" title="Heart">❤️</button>
          <button class="wa-reaction-btn" data-emoji="😂" title="Laugh">😂</button>
          <button class="wa-reaction-btn" data-emoji="😮" title="Wow">😮</button>
          <button class="wa-reaction-btn" data-emoji="😢" title="Sad">😢</button>
          <button class="wa-reaction-btn" data-emoji="🙏" title="Pray">🙏</button>
          <button class="wa-reaction-btn" data-emoji="plus" title="More reactions">+</button>
        </div>
        
        <div class="wa-bubble ${isOwn ? 'wa-bubble-out' : 'wa-bubble-in'}" data-msg-id="${msg.id}">
          <button class="wa-bubble-chevron" id="btn-bubble-menu" title="Message menu">
            <svg viewBox="0 0 19 20" width="16" height="16" fill="currentColor"><path d="m3.8 6.7 5.7 5.7 5.7-5.7 1.6 1.6-7.3 7.3-7.3-7.3 1.6-1.6z"/></svg>
          </button>
          
          <!-- BUBBLE DROPDOWN -->
          <div class="wa-bubble-dropdown" id="bubble-dropdown">
            <button class="wa-bubble-menu-btn" data-action="reply">↩️ Reply</button>
            <button class="wa-bubble-menu-btn" data-action="react">😊 React</button>
            <button class="wa-bubble-menu-btn" data-action="star">⭐ ${msg.is_starred ? 'Unstar' : 'Star'}</button>
            <button class="wa-bubble-menu-btn" data-action="copy">📋 Copy</button>
            <button class="wa-bubble-menu-btn" data-action="forward">↪️ Forward</button>
            <div style="height:1px;background:#e2e8f0;margin:3px 0"></div>
            <button class="wa-bubble-menu-btn danger" data-action="delete">🗑️ Delete</button>
          </div>
          
          ${showSender ? `<div class="wa-bubble-sender">${this.escapeHtml(sender)}</div>` : ''}
          ${quotedHtml}
          ${bodyContent}
          
          <div class="wa-bubble-meta">
            ${msg.is_starred ? '<span title="Starred">⭐</span>' : ''}
            <span>${time}</span>
            ${statusTickHtml}
          </div>
          ${reactionHtml}
        </div>
      </div>
    `;

    this.bindBubbleEvents(docUrl, docName);
  }

  bindBubbleEvents(docUrl, docName) {
    const msg = this.msg;

    // PDF Preview Lightbox trigger
    this.shadowRoot.querySelector('#btn-preview-pdf')?.addEventListener('click', (e) => {
      e.stopPropagation();
      this.dispatchEvent(new CustomEvent('preview-pdf', {
        detail: { url: docUrl, title: docName },
        bubbles: true,
        composed: true
      }));
    });

    // PDF Download trigger
    this.shadowRoot.querySelector('#btn-download-pdf')?.addEventListener('click', (e) => {
      e.stopPropagation();
      if (docUrl) {
        const a = document.createElement('a');
        a.href = docUrl;
        a.download = docName;
        a.click();
      } else {
        alert(`Downloading ${docName}...`);
      }
    });

    // Audio Voice Note Play/Pause trigger
    const playBtn = this.shadowRoot.querySelector('#btn-play-audio');
    const audioEl = this.shadowRoot.querySelector('#bubble-audio-el');
    const iconPlay = playBtn?.querySelector('.icon-play');
    const iconPause = playBtn?.querySelector('.icon-pause');
    const timeLabel = this.shadowRoot.querySelector('#audio-time-label');

    playBtn?.addEventListener('click', (e) => {
      e.stopPropagation();
      if (!audioEl) {
        if (playBtn.classList.contains('playing')) {
          playBtn.classList.remove('playing');
          if (iconPlay) iconPlay.style.display = 'block';
          if (iconPause) iconPause.style.display = 'none';
        } else {
          playBtn.classList.add('playing');
          if (iconPlay) iconPlay.style.display = 'none';
          if (iconPause) iconPause.style.display = 'block';
          try { window.WhatsAppAudio?.playSentSound(); } catch (e) {}
          setTimeout(() => {
            playBtn.classList.remove('playing');
            if (iconPlay) iconPlay.style.display = 'block';
            if (iconPause) iconPause.style.display = 'none';
          }, 3000);
        }
        return;
      }
      if (audioEl.paused) {
        audioEl.play().then(() => {
          if (iconPlay) iconPlay.style.display = 'none';
          if (iconPause) iconPause.style.display = 'block';
        }).catch(() => {});
      } else {
        audioEl.pause();
        if (iconPlay) iconPlay.style.display = 'block';
        if (iconPause) iconPause.style.display = 'none';
      }
    });

    audioEl?.addEventListener('ended', () => {
      if (iconPlay) iconPlay.style.display = 'block';
      if (iconPause) iconPause.style.display = 'none';
    });

    audioEl?.addEventListener('timeupdate', () => {
      if (timeLabel && !isNaN(audioEl.duration)) {
        const remaining = Math.max(0, Math.floor(audioEl.duration - audioEl.currentTime));
        const mins = Math.floor(remaining / 60);
        const secs = remaining % 60;
        timeLabel.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
      }
    });

    // Reaction clicks
    this.shadowRoot.querySelectorAll('.wa-reaction-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const emoji = btn.dataset.emoji;
        if (emoji === 'plus') {
          this.dispatchEvent(new CustomEvent('open-emoji-picker', { detail: { msgId: msg.id }, bubbles: true, composed: true }));
        } else {
          this.toggleReaction(emoji);
        }
      });
    });

    // Chevron Dropdown Menu
    const chevron = this.shadowRoot.querySelector('#btn-bubble-menu');
    const dropdown = this.shadowRoot.querySelector('#bubble-dropdown');
    chevron?.addEventListener('click', (e) => {
      e.stopPropagation();
      dropdown?.classList.toggle('open');
    });

    document.addEventListener('click', (e) => {
      if (dropdown && !dropdown.contains(e.target)) {
        dropdown.classList.remove('open');
      }
    });

    // Dropdown Actions
    this.shadowRoot.querySelectorAll('.wa-bubble-menu-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const action = btn.dataset.action;
        dropdown?.classList.remove('open');

        if (action === 'reply') {
          this.dispatchEvent(new CustomEvent('reply-to-message', { detail: { message: msg }, bubbles: true, composed: true }));
        } else if (action === 'copy') {
          const text = msg.body || msg.caption || '';
          navigator.clipboard.writeText(text).then(() => {
            alert('Message copied to clipboard');
          });
        } else if (action === 'star') {
          msg.is_starred = !msg.is_starred;
          this.store?.updateMessage(msg.id, { is_starred: msg.is_starred });
          this.render();
        } else if (action === 'delete') {
          const confirmFn = window.hccConfirm || ((opts) => Promise.resolve(confirm(typeof opts === 'string' ? opts : opts.message)));
          confirmFn({
            title: 'Delete Message',
            message: 'Are you sure you want to delete this message?',
            confirmText: 'Delete',
            type: 'danger'
          }).then((confirmed) => {
            if (confirmed) {
              this.remove();
            }
          });
        } else if (action === 'forward') {
          alert('Select a student contact to forward this message.');
        }
      });
    });
  }

  toggleReaction(emoji) {
    const msg = this.msg;
    msg.reactions = msg.reactions || [];
    const idx = msg.reactions.findIndex(r => r.emoji === emoji);
    if (idx >= 0) {
      msg.reactions.splice(idx, 1);
    } else {
      msg.reactions.push({ emoji, count: 1 });
    }
    this.setAttribute('message', JSON.stringify(msg));
    this.store?.updateMessage(msg.id, { reactions: msg.reactions });
  }

  escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }

  formatSize(bytes) {
    if (!bytes) return '';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
  }
}
customElements.define('wa-message-bubble', WaMessageBubble);

// ============ CONVERSATION MESSAGES CONTAINER ============

class WaConversationMessages extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.store = window.WhatsAppStore;
  }

  connectedCallback() {
    this.boundLoadMessages = (e) => this.renderMessages(e.detail);
    this.boundNewMsg = (e) => this.addMessage(e.detail);
    this.boundUpdateMsg = (e) => this.updateMsg(e.detail);
    this.boundChatSelected = () => this.render();
    this.boundChatCleared = () => this.clearChatView();

    if (this.store) {
      this.store.addEventListener('messages:loaded', this.boundLoadMessages);
      this.store.addEventListener('message:new', this.boundNewMsg);
      this.store.addEventListener('message:updated', this.boundUpdateMsg);
      this.store.addEventListener('chat:selected', this.boundChatSelected);
      this.store.addEventListener('chat:cleared', this.boundChatCleared);
    }
    this.render();
  }

  disconnectedCallback() {
    if (this.store) {
      this.store.removeEventListener('messages:loaded', this.boundLoadMessages);
      this.store.removeEventListener('message:new', this.boundNewMsg);
      this.store.removeEventListener('message:updated', this.boundUpdateMsg);
      this.store.removeEventListener('chat:selected', this.boundChatSelected);
      this.store.removeEventListener('chat:cleared', this.boundChatCleared);
    }
  }

  render() {
    if (!this.store?.activeChatId) {
      this.shadowRoot.innerHTML = `
        <style>
          :host {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            background-color: var(--wa-bg-chat, #efeae2);
            color: var(--wa-text-tertiary-var, #8696a0);
          }
        </style>
        <div>Select a chat to view messages</div>
      `;
      return;
    }

    this.shadowRoot.innerHTML = `
      <style>
        :host {
          display: flex;
          flex-direction: column;
          flex: 1;
          overflow-y: auto;
          overflow-x: hidden;
          padding: 16px 36px;
          background-color: var(--wa-bg-chat, #efeae2);
          background-image: var(--wa-bg-chat-pattern, url("data:image/svg+xml,%3Csvg width='600' height='600' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M300 300c0-82.8-67.2-150-150-150S0 217.2 0 300s67.2 150 150 150 150-67.2 150-150zm150 0c0 82.8-67.2 150-150 150S300 382.8 300 300s67.2-150 150-150 150 67.2 150 150zm-300 0c0-82.8 67.2-150 150-150s150 67.2 150 150-67.2 150-150 150-150-67.2-150-150z' fill='%23e0ddd5' fill-opacity='0.4' fill-rule='evenodd'/%3E%3C/svg%3E"));
          box-sizing: border-box;
          position: relative;
        }
        :host::-webkit-scrollbar {
          width: 6px;
        }
        :host::-webkit-scrollbar-thumb {
          background: rgba(11,20,26,0.18);
          border-radius: 3px;
        }
        .wa-messages-container {
          display: flex;
          flex-direction: column;
          gap: 4px;
          min-height: 100%;
        }
        .wa-date-divider {
          align-self: center;
          background: rgba(255, 255, 255, 0.94);
          color: #54656f;
          font-size: 11.5px;
          font-weight: 700;
          padding: 5px 14px;
          border-radius: 8px;
          box-shadow: 0 1px 2px rgba(11,20,26,0.12);
          margin: 12px 0 8px;
          user-select: none;
          text-transform: uppercase;
          letter-spacing: 0.5px;
        }
      </style>
      <div class="wa-messages-container" id="msg-container">
        <div style="padding:24px;text-align:center;color:#8696a0;">
          <wa-spinner size="28"></wa-spinner>
          <div style="margin-top:8px;font-size:13px">Loading messages...</div>
        </div>
      </div>
    `;

    // Load messages from store
    const chatId = this.store.activeChatId;
    const msgsMap = this.store.messages?.get(chatId);
    if (msgsMap && msgsMap.size > 0) {
      this.renderMessages({ chatId, messages: Array.from(msgsMap.values()) });
    } else {
      this.store.loadMessages(chatId);
    }
  }

  renderMessages({ chatId, messages }) {
    if (String(chatId) !== String(this.store?.activeChatId)) return;
    const container = this.shadowRoot.querySelector('#msg-container');
    if (!container) return;

    if (!messages || messages.length === 0) {
      container.innerHTML = `
        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;padding:48px 24px;color:#8696a0;text-align:center;">
          <div style="background:#ffffff;padding:12px 18px;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,0.08);max-width:320px;">
            <p style="font-size:13px;color:#54656f;margin-bottom:6px">🔒 Messages are end-to-end encrypted for student privacy.</p>
            <p style="font-size:12px;color:#8696a0;">Send academic reports, marksheets, and notices directly to parents.</p>
          </div>
        </div>
      `;
      return;
    }

    // Sort by timestamp asc
    const sorted = [...messages].sort((a, b) => (a.timestamp || 0) - (b.timestamp || 0));
    const grouped = this.groupMessagesByDate(sorted);

    let html = '';
    grouped.forEach(item => {
      if (item.type === 'date') {
        html += `<div class="wa-date-divider">${item.label}</div>`;
      } else {
        html += `<wa-message-bubble data-msg-id="${item.message.id}" message="${this.escapeHtml(JSON.stringify(item.message))}"></wa-message-bubble>`;
      }
    });

    container.innerHTML = html;
    this.scrollToBottom();
  }

  addMessage(msg) {
    if (String(msg.chat_id) !== String(this.store?.activeChatId)) return;
    const container = this.shadowRoot.querySelector('#msg-container');
    if (!container) return;

    const bubble = document.createElement('wa-message-bubble');
    bubble.setAttribute('data-msg-id', msg.id);
    bubble.setAttribute('message', JSON.stringify(msg));
    container.appendChild(bubble);
    this.scrollToBottom();
  }

  updateMsg(msg) {
    const bubble = this.shadowRoot.querySelector(`wa-message-bubble[data-msg-id="${msg.id}"]`);
    if (bubble) {
      bubble.setAttribute('message', JSON.stringify(msg));
    }
  }

  clearChatView() {
    const container = this.shadowRoot.querySelector('#msg-container');
    if (container) {
      container.innerHTML = `<div style="text-align:center;padding:24px;color:#8696a0;font-size:13px;">Chat cleared</div>`;
    }
  }

  groupMessagesByDate(messages) {
    const res = [];
    let lastDate = null;
    messages.forEach(m => {
      const d = new Date((m.timestamp || 0) * 1000);
      const dateStr = d.toDateString();
      if (dateStr !== lastDate) {
        res.push({ type: 'date', label: this.formatDateLabel(d) });
        lastDate = dateStr;
      }
      res.push({ type: 'message', message: m });
    });
    return res;
  }

  formatDateLabel(date) {
    const now = new Date();
    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);

    if (date.toDateString() === now.toDateString()) return 'TODAY';
    if (date.toDateString() === yesterday.toDateString()) return 'YESTERDAY';
    return date.toLocaleDateString([], { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' }).toUpperCase();
  }

  scrollToBottom() {
    setTimeout(() => {
      this.scrollTop = this.scrollHeight;
    }, 20);
  }

  escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }
}
customElements.define('wa-conversation-messages', WaConversationMessages);

// ============ COMPOSER ============

class WaComposer extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.store = window.WhatsAppStore;
    this.attachments = [];
    this.quotedMessage = null;
    this.isRecording = false;
    this.recordTimer = null;
    this.recordSeconds = 0;
    this.mediaStream = null;
    this.mediaRecorder = null;
    this.audioChunks = [];
  }

  connectedCallback() {
    this.render();
    this.bindEvents();
    this.boundReplyListener = (e) => this.setQuotedMessage(e.detail.message);
    window.addEventListener('reply-to-message', this.boundReplyListener);
  }

  disconnectedCallback() {
    window.removeEventListener('reply-to-message', this.boundReplyListener);
    this.stopRecordingCleanup();
  }

  setQuotedMessage(msg) {
    this.quotedMessage = msg;
    const banner = this.shadowRoot.querySelector('#reply-banner');
    if (banner) {
      if (msg) {
        banner.style.display = 'flex';
        banner.querySelector('.wa-reply-name').textContent = msg.sender_name || (msg.from_me ? 'You' : 'Student');
        banner.querySelector('.wa-reply-snippet').textContent = msg.body || msg.caption || 'Attachment';
      } else {
        banner.style.display = 'none';
      }
    }
  }

  render() {
    this.shadowRoot.innerHTML = `
      <style>
        :host {
          display: flex;
          flex-direction: column;
          background: var(--wa-bg-chat-list-var, #f0f2f5);
          border-top: 1px solid var(--wa-border-light, #e9edef);
          box-sizing: border-box;
          position: relative;
          z-index: 40;
        }
        
        /* REPLY BANNER */
        .wa-reply-banner {
          display: none;
          align-items: center;
          justify-content: space-between;
          padding: 8px 16px;
          background: #ffffff;
          border-left: 4px solid var(--wa-green, #00a884);
          border-bottom: 1px solid #e2e8f0;
          font-size: 13px;
        }
        .wa-reply-content {
          flex: 1;
          min-width: 0;
        }
        .wa-reply-name {
          font-weight: 600;
          color: var(--wa-green, #00a884);
        }
        .wa-reply-snippet {
          color: #667781;
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
        }
        .wa-reply-close {
          background: none;
          border: none;
          color: #667781;
          cursor: pointer;
          font-size: 16px;
          padding: 4px;
        }
        
        /* ATTACHMENT CHIPS */
        .wa-attachments-preview {
          display: flex;
          gap: 8px;
          padding: 8px 16px;
          background: #ffffff;
          border-bottom: 1px solid #e2e8f0;
          flex-wrap: wrap;
        }
        .wa-attachment-chip {
          display: inline-flex;
          align-items: center;
          gap: 6px;
          background: #e2e8f0;
          border-radius: 14px;
          padding: 4px 10px;
          font-size: 12.5px;
          font-weight: 500;
        }
        .wa-chip-remove {
          background: none;
          border: none;
          color: #e74c3c;
          cursor: pointer;
          font-size: 14px;
        }
        
        /* COMPOSER ROW */
        .wa-compose-row {
          display: flex;
          align-items: flex-end;
          gap: 8px;
          padding: 10px 16px;
          box-sizing: border-box;
          position: relative;
        }
        .wa-input-pill {
          flex: 1;
          display: flex;
          align-items: center;
          background: #ffffff;
          border-radius: 8px;
          padding: 6px 12px;
          box-sizing: border-box;
          min-height: 42px;
          box-shadow: 0 1px 2px rgba(11,20,26,0.06);
        }
        .wa-compose-textarea {
          flex: 1;
          border: none;
          outline: none;
          resize: none;
          font-size: 14.5px;
          line-height: 20px;
          color: #111b21;
          font-family: inherit;
          max-height: 120px;
          min-height: 20px;
          padding: 2px 0;
          box-sizing: border-box;
          background: transparent;
        }
        .wa-compose-textarea::placeholder {
          color: #8696a0;
        }
        
        /* ACTIVE VOICE RECORDING BAR */
        .wa-record-bar {
          flex: 1;
          display: none;
          align-items: center;
          gap: 12px;
          background: #ffffff;
          border-radius: 8px;
          padding: 6px 14px;
          box-sizing: border-box;
          min-height: 42px;
          box-shadow: 0 1px 2px rgba(11,20,26,0.06);
          animation: waRecFade 0.15s ease-out;
        }
        @keyframes waRecFade {
          from { opacity: 0; transform: scale(0.98); }
          to { opacity: 1; transform: scale(1); }
        }
        .wa-record-cancel-btn {
          background: none;
          border: none;
          color: #ea4335;
          cursor: pointer;
          padding: 6px;
          display: flex;
          align-items: center;
          justify-content: center;
          border-radius: 50%;
          transition: background 0.15s;
        }
        .wa-record-cancel-btn:hover {
          background: #fee2e2;
        }
        .wa-record-cancel-btn svg {
          width: 20px;
          height: 20px;
          fill: currentColor;
        }
        .wa-rec-timer-wrap {
          display: flex;
          align-items: center;
          gap: 8px;
        }
        .wa-rec-dot {
          width: 10px;
          height: 10px;
          border-radius: 50%;
          background: #ea4335;
          animation: waRecBlink 1s infinite alternate ease-in-out;
        }
        @keyframes waRecBlink {
          from { opacity: 1; transform: scale(1); }
          to { opacity: 0.25; transform: scale(0.75); }
        }
        .wa-rec-timer {
          font-size: 14px;
          font-weight: 600;
          color: #111b21;
          font-variant-numeric: tabular-nums;
        }
        .wa-rec-waveform {
          flex: 1;
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 3px;
          height: 22px;
          overflow: hidden;
        }
        .wa-wave-bar {
          width: 3px;
          height: 8px;
          background: #00a884;
          border-radius: 2px;
          animation: waWaveOscillate 1.2s infinite ease-in-out;
        }
        .wa-wave-bar:nth-child(2) { animation-delay: 0.1s; height: 14px; }
        .wa-wave-bar:nth-child(3) { animation-delay: 0.2s; height: 20px; }
        .wa-wave-bar:nth-child(4) { animation-delay: 0.3s; height: 10px; }
        .wa-wave-bar:nth-child(5) { animation-delay: 0.4s; height: 18px; }
        .wa-wave-bar:nth-child(6) { animation-delay: 0.5s; height: 22px; }
        .wa-wave-bar:nth-child(7) { animation-delay: 0.6s; height: 16px; }
        .wa-wave-bar:nth-child(8) { animation-delay: 0.7s; height: 12px; }
        .wa-wave-bar:nth-child(9) { animation-delay: 0.8s; height: 18px; }
        .wa-wave-bar:nth-child(10) { animation-delay: 0.9s; height: 20px; }
        .wa-wave-bar:nth-child(11) { animation-delay: 1.0s; height: 14px; }
        .wa-wave-bar:nth-child(12) { animation-delay: 1.1s; height: 8px; }
        @keyframes waWaveOscillate {
          0%, 100% { transform: scaleY(0.35); }
          50% { transform: scaleY(1.3); }
        }
        .wa-record-send-btn {
          width: 34px;
          height: 34px;
          border-radius: 50%;
          border: none;
          background: #00a884;
          color: #ffffff;
          display: flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
          transition: background 0.15s, transform 0.15s;
        }
        .wa-record-send-btn:hover {
          background: #008069;
          transform: scale(1.05);
        }
        .wa-record-send-btn svg {
          width: 18px;
          height: 18px;
          fill: currentColor;
        }

        /* DYNAMIC MIC / SEND BUTTON */
        .wa-action-circle-btn {
          width: 42px;
          height: 42px;
          border-radius: 50%;
          border: none;
          background: transparent;
          color: #54656f;
          display: inline-flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
          transition: background 0.15s, transform 0.15s, color 0.15s;
          flex-shrink: 0;
        }
        .wa-action-circle-btn:hover {
          background: rgba(11,20,26,0.08);
          color: #111b21;
        }
        .wa-action-circle-btn.send-mode {
          background: var(--wa-green, #00a884);
          color: #ffffff;
          box-shadow: 0 2px 4px rgba(0, 168, 132, 0.3);
        }
        .wa-action-circle-btn.send-mode:hover {
          background: #008069;
          transform: scale(1.05);
        }
        .wa-action-circle-btn svg {
          width: 22px;
          height: 22px;
          fill: currentColor;
        }
        
        /* KEYBOARD HINT FOOTER */
        .wa-composer-hints {
          display: flex;
          justify-content: flex-end;
          gap: 12px;
          padding: 0 16px 6px;
          font-size: 11px;
          color: #8696a0;
          user-select: none;
        }
        .wa-hint-badge {
          background: #e2e8f0;
          padding: 1px 5px;
          border-radius: 4px;
          font-family: monospace;
          color: #475569;
        }
      </style>
      
      <!-- INLINE POPUPS (Directly anchored to emoji & paperclip buttons) -->
      <wa-emoji-picker id="emoji-picker"></wa-emoji-picker>
      <wa-attach-menu id="attach-menu"></wa-attach-menu>
      
      <!-- HIDDEN FILE INPUTS -->
      <input type="file" id="file-doc-input" accept=".pdf,.doc,.docx" style="display:none;">
      <input type="file" id="file-media-input" accept="image/*,video/*" style="display:none;">

      <!-- REPLY QUOTE BANNER -->
      <div class="wa-reply-banner" id="reply-banner">
        <div class="wa-reply-content">
          <div class="wa-reply-name"></div>
          <div class="wa-reply-snippet"></div>
        </div>
        <button class="wa-reply-close" id="btn-cancel-reply" title="Cancel reply">✕</button>
      </div>
      
      <!-- ATTACHMENTS PREVIEW -->
      <div class="wa-attachments-preview" id="attachments-preview" style="display:none"></div>
      
      <div class="wa-compose-row">
        <!-- Emoji button -->
        <wa-icon-button id="btn-emoji" icon="emoji" tooltip="Emoji"></wa-icon-button>
        <!-- Paperclip Attach button -->
        <wa-icon-button id="btn-attach" icon="attach" tooltip="Attach"></wa-icon-button>
        
        <!-- Expanding Textarea Pill -->
        <div class="wa-input-pill">
          <textarea class="wa-compose-textarea" id="composer-textarea" rows="1" placeholder="Type a message"></textarea>
        </div>
        
        <!-- Active Voice Recording Bar (hidden until mic clicked) -->
        <div class="wa-record-bar" id="record-bar">
          <button type="button" class="wa-record-cancel-btn" id="btn-record-cancel" title="Discard voice note">
            <svg viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
          </button>
          <div class="wa-rec-timer-wrap">
            <span class="wa-rec-dot"></span>
            <span class="wa-rec-timer" id="record-timer">0:00</span>
          </div>
          <div class="wa-rec-waveform">
            <div class="wa-wave-bar"></div><div class="wa-wave-bar"></div><div class="wa-wave-bar"></div>
            <div class="wa-wave-bar"></div><div class="wa-wave-bar"></div><div class="wa-wave-bar"></div>
            <div class="wa-wave-bar"></div><div class="wa-wave-bar"></div><div class="wa-wave-bar"></div>
            <div class="wa-wave-bar"></div><div class="wa-wave-bar"></div><div class="wa-wave-bar"></div>
          </div>
          <button type="button" class="wa-record-send-btn" id="btn-record-send" title="Send voice note">
            <svg viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
          </button>
        </div>

        <!-- Dynamic Mic / Send Button -->
        <button class="wa-action-circle-btn" id="btn-action-send" title="Voice message">
          <svg id="icon-mic" viewBox="0 0 24 24"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.5V21h-2v-4.5c-2.76-.4-5-2.5-5-5.5H3c0 4.41 3.59 8 8 8s8-3.59 8-8h-1.7z"/></svg>
          <svg id="icon-send" style="display:none;" viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
        </button>
      </div>
      
      <div class="wa-composer-hints">
        <span><span class="wa-hint-badge">Enter</span> to send</span>
        <span><span class="wa-hint-badge">Shift+Enter</span> for newline</span>
        <span><span class="wa-hint-badge">Alt+Enter</span> Send &amp; Next Student</span>
      </div>
    `;
  }

  bindEvents() {
    this.textarea = this.shadowRoot.querySelector('#composer-textarea');
    this.actionBtn = this.shadowRoot.querySelector('#btn-action-send');
    this.micIcon = this.shadowRoot.querySelector('#icon-mic');
    this.sendIcon = this.shadowRoot.querySelector('#icon-send');
    this.emojiBtn = this.shadowRoot.querySelector('#btn-emoji');
    this.emojiPicker = this.shadowRoot.querySelector('#emoji-picker');
    this.attachBtn = this.shadowRoot.querySelector('#btn-attach');
    this.attachMenu = this.shadowRoot.querySelector('#attach-menu');

    // Auto-grow textarea & input tracking
    this.textarea?.addEventListener('input', () => {
      this.updateTextareaHeight();
      this.updateSendButtonState();
    });

    // Keyboard shortcuts
    this.textarea?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        if (e.altKey) {
          e.preventDefault();
          this.handleSend(true);
        } else if (!e.shiftKey) {
          e.preventDefault();
          this.handleSend(false);
        }
      }
    });

    // Action button click (Send vs Voice Note)
    this.actionBtn?.addEventListener('click', () => {
      const hasContent = this.textarea?.value.trim().length > 0 || this.attachments.length > 0;
      if (hasContent) {
        this.handleSend(false);
      } else {
        this.startVoiceRecording();
      }
    });

    // Voice record cancel & send
    this.shadowRoot.querySelector('#btn-record-cancel')?.addEventListener('click', () => {
      this.cancelVoiceRecording();
    });
    this.shadowRoot.querySelector('#btn-record-send')?.addEventListener('click', () => {
      this.sendVoiceRecording();
    });

    // Emoji button click
    this.emojiBtn?.addEventListener('click', (e) => {
      e.stopPropagation();
      this.attachMenu?.close();
      if (this.emojiPicker?.hasAttribute('open')) {
        this.emojiPicker.close();
      } else {
        this.emojiPicker?.open();
      }
    });

    this.emojiPicker?.addEventListener('emoji-select', (e) => {
      this.insertText(e.detail.emoji);
    });

    // Attach button click
    this.attachBtn?.addEventListener('click', (e) => {
      e.stopPropagation();
      this.emojiPicker?.close();
      if (this.attachMenu?.hasAttribute('open')) {
        this.attachMenu.close();
      } else {
        this.attachMenu?.open();
      }
    });

    this.attachMenu?.addEventListener('attach-select', (e) => {
      this.handleAttachSelect(e.detail.type);
    });

    // Click outside to close emoji picker & attach menu
    document.addEventListener('click', (e) => {
      const path = e.composedPath();
      if (this.emojiPicker?.hasAttribute('open') && !path.includes(this.emojiBtn) && !path.includes(this.emojiPicker)) {
        this.emojiPicker.close();
      }
      if (this.attachMenu?.hasAttribute('open') && !path.includes(this.attachBtn) && !path.includes(this.attachMenu)) {
        this.attachMenu.close();
      }
    });

    // Hidden file input handlers
    const docInput = this.shadowRoot.querySelector('#file-doc-input');
    docInput?.addEventListener('change', (e) => {
      const file = e.target.files?.[0];
      if (file) {
        this.attachFile({
          name: file.name,
          type: 'document',
          size: file.size,
          path: URL.createObjectURL(file)
        });
      }
      e.target.value = '';
    });

    const mediaInput = this.shadowRoot.querySelector('#file-media-input');
    mediaInput?.addEventListener('change', (e) => {
      const file = e.target.files?.[0];
      if (file) {
        this.attachFile({
          name: file.name,
          type: file.type.startsWith('video') ? 'video' : 'image',
          size: file.size,
          path: URL.createObjectURL(file)
        });
      }
      e.target.value = '';
    });

    // Cancel reply button
    this.shadowRoot.querySelector('#btn-cancel-reply')?.addEventListener('click', () => {
      this.setQuotedMessage(null);
    });
  }

  handleAttachSelect(type) {
    if (type === 'document') {
      this.shadowRoot.querySelector('#file-doc-input')?.click();
    } else if (type === 'gallery' || type === 'camera') {
      this.shadowRoot.querySelector('#file-media-input')?.click();
    } else if (type === 'templates') {
      const activeChat = this.store?.activeChat;
      const studentName = activeChat?.name || 'Student';
      const template = `Dear Parent,\n\nPlease find attached the official *Semester Report Card* and academic slip for *${studentName}* from Hindustani Covenant Church High School.\n\nWarm regards,\n*Principal, HCC High School*`;
      this.insertText(template);
    } else if (type === 'contact') {
      this.dispatchEvent(new CustomEvent('open-contact-picker', { bubbles: true, composed: true }));
      window.dispatchEvent(new CustomEvent('open-contact-picker'));
      const cp = document.querySelector('wa-contact-picker') || this.getRootNode()?.querySelector('wa-contact-picker');
      if (cp && typeof cp.open === 'function') cp.open();
    }
  }

  startVoiceRecording() {
    this.isRecording = true;
    this.recordSeconds = 0;
    this.audioChunks = [];

    const inputPill = this.shadowRoot.querySelector('.wa-input-pill');
    const recordBar = this.shadowRoot.querySelector('#record-bar');
    const hints = this.shadowRoot.querySelector('.wa-composer-hints');
    if (inputPill) inputPill.style.display = 'none';
    if (recordBar) recordBar.style.display = 'flex';
    if (hints) hints.style.display = 'none';
    if (this.actionBtn) this.actionBtn.style.display = 'none';

    const timerEl = this.shadowRoot.querySelector('#record-timer');
    if (timerEl) timerEl.textContent = '0:00';

    this.recordTimer = setInterval(() => {
      this.recordSeconds++;
      const mins = Math.floor(this.recordSeconds / 60);
      const secs = this.recordSeconds % 60;
      if (timerEl) {
        timerEl.textContent = `${mins}:${secs.toString().padStart(2, '0')}`;
      }
    }, 1000);

    if (navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function') {
      navigator.mediaDevices.getUserMedia({ audio: true }).then(stream => {
        this.mediaStream = stream;
        try {
          this.mediaRecorder = new MediaRecorder(stream);
          this.mediaRecorder.ondataavailable = (e) => {
            if (e.data.size > 0) this.audioChunks.push(e.data);
          };
          this.mediaRecorder.start();
        } catch (e) {
          console.warn('MediaRecorder error, falling back to simulated audio:', e);
        }
      }).catch(err => {
        console.warn('Microphone permission not granted, using simulated audio recording:', err);
      });
    }
  }

  cancelVoiceRecording() {
    this.stopRecordingCleanup();
  }

  async sendVoiceRecording() {
    const elapsed = this.recordSeconds || 1;
    const mins = Math.floor(elapsed / 60);
    const secs = elapsed % 60;
    const durationStr = `${mins}:${secs.toString().padStart(2, '0')}`;
    
    let audioBlobUrl = null;
    if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
      await new Promise(resolve => {
        this.mediaRecorder.onstop = () => {
          if (this.audioChunks.length > 0) {
            try {
              const blob = new Blob(this.audioChunks, { type: 'audio/webm' });
              audioBlobUrl = URL.createObjectURL(blob);
            } catch (e) {}
          }
          resolve();
        };
        try {
          this.mediaRecorder.stop();
        } catch (e) {
          resolve();
        }
      });
    } else if (this.audioChunks.length > 0) {
      try {
        const blob = new Blob(this.audioChunks, { type: 'audio/webm' });
        audioBlobUrl = URL.createObjectURL(blob);
      } catch (e) {}
    }

    this.stopRecordingCleanup();

    const chatId = this.store?.activeChatId;
    if (!chatId) return;

    try {
      await this.store.sendMessage(chatId, {
        msg_type: 'audio',
        body: `🎤 Voice note (${durationStr})`,
        media_path: audioBlobUrl,
        duration: elapsed
      });
    } catch (err) {
      console.error('Failed to send voice recording:', err);
    }
  }

  stopRecordingCleanup() {
    this.isRecording = false;
    if (this.recordTimer) {
      clearInterval(this.recordTimer);
      this.recordTimer = null;
    }
    if (this.mediaStream) {
      this.mediaStream.getTracks().forEach(t => t.stop());
      this.mediaStream = null;
    }

    const inputPill = this.shadowRoot.querySelector('.wa-input-pill');
    const recordBar = this.shadowRoot.querySelector('#record-bar');
    const hints = this.shadowRoot.querySelector('.wa-composer-hints');
    if (inputPill) inputPill.style.display = 'flex';
    if (recordBar) recordBar.style.display = 'none';
    if (hints) hints.style.display = 'flex';
    if (this.actionBtn) this.actionBtn.style.display = 'inline-flex';
  }

  updateTextareaHeight() {
    if (!this.textarea) return;
    this.textarea.style.height = 'auto';
    const newHeight = Math.min(this.textarea.scrollHeight, 120);
    this.textarea.style.height = `${newHeight}px`;
  }

  updateSendButtonState() {
    const hasText = this.textarea?.value.trim().length > 0;
    const hasAttachments = this.attachments.length > 0;
    const isSend = hasText || hasAttachments;

    if (this.actionBtn) {
      this.actionBtn.classList.toggle('send-mode', isSend);
      this.actionBtn.title = isSend ? 'Send message' : 'Voice message';
    }
    if (this.micIcon) this.micIcon.style.display = isSend ? 'none' : 'block';
    if (this.sendIcon) this.sendIcon.style.display = isSend ? 'block' : 'none';
  }

  async handleSend(nextStudent = false) {
    const text = this.textarea?.value.trim() || '';
    const chatId = this.store?.activeChatId;

    if (!chatId || (!text && this.attachments.length === 0)) return;

    try {
      const att = this.attachments[0] || null;
      await this.store.sendMessage(chatId, {
        msg_type: att ? att.type : 'text',
        body: text,
        media_path: att ? att.path : null,
        file_name: att ? att.name : null,
        file_size: att ? att.size : null,
        reply_to: this.quotedMessage ? {
          id: this.quotedMessage.id,
          sender_name: this.quotedMessage.sender_name || 'Student',
          body: this.quotedMessage.body || this.quotedMessage.caption
        } : null
      });

      // Clear input
      if (this.textarea) {
        this.textarea.value = '';
        this.textarea.style.height = 'auto';
      }
      this.attachments = [];
      this.updateAttachmentsView();
      this.setQuotedMessage(null);
      this.updateSendButtonState();

      if (nextStudent) {
        this.dispatchEvent(new CustomEvent('send-and-next-student', { bubbles: true, composed: true }));
      }
    } catch (err) {
      console.error('Failed to send message:', err);
    }
  }

  updateAttachmentsView() {
    const container = this.shadowRoot.querySelector('#attachments-preview');
    if (!container) return;

    if (this.attachments.length === 0) {
      container.style.display = 'none';
      container.innerHTML = '';
      return;
    }

    container.style.display = 'flex';
    container.innerHTML = this.attachments.map((a, i) => `
      <div class="wa-attachment-chip">
        <span>📎 ${a.name || a.type}</span>
        <button class="wa-chip-remove" data-idx="${i}">&times;</button>
      </div>
    `).join('');

    container.querySelectorAll('.wa-chip-remove').forEach(btn => {
      btn.addEventListener('click', () => {
        const idx = parseInt(btn.dataset.idx, 10);
        this.attachments.splice(idx, 1);
        this.updateAttachmentsView();
        this.updateSendButtonState();
      });
    });
  }

  insertText(txt) {
    if (!this.textarea) return;
    const start = this.textarea.selectionStart || 0;
    const end = this.textarea.selectionEnd || 0;
    const val = this.textarea.value;
    this.textarea.value = val.substring(0, start) + txt + val.substring(end);
    this.textarea.selectionStart = this.textarea.selectionEnd = start + txt.length;
    this.textarea.focus();
    this.updateTextareaHeight();
    this.updateSendButtonState();
  }

  attachFile(fileObj) {
    this.attachments.push(fileObj);
    this.updateAttachmentsView();
    this.updateSendButtonState();
  }
}
customElements.define('wa-composer', WaComposer);

// ============ EMOJI PICKER ============

class WaEmojiPicker extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.activeCategory = 'smileys';
    this.searchQuery = '';

    this.categories = {
      smileys: {
        name: 'Smileys & Emotion',
        icon: '😊',
        emojis: ['😊', '😂', '🤣', '😍', '🥰', '😘', '😗', '😙', '😚', '🙂', '🙃', '😉', '😋', '😛', '😜', '🤪', '😎', '🤩', '🥳', '😏', '😒', '😞', '😔', '😟', '😕', '🙁', '😮', '😯', '😲', '😳', '🥺', '😦', '😧', '😨', '😰', '😥', '😢', '😭', '😱', '😖', '😣', '😞', '😓', '😩', '😫', '🥱', '😤', '😡', '😠', '🤬', '😷', '🤒', '🤕', '🤢', '🤮', '🤧', '😇', '🤠', '🤡', '👻', '💀', '👽', '🤖', '💩']
      },
      people: {
        name: 'People & Body',
        icon: '👋',
        emojis: ['👋', '🤚', '🖐', '✋', '🖖', '👌', '🤌', '🤏', '✌️', '🤞', '🤟', '🤘', '🤙', '👈', '👉', '👆', '🖕', '👇', '☝️', '👍', '👎', '✊', '👊', '🤛', '🤜', '👏', '🙌', '👐', '🤲', '🤝', '🙏', '✍️', '💅', '🤳', '💪', '🦾', '🦿', '🦵', '🦶', '👂', '🦻', '👃', '🧠', '🫀', '🫁', '🦷', '🦴', '👀', '👁', '👅', '👄']
      },
      animals: {
        name: 'Animals & Nature',
        icon: '🐶',
        emojis: ['🐶', '🐱', '🐭', '🐹', '🐰', '🦊', '🐻', '🐼', '🐨', '🐯', '🦁', '🐮', '🐷', '🐸', '🐵', '🐔', '🐧', '🐦', '🐤', '🦆', '🦅', '🦉', '🦇', '🐺', '🐗', '🐴', '🦄', '🐝', '🐛', '🦋', '🐌', '🐞', '🐜', '🦟', '🐢', '🐍', '🐙', '🦑', '🦐', '🦀', '🐡', '🐠', '🐟', '🐬', '🐳', '🦈', '🐊', '🐅', '🐆', '🦓', '🦍', '🦧', '🦣', '🐘', '🦛', '🦏', '🐪', '🐫', '🦒', '🦘']
      },
      food: {
        name: 'Food & Drink',
        icon: '🍕',
        emojis: ['🍏', '🍎', '🍐', '🍊', '🍋', '🍌', '🍉', '🍇', '🍓', '🫐', '🍈', '🍒', '🍑', '🥭', '🍍', '🥥', '🥝', '🍅', '🥑', '🥦', '🥬', '🥒', '🌶', '🌽', '🥕', '🧄', '🧅', '🥔', '🍠', '🥐', '🥯', '🍞', '🥖', '🥨', '🧀', '🥚', '🍳', '🧈', '🥞', '🧇', '🥓', '🥩', '🍗', '🍖', '🌭', '🍔', '🍟', '🍕', '🥪', '🥙', '🧆', '🌮', '🌯', '🥗', '🥘', '🫕', '🥫', '🍝', '🍜', '🍲', '🍛', '🍣', '🍱', '🥟', '🍤', '🍙', '🍚', '🍘', '🍥', '🥠', '🥮', '🍢', '🍡', '🍧', '🍨', '🍦', '🥧', '🧁', '🍰', '🎂', '🍮', '🍭', '🍬', '🍫', '🍿', '🍩', '🍪', '🥮', '☕', '🍵', '🧃', '🥤', '🧋', '🍶', '🍺', '🍻', '🥂', '🍷', '🥃', '🍸', '🍹']
      },
      travel: {
        name: 'Travel & Places',
        icon: '🚗',
        emojis: ['🚗', '🚕', '🚙', '🚌', '🚎', '🏎', '🚓', '🚑', '🚒', '🚐', '🛻', '🚚', '🚛', '🚜', '🛵', '🏍', '🛺', '🚲', '🛴', '🚏', '🛣', '🛤', '🛢', '⛽', '🚨', '🚥', '🚦', '🛑', '🚢', '✈️', '🚀', '🛸', '🚁', '🛶', '⛵', '🚤', '🛳', '⛴', '🚂', '🚆', '🚄', '🚅', '🚈', '🚉', '🚊', '🚇', '🏠', '🏡', '🏢', '🏣', '🏤', '🏥', '🏦', '🏨', '🏫', '🏛', '⛪', '🕌', '🛕', '🕍', '🕋']
      },
      activities: {
        name: 'Activities',
        icon: '⚽',
        emojis: ['⚽', '🏀', '🏈', '⚾', '🥎', '🎾', '🏐', '🏉', '🥏', '🎱', '🪀', '🏓', '🏸', '🏒', '🏑', '🥍', '🏏', '🪃', '🥅', '⛳', '🪁', '🏹', '🎣', '🤿', '🥊', '🥋', '🎽', '🛹', '🛼', '🛷', '⛸', '🥌', '🎿', '⛷', '🏂', '🪂', '🏋️', '🤼', '🤸', '🤺', '🎯', '🏆', '🥇', '🥈', '🥉', '🏅', '🎖', '🎫', '🎟', '🎪', '🎭', '🎨', '🎬', '🎤', '🎧', '🎼', '🎹', '🥁', '🎷', '🎺', '🎸', '🪕', '🎻']
      },
      objects: {
        name: 'Objects',
        icon: '💡',
        emojis: ['💡', '🔦', '🏮', '🪔', '📱', '📲', '💻', '⌨️', '🖥', '🖨', '🖱', '📷', '📸', '📹', '🎥', '📞', '☎️', '📟', '📠', '📺', '📻', '🎙', '🎚', '🎛', '⏱', '⏲', '⏰', '🕰', '⌛', '⏳', '📡', '🔋', '🔌', '🧯', '🛢', '💸', '💵', '💴', '💶', '💷', '💰', '💳', '💎', '⚖️', '🧰', '🔧', '🔨', '⚒', '⛏', '🪓', '🔩', '⚙️', '🪛', '🔑', '🗝', '🚪', '🪞', '🪟', '🛏', '🛋', '📜', '📄', '📑', '📊', '📈', '📉', '📁', '📂', '🗂', '📅', '📆', '🗓', '📇', '📋', '📁', '📌', '📍', '📎', '🖇', '📏', '📐', '✂️', '🗃', '🗄', '🗑', '🔒', '🔓', '🔏', '🔐', '🔑', '🏷', '✉️', '📦']
      },
      symbols: {
        name: 'Symbols',
        icon: '❤️',
        emojis: ['❤️', '🧡', '💛', '💚', '💙', '💜', '🖤', '🤍', '🤎', '💔', '❣️', '💕', '💞', '💓', '💗', '💖', '💘', '💝', '💟', '☮️', '✝️', '☪️', '🕉', '☸️', '✡️', '🔯', '🕎', '☯️', '☦️', '🛐', '⛎', '♈', '♉', '♊', '♋', '♌', '♍', '♎', '♏', '♐', '♑', '♒', '♓', '🆔', '⚛️', '🉑', '☢️', '☣️', '📴', '📳', '🈶', '🈚', '🈸', '🈺', '🉐', '✴️', '🈶', '🔴', '🟠', '🟡', '🟢', '🔵', '🟣', '⚫', '⚪', '🟤', '🔺', '🔻', '💠', '🔘', '🔳', '🔲', '🏁', '🚩', '🎌', '🏴', '🏳️']
      }
    };
  }

  connectedCallback() {
    this.render();
  }

  open() {
    this.setAttribute('open', '');
    const search = this.shadowRoot.querySelector('#emoji-search');
    if (search) search.focus();
  }

  close() {
    this.removeAttribute('open');
  }

  render() {
    this.shadowRoot.innerHTML = `
      <style>
        :host {
          position: absolute;
          bottom: 74px;
          left: 16px;
          z-index: 1000;
          display: none;
        }
        :host([open]) {
          display: block;
        }
        .wa-picker-box {
          width: 360px;
          height: 380px;
          background: #ffffff;
          border-radius: 12px;
          box-shadow: 0 8px 30px rgba(11,20,26,0.22);
          border: 1px solid #e2e8f0;
          display: flex;
          flex-direction: column;
          overflow: hidden;
          animation: waPopScale 0.15s ease-out;
        }
        @keyframes waPopScale {
          from { opacity: 0; transform: scale(0.92); }
          to { opacity: 1; transform: scale(1); }
        }
        .wa-picker-search {
          padding: 10px 14px;
          background: #f8fafc;
          border-bottom: 1px solid #e2e8f0;
        }
        .wa-picker-search input {
          width: 100%;
          padding: 8px 12px;
          border: 1px solid #cbd5e1;
          border-radius: 8px;
          font-size: 13.5px;
          outline: none;
          box-sizing: border-box;
          font-family: inherit;
        }
        .wa-picker-search input:focus {
          border-color: var(--wa-green, #00a884);
        }
        .wa-picker-tabs {
          display: flex;
          background: #f0f2f5;
          border-bottom: 1px solid #e2e8f0;
          overflow-x: auto;
          scrollbar-width: none;
        }
        .wa-picker-tabs::-webkit-scrollbar { display: none; }
        .wa-picker-tab-btn {
          flex: 1;
          min-width: 40px;
          height: 40px;
          background: none;
          border: none;
          font-size: 18px;
          cursor: pointer;
          display: flex;
          align-items: center;
          justify-content: center;
          border-bottom: 2px solid transparent;
          transition: background 0.15s;
        }
        .wa-picker-tab-btn:hover { background: #e2e8f0; }
        .wa-picker-tab-btn.active {
          border-bottom-color: var(--wa-green, #00a884);
          background: #ffffff;
        }
        .wa-picker-grid {
          flex: 1;
          overflow-y: auto;
          padding: 10px;
          display: grid;
          grid-template-columns: repeat(8, 1fr);
          gap: 4px;
        }
        .wa-emoji-cell {
          height: 36px;
          display: flex;
          align-items: center;
          justify-content: center;
          font-size: 22px;
          cursor: pointer;
          border-radius: 6px;
          transition: transform 0.1s, background 0.1s;
          user-select: none;
        }
        .wa-emoji-cell:hover {
          background: #f1f5f9;
          transform: scale(1.25);
        }
      </style>
      
      <div class="wa-picker-box">
        <div class="wa-picker-search">
          <input type="text" id="emoji-search" placeholder="Search emojis...">
        </div>
        
        <div class="wa-picker-tabs" id="picker-tabs">
          ${Object.entries(this.categories).map(([k, c]) => `
            <button class="wa-picker-tab-btn ${k === this.activeCategory ? 'active' : ''}" data-cat="${k}" title="${c.name}">
              ${c.icon}
            </button>
          `).join('')}
        </div>
        
        <div class="wa-picker-grid" id="picker-grid"></div>
      </div>
    `;

    this.bindPickerEvents();
    this.renderEmojiGrid();
  }

  bindPickerEvents() {
    const searchInput = this.shadowRoot.querySelector('#emoji-search');
    searchInput?.addEventListener('input', (e) => {
      this.searchQuery = e.target.value.toLowerCase().trim();
      this.renderEmojiGrid();
    });

    this.shadowRoot.querySelectorAll('.wa-picker-tab-btn').forEach(btn => {
      btn.addEventListener('click', () => {
        this.activeCategory = btn.dataset.cat;
        this.shadowRoot.querySelectorAll('.wa-picker-tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        this.renderEmojiGrid();
      });
    });

    // Close on outside click
    document.addEventListener('click', (e) => {
      if (this.hasAttribute('open') && !this.contains(e.target)) {
        this.close();
      }
    });
  }

  renderEmojiGrid() {
    const grid = this.shadowRoot.querySelector('#picker-grid');
    if (!grid) return;

    let emojis = [];
    if (this.searchQuery) {
      // Collect all matches
      Object.values(this.categories).forEach(c => {
        emojis.push(...c.emojis);
      });
    } else {
      emojis = this.categories[this.activeCategory]?.emojis || [];
    }

    grid.innerHTML = emojis.map(e => `
      <div class="wa-emoji-cell" data-emoji="${e}">${e}</div>
    `).join('');

    grid.querySelectorAll('.wa-emoji-cell').forEach(cell => {
      cell.addEventListener('click', () => {
        const emoji = cell.dataset.emoji;
        this.dispatchEvent(new CustomEvent('emoji-select', { detail: { emoji }, bubbles: true, composed: true }));
      });
    });
  }
}
customElements.define('wa-emoji-picker', WaEmojiPicker);

// ============ ATTACH MENU ============

class WaAttachMenu extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
  }

  connectedCallback() {
    this.render();
  }

  open() {
    this.setAttribute('open', '');
  }

  close() {
    this.removeAttribute('open');
  }

  render() {
    this.shadowRoot.innerHTML = `
      <style>
        :host {
          position: absolute;
          bottom: 74px;
          left: 56px;
          z-index: 1000;
          display: none;
        }
        :host([open]) {
          display: block;
        }
        .wa-attach-popup {
          background: #ffffff;
          border-radius: 14px;
          box-shadow: 0 8px 30px rgba(11,20,26,0.22);
          border: 1px solid #e2e8f0;
          padding: 12px;
          display: flex;
          flex-direction: column;
          gap: 10px;
          width: 220px;
          animation: waPopScale 0.15s ease-out;
        }
        .wa-attach-item {
          display: flex;
          align-items: center;
          gap: 12px;
          padding: 8px 10px;
          border-radius: 8px;
          background: none;
          border: none;
          width: 100%;
          cursor: pointer;
          text-align: left;
          transition: background 0.15s;
        }
        .wa-attach-item:hover {
          background: #f1f5f9;
        }
        .wa-attach-icon-circle {
          width: 40px;
          height: 40px;
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          color: #ffffff;
          font-size: 18px;
          flex-shrink: 0;
          box-shadow: 0 2px 6px rgba(0,0,0,0.12);
        }
        .wa-attach-label {
          font-size: 14px;
          font-weight: 500;
          color: #1e293b;
        }
      </style>
      
      <div class="wa-attach-popup">
        <button class="wa-attach-item" data-type="document">
          <div class="wa-attach-icon-circle" style="background:#8f3985;">📄</div>
          <div class="wa-attach-label">Document (PDF)</div>
        </button>
        <button class="wa-attach-item" data-type="gallery">
          <div class="wa-attach-icon-circle" style="background:#007bfc;">🖼️</div>
          <div class="wa-attach-label">Photos &amp; Videos</div>
        </button>
        <button class="wa-attach-item" data-type="camera">
          <div class="wa-attach-icon-circle" style="background:#ff2e74;">📸</div>
          <div class="wa-attach-label">Camera</div>
        </button>
        <button class="wa-attach-item" data-type="contact">
          <div class="wa-attach-icon-circle" style="background:#0099ff;">👤</div>
          <div class="wa-attach-label">Contact</div>
        </button>
        <button class="wa-attach-item" data-type="templates">
          <div class="wa-attach-icon-circle" style="background:#e4a11b;">🏫</div>
          <div class="wa-attach-label">School Templates</div>
        </button>
      </div>
    `;

    this.bindAttachEvents();
  }

  bindAttachEvents() {
    this.shadowRoot.querySelectorAll('.wa-attach-item').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const type = btn.dataset.type;
        this.close();
        this.dispatchEvent(new CustomEvent('attach-select', { detail: { type }, bubbles: true, composed: true }));
      });
    });

    document.addEventListener('click', (e) => {
      if (this.hasAttribute('open') && !this.contains(e.target)) {
        this.close();
      }
    });
  }
}
customElements.define('wa-attach-menu', WaAttachMenu);

// ============ CALL MODAL SIMULATION ============

class WaCallModal extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.timerInterval = null;
    this.seconds = 0;
  }

  connectedCallback() {
    this.render();
  }

  open({ chat, callType = 'audio' }) {
    this.chat = chat || {};
    this.callType = callType;
    this.seconds = 0;
    this.setAttribute('open', '');
    this.render();
    this.startCallSimulation();
  }

  close() {
    if (this.timerInterval) clearInterval(this.timerInterval);
    this.removeAttribute('open');
  }

  startCallSimulation() {
    const statusText = this.shadowRoot.querySelector('#call-status-text');
    if (statusText) statusText.textContent = 'Calling...';

    setTimeout(() => {
      if (statusText) statusText.textContent = 'Ringing...';
    }, 1500);

    setTimeout(() => {
      if (statusText) {
        this.timerInterval = setInterval(() => {
          this.seconds++;
          const m = String(Math.floor(this.seconds / 60)).padStart(2, '0');
          const s = String(this.seconds % 60).padStart(2, '0');
          statusText.textContent = `${m}:${s}`;
        }, 1000);
      }
    }, 3200);
  }

  render() {
    const name = this.chat?.name || 'Student Parent';
    const phone = this.chat?.wa_chat_id || this.chat?.phone_e164 || '';
    const isVideo = this.callType === 'video';

    this.shadowRoot.innerHTML = `
      <style>
        :host {
          position: fixed;
          inset: 0;
          z-index: 9999;
          display: none;
          align-items: center;
          justify-content: center;
          background: rgba(11, 20, 26, 0.82);
          backdrop-filter: blur(8px);
        }
        :host([open]) {
          display: flex;
        }
        .wa-call-box {
          width: 380px;
          height: 520px;
          background: #111b21;
          border-radius: 20px;
          box-shadow: 0 16px 40px rgba(0,0,0,0.5);
          display: flex;
          flex-direction: column;
          align-items: center;
          justify-content: space-between;
          padding: 40px 24px 32px;
          box-sizing: border-box;
          color: #ffffff;
          position: relative;
          overflow: hidden;
        }
        .wa-call-top {
          display: flex;
          flex-direction: column;
          align-items: center;
          text-align: center;
          gap: 6px;
        }
        .wa-call-name {
          font-size: 20px;
          font-weight: 700;
        }
        .wa-call-phone {
          font-size: 13.5px;
          color: #8696a0;
        }
        .wa-call-status {
          font-size: 14px;
          color: #25d366;
          font-weight: 600;
          margin-top: 4px;
        }
        
        /* PULSATING AVATAR */
        .wa-avatar-pulse-wrap {
          position: relative;
          display: flex;
          align-items: center;
          justify-content: center;
          margin: 30px 0;
        }
        .wa-pulse-ring {
          position: absolute;
          width: 140px;
          height: 140px;
          border-radius: 50%;
          background: rgba(0, 168, 132, 0.25);
          animation: waPulseAnim 2s infinite ease-out;
        }
        @keyframes waPulseAnim {
          0% { transform: scale(0.8); opacity: 1; }
          100% { transform: scale(1.4); opacity: 0; }
        }
        
        .wa-call-actions {
          display: flex;
          align-items: center;
          gap: 20px;
        }
        .wa-call-btn {
          width: 52px;
          height: 52px;
          border-radius: 50%;
          border: none;
          display: flex;
          align-items: center;
          justify-content: center;
          cursor: pointer;
          color: #ffffff;
          font-size: 20px;
          transition: transform 0.15s;
        }
        .wa-call-btn:hover {
          transform: scale(1.1);
        }
        .wa-btn-end {
          background: #e74c3c;
          width: 62px;
          height: 62px;
          font-size: 24px;
        }
        .wa-btn-ctrl {
          background: #2a3942;
        }
      </style>
      
      <div class="wa-call-box">
        <div class="wa-call-top">
          <div class="wa-call-name">${name}</div>
          <div class="wa-call-phone">${phone}</div>
          <div class="wa-call-status" id="call-status-text">Calling...</div>
        </div>
        
        <div class="wa-avatar-pulse-wrap">
          <div class="wa-pulse-ring"></div>
          <wa-avatar name="${name}" size="96"></wa-avatar>
        </div>
        
        <div class="wa-call-actions">
          <button class="wa-call-btn wa-btn-ctrl" title="Mute Mic">🎤</button>
          <button class="wa-call-btn wa-btn-ctrl" title="Toggle Video">${isVideo ? '📹' : '🔊'}</button>
          <button class="wa-call-btn wa-btn-end" id="btn-end-call" title="End Call">📞</button>
        </div>
      </div>
    `;

    this.shadowRoot.querySelector('#btn-end-call')?.addEventListener('click', () => {
      this.close();
    });
  }
}
customElements.define('wa-call-modal', WaCallModal);

// ============ CONTACT DRAWER ============

class WaContactDrawer extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
  }

  connectedCallback() {
    this.render();
  }

  open(chat) {
    this.chat = chat || {};
    this.setAttribute('open', '');
    this.render();
  }

  close() {
    this.removeAttribute('open');
  }

  render() {
    const chat = this.chat || {};
    const student = chat.student_data || chat.metadata?.student_data || {};
    const name = chat.name || student.name || 'Student Name';
    const phone = chat.phone_e164 || chat.wa_chat_id || student.parent_phone || '+91 98765 43210';
    const rollNo = student.roll_no || '1';
    const className = student.class_name || 'STD V A';
    const academicYear = student.academic_year || '2025-26';
    const percentage = student.percentage ? `${student.percentage}%` : '93.83%';
    const grade = student.grade || 'A1';
    const attendance = student.attendance ? `${student.attendance}%` : '94.5% (186/196 days)';

    this.shadowRoot.innerHTML = `
      <style>
        :host {
          position: fixed;
          top: 0;
          right: 0;
          bottom: 0;
          width: 420px;
          background: #ffffff;
          box-shadow: -4px 0 24px rgba(11,20,26,0.18);
          z-index: 10000;
          display: flex;
          flex-direction: column;
          transform: translateX(100%);
          transition: transform 0.25s cubic-bezier(0.1, 0.9, 0.2, 1);
          box-sizing: border-box;
        }
        :host([open]) {
          transform: translateX(0);
        }
        .wa-drawer-header {
          display: flex;
          align-items: center;
          gap: 16px;
          height: 60px;
          padding: 0 16px;
          background: #f0f2f5;
          border-bottom: 1px solid #e9edef;
          flex-shrink: 0;
        }
        .wa-drawer-close {
          background: none;
          border: none;
          font-size: 20px;
          cursor: pointer;
          color: #54656f;
        }
        .wa-drawer-title {
          font-size: 16px;
          font-weight: 600;
          color: #111b21;
        }
        .wa-drawer-body {
          flex: 1;
          overflow-y: auto;
          background: #f0f2f5;
          display: flex;
          flex-direction: column;
          gap: 10px;
        }
        .wa-drawer-section {
          background: #ffffff;
          padding: 16px;
          box-shadow: 0 1px 3px rgba(11,20,26,0.08);
        }
        .wa-profile-hero {
          display: flex;
          flex-direction: column;
          align-items: center;
          text-align: center;
          gap: 10px;
          padding: 24px 16px;
        }
        .wa-hero-name {
          font-size: 19px;
          font-weight: 700;
          color: #111b21;
        }
        .wa-hero-phone {
          font-size: 14px;
          color: #667781;
        }
        
        .wa-sec-heading {
          font-size: 13.5px;
          font-weight: 700;
          color: var(--wa-green, #00a884);
          text-transform: uppercase;
          letter-spacing: 0.5px;
          margin-bottom: 12px;
        }
        .wa-info-row {
          display: flex;
          justify-content: space-between;
          padding: 8px 0;
          border-bottom: 1px solid #f1f5f9;
          font-size: 13.5px;
        }
        .wa-info-row:last-child { border-bottom: none; }
        .wa-info-label { color: #64748b; font-weight: 500; }
        .wa-info-value { color: #0f172a; font-weight: 600; text-align: right; }
        
        .wa-shared-doc-card {
          display: flex;
          align-items: center;
          gap: 10px;
          padding: 8px 10px;
          background: #f8fafc;
          border-radius: 8px;
          border: 1px solid #e2e8f0;
          margin-bottom: 8px;
          font-size: 13px;
        }
        .wa-doc-badge {
          background: #fee2e2;
          color: #e74c3c;
          padding: 4px 6px;
          border-radius: 4px;
          font-weight: 700;
          font-size: 11px;
        }
      </style>
      
      <div class="wa-drawer-header">
        <button class="wa-drawer-close" id="drawer-close-btn">✕</button>
        <span class="wa-drawer-title">Contact &amp; Student Info</span>
      </div>
      
      <div class="wa-drawer-body">
        <!-- PROFILE HERO -->
        <div class="wa-drawer-section wa-profile-hero">
          <wa-avatar name="${name}" size="90"></wa-avatar>
          <div class="wa-hero-name">${name}</div>
          <div class="wa-hero-phone">${phone}</div>
        </div>
        
        <!-- ACADEMIC & STUDENT DETAILS -->
        <div class="wa-drawer-section">
          <div class="wa-sec-heading">🎓 Academic Profile</div>
          <div class="wa-info-row">
            <span class="wa-info-label">Roll Number</span>
            <span class="wa-info-value">${rollNo}</span>
          </div>
          <div class="wa-info-row">
            <span class="wa-info-label">Class &amp; Division</span>
            <span class="wa-info-value">${className}</span>
          </div>
          <div class="wa-info-row">
            <span class="wa-info-label">Academic Year</span>
            <span class="wa-info-value">${academicYear}</span>
          </div>
          <div class="wa-info-row">
            <span class="wa-info-label">Overall Performance</span>
            <span class="wa-info-value" style="color:#008069">${percentage} · Grade ${grade}</span>
          </div>
          <div class="wa-info-row">
            <span class="wa-info-label">Attendance</span>
            <span class="wa-info-value">${attendance}</span>
          </div>
        </div>
        
        <!-- SHARED MEDIA / DOCS -->
        <div class="wa-drawer-section">
          <div class="wa-sec-heading">📄 Shared Documents</div>
          <div class="wa-shared-doc-card">
            <span class="wa-doc-badge">PDF</span>
            <span style="flex:1;font-weight:600">Semester I Report Card</span>
            <button style="border:none;background:#00a884;color:#fff;border-radius:4px;padding:4px 8px;cursor:pointer;font-size:11.5px" id="drawer-preview-rc">View</button>
          </div>
          <div class="wa-shared-doc-card">
            <span class="wa-doc-badge">PDF</span>
            <span style="flex:1;font-weight:600">Unit Test Marksheet Slip</span>
            <button style="border:none;background:#00a884;color:#fff;border-radius:4px;padding:4px 8px;cursor:pointer;font-size:11.5px">View</button>
          </div>
        </div>
        
        <!-- CHAT SETTINGS -->
        <div class="wa-drawer-section">
          <div class="wa-sec-heading">⚙️ Chat Options</div>
          <div class="wa-info-row" style="cursor:pointer" id="drawer-mute-toggle">
            <span class="wa-info-label">Mute Notifications</span>
            <span class="wa-info-value">${chat.is_muted ? 'Muted' : 'No'}</span>
          </div>
          <div class="wa-info-row" style="cursor:pointer;color:#e74c3c" id="drawer-clear-chat">
            <span style="color:#e74c3c">🧹 Clear Chat</span>
            <span>&gt;</span>
          </div>
        </div>
      </div>
    `;

    this.shadowRoot.querySelector('#drawer-close-btn')?.addEventListener('click', () => {
      this.close();
    });

    this.shadowRoot.querySelector('#drawer-preview-rc')?.addEventListener('click', () => {
      this.dispatchEvent(new CustomEvent('preview-pdf', {
        detail: { url: '', title: `Report_Card_${name.replace(/\s+/g, '_')}.pdf` },
        bubbles: true,
        composed: true
      }));
    });
  }
}
customElements.define('wa-contact-drawer', WaContactDrawer);

// ============ PDF PREVIEW MODAL ============

class WaPdfPreviewModal extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.zoomLevel = 100;
  }

  connectedCallback() {
    this.render();
  }

  open({ url, title = 'Student Report Card' }) {
    this.pdfUrl = url;
    this.pdfTitle = title;
    this.zoomLevel = 100;
    this.setAttribute('open', '');
    this.render();
  }

  close() {
    this.removeAttribute('open');
  }

  render() {
    this.shadowRoot.innerHTML = `
      <style>
        :host {
          position: fixed;
          inset: 0;
          z-index: 100000;
          display: none;
          align-items: center;
          justify-content: center;
          background: rgba(11,20,26,0.85);
          backdrop-filter: blur(6px);
        }
        :host([open]) {
          display: flex;
        }
        .wa-pdf-modal-box {
          width: 90vw;
          max-width: 980px;
          height: 88vh;
          background: #ffffff;
          border-radius: 14px;
          box-shadow: 0 20px 50px rgba(0,0,0,0.5);
          display: flex;
          flex-direction: column;
          overflow: hidden;
          animation: waPopScale 0.18s ease-out;
        }
        .wa-pdf-topbar {
          display: flex;
          align-items: center;
          justify-content: space-between;
          height: 56px;
          padding: 0 20px;
          background: #005c4b;
          color: #ffffff;
          flex-shrink: 0;
        }
        .wa-pdf-top-title {
          font-size: 16px;
          font-weight: 700;
          white-space: nowrap;
          overflow: hidden;
          text-overflow: ellipsis;
        }
        .wa-pdf-top-controls {
          display: flex;
          align-items: center;
          gap: 10px;
        }
        .wa-pdf-top-btn {
          background: rgba(255,255,255,0.15);
          border: none;
          color: #ffffff;
          border-radius: 6px;
          padding: 6px 12px;
          font-size: 13px;
          cursor: pointer;
          display: inline-flex;
          align-items: center;
          gap: 6px;
        }
        .wa-pdf-top-btn:hover { background: rgba(255,255,255,0.25); }
        .wa-pdf-viewer-body {
          flex: 1;
          background: #525659;
          display: flex;
          align-items: center;
          justify-content: center;
          overflow: auto;
          padding: 20px;
        }
        .wa-mock-paper {
          width: 595px;
          min-height: 842px;
          background: #ffffff;
          box-shadow: 0 8px 30px rgba(0,0,0,0.3);
          padding: 40px;
          box-sizing: border-box;
          transform-origin: top center;
          transition: transform 0.15s;
          font-family: Arial, sans-serif;
          color: #1e293b;
        }
        .wa-rc-school-head {
          text-align: center;
          border-bottom: 2px solid #7a2238;
          padding-bottom: 16px;
          margin-bottom: 20px;
        }
        .wa-rc-school-title {
          font-size: 20px;
          font-weight: 900;
          color: #7a2238;
        }
        .wa-rc-school-sub {
          font-size: 12px;
          color: #64748b;
          margin-top: 4px;
        }
        .wa-rc-table {
          width: 100%;
          border-collapse: collapse;
          margin-top: 20px;
          font-size: 13px;
        }
        .wa-rc-table th, .wa-rc-table td {
          border: 1px solid #cbd5e1;
          padding: 8px 10px;
          text-align: center;
        }
        .wa-rc-table th {
          background: #f8fafc;
          font-weight: 700;
        }
      </style>
      
      <div class="wa-pdf-modal-box">
        <div class="wa-pdf-topbar">
          <div class="wa-pdf-top-title">📄 ${this.pdfTitle || 'Student Report Card'}</div>
          <div class="wa-pdf-top-controls">
            <button class="wa-pdf-top-btn" id="btn-zoom-out" title="Zoom Out">🔍 -</button>
            <span style="font-size:13px;" id="zoom-val">100%</span>
            <button class="wa-pdf-top-btn" id="btn-zoom-in" title="Zoom In">🔍 +</button>
            <button class="wa-pdf-top-btn" id="btn-download" title="Download PDF">📥 Download</button>
            <button class="wa-pdf-top-btn" id="btn-print" title="Print PDF">🖨️ Print</button>
            <button class="wa-pdf-top-btn" id="btn-close" style="background:#e74c3c" title="Close">✕</button>
          </div>
        </div>
        
        <div class="wa-pdf-viewer-body">
          ${this.pdfUrl ? `
            <iframe src="${this.pdfUrl}" style="width:100%;height:100%;border:none;"></iframe>
          ` : `
            <div class="wa-mock-paper" id="paper-preview">
              <div class="wa-rc-school-head">
                <div class="wa-rc-school-title">HINDUSTANI COVENANT CHURCH HIGH SCHOOL</div>
                <div class="wa-rc-school-sub">Excellence in Academic &amp; Moral Education · Pune, Maharashtra</div>
                <div style="font-size:14px;font-weight:bold;margin-top:8px;color:#005c4b;">PROGRESS REPORT CARD · ACADEMIC YEAR 2025-26</div>
              </div>
              
              <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:16px;">
                <div>
                  <div><b>Student:</b> Asabe Madhura Shankar</div>
                  <div><b>Class:</b> STD V Division A</div>
                </div>
                <div>
                  <div><b>Roll No:</b> 1</div>
                  <div><b>Semester:</b> Semester I</div>
                </div>
              </div>
              
              <table class="wa-rc-table">
                <thead>
                  <tr>
                    <th style="text-align:left">Subject</th>
                    <th>Max Marks</th>
                    <th>Marks Obtained</th>
                    <th>Grade</th>
                  </tr>
                </thead>
                <tbody>
                  <tr><td style="text-align:left">English</td><td>100</td><td>94</td><td>A1</td></tr>
                  <tr><td style="text-align:left">Mathematics</td><td>100</td><td>98</td><td>A1</td></tr>
                  <tr><td style="text-align:left">General Science</td><td>100</td><td>92</td><td>A1</td></tr>
                  <tr><td style="text-align:left">Social Studies</td><td>100</td><td>91</td><td>A1</td></tr>
                  <tr><td style="text-align:left">Hindi / Marathi</td><td>100</td><td>94</td><td>A1</td></tr>
                </tbody>
              </table>
              
              <div style="margin-top:24px;display:flex;justify-content:space-between;font-weight:bold;">
                <div>Total: 469 / 500</div>
                <div>Percentage: 93.83%</div>
                <div>Result: PASSED (Grade A1)</div>
              </div>
              
              <div style="margin-top:60px;display:flex;justify-content:space-between;font-size:12px;">
                <div>Class Teacher's Signature</div>
                <div>Principal's Signature</div>
              </div>
            </div>
          `}
        </div>
      </div>
    `;

    this.bindPdfEvents();
  }

  bindPdfEvents() {
    this.shadowRoot.querySelector('#btn-close')?.addEventListener('click', () => {
      this.close();
    });

    const paper = this.shadowRoot.querySelector('#paper-preview');
    const zoomText = this.shadowRoot.querySelector('#zoom-val');

    this.shadowRoot.querySelector('#btn-zoom-in')?.addEventListener('click', () => {
      this.zoomLevel = Math.min(this.zoomLevel + 15, 180);
      if (paper) paper.style.transform = `scale(${this.zoomLevel / 100})`;
      if (zoomText) zoomText.textContent = `${this.zoomLevel}%`;
    });

    this.shadowRoot.querySelector('#btn-zoom-out')?.addEventListener('click', () => {
      this.zoomLevel = Math.max(this.zoomLevel - 15, 50);
      if (paper) paper.style.transform = `scale(${this.zoomLevel / 100})`;
      if (zoomText) zoomText.textContent = `${this.zoomLevel}%`;
    });

    this.shadowRoot.querySelector('#btn-print')?.addEventListener('click', () => {
      window.print();
    });

    this.shadowRoot.querySelector('#btn-download')?.addEventListener('click', () => {
      alert(`Downloading ${this.pdfTitle}...`);
    });
  }
}
customElements.define('wa-pdf-preview-modal', WaPdfPreviewModal);

// ============ CONTACT PICKER (NEW CHAT) ============

class WaContactPicker extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.store = window.WhatsAppStore;
    this.allContacts = [];
    this.searchQuery = '';
  }

  connectedCallback() {
    this.render();
  }

  open() {
    this.setAttribute('open', '');
    const searchInp = this.shadowRoot.querySelector('#cp-search-input');
    if (searchInp) {
      searchInp.value = '';
      this.searchQuery = '';
    }
    this.loadContacts();
    setTimeout(() => {
      searchInp?.focus();
    }, 120);
  }

  close() {
    this.removeAttribute('open');
  }

  escapeHtml(text) {
    if (!text) return '';
    return String(text).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }

  async loadContacts() {
    const list = this.shadowRoot.querySelector('#contact-list');
    if (!list) return;

    const contactMap = new Map();

    // 1. Existing contacts in store
    if (this.store?.contacts) {
      for (const c of this.store.contacts.values()) {
        const waId = c.wa_id || (c.phone_e164 ? c.phone_e164.replace(/\D/g, '') + '@c.us' : '');
        if (waId && !contactMap.has(waId)) {
          contactMap.set(waId, {
            wa_id: waId,
            name: c.name || 'Student',
            phone_e164: c.phone_e164 || '',
            linked_student_roll: c.linked_student_roll || '',
            linked_class_key: c.linked_class_key || '',
            avatar_url: c.avatar_url || ''
          });
        }
      }
    }

    // 2. Individual chats in store
    if (this.store?.chats) {
      for (const ch of this.store.chats.values()) {
        if (ch.chat_type === 'individual' && ch.wa_chat_id && !contactMap.has(ch.wa_chat_id)) {
          contactMap.set(ch.wa_chat_id, {
            wa_id: ch.wa_chat_id,
            name: ch.name || 'Student',
            phone_e164: ch.phone_e164 || ('+' + ch.wa_chat_id.replace(/\D/g, '')),
            linked_student_roll: ch.linked_student_roll || '',
            linked_class_key: ch.linked_class_key || '',
            avatar_url: ch.avatar_url || ''
          });
        }
      }
    }

    // 3. Current active class roster from #waHubData
    try {
      const hubDataEl = document.getElementById('waHubData');
      if (hubDataEl) {
        const hubCfg = JSON.parse(hubDataEl.textContent || '{}');
        const stuList = hubCfg.students || [];
        for (const stu of stuList) {
          const rawPhone = String(stu.parent_phone || '').replace(/\D/g, '');
          const cleanPhone = rawPhone.length >= 10 ? (rawPhone.startsWith('91') ? rawPhone : '91' + rawPhone) : ('9198765' + String(stu.roll_no).padStart(5, '0'));
          const waId = cleanPhone + '@c.us';
          if (!contactMap.has(waId)) {
            contactMap.set(waId, {
              wa_id: waId,
              name: stu.student_name || `Student ${stu.roll_no}`,
              phone_e164: '+' + cleanPhone,
              linked_student_roll: stu.roll_no,
              linked_class_key: hubCfg.classKey || stu.std || '',
              avatar_url: `https://api.dicebear.com/7.x/initials/svg?seed=${encodeURIComponent(stu.student_name || 'Student')}`
            });
          }
        }
      }
    } catch (e) {}

    this.allContacts = Array.from(contactMap.values()).sort((a, b) => (a.name || '').localeCompare(b.name || ''));
    this.renderContactList();

    // In parallel, load contacts from server
    if (this.store && typeof this.store.loadContacts === 'function') {
      try {
        const res = await this.store.loadContacts('', 1);
        if (res && res.data && Array.isArray(res.data)) {
          for (const c of res.data) {
            if (c.wa_id && !contactMap.has(c.wa_id)) {
              contactMap.set(c.wa_id, {
                wa_id: c.wa_id,
                name: c.name || 'Student',
                phone_e164: c.phone_e164 || '',
                linked_student_roll: c.linked_student_roll || '',
                linked_class_key: c.linked_class_key || '',
                avatar_url: c.avatar_url || ''
              });
            }
          }
          this.allContacts = Array.from(contactMap.values()).sort((a, b) => (a.name || '').localeCompare(b.name || ''));
          this.renderContactList();
        }
      } catch (e) {}
    }
  }

  renderContactList() {
    const list = this.shadowRoot.querySelector('#contact-list');
    if (!list) return;

    const q = (this.searchQuery || '').trim().toLowerCase();
    const cleanDigits = q.replace(/\D/g, '');
    
    let filtered = this.allContacts;
    if (q) {
      filtered = this.allContacts.filter(c => {
        const name = (c.name || '').toLowerCase();
        const phone = (c.phone_e164 || c.wa_id || '').toLowerCase();
        const roll = String(c.linked_student_roll || '').toLowerCase();
        const cls = String(c.linked_class_key || '').toLowerCase();
        return name.includes(q) || phone.includes(q) || roll.includes(q) || cls.includes(q);
      });
    }

    let html = '';

    // Direct phone number action
    if (cleanDigits.length >= 3) {
      const displayPhone = cleanDigits.length === 10 ? `+91 ${cleanDigits}` : `+${cleanDigits}`;
      html += `
        <div class="wa-cp-direct-phone-card" data-phone="${cleanDigits}">
          <div class="wa-cp-action-icon" style="background:#00a884;color:#fff;">
            <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 10h-4v4h-2v-4H8v-2h4V6h2v4h4v2z"/></svg>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="font-weight:600;font-size:14px;color:#111b21;">Chat with <strong>${this.escapeHtml(displayPhone)}</strong></div>
            <div style="font-size:12px;color:#667781;">Click to start a new chat with this phone number</div>
          </div>
          <span style="color:#00a884;font-weight:600;font-size:13px;">Chat →</span>
        </div>
      `;
    }

    if (!q) {
      html += `
        <div class="wa-cp-action-row" id="cp-act-new-group">
          <div class="wa-cp-action-icon" style="background:#00a884;color:#fff;">
            <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
          </div>
          <div style="flex:1;">
            <div style="font-weight:600;font-size:14.5px;color:#111b21;">New group</div>
            <div style="font-size:12px;color:#667781;">Create a class parent group or academic forum</div>
          </div>
        </div>

        <div class="wa-cp-action-row" id="cp-act-new-channel">
          <div class="wa-cp-action-icon" style="background:#008069;color:#fff;">
            <svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-1.99.9-1.99 2L2 22l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-7 9h-2V5h2v6zm0 4h-2v-2h2v2z"/></svg>
          </div>
          <div style="flex:1;">
            <div style="font-weight:600;font-size:14.5px;color:#111b21;">New broadcast channel</div>
            <div style="font-size:12px;color:#667781;">Official circulars, events, and school announcements</div>
          </div>
        </div>

        <div class="wa-cp-action-row" id="cp-act-new-phone">
          <div class="wa-cp-action-icon" style="background:#128c7e;color:#fff;">
            <svg viewBox="0 0 24 24"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>
          </div>
          <div style="flex:1;">
            <div style="font-weight:600;font-size:14.5px;color:#111b21;">Direct chat with phone number</div>
            <div style="font-size:12px;color:#667781;">Enter any phone number to start chatting immediately</div>
          </div>
        </div>
      `;
    }

    html += `<div class="wa-cp-section-header">STUDENTS & CONTACTS ON WHATSAPP (${filtered.length})</div>`;

    if (filtered.length === 0 && cleanDigits.length < 3) {
      html += `
        <div style="padding:32px 16px;text-align:center;color:#8696a0;">
          <div style="font-size:15px;font-weight:600;color:#111b21;margin-bottom:6px;">No contacts found</div>
          <div style="font-size:13px;">No matching student or parent contact found for "${this.escapeHtml(q)}".</div>
        </div>
      `;
    } else {
      html += filtered.map(c => `
        <div class="wa-cp-contact" data-contact='${JSON.stringify(c)}'>
          <wa-avatar name="${this.escapeHtml(c.name || 'Student')}" size="44"></wa-avatar>
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:6px;">
              <span style="font-weight:600;font-size:14px;color:#111b21;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${this.escapeHtml(c.name || 'Student')}</span>
              ${c.linked_student_roll ? `<span class="wa-badge-roll">Roll ${this.escapeHtml(c.linked_student_roll)}</span>` : ''}
            </div>
            <div style="font-size:12.5px;color:#667781;display:flex;align-items:center;gap:6px;margin-top:2px;">
              <span>${this.escapeHtml(c.phone_e164 || c.wa_id || '')}</span>
              ${c.linked_class_key ? `<span class="wa-badge-class">${this.escapeHtml(c.linked_class_key)}</span>` : ''}
            </div>
          </div>
          <button type="button" class="wa-cp-chat-btn">Chat</button>
        </div>
      `).join('');
    }

    list.innerHTML = html;

    // Click events
    list.querySelectorAll('.wa-cp-contact').forEach(row => {
      row.addEventListener('click', async () => {
        try {
          const contact = JSON.parse(row.dataset.contact);
          await this.store?.getOrCreateChat({
            phone: contact.phone_e164 || contact.wa_id,
            name: contact.name,
            student_roll: contact.linked_student_roll,
            class_key: contact.linked_class_key,
            chat_type: 'individual'
          });
          this.close();
        } catch (e) {
          console.error('Error starting chat from picker:', e);
        }
      });
    });

    list.querySelector('.wa-cp-direct-phone-card')?.addEventListener('click', async (e) => {
      const card = e.currentTarget;
      const phone = card.dataset.phone;
      if (phone) {
        await this.store?.getOrCreateChat({
          phone: phone,
          name: phone.length === 10 ? `+91 ${phone}` : `+${phone}`,
          chat_type: 'individual'
        });
        this.close();
      }
    });

    list.querySelector('#cp-act-new-group')?.addEventListener('click', () => {
      this.close();
      const gc = document.querySelector('wa-group-create') || this.getRootNode()?.querySelector('wa-group-create');
      if (gc && typeof gc.open === 'function') gc.open();
    });

    list.querySelector('#cp-act-new-channel')?.addEventListener('click', async () => {
      const name = prompt('Enter official announcement channel name:', 'HCC Official Circulars & Events');
      if (name && name.trim()) {
        await this.store?.getOrCreateChat({
          name: name.trim(),
          chat_type: 'broadcast'
        });
        this.close();
      }
    });

    list.querySelector('#cp-act-new-phone')?.addEventListener('click', async () => {
      const phone = prompt('Enter 10-digit mobile number or phone with country code:');
      if (phone && phone.trim()) {
        const clean = phone.replace(/\D/g, '');
        if (clean.length < 5) return alert('Please enter a valid phone number');
        await this.store?.getOrCreateChat({
          phone: clean,
          name: clean.length === 10 ? `+91 ${clean}` : `+${clean}`,
          chat_type: 'individual'
        });
        this.close();
      }
    });
  }

  render() {
    this.shadowRoot.innerHTML = `
      <style>
        :host {
          position: fixed;
          inset: 0;
          z-index: 9999;
          display: none;
          align-items: center;
          justify-content: center;
          background: rgba(11,20,26,0.6);
          backdrop-filter: blur(2px);
        }
        :host([open]) { display: flex; }
        .wa-cp-box {
          width: 480px;
          max-width: 95vw;
          height: 85vh;
          max-height: 640px;
          background: #ffffff;
          border-radius: 12px;
          box-shadow: 0 16px 40px rgba(0,0,0,0.25);
          display: flex;
          flex-direction: column;
          overflow: hidden;
          animation: waModalPop 0.18s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes waModalPop {
          from { opacity: 0; transform: scale(0.95) translateY(8px); }
          to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .wa-cp-head {
          display: flex;
          align-items: center;
          gap: 14px;
          padding: 16px 20px;
          background: #008069;
          color: #ffffff;
          flex-shrink: 0;
        }
        .wa-cp-back {
          background: none;
          border: none;
          color: #ffffff;
          cursor: pointer;
          padding: 4px;
          display: flex;
          align-items: center;
          justify-content: center;
          border-radius: 50%;
          transition: background 0.15s;
        }
        .wa-cp-back:hover {
          background: rgba(255,255,255,0.15);
        }
        .wa-cp-back svg {
          width: 20px;
          height: 20px;
          fill: currentColor;
        }
        .wa-cp-title {
          font-size: 17px;
          font-weight: 700;
          flex: 1;
        }
        .wa-cp-close {
          background: none;
          border: none;
          color: #fff;
          font-size: 20px;
          cursor: pointer;
          opacity: 0.85;
          padding: 4px;
        }
        .wa-cp-close:hover { opacity: 1; }
        
        .wa-cp-search-bar {
          padding: 10px 16px;
          background: #ffffff;
          border-bottom: 1px solid #f0f2f5;
          flex-shrink: 0;
        }
        .wa-cp-search-inner {
          display: flex;
          align-items: center;
          background: #f0f2f5;
          border-radius: 8px;
          padding: 0 12px;
          height: 38px;
        }
        .wa-cp-search-inner:focus-within {
          background: #ffffff;
          box-shadow: 0 0 0 1px #00a884;
        }
        .wa-cp-search-inner svg {
          width: 17px;
          height: 17px;
          fill: #8696a0;
          margin-right: 10px;
          flex-shrink: 0;
        }
        .wa-cp-search-input {
          flex: 1;
          border: none;
          outline: none;
          background: transparent;
          font-size: 14px;
          color: #111b21;
          font-family: inherit;
        }
        
        .wa-cp-list {
          flex: 1;
          overflow-y: auto;
        }
        .wa-cp-action-row {
          display: flex;
          align-items: center;
          gap: 14px;
          padding: 12px 16px;
          cursor: pointer;
          border-bottom: 1px solid #f8fafc;
          transition: background 0.15s ease;
        }
        .wa-cp-action-row:hover {
          background: #f0f2f5;
        }
        .wa-cp-direct-phone-card {
          display: flex;
          align-items: center;
          gap: 14px;
          padding: 12px 16px;
          background: #f0fdf4;
          cursor: pointer;
          border-bottom: 1px solid #dcfce7;
          transition: background 0.15s ease;
        }
        .wa-cp-direct-phone-card:hover {
          background: #dcfce7;
        }
        .wa-cp-action-icon {
          width: 44px;
          height: 44px;
          border-radius: 50%;
          display: flex;
          align-items: center;
          justify-content: center;
          flex-shrink: 0;
        }
        .wa-cp-action-icon svg {
          width: 22px;
          height: 22px;
          fill: currentColor;
        }
        .wa-cp-section-header {
          padding: 10px 16px 6px;
          font-size: 11.5px;
          font-weight: 700;
          text-transform: uppercase;
          letter-spacing: 0.6px;
          color: #008069;
          background: #f8fafc;
          border-top: 1px solid #f1f5f9;
          border-bottom: 1px solid #f1f5f9;
        }
        .wa-cp-contact {
          display: flex;
          align-items: center;
          gap: 12px;
          padding: 10px 16px;
          cursor: pointer;
          border-bottom: 1px solid #f1f5f9;
          transition: background 0.12s ease;
        }
        .wa-cp-contact:hover {
          background: #f0f2f5;
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
        }
        .wa-cp-chat-btn {
          background: #00a884;
          color: #ffffff;
          border: none;
          border-radius: 6px;
          padding: 6px 12px;
          font-size: 12px;
          font-weight: 600;
          cursor: pointer;
          transition: background 0.15s;
          flex-shrink: 0;
        }
        .wa-cp-chat-btn:hover {
          background: #008069;
        }
      </style>
      <div class="wa-cp-box">
        <div class="wa-cp-head">
          <button class="wa-cp-back" id="cp-back-btn" title="Back">
            <svg viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
          </button>
          <span class="wa-cp-title">New chat</span>
          <button class="wa-cp-close" id="cp-close-btn" title="Close">✕</button>
        </div>
        <div class="wa-cp-search-bar">
          <div class="wa-cp-search-inner">
            <svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
            <input type="text" class="wa-cp-search-input" id="cp-search-input" placeholder="Search name or number">
          </div>
        </div>
        <div class="wa-cp-list" id="contact-list">
          <div style="padding:24px;text-align:center;color:#8696a0">Loading school contacts...</div>
        </div>
      </div>
    `;

    this.shadowRoot.querySelector('#cp-close-btn')?.addEventListener('click', () => this.close());
    this.shadowRoot.querySelector('#cp-back-btn')?.addEventListener('click', () => this.close());
    
    const searchInp = this.shadowRoot.querySelector('#cp-search-input');
    searchInp?.addEventListener('input', (e) => {
      this.searchQuery = e.target.value;
      this.renderContactList();
    });
  }
}
customElements.define('wa-contact-picker', WaContactPicker);

// ============ GROUP CREATE ============

class WaGroupCreate extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
  }

  connectedCallback() {
    this.render();
  }

  open() { 
    this.setAttribute('open', ''); 
    const inp = this.shadowRoot.querySelector('#group-name-input');
    if (inp) {
      inp.value = '';
      setTimeout(() => inp.focus(), 100);
    }
  }

  close() { this.removeAttribute('open'); }

  render() {
    this.shadowRoot.innerHTML = `
      <style>
        :host {
          position: fixed; inset: 0; z-index: 9999; display: none; align-items: center; justify-content: center; background: rgba(11,20,26,0.6);
        }
        :host([open]) { display: flex; }
        .wa-gc-box { width: 440px; max-width: 92vw; background: #ffffff; border-radius: 12px; padding: 22px; box-shadow: 0 16px 40px rgba(0,0,0,0.25); }
        .wa-gc-title { font-size: 17px; font-weight: 700; color: #111b21; margin-bottom: 6px; }
        .wa-gc-sub { font-size: 13px; color: #667781; margin-bottom: 16px; }
        .wa-gc-input { width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; box-sizing: border-box; margin-bottom: 16px; font-family: inherit; }
        .wa-gc-input:focus { outline: none; border-color: #00a884; }
        .wa-gc-actions { display: flex; justify-content: flex-end; gap: 10px; }
        .wa-gc-btn { padding: 9px 18px; border-radius: 6px; font-weight: 600; cursor: pointer; border: none; font-size: 13.5px; transition: background 0.15s; font-family: inherit; }
        .wa-gc-btn-cancel { background: #e2e8f0; color: #334155; }
        .wa-gc-btn-cancel:hover { background: #cbd5e1; }
        .wa-gc-btn-submit { background: #00a884; color: #ffffff; }
        .wa-gc-btn-submit:hover { background: #008069; }
      </style>
      <div class="wa-gc-box">
        <div class="wa-gc-title">Create Class Parent Group</div>
        <div class="wa-gc-sub">Start an official group to broadcast class notices, circulars, and exam timetables.</div>
        <input type="text" class="wa-gc-input" id="group-name-input" placeholder="e.g. STD X B Parents (2024-25)">
        <div class="wa-gc-actions">
          <button class="wa-gc-btn wa-gc-btn-cancel" id="gc-cancel">Cancel</button>
          <button class="wa-gc-btn wa-gc-btn-submit" id="gc-submit">Create Group</button>
        </div>
      </div>
    `;

    this.shadowRoot.querySelector('#gc-cancel')?.addEventListener('click', () => this.close());
    this.shadowRoot.querySelector('#gc-submit')?.addEventListener('click', async () => {
      const name = this.shadowRoot.querySelector('#group-name-input')?.value.trim();
      if (!name) return alert('Please enter a group name');
      
      const submitBtn = this.shadowRoot.querySelector('#gc-submit');
      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating...';
      }

      try {
        await window.WhatsAppStore?.getOrCreateChat({
          name: name,
          chat_type: 'group'
        });
        this.close();
      } catch (err) {
        console.error('Failed to create group:', err);
        alert('Failed to create group: ' + (err.message || 'Unknown error'));
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Create Group';
        }
      }
    });

    this.shadowRoot.querySelector('#group-name-input')?.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        this.shadowRoot.querySelector('#gc-submit')?.click();
      }
    });
  }
}
customElements.define('wa-group-create', WaGroupCreate);

// ============ QR SCANNER MODAL ============

class WaQrModal extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
  }

  connectedCallback() {
    this.render();
  }

  open() { this.setAttribute('open', ''); }
  close() { this.removeAttribute('open'); }

  render() {
    this.shadowRoot.innerHTML = `
      <style>
        :host { position: fixed; inset: 0; z-index: 9999; display: none; align-items: center; justify-content: center; background: rgba(11,20,26,0.7); }
        :host([open]) { display: flex; }
        .wa-qr-box { width: 360px; background: #ffffff; border-radius: 14px; padding: 24px; text-align: center; box-shadow: 0 16px 40px rgba(0,0,0,0.3); }
        .wa-qr-title { font-size: 18px; font-weight: 700; margin-bottom: 8px; color: #111b21; }
        .wa-qr-desc { font-size: 13px; color: #667781; margin-bottom: 20px; line-height: 1.4; }
        .wa-qr-img { width: 220px; height: 220px; margin: 0 auto 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 80px; }
      </style>
      <div class="wa-qr-box">
        <div class="wa-qr-title">Link School WhatsApp</div>
        <div class="wa-qr-desc">Scan this QR code with WhatsApp on your official school phone to sync real-time conversations.</div>
        <div class="wa-qr-img">📲</div>
        <button style="background:#00a884;color:#fff;border:none;border-radius:6px;padding:8px 16px;cursor:pointer;font-weight:600;" id="qr-close-btn">Done</button>
      </div>
    `;
    this.shadowRoot.querySelector('#qr-close-btn')?.addEventListener('click', () => this.close());
  }
}
customElements.define('wa-qr-modal', WaQrModal);

// ============ SETTINGS PANEL ============

class WaSettings extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.store = window.WhatsAppStore;
  }

  connectedCallback() {
    this.render();
  }

  open() { this.setAttribute('open', ''); }
  close() { this.removeAttribute('open'); }

  render() {
    this.shadowRoot.innerHTML = `
      <style>
        :host { position: fixed; inset: 0; z-index: 9999; display: none; align-items: center; justify-content: center; background: rgba(11,20,26,0.6); }
        :host([open]) { display: flex; }
        .wa-st-box { width: 400px; background: #ffffff; border-radius: 12px; padding: 24px; box-shadow: 0 16px 40px rgba(0,0,0,0.25); }
        .wa-st-title { font-size: 18px; font-weight: 700; margin-bottom: 16px; }
        .wa-st-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #f1f5f9; font-size: 14px; }
      </style>
      <div class="wa-st-box">
        <div class="wa-st-title">WhatsApp Settings</div>
        <div class="wa-st-row">
          <span>Dark Mode</span>
          <button style="border:none;background:#e2e8f0;padding:4px 10px;border-radius:12px;cursor:pointer" id="btn-toggle-dark">Toggle</button>
        </div>
        <div class="wa-st-row">
          <span>Notifications</span>
          <span style="color:#008069;font-weight:bold">Enabled</span>
        </div>
        <div class="wa-st-row">
          <span>School Account</span>
          <span>Hindustani Covenant Church</span>
        </div>
        <div style="margin-top:20px;text-align:right">
          <button style="background:#00a884;color:#fff;border:none;border-radius:6px;padding:8px 16px;cursor:pointer;font-weight:600;" id="st-close-btn">Close</button>
        </div>
      </div>
    `;

    this.shadowRoot.querySelector('#st-close-btn')?.addEventListener('click', () => this.close());
    this.shadowRoot.querySelector('#btn-toggle-dark')?.addEventListener('click', () => {
      document.documentElement.classList.toggle('wa-dark');
      this.store?.toggleDarkMode();
    });
  }
}
customElements.define('wa-settings', WaSettings);

// ============ MAIN APP SHELL ============

class WaApp extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this.store = window.WhatsAppStore;
    this.isFullscreen = false;
  }

  connectedCallback() {
    this.render();
    this.bindAppOrchestration();
  }

  toggleFullscreenMode() {
    this.isFullscreen = !this.isFullscreen;
    const host = this;
    if (this.isFullscreen) {
      host.classList.add('fullscreen-mode');
      document.body.classList.add('wa-fullscreen-active');
      if (document.documentElement.requestFullscreen) {
        document.documentElement.requestFullscreen().catch(() => {});
      }
    } else {
      host.classList.remove('fullscreen-mode');
      document.body.classList.remove('wa-fullscreen-active');
      if (document.exitFullscreen && document.fullscreenElement) {
        document.exitFullscreen().catch(() => {});
      }
    }
    this.dispatchEvent(new CustomEvent('fullscreen-changed', {
      detail: { isFullscreen: this.isFullscreen },
      bubbles: true,
      composed: true
    }));
  }

  render() {
    this.shadowRoot.innerHTML = `
      <style>
        :host {
          display: flex;
          flex-direction: column;
          height: 100%;
          width: 100%;
          overflow: hidden;
          background: #f0f2f5;
          font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif;
          box-sizing: border-box;
          border-radius: 12px;
        }
        
        :host(.fullscreen-mode) {
          position: fixed;
          top: 0;
          left: 0;
          right: 0;
          bottom: 0;
          z-index: 99999;
          width: 100vw;
          height: 100vh;
        }
        
        /* TOP BRAND & CONTROL BAR */
        .wa-top-bar {
          display: flex;
          align-items: center;
          justify-content: space-between;
          height: 44px;
          background: #005c4b;
          color: #ffffff;
          padding: 0 16px;
          box-sizing: border-box;
          flex-shrink: 0;
        }
        .wa-top-brand {
          display: flex;
          align-items: center;
          gap: 8px;
          font-size: 13.5px;
          font-weight: 700;
          letter-spacing: 0.3px;
        }
        .wa-top-actions {
          display: flex;
          align-items: center;
          gap: 10px;
        }
        .wa-top-btn {
          background: rgba(255,255,255,0.15);
          border: none;
          color: #ffffff;
          border-radius: 6px;
          padding: 4px 10px;
          font-size: 12px;
          font-weight: 600;
          cursor: pointer;
          display: inline-flex;
          align-items: center;
          gap: 6px;
          transition: background 0.15s;
        }
        .wa-top-btn:hover { background: rgba(255,255,255,0.25); }
        .wa-status-badge {
          display: inline-flex;
          align-items: center;
          gap: 5px;
          font-size: 11.5px;
          color: #dcf8c6;
        }
        .wa-status-dot {
          width: 7px;
          height: 7px;
          border-radius: 50%;
          background: #25d366;
        }
        
        /* 2-PANE DESKTOP CONTAINER */
        .wa-workspace {
          display: flex;
          flex: 1;
          overflow: hidden;
          position: relative;
        }
        
        .wa-sidebar-pane {
          width: 380px;
          min-width: 320px;
          max-width: 460px;
          display: flex;
          flex-direction: column;
          height: 100%;
          background: #ffffff;
          border-right: 1px solid #e9edef;
          flex-shrink: 0;
        }
        
        .wa-conversation-pane {
          flex: 1;
          display: flex;
          flex-direction: column;
          height: 100%;
          background: #efeae2;
          position: relative;
          min-width: 0;
        }
        
        /* MOBILE VIEW TOGGLE */
        @media (max-width: 768px) {
          .wa-sidebar-pane { width: 100%; max-width: none; }
          .wa-conversation-pane { display: none; width: 100%; }
          :host(.mobile-show-chat) .wa-sidebar-pane { display: none; }
          :host(.mobile-show-chat) .wa-conversation-pane { display: flex; }
        }
      </style>
      
      <!-- TOP STATUS & FULLSCREEN BAR -->
      <div class="wa-top-bar">
        <div class="wa-top-brand">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.816 9.816 0 0 0 12.04 2z"/></svg>
          <span>Hindustani Covenant Church — WhatsApp Web Replica</span>
        </div>
        <div class="wa-top-actions">
          <span class="wa-status-badge">
            <span class="wa-status-dot"></span>
            <span>Connected</span>
          </span>
          <button class="wa-top-btn" id="btn-fullscreen-toggle" title="Toggle Fullscreen WhatsApp Web">
            <span>⛶</span>
            <span>Fullscreen WhatsApp Web</span>
          </button>
          <button class="wa-top-btn" id="btn-dark-toggle" title="Toggle Dark Mode">🌙</button>
        </div>
      </div>
      
      <!-- MAIN 2-PANE WORKSPACE -->
      <div class="wa-workspace">
        <div class="wa-sidebar-pane">
          <wa-chat-list id="chat-list"></wa-chat-list>
        </div>
        
        <div class="wa-conversation-pane">
          <wa-conversation-header id="convo-header"></wa-conversation-header>
          <wa-conversation-messages id="convo-messages"></wa-conversation-messages>
          <wa-composer id="composer"></wa-composer>
        </div>
      </div>
      
      <!-- INTERACTIVE MODALS & DRAWERS -->
      <wa-call-modal id="call-modal"></wa-call-modal>
      <wa-contact-drawer id="contact-drawer"></wa-contact-drawer>
      <wa-pdf-preview-modal id="pdf-modal"></wa-pdf-preview-modal>
      <wa-contact-picker id="contact-picker"></wa-contact-picker>
      <wa-group-create id="group-create"></wa-group-create>
      <wa-qr-modal id="qr-modal"></wa-qr-modal>
      <wa-settings id="settings-modal"></wa-settings>
    `;
  }

  bindAppOrchestration() {
    const composer = this.shadowRoot.querySelector('#composer');
    const callModal = this.shadowRoot.querySelector('#call-modal');
    const contactDrawer = this.shadowRoot.querySelector('#contact-drawer');
    const pdfModal = this.shadowRoot.querySelector('#pdf-modal');
    const contactPicker = this.shadowRoot.querySelector('#contact-picker');
    const groupCreate = this.shadowRoot.querySelector('#group-create');
    const qrModal = this.shadowRoot.querySelector('#qr-modal');
    const settingsModal = this.shadowRoot.querySelector('#settings-modal');

    // Fullscreen toggle
    this.shadowRoot.querySelector('#btn-fullscreen-toggle')?.addEventListener('click', () => {
      this.toggleFullscreenMode();
    });

    // Dark mode toggle
    this.shadowRoot.querySelector('#btn-dark-toggle')?.addEventListener('click', () => {
      document.documentElement.classList.toggle('wa-dark');
      this.store?.toggleDarkMode();
    });

    // Mobile back to list
    this.addEventListener('back-to-list', () => {
      this.classList.remove('mobile-show-chat');
    });

    // Chat selected -> switch pane on mobile
    if (this.store) {
      this.store.addEventListener('chat:selected', () => {
        this.classList.add('mobile-show-chat');
      });
    }

    // Open Call Modal
    this.addEventListener('open-call-modal', (e) => {
      callModal?.open(e.detail);
    });

    // Open Contact Drawer
    this.addEventListener('open-contact-drawer', (e) => {
      contactDrawer?.open(e.detail.chat);
    });

    // Preview PDF Modal
    this.addEventListener('preview-pdf', (e) => {
      pdfModal?.open(e.detail);
    });

    // Open Group Create
    const onOpenGroupCreate = () => groupCreate?.open();
    this.addEventListener('open-group-create', onOpenGroupCreate);
    window.addEventListener('open-group-create', onOpenGroupCreate);

    // Open Settings
    const onOpenSettings = () => settingsModal?.open();
    this.addEventListener('open-settings', onOpenSettings);
    window.addEventListener('open-settings', onOpenSettings);

    // Open Contact Picker (from header New Chat or composer attach)
    const onOpenContactPicker = () => contactPicker?.open();
    this.addEventListener('open-contact-picker', onOpenContactPicker);
    window.addEventListener('open-contact-picker', onOpenContactPicker);

    // Fullscreen toggle event from menus
    this.addEventListener('toggle-fullscreen', () => {
      this.toggleFullscreenMode();
    });

    // Send and next student
    this.addEventListener('send-and-next-student', () => {
      const chats = Array.from(this.store?.chats?.values() || []);
      const currentId = this.store?.activeChatId;
      const idx = chats.findIndex(c => c.id === currentId);
      if (idx >= 0 && idx < chats.length - 1) {
        this.store?.setActiveChat(chats[idx + 1].id);
      }
    });
  }
}
customElements.define('wa-app', WaApp);
