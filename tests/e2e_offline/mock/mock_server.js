#!/usr/bin/env node
/**
 * Local mock WhatsApp Web + control API.
 * Serves HTML at / and control endpoints under /mock/*
 * Zero contact with web.whatsapp.com.
 */
'use strict';
const http = require('http');
const fs = require('fs');
const path = require('path');
const { URL } = require('url');

const PORT = parseInt(process.env.WA_MOCK_PORT || '9876', 10);
const HOST = process.env.WA_MOCK_HOST || '127.0.0.1';
const htmlPath = path.join(__dirname, 'index.html');

// Server-side mirror of browser mock state (for tests that don't drive Puppeteer evaluate)
const state = {
  loggedIn: false,
  failNextSend: false,
  failNextRevoke: false,
  chats: {},
  received: [],
  seq: 1,
  lastQrAt: Date.now()
};

function json(res, code, body) {
  const raw = JSON.stringify(body);
  res.writeHead(code, {
    'Content-Type': 'application/json',
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET,POST,OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type'
  });
  res.end(raw);
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on('data', c => chunks.push(c));
    req.on('end', () => {
      const raw = Buffer.concat(chunks).toString('utf8');
      if (!raw) return resolve({});
      try { resolve(JSON.parse(raw)); } catch (e) { reject(e); }
    });
    req.on('error', reject);
  });
}

const server = http.createServer(async (req, res) => {
  const u = new URL(req.url, `http://${HOST}:${PORT}`);
  if (req.method === 'OPTIONS') return json(res, 204, {});

  if (u.pathname === '/' || u.pathname === '/index.html') {
    const html = fs.readFileSync(htmlPath, 'utf8');
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
    return res.end(html);
  }

  if (u.pathname === '/mock/health') {
    return json(res, 200, { ok: true, port: PORT });
  }

  if (u.pathname === '/mock/state' && req.method === 'GET') {
    return json(res, 200, state);
  }

  if (u.pathname === '/mock/reset' && req.method === 'POST') {
    state.loggedIn = false;
    state.failNextSend = false;
    state.failNextRevoke = false;
    state.chats = {};
    state.received = [];
    state.seq = 1;
    state.lastQrAt = Date.now();
    return json(res, 200, { ok: true });
  }

  if (u.pathname === '/mock/login' && req.method === 'POST') {
    const body = await readBody(req);
    state.loggedIn = true;
    state.chats = {};
    (body.chats || []).forEach(c => {
      state.chats[c.wa_chat_id] = { ...c, messages: c.messages || [], unread: !!c.unread };
    });
    return json(res, 200, { ok: true, loggedIn: true });
  }

  if (u.pathname === '/mock/logout' && req.method === 'POST') {
    state.loggedIn = false;
    state.lastQrAt = Date.now();
    return json(res, 200, { ok: true });
  }

  if (u.pathname === '/mock/inject' && req.method === 'POST') {
    const msg = await readBody(req);
    const jid = msg.wa_chat_id;
    if (!state.chats[jid]) state.chats[jid] = { wa_chat_id: jid, name: msg.sender_name || jid, messages: [], unread: true };
    const m = {
      wa_msg_id: msg.wa_msg_id || (`false_${jid}_${Date.now()}`),
      wa_chat_id: jid,
      from_me: false,
      body: msg.body || '',
      status: 'delivered',
      timestamp: msg.timestamp || Math.floor(Date.now() / 1000),
      msg_type: msg.msg_type || 'text',
      chat_type: msg.chat_type || (String(jid).includes('@g.us') ? 'group' : 'individual'),
      sender_name: msg.sender_name || 'Fixture Sender'
    };
    state.chats[jid].messages.push(m);
    state.chats[jid].unread = true;
    return json(res, 200, { ok: true, message: m });
  }

  if (u.pathname === '/mock/ack' && req.method === 'POST') {
    const body = await readBody(req);
    Object.values(state.chats).forEach(ch => {
      (ch.messages || []).forEach(m => {
        if (m.wa_msg_id === body.wa_msg_id) m.status = body.status || 'delivered';
      });
    });
    return json(res, 200, { ok: true });
  }

  if (u.pathname === '/mock/fail-next-send' && req.method === 'POST') {
    state.failNextSend = true;
    return json(res, 200, { ok: true });
  }

  if (u.pathname === '/mock/fail-next-revoke' && req.method === 'POST') {
    state.failNextRevoke = true;
    return json(res, 200, { ok: true });
  }

  if (u.pathname === '/mock/record-send' && req.method === 'POST') {
    // Used by the mock page via fetch, or by a bridge shim
    const body = await readBody(req);
    if (state.failNextSend) {
      state.failNextSend = false;
      return json(res, 500, { ok: false, error: 'mock send failure' });
    }
    const jid = body.wa_chat_id;
    const id = body.wa_msg_id || (`true_${jid}_${Date.now()}_${state.seq++}`);
    const msg = { ...body, wa_msg_id: id, from_me: true, status: 'sent', timestamp: Math.floor(Date.now()/1000) };
    if (!state.chats[jid]) state.chats[jid] = { wa_chat_id: jid, name: jid, messages: [] };
    state.chats[jid].messages.push(msg);
    state.received.push(msg);
    return json(res, 200, { ok: true, wa_msg_id: id, message: msg });
  }

  if (u.pathname === '/mock/received' && req.method === 'GET') {
    return json(res, 200, { ok: true, received: state.received });
  }

  json(res, 404, { error: 'not found', path: u.pathname });
});

server.listen(PORT, HOST, () => {
  console.log(`MOCK_WA listening on http://${HOST}:${PORT}/`);
  fs.writeFileSync(path.join(__dirname, '..', 'results', 'mock_server.pid'), String(process.pid));
});

process.on('SIGTERM', () => server.close(() => process.exit(0)));
process.on('SIGINT', () => server.close(() => process.exit(0)));
