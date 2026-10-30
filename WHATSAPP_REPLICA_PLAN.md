# HCC WhatsApp Web Replica — Complete UI & Architecture Plan

## Executive Summary
Build a **full WhatsApp Web clone** inside HCC admin: real-time chat list, conversation view, message bubbles, media handling, groups, search, archive/pin/mute, typing indicators, read receipts, voice notes, document preview — all synced with actual WhatsApp via Puppeteer automation + webhook simulation.

---

## Current State Analysis

### What Exists (Outbound Only)
| Component | Purpose | Gap |
|-----------|---------|-----|
| `whatsapp_share.php` | Admin picks students → preview PDF → send | One-way blast, no conversation history |
| `whatsapp-hub.js` | Student roster, composer, phone input, pane preview | No chat list, no message threads |
| `whatsapp-share.js` | PDF generation, base64 encoding, download | No message rendering |
| `whatsapp_sender.js` | Puppeteer batch send via WhatsApp Web | No inbound listening, no session persistence beyond QR |
| `whatsapp_dispatch_log` | Audit trail of sent messages | No inbound log, no conversation threading |

### What's Missing (Everything Inbound + Full UI)
- ❌ **Real-time message receiving** (webhook/polling from WhatsApp)
- ❌ **Chat list sidebar** (contacts, groups, search, filter, archive, pin, mute)
- ❌ **Conversation view** (bubbles, timestamps, read receipts, reply/forward/star/delete)
- ❌ **Media handling** (image/video preview, document download, voice playback, location map)
- ❌ **Group chat support** (participants, admin controls, group info)
- ❌ **Typing indicators, online/last seen, profile photos**
- ❌ **Emoji picker, GIF search, sticker support**
- ❌ **Message search within conversation + global search**
- ❌ **Contact management** (create, edit, block, report)
- ❌ **Backup/export chats (JSON/CSV/PDF)**
- ❌ **WhatsApp Business API / Cloud API integration** (for reliable inbound)
- ❌ **Multi-device session management** (linked devices)
- ❌ **End-to-end encryption indicator**
- ❌ **Disappearing messages timer**
- ❌ **Status/Stories view**
- ❌ **Call history (voice/video)**

---

## Architecture: Two-Track Approach

### Track A: Puppeteer-Based (Current Stack Enhanced)
**Pros:** No API costs, full WhatsApp Web fidelity, works with personal account
**Cons:** Fragile, requires QR login, no official inbound events, rate-limited, single-session

```
┌─────────────────────────────────────────────────────────────────────┐
│                        HCC ADMIN (PHP + JS)                         │
├─────────────────────────────────────────────────────────────────────┤
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐              │
│  │ Chat List    │  │ Conversation │  │ Media Viewer │  ← New UI    │
│  │ (Vue/React)  │  │ View         │  │ (Lightbox)   │              │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘              │
│         │                 │                 │                       │
│         ▼                 ▼                 ▼                       │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │              WhatsApp Core Service (JS Module)              │   │
│  │  • Message store (IndexedDB + Sync to MySQL)                │   │
│  │  • Contact resolver (phone → name, avatar, groups)          │   │
│  │  • Outbound queue (batch, retry, rate-limit)                │   │
│  │  • Inbound poller (Puppeteer bridge → WebSocket/SSE)        │   │
│  │  • Media cache (blob URLs, thumbnails)                      │   │
│  └──────────────────────────┬──────────────────────────────────┘   │
│                             │                                        │
│              ┌──────────────┴──────────────┐                       │
│              ▼                             ▼                       │
│    ┌─────────────────┐           ┌─────────────────┐              │
│    │ Puppeteer       │           │ MySQL Tables    │              │
│    │ Bridge (Node)   │◄─────────►│ • whatsapp_msgs │              │
│    │ • QR login      │           │ • whatsapp_chats│              │
│    │ • Send msg      │           │ • whatsapp_media│              │
│    │ • Poll inbound  │           │ • whatsapp_cts  │              │
│    │ • Emit events   │           │ • whatsapp_groups           │
│    └─────────────────┘           └─────────────────┘              │
└─────────────────────────────────────────────────────────────────────┘
```

