/**
 * HCC WhatsApp Bridge — Full Puppeteer Integration
 * Handles: QR login, session persistence, send/receive messages, media download,
 * contact/chat sync, typing indicators, presence, real-time event emission
 */

require('dotenv').config({ path: __dirname + '/.env' });
const puppeteer = require('puppeteer');
const axios = require('axios');
const fs = require('fs').promises;
const path = require('path');
const EventEmitter = require('events');
const FormData = require('form-data');
const mime = require('mime-types');

class WhatsAppBridge extends EventEmitter {
  constructor(config = {}) {
    super();
    this.config = {
      // HCC API
      apiBaseUrl: config.apiBaseUrl || process.env.API_BASE_URL || 'http://localhost/hcc/admin',
      bridgeSecret: config.bridgeSecret || process.env.WHATSAPP_BRIDGE_SECRET || 'change-this-secret',
      
      // Browser
      userDataDir: config.userDataDir || process.env.USER_DATA_DIR || path.join(__dirname, '.whatsapp-session'),
      headless: config.headless !== undefined ? config.headless : (process.env.HEADLESS === 'true'),
      executablePath: config.executablePath || process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
      
      // Behavior
      pollInterval: config.pollInterval || parseInt(process.env.POLL_INTERVAL) || 3000,
      messageBatchSize: config.messageBatchSize || 50,
      mediaDownloadDir: config.mediaDownloadDir || path.join(__dirname, '..', 'storage', 'whatsapp', 'media'),
      thumbnailDir: config.thumbnailDir || path.join(__dirname, '..', 'storage', 'whatsapp', 'thumbnails'),
      
      // Session
      sessionName: config.sessionName || 'default',
      qrRefreshInterval: 30000, // 30 seconds
      
      // Logging
      logLevel: config.logLevel || process.env.LOG_LEVEL || 'info'
    };
    
    this.browser = null;
    this.page = null;
    this.isConnected = false;
    this.isPolling = false;
    this.pollInterval = null;
    this.lastMsgTimestamp = 0;
    this.lastMsgId = 0;
    this.currentQR = null;
    this.qrExpiresAt = null;
    this.phoneNumber = null;
    this.deviceInfo = {};
    this.reconnectAttempts = 0;
    this.maxReconnectAttempts = 10;
    this.commandFlags = new Map(); // For QR refresh, logout, etc.
    
    // Ensure directories exist
    this.ensureDirs();
  }
  
