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
