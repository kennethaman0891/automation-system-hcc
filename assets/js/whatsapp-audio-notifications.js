/**
 * HCC WhatsApp Web Replica — Sound Synthesizer & Notification Engine
 * Pure Web Audio API synthesis (zero external audio files) & Desktop/In-app Notifications
 */

class WhatsAppAudio {
  constructor() {
    this.ctx = null;
    this.isRinging = false;
    this.ringInterval = null;
    this.activeNodes = [];
    this.isMuted = false;

    // Auto-unlock AudioContext on first user interaction
    this.setupUnlockListeners();
  }

  setupUnlockListeners() {
    if (typeof window === 'undefined' || typeof document === 'undefined') return;

    const unlock = () => {
      if (this.ctx && this.ctx.state === 'suspended') {
        this.ctx.resume().catch(() => {});
      }
      document.removeEventListener('click', unlock, true);
      document.removeEventListener('keydown', unlock, true);
      document.removeEventListener('touchstart', unlock, true);
    };

    document.addEventListener('click', unlock, true);
    document.addEventListener('keydown', unlock, true);
    document.addEventListener('touchstart', unlock, true);
  }

  getAudioContext() {
    if (typeof window === 'undefined') return null;
    if (!this.ctx) {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) {
        console.warn('Web Audio API not supported in this browser.');
        return null;
      }
      this.ctx = new AudioCtx();
    }
    if (this.ctx.state === 'suspended') {
      this.ctx.resume().catch(() => {});
    }
    return this.ctx;
  }

  setMuted(muted) {
    this.isMuted = !!muted;
  }

  /**
   * Authentic WhatsApp outgoing message sound ("pop/swoosh").
   * Frequency ramp from ~400Hz up to ~850Hz with exponential gain decay.
   */
  playSentSound() {
    if (this.isMuted) return;
    try {
      const ctx = this.getAudioContext();
      if (!ctx || ctx.state === 'suspended') return;

      const now = ctx.currentTime;

      // Primary tone: quick rising pop (400Hz -> 850Hz)
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();

      osc.type = 'sine';
      osc.frequency.setValueAtTime(400, now);
      osc.frequency.exponentialRampToValueAtTime(850, now + 0.035);

      gain.gain.setValueAtTime(0.32, now);
      gain.gain.exponentialRampToValueAtTime(0.001, now + 0.11);

      osc.connect(gain);
      gain.connect(ctx.destination);

      osc.start(now);
      osc.stop(now + 0.12);

      // Subtle tactile click body
      const clickOsc = ctx.createOscillator();
      const clickGain = ctx.createGain();
      clickOsc.type = 'triangle';
      clickOsc.frequency.setValueAtTime(180, now);
      clickGain.gain.setValueAtTime(0.12, now);
      clickGain.gain.exponentialRampToValueAtTime(0.001, now + 0.04);

      clickOsc.connect(clickGain);
      clickGain.connect(ctx.destination);

      clickOsc.start(now);
      clickOsc.stop(now + 0.045);
    } catch (err) {
      console.warn('WhatsAppAudio.playSentSound failed:', err);
    }
  }

  /**
   * Authentic WhatsApp incoming message sound ("ding/chime").
   * Harmonic two-tone chime: ~880Hz A5 followed by 1318.5Hz E6 with gentle decay.
   */
  playReceivedSound() {
    if (this.isMuted) return;
    try {
      const ctx = this.getAudioContext();
      if (!ctx || ctx.state === 'suspended') return;

      const now = ctx.currentTime;

      // --- Tone 1: 880Hz (A5) ---
      const osc1 = ctx.createOscillator();
      const gain1 = ctx.createGain();
      osc1.type = 'sine';
      osc1.frequency.setValueAtTime(880, now);

      gain1.gain.setValueAtTime(0.28, now);
      gain1.gain.exponentialRampToValueAtTime(0.001, now + 0.13);

      osc1.connect(gain1);
      gain1.connect(ctx.destination);

      osc1.start(now);
      osc1.stop(now + 0.14);

      // Soft 2nd harmonic for A5 warmth (1760Hz)
      const harm1 = ctx.createOscillator();
      const harmGain1 = ctx.createGain();
      harm1.type = 'sine';
      harm1.frequency.setValueAtTime(1760, now);
      harmGain1.gain.setValueAtTime(0.05, now);
      harmGain1.gain.exponentialRampToValueAtTime(0.0005, now + 0.09);
      harm1.connect(harmGain1);
      harmGain1.connect(ctx.destination);
      harm1.start(now);
      harm1.stop(now + 0.1);

      // --- Tone 2: 1318.51Hz (E6) ---
      const t2 = now + 0.085;
      const osc2 = ctx.createOscillator();
      const gain2 = ctx.createGain();
      osc2.type = 'sine';
      osc2.frequency.setValueAtTime(1318.51, t2);

      gain2.gain.setValueAtTime(0.0001, now);
      gain2.gain.setValueAtTime(0.38, t2);
      gain2.gain.exponentialRampToValueAtTime(0.0001, t2 + 0.42);

      osc2.connect(gain2);
      gain2.connect(ctx.destination);

      osc2.start(t2);
      osc2.stop(t2 + 0.45);

      // Soft 2nd harmonic for E6 warmth (2637Hz)
      const harm2 = ctx.createOscillator();
      const harmGain2 = ctx.createGain();
      harm2.type = 'sine';
      harm2.frequency.setValueAtTime(2637, t2);
      harmGain2.gain.setValueAtTime(0.0001, now);
      harmGain2.gain.setValueAtTime(0.06, t2);
      harmGain2.gain.exponentialRampToValueAtTime(0.0005, t2 + 0.25);
      harm2.connect(harmGain2);
      harmGain2.connect(ctx.destination);
      harm2.start(t2);
      harm2.stop(t2 + 0.28);
    } catch (err) {
      console.warn('WhatsAppAudio.playReceivedSound failed:', err);
    }
  }

  /**
   * Synthesizes WhatsApp voice/video call ringing tone.
   * Plays a melodic cadence (warm marimba-like sequence) repeating every 2.4s.
   */
  playRingtone() {
    if (this.isMuted) return;
    this.stopRingtone();
    this.isRinging = true;

    const playCycle = () => {
      if (!this.isRinging) return;
      const ctx = this.getAudioContext();
      if (!ctx || ctx.state === 'suspended') return;

      const now = ctx.currentTime;
      // Melodic notes sequence: E5, G#5, B5, E6, D6, B5
      const notes = [
        { f: 659.25, start: 0.00, dur: 0.12, vol: 0.22 },
        { f: 830.61, start: 0.12, dur: 0.12, vol: 0.22 },
        { f: 987.77, start: 0.24, dur: 0.14, vol: 0.24 },
        { f: 1318.51, start: 0.38, dur: 0.32, vol: 0.28 },
        { f: 1174.66, start: 0.85, dur: 0.12, vol: 0.20 },
        { f: 987.77, start: 0.97, dur: 0.30, vol: 0.24 }
      ];

      notes.forEach(({ f, start, dur, vol }) => {
        if (!this.isRinging) return;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = 'triangle';
        osc.frequency.setValueAtTime(f, now + start);

        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.setValueAtTime(vol, now + start);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + start + dur);

        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.start(now + start);
        osc.stop(now + start + dur + 0.05);

        this.activeNodes.push(osc, gain);
      });
    };

    playCycle();
    this.ringInterval = setInterval(playCycle, 2400);
  }

  /**
   * Stops active ringtone immediately.
   */
  stopRingtone() {
    this.isRinging = false;
    if (this.ringInterval) {
      clearInterval(this.ringInterval);
      this.ringInterval = null;
    }
    if (this.activeNodes && this.activeNodes.length > 0) {
      this.activeNodes.forEach(node => {
        try {
          if (node.stop) node.stop();
          if (node.disconnect) node.disconnect();
        } catch (e) {}
      });
      this.activeNodes = [];
    }
  }
}

