/**
 * HCC WhatsApp Bridge — Full Puppeteer Integration
 * Handles: QR login, session persistence, send/receive messages, media download,
 * contact/chat sync, typing indicators, presence, real-time event emission
 */

require('dotenv').config({ path: __dirname + '/.env' });
const fs = require('fs');
const puppeteer = require('puppeteer');
const axios = require('axios');
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
      
      // Privacy Filter
      enablePrivacyFilter: config.enablePrivacyFilter !== undefined ? config.enablePrivacyFilter : (process.env.ENABLE_PRIVACY_FILTER !== 'false'),
      privacyFilterApi: config.privacyFilterApi || process.env.PRIVACY_FILTER_API || 'api_whatsapp_bridge_filter.php',
      filterRefreshInterval: config.filterRefreshInterval || parseInt(process.env.FILTER_REFRESH_INTERVAL) || 300000, // 5 minutes
      
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
    this.lastQRBase64 = null;
    this.sessionWatchTimer = null;
    this.qrExpiresAt = null;
    this.pairingActive = false;
    this.lastQrCaptureAt = 0;
    this.linkingSince = null;
    this.lastLinkError = null;
    this.connectedAt = null;
    this.sessionSyncTimer = null;
    this.phoneNumber = null;
    this.deviceInfo = {};
    this.reconnectAttempts = 0;
    this.maxReconnectAttempts = 10;
    this.commandFlags = new Map(); // For QR refresh, logout, etc.
    
    // Privacy Filter State
    this.privacyFilter = {
      enabled: this.config.enablePrivacyFilter,
      rosterNumbers: new Set(),      // Set of allowed wa_id (e.g., 919876543210@c.us)
      rosterPhones: new Set(),       // Set of allowed phone_e164 (e.g., +919876543210)
      admissionKeywords: [],         // Array of admission-related keywords
      classGroupIds: new Set(),      // Set of allowed group wa_chat_id
      lastRefreshed: 0,
      refreshInterval: this.config.filterRefreshInterval
    };
    this.filterRefreshTimer = null;
    
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

  // ============ PRIVACY FILTER METHODS ============

  async refreshPrivacyFilter() {
    if (!this.privacyFilter.enabled) {
      this.log('debug', 'Privacy filter disabled, skipping refresh');
      return;
    }

    try {
      this.log('info', 'Refreshing privacy filter from API...');
      const response = await this.apiGet(this.config.privacyFilterApi);
      
      if (response.success && response.data) {
        const data = response.data;
        
        // Clear and rebuild roster numbers
        this.privacyFilter.rosterNumbers.clear();
        this.privacyFilter.rosterPhones.clear();
        
        // Add roster numbers (students + teachers)
        if (data.roster_numbers && Array.isArray(data.roster_numbers)) {
          for (const entry of data.roster_numbers) {
            if (entry.wa_id) this.privacyFilter.rosterNumbers.add(entry.wa_id.toLowerCase());
            if (entry.phone_e164) this.privacyFilter.rosterPhones.add(entry.phone_e164.toLowerCase());
          }
        }
        
        // Add linked contacts
        if (data.linked_contacts && Array.isArray(data.linked_contacts)) {
          for (const entry of data.linked_contacts) {
            if (entry.wa_id) this.privacyFilter.rosterNumbers.add(entry.wa_id.toLowerCase());
            if (entry.phone_e164) this.privacyFilter.rosterPhones.add(entry.phone_e164.toLowerCase());
          }
        }
        
        // Add class group IDs
        this.privacyFilter.classGroupIds.clear();
        if (data.class_groups && Array.isArray(data.class_groups)) {
          for (const group of data.class_groups) {
            if (group.wa_chat_id) this.privacyFilter.classGroupIds.add(group.wa_chat_id.toLowerCase());
          }
        }
        
        // Update admission keywords
        if (data.admission_keywords && Array.isArray(data.admission_keywords)) {
          this.privacyFilter.admissionKeywords = data.admission_keywords.map(k => k.toLowerCase());
        }
        
        this.privacyFilter.lastRefreshed = Date.now();
        this.log('info', `Privacy filter refreshed: ${this.privacyFilter.rosterNumbers.size} roster numbers, ${this.privacyFilter.classGroupIds.size} class groups, ${this.privacyFilter.admissionKeywords.length} keywords`);
      } else {
        this.log('warn', 'Failed to refresh privacy filter', response);
      }
    } catch (error) {
      this.log('error', 'Error refreshing privacy filter', error.message);
    }
  }

  startFilterRefreshTimer() {
    if (this.filterRefreshTimer) {
      clearInterval(this.filterRefreshTimer);
    }
    this.filterRefreshTimer = setInterval(() => {
      this.refreshPrivacyFilter();
    }, this.privacyFilter.refreshInterval);
    this.log('debug', `Privacy filter refresh timer started (interval: ${this.privacyFilter.refreshInterval}ms)`);
  }

  stopFilterRefreshTimer() {
    if (this.filterRefreshTimer) {
      clearInterval(this.filterRefreshTimer);
      this.filterRefreshTimer = null;
    }
  }

  isChatAllowed(chat) {
    if (!this.privacyFilter.enabled) {
      return { allowed: true, reason: 'filter_disabled' };
    }

    const waChatId = (chat.wa_chat_id || '').toLowerCase();
    const chatName = (chat.name || '').toLowerCase();
    const chatType = chat.chat_type || 'individual';

    // 1. Always allow class groups (official school groups)
    if (this.privacyFilter.classGroupIds.has(waChatId)) {
      return { allowed: true, reason: 'class_group' };
    }

    // 2. For group chats, check if it's a known class group by name pattern
    if (chatType === 'group' || chatType === 'broadcast') {
      const classGroupPatterns = [
        /^std\s+\d+/i,           // "STD I A", "STD X B"
        /class\s+\d+/i,          // "Class 5A Parents"
        /hcc/i,                  // "HCC Official"
        /parent/i,               // "Parent Group"
        /official/i,             // "Official Group"
        /announcement/i          // "Announcements"
      ];
      
      for (const pattern of classGroupPatterns) {
        if (pattern.test(chatName)) {
          return { allowed: true, reason: 'group_name_pattern' };
        }
      }
      
      // Unknown group - reject
      return { allowed: false, reason: 'unknown_group' };
    }

    // 3. For individual chats, check roster allowlist
    if (chatType === 'individual') {
      // Check wa_chat_id (format: 919876543210@c.us)
      if (this.privacyFilter.rosterNumbers.has(waChatId)) {
        return { allowed: true, reason: 'roster_number' };
      }
      
      // Also check if sender wa_id is in roster (from chat metadata)
      const senderWaId = (chat.sender_wa_id || '').toLowerCase();
      if (senderWaId && this.privacyFilter.rosterNumbers.has(senderWaId)) {
        return { allowed: true, reason: 'roster_sender' };
      }
      
      // Check if chat name matches a known student/parent
      // This is a fallback - not very reliable but helps
      return { allowed: false, reason: 'unknown_individual' };
    }

    // Default: reject unknown chat types
    return { allowed: false, reason: 'unknown_chat_type' };
  }

  isMessageAllowed(message, chatContext = {}) {
    if (!this.privacyFilter.enabled) {
      return { allowed: true, reason: 'filter_disabled' };
    }

    // Outbound messages from admin are always allowed
    if (message.from_me) {
      return { allowed: true, reason: 'outbound' };
    }

    const body = (message.body || '').toLowerCase();
    const senderWaId = (message.sender_wa_id || '').toLowerCase();
    const waChatId = (chatContext.wa_chat_id || message.wa_chat_id || '').toLowerCase();
    const chatType = chatContext.chat_type || 'individual';

    // 1. Check if sender is in roster
    if (senderWaId && this.privacyFilter.rosterNumbers.has(senderWaId)) {
      return { allowed: true, reason: 'roster_sender' };
    }

    // 2. Check if chat is allowed
    const chatCheck = this.isChatAllowed({ wa_chat_id: waChatId, chat_type: chatType, name: chatContext.chat_name || '' });
    if (chatCheck.allowed) {
      return { allowed: true, reason: `chat_allowed_${chatCheck.reason}` };
    }

    // 3. Check for admission keywords in message body (new enquiry detection)
    for (const keyword of this.privacyFilter.admissionKeywords) {
      if (body.includes(keyword)) {
        return { allowed: true, reason: `admission_keyword:${keyword}` };
      }
    }

    // 4. If it's a group message and group is not allowed, reject
    if (chatType === 'group' || chatType === 'broadcast') {
      return { allowed: false, reason: 'unauthorized_group' };
    }

    // 5. Individual chat from unknown number - reject
    return { allowed: false, reason: 'unknown_sender' };
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
      // A crashed/killed Chrome can leave stale Singleton* symlinks behind, which
      // makes every subsequent launch abort with "Failed to create ... SingletonLock".
      this.cleanStaleProfileLock();

      // Once the device has been linked successfully, we no longer need a visible
      // browser window: run headless so this behaves like a silent background service.
      const linkedMarker = path.join(this.config.userDataDir, '.linked');
      const autoHeadless = this.config.headless || fs.existsSync(linkedMarker);
      if (autoHeadless && !this.config.headless) {
        this.log('info', 'Linked-device marker found — starting headless (no window)');
      }

      // Launch browser with persistent profile
      this.browser = await puppeteer.launch({
        headless: autoHeadless,
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
      this.startPairingWatcher();
      
      this.log('info', 'Bridge initialized successfully');
      return true;
      
    } catch (error) {
      this.log('error', 'Failed to initialize bridge', error.message);
      throw error;
    }
  }
  
  async injectBridgeScripts() {
    // Inject utility functions into page context
    const bridgeScript = () => {
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
          const qrCanvas = document.querySelector(
            'canvas[aria-label*="QR"], canvas[aria-label*="Scan"], div[data-ref] canvas, canvas[data-ref], [data-ref] canvas'
          );
          if (qrCanvas) {
            try { return qrCanvas.toDataURL('image/png').split(',')[1]; } catch (e) { return null; }
          }
          // Fallback: pick a square-ish canvas (WhatsApp renders the QR on a canvas)
          const canvases = Array.from(document.querySelectorAll('canvas'));
          for (const c of canvases) {
            if (c.width >= 100 && Math.abs(c.width - c.height) < 20) {
              try { return c.toDataURL('image/png').split(',')[1]; } catch (e) {}
            }
          }
          return null;
        },
        
        // Check if logged in
        isLoggedIn: () => {
          return !!document.querySelector('[data-testid="chat-list"], #pane-side');
        }
      };
    };

    // Register for future navigations AND inject into the already-loaded document
    await this.page.evaluateOnNewDocument(bridgeScript);
    await this.page.evaluate(bridgeScript);

    this.log('debug', 'Bridge scripts injected');
  }

  async checkSession() {
    this.log('info', 'Checking WhatsApp session...');
    
    // Wait for either QR or chat list
    try {
      await this.page.waitForFunction(() => window.WABridge && window.WABridge.isLoggedIn(), { timeout: 10000 });
      this.log('info', 'Existing session found - logged in');
      await this.onLoggedIn();
      return;
    } catch (e) {
      // No session, check for QR
    }
    
    try {
      await this.page.waitForFunction(() => window.WABridge && window.WABridge.getQR(), { timeout: 15000 });
      await this.captureQR();
    } catch (e) {
      this.log('warn', 'Neither session nor QR found, waiting...');
      // Wait a bit more
      await new Promise(r => setTimeout(r, 5000));
      await this.checkSession();
      return;
    }

    // QR is on screen — keep watching for scan + QR rotation
    this.startSessionWatcher();
  }

  startSessionWatcher() {
    if (this.sessionWatchTimer) return;
    this.log('info', 'Waiting for QR scan...');
    this.sessionWatchTimer = setInterval(async () => {
      try {
        // Do not disturb an in-progress pairing-code flow
        if (this.pairingActive) return;

        const state = await this.page.evaluate(() => {
          const logged = !!(window.WABridge && window.WABridge.isLoggedIn && window.WABridge.isLoggedIn());
          const qr = (window.WABridge && window.WABridge.getQR) ? window.WABridge.getQR() : null;
          const body = document.body ? (document.body.innerText || '') : '';
          const expired = /click to reload|qr code expired|refresh qr code|tap.{0,20}menu/i.test(body);
          let linkError = '';
          const errMatch = body.match(/(couldn['’]t link[^\n]*|unable to link[^\n]*|something went wrong[^\n]*|try again later[^\n]*)/i);
          if (errMatch) linkError = errMatch[0].trim().slice(0, 160);
          return { logged, qr, expired, linkError };
        });

        // 1) Logged in — done
        if (state.logged) {
          clearInterval(this.sessionWatchTimer);
          this.sessionWatchTimer = null;
          this.log('info', 'WhatsApp login detected');
          await this.onLoggedIn();
          return;
        }

        // Surface WhatsApp's own linking errors (e.g. too many linked devices)
        if (state.linkError && state.linkError !== this.lastLinkError) {
          this.lastLinkError = state.linkError;
          this.log('warn', 'WhatsApp says: ' + state.linkError);
        }

        // 2) A QR is on screen. Keep it fresh, but NEVER reload while one is
        //    visible — reloading aborts an in-progress scan handshake.
        if (state.qr) {
          this.linkingSince = null;
          if (state.qr !== this.lastQRBase64 || Date.now() - (this.lastQrCaptureAt || 0) > 30000) {
            this.lastQRBase64 = state.qr;
            await this.captureQR();
          }
          return;
        }

        // 3) No QR and not logged in — a scan may be in progress (WhatsApp shows a
        //    loading screen). Give it up to 90s before doing anything drastic.
        if (!this.linkingSince) {
          this.linkingSince = Date.now();
          this.log('info', 'QR no longer visible — assuming a scan is in progress...');
          return;
        }
        if (Date.now() - this.linkingSince < 90000) return;

        this.linkingSince = null;
        this.log('info', 'No QR and no login after 90s — reloading WhatsApp Web');
        await this.page.reload({ waitUntil: 'domcontentloaded' }).catch(() => {});
        this.lastQrCaptureAt = Date.now();
        this.lastQRBase64 = null;
        await new Promise(r => setTimeout(r, 4000));
      } catch (e) {
        // Page may be navigating; ignore
      }
    }, 5000);
  }
  
  async captureQR() {
    const qrBase64 = await this.page.evaluate(() => window.WABridge.getQR());
    if (qrBase64) {
      this.currentQR = 'data:image/png;base64,' + qrBase64;
      this.qrExpiresAt = new Date(Date.now() + 90000).toISOString();
      this.lastQrCaptureAt = Date.now();
      this.log('info', 'QR code captured');
      this.emit('qr', { qr: this.currentQR, expires_at: this.qrExpiresAt });
      await this.saveSessionToAPI();
    }
  }
  
  async onLoggedIn() {
    this.isConnected = true;
    this.reconnectAttempts = 0;
    this.connectedAt = this.connectedAt || new Date().toISOString();
    
    // Get phone number and device info
    const info = await this.page.evaluate(() => {
      let phone = '';
      // Primary: internal Store (works on most builds)
      try {
        const me = window.Store?.Conn?.me || window.Store?.Me?.me;
        phone = me?.user || me?.id?.user || '';
      } catch (e) {}

      // Fallback: WhatsApp writes the linked device WID into localStorage
      if (!phone) {
        try {
          const raw = localStorage.getItem('last-wid-md') ||
                      localStorage.getItem('last-wid') ||
                      localStorage.getItem('last-wid-meta') || '';
          const cleaned = String(raw).replace(/"/g, '');
          const m = cleaned.match(/(\d{6,15})/);
          if (m) phone = m[1];
        } catch (e) {}
      }

      // Fallback: scan the WID out of any localStorage value
      if (!phone) {
        try {
          for (let i = 0; i < localStorage.length; i++) {
            const k = localStorage.key(i) || '';
            if (!/wid|device|me/i.test(k)) continue;
            const v = String(localStorage.getItem(k) || '');
            const m = v.match(/(\d{10,15})(?::\d+)?@[cs]\.us/);
            if (m) { phone = m[1]; break; }
          }
        } catch (e) {}
      }

      return {
        phone: phone || '',
        platform: navigator.platform,
        device: navigator.userAgent
      };
    });
    
    this.phoneNumber = info.phone;
    this.deviceInfo = info;
    
    this.log('info', 'Logged in as', this.phoneNumber);
    
    // Save session to API
    await this.saveSessionToAPI();
    
    // Refresh privacy filter and start auto-refresh timer
    await this.refreshPrivacyFilter();
    this.startFilterRefreshTimer();
    
    // Initial sync
    await this.syncChats();
    await this.syncContacts();
    
    // Start polling for messages
    this.startPolling();
    this.startSessionSyncTimer();

    // Immediately flush anything that was queued while we were offline
    this.processOutboundQueue().catch(() => {});
    
    // Remember that this profile is linked, so future launches can run headless
    try {
      fs.writeFileSync(path.join(this.config.userDataDir, '.linked'), String(Date.now()));
    } catch (e) {}

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
        // Once linked, the QR is meaningless — clear it so the UI stops showing it
        qr_code: this.isConnected ? null : this.currentQR,
        qr_expires_at: this.isConnected ? null : this.qrExpiresAt,
        is_connected: this.isConnected,
        connected_at: this.isConnected ? (this.connectedAt || new Date().toISOString()) : null,
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
    
    let synced = 0;
    let filtered = 0;
    
    for (const chat of chats) {
      // Determine chat type
      const chatType = chat.wa_chat_id?.includes('@g.us') ? 'group' : 
                       chat.wa_chat_id?.includes('broadcast') || chat.wa_chat_id?.includes('channel') ? 'broadcast' : 'individual';
      
      const chatWithType = { ...chat, chat_type: chatType };
      
      // Check if chat is allowed
      const allowed = this.isChatAllowed(chatWithType);
      
      if (allowed.allowed) {
        await this.apiPost('api_whatsapp_ingest.php', [{
          wa_msg_id: 'chat_sync_' + chat.wa_chat_id,
          wa_chat_id: chat.wa_chat_id,
          chat_type: chatType,
          chat_name: chat.name,
          chat_avatar: chat.avatar,
          from_me: false,
          sender_wa_id: chat.wa_chat_id,
          sender_name: chat.name,
          sender_avatar: chat.avatar,
          msg_type: 'system',
          body: '',
          timestamp: Math.floor(Date.now() / 1000),
          metadata: { sync: true, unread: chat.unread, is_pinned: chat.is_pinned, is_archived: chat.is_archived, is_muted: chat.is_muted, filter_reason: allowed.reason }
        }]);
        synced++;
      } else {
        filtered++;
        this.log('debug', `Filtered out chat: ${chat.name} (${chat.wa_chat_id}) - reason: ${allowed.reason}`);
      }
    }
    
    this.log('info', `Synced ${synced} chats, filtered ${filtered} chats`);
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
      
      // Filter messages based on privacy rules
      let allowedMessages = [];
      let filteredMessages = 0;
      
      for (const msg of enriched) {
        const chatContext = {
          wa_chat_id: msg.wa_chat_id,
          chat_name: msg.chat_name,
          chat_type: msg.wa_chat_id?.includes('@g.us') ? 'group' : 
                    msg.wa_chat_id?.includes('broadcast') || msg.wa_chat_id?.includes('channel') ? 'broadcast' : 'individual'
        };
        
        const allowed = this.isMessageAllowed(msg, chatContext);
        
        if (allowed.allowed) {
          allowedMessages.push({ ...msg, metadata: { ...msg.metadata, filter_reason: allowed.reason } });
        } else {
          filteredMessages++;
          this.log('debug', `Filtered out message from ${msg.sender_name} (${msg.sender_wa_id}) in chat ${msg.wa_chat_id} - reason: ${allowed.reason}`);
        }
      }
      
      if (allowedMessages.length > 0) {
        // Send to API
        await this.apiPost('api_whatsapp_ingest.php', allowedMessages);
        
        // Update last timestamp
        this.lastMsgTimestamp = Math.max(this.lastMsgTimestamp, ...messages.map(m => m.timestamp));
        this.lastMsgId = Math.max(this.lastMsgId, ...messages.map(m => parseInt(m.wa_msg_id) || 0));
      } else {
        this.log('debug', `All ${filteredMessages} messages filtered out`);
      }
    }
    
    // Update last poll time
    await this.apiPost('api_whatsapp_session.php?action=poll', { last_poll_at: new Date().toISOString() });
  }
  
  async checkCommandFlags() {
    const qrFlag = `/tmp/whatsapp_qr_refresh_${this.config.sessionName}`;
    if (fs.existsSync(qrFlag)) {
      fs.unlinkSync(qrFlag);
      await this.refreshQR();
    }
    
    // Check for logout request
    const logoutFlag = `/tmp/whatsapp_logout_${this.config.sessionName}`;
    if (fs.existsSync(logoutFlag)) {
      fs.unlinkSync(logoutFlag);
      await this.logout();
    }
  }
  
  startSessionSyncTimer() {
    if (this.sessionSyncTimer) return;
    this.sessionSyncTimer = setInterval(() => {
      if (this.isConnected) this.saveSessionToAPI().catch(() => {});
    }, 60000);
  }

  startFlagWatcher() {
    if (this.flagWatcherTimer) return;
    this.flagWatcherTimer = setInterval(() => {
      this.checkCommandFlags().catch(() => {});
    }, 5000);
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
  
  async requestPairingCode(phoneRaw) {
    const digits = String(phoneRaw || '').replace(/\D/g, '');
    if (digits.length < 10) throw new Error('Invalid phone number');

    const dbgFile = '/tmp/whatsapp_pair_debug.txt';
    try { fs.writeFileSync(dbgFile, ''); } catch (e) {}
    const dbg = (m) => { try { fs.appendFileSync(dbgFile, new Date().toISOString() + ' ' + m + '\n'); } catch (e) {} };

    this.log('info', 'Requesting WhatsApp pairing code for ' + digits);
    dbg('start ' + digits);

    // Ensure we are on the login screen
    await this.page.goto('https://web.whatsapp.com', { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
    await new Promise(r => setTimeout(r, 4000));
    dbg('navigated url=' + this.page.url());

    // 1) Click "Link with phone number"
    const clicked = await this.page.evaluate(() => {
      const byTestId = document.querySelector('[data-testid="link-phone-number-btn"]');
      if (byTestId) { byTestId.click(); return 'testid'; }
      const candidates = Array.from(document.querySelectorAll('button, a, div[role="button"]'));
      const el = candidates.find(e => /link with phone number/i.test(e.textContent || ''));
      if (el) { el.click(); return 'text'; }
      return 'none';
    });
    dbg('click result=' + clicked);
    await new Promise(r => setTimeout(r, 2500));

    // Detect the dial-code prefix shown by WhatsApp (defaults to the browser locale).
    // WhatsApp shows the country code as a separate selector (e.g. "+91"), often NOT
    // inside the input's value/placeholder — so scan both the input and page text.
    let prefix = '';
    try {
      prefix = await this.page.evaluate(() => {
        try {
          const inp = document.querySelector('input[type="tel"], input[data-testid="phone-number-input"]');
          const src = [];
          if (inp) {
            src.push(String(inp.value || ''), String(inp.getAttribute('placeholder') || ''));
          }
          // Also scan nearby page text where WhatsApp prints "+91"
          const body = document.body.innerText || '';
          const mBody = body.match(/\+\d{1,3}/);
          if (mBody) src.push(mBody[0]);
          const joined = src.join(' ');
          const m = joined.match(/\+\d{1,3}/);
          return m ? m[1] : '';
        } catch (e) {
          return '';
        }
      });
    } catch (e) {
      prefix = '';
    }
    dbg('dial prefix=' + JSON.stringify(prefix));

    let national = digits;
    if (prefix) {
      const cc = prefix.replace(/\D/g, '');
      if (cc && national.startsWith(cc) && national.length > cc.length + 6) {
        national = national.slice(cc.length);
        dbg('stripped country code -> ' + national);
      }
    } else {
      // No prefix detected anywhere: assume the field already shows the country code
      // and type only the national (last 10 digits for most countries).
      if (national.length >= 10 && national.length <= 15) {
        national = national.slice(-10);
        dbg('no prefix detected -> typing national only: ' + national);
      }
    }

    // 2) Fill the phone number
    const input = await this.page.$('input[data-testid="phone-number-input"], input[type="tel"], input[aria-label*="phone" i]');
    dbg('input found=' + !!input);
    if (!input) {
      const txt = await this.page.evaluate(() => (document.body.innerText || '').slice(0, 600));
      dbg('page text=' + txt.replace(/\n/g, ' | '));
      throw new Error('Phone input not found');
    }

    // Type with real keystrokes (WhatsApp's field is React-controlled)
    await input.click();
    await new Promise(r => setTimeout(r, 250));
    await this.page.keyboard.down('Meta');
    await this.page.keyboard.press('KeyA');
    await this.page.keyboard.up('Meta');
    await this.page.keyboard.press('Backspace');
    await this.page.keyboard.type(String(national), { delay: 80 });
    await new Promise(r => setTimeout(r, 700));

    const typedValue = await input.evaluate(el => el.value);
    dbg('typed phone -> value=' + JSON.stringify(typedValue));

    if (!String(typedValue || '').replace(/\D/g, '')) {
      throw new Error('Phone number did not enter the field (WhatsApp UI may have changed)');
    }

    // 3) Click Next
    const nextClicked = await this.page.evaluate(() => {
      const btn = document.querySelector('[data-testid="continue-btn"], button[type="submit"]');
      if (btn) { btn.click(); return 'testid'; }
      const candidates = Array.from(document.querySelectorAll('button, div[role="button"]'));
      const el = candidates.find(e => /^(next|continue)$/i.test((e.textContent || '').trim()));
      if (el) { el.click(); return 'text'; }
      return 'none';
    });
    dbg('next result=' + nextClicked);
    await new Promise(r => setTimeout(r, 3000));

    // 4) Read the 8-character code — wait up to ~120s for WhatsApp to render it
    for (let i = 0; i < 120; i++) {
      const res = await this.page.evaluate(() => {
        const bodyText = document.body.innerText || '';

        // Hard failure: WhatsApp rejected the number
        if (/a valid phone number is required/i.test(bodyText)) {
          return { fatal: 'WhatsApp says the number is invalid. Enter your real number with country code, e.g. 919812345678.' };
        }

        // Look in specific elements first
        const codeSelectors = [
          '[data-testid="pairing-code"]',
          '[data-testid="pairing-code-message"]',
          '[data-testid="link-device-code"]',
          'div[role="dialog"] span',
          'span[data-testid*="code"]',
          'div[data-testid*="code"]'
        ];
        for (const sel of codeSelectors) {
          try {
            const els = Array.from(document.querySelectorAll(sel));
            for (const el of els) {
              const t = (el.textContent || '').trim();
              if (/^[A-Z0-9]{4}[- ]?[A-Z0-9]{4}$/.test(t)) {
                return { code: t.replace(/[- ]/g, '').toUpperCase() };
              }
            }
          } catch (e) {}
        }

        // Fallback: scan body text for an 8-char code
        // Preferred form: "ABCD-EFGH"
        let m = bodyText.match(/\b([A-Z0-9]{4})-([A-Z0-9]{4})\b/);
        if (m) return { code: (m[1] + m[2]).toUpperCase() };
        // Bare 8 alphanumerics that look like a code (has at least one letter and one digit)
        m = bodyText.match(/\b[A-Z0-9]{8}\b/);
        if (m && /[A-Z]/.test(m[0]) && /\d/.test(m[0])) return { code: m[0].toUpperCase() };

        return {};
      });
      if (res.fatal) {
        dbg('fatal=' + res.fatal);
        throw new Error(res.fatal);
      }
      if (res.code) {
        dbg('code found=' + res.code);
        return res.code.slice(0, 8);
      }
      dbg('try ' + i + ' (no code yet)');

      await new Promise(r => setTimeout(r, 1000));
    }

    // Dump everything we see so the user can debug WhatsApp's UI
    const dump = await this.page.evaluate(() => {
      const text = document.body.innerText || '';
      const lines = text.split('\n').map(l => l.trim()).filter(l => l.length > 0);
      return { allLines: lines.slice(0, 80), bodyLen: text.length };
    });
    dbg('page text dump=' + JSON.stringify(dump.allLines));
    throw new Error('Pairing code not found — WhatsApp showed pairing screen but no code was readable. Try scanning the QR instead.');
  }

  startPairingWatcher() {
    if (this.pairingTimer) return;
    this.pairingTimer = setInterval(async () => {
      const reqFile = '/tmp/whatsapp_pair_request';
      if (!fs.existsSync(reqFile)) return;
      let phone = '';
      try { phone = fs.readFileSync(reqFile, 'utf8').trim(); } catch (e) {}
      try { fs.unlinkSync(reqFile); } catch (e) {}
      try {
        this.pairingActive = true;
        const code = await this.requestPairingCode(phone);
        fs.writeFileSync('/tmp/whatsapp_pair_code.txt', code);
        this.log('info', 'Pairing code generated: ' + code);
      } catch (e) {
        fs.writeFileSync('/tmp/whatsapp_pair_code.txt', 'ERROR: ' + e.message);
        this.log('error', 'Pairing code failed', e.message);
      } finally {
        this.pairingActive = false;
        // Give WhatsApp a moment, then resume normal QR watching
        this.lastQrCaptureAt = Date.now();
      }
    }, 3000);
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
      try { fs.unlinkSync(path.join(this.config.userDataDir, '.linked')); } catch (e) {}
      this.stopPolling();
      this.stopFilterRefreshTimer();
      await this.saveSessionToAPI();
      this.emit('disconnected');
    } catch (e) {
      this.log('error', 'Logout failed', e.message);
    }
  }
  
  cleanStaleProfileLock() {
    const dir = this.config.userDataDir;
    const lock = path.join(dir, 'SingletonLock');
    const socket = path.join(dir, 'SingletonSocket');

    if (!fs.existsSync(lock) && !fs.existsSync(socket)) return;

    // Chrome writes SingletonLock as "<hostname>-<pid>"
    let holderPid = 0;
    try {
      const target = String(fs.readlinkSync(lock));
      const m = target.match(/-(\d+)$/);
      if (m) holderPid = parseInt(m[1], 10);
    } catch (e) {}

    let holderAlive = false;
    if (holderPid > 0) {
      try { process.kill(holderPid, 0); holderAlive = true; } catch (e) { holderAlive = false; }
    }

    // A live holder at this point can only be an orphan from a previous run.
    if (holderAlive) {
      this.log('warn', `Killing orphaned Chrome (pid ${holderPid}) holding the profile`);
      try { process.kill(holderPid, 'SIGKILL'); } catch (e) {}
      const start = Date.now();
      while (Date.now() - start < 5000) {
        try { process.kill(holderPid, 0); } catch (e) { break; }
        try { require('child_process').execSync('sleep 0.2'); } catch (e) {}
      }
    }

    for (const f of ['SingletonLock', 'SingletonSocket', 'SingletonCookie']) {
      try { fs.unlinkSync(path.join(dir, f)); } catch (e) {}
    }
    this.log('info', 'Cleared Chrome profile lock');
  }

  handleDisconnect() {
    this.log('warn', 'Browser disconnected');
    this.isConnected = false;
    this.stopPolling();
    this.stopFilterRefreshTimer();

    if (this.sessionSyncTimer) {
      clearInterval(this.sessionSyncTimer);
      this.sessionSyncTimer = null;
    }
    if (this.sessionWatchTimer) {
      clearInterval(this.sessionWatchTimer);
      this.sessionWatchTimer = null;
    }

    // Drop the dead handles so the next launch starts from a clean state
    this.browser = null;
    this.page = null;

    this.reconnect();
  }

  async reconnect() {
    if (this.reconnectAttempts >= this.maxReconnectAttempts) {
      this.log('error', 'Max reconnect attempts reached');
      this.emit('failed');
      return;
    }

    this.reconnectAttempts++;
    const delay = Math.min(5000 * this.reconnectAttempts, 60000);
    this.log('info', `Reconnecting... (attempt ${this.reconnectAttempts}/${this.maxReconnectAttempts}) in ${delay / 1000}s`);

    await new Promise(r => setTimeout(r, delay));

    try {
      const ok = await this.init();
      if (ok === false) throw new Error('init() returned false');
    } catch (e) {
      // Never let a failed relaunch crash the node process
      this.log('error', 'Reconnect attempt failed', e.message);
      this.reconnect();
    }
  }

  // ============ OUTBOUND MESSAGING ============
  
  async processOutboundQueue() {
    if (!this.isConnected) return;
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
        try {
          await this.apiPost('api_whatsapp_outbound_update.php', {
            id: queueId,
            status: 'failed',
            attempts: newAttempts,
            error_message: error.message
          });
        } catch (e) {
          this.log('error', `Could not mark queue ${queueId} failed`, e.message);
        }
        try {
          await this.apiPost('api_whatsapp_update_temp_msg.php', {
            temp_msg_id: item.temp_msg_id,
            status: 'failed'
          });
        } catch (e) {
          this.log('debug', `temp msg update skipped for queue ${queueId}`, e.message);
        }
      } else {
        try {
          await this.apiPost('api_whatsapp_outbound_update.php', {
            id: queueId,
            status: 'queued',
            attempts: newAttempts,
            error_message: error.message
          });
        } catch (e) {
          this.log('error', `Could not requeue ${queueId}`, e.message);
        }
      }
    }
  }
  
  // Wait until the message composer is present and visible
  async waitForComposer(timeout = 25000) {
    try {
      await this.page.waitForFunction(() => {
        const el = document.querySelector('footer div[contenteditable="true"], div[contenteditable="true"][data-tab="10"], [aria-label="Type a message"]');
        return !!el && el.offsetParent !== null;
      }, { timeout, polling: 400 });
      return true;
    } catch (e) {
      return false;
    }
  }

  async openChat(waChatId) {
    const raw = String(waChatId || '');
    const digits = raw.split('@')[0].replace(/\D/g, '');
    const isGroup = raw.includes('@g.us');

    // Individuals: deep-link straight to the conversation (far more reliable
    // than the in-app search, whose selectors change often).
    if (!isGroup && digits.length >= 6) {
      const target = `https://web.whatsapp.com/send?phone=${digits}`;
      if (!this.page.url().includes(`phone=${digits}`)) {
        this.log('debug', 'Opening chat via send link: ' + digits);
        await this.page.goto(target, { waitUntil: 'domcontentloaded', timeout: 90000 }).catch(() => {});
      }
      if (await this.waitForComposer(30000)) return true;
      this.log('warn', 'Composer not ready after deep link, falling back to search');
    }

    // Fallback: in-app search
    try {
      if (!this.page.url().startsWith('https://web.whatsapp.com')) {
        await this.page.goto('https://web.whatsapp.com', { waitUntil: 'domcontentloaded' }).catch(() => {});
      }
      await this.page.evaluate(() => {
        const s = document.querySelector('[aria-label="Search"], [data-testid="chat-list-search"], div[contenteditable="true"][data-tab="3"]');
        if (s) { s.click(); if (s.focus) s.focus(); }
      });
      await new Promise(r => setTimeout(r, 800));
      await this.page.keyboard.type(digits || raw, { delay: 60 });
      await new Promise(r => setTimeout(r, 1800));
      await this.page.keyboard.press('Enter');
      return await this.waitForComposer(15000);
    } catch (e) {
      this.log('warn', 'openChat fallback failed', e.message);
      return false;
    }
  }

  async sendText(waChatId, text) {
    const opened = await this.openChat(waChatId);
    if (!opened) return { success: false, error: 'Could not open chat / composer not found' };
    await new Promise(r => setTimeout(r, 900));

    const boxSel = 'footer div[contenteditable="true"], div[contenteditable="true"][data-tab="10"], [aria-label="Type a message"]';
    const box = await this.page.$(boxSel);
    if (!box) return { success: false, error: 'Input not found' };

    await box.click();
    await new Promise(r => setTimeout(r, 250));

    // Clear anything already in the composer
    await this.page.keyboard.down('Meta');
    await this.page.keyboard.press('KeyA');
    await this.page.keyboard.up('Meta');
    await this.page.keyboard.press('Backspace');

    // Type with real keystrokes so React's editor registers the input
    const lines = String(text == null ? '' : text).split('\n');
    for (let i = 0; i < lines.length; i++) {
      if (i > 0) {
        await this.page.keyboard.down('Shift');
        await this.page.keyboard.press('Enter');
        await this.page.keyboard.up('Shift');
      }
      if (lines[i]) await this.page.keyboard.type(lines[i], { delay: 8 });
    }
    await new Promise(r => setTimeout(r, 400));

    const readBox = (sel) => this.page.evaluate((s) => {
      const el = document.querySelector(s);
      return el ? (el.innerText || el.textContent || '').trim() : '';
    }, sel);

    if (!(await readBox(boxSel))) {
      return { success: false, error: 'Typed text did not appear in composer' };
    }

    // Send with Enter (most reliable)
    await this.page.keyboard.press('Enter');
    await new Promise(r => setTimeout(r, 1600));

    // If the composer still has text, the message was not accepted — try the button
    if (await readBox(boxSel)) {
      const clicked = await this.page.evaluate(() => {
        const btn = document.querySelector('[data-testid="send"], [aria-label="Send"], span[data-icon="send"]');
        if (btn) { (btn.closest('button') || btn).click(); return true; }
        return false;
      });
      if (!clicked) return { success: false, error: 'Message not accepted (composer still filled, no send button)' };
      await new Promise(r => setTimeout(r, 1600));
      if (await readBox(boxSel)) return { success: false, error: 'Message not sent (composer still filled)' };
    }

    const msgId = await this.getLastSentMessageId(waChatId);
    return { success: true, wa_msg_id: msgId };
  }
  
  async sendMedia(waChatId, filePath, mediaType, caption) {
    const opened = await this.openChat(waChatId);
    if (!opened) throw new Error('Could not open chat');
    await new Promise(r => setTimeout(r, 900));

    // Open the attach menu (WhatsApp creates the file input lazily)
    await this.page.evaluate(() => {
      const attachBtn = document.querySelector('span[data-icon="plus"], span[data-icon="clip"], [data-testid="clip"], [aria-label="Attach"]');
      if (attachBtn) (attachBtn.closest('button') || attachBtn).click();
    });
    await new Promise(r => setTimeout(r, 600));

    // Pick whichever file input matches the media kind; fall back to any
    const acceptMap = {
      image: 'image', video: 'video', audio: 'audio', document: 'document,application'
    };
    let fileInput = null;
    try {
      fileInput = await this.page.waitForSelector(
        `input[type="file"][accept*="${acceptMap[mediaType] || 'image'}"]`,
        { timeout: 8000 }
      );
    } catch (e) {}
    if (!fileInput) {
      try { fileInput = await this.page.waitForSelector('input[type="file"]', { timeout: 5000 }); } catch (e) {}
    }
    if (!fileInput) throw new Error('File input not found');

    await fileInput.uploadFile(filePath);
    await new Promise(r => setTimeout(r, 2500)); // upload / preview

    if (caption) {
      const capSel = 'div[contenteditable="true"][data-tab="10"], [data-testid="media-caption-input"], footer div[contenteditable="true"]';
      const capBox = await this.page.$(capSel);
      if (capBox) {
        await capBox.click();
        await this.page.keyboard.type(String(caption), { delay: 10 });
        await new Promise(r => setTimeout(r, 300));
      }
    }

    const clicked = await this.page.evaluate(() => {
      const btn = document.querySelector('[data-testid="send"], [aria-label="Send"], span[data-icon="send"]');
      if (btn) { (btn.closest('button') || btn).click(); return true; }
      return false;
    });
    if (!clicked) return { success: false, error: 'Send button not found after media' };

    await new Promise(r => setTimeout(r, 2500));
    const msgId = await this.getLastSentMessageId(waChatId);
    return { success: true, wa_msg_id: msgId };
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
        file_size: fs.statSync(filepath).size,
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
        'X-WA-Signature': signature,
        'X-Admin-Test': '1'
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
      headers: { 'X-Admin-Test': '1' },
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
    if (this.sessionWatchTimer) {
      clearInterval(this.sessionWatchTimer);
      this.sessionWatchTimer = null;
    }
    if (this.flagWatcherTimer) {
      clearInterval(this.flagWatcherTimer);
      this.flagWatcherTimer = null;
    }
    if (this.sessionSyncTimer) {
      clearInterval(this.sessionSyncTimer);
      this.sessionSyncTimer = null;
    }
    
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
  
  process.on('unhandledRejection', (reason) => {
    console.error('[unhandledRejection]', reason && reason.message ? reason.message : reason);
  });

  let started = false;
  for (let attempt = 1; attempt <= 5 && !started; attempt++) {
    try {
      await bridge.init();
      started = true;
    } catch (error) {
      console.error(`Bridge failed to start (attempt ${attempt}/5):`, error.message);
      bridge.cleanStaleProfileLock();
      if (attempt < 5) await new Promise(r => setTimeout(r, 10000));
    }
  }

  if (!started) {
    console.error('Bridge could not start after 5 attempts. Giving up.');
    process.exit(1);
  }

  // Start outbound queue processor
  setInterval(() => bridge.processOutboundQueue(), 5000);

  // Keep alive
  console.log('Bridge running. Press Ctrl+C to stop.');
}

if (require.main === module) {
  main();
}

module.exports = WhatsAppBridge;
