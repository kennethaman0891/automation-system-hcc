const puppeteer = require('puppeteer');
const path = require('path');
const fs = require('fs');
const WhatsAppBridge = require('./whatsapp-bridge');

(async () => {
  const outDir = '/tmp/hcc-shots';
  fs.mkdirSync(outDir, { recursive: true });
  const browser = await puppeteer.launch({
    headless: true,
    executablePath: WhatsAppBridge.findChrome(),
    args: ['--no-sandbox', '--window-size=1400,900']
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1400, height: 900 });
  page.setDefaultTimeout(60000);

  const url = 'http://127.0.0.1/hcc/admin/whatsapp_share.php?view=replica';
  await page.goto(url, { waitUntil: 'networkidle2' });
  await page.waitForSelector('wa-app, #waAppReplica, .wa-app', { timeout: 30000 }).catch(() => {});
  await new Promise(r => setTimeout(r, 2500));

  // Shot 1: conversation / hub view
  const shot1 = path.join(outDir, 'hcc-shot-conversation.png');
  await page.screenshot({ path: shot1, fullPage: false });
  console.log('saved', shot1);

  // Open template library via evaluate (custom element may be in light DOM)
  const opened = await page.evaluate(async () => {
    const lib = document.getElementById('wa-template-library');
    if (!lib) return { ok: false, reason: 'no lib' };
    if (typeof lib.open === 'function') {
      lib.open({});
      return { ok: true };
    }
    lib.setAttribute('open', '');
    return { ok: true, via: 'attr' };
  });
  console.log('open library', opened);
  await new Promise(r => setTimeout(r, 1500));

  // Force load if needed
  await page.evaluate(async () => {
    const lib = document.getElementById('wa-template-library');
    if (lib && typeof lib.load === 'function') await lib.load();
  });
  await new Promise(r => setTimeout(r, 1200));

  const shot2 = path.join(outDir, 'hcc-shot-templates.png');
  await page.screenshot({ path: shot2, fullPage: false });
  console.log('saved', shot2);

  // Edit first card
  await page.evaluate(() => {
    const lib = document.getElementById('wa-template-library');
    if (!lib || !lib.shadowRoot) return;
    const editBtn = lib.shadowRoot.querySelector('[data-act="edit"]');
    if (editBtn) editBtn.click();
  });
  await new Promise(r => setTimeout(r, 800));
  const shot3 = path.join(outDir, 'hcc-shot-template-edit.png');
  await page.screenshot({ path: shot3, fullPage: false });
  console.log('saved', shot3);

  await browser.close();
  console.log('DONE');
})().catch(err => {
  console.error('SHOT_FAIL', err);
  process.exit(1);
});
