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
import { formatTime, isBetter, starsFor } from './game/scoring.js';
import { nextIndex } from './game/grid.js';

const app = document.getElementById('app');

if (app) {
    const icons = JSON.parse(app.dataset.cards || '[]');
    const difficulties = JSON.parse(app.dataset.difficulties || '{}');
    const today = app.dataset.today;
    const gameTitle = app.dataset.title || 'Memory Game';
    const gamesUrl = app.dataset.gamesUrl;
    const finishUrl = app.dataset.finishUrl;
    const accountName = app.dataset.playerName || null; // set for verified, signed-in players
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
    };

    const defaultDifficulty = difficulties[app.dataset.defaultDifficulty] ? app.dataset.defaultDifficulty : Object.keys(difficulties)[0];
    let difficulty = readStorage('difficulty');
    if (!difficulties[difficulty]) difficulty = defaultDifficulty;
    let mode = readStorage('mode') === 'daily' ? 'daily' : 'free';
    let soundOn = readStorage('sound') !== 'off';
    let playerCount = readStorage('players') === '2' ? 2 : 1;
    let currentPlayer = 0;
    let playerScores = [0, 0];
    let gameSession = null; // promise resolving to the server's game token
    let finishRequest = null; // promise resolving once the server has recorded the finished game

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

    function bestKey() {
        return mode === 'daily' ? `best:daily:${today}:${difficulty}` : `best:${difficulty}`;
    }

    function readBest() {
        try {
            const raw = readStorage(bestKey());
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

    function recordDailyWin() {
        writeStorage('daily:streak', JSON.stringify(nextStreak(readStreak(), today)));
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

    // Registered on the first flip so idle page loads create no rows.
    function ensureGameSession() {
        if (gameSession) return;
        gameSession = postJson(gamesUrl, { difficulty, daily: mode === 'daily', players: playerCount })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error('start failed'))))
            .then((body) => body.token);
        // Failures surface when the score is submitted, not mid-game.
        gameSession.catch(() => {});
    }

    /** Tell the server the game is over and how many turns it took. */
    function finishGameSession() {
        const session = gameSession;
        if (!session) return Promise.reject(new Error('no session'));
        finishRequest = session
            .then((token) => postJson(finishUrl.replace('__GAME__', encodeURIComponent(token)), {
                turns: moves,
                seconds: Math.max(elapsed, 1),
            }).then((response) => (response.ok ? token : Promise.reject(new Error('finish failed')))));
        finishRequest.catch(() => {});
        return finishRequest;
    }

    // --- Game state ------------------------------------------------------

    function initializeGame() {
        const random = mode === 'daily' ? seededRandom(hashString(`${today}:${difficulty}`)) : Math.random;
        cards = dealBoard(icons, level().pairs, random);
        flipped = [];
        matchedPairs = 0;
        moves = 0;
        canFlip = true;
        lastSubmittedId = null;
        lastResult = null;
        gameSession = null;
        finishRequest = null;
        currentPlayer = 0;
        playerScores = [0, 0];
        resetTimer();
    }

    function createCard(card) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'card';
        button.dataset.cardId = card.uniqueId;
        button.setAttribute('aria-label', 'Hidden card');

        const inner = document.createElement('div');
        inner.className = 'card__inner';

        const back = document.createElement('div');
        back.className = 'card__face card__back';
        const cross = document.createElement('span');
        cross.className = 'card__cross';
        cross.setAttribute('aria-hidden', 'true');
        back.append(cross);

        const front = document.createElement('div');
        front.className = 'card__face card__front';

        const icon = document.createElement('span');
        icon.className = 'card__icon';
        icon.textContent = card.icon;

        const name = document.createElement('span');
        name.className = 'card__name';
        name.textContent = card.name;

        front.append(icon, name);
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

        startTimer();
        ensureGameSession();
        sounds.flip();
        card.isFlipped = true;
        flipped.push(card);
        updateCard(card);

        if (flipped.length === 2) {
            canFlip = false;
            moves++;
            el.moves.textContent = moves;
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

            if (matchedPairs === level().pairs) {
                stopTimer();
                finishGameSession().then(
                    () => {
                        if (accountName && el.winSaved) el.winSaved.hidden = false;
                    },
                    () => {},
                );
                sounds.win();
                vibrate([40, 60, 40, 60, 120]);
                setTimeout(showWinDialog, 600);
            } else {
                sounds.match();
                vibrate(30);
            }
        } else {
            setTimeout(() => {
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
            }, 900);
        }
    }

    function updateCard(card, effect = null) {
        const button = el.board.querySelector(`[data-card-id="${card.uniqueId}"]`);
        if (!button) return;

        button.classList.toggle('card--flipped', card.isFlipped);
        button.setAttribute('aria-label', card.isFlipped || card.isMatched ? card.name : 'Hidden card');

        if (effect) {
            button.classList.remove(effect);
            void button.offsetWidth; // restart the animation
            button.classList.add(effect);
        }

        if (card.isMatched) {
            button.setAttribute('aria-disabled', 'true');
            // Let the flip finish before fading the matched pair.
            setTimeout(() => button.classList.add('card--matched'), 600);
        }
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
        el.board.style.setProperty('--rows', Math.ceil((pairs * 2) / columns));
        el.board.toggleAttribute('data-dense', (narrow ?? columns) >= 5);
        hideFact();
        el.board.replaceChildren(...cards.map(createCard));
        el.pairsCount.textContent = matchedPairs;
        el.pairsTotal.textContent = pairs;
        el.moves.textContent = moves;
        el.modes.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.mode === mode)));
        el.modeHint.hidden = mode !== 'daily';
        el.players.value = String(playerCount);
        el.bestStat.hidden = playerCount === 2;
        renderPlayers();
        renderStreak();
        renderBest();
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
        if (el.dialog.open) el.dialog.close();
        initializeGame();
        render();
    }

    // --- Win dialog, confetti, share ------------------------------------

    function showWinDialog() {
        const result = { moves, seconds: Math.max(elapsed, 1), stars: starsFor(moves, level().pairs) };
        lastResult = result;

        if (playerCount === 2) {
            showTwoPlayerResult(result);
            return;
        }

        const record = isBetter(result, readBest());
        if (record) {
            writeStorage(bestKey(), JSON.stringify(result));
            renderBest();
        }
        if (mode === 'daily') {
            recordDailyWin();
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
        el.winSummary.textContent = `${modeLabel()} · ${level().label} · ${moves} turns in ${formatTime(result.seconds)}`;
        el.winRecord.hidden = !record;
        if (el.winSaved) el.winSaved.hidden = !(accountName && finishRequest);
        el.scoreError.hidden = true;
        el.scoreForm.hidden = false;
        el.submitScore.disabled = false;
        el.share.textContent = 'Share result';
        el.playerName.value = accountName ?? (readStorage('player') || '');

        openDialog();
        (accountName ? el.submitScore : el.playerName).focus();
        launchConfetti();
    }

    function openDialog() {
        if (typeof el.dialog.showModal === 'function') {
            el.dialog.showModal();
        } else {
            el.dialog.setAttribute('open', '');
        }
    }

    function showTwoPlayerResult(result) {
        const [p1, p2] = playerScores;
        const winner = p1 === p2 ? null : p1 > p2 ? 1 : 2;
        renderPlayers();

        el.winStars.replaceChildren();
        el.winStars.setAttribute('aria-label', '');
        el.winSummary.textContent = `${winner ? `Player ${winner} wins!` : "It's a tie!"} ${p1} – ${p2} pairs · ${level().label} · ${moves} turns in ${formatTime(result.seconds)}`;
        el.winRecord.hidden = true;
        el.scoreForm.hidden = true;
        el.scoreError.hidden = true;
        el.share.textContent = 'Share result';

        openDialog();
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
        const board = mode === 'daily' ? `Daily challenge ${today}` : 'Free play';
        if (playerCount === 2) {
            const [p1, p2] = playerScores;
            const outcome = p1 === p2 ? "It's a tie" : `Player ${p1 > p2 ? 1 : 2} wins`;
            return `${gameTitle} · 2 players · ${level().label}\n${outcome} ${p1} – ${p2}\n${window.location.href}`;
        }
        const stars = '⭐'.repeat(lastResult.stars) + '☆'.repeat(3 - lastResult.stars);
        return `${gameTitle} · ${board} · ${level().label}\n${stars} ${lastResult.moves} turns in ${formatTime(lastResult.seconds)}\n${window.location.href}`;
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

    async function submitScore(event) {
        event.preventDefault();
        el.scoreError.hidden = true;
        el.submitScore.disabled = true;

        try {
            let token = null;
            try {
                token = await (finishRequest ?? Promise.reject(new Error('not finished')));
            } catch {
                token = null;
            }
            if (!token) {
                throw new Error('Could not reach the server to record this game, so the score cannot be saved.');
            }

            const payload = { game: token, player_name: accountName ?? el.playerName.value.trim() };
            const response = await postJson(storeUrl, payload);

            const body = await response.json().catch(() => ({}));

            if (!response.ok) {
                const first = body.errors ? Object.values(body.errors).flat()[0] : null;
                throw new Error(first || body.message || 'Could not save your score.');
            }

            if (!accountName) writeStorage('player', payload.player_name);
            lastSubmittedId = body.data?.id ?? null;
            el.scoreForm.hidden = true;
            el.winSummary.textContent += body.rank ? ` · ranked #${body.rank}` : '';
            await loadLeaderboard();
            el.playAgain.focus();
        } catch (error) {
            el.scoreError.textContent = error.message;
            el.scoreError.hidden = false;
            el.submitScore.disabled = false;
        }
    }

    async function loadLeaderboard() {
        el.leaderboardTitle.textContent = mode === 'daily'
            ? `Today's top scores · ${level().label}`
            : `Top scores · ${level().label}`;
        el.leaderboardError.hidden = true;
        el.leaderboardEmpty.hidden = true;

        const params = new URLSearchParams({ difficulty });
        if (mode === 'daily') params.set('daily', '1');

        try {
            const response = await fetch(`${scoresUrl}?${params}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('bad response');
            const { data } = await response.json();
            renderLeaderboard(data);
        } catch {
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
    el.reset.addEventListener('click', newGame);
    el.playAgain.addEventListener('click', newGame);
    el.share.addEventListener('click', shareResult);
    el.scoreForm.addEventListener('submit', submitScore);
    el.dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        newGame();
    });

    renderSoundButton();
    initializeGame();
    render();
    loadLeaderboard();
}