### Track B: WhatsApp Cloud API (Meta Official) — Recommended for Production
**Pros:** Official webhooks, reliable inbound, multi-device, scalable, verified business
**Cons:** Requires Meta Business verification, per-conversation pricing, template approval for outbound

**Recommendation:** Build Track A first (local dev), design Track B interfaces for future swap.

---

## Database Schema (New Tables)

```sql
-- Core conversations (1:1 or group)
CREATE TABLE whatsapp_chats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wa_chat_id VARCHAR(100) NOT NULL UNIQUE,  -- "919876543210@c.us" or "12345@g.us"
    chat_type ENUM('individual','group','broadcast') DEFAULT 'individual',
    name VARCHAR(255),                          -- Group name or contact name
    avatar_url TEXT,
    is_archived BOOLEAN DEFAULT FALSE,
    is_pinned BOOLEAN DEFAULT FALSE,
    is_muted BOOLEAN DEFAULT FALSE,
    mute_until TIMESTAMP NULL,
    unread_count INT UNSIGNED DEFAULT 0,
    last_message_id BIGINT UNSIGNED,
    last_activity_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    metadata JSON,                              -- Group participants, description, etc.
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_last_activity (last_activity_at),
    INDEX idx_archived (is_archived),
    INDEX idx_pinned (is_pinned)
) ENGINE=InnoDB;

-- Messages (inbound + outbound)
CREATE TABLE whatsapp_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wa_msg_id VARCHAR(100) NOT NULL,            -- WhatsApp's message ID
    chat_id BIGINT UNSIGNED NOT NULL,
    from_me BOOLEAN NOT NULL,                   -- TRUE = sent by us, FALSE = received
    sender_id VARCHAR(100),                     -- WA ID of sender (for groups)
    sender_name VARCHAR(255),
    sender_avatar TEXT,
    msg_type ENUM('text','image','video','audio','document','sticker','location','contact','vcard','call','revoked','reaction','poll','order','payment') DEFAULT 'text',
    body TEXT,                                  -- Text content or caption
    media_url TEXT,                             -- Local cached URL or WhatsApp CDN URL
    media_mime VARCHAR(100),
    media_size BIGINT UNSIGNED,
    media_width INT UNSIGNED,
    media_height INT UNSIGNED,
    media_duration INT UNSIGNED,                -- For audio/video (seconds)
    thumbnail_url TEXT,
    quoted_msg_id BIGINT UNSIGNED NULL,         -- Reply context
    is_forwarded BOOLEAN DEFAULT FALSE,
    is_starred BOOLEAN DEFAULT FALSE,
    status ENUM('pending','sent','delivered','read','failed') DEFAULT 'pending',
    timestamp INT UNSIGNED NOT NULL,            -- WhatsApp epoch timestamp
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    acknowledged_at TIMESTAMP NULL,             -- When we sent read receipt
    metadata JSON,                              -- Location coords, contact vcard, poll options, etc.
    FOREIGN KEY (chat_id) REFERENCES whatsapp_chats(id) ON DELETE CASCADE,
    INDEX idx_chat_timestamp (chat_id, timestamp),
    INDEX idx_wa_msg_id (wa_msg_id),
    INDEX idx_from_me (from_me),
    INDEX idx_status (status),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB;

-- Media cache (separate for deduplication)
CREATE TABLE whatsapp_media (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wa_media_key VARCHAR(200) NOT NULL UNIQUE,  -- WhatsApp's media key/hash
    msg_id BIGINT UNSIGNED,
    local_path VARCHAR(500),                    -- /storage/whatsapp/media/...
    mime_type VARCHAR(100),
    file_size BIGINT UNSIGNED,
    width INT UNSIGNED,
    height INT UNSIGNED,
    duration INT UNSIGNED,
    thumbnail_path VARCHAR(500),
    download_status ENUM('pending','downloaded','failed') DEFAULT 'pending',
    expires_at TIMESTAMP NULL,                  -- WhatsApp media URLs expire
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (msg_id) REFERENCES whatsapp_messages(id) ON DELETE SET NULL,
    INDEX idx_wa_media_key (wa_media_key),
    INDEX idx_download_status (download_status)
) ENGINE=InnoDB;

-- Contacts (synced from phone + manual)
CREATE TABLE whatsapp_contacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    wa_id VARCHAR(100) NOT NULL UNIQUE,         -- "919876543210@c.us"
    phone_e164 VARCHAR(20),                     -- "+919876543210"
    name VARCHAR(255),                          -- Profile name
    push_name VARCHAR(255),                     -- WhatsApp push name
    avatar_url TEXT,
    is_business BOOLEAN DEFAULT FALSE,
    business_info JSON,
    is_blocked BOOLEAN DEFAULT FALSE,
    is_saved BOOLEAN DEFAULT FALSE,             -- In phone contacts
    labels JSON,                                -- Custom labels/tags
    last_seen_at TIMESTAMP NULL,
    source ENUM('whatsapp','manual','import','student_link') DEFAULT 'whatsapp',
    linked_student_roll VARCHAR(20),            -- If linked to HCC student
    linked_class_key VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_phone (phone_e164),
    INDEX idx_linked_student (linked_student_roll, linked_class_key)
) ENGINE=InnoDB;

-- Group participants
CREATE TABLE whatsapp_group_participants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED NOT NULL,
    is_admin BOOLEAN DEFAULT FALSE,
    is_super_admin BOOLEAN DEFAULT FALSE,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chat_id) REFERENCES whatsapp_chats(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES whatsapp_contacts(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_chat_contact (chat_id, contact_id)
) ENGINE=InnoDB;

-- Session state (Puppeteer)
CREATE TABLE whatsapp_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_name VARCHAR(50) DEFAULT 'default',
    user_data_dir VARCHAR(500),
    qr_code TEXT,
    qr_expires_at TIMESTAMP NULL,
    is_connected BOOLEAN DEFAULT FALSE,
    connected_at TIMESTAMP NULL,
    last_poll_at TIMESTAMP NULL,
    phone_number VARCHAR(50),
    device_name VARCHAR(100),
    platform VARCHAR(50),
    cookies JSON,
    local_storage JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Outbound queue (replaces current dispatch_log + adds retry)
CREATE TABLE whatsapp_outbound_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT UNSIGNED NOT NULL,
    contact_id BIGINT UNSIGNED,
    msg_type ENUM('text','image','video','audio','document','location','contact','vcard') DEFAULT 'text',
    body TEXT,
    media_path VARCHAR(500),
    media_mime VARCHAR(100),
    caption TEXT,
    priority TINYINT UNSIGNED DEFAULT 5,        -- 1=high, 10=low
    attempts TINYINT UNSIGNED DEFAULT 0,
    max_attempts TINYINT UNSIGNED DEFAULT 3,
    status ENUM('queued','processing','sent','delivered','read','failed','cancelled') DEFAULT 'queued',
    error_message TEXT,
    scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    sent_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    read_at TIMESTAMP NULL,
    dispatch_method ENUM('auto','manual','companion','api') DEFAULT 'auto',
    dispatched_by INT UNSIGNED,                 -- admin user ID
    related_student_roll VARCHAR(20),
    related_class_key VARCHAR(100),
    related_doc_type VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (chat_id) REFERENCES whatsapp_chats(id),
    FOREIGN KEY (contact_id) REFERENCES whatsapp_contacts(id),
    INDEX idx_status_priority (status, priority, scheduled_at),
    INDEX idx_dispatched_by (dispatched_by)
) ENGINE=InnoDB;
```