class WhatsAppNotifications {
  constructor() {
    this.container = null;
    this.defaultIcon = '/hcc/assets/images/hcclogo.jpg';
    /** @type {Map<string, {el: HTMLElement, count: number, chatId: *, title: string, timer: *}>} */
    this.toastsByChat = new Map();
    this.overflowEl = null;
    this.maxVisible = 3;
    this.defaultTimeout = 4500;
    this.injectStyles();
  }

  injectStyles() {
    if (typeof document === 'undefined') return;
    const existing = document.getElementById('wa-notifications-style');
    if (existing) existing.remove();

    const style = document.createElement('style');
    style.id = 'wa-notifications-style';
    style.textContent = `
      #wa-notification-container {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 999999;
        display: flex;
        flex-direction: column;
        gap: 10px;
        max-width: 380px;
        width: calc(100vw - 40px);
        pointer-events: none;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      }
      .wa-toast-banner, .wa-toast-overflow {
        pointer-events: auto;
        display: flex;
        align-items: center;
        gap: 12px;
        background: #111b21;
        color: #e9edef;
        border: 1px solid rgba(134, 150, 160, 0.22);
        border-left: 4px solid #00a884;
        border-radius: 10px;
        padding: 12px 14px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.35);
        cursor: pointer;
        transition: transform 0.28s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.28s ease, filter 0.2s ease;
        transform: translateX(110%);
        opacity: 0;
      }
      .wa-light .wa-toast-banner, html:not(.wa-dark) .wa-toast-banner,
      .wa-light .wa-toast-overflow, html:not(.wa-dark) .wa-toast-overflow {
        background: #ffffff;
        color: #111b21;
        border: 1px solid rgba(0, 0, 0, 0.08);
        border-left: 4px solid #00a884;
        box-shadow: 0 4px 18px rgba(0, 0, 0, 0.12);
      }
      .wa-toast-banner.wa-toast-visible, .wa-toast-overflow.wa-toast-visible {
        transform: translateX(0);
        opacity: 1;
      }
      .wa-toast-banner.wa-toast-hiding, .wa-toast-overflow.wa-toast-hiding {
        transform: translateX(110%);
        opacity: 0;
      }
      .wa-toast-banner:hover, .wa-toast-overflow:hover { filter: brightness(1.05); }
      .wa-toast-avatar {
        width: 42px; height: 42px; border-radius: 50%; object-fit: cover;
        background: #202c33; flex-shrink: 0; display: flex; align-items: center;
        justify-content: center; font-weight: 600; color: #00a884; font-size: 16px;
      }
      .wa-light .wa-toast-avatar, html:not(.wa-dark) .wa-toast-avatar { background: #e9edef; }
      .wa-toast-content { flex: 1; min-width: 0; }
      .wa-toast-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 3px; gap: 8px; }
      .wa-toast-title {
        font-size: 14px; font-weight: 600; color: inherit;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      }
      .wa-toast-count {
        font-size: 11px; font-weight: 700; background: #00a884; color: #fff;
        border-radius: 999px; padding: 1px 7px; flex-shrink: 0;
      }
      .wa-toast-time { font-size: 11px; color: #8696a0; margin-left: auto; flex-shrink: 0; }
      .wa-toast-body {
        font-size: 13px; color: #8696a0; white-space: nowrap;
        overflow: hidden; text-overflow: ellipsis; line-height: 1.35;
      }
      .wa-toast-close {
        background: transparent; border: none; color: #8696a0; cursor: pointer;
        font-size: 18px; line-height: 1; padding: 4px; margin-left: 4px;
        border-radius: 50%; display: flex; align-items: center; justify-content: center;
        width: 24px; height: 24px; flex-shrink: 0;
      }
      .wa-toast-close:hover { background: rgba(134, 150, 160, 0.15); color: #e9edef; }
      .wa-light .wa-toast-close:hover, html:not(.wa-dark) .wa-toast-close:hover { color: #111b21; }
      .wa-toast-overflow { justify-content: center; font-size: 13px; font-weight: 600; cursor: default; }
    `;
    document.head.appendChild(style);
  }

