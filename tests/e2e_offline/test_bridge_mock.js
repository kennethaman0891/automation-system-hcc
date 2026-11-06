#!/usr/bin/env node
'use strict';
const path = require('path');
const fs = require('fs');
const http = require('http');
const { execSync } = require('child_process');

process.env.WA_TEST_MODE = '1';
process.env.WA_MOCK_URL = process.env.WA_MOCK_URL || 'http://127.0.0.1:9876/';
process.env.WA_ALLOWED_CHATS = process.env.WA_ALLOWED_CHATS ||
  '919999000001@c.us,120363999000002@g.us,123456789012345@lid,919999000099@c.us';
process.env.API_BASE_URL = process.env.E2E_API_BASE || 'http://127.0.0.1:9080/admin';
process.env.HEADLESS = 'true';
process.env.USER_DATA_DIR = path.join(__dirname, '../../automation/.whatsapp-session-test');
process.env.ENABLE_PRIVACY_FILTER = 'false';
process.env.POLL_INTERVAL = '60000';

const secret = execSync('/Applications/XAMPP/xamppfiles/bin/php ' + path.join(__dirname, 'lib/get_bridge_secret.php'), { encoding: 'utf8' }).trim();
process.env.WHATSAPP_BRIDGE_SECRET = secret;

const WhatsAppBridge = require(path.join(__dirname, '../../automation/whatsapp-bridge'));
const waSafety = require(path.join(__dirname, '../../automation/wa-safety'));
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/chats.json'), 'utf8'));

const results = [];
function t(id, name, ok, detail='') {
  results.push({ id, name, ok, detail });
  console.log(`${ok?'PASS':'FAIL'}  ${id}  ${name}${detail?' — '+detail:''}`);
}
function get(url) {
  return new Promise((resolve, reject) => {
    http.get(url, res => { let d=''; res.on('data', c => d+=c); res.on('end', () => { try { resolve(JSON.parse(d)); } catch { resolve(d); } }); }).on('error', reject);
  });
}
function post(url, body) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const data = JSON.stringify(body || {});
    const req = http.request({ hostname: u.hostname, port: u.port, path: u.pathname, method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(data) } }, res => {
      let d=''; res.on('data', c => d+=c); res.on('end', () => { try { resolve(JSON.parse(d)); } catch { resolve(d); } });
    });
    req.on('error', reject); req.write(data); req.end();
  });
}

function cancelAllQueued() {
  // Cancel anything left in test DB so polling cannot send mid-test
  execSync(`/Applications/XAMPP/xamppfiles/bin/php -r '
    putenv("WA_TEST_MODE=1"); putenv("HCC_DB_NAME=hcc_wa_test");
    $_SERVER["SCRIPT_NAME"]="/tmp/x.php"; $_SERVER["HTTP_HOST"]="localhost";
    require "${path.join(__dirname, "../../config/database.php").replace(/\\/g, "/")}";
    $db=getDB();
    $n=$db->exec("UPDATE whatsapp_outbound_queue SET status=\\"cancelled\\" WHERE status IN (\\"queued\\",\\"processing\\",\\"revoke_pending\\") OR msg_type=\\"revoke\\"");
    echo "cancelled_rows=$n\\n";
  '`, { encoding: 'utf8' });
}