  ensureDirs() {
    [this.config.mediaDownloadDir, this.config.thumbnailDir].forEach(dir => {
      if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
      }
    });
  }
  
  log(level, message, data = null) {
    const levels = { debug: 0, info: 1, warn: 2, error: 3 };
    if (levels[level] >= levels[this.config.logLevel]) {
      const timestamp = new Date().toISOString();
      const msg = `[${timestamp}] [${level.toUpperCase()}] ${message}`;
      console.log(msg, data ? JSON.stringify(data) : '');
    }
  }
  
  async init() {
    this.log('info', 'Initializing WhatsApp Bridge...');
    
    try {
      // Launch browser with persistent profile
      this.browser = await puppeteer.launch({
        headless: this.config.headless,
        executablePath: this.config.executablePath,
        userDataDir: this.config.userDataDir,
        args: [
          '--no-sandbox',
          '--disable-setuid-sandbox',
          '--disable-dev-shm-usage',
          '--disable-accelerated-2d-canvas',
          '--no-first-run',
          '--no-zygote',
          '--disable-gpu',
          '--window-size=1280,800',
          '--disable-background-timer-throttling',
          '--disable-backgrounding-occluded-windows',
          '--disable-renderer-backgrounding',
          '--disable-features=TranslateUI',
          '--disable-ipc-flooding-protection'
        ],
        defaultViewport: { width: 1280, height: 800 }
      });
      
      this.browser.on('disconnected', () => this.handleDisconnect());
      
      const pages = await this.browser.pages();
      this.page = pages[0] || await this.browser.newPage();
      
      // Set longer timeouts
      this.page.setDefaultTimeout(60000);
      this.page.setDefaultNavigationTimeout(60000);
      
      // Enable request interception for media downloads
      await this.page.setRequestInterception(true);
      this.page.on('request', this.handleRequest.bind(this));
      
      // Listen for console messages from page
      this.page.on('console', msg => {
        if (msg.type() === 'log' && msg.text().startsWith('[WA_BRIDGE]')) {
          this.log('debug', 'Page log: ' + msg.text().substring(11));
        }
      });
      
      // Navigate to WhatsApp Web
      this.log('info', 'Navigating to WhatsApp Web...');
      await this.page.goto('https://web.whatsapp.com', { waitUntil: 'networkidle2', timeout: 120000 });
      
      // Inject bridge scripts
      await this.injectBridgeScripts();
      
      // Check for existing session
      await this.checkSession();
      
      // Start command flag watcher
      this.startFlagWatcher();
      
      this.log('info', 'Bridge initialized successfully');
      return true;
      
    } catch (error) {
      this.log('error', 'Failed to initialize bridge', error.message);
      throw error;
    }
  }
  
  async injectBridgeScripts() {
    // Inject utility functions into page context
    await this.page.evaluateOnNewDocument(() => {
      window.WABridge = {
        // Extract all visible chats from chat list
        getChats: () => {
          const chats = [];
          const chatElements = document.querySelectorAll('[data-testid="chat-list"] [role="row"], #pane-side [role="listitem"]');
          chatElements.forEach(el => {
            try {
              const nameEl = el.querySelector('[data-testid="cell-frame-title"], .x1iyjqo2, ._amie');
              const msgEl = el.querySelector('[data-testid="cell-frame-subtitle"], .x1iyjqo2 + div, ._amig');
              const timeEl = el.querySelector('[data-testid="cell-frame-time"], .x1iyjqo2 + div + div');
              const unreadEl = el.querySelector('[data-testid="cell-frame-unread"], .x1iyjqo2 ~ span[aria-label]');
              const avatarEl = el.querySelector('img[src*="blob:"], img[src*="https://pps.whatsapp.net"]');
              const pinnedEl = el.querySelector('[data-testid="cell-frame-pinned"], ._ak8l');
              const archivedEl = el.querySelector('[data-testid="cell-frame-archived"]');
              const mutedEl = el.querySelector('[data-testid="cell-frame-muted"], ._ak8m');
              
              const waChatId = el.getAttribute('data-chat-id') || el.getAttribute('id') || '';
              
              if (nameEl) {
                chats.push({
                  wa_chat_id: waChatId,
                  name: nameEl.textContent?.trim() || '',
                  last_message: msgEl?.textContent?.trim() || '',
                  time: timeEl?.textContent?.trim() || '',
                  unread: unreadEl ? parseInt(unreadEl.getAttribute('aria-label')?.match(/\d+/) || ['0'])[0] : 0,
                  avatar: avatarEl?.src || '',
                  is_pinned: !!pinnedEl,
                  is_archived: !!archivedEl,
                  is_muted: !!mutedEl
                });
              }
            } catch (e) {}
          });
          return chats;
        },
        
        // Extract messages from current open chat
        getMessages: (sinceTimestamp = 0) => {
          const messages = [];
          const msgElements = document.querySelectorAll('[data-testid="msg-container"], .message-in, .message-out');
          msgElements.forEach(el => {
            try {
              const msgId = el.getAttribute('data-id') || el.getAttribute('id') || '';
              const isOut = el.classList.contains('message-out') || el.closest('.message-out');
              const textEl = el.querySelector('[data-testid="msg-text"], .selectable-text');
              const mediaEl = el.querySelector('img, video, audio');
              const timeEl = el.querySelector('[data-testid="msg-time"], .copyable-text');
              const quotedEl = el.querySelector('[data-testid="quoted-msg"], .quoted-msg');
              const statusEl = el.querySelector('[data-testid="msg-status"], .msg-status');
              
              const timestamp = timeEl?.getAttribute('data-pre-plain-text')?.match(/\[(\d+:\d+)\].*?(\d+)/) 
                ? parseInt(timeEl.getAttribute('data-pre-plain-text').match(/\d+/g).pop())
                : Math.floor(Date.now() / 1000);
              
              if (msgId && timestamp > sinceTimestamp) {
                messages.push({
                  wa_msg_id: msgId,
                  from_me: !!isOut,
                  msg_type: mediaEl ? (mediaEl.tagName.toLowerCase() === 'img' ? 'image' : 
                           mediaEl.tagName.toLowerCase() === 'video' ? 'video' : 'audio') : 'text',
                  body: textEl?.textContent?.trim() || '',
                  media_url: mediaEl?.src || '',
                  timestamp: timestamp,
                  quoted_wa_msg_id: quotedEl?.getAttribute('data-id') || '',
                  status: statusEl?.getAttribute('aria-label')?.includes('Read') ? 'read' : 
                          statusEl?.getAttribute('aria-label')?.includes('Delivered') ? 'delivered' : 'sent'
                });
              }
            } catch (e) {}
          });
          return messages;
        },
        
        // Get contact info for current chat
        getContactInfo: () => {
          const header = document.querySelector('[data-testid="conversation-header"], header');
          if (!header) return null;
          
          const nameEl = header.querySelector('[data-testid="conversation-header-name"], ._amie');
          const avatarEl = header.querySelector('img[src*="blob:"], img[src*="https://pps.whatsapp.net"]');
          const statusEl = header.querySelector('[data-testid="conversation-header-status"], ._amig');
          
          return {
            name: nameEl?.textContent?.trim() || '',
            avatar: avatarEl?.src || '',
            status: statusEl?.textContent?.trim() || ''
          };
        },
        
        // Send text message
        sendMessage: async (chatId, text) => {
          // Find and click chat
          const chatEl = document.querySelector(`[data-chat-id="${chatId}"]`) || 
                         Array.from(document.querySelectorAll('[role="listitem"]')).find(el => 
                           el.getAttribute('data-chat-id') === chatId || el.textContent.includes(chatId));
          if (chatEl) chatEl.click();
          
          await new Promise(r => setTimeout(r, 500));
          
          // Type message
          const inputEl = document.querySelector('[data-testid="conversation-compose-box-input"], [contenteditable="true"][data-tab="10"]');
          if (!inputEl) return { success: false, error: 'Input not found' };
          
          inputEl.focus();
          inputEl.textContent = text;
          inputEl.dispatchEvent(new Event('input', { bubbles: true }));
          
          await new Promise(r => setTimeout(r, 200));
          
          // Click send
          const sendBtn = document.querySelector('[data-testid="compose-btn-send"], [aria-label="Send"]');
          if (sendBtn) {
            sendBtn.click();
            return { success: true };
          }
          return { success: false, error: 'Send button not found' };
        },
        
        // Get QR code
        getQR: () => {
          const qrCanvas = document.querySelector('canvas[aria-label*="QR"], canvas[data-ref]');
          if (qrCanvas) {
            return qrCanvas.toDataURL('image/png').split(',')[1]; // base64 without prefix
          }
          return null;
        },
        
        // Check if logged in
        isLoggedIn: () => {
          return !!document.querySelector('[data-testid="chat-list"], #pane-side');
        }
      };
    });
    
    this.log('debug', 'Bridge scripts injected');
  }

  async checkSession() {
    this.log('info', 'Checking WhatsApp session...');
    
    // Wait for either QR or chat list
    try {
      await this.page.waitForFunction(() => window.WABridge.isLoggedIn(), { timeout: 10000 });
      this.log('info', 'Existing session found - logged in');
      await this.onLoggedIn();
      return;
    } catch (e) {
      // No session, check for QR
    }
    
    try {
      await this.page.waitForFunction(() => window.WABridge.getQR(), { timeout: 5000 });
      await this.captureQR();
    } catch (e) {
      this.log('warn', 'Neither session nor QR found, waiting...');
      // Wait a bit more
      await new Promise(r => setTimeout(r, 5000));
      await this.checkSession();
    }
  }
  
  async captureQR() {
    const qrBase64 = await this.page.evaluate(() => window.WABridge.getQR());
    if (qrBase64) {
      this.currentQR = 'data:image/png;base64,' + qrBase64;
      this.qrExpiresAt = new Date(Date.now() + this.config.qrRefreshInterval).toISOString();
      this.log('info', 'QR code captured');
      this.emit('qr', { qr: this.currentQR, expires_at: this.qrExpiresAt });
      await this.saveSessionToAPI();
    }
  }
  
  async onLoggedIn() {
    this.isConnected = true;
    this.reconnectAttempts = 0;
    
    // Get phone number and device info
    const info = await this.page.evaluate(() => {
      const me = window.Store?.Conn?.me || window.Store?.Me?.me;
      return {
        phone: me?.user || '',
        platform: navigator.platform,
        device: navigator.userAgent
      };
    });
    
    this.phoneNumber = info.phone;
    this.deviceInfo = info;
    
    this.log('info', 'Logged in as', this.phoneNumber);
    
    // Save session to API
    await this.saveSessionToAPI();
    
    // Initial sync
    await this.syncChats();
    await this.syncContacts();
    
    // Start polling for messages
    this.startPolling();
    
    this.emit('connected', { phone: this.phoneNumber });
  }
  
  async saveSessionToAPI() {
    try {
      const cookies = await this.page.cookies();
      const localStorage = await this.page.evaluate(() => {
        const obj = {};
        for (let i = 0; i < localStorage.length; i++) {
          const key = localStorage.key(i);
          obj[key] = localStorage.getItem(key);
        }
        return obj;
      });
      
      const data = {
        session_name: this.config.sessionName,
        user_data_dir: this.config.userDataDir,
        qr_code: this.currentQR,
        qr_expires_at: this.qrExpiresAt,
        is_connected: this.isConnected,
        connected_at: this.isConnected ? new Date().toISOString() : null,
        last_poll_at: new Date().toISOString(),
        phone_number: this.phoneNumber,
        device_name: this.deviceInfo.device,
        platform: this.deviceInfo.platform,
        cookies: cookies,
        local_storage: localStorage
      };
      
      await this.apiPost('api_whatsapp_session.php?action=sync', data);
    } catch (e) {
      this.log('warn', 'Failed to save session to API', e.message);
    }
  }
  
  async syncChats() {
    this.log('info', 'Syncing chats...');
    const chats = await this.page.evaluate(() => window.WABridge.getChats());
    
    for (const chat of chats) {
      await this.apiPost('api_whatsapp_ingest.php', [{
        wa_msg_id: 'chat_sync_' + chat.wa_chat_id,
        wa_chat_id: chat.wa_chat_id,
        chat_type: chat.name.includes('@g.us') ? 'group' : 'individual',
        chat_name: chat.name,
        chat_avatar: chat.avatar,
        from_me: false,
        sender_wa_id: chat.wa_chat_id,
        sender_name: chat.name,
        sender_avatar: chat.avatar,
        msg_type: 'system',
        body: '',
        timestamp: Math.floor(Date.now() / 1000),
        metadata: { sync: true, unread: chat.unread, is_pinned: chat.is_pinned, is_archived: chat.is_archived, is_muted: chat.is_muted }
      }]);
    }
    
    this.log('info', `Synced ${chats.length} chats`);
  }
  
  async syncContacts() {
    this.log('info', 'Syncing contacts...');
    // This would require navigating to contacts page
    // For now, contacts are created when messages arrive
  }
  
  startPolling() {
    if (this.isPolling) return;
    this.isPolling = true;
    
    this.log('info', `Starting message polling (interval: ${this.config.pollInterval}ms)`);
    
    this.pollInterval = setInterval(async () => {
      try {
        await this.pollMessages();
        await this.checkCommandFlags();
      } catch (e) {
        this.log('error', 'Polling error', e.message);
      }
    }, this.config.pollInterval);
  }
  
  stopPolling() {
    if (this.pollInterval) {
      clearInterval(this.pollInterval);
      this.pollInterval = null;
    }
    this.isPolling = false;
  }
  
  async pollMessages() {
    if (!this.isConnected) return;
    
    // Get current open chat messages (if any)
    const messages = await this.page.evaluate((since) => window.WABridge.getMessages(since), this.lastMsgTimestamp);
    
    if (messages.length > 0) {
      this.log('debug', `Polled ${messages.length} new messages`);
      
      // Enrich messages with chat info
      const chatInfo = await this.page.evaluate(() => window.WABridge.getContactInfo());
      
      const enriched = messages.map(msg => ({
        ...msg,
        wa_chat_id: chatInfo?.wa_chat_id || '',
        chat_name: chatInfo?.name,
        chat_avatar: chatInfo?.avatar,
        sender_wa_id: msg.from_me ? 'me' : (chatInfo?.wa_id || ''),
        sender_name: msg.from_me ? 'You' : (chatInfo?.name || ''),
        sender_avatar: chatInfo?.avatar
      }));
      
      // Send to API
      await this.apiPost('api_whatsapp_ingest.php', enriched);
      
      // Update last timestamp
      this.lastMsgTimestamp = Math.max(this.lastMsgTimestamp, ...messages.map(m => m.timestamp));
      this.lastMsgId = Math.max(this.lastMsgId, ...messages.map(m => parseInt(m.wa_msg_id) || 0));
    }
    
    // Update last poll time
    await this.apiPost('api_whatsapp_session.php?action=poll', { last_poll_at: new Date().toISOString() });
  }
  
  async checkCommandFlags() {
    // Check for QR refresh request
    const qrFlag = `/tmp/whatsapp_qr_refresh_${this.config.sessionName}`;
    if (await fs.exists(qrFlag)) {
      await fs.unlink(qrFlag);
      await this.refreshQR();
    }
    
    // Check for logout request
    const logoutFlag = `/tmp/whatsapp_logout_${this.config.sessionName}`;
    if (await fs.exists(logoutFlag)) {
      await fs.unlink(logoutFlag);
      await this.logout();
    }
  }
  
  async refreshQR() {
    this.log('info', 'Refreshing QR code...');
    try {
      // Click "Link a device" or refresh QR
      await this.page.evaluate(() => {
        const btn = document.querySelector('[data-testid="link-device-btn"], [aria-label="Link a device"]');
        if (btn) btn.click();
      });
      await new Promise(r => setTimeout(r, 2000));
      await this.captureQR();
    } catch (e) {
      this.log('error', 'Failed to refresh QR', e.message);
    }
  }
  
  async logout() {
    this.log('info', 'Logging out...');
    try {
      await this.page.evaluate(() => {
        const menuBtn = document.querySelector('[data-testid="menu"], [aria-label="Menu"]');
        if (menuBtn) menuBtn.click();
        setTimeout(() => {
          const logoutBtn = document.querySelector('[data-testid="mi-logout"], [aria-label="Log out"]');
          if (logoutBtn) logoutBtn.click();
        }, 500);
      });
      await new Promise(r => setTimeout(r, 2000));
      
      this.isConnected = false;
      this.currentQR = null;
      this.stopPolling();
      await this.saveSessionToAPI();
      this.emit('disconnected');
    } catch (e) {
      this.log('error', 'Logout failed', e.message);
    }
  }
  
  handleDisconnect() {
    this.log('warn', 'Browser disconnected');
    this.isConnected = false;
    this.stopPolling();
    
    if (this.reconnectAttempts < this.maxReconnectAttempts) {
      this.reconnectAttempts++;
      this.log('info', `Reconnecting... (attempt ${this.reconnectAttempts})`);
      setTimeout(() => this.init(), 5000 * this.reconnectAttempts);
    } else {
      this.log('error', 'Max reconnect attempts reached');
      this.emit('failed');
    }
  }

  // ============ OUTBOUND MESSAGING ============
  
  async processOutboundQueue() {
    try {
      const response = await this.apiGet('api_whatsapp_outbound_queue.php', {
        status: 'queued',
        limit: 10
      });
      
      if (response.success && response.data.length > 0) {
        for (const item of response.data) {
          await this.sendQueuedMessage(item);
        }
      }
    } catch (e) {
      this.log('error', 'Failed to process outbound queue', e.message);
    }
  }
  
  async sendQueuedMessage(item) {
    const queueId = item.id;
    this.log('info', `Sending queued message ${queueId} to chat ${item.chat_id}`);
    
    try {
      // Update status to processing
      await this.apiPost('api_whatsapp_outbound_update.php', {
        id: queueId,
        status: 'processing',
        attempts: item.attempts + 1
      });
      
      // Get chat WA ID
      const chatResponse = await this.apiGet('api_whatsapp_chat_by_id.php', { id: item.chat_id });
      if (!chatResponse.success || !chatResponse.data.wa_chat_id) {
        throw new Error('Chat not found');
      }
      const waChatId = chatResponse.data.wa_chat_id;
      
      let result;
      switch (item.msg_type) {
        case 'text':
          result = await this.sendText(waChatId, item.body);
          break;
        case 'image':
        case 'video':
        case 'audio':
        case 'document':
          result = await this.sendMedia(waChatId, item.media_path, item.msg_type, item.caption || item.body);
          break;
        case 'location':
          result = await this.sendLocation(waChatId, item.body); // body contains lat,lng,name,address
          break;
        case 'contact':
          result = await this.sendContact(waChatId, item.body); // body contains vcard
          break;
        default:
          throw new Error(`Unknown message type: ${item.msg_type}`);
      }
      
      if (result.success) {
        // Update queue status
        await this.apiPost('api_whatsapp_outbound_update.php', {
          id: queueId,
          status: 'sent',
          sent_at: new Date().toISOString()
        });
        
        // Update the temporary message with real WA message ID
        await this.apiPost('api_whatsapp_update_temp_msg.php', {
          temp_msg_id: item.temp_msg_id,
          wa_msg_id: result.wa_msg_id,
          status: 'sent'
        });
        
        this.log('info', `Message ${queueId} sent successfully`);
      } else {
        throw new Error(result.error || 'Send failed');
      }
      
    } catch (error) {
      this.log('error', `Failed to send message ${queueId}`, error.message);
      
      const newAttempts = item.attempts + 1;
      const maxAttempts = item.max_attempts || 3;
      
      if (newAttempts >= maxAttempts) {
        await this.apiPost('api_whatsapp_outbound_update.php', {
          id: queueId,
          status: 'failed',
          error_message: error.message
        });
        await this.apiPost('api_whatsapp_update_temp_msg.php', {
          temp_msg_id: item.temp_msg_id,
          status: 'failed'
        });
      } else {
        await this.apiPost('api_whatsapp_outbound_update.php', {
          id: queueId,
          status: 'queued',
          attempts: newAttempts,
          error_message: error.message
        });
      }
    }
  }
  
  async sendText(waChatId, text) {
    // Find and open chat
    await this.openChat(waChatId);
    await new Promise(r => setTimeout(r, 500));
    
    // Type and send
    const result = await this.page.evaluate(async (text) => {
      const inputEl = document.querySelector('[data-testid="conversation-compose-box-input"], [contenteditable="true"][data-tab="10"]');
      if (!inputEl) return { success: false, error: 'Input not found' };
      
      inputEl.focus();
      inputEl.textContent = text;
      inputEl.dispatchEvent(new Event('input', { bubbles: true }));
      
      await new Promise(r => setTimeout(r, 200));
      
      const sendBtn = document.querySelector('[data-testid="compose-btn-send"], [aria-label="Send"]');
      if (sendBtn) {
        sendBtn.click();
        return { success: true };
      }
      return { success: false, error: 'Send button not found' };
    }, text);
    
    if (result.success) {
      // Get the sent message ID
      await new Promise(r => setTimeout(r, 1000));
      const msgId = await this.getLastSentMessageId(waChatId);
      return { success: true, wa_msg_id: msgId };
    }
    
    return result;
  }
  
  async sendMedia(waChatId, filePath, mediaType, caption) {
    await this.openChat(waChatId);
    await new Promise(r => setTimeout(r, 500));
    
    // Click attach button
    await this.page.evaluate(() => {
      const attachBtn = document.querySelector('[data-testid="clip"], [aria-label="Attach"]');
      if (attachBtn) attachBtn.click();
    });
    await new Promise(r => setTimeout(r, 500));
    
    // Select file input
    const fileInput = await this.page.$('input[type="file"][accept*="image"], input[type="file"][accept*="video"], input[type="file"][accept*="audio"], input[type="file"][accept*="document"]');
    if (!fileInput) {
      throw new Error('File input not found');
    }
    
    await fileInput.uploadFile(filePath);
    await new Promise(r => setTimeout(r, 2000)); // Wait for upload
    
    // Add caption if provided
    if (caption) {
      await this.page.evaluate((cap) => {
        const captionEl = document.querySelector('[data-testid="media-caption-input"], [contenteditable="true"][data-tab="10"]');
        if (captionEl) {
          captionEl.focus();
          captionEl.textContent = cap;
          captionEl.dispatchEvent(new Event('input', { bubbles: true }));
        }
      }, caption);
      await new Promise(r => setTimeout(r, 300));
    }
    
    // Send
    const sendBtn = await this.page.$('[data-testid="send"], [aria-label="Send"]');
    if (sendBtn) {
      await sendBtn.click();
      await new Promise(r => setTimeout(r, 2000));
      const msgId = await this.getLastSentMessageId(waChatId);
      return { success: true, wa_msg_id: msgId };
    }
    
    return { success: false, error: 'Send button not found after media' };
  }
  
  async sendLocation(waChatId, locationData) {
    // locationData: "lat,lng,name,address"
    const [lat, lng, name, address] = locationData.split(',');
    await this.openChat(waChatId);
    await new Promise(r => setTimeout(r, 500));
    
    // Click attach -> location
    await this.page.evaluate(() => {
      const attachBtn = document.querySelector('[data-testid="clip"], [aria-label="Attach"]');
      if (attachBtn) attachBtn.click();
    });
    await new Promise(r => setTimeout(r, 300));
    
    await this.page.evaluate(() => {
      const locBtn = document.querySelector('[data-testid="attach-location"], [aria-label="Location"]');
      if (locBtn) locBtn.click();
    });
    await new Promise(r => setTimeout(r, 1000));
    
    // Search and select location
    await this.page.evaluate((data) => {
      const searchInput = document.querySelector('[data-testid="location-search-input"], input[placeholder*="Search"]');
      if (searchInput) {
        searchInput.focus();
        searchInput.value = data.name || `${data.lat},${data.lng}`;
        searchInput.dispatchEvent(new Event('input', { bubbles: true }));
      }
    }, { lat, lng, name, address });
    
    await new Promise(r => setTimeout(r, 2000));
    
    // Click first result
    await this.page.evaluate(() => {
      const result = document.querySelector('[data-testid="location-result"], .location-result');
      if (result) result.click();
    });
    await new Promise(r => setTimeout(r, 1000));
    
    // Send
    const sendBtn = await this.page.$('[data-testid="send"], [aria-label="Send"]');
    if (sendBtn) {
      await sendBtn.click();
      await new Promise(r => setTimeout(r, 1000));
      const msgId = await this.getLastSentMessageId(waChatId);
      return { success: true, wa_msg_id: msgId };
    }
    
    return { success: false, error: 'Failed to send location' };
  }
  
  async sendContact(waChatId, vcard) {
    // Similar to location but for contact
    await this.openChat(waChatId);
    // Implementation would follow similar pattern
    return { success: false, error: 'Contact sending not yet implemented' };
  }
  
  async openChat(waChatId) {
    // Search for chat and click
    await this.page.evaluate(async (chatId) => {
      // Try direct click
      let chatEl = document.querySelector(`[data-chat-id="${chatId}"]`);
      if (chatEl) {
        chatEl.click();
        return true;
      }
      
      // Try search
      const searchBtn = document.querySelector('[data-testid="chat-list-search"], [aria-label="Search"]');
      if (searchBtn) {
        searchBtn.click();
        await new Promise(r => setTimeout(r, 300));
        
        const searchInput = document.querySelector('[data-testid="chat-list-search-input"], input[placeholder*="Search"]');
        if (searchInput) {
          searchInput.focus();
          searchInput.value = chatId.split('@')[0]; // Search by phone
          searchInput.dispatchEvent(new Event('input', { bubbles: true }));
          await new Promise(r => setTimeout(r, 1000));
          
          const result = document.querySelector('[data-testid="chat-list-item"], [role="listitem"]');
          if (result) result.click();
          return true;
        }
      }
      return false;
    }, waChatId);
  }
  
  async getLastSentMessageId(waChatId) {
    // Get the most recent outgoing message ID
    return await this.page.evaluate(() => {
      const msgs = document.querySelectorAll('[data-testid="msg-container"].message-out, .message-out');
      if (msgs.length > 0) {
        const last = msgs[msgs.length - 1];
        return last.getAttribute('data-id') || last.getAttribute('id') || 'unknown_' + Date.now();
      }
      return 'unknown_' + Date.now();
    });
  }
  
  // ============ MEDIA DOWNLOAD ============
  
  async downloadMedia(msg) {
    if (!msg.media_url) return null;
    
    try {
      this.log('debug', 'Downloading media', msg.media_url);
      
      const response = await axios({
        method: 'GET',
        url: msg.media_url,
        responseType: 'stream',
        headers: {
          'User-Agent': 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'
        },
        timeout: 60000
      });
      
      const ext = mime.extension(msg.media_mime || '') || 'bin';
      const filename = `${msg.wa_msg_id}.${ext}`;
      const filepath = path.join(this.config.mediaDownloadDir, filename);
      
      const writer = require('fs').createWriteStream(filepath);
      response.data.pipe(writer);
      
      await new Promise((resolve, reject) => {
        writer.on('finish', resolve);
        writer.on('error', reject);
      });
      
      // Generate thumbnail for images/videos
      let thumbnailPath = null;
      if (msg.media_mime?.startsWith('image/') || msg.media_mime?.startsWith('video/')) {
        thumbnailPath = await this.generateThumbnail(filepath, msg.media_mime);
      }
      
      // Save to API
      await this.apiPost('api_whatsapp_media_save.php', {
        wa_media_key: msg.wa_msg_id,
        msg_id: msg.id,
        local_path: filepath,
        mime_type: msg.media_mime,
        file_size: (await fs.stat(filepath)).size,
        thumbnail_path: thumbnailPath
      });
      
      return { local_path: filepath, thumbnail_path: thumbnailPath };
      
    } catch (e) {
      this.log('error', 'Media download failed', e.message);
      return null;
    }
  }
  
  async generateThumbnail(filepath, mimeType) {
    // Use sharp or ffmpeg for thumbnails
    // For now, return null - would need sharp/ffmpeg installed
    return null;
  }
  
  // ============ API COMMUNICATION ============
  
  async apiPost(endpoint, data) {
    const payload = JSON.stringify(data);
    const signature = require('crypto')
      .createHmac('sha256', this.config.bridgeSecret)
      .update(payload)
      .digest('hex');
    
    return axios.post(`${this.config.apiBaseUrl}/${endpoint}`, data, {
      headers: {
        'Content-Type': 'application/json',
        'X-WA-Signature': signature
      },
      timeout: 30000
    }).then(r => r.data).catch(e => {
      this.log('error', `API POST ${endpoint} failed`, e.message);
      throw e;
    });
  }
  
  async apiGet(endpoint, params = {}) {
    return axios.get(`${this.config.apiBaseUrl}/${endpoint}`, {
      params,
      timeout: 30000
    }).then(r => r.data).catch(e => {
      this.log('error', `API GET ${endpoint} failed`, e.message);
      throw e;
    });
  }
  
  // ============ REQUEST INTERCEPTION ============
  
  handleRequest(request) {
    // Allow all requests by default
    request.continue();
  }
  
  // ============ GRACEFUL SHUTDOWN ============
  
  async shutdown() {
    this.log('info', 'Shutting down bridge...');
    this.stopPolling();
    
    if (this.browser) {
      await this.browser.close();
      this.browser = null;
    }
    
    this.emit('shutdown');
  }
}

// ============ MAIN ENTRY ============

async function main() {
  const bridge = new WhatsAppBridge();
  
  // Handle signals
  process.on('SIGINT', async () => {
    console.log('\nReceived SIGINT, shutting down...');
    await bridge.shutdown();
    process.exit(0);
  });
  
  process.on('SIGTERM', async () => {
    console.log('\nReceived SIGTERM, shutting down...');
    await bridge.shutdown();
    process.exit(0);
  });
  
  try {
    await bridge.init();
    
    // Start outbound queue processor
    setInterval(() => bridge.processOutboundQueue(), 5000);
    
    // Keep alive
    console.log('Bridge running. Press Ctrl+C to stop.');
    
  } catch (error) {
    console.error('Bridge failed to start:', error);
    process.exit(1);
  }
}

if (require.main === module) {
  main();
}

module.exports = WhatsAppBridge;
