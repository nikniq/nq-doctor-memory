/** The calendar day before a YYYY-MM-DD date. */
export function yesterdayOf(dateString) {
    const d = new Date(`${dateString}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() - 1);
    return d.toISOString().slice(0, 10);
}

/** Streak after winning today's challenge. Winning twice in a day changes nothing. */
export function nextStreak(streak, today) {
    if (streak?.last === today) return streak;
    return {
        last: today,
        count: streak?.last === yesterdayOf(today) ? streak.count + 1 : 1,
    };
}

/** The streak still alive today: won today or yesterday, otherwise broken. */
export function currentStreak(streak, today) {
    if (!streak?.last) return 0;
    return streak.last === today || streak.last === yesterdayOf(today) ? streak.count : 0;
}
