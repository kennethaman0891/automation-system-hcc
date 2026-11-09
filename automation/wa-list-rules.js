/**
 * Phone and chat-list rules shared by the WhatsApp bridge and the node tests.
 * A @lid id is not a phone. System chips (Archived, Unread, …) are not chats.
 */

function normalizePhoneDigits(digits) {
  let d = String(digits || '').replace(/\D/g, '');
  if (d.startsWith('00')) d = d.slice(2);
  d = d.replace(/^0+/, '');
  if (d.length === 10 && /^[6-9]/.test(d)) d = '91' + d;
  if (d.length < 8 || d.length > 15 || d[0] === '0') return '';
  if (/^(\d)\1+$/.test(d)) return '';
  if (d.startsWith('91') && d.length === 12 && !/^91[6-9]\d{9}$/.test(d)) return '';
  return d;
}

function phoneDigitsFromJid(jid) {
  const raw = String(jid || '').trim().toLowerCase();
  if (!raw) return '';
  if (raw.endsWith('@lid')) return '';
  if (raw.includes('@g.us') || raw.includes('broadcast') || raw.includes('newsletter')) return '';
  return normalizePhoneDigits(raw.split('@')[0]);
}

function isSystemChatTitle(title) {
  return /^(archived|unread|all|favourites|favorites|groups|communities|locked chats|status|updates)$/i
    .test(String(title || '').trim());
}

// ---- Chat-list change detection (2026-11-06 incoming fix) --------------------
// The bridge decides which chats to open from what the sidebar shows. These are pure
// so they can be tested without Chrome. Nothing here types, clicks or sends.

const crypto = require('crypto');

// "typing…" / "recording audio…" temporarily replace the preview. Never compare on it.
function isTransientPreview(text) {
  return /\b(typing|recording audio|recording video)\b/i.test(String(text || ''));
}

function isClockTime(value) {
  return /^\d{1,2}:\d{2}(\s*[ap]\.?\s?m\.?)?$/i.test(String(value || '').trim());
}

function titleKey(title) {
  return String(title || '').replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, '').trim().toLowerCase();
}

function titleSlug(title) {
  return titleKey(title).replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 48);
}

// Signature of everything the row shows (title, time, preview, badge). It changes when
// a new message arrives even if the text repeats, because the time changes. null = skip.
function rowSignature(row) {
  if (!row) return null;
  const text = String(row.rowText || [row.time, row.preview].filter(Boolean).join(' ') || '')
    .replace(/[\u200E\u200F\u202A-\u202E\uFEFF]/g, '')
    .replace(/\s+/g, ' ').trim();
  if (!text || isTransientPreview(text) || isTransientPreview(row.preview)) return null;
  return [text.slice(0, 400), Number(row.unreadCount) || 0, row.unread ? 1 : 0].join('|');
}

// Stable id for a message we could only read from the chat list preview.
function previewMsgId(waChatId, row) {
  const basis = [String(waChatId || '').toLowerCase(), String((row && row.preview) || '').trim(), String((row && row.time) || '').trim()].join('|');
  return 'preview:' + crypto.createHash('sha1').update(basis).digest('hex').slice(0, 24);
}

/**
 * Decide which sidebar rows to open this cycle.
 * rows: [{ index, title, preview, time, rowText, unread, unreadCount }]
 * state: { prevSigs: Map, primed: bool, backfill: string[] (title keys), failUntil: Map }
 * Returns { toOpen: [{ row, reason, key, sig }], deferred: [...] , sigs: Map(key -> sig) }
 * Reasons: unread > changed > new-today > backfill. Rows in failure backoff are deferred.
 */
function planListActions(rows, state = {}, opts = {}) {
  const now = Number(opts.now) || Date.now();
  const limit = Math.max(0, Number(opts.limit) || 3);
  const prevSigs = state.prevSigs || new Map();
  const failUntil = state.failUntil || new Map();
  const primed = !!state.primed;
  const backfill = Array.isArray(state.backfill) ? state.backfill : [];

  const byKey = new Map();
  for (const row of rows || []) {
    if (!row || !row.title || isSystemChatTitle(row.title)) continue;
    const key = titleKey(row.title);
    const prev = byKey.get(key);
    if (!prev) { byKey.set(key, { ...row }); continue; }
    // Nested duplicate of the same chat: keep the first index, merge unread.
    prev.unread = prev.unread || !!row.unread;
    prev.unreadCount = Math.max(Number(prev.unreadCount) || 0, Number(row.unreadCount) || 0);
  }

  const buckets = { unread: [], changed: [], 'new-today': [], backfill: [] };
  const sigs = new Map();
  for (const [key, row] of byKey) {
    const sig = rowSignature(row);
    sigs.set(key, sig);
    const prev = prevSigs.get(key);
    let reason = '';
    if (row.unread || (Number(row.unreadCount) || 0) > 0) reason = 'unread';
    else if (primed && sig !== null && prev !== undefined && prev !== null && prev !== sig) reason = 'changed';
    else if (primed && sig !== null && prev === undefined && isClockTime(row.time)) reason = 'new-today';
    else if (backfill.includes(key)) reason = 'backfill';
    if (reason) buckets[reason].push({ row, reason, key, sig });
  }
  // Backfill keeps list order (top of the list first).
  buckets.backfill.sort((a, b) => backfill.indexOf(a.key) - backfill.indexOf(b.key));

  const ordered = [...buckets.unread, ...buckets.changed, ...buckets['new-today'], ...buckets.backfill];
  const toOpen = [];
  const deferred = [];
  for (const item of ordered) {
    const until = failUntil.get(item.key) || 0;
    if (until > now) { deferred.push({ ...item, backoff: true }); continue; }
    if (toOpen.length < limit) toOpen.push(item);
    else deferred.push(item);
  }
  return { toOpen, deferred, sigs };
}

// Exponential backoff for rows that could not be opened: 30s, 60s, 120s … max 10 min.
function nextBackoffMs(failCount) {
  const n = Math.max(1, Number(failCount) || 1);
  return Math.min(10 * 60 * 1000, 30000 * Math.pow(2, n - 1));
}

module.exports = {
  normalizePhoneDigits,
  phoneDigitsFromJid,
  isSystemChatTitle,
  isTransientPreview,
  isClockTime,
  titleKey,
  titleSlug,
  rowSignature,
  previewMsgId,
  planListActions,
  nextBackoffMs
};
