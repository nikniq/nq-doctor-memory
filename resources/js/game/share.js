/**
 * What gets shared after a game: a link that opens the same board, and a
 * short text summary.
 */

/** A link that opens the game on the given mode and grid. */
export function shareUrl(origin, { mode, grid }) {
    const params = new URLSearchParams();
    if (mode) params.set('mode', mode);
    if (grid) params.set('grid', grid);
    const query = params.toString();
    return `${origin.replace(/\/$/, '')}/${query ? `?${query}` : ''}`;
}

/** The shareable summary of a finished game. */
export function shareText(result, { title, url }) {
    if (result.players === 2) {
        const [p1, p2] = result.playerScores;
        const outcome = p1 === p2 ? "It's a tie" : `Player ${p1 > p2 ? 1 : 2} wins`;
        return `${title} · 2 players · ${result.label}\n${outcome} ${p1} – ${p2}\n${url}`;
    }
    const board = result.mode === 'daily' ? `Daily challenge ${result.today}` : 'Free play';
    const stars = '⭐'.repeat(result.stars) + '☆'.repeat(3 - result.stars);
    const minutes = Math.floor(result.seconds / 60);
    const time = `${minutes}:${String(result.seconds % 60).padStart(2, '0')}`;
    return `${title} · ${board} · ${result.label}\n${stars} ${result.moves} turns in ${time}\n${url}`;
}
