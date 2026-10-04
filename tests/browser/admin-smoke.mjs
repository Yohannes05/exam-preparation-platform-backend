/* TEMPORARY dev-only browser test for the admin Next.js app. Run: node tests/browser/admin-smoke.mjs */
import puppeteer from 'puppeteer-core';

const BASE = 'http://127.0.0.1:3000';

const results = [];
const check = (name, ok, extra = '') => {
    results.push({ name, ok });
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${extra ? ' — ' + extra : ''}`);
};

const browser = await puppeteer.launch({
    executablePath: '/usr/bin/google-chrome',
    headless: 'new',
    args: [
        '--no-sandbox',
        '--disable-gpu',
        '--disable-dev-shm-usage',
        '--disable-web-security',
    ],
});

try {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
    page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

    // Login view renders
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0', timeout: 30000 });
    check('login view renders', !!(await page.$('h1')));
    const hasEyeIcon = await page.$('button[aria-label*="Show password"]') !== null;
    check('eye icon present', hasEyeIcon);

    // Pre-fill email + password
    await page.$eval('input[name=email]', (el) => { el.value = 'admin@example.com'; });
    await page.$eval('input[name=password]', (el) => { el.value = ''; });
    await page.type('input[name=password]', 'password');

    // Toggle the password visibility
    const eyeBefore = await page.$('button[aria-label*="Show password"]');
    const isVisibleBefore = await page.evaluate(() => {
        const input = document.querySelector('input[name=password]');
        return input.type === 'text';
    });
    await eyeBefore.click();
    const eyeAfter = await page.waitForFunction(() => {
        const input = document.querySelector('input[name=password]');
        return input && input.type === 'text';
    }, { timeout: 5000 });
    check('show/hide password toggle switches eye', true);

    // Submit login
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 30000 }).catch(() => {}),
        page.click('button[type="submit"]'),
    ]);
    await page.waitForSelector('.stat-grid', { timeout: 30000 });
    check('login via UI reaches dashboard', true);

    const stats = await page.$$eval('.stat', (els) => els.length);
    check('dashboard stats render', stats >= 8, `${stats} stat cards`);

    // Logout
    await page.click('#logout-btn');
    await page.waitForSelector('h1', { timeout: 30000 });
    check('logout returns to login view', true);

} finally {
    await browser.close();
    console.log(`\n${results.filter((r) => !r.ok).length} failure(s)`);
    process.exitCode = results.some((r) => !r.ok) ? 1 : 0;
}
