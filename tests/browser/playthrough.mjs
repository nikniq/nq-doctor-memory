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
import { dealBoard, hashString, seededRandom } from '../../resources/js/game/random.js';

const PORT = Number(process.env.PLAYTHROUGH_PORT ?? 8790);
const BASE = `http://127.0.0.1:${PORT}`;
const shotsDir = process.env.SHOTS;
const failures = [];

// A throwaway database, so playthrough data never lands in the dev database.
const workDir = mkdtempSync(join(tmpdir(), 'memory-playthrough-'));
const dbPath = join(workDir, 'playthrough.sqlite');
writeFileSync(dbPath, '');
// Mail goes to a log file of its own, whatever the developer's .env says.
const mailLog = join(workDir, 'mail.log');
const env = {
    ...process.env,
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: dbPath,
    MAIL_MAILER: 'log',
    MAIL_LOG_CHANNEL: 'single',
    LOG_CHANNEL: 'single',
    LOG_LEVEL: 'debug',
    LOG_SINGLE_PATH: mailLog,
    APP_URL: BASE,
};
execFileSync('php', ['artisan', 'migrate', '--force', '--no-interaction'], { env, stdio: 'ignore' });
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

async function isServing() {
    try {
        return (await fetch(`${BASE}/up`)).ok;
    } catch {
        return false;
    }
}

async function waitForServer(server) {
    let exited = null;
    server.once('exit', (code) => {
        exited = code;
    });
    for (let i = 0; i < 40; i++) {
        if (exited !== null) throw new Error(`php artisan serve exited with code ${exited}`);
        if (await isServing()) return;
        await new Promise((r) => setTimeout(r, 250));
    }
    throw new Error('server did not start');
}

async function shot(page, name) {
    if (shotsDir) await page.screenshot({ path: `${shotsDir}/${name}.png`, fullPage: true });
}

/**
 * Card names in board order. Face-down cards carry nothing in the DOM, so
 * the layout is worked out the way the game deals a daily board: same
 * cards, same seed (date + grid), same shuffle.
 */
async function boardNames(page) {
    const board = await page.evaluate(() => {
        const app = document.getElementById('app');
        const grid = document.getElementById('difficulty').value;
        return {
            today: app.dataset.today,
            grid,
            cards: JSON.parse(app.dataset.cards),
            pairs: JSON.parse(app.dataset.difficulties)[grid].pairs,
            daily: document.querySelector('.game__mode[data-mode="daily"]').getAttribute('aria-pressed') === 'true',
        };
    });
    if (!board.daily) throw new Error('the solver needs daily mode, where the layout is known');
    return dealBoard(board.cards, board.pairs, seededRandom(hashString(`${board.today}:${board.grid}`))).map((c) => c.name);
}

/** Flip every pair. Optionally make one miss first. */
async function solve(page, { miss = false } = {}) {
    const names = await boardNames(page);
    if (miss) {
        const other = names.findIndex((n) => n !== names[0]);
        await page.click(`.card[data-card-id="0"]`);
        await page.click(`.card[data-card-id="${other}"]`);
        await page.waitForTimeout(1100);
    }
    // Cards already matched (aria-disabled) are skipped.
    const done = new Set(await page.$$eval('.card', (cards) => cards.flatMap((c, i) => (c.getAttribute('aria-disabled') === 'true' ? [i] : []))));
    for (let i = 0; i < names.length; i++) {
        if (done.has(i)) continue;
        const j = names.findIndex((n, k) => k !== i && n === names[i]);
        await page.click(`.card[data-card-id="${i}"]`);
        await page.click(`.card[data-card-id="${j}"]`);
        done.add(i).add(j);
        await page.waitForTimeout(80);
    }
}

// Something else on the port would be tested instead of this checkout.
if (await isServing()) {
    console.error(`Port ${PORT} is already serving an app. Stop it or set PLAYTHROUGH_PORT.`);
    process.exit(1);
}

const server = spawn('php', ['artisan', 'serve', `--port=${PORT}`], { stdio: 'ignore', env });
let browser;

