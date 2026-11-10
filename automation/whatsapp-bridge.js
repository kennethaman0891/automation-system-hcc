/**
 * HCC WhatsApp Bridge — Full Puppeteer Integration
 * Handles: QR login, session persistence, send/receive messages, media download,
 * contact/chat sync, typing indicators, presence, real-time event emission
 */

const path = require('path');
require('dotenv').config({ path: path.join(__dirname, '.env') });
const fs = require('fs');
const waListRules = require('./wa-list-rules');
const waRevokeRules = require('./wa-revoke-rules');
const waSafety = require('./wa-safety');
const puppeteer = require('puppeteer');
const axios = require('axios');
const os = require('os');
const EventEmitter = require('events');
const FormData = require('form-data');
const mime = require('mime-types');

/**
 * Cross-platform detection of Chrome or Chromium executable.
 *
 * CRITICAL (macOS Single-Instance Isolation):
 * Never default to macOS's system `/Applications/Google Chrome.app` for headless
 * automation. Doing so registers the system binary with LaunchServices in headless mode,
 * preventing the user from opening their desktop Chrome GUI browser from the Dock.
 *
 * Resolution Order:
 * 1. explicitPath parameter (if provided & exists)
 * 2. CHROME_PATH environment variable (if provided & exists)
 * 3. Puppeteer's dedicated browser (`puppeteer.executablePath()`, e.g. "Google Chrome for Testing")
 * 4. Standalone Chromium / Edge binaries that do NOT share bundle ID with desktop Chrome
 * 5. Platform-specific fallbacks (Windows / Linux standard paths)
 */
function findChrome(explicitPath) {
  if (explicitPath && fs.existsSync(explicitPath)) return explicitPath;
  if (process.env.CHROME_PATH && fs.existsSync(process.env.CHROME_PATH)) return process.env.CHROME_PATH;

  // 1. Prefer Puppeteer's dedicated, isolated Chrome for Testing binary
  try {
    const pPath = puppeteer.executablePath ? puppeteer.executablePath() : null;
    if (pPath && fs.existsSync(pPath)) return pPath;
  } catch (e) {}

  let candidates = [];
  if (process.platform === 'win32') {
    const progFiles = process.env.PROGRAMFILES || 'C:\\Program Files';
    const progFilesX86 = process.env['PROGRAMFILES(X86)'] || 'C:\\Program Files (x86)';
    const localAppData = process.env.LOCALAPPDATA || (process.env.USERPROFILE ? path.join(process.env.USERPROFILE, 'AppData', 'Local') : '');

    candidates = [
      path.join(progFiles, 'Google', 'Chrome', 'Application', 'chrome.exe'),
      path.join(progFilesX86, 'Google', 'Chrome', 'Application', 'chrome.exe'),
      localAppData ? path.join(localAppData, 'Google', 'Chrome', 'Application', 'chrome.exe') : '',
      // Edge fallback on Windows
      path.join(progFilesX86, 'Microsoft', 'Edge', 'Application', 'msedge.exe'),
      path.join(progFiles, 'Microsoft', 'Edge', 'Application', 'msedge.exe')
    ].filter(Boolean);
  } else if (process.platform === 'darwin') {
    // macOS: Use standalone Chromium or Microsoft Edge first.
    // Explicitly exclude /Applications/Google Chrome.app to prevent hijacking user's primary desktop GUI browser.
    candidates = [
      '/Applications/Chromium.app/Contents/MacOS/Chromium',
      path.join(os.homedir(), 'Applications', 'Chromium.app', 'Contents', 'MacOS', 'Chromium'),
      '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge'
    ];
  } else {
    candidates = [
      '/usr/bin/chromium',
      '/usr/bin/chromium-browser',
      '/snap/bin/chromium',
      '/usr/bin/google-chrome',
      '/usr/bin/google-chrome-stable'
    ];
  }

  for (const c of candidates) {
    if (fs.existsSync(c)) return c;
  }

  // Only use macOS desktop Google Chrome if explicitly permitted via env var
  if (process.platform === 'darwin' && process.env.ALLOW_SYSTEM_CHROME === '1') {
    const sysChrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
    if (fs.existsSync(sysChrome)) return sysChrome;
  }

  return null;
}

/**
 * Cross-platform resolution of IPC temporary directory.
 * On POSIX systems, /tmp is standard and shared across webserver and CLI users.
 * On Windows, /tmp does NOT exist, so os.tmpdir() is used.
 */
function getBridgeTempDir() {
  if (process.env.WHATSAPP_IPC_DIR && fs.existsSync(process.env.WHATSAPP_IPC_DIR)) {
    return process.env.WHATSAPP_IPC_DIR;
  }
  if (process.platform !== 'win32' && fs.existsSync('/tmp')) {
    return '/tmp';
  }
  return os.tmpdir();
}

