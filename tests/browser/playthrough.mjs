/**
 * Browser playthrough: starts `php artisan serve`, plays real games in
 * headless Chromium and fails on any console error or broken flow.
 *
 *   npm run test:browser            # run
 *   SHOTS=dir npm run test:browser  # also save screenshots to dir
 */
import { spawn, execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { chromium } from 'playwright';

const PORT = 8790;
const BASE = `http://127.0.0.1:${PORT}`;
const shotsDir = process.env.SHOTS;
const failures = [];

// A throwaway database, so playthrough data never lands in the dev database.
const workDir = mkdtempSync(join(tmpdir(), 'memory-playthrough-'));
const dbPath = join(workDir, 'playthrough.sqlite');
writeFileSync(dbPath, '');
const env = { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: dbPath, MAIL_MAILER: 'log', APP_URL: BASE };
execFileSync('php', ['artisan', 'migrate', '--force', '--no-interaction'], { env, stdio: 'ignore' });

const mailLog = 'storage/logs/laravel.log';
const logSize = () => {
    try {
        return statSync(mailLog).size;
    } catch {
        return 0;
    }
};

/** The newest verification link written to the mail log since `offset`. */
function verificationLinkSince(offset) {
    const text = readFileSync(mailLog, 'utf8').slice(offset)
        .replace(/=\r?\n/g, '') // quoted-printable soft line breaks
        .replace(/=3D/g, '=')
        .replace(/&amp;/g, '&');
    const links = text.match(/https?:\/\/[^\s"'<>\]]+\/email\/verify\/[^\s"'<>\]]+/g) ?? [];
    return links.at(-1);
}

function check(condition, message) {
    if (!condition) failures.push(message);
    console.log(`${condition ? '✓' : '✗'} ${message}`);
}

async function waitForServer() {
    for (let i = 0; i < 40; i++) {
        try {
            if ((await fetch(`${BASE}/up`)).ok) return;
        } catch {}
        await new Promise((r) => setTimeout(r, 250));
    }
    throw new Error('server did not start');
}

async function shot(page, name) {
    if (shotsDir) await page.screenshot({ path: `${shotsDir}/${name}.png`, fullPage: true });
}

/** Flip every pair, reading names off the card faces. Optionally make one miss first. */
async function solve(page, { miss = false } = {}) {
    const names = await page.$$eval('.card', (cards) => cards.map((c) => c.querySelector('.card__name').textContent));
    if (miss) {
        const other = names.findIndex((n) => n !== names[0]);
        await page.click(`.card[data-card-id="0"]`);
        await page.click(`.card[data-card-id="${other}"]`);
        await page.waitForTimeout(1100);
    }
    const done = new Set();
    for (let i = 0; i < names.length; i++) {
        if (done.has(i)) continue;
        const j = names.findIndex((n, k) => k !== i && n === names[i]);
        await page.click(`.card[data-card-id="${i}"]`);
        await page.click(`.card[data-card-id="${j}"]`);
        done.add(i).add(j);
        await page.waitForTimeout(80);
    }
}

const server = spawn('php', ['artisan', 'serve', `--port=${PORT}`], { stdio: 'ignore', env });
const browser = await chromium.launch();

try {
    await waitForServer();
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));

    await page.goto(BASE);
    check((await page.$$('#difficulty option')).length === 6, 'six grid sizes offered');
    await page.selectOption('#difficulty', '6x6');
    check((await page.$$('.card')).length === 36, '6 × 6 grid deals 36 cards');
    await page.selectOption('#difficulty', '4x3');
    check((await page.$$('.card')).length === 12, '4 × 3 grid deals 12 cards');
    const boardBottom = await page.$eval('#gameBoard', (b) => b.getBoundingClientRect().bottom);
    check(boardBottom <= 900, `easy board fits a 900px-high window (bottom at ${Math.round(boardBottom)}px)`);
    await shot(page, 'desktop-start');

    // Arrow keys move focus around the grid (4 columns on desktop).
    await page.focus('.card[data-card-id="0"]');
    await page.keyboard.press('ArrowRight');
    await page.keyboard.press('ArrowDown');
    check(await page.evaluate(() => document.activeElement.dataset.cardId) === '5', 'arrow keys move focus right then down');
    check((await page.$$('.card[tabindex="0"]')).length === 1, 'only one card is in the tab order');

    // Single player: one miss, then a perfect solve.
    await solve(page, { miss: true });
    await page.waitForSelector('#winDialog[open]', { timeout: 3000 });
    check(await page.textContent('#moves') === '7', 'turns counted (1 miss + 6 matches)');
    check(await page.isVisible('#fact') && (await page.textContent('#factText')).includes(':'), 'a medical fact is shown after a match');
    await page.waitForTimeout(400);
    const box = await page.$eval('#winDialog', (d) => d.getBoundingClientRect().toJSON());
    const centred = Math.abs(box.x + box.width / 2 - 640) < 4 && Math.abs(box.y + box.height / 2 - 450) < 4;
    check(centred, `win dialog is centred (centre at ${Math.round(box.x + box.width / 2)}, ${Math.round(box.y + box.height / 2)})`);
    check((await page.$$('#winStars .star--on')).length >= 2, 'star rating shown');
    await shot(page, 'desktop-win');

    await page.fill('#playerName', 'Playtest');
    await page.click('#submitScore');
    await page.waitForSelector('#scoreForm[hidden]', { state: 'attached', timeout: 3000 });
    check(/ranked #\d+/.test(await page.textContent('#winSummary')), 'score submitted and ranked');
    await page.waitForSelector('.leaderboard__row--mine', { timeout: 3000 });
    check(true, 'own row highlighted on leaderboard');

    // Escape starts a new game.
    await page.keyboard.press('Escape');
    check(!(await page.$('#winDialog[open]')), 'escape closes the win dialog');
    check(await page.isHidden('#winSaved'), 'guests are not told the game was saved');
    check(await page.textContent('#moves') === '0', 'new game resets moves');

    // Daily boards are identical across reloads.
    await page.click('.game__mode[data-mode="daily"]');
    const daily1 = await page.$$eval('.card__name', (els) => els.map((e) => e.textContent).join());
    await page.reload();
    const daily2 = await page.$$eval('.card__name', (els) => els.map((e) => e.textContent).join());
    check(daily1 === daily2, 'daily board is the same after reload');
    await page.click('.game__mode[data-mode="free"]');

    // Two players: a miss passes the turn.
    await page.selectOption('#players', '2');
    check(await page.isVisible('#playersBar'), 'two-player bar visible');
    const before = await page.getAttribute('.players__card--active', 'data-player');
    await solve(page, { miss: true });
    await page.waitForSelector('#winDialog[open]', { timeout: 3000 });
    const summary = await page.textContent('#winSummary');
    check(before === '0' && /Player 2 wins|Player 1 wins|tie/.test(summary), `two-player result: "${summary.trim()}"`);
    check(await page.isHidden('#scoreForm'), 'two-player games cannot be submitted');
    await page.click('#playAgainBtn');
    await page.selectOption('#players', '1');

    // Keyboard: cards are focusable buttons.
    await page.focus('.card[data-card-id="0"]');
    await page.keyboard.press('Enter');
    check(await page.$eval('.card[data-card-id="0"]', (c) => c.classList.contains('card--flipped')), 'enter flips the focused card');

    // Phone width.
    const phone = await browser.newPage({ viewport: { width: 375, height: 800 } });
    phone.on('pageerror', (e) => errors.push(e.message));
    await phone.goto(BASE);
    for (const grid of ['6x4', '6x6']) {
        await phone.selectOption('#difficulty', grid);
        const overflow = await phone.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
        check(!overflow, `no horizontal scroll at phone width (${grid})`);
    }
    await shot(phone, 'phone-6x6');
    await phone.goto(`${BASE}/leaderboard`);
    check(!(await phone.evaluate(() => document.documentElement.scrollWidth > window.innerWidth)), 'no horizontal scroll on rankings at phone width');

    // Register, confirm by the emailed link, then play as a verified player.
    const account = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    account.on('pageerror', (e) => errors.push(e.message));
    const name = `Tester${Date.now() % 100000}`;
    const offset = logSize();
    await account.goto(`${BASE}/register`);
    await shot(account, 'register');
    await account.fill('#name', name);
    await account.fill('#email', `${name.toLowerCase()}@example.com`);
    await account.fill('#password', 'secret123');
    await account.fill('#password_confirmation', 'secret123');
    await account.click('button[type="submit"]');
    await account.waitForURL('**/email/verify');
    check(await account.isVisible('text=Check your inbox'), 'registration asks for email confirmation');

    await account.goto(BASE);
    check(await account.isVisible('text=Confirm your email to save games'), 'unverified players see a reminder on the game');

    const link = verificationLinkSince(offset);
    check(Boolean(link), 'verification email written to the mail log');
    await account.goto(link);
    check((await account.textContent('.flash')).includes('verified'), 'emailed link verifies the account');
    check(await account.isVisible(`.topnav >> text=${name}`), 'nav shows the player name once verified');

    await account.selectOption('#difficulty', '4x3');
    await solve(account);
    await account.waitForSelector('#winDialog[open]', { timeout: 3000 });
    await account.waitForSelector('#winSaved:not([hidden])', { timeout: 3000 });
    check(true, 'win dialog confirms the game was saved to the account');
    check(await account.isHidden('#playerName'), 'verified players are not asked for a name');
    await account.click('#submitScore');
    await account.waitForSelector('.leaderboard__row--mine', { timeout: 3000 });
    check((await account.textContent('.leaderboard__row--mine')).includes(name), 'top score posted under the account name');

    await account.goto(`${BASE}/account`);
    check((await account.textContent('.tiles')).includes('6'), 'account page shows turns taken');
    await shot(account, 'account');

    await account.goto(`${BASE}/leaderboard?grid=4x3`);
    check(await account.isVisible(`.table >> text=${name}`), 'player appears in the 4 × 3 rankings');
    await shot(account, 'rankings');

    check(errors.length === 0, `no console errors${errors.length ? `: ${errors.join(' | ')}` : ''}`);
} finally {
    await browser.close();
    server.kill();
    rmSync(workDir, { recursive: true, force: true });
}

if (failures.length) {
    console.error(`\n${failures.length} check(s) failed`);
    process.exit(1);
}
console.log('\nplaythrough passed');
