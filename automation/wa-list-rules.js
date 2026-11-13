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
 * state: { prevSigs: Map, primed: bool, backfill: string[] (title keys), failUntil: Map,
 *          quietUnread: Map(title key -> unix ms until which a stale badge is ignored) }
 * Returns { toOpen: [{ row, reason, key, sig }], deferred: [...] , sigs: Map(key -> sig) }
 * Reasons: unread > changed > new-today > backfill. Rows in failure backoff are deferred.
 * A badge we already opened and found nothing new in does not keep winning the slot.
 */
function planListActions(rows, state = {}, opts = {}) {
  const now = Number(opts.now) || Date.now();
  const limit = Math.max(0, Number(opts.limit) || 3);
  const prevSigs = state.prevSigs || new Map();
  const failUntil = state.failUntil || new Map();
  const quietUnread = state.quietUnread || new Map();
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
    const unreadQuiet = (quietUnread.get(key) || 0) > now;
    let reason = '';
    if (!unreadQuiet && (row.unread || (Number(row.unreadCount) || 0) > 0)) reason = 'unread';
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

// Sidebar and bubble clocks. Weekday names are the previous time WhatsApp showed
// that name (today itself is a clock, so "Wednesday" on a Wednesday is last week).
// A day label has no clock, so it is stored at noon and marked precision "day".
// nowMs is the machine clock; the returned unix is in that same local timezone.
function parseWaTime(text, nowMs) {
  const now = new Date(Number(nowMs) || Date.now());
  const fallback = { unix: Math.floor(now.getTime() / 1000), parsed: false, precision: '', hasDate: false };
  const raw = String(text || '')
    .replace(/[\u200E\u200F\u202A-\u202E\uFEFF\u2066-\u2069]/g, '')
    .replace(/[\u00A0\u202F]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  if (!raw) return fallback;

  const applyAmPm = (hour, ampm) => {
    const ap = String(ampm || '').toLowerCase();
    if (ap.startsWith('p') && hour < 12) hour += 12;
    if (ap.startsWith('a') && hour === 12) hour = 0;
    return hour;
  };

  const finish = (year, month, day, hour, minute, second, precision, hasDate) => {
    year = Number(year);
    month = Number(month);
    day = Number(day);
    hour = Number(hour);
    minute = Number(minute);
    second = Number(second) || 0;
    if (year < 100) year += 2000;
    if (month > 12 && day <= 12) {
      const swap = day;
      day = month;
      month = swap;
    }
    if (month < 1 || month > 12 || day < 1 || day > 31) return null;
    if (hour < 0 || hour > 23 || minute < 0 || minute > 59 || second < 0 || second > 59) return null;
    const parsed = new Date(year, month - 1, day, hour, minute, second);
    if (Number.isNaN(parsed.getTime())) return null;
    if (parsed.getFullYear() !== year || parsed.getMonth() !== month - 1 || parsed.getDate() !== day) return null;
    const unix = Math.floor(parsed.getTime() / 1000);
    if (unix < 1577836800 || unix > Math.floor(now.getTime() / 1000) + 86400) return null;
    return { unix, parsed: true, precision, hasDate: !!hasDate };
  };

  const timeDate = raw.match(/(\d{1,2})[:.](\d{2})(?:[:.](\d{2}))?\s*([ap]\.?m\.?)?\s*[,،]?\s*(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/i);
  if (timeDate) {
    const hour = applyAmPm(parseInt(timeDate[1], 10), timeDate[4]);
    const second = timeDate[3] ? parseInt(timeDate[3], 10) : 0;
    const hit = finish(
      parseInt(timeDate[7], 10),
      parseInt(timeDate[6], 10),
      parseInt(timeDate[5], 10),
      hour,
      parseInt(timeDate[2], 10),
      second,
      second ? 'second' : 'minute',
      true
    );
    if (hit) return hit;
  }

  const dateTime = raw.match(/(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})\s*[,،]?\s*(\d{1,2})[:.](\d{2})(?:[:.](\d{2}))?\s*([ap]\.?m\.?)?/i);
  if (dateTime) {
    const hour = applyAmPm(parseInt(dateTime[4], 10), dateTime[7]);
    const second = dateTime[6] ? parseInt(dateTime[6], 10) : 0;
    const hit = finish(
      parseInt(dateTime[3], 10),
      parseInt(dateTime[2], 10),
      parseInt(dateTime[1], 10),
      hour,
      parseInt(dateTime[5], 10),
      second,
      second ? 'second' : 'minute',
      true
    );
    if (hit) return hit;
  }

  const clockOnly = raw.match(/^(\d{1,2})[:.](\d{2})(?:[:.](\d{2}))?(?:\s*([ap])\.?\s?m\.?)?$/i);
  if (clockOnly) {
    const hour = applyAmPm(parseInt(clockOnly[1], 10), clockOnly[4]);
    const second = clockOnly[3] ? parseInt(clockOnly[3], 10) : 0;
    const hit = finish(
      now.getFullYear(),
      now.getMonth() + 1,
      now.getDate(),
      hour,
      parseInt(clockOnly[2], 10),
      second,
      second ? 'second' : 'minute',
      false
    );
    if (hit) return hit;
  }

  const dayWord = raw.toLowerCase();
  if (dayWord === 'yesterday' || dayWord === 'today') {
    const shift = dayWord === 'yesterday' ? 1 : 0;
    const date = new Date(now.getFullYear(), now.getMonth(), now.getDate() - shift, 12, 0, 0);
    const hit = finish(date.getFullYear(), date.getMonth() + 1, date.getDate(), 12, 0, 0, 'day', true);
    if (hit) return hit;
  }

  const week = {
    sun: 0, sunday: 0,
    mon: 1, monday: 1,
    tue: 2, tues: 2, tuesday: 2,
    wed: 3, wednesday: 3,
    thu: 4, thur: 4, thurs: 4, thursday: 4,
    fri: 5, friday: 5,
    sat: 6, saturday: 6
  };
  if (Object.prototype.hasOwnProperty.call(week, dayWord)) {
    const date = new Date(now.getFullYear(), now.getMonth(), now.getDate(), 12, 0, 0);
    let delta = date.getDay() - week[dayWord];
    if (delta <= 0) delta += 7;
    date.setDate(date.getDate() - delta);
    const hit = finish(date.getFullYear(), date.getMonth() + 1, date.getDate(), 12, 0, 0, 'day', true);
    if (hit) return hit;
  }

  const dated = raw.match(/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})$/);
  if (dated) {
    const hit = finish(parseInt(dated[3], 10), parseInt(dated[2], 10), parseInt(dated[1], 10), 12, 0, 0, 'day', true);
    if (hit) return hit;
  }

  return fallback;
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
  nextBackoffMs,
  parseWaTime
};
