/**
 * Run: node automation/test-wa-list-rules.js
 */
const assert = require('assert');
const rules = require('./wa-list-rules');

assert.strictEqual(rules.normalizePhoneDigits('9876543210'), '919876543210');
assert.strictEqual(rules.normalizePhoneDigits('919876543210'), '919876543210');
assert.strictEqual(rules.normalizePhoneDigits('+91 98765 43210'), '919876543210');
assert.strictEqual(rules.normalizePhoneDigits('0000000000'), '');
assert.strictEqual(rules.normalizePhoneDigits('123'), '');

assert.strictEqual(rules.phoneDigitsFromJid('919876543210@c.us'), '919876543210');
assert.strictEqual(rules.phoneDigitsFromJid('9876543210@s.whatsapp.net'), '919876543210');
assert.strictEqual(rules.phoneDigitsFromJid('154321098765432@lid'), '');
assert.strictEqual(rules.phoneDigitsFromJid('120363000000009999@g.us'), '');
assert.strictEqual(rules.phoneDigitsFromJid(''), '');

assert.strictEqual(rules.isSystemChatTitle('Archived'), true);
assert.strictEqual(rules.isSystemChatTitle('unread'), true);
assert.strictEqual(rules.isSystemChatTitle('Locked chats'), true);
assert.strictEqual(rules.isSystemChatTitle('Pooja Deshmukh'), false);
assert.strictEqual(rules.isSystemChatTitle(''), false);

console.log('PASS wa-list-rules');
