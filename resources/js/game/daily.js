/**
 * Daily challenge timing. The server sends when the next board starts (its
 * own midnight), so the countdown matches whatever time zone it runs in.
 */

/** Milliseconds from `now` until `resetsAt` (an ISO 8601 string or ms), never negative. */
export function msUntil(resetsAt, now) {
    const target = typeof resetsAt === 'number' ? resetsAt : Date.parse(resetsAt);
    if (Number.isNaN(target)) return 0;
    return Math.max(0, target - now);
}

/** "5h 12m", "12m", "under a minute". */
export function formatCountdown(ms) {
    const minutes = Math.floor(ms / 60000);
    if (minutes < 1) return 'under a minute';
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    return hours > 0 ? `${hours}h ${rest}m` : `${rest}m`;
}

/** "Better than 68% of 41 players today", or a friendlier line while few have played. */
export function percentileText({ players, beaten }) {
    if (players <= 1) return 'You are the first player today';
    if (players < 5) return `You are one of the first ${players} players today`;
    const percent = Math.round((beaten / (players - 1)) * 100);
    return `Better than ${percent}% of ${players} players today`;
}

/** Bar heights in px, the tallest bar `maxPx`; every non-empty bucket gets at least 2px. */
export function barHeights(buckets, maxPx) {
    const most = Math.max(0, ...buckets.map((b) => b.count));
    if (most === 0) return buckets.map(() => 0);
    return buckets.map((b) => (b.count === 0 ? 0 : Math.max(2, Math.round((b.count / most) * maxPx))));
}
