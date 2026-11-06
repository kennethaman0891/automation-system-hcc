/**
 * Rules for WhatsApp "Delete for everyone" (revoke).
 * Shared by the bridge and unit tests. No DOM / Puppeteer here.
 */

/** Default WhatsApp Web window for delete-for-everyone (~2 days). */
const DEFAULT_REVOKE_WINDOW_SEC = 48 * 60 * 60;

function revokeWindowSec(env = process.env) {
  const raw = env && env.WHATSAPP_REVOKE_WINDOW_SEC;
  const n = raw != null && String(raw).trim() !== '' ? parseInt(String(raw), 10) : NaN;
  if (Number.isFinite(n) && n > 0) return n;
  return DEFAULT_REVOKE_WINDOW_SEC;
}

/**
 * @param {number|string|null|undefined} messageTimestampSec Unix seconds when the message was sent
 * @param {number} [nowSec]
 * @param {number} [windowSec]
 * @returns {{ ok: boolean, reason?: string, ageSec?: number, windowSec: number }}
 */
function canRevokeForEveryone(messageTimestampSec, nowSec = Math.floor(Date.now() / 1000), windowSec = revokeWindowSec()) {
  const ts = Number(messageTimestampSec);
  if (!Number.isFinite(ts) || ts <= 0) {
    return { ok: false, reason: 'Message timestamp is missing or invalid', windowSec };
  }
  // Guard against ms accidentally stored as seconds
  const sec = ts > 1e12 ? Math.floor(ts / 1000) : Math.floor(ts);
  const ageSec = Math.max(0, Math.floor(nowSec) - sec);
  if (ageSec > windowSec) {
    return {
      ok: false,
      reason: `Message is older than the delete-for-everyone window (${windowSec}s)`,
      ageSec,
      windowSec
    };
  }
  return { ok: true, ageSec, windowSec };
}

/**
 * Parse outbound queue body for a revoke job.
 * @param {string|object|null|undefined} body
 * @returns {{ wa_msg_id: string, msg_db_id: number, timestamp: number } | null}
 */
function parseRevokeQueueBody(body) {
  let obj = body;
  if (typeof body === 'string') {
    try { obj = JSON.parse(body); } catch (e) { return null; }
  }
  if (!obj || typeof obj !== 'object') return null;
  const waMsgId = String(obj.wa_msg_id || '').trim();
  const msgDbId = parseInt(String(obj.msg_db_id || '0'), 10) || 0;
  const timestamp = Number(obj.timestamp) || 0;
  if (!waMsgId || waMsgId.startsWith('temp_')) return null;
  return { wa_msg_id: waMsgId, msg_db_id: msgDbId, timestamp };
}

module.exports = {
  DEFAULT_REVOKE_WINDOW_SEC,
  revokeWindowSec,
  canRevokeForEveryone,
  parseRevokeQueueBody
};
