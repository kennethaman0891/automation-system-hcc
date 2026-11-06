/**
 * WhatsApp bridge safety guards (test mode + live hardening).
 * Pure functions — unit-tested without Puppeteer or network.
 */
'use strict';

const REAL_WA_HOSTS = ['web.whatsapp.com', 'whatsapp.com'];

function isTestMode(env = process.env) {
  const v = String(env.WA_TEST_MODE || '').trim().toLowerCase();
  return v === '1' || v === 'true' || v === 'yes' || v === 'on';
}

function getMockUrl(env = process.env) {
  return String(env.WA_MOCK_URL || '').trim() || 'http://127.0.0.1:9876/';
}

/** Parse comma/space-separated fixture JIDs from env. */
function getAllowedChats(env = process.env) {
  const raw = String(env.WA_ALLOWED_CHATS || '').trim();
  if (!raw) return new Set();
  return new Set(
    raw.split(/[\s,]+/).map(s => s.trim().toLowerCase()).filter(Boolean)
  );
}

function hostnameOf(url) {
  try {
    return new URL(String(url)).hostname.toLowerCase();
  } catch (e) {
    return '';
  }
}

function isRealWhatsAppUrl(url) {
  const host = hostnameOf(url);
  if (!host) return false;
  return REAL_WA_HOSTS.some(h => host === h || host.endsWith('.' + h));
}

/**
 * Decide whether navigation is allowed.
 * In test mode: only mock URL host (and localhost/127.0.0.1 for that mock).
 * Outside test mode: normal (real WA allowed).
 */
function assertNavigationAllowed(url, env = process.env) {
  const target = String(url || '');
  if (!isTestMode(env)) {
    return { ok: true, url: target };
  }
  if (isRealWhatsAppUrl(target)) {
    return {
      ok: false,
      error: `WA_TEST_MODE blocks navigation to real WhatsApp: ${target}`,
      url: target
    };
  }
  const mock = getMockUrl(env);
  const mockHost = hostnameOf(mock);
  const targetHost = hostnameOf(target);
  // Allow relative / about:blank during boot
  if (!targetHost || target === 'about:blank') {
    return { ok: true, url: target || mock };
  }
  if (targetHost !== mockHost && targetHost !== '127.0.0.1' && targetHost !== 'localhost') {
    return {
      ok: false,
      error: `WA_TEST_MODE blocks navigation outside mock host (${mockHost}): ${target}`,
      url: target
    };
  }
  return { ok: true, url: target };
}

/** Resolve the page the bridge should open instead of web.whatsapp.com. */
function resolveWhatsAppEntryUrl(env = process.env) {
  if (isTestMode(env)) return getMockUrl(env);
  return 'https://web.whatsapp.com';
}

/**
 * In test mode, refuse chats not on the fixture allowlist.
 * Empty allowlist in test mode = refuse everything (fail closed).
 */
function assertChatAllowed(waChatId, env = process.env) {
  const jid = String(waChatId || '').trim().toLowerCase();
  if (!isTestMode(env)) return { ok: true, waChatId: jid };
  if (!jid) return { ok: false, error: 'WA_TEST_MODE: empty chat id refused' };
  const allowed = getAllowedChats(env);
  if (allowed.size === 0) {
    return { ok: false, error: 'WA_TEST_MODE: WA_ALLOWED_CHATS is empty — refuse all sends' };
  }
  if (!allowed.has(jid)) {
    return { ok: false, error: `WA_TEST_MODE: chat not in fixture allowlist: ${jid}` };
  }
  return { ok: true, waChatId: jid };
}

/** Fixture JIDs used by the offline e2e suite. */
const FIXTURE_CHATS = {
  personal: '919999000001@c.us',
  group: '120363999000002@g.us',
  lid: '123456789012345@lid',
  self: '919999000099@c.us'
};

function defaultAllowedChatsCsv() {
  return Object.values(FIXTURE_CHATS).join(',');
}

module.exports = {
  isTestMode,
  getMockUrl,
  getAllowedChats,
  isRealWhatsAppUrl,
  assertNavigationAllowed,
  resolveWhatsAppEntryUrl,
  assertChatAllowed,
  FIXTURE_CHATS,
  defaultAllowedChatsCsv,
  REAL_WA_HOSTS
};
