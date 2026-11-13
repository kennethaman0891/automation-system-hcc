#!/usr/bin/env node
'use strict';
/**
 * Incoming capture from the chat list (2026-11-06). No Chrome, no WhatsApp.
 * Proves: unread / changed / new rows are picked; a chat that cannot be opened keeps its
 * list preview; nothing types a phone number, presses Enter, sends, or queues a reply.
 */
process.env.WA_TEST_MODE = process.env.WA_TEST_MODE || '1';
const fs = require('fs');
const path = require('path');
const BRIDGE = process.env.HCC_BRIDGE_UNDER_TEST || path.join(__dirname, '../../automation/whatsapp-bridge.js');
const RULES = path.join(__dirname, '../../automation/wa-list-rules.js');
const WhatsAppBridge = require(BRIDGE);
const R = require(RULES);
const src = fs.readFileSync(BRIDGE, 'utf8');

const results = [];
function t(id, name, ok, detail = '') {
  results.push({ id, name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${id}  ${name}${detail ? ' — ' + detail : ''}`);
}
const SELF = '919999000123';
const PARENT = '919999000001';

const row = (o) => ({ index: 0, title: 'Parent A', preview: 'hello', time: '12:40', rowText: 'Parent A 12:40 hello', unread: false, unreadCount: 0, phone: '', wa_chat_id: '', outgoing: false, groupHint: false, ...o });

// ---- pure planning rules -------------------------------------------------------
{
  const p = R.planListActions([row({ unread: true, unreadCount: 2 })], { primed: true }, { limit: 3 });
  t('IL1', 'unread row is opened', p.toOpen.length === 1 && p.toOpen[0].reason === 'unread');
}
{
  const r0 = row({});
  const prev = new Map([['parent a', R.rowSignature(r0)]]);
  const r1 = row({ time: '12:47', rowText: 'Parent A 12:47 hello' });   // same text, new time
  const p = R.planListActions([r1], { primed: true, prevSigs: prev }, { limit: 3 });
  t('IL2', 'same text at a new time counts as a new message (no badge needed)', p.toOpen.length === 1 && p.toOpen[0].reason === 'changed');
  const same = R.planListActions([r0], { primed: true, prevSigs: prev }, { limit: 3 });
  t('IL3', 'unchanged row is left alone', same.toOpen.length === 0 && same.deferred.length === 0);
}
{
  const prev = new Map([['parent a', R.rowSignature(row({}))]]);
  const typing = row({ preview: 'typing…', rowText: 'Parent A 12:40 typing…' });
  const p = R.planListActions([typing], { primed: true, prevSigs: prev }, { limit: 3 });
  t('IL4', '"typing…" is not treated as a message', p.toOpen.length === 0 && p.sigs.get('parent a') === null);
}
{
  const fresh = row({ title: 'New Parent', time: '12:49', rowText: 'New Parent 12:49 admission?' });
  const old = row({ title: 'Old Chat', time: '03/11/2026', rowText: 'Old Chat 03/11/2026 bye' });
  const p = R.planListActions([fresh, old], { primed: true, prevSigs: new Map() }, { limit: 3 });
  t('IL5', 'a never-seen chat with today\'s time is opened; an old one is not', p.toOpen.length === 1 && p.toOpen[0].row.title === 'New Parent' && p.toOpen[0].reason === 'new-today');
}
{
  const now = Date.now();
  const p = R.planListActions([row({ unread: true })], { primed: true, failUntil: new Map([['parent a', now + 60000]]) }, { limit: 3, now });
  t('IL6', 'a row that failed to open backs off (deferred, not retried every 3s)', p.toOpen.length === 0 && p.deferred.length === 1 && p.deferred[0].backoff === true);
  t('IL6b', 'backoff grows and caps at 10 minutes', R.nextBackoffMs(1) === 30000 && R.nextBackoffMs(2) === 60000 && R.nextBackoffMs(20) === 600000);
}
{
  const rows = [
    row({ index: 0, title: 'B1', rowText: 'B1' }),
    row({ index: 1, title: 'U1', unread: true, rowText: 'U1' }),
    row({ index: 2, title: 'U2', unread: true, rowText: 'U2' }),
    row({ index: 3, title: 'U3', unread: true, rowText: 'U3' }),
    row({ index: 4, title: 'U1', unread: false, rowText: 'U1' })   // nested duplicate
  ];
  const p = R.planListActions(rows, { primed: true, backfill: ['b1'], prevSigs: new Map([['b1', 'B1|0|0']]) }, { limit: 3 });
  t('IL7', 'unread first, limit respected, duplicates merged', p.toOpen.length === 3 && p.toOpen.every(i => i.reason === 'unread') && p.deferred.length === 1 && p.deferred[0].reason === 'backfill');
}
{
  const a = R.previewMsgId(PARENT + '@c.us', row({}));
  const b = R.previewMsgId(PARENT + '@c.us', row({}));
  const c = R.previewMsgId(PARENT + '@c.us', row({ time: '12:41' }));
  t('IL8', 'preview id is stable for the same row and changes with time', a === b && a !== c && a.startsWith('preview:'));
}
{
  const now = new Date(2026, 10, 11, 11, 4, 44).getTime();
  const at = (y, m, d, h, min, s) => Math.floor(new Date(y, m, d, h, min, s || 0).getTime() / 1000);
  const thu = R.parseWaTime('Thursday', now);
  t('IL22', 'a weekday label is that day at noon, not the scrape second',
    thu.parsed === true && thu.precision === 'day' && thu.hasDate === true && thu.unix === at(2026, 10, 5, 12, 0));
  const yest = R.parseWaTime('Yesterday', now);
  t('IL23', 'Yesterday is the previous calendar day', yest.parsed === true && yest.unix === at(2026, 10, 10, 12, 0));
  const monthEnd = R.parseWaTime('Yesterday', new Date(2026, 11, 1, 9, 0, 0).getTime());
  t('IL24', 'Yesterday still resolves on the first of a month', monthEnd.parsed === true && monthEnd.unix === at(2026, 10, 30, 12, 0));
  const clock = R.parseWaTime('9:54 am', now);
  t('IL25', 'a sidebar clock is today at that minute', clock.parsed === true && clock.precision === 'minute' && clock.unix === at(2026, 10, 11, 9, 54));
  const midnight = R.parseWaTime('12:19 am', now);
  t('IL26', '12 am is midnight, not noon', midnight.parsed === true && midnight.unix === at(2026, 10, 11, 0, 19));
  const bubble = R.parseWaTime('[10:38:16 am, 22/10/2026] Parent: ', now);
  t('IL27', 'a bubble clock keeps its date and seconds', bubble.parsed === true && bubble.precision === 'second' && bubble.unix === at(2026, 9, 22, 10, 38, 16));
  const marks = R.parseWaTime('[\u200E10:38 am, 22\u200E/\u200E10\u200E/\u200E2026] Name: ', now);
  t('IL28', 'invisible marks in the bubble clock still parse', marks.parsed === true && marks.unix === at(2026, 9, 22, 10, 38));
  const dotted = R.parseWaTime('10.38 am, 22.10.2026', now);
  t('IL29', 'dot time and dot date still parse', dotted.parsed === true && dotted.unix === at(2026, 9, 22, 10, 38));
  const swapped = R.parseWaTime('10:38, 9/15/2026', now);
  t('IL30', 'a month past 12 is read as day/month', swapped.parsed === true && swapped.unix === at(2026, 8, 15, 10, 38));
}
{
  const now = Date.now();
  const quiet = new Map([['parent a', now + 60000]]);
  const seen = row({ unread: true, unreadCount: 1 });
  const stuck = R.planListActions(
    [seen],
    { primed: true, prevSigs: new Map([['parent a', R.rowSignature(seen)]]), quietUnread: quiet },
    { limit: 3, now }
  );
  t('IL20', 'a chat already read is not opened again just because its badge is still there', stuck.toOpen.length === 0);
  const prev = new Map([['parent a', R.rowSignature(row({}))]]);
  const fresh = row({ unread: true, unreadCount: 1, time: '12:55', rowText: 'Parent A 12:55 new text', preview: 'new text' });
  const again = R.planListActions([fresh], { primed: true, prevSigs: prev, quietUnread: quiet }, { limit: 3, now });
  t('IL21', 'a new line still opens while the old badge is quiet', again.toOpen.length === 1 && again.toOpen[0].reason === 'changed');
}

// ---- bridge-level with a fake page ------------------------------------------------
function makeFakePage(listRows) {
  const keys = [];
  const composer = { closest: (s) => (s === 'footer' || s === '#main') ? {} : null, focus() {}, click() {}, contains: () => false, offsetParent: {} };
  const fakeDoc = {
    activeElement: composer,
    querySelector(sel) {
      if (/Search input textbox|chat-list-search|data-tab="3"/.test(sel)) return composer; // search box missing: composer only
      return null;
    },
    querySelectorAll() { return []; },
    body: {},
  };
  const page = {
    keys,
    url: () => 'https://web.whatsapp.com/',
    evaluate: async (fn, ...args) => {
      global.document = fakeDoc;
      global.window = { WABridge: { getChatListRows: () => listRows, probeChatList: () => ({ root: true }), getMessages: () => [], getContactInfo: () => null } };
      try { return fn(...args); } finally { delete global.document; delete global.window; }
    },
    waitForFunction: async () => { throw new Error('timeout'); },
    $: async () => null,
    mouse: { click: async () => keys.push('mouse.click') },
    keyboard: {
      type: async (s) => keys.push('type:' + s),
      press: async (k) => keys.push('press:' + k),
      down: async (k) => keys.push('down:' + k),
      up: async (k) => keys.push('up:' + k),
      sendCharacter: async (c) => keys.push('char:' + c),
    },
  };
  return page;
}
function makeBridge(page) {
  const b = new WhatsAppBridge({ apiBaseUrl: 'http://127.0.0.1:1', headless: true, enablePrivacyFilter: false, pollInterval: 600000, bridgeSecret: 'x'.repeat(32) });
  b.page = page;
  b.isConnected = true;
  b.sending = false;
  b.phoneNumber = SELF;
  b.log = () => {};
  b.posts = [];
  b.apiPost = async (endpoint, data) => { b.posts.push({ endpoint, data }); return { success: true, inserted: Array.isArray(data) ? data.length : 0 }; };
  b.sendText = async () => { b.sent = true; throw new Error('send must not be called'); };
  b.sendMedia = async () => { b.sent = true; throw new Error('send must not be called'); };
  b.safeGoto = async () => {};
  return b;
}
const typedOrEnter = (keys) => keys.filter(k => k.startsWith('type:') || k === 'press:Enter' || k.startsWith('char:'));

(async () => {
  {
    const b = makeBridge(makeFakePage([]));
    const hit = b.bubbleTime({ time_raw: '[10:38 am, 22/10/2026] Parent: ', clock_raw: '10:38' });
    const expectBubble = Math.floor(new Date(2026, 9, 22, 10, 38, 0).getTime() / 1000);
    t('IL31', 'an opened bubble keeps the date in data-pre-plain-text', hit.parsed === true && hit.unix === expectBubble);
    const photo = b.bubbleTime({ clock_raw: '9:54 am' });
    const photoDate = new Date(photo.unix * 1000);
    const today = new Date();
    t('IL32', 'a photo that only shows a clock uses today at that minute',
      photo.parsed === true && photo.precision === 'minute' && photoDate.getHours() === 9 && photoDate.getMinutes() === 54 && photoDate.getDate() === today.getDate());
  }
  // IL9: unread row with a phone that cannot be opened -> list preview stored, nothing typed
  {
    const rows = [row({ title: '+91 99990 00001', phone: PARENT, unread: true, unreadCount: 1, preview: 'Is admission open?', rowText: '+91 99990 00001 12:50 Is admission open? 1' })];
    const page = makeFakePage(rows);
    const b = makeBridge(page);
    await b.ingestVisibleChats(3);
    const ingest = b.posts.filter(p => p.endpoint === 'api_whatsapp_ingest.php');
    const msg = ingest.length ? ingest[ingest.length - 1].data[0] : null;
    t('IL9', 'unopenable unread chat: list preview is stored as incoming',
      !!msg && msg.from_me === false && msg.body === 'Is admission open?' && msg.wa_chat_id === PARENT + '@c.us' && msg.metadata && msg.metadata.preview_only === true && msg.timestamp_parsed === true && msg.metadata.time_precision === 'minute',
      JSON.stringify(msg));
    t('IL10', 'opening never typed digits or pressed Enter in a composer', typedOrEnter(page.keys).length === 0, JSON.stringify(page.keys));
    const queuePosts = b.posts.filter(p => /outbound|send/i.test(p.endpoint));
    t('IL11', 'incoming capture posts no send / outbound queue request', !b.sent && queuePosts.length === 0, JSON.stringify(b.posts.map(p => p.endpoint)));
    // Second cycle immediately: backoff, same preview id -> not re-posted
    const before = b.posts.length;
    await b.ingestVisibleChats(3);
    const again = b.posts.slice(before).filter(p => p.endpoint === 'api_whatsapp_ingest.php');
    t('IL12', 'same preview is not stored twice and the row is not hammered', again.length === 0, String(again.length));
  }
  // IL13: our own last message (ticks / "You:") is not stored as incoming
  {
    const rows = [row({ phone: PARENT, unread: true, preview: 'Thanks, see you', outgoing: true })];
    const page = makeFakePage(rows);
    const b = makeBridge(page);
    await b.ingestVisibleChats(3);
    const ingest = b.posts.filter(p => p.endpoint === 'api_whatsapp_ingest.php');
    t('IL13', 'outgoing preview is never stored as an incoming message', ingest.length === 0);
  }
  // IL14: no phone / no @lid / unknown name -> not stored with a made-up id
  {
    const rows = [row({ title: 'Some Group', unread: true, preview: 'Ravi: hi' })];
    const page = makeFakePage(rows);
    const b = makeBridge(page);
    await b.ingestVisibleChats(3);
    const ingest = b.posts.filter(p => p.endpoint === 'api_whatsapp_ingest.php');
    const msg = ingest.length ? ingest[0].data[0] : null;
    t('IL14', 'a name-only chat is stored under a title id, not a made-up phone',
      !!msg && msg.wa_chat_id === 'title:some-group@lid' && msg.from_me === false && !/@c\.us$/.test(msg.wa_chat_id),
      JSON.stringify(msg && msg.wa_chat_id));
    t('IL15', 'no typing for that chat either', typedOrEnter(page.keys).length === 0, JSON.stringify(page.keys));
  }
  {
    const rows = [row({ title: 'Parents Group', unread: true, preview: 'Ravi: hi', groupHint: true })];
    const page = makeFakePage(rows);
    const b = makeBridge(page);
    await b.ingestVisibleChats(3);
    const ingest = b.posts.filter(p => p.endpoint === 'api_whatsapp_ingest.php');
    t('IL14b', 'a group row is not stored as an incoming chat', ingest.length === 0);
  }
  // IL16: a row that opens is read from the open thread, not from the preview
  {
    const rows = [row({ phone: PARENT, unread: true, preview: 'hi' })];
    const page = makeFakePage(rows);
    const b = makeBridge(page);
    let opened = 0;
    b.openUnreadRow = async () => { opened++; return true; };
    let read = 0;
    b.ingestOpenChat = async () => { read++; return { waChatId: PARENT + '@c.us', incoming: 1 }; };
    await b.ingestVisibleChats(3);
    const previews = b.posts.filter(p => p.endpoint === 'api_whatsapp_ingest.php');
    t('IL16', 'openable unread row is clicked and its thread read (no preview stand-in)', opened === 1 && read >= 2 && previews.length === 0, `opened=${opened} read=${read}`);
  }
  // Static guarantees
  const body = src.slice(src.indexOf('  async ingestVisibleChats('), src.indexOf("  async ingestOpenChat(knownWaChatId = '')"));
  t('IL17', 'list ingest code has no keyboard typing, Enter, or send calls',
    body.length > 500 && !/keyboard\.(type|press|sendCharacter)/.test(body) && !/sendText\(|sendMedia\(|outbound_queue|api_whatsapp_send/.test(body));
  t('IL18', 'polling ticks cannot overlap (one page driver at a time)', /if \(this\.pollTickRunning\) return;/.test(src));
  t('IL19', 'pane is scrolled to the top before reading the list', /pane\.scrollTop = 0/.test(body));

  const failed = results.filter(r => !r.ok).length;
  try { fs.writeFileSync(path.join(__dirname, 'results', 'incoming_list.json'), JSON.stringify({ suite: 'incoming_list', passed: results.length - failed, failed, results }, null, 2)); } catch (e) {}
  console.log(`\nIncoming list: ${results.length - failed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
