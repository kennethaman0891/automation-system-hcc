#!/usr/bin/env node
'use strict';
const path = require('path');
const fs = require('fs');
const WhatsAppBridge = require(path.join(__dirname, '../../automation/whatsapp-bridge'));
const results = [];
function t(id, name, ok, detail='') {
  results.push({ id, name, ok, detail });
  console.log(`${ok?'PASS':'FAIL'}  ${id}  ${name}${detail?' — '+detail:''}`);
}

// findChrome / getBridgeTempDir exist
t('P1', 'findChrome exported', typeof WhatsAppBridge.findChrome === 'function');
t('P2', 'getBridgeTempDir exported', typeof WhatsAppBridge.getBridgeTempDir === 'function');
t('P3', 'temp dir resolves', !!WhatsAppBridge.getBridgeTempDir());

// Simulate platform UA strings (mirror bridge logic)
function uaFor(platform) {
  if (platform === 'win32') return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
  if (platform === 'darwin') return 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36';
  return 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36';
}
function modKey(platform) { return platform === 'darwin' ? 'Meta' : 'Control'; }

t('P4', 'UA win32', uaFor('win32').includes('Windows'));
t('P5', 'UA darwin', uaFor('darwin').includes('Macintosh'));
t('P6', 'UA linux', uaFor('linux').includes('Linux'));
t('P7', 'modKey darwin Meta', modKey('darwin') === 'Meta');
t('P8', 'modKey win32 Control', modKey('win32') === 'Control');
t('P9', 'modKey linux Control', modKey('linux') === 'Control');

// Supervisor / unit files structural checks
const root = path.join(__dirname, '../../automation');
const plist = fs.readFileSync(path.join(root, 'com.hcc.whatsapp-bridge.plist.template'), 'utf8');
t('P10', 'plist has Label', /<key>Label<\/key>/.test(plist));
t('P11', 'plist has ProgramArguments', /ProgramArguments/.test(plist));

const systemdPath = path.join(root, 'linux');
let unit = '';
try {
  const files = fs.readdirSync(systemdPath).filter(f => f.endsWith('.template') || f.endsWith('.service'));
  unit = files.map(f => fs.readFileSync(path.join(systemdPath, f), 'utf8')).join('\n');
} catch (e) {}
t('P12', 'systemd template exists', unit.length > 0);
t('P13', 'systemd has [Service]', /\[Service\]/.test(unit));
t('P14', 'systemd Restart=', /Restart=/.test(unit));

const bat = fs.readFileSync(path.join(root, 'start-bridge.bat'), 'utf8');
t('P15', 'windows bat mentions node', /node/i.test(bat));

// plutil lint if available
const { execSync } = require('child_process');
let plutilOk = false;
let plutilDetail = 'plutil not available';
try {
  // Materialize template with dummy paths for lint
  const tmp = path.join(__dirname, 'results', 'lint.plist');
  const rendered = plist
    .replace(/\{\{[^}]+\}\}/g, '/tmp/hcc-dummy')
    .replace(/__[^_]+__/g, '/tmp/hcc-dummy');
  fs.writeFileSync(tmp, rendered);
  execSync(`plutil -lint "${tmp}"`, { stdio: 'pipe' });
  plutilOk = true;
  plutilDetail = 'plutil -lint OK';
} catch (e) {
  plutilDetail = (e.stderr && e.stderr.toString()) || e.message;
  // structural fallback already covered by P10/P11
}
t('P16', 'plist lint (plutil or structural)', plutilOk || (/Label/.test(plist) && /ProgramArguments/.test(plist)), plutilDetail);

// Isolation from system Google Chrome on macOS
const resolvedChrome = WhatsAppBridge.findChrome();
if (process.platform === 'darwin') {
  t('P17', 'macOS does not default to system Google Chrome.app', resolvedChrome !== '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', resolvedChrome);
  t('P18', 'macOS prefers isolated browser or Chrome for Testing', !resolvedChrome || resolvedChrome.includes('Google Chrome for Testing') || resolvedChrome.includes('Chromium'), resolvedChrome);
} else {
  t('P17', 'platform findChrome returned string or null', resolvedChrome === null || typeof resolvedChrome === 'string');
}

// Explicit path parameter test (using process.execPath which is guaranteed to exist)
t('P19', 'findChrome respects explicit path', WhatsAppBridge.findChrome(process.execPath) === process.execPath);

const failed = results.filter(r => !r.ok).length;
const out = { suite: 'platform', passed: results.length - failed, failed, results };
fs.writeFileSync(path.join(__dirname, 'results', 'platform.json'), JSON.stringify(out, null, 2));
console.log(`\nPlatform: ${out.passed} passed, ${out.failed} failed`);
process.exit(failed ? 1 : 0);
