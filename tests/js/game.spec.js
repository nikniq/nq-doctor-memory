import { describe, expect, it } from 'vitest';
import { dealBoard, hashString, seededRandom, shuffle } from '../../resources/js/game/random.js';
import { formatTime, isBetter, nextGrid, starsFor, starTarget } from '../../resources/js/game/scoring.js';
import { currentStreak, nextStreak, yesterdayOf } from '../../resources/js/game/streak.js';
import { nextIndex } from '../../resources/js/game/grid.js';
import { formatCountdown, msUntil } from '../../resources/js/game/daily.js';
import { shareText, shareUrl } from '../../resources/js/game/share.js';
import { cardLabel, positionOf, turnMessage, winMessage } from '../../resources/js/game/announce.js';
import { CONNECTIVITY_MESSAGE, dailyDateChanged, firstErrorMessage, requestError } from '../../resources/js/game/session.js';

const icons = Array.from({ length: 12 }, (_, i) => ({ id: i + 1, icon: '•', name: `Card ${i + 1}` }));

describe('dealing', () => {
    it('deals each chosen icon exactly twice with unique ids', () => {
        const board = dealBoard(icons, 6);
        expect(board).toHaveLength(12);
        expect(new Set(board.map((c) => c.uniqueId)).size).toBe(12);
        const counts = Object.values(Object.groupBy(board, (c) => c.id)).map((g) => g.length);
        expect(counts).toEqual(Array(6).fill(2));
        expect(board.every((c) => !c.isFlipped && !c.isMatched)).toBe(true);
    });

    it('is reproducible for the same seed and different for another', () => {
        const seed = hashString('2026-10-16:hard');
        const a = dealBoard(icons, 12, seededRandom(seed)).map((c) => c.id);
        const b = dealBoard(icons, 12, seededRandom(seed)).map((c) => c.id);
        const c = dealBoard(icons, 12, seededRandom(hashString('2026-10-17:hard'))).map((x) => x.id);
        expect(a).toEqual(b);
        expect(a).not.toEqual(c);
    });

    it('shuffle keeps every element and does not mutate the input', () => {
        const input = [1, 2, 3, 4, 5];
        const out = shuffle(input, seededRandom(1));
        expect(input).toEqual([1, 2, 3, 4, 5]);
        expect([...out].sort()).toEqual(input);
    });

    it('seeded random stays in [0, 1)', () => {
        const random = seededRandom(42);
        for (let i = 0; i < 1000; i++) {
            const n = random();
            expect(n).toBeGreaterThanOrEqual(0);
            expect(n).toBeLessThan(1);
        }
    });
});

describe('scoring', () => {
    it('formats times', () => {
        expect(formatTime(0)).toBe('0:00');
        expect(formatTime(5)).toBe('0:05');
        expect(formatTime(90)).toBe('1:30');
        expect(formatTime(720)).toBe('12:00');
    });

    it('rates moves against the pair count', () => {
        expect(starsFor(8, 8)).toBe(3);
        expect(starsFor(12, 8)).toBe(3);
        expect(starsFor(13, 8)).toBe(2);
        expect(starsFor(18, 8)).toBe(2);
        expect(starsFor(19, 8)).toBe(1);
    });

    it('prefers fewer moves, then faster time', () => {
        expect(isBetter({ moves: 10, seconds: 50 }, null)).toBe(true);
        expect(isBetter({ moves: 9, seconds: 99 }, { moves: 10, seconds: 5 })).toBe(true);
        expect(isBetter({ moves: 10, seconds: 4 }, { moves: 10, seconds: 5 })).toBe(true);
        expect(isBetter({ moves: 10, seconds: 5 }, { moves: 10, seconds: 5 })).toBe(false);
        expect(isBetter({ moves: 11, seconds: 1 }, { moves: 10, seconds: 5 })).toBe(false);
    });
});