---

## Frontend Architecture (New UI)

### Tech Stack
- **Framework:** Vanilla ES6 Modules + Web Components (no build step, works in XAMPP)
- **State:** Centralized `WhatsAppStore` (singleton, IndexedDB-backed)
- **Real-time:** Server-Sent Events (SSE) from PHP → Puppeteer bridge
- **Styling:** CSS Variables matching WhatsApp Web (green #075E54, #128C7E, dark mode)
- **Icons:** Font Awesome 6 + custom SVG for WhatsApp-specific icons

### Component Tree
```
whatsapp-app (root)
├── whatsapp-sidebar
│   ├── whatsapp-search-bar
│   ├── whatsapp-chat-list
│   │   └── whatsapp-chat-item[] (virtual scroll)
│   └── whatsapp-profile-bar (own avatar, status, settings)
├── whatsapp-main
│   ├── whatsapp-conversation-header
│   │   ├── avatar + name + status (online/typing/last seen)
│   │   ├── call buttons (voice/video)
│   │   └── menu (view contact, media, search, mute, archive, block, clear)
│   ├── whatsapp-message-list (virtual scroll, reverse infinite)
│   │   ├── whatsapp-day-divider[]
│   │   └── whatsapp-message-bubble[]
│   │       ├── whatsapp-message-content (text/media/location/contact/poll)
│   │       ├── whatsapp-message-meta (time, status icons, star, reply preview)
│   │       └── whatsapp-message-actions (hover: reply, forward, star, delete, copy)
│   ├── whatsapp-typing-indicator
│   └── whatsapp-composer
│       ├── emoji-picker (toggle)
│       ├── attach-menu (photo, document, camera, contact, location, poll)
│       ├── input-area (textarea + mention autocomplete)
│       └── send-button (voice → mic when empty)
├── whatsapp-media-viewer (modal)
├── whatsapp-contact-picker (modal)
├── whatsapp-group-create (modal)
├── whatsapp-settings (modal)
└── whatsapp-qr-modal (when session disconnected)
```

### CSS Design System (WhatsApp Web Fidelity)
```css
:root {
  /* WhatsApp Green Palette */
  --wa-green: #075E54;
  --wa-green-light: #128C7E;
  --wa-green-lighter: #DCF8C6;
  --wa-green-dark: #054A42;
  
  /* Backgrounds */
  --wa-bg-chatlist: #F0F2F5;
  --wa-bg-chat: #E5DDD5;
  --wa-bg-chat-dark: #111B21;
  --wa-bg-message-in: #FFFFFF;
  --wa-bg-message-out: #DCF8C6;
  --wa-bg-message-out-dark: #005C4B;
  --wa-bg-panel: #FFFFFF;
  --wa-bg-hover: #F0F2F5;
  --wa-bg-selected: #E9EDEF;
  
  /* Text */
  --wa-text-primary: #111B21;
  --wa-text-secondary: #667781;
  --wa-text-tertiary: #8696A0;
  --wa-text-inverse: #FFFFFF;
  
  /* Borders */
  --wa-border: #E9EDEF;
  --wa-border-dark: #2A3942;
  
  /* Status Colors */
  --wa-sent: #A5B3BB;
  --wa-delivered: #A5B3BB;
  --wa-read: #53BDEB;
  --wa-failed: #E53935;
  
  /* Spacing */
  --wa-radius-sm: 4px;
  --wa-radius-md: 8px;
  --wa-radius-lg: 12px;
  --wa-radius-full: 9999px;
  
  /* Shadows */
  --wa-shadow-sm: 0 1px 2px rgba(0,0,0,0.1);
  --wa-shadow-md: 0 4px 8px rgba(0,0,0,0.12);
  --wa-shadow-lg: 0 8px 24px rgba(0,0,0,0.16);
  
  /* Transitions */
  --wa-transition: 150ms ease;
}

/* Dark mode */
@media (prefers-color-scheme: dark) {
  :root {
    --wa-bg-chatlist: #111B21;
    --wa-bg-chat: #202C33;
    --wa-bg-message-in: #2A3942;
    --wa-bg-message-out: #005C4B;
    --wa-bg-panel: #2A3942;
    --wa-bg-hover: #2A3942;
    --wa-bg-selected: #35414A;
    --wa-text-primary: #E9EDEF;
    --wa-text-secondary: #8696A0;
    --wa-border: #35414A;
  }
}
```

---

## Puppeteer Bridge Enhancement (Node.js)

### Current: `whatsapp_sender.js` (289 lines, send-only)
### Target: Full Bridge Service (~1500 lines)

```javascript
// whatsapp-bridge.js — Core Architecture
class WhatsAppBridge {
  constructor(config) {
    this.config = config;
    this.browser = null;
    this.page = null;
    this.session = null;
    this.eventEmitter = new EventEmitter();
    this.messageQueue = [];
    this.isPolling = false;
    this.pollInterval = null;
    this.lastMsgTimestamp = 0;
  }

  // Session Management
  async init() { /* launch persistent browser, restore cookies */ }
  async getQR() { /* return base64 QR for frontend */ }
  async waitForLogin() { /* detect successful auth */ }
  async saveSession() { /* persist cookies + localStorage to DB */ }
  
  // Outbound
  async sendText(chatId, text) { /* ... */ }
  async sendMedia(chatId, filePath, caption, mimeType) { /* ... */ }
  async sendLocation(chatId, lat, lng, name, address) { /* ... */ }
  async sendContact(chatId, vcard) { /* ... */ }
  async reactToMessage(msgId, emoji) { /* ... */ }
  async deleteMessage(msgId, forEveryone) { /* ... */ }
  async forwardMessage(msgId, targetChatId) { /* ... */ }
  async starMessage(msgId, star) { /* ... */ }
  async pinChat(chatId, pin) { /* ... */ }
  async muteChat(chatId, duration) { /* ... */ }
  async archiveChat(chatId, archive) { /* ... */ }
  async markChatRead(chatId) { /* ... */ }
  async sendReadReceipt(msgId) { /* ... */ }
  async sendTyping(chatId, typing) { /* ... */ }
  
  // Inbound Polling (runs in background)
  async startPolling() { 
    this.isPolling = true;
    this.pollInterval = setInterval(() => this.pollMessages(), 3000);
  }
  async pollMessages() {
    // Inject JS into page to extract new messages since lastMsgTimestamp
    const newMessages = await this.page.evaluate((ts) => {
      // Traverse DOM: document.querySelectorAll('[data-testid="msg-container"]')
      // Extract: msgId, chatId, fromMe, sender, type, body, media, timestamp, quoted, status
      return messages.filter(m => m.timestamp > ts);
    }, this.lastMsgTimestamp);
    
    for (const msg of newMessages) {
      this.lastMsgTimestamp = Math.max(this.lastMsgTimestamp, msg.timestamp);
      this.eventEmitter.emit('message', msg);
      // Persist to MySQL via HTTP POST to api_whatsapp_ingest.php
    }
  }
  
  // Contact/Chat Sync
  async syncContacts() { /* Get all contacts from WhatsApp Web */ }
  async syncChats() { /* Get chat list with last message preview */ }
  async getChatInfo(chatId) { /* Group info, participants, etc. */ }
  async downloadMedia(msgId) { /* Blob → save to storage, return local URL */ }
}
```

### New API Endpoints Needed

| Endpoint | Purpose |
|----------|---------|
| `api_whatsapp_qr.php` | Return current QR code for login |
| `api_whatsapp_session_status.php` | Check if connected |
| `api_whatsapp_send.php` | Queue outbound message |
| `api_whatsapp_ingest.php` | Receive inbound from Puppeteer bridge |
| `api_whatsapp_chats.php` | List chats (paginated, filtered) |
| `api_whatsapp_messages.php` | Get messages for a chat (paginated) |
| `api_whatsapp_media.php` | Proxy media downloads |
| `api_whatsapp_contacts.php` | Search/list contacts |
| `api_whatsapp_chat_actions.php` | Pin, archive, mute, block, delete |
| `api_whatsapp_typing.php` | Send typing indicator |
| `api_whatsapp_presence.php` | Get online/last seen |
| `api_whatsapp_qr_refresh.php` | Force new QR |
| `api_whatsapp_logout.php` | Disconnect session |
| `api_whatsapp_sync.php` | Trigger full contact/chat sync |

---

## Implementation Phases

### Phase 1: Foundation (Week 1-2)
- [ ] Create all MySQL tables
- [ ] Build `api_whatsapp_ingest.php` (secure, validates HMAC from bridge)
- [ ] Build `api_whatsapp_chats.php` + `api_whatsapp_messages.php`
- [ ] Enhance `whatsapp_sender.js` → `whatsapp-bridge.js` with polling + event emitter
- [ ] Add SSE endpoint: `api_whatsapp_events.php` (frontend subscribes)
- [ ] Store session cookies in `whatsapp_sessions` table

### Phase 2: Chat List UI (Week 2-3)
- [ ] `whatsapp-chat-list` web component with virtual scroll
- [ ] `whatsapp-chat-item` with avatar, name, last msg preview, time, unread badge, pin/mute/archive icons
- [ ] Search filter (debounced, searches name + last message)
- [ ] Tabs: All / Unread / Groups / Broadcast / Archived
- [ ] Context menu: Pin, Archive, Mute, Block, Delete, Mark unread
- [ ] Drag-to-pin, swipe actions (mobile)
- [ ] Load more on scroll (cursor pagination)

### Phase 3: Conversation View (Week 3-4)
- [ ] `whatsapp-message-list` with reverse virtual scroll (newest at bottom)
- [ ] `whatsapp-message-bubble` — all types:
  - Text (with link preview, emoji rendering)
  - Image (click → lightbox, zoom, pan)
  - Video (inline play, progress bar, picture-in-picture)
  - Audio/Voice (waveform visualization, playback speed, seek)
  - Document (icon + name + size, click → download/preview)
  - Location (static map → click → Google Maps)
  - Contact/vCard (preview card, save to contacts)
  - Sticker (Lottie/WebP animation)
  - Poll (vote UI, results)
  - Reaction (emoji on bubble corner)
  - System messages (calls, group events, disappearing timer)
- [ ] Message grouping by date (day dividers)
- [ ] Read receipts (single/double check, blue double check)
- [ ] Reply context (swipe or click → reply bar)
- [ ] Forward/Star/Delete/Copy/Info actions (hover/long-press)
- [ ] Jump to date, search within chat

### Phase 4: Composer & Media (Week 4-5)
- [ ] `whatsapp-composer` with auto-resize textarea
- [ ] Emoji picker (categorized, recent, search)
- [ ] Attach menu: Photo/Video, Camera, Document, Contact, Location, Poll
- [ ] Media preview before send (crop, rotate, caption, draw, sticker)
- [ ] Voice recording (hold mic → record, slide to cancel, waveform)
- [ ] Mention autocomplete (@name in groups)
- [ ] Send button states: mic → send → sending → sent → delivered → read

### Phase 5: Real-time Sync & Polish (Week 5-6)
- [ ] SSE connection manager (reconnect, heartbeat, backoff)
- [ ] Optimistic UI (instant local render, sync status icons)
- [ ] Offline queue (IndexedDB → flush on reconnect)
- [ ] Media lazy-loading + cache (Service Worker optional)
- [ ] Keyboard shortcuts (Ctrl+N, Ctrl+Shift+M, Ctrl+E, etc.)
- [ ] Accessibility (ARIA, focus management, screen reader)
- [ ] Dark mode toggle + system preference sync

### Phase 6: Advanced Features (Week 6-8)
- [ ] Group management (create, add/remove, promote/demote, group info)
- [ ] Broadcast lists
- [ ] Status/Stories view (ephemeral, 24h)
- [ ] Call history tab
- [ ] Chat export (TXT, CSV, HTML, PDF with media)
- [ ] Backup to Google Drive (integration with existing Drive workspace)
- [ ] Linked devices management
- [ ] Disappearing messages timer UI
- [ ] Chat lock (fingerprint/password)
- [ ] Multi-account support (tabs)

---

## File Structure (New)

```
/Applications/XAMPP/xamppfiles/htdocs/hcc/
├── admin/
│   ├── whatsapp.php                 # Main entry (replaces whatsapp_share.php)
│   ├── whatsapp_chats.php           # Chat list partial (AJAX)
│   ├── whatsapp_messages.php        # Conversation partial (AJAX)
│   ├── api_whatsapp_*.php           # All API endpoints above
│   └── includes/
│       └── whatsapp_functions.php   # Shared PHP helpers
├── assets/
│   ├── js/
│   │   ├── whatsapp/
│   │   │   ├── core/
│   │   │   │   ├── WhatsAppStore.js       # Central state + IndexedDB
│   │   │   │   ├── WhatsAppAPI.js         # API client + SSE
│   │   │   │   ├── WhatsAppRouter.js      # Hash routing (chat/:id)
│   │   │   │   └── WhatsAppEvents.js      # Custom events
│   │   │   ├── components/
│   │   │   │   ├── whatsapp-app.js
│   │   │   │   ├── whatsapp-sidebar.js
│   │   │   │   ├── whatsapp-chat-list.js
│   │   │   │   ├── whatsapp-chat-item.js
│   │   │   │   ├── whatsapp-search-bar.js
│   │   │   │   ├── whatsapp-main.js
│   │   │   │   ├── whatsapp-conversation-header.js
│   │   │   │   ├── whatsapp-message-list.js
│   │   │   │   ├── whatsapp-message-bubble.js
│   │   │   │   ├── whatsapp-message-content.js
│   │   │   │   ├── whatsapp-typing-indicator.js
│   │   │   │   ├── whatsapp-composer.js
│   │   │   │   ├── whatsapp-emoji-picker.js
│   │   │   │   ├── whatsapp-attach-menu.js
│   │   │   │   ├── whatsapp-media-viewer.js
│   │   │   │   ├── whatsapp-contact-picker.js
│   │   │   │   ├── whatsapp-group-create.js
│   │   │   │   ├── whatsapp-settings.js
│   │   │   │   └── whatsapp-qr-modal.js
│   │   │   ├── utils/
│   │   │   │   ├── date-format.js
│   │   │   │   ├── virtual-scroll.js
│   │   │   │   ├── media-cache.js
│   │   │   │   ├── link-preview.js
│   │   │   │   ├── emoji-data.js
│   │   │   │   └── waveform.js
│   │   │   └── index.js                   # Entry point
│   │   └── whatsapp-bridge-client.js      # DevTools helper
│   ├── css/
│   │   ├── whatsapp.css                   # Main design system
│   │   ├── whatsapp-components.css        # Component styles
│   │   ├── whatsapp-dark.css              # Dark mode
│   │   └── whatsapp-print.css             # Chat export print
│   └── vendor/
│       └── (lottie, emoji-picker, etc.)
├── automation/
│   ├── package.json
│   ├── whatsapp-bridge.js                 # Enhanced Puppeteer bridge
│   ├── whatsapp-bridge.service.js         # systemd/pm2 service file
│   ├── utils/
│   │   ├── dom-extractors.js              # Page evaluation scripts
│   │   ├── media-downloader.js
│   │   └── session-manager.js
│   └── .env                               # Config
└── storage/
    └── whatsapp/
        ├── media/                         # Downloaded media files
        ├── thumbnails/
        └── backups/
```

---

## Integration with Existing HCC Features

| Feature | Integration Point |
|---------|-------------------|
| **Student/Parent Data** | Auto-link `whatsapp_contacts` to `students` via phone; show student name/roll in chat header |
| **Report Cards/Slips/Bonafides** | "Send via WhatsApp" button in existing views → opens WhatsApp app with pre-filled chat |
| **Dispatch Log** | Merge `whatsapp_dispatch_log` → `whatsapp_outbound_queue` (add inbound tracking) |
| **Google Drive Workspace** | Auto-backup chats/media to Drive; "Open in Drive" from media viewer |
| **Teacher Collaboration** | Share student chat context with colleague (forward thread) |
| **Analytics** | Message volume, response time, parent engagement metrics |

---

## Security & Privacy

- **Row-level security:** Admin only sees chats for their assigned classes (or all if superadmin)
- **Media proxy:** `api_whatsapp_media.php` streams with auth, prevents direct CDN exposure
- **Session isolation:** Each admin gets separate Puppeteer profile (or shared business account)
- **PII encryption:** Encrypt `wa_msg_id`, `phone_e164` at rest (AES-256)
- **Audit trail:** All admin actions (send, delete, forward) logged
- **Rate limiting:** Respect WhatsApp limits (100 msgs/min, 250/day new contacts)

---

## Testing Checklist

### Unit/Integration
- [ ] Database migrations up/down
- [ ] API contract tests (OpenAPI spec)
- [ ] Puppeteer bridge: login, send, receive, media, groups
- [ ] SSE reconnection under network failure
- [ ] IndexedDB sync consistency

### UI/UX (Visual Regression)
- [ ] Chat list: density, avatar fallback, unread badge, pin/mute icons
- [ ] Conversation: bubble tail radius, text wrapping, timestamp alignment
- [ ] Media: image zoom/pan, video controls, audio waveform
- [ ] Composer: emoji picker positioning, attach menu dropdown
- [ ] Dark mode: all components, scrollbar, selection
- [ ] Mobile: touch gestures, swipe reply, pull-to-load-more

### E2E Scenarios
- [ ] QR login → see chats → open chat → send text → receive reply
- [ ] Receive image → click → view fullscreen → download
- [ ] Create group → add participants → send message
- [ ] Archive chat → unarchive from archived tab
- [ ] Search "exam" → highlights in chat list + conversation
- [ ] Network disconnect → queue message → reconnect → auto-send

---

## Effort Estimate

| Phase | Backend (PHP/Node) | Frontend (JS/CSS) | Total |
|-------|-------------------|-------------------|-------|
| 1. Foundation | 24h | 8h | 32h |
| 2. Chat List | 8h | 24h | 32h |
| 3. Conversation | 16h | 40h | 56h |
| 4. Composer/Media | 12h | 24h | 36h |
| 5. Real-time/Polish | 16h | 24h | 40h |
| 6. Advanced | 24h | 32h | 56h |
| **Total** | **~100h** | **~152h** | **~252h** |

**Minimum Viable Replica (Phases 1-4):** ~156h
**Production-Ready (All Phases):** ~252h

---

## Immediate Next Steps (If Approved)

1. **Approve schema** → Run migrations
2. **Create `whatsapp-bridge.js`** from current `whatsapp_sender.js` + polling
3. **Build `api_whatsapp_ingest.php`** + SSE endpoint
4. **Scaffold frontend component structure** (empty components with CSS)
5. **Implement chat list** (read-only from DB, no real-time yet)
6. **Implement conversation view** (read-only)
7. **Wire up outbound send** (existing Puppeteer + new queue)
8. **Enable inbound polling** (bridge → API → SSE → UI)

---

## Decision Required

**Track A (Puppeteer) vs Track B (Cloud API):**
- Track A: Free, full fidelity, but fragile. Good for internal tool.
- Track B: Reliable, official, but costs + verification. Required for parent-facing portal.

**Recommendation:** Build Track A fully first. Abstract all WhatsApp interactions behind `WhatsAppAdapter` interface so Track B can be swapped later.

---

*Document generated: 2026-10-24*
*Based on analysis of existing HCC WhatsApp Share Hub codebase*