try {
    await waitForServer(server);
    browser = await chromium.launch();
    const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    let forcedFailure = false; // set while a test deliberately makes the server fail
    page.on('console', (m) => {
        if (m.type() !== 'error') return;
        if (forcedFailure && m.text().includes('status of 500')) return;
        errors.push(m.text());
    });

    await page.goto(BASE);
    await page.click('.game__mode[data-mode="daily"]'); // daily boards have a known layout
    check((await page.$$('#difficulty option')).length === 6, 'six grid sizes offered');
    await page.selectOption('#difficulty', '6x6');
    check((await page.$$('.card')).length === 36, '6 × 6 grid deals 36 cards');
    await page.selectOption('#difficulty', '4x3');
    check((await page.$$('.card')).length === 12, '4 × 3 grid deals 12 cards');
    {
        const allNames = await page.evaluate(() => JSON.parse(document.getElementById('app').dataset.cards).map((c) => c.name));
        const boardText = await page.textContent('#gameBoard');
        check(!allNames.some((n) => boardText.includes(n)), 'face-down cards reveal nothing in the page');
        check((await page.getAttribute('.card[data-card-id="0"]', 'aria-label')).startsWith('Row 1, column 1'), 'cards are labelled by row and column');
        check((await page.textContent('#starMeter')).trim() === '⭐⭐⭐ 10 left', 'star meter shows the 3-star target before play');
        check(await page.isVisible('#introLine'), 'first-time players see how scoring works');
    }
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
    check(await page.isHidden('#introLine'), 'the scoring line goes away after the first win');
    check((await page.textContent('#practiceNotice')).includes('Replays are practice') && await page.isVisible('#practiceNotice'), 'replaying the daily board is marked as practice, with a countdown');

    // Daily boards are identical across reloads.
    const firstFace = async () => {
        await page.click('.card[data-card-id="0"]');
        return page.textContent('.card[data-card-id="0"] .card__name');
    };
    const daily1 = await firstFace();
    await page.reload();
    const daily2 = await firstFace();
    check(daily1 === daily2 && daily1 === (await boardNames(page))[0], 'daily board is the same after reload and matches the deal');
    await page.click('#resetBtn');

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

    // --- Races found in review ------------------------------------------
    await page.selectOption('#players', '1');
    await page.selectOption('#difficulty', '4x3');

    const names = () => boardNames(page);
    const missPair = async () => {
        const n = await names();
        const other = n.findIndex((x) => x !== n[0]);
        await page.click('.card[data-card-id="0"]');
        await page.click(`.card[data-card-id="${other}"]`);
    };

    // (a) Miss, New Game, flip within 0.9 s: the board must still be winnable.
    await page.click('#resetBtn');
    await missPair();
    await page.click('#resetBtn');
    await page.click('.card[data-card-id="3"]');
    await page.waitForTimeout(1100);
    {
        const n = await names();
        const twin = n.findIndex((x, k) => k !== 3 && x === n[3]);
        await page.click(`.card[data-card-id="${twin}"]`);
    }
    await solve(page);
    await page.waitForSelector('#winDialog[open]', { timeout: 3000 }).catch(() => {});
    check(await page.textContent('#pairsCount') === '6' && Boolean(await page.$('#winDialog[open]')), 'old miss timer cannot freeze a new board');
    await page.click('#playAgainBtn');
    // (f) Focus returns to the board after Play Again.
    check(await page.evaluate(() => document.activeElement?.matches('.card[data-card-id="0"]')), 'focus returns to the first card after Play Again');

    // (b) Two players: a miss followed by New Game keeps Player 1 on turn.
    await page.selectOption('#players', '2');
    await missPair();
    await page.waitForTimeout(1100);
    {
        const said = await page.textContent('#announcer');
        check(said.includes('No match') && said.includes("Player 2's turn"), `a miss is announced with the next player ("${said}")`);
    }
    await page.click('#resetBtn');
    await missPair();
    await page.click('#resetBtn');
    await page.waitForTimeout(1200);
    check(await page.getAttribute('.players__card--active', 'data-player') === '0', 'old miss timer cannot pass the turn on a new board');
    await page.selectOption('#players', '1');

    // (c) Win, then New Game before the dialog: no dialog and no 0-turn best.
    await page.evaluate(() => localStorage.clear());
    await solve(page);
    await page.click('#resetBtn');
    await page.waitForTimeout(900);
    const zeroBest = await page.evaluate(() => Object.keys(localStorage).filter((k) => k.includes('best')).map((k) => JSON.parse(localStorage.getItem(k)).moves));
    check(!(await page.$('#winDialog[open]')) && !zeroBest.includes(0), 'a cancelled win neither opens the dialog nor saves a 0-turn best');

    // (g) The fact toast leaves as soon as the next turn starts.
    {
        const n = await names();
        const j = n.findIndex((x, k) => k > 0 && x === n[0]);
        await page.click('.card[data-card-id="0"]');
        await page.click(`.card[data-card-id="${j}"]`);
        await page.waitForSelector('#fact:not([hidden])');
        const next = n.findIndex((x, k) => k !== 0 && k !== j);
        await page.click(`.card[data-card-id="${next}"]`);
        await page.waitForTimeout(450);
        check(await page.isHidden('#fact'), 'fact toast hides when the next turn starts');
        await page.click('#resetBtn');
    }

    // (d) A failed finish never claims the game was saved and explains why.
    forcedFailure = true;
    await page.route('**/games/*/finish', (route) => route.fulfill({ status: 500, body: '{}' }));
    await solve(page);
    await page.waitForSelector('#winDialog[open]', { timeout: 3000 });
    await page.fill('#playerName', 'Retry');
    await page.click('#submitScore');
    await page.waitForSelector('#scoreError:not([hidden])', { timeout: 3000 });
    check(await page.isHidden('#winSaved') && (await page.textContent('#scoreError')).includes('Could not reach the server'), 'failed finish shows an error and no "saved" claim');
    await page.unroute('**/games/*/finish');
    forcedFailure = false;
    await page.click('#playAgainBtn');

    // (e) A slow response for the previous grid cannot overwrite the current list.
    await page.route('**/scores?difficulty=6x6*', async (route) => {
        await new Promise((r) => setTimeout(r, 1200));
        await route.continue();
    });
    await page.selectOption('#difficulty', '6x6');
    await page.selectOption('#difficulty', '4x3');
    await page.waitForTimeout(1600);
    check((await page.textContent('#leaderboardTitle')).includes('4 × 3') && (await page.$$('.leaderboard__row')).length > 0, 'leaderboard shows the grid that is selected now');
    await page.unroute('**/scores?difficulty=6x6*');

    // Card faces fit on the densest grid at a common laptop size.
    {
        const laptop = await browser.newPage({ viewport: { width: 1366, height: 657 } });
        laptop.on('pageerror', (e) => errors.push(e.message));
        await laptop.goto(BASE);
        await laptop.click('.game__mode[data-mode="daily"]');
        await laptop.selectOption('#difficulty', '6x6');
        await solve(laptop); // faces exist only once revealed
        await laptop.waitForTimeout(700);
        const faces = await laptop.$$eval('.card', (cards) => cards.map((c) => {
            const card = c.getBoundingClientRect();
            const icon = c.querySelector('.card__icon')?.getBoundingClientRect();
            const name = c.querySelector('.card__name')?.getBoundingClientRect();
            return { fits: Boolean(icon) && icon.width <= card.width + 0.5, named: Boolean(name) && name.height > 0 };
        }));
        check(faces.every((f) => f.fits && f.named), '6 × 6 card faces fit at 1366 × 657');

        await laptop.emulateMedia({ forcedColors: 'active' });
        const border = await laptop.$eval('.card__back', (back) => getComputedStyle(back).borderTopStyle);
        check(border !== 'none', `cards keep a border under forced colours (${border})`);
        await laptop.close();
    }

    // Phone focus mode: once play starts, the whole board and the turn count fit on screen.
    for (const viewport of [{ width: 390, height: 844 }, { width: 375, height: 667 }]) {
        const small = await browser.newPage({ viewport });
        small.on('pageerror', (e) => errors.push(e.message));
        await small.goto(BASE);
        const grids = await small.$$eval('#difficulty option', (options) => options.map((o) => o.value));
        const misfits = [];
        for (const grid of grids) {
            await small.selectOption('#difficulty', grid);
            await small.click('.card[data-card-id="0"]');
            await small.waitForTimeout(150);
            const fit = await small.evaluate(() => ({
                bottom: document.getElementById('gameBoard').getBoundingClientRect().bottom,
                height: window.innerHeight,
                movesVisible: document.getElementById('moves').getBoundingClientRect().top >= 0,
            }));
            if (fit.bottom > fit.height + 0.5 || !fit.movesVisible) misfits.push(`${grid} (bottom ${Math.round(fit.bottom)} of ${fit.height})`);
            if (grid === '6x6' && viewport.height === 667) await shot(small, 'phone-focus-6x6');
            await small.click('#settingsToggle');
            await small.selectOption('#difficulty', grid); // reachable again through Settings
        }
        check(misfits.length === 0, `focus mode fits every grid at ${viewport.width}×${viewport.height}${misfits.length ? `: ${misfits.join(', ')}` : ''}`);
        await small.close();
    }

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

    await account.click('.game__mode[data-mode="daily"]');
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
    const tile = (label) => account.textContent(`.tile:has(dt:text-is("${label}")) dd`).then((t) => t.trim());
    check(await tile('Games finished') === '1' && await tile('Turns taken') === '6', 'account page shows exactly the game just played');
    await shot(account, 'account');

    await account.goto(`${BASE}/leaderboard?grid=4x3`);
    check(await account.isVisible(`.table >> text=${name}`), 'player appears in the 4 × 3 rankings');
    await account.setViewportSize({ width: 375, height: 800 });
    check(await account.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth), 'rankings with rows do not scroll sideways at 375px');
    await account.setViewportSize({ width: 1280, height: 900 });
    await shot(account, 'rankings');

    check(errors.length === 0, `no console errors${errors.length ? `: ${errors.join(' | ')}` : ''}`);
} finally {
    await browser?.close();
    server.kill();
    rmSync(workDir, { recursive: true, force: true });
}

if (failures.length) {
    console.error(`\n${failures.length} check(s) failed`);
    process.exit(1);
}
console.log('\nplaythrough passed');
