/** Deterministic hash of a string to a 32-bit unsigned integer. */
export function hashString(str) {
    let h = 1779033703 ^ str.length;
    for (let i = 0; i < str.length; i++) {
        h = Math.imul(h ^ str.charCodeAt(i), 3432918353);
        h = (h << 13) | (h >>> 19);
    }
    return h >>> 0;
}

/** mulberry32: small seeded PRNG so a daily board is identical for everyone. */
export function seededRandom(seed) {
    let a = seed;
    return () => {
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/** Fisher–Yates shuffle into a new array. */
export function shuffle(array, random = Math.random) {
    const out = [...array];
    for (let i = out.length - 1; i > 0; i--) {
        const j = Math.floor(random() * (i + 1));
        [out[i], out[j]] = [out[j], out[i]];
    }
    return out;
}

/**
 * Deal a board: pick `pairs` distinct icons and lay each down twice.
 * Pass a seeded `random` for a reproducible board.
 */
export function dealBoard(icons, pairs, random = Math.random) {
    const chosen = shuffle(icons, random).slice(0, pairs);
    return shuffle([...chosen, ...chosen], random).map((card, index) => ({
        ...card,
        uniqueId: index,
        isFlipped: false,
        isMatched: false,
    }));
}
