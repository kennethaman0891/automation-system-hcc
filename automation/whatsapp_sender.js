/**
 * HCC WhatsApp PDF Sender — Puppeteer Browser Automation
 *
 * Opens WhatsApp Web, waits for QR login, finds the contact chat,
 * uploads a PDF attachment, and sends the message automatically.
 *
 * Usage:
 *   node whatsapp_sender.js <phone> <message> <pdf_path>
 *
 * Example:
 *   node whatsapp_sender.js 919876543210 "Hello parent" /tmp/report_card.pdf
 *
 * First run: Browser opens with QR code. Scan with phone to login.
 * Subsequent runs: Session is restored from ~/.hcc-whatsapp-data
 */
const puppeteer = require('puppeteer');
const path = require('path');
const os = require('os');
const fs = require('fs');

/**
 * Cross-platform detection of Chrome or Chromium executable.
 * Checks: explicit path -> CHROME_PATH env -> platform standard paths -> Puppeteer cache.
 */
function findChrome(explicitPath) {
    if (explicitPath && fs.existsSync(explicitPath)) return explicitPath;
    if (process.env.CHROME_PATH && fs.existsSync(process.env.CHROME_PATH)) return process.env.CHROME_PATH;

    let candidates = [];
    if (process.platform === 'win32') {
        const progFiles = process.env.PROGRAMFILES || 'C:\\Program Files';
        const progFilesX86 = process.env['PROGRAMFILES(X86)'] || 'C:\\Program Files (x86)';
        const localAppData = process.env.LOCALAPPDATA || (process.env.USERPROFILE ? path.join(process.env.USERPROFILE, 'AppData', 'Local') : '');

        candidates = [
            path.join(progFiles, 'Google', 'Chrome', 'Application', 'chrome.exe'),
            path.join(progFilesX86, 'Google', 'Chrome', 'Application', 'chrome.exe'),
            localAppData ? path.join(localAppData, 'Google', 'Chrome', 'Application', 'chrome.exe') : '',
            // Edge fallback on Windows
            path.join(progFilesX86, 'Microsoft', 'Edge', 'Application', 'msedge.exe'),
            path.join(progFiles, 'Microsoft', 'Edge', 'Application', 'msedge.exe')
        ].filter(Boolean);
    } else if (process.platform === 'darwin') {
        candidates = [
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
            path.join(os.homedir(), 'Applications', 'Google Chrome.app', 'Contents', 'MacOS', 'Google Chrome'),
            '/Applications/Chromium.app/Contents/MacOS/Chromium',
            '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge'
        ];
    } else {
        candidates = [
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/snap/bin/chromium'
        ];
    }

    for (const c of candidates) {
        if (fs.existsSync(c)) return c;
    }

    try {
        const pPath = puppeteer.executablePath ? puppeteer.executablePath() : null;
        if (pPath && fs.existsSync(pPath)) return pPath;
    } catch (e) {}

    return null;
}

const CHROME_PATH = findChrome();
const USER_DATA_DIR = path.join(os.homedir(), '.hcc-whatsapp-data');
const DEFAULT_USER_AGENT = process.platform === 'win32'
    ? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36'
    : 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/127.0.0.0 Safari/537.36';

/** Puppeteer v22+ removed page.waitForTimeout — use this instead. */
function delay(ms) {
    return new Promise(function (resolve) { setTimeout(resolve, ms); });
}

/**
 * Wait for the user to complete QR scan and log into WhatsApp Web.
 * Times out after 3 minutes.
 */
async function waitForLoggedIn(page) {
    // Wait for the chat input textarea to appear (indicates successful login)
    await page.waitForSelector('textarea[data-testid="conversation-input-box"]', {
        timeout: 180000 // 3 minutes for QR scan
    });
    // Extra pause for the chat to fully load
    await delay(2000);
}

/**
 * Navigate to the specific contact's chat using the phone number.
 * Returns true if the chat was found, false if the contact doesn't exist.
 */
async function navigateToContact(page, phone) {
    const waUrl = `https://web.whatsapp.com/send?phone=${phone}&text=`;
    await page.goto(waUrl, { waitUntil: 'networkidle2', timeout: 30000 });

    // Wait for either the chat input (contact found) or a search result
    try {
        await page.waitForSelector('textarea[data-testid="conversation-input-box"]', { timeout: 15000 });
    } catch (e) {
        // Contact might not exist — check for the invalid-number dialog
        var invalid = await page.evaluate(function () {
            var t = document.body ? document.body.innerText : '';
            return /invalid|not on whatsapp|doesn't exist|does not exist/i.test(t);
        }).catch(function () { return false; });
        if (invalid) return false;
        throw new Error('Could not find contact. Check the phone number.');
    }
    return true;
}

/**
 * Attach a PDF file to the WhatsApp Web chat.
 * Clicks the attachment button, selects Document, uploads the PDF.
 */
