/**
 * HCC WhatsApp Web Replica — Central Store (IndexedDB-backed)
 * Manages all state: chats, messages, contacts, session, UI, state machine & audio triggers
 */

class WhatsAppStore extends EventTarget {
  constructor() {
    super();
    this.db = null;
    this.dbName = 'hcc_whatsapp';
    this.dbVersion = 1;
    
    // State
    this.chats = new Map();           // chatId -> chat object
    this.messages = new Map();        // chatId -> Map(msgId -> message)
    this.contacts = new Map();        // waId -> contact
    this.media = new Map();           // mediaKey -> media object
    
    // UI State
    this.activeChatId = null;
    this.activeChat = null;
    this.searchQuery = '';
    this.filter = 'all';              // all, unread, groups, archived, pinned
    this.showArchived = false;
    this.isSidebarCollapsed = false;
    this.isDarkMode = false;
    this.isOnline = typeof navigator !== 'undefined' ? navigator.onLine : true;
    this.session = null;
    this.unreadTotal = 0;
    
    // Pagination
    this.chatPage = 1;
    this.messagePages = new Map();    // chatId -> page
    this.loadingChats = false;
    this.loadingMessages = new Set();
    this.hasMoreChats = true;
    this.hasMoreMessages = new Map();
    
    // SSE
    this.eventSource = null;
    this.sseReconnectAttempts = 0;
    this.maxSseReconnectAttempts = 10;
    this.sseReconnectTimeout = null;
    
    // Outbound queue polling
    this.outboundPollInterval = null;
    
    // Initialize if in browser
    if (typeof window !== 'undefined') {
      this.init();
    }
  }
  
  async init() {
    if (this._initialized) return;
    this._initialized = true;
    try {
      await this.openDB();
      await this.loadFromIndexedDB();
    } catch (e) {
      console.warn('IndexedDB unavailable, proceeding in memory:', e);
    }
    this.applyTheme();
    this.setupEventListeners();
    this.connectSSE();
    this.startOutboundPolling();
    
    // Load initial chats
    await this.loadChats();
    
    this.dispatchEvent(new CustomEvent('store:ready'));
  }
  
  // ============ INDEXEDDB ============
  
  async openDB() {
    if (typeof indexedDB === 'undefined') return;
    return new Promise((resolve, reject) => {
      const request = indexedDB.open(this.dbName, this.dbVersion);
      
      request.onerror = () => reject(request.error);
      request.onsuccess = () => {
        this.db = request.result;
        resolve();
      };
      
      request.onupgradeneeded = (event) => {
        const db = event.target.result;
        
        // Chats
        if (!db.objectStoreNames.contains('chats')) {
          const chatStore = db.createObjectStore('chats', { keyPath: 'id' });
          chatStore.createIndex('wa_chat_id', 'wa_chat_id', { unique: true });
          chatStore.createIndex('last_activity', 'last_activity_at');
          chatStore.createIndex('unread', 'unread_count');
          chatStore.createIndex('pinned', 'is_pinned');
          chatStore.createIndex('archived', 'is_archived');
        }
        
        // Messages
        if (!db.objectStoreNames.contains('messages')) {
          const msgStore = db.createObjectStore('messages', { keyPath: 'id' });
          msgStore.createIndex('chat_id', 'chat_id');
          msgStore.createIndex('wa_msg_id', 'wa_msg_id', { unique: true });
          msgStore.createIndex('timestamp', 'timestamp');
          msgStore.createIndex('status', 'status');
        }
        
        // Contacts
        if (!db.objectStoreNames.contains('contacts')) {
          const contactStore = db.createObjectStore('contacts', { keyPath: 'id' });
          contactStore.createIndex('wa_id', 'wa_id', { unique: true });
          contactStore.createIndex('phone', 'phone_e164');
        }
        
        // Media
        if (!db.objectStoreNames.contains('media')) {
          const mediaStore = db.createObjectStore('media', { keyPath: 'id' });
          mediaStore.createIndex('wa_media_key', 'wa_media_key', { unique: true });
          mediaStore.createIndex('msg_id', 'msg_id');
        }
        
        // Session
        if (!db.objectStoreNames.contains('session')) {
          db.createObjectStore('session', { keyPath: 'id' });
        }
        
        // UI State
        if (!db.objectStoreNames.contains('ui_state')) {
          db.createObjectStore('ui_state', { keyPath: 'key' });
        }
        
        // Outbound queue (local mirror)
        if (!db.objectStoreNames.contains('outbound')) {
          const outStore = db.createObjectStore('outbound', { keyPath: 'id' });
          outStore.createIndex('status', 'status');
        }
      };
    });
  }
  
  async loadFromIndexedDB() {
    if (!this.db) return;
    
    const tx = this.db.transaction(['chats', 'messages', 'contacts', 'media', 'session', 'ui_state'], 'readonly');
    
    // Chats
    const chats = await this.getAll(tx.objectStore('chats'));
    chats.forEach(c => this.chats.set(c.id, c));
    
    // Messages (only recent - last 50 per chat)
    const messages = await this.getAll(tx.objectStore('messages'));
    messages.forEach(m => {
      if (!this.messages.has(m.chat_id)) {
        this.messages.set(m.chat_id, new Map());
      }
      this.messages.get(m.chat_id).set(m.id, m);
    });
    
    // Contacts
    const contacts = await this.getAll(tx.objectStore('contacts'));
    contacts.forEach(c => this.contacts.set(c.wa_id, c));
    
    // Media
    const media = await this.getAll(tx.objectStore('media'));
    media.forEach(m => this.media.set(m.wa_media_key, m));
    
    // Session
    const session = await this.get(tx.objectStore('session'), 1);
    if (session) this.session = session;
    
    // UI State
    const uiState = await this.getAll(tx.objectStore('ui_state'));
    uiState.forEach(s => {
      if (s.key === 'activeChatId') this.activeChatId = s.value;
      if (s.key === 'darkMode') this.isDarkMode = s.value;
      if (s.key === 'sidebarCollapsed') this.isSidebarCollapsed = s.value;
    });
    
    return tx.done;
  }
  