  getContainer() {
    if (typeof document === 'undefined') return null;
    let container = document.getElementById('wa-notification-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'wa-notification-container';
      document.body.appendChild(container);
    }
    return container;
  }

  chatKey(options = {}, title = '') {
    if (options.chatId != null && options.chatId !== '') return 'c:' + String(options.chatId);
    return 't:' + String(title || 'WhatsApp');
  }

  shouldSuppress(options = {}) {
    const store = (typeof window !== 'undefined') ? window.WhatsAppStore : null;
    if (options.force) return false;
    if (options.silentToast) return true;
    if (store) {
      if (store.suppressNotifications) return true;
      if (store._initialSyncing) return true;
      if (options.chatId != null && store.activeChatId != null
          && String(store.activeChatId) === String(options.chatId)) {
        return true;
      }
    }
    return false;
  }

  async requestPermission() {
    if (typeof window === 'undefined' || !('Notification' in window)) return 'denied';
    if (Notification.permission === 'granted') return 'granted';
    if (Notification.permission !== 'denied') {
      try { return await Notification.requestPermission(); } catch (e) { return 'denied'; }
    }
    return Notification.permission;
  }

  show(title, body, options = {}) {
    if (this.shouldSuppress(options)) return null;

    const isPageFocused = (typeof document !== 'undefined' && document.hasFocus) ? document.hasFocus() : true;
    const canNative = (typeof window !== 'undefined' && 'Notification' in window && Notification.permission === 'granted');

    if (canNative && (!isPageFocused || options.forceDesktop)) {
      try {
        const iconPath = options.icon || this.defaultIcon;
        const tag = options.tag || ('wa_chat_' + this.chatKey(options, title));
        const nativeNotif = new Notification(title || 'WhatsApp Message', {
          body: body || '',
          icon: iconPath,
          badge: iconPath,
          tag,
          renotify: true,
          silent: options.silent || false
        });
        nativeNotif.onclick = () => {
          if (typeof window !== 'undefined') window.focus();
          this.openChat(options);
          nativeNotif.close();
        };
        // Still show in-app grouped toast when focused later? Only native when blurred.
        return nativeNotif;
      } catch (err) {
        console.warn('Native notification failed, falling back to toast:', err);
      }
    }

    return this.showToast(title, body, options);
  }

