/**
 * Doctor Memory Game
 *
 * Cards, difficulty levels, the win message and the score endpoints come from
 * the #app element's data attributes (rendered by resources/views/game.blade.php
 * from config/game.php). Colours and fonts are CSS custom properties on the
 * same element. Personal bests and preferences live in localStorage; the
 * leaderboard is fetched from the server.
 *
 * Modes: "free" deals a random board; "daily" deals the same board for
 * everyone on a given date (seeded by the server's date + difficulty) and
 * has its own leaderboard.
 */

import { currentStreak, nextStreak } from './game/streak.js';
import { dealBoard, hashString, seededRandom } from './game/random.js';
import { formatTime, isBetter, nextGrid, starsFor, starTarget } from './game/scoring.js';
import { nextIndex } from './game/grid.js';
import { CONNECTIVITY_MESSAGE, dailyDateChanged, requestError } from './game/session.js';
import { cardLabel, turnMessage, winMessage } from './game/announce.js';
import { formatCountdown, msUntil } from './game/daily.js';
import { shareText as summarise, shareUrl } from './game/share.js';

const app = document.getElementById('app');

if (app) {
    const icons = JSON.parse(app.dataset.cards || '[]');
    const difficulties = JSON.parse(app.dataset.difficulties || '{}');
    let today = app.dataset.today; // the server's date at page load; updated if a new day starts
    const gameTitle = app.dataset.title || 'Memory Game';
    const gamesUrl = app.dataset.gamesUrl;
    const finishUrl = app.dataset.finishUrl;
    const accountName = app.dataset.playerName || null; // set for verified, signed-in players
    let dailyResetsAt = app.dataset.dailyResets;
    // Grids whose daily board this player has started today: replays are practice.
    const dailyPlayed = new Set(JSON.parse(app.dataset.dailyPlayed || '[]'));
    const startedThisDeal = new Set();
    const scoresUrl = app.dataset.scoresUrl;
    const storeUrl = app.dataset.storeUrl;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const STORAGE_PREFIX = 'doctor-memory';
    const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

    const el = {
        board: document.getElementById('gameBoard'),
        modes: [...document.querySelectorAll('.game__mode')],
        modeHint: document.getElementById('modeHint'),
        difficulty: document.getElementById('difficulty'),
        players: document.getElementById('players'),
        playersBar: document.getElementById('playersBar'),
        playerCards: [...document.querySelectorAll('.players__card')],
        playerScores: [document.getElementById('playerScore0'), document.getElementById('playerScore1')],
        streak: document.getElementById('streak'),
        bestStat: document.getElementById('bestStat'),
        reset: document.getElementById('resetBtn'),
        sound: document.getElementById('soundBtn'),
        pairsCount: document.getElementById('pairsCount'),
        pairsTotal: document.getElementById('pairsTotal'),
        moves: document.getElementById('moves'),
        timer: document.getElementById('timer'),
        best: document.getElementById('best'),
        leaderboard: document.getElementById('leaderboard'),
        leaderboardTitle: document.getElementById('leaderboardTitle'),
        leaderboardEmpty: document.getElementById('leaderboardEmpty'),
        leaderboardError: document.getElementById('leaderboardError'),
        dialog: document.getElementById('winDialog'),
        winStars: document.getElementById('winStars'),
        winSummary: document.getElementById('winSummary'),
        winRecord: document.getElementById('winRecord'),
        winSaved: document.getElementById('winSaved'),
        scoreLabel: document.getElementById('scoreLabel'),
        signedOut: document.getElementById('signedOutNotice'),
        dailyNotice: document.getElementById('dailyNotice'),
        practiceNotice: document.getElementById('practiceNotice'),
        unrankedNotice: document.getElementById('unrankedNotice'),
        scoreForm: document.getElementById('scoreForm'),
        playerName: document.getElementById('playerName'),
        submitScore: document.getElementById('submitScore'),
        scoreError: document.getElementById('scoreError'),
        share: document.getElementById('shareBtn'),
        playAgain: document.getElementById('playAgainBtn'),
        confetti: document.getElementById('confetti'),
        fact: document.getElementById('fact'),
        factIcon: document.getElementById('factIcon'),
        factText: document.getElementById('factText'),
        announcer: document.getElementById('announcer'),
        starMeter: document.getElementById('starMeter'),
        introLine: document.getElementById('introLine'),
        winShort: document.getElementById('winShort'),
        nextGridBtn: document.getElementById('nextGridBtn'),
        settingsToggle: document.getElementById('settingsToggle'),
    };

    const defaultDifficulty = difficulties[app.dataset.defaultDifficulty] ? app.dataset.defaultDifficulty : Object.keys(difficulties)[0];
    // A shared link (?mode=…&grid=…) wins over what this browser last used.
    const linkedGrid = difficulties[app.dataset.initialGrid] ? app.dataset.initialGrid : null;
    const linkedMode = ['daily', 'free'].includes(app.dataset.initialMode) ? app.dataset.initialMode : null;
    let difficulty = linkedGrid ?? readStorage('difficulty');
    if (!difficulties[difficulty]) difficulty = defaultDifficulty;
    let mode = linkedMode ?? (readStorage('mode') === 'daily' ? 'daily' : 'free');
    if (linkedGrid) writeStorage('difficulty', linkedGrid);
    if (linkedMode) writeStorage('mode', linkedMode);
    if ((linkedGrid || linkedMode) && window.history?.replaceState) {
        // Applied once; a reload should keep whatever the player picks next.
        window.history.replaceState(null, '', window.location.pathname);
    }
    let soundOn = readStorage('sound') !== 'off';
    let playerCount = readStorage('players') === '2' ? 2 : 1;
    let currentPlayer = 0;
    let playerScores = [0, 0];
    let gameSession = null; // promise resolving to the start response: { token, account }
    let startResult = null; // that response once it has arrived
    let finishRequest = null; // promise resolving once the server has recorded the finished game
    let savedToAccount = false;
    let finishOutcome = null; // the server's verdict on the finished game

    // Every timer belongs to one deal. A new game bumps the generation and
    // cancels them, so nothing scheduled for the old board touches the new one.
    let gameGeneration = 0;
    const timers = new Set();

    function later(fn, ms) {
        const generation = gameGeneration;
        const id = setTimeout(() => {
            timers.delete(id);
            if (generation === gameGeneration) fn();
        }, ms);
        timers.add(id);
        return id;
    }

    let cards = [];
    let flipped = [];
    let matchedPairs = 0;
    let moves = 0;
    let canFlip = true;
    let lastSubmittedId = null;
    let lastResult = null;

    // Timer: accumulated milliseconds plus the start of the current run,
    // so it can pause while the tab is hidden.
    let runStart = null;
    let accumulated = 0;
    let elapsed = 0;
    let timerHandle = null;
    let finished = false;

    // --- Storage helpers -------------------------------------------------

    function readStorage(key) {
        try {
            return localStorage.getItem(`${STORAGE_PREFIX}:${key}`);
        } catch {
            return null;
        }
    }

    function writeStorage(key, value) {
        try {
            localStorage.setItem(`${STORAGE_PREFIX}:${key}`, value);
        } catch {
            // Storage unavailable (private mode etc.) — preferences simply aren't kept.
        }
    }

    function bestKey(board = { mode, today, difficulty }) {
        return board.mode === 'daily' ? `best:daily:${board.today}:${board.difficulty}` : `best:${board.difficulty}`;
    }

    function readBest(key = bestKey()) {
        try {
            const raw = readStorage(key);
            return raw ? JSON.parse(raw) : null;
        } catch {
            return null;
        }
    }

    function level() {
        return difficulties[difficulty];
    }

    function modeLabel() {
        return mode === 'daily' ? 'Daily challenge' : 'Free play';
    }

    // --- Sound (tiny synthesized beeps, no asset files) ------------------

    let audioContext = null;

    function playTone(steps) {
        if (!soundOn) return;
        try {
            audioContext ??= new (window.AudioContext || window.webkitAudioContext)();
            if (audioContext.state === 'suspended') audioContext.resume();
            const now = audioContext.currentTime;
            steps.forEach(({ freq, at, length, type = 'sine', gain = 0.08 }) => {
                const osc = audioContext.createOscillator();
                const amp = audioContext.createGain();
                osc.type = type;
                osc.frequency.setValueAtTime(freq, now + at);
                amp.gain.setValueAtTime(0.0001, now + at);
                amp.gain.exponentialRampToValueAtTime(gain, now + at + 0.01);
                amp.gain.exponentialRampToValueAtTime(0.0001, now + at + length);
                osc.connect(amp).connect(audioContext.destination);
                osc.start(now + at);
                osc.stop(now + at + length + 0.05);
            });
        } catch {
            // No audio available — stay silent.
        }
    }

    const sounds = {
        flip: () => playTone([{ freq: 520, at: 0, length: 0.08, type: 'triangle', gain: 0.05 }]),
        match: () => playTone([
            { freq: 660, at: 0, length: 0.12 },
            { freq: 880, at: 0.1, length: 0.18 },
        ]),
        miss: () => playTone([{ freq: 220, at: 0, length: 0.2, type: 'sawtooth', gain: 0.04 }]),
        drop: () => playTone([
            { freq: 392, at: 0.25, length: 0.12, gain: 0.04 },
            { freq: 330, at: 0.37, length: 0.18, gain: 0.04 },
        ]),
        win: () => playTone([
            { freq: 523, at: 0, length: 0.15 },
            { freq: 659, at: 0.15, length: 0.15 },
            { freq: 784, at: 0.3, length: 0.15 },
            { freq: 1047, at: 0.45, length: 0.4 },
        ]),
    };

    function vibrate(pattern) {
        if (!soundOn) return;
        try {
            navigator.vibrate?.(pattern);
        } catch {
            // Not supported — ignore.
        }
    }

    function renderSoundButton() {
        el.sound.textContent = soundOn ? '🔊' : '🔇';
        el.sound.setAttribute('aria-pressed', String(soundOn));
        el.sound.setAttribute('aria-label', soundOn ? 'Sound on' : 'Sound off');
    }

    // --- Timer -----------------------------------------------------------

    function startTimer() {
        if (runStart !== null || finished) return;
        runStart = Date.now();
        timerHandle = setInterval(tick, 250);
        app.classList.remove('game--paused');
    }

    function tick() {
        const running = runStart === null ? 0 : Date.now() - runStart;
        elapsed = Math.floor((accumulated + running) / 1000);
        el.timer.textContent = formatTime(elapsed);
    }

    function pauseTimer() {
        if (runStart === null) return;
        accumulated += Date.now() - runStart;
        runStart = null;
        clearInterval(timerHandle);
        timerHandle = null;
        tick();
    }

    function stopTimer() {
        pauseTimer();
        finished = true;
        app.classList.remove('game--paused');
    }

    function resetTimer() {
        pauseTimer();
        accumulated = 0;
        elapsed = 0;
        finished = false;
        app.classList.remove('game--paused');
        el.timer.textContent = formatTime(0);
    }

    document.addEventListener('visibilitychange', () => {
        if (finished) return;
        if (document.hidden && runStart !== null) {
            pauseTimer();
            app.classList.add('game--paused');
        } else if (!document.hidden && accumulated > 0 && runStart === null) {
            startTimer();
        }
    });

    // --- Daily streak ----------------------------------------------------

    function readStreak() {
        try {
            const raw = readStorage('daily:streak');
            return raw ? JSON.parse(raw) : { last: null, count: 0 };
        } catch {
            return { last: null, count: 0 };
        }
    }

    function recordDailyWin(day) {
        writeStorage('daily:streak', JSON.stringify(nextStreak(readStreak(), day)));
    }

    function renderStreak() {
        const count = currentStreak(readStreak(), today);
        el.streak.hidden = mode !== 'daily' || count === 0;
        el.streak.textContent = count > 0 ? `🔥 ${count}-day streak` : '';
    }

    // --- Server game session --------------------------------------------

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(body),
        });
    }

    async function readJson(response) {
        return response.json().catch(() => ({}));
    }

    // Registered on the first flip so idle page loads create no rows.
    function ensureGameSession() {
        if (gameSession) return;
        const generation = gameGeneration;
        const payload = { difficulty, daily: mode === 'daily', players: playerCount };
        if (mode === 'daily') payload.date = today;

        gameSession = postJson(gamesUrl, payload).then(async (response) => {
            const body = await readJson(response);
            if (!response.ok) {
                const error = requestError(response.status, body);
                if (error.errors?.date && generation === gameGeneration) {
                    // Yesterday's board: find out today's date from the server.
                    loadLeaderboard();
                }
                throw error;
            }
            if (generation === gameGeneration) startResult = body;
            if (payload.daily && playerCount === 1) startedThisDeal.add(payload.difficulty);
            return body;
        });
        // Failures surface when the score is submitted, not mid-game.
        gameSession.catch(() => {});
    }

    /** Tell the server the game is over and how many turns it took. */
    function finishGameSession(result) {
        const session = gameSession ?? Promise.reject(requestError(0, null));
        finishRequest = session.then((start) => postJson(finishUrl.replace('__GAME__', encodeURIComponent(start.token)), {
            turns: result.moves,
            seconds: result.seconds,
        }).then(async (response) => {
            const body = await readJson(response);
            if (!response.ok) throw requestError(response.status, body);
            return {
                token: start.token,
                account: start.account ?? null,
                ranked: body.ranked !== false,
                message: body.message ?? null,
            };
        }, () => {
            throw requestError(0, null);
        }));
        finishRequest.catch(() => {});
        return finishRequest;
    }

    // --- Game state ------------------------------------------------------

    function initializeGame() {
        gameGeneration++;
        timers.forEach((id) => clearTimeout(id));
        timers.clear();

        const random = mode === 'daily' ? seededRandom(hashString(`${today}:${difficulty}`)) : Math.random;
        cards = dealBoard(icons, level().pairs, random);
        flipped = [];
        matchedPairs = 0;
        moves = 0;
        canFlip = true;
        lastSubmittedId = null;
        lastResult = null;
        leaveFocusMode();
        gameSession = null;
        startResult = null;
        finishRequest = null;
        savedToAccount = false;
        finishOutcome = null;
        if (el.winSaved) el.winSaved.hidden = true;
        // The previous deal's daily board now counts as played.
        startedThisDeal.forEach((grid) => dailyPlayed.add(grid));
        startedThisDeal.clear();
        currentPlayer = 0;
        playerScores = [0, 0];
        resetTimer();
    }

    function createCard(card) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'card';
        button.dataset.cardId = card.uniqueId;
        button.setAttribute('aria-label', cardLabel(card, card.uniqueId, boardColumns()));

        const inner = document.createElement('div');
        inner.className = 'card__inner';

        const back = document.createElement('div');
        back.className = 'card__face card__back';
        const cross = document.createElement('span');
        cross.className = 'card__cross';
        cross.setAttribute('aria-hidden', 'true');
        back.append(cross);

        // The front stays empty while the card is face down, so the board
        // cannot be read from the page (select-all, view source, extensions).
        const front = document.createElement('div');
        front.className = 'card__face card__front';

        inner.append(back, front);
        button.append(inner);

        button.tabIndex = card.uniqueId === 0 ? 0 : -1;
        button.addEventListener('click', () => handleCardClick(card.uniqueId));
        button.addEventListener('focus', () => setRovingFocus(button));
        return button;
    }

    function handleCardClick(uniqueId) {
        if (!canFlip) return;

        const card = cards.find((c) => c.uniqueId === uniqueId);
        if (!card || card.isFlipped || card.isMatched) return;

        // A new turn: the last fact has been seen, get it off the board.
        if (flipped.length === 0) hideFact();

        startTimer();
        enterFocusMode();
        ensureGameSession();
        sounds.flip();
        card.isFlipped = true;
        flipped.push(card);
        updateCard(card);

        if (flipped.length === 2) {
            canFlip = false;
            const starsBefore = level().stars ? starsFor(moves, level().stars) : 3;
            moves++;
            el.moves.textContent = moves;
            renderStarMeter();
            if (level().stars && starsFor(moves, level().stars) < starsBefore) sounds.drop();
            checkForMatch();
        }
    }

    function checkForMatch() {
        const [a, b] = flipped;

        if (a.id === b.id) {
            a.isMatched = true;
            b.isMatched = true;
            matchedPairs++;
            flipped = [];
            canFlip = true;
            updateCard(a, 'card--just-matched');
            updateCard(b, 'card--just-matched');
            el.pairsCount.textContent = matchedPairs;
            playerScores[currentPlayer]++;
            renderPlayers(); // a match keeps the turn

            showFact(a);
            announce(turnMessage({
                matched: true,
                a: a.name,
                pairsFound: matchedPairs,
                pairs: level().pairs,
                turn: moves,
                players: playerCount,
                nextPlayer: currentPlayer + 1,
                fact: a.fact ?? '',
            }));

            if (matchedPairs === level().pairs) {
                stopTimer();
                const result = snapshotResult();
                lastResult = result;
                const generation = gameGeneration;
                finishGameSession(result).then((outcome) => {
                    if (generation !== gameGeneration) return;
                    savedToAccount = Boolean(outcome.account);
                    finishOutcome = outcome;
                    if (el.dialog.open && el.winSaved) el.winSaved.hidden = !savedToAccount;
                    if (el.dialog.open && result.players === 1) renderVerdict();
                }, () => {});
                sounds.win();
                vibrate([40, 60, 40, 60, 120]);
                later(() => showWinDialog(result), 600);
            } else {
                sounds.match();
                vibrate(30);
            }
        } else {
            later(() => {
                sounds.miss();
                if (playerCount === 2) {
                    currentPlayer = 1 - currentPlayer;
                    renderPlayers();
                }
                a.isFlipped = false;
                b.isFlipped = false;
                updateCard(a, 'card--wrong');
                updateCard(b, 'card--wrong');
                flipped = [];
                canFlip = true;
                announce(turnMessage({
                    matched: false,
                    a: a.name,
                    b: b.name,
                    turn: moves,
                    players: playerCount,
                    nextPlayer: currentPlayer + 1,
                }));
                // Empty the faces once they have turned away (0.6 s flip).
                later(() => {
                    [a, b].forEach((card) => {
                        if (!card.isFlipped && !card.isMatched) {
                            clearFront(el.board.querySelector(`[data-card-id="${card.uniqueId}"]`));
                        }
                    });
                }, 650);
            }, 900);
        }
    }

    function updateCard(card, effect = null) {
        const button = el.board.querySelector(`[data-card-id="${card.uniqueId}"]`);
        if (!button) return;

        if (card.isFlipped || card.isMatched) fillFront(button, card);
        button.classList.toggle('card--flipped', card.isFlipped);
        button.setAttribute('aria-label', cardLabel(card, card.uniqueId, boardColumns()));

        if (effect) {
            button.classList.remove(effect);
            void button.offsetWidth; // restart the animation
            button.classList.add(effect);
        }

        if (card.isMatched) {
            button.setAttribute('aria-disabled', 'true');
            // Let the flip finish before fading the matched pair.
            later(() => button.classList.add('card--matched'), 600);
        }
    }

    function fillFront(button, card) {
        const front = button.querySelector('.card__front');
        if (!front || front.childElementCount) return;
        const icon = document.createElement('span');
        icon.className = 'card__icon';
        icon.textContent = card.icon;
        const name = document.createElement('span');
        name.className = 'card__name';
        name.textContent = card.name;
        front.append(icon, name);
    }

    function clearFront(button) {
        button?.querySelector('.card__front')?.replaceChildren();
    }

    function relabelCards() {
        const columns = boardColumns();
        cards.forEach((card) => {
            el.board.querySelector(`[data-card-id="${card.uniqueId}"]`)?.setAttribute('aria-label', cardLabel(card, card.uniqueId, columns));
        });
    }

    function announce(message) {
        if (!el.announcer) return;
        // Clear first so repeating the same words is still read out.
        el.announcer.textContent = '';
        later(() => {
            el.announcer.textContent = message;
        }, 50);
    }

    function setRovingFocus(active) {
        el.board.querySelectorAll('.card').forEach((card) => {
            card.tabIndex = card === active ? 0 : -1;
        });
    }

    function boardColumns() {
        // Read the live grid so the narrow-screen column count is respected.
        return getComputedStyle(el.board).gridTemplateColumns.split(' ').filter(Boolean).length || level().columns;
    }

    function handleBoardKeydown(event) {
        const current = event.target.closest('.card');
        if (!current) return;
        const buttons = [...el.board.querySelectorAll('.card')];
        const index = buttons.indexOf(current);
        const next = nextIndex(index, event.key, boardColumns(), buttons.length);
        if (next === null) return;
        event.preventDefault();
        buttons[next].focus();
    }

    let factTimer = null;

    function showFact(card) {
        if (!card.fact || !el.fact) return;
        el.factIcon.textContent = card.icon;
        el.factText.textContent = `${card.name}: ${card.fact}`;
        el.fact.hidden = false;
        el.fact.classList.remove('fact--leaving');
        clearTimeout(factTimer);
        factTimer = setTimeout(hideFact, 4500);
    }

    function hideFact() {
        if (!el.fact || el.fact.hidden) return;
        el.fact.classList.add('fact--leaving');
        clearTimeout(factTimer);
        factTimer = setTimeout(() => {
            el.fact.hidden = true;
        }, 300);
    }

    function render() {
        const { columns, columns_narrow: narrow, pairs } = level();
        el.board.style.setProperty('--columns', columns);
        el.board.style.setProperty('--columns-narrow', narrow ?? columns);
        el.board.toggleAttribute('data-dense', (narrow ?? columns) >= 5);
        hideFact();
        el.board.replaceChildren(...cards.map(createCard));
        setBoardShape();
        el.pairsCount.textContent = matchedPairs;
        el.pairsTotal.textContent = pairs;
        el.moves.textContent = moves;
        el.modes.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.mode === mode)));
        el.modeHint.hidden = mode !== 'daily';
        renderPracticeNotice();
        renderStarMeter();
        renderIntro();
        el.players.value = String(playerCount);
        el.bestStat.hidden = playerCount === 2;
        renderPlayers();
        renderStreak();
        renderBest();
    }

    /** Phones: shrink the header while playing (CSS does nothing on wide screens). */
    function enterFocusMode() {
        if (app.classList.contains('game--playing')) return;
        app.classList.add('game--playing');
        measureBoardTop();
    }

    function leaveFocusMode() {
        app.classList.remove('game--playing', 'game--toolbar-open');
        el.settingsToggle?.setAttribute('aria-expanded', 'false');
    }

    function measureBoardTop() {
        // Where the board starts with the compact header, at the top of the page.
        const top = el.board.getBoundingClientRect().top + window.scrollY;
        app.style.setProperty('--board-top', `${Math.round(top)}px`);
        if (window.scrollY > 0 && window.matchMedia?.('(max-width: 640px)').matches) {
            window.scrollTo({ top: 0 });
        }
    }

    function setBoardShape() {
        const columns = boardColumns();
        el.board.style.setProperty('--cols', columns);
        el.board.style.setProperty('--rows', Math.ceil(cards.length / columns));
    }

    function renderStarMeter() {
        if (!el.starMeter) return;
        const cuts = level().stars;
        if (!cuts || playerCount === 2) {
            el.starMeter.textContent = '';
            return;
        }
        const target = starTarget(moves, cuts);
        el.starMeter.textContent = target
            ? `${'⭐'.repeat(target.stars)} ${target.turnsLeft === 0 ? 'last turn' : `${target.turnsLeft} left`}`
            : '⭐';
    }

    function renderIntro() {
        if (el.introLine) el.introLine.hidden = readStorage('intro-seen') === '1';
    }

    function renderPracticeNotice() {
        if (!el.practiceNotice) return;
        const practice = mode === 'daily' && playerCount === 1 && dailyPlayed.has(difficulty);
        el.practiceNotice.hidden = !practice;
        if (practice) {
            const wait = formatCountdown(msUntil(dailyResetsAt, Date.now()));
            el.practiceNotice.textContent = `You've played today's ${level().label}. Replays are practice. Next board in ${wait}.`;
        }
    }

    function renderPlayers() {
        el.playersBar.hidden = playerCount !== 2;
        el.playerCards.forEach((card, index) => {
            card.classList.toggle('players__card--active', playerCount === 2 && index === currentPlayer && !finished);
        });
        el.playerScores.forEach((cell, index) => {
            cell.textContent = playerScores[index];
        });
    }

    function renderBest() {
        const best = readBest();
        el.best.textContent = best ? `${best.moves} turns · ${formatTime(best.seconds)}` : '—';
    }

    function newGame() {
        const active = document.activeElement;
        const restoreFocus = el.dialog.open || el.board.contains(active) || active === document.body || active === null;
        if (el.dialog.open) el.dialog.close();
        if (el.dailyNotice) el.dailyNotice.hidden = true;
        initializeGame();
        render();
        if (restoreFocus) el.board.querySelector('.card[tabindex="0"]')?.focus();
    }

    /** Everything the end-of-game screens need, frozen at the moment of the win. */
    function snapshotResult() {
        return {
            moves,
            seconds: Math.max(elapsed, 1),
            stars: starsFor(moves, level().stars ?? level().pairs),
            cuts: level().stars ?? null,
            difficulty,
            label: level().label,
            pairs: level().pairs,
            mode,
            today,
            players: playerCount,
            playerScores: [...playerScores],
        };
    }

    // --- Win dialog, confetti, share ------------------------------------

    function showWinDialog(result) {
        leaveFocusMode();
        if (result.players === 2) {
            showTwoPlayerResult(result);
            return;
        }

        const key = bestKey(result);
        // A real game takes at least one turn per pair; never store less.
        const record = result.moves >= result.pairs && isBetter(result, readBest(key));
        if (record) {
            writeStorage(key, JSON.stringify({ moves: result.moves, seconds: result.seconds, stars: result.stars }));
            renderBest();
        }
        if (result.mode === 'daily') {
            recordDailyWin(result.today);
            renderStreak();
        }

        el.winStars.replaceChildren(
            ...[1, 2, 3].map((n) => {
                const star = document.createElement('span');
                star.textContent = '⭐';
                star.className = n <= result.stars ? 'star--on' : 'star--off';
                return star;
            }),
        );
        el.winStars.setAttribute('aria-label', `${result.stars} of 3 stars`);
        el.winSummary.textContent = `${result.mode === 'daily' ? 'Daily challenge' : 'Free play'} · ${result.label} · ${result.moves} turns in ${formatTime(result.seconds)}`;
        el.winRecord.hidden = !record;
        writeStorage('intro-seen', '1');
        renderIntro();
        const short = result.cuts && result.stars < 3 ? result.moves - result.cuts[3] : null;
        if (el.winShort) {
            el.winShort.hidden = !(short !== null && short <= 2);
            el.winShort.textContent = short !== null ? `${short} turn${short === 1 ? '' : 's'} short of 3 stars` : '';
        }
        const upNext = result.stars === 3 && result.mode === 'free' ? nextGrid(Object.keys(difficulties), result.difficulty) : null;
        if (el.nextGridBtn) {
            el.nextGridBtn.hidden = !upNext;
            el.nextGridBtn.dataset.grid = upNext ?? '';
            el.nextGridBtn.textContent = upNext ? `Try ${difficulties[upNext].label}` : '';
        }
        if (el.winSaved) el.winSaved.hidden = !savedToAccount;
        el.scoreError.hidden = true;
        el.scoreForm.hidden = false;
        el.submitScore.disabled = false;
        el.share.textContent = 'Share result';
        renderScoreForm();
        renderVerdict();

        openDialog();
        announce(winMessage(result));
        (el.playerName.hidden ? el.submitScore : el.playerName).focus();
        launchConfetti();
    }

    /** Practice and held games cannot go on the top scores: say why instead. */
    function renderVerdict() {
        const unranked = finishOutcome && !finishOutcome.ranked;
        if (el.unrankedNotice) {
            el.unrankedNotice.hidden = !unranked;
            el.unrankedNotice.textContent = unranked ? finishOutcome.message ?? '' : '';
        }
        if (unranked) el.scoreForm.hidden = true;
    }

    /**
     * The page was rendered for a signed-in player, but the server started
     * this game as a guest: the session has ended since the page loaded.
     */
    function signedOutSinceLoad() {
        return Boolean(accountName) && startResult !== null && !startResult.account;
    }

    function renderScoreForm({ prefill = true } = {}) {
        const asAccount = Boolean(accountName) && !signedOutSinceLoad();
        el.playerName.hidden = asAccount;
        el.playerName.required = !asAccount;
        if (prefill) el.playerName.value = asAccount ? accountName : (accountName ?? (readStorage('player') || ''));
        if (el.scoreLabel) {
            el.scoreLabel.textContent = asAccount
                ? `Add this game to the top scores as ${accountName}`
                : 'Add your score to the top scores';
        }
        if (el.signedOut) el.signedOut.hidden = asAccount || !accountName;
    }

    function openDialog() {
        if (typeof el.dialog.showModal === 'function') {
            el.dialog.showModal();
        } else {
            el.dialog.setAttribute('open', '');
        }
    }

    function showTwoPlayerResult(result) {
        const [p1, p2] = result.playerScores;
        const winner = p1 === p2 ? null : p1 > p2 ? 1 : 2;
        renderPlayers();

        el.winStars.replaceChildren();
        el.winStars.setAttribute('aria-label', '');
        el.winSummary.textContent = `${winner ? `Player ${winner} wins!` : "It's a tie!"} ${p1} – ${p2} pairs · ${result.label} · ${result.moves} turns in ${formatTime(result.seconds)}`;
        el.winRecord.hidden = true;
        if (el.winSaved) el.winSaved.hidden = true;
        if (el.signedOut) el.signedOut.hidden = true;
        if (el.unrankedNotice) el.unrankedNotice.hidden = true;
        if (el.winShort) el.winShort.hidden = true;
        if (el.nextGridBtn) el.nextGridBtn.hidden = true;
        el.scoreForm.hidden = true;
        el.scoreError.hidden = true;
        el.share.textContent = 'Share result';

        openDialog();
        announce(winMessage(result));
        el.playAgain.focus();
        launchConfetti();
    }

    function launchConfetti() {
        if (reducedMotion || !el.confetti) return;
        const palette = ['#f43f5e', '#f59e0b', '#10b981', '#3b82f6', '#a855f7', '#ffffff'];
        const pieces = Array.from({ length: 80 }, () => {
            const piece = document.createElement('span');
            piece.className = 'confetti__piece';
            piece.style.left = `${Math.random() * 100}vw`;
            piece.style.backgroundColor = palette[Math.floor(Math.random() * palette.length)];
            piece.style.setProperty('--duration', `${2.5 + Math.random() * 2}s`);
            piece.style.setProperty('--delay', `${Math.random() * 0.8}s`);
            piece.style.setProperty('--spin', `${(Math.random() > 0.5 ? 1 : -1) * (360 + Math.random() * 720)}deg`);
            piece.style.transform = `scale(${0.6 + Math.random() * 0.8})`;
            return piece;
        });
        el.confetti.replaceChildren(...pieces);
        setTimeout(() => el.confetti.replaceChildren(), 6000);
    }

    function shareText() {
        const result = lastResult;
        const url = shareUrl(window.location.origin, { mode: result.mode, grid: result.difficulty });
        return summarise(result, { title: gameTitle, url });
    }

    async function shareResult() {
        if (!lastResult) return;
        const text = shareText();
        try {
            if (navigator.share) {
                await navigator.share({ text });
                return;
            }
            await navigator.clipboard.writeText(text);
            el.share.textContent = 'Copied!';
        } catch {
            // User cancelled the share sheet, or clipboard unavailable.
            el.share.textContent = 'Share result';
        }
    }

    // --- Scores ----------------------------------------------------------

    /** The recorded game, retrying the finish once if it failed for a passing reason. */
    async function recordedGame() {
        if (!finishRequest) throw requestError(0, null);
        try {
            return await finishRequest;
        } catch (error) {
            // Retrying only makes sense if the server saw the game start;
            // a fresh start would record a time of a few seconds.
            if (error.retryable && startResult && lastResult) {
                return finishGameSession(lastResult);
            }
            throw error;
        }
    }

    async function submitScore(event) {
        event.preventDefault();
        el.scoreError.hidden = true;
        el.submitScore.disabled = true;

        try {
            const game = await recordedGame();
            renderScoreForm({ prefill: false }); // the start response may have arrived since the dialog opened
            const asAccount = Boolean(game.account);
            const name = el.playerName.value.trim();
            if (!asAccount && !name) {
                el.playerName.hidden = false;
                el.playerName.focus();
                throw new Error('Enter a name for the top scores.');
            }

            const response = await postJson(storeUrl, { game: game.token, player_name: asAccount ? game.account : name });
            const body = await readJson(response);
            if (!response.ok) throw requestError(response.status, body);

            if (!asAccount) writeStorage('player', name);
            lastSubmittedId = body.data?.id ?? null;
            el.scoreForm.hidden = true;
            if (body.improved === false) {
                el.winSummary.textContent += body.rank ? ` · your best here stays #${body.rank}` : '';
            } else {
                el.winSummary.textContent += body.rank ? ` · ranked #${body.rank}` : '';
            }
            await loadLeaderboard();
            el.playAgain.focus();
        } catch (error) {
            el.scoreError.textContent = error.message || CONNECTIVITY_MESSAGE;
            el.scoreError.hidden = false;
            el.submitScore.disabled = false;
        }
    }

    let leaderboardSeq = 0;

    /** The server says a different day has started than the one this page was dealt for. */
    function adoptServerDate(playedOn) {
        if (!dailyDateChanged(today, playedOn)) return;
        today = playedOn;
        dailyPlayed.clear();
        startedThisDeal.clear();
        const next = new Date(`${playedOn}T00:00:00Z`);
        next.setUTCDate(next.getUTCDate() + 1);
        dailyResetsAt = next.toISOString(); // approximate until the next page load
        if (mode !== 'daily') return;
        if (moves === 0 && flipped.length === 0) {
            newGame(); // nothing played yet: deal today's board straight away
        } else if (el.dailyNotice) {
            el.dailyNotice.hidden = false;
        }
    }

    async function loadLeaderboard() {
        const seq = ++leaderboardSeq;
        el.leaderboardTitle.textContent = mode === 'daily'
            ? `Today's top scores · ${level().label}`
            : `Top scores · ${level().label}`;
        el.leaderboardError.hidden = true;
        el.leaderboardEmpty.hidden = true;

        const params = new URLSearchParams({ difficulty });
        if (mode === 'daily') params.set('daily', '1');

        try {
            const response = await fetch(`${scoresUrl}?${params}`, { headers: { Accept: 'application/json' } });
            if (seq !== leaderboardSeq) return;
            if (!response.ok) throw new Error('bad response');
            const { data, played_on: playedOn } = await response.json();
            if (seq !== leaderboardSeq) return;
            renderLeaderboard(data);
            if (mode === 'daily') adoptServerDate(playedOn);
        } catch {
            if (seq !== leaderboardSeq) return;
            el.leaderboard.replaceChildren();
            el.leaderboardError.hidden = false;
        }
    }

    function renderLeaderboard(rows) {
        el.leaderboard.replaceChildren(
            ...rows.map((row, index) => {
                const li = document.createElement('li');
                li.className = 'leaderboard__row';
                if (row.id === lastSubmittedId) li.classList.add('leaderboard__row--mine');

                const rank = document.createElement('span');
                rank.className = 'leaderboard__rank';
                rank.textContent = `${index + 1}.`;

                const name = document.createElement('span');
                name.className = 'leaderboard__name';
                name.textContent = row.player_name;
                if (row.registered) {
                    const badge = document.createElement('span');
                    badge.className = 'leaderboard__badge';
                    badge.textContent = '✓';
                    badge.title = 'Registered player';
                    badge.setAttribute('aria-label', 'registered player');
                    name.append(' ', badge);
                }

                const movesCell = document.createElement('span');
                movesCell.textContent = `${row.moves} turns`;

                const time = document.createElement('span');
                time.textContent = formatTime(row.seconds);

                li.append(rank, name, movesCell, time);
                return li;
            }),
        );
        el.leaderboardEmpty.hidden = rows.length > 0;
    }

    // --- Wiring ----------------------------------------------------------

    el.difficulty.value = difficulty;
    el.difficulty.addEventListener('change', () => {
        difficulty = el.difficulty.value;
        writeStorage('difficulty', difficulty);
        newGame();
        loadLeaderboard();
    });

    el.modes.forEach((button) => {
        button.addEventListener('click', () => {
            if (button.dataset.mode === mode) return;
            mode = button.dataset.mode;
            writeStorage('mode', mode);
            newGame();
            loadLeaderboard();
        });
    });

    el.players.addEventListener('change', () => {
        playerCount = el.players.value === '2' ? 2 : 1;
        writeStorage('players', String(playerCount));
        newGame();
    });

    el.sound.addEventListener('click', () => {
        soundOn = !soundOn;
        writeStorage('sound', soundOn ? 'on' : 'off');
        renderSoundButton();
        if (soundOn) sounds.flip();
    });

    el.board.addEventListener('keydown', handleBoardKeydown);
    window.matchMedia?.('(max-width: 640px)').addEventListener?.('change', () => {
        setBoardShape();
        relabelCards();
        if (app.classList.contains('game--playing')) measureBoardTop();
    });

    el.settingsToggle?.addEventListener('click', () => {
        const open = app.classList.toggle('game--toolbar-open');
        el.settingsToggle.setAttribute('aria-expanded', String(open));
        measureBoardTop();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !el.dialog.open && app.classList.contains('game--toolbar-open')) {
            app.classList.remove('game--toolbar-open');
            el.settingsToggle?.setAttribute('aria-expanded', 'false');
            measureBoardTop();
            el.settingsToggle?.focus();
        }
    });
    el.reset.addEventListener('click', newGame);
    el.playAgain.addEventListener('click', newGame);
    el.nextGridBtn?.addEventListener('click', () => {
        const grid = el.nextGridBtn.dataset.grid;
        if (!difficulties[grid]) return;
        difficulty = grid;
        el.difficulty.value = grid;
        writeStorage('difficulty', grid);
        newGame();
        loadLeaderboard();
    });
    el.share.addEventListener('click', shareResult);
    el.scoreForm.addEventListener('submit', submitScore);
    el.dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        newGame();
    });

    // Keep the countdown current, and pick up the new board once it is due.
    setInterval(() => {
        renderPracticeNotice();
        if (mode === 'daily' && msUntil(dailyResetsAt, Date.now()) === 0) loadLeaderboard();
    }, 60000);

    renderSoundButton();
    initializeGame();
    render();
    loadLeaderboard();
}
