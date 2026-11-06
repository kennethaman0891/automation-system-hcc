/**
 * Run: node automation/test-wa-revoke-rules.js
 */
const assert = require('assert');
const rules = require('./wa-revoke-rules');

const now = 1_700_000_000;
assert.strictEqual(rules.canRevokeForEveryone(now - 60, now, 3600).ok, true);
assert.strictEqual(rules.canRevokeForEveryone(now - 4000, now, 3600).ok, false);
assert.ok(String(rules.canRevokeForEveryone(now - 4000, now, 3600).reason).includes('window'));
assert.strictEqual(rules.canRevokeForEveryone(null, now, 3600).ok, false);
assert.strictEqual(rules.canRevokeForEveryone(now * 1000 - 1000, now, 3600).ok, true); // ms form

const parsed = rules.parseRevokeQueueBody(JSON.stringify({
  wa_msg_id: 'true_919876543210@c.us_ABCD',
  msg_db_id: 42,
  timestamp: now - 10
}));
assert.strictEqual(parsed.wa_msg_id, 'true_919876543210@c.us_ABCD');
assert.strictEqual(parsed.msg_db_id, 42);
assert.strictEqual(rules.parseRevokeQueueBody('temp_1_2'), null);
assert.strictEqual(rules.parseRevokeQueueBody(JSON.stringify({ wa_msg_id: 'temp_1_2' })), null);
assert.strictEqual(rules.parseRevokeQueueBody('{'), null);

assert.strictEqual(rules.revokeWindowSec({ WHATSAPP_REVOKE_WINDOW_SEC: '120' }), 120);
assert.strictEqual(rules.revokeWindowSec({}), rules.DEFAULT_REVOKE_WINDOW_SEC);

console.log('PASS wa-revoke-rules');