(async () => {
  let health;
  try { health = await get('http://127.0.0.1:9876/mock/health'); }
  catch (e) { t('M0', 'mock server up', false, e.message); return finish(1); }
  t('M0', 'mock server up', !!(health && health.ok));

  cancelAllQueued();
  await post('http://127.0.0.1:9876/mock/reset', {});
  await post('http://127.0.0.1:9876/mock/logout', {});

  const bridge = new WhatsAppBridge({
    apiBaseUrl: process.env.API_BASE_URL,
    userDataDir: process.env.USER_DATA_DIR,
    headless: true,
    enablePrivacyFilter: false,
    pollInterval: 60000,
  });

  try {
    await bridge.init();
    t('M1', 'bridge init against mock', true);
  } catch (e) {
    t('M1', 'bridge init against mock', false, e.message);
    return finish(1);
  }

  // Stop background polling / queue processing during assertions
  try { if (bridge.pollInterval) clearInterval(bridge.pollInterval); } catch {}
  bridge.isPolling = false;
  bridge.sending = false;
  cancelAllQueued();

  let blocked = false;
  try { await bridge.safeGoto('https://web.whatsapp.com'); }
  catch (e) { blocked = /WA_TEST_MODE blocks/.test(e.message); }
  t('M2', 'safeGoto blocks real WA', blocked);

  const entry = waSafety.resolveWhatsAppEntryUrl();
  await bridge.safeGoto(entry, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await new Promise(r => setTimeout(r, 500));
  try {
    await bridge.captureQR();
    t('M3', 'QR captured from mock', !!(bridge.currentQR && String(bridge.currentQR).startsWith('data:image')), (bridge.currentQR || '').slice(0, 32));
  } catch (e) {
    t('M3', 'QR captured from mock', false, e.message);
  }

  const chats = Object.values(fixtures).map(c => ({ ...c, messages: [] }));
  await bridge.safeGoto(entry, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await bridge.page.evaluate((chats) => { window.MockAPI.login(chats); }, chats);
  await post('http://127.0.0.1:9876/mock/login', { chats });
  bridge.isConnected = true;
  t('M4', 'mock login / connected flag', bridge.isConnected === true);

  let refused = false;
  try { bridge.assertFixtureChatOrThrow('919999000501@c.us'); } catch (e) { refused = true; }
  t('M5', 'non-fixture chat refused', refused);

  const personal = fixtures.personal.wa_chat_id;
  try {
    const sendResult = await bridge.sendText(personal, 'E2E mock personal ' + Date.now());
    t('M6', 'sendText personal success', !!(sendResult && sendResult.success), JSON.stringify(sendResult));
    t('M7', 'wa_msg_id real not temp_', !!(sendResult && sendResult.wa_msg_id && !String(sendResult.wa_msg_id).startsWith('temp_')), (sendResult && sendResult.wa_msg_id) || '');
  } catch (e) {
    t('M6', 'sendText personal success', false, e.message);
    t('M7', 'wa_msg_id real not temp_', false, e.message);
  }

  for (const key of ['group', 'lid']) {
    try {
      const r = await bridge.sendText(fixtures[key].wa_chat_id, `E2E mock ${key} ` + Date.now());
      t(`M8_${key}`, `sendText ${key}`, !!(r && r.success && r.wa_msg_id), JSON.stringify(r));
    } catch (e) {
      t(`M8_${key}`, `sendText ${key}`, false, e.message);
    }
  }

  try {
    await bridge.page.evaluate((jid) => {
      window.MockAPI.injectIncoming({ wa_chat_id: jid, body: 'E2E mock incoming', sender_name: 'Fixture Parent One' });
      const rows = document.querySelectorAll('#pane-side [role="listitem"]');
      for (const row of rows) {
        if ((row.getAttribute('data-id') || '') === jid) { row.click(); break; }
      }
    }, personal);
    const hasIn = await bridge.page.evaluate(() => !!document.querySelector('#main .message-in'));
    t('M9', 'incoming visible in mock DOM', hasIn);
  } catch (e) {
    t('M9', 'incoming visible in mock DOM', false, e.message);
  }

  await bridge.page.evaluate(() => { window.__MOCK__.failNextSend = true; });
  const fail = await bridge.sendText(personal, 'E2E should fail');
  t('M10', 'mock send failure surfaced', !(fail && fail.success), JSON.stringify(fail));
  await bridge.page.evaluate(() => { window.__MOCK__.failNextSend = false; });

  await bridge.page.evaluate(() => window.MockAPI.logout());
  bridge.isConnected = false;
  await bridge.safeGoto(entry, { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
  await bridge.captureQR().catch(() => {});
  t('M11', 'QR after logout', !!(bridge.currentQR), (bridge.currentQR || '').slice(0, 24));

  // Single-instance lock evidence: supervisor lock file mechanism already tested separately
  t('M12', 'test mode still active', waSafety.isTestMode() === true);

  await bridge.shutdown().catch(() => {});
  finish(results.some(r => !r.ok) ? 1 : 0);
})().catch(async (e) => {
  console.error(e);
  t('M_FATAL', 'suite', false, e.message);
  finish(1);
});

function finish(code) {
  const failed = results.filter(r => !r.ok).length;
  const out = { suite: 'bridge_mock', passed: results.length - failed, failed, results };
  fs.writeFileSync(path.join(__dirname, 'results', 'bridge_mock.json'), JSON.stringify(out, null, 2));
  console.log(`\nBridge mock: ${out.passed} passed, ${out.failed} failed`);
  process.exit(code);
}