  openChat(options = {}) {
    if (typeof options.onClick === 'function') {
      options.onClick();
      return;
    }
    if (options.chatId != null && window.WhatsAppStore && typeof window.WhatsAppStore.setActiveChat === 'function') {
      window.WhatsAppStore.setActiveChat(options.chatId);
    }
  }

  showToast(title, body, options = {}) {
    if (typeof document === 'undefined') return null;
    if (this.shouldSuppress(options)) return null;
    this.injectStyles();
    const container = this.getContainer();
    if (!container) return null;

    const key = this.chatKey(options, title);
    const existing = this.toastsByChat.get(key);
    const timeStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const avatarSrc = options.avatar || options.icon || this.defaultIcon;
    const initial = (title || 'W').charAt(0).toUpperCase();
    const timeout = options.timeout || this.defaultTimeout;

    if (existing && existing.el && document.body.contains(existing.el)) {
      existing.count += 1;
      existing.title = title || existing.title;
      existing.body = body || existing.body;
      existing.options = { ...existing.options, ...options };
      const countEl = existing.el.querySelector('.wa-toast-count');
      if (countEl) {
        countEl.textContent = String(existing.count);
        countEl.style.display = existing.count > 1 ? 'inline-block' : 'none';
      }
      const bodyEl = existing.el.querySelector('.wa-toast-body');
      if (bodyEl) bodyEl.textContent = body || '';
      const titleEl = existing.el.querySelector('.wa-toast-title');
      if (titleEl) titleEl.textContent = title || 'New Message';
      const timeEl = existing.el.querySelector('.wa-toast-time');
      if (timeEl) timeEl.textContent = timeStr;
      // Move to top of stack (most recent)
      container.insertBefore(existing.el, container.firstChild);
      if (existing.timer) clearTimeout(existing.timer);
      existing.timer = setTimeout(() => this.dismissToast(existing.el), timeout);
      existing.el._dismissTimer = existing.timer;
      this.enforceCap();
      return existing.el;
    }

    const toast = document.createElement('div');
    toast.className = 'wa-toast-banner';
    toast.dataset.chatKey = key;
    toast.innerHTML = `
      <img class="wa-toast-avatar" src="${avatarSrc}" alt="${this.escapeHtml(title || '')}" onerror="this.outerHTML='<div class=\\'wa-toast-avatar\\'>${initial}</div>'">
      <div class="wa-toast-content">
        <div class="wa-toast-header">
          <div class="wa-toast-title">${this.escapeHtml(title || 'New Message')}</div>
          <span class="wa-toast-count" style="display:none">1</span>
          <div class="wa-toast-time">${timeStr}</div>
        </div>
        <div class="wa-toast-body">${this.escapeHtml(body || '')}</div>
      </div>
      <button class="wa-toast-close" title="Close" aria-label="Close">×</button>
    `;

    toast.addEventListener('click', (e) => {
      if (e.target.closest('.wa-toast-close')) return;
      const entry = this.toastsByChat.get(key);
      this.openChat((entry && entry.options) || options);
      this.dismissToast(toast);
    });
    toast.querySelector('.wa-toast-close')?.addEventListener('click', (e) => {
      e.stopPropagation();
      this.dismissToast(toast);
    });

    container.insertBefore(toast, container.firstChild);
    if (typeof requestAnimationFrame !== 'undefined') {
      requestAnimationFrame(() => toast.classList.add('wa-toast-visible'));
    } else {
      toast.classList.add('wa-toast-visible');
    }

    const timer = setTimeout(() => this.dismissToast(toast), timeout);
    toast._dismissTimer = timer;
    this.toastsByChat.set(key, {
      el: toast, count: 1, chatId: options.chatId, title: title || 'WhatsApp',
      body: body || '', options: { ...options }, timer
    });
    this.enforceCap();
    return toast;
  }

