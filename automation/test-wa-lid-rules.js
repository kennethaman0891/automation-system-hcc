const assert = require('assert');
const rules = require('./wa-list-rules');

assert.strictEqual(rules.phoneDigitsFromJid('154321098765432@lid'), '');
assert.strictEqual(rules.phoneDigitsFromJid('919876543210@c.us'), '919876543210');

function jidToWaChatId(jid) {
  const raw = String(jid || '').trim().toLowerCase();
  if (!raw) return '';
  if (raw.includes('@g.us')) {
    const group = raw.match(/(\d+(?:-\d+)?@g\.us)/);
    return group ? group[1] : raw;
  }
  if (raw.includes('broadcast') || raw.includes('newsletter')) return raw;
  if (raw.includes('@lid')) {
    const lid = raw.match(/(\d+)@lid/);
    return lid ? (lid[1] + '@lid') : '';
  }
  const digits = rules.phoneDigitsFromJid(raw);
  return digits ? digits + '@c.us' : '';
}

assert.strictEqual(jidToWaChatId('154321098765432@lid'), '154321098765432@lid');
assert.strictEqual(jidToWaChatId('true_154321098765432@lid_ABCD'), '154321098765432@lid');
assert.strictEqual(jidToWaChatId('false_999@lid_xyz'), '999@lid');
assert.strictEqual(jidToWaChatId('919876543210@c.us'), '919876543210@c.us');
assert.strictEqual(jidToWaChatId('120363@g.us'), '120363@g.us');
console.log('PASS  lid jid preserved; phones still normalize');
