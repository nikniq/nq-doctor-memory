/**
 * Memory feedback: streaks of matches and "slips" (a turn where the twin of
 * the first card had already been seen, but a different card was flipped).
 */

export function createMemoryTracker() {
    return { seen: new Set(), streak: 0, bestStreak: 0, slips: 0 };
}

/**
 * Record a finished turn. `a` and `b` are the two cards flipped, in order,
 * each { uniqueId, id }. Mutates and returns the tracker.
 */
export function recordTurn(tracker, a, b) {
    const twinOfFirstSeen = [...tracker.seen].some((key) => {
        const [uid, id] = key.split(':');
        return Number(id) === a.id && Number(uid) !== a.uniqueId;
    });
    const matched = a.id === b.id;

    if (!matched && twinOfFirstSeen) tracker.slips++;

    tracker.streak = matched ? tracker.streak + 1 : 0;
    tracker.bestStreak = Math.max(tracker.bestStreak, tracker.streak);

    tracker.seen.add(`${a.uniqueId}:${a.id}`);
    tracker.seen.add(`${b.uniqueId}:${b.id}`);
    return tracker;
}

/** "Best streak 4 · 2 memory slips", or a perfect-memory note. */
export function memoryReport(tracker) {
    const slips = tracker.slips === 0 ? 'no memory slips' : `${tracker.slips} memory slip${tracker.slips === 1 ? '' : 's'}`;
    return `Best streak ${tracker.bestStreak} · ${slips}`;
}