  getAll(store) {
    return new Promise((resolve, reject) => {
      const request = store.getAll();
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  }
  
  get(store, key) {
    return new Promise((resolve, reject) => {
      const request = store.get(key);
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  }
  
  async put(storeName, data) {
    if (!this.db) return;
    return new Promise((resolve, reject) => {
      const tx = this.db.transaction([storeName], 'readwrite');
      const store = tx.objectStore(storeName);
      const request = store.put(data);
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  }
  
  async delete(storeName, key) {
    if (!this.db) return;
    return new Promise((resolve, reject) => {
      const tx = this.db.transaction([storeName], 'readwrite');
      const store = tx.objectStore(storeName);
      const request = store.delete(key);
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  }
  
  async clearStore(storeName) {
    if (!this.db) return;
    return new Promise((resolve, reject) => {
      const tx = this.db.transaction([storeName], 'readwrite');
      const store = tx.objectStore(storeName);
      const request = store.clear();
      request.onsuccess = () => resolve();
      request.onerror = () => reject(request.error);
    });
  }
  
  // ============ PERSISTENCE ============
  
  async saveChat(chat) {
    this.chats.set(chat.id, chat);
    await this.put('chats', chat);
    this.updateUnreadTotal();
    this.dispatchEvent(new CustomEvent('chats:updated', { detail: chat }));
  }
  
  async saveChats(chats) {
    for (const chat of chats) {
      this.chats.set(chat.id, chat);
      await this.put('chats', chat);
    }
    this.updateUnreadTotal();
    this.dispatchEvent(new CustomEvent('chats:bulk-updated', { detail: chats }));
  }
  
  async saveMessage(message) {
    if (!this.messages.has(message.chat_id)) {
      this.messages.set(message.chat_id, new Map());
    }
    this.messages.get(message.chat_id).set(message.id, message);
    await this.put('messages', message);
    
    // Update chat's last message
    const chat = this.chats.get(message.chat_id);
    if (chat) {
      chat.last_message_id = message.id;
      chat.last_activity_at = new Date((message.timestamp || Math.floor(Date.now() / 1000)) * 1000).toISOString();
      if (!message.from_me && message.status !== 'read') {
        chat.unread_count = (chat.unread_count || 0) + 1;
      }
      await this.saveChat(chat);
    }
    
    this.dispatchEvent(new CustomEvent('message:new', { detail: message }));
  }
  
  async saveMessages(messages) {
    for (const msg of messages) {
      await this.saveMessage(msg);
    }
  }
  
  async updateMessage(msgId, updates) {
    for (const [chatId, msgMap] of this.messages) {
      if (msgMap.has(msgId)) {
        const msg = { ...msgMap.get(msgId), ...updates };
        msgMap.set(msgId, msg);
        await this.put('messages', msg);
        this.dispatchEvent(new CustomEvent('message:updated', { detail: msg }));
        return msg;
      }
    }
    return null;
  }
  
  async saveContact(contact) {
    this.contacts.set(contact.wa_id, contact);
    await this.put('contacts', contact);
    this.dispatchEvent(new CustomEvent('contact:updated', { detail: contact }));
  }
  
  async saveMedia(media) {
    this.media.set(media.wa_media_key, media);
    await this.put('media', media);
  }
  
  async saveSession(session) {
    this.session = session;
    await this.put('session', { id: 1, ...session });
    this.dispatchEvent(new CustomEvent('session:updated', { detail: session }));
  }
  
  async saveUIState(key, value) {
    await this.put('ui_state', { key, value });
  }
  
  // ============ API COMMUNICATION ============

  /**
   * Dynamic base URL resolver for HCC admin endpoints.
   */
  getBaseUrl() {
    if (typeof window !== 'undefined' && window.HCC_API_BASE_URL) return window.HCC_API_BASE_URL;
    const path = (typeof window !== 'undefined' && window.location && window.location.pathname) ? window.location.pathname : '';
    const idx = path.indexOf('/admin');
    if (idx !== -1) return path.substring(0, idx) + '/admin';
    return '/hcc/admin';
  }
  
  async apiGet(endpoint, params = {}) {
    const origin = (typeof window !== 'undefined' && window.location && window.location.origin) ? window.location.origin : 'http://localhost';
    const url = new URL(endpoint, origin);
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null) {
        url.searchParams.append(k, v);
      }
    });
    
    const response = await fetch(url, {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    });
    
    if (!response.ok) {
      throw new Error(`API ${response.status}: ${response.statusText}`);
    }
    
    return response.json();
  }
  
  async apiPost(endpoint, data = {}) {
    const origin = (typeof window !== 'undefined' && window.location && window.location.origin) ? window.location.origin : 'http://localhost';
    const url = new URL(endpoint, origin);
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': this.getCSRFToken()
      },
      credentials: 'same-origin',
      body: JSON.stringify(data)
    });
    
    if (!response.ok) {
      throw new Error(`API ${response.status}: ${response.statusText}`);
    }
    
    return response.json();
  }
  
  getCSRFToken() {
    if (typeof document === 'undefined') return '';
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
  }
  
  // ============ CHATS ============
  
  async loadChats(reset = false) {
    if (this.loadingChats) return;
    this.loadingChats = true;
    
    if (reset) {
      this.chatPage = 1;
      this.hasMoreChats = true;
    }
    
    if (!this.hasMoreChats && !reset) {
      this.loadingChats = false;
      return;
    }
    
    try {
      const params = {
        page: this.chatPage,
        per_page: 50,
        filter: this.filter,
        search: this.searchQuery,
        archived: this.showArchived
      };
      
      const response = await this.apiGet(`${this.getBaseUrl()}/api_whatsapp_chats.php`, params);
      
      if (response && response.success) {
        if (reset || this.chatPage === 1) {
          // Clear old chats not in new batch (for filter changes)
          const newIds = new Set(response.data.map(c => c.id));
          for (const [id, chat] of this.chats) {
            if (!newIds.has(id) && !chat.is_pinned) {
              this.chats.delete(id);
              await this.delete('chats', id);
            }
          }
        }
        
        await this.saveChats(response.data);
        
        this.chatPage = response.pagination?.page || response.pagination?.current_page || 1;
        this.hasMoreChats = response.pagination ? (response.pagination.has_more ?? (response.pagination.page < response.pagination.total_pages)) : false;
        
        this.dispatchEvent(new CustomEvent('chats:loaded', { 
          detail: { chats: response.data, pagination: response.pagination } 
        }));
      }
    } catch (error) {
      console.error('Failed to load chats:', error);
      this.dispatchEvent(new CustomEvent('error', { detail: { action: 'loadChats', error } }));
    } finally {
      this.loadingChats = false;
    }
  }
  
  async loadMoreChats() {
    if (!this.loadingChats && this.hasMoreChats) {
      this.chatPage++;
      await this.loadChats();
    }
  }
  
  async searchChats(query) {
    this.searchQuery = query;
    await this.loadChats(true);
  }

  /**
   * Create or retrieve a chat session (individual student, custom phone, group, or broadcast)
   */
  async getOrCreateChat(chatData = {}) {
    try {
      const response = await this.apiPost(`${this.getBaseUrl()}/api_whatsapp_chats.php`, chatData);
      if (response && response.success && (response.chat || response.data)) {
        const chat = response.chat || response.data;
        this.chats.set(chat.id, chat);
        await this.saveChat(chat);
        
        // Dispatch store events
        this.dispatchEvent(new CustomEvent('chat:created', { detail: { chat } }));
        this.dispatchEvent(new CustomEvent('chats:updated', { detail: { chats: Array.from(this.chats.values()) } }));
        
        // Immediately activate this chat
        this.setActiveChat(chat.id);
        return chat;
      } else {
        throw new Error(response?.error || 'Failed to start chat');
      }
    } catch (error) {
      console.warn('Backend getOrCreateChat notice, falling back to local chat session:', error);
      const tempId = Date.now();
      const phoneDigits = String(chatData.phone || chatData.wa_chat_id || '9876543210').replace(/\D/g, '');
      const cleanWaId = phoneDigits.includes('@') ? phoneDigits : (phoneDigits + '@c.us');
      const fallbackChat = {
        id: tempId,
        wa_chat_id: cleanWaId,
        chat_type: chatData.chat_type || 'individual',
        name: chatData.name || ('Contact ' + (phoneDigits.length >= 4 ? phoneDigits.slice(-4) : phoneDigits)),
        avatar_url: chatData.avatar_url || `https://api.dicebear.com/7.x/initials/svg?seed=${encodeURIComponent(chatData.name || 'Chat')}`,
        unread_count: 0,
        is_pinned: 0,
        is_archived: 0,
        last_activity_at: new Date().toISOString(),
        linked_student_roll: chatData.student_roll || null,
        linked_class_key: chatData.class_key || null,
        metadata: chatData.metadata || {}
      };
      this.chats.set(tempId, fallbackChat);
      await this.saveChat(fallbackChat);
      this.dispatchEvent(new CustomEvent('chat:created', { detail: { chat: fallbackChat } }));
      this.dispatchEvent(new CustomEvent('chats:updated', { detail: { chats: Array.from(this.chats.values()) } }));
      this.setActiveChat(tempId);
      return fallbackChat;
    }
  }
  
  async setFilter(filter) {
    this.filter = filter;
    await this.loadChats(true);
  }
  
  async toggleArchived() {
    this.showArchived = !this.showArchived;
    await this.loadChats(true);
  }
  
  getChat(chatId) {
    return this.chats.get(chatId);
  }
  
  getChats() {
    // Return sorted: pinned first, then by last_activity_at desc
    return Array.from(this.chats.values()).sort((a, b) => {
      if (a.is_pinned && !b.is_pinned) return -1;
      if (!a.is_pinned && b.is_pinned) return 1;
      return new Date(b.last_activity_at || 0) - new Date(a.last_activity_at || 0);
    });
  }
  
  getFilteredChats() {
    let chats = this.getChats();
    
    if (this.filter === 'unread') {
      chats = chats.filter(c => c.unread_count > 0);
    } else if (this.filter === 'groups') {
      chats = chats.filter(c => c.chat_type === 'group' || c.chat_type === 'broadcast');
    } else if (this.filter === 'pinned') {
      chats = chats.filter(c => c.is_pinned);
    }
    
    if (this.searchQuery) {
      const q = this.searchQuery.toLowerCase();
      chats = chats.filter(c => 
        c.name?.toLowerCase().includes(q) || 
        c.wa_chat_id?.toLowerCase().includes(q)
      );
    }
    
    if (!this.showArchived) {
      chats = chats.filter(c => !c.is_archived);
    }
    
    return chats;
  }
  
  // ============ MESSAGES ============
  
  async loadMessages(chatId, reset = false) {
    if (this.loadingMessages.has(chatId)) return;
    this.loadingMessages.add(chatId);
    
    const page = reset ? 1 : (this.messagePages.get(chatId) || 1);
    const hasMore = this.hasMoreMessages.get(chatId) !== false;
    
    if (!hasMore && !reset) {
      this.loadingMessages.delete(chatId);
      return;
    }
    
    try {
      const params = {
        chat_id: chatId,
        page: page,
        per_page: 50
      };
      
      if (!reset) {
        // Get oldest message ID for pagination
        const chatMessages = this.messages.get(chatId);
        if (chatMessages && chatMessages.size > 0) {
          const oldest = Math.min(...Array.from(chatMessages.keys()));
          params.before_msg_id = oldest;
        }
      }
      
      const response = await this.apiGet(`${this.getBaseUrl()}/api_whatsapp_messages.php`, params);
      
      if (response && response.success) {
        const msgMap = this.messages.get(chatId) || new Map();
        
        if (reset) {
          msgMap.clear();
        }
        
        for (const msg of response.data) {
          msgMap.set(msg.id, msg);
        }
        
        this.messages.set(chatId, msgMap);
        this.messagePages.set(chatId, response.pagination?.current_page || 1);
        this.hasMoreMessages.set(chatId, response.pagination ? (response.pagination.current_page < response.pagination.total_pages) : false);
        
        this.dispatchEvent(new CustomEvent('messages:loaded', { 
          detail: { chatId, messages: response.data, pagination: response.pagination } 
        }));
      }
    } catch (error) {
      console.error('Failed to load messages:', error);
    } finally {
      this.loadingMessages.delete(chatId);
    }
  }
  
  async loadMoreMessages(chatId) {
    if (!this.loadingMessages.has(chatId) && this.hasMoreMessages.get(chatId)) {
      await this.loadMessages(chatId);
    }
  }
  
  getMessages(chatId) {
    const msgMap = this.messages.get(chatId);
    if (!msgMap) return [];
    return Array.from(msgMap.values()).sort((a, b) => a.timestamp - b.timestamp);
  }
  
  // ============ SEND MESSAGE & SIMULATION ============
  
  /**
   * Send message with optimistic store insertion, audio feedback, life-cycle state transitions,
   * and simulated parent reply loop.
   */
  async sendMessage(chatId, data) {
    const { msg_type = 'text', body = '', media_path = null, caption = null, priority = 5 } = (data || {});
    
    // 1. Immediately create optimistic message in store/IndexedDB with status 'pending' (🕐)
    const tempMsgId = 'temp_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    const chat = this.chats.get(chatId) || { id: chatId, name: 'Student' };
    const optimisticMsg = {
      id: tempMsgId,
      wa_msg_id: tempMsgId,
      chat_id: chatId,
      from_me: true,
      sender_id: 'me',
      sender_name: 'You',
      sender_avatar: '',
      msg_type,
      body: msg_type === 'text' ? body : (caption || body),
      media_url: media_path,
      media_mime: media_path ? this.guessMime(media_path) : null,
      caption,
      status: 'pending',
      timestamp: Math.floor(Date.now() / 1000),
      metadata: { temp: true, optimistic: true }
    };
    
    // Add to local store and IndexedDB immediately
    await this.saveMessage(optimisticMsg);

    // 2. Play the outgoing message sound via WhatsAppAudio.playSentSound()
    try {
      if (typeof window !== 'undefined' && window.WhatsAppAudio) {
        window.WhatsAppAudio.playSentSound();
      }
    } catch (soundErr) {
      console.warn('WhatsAppAudio.playSentSound error:', soundErr);
    }
    
    // 3. Call server send endpoint
    let sendResult = null;
    try {
      sendResult = await this.apiPost(`${this.getBaseUrl()}/api_whatsapp_send.php`, {
        chat_id: chatId,
        msg_type,
        body,
        media_path,
        caption,
        priority
      });
      
      if (sendResult && sendResult.success) {
        await this.updateMessage(tempMsgId, { 
          metadata: { ...optimisticMsg.metadata, queue_id: sendResult.queue_id, temp: true }
        });
      }
    } catch (error) {
      console.warn('apiPost /api_whatsapp_send.php error:', error);
      // Keep optimistic message and simulate delivery for test/offline demo
    }

    // 4. Simulate life-cycle progression:
    // Transition status to 'sent' (✓) after 800ms
    setTimeout(async () => {
      try {
        await this.updateMessage(tempMsgId, { status: 'sent' });
      } catch (e) {}
    }, 800);

    // Transition status to 'delivered' (✓✓) after 1600ms
    setTimeout(async () => {
      try {
        await this.updateMessage(tempMsgId, { status: 'delivered' });
      } catch (e) {}
    }, 1600);

    // Transition status to 'read' (✓✓ blue) after 2400ms
    setTimeout(async () => {
      try {
        await this.updateMessage(tempMsgId, { status: 'read' });
      } catch (e) {}
    }, 2400);

    // 5. Trigger simulated parent reply after 3000ms:
    // - Emit typing:update with { chatId, isTyping: true }
    // - After 2000ms of typing, call apiPost(api_whatsapp_parent_simulator.php)
    // - Add incoming message to store, emit message:received, play incoming chime, trigger desktop notification
    setTimeout(() => {
      this.dispatchEvent(new CustomEvent('typing:update', {
        detail: { chatId, isTyping: true }
      }));

      setTimeout(async () => {
        // Typing finished
        this.dispatchEvent(new CustomEvent('typing:update', {
          detail: { chatId, isTyping: false }
        }));

        let replyMsg = null;
        try {
          const simResponse = await this.apiPost(`${this.getBaseUrl()}/api_whatsapp_parent_simulator.php`, {
            chat_id: chatId,
            student_name: chat.name || 'Student'
          });
          if (simResponse && simResponse.success && simResponse.message) {
            replyMsg = simResponse.message;
          }
        } catch (simErr) {
          console.warn('apiPost parent simulator error, falling back to simulated reply:', simErr);
        }

        if (!replyMsg) {
          const simMsgId = 'sim_' + Date.now() + '_' + Math.random().toString(36).substr(2, 7);
          replyMsg = {
            id: simMsgId,
            wa_msg_id: simMsgId,
            chat_id: chatId,
            from_me: false,
            sender_id: chat.wa_chat_id || ('parent_' + chatId),
            sender_name: chat.name || 'Parent',
            sender_avatar: chat.avatar_url || '',
            msg_type: 'text',
            body: 'Thank you for the update! We have noted this and will check with the student.',
            media_url: null,
            media_mime: null,
            caption: null,
            status: 'read',
            timestamp: Math.floor(Date.now() / 1000),
            metadata: { simulated: true }
          };
        }

        // Add incoming message to store (and IndexedDB)
        await this.saveMessage(replyMsg);

        // Emit message:received event
        this.dispatchEvent(new CustomEvent('message:received', { detail: replyMsg }));

        // Play incoming chime via WhatsAppAudio.playReceivedSound()
        try {
          if (typeof window !== 'undefined' && window.WhatsAppAudio) {
            window.WhatsAppAudio.playReceivedSound();
          }
        } catch (audioErr) {
          console.warn('WhatsAppAudio.playReceivedSound error:', audioErr);
        }

        // Trigger desktop / in-app notification via WhatsAppNotifications.show()
        try {
          if (typeof window !== 'undefined' && window.WhatsAppNotifications) {
            window.WhatsAppNotifications.show(chat.name || 'WhatsApp', replyMsg.body, {
              chatId: chatId,
              avatar: chat.avatar_url || ''
            });
          }
        } catch (notifErr) {
          console.warn('WhatsAppNotifications.show error:', notifErr);
        }
      }, 2000);
    }, 3000);

    return sendResult || { success: true, temp_msg_id: tempMsgId };
  }
  
  guessMime(path) {
    if (!path) return 'application/octet-stream';
    const ext = path.split('.').pop().toLowerCase();
    const mimeMap = {
      jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', gif: 'image/gif',
      mp4: 'video/mp4', mov: 'video/quicktime',
      mp3: 'audio/mpeg', ogg: 'audio/ogg', m4a: 'audio/mp4',
      pdf: 'application/pdf', doc: 'application/msword', docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    };
    return mimeMap[ext] || 'application/octet-stream';
  }
  
  // ============ CHAT ACTIONS & STATE METHODS ============
  
  async chatAction(chatId, action, params = {}) {
    try {
      const response = await this.apiPost(`${this.getBaseUrl()}/api_whatsapp_chat_actions.php`, {
        chat_id: chatId,
        action,
        ...params
      });
      
      if (response && response.success) {
        // Update local chat
        const chat = this.chats.get(chatId);
        if (chat) {
          switch (action) {
            case 'pin':
              chat.is_pinned = params.pin !== false;
              break;
            case 'archive':
              chat.is_archived = params.archive !== false;
              break;
            case 'mute':
              chat.is_muted = params.mute !== false;
              chat.mute_until = params.mute_until || null;
              break;
            case 'mark_read':
              chat.unread_count = 0;
              break;
            case 'clear':
              this.messages.get(chatId)?.clear();
              break;
            case 'delete':
              this.chats.delete(chatId);
              await this.delete('chats', chatId);
              this.messages.delete(chatId);
              break;
          }
          if (action !== 'delete') {
            await this.saveChat(chat);
          }
        }
        
        this.dispatchEvent(new CustomEvent('chat:action', { detail: { chatId, action, response } }));
      }
      
      return response;
    } catch (error) {
      console.error(`Chat action ${action} failed:`, error);
      throw error;
    }
  }

  /**
   * React to a message with an emoji.
   */
  async reactToMessage(msgId, reactionEmoji) {
    let targetChatId = null;
    let targetMsg = null;
    for (const [chatId, msgMap] of this.messages) {
      if (msgMap.has(msgId)) {
        targetMsg = msgMap.get(msgId);
        targetChatId = chatId;
        break;
      }
    }

    const reactions = targetMsg?.reactions ? { ...targetMsg.reactions } : {};
    if (reactionEmoji) {
      reactions['me'] = reactionEmoji;
    } else {
      delete reactions['me'];
    }

    const updated = await this.updateMessage(msgId, {
      reactions,
      reaction: reactionEmoji || null
    });

    try {
      await this.apiPost(`${this.getBaseUrl()}/api_whatsapp_chat_actions.php`, {
        chat_id: targetChatId || this.activeChatId,
        action: 'react',
        msg_id: msgId,
        reaction: reactionEmoji
      });
    } catch (err) {
      console.warn('reactToMessage server action warning:', err);
    }

    this.dispatchEvent(new CustomEvent('message:reacted', {
      detail: { msgId, reaction: reactionEmoji, message: updated }
    }));

    return updated;
  }

  /**
   * Delete a message (locally or for everyone).
   */
  async deleteMessage(msgId, forEveryone = false) {
    let targetChatId = null;
    let targetMsg = null;
    for (const [chatId, msgMap] of this.messages) {
      if (msgMap.has(msgId)) {
        targetMsg = msgMap.get(msgId);
        targetChatId = chatId;
        break;
      }
    }

    if (forEveryone) {
      await this.updateMessage(msgId, {
        msg_type: 'revoked',
        body: 'This message was deleted',
        media_url: null,
        caption: null,
        status: 'read'
      });
    } else {
      if (targetChatId && this.messages.has(targetChatId)) {
        this.messages.get(targetChatId).delete(msgId);
      }
      await this.delete('messages', msgId);
      this.dispatchEvent(new CustomEvent('message:deleted', {
        detail: { msgId, chatId: targetChatId, forEveryone: false }
      }));
    }

    try {
      await this.apiPost(`${this.getBaseUrl()}/api_whatsapp_chat_actions.php`, {
        chat_id: targetChatId || this.activeChatId,
        action: 'delete_message',
        msg_id: msgId,
        for_everyone: forEveryone
      });
    } catch (err) {
      console.warn('deleteMessage server action warning:', err);
    }

    return true;
  }

  /**
   * Star or unstar a message.
   */
  async starMessage(msgId) {
    let targetMsg = null;
    let targetChatId = null;
    for (const [chatId, msgMap] of this.messages) {
      if (msgMap.has(msgId)) {
        targetMsg = msgMap.get(msgId);
        targetChatId = chatId;
        break;
      }
    }

    const newStarred = !targetMsg?.is_starred;
    const updated = await this.updateMessage(msgId, { is_starred: newStarred });

    try {
      await this.apiPost(`${this.getBaseUrl()}/api_whatsapp_chat_actions.php`, {
        chat_id: targetChatId || this.activeChatId,
        action: 'star',
        msg_id: msgId,
        star: newStarred
      });
    } catch (err) {
      console.warn('starMessage server action warning:', err);
    }

    this.dispatchEvent(new CustomEvent('message:starred', {
      detail: { msgId, isStarred: newStarred, message: updated }
    }));

    return updated;
  }

  /**
   * Mark an entire chat as read locally and on server.
   */
  async markChatRead(chatId) {
    const chat = this.chats.get(chatId);
    if (chat) {
      chat.unread_count = 0;
      await this.saveChat(chat);
    }

    const msgMap = this.messages.get(chatId);
    if (msgMap) {
      for (const [msgId, msg] of msgMap) {
        if (!msg.from_me && msg.status !== 'read') {
          msg.status = 'read';
          await this.put('messages', msg);
          this.dispatchEvent(new CustomEvent('message:updated', { detail: msg }));
        }
      }
    }

    this.updateUnreadTotal();

    try {
      await this.apiPost(`${this.getBaseUrl()}/api_whatsapp_chat_actions.php`, {
        chat_id: chatId,
        action: 'mark_read'
      });
    } catch (err) {
      console.warn('markChatRead server action warning:', err);
    }

    this.dispatchEvent(new CustomEvent('chat:read', { detail: { chatId } }));
    return true;
  }

  /**
   * Sync class roster calling api_whatsapp_sync_roster.php.
   */
  async syncRoster(classKey) {
    try {
      const response = await this.apiPost(`${this.getBaseUrl()}/api_whatsapp_sync_roster.php`, {
        class_key: classKey
      });

      if (response && response.success) {
        if (response.chats && Array.isArray(response.chats)) {
          await this.saveChats(response.chats);
        } else {
          await this.loadChats(true);
        }
      }

      this.dispatchEvent(new CustomEvent('roster:synced', {
        detail: { classKey, response }
      }));

      return response;
    } catch (error) {
      console.error('Failed to sync roster:', error);
      this.dispatchEvent(new CustomEvent('roster:sync_failed', {
        detail: { classKey, error: error.message }
      }));
      throw error;
    }
  }
  
  // ============ CONTACTS ============
  
  async loadContacts(search = '', page = 1) {
    try {
      const response = await this.apiGet(`${this.getBaseUrl()}/api_whatsapp_contacts.php`, { search, page, per_page: 50 });
      if (response && response.success) {
        for (const contact of response.data) {
          this.contacts.set(contact.wa_id, contact);
          await this.saveContact(contact);
        }
        return response;
      }
    } catch (error) {
      console.error('Failed to load contacts:', error);
    }
  }
  
  getContact(waId) {
    return this.contacts.get(waId);
  }

  async searchContacts(search = '') {
    try {
      const response = await this.apiGet(`${this.getBaseUrl()}/api_whatsapp_contacts.php`, { search, per_page: 50 });
      if (response && response.success && Array.isArray(response.data)) {
        for (const contact of response.data) {
          this.contacts.set(contact.wa_id, contact);
          await this.saveContact(contact);
        }
        return response.data;
      }
    } catch (error) {
      console.warn('Failed to search contacts from server:', error);
    }
    // Search local store contacts Map & roster fallback
    const q = (search || '').toLowerCase();
    const local = Array.from(this.contacts.values()).filter(c => {
      const n = (c.name || '').toLowerCase();
      const p = (c.phone_e164 || c.wa_id || '').toLowerCase();
      const r = String(c.linked_student_roll || '').toLowerCase();
      return n.includes(q) || p.includes(q) || r.includes(q);
    });
    return local;
  }
  
  // ============ SESSION ============
  
  async loadSession() {
    try {
      const response = await this.apiGet(`${this.getBaseUrl()}/api_whatsapp_session.php`, { action: 'status' });
      if (response && response.success && response.session) {
        await this.saveSession(response.session);
        return response.session;
      }
    } catch (error) {
      console.error('Failed to load session:', error);
    }
    return null;
  }
  
  async getQR() {
    try {
      const response = await this.apiGet(`${this.getBaseUrl()}/api_whatsapp_session.php`, { action: 'qr' });
      if (response && response.success) {
        return response.qr_code;
      }
    } catch (error) {
      console.error('Failed to get QR:', error);
    }
    return null;
  }
  
  async refreshQR() {
    try {
      const response = await this.apiPost(`${this.getBaseUrl()}/api_whatsapp_session.php`, { action: 'refresh_qr' });
      return !!(response && response.success);
    } catch (error) {
      console.error('Failed to refresh QR:', error);
      return false;
    }
  }
  
  async logout() {
    try {
      const response = await this.apiPost(`${this.getBaseUrl()}/api_whatsapp_session.php`, { action: 'logout' });
      if (response && response.success) {
        this.isOnline = false;
        this.session = null;
        await this.saveSession({ is_connected: false });
        this.dispatchEvent(new CustomEvent('session:disconnected'));
      }
      return !!(response && response.success);
    } catch (error) {
      console.error('Logout failed:', error);
      return false;
    }
  }
  
  // ============ SSE ============
  
  connectSSE() {
    if (this.eventSource) {
      this.eventSource.close();
      this.eventSource = null;
    }

    if (this.sseReconnectTimeout) {
      clearTimeout(this.sseReconnectTimeout);
      this.sseReconnectTimeout = null;
    }

    if (typeof EventSource === 'undefined') return;
    
    try {
      this.eventSource = new EventSource(`${this.getBaseUrl()}/api_whatsapp_events.php`);
      
      this.eventSource.onopen = () => {
        this.sseReconnectAttempts = 0;
        this.isOnline = true;
        this.dispatchEvent(new CustomEvent('sse:connected'));
      };
      
      this.eventSource.addEventListener('message', (event) => {
        try {
          const data = JSON.parse(event.data);
          this.handleSSEMessage('message', data);
        } catch (e) {}
      });
      
      this.eventSource.addEventListener('chat_update', (event) => {
        try {
          const data = JSON.parse(event.data);
          this.handleSSEMessage('chat_update', data);
        } catch (e) {}
      });

      this.eventSource.addEventListener('typing', (event) => {
        try {
          const data = JSON.parse(event.data);
          this.dispatchEvent(new CustomEvent('typing:update', { detail: data }));
        } catch (e) {}
      });
      
      this.eventSource.addEventListener('connected', (event) => {
        try {
          const data = JSON.parse(event.data);
          this.dispatchEvent(new CustomEvent('sse:connected', { detail: data }));
        } catch (e) {}
      });
      
      this.eventSource.addEventListener('disconnect', (event) => {
        try {
          const data = JSON.parse(event.data);
          this.dispatchEvent(new CustomEvent('sse:disconnect', { detail: data }));
        } catch (e) {}
        this.scheduleSSEReconnect();
      });
      
      this.eventSource.onerror = (error) => {
        this.dispatchEvent(new CustomEvent('sse:error', { detail: error }));
        if (this.eventSource) {
          this.eventSource.close();
          this.eventSource = null;
        }
        this.scheduleSSEReconnect();
      };
    } catch (err) {
      console.warn('connectSSE failed:', err);
      this.scheduleSSEReconnect();
    }
  }
  
  scheduleSSEReconnect() {
    if (this.sseReconnectTimeout) {
      clearTimeout(this.sseReconnectTimeout);
      this.sseReconnectTimeout = null;
    }

    if (this.sseReconnectAttempts >= this.maxSseReconnectAttempts) {
      console.warn('Max SSE reconnect attempts reached; retrying in 60s');
      this.sseReconnectTimeout = setTimeout(() => {
        this.sseReconnectAttempts = 0;
        this.connectSSE();
      }, 60000);
      return;
    }
    
    this.sseReconnectAttempts++;
    const delay = Math.min(1000 * Math.pow(2, this.sseReconnectAttempts), 30000);
    
    this.sseReconnectTimeout = setTimeout(() => {
      this.connectSSE();
    }, delay);
  }
  
  handleSSEMessage(type, data) {
    switch (type) {
      case 'message':
        this.handleIncomingMessage(data);
        break;
      case 'chat_update':
        this.handleChatUpdate(data);
        break;
    }
  }
  
  async handleIncomingMessage(msg) {
    // Check if already have this message
    const chatMessages = this.messages.get(msg.chat_id);
    if (chatMessages && chatMessages.has(msg.id)) {
      return; // Already have it
    }
    
    // Check if it's an update to our optimistic message
    if (msg.wa_msg_id && msg.wa_msg_id.startsWith('temp_')) {
      for (const [chatId, msgMap] of this.messages) {
        for (const [localId, localMsg] of msgMap) {
          if (localMsg.wa_msg_id === msg.wa_msg_id || 
              (localMsg.metadata?.queue_id && localMsg.metadata.queue_id === msg.queue_id)) {
            await this.updateMessage(localId, { 
              wa_msg_id: msg.wa_msg_id, 
              status: msg.status,
              metadata: { ...localMsg.metadata, temp: false }
            });
            return;
          }
        }
      }
    }
    
    // New message
    await this.saveMessage(msg);
    
    // If incoming message from external contact, trigger audio and notification
    if (!msg.from_me) {
      this.dispatchEvent(new CustomEvent('message:received', { detail: msg }));
      try {
        if (typeof window !== 'undefined' && window.WhatsAppAudio) {
          window.WhatsAppAudio.playReceivedSound();
        }
      } catch (e) {}
      try {
        if (typeof window !== 'undefined' && window.WhatsAppNotifications) {
          const chat = this.chats.get(msg.chat_id);
          window.WhatsAppNotifications.show(msg.sender_name || chat?.name || 'WhatsApp', msg.body, {
            chatId: msg.chat_id,
            avatar: msg.sender_avatar || chat?.avatar_url || ''
          });
        }
      } catch (e) {}
    }
    
    // If this chat is active, mark as read
    if (this.activeChatId === msg.chat_id && !msg.from_me) {
      await this.chatAction(msg.chat_id, 'mark_read');
    }
  }
  
  async handleChatUpdate(update) {
    const chat = this.chats.get(update.id);
    if (chat) {
      Object.assign(chat, update);
      await this.saveChat(chat);
    }
  }
  
  // ============ OUTBOUND POLLING ============
  
  startOutboundPolling() {
    if (typeof setInterval === 'undefined') return;
    this.outboundPollInterval = setInterval(() => {
      this.processOutboundQueue();
    }, 5000);
  }
  
  async processOutboundQueue() {
    for (const [chatId, msgMap] of this.messages) {
      for (const [msgId, msg] of msgMap) {
        if (msg.status === 'pending' && msg.metadata?.queue_id) {
          try {
            await this.apiGet(`${this.getBaseUrl()}/api_whatsapp_outbound_queue.php`, { 
              status: 'processing',
              limit: 50
            });
          } catch (e) {}
        }
      }
    }
  }
  
  // ============ UTILITIES ============
  
  updateUnreadTotal() {
    this.unreadTotal = Array.from(this.chats.values())
      .filter(c => !c.is_archived)
      .reduce((sum, c) => sum + (c.unread_count || 0), 0);
    this.dispatchEvent(new CustomEvent('unread:updated', { detail: this.unreadTotal }));
  }
  
  applyTheme() {
    if (typeof document === 'undefined' || !document.documentElement) return;
    document.documentElement.classList.toggle('wa-dark', this.isDarkMode);
    document.documentElement.classList.toggle('wa-light', !this.isDarkMode);
  }
  
  toggleDarkMode() {
    this.isDarkMode = !this.isDarkMode;
    this.saveUIState('darkMode', this.isDarkMode);
    this.applyTheme();
    this.dispatchEvent(new CustomEvent('theme:changed', { detail: this.isDarkMode }));
  }
  
  toggleSidebar() {
    this.isSidebarCollapsed = !this.isSidebarCollapsed;
    this.saveUIState('sidebarCollapsed', this.isSidebarCollapsed);
    this.dispatchEvent(new CustomEvent('sidebar:toggled', { detail: this.isSidebarCollapsed }));
  }
  
  setActiveChat(chatId) {
    this.activeChatId = chatId;
    this.activeChat = this.chats.get(chatId)
      || this.chats.get(parseInt(chatId, 10))
      || this.chats.get(String(chatId))
      || Array.from(this.chats.values()).find(c => c.id == chatId || c.wa_chat_id == chatId);
    
    if (this.activeChat) {
      this.activeChatId = this.activeChat.id;
      this.saveUIState('activeChatId', this.activeChat.id);
      // Mark as read
      if (this.activeChat.unread_count > 0) {
        this.chatAction(this.activeChat.id, 'mark_read');
      }
      // Load messages
      this.loadMessages(this.activeChat.id, true);
    } else {
      this.saveUIState('activeChatId', chatId);
    }
    
    this.dispatchEvent(new CustomEvent('chat:selected', { detail: { chatId: this.activeChatId, chat: this.activeChat } }));
  }
  
  setupEventListeners() {
    if (typeof window === 'undefined' || !window.addEventListener) return;

    window.addEventListener('online', () => {
      this.isOnline = true;
      this.connectSSE();
      this.dispatchEvent(new CustomEvent('network:online'));
    });
    
    window.addEventListener('offline', () => {
      this.isOnline = false;
      this.dispatchEvent(new CustomEvent('network:offline'));
    });
    
    // Listen for theme changes from system
    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
        if (typeof localStorage !== 'undefined' && !localStorage.getItem('wa-theme-overridden')) {
          this.isDarkMode = e.matches;
          this.applyTheme();
        }
      });
    }
  }
  
  // ============ CLEANUP ============
  
  destroy() {
    if (this.eventSource) {
      this.eventSource.close();
      this.eventSource = null;
    }
    if (this.sseReconnectTimeout) {
      clearTimeout(this.sseReconnectTimeout);
      this.sseReconnectTimeout = null;
    }
    if (this.outboundPollInterval) {
      clearInterval(this.outboundPollInterval);
      this.outboundPollInterval = null;
    }
    if (this.db) {
      this.db.close();
      this.db = null;
    }
  }
}

// Auto-load WhatsAppAudio & WhatsAppNotifications if not yet present in DOM
if (typeof window !== 'undefined' && typeof document !== 'undefined' && document.head) {
  if (!window.WhatsAppAudio || !window.WhatsAppNotifications) {
    const audioScript = document.createElement('script');
    const basePath = (window.location && window.location.pathname && window.location.pathname.includes('/hcc/')) ? '/hcc/assets/js/' : 'assets/js/';
    audioScript.src = basePath + 'whatsapp-audio-notifications.js';
    document.head.appendChild(audioScript);
  }
}

// Export singleton & auto-init
if (typeof window !== 'undefined') {
  window.WhatsAppStore = window.WhatsAppStore || new WhatsAppStore();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      window.WhatsAppStore.init().catch(err => console.warn('Store auto-init error:', err));
    });
  } else {
    window.WhatsAppStore.init().catch(err => console.warn('Store auto-init error:', err));
  }
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = { WhatsAppStore };
}
