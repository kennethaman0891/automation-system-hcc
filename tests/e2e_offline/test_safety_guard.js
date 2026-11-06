#!/usr/bin/env node
'use strict';
const path = require('path');
const waSafety = require(path.join(__dirname, '../../automation/wa-safety'));
const results = [];
function t(id, name, ok, detail='') {
  results.push({ id, name, ok, detail });
  console.log(`${ok?'PASS':'FAIL'}  ${id}  ${name}${detail?' — '+detail:''}`);
}

const envTest = { WA_TEST_MODE: '1', WA_MOCK_URL: 'http://127.0.0.1:9876/', WA_ALLOWED_CHATS: waSafety.defaultAllowedChatsCsv() };
const envLive = { WA_TEST_MODE: '0' };

t('S1', 'isTestMode true', waSafety.isTestMode(envTest) === true);
t('S2', 'isTestMode false', waSafety.isTestMode(envLive) === false);
t('S3', 'blocks web.whatsapp.com in test', waSafety.assertNavigationAllowed('https://web.whatsapp.com', envTest).ok === false);
t('S4', 'blocks web.whatsapp.com/send in test', waSafety.assertNavigationAllowed('https://web.whatsapp.com/send?phone=91', envTest).ok === false);
t('S5', 'allows mock URL in test', waSafety.assertNavigationAllowed('http://127.0.0.1:9876/', envTest).ok === true);
t('S6', 'allows real WA when not test', waSafety.assertNavigationAllowed('https://web.whatsapp.com', envLive).ok === true);
t('S7', 'resolve entry is mock in test', waSafety.resolveWhatsAppEntryUrl(envTest).includes('127.0.0.1'));
t('S8', 'resolve entry is real when live', waSafety.resolveWhatsAppEntryUrl(envLive).includes('web.whatsapp.com'));
t('S9', 'fixture personal allowed', waSafety.assertChatAllowed(waSafety.FIXTURE_CHATS.personal, envTest).ok);
t('S10', 'non-fixture personal chat refused', waSafety.assertChatAllowed('919999000501@c.us', envTest).ok === false);
t('S11', 'non-fixture business chat refused', waSafety.assertChatAllowed('919999000502@c.us', envTest).ok === false);
t('S12', 'empty allowlist refuses all', waSafety.assertChatAllowed('919999000001@c.us', { WA_TEST_MODE:'1', WA_ALLOWED_CHATS:'' }).ok === false);
t('S13', 'group fixture allowed', waSafety.assertChatAllowed(waSafety.FIXTURE_CHATS.group, envTest).ok);
t('S14', 'lid fixture allowed', waSafety.assertChatAllowed(waSafety.FIXTURE_CHATS.lid, envTest).ok);
t('S15', 'isRealWhatsAppUrl', waSafety.isRealWhatsAppUrl('https://web.whatsapp.com/') === true && waSafety.isRealWhatsAppUrl('http://127.0.0.1:9876/') === false);

const failed = results.filter(r => !r.ok).length;
const out = { suite: 'safety_guard', passed: results.length - failed, failed, results };
require('fs').writeFileSync(path.join(__dirname, 'results', 'safety_guard.json'), JSON.stringify(out, null, 2));
console.log(`\nSafety: ${out.passed} passed, ${out.failed} failed`);
process.exit(failed ? 1 : 0);
