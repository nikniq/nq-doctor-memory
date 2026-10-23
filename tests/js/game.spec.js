import { describe, expect, it } from 'vitest';
import { dealBoard, hashString, seededRandom, shuffle } from '../../resources/js/game/random.js';
import { formatTime, isBetter, starsFor } from '../../resources/js/game/scoring.js';
import { currentStreak, nextStreak, yesterdayOf } from '../../resources/js/game/streak.js';
import { nextIndex } from '../../resources/js/game/grid.js';

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
