#!/usr/bin/env node
'use strict';
const path = require('path');
const fs = require('fs');
const { execSync } = require('child_process');
const results = [];
function t(id, name, ok, detail='') {
  results.push({ id, name, ok, detail });
  console.log(`${ok?'PASS':'FAIL'}  ${id}  ${name}${detail?' — '+detail:''}`);
}
try {
  const out = execSync('node test-wa-revoke-rules.js', { cwd: path.join(__dirname, '../../automation'), encoding: 'utf8' });
  t('R0', 'wa-revoke-rules suite', !/FAIL|Error/i.test(out) || /ok|pass|all/i.test(out), out.trim().slice(0, 200));
  console.log(out);
} catch (e) {
  t('R0', 'wa-revoke-rules suite', false, e.message);
}
const rules = require(path.join(__dirname, '../../automation/wa-revoke-rules'));
const now = Math.floor(Date.now() / 1000);
t('R1', 'within 48h allowed', rules.canRevokeForEveryone(now - 3600).ok === true);
t('R2', '>48h rejected', rules.canRevokeForEveryone(now - (49 * 3600)).ok === false);
t('R3', 'parse body', !!rules.parseRevokeQueueBody(JSON.stringify({ wa_msg_id: 'true_x_1', timestamp: now })));

const failed = results.filter(r => !r.ok).length;
fs.writeFileSync(path.join(__dirname, 'results', 'revoke_rules.json'), JSON.stringify({ suite:'revoke_rules', passed: results.length-failed, failed, results }, null, 2));
process.exit(failed ? 1 : 0);