  enforceCap() {
    const container = this.getContainer();
    if (!container) return;
    const banners = Array.from(container.querySelectorAll('.wa-toast-banner'));
    // Keep first maxVisible; hide the rest and show overflow chip
    let hidden = 0;
    banners.forEach((el, idx) => {
      if (idx < this.maxVisible) {
        el.style.display = '';
      } else {
        el.style.display = 'none';
        hidden += 1;
      }
    });

    if (hidden > 0) {
      if (!this.overflowEl || !document.body.contains(this.overflowEl)) {
        this.overflowEl = document.createElement('div');
        this.overflowEl.className = 'wa-toast-overflow wa-toast-visible';
        this.overflowEl.addEventListener('click', () => {
          // Reveal next hidden toast by dismissing oldest visible? Prefer expand briefly.
          banners.forEach((el) => { el.style.display = ''; });
          this.overflowEl.remove();
          this.overflowEl = null;
          // Re-cap after a beat so UI stays tidy
          setTimeout(() => this.enforceCap(), 4000);
        });
      }
      this.overflowEl.textContent = `+${hidden} more notification${hidden === 1 ? '' : 's'}`;
      // Place after visible toasts
      const lastVisible = banners[Math.min(this.maxVisible, banners.length) - 1];
      if (lastVisible && lastVisible.nextSibling !== this.overflowEl) {
        if (this.overflowEl.parentNode) this.overflowEl.remove();
        if (lastVisible.nextSibling) container.insertBefore(this.overflowEl, lastVisible.nextSibling);
        else container.appendChild(this.overflowEl);
      } else if (!this.overflowEl.parentNode) {
        container.appendChild(this.overflowEl);
      }
      requestAnimationFrame(() => this.overflowEl && this.overflowEl.classList.add('wa-toast-visible'));
    } else if (this.overflowEl) {
      this.overflowEl.remove();
      this.overflowEl = null;
    }
  }

  dismissToast(toast) {
    if (!toast || toast._isDismissing) return;
    toast._isDismissing = true;
    if (toast._dismissTimer) clearTimeout(toast._dismissTimer);
    const key = toast.dataset.chatKey;
    if (key && this.toastsByChat.has(key)) {
      const entry = this.toastsByChat.get(key);
      if (entry && entry.timer) clearTimeout(entry.timer);
      this.toastsByChat.delete(key);
    }
    toast.classList.remove('wa-toast-visible');
    toast.classList.add('wa-toast-hiding');
    setTimeout(() => {
      toast.remove();
      this.enforceCap();
    }, 280);
  }

  dismissAll() {
    const container = this.getContainer();
    if (!container) return;
    Array.from(container.querySelectorAll('.wa-toast-banner')).forEach((el) => this.dismissToast(el));
    if (this.overflowEl) { this.overflowEl.remove(); this.overflowEl = null; }
    this.toastsByChat.clear();
  }

  escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
}

// Attach singletons
if (typeof window !== 'undefined') {
  window.WhatsAppAudio = window.WhatsAppAudio || new WhatsAppAudio();
  window.WhatsAppNotifications = window.WhatsAppNotifications || new WhatsAppNotifications();
}

if (typeof module !== 'undefined' && module.exports) {
  module.exports = { WhatsAppAudio, WhatsAppNotifications };
}