describe('daily streak', () => {
    it('knows yesterday across month and year boundaries', () => {
        expect(yesterdayOf('2026-10-16')).toBe('2026-10-15');
        expect(yesterdayOf('2026-03-01')).toBe('2026-02-28');
        expect(yesterdayOf('2027-01-01')).toBe('2026-12-31');
    });

    it('starts, extends, holds and resets', () => {
        let streak = nextStreak(null, '2026-10-14');
        expect(streak).toEqual({ last: '2026-10-14', count: 1 });
        streak = nextStreak(streak, '2026-10-15');
        expect(streak.count).toBe(2);
        expect(nextStreak(streak, '2026-10-15')).toBe(streak);
        expect(nextStreak(streak, '2026-10-17')).toEqual({ last: '2026-10-17', count: 1 });
    });

    it('reports a streak only while it is alive', () => {
        const streak = { last: '2026-10-15', count: 4 };
        expect(currentStreak(streak, '2026-10-15')).toBe(4);
        expect(currentStreak(streak, '2026-10-16')).toBe(4);
        expect(currentStreak(streak, '2026-10-17')).toBe(0);
        expect(currentStreak(null, '2026-10-17')).toBe(0);
    });
});

describe('grid navigation', () => {
    // 4 columns, 10 cells: last row is partial (8, 9).
    it('moves in four directions and stops at edges', () => {
        expect(nextIndex(0, 'ArrowRight', 4, 10)).toBe(1);
        expect(nextIndex(9, 'ArrowRight', 4, 10)).toBe(9);
        expect(nextIndex(0, 'ArrowLeft', 4, 10)).toBe(0);
        expect(nextIndex(1, 'ArrowDown', 4, 10)).toBe(5);
        expect(nextIndex(6, 'ArrowDown', 4, 10)).toBe(6);
        expect(nextIndex(5, 'ArrowUp', 4, 10)).toBe(1);
        expect(nextIndex(2, 'ArrowUp', 4, 10)).toBe(2);
    });

    it('jumps to the ends and ignores other keys', () => {
        expect(nextIndex(5, 'Home', 4, 10)).toBe(0);
        expect(nextIndex(5, 'End', 4, 10)).toBe(9);
        expect(nextIndex(5, 'Enter', 4, 10)).toBeNull();
    });
});

describe('game session helpers', () => {
    it('notices only a real change of daily date', () => {
        expect(dailyDateChanged('2026-10-22', '2026-10-22')).toBe(false);
        expect(dailyDateChanged('2026-10-22', '2026-10-23')).toBe(true);
        expect(dailyDateChanged('2026-10-22', null)).toBe(false);
    });

    it('reads the first validation message', () => {
        expect(firstErrorMessage({ errors: { turns: ['Turns must be at least 8.'] } })).toBe('Turns must be at least 8.');
        expect(firstErrorMessage({ message: 'Too Many Attempts.' })).toBe('Too Many Attempts.');
        expect(firstErrorMessage(null)).toBeNull();
    });

    it('keeps the server explanation for 422 and marks other failures retryable', () => {
        const rejected = requestError(422, { errors: { date: ['A new daily challenge has started. Reload to play it.'] } });
        expect(rejected.message).toBe('A new daily challenge has started. Reload to play it.');
        expect(rejected.retryable).toBe(false);
        expect(rejected.errors.date).toBeDefined();

        const outage = requestError(500, {});
        expect(outage.message).toBe(CONNECTIVITY_MESSAGE);
        expect(outage.retryable).toBe(true);
    });
});

describe('screen-reader text', () => {
    it('locates cards by row and column at any width', () => {
        expect(positionOf(0, 4)).toEqual({ row: 1, col: 1 });
        expect(positionOf(5, 3)).toEqual({ row: 2, col: 3 });
        expect(positionOf(9, 5)).toEqual({ row: 2, col: 5 });
        expect(positionOf(35, 6)).toEqual({ row: 6, col: 6 });
    });

    it('labels a card in each state', () => {
        const card = { name: 'Stethoscope', isFlipped: false, isMatched: false };
        expect(cardLabel(card, 6, 4)).toBe('Row 2, column 3, face down');
        expect(cardLabel({ ...card, isFlipped: true }, 6, 4)).toBe('Row 2, column 3, Stethoscope');
        expect(cardLabel({ ...card, isMatched: true }, 6, 4)).toBe('Row 2, column 3, Stethoscope, matched');
    });

    it('describes misses, matches and whose turn it is', () => {
        expect(turnMessage({ matched: false, a: 'Pills', b: 'Syringe', turn: 6 })).toBe('No match: Pills and Syringe. Turn 6.');
        expect(turnMessage({ matched: false, a: 'Pills', b: 'Syringe', turn: 6, players: 2, nextPlayer: 2 }))
            .toBe("No match: Pills and Syringe. Turn 6. Player 2's turn.");
        expect(turnMessage({ matched: true, a: 'Heart', pairsFound: 3, pairs: 8, turn: 5, fact: 'It beats a lot.' }))
            .toBe('Match: Heart. 3 of 8 pairs. Turn 5. It beats a lot.');
    });

    it('sums up a finished game', () => {
        expect(winMessage({ players: 1, moves: 10, stars: 3 })).toBe('All pairs found in 10 turns. 3 of 3 stars.');
        expect(winMessage({ players: 2, playerScores: [4, 2] })).toBe('All pairs found. Player 1 wins, 4 to 2.');
        expect(winMessage({ players: 2, playerScores: [3, 3] })).toBe("All pairs found. It's a tie, 3 to 3.");
    });
});

