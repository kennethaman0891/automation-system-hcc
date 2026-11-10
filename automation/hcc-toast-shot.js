const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const WhatsAppBridge = require('./whatsapp-bridge');
(async () => {
  const out = path.join(process.env.HOME, 'hcc-review-backup-20261105-212400', 'hcc-shot-conversation2.png');
  fs.mkdirSync(path.dirname(out), { recursive: true });
  const browser = await puppeteer.launch({
    headless: true,
    executablePath: WhatsAppBridge.findChrome(),
    args: ['--no-sandbox', '--window-size=1400,900']
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1400, height: 900 });
  await page.goto('http://127.0.0.1/hcc/admin/whatsapp_share.php?view=replica', { waitUntil: 'networkidle2' });
  await page.waitForSelector('wa-app, #waAppReplica', { timeout: 30000 }).catch(() => {});
  await new Promise(r => setTimeout(r, 2500));

  const stats = await page.evaluate(async () => {
    // Ensure notifications module is ready
    const wait = (ms) => new Promise(r => setTimeout(r, ms));
    for (let i = 0; i < 20 && !window.WhatsAppNotifications; i++) await wait(100);
    if (!window.WhatsAppNotifications) return { error: 'no WhatsAppNotifications' };

    // Force allow toasts for this visual test
    if (window.WhatsAppStore) {
      window.WhatsAppStore.suppressNotifications = false;
      window.WhatsAppStore._initialSyncing = false;
      // Leave active chat unset / different so toasts show
      window.WhatsAppStore.activeChatId = null;
    }
    window.WhatsAppNotifications.dismissAll();

    // Simulate the stacked flood the user saw — same chats with multiple msgs
    const bursts = [
      ['Jio Promotions', 'Special offer today', 101],
      ['Jio Promotions', 'Another promo', 101],
      ['Dad', 'Call me', 202],
      ['Dad', 'Where are you?', 202],
      ['Demo Teacher', 'Meeting at 4', 303],
      ['Demo Teacher', 'Please confirm', 303],
      ['Demo Teacher', 'Bring the files', 303],
      ['Demo Teacher', 'Also the marks sheet', 303],
      ['Demo Teacher', 'Thanks', 303],
      ['Mom', 'Dinner ready', 404],
      ['Office Group', 'Announcement', 505],
    ];
    for (const [title, body, chatId] of bursts) {
      window.WhatsAppNotifications.show(title, body, { chatId, timeout: 20000 });
    }
    await wait(400);

    const visible = Array.from(document.querySelectorAll('#wa-notification-container .wa-toast-banner'))
      .filter(el => el.style.display !== 'none');
    const hidden = Array.from(document.querySelectorAll('#wa-notification-container .wa-toast-banner'))
      .filter(el => el.style.display === 'none');
    const overflow = document.querySelector('.wa-toast-overflow');
    const counts = visible.map(el => ({
      title: el.querySelector('.wa-toast-title')?.textContent,
      count: el.querySelector('.wa-toast-count')?.textContent,
      body: el.querySelector('.wa-toast-body')?.textContent,
    }));
    return {
      uniqueKeys: window.WhatsAppNotifications.toastsByChat.size,
      visible: visible.length,
      hidden: hidden.length,
      overflow: overflow ? overflow.textContent : null,
      counts
    };
  });
  console.log('TOAST_STATS', JSON.stringify(stats, null, 2));

  await page.screenshot({ path: out, fullPage: false });
  console.log('saved', out);
  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