class WhatsAppBridge extends EventEmitter {
  constructor(config = {}) {
    super();
    this.config = {
      // HCC API
      apiBaseUrl: config.apiBaseUrl || process.env.API_BASE_URL || 'http://localhost/hcc/admin',
      bridgeSecret: process.env.WHATSAPP_BRIDGE_SECRET || '',
      
      // Browser
      userDataDir: config.userDataDir || process.env.USER_DATA_DIR || path.join(__dirname, '.whatsapp-session'),
      headless: config.headless !== undefined ? config.headless : (process.env.HEADLESS === 'true'),
      executablePath: findChrome(config.executablePath || process.env.CHROME_PATH),
      ipcDir: config.ipcDir || process.env.WHATSAPP_IPC_DIR || getBridgeTempDir(),
      
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
      filterRefreshInterval: config.filterRefreshInterval || parseInt(process.env.FILTER_REFRESH_INTERVAL) || 15000, // 15s so a New chat is on the allowlist quickly
      
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
      rosterNames: new Set(),        // Saved chat titles, so an unread row can be opened without a visible number
      nameToJidMap: new Map(),       // Lowercase name -> wa_chat_id map
      admissionKeywords: [],         // Array of admission-related keywords
      classGroupIds: new Set(),      // Set of allowed group wa_chat_id
      lastRefreshed: 0,
      refreshInterval: this.config.filterRefreshInterval
    };
    this.filterRefreshTimer = null;
    this.consecutiveQrDetections = 0;
    this.sending = false;
    this.shuttingDown = false;
    this.pollingNow = false;
    this.recentOutboundChatIds = new Set();
    this.recentOutboundBodies = new Map();
    this.outboundEchoSeen = new Set();
    this.outboundWatch = [];
    this.outboundWatchCursor = 0;
    this.lastRevisitAt = 0;
    this.ingestNotices = new Map();
    this.ingestedMsgIds = new Set();
    this.chatPreviewByTitle = new Map();
    this.chatListPrimed = false;
    this.didInitialListPass = false;
    this.lastChatListSummary = '';
    this.lastIngestAt = null;
    this.lastSkipNote = '';
    if (!this.config.bridgeSecret || String(this.config.bridgeSecret).length < 16) {
      this.log('error', 'WHATSAPP_BRIDGE_SECRET is missing or shorter than 16 characters; ingest posts will be refused');
    }
    
    // Ensure directories exist
    this.ensureDirs();

    // Test-mode hardening: isolated profile, mock entry URL, fixture-only chats.
    if (waSafety.isTestMode()) {
      this.config.userDataDir = process.env.USER_DATA_DIR
        || path.join(__dirname, '.whatsapp-session-test');
      this.config.enablePrivacyFilter = false;
      if (this.privacyFilter) this.privacyFilter.enabled = false;
      this.config.headless = process.env.HEADLESS === 'false' ? false : true;
      this.log('warn', 'WA_TEST_MODE=1 — real WhatsApp navigation is blocked; using mock + fixture chats only');
    }
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
        this.privacyFilter.rosterNames.clear();
        this.privacyFilter.nameToJidMap.clear();
        
        // Add roster numbers (students + teachers)
        if (data.roster_numbers && Array.isArray(data.roster_numbers)) {
          for (const entry of data.roster_numbers) {
            if (entry.wa_id) this.privacyFilter.rosterNumbers.add(entry.wa_id.toLowerCase());
            if (entry.phone_e164) this.privacyFilter.rosterPhones.add(entry.phone_e164.toLowerCase());
            if (entry.name && entry.wa_id) {
              const cleaned = String(entry.name || '').trim().toLowerCase();
              if (cleaned.length >= 2) {
                this.privacyFilter.rosterNames.add(cleaned);
                this.privacyFilter.nameToJidMap.set(cleaned, entry.wa_id.toLowerCase());
              }
            }
          }
        }
        
        // Add linked contacts
        const rememberName = (name, jid = '') => {
          const cleaned = String(name || '').trim().toLowerCase();
          if (cleaned.length >= 2) {
            this.privacyFilter.rosterNames.add(cleaned);
            if (jid) this.privacyFilter.nameToJidMap.set(cleaned, jid.toLowerCase());
          }
        };

        if (data.linked_contacts && Array.isArray(data.linked_contacts)) {
          for (const entry of data.linked_contacts) {
            if (entry.wa_id) this.privacyFilter.rosterNumbers.add(entry.wa_id.toLowerCase());
            if (entry.phone_e164) this.privacyFilter.rosterPhones.add(entry.phone_e164.toLowerCase());
            rememberName(entry.name, entry.wa_id);
          }
        }

        // Individual whatsapp_chats the PHP filter already knows
        if (data.known_chats && Array.isArray(data.known_chats)) {
          for (const entry of data.known_chats) {
            const jid = entry && entry.wa_chat_id ? String(entry.wa_chat_id).toLowerCase() : '';
            if (jid) {
              this.privacyFilter.rosterNumbers.add(jid);
            }
            rememberName(entry && entry.name, jid);
          }
        }

        // Admin/Self phone number is always in the roster
        if (this.phoneNumber) {
          const adminDigits = this.normalizePhoneDigits(String(this.phoneNumber));
          if (adminDigits) {
            this.privacyFilter.rosterNumbers.add(adminDigits + '@c.us');
            this.privacyFilter.rosterPhones.add('+' + adminDigits);
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

    if (waChatId && this.recentOutboundChatIds.has(waChatId)) {
      return { allowed: true, reason: 'recent_outbound' };
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
      // Admin / self chat is always allowed
      if (chat.is_self || (this.phoneNumber && waChatId.startsWith(this.normalizePhoneDigits(this.phoneNumber)))) {
        return { allowed: true, reason: 'admin_or_self' };
      }

      // Linked-device chats without a phone: allow when we already know the id
      // (known_chats / recent open) — never invent a phone from @lid digits.
      if (waChatId.includes('@lid') && this.privacyFilter.rosterNumbers.has(waChatId)) {
        return { allowed: true, reason: 'known_lid' };
      }
      if (waChatId.includes('@lid') && this.recentOutboundChatIds.has(waChatId)) {
        return { allowed: true, reason: 'recent_lid' };
      }

      // Check wa_chat_id (format: 919876543210@c.us)
      if (waChatId && this.privacyFilter.rosterNumbers.has(waChatId)) {
        return { allowed: true, reason: 'roster_number' };
      }
      
      const digits = waChatId.split('@')[0].replace(/\D/g, '');
      if (digits && (this.privacyFilter.rosterPhones.has('+' + digits) || this.privacyFilter.rosterNumbers.has(digits + '@c.us'))) {
        return { allowed: true, reason: 'roster_phone' };
      }

      // Also check if sender wa_id is in roster (from chat metadata)
      const senderWaId = (chat.sender_wa_id || '').toLowerCase();
      if (senderWaId && this.privacyFilter.rosterNumbers.has(senderWaId)) {
        return { allowed: true, reason: 'roster_sender' };
      }

      // Chat the bridge itself just sent to (reply may arrive before the next filter refresh)
      if (waChatId && this.recentOutboundChatIds.has(waChatId)) {
        return { allowed: true, reason: 'recent_outbound' };
      }
      
      // Check if chat name matches a known contact/student/parent in roster
      const cleanTitle = (chatName || '').trim().toLowerCase();
      if (cleanTitle && this.privacyFilter.rosterNames && this.privacyFilter.rosterNames.has(cleanTitle)) {
        return { allowed: true, reason: 'roster_name' };
      }

      // Direct 1-to-1 chats to the school account are allowed so prospective parents and new students are received
      return { allowed: true, reason: 'individual_direct' };
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

    // Admin / self-test incoming replies are always allowed
    if (chatContext.is_self || (this.phoneNumber && waChatId.startsWith(this.normalizePhoneDigits(this.phoneNumber)))) {
      return { allowed: true, reason: 'admin_or_self' };
    }

    // 1. Check if sender is in roster
    if (senderWaId && this.privacyFilter.rosterNumbers.has(senderWaId)) {
      return { allowed: true, reason: 'roster_sender' };
    }

    if (waChatId && this.privacyFilter.rosterNumbers.has(waChatId)) {
      return { allowed: true, reason: 'roster_number' };
    }

    const digits = waChatId.split('@')[0].replace(/\D/g, '');
    if (digits && (this.privacyFilter.rosterPhones.has('+' + digits) || this.privacyFilter.rosterNumbers.has(digits + '@c.us'))) {
      return { allowed: true, reason: 'roster_phone' };
    }

    if (waChatId && this.recentOutboundChatIds.has(waChatId)) {
      return { allowed: true, reason: 'recent_outbound' };
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

    // 5. Individual chat from prospective parent / admission enquiry
    if (chatType === 'individual') {
      return { allowed: true, reason: 'individual_admission_enquiry' };
    }

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

      if (!this.config.executablePath) {
        this.config.executablePath = findChrome();
      }
      if (!this.config.executablePath && process.platform === 'darwin') {
        this.log('warn', 'Puppeteer browser not found. Installing Chrome for Testing...');
        try {
          const { execSync } = require('child_process');
          execSync('npx puppeteer install chrome', { stdio: 'inherit', cwd: __dirname });
          this.config.executablePath = findChrome();
        } catch (err) {
          this.log('error', `Failed to auto-install Chrome for Testing: ${err.message}`);
        }
      }
      if (this.config.executablePath) {
        this.log('info', `Using Chrome executable: ${this.config.executablePath}`);
      } else {
        this.log('warn', 'No Chrome or Edge executable found; attempting Puppeteer default launch');
      }

      // Clean up stale IPC files from prior runs
      const staleIpcFiles = [
        'whatsapp_pair_request',
        'whatsapp_pair_code.txt',
        'whatsapp_qr_refresh_default',
        `whatsapp_qr_refresh_${this.config.sessionName}`
      ];
      for (const f of staleIpcFiles) {
        try {
          const p = path.join(this.config.ipcDir, f);
          if (fs.existsSync(p)) fs.unlinkSync(p);
        } catch (e) {}
      }

      // Launch browser with persistent profile
      const launchOpts = {
        headless: autoHeadless,
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
      };
      if (this.config.executablePath) {
        launchOpts.executablePath = this.config.executablePath;
      }
      this.browser = await puppeteer.launch(launchOpts);
      
      this.browser.on('disconnected', () => this.handleDisconnect());
      
      const pages = await this.browser.pages();
      this.page = pages[0] || await this.browser.newPage();
      
      // Set longer timeouts
      this.page.setDefaultTimeout(60000);
      this.page.setDefaultNavigationTimeout(60000);
      
      // Mask webdriver flag so WhatsApp Web anti-bot does not reject device pairing
      await this.page.evaluateOnNewDocument(() => {
        Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
      });

      // Enable request interception for media downloads
      await this.page.setRequestInterception(true);
      this.page.on('request', this.handleRequest.bind(this));
      
      // Listen for console messages from page
      this.page.on('console', msg => {
        if (msg.type() === 'log' && msg.text().startsWith('[WA_BRIDGE]')) {
          this.log('debug', 'Page log: ' + msg.text().substring(11));
        }
      });
      
      // Announce bridge is alive to backend API
      await this.apiPost('api_whatsapp_session.php?action=poll', {
        last_poll_at: new Date().toISOString(),
        is_syncing: 0
      }).catch(() => {});

      // Navigate to WhatsApp Web
      this.log('info', 'Navigating to WhatsApp Web...');
      await this.safeGoto(waSafety.resolveWhatsAppEntryUrl(), { waitUntil: 'networkidle2', timeout: 120000 });
      
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
              
              let waChatId = el.getAttribute('data-chat-id') || el.getAttribute('id') || el.getAttribute('data-id') || '';
              if (!waChatId) {
                const titleStr = (nameEl?.getAttribute('title') || nameEl?.textContent || '').replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, '').trim();
                const digits = titleStr.replace(/\D/g, '');
                if (digits.length === 10 && /^[6-9]/.test(digits)) {
                  waChatId = '91' + digits + '@c.us';
                } else if (digits.length >= 11 && digits.length <= 15) {
                  waChatId = digits + '@c.us';
                }
              }
              if (!waChatId) {
                const rowHtml = el.outerHTML ? el.outerHTML.slice(0, 800) : '';
                const mJid = rowHtml.match(/(\d{10,15})@(c\.us|s\.whatsapp\.net|g\.us|lid)/i);
                if (mJid) waChatId = (mJid[1] + '@' + mJid[2]).toLowerCase().replace('@s.whatsapp.net', '@c.us');
              }
              
              if (nameEl) {
                chats.push({
                  wa_chat_id: waChatId,
                  name: (nameEl.textContent?.trim() || '').replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, ''),
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
        
        // Extract messages from the open chat.
        // Older builds used true_JID_MSG / false_JID_MSG. The current build puts
        // only the message id on the row (data-testid="conv-msg-…") and marks
        // outgoing with a "You:" / Read label or a tail-out icon.
        getMessages: () => {
          const messages = [];
          const seen = new Set();
          const msgElements = document.querySelectorAll(
            '#main [data-testid^="conv-msg-"], #main [data-id], [data-testid="msg-container"], .message-in, .message-out'
          );
          msgElements.forEach(node => {
            try {
              const el = node.closest('[data-id]') || node;
              let msgId = el.getAttribute('data-id') || el.getAttribute('id') || '';
              if (!msgId) {
                const testId = el.getAttribute('data-testid') || node.getAttribute('data-testid') || '';
                const m = testId.match(/^conv-msg-(.+)$/);
                if (m) msgId = m[1];
              }
              if (!msgId) {
                const inner = el.querySelector('[data-id]');
                if (inner) msgId = inner.getAttribute('data-id') || '';
              }
              const virtual = el.querySelector('[data-virtualized="true"]');
              const hasText = !!el.querySelector('.selectable-text, [data-testid="msg-text"]');
              const hasMedia = !!el.querySelector('img[src*="blob:"], video, audio');
              if (virtual && !hasText && !hasMedia) return;
              const textNodes = el.querySelectorAll('.selectable-text, [data-testid="msg-text"]');
              const textEl = textNodes.length ? textNodes[textNodes.length - 1] : null;
              const body = textEl?.textContent?.trim() || '';
              const mediaEl = el.querySelector('img[src*="blob:"], video, audio');
              if (!body && !mediaEl) return;

              if (!msgId) {
                let h = 0;
                const sample = body.slice(0, 40);
                for (let si = 0; si < sample.length; si++) { h = ((h << 5) - h) + sample.charCodeAt(si); h |= 0; }
                msgId = 'syn_' + Math.abs(h);
              }
              if (seen.has(msgId)) return;

              const idMatch = String(msgId).match(/^(true|false)_(.+?@(?:c\.us|g\.us|s\.whatsapp\.net|lid|broadcast|newsletter))_(.+)$/i);
              seen.add(msgId);

              const iconNames = Array.from(el.querySelectorAll('[data-icon]')).map(n => n.getAttribute('data-icon') || '').join(' ');
              const statusLabels = Array.from(el.querySelectorAll('[aria-label]')).map(n => (n.getAttribute('aria-label') || '').trim());
              const outgoingLabel = statusLabels.some(label => /^(you:|read|delivered|sent)$/i.test(label));
              let fromMe;
              if (idMatch) fromMe = idMatch[1].toLowerCase() === 'true';
              else if (/tail-in/.test(iconNames)) fromMe = false;
              else if (/tail-out|msg-check|msg-dblcheck/.test(iconNames) || outgoingLabel || el.classList.contains('message-out') || !!el.closest('.message-out')) fromMe = true;
              else fromMe = false;

              const timeEl = el.querySelector('[data-pre-plain-text]')
                || el.querySelector('[data-testid="msg-time"], .copyable-text');
              const quotedEl = el.querySelector('[data-testid="quoted-msg"], .quoted-msg');
              let timestamp = Math.floor(Date.now() / 1000);
              let timestampParsed = false;
              const pre = timeEl?.getAttribute('data-pre-plain-text') || '';
              const clock = pre.match(/\[(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp][Mm])?,\s*(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/);
              if (clock) {
                let hour = parseInt(clock[1], 10);
                const minute = parseInt(clock[2], 10);
                const second = clock[3] ? parseInt(clock[3], 10) : 0;
                const ampm = (clock[4] || '').toLowerCase();
                let day = parseInt(clock[5], 10);
                let month = parseInt(clock[6], 10);
                let year = parseInt(clock[7], 10);
                if (ampm === 'pm' && hour < 12) hour += 12;
                if (ampm === 'am' && hour === 12) hour = 0;
                if (year < 100) year += 2000;
                if (month > 12 && day <= 12) {
                  const swap = day;
                  day = month;
                  month = swap;
                }
                const parsed = new Date(year, month - 1, day, hour, minute, second).getTime();
                if (!Number.isNaN(parsed)) {
                  const unix = Math.floor(parsed / 1000);
                  if (unix > 1000000000 && unix < 20000000000) {
                    timestamp = unix;
                    timestampParsed = true;
                  }
                }
              }

              const iconText = Array.from(el.querySelectorAll('[data-icon], [aria-label]'))
                .map(node => (node.getAttribute('data-icon') || '') + ' ' + (node.getAttribute('aria-label') || ''))
                .join(' ');
              let status = 'sent';
              if (/msg-dblcheck-ack|Read/i.test(iconText)) status = 'read';
              else if (/msg-dblcheck|Delivered/i.test(iconText)) status = 'delivered';

              const mediaTag = mediaEl ? mediaEl.tagName.toLowerCase() : '';
              const mediaType = mediaTag === 'img' ? 'image' : (mediaTag === 'video' ? 'video' : (mediaTag === 'audio' ? 'audio' : 'text'));
              const resolvedBody = body || (mediaEl ? (mediaTag === 'img' ? '📷 [Photo]' : mediaTag === 'video' ? '🎥 [Video]' : '🎤 [Voice message]') : '');

              messages.push({
                wa_msg_id: msgId,
                wa_chat_id: idMatch ? idMatch[2] : '',
                from_me: fromMe,
                msg_type: !body && mediaEl ? mediaType : 'text',
                body: resolvedBody,
                media_url: mediaEl?.src || '',
                timestamp: timestamp,
                timestamp_parsed: timestampParsed,
                quoted_wa_msg_id: quotedEl?.getAttribute('data-id') || '',
                status: status
              });
            } catch (e) {}
          });
          return messages;
        },

        // Visible chat-list rows. Index matches openUnreadRow's node list.
        // Phone is set only when the row itself contains one. @lid is not a phone.
        getChatListRows: () => {
          const root = document.querySelector('#pane-side') || document.querySelector('[data-testid="chat-list"]');
          if (!root) return [];
          const phoneLike = (value) => {
            const raw = String(value || '').replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, '').trim();
            if (!raw || /@lid/i.test(raw)) return '';
            const jid = raw.match(/(\d{8,15})@(?:c\.us|s\.whatsapp\.net)/i);
            if (jid) return jid[1];
            const phoneMatch = raw.match(/(?:\+?91[\s\-]?)?[6-9]\d{4}[\s\-]?\d{5}|\+?\d{1,3}[\s\-]?\d{8,14}/);
            if (phoneMatch) {
              const digits = phoneMatch[0].replace(/\D/g, '');
              if (digits.length === 10 && /^[6-9]/.test(digits)) return '91' + digits;
              if (digits.length >= 8 && digits.length <= 15) return digits;
            }
            if (!/[a-z]/i.test(raw)) {
              const digits = raw.replace(/\D/g, '');
              if (digits.length >= 8 && digits.length <= 15) return digits;
            }
            return '';
          };
          const ROW_SEL = '[role="listitem"], [role="row"], [data-testid="cell-frame-container"]';
          const TIME_RE = /^(\d{1,2}:\d{2}(\s*[ap]\.?\s?m\.?)?|yesterday|today|monday|tuesday|wednesday|thursday|friday|saturday|sunday|\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4})$/i;
          const rows = root.querySelectorAll(ROW_SEL);
          const out = [];
          rows.forEach((el, index) => {
            // A chat is often matched twice (listitem > row). Keep the outer one only;
            // index still matches openUnreadRow's node list.
            const outer = el.parentElement && el.parentElement.closest(ROW_SEL);
            if (outer && root.contains(outer)) return;
            const titleEl = el.querySelector('[data-testid="cell-frame-title"]')
              || el.querySelector('span[title][dir="auto"]')
              || el.querySelector('span[dir="auto"]');
            let title = (titleEl?.getAttribute('title') || titleEl?.textContent || '').replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, '').trim();
            if (!title) return;
            // Clean phone titles (e.g. +91 98765 43210Friday...) by keeping the lead phone digits
            const leadPhone = title.match(/^(\+?\d[\d\s\-]{7,16}\d)/);
            if (leadPhone) {
              title = leadPhone[1].trim();
            }
            let time = '';
            el.querySelectorAll('div, span').forEach(node => {
              if (time || node.children.length) return;
              const text = (node.textContent || '').trim();
              if (text && text.length <= 12 && TIME_RE.test(text)) time = text;
            });
            let preview = '';
            el.querySelectorAll('span[title], span[dir="auto"], span[dir="ltr"]').forEach(span => {
              if (preview) return;
              if (titleEl && (span === titleEl || titleEl.contains(span) || span.contains(titleEl))) return;
              const text = (span.getAttribute('title') || span.textContent || '').replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, '').trim();
              if (!text || text === title || text === time || TIME_RE.test(text)) return;
              preview = text;
            });
            const dataId = [
              el.getAttribute('data-id'),
              el.getAttribute('data-chat-id'),
              el.querySelector('[data-id]')?.getAttribute('data-id') || '',
              el.querySelector('[data-chat-id]')?.getAttribute('data-chat-id') || ''
            ].join(' ');
            const labels = [el.getAttribute('aria-label') || ''];
            el.querySelectorAll('[aria-label]').forEach(node => {
              labels.push(node.getAttribute('aria-label') || '');
            });
            const labelText = labels.join(' | ');
            const icons = Array.from(el.querySelectorAll('[data-icon]')).map(n => n.getAttribute('data-icon') || '').join(' ');

            // If preview text is empty but an attachment/media icon is in the row, label it
            if (!preview) {
              if (icons.includes('status-image') || icons.includes('image') || /photo|image/i.test(labelText)) preview = '📷 [Photo]';
              else if (icons.includes('audio') || icons.includes('ptt') || /voice|audio/i.test(labelText)) preview = '🎤 [Voice message]';
              else if (icons.includes('video') || /video/i.test(labelText)) preview = '🎥 [Video]';
              else if (icons.includes('document') || /document/i.test(labelText)) preview = '📄 [Document]';
            }

            const badge = el.querySelector('[data-testid="icon-unread-count"], [data-testid="cell-frame-unread"], [data-icon*="unread" i], [data-testid*="unread" i]');
            let unreadCount = 0;
            const countMatch = labelText.match(/(\d+)\s+unread/i);
            if (countMatch) unreadCount = parseInt(countMatch[1], 10) || 0;
            else if (badge && /^\d{1,4}$/.test((badge.textContent || '').trim())) unreadCount = parseInt(badge.textContent.trim(), 10) || 0;
            const unread = !!badge || /\bunread\b/i.test(labelText) || unreadCount > 0;
            // Last message was ours (tick icons or "You:"). Not an incoming preview.
            const outgoing = /status-(check|dblcheck|time)|msg-(check|dblcheck|time)|(^|\s)(check|dblcheck)(\s|$)/i.test(icons)
              || /^you:/i.test(preview)
              || labels.some(label => /^(read|delivered|sent|pending)$/i.test(String(label).trim()));
            const groupHint = /default-group|community/i.test(icons);
            const rowText = String(el.innerText || el.textContent || '').replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, '').replace(/\s+/g, ' ').trim().slice(0, 400);
            let lidId = '';
            const lidMatch = String(dataId).match(/(\d+)@lid/i);
            if (lidMatch) lidId = (lidMatch[1] + '@lid').toLowerCase();
            const resolvedPhone = phoneLike(dataId) || phoneLike(title) || phoneLike(labelText);
            out.push({
              index,
              title: title.slice(0, 80),
              preview: preview.slice(0, 200),
              time,
              rowText,
              phone: resolvedPhone,
              wa_chat_id: lidId || (resolvedPhone ? resolvedPhone + '@c.us' : ''),
              unread,
              unreadCount,
              outgoing,
              groupHint
            });
          });
          return out;
        },

        // Structure-only probe of the first rows (no names, no message text). Used to
        // check unread/preview selectors against the live build. Read-only.
        probeChatList: () => {
          const root = document.querySelector('#pane-side') || document.querySelector('[data-testid="chat-list"]');
          if (!root) return { root: false };
          const ROW_SEL = '[role="listitem"], [role="row"], [data-testid="cell-frame-container"]';
          const all = root.querySelectorAll(ROW_SEL);
          const keep = (label) => /unread|read|delivered|sent|pinned|muted|message/i.test(label)
            ? String(label).replace(/\d+/g, 'N').slice(0, 40) : '';
          const sample = [];
          for (let i = 0; i < all.length && sample.length < 4; i++) {
            const el = all[i];
            sample.push({
              role: el.getAttribute('role') || '',
              nested: !!(el.parentElement && el.parentElement.closest(ROW_SEL)),
              testids: Array.from(new Set(Array.from(el.querySelectorAll('[data-testid]')).map(n => n.getAttribute('data-testid')))).slice(0, 12),
              icons: Array.from(new Set(Array.from(el.querySelectorAll('[data-icon]')).map(n => n.getAttribute('data-icon')))).slice(0, 12),
              labels: Array.from(new Set(Array.from(el.querySelectorAll('[aria-label]')).map(n => keep(n.getAttribute('aria-label') || '')).filter(Boolean))).slice(0, 6),
              titledSpans: el.querySelectorAll('span[title]').length
            });
          }
          return { root: true, rows: all.length, scrollTop: Math.round(root.scrollTop || 0), sample };
        },

        // Unread chat-list rows. A row often has a name and no phone; index lets
        // the bridge click that row and read the number from the open thread.
        getUnreadChatPhones: () => {
          const root = document.querySelector('#pane-side') || document.querySelector('[data-testid="chat-list"]');
          if (!root) return [];
          const phoneLike = (value) => {
            const raw = String(value || '').trim();
            if (!raw) return '';
            const jid = raw.match(/(\d{8,15})@c\.us/i);
            if (jid) return jid[1];
            if (/[a-z]/i.test(raw)) return '';
            const digits = raw.replace(/\D/g, '');
            if (digits.length >= 8 && digits.length <= 15) return digits;
            return '';
          };
          const rows = root.querySelectorAll('[role="listitem"], [role="row"], [data-testid="cell-frame-container"]');
          const out = [];
          const seen = new Set();
          rows.forEach((el, index) => {
            const badge = el.querySelector('[data-testid="icon-unread-count"], [data-testid="cell-frame-unread"], span[aria-label*="unread" i]');
            const rowLabel = el.getAttribute('aria-label') || '';
            const gridLabel = el.querySelector('[role="gridcell"]')?.getAttribute('aria-label') || '';
            if (!badge && !/unread/i.test(rowLabel) && !/unread/i.test(gridLabel)) return;

            const titleEl = el.querySelector('[data-testid="cell-frame-title"], span[title], span[dir="auto"]');
            const title = titleEl?.getAttribute('title') || titleEl?.textContent || '';
            if (/^(archived|unread|all|favourites|favorites|groups|communities|locked chats|status|updates)$/i.test(String(title).trim())) return;
            const subtitleEl = el.querySelector('[data-testid="cell-frame-secondary"], [data-testid="cell-frame-subtitle"]');
            const subtitle = subtitleEl?.getAttribute('title') || subtitleEl?.textContent || '';
            const dataId = [
              el.getAttribute('data-id'),
              el.getAttribute('data-chat-id'),
              el.querySelector('[data-id]')?.getAttribute('data-id') || '',
              el.querySelector('[data-chat-id]')?.getAttribute('data-chat-id') || ''
            ].join(' ');
            const phone = phoneLike(dataId) || phoneLike(title) || phoneLike(subtitle);
            const key = phone || ('row:' + index);
            if (seen.has(key)) return;
            seen.add(key);
            out.push({ index, phone: phone || '', title: String(title).trim().slice(0, 80) });
          });
          return out;
        },
        
        // Get contact info for current chat
        getContactInfo: () => {
          const header = document.querySelector('[data-testid="conversation-header"]')
            || document.querySelector('#main header');
          const urlPhone = (location.href.match(/[?&]phone=(\d{6,15})/) || [])[1] || '';
          const phoneLike = (value) => {
            const raw = String(value || '').replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, '').trim();
            if (!raw) return '';
            const jid = raw.match(/(\d{8,15})@(?:c\.us|s\.whatsapp\.net)/i);
            if (jid) return jid[1];
            const phoneMatch = raw.match(/(?:\+?91[\s\-]?)?[6-9]\d{4}[\s\-]?\d{5}|\+?\d{1,3}[\s\-]?\d{8,14}/);
            if (phoneMatch) {
              const digits = phoneMatch[0].replace(/\D/g, '');
              if (digits.length === 10 && /^[6-9]/.test(digits)) return '91' + digits;
              if (digits.length >= 8 && digits.length <= 15) return digits;
            }
            if (!/[a-z]/i.test(raw)) {
              const digits = raw.replace(/\D/g, '');
              if (digits.length >= 8 && digits.length <= 15) return digits;
            }
            return '';
          };
          if (!header) {
            if (!urlPhone) return null;
            return { name: '', avatar: '', status: '', wa_chat_id: urlPhone + '@c.us' };
          }
          
          const nameEl = header.querySelector('[data-testid="conversation-info-header-chat-title"], [data-testid="conversation-header-name"], span[title]');
          const avatarEl = header.querySelector('img[src*="blob:"], img[src*="https://pps.whatsapp.net"]');
          const statusEl = header.querySelector('[data-testid="conversation-header-subtitle"], [data-testid="conversation-header-status"], ._amig');
          const headerText = (header.innerText || '').replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, '');
          const name = (nameEl?.getAttribute('title') || nameEl?.textContent || '').replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, '').trim();
          const status = (statusEl?.textContent || '').replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, '').trim();
          const isSelf = /message yourself|\(you\)/i.test(headerText);
          let waChatId = '';
          if (urlPhone) waChatId = urlPhone + '@c.us';
          const headerId = header.getAttribute('data-id') || header.querySelector('[data-id]')?.getAttribute('data-id') || '';
          const headerJid = headerId.match(/(\d{8,15})@(?:c\.us|s\.whatsapp\.net)/i);
          if (!waChatId && headerJid) waChatId = headerJid[1] + '@c.us';
          if (!waChatId) {
            const fromStatus = phoneLike(status);
            const fromName = phoneLike(name);
            const fromHeaderText = phoneLike(headerText);
            if (fromStatus) waChatId = fromStatus + '@c.us';
            else if (fromName) waChatId = fromName + '@c.us';
            else if (fromHeaderText) waChatId = fromHeaderText + '@c.us';
          }
          if (!waChatId) {
            // Check active sidebar row
            const activeRow = document.querySelector('#pane-side [aria-selected="true"], #pane-side .active');
            if (activeRow) {
              const rId = activeRow.getAttribute('data-id') || activeRow.getAttribute('data-chat-id') || '';
              const rJid = rId.match(/(\d{8,15})@(?:c\.us|s\.whatsapp\.net)/i);
              if (rJid) waChatId = rJid[1] + '@c.us';
              if (!waChatId) {
                const rPhone = phoneLike(activeRow.innerText);
                if (rPhone) waChatId = rPhone + '@c.us';
              }
            }
          }
          
          return {
            name,
            avatar: avatarEl?.src || '',
            status,
            wa_chat_id: waChatId,
            is_self: isSelf
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
        
        // Check if logged in — covers modern WhatsApp Web layouts, landing screen, and sidebar markers
        isLoggedIn: () => {
          const hasChatList = !!document.querySelector(
            '#pane-side, [data-testid="chat-list"], [aria-label="Chat list"], div[role="grid"][aria-label*="chat" i], div[data-testid="chatlist-header"], #side, div[data-testid="intro-title"], header [aria-label*="Profile" i], header [aria-label*="Status" i], header [aria-label*="New chat" i], header [aria-label*="Menu" i], [data-tab="3"]'
          );
          const hasMain = !!document.querySelector('#main, [data-testid="conversation-panel-wrapper"], [data-testid="conversation-compose-box-input"]');
          const hasComposer = !!document.querySelector('footer [contenteditable="true"], [data-testid="conversation-compose-box-input"]');
          return hasChatList || hasMain || hasComposer;
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
          const hasChatList = !!document.querySelector(
            '#pane-side, [data-testid="chat-list"], [aria-label="Chat list"], div[role="grid"][aria-label*="chat" i], div[data-testid="chatlist-header"], #side, div[data-testid="intro-title"], header [aria-label*="Profile" i], header [aria-label*="Status" i], header [aria-label*="New chat" i], header [aria-label*="Menu" i], [data-tab="3"]'
          );
          const hasMain = !!document.querySelector('#main, [data-testid="conversation-panel-wrapper"], [data-testid="conversation-compose-box-input"]');
          const hasComposer = !!document.querySelector('footer [contenteditable="true"], [data-testid="conversation-compose-box-input"]');
          const bridgeLogged = !!(window.WABridge && window.WABridge.isLoggedIn && window.WABridge.isLoggedIn());
          const logged = hasChatList || hasMain || hasComposer || bridgeLogged;

          const qr = (window.WABridge && window.WABridge.getQR) ? window.WABridge.getQR() : null;
          const body = document.body ? (document.body.innerText || '') : '';

          // Detect active sync or loading progress after scan
          const isSyncing = !!document.querySelector('progress, [role="progressbar"], [data-testid="loading-screen"]') ||
            /loading your chats|organizing messages|downloading messages|connecting\.\.\.|syncing/i.test(body);

          // Look for a SHORT, visible reload label on an expired QR — not a huge
          // container whose innerText happens to include those words.
          const reloadBtn = Array.from(document.querySelectorAll('button, [role="button"], span'))
            .find(el => {
              if (!el || el.offsetParent === null) return false;
              const txt = (el.innerText || el.getAttribute('aria-label') || '').trim();
              if (!txt || txt.length > 64) return false;
              return /^(?:click|select|tap)\s+to\s+reload(?:\s+qr(?:\s*code)?)?$|^reload\s*qr(?:\s*code)?$|^refresh\s*qr(?:\s*code)?$|^qr\s*code\s*expired$/i.test(txt);
            });
          const expired = !!reloadBtn;

          // Check if on phone pairing or error screen offering "Log in with QR code"
          const qrLoginBtn = Array.from(document.querySelectorAll('button, [role="button"], span, div'))
            .find(el => {
              const txt = (el.innerText || el.getAttribute('aria-label') || '').trim();
              return /^(?:log\s*in|link)\s+with\s+qr(?:\s*code)?$/i.test(txt);
            });
          const hasLogInWithQR = !logged && !qr && !!qrLoginBtn;

          let linkError = '';
          const errMatch = body.match(/(couldn['’]t link[^\n]*|unable to link[^\n]*|something went wrong[^\n]*|try again later[^\n]*)/i);
          if (errMatch) linkError = errMatch[0].trim().slice(0, 160);
          return { logged, qr, expired, hasLogInWithQR, linkError, isSyncing };
        });

        // Keep bridge heartbeat alive and surface sync status during scan/handshake
        await this.apiPost('api_whatsapp_session.php?action=poll', {
          last_poll_at: new Date().toISOString(),
          is_syncing: (state.isSyncing || !!this.linkingSince) ? 1 : 0
        }).catch(() => {});

        // 1) Logged in — done
        if (state.logged) {
          clearInterval(this.sessionWatchTimer);
          this.sessionWatchTimer = null;
          this.log('info', 'WhatsApp login detected');
          await this.onLoggedIn();
          return;
        }

        // Return to QR screen if stuck on phone pairing or error screen
        if (!state.qr && state.hasLogInWithQR && !this.pairingActive) {
          const since = Date.now() - (this.lastQrSwitchClickAt || 0);
          if (since > 15000) {
            this.lastQrSwitchClickAt = Date.now();
            this.log('info', 'Found "Log in with QR code" button — switching back to QR view...');
            const qrBtn = await this.page.$('[data-testid="link_device_phone_number_qr_link"], [data-testid="link-device-qrcode-alt-linking-tc"]');
            if (qrBtn) {
              await qrBtn.click().catch(() => {});
            } else {
              await this.page.evaluate(() => {
                const btn = Array.from(document.querySelectorAll('button, [role="button"], span, div'))
                  .find(el => /(?:log\s*in|link)\s+with\s+qr/i.test((el.innerText || el.getAttribute('aria-label') || '').trim()));
                if (btn) (btn.closest('button, [role="button"], div[role="button"]') || btn).click();
              }).catch(() => {});
            }
            await new Promise(r => setTimeout(r, 2000));
            await this.captureQR();
          }
          return;
        }

        // If QR expired, click the real reload control — with a cooldown so we
        // do not rotate the code while the user is mid-scan. Never click the
        // canvas as a fallback (that used to force a useless refresh loop).
        if (state.expired) {
          const since = Date.now() - (this.lastQrRefreshClickAt || 0);
          if (since < 25000) {
            if (state.qr) await this.captureQR();
            return;
          }
          this.lastQrRefreshClickAt = Date.now();
          this.log('info', 'QR expired on screen — clicking to refresh...');
          const clicked = await this.page.evaluate(() => {
            const btn = Array.from(document.querySelectorAll('button, [role="button"], span'))
              .find(el => {
                if (!el || el.offsetParent === null) return false;
                const txt = (el.innerText || el.getAttribute('aria-label') || '').trim();
                if (!txt || txt.length > 64) return false;
                return /^(?:click|select|tap)\s+to\s+reload(?:\s+qr(?:\s*code)?)?$|^reload\s*qr(?:\s*code)?$|^refresh\s*qr(?:\s*code)?$|^qr\s*code\s*expired$/i.test(txt);
              });
            if (btn) { (btn.closest('button,[role="button"]') || btn).click(); return true; }
            return false;
          });
          if (!clicked) {
            this.log('warn', 'QR looked expired but no reload control was found — leaving canvas alone');
          }
          await new Promise(r => setTimeout(r, 2000));
          await this.captureQR();
          return;
        }

        // Surface WhatsApp's own linking errors (e.g. too many linked devices)
        if (state.linkError && state.linkError !== this.lastLinkError) {
          this.lastLinkError = state.linkError;
          this.log('warn', 'WhatsApp says: ' + state.linkError);
          // Dismiss "something went wrong" dialog if present
          if (/something went wrong/i.test(state.linkError)) {
            await this.page.evaluate(() => {
              const okBtn = Array.from(document.querySelectorAll('button, [role="button"]'))
                .find(el => /^(?:ok|close|dismiss)$/i.test((el.innerText || '').trim()));
              if (okBtn) okBtn.click();
            });
            await new Promise(r => setTimeout(r, 1000));
          }
        }

        // 2) A QR is on screen. Keep it fresh, but NEVER reload while one is
        //    visible — reloading aborts an in-progress scan handshake.
        if (state.qr) {
          this.linkingSince = null;
          if (state.qr !== this.lastQRBase64 || Date.now() - (this.lastQrCaptureAt || 0) > 25000) {
            this.lastQRBase64 = state.qr;
            await this.captureQR();
          }
          return;
        }

        // 3) No QR and not logged in — a scan may be in progress (WhatsApp shows a loading screen)
        if (!this.linkingSince) {
          this.linkingSince = Date.now();
          this.log('info', 'QR no longer visible — scan detected, waiting for login/sync handshake...');
          return;
        }

        // If WhatsApp Web is actively syncing or downloading messages from phone, do not abort
        if (state.isSyncing) {
          this.log('info', 'WhatsApp is syncing messages from phone... waiting for sync to complete');
          if (Date.now() - this.linkingSince < 300000) {
            return;
          }
        }

        // Allow up to 180s for handshake before reloading
        if (Date.now() - this.linkingSince < 180000) {
          const elapsed = Math.round((Date.now() - this.linkingSince) / 1000);
          if (elapsed % 15 === 0) {
            this.log('info', `Waiting for login handshake (${elapsed}s elapsed)...`);
          }
          return;
        }

        this.linkingSince = null;
        this.log('warn', 'No QR and no login after 180s — reloading WhatsApp Web');
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
    this.linkingSince = null;
    this.didInitialListPass = false;
    this.chatListPrimed = false;
    this.listBackfill = [];
    this.rowOpenFail = new Map();
    
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

    // Remember recent sends so a reply in that chat is scraped even with no unread badge.
    await this.seedOutboundWatch();

    // Send-on-press: drain only fresh user-pressed rows. Stale ones are
    // marked failed by the queue API (user presses Retry). Do not flush backlog.
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
  
  async seedOutboundWatch() {
    for (const status of ['processing', 'sent']) {
      try {
        const response = await this.apiGet('api_whatsapp_outbound_queue.php', { status, limit: 10 });
        const rows = (response && response.data) || [];
        for (const row of rows) {
          if (!row || !row.wa_chat_id) continue;
          this.rememberOutboundChat(row.wa_chat_id);
          if (row.body) this.rememberOutboundBody(row.wa_chat_id, row.body);
        }
      } catch (e) {
        this.log('debug', 'Could not seed recent chats', e.message);
      }
    }
    if (this.outboundWatch.length) {
      this.log('info', `Watching ${this.outboundWatch.length} recent chat(s) for replies`);
    }
  }

  async syncChats() {
    this.log('info', 'Syncing chats...');
    if (!this.hasBridgeSecret()) return;
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
        if (!chat.wa_chat_id) {
          filtered++;
          continue;
        }
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
    
    this._selfPollTick = 0;
    this.pollTickRunning = false;
    this.pollInterval = setInterval(async () => {
      // One tick at a time. Overlapping ticks could click/type into the page concurrently.
      if (this.pollTickRunning) return;
      this.pollTickRunning = true;
      try {
        // Drain outbound first so hub sends are not stuck behind chat-list scraping.
        await this.processOutboundQueue();
        await this.pollMessages();
        this._selfPollTick = (this._selfPollTick || 0) + 1;
        // Every ~5th cycle (~15s at 3s poll) re-open Message yourself so phone-typed
        // notes are ingested even when WhatsApp shows no unread badge.
        if (this._selfPollTick % 5 === 0) {
          await this.pollSelfChat();
        }
        await this.checkCommandFlags();
      } catch (e) {
        this.log('error', 'Polling error', e.message);
      } finally {
        this.pollTickRunning = false;
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
  
  hasBridgeSecret() {
    const secret = this.config.bridgeSecret;
    return typeof secret === 'string' && secret.length >= 16;
  }

  rememberCapped(set, value, cap) {
    if (!value) return;
    set.add(value);
    while (set.size > cap) {
      const oldest = set.values().next().value;
      set.delete(oldest);
    }
  }

  rememberIngested(waMsgId) {
    if (!waMsgId) return;
    this.rememberCapped(this.ingestedMsgIds, String(waMsgId), 2000);
  }

  rememberOutboundChat(waChatId) {
    const id = String(waChatId || '').trim().toLowerCase();
    if (!id) return;
    this.rememberCapped(this.recentOutboundChatIds, id, 2000);
    const normalized = this.jidToWaChatId(id) || id;
    this.rememberCapped(this.recentOutboundChatIds, normalized, 2000);
    const digits = normalized.split('@')[0].replace(/\D/g, '');
    if (digits.length >= 6 && digits.length <= 15) {
      this.rememberCapped(this.recentOutboundChatIds, digits + '@c.us', 2000);
    }
    if (!normalized) return;
    this.outboundWatch = this.outboundWatch.filter(item => item !== normalized);
    this.outboundWatch.push(normalized);
    if (this.outboundWatch.length > 20) this.outboundWatch.shift();
  }

  rememberOutboundBody(waChatId, body) {
    const id = this.jidToWaChatId(waChatId) || String(waChatId || '').trim().toLowerCase();
    const text = String(body || '').trim();
    if (!id || !text) return;
    const key = id + '\n' + text;
    this.recentOutboundBodies.set(key, Date.now());
    this.outboundEchoSeen.delete(key);
    for (const [savedKey, at] of this.recentOutboundBodies) {
      if (Date.now() - at > 30 * 60 * 1000) {
        this.recentOutboundBodies.delete(savedKey);
        this.outboundEchoSeen.delete(savedKey);
      }
    }
  }

  // The first on-screen copy of a text we just sent is the bridge's own bubble.
  takeOutboundEcho(waChatId, body) {
    const id = this.jidToWaChatId(waChatId) || String(waChatId || '').trim().toLowerCase();
    const text = String(body || '').trim();
    const key = id + '\n' + text;
    const at = this.recentOutboundBodies.get(key);
    if (!at || Date.now() - at > 30 * 60 * 1000) return false;
    if (this.outboundEchoSeen.has(key)) return false;
    this.outboundEchoSeen.add(key);
    return true;
  }

  noticeOnce(key, level, message, intervalMs = 60000) {
    const now = Date.now();
    const prev = this.ingestNotices.get(key) || 0;
    if (now - prev < intervalMs) return;
    this.ingestNotices.set(key, now);
    this.log(level, message);
  }

  mysqlDateTime(date = new Date()) {
    const parts = new Intl.DateTimeFormat('en-GB', {
      timeZone: 'Asia/Kolkata',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hourCycle: 'h23'
    }).formatToParts(date);
    const part = (type) => {
      const found = parts.find(item => item.type === type);
      return found ? found.value : '00';
    };
    return `${part('year')}-${part('month')}-${part('day')} ${part('hour')}:${part('minute')}:${part('second')}`;
  }

  // @c.us / @s.whatsapp.net become 91xxxxxxxxxx@c.us.
  // @lid is not a phone, but it IS a stable chat id — keep it so we can show
  // and reply to chats that have no phone number yet.
  jidToWaChatId(jid) {
    const raw = String(jid || '').trim().toLowerCase();
    if (!raw) return '';
    if (raw.includes('@g.us')) {
      const group = raw.match(/(\d+(?:-\d+)?@g\.us)/);
      return group ? group[1] : raw;
    }
    if (raw.includes('broadcast') || raw.includes('newsletter')) return raw;
    if (raw.includes('@lid')) {
      // Message data-ids look like true_<lid>@lid_<msgid> — keep only digits@lid
      const lid = raw.match(/(\d+)@lid/);
      return lid ? (lid[1] + '@lid') : '';
    }
    const digits = waListRules.phoneDigitsFromJid(raw);
    return digits ? digits + '@c.us' : '';
  }

  phoneDigitsFromLabel(value) {
    const raw = String(value || '').replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, '').trim();
    if (!raw) return '';
    const jid = raw.match(/(\d{8,15})@c\.us/i);
    if (jid) return this.normalizePhoneDigits(jid[1]);
    const phoneMatch = raw.match(/(?:\+?91[\s\-]?)?[6-9]\d{4}[\s\-]?\d{5}|\+?\d{1,3}[\s\-]?\d{8,14}/);
    if (phoneMatch) {
      const digits = phoneMatch[0].replace(/\D/g, '');
      if (digits.length === 10 && /^[6-9]/.test(digits)) return '91' + digits;
      const normalized = this.normalizePhoneDigits(digits);
      if (normalized) return normalized;
    }
    if (!/[a-z]/i.test(raw)) {
      return this.normalizePhoneDigits(raw.replace(/\D/g, ''));
    }
    return '';
  }

  // Match waValidateWhatsAppPhone: 10-digit Indian mobiles are stored as 91 + digits.
  normalizePhoneDigits(digits) {
    return waListRules.normalizePhoneDigits(digits);
  }

  saneTimestamp(value) {
    const n = Number(value);
    if (Number.isFinite(n) && n > 1000000000 && n < 20000000000) return Math.floor(n);
    return Math.floor(Date.now() / 1000);
  }

  // URL phone= wins, then the jid parsed from a message data-id, then the header.
  resolveWaChatId(contactInfo, messageJid = '') {
    let url = '';
    try { url = this.page && this.page.url ? this.page.url() : ''; } catch (e) { url = ''; }
    const fromUrl = String(url).match(/[?&]phone=(\d{6,15})/);
    if (fromUrl) {
      const digits = this.normalizePhoneDigits(fromUrl[1]);
      if (digits) return digits + '@c.us';
    }

    const fromMessage = this.jidToWaChatId(messageJid);
    if (fromMessage) return fromMessage;

    const direct = contactInfo && contactInfo.wa_chat_id ? String(contactInfo.wa_chat_id).trim().toLowerCase() : '';
    const fromHeader = this.jidToWaChatId(direct);
    if (fromHeader) return fromHeader;

    const fromStatus = this.phoneDigitsFromLabel(contactInfo && contactInfo.status);
    if (fromStatus) return fromStatus + '@c.us';
    const fromName = this.phoneDigitsFromLabel(contactInfo && contactInfo.name);
    if (fromName) return fromName + '@c.us';

    // Name match against known chats / roster
    if (contactInfo && contactInfo.name) {
      const cleanName = String(contactInfo.name).trim().toLowerCase();
      if (this.privacyFilter.nameToJidMap && this.privacyFilter.nameToJidMap.has(cleanName)) {
        return this.privacyFilter.nameToJidMap.get(cleanName);
      }
    }

    // "Message yourself" has no phone in the header. It is the linked account.
    if (contactInfo && contactInfo.is_self && this.phoneNumber) {
      const selfDigits = this.normalizePhoneDigits(String(this.phoneNumber));
      if (selfDigits) return selfDigits + '@c.us';
    }
    return '';
  }

  async scrollOpenChatToEnd() {
    if (!this.page) return;
    try {
      await this.page.evaluate(() => {
        const nodes = Array.from(document.querySelectorAll('#main div'));
        let best = null;
        let bestHeight = 0;
        for (const node of nodes) {
          if (node.scrollHeight > node.clientHeight + 40 && node.clientHeight > 200 && node.scrollHeight > bestHeight) {
            best = node;
            bestHeight = node.scrollHeight;
          }
        }
        if (best) best.scrollTop = best.scrollHeight;
      });
      await new Promise(r => setTimeout(r, 100));
    } catch (e) {}
  }

  noteParentIngest() {
    this.lastIngestAt = new Date().toISOString();
    this.lastSkipNote = '';
  }

  noteSkip(note) {
    this.lastSkipNote = String(note || '').slice(0, 240);
  }

  // The open thread often has only a @lid. The contact drawer is where WhatsApp
  // still shows the real number. Never treat the linked school number as the parent.
  async readPhoneFromContactDrawer() {
    if (!this.page) return '';
    const own = this.normalizePhoneDigits(this.phoneNumber || '');
    try {
      const opened = await this.page.evaluate(() => {
        const header = document.querySelector('#main header');
        if (!header) return false;
        const target = header.querySelector(
          '[data-testid="conversation-info-header-chat-title"], [data-testid="conversation-info-header"], [role="button"], img'
        ) || header;
        target.click();
        return true;
      });
      if (!opened) return '';
      await new Promise(r => setTimeout(r, 600));
      const rawNumbers = await this.page.evaluate(() => {
        const drawer = document.querySelector(
          '[data-testid="chat-info-drawer"], [data-testid="contact-info-drawer"], [data-testid="drawer-right"], section[data-testid="drawer-right"], [data-testid="contact-info"], div[role="region"]'
        );
        if (!drawer) return [];
        return (drawer.innerText || '').match(/\+?\d[\d\s\-()]{8,16}\d/g) || [];
      });
      await this.page.keyboard.press('Escape').catch(() => {});
      await new Promise(r => setTimeout(r, 200));
      for (const raw of rawNumbers) {
        const digits = this.normalizePhoneDigits(String(raw).replace(/\D/g, ''));
        if (digits && digits !== own) return digits;
      }
    } catch (e) {
      this.log('debug', 'Contact drawer phone read failed', e.message);
    }
    return '';
  }

  // Mask long digit runs (phone numbers) before they reach the log.
  maskForLog(value) {
    return String(value || '').replace(/\d{6,}/g, (m) => '***' + m.slice(-4));
  }

  /**
   * Incoming capture from the sidebar (2026-11-06). Opens chats ONLY by clicking the
   * row (or the guarded name search). Never types a phone number, never presses Enter,
   * never sends. A chat that cannot be opened keeps its list preview instead.
   */
  async ingestVisibleChats(limit = 3) {
    if (this.sending || !this.page || !this.hasBridgeSecret()) return;
    // New activity appears at the TOP of the list. Clicking a lower row scrolls the
    // pane and WhatsApp only renders rows near the viewport, so go back to the top.
    try {
      await this.page.evaluate(() => {
        const pane = document.querySelector('#pane-side');
        if (pane && pane.scrollTop > 0) pane.scrollTop = 0;
      });
    } catch (e) {}

    let rows = [];
    try {
      rows = await this.page.evaluate(() => {
        if (!window.WABridge || !window.WABridge.getChatListRows) return [];
        return window.WABridge.getChatListRows();
      });
    } catch (e) {
      this.log('debug', 'Chat list scrape failed', e.message);
      return;
    }

    const chats = (rows || []).filter(row => row && row.title && !waListRules.isSystemChatTitle(row.title));
    const withPhone = chats.filter(row => this.normalizePhoneDigits(row.phone || '')).length;
    const unreadCount = chats.filter(row => row.unread).length;
    const summary = `Chat list: ${chats.length} rows, ${withPhone} with a phone, ${unreadCount} unread`;
    if (summary !== this.lastChatListSummary) {
      this.lastChatListSummary = summary;
      this.log('info', summary);
    } else {
      this.noticeOnce('chat-list-summary', 'info', summary, 60000);
    }

    if (!this.didInitialListPass && this.page) {
      try {
        const probe = await this.page.evaluate(() => (window.WABridge && window.WABridge.probeChatList) ? window.WABridge.probeChatList() : null);
        if (probe) this.log('info', 'Chat-list probe (structure only): ' + this.maskForLog(JSON.stringify(probe)).slice(0, 1500));
      } catch (e) {}
    }

    try {
      await this.ingestOpenChat();
    } catch (e) {
      this.log('debug', 'Open-chat ingest failed', e.message);
    }

    if (!this.chatSigByTitle) this.chatSigByTitle = new Map();
    if (!this.rowOpenFail) this.rowOpenFail = new Map();
    if (!this.didInitialListPass) {
      // After login, walk the top of the list a few rows per cycle so messages that
      // arrived while the bridge was offline (already read on the phone) still land.
      const keys = [];
      for (const row of chats) {
        const key = waListRules.titleKey(row.title);
        if (!keys.includes(key)) keys.push(key);
        if (keys.length >= 12) break;
      }
      this.listBackfill = keys;
      this.didInitialListPass = true;
    }

    // Opening a chat clears its badge, which changes the row. That is our own doing,
    // not a new message: absorb it for 20s. The open chat itself is re-read every cycle.
    if (!this.recentlyOpened) this.recentlyOpened = new Map();
    for (const row of chats) {
      const key = waListRules.titleKey(row.title);
      const at = this.recentlyOpened.get(key) || 0;
      if (!at || Date.now() - at > 20000 || row.unread) continue;
      const sig = waListRules.rowSignature(row);
      if (sig !== null) this.chatSigByTitle.set(key, sig);
    }

    const failUntil = new Map();
    for (const [key, info] of this.rowOpenFail) failUntil.set(key, info.until || 0);
    const plan = waListRules.planListActions(chats, {
      prevSigs: this.chatSigByTitle,
      primed: this.chatListPrimed,
      backfill: this.listBackfill || [],
      failUntil
    }, { now: Date.now(), limit });

    // Remember signatures for rows we are not acting on; acted-on rows are only
    // remembered once their content is stored (so a failure is retried later).
    const pendingKeys = new Set([...plan.toOpen, ...plan.deferred].map(item => item.key));
    for (const [key, sig] of plan.sigs) {
      if (sig === null) continue;
      if (!pendingKeys.has(key)) this.chatSigByTitle.set(key, sig);
    }
    this.chatListPrimed = true;

    for (const item of plan.deferred) {
      if (!item.backoff || item.reason === 'backfill') continue;
      // Still capture what the list shows while the open is backing off.
      const stored = await this.ingestRowPreview(item.row, item.reason);
      if (stored) this.chatSigByTitle.set(item.key, item.sig);
    }

    for (const item of plan.toOpen) {
      if (this.sending) break;
      const { row, reason, key } = item;
      if (reason === 'backfill' && Array.isArray(this.listBackfill)) {
        this.listBackfill = this.listBackfill.filter(k => k !== key);
      }
      const phone = this.normalizePhoneDigits(row.phone || '') || this.phoneDigitsFromLabel(row.title);
      let openedOk = false;
      let how = '';
      if (Number.isInteger(row.index)) {
        openedOk = await this.openUnreadRow(row.index, row.title);
        if (openedOk) how = 'row click';
      }
      if (!openedOk && !this.sending) {
        openedOk = await this.openChatByTitle(row.title);
        if (openedOk) how = 'name search';
      }
      if (!openedOk && phone && !this.sending) {
        openedOk = await this.openChat(phone + '@c.us');
        if (openedOk) how = 'deep link';
      }
      if (this.sending) break;
      const label = this.maskForLog(row.title);
      if (!openedOk) {
        const prev = this.rowOpenFail.get(key) || { count: 0 };
        const count = prev.count + 1;
        this.rowOpenFail.set(key, { count, until: Date.now() + Math.min(30000, waListRules.nextBackoffMs(count)) });
        if (reason === 'backfill') continue;
        const stored = await this.ingestRowPreview(row, reason);
        if (stored) this.chatSigByTitle.set(key, item.sig);
        this.noticeOnce('open-fail:' + key, 'warn',
          `Could not open chat "${label}" (${reason}) — ${stored ? 'stored the list preview instead' : 'list preview not stored (no number for this chat)'}`, 60000);
        continue;
      }
      this.rowOpenFail.delete(key);
      this.recentlyOpened.set(key, Date.now());
      let result = null;
      try {
        result = await this.ingestOpenChat(phone ? phone + '@c.us' : '');
      } catch (e) {
        this.log('debug', 'Row ingest failed', e.message);
      }
      if (item.sig !== null && item.sig !== undefined) this.chatSigByTitle.set(key, item.sig);
      if (reason !== 'backfill') {
        const n = result && Number.isFinite(result.incoming) ? result.incoming : 0;
        const why = result && result.reason ? `, ${result.reason}` : '';
        this.noticeOnce('open-ok:' + key + ':' + reason, 'info',
          `Opened "${label}" (${reason}, ${how}): ${n} new incoming${why}`, 20000);
      }
    }
  }

  // Store the last incoming line the chat list shows, for a chat we could not open.
  // Only when the row maps to a real chat id (phone, @lid, or a roster name).
  async ingestRowPreview(row, reason = '') {
    if (!row || !this.page || !this.hasBridgeSecret()) return 0;
    const preview = String(row.preview || '').trim();
    if (!preview || row.outgoing || waListRules.isTransientPreview(preview)) return 0;
    const key = waListRules.titleKey(row.title);
    let waChatId = '';
    const phone = this.normalizePhoneDigits(row.phone || '') || this.phoneDigitsFromLabel(row.title);
    if (phone) waChatId = phone + '@c.us';
    else if (row.wa_chat_id && /^\d+@lid$/i.test(row.wa_chat_id)) waChatId = String(row.wa_chat_id).toLowerCase();
    else if (this.privacyFilter.nameToJidMap && this.privacyFilter.nameToJidMap.has(key)) waChatId = this.privacyFilter.nameToJidMap.get(key);
    if (!waChatId) return 0;
    const own = this.normalizePhoneDigits(this.phoneNumber || '');
    if (own && waChatId.startsWith(own + '@')) return 0;
    if (row.groupHint && !waChatId.includes('@g.us')) return 0;
    const id = waListRules.previewMsgId(waChatId, row);
    if (this.ingestedMsgIds.has(id)) return 0;
    const chatType = waChatId.includes('@g.us') ? 'group' : 'individual';
    const msg = {
      wa_msg_id: id,
      wa_chat_id: waChatId,
      from_me: false,
      msg_type: 'text',
      body: preview,
      media_url: '',
      timestamp: Math.floor(Date.now() / 1000),
      timestamp_parsed: false,
      quoted_wa_msg_id: '',
      status: 'delivered',
      chat_name: row.title || '',
      chat_avatar: '',
      sender_wa_id: waChatId,
      sender_name: row.title || '',
      sender_avatar: '',
      chat_type: chatType
    };
    const allowed = this.isMessageAllowed(msg, { wa_chat_id: waChatId, chat_name: row.title || '', chat_type: chatType });
    if (!allowed.allowed) return 0;
    msg.metadata = { preview_only: true, source: 'chat_list', reason, list_time: row.time || '', filter_reason: allowed.reason };
    let result = null;
    try {
      result = await this.apiPost('api_whatsapp_ingest.php', [msg]);
    } catch (e) {
      return 0;
    }
    if (!result || result.success === false) return 0;
    this.rememberIngested(id);
    this.noteParentIngest();
    this.log('info', `Stored list preview for ${this.maskForLog(waChatId)} (${reason}; chat not opened)`);
    return 1;
  }

  async ingestOpenChat(knownWaChatId = '') {
    if (!this.page || !this.hasBridgeSecret()) return;
    await this.scrollOpenChatToEnd();
    let messages = [];
    let chatInfo = null;
    try {
      const scraped = await this.page.evaluate(() => {
        if (!window.WABridge) return { messages: [], chatInfo: null };
        return {
          messages: window.WABridge.getMessages(),
          chatInfo: window.WABridge.getContactInfo()
        };
      });
      messages = (scraped && scraped.messages) || [];
      chatInfo = scraped && scraped.chatInfo;
    } catch (e) {
      this.log('debug', 'Open-chat scrape failed', e.message);
      return;
    }

    const jidCounts = new Map();
    for (const msg of messages) {
      const jid = msg && msg.wa_chat_id ? String(msg.wa_chat_id).toLowerCase() : '';
      if (!jid) continue;
      jidCounts.set(jid, (jidCounts.get(jid) || 0) + 1);
    }
    let messageJid = '';
    let messageJidCount = 0;
    for (const [jid, count] of jidCounts) {
      if (count > messageJidCount) {
        messageJid = jid;
        messageJidCount = count;
      }
    }

    let waChatId = String(
      this.jidToWaChatId(knownWaChatId) || this.resolveWaChatId(chatInfo, messageJid) || ''
    ).trim().toLowerCase();
    const incomingOnScreen = messages.filter(msg => msg && msg.wa_msg_id && !msg.from_me).length;
    if (!waChatId && incomingOnScreen) {
      const titleKey = (chatInfo && chatInfo.name) || messageJid || 'open';
      const drawerKey = 'drawer:' + titleKey;
      const lastDrawer = this.ingestNotices.get(drawerKey) || 0;
      if (Date.now() - lastDrawer > 60000) {
        this.ingestNotices.set(drawerKey, Date.now());
        const fromDrawer = await this.readPhoneFromContactDrawer();
        if (fromDrawer) waChatId = fromDrawer + '@c.us';
      }
    }
    // Last resort for Linked-ID chats: scrape any @lid from the open conversation DOM
    // so we can still show + reply by chat id when WhatsApp hides the phone.
    if (!waChatId && this.page) {
      try {
        const scrapedLid = await this.page.evaluate(() => {
          const root = document.querySelector('#main') || document.body;
          if (!root) return '';
          const blob = [
            root.getAttribute('data-id') || '',
            ...Array.from(root.querySelectorAll('[data-id]')).slice(0, 80).map(n => n.getAttribute('data-id') || '')
          ].join(' ');
          const m = blob.match(/(\d+)@lid/i);
          return m ? (m[1] + '@lid') : '';
        });
        if (scrapedLid) waChatId = String(scrapedLid).toLowerCase();
      } catch (e) {}
    }
    // Stable synthetic id from title only when we still have nothing — keeps hub replyable.
    if (!waChatId && incomingOnScreen && chatInfo && chatInfo.name) {
      const slug = String(chatInfo.name).trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 48);
      if (slug) {
        waChatId = 'title:' + slug + '@lid';
        this.log('info', `Using title-based @lid for chat without phone: ${waChatId}`);
      }
    }
    if (!waChatId) {
      if (incomingOnScreen) {
        const title = (chatInfo && chatInfo.name) || 'this chat';
        const note = `Chat "${title}" has incoming text but no WhatsApp number — not stored`;
        this.noteSkip(note);
        this.noticeOnce('no-jid:' + title, 'info', note);
      } else {
        this.log('debug', 'Skipping ingest: empty wa_chat_id');
      }
      return { waChatId: '', incoming: 0, reason: incomingOnScreen ? 'no WhatsApp number for this chat' : 'no messages read' };
    }

    const fresh = [];
    const seenBatch = new Set();
    for (const msg of messages) {
      if (!msg || !msg.wa_msg_id) continue;
      const id = String(msg.wa_msg_id);
      if (this.ingestedMsgIds.has(id) || seenBatch.has(id)) continue;
      seenBatch.add(id);
      if (chatInfo && chatInfo.is_self) {
        // In "Message yourself", all messages show as from_me in WhatsApp Web.
        // If it matches an echo we sent from this bridge, keep it from_me/ingested.
        // If it does NOT match our echo, it is a reply typed on the phone — mark it incoming!
        if (this.takeOutboundEcho(waChatId, msg.body)) {
          this.rememberIngested(id);
          continue;
        }
        msg.from_me = false;
      } else if (msg.from_me) {
        // Our own send is already stored. A second copy, or a message typed on
        // the phone in the last 20 minutes, is still worth keeping.
        if (this.takeOutboundEcho(waChatId, msg.body)) {
          this.rememberIngested(id);
          continue;
        }
        const age = Math.floor(Date.now() / 1000) - this.saneTimestamp(msg.timestamp);
        if (age > 6 * 60 * 60) {
          this.rememberIngested(id);
          continue;
        }
      }
      fresh.push(msg);
    }
    if (!fresh.length) return { waChatId, incoming: 0, reason: messages.length ? 'nothing new on screen' : 'no messages read' };

    const allowedMessages = [];
    let filteredMessages = 0;
    for (const msg of fresh) {
      const enriched = {
        ...msg,
        wa_chat_id: waChatId,
        chat_name: (chatInfo && chatInfo.name) || '',
        chat_avatar: (chatInfo && chatInfo.avatar) || '',
        sender_wa_id: msg.from_me ? 'me' : waChatId,
        sender_name: msg.from_me ? 'You' : ((chatInfo && chatInfo.name) || ''),
        sender_avatar: (chatInfo && chatInfo.avatar) || '',
        timestamp: this.saneTimestamp(msg.timestamp)
      };
      if (!enriched.wa_chat_id) continue;
      const chatContext = {
        wa_chat_id: enriched.wa_chat_id,
        chat_name: enriched.chat_name,
        chat_type: enriched.wa_chat_id.includes('@g.us') ? 'group' :
          (enriched.wa_chat_id.includes('broadcast') || enriched.wa_chat_id.includes('channel') ? 'broadcast' : 'individual')
      };
      const allowed = this.isMessageAllowed(enriched, chatContext);
      if (allowed.allowed) {
        allowedMessages.push({
          ...enriched,
          chat_type: chatContext.chat_type,
          metadata: { ...(enriched.metadata || {}), filter_reason: allowed.reason }
        });
      } else {
        filteredMessages++;
        this.log('debug', `Filtered out message from ${enriched.sender_name} in chat ${enriched.wa_chat_id} - reason: ${allowed.reason}`);
      }
    }

    if (!allowedMessages.length) {
      if (filteredMessages) {
        this.noticeOnce('filtered:' + waChatId, 'info', `Dropped ${filteredMessages} incoming message(s) for ${waChatId} (not on the school roster)`);
      }
      return { waChatId, incoming: 0, reason: 'dropped by privacy filter' };
    }

    const result = await this.apiPost('api_whatsapp_ingest.php', allowedMessages);
    for (const msg of allowedMessages) this.rememberIngested(msg.wa_msg_id);
    this.lastMsgTimestamp = Math.max(this.lastMsgTimestamp, ...allowedMessages.map(m => m.timestamp));
    const incomingCount = allowedMessages.filter(msg => !msg.from_me).length;
    const phoneSentCount = allowedMessages.length - incomingCount;
    const inserted = result && result.inserted != null ? ` (stored ${result.inserted})` : '';
    if (incomingCount) {
      this.noteParentIngest();
      this.log('info', `Ingested ${incomingCount} incoming message(s) for ${waChatId}${inserted}`);
    }
    if (phoneSentCount) this.log('info', `Stored ${phoneSentCount} message(s) typed on the phone for ${waChatId}${inserted}`);
    return { waChatId, incoming: incomingCount, stored: result && result.inserted != null ? result.inserted : null };
  }

  async ingestVisibleUnread(limit = 5) {
    if (this.sending || !this.page || !this.hasBridgeSecret()) return;
    const seen = new Set();
    for (let opened = 0; opened < limit; opened++) {
      if (this.sending) return;
      let rows = [];
      try {
        rows = await this.page.evaluate(() => {
          if (!window.WABridge || !window.WABridge.getUnreadChatPhones) return [];
          return window.WABridge.getUnreadChatPhones();
        });
      } catch (e) {
        this.log('debug', 'Unread list scrape failed', e.message);
        return;
      }

      const row = (rows || []).find(item => {
        const key = String((item && (item.phone || item.title)) || '') + ':' + (item && item.index);
        return item && !seen.has(key);
      });
      if (!row) return;
      seen.add(String((row.phone || row.title) || '') + ':' + row.index);

      const phone = this.phoneDigitsFromLabel(row.phone || row.title);
      const title = String(row.title || '').trim();
      if (waListRules.isSystemChatTitle(title)) {
        continue;
      }

      let openedOk = false;
      if (phone) {
        const waChatId = phone + '@c.us';
        let currentUrl = '';
        try { currentUrl = this.page.url(); } catch (e) { currentUrl = ''; }
        if (!currentUrl.includes('phone=' + phone)) {
          openedOk = await this.openChat(waChatId);
        } else {
          openedOk = true;
        }
        if (!openedOk || this.sending) continue;
        try {
          await this.ingestOpenChat(waChatId);
        } catch (e) {
          this.log('debug', 'Unread chat ingest failed', e.message);
        }
      } else if (Number.isInteger(row.index)) {
        openedOk = await this.openUnreadRow(row.index);
        if (!openedOk || this.sending) continue;
        try {
          await this.ingestOpenChat('');
        } catch (e) {
          this.log('debug', 'Unread chat ingest failed', e.message);
        }
      }
    }
  }

  async headerTitle() {
    if (!this.page) return '';
    try {
      return await this.page.evaluate(() => {
        const header = document.querySelector('#main header');
        if (!header) return '';
        const nameEl = header.querySelector('[data-testid="conversation-info-header-chat-title"], [data-testid="conversation-header-name"], span[title], span[dir="auto"]');
        return (nameEl?.getAttribute('title') || nameEl?.textContent || header.innerText || '').replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, '').trim();
      });
    } catch (e) {
      return '';
    }
  }

  async waitForHeaderTitle(expectedTitle, timeout = 5000) {
    const clean = (s) => String(s || '').replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, '').trim().toLowerCase();
    const cleanDigits = (s) => {
      const d = String(s || '').replace(/\D/g, '');
      return d.length >= 8 ? d.slice(-10) : '';
    };
    const wanted = clean(expectedTitle);
    const wantedDigits = cleanDigits(expectedTitle);
    if (!wanted && !wantedDigits) return false;
    const start = Date.now();
    while (Date.now() - start < timeout) {
      const title = clean(await this.headerTitle());
      const titleDigits = cleanDigits(title);
      if (wanted && title && (title === wanted || title.includes(wanted) || wanted.includes(title))) return true;
      if (wantedDigits && titleDigits && (wantedDigits === titleDigits || wantedDigits.includes(titleDigits) || titleDigits.includes(wantedDigits))) return true;
      const noSpaceWanted = wanted.replace(/[\s\-\(\)\+]/g, '');
      const noSpaceTitle = title.replace(/[\s\-\(\)\+]/g, '');
      if (noSpaceWanted && noSpaceTitle && (noSpaceTitle.includes(noSpaceWanted) || noSpaceWanted.includes(noSpaceTitle))) return true;
      await new Promise(r => setTimeout(r, 150));
    }
    return false;
  }

  async waitForConversationReady(timeout = 4000) {
    try {
      await this.page.waitForFunction(() => {
        const main = document.querySelector('#main');
        if (!main) return false;
        const header = main.querySelector('header');
        const messages = main.querySelector('[data-testid="conversation-panel-messages"], .message-in, .message-out, #messages, div[role="region"]');
        const composer = document.querySelector('footer div[contenteditable="true"], div[contenteditable="true"][data-tab="10"], [aria-label="Type a message"]');
        return !!(header || messages || composer);
      }, { timeout, polling: 150 });
      return true;
    } catch (e) {
      return false;
    }
  }

  async openUnreadRow(index, expectedTitle = '') {
    if (!this.page) return false;
    const point = await this.page.evaluate((idx, expected) => {
      const root = document.querySelector('#pane-side') || document.querySelector('[data-testid="chat-list"]');
      if (!root) return null;
      const rows = root.querySelectorAll('[role="listitem"], [role="row"], [data-testid="cell-frame-container"]');
      let el = rows[idx];
      if (expected) {
        const cleanStr = (s) => String(s || '').replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, '').replace(/[\s\-\(\)\+]/g, '').toLowerCase();
        const targetKey = cleanStr(expected);
        if (targetKey) {
          for (const r of rows) {
            const titleEl = r.querySelector('[data-testid="cell-frame-title"], span[title], span[dir="auto"]');
            const rTitle = cleanStr(titleEl?.getAttribute('title') || titleEl?.textContent || r.innerText || '');
            if (rTitle && (rTitle.includes(targetKey) || targetKey.includes(rTitle))) {
              el = r;
              break;
            }
          }
        }
      }
      if (!el) return null;
      el.scrollIntoView({ block: 'nearest' });
      const target = el.querySelector('[role="gridcell"]') || el;
      try {
        target.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
        target.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, cancelable: true }));
        target.click();
      } catch (e) {}
      const rect = target.getBoundingClientRect();
      if (rect.width < 8 || rect.height < 8) return null;
      return { x: rect.x + rect.width / 2, y: rect.y + Math.min(24, rect.height / 2) };
    }, index, expectedTitle);
    if (!point) return false;
    await this.page.mouse.click(point.x, point.y).catch(() => {});
    if (expectedTitle) {
      const named = await this.waitForHeaderTitle(expectedTitle, 2200);
      if (!named) return false;
    }
    return await this.waitForConversationReady(2500);
  }

  async openChatByTitle(title) {
    const wanted = String(title || '').trim();
    if (!this.page || wanted.length < 2 || waListRules.isSystemChatTitle(wanted)) return false;
    try {
      const focused = await this.page.evaluate(() => {
        const box = document.querySelector('[aria-label="Search input textbox"], [aria-label="Search"], [data-testid="chat-list-search"], div[contenteditable="true"][data-tab="3"]');
        if (!box) return false;
        // Never treat a message composer as the search box.
        if (box.closest('footer') || box.closest('#main')) return false;
        box.focus();
        box.click();
        const active = document.activeElement;
        if (!active) return false;
        if (active.closest('footer') || active.closest('#main')) return false;
        return active === box || box.contains(active);
      });
      if (!focused) return false;
      await this.page.keyboard.down(process.platform === 'darwin' ? 'Meta' : 'Control');
      await this.page.keyboard.press('KeyA');
      await this.page.keyboard.up(process.platform === 'darwin' ? 'Meta' : 'Control');
      await this.page.keyboard.press('Backspace');
      await this.page.keyboard.type(wanted.slice(0, 40), { delay: 15 });
      await new Promise(r => setTimeout(r, 900));
      const point = await this.page.evaluate((name) => {
        const root = document.querySelector('#pane-side') || document.body;
        const needle = String(name || '').trim().toLowerCase();
        const rows = root.querySelectorAll('[role="listitem"], [role="row"], [data-testid="cell-frame-container"]');
        for (const row of rows) {
          const text = (row.innerText || '').trim().toLowerCase();
          if (!text || !text.includes(needle)) continue;
          const cell = row.querySelector('[role="gridcell"]') || row;
          const rect = cell.getBoundingClientRect();
          if (rect.width < 8 || rect.height < 8) continue;
          return { x: rect.x + rect.width / 2, y: rect.y + Math.min(24, rect.height / 2) };
        }
        return null;
      }, wanted);
      if (!point) {
        await this.page.keyboard.press('Escape').catch(() => {});
        return false;
      }
      await this.page.mouse.click(point.x, point.y);
      const named = await this.waitForHeaderTitle(wanted, 4000);
      if (!named) {
        await this.page.keyboard.press('Escape').catch(() => {});
        return false;
      }
      await this.page.evaluate(() => {
        const clear = document.querySelector('[aria-label="Clear search"], [aria-label="Cancel search"], [data-testid="x-alt"]');
        if (clear) clear.click();
      }).catch(() => {});
      return await this.waitForConversationReady(4000);
    } catch (e) {
      this.log('debug', 'Title search failed', e.message);
      return false;
    }
  }

  async revisitRecentOutbound() {
    if (this.sending || !this.page || !this.outboundWatch.length) return;
    if (Date.now() - (this.lastRevisitAt || 0) < 15000) return;
    this.lastRevisitAt = Date.now();
    const waChatId = this.outboundWatch[this.outboundWatchCursor % this.outboundWatch.length];
    this.outboundWatchCursor++;
    const digits = String(waChatId || '').split('@')[0].replace(/\D/g, '');
    let url = '';
    try { url = this.page.url(); } catch (e) { url = ''; }
    if (digits && url.includes('phone=' + digits)) {
      await this.ingestOpenChat(waChatId);
      return;
    }
    // Never allow full page reload on background revisit
    const opened = await this.openChat(waChatId, false);
    if (!opened || this.sending) return;
    await this.ingestOpenChat(waChatId);
  }

  async pageSessionState() {
    if (!this.page) return { logged: false, qr: false };
    try {
      return await this.page.evaluate(() => {
        const hasChatList = !!document.querySelector(
          '#pane-side, [data-testid="chat-list"], [aria-label="Chat list"], div[role="grid"][aria-label*="chat" i], div[data-testid="chatlist-header"], #side, div[data-testid="intro-title"], header [aria-label*="Profile" i], header [aria-label*="Status" i], header [aria-label*="New chat" i], header [aria-label*="Menu" i], [data-tab="3"]'
        );
        const hasMain = !!document.querySelector('#main, [data-testid="conversation-panel-wrapper"], [data-testid="conversation-compose-box-input"]');
        const hasComposer = !!document.querySelector('footer [contenteditable="true"], [data-testid="conversation-compose-box-input"]');
        const logged = hasChatList || hasMain || hasComposer || (window.WABridge && typeof window.WABridge.isLoggedIn === 'function' && window.WABridge.isLoggedIn());

        // A real QR requires an actual visible QR canvas or qrcode container
        const qrCanvas = document.querySelector('canvas[aria-label*="QR" i], canvas[aria-label*="Scan" i], div[data-ref] canvas');
        const qrBox = document.querySelector('[data-testid="qrcode"]');
        const qr = !logged && !!(qrBox || (qrCanvas && qrCanvas.width > 50));
        return { logged: !!logged, qr: !!qr };
      });
    } catch (e) {
      return { logged: false, qr: false };
    }
  }

  async handleSessionLost() {
    if (!this.isConnected) return;
    this.isConnected = false;
    this.log('warn', 'WhatsApp session lost — waiting for a new QR scan');
    this.stopPolling();
    try { await this.captureQR(); } catch (e) {
      this.log('debug', 'QR recapture failed', e.message);
    }
    try { await this.saveSessionToAPI(); } catch (e) {}
    this.startSessionWatcher();
  }

  async pollSelfChat() {
    if (this.sending || !this.page || !this.phoneNumber) return;
    const selfJid = this.normalizePhoneDigits(String(this.phoneNumber)) + '@c.us';
    try {
      const currentHeader = await this.headerTitle();
      if (/message yourself|\(you\)/i.test(currentHeader)) {
        await this.ingestOpenChat(selfJid);
        return;
      }
      const visibleIndex = await this.page.evaluate(() => {
        const root = document.querySelector('#pane-side') || document.querySelector('[data-testid="chat-list"]');
        if (!root) return -1;
        const rows = root.querySelectorAll('[role="listitem"], [role="row"], [data-testid="cell-frame-container"]');
        for (let i = 0; i < Math.min(rows.length, 12); i++) {
          const txt = (rows[i].innerText || '').replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, '');
          if (/message yourself|\(you\)/i.test(txt)) return i;
        }
        return -1;
      });
      if (visibleIndex >= 0) {
        const opened = await this.openUnreadRow(visibleIndex, 'Message yourself');
        if (opened) await this.ingestOpenChat(selfJid);
        return;
      }
      if (Date.now() - (this.lastSelfSearchAt || 0) < 60000) return;
      this.lastSelfSearchAt = Date.now();
      const opened = await this.openChatByTitle('Message yourself');
      if (!opened) return;
      await this.ingestOpenChat(selfJid);
    } catch (e) {
      this.log('debug', 'Self-chat poll failed', e.message);
    }
  }

  async pollMessages() {
    if (this.loggingOut || !this.isConnected || !this.page || this.pollingNow) return;
    this.pollingNow = true;
    try {
      const state = await this.pageSessionState();
      if (!state.logged) {
        // A send navigates the page. Only treat the real QR screen as a logout.
        if (this.sending || !state.qr) {
          this.consecutiveQrDetections = 0;
          return;
        }
        this.consecutiveQrDetections = (this.consecutiveQrDetections || 0) + 1;
        // Require 5 consecutive detections (~15s) before declaring session lost
        if (this.consecutiveQrDetections < 5) {
          return;
        }
        this.consecutiveQrDetections = 0;
        await this.handleSessionLost();
        return;
      }
      this.consecutiveQrDetections = 0;

      // Do not navigate away while a send owns the open chat.
      if (!this.sending) {
        try {
          await this.ingestVisibleChats(3);
        } catch (e) {
          this.log('debug', 'Chat-list ingest failed', e.message);
        }
        try {
          await this.revisitRecentOutbound();
        } catch (e) {
          this.log('debug', 'Recent-chat ingest failed', e.message);
        }
      }

      await this.apiPost('api_whatsapp_session.php?action=poll', {
        last_poll_at: new Date().toISOString(),
        last_ingest_at: this.lastIngestAt,
        last_skip_note: this.lastSkipNote || ''
      });
    } finally {
      this.pollingNow = false;
    }
  }
  
  async checkCommandFlags() {
    if (this.loggingOut) return;

    const logoutFlags = [
      path.join(this.config.ipcDir, `whatsapp_logout_${this.config.sessionName}`),
      path.join(this.config.ipcDir, 'whatsapp_logout_default')
    ];
    let logoutTriggered = false;
    for (const f of logoutFlags) {
      if (fs.existsSync(f)) {
        try { fs.unlinkSync(f); } catch (e) {}
        logoutTriggered = true;
      }
    }
    if (logoutTriggered) {
      await this.logout();
      return;
    }

    const qrFlags = [
      path.join(this.config.ipcDir, `whatsapp_qr_refresh_${this.config.sessionName}`),
      path.join(this.config.ipcDir, 'whatsapp_qr_refresh_default')
    ];
    let refreshTriggered = false;
    for (const f of qrFlags) {
      if (fs.existsSync(f)) {
        try { fs.unlinkSync(f); } catch (e) {}
        refreshTriggered = true;
      }
    }
    if (refreshTriggered) {
      await this.refreshQR();
    }

    const dumpFlags = [
      path.join(this.config.ipcDir, `whatsapp_dump_ids_${this.config.sessionName}`),
      path.join(this.config.ipcDir, 'whatsapp_dump_ids_default')
    ];
    for (const f of dumpFlags) {
      if (!fs.existsSync(f)) continue;
      try { fs.unlinkSync(f); } catch (e) {}
      try {
        if (!this.page) continue;
        const dump = await this.page.evaluate(() => {
          const out = [];
          document.querySelectorAll('#main [data-id]').forEach((el) => {
            const id = el.getAttribute('data-id') || '';
            const text = (el.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 80);
            out.push({ id, text });
          });
          return out.slice(-20);
        });
        const dest = path.join(this.config.ipcDir, 'hcc-bridge-msg-ids.json');
        fs.writeFileSync(dest, JSON.stringify(dump, null, 2));
        this.log('info', 'Dumped ' + dump.length + ' message ids to ' + dest);
      } catch (e) {
        this.log('warn', 'dump ids failed', e.message);
      }
    }

    const shotFlags = [
      path.join(this.config.ipcDir, `whatsapp_screenshot_${this.config.sessionName}`),
      path.join(this.config.ipcDir, 'whatsapp_screenshot_default')
    ];
    for (const f of shotFlags) {
      if (!fs.existsSync(f)) continue;
      let dest = path.join(this.config.ipcDir, 'hcc-bridge-page.png');
      try {
        const raw = fs.readFileSync(f, 'utf8').trim();
        if (raw) dest = raw;
      } catch (e) {}
      try { fs.unlinkSync(f); } catch (e) {}
      try {
        if (this.page) {
          await this.page.screenshot({ path: dest, fullPage: false });
          this.log('info', 'Saved bridge page screenshot to ' + dest);
        }
      } catch (e) {
        this.log('warn', 'Bridge screenshot failed', e.message);
      }
    }
  }
  
  startSessionSyncTimer() {
    if (this.sessionSyncTimer) return;
    this.sessionSyncTimer = setInterval(() => {
      if (this.isConnected) this.saveSessionToAPI().catch(() => {});
    }, 15000);
  }

  startFlagWatcher() {
    if (this.flagWatcherTimer) return;
    this.flagWatcherTimer = setInterval(() => {
      this.checkCommandFlags().catch(() => {});
    }, 5000);
  }

  async refreshQR() {
    if (this.loggingOut || this.isConnected) return;
    const since = Date.now() - (this.lastQrRefreshClickAt || 0);
    if (since < 15000) {
      this.log('debug', 'Ignoring QR refresh request (cooldown)');
      return;
    }
    this.lastQrRefreshClickAt = Date.now();
    this.log('info', 'Refreshing QR code...');
    try {
      if (!this.page) return;
      const state = await this.pageSessionState();
      if (state.logged) return;
      const clicked = await this.page.evaluate(() => {
        const qrLoginBtn = Array.from(document.querySelectorAll('button, [role="button"], span, div'))
          .find(el => /^(?:log\s*in|link)\s+with\s+qr(?:\s*code)?$/i.test((el.innerText || el.getAttribute('aria-label') || '').trim()));
        if (qrLoginBtn) { qrLoginBtn.click(); return 'qr_login'; }

        const reloadBtn = Array.from(document.querySelectorAll('button, [role="button"], span, div'))
          .find(el => /(?:click|select|tap)\s+to\s+reload|reload\s*qr|refresh\s*qr|qr\s*code\s*expired/i.test((el.innerText || el.getAttribute('aria-label') || '').trim()));
        if (reloadBtn) { reloadBtn.click(); return 'reload'; }

        // Do not click a random canvas — that rotates a still-valid QR.
        return 'none';

        return false;
      });

      if (!clicked || clicked === 'none') {
        // Prefer capturing an on-screen QR over a full page reload (reload aborts scans).
        this.log('info', 'No QR reload control found — capturing current QR without page reload');
        await this.captureQR();
        return;
      }

      await new Promise(r => setTimeout(r, 3000));
      await this.captureQR();
    } catch (e) {
      this.log('error', 'Failed to refresh QR', e.message);
    }
  }
  
  async requestPairingCode(phoneRaw) {
    const digits = String(phoneRaw || '').replace(/\D/g, '');
    if (digits.length < 10) throw new Error('Invalid phone number');

    const dbgFile = path.join(this.config.ipcDir, 'whatsapp_pair_debug.txt');
    try { fs.writeFileSync(dbgFile, ''); } catch (e) {}
    const dbg = (m) => { try { fs.appendFileSync(dbgFile, new Date().toISOString() + ' ' + m + '\n'); } catch (e) {} };

    this.log('info', 'Requesting WhatsApp pairing code for ' + digits);
    dbg('start ' + digits);

    // Ensure we are on the login screen
    await this.safeGoto(waSafety.resolveWhatsAppEntryUrl(), { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
    await new Promise(r => setTimeout(r, 4000));
    dbg('navigated url=' + this.page.url());

    // 1) Click "Link with phone number" / "Log in with phone number"
    let clicked = await this.page.evaluate(() => {
      const byTestId = document.querySelector('[data-testid="link-phone-number-btn"], [data-testid="link_device_phone_number_link"]');
      if (byTestId) { (byTestId.closest('div[role="button"], button') || byTestId).click(); return 'testid'; }
      const candidates = Array.from(document.querySelectorAll('button, a, div[role="button"], span'));
      const el = candidates.find(e => /(?:link|log\s*in)\s+with\s+phone\s*number/i.test(e.textContent || ''));
      if (el) { (el.closest('div[role="button"], button') || el).click(); return 'text'; }
      return 'none';
    });
    if (clicked === 'none') {
      const els = await this.page.$$('button, a, div[role="button"]');
      for (const el of els) {
        const txt = await el.evaluate(e => e.textContent || '').catch(() => '');
        if (/(?:link|log\s*in)\s+with\s+phone\s*number/i.test(txt)) {
          await el.click().catch(() => {});
          clicked = 'handle';
          break;
        }
      }
    }
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
    const modKey = process.platform === 'darwin' ? 'Meta' : 'Control';
    await this.page.keyboard.down(modKey);
    await this.page.keyboard.press('KeyA');
    await this.page.keyboard.up(modKey);
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
      const reqFile = path.join(this.config.ipcDir, 'whatsapp_pair_request');
      const codeFile = path.join(this.config.ipcDir, 'whatsapp_pair_code.txt');
      if (!fs.existsSync(reqFile)) return;
      let phone = '';
      try { phone = fs.readFileSync(reqFile, 'utf8').trim(); } catch (e) {}
      try { fs.unlinkSync(reqFile); } catch (e) {}
      try {
        this.pairingActive = true;
        const code = await this.requestPairingCode(phone);
        fs.writeFileSync(codeFile, code);
        this.log('info', 'Pairing code generated: ' + code);
      } catch (e) {
        fs.writeFileSync(codeFile, 'ERROR: ' + e.message);
        this.log('error', 'Pairing code failed', e.message);
      } finally {
        this.pairingActive = false;
        // Give WhatsApp a moment, then resume normal QR watching
        this.lastQrCaptureAt = Date.now();
      }
    }, 3000);
  }

  async clickPaneMenu() {
    if (!this.page) return false;
    const point = await this.page.evaluate(() => {
      const side = document.querySelector('#pane-side');
      const header = side
        ? (side.previousElementSibling || (side.parentElement && side.parentElement.querySelector('header')) || side.parentElement)
        : document.querySelector('header');
      const root = header || document;
      const nodes = Array.from(root.querySelectorAll('button, [role="button"], span[data-icon]'));
      const el = nodes.find(node => {
        const label = (node.getAttribute('aria-label') || node.getAttribute('title') || '').trim();
        const icon = node.getAttribute('data-icon') || '';
        return /^menu$/i.test(label) || icon === 'menu';
      });
      if (!el) return null;
      const target = el.closest('button, [role="button"]') || el;
      const r = target.getBoundingClientRect();
      if (r.width < 4 || r.height < 4) return null;
      return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    });
    if (!point) return false;
    await this.page.mouse.click(point.x, point.y);
    return true;
  }

  async clickExactLabel(patternSource) {
    if (!this.page) return false;
    const point = await this.page.evaluate((source) => {
      const re = new RegExp(source, 'i');
      const nodes = Array.from(document.querySelectorAll('button, [role="button"], [role="menuitem"], li, span, div'));
      const hits = [];
      for (const node of nodes) {
        const raw = (node.getAttribute('aria-label') || node.innerText || '').replace(/\s+/g, ' ').trim();
        if (!raw || raw.length > 32 || !re.test(raw)) continue;
        const r = node.getBoundingClientRect();
        if (r.width < 8 || r.height < 8 || r.bottom < 0 || r.top > window.innerHeight) continue;
        hits.push({ area: r.width * r.height, x: r.x + r.width / 2, y: r.y + r.height / 2 });
      }
      hits.sort((a, b) => a.area - b.area);
      return hits.length ? { x: hits[0].x, y: hits[0].y } : null;
    }, patternSource);
    if (!point) return false;
    await this.page.mouse.click(point.x, point.y);
    return true;
  }

  async confirmLogoutDialog() {
    if (!this.page) return false;
    const point = await this.page.evaluate(() => {
      const dialog = document.querySelector('[role="dialog"], [data-animate-modal-body]');
      const root = dialog || document.body;
      const nodes = Array.from(root.querySelectorAll('button, [role="button"]'));
      const el = nodes.find(node => /^log\s*out$/i.test((node.innerText || node.getAttribute('aria-label') || '').replace(/\s+/g, ' ').trim()));
      if (!el) return null;
      const r = el.getBoundingClientRect();
      if (r.width < 8 || r.height < 8) return null;
      return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    });
    if (!point) return false;
    await this.page.mouse.click(point.x, point.y);
    return true;
  }

  async unlinkWhatsAppWeb() {
    if (!this.page) return false;
    const opened = await this.clickPaneMenu();
    if (!opened) this.log('warn', 'WhatsApp menu button was not found');
    await new Promise(r => setTimeout(r, 700));
    let clicked = await this.clickExactLabel('^log\\s*out$');
    if (!clicked) {
      const openedSettings = await this.clickExactLabel('^settings$');
      if (openedSettings) {
        await new Promise(r => setTimeout(r, 800));
        clicked = await this.clickExactLabel('^log\\s*out$');
      }
    }
    if (!clicked) return false;
    await new Promise(r => setTimeout(r, 700));
    await this.confirmLogoutDialog();
    for (let i = 0; i < 15; i++) {
      await new Promise(r => setTimeout(r, 1000));
      const state = await this.pageSessionState();
      if (state.qr) return true;
      const onLoginScreen = await this.page.evaluate(() => {
        const text = (document.body && document.body.innerText) || '';
        return /link with phone number|log in with qr|scan (?:this|the) qr|use whatsapp on this computer/i.test(text);
      }).catch(() => false);
      if (!state.logged && onLoginScreen) return true;
    }
    return false;
  }

  async clearWhatsAppWebStorage() {
    if (!this.page) return false;
    this.log('warn', 'Menu logout did not unlink. Clearing the WhatsApp Web session in this browser.');
    try {
      await this.page.evaluate(async () => {
        try { localStorage.clear(); } catch (e) {}
        try { sessionStorage.clear(); } catch (e) {}
        try {
          if (indexedDB.databases) {
            const dbs = await indexedDB.databases();
            await Promise.all((dbs || []).map(db => {
              if (!db || !db.name) return Promise.resolve();
              return new Promise(resolve => {
                const req = indexedDB.deleteDatabase(db.name);
                req.onsuccess = () => resolve();
                req.onerror = () => resolve();
                req.onblocked = () => resolve();
              });
            }));
          }
        } catch (e) {}
      });
    } catch (e) {
      this.log('debug', 'In-page storage clear failed', e.message);
    }

    let client = null;
    try {
      if (typeof this.page.createCDPSession === 'function') {
        client = await this.page.createCDPSession();
      } else if (this.page.target && typeof this.page.target().createCDPSession === 'function') {
        client = await this.page.target().createCDPSession();
      }
      if (client) {
        await client.send('Network.clearBrowserCookies').catch(() => {});
        await client.send('Storage.clearDataForOrigin', {
          origin: 'https://web.whatsapp.com',
          storageTypes: 'all'
        }).catch((err) => {
          this.log('warn', 'Could not clear WhatsApp origin storage', err && err.message ? err.message : '');
        });
      }
    } catch (e) {
      this.log('warn', 'Browser storage clear failed', e.message);
    } finally {
      if (client) {
        try { await client.detach(); } catch (e) {}
      }
    }

    await this.safeGoto(waSafety.resolveWhatsAppEntryUrl(), { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
    for (let i = 0; i < 12; i++) {
      await new Promise(r => setTimeout(r, 1000));
      const state = await this.pageSessionState();
      if (state.logged) return false;
      if (state.qr) return true;
      const onLoginScreen = await this.page.evaluate(() => {
        const text = (document.body && document.body.innerText) || '';
        return /link with phone number|log in with qr|scan (?:this|the) qr|use whatsapp on this computer/i.test(text);
      }).catch(() => false);
      if (onLoginScreen) return true;
    }
    return false;
  }

  async logout() {
    if (this.loggingOut) return;
    this.loggingOut = true;
    this.log('info', 'Logging out of WhatsApp...');
    this.isConnected = false;
    this.currentQR = null;
    this.qrExpiresAt = null;
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

    try {
      await this.saveSessionToAPI();
    } catch (e) {}

    let unlinked = false;
    try {
      unlinked = await this.unlinkWhatsAppWeb();
    } catch (e) {
      this.log('warn', 'Menu logout failed', e.message);
    }
    if (!unlinked) {
      try {
        unlinked = await this.clearWhatsAppWebStorage();
      } catch (e) {
        this.log('error', 'Could not clear the WhatsApp session', e.message);
      }
    }

    if (unlinked) {
      this.log('info', 'WhatsApp logged out. Waiting for a new QR.');
    } else {
      this.log('error', 'WhatsApp is still logged in after logout.');
    }

    // Keep the .linked marker so the next launch stays headless.
    // This browser is already open and will show the new QR itself.
    this.emit('disconnected');
    this.linkingSince = null;
    this.lastQRBase64 = null;
    this.loggingOut = false;
    this.startSessionWatcher();
  }
  
  cleanStaleProfileLock() {
    const dir = this.config.userDataDir;
    const lock = path.join(dir, 'SingletonLock');
    const socket = path.join(dir, 'SingletonSocket');
    const lockfile = path.join(dir, 'lockfile');

    if (!fs.existsSync(lock) && !fs.existsSync(socket) && !fs.existsSync(lockfile)) return;

    // Chrome writes SingletonLock as "<hostname>-<pid>" (symlink on POSIX, regular file/handle on Windows)
    let holderPid = 0;
    try {
      if (fs.existsSync(lock)) {
        const stat = fs.lstatSync(lock);
        let target = '';
        if (stat.isSymbolicLink()) {
          target = String(fs.readlinkSync(lock));
        } else {
          target = fs.readFileSync(lock, 'utf8');
        }
        const m = target.match(/-(\d+)$/) || target.match(/(\d+)/);
        if (m) holderPid = parseInt(m[1], 10);
      }
    } catch (e) {}

    let holderAlive = false;
    if (holderPid > 0) {
      try { process.kill(holderPid, 0); holderAlive = true; } catch (e) { holderAlive = false; }
    }

    // A live holder at this point can only be an orphan from a previous run.
    if (holderAlive) {
      this.log('warn', `Terminating orphaned Chrome (pid ${holderPid}) holding the profile`);
      if (process.platform === 'win32') {
        try {
          require('child_process').execSync(`taskkill /F /PID ${holderPid}`, { stdio: 'ignore' });
        } catch (e) {
          try { process.kill(holderPid); } catch (e2) {}
        }
      } else {
        try { process.kill(holderPid, 'SIGKILL'); } catch (e) {
          try { process.kill(holderPid, 'SIGTERM'); } catch (e2) {}
        }
      }

      const start = Date.now();
      while (Date.now() - start < 3000) {
        try { process.kill(holderPid, 0); } catch (e) { break; }
        try {
          Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 200);
        } catch (e) {}
      }
    }

    const lockCandidates = ['SingletonLock', 'SingletonSocket', 'SingletonCookie', 'lockfile'];
    for (const f of lockCandidates) {
      const p = path.join(dir, f);
      try {
        if (fs.existsSync(p)) fs.unlinkSync(p);
      } catch (e) {
        this.log('debug', `Could not unlink profile lock ${f}: ${e.message}`);
      }
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

    if (this.shuttingDown || waSafety.isTestMode()) {
      this.log('info', 'Skipping reconnect (shutdown or WA_TEST_MODE)');
      return;
    }
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
  
  /**
   * Navigate only through the safety guard. In WA_TEST_MODE, web.whatsapp.com is refused.
   */
  async safeGoto(url, options = {}) {
    const check = waSafety.assertNavigationAllowed(url);
    if (!check.ok) {
      this.log('error', check.error);
      throw new Error(check.error);
    }
    return this.page.goto(check.url, options);
  }

  assertFixtureChatOrThrow(waChatId) {
    const check = waSafety.assertChatAllowed(waChatId);
    if (!check.ok) {
      this.log('error', check.error);
      throw new Error(check.error);
    }
    return check.waChatId;
  }

  async processOutboundQueue() {
    // Do NOT gate on pollingNow — ingestVisibleChats can run 20–40s and was
    // starving the outbound queue so hub sends sat forever as "queued".
    if (!this.isConnected || this.sending) return;
    this.sending = true;
    try {
      const response = await this.apiGet('api_whatsapp_outbound_queue.php', {
        status: 'queued',
        limit: 10
      });
      
      if (response.success && response.data && response.data.length > 0) {
        for (const item of response.data) {
          await this.sendQueuedMessage(item);
        }
      }
    } catch (e) {
      this.log('error', 'Failed to process outbound queue', e.message);
    } finally {
      this.sending = false;
    }
  }
  
  async sendQueuedMessage(item) {
    const queueId = item.id;
    this.log('info', `Sending queued message ${queueId} to chat ${item.chat_id}`);

    // Queue API already filters to fresh user-pressed rows. Send exactly once.
    try {
      // Update status to processing
      await this.apiPost('api_whatsapp_outbound_update.php', {
        id: queueId,
        status: 'processing',
        attempts: item.attempts + 1
      });
      
      // Queue rows include wa_chat_id; look it up only when the payload omitted it.
      let waChatId = item.wa_chat_id || '';
      if (!waChatId) {
        const chatResponse = await this.apiGet('api_whatsapp_chat_by_id.php', { id: item.chat_id });
        if (!chatResponse.success || !chatResponse.data || !chatResponse.data.wa_chat_id) {
          throw new Error('Chat not found');
        }
        waChatId = chatResponse.data.wa_chat_id;
      }
      waChatId = this.assertFixtureChatOrThrow(waChatId);
      
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
        case 'revoke':
          result = await this.revokeForEveryone(waChatId, item.body);
          break;
        default:
          throw new Error(`Unknown message type: ${item.msg_type}`);
      }
      
      if (result && result.success) {
        // Remember the text before the status write. A failed clock update must
        // not make the next scrape store this same bubble a second time.
        // Revoke jobs carry JSON metadata, not chat text — do not echo-filter them.
        if (item.msg_type !== 'revoke') {
          this.rememberOutboundBody(waChatId, item.body);
        }
        // Update queue status only after the bridge actually sent / revoked
        await this.apiPost('api_whatsapp_outbound_update.php', {
          id: queueId,
          status: 'sent',
          sent_at: this.mysqlDateTime()
        });
        
        // Missing temp id must not fail the send or skip it
        if (item.temp_msg_id) {
          try {
            await this.apiPost('api_whatsapp_update_temp_msg.php', {
              temp_msg_id: item.temp_msg_id,
              wa_msg_id: result.wa_msg_id,
              status: 'sent'
            });
          } catch (e) {
            this.log('warn', `temp msg update failed for queue ${queueId}`, e.message);
          }
        }
        
        this.rememberOutboundChat(waChatId);
        try {
          await this.ingestOpenChat(waChatId);
        } catch (e) {
          this.log('warn', `Post-send ingest failed for queue ${queueId}`, e.message);
        }
        this.log('info', `Message ${queueId} sent successfully`);
      } else {
        throw new Error((result && result.error) || 'Send failed');
      }
      
    } catch (error) {
      this.log('error', `Failed to send message ${queueId}`, error.message);
      
      // No auto-retry. Failed stays failed until the user presses Retry in the hub.
      const newAttempts = (item.attempts || 0) + 1;
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
      if (item.temp_msg_id) {
        try {
          await this.apiPost('api_whatsapp_update_temp_msg.php', {
            temp_msg_id: item.temp_msg_id,
            status: 'failed'
          });
        } catch (e) {
          this.log('debug', `temp msg update skipped for queue ${queueId}`, e.message);
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

  async openChat(waChatId, allowDeepLink = true) {
    const raw = String(waChatId || '');
    const digits = raw.split('@')[0].replace(/\D/g, '');
    const isGroup = raw.includes('@g.us');
    const isLid = /@lid/i.test(raw);
    const isTitleLid = /^title:.+@lid$/i.test(raw);
    // title-based ids cannot be deep-linked; if composer is already open (sendQueuedMessage
    // called openChatByTitle), treat as success. Otherwise refuse rather than phone-search.
    if (isTitleLid) {
      if (await this.waitForComposer(2000)) return true;
      this.log('warn', 'title-based @lid chat is not open; openChatByTitle must run first');
      return false;
    }

    // 1. Try finding and clicking in sidebar without navigating!
    try {
      const clicked = await this.page.evaluate((targetDigits, targetRaw) => {
        const root = document.querySelector('#pane-side') || document.querySelector('[data-testid="chat-list"]');
        if (!root) return false;
        const rows = root.querySelectorAll('[role="listitem"], [role="row"], [data-testid="cell-frame-container"]');
        for (const row of rows) {
          const rowText = (row.innerText || '').replace(/\D/g, '');
          const rowData = [
            row.getAttribute('data-id'),
            row.getAttribute('data-chat-id'),
            row.querySelector('[data-id]')?.getAttribute('data-id') || '',
            row.querySelector('[data-chat-id]')?.getAttribute('data-chat-id') || ''
          ].join(' ');
          if ((targetDigits && targetDigits.length >= 6 && (rowText.includes(targetDigits) || rowData.includes(targetDigits))) || (targetRaw && rowData.includes(targetRaw))) {
            const cell = row.querySelector('[role="gridcell"]') || row;
            cell.click();
            return true;
          }
        }
        return false;
      }, digits, raw);
      if (clicked && (await this.waitForConversationReady(4000) || await this.waitForComposer(1000))) return true;
    } catch (e) {}

    // 2. Individuals: deep-link straight to the conversation ONLY if allowed.
    // Never deep-link @lid — those digits are not a phone number.
    if (allowDeepLink && !isGroup && !isLid && digits.length >= 6) {
      let target = `https://web.whatsapp.com/send?phone=${digits}`;
      if (waSafety.isTestMode()) {
        // Stay on mock; deep-link to real WA is forbidden.
        target = waSafety.resolveWhatsAppEntryUrl();
      }
      let currentUrl = '';
      try { currentUrl = this.page.url(); } catch (e) {}
      if (!currentUrl.includes(`phone=${digits}`)) {
        this.log('debug', 'Opening chat via send link: ' + digits);
        await this.safeGoto(target, { waitUntil: 'domcontentloaded', timeout: 90000 }).catch(() => {});
      }
      if (await this.waitForComposer(30000)) return true;
      this.log('warn', 'Composer not ready after deep link');
      return false;
    }

    // 3. REMOVED (2026-11-06): the old in-app search fallback typed the target number
    //    and pressed Enter WITHOUT verifying the search box had focus. When WhatsApp Web's
    //    search selector did not match, keystrokes landed in the OPEN CHAT's composer and
    //    Enter SENT the number as a message (e.g. the linked number went to "JioMart Offers").
    //    Opening a chat now only uses verified paths: sidebar row match or deep link.
    //    Nothing in openChat types into the page.
    this.log('warn', 'openChat: could not open chat via sidebar/deep link (no search typing fallback): ' + (isGroup ? raw : (digits ? digits.slice(0, 2) + '…' + digits.slice(-4) : raw)));
    return false;
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

    // Clear anything already in the composer (Ctrl on Win/Linux, Meta on macOS)
    const modKey = process.platform === 'darwin' ? 'Meta' : 'Control';
    await this.page.keyboard.down(modKey);
    await this.page.keyboard.press('KeyA');
    await this.page.keyboard.up(modKey);
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

    let msgId = await this.getLastSentMessageId(waChatId);
    if (!msgId) {
      await new Promise(r => setTimeout(r, 800));
      msgId = await this.getLastSentMessageId(waChatId);
    }
    if (!msgId) msgId = await this.findOutgoingIdByBody(text);
    return { success: true, wa_msg_id: msgId || '' };
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
  

  /**
   * Delete a from-me message for everyone in WhatsApp Web.
   * body: JSON { wa_msg_id, msg_db_id, timestamp }
   */
  async revokeForEveryone(waChatId, body) {
    const parsed = waRevokeRules.parseRevokeQueueBody(body);
    if (!parsed) {
      return { success: false, error: 'Invalid revoke payload (need wa_msg_id)' };
    }
    const windowCheck = waRevokeRules.canRevokeForEveryone(parsed.timestamp);
    if (!windowCheck.ok) {
      return { success: false, error: windowCheck.reason || 'Outside delete-for-everyone window' };
    }
    if (!this.page) return { success: false, error: 'Browser page not ready' };

    const opened = await this.openChat(waChatId);
    if (!opened) return { success: false, error: 'Could not open chat for revoke' };
    await new Promise(r => setTimeout(r, 700));

    const waMsgId = parsed.wa_msg_id;
    this.log('info', `Revoking message ${waMsgId} in ${waChatId}`);

    // Locate the bubble. Prefer exact data-id; fall back to suffix match.
    const found = await this.page.evaluate((targetId) => {
      const nodes = Array.from(document.querySelectorAll('#main [data-id], [data-testid="msg-container"][data-id], .message-out[data-id]'));
      let el = nodes.find(n => (n.getAttribute('data-id') || '') === targetId);
      if (!el) {
        const short = targetId.includes('_') ? targetId.split('_').pop() : targetId;
        el = nodes.find(n => {
          const id = n.getAttribute('data-id') || '';
          return id === targetId || id.endsWith('_' + short) || id.endsWith(short);
        });
      }
      if (!el) return { ok: false, reason: 'Message bubble not found in open chat' };
      el.scrollIntoView({ block: 'center', inline: 'nearest' });
      return { ok: true, id: el.getAttribute('data-id') || targetId };
    }, waMsgId);

    if (!found || !found.ok) {
      return { success: false, error: (found && found.reason) || 'Message not found on screen' };
    }

    // Hover to reveal the message menu caret, then open it.
    const menuOpened = await this.page.evaluate((targetId) => {
      const nodes = Array.from(document.querySelectorAll('#main [data-id]'));
      const el = nodes.find(n => (n.getAttribute('data-id') || '') === targetId)
        || nodes.find(n => (n.getAttribute('data-id') || '').endsWith(targetId.split('_').pop()));
      if (!el) return false;
      el.dispatchEvent(new MouseEvent('mouseover', { bubbles: true }));
      el.dispatchEvent(new MouseEvent('mouseenter', { bubbles: true }));
      const root = el.closest('[data-id]') || el;
      const btn = root.querySelector('[data-testid="down-context"], [data-icon="down-context"], span[data-icon="down-context"], div[aria-label*="Context" i], div[aria-label*="Menu" i], button[aria-label*="Menu" i]');
      if (btn) {
        (btn.closest('button') || btn).click();
        return true;
      }
      // Right-click fallback
      const rect = root.getBoundingClientRect();
      const x = rect.left + Math.min(rect.width - 8, Math.max(8, rect.width * 0.85));
      const y = rect.top + rect.height / 2;
      root.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, clientX: x, clientY: y, button: 2 }));
      return true;
    }, found.id);
    await new Promise(r => setTimeout(r, 500));

    // Click "Delete" / "Delete message"
    const clickedDelete = await this.page.evaluate(() => {
      const items = Array.from(document.querySelectorAll('[role="button"], [role="menuitem"], li, div, span, button'));
      const del = items.find(el => /^(delete(?:\s+message)?)$/i.test((el.innerText || el.getAttribute('aria-label') || '').trim()));
      if (!del) return false;
      (del.closest('[role="button"], [role="menuitem"], button, li') || del).click();
      return true;
    });
    if (!clickedDelete) {
      return { success: false, error: 'Delete menu item not found (UI may have changed)' };
    }
    await new Promise(r => setTimeout(r, 600));

    // Confirm "Delete for everyone"
    const clickedEveryone = await this.page.evaluate(() => {
      const items = Array.from(document.querySelectorAll('button, [role="button"], div[role="button"], span'));
      const everyone = items.find(el => /delete\s+for\s+everyone/i.test((el.innerText || el.getAttribute('aria-label') || '').trim()));
      if (!everyone) return false;
      (everyone.closest('button, [role="button"]') || everyone).click();
      return true;
    });
    if (!clickedEveryone) {
      // Dismiss any "Delete for me" only dialog — treat missing option as failure
      await this.page.evaluate(() => {
        const cancel = Array.from(document.querySelectorAll('button, [role="button"]'))
          .find(el => /^(cancel|close)$/i.test((el.innerText || '').trim()));
        if (cancel) cancel.click();
      });
      return { success: false, error: 'Delete for everyone is not available for this message (window expired or WhatsApp hid the option)' };
    }
    await new Promise(r => setTimeout(r, 1200));

    // Verify: bubble gone, or shows revoked / "You deleted this message"
    const verified = await this.page.evaluate((targetId) => {
      const nodes = Array.from(document.querySelectorAll('#main [data-id]'));
      const el = nodes.find(n => (n.getAttribute('data-id') || '') === targetId);
      if (!el) return { ok: true, how: 'removed' };
      const txt = (el.innerText || '').toLowerCase();
      if (/you deleted this message|this message was deleted|message deleted/.test(txt)) {
        return { ok: true, how: 'placeholder' };
      }
      // Still present with original text — not revoked
      return { ok: false, how: 'still_present' };
    }, found.id);

    if (!verified || !verified.ok) {
      return { success: false, error: 'WhatsApp did not confirm the revoke (message still visible)' };
    }
    this.log('info', `Revoke confirmed for ${waMsgId} (${verified.how})`);
    return { success: true, wa_msg_id: waMsgId, revoke: verified.how };
  }

  async findOutgoingIdByBody(body) {
    const needle = String(body || '').trim();
    if (!needle || !this.page) return '';
    return await this.page.evaluate((text) => {
      const nodes = Array.from(document.querySelectorAll('#main [data-id]'));
      for (let i = nodes.length - 1; i >= 0; i--) {
        const el = nodes[i];
        const id = (el.getAttribute('data-id') || '').trim();
        if (!id || /^false_/i.test(id)) continue;
        const txt = (el.innerText || '').trim();
        if (txt.includes(text)) return id;
      }
      return '';
    }, needle.slice(0, 120));
  }

  async getLastSentMessageId(waChatId) {
    // Real WhatsApp id only. Modern WA Web often uses short data-ids (no true_jid_ prefix).
    return await this.page.evaluate(() => {
      const nodes = Array.from(document.querySelectorAll(
        '#main [data-id], #main [data-testid^="conv-msg-"], #main .message-out, #main [data-testid="msg-container"]'
      ));
      for (let i = nodes.length - 1; i >= 0; i--) {
        const el = nodes[i].closest('[data-id]') || nodes[i];
        const id = (el.getAttribute('data-id') || '').trim();
        if (!id || /^false_/i.test(id)) continue;
        const icons = Array.from(el.querySelectorAll('[data-icon]')).map(n => n.getAttribute('data-icon') || '').join(' ');
        const labels = Array.from(el.querySelectorAll('[aria-label]')).map(n => (n.getAttribute('aria-label') || '')).join(' ');
        const outgoing = /^true_/i.test(id)
          || /tail-out|msg-check|msg-dblcheck/i.test(icons)
          || /^(you:|read|delivered|sent)$/i.test(labels)
          || el.classList.contains('message-out')
          || !!el.closest('.message-out');
        if (outgoing) return id;
      }
      return '';
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
          'User-Agent': process.platform === 'win32'
            ? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            : process.platform === 'darwin'
              ? 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'
              : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36'
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
    const isIngest = String(endpoint).split('?')[0].endsWith('api_whatsapp_ingest.php');
    if (isIngest && !this.hasBridgeSecret()) {
      throw new Error('Refusing ingest: WHATSAPP_BRIDGE_SECRET missing or shorter than 16 characters');
    }

    // Sign and send the same bytes. Axios would re-serialize an object and
    // PHP's hash of php://input would not match.
    const payload = JSON.stringify(data);
    const signature = require('crypto')
      .createHmac('sha256', this.config.bridgeSecret)
      .update(payload)
      .digest('hex');
    
    return axios.post(`${this.config.apiBaseUrl}/${endpoint}`, payload, {
      headers: {
        'Content-Type': 'application/json',
        'X-WA-Signature': signature,
        'X-Bridge-Secret': this.config.bridgeSecret
      },
      transformRequest: [(body) => body],
      timeout: 30000
    }).then(r => r.data).catch(e => {
      this.log('error', `API POST ${endpoint} failed`, e.message);
      throw e;
    });
  }
  
  async apiGet(endpoint, params = {}) {
    return axios.get(`${this.config.apiBaseUrl}/${endpoint}`, {
      params,
      headers: { 'X-Bridge-Secret': this.config.bridgeSecret },
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
    this.shuttingDown = true;
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
  
  // Windows console signal compatibility
  if (process.platform === 'win32') {
    const readline = require('readline');
    const rl = readline.createInterface({
      input: process.stdin,
      output: process.stdout
    });
    rl.on('SIGINT', () => {
      process.emit('SIGINT');
    });
  }

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

  process.on('SIGBREAK', async () => {
    console.log('\nReceived SIGBREAK, shutting down...');
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

WhatsAppBridge.findChrome = findChrome;
WhatsAppBridge.getBridgeTempDir = getBridgeTempDir;

module.exports = WhatsAppBridge;