describe('daily countdown', () => {
    it('measures the time until the next board and never goes negative', () => {
        const now = Date.parse('2026-10-23T18:48:00Z');
        expect(msUntil('2026-10-24T00:00:00+00:00', now)).toBe(5 * 3600000 + 12 * 60000);
        expect(msUntil('2026-10-23T00:00:00Z', now)).toBe(0);
        expect(msUntil('not a date', now)).toBe(0);
    });

    it('reads naturally', () => {
        expect(formatCountdown(5 * 3600000 + 12 * 60000)).toBe('5h 12m');
        expect(formatCountdown(12 * 60000 + 59000)).toBe('12m');
        expect(formatCountdown(30000)).toBe('under a minute');
    });
});

describe('star targets', () => {
    const grids = {
        '4x3': { 3: 10, 2: 13 },
        '4x4': { 3: 13, 2: 18 },
        '5x4': { 3: 16, 2: 22 },
        '6x4': { 3: 19, 2: 27 },
        '6x5': { 3: 24, 2: 33 },
        '6x6': { 3: 29, 2: 40 },
    };

    it.each(Object.entries(grids))('awards stars at the %s boundaries', (grid, cuts) => {
        expect(starsFor(cuts[3], cuts)).toBe(3);
        expect(starsFor(cuts[3] + 1, cuts)).toBe(2);
        expect(starsFor(cuts[2], cuts)).toBe(2);
        expect(starsFor(cuts[2] + 1, cuts)).toBe(1);
    });

    it('says how many turns keep the current stars', () => {
        const cuts = grids['4x4'];
        expect(starTarget(0, cuts)).toEqual({ stars: 3, turnsLeft: 13 });
        expect(starTarget(13, cuts)).toEqual({ stars: 3, turnsLeft: 0 });
        expect(starTarget(14, cuts)).toEqual({ stars: 2, turnsLeft: 4 });
        expect(starTarget(19, cuts)).toBeNull();
    });

    it('suggests the next grid up, and nothing after the last', () => {
        const keys = Object.keys(grids);
        expect(nextGrid(keys, '4x4')).toBe('5x4');
        expect(nextGrid(keys, '6x6')).toBeNull();
        expect(nextGrid(keys, 'nope')).toBeNull();
    });

    it('still accepts a pair count', () => {
        expect(starsFor(12, 8)).toBe(3);
        expect(starsFor(18, 8)).toBe(2);
    });
});

describe('sharing', () => {
    it('links to the same mode and grid', () => {
        expect(shareUrl('https://doctor.example/', { mode: 'daily', grid: '6x6' })).toBe('https://doctor.example/?mode=daily&grid=6x6');
        expect(shareUrl('https://doctor.example', {})).toBe('https://doctor.example/');
    });

    it('summarises solo and two-player games', () => {
        const url = 'https://doctor.example/?mode=daily&grid=4x4';
        expect(shareText({ players: 1, mode: 'daily', today: '2026-10-23', label: '4 × 4', stars: 2, moves: 15, seconds: 75 }, { title: 'Doctor Memory Game', url }))
            .toBe(`Doctor Memory Game · Daily challenge 2026-10-23 · 4 × 4\n⭐⭐☆ 15 turns in 1:15\n${url}`);
        expect(shareText({ players: 2, playerScores: [5, 3], label: '4 × 4' }, { title: 'Doctor Memory Game', url }))
            .toBe(`Doctor Memory Game · 2 players · 4 × 4\nPlayer 1 wins 5 – 3\n${url}`);
    });
});