async function attachPdf(page, pdfPath) {
    // Click the attachment (paperclip) button
    const attachBtn = await page.$('button[data-testid="attach"]');
    if (!attachBtn) {
        throw new Error('Attachment button not found — WhatsApp Web UI may have changed');
    }
    await attachBtn.click();
    await delay(500);

    // Click "Document" option in the attachment menu
    const docBtn = await page.$('span[title="Document"]');
    if (docBtn) {
        await docBtn.click();
    } else {
        // Fallback: find the Document option by visible text (Puppeteer-safe)
        const found = await page.evaluate(function () {
            var spans = Array.prototype.slice.call(document.querySelectorAll('div[role="menu"] span, li span, div[role="button"] span'));
            for (var i = 0; i < spans.length; i++) {
                if (/^\s*document\s*$/i.test(spans[i].textContent || '')) {
                    spans[i].click();
                    return true;
                }
            }
            return false;
        });
        if (!found) {
            console.warn('Document button not found, skipping attachment');
            return false;
        }
    }
    await delay(500);

    // Upload the PDF file via the file input
    const fileInput = await page.$('input[type="file"][accept*=".pdf"]');
    if (fileInput) {
        await fileInput.uploadFile(pdfPath);
    } else {
        // Try broader selector
        const fileInputs = await page.$$('input[type="file"]');
        if (fileInputs.length > 0) {
            await fileInputs[0].uploadFile(pdfPath);
        }
    }

    // Wait for the upload to complete — the send button becomes enabled
    try {
        await page.waitForFunction(() => {
            const sendBtn = document.querySelector('button[data-testid="send"]');
            return sendBtn && !sendBtn.disabled;
        }, { timeout: 60000 });
    } catch (e) {
        console.warn('Upload timeout — send button may not be ready');
        // Give it a bit more time
        await delay(5000);
    }
    return true;
}

/**
 * Type the message as the document caption before sending.
 * After attaching, the composer box becomes the caption field.
 */
async function typeCaption(page, message) {
    if (!message) return;
    try {
        const box = await page.waitForSelector(
            'textarea[data-testid="conversation-input-box"], div[contenteditable="true"][data-testid="conversation-compose-box-input"]',
            { timeout: 10000 }
        );
        await box.click();
        await page.keyboard.type(message, { delay: 8 });
        await delay(400);
    } catch (e) {
        console.warn('Could not type caption, sending attachment without caption: ' + e.message);
    }
}

/**
 * Send the message by clicking the send button.
 */
async function sendMessage(page) {
    const sendBtn = await page.$('button[data-testid="send"]');
    if (sendBtn) {
        await sendBtn.click();
    } else {
        // Fallback: press Enter
        await page.keyboard.press('Enter');
    }
    // Wait for send confirmation
    await delay(1500);
}

/**
 * Main function: launch browser, open WhatsApp Web, attach PDF, send message.
 */
async function sendToContact(phone, message, pdfPath) {
    const launchOpts = {
        headless: false, // Show browser window so user can see QR scan on first run
        userDataDir: USER_DATA_DIR, // Persist login session
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu'
        ]
    };
    if (CHROME_PATH) launchOpts.executablePath = CHROME_PATH;
    const browser = await puppeteer.launch(launchOpts);

    let page;
    try {
        page = await browser.newPage();

        // Set a realistic user agent so WhatsApp Web doesn't block us
        await page.setUserAgent(DEFAULT_USER_AGENT);

        // Navigate to the contact's chat
        const contactFound = await navigateToContact(page, phone);
        if (!contactFound) {
            throw new Error('Contact not found for phone: ' + phone);
        }

        // Wait for user to be logged in (QR scan on first run)
        await waitForLoggedIn(page);

        // Navigate again to ensure we're on the correct chat
        await navigateToContact(page, phone);
        await waitForLoggedIn(page);

        // Attach the PDF
        const attached = await attachPdf(page, pdfPath);
        if (!attached) {
            throw new Error('Could not attach PDF — WhatsApp Web UI may have changed');
        }

        // Type the message as caption, then send both together
        await typeCaption(page, message);
        await sendMessage(page);

        return { success: true };
    } catch (err) {
        return { success: false, error: err.message };
    } finally {
        await browser.close();
    }
}

/**
 * Open browser to web.whatsapp.com so the user can scan QR code once to login.
 */
async function openForLogin() {
    const launchOpts = {
        headless: false,
        userDataDir: USER_DATA_DIR,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--window-size=1100,850'
        ]
    };
    if (CHROME_PATH) launchOpts.executablePath = CHROME_PATH;
    const browser = await puppeteer.launch(launchOpts);

    try {
        const page = await browser.newPage();
        await page.setUserAgent(DEFAULT_USER_AGENT);
        await page.goto('https://web.whatsapp.com', { waitUntil: 'networkidle2', timeout: 45000 });
        await waitForLoggedIn(page);
        return { success: true, message: 'WhatsApp Web login verified!' };
    } catch (err) {
        return { success: false, error: err.message };
    } finally {
        await browser.close();
    }
}

// CLI entry point
async function main() {
    if (process.argv.includes('--login')) {
        try {
            const result = await openForLogin();
            console.log(JSON.stringify(result));
            if (!result.success) process.exit(1);
        } catch (err) {
            console.error(JSON.stringify({ success: false, error: err.message }));
            process.exit(1);
        }
        return;
    }

    const [phone, message, pdfPath] = process.argv.slice(2);

    if (!phone || !message || !pdfPath) {
        console.error('Usage: node whatsapp_sender.js <phone> <message> <pdf_path> OR node whatsapp_sender.js --login');
        console.error('Example: node whatsapp_sender.js 919876543210 "Hello" /path/to/file.pdf');
        process.exit(1);
    }

    // Verify PDF file exists
    const fs = require('fs');
    if (!fs.existsSync(pdfPath)) {
        console.error('Error: PDF file not found: ' + pdfPath);
        process.exit(1);
    }

    try {
        const result = await sendToContact(phone, message, pdfPath);
        console.log(JSON.stringify(result));
        if (!result.success) {
            process.exit(1);
        }
    } catch (err) {
        console.error(JSON.stringify({ success: false, error: err.message }));
        process.exit(1);
    }
}

main();
