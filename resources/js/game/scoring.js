/** 0:05, 1:30, 12:00 */
export function formatTime(seconds) {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

/**
 * One to three stars. `cuts` is a grid's { 3: most turns for 3 stars,
 * 2: most turns for 2 stars }. A number is still accepted as the pair
 * count, using the original 1.5x / 2.25x-per-pair rule.
 */
export function starsFor(moves, cuts) {
    if (typeof cuts === 'number') {
        const ratio = moves / cuts;
        if (ratio <= 1.5) return 3;
        if (ratio <= 2.25) return 2;
        return 1;
    }
    if (moves <= cuts[3]) return 3;
    if (moves <= cuts[2]) return 2;
    return 1;
}

/** The best star level still reachable and how many more turns keep it; null at 1 star. */
export function starTarget(moves, cuts) {
    const stars = starsFor(moves, cuts);
    if (stars === 1) return null;
    return { stars, turnsLeft: cuts[stars] - moves };
}

/** The grid after `current` in `keys`, or null at the end. */
export function nextGrid(keys, current) {
    const index = keys.indexOf(current);
    return index >= 0 && index < keys.length - 1 ? keys[index + 1] : null;
}

/** Fewer moves wins; equal moves fall back to the faster time. */
export function isBetter(candidate, current) {
    if (!current) return true;
    if (candidate.moves !== current.moves) return candidate.moves < current.moves;
    return candidate.seconds < current.seconds;
}
