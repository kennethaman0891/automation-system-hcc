#!/usr/bin/env node
'use strict';
/**
 * Regression for the 2026-11-06 "linked number sent to JioMart Offers" incident.
 * Drives the REAL WhatsAppBridge.openChat / openChatByTitle / pollSelfChat against a fake
 * page (no Chrome, no WhatsApp) and records every keystroke. Background scraping must
 * never type into the page or press Enter.
 */
process.env.WA_TEST_MODE = process.env.WA_TEST_MODE || '1';
const fs = require('fs');
const path = require('path');
const BRIDGE = process.env.HCC_BRIDGE_UNDER_TEST || path.join(__dirname, '../../automation/whatsapp-bridge.js');
const WhatsAppBridge = require(BRIDGE);
const src = fs.readFileSync(BRIDGE, 'utf8');

const results = [];
function t(id, name, ok, detail = '') {
  results.push({ id, name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${id}  ${name}${detail ? ' — ' + detail : ''}`);
}

const SELF = '919999000123';   // synthetic linked number (never a real one)

function makeFakePage({ searchIsComposer = false, composerVisible = true } = {}) {
  const keys = [];
  const composer = { closest: (s) => (s === 'footer' || s === '#main') ? {} : null, focus() {}, click() {}, contains: () => false, offsetParent: {} };
  const search = { closest: () => null, focus() { fakeDoc.activeElement = search; }, click() {}, contains: (x) => x === search };
  const fakeDoc = {
    activeElement: composer,   // like the incident: the open chat's composer had focus
    querySelector(sel) {
      if (/pane-side|chat-list"/.test(sel)) return null;               // sidebar match fails
      if (/Search input textbox|chat-list-search|data-tab="3"/.test(sel)) return searchIsComposer ? composer : null;
      if (/footer|data-tab="10"|Type a message/.test(sel)) return composerVisible ? composer : null;
      return null;
    },
    querySelectorAll() { return []; },
    body: {},
  };
  const page = {
    keys,
    url: () => 'https://web.whatsapp.com/',
    evaluate: async (fn, ...args) => { global.document = fakeDoc; try { return fn(...args); } finally { delete global.document; } },
    waitForFunction: async () => { if (composerVisible) return true; throw new Error('timeout'); },
    $: async () => (composerVisible ? { click: async () => {} } : null),
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
  const b = new WhatsAppBridge({ apiBaseUrl: 'http://127.0.0.1:1', headless: true, enablePrivacyFilter: false, pollInterval: 600000 });
  b.page = page;
  b.isConnected = true;
  b.sending = false;
  b.phoneNumber = SELF;
  b.safeGoto = async () => {};          // deep link navigation is a no-op in this harness
  b.log = () => {};
  b.ingestOpenChat = async () => { b._ingested = (b._ingested || 0) + 1; };
  return b;
}

const typedOrEnter = (keys) => keys.filter(k => k.startsWith('type:') || k === 'press:Enter' || k.startsWith('char:'));

(async () => {
  // NT1: openChat without deep link (the self-chat path in the incident) must not type
  {
    const page = makeFakePage();
    const b = makeBridge(page);
    const ok = await b.openChat(SELF + '@c.us', false);
    t('NT1', 'openChat(self, no deep link) types nothing and does not press Enter', ok === false && typedOrEnter(page.keys).length === 0, JSON.stringify(page.keys));
  }
  // NT2: openChat with deep link that fails must not fall back to typing
  {
    const page = makeFakePage({ composerVisible: false });
    const b = makeBridge(page);
    const ok = await b.openChat('919999000001@c.us', true);
    t('NT2', 'failed deep link does not fall back to search typing', ok === false && typedOrEnter(page.keys).length === 0, JSON.stringify(page.keys));
  }
  // NT3: openChatByTitle refuses when the "search" element is actually a composer
  {
    const page = makeFakePage({ searchIsComposer: true });
    const b = makeBridge(page);
    const ok = await b.openChatByTitle('Message yourself');
    t('NT3', 'title search refuses to type into a composer', ok === false && typedOrEnter(page.keys).length === 0, JSON.stringify(page.keys));
  }
  // NT4: pollSelfChat never types the linked number
  {
    const page = makeFakePage({ searchIsComposer: true });
    const b = makeBridge(page);
    await b.pollSelfChat();
    const typedSelf = page.keys.some(k => k.includes(SELF) || k.includes(SELF.slice(-4)));
    t('NT4', 'pollSelfChat never types the linked number (or last 4)', !typedSelf && typedOrEnter(page.keys).length === 0, JSON.stringify(page.keys));
  }
  // NT5-NT7: static guarantees
  const openChatBody = src.slice(src.indexOf('  async openChat(waChatId'), src.indexOf('  async sendText(waChatId, text)'));
  t('NT5', 'openChat source contains no keyboard typing / Enter', openChatBody.length > 100 && !/keyboard\.(type|press|sendCharacter)/.test(openChatBody));
  const selfBody = src.slice(src.indexOf('  async pollSelfChat()'), src.indexOf('  async pollMessages()'));
  const selfCode = selfBody.split('\n').filter(l => !l.trim().startsWith('//')).join('\n');
  t('NT6', 'pollSelfChat does not open the self chat by phone number', !/openChat\(selfJid/.test(selfCode));
  const sendCalls = (src.match(/this\.sendText\(|this\.sendMedia\(/g) || []).length;
  const insideQueued = src.slice(src.indexOf('  async sendQueuedMessage(item)'), src.indexOf('  async sendQueuedMessage(item)') + 6000);
  const queuedCalls = (insideQueued.match(/this\.sendText\(|this\.sendMedia\(/g) || []).length;
  t('NT7', 'sendText/sendMedia only called from sendQueuedMessage (pressed queue rows)', sendCalls > 0 && sendCalls === queuedCalls, `total=${sendCalls} inQueued=${queuedCalls}`);

  const failed = results.filter(r => !r.ok).length;
  try { fs.writeFileSync(path.join(__dirname, 'results', 'no_silent_typing.json'), JSON.stringify({ suite: 'no_silent_typing', passed: results.length - failed, failed, results }, null, 2)); } catch (e) {}
  console.log(`\nNo silent typing: ${results.length - failed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
