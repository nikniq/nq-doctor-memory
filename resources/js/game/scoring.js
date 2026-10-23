/** 0:05, 1:30, 12:00 */
export function formatTime(seconds) {
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}

/** One to three stars from moves relative to the minimum possible (one per pair). */
export function starsFor(moves, pairs) {
    const ratio = moves / pairs;
    if (ratio <= 1.5) return 3;
    if (ratio <= 2.25) return 2;
    return 1;
}

/** Fewer moves wins; equal moves fall back to the faster time. */
export function isBetter(candidate, current) {
    if (!current) return true;
    if (candidate.moves !== current.moves) return candidate.moves < current.moves;
    return candidate.seconds < current.seconds;
}
